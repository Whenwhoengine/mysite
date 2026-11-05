<?php

declare(strict_types=1);

namespace Tests\Feature\Preview;

use App\Support\Secrets\KeyRing;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Content\Concerns\FakesWordPress;
use Tests\TestCase;

/**
 * /preview/exchange 搭配預覽金鑰圈（GAP-KEYRING-01、契約 §7 擴充）：kid 選金鑰、未知 kid 403、retire 後舊 kid 403。
 */
final class PreviewKeyRingExchangeTest extends TestCase
{
    use FakesWordPress;
    use RefreshDatabase;

    private const K1 = '5c5c5c5c5c5c5c5c5c5c5c5c5c5c5c5c5c5c5c5c5c5c5c5c5c5c5c5c5c5c5c5c';

    private const K2 = '6d6d6d6d6d6d6d6d6d6d6d6d6d6d6d6d6d6d6d6d6d6d6d6d6d6d6d6d6d6d6d6d';

    protected function setUp(): void
    {
        parent::setUp();
        $this->configureContent();
        $this->freezeSecond();
    }

    private function useRing(string $ring): void
    {
        config(['mysite.preview.signing_keyring' => KeyRing::parse('TEST', $ring)->toCompact()]);
    }

    private function ticket(string $key, ?string $kid): string
    {
        $header = ['alg' => 'HS256', 'typ' => 'mysite-preview'];
        if ($kid !== null) {
            $header['kid'] = $kid;
        }

        return self::previewTicket([], $key, $header);
    }

    #[Test]
    #[Group('API-11')]
    #[Group('OPS-01')]
    public function exchange_follows_the_keyring_through_promote_and_retire(): void
    {
        $this->useRing('k1 '.self::K1." accept\nk2 ".self::K2." active\n");
        $this->get('/preview/exchange?ticket='.$this->ticket(self::K2, 'k2'))->assertStatus(302);
        $this->get('/preview/exchange?ticket='.$this->ticket(self::K1, 'k1'))->assertStatus(302);
        $this->get('/preview/exchange?ticket='.$this->ticket(self::K2, null))->assertStatus(302);
        $this->get('/preview/exchange?ticket='.$this->ticket(self::K1, 'k7'))->assertStatus(403)->assertSee('預覽已失效');
        $this->assertSame(3, DB::table('preview_ticket_uses')->count());

        $this->useRing('k2 '.self::K2." active\n");
        $this->get('/preview/exchange?ticket='.$this->ticket(self::K1, 'k1'))->assertStatus(403);
        $this->get('/preview/exchange?ticket='.$this->ticket(self::K1, null))->assertStatus(403);
        // 有金鑰圈時不再使用單一金鑰
        $this->get('/preview/exchange?ticket='.$this->ticket(self::PREVIEW_KEY, 'k1'))->assertStatus(403);
        $this->assertSame(3, DB::table('preview_ticket_uses')->count(), '被拒的票券不消耗 jti');
    }

    #[Test]
    #[Group('API-11')]
    public function without_a_keyring_old_and_new_ticket_formats_both_work(): void
    {
        config(['mysite.preview.signing_keyring' => null]);

        $this->get('/preview/exchange?ticket='.$this->ticket(self::PREVIEW_KEY, null))->assertStatus(302);
        $this->get('/preview/exchange?ticket='.$this->ticket(self::PREVIEW_KEY, 'k1'))->assertStatus(302);
        $this->get('/preview/exchange?ticket='.$this->ticket(self::PREVIEW_KEY, 'k2'))->assertStatus(403);
    }
}
