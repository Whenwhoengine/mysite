<?php

declare(strict_types=1);

namespace App\Http\Controllers\Shop;

use App\Domain\Commerce\Cart\CartPricer;
use App\Domain\Commerce\Cart\SessionCart;
use App\Domain\Commerce\CommerceMode;
use App\Domain\Commerce\Exceptions\CartLimitException;
use App\Http\Controllers\Controller;
use App\Models\ProductVariant;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Session 購物車。只接受 variant_id 與 quantity；任何價格、折扣、總額欄位都被忽略（COM-03）。
 */
final class CartController extends Controller
{
    public function show(Request $request, CartPricer $pricer): View
    {
        $cart = SessionCart::for($request->session());
        $priced = $pricer->price($cart->lines());
        // 先以「快照≠現價」顯示提示，再把快照更新為現價（訪客已看到新價格）。
        foreach ($priced->lines as $line) {
            if ($line->priceChanged && $line->unitPrice !== null) {
                $cart->acknowledgePrice($line->line->variantId, $line->unitPrice);
            }
        }

        return view('shop.cart', [
            'cart' => $priced,
            'maxQuantity' => CommerceMode::maxLineQuantity(),
            'maxLines' => CommerceMode::maxCartLines(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'variant_id' => ['required', 'integer', 'min:1'],
            'quantity' => ['required', 'integer', 'min:1', 'max:'.CommerceMode::maxLineQuantity()],
        ], $this->messages());

        $variant = ProductVariant::query()->with('product')->find((int) $data['variant_id']);
        if ($variant === null || ! $variant->isPurchasable()) {
            return back()->withErrors(['cart' => '這個商品目前無法購買。']);
        }
        try {
            SessionCart::for($request->session())->add($variant->id, (int) $data['quantity'], $variant->price);
        } catch (CartLimitException $e) {
            return back()->withErrors(['cart' => $e->getMessage()]);
        }

        return redirect()->route('cart.show')->with('status', '已加入購物車（模擬）。');
    }

    public function update(Request $request, int $variantId): RedirectResponse
    {
        $data = $request->validate([
            'quantity' => ['required', 'integer', 'min:1', 'max:'.CommerceMode::maxLineQuantity()],
        ], $this->messages());
        try {
            $updated = SessionCart::for($request->session())->update($variantId, (int) $data['quantity']);
        } catch (CartLimitException $e) {
            return back()->withErrors(['cart' => $e->getMessage()]);
        }

        return redirect()->route('cart.show')->with('status', $updated ? '已更新數量。' : '購物車裡沒有這個商品。');
    }

    public function destroy(Request $request, int $variantId): RedirectResponse
    {
        SessionCart::for($request->session())->remove($variantId);

        return redirect()->route('cart.show')->with('status', '已從購物車移除。');
    }

    /** @return array<string, string> */
    private function messages(): array
    {
        $max = CommerceMode::maxLineQuantity();

        return [
            'variant_id.required' => '請選擇商品規格。',
            'variant_id.integer' => '商品規格不合法。',
            'variant_id.min' => '商品規格不合法。',
            'quantity.required' => '請輸入數量。',
            'quantity.integer' => '數量必須是整數。',
            'quantity.min' => '數量至少為 1。',
            'quantity.max' => "每項商品最多 {$max} 件。",
        ];
    }
}
