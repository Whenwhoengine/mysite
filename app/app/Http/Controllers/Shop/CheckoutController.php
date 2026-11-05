<?php

declare(strict_types=1);

namespace App\Http\Controllers\Shop;

use App\Domain\Commerce\Cart\CartPricer;
use App\Domain\Commerce\Cart\SessionCart;
use App\Domain\Commerce\Checkout\CheckoutCommand;
use App\Domain\Commerce\Checkout\CheckoutService;
use App\Domain\Commerce\CommerceSession;
use App\Domain\Commerce\Exceptions\CheckoutRejected;
use App\Http\Controllers\Controller;
use App\Support\Errors\ErrorCode;
use App\Support\Errors\MysiteException;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * 模擬結帳。表單只送出 CSRF token、冪等鍵與可選測試代號；不收卡號、真實地址或電話（COM-11）。
 */
final class CheckoutController extends Controller
{
    public function show(Request $request, CartPricer $pricer): View|RedirectResponse
    {
        $cart = SessionCart::for($request->session());
        $priced = $pricer->price($cart->lines());
        if ($priced->isEmpty()) {
            return redirect()->route('cart.show')->withErrors(['cart' => '購物車是空的。']);
        }
        if ($priced->hasBlockingIssues()) {
            return redirect()->route('cart.show')->withErrors(['cart' => '部分商品已下架或庫存不足，請先調整購物車。']);
        }
        foreach ($priced->lines as $line) {
            if ($line->priceChanged && $line->unitPrice !== null) {
                $cart->acknowledgePrice($line->line->variantId, $line->unitPrice);
            }
        }
        $session = new CommerceSession($request->session());
        $session->bindingHash();
        $ticket = $session->issueTicket();

        return view('shop.checkout', [
            'cart' => $priced,
            'idempotencyKey' => $ticket['key'],
        ]);
    }

    public function store(Request $request, CheckoutService $service, CartPricer $pricer): RedirectResponse
    {
        $data = $request->validate([
            'idempotency_key' => ['required', 'string', 'regex:/^[a-f0-9]{32}$/'],
            'buyer_code' => ['nullable', 'string', 'regex:/^[A-Za-z0-9-]{1,20}$/'],
        ], [
            'idempotency_key.required' => '結帳頁已過期，請重新整理後再送出。',
            'idempotency_key.regex' => '結帳頁已過期，請重新整理後再送出。',
            'buyer_code.regex' => '測試代號只能使用英數字與連字號，最多 20 字。',
        ]);

        $session = new CommerceSession($request->session());
        $key = $data['idempotency_key'];
        $token = $session->ticketToken($key);
        if ($token === null) {
            // 票券不屬於這個 Session（過期或他人的鍵）：不查詢、不透露任何既有結果。
            return redirect()->route('checkout.show')->withErrors(['checkout' => '結帳頁已過期，請重新確認後送出。']);
        }

        $cart = SessionCart::for($request->session());
        $lines = $cart->lines();
        $command = new CheckoutCommand(
            sessionBindingHash: $session->bindingHash(),
            idempotencyKey: $key,
            orderToken: $token,
            quantities: array_map(static fn ($l) => $l->quantity, $lines),
            priceSnapshots: array_map(static fn ($l) => $l->priceSnapshot, $lines),
            buyerCode: isset($data['buyer_code']) ? strtoupper($data['buyer_code']) : null,
        );

        try {
            $result = $service->placeOrder($command);
        } catch (CheckoutRejected $e) {
            if ($e->has('price_changed')) {
                foreach ($pricer->price($lines)->lines as $line) {
                    if ($line->unitPrice !== null) {
                        $cart->acknowledgePrice($line->line->variantId, $line->unitPrice);
                    }
                }

                return redirect()->route('checkout.show')->withErrors(['checkout' => implode(' ', $e->messages())]);
            }
            if ($e->has('empty_cart')) {
                return redirect()->route('cart.show')->withErrors(['cart' => '購物車是空的。']);
            }

            return redirect()->route('cart.show')->withErrors(['cart' => implode(' ', $e->messages())]);
        }

        $cart->clear();
        if (! hash_equals((string) $result->order->token_hash, CommerceSession::tokenHash($token))) {
            abort(404); // 理論上不會發生：同一票券一定對應同一 token
        }

        return redirect()->route('orders.show', ['token' => $token])->with(
            'status',
            $result->replayed ? '這筆模擬訂單先前已建立；重複送出不會產生第二筆。' : '模擬訂單已建立（不收款、不出貨）。',
        );
    }

    /** 任何付款語意一律拒絕（COM-01、COM-11）。 */
    public function payment(): never
    {
        throw new MysiteException(ErrorCode::SimulationOnly, 'payment endpoint requested');
    }
}
