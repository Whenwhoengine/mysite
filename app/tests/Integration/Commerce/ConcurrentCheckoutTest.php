<?php

declare(strict_types=1);

namespace Tests\Integration\Commerce;

use App\Domain\Commerce\Checkout\CheckoutCommand;
use App\Domain\Commerce\Checkout\CheckoutService;
use App\Domain\Commerce\Money;
use App\Models\Product;
use App\Models\ProductStatus;
use App\Models\ProductVariant;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\Feature\Commerce\CommerceTestCase;

/**
 * 真實 MySQL 多程序併發測試（COM-04、COM-05、COM-06、COM-08）。
 *
 * 每個買家是一個獨立 PHP 程序（bin/commerce-worker.php）、各自一條 MySQL 連線；全部印出 READY 後同時收到 GO。
 * 不使用 RefreshDatabase／DatabaseTransactions（交易包覆會讓其他連線看不到資料），改由本類別自行建立與清理資料，
 * 且只在 mysite_app_test_* 測試庫執行（CommerceTestCase 與 worker 都會檢查）。
 */
final class ConcurrentCheckoutTest extends CommerceTestCase
{
    private const TIMEOUT_SECONDS = 60;

    /** @var list<int> */
    private array $productIds = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->artisan('migrate', ['--force' => true])->assertSuccessful();
    }

    protected function tearDown(): void
    {
        $variantIds = DB::table('product_variants')->whereIn('product_id', $this->productIds)->pluck('id')->all();
        $orderIds = DB::table('order_items')->whereIn('product_variant_id', $variantIds)->pluck('order_id')->unique()->all();
        DB::table('inventory_mutations')->whereIn('product_variant_id', $variantIds)->delete();
        DB::table('order_items')->whereIn('order_id', $orderIds)->delete();
        DB::table('audit_logs')->where('target_type', 'order')->whereIn('target_id', array_map('strval', $orderIds))->delete();
        DB::table('orders')->whereIn('id', $orderIds)->delete();
        DB::table('product_variants')->whereIn('id', $variantIds)->delete();
        DB::table('products')->whereIn('id', $this->productIds)->delete();
        parent::tearDown();
    }

    private function makeVariant(int $stock, string $price = '100.00'): ProductVariant
    {
        $product = new Product;
        $product->forceFill([
            'slug' => 'conc-'.Str::lower(Str::random(12)), 'title' => '併發測試品', 'price' => Money::fromDecimal($price),
            'status' => ProductStatus::Active, 'is_sample' => true,
        ])->save();
        $this->productIds[] = $product->id;
        $variant = new ProductVariant;
        $variant->forceFill([
            'product_id' => $product->id, 'sku' => 'CONC-'.Str::upper(Str::random(12)), 'name' => '標準款',
            'price' => Money::fromDecimal($price), 'stock' => $stock, 'is_active' => true, 'is_default' => true,
        ])->save();

        return $variant;
    }

    /** @param array<int, int> $quantities */
    private function checkoutArgs(array $quantities, ?string $binding = null, ?string $key = null, ?string $token = null, array $extra = []): array
    {
        $snapshots = [];
        foreach (array_keys($quantities) as $id) {
            $snapshots[$id] = ProductVariant::query()->findOrFail($id)->price->toDecimal();
        }

        return [
            'mode' => 'checkout',
            'sessionBindingHash' => $binding ?? bin2hex(random_bytes(32)),
            'idempotencyKey' => $key ?? bin2hex(random_bytes(16)),
            'orderToken' => $token ?? self::token(),
            'quantities' => $quantities,
            'priceSnapshots' => $snapshots,
        ] + $extra;
    }

    private static function token(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    }

    /** @return array{0: resource, 1: array<int, resource>} */
    private function spawn(array $args): array
    {
        $env = getenv();
        $env['MYSITE_TEST_DB'] = self::resolveTestDatabase();
        $env['DB_DATABASE'] = self::resolveTestDatabase();
        $process = proc_open(
            [PHP_BINARY, __DIR__.'/bin/commerce-worker.php', base64_encode(json_encode($args, JSON_THROW_ON_ERROR))],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            base_path(),
            $env,
        );
        if (! is_resource($process)) {
            throw new RuntimeException('無法啟動 worker 程序');
        }
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        return [$process, $pipes];
    }

    /** 讀 stdout 直到出現以 $prefix 開頭的行；回傳該行。 */
    private function readLine(array $pipes, string $prefix, array &$buffer): string
    {
        $deadline = microtime(true) + self::TIMEOUT_SECONDS;
        $key = (int) $pipes[1];
        $buffer[$key] ??= '';
        while (microtime(true) < $deadline) {
            while (($pos = strpos($buffer[$key], "\n")) !== false) {
                $line = substr($buffer[$key], 0, $pos);
                $buffer[$key] = substr($buffer[$key], $pos + 1);
                if (str_starts_with($line, $prefix)) {
                    return $line;
                }
            }
            $read = [$pipes[1]];
            $write = $except = null;
            if (@stream_select($read, $write, $except, 0, 200_000) > 0) {
                $chunk = fread($pipes[1], 8192);
                if ($chunk === '' || $chunk === false) {
                    if (feof($pipes[1])) {
                        break;
                    }
                } else {
                    $buffer[$key] .= $chunk;
                }
            }
        }
        $stderr = (string) stream_get_contents($pipes[2]);
        throw new RuntimeException("worker 未在時限內輸出 {$prefix}；stderr：".mb_substr($stderr, 0, 2000));
    }

    /**
     * 同時啟動多個 worker：全部 READY 後一起送 GO，回傳每個 worker 的結果。
     *
     * @param  list<array<string, mixed>>  $jobs
     * @return list<array<string, mixed>>
     */
    private function runConcurrently(array $jobs): array
    {
        $procs = array_map(fn (array $job) => $this->spawn($job), $jobs);
        $buffer = [];
        foreach ($procs as [, $pipes]) {
            $this->readLine($pipes, 'READY', $buffer);
        }
        foreach ($procs as [, $pipes]) {
            fwrite($pipes[0], "GO\n");
        }
        foreach ($procs as [, $pipes]) {
            fflush($pipes[0]);
        }

        return $this->collect($procs, $buffer);
    }

    /** @return list<array<string, mixed>> */
    private function collect(array $procs, array &$buffer): array
    {
        $results = [];
        foreach ($procs as [$process, $pipes]) {
            $line = $this->readLine($pipes, 'RESULT ', $buffer);
            $results[] = json_decode(substr($line, 7), true, 16, JSON_THROW_ON_ERROR);
            foreach ($pipes as $pipe) {
                fclose($pipe);
            }
            proc_close($process);
        }

        return $results;
    }

    private function assertNoUnexpectedErrors(array $results): void
    {
        foreach ($results as $r) {
            $this->assertArrayNotHasKey('error', $r, 'worker 發生非預期例外：'.json_encode($r, JSON_UNESCAPED_UNICODE));
        }
    }

    /** 庫存守恆：初始庫存＋所有異動 delta＝目前庫存，且每筆 stock_after 都不為負。 */
    private function assertInventoryConserved(ProductVariant $variant, int $initial): void
    {
        $stock = (int) DB::table('product_variants')->where('id', $variant->id)->value('stock');
        $sum = (int) DB::table('inventory_mutations')->where('product_variant_id', $variant->id)->sum('delta');
        $this->assertGreaterThanOrEqual(0, $stock);
        $this->assertSame($initial + $sum, $stock, '庫存與異動不守恆');
        $this->assertSame(0, DB::table('inventory_mutations')->where('product_variant_id', $variant->id)->where('stock_after', '<', 0)->count());
        $sold = (int) DB::table('order_items')->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->where('order_items.product_variant_id', $variant->id)->where('orders.status', 'placed')->sum('order_items.quantity');
        $this->assertSame($initial - $stock, $sold, '成立訂單的件數必須等於扣掉的庫存');
    }

    #[Test]
    #[Group('COM-04')]
    #[Group('concurrency')]
    public function eight_buyers_race_for_the_last_item_and_only_one_wins(): void
    {
        $variant = $this->makeVariant(1);
        $results = $this->runConcurrently(array_map(fn () => $this->checkoutArgs([$variant->id => 1]), range(1, 8)));

        $this->assertNoUnexpectedErrors($results);
        $winners = array_values(array_filter($results, static fn ($r) => $r['ok']));
        $this->assertCount(1, $winners, '最後一件只能成立一單：'.json_encode($results));
        foreach (array_filter($results, static fn ($r) => ! $r['ok']) as $loser) {
            $this->assertSame(['insufficient_stock'], $loser['rejected']);
        }
        $this->assertSame(0, (int) DB::table('product_variants')->where('id', $variant->id)->value('stock'));
        $this->assertSame(1, DB::table('order_items')->where('product_variant_id', $variant->id)->count());
        $this->assertInventoryConserved($variant, 1);
    }

    #[Test]
    #[Group('COM-04')]
    #[Group('concurrency')]
    public function twelve_buyers_with_held_locks_never_oversell_five_items(): void
    {
        $variant = $this->makeVariant(5);
        $results = $this->runConcurrently(array_map(fn () => $this->checkoutArgs([$variant->id => 1], extra: ['holdMs' => 30]), range(1, 12)));

        $this->assertNoUnexpectedErrors($results);
        $this->assertCount(5, array_filter($results, static fn ($r) => $r['ok']));
        $this->assertSame(0, (int) DB::table('product_variants')->where('id', $variant->id)->value('stock'));
        $this->assertInventoryConserved($variant, 5);
    }

    #[Test]
    #[Group('COM-04')]
    #[Group('concurrency')]
    public function multi_line_carts_lock_in_fixed_order_without_deadlock_or_oversell(): void
    {
        $a = $this->makeVariant(3, '10.00');
        $b = $this->makeVariant(3, '20.00');
        // 一半的購物車以 B、A 順序加入：服務端仍依 variant ID 遞增鎖定，不會互相死鎖
        $jobs = [];
        foreach (range(1, 8) as $i) {
            $jobs[] = $this->checkoutArgs($i % 2 === 0 ? [$a->id => 1, $b->id => 1] : [$b->id => 1, $a->id => 1], extra: ['holdMs' => 20]);
        }
        $results = $this->runConcurrently($jobs);

        $this->assertNoUnexpectedErrors($results);
        $this->assertCount(3, array_filter($results, static fn ($r) => $r['ok']));
        $this->assertInventoryConserved($a, 3);
        $this->assertInventoryConserved($b, 3);
        $this->assertSame(0, (int) DB::table('product_variants')->where('id', $a->id)->value('stock'));
        $this->assertSame(0, (int) DB::table('product_variants')->where('id', $b->id)->value('stock'));
    }

    #[Test]
    #[Group('COM-05')]
    #[Group('concurrency')]
    public function concurrent_resubmits_with_the_same_key_create_exactly_one_order(): void
    {
        $variant = $this->makeVariant(10);
        $args = $this->checkoutArgs([$variant->id => 2]);
        $results = $this->runConcurrently(array_fill(0, 6, $args));

        $this->assertNoUnexpectedErrors($results);
        $orderIds = array_unique(array_column($results, 'order_id'));
        $this->assertCount(6, array_filter($results, static fn ($r) => $r['ok']));
        $this->assertCount(1, $orderIds, '同一冪等鍵只能有一筆訂單');
        $this->assertCount(1, array_filter($results, static fn ($r) => $r['replayed'] === false));
        $this->assertSame(1, DB::table('orders')->where('idempotency_key', $args['idempotencyKey'])->count());
        $this->assertSame(8, (int) DB::table('product_variants')->where('id', $variant->id)->value('stock'));
        $this->assertInventoryConserved($variant, 10);
    }

    #[Test]
    #[Group('COM-05')]
    #[Group('COM-09')]
    #[Group('concurrency')]
    public function the_same_key_from_another_session_never_receives_someone_elses_order(): void
    {
        $variant = $this->makeVariant(10);
        $key = bin2hex(random_bytes(16));
        $alice = $this->checkoutArgs([$variant->id => 1], key: $key);
        $mallory = $this->checkoutArgs([$variant->id => 1], key: $key);
        $results = $this->runConcurrently([$alice, $mallory]);

        $this->assertNoUnexpectedErrors($results);
        $this->assertTrue($results[0]['ok'] && $results[1]['ok']);
        $this->assertNotSame($results[0]['order_id'], $results[1]['order_id']);
        $this->assertFalse($results[0]['replayed']);
        $this->assertFalse($results[1]['replayed']);
        $this->assertSame($alice['sessionBindingHash'], DB::table('orders')->where('id', $results[0]['order_id'])->value('session_binding_hash'));
        $this->assertSame($mallory['sessionBindingHash'], DB::table('orders')->where('id', $results[1]['order_id'])->value('session_binding_hash'));
        $this->assertInventoryConserved($variant, 10);
    }

    #[Test]
    #[Group('COM-06')]
    #[Group('concurrency')]
    public function a_transaction_failing_before_commit_leaves_no_trace_while_a_competitor_succeeds(): void
    {
        $variant = $this->makeVariant(3);
        $failing = $this->checkoutArgs([$variant->id => 2], extra: ['holdMs' => 400, 'failBeforeCommit' => true]);
        $normal = $this->checkoutArgs([$variant->id => 2]);
        $buffer = [];
        $procs = [$this->spawn($failing), $this->spawn($normal)];
        foreach ($procs as [, $pipes]) {
            $this->readLine($pipes, 'READY', $buffer);
        }
        // 先讓會失敗的交易取得列鎖（持有 400ms 後在提交前拋例外），再讓正常買家進來排隊等鎖
        fwrite($procs[0][1][0], "GO\n");
        usleep(150_000);
        fwrite($procs[1][1][0], "GO\n");
        $results = $this->collect($procs, $buffer);

        $this->assertFalse($results[0]['ok']);
        $this->assertSame('RuntimeException', $results[0]['error'] ?? null, json_encode($results));
        $this->assertTrue($results[1]['ok'], json_encode($results));
        $this->assertSame(0, DB::table('orders')->where('idempotency_key', $failing['idempotencyKey'])->count());
        $this->assertSame(1, DB::table('orders')->where('idempotency_key', $normal['idempotencyKey'])->count());
        $this->assertSame(1, (int) DB::table('product_variants')->where('id', $variant->id)->value('stock'));
        $this->assertSame([-2], DB::table('inventory_mutations')->where('product_variant_id', $variant->id)->pluck('delta')->map(static fn ($d) => (int) $d)->all());
        $this->assertSame(1, DB::table('order_items')->where('product_variant_id', $variant->id)->count());
        $this->assertInventoryConserved($variant, 3);
    }

    #[Test]
    #[Group('COM-06')]
    #[Group('concurrency')]
    public function a_failing_transaction_alone_rolls_back_order_items_stock_and_mutations(): void
    {
        $variant = $this->makeVariant(4);
        $failing = $this->checkoutArgs([$variant->id => 3], extra: ['failBeforeCommit' => true]);
        $results = $this->runConcurrently([$failing]);

        $this->assertSame('RuntimeException', $results[0]['error'] ?? null);
        $this->assertSame(4, (int) DB::table('product_variants')->where('id', $variant->id)->value('stock'));
        $this->assertSame(0, DB::table('orders')->where('idempotency_key', $failing['idempotencyKey'])->count());
        $this->assertSame(0, DB::table('order_items')->where('product_variant_id', $variant->id)->count());
        $this->assertSame(0, DB::table('inventory_mutations')->where('product_variant_id', $variant->id)->count());
    }

    /** 製造真實死鎖：流氓交易先改 B（可加更多列加重權重）再要 A；結帳交易依 ID 順序先鎖 A 再等 B。 */
    private function provokeDeadlock(ProductVariant $a, ProductVariant $b, array $extraRows): array
    {
        $buffer = [];
        [$rogueProc, $roguePipes] = $this->spawn(['mode' => 'rogue', 'lockFirst' => $b->id, 'lockSecond' => $a->id, 'extraRows' => $extraRows]);
        $this->readLine($roguePipes, 'READY', $buffer);
        fwrite($roguePipes[0], "GO\n");
        $this->readLine($roguePipes, 'LOCKED', $buffer);

        $checkoutArgs = $this->checkoutArgs([$a->id => 1, $b->id => 1]);
        [$checkoutProc, $checkoutPipes] = $this->spawn($checkoutArgs);
        $this->readLine($checkoutPipes, 'READY', $buffer);
        fwrite($checkoutPipes[0], "GO\n");
        usleep(500_000); // 讓結帳交易鎖住 A 並等待 B
        fwrite($roguePipes[0], "GO2\n");

        [$rogue, $checkout] = $this->collect([[$rogueProc, $roguePipes], [$checkoutProc, $checkoutPipes]], $buffer);
        fwrite(STDERR, sprintf("\n[deadlock] extra_rows=%d rogue_victim=%s checkout_attempts=%s\n", count($extraRows), json_encode($rogue['deadlock'] ?? null), json_encode($checkout['attempts'] ?? null)));

        return [$rogue, $checkout, $checkoutArgs];
    }

    #[Test]
    #[Group('COM-04')]
    #[Group('COM-06')]
    #[Group('concurrency')]
    public function a_real_mysql_deadlock_is_detected_and_checkout_still_completes_once(): void
    {
        $a = $this->makeVariant(5, '10.00');
        $b = $this->makeVariant(5, '20.00');
        [$rogue, $checkout, $args] = $this->provokeDeadlock($a, $b, []);

        $this->assertTrue($checkout['ok'], '結帳最終必須成立：'.json_encode([$rogue, $checkout]));
        $deadlockSeen = ($rogue['deadlock'] ?? false) === true || $checkout['attempts'] > 1;
        $this->assertTrue($deadlockSeen, 'MySQL 應偵測到死鎖：'.json_encode([$rogue, $checkout]));
        $this->assertSame(1, DB::table('orders')->where('idempotency_key', $args['idempotencyKey'])->count());
        $this->assertInventoryConserved($a, 5);
        $this->assertInventoryConserved($b, 5);
    }

    #[Test]
    #[Group('COM-04')]
    #[Group('COM-06')]
    #[Group('concurrency')]
    public function checkout_chosen_as_deadlock_victim_is_retried_and_succeeds(): void
    {
        $a = $this->makeVariant(5, '10.00');
        $b = $this->makeVariant(5, '20.00');
        $extra = array_map(fn () => $this->makeVariant(1)->id, range(1, 4));
        [$rogue, $checkout, $args] = $this->provokeDeadlock($a, $b, $extra);

        // 流氓交易改了 5 列、結帳交易尚未改任何列：InnoDB 會回滾較小的結帳交易，TransactionRunner 重試後成立
        $this->assertTrue($rogue['ok'], json_encode([$rogue, $checkout]));
        $this->assertTrue($checkout['ok'], json_encode([$rogue, $checkout]));
        $this->assertGreaterThanOrEqual(2, $checkout['attempts'], '結帳應因死鎖被回滾後重試');
        $this->assertSame(1, DB::table('orders')->where('idempotency_key', $args['idempotencyKey'])->count());
        $this->assertInventoryConserved($a, 5);
        $this->assertInventoryConserved($b, 5);
    }

    #[Test]
    #[Group('COM-08')]
    #[Group('concurrency')]
    public function concurrent_cancellations_restock_only_once(): void
    {
        $variant = $this->makeVariant(6);
        $result = app(CheckoutService::class)->placeOrder(new CheckoutCommand(
            bin2hex(random_bytes(32)), bin2hex(random_bytes(16)), self::token(), [$variant->id => 4], [$variant->id => '100.00'],
        ));
        $orderId = $result->order->id;
        $this->assertSame(2, (int) DB::table('product_variants')->where('id', $variant->id)->value('stock'));

        $results = $this->runConcurrently(array_fill(0, 6, ['mode' => 'cancel', 'orderId' => $orderId]));

        $this->assertNoUnexpectedErrors($results);
        $this->assertCount(1, array_filter($results, static fn ($r) => $r['changed'] === true), '只能有一次真正取消');
        $this->assertSame(6, (int) DB::table('product_variants')->where('id', $variant->id)->value('stock'));
        $this->assertSame(1, DB::table('inventory_mutations')->where(['order_id' => $orderId, 'reason' => 'order_cancelled'])->count());
        $this->assertSame('cancelled', DB::table('orders')->where('id', $orderId)->value('status'));
        $this->assertInventoryConserved($variant, 6);
    }
}
