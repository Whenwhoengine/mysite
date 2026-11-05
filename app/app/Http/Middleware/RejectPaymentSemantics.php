<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Support\Errors\ErrorCode;
use App\Support\Errors\MysiteException;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * 拒絕任何帶有付款或真實收件語意的欄位（COM-11）：卡號、有效期、CVV、付款方式、銀行帳號、真實地址、電話。
 * 命中即回 422 SIMULATION_ONLY；本案不接收、不記錄這些值。
 */
final class RejectPaymentSemantics
{
    private const PATTERN = '/(card|cvv|cvc|pan$|^pan|expir|exp_month|exp_year|payment|pay_|iban|bank|account_number|routing|billing|shipping|address|phone|mobile|tel$|^tel)/i';

    public function handle(Request $request, Closure $next): Response
    {
        if ($this->containsForbiddenKey($request->request->all()) || $this->containsForbiddenKey($request->query->all())) {
            throw new MysiteException(ErrorCode::SimulationOnly, 'payment-like field submitted');
        }

        return $next($request);
    }

    /** @param array<array-key, mixed> $data */
    private function containsForbiddenKey(array $data, int $depth = 0): bool
    {
        if ($depth > 3) {
            return true; // 本案表單沒有這麼深的結構；過深一律視為可疑
        }
        foreach ($data as $key => $value) {
            if (is_string($key) && preg_match(self::PATTERN, $key) === 1) {
                return true;
            }
            if (is_array($value) && $this->containsForbiddenKey($value, $depth + 1)) {
                return true;
            }
        }

        return false;
    }
}
