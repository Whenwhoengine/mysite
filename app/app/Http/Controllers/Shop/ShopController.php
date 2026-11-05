<?php

declare(strict_types=1);

namespace App\Http\Controllers\Shop;

use App\Domain\Commerce\CommerceMode;
use App\Http\Controllers\Controller;
use App\Models\Product;
use Illuminate\Contracts\View\View;

/** 模擬商店：商品列表與詳情（只顯示上架中商品）。 */
final class ShopController extends Controller
{
    /** 每頁商品數（版面用）。 */
    private const PER_PAGE = 12;

    public function index(): View
    {
        $products = Product::query()
            ->active()
            ->with(['variants' => static fn ($q) => $q->where('is_active', true)])
            ->orderBy('id')
            ->paginate(self::PER_PAGE);

        return view('shop.index', ['products' => $products]);
    }

    public function show(string $slug): View
    {
        $product = Product::query()
            ->active()
            ->where('slug', $slug)
            ->with(['variants' => static fn ($q) => $q->where('is_active', true)])
            ->firstOrFail();

        return view('shop.show', [
            'product' => $product,
            'maxQuantity' => CommerceMode::maxLineQuantity(),
        ]);
    }
}
