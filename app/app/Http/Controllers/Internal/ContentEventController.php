<?php

declare(strict_types=1);

namespace App\Http\Controllers\Internal;

use App\Domain\Content\Events\ContentEventPayload;
use App\Domain\Content\Events\ContentEventReceiver;
use App\Domain\Content\Events\EventSignatureVerifier;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * POST /internal/content-events/v1（契約 §4、API-06、API-07）。
 *
 * 只接受容器內直接連線（internal 中介層）；驗 key ID、時差 ≤ 300 秒、HMAC-SHA256（原始 body 位元組）；
 * 先持久保存收據才回 202；同 ID 同內容 → 202 duplicate；同 ID 不同內容 → 409；簽章錯 → 401；格式錯 → 422。
 */
final class ContentEventController extends Controller
{
    /** 事件 body 大小上限（位元組；暫定值，見完工報告）。 */
    public const MAX_BODY_BYTES = 16384;

    public function __invoke(Request $request, EventSignatureVerifier $verifier, ContentEventReceiver $receiver): JsonResponse
    {
        if (! $verifier->isConfigured()) {
            Log::critical('content_event_receiver_misconfigured');

            return $this->json(['error' => 'EVENT_RECEIVER_UNAVAILABLE'], 503);
        }

        $raw = $request->getContent();
        $keyId = $this->header($request, 'X-Mysite-Key-Id');
        $eventId = $this->header($request, 'X-Mysite-Event-Id');
        $failure = $verifier->failure(
            $keyId,
            $this->header($request, 'X-Mysite-Timestamp'),
            $eventId,
            $this->header($request, 'X-Mysite-Signature'),
            $raw,
            now()->getTimestamp(),
        );
        if ($failure !== null) {
            Log::warning('content_event_signature_invalid', ['reason' => $failure]);

            return $this->json(['error' => 'EVENT_SIGNATURE_INVALID'], 401);
        }

        $payload = strlen($raw) <= self::MAX_BODY_BYTES && str_contains(strtolower((string) $request->header('Content-Type')), 'application/json')
            ? ContentEventPayload::parse($raw)
            : null;
        if ($payload === null || $payload->eventId !== $eventId) {
            Log::warning('content_event_invalid', ['bytes' => strlen($raw)]);

            return $this->json(['error' => 'EVENT_INVALID'], 422);
        }

        return match ($receiver->receive($payload, $raw, (string) $keyId)) {
            ContentEventReceiver::ACCEPTED => $this->json(['status' => 'accepted'], 202),
            ContentEventReceiver::DUPLICATE => $this->json(['status' => 'duplicate'], 202),
            default => $this->json(['error' => 'EVENT_CONFLICT'], 409),
        };
    }

    private function header(Request $request, string $name): ?string
    {
        $value = $request->headers->get($name);

        return is_string($value) ? trim($value) : null;
    }

    /** @param array<string, string> $body */
    private function json(array $body, int $status): JsonResponse
    {
        return response()->json($body, $status, ['Cache-Control' => 'no-store']);
    }
}
