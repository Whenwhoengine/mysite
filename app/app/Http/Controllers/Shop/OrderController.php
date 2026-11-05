<?php

declare(strict_types=1);

namespace App\Http\Controllers\Shop;

use App\Domain\Commerce\CommerceSession;
use App\Domain\Commerce\Exceptions\InvalidOrderTransition;
use App\Domain\Commerce\Orders\OrderCancellationService;
use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\OrderStatus;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * 訪客查看／取消自己的模擬訂單（COM-09）：必須同時持有 token（只比對 SHA-256）與建立訂單的 Session 綁定。
 * 任何不符一律 404，不區分「token 錯」或「Session 不符」，避免成為探測工具。
 */
final class OrderController extends Controller
{
    public function show(Request $request, string $token): View
    {
        $order = $this->resolve($request, $token);
        $order->load('items');

        return view('shop.order', [
            'order' => $order,
            'token' => $token,
            'canCancel' => $order->status === OrderStatus::Placed,
        ]);
    }

    public function cancel(Request $request, string $token, OrderCancellationService $service): RedirectResponse
    {
        $order = $this->resolve($request, $token);
        try {
            $changed = $service->cancel($order, null, 'customer');
        } catch (InvalidOrderTransition) {
            return redirect()->route('orders.show', ['token' => $token])->withErrors(['order' => '此訂單目前無法取消。']);
        }

        return redirect()->route('orders.show', ['token' => $token])->with(
            'status',
            $changed ? '模擬訂單已取消，庫存已回補。' : '這筆模擬訂單先前已取消；重複送出不會再次回補庫存。',
        );
    }

    private function resolve(Request $request, string $token): Order
    {
        if (! CommerceSession::isValidToken($token)) {
            abort(404);
        }
        $binding = (new CommerceSession($request->session()))->existingBindingHash();
        if ($binding === null) {
            abort(404);
        }
        $order = Order::query()->where('token_hash', CommerceSession::tokenHash($token))->first();
        if ($order === null || $order->session_binding_hash === null || ! hash_equals($order->session_binding_hash, $binding)) {
            abort(404);
        }

        return $order;
    }
}
