<?php

declare(strict_types=1);

namespace App\Actions\Fortify;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Laravel\Fortify\Contracts\FailedPasswordResetLinkRequestResponse;
use Laravel\Fortify\Contracts\SuccessfulPasswordResetLinkRequestResponse;

/**
 * 申請重設密碼時，不論 email 是否存在、是否被節流，都回同一訊息，避免被用來探測後臺帳號。
 */
final class GenericPasswordResetLinkResponse implements FailedPasswordResetLinkRequestResponse, SuccessfulPasswordResetLinkRequestResponse
{
    public const MESSAGE = '若此 email 屬於後臺帳號，重設連結已送出（開發環境只寫入郵件捕捉器）。';

    public function __construct(protected string $status = '') {}

    public function toResponse($request): JsonResponse|RedirectResponse
    {
        return $request->wantsJson()
            ? new JsonResponse(['message' => self::MESSAGE], 200)
            : back()->with('status', self::MESSAGE);
    }
}
