<?php

declare(strict_types=1);

namespace App\Domain\Preview;

use App\Domain\Content\ContentMapper;
use App\Domain\Content\Dto\ContentItem;
use App\Domain\Content\Http\UpstreamException;
use App\Domain\Content\Http\WordPressRestClient;
use App\Support\Errors\MysiteException;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Contracts\Encryption\Encrypter;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * 草稿預覽（API-11、API-12、API-13、契約 §5）。
 *
 * - exchange：驗票券 → 以 preview_ticket_uses.jti 唯一鍵原子消耗（並發只成功一次）→ 建立 preview session
 *   （隨機 ID、DB 只存 SHA-256、票券加密保存、到期時間＝票券 exp）。
 * - snapshot：以服務帳號經 HTTPS 私密端點取快照；WP 403／404 → PREVIEW_INVALID；
 *   回應的型別／ID／快照／revision 必須與票券一致，否則同樣拒絕（不拿正式文章替代）。
 * - 預覽內容不寫入任何公開快取。
 */
final class PreviewService
{
    public const COOKIE_TOKEN_PATTERN = '/^[0-9a-f]{64}$/';

    public function __construct(
        private readonly PreviewTicketVerifier $verifier,
        private readonly WordPressRestClient $client,
        private readonly ContentMapper $mapper,
        private readonly Encrypter $encrypter,
    ) {
    }

    /**
     * @return array{token: string, expires_at: CarbonImmutable}
     *
     * @throws PreviewInvalidException
     */
    public function exchange(#[\SensitiveParameter] string $ticket): array
    {
        $now = now()->toImmutable();
        $claims = $this->verifier->verify($ticket, $now->getTimestamp());
        $token = bin2hex(random_bytes(32));
        $expires = CarbonImmutable::createFromTimestampUTC($claims->exp);

        try {
            DB::transaction(function () use ($claims, $ticket, $token, $now, $expires): void {
                DB::table('preview_ticket_uses')->insert([
                    'jti' => $claims->jti,
                    'content_type' => $claims->ctype->value,
                    'content_id' => $claims->cid,
                    'snapshot_id' => $claims->sid,
                    'issued_by' => $claims->sub,
                    'expires_at' => $expires,
                    'used_at' => $now,
                ]);
                DB::table('preview_sessions')->insert([
                    'token_hash' => hash('sha256', $token),
                    'jti' => $claims->jti,
                    'content_type' => $claims->ctype->value,
                    'content_id' => $claims->cid,
                    'snapshot_id' => $claims->sid,
                    'revision_id' => $claims->rev,
                    'ticket_ciphertext' => $this->encrypter->encryptString($ticket),
                    'expires_at' => $expires,
                    'created_at' => $now,
                ]);
            });
        } catch (UniqueConstraintViolationException) {
            throw new PreviewInvalidException('replay');
        }

        return ['token' => $token, 'expires_at' => $expires];
    }

    /**
     * @throws PreviewInvalidException
     */
    public function resolve(?string $token): PreviewContext
    {
        if ($token === null || preg_match(self::COOKIE_TOKEN_PATTERN, $token) !== 1) {
            throw new PreviewInvalidException('session_missing');
        }
        $row = DB::table('preview_sessions')->where('token_hash', hash('sha256', $token))->first();
        $now = now()->toImmutable();
        if ($row === null) {
            throw new PreviewInvalidException('session_unknown');
        }
        if (CarbonImmutable::parse((string) $row->expires_at, 'UTC')->lessThanOrEqualTo($now)) {
            throw new PreviewInvalidException('session_expired');
        }
        try {
            $ticket = $this->encrypter->decryptString((string) $row->ticket_ciphertext);
        } catch (DecryptException) {
            throw new PreviewInvalidException('session_decrypt');
        }
        $claims = $this->verifier->verify($ticket, $now->getTimestamp());
        if ($claims->jti !== $row->jti || $claims->ctype->value !== $row->content_type || $claims->cid !== (int) $row->content_id || $claims->sid !== $row->snapshot_id) {
            throw new PreviewInvalidException('session_mismatch');
        }

        return new PreviewContext($claims, $ticket);
    }

    /**
     * @throws PreviewInvalidException
     * @throws MysiteException 上游不可用（503）
     */
    public function snapshot(PreviewContext $context): ContentItem
    {
        $claims = $context->claims;
        try {
            $envelope = $this->client->getPrivate(
                '/preview/'.$claims->ctype->value.'/'.$claims->cid,
                ['snapshot' => $claims->sid],
                ['X-Mysite-Preview-Ticket' => $context->ticket],
            );
        } catch (UpstreamException $e) {
            if ($e->reason === 'not_found' || $e->upstreamStatus === 403) {
                throw new PreviewInvalidException('wp_rejected');
            }

            throw $e;
        }

        $data = $this->mapper->data($envelope);
        if (! is_array($data) || array_is_list($data)) {
            throw new PreviewInvalidException('snapshot_shape');
        }
        if (($data['preview'] ?? null) !== true
            || ($data['type'] ?? null) !== $claims->ctype->value
            || ($data['id'] ?? null) !== $claims->cid
            || ($data['snapshot_id'] ?? null) !== $claims->sid
            || ($data['revision_id'] ?? null) !== $claims->rev) {
            throw new PreviewInvalidException('snapshot_mismatch');
        }

        return $this->mapper->item($data, true, true);
    }
}
