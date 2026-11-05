<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Domain\Audit\AuditLogger;
use App\Domain\Commerce\Exceptions\InvalidOrderTransition;
use App\Domain\Commerce\Orders\OrderCancellationService;
use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\OrderStatus;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** 後臺模擬訂單：列表、詳情、取消（admin）、CSV 匯出（admin，留稽核）。 */
final class OrderController extends Controller
{
    private const PER_PAGE = 25;

    public function index(Request $request): View
    {
        Gate::authorize('viewAny', Order::class);
        $status = OrderStatus::tryFrom((string) $request->query('status', ''));
        $orders = Order::query()
            ->when($status !== null, static fn ($q) => $q->where('status', $status->value))
            ->orderByDesc('id')
            ->paginate(self::PER_PAGE)
            ->withQueryString();

        return view('admin.orders.index', [
            'orders' => $orders,
            'status' => $status,
            'canExport' => Gate::allows('export', Order::class),
        ]);
    }

    public function show(Order $order): View
    {
        Gate::authorize('view', $order);
        $order->load(['items', 'mutations']);

        return view('admin.orders.show', [
            'order' => $order,
            'canCancel' => Gate::allows('cancel', $order) && $order->status === OrderStatus::Placed && ! $order->isAnonymized(),
        ]);
    }

    public function cancel(Request $request, Order $order, OrderCancellationService $service): RedirectResponse
    {
        Gate::authorize('cancel', $order);
        try {
            $changed = $service->cancel($order, $request->user(), 'admin');
        } catch (InvalidOrderTransition $e) {
            return back()->withErrors(['order' => $e->getMessage()]);
        }

        return redirect()->route('admin.orders.show', $order)->with('status', $changed ? '訂單已取消，庫存已回補。' : '此訂單先前已取消，未重複回補。');
    }

    /** 匯出不含 token、Session 綁定、冪等鍵與測試代號；只含統計所需欄位。 */
    public function export(Request $request, AuditLogger $audit): StreamedResponse
    {
        Gate::authorize('export', Order::class);
        $count = Order::query()->count();
        $audit->record('orders.exported', $request->user(), 'order', null, ['rows' => $count, 'format' => 'csv']);

        $filename = 'simulated-orders-'.now()->format('Ymd-His').'.csv';

        return response()->streamDownload(static function (): void {
            $out = fopen('php://output', 'wb');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, ['order_number', 'status', 'currency', 'subtotal', 'total', 'item_count', 'placed_at', 'cancelled_at', 'anonymized'], escape: '');
            Order::query()->orderBy('id')->chunkById(500, static function ($orders) use ($out): void {
                foreach ($orders as $order) {
                    fputcsv($out, [
                        $order->displayNumber(),
                        $order->status->value,
                        $order->currency,
                        $order->subtotal->toDecimal(),
                        $order->total->toDecimal(),
                        $order->item_count,
                        $order->placed_at?->toIso8601String(),
                        $order->cancelled_at?->toIso8601String(),
                        $order->isAnonymized() ? 'yes' : 'no',
                    ], escape: '');
                }
            });
            fclose($out);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Cache-Control' => 'no-store, private',
        ]);
    }
}
