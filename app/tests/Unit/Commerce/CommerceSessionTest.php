<?php

declare(strict_types=1);

namespace Tests\Unit\Commerce;

use App\Domain\Commerce\CommerceSession;
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Session\Store;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Commerce\CommerceTestCase;

/** Session 綁定與結帳票券（COM-05、COM-09）。 */
final class CommerceSessionTest extends CommerceTestCase
{
    #[Test]
    #[Group('COM-09')]
    public function binding_is_stable_per_session_and_differs_between_sessions(): void
    {
        $a = new CommerceSession(new Store('a', new ArraySessionHandler(120)));
        $b = new CommerceSession(new Store('b', new ArraySessionHandler(120)));
        $hashA = $a->bindingHash();
        $this->assertSame($hashA, $a->bindingHash());
        $this->assertSame($hashA, $a->existingBindingHash());
        $this->assertNotSame($hashA, $b->bindingHash());
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $hashA);
        $this->assertNull((new CommerceSession(new Store('c', new ArraySessionHandler(120))))->existingBindingHash());
    }

    #[Test]
    #[Group('COM-05')]
    #[Group('COM-09')]
    public function tickets_are_random_bounded_and_session_scoped(): void
    {
        $store = new Store('a', new ArraySessionHandler(120));
        $session = new CommerceSession($store);
        $tickets = [];
        for ($i = 0; $i < CommerceSession::MAX_TICKETS + 2; $i++) {
            $tickets[] = $session->issueTicket();
        }
        $this->assertCount(CommerceSession::MAX_TICKETS, $store->get(CommerceSession::KEY_TICKETS));
        $this->assertNull($session->ticketToken($tickets[0]['key']), '最舊的票券應被淘汰');
        $last = end($tickets);
        $this->assertSame($last['token'], $session->ticketToken($last['key']));
        $this->assertTrue(CommerceSession::isValidKey($last['key']));
        $this->assertTrue(CommerceSession::isValidToken($last['token']));
        $this->assertCount(count($tickets), array_unique(array_column($tickets, 'token')));

        $other = new CommerceSession(new Store('b', new ArraySessionHandler(120)));
        $this->assertNull($other->ticketToken($last['key']), '他人 Session 拿不到票券');
        $this->assertSame(hash('sha256', $last['token']), CommerceSession::tokenHash($last['token']));
    }

    #[Test]
    #[Group('COM-09')]
    public function corrupt_ticket_and_binding_values_are_ignored(): void
    {
        $store = new Store('a', new ArraySessionHandler(120));
        $store->put(CommerceSession::KEY_TICKETS, ['bad' => 'x', str_repeat('a', 32) => 'short']);
        $store->put(CommerceSession::KEY_BINDING, 'not-hex');
        $session = new CommerceSession($store);
        $this->assertNull($session->ticketToken('bad'));
        $this->assertNull($session->existingBindingHash());
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $session->bindingHash());
    }
}
