<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Domain\Commerce\CommerceMode;
use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Order;
use App\Models\OrderStatus;
use App\Models\Product;
use App\Models\ProductStatus;
use App\Models\ProductVariant;
use Illuminate\Contracts\View\View;

final class DashboardController extends Controller
{
    /** 「低庫存」提示門檻（版面提示用；暫定 3 件）。 */
    private const LOW_STOCK = 3;

    public function __invoke(): View
    {
        return view('admin.dashboard', [
            'mode' => CommerceMode::current(),
            'productCount' => Product::query()->count(),
            'activeProductCount' => Product::query()->where('status', ProductStatus::Active->value)->count(),
            'lowStock' => ProductVariant::query()->with('product')->where('is_active', true)->where('stock', '<=', self::LOW_STOCK)->orderBy('stock')->limit(10)->get(),
            'placedCount' => Order::query()->where('status', OrderStatus::Placed->value)->count(),
            'cancelledCount' => Order::query()->where('status', OrderStatus::Cancelled->value)->count(),
            'recentAudit' => AuditLog::query()->orderByDesc('id')->limit(8)->get(),
            'lowStockThreshold' => self::LOW_STOCK,
        ]);
    }
}
