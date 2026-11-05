<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Domain\Commerce\CommerceMode;
use App\Support\Errors\ErrorCode;
use App\Support\Errors\MysiteException;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * 商店功能旗標（COM-01）：
 * - disabled（預設）：所有商店頁面與寫入回 404 COMMERCE_DISABLED。
 * - simulation：開放模擬商店。
 * - 其他值（含 real）：MysiteConfig 啟動時已拒絕；此處再防禦一次，回 422 SIMULATION_ONLY。
 */
final class EnsureCommerceEnabled
{
    public function handle(Request $request, Closure $next): Response
    {
        $mode = CommerceMode::current();
        if ($mode === CommerceMode::DISABLED) {
            throw new MysiteException(ErrorCode::CommerceDisabled, 'commerce mode disabled');
        }
        if ($mode !== CommerceMode::SIMULATION) {
            throw new MysiteException(ErrorCode::SimulationOnly, 'commerce mode not simulation');
        }

        return $next($request);
    }
}
