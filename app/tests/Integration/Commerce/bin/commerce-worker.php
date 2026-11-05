<?php

declare(strict_types=1);

/*
 * 併發測試用的獨立程序（只供 tests/Integration/Commerce 使用，不隨正式程式部署）。
 * 每個程序自行啟動 Laravel、自建 MySQL 連線；印出 READY 後等待 stdin 的 GO 才動作，
 * 讓多個程序在同一瞬間搶同一批庫存列。結果以一行「RESULT <json>」輸出。
 *
 * 用法：php commerce-worker.php <base64(json)>
 *   mode=checkout：sessionBindingHash、idempotencyKey、orderToken、quantities、priceSnapshots、holdMs、failBeforeCommit
 *   mode=cancel：orderId
 *   mode=rogue：lockFirst、lockSecond（先更新 lockFirst 取得列鎖，印 LOCKED，等 GO2 再鎖 lockSecond，用來製造真實死鎖）
 */

use App\Domain\Commerce\Checkout\CheckoutCommand;
use App\Domain\Commerce\Checkout\CheckoutService;
use App\Domain\Commerce\Exceptions\CheckoutRejected;
use App\Domain\Commerce\Notifications\SimulatedOrderNotifier;
use App\Domain\Commerce\Orders\OrderCancellationService;
use App\Models\Order;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

$args = json_decode((string) base64_decode($argv[1] ?? '', true), true, 16, JSON_THROW_ON_ERROR);
$db = (string) getenv('MYSITE_TEST_DB');
if (preg_match('/^mysite_app_test(_[a-z0-9]{1,20})?$/', $db) !== 1) {
    fwrite(STDERR, "worker：MYSITE_TEST_DB 不是測試庫，拒絕執行\n");
    exit(2);
}
foreach ([
    'APP_ENV' => 'testing', 'DB_CONNECTION' => 'mysql', 'DB_DATABASE' => $db, 'CACHE_STORE' => 'array',
    'SESSION_DRIVER' => 'array', 'QUEUE_CONNECTION' => 'sync', 'MAIL_MAILER' => 'array', 'COMMERCE_MODE' => 'simulation', 'APP_DEBUG' => 'false',
] as $k => $v) {
    putenv("{$k}={$v}");
    $_ENV[$k] = $v;
    $_SERVER[$k] = $v;
}

$root = dirname(__DIR__, 4);
require $root.'/vendor/autoload.php';
$app = require $root.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
if (DB::connection()->getDatabaseName() !== $db || DB::selectOne('select database() as d')->d !== $db) {
    fwrite(STDERR, "worker：實際連線不是測試庫，拒絕執行\n");
    exit(3);
}

$out = static function (array $result): void {
    fwrite(STDOUT, 'RESULT '.json_encode($result, JSON_UNESCAPED_UNICODE)."\n");
    fflush(STDOUT);
};
$waitFor = static function (string $word): void {
    $line = fgets(STDIN);
    if ($line === false || trim($line) !== $word) {
        exit(4);
    }
};

fwrite(STDOUT, "READY\n");
fflush(STDOUT);
$waitFor('GO');

try {
    switch ($args['mode']) {
        case 'checkout':
            $service = new class(app(SimulatedOrderNotifier::class), (int) ($args['holdMs'] ?? 0), (bool) ($args['failBeforeCommit'] ?? false)) extends CheckoutService
            {
                public function __construct(SimulatedOrderNotifier $notifier, private readonly int $holdMs, private readonly bool $fail)
                {
                    parent::__construct($notifier);
                }

                protected function afterStockLocked(Collection $variants): void
                {
                    if ($this->holdMs > 0) {
                        usleep($this->holdMs * 1000); // 持有列鎖一段時間，放大競爭
                    }
                }

                protected function beforeCommit(Order $order): void
                {
                    if ($this->fail) {
                        throw new RuntimeException('故意在提交前失敗');
                    }
                }
            };
            $quantities = [];
            foreach ($args['quantities'] as $id => $qty) {
                $quantities[(int) $id] = (int) $qty;
            }
            $snapshots = [];
            foreach ($args['priceSnapshots'] as $id => $price) {
                $snapshots[(int) $id] = (string) $price;
            }
            $result = $service->placeOrder(new CheckoutCommand(
                $args['sessionBindingHash'], $args['idempotencyKey'], $args['orderToken'], $quantities, $snapshots,
            ));
            $out(['ok' => true, 'order_id' => $result->order->id, 'replayed' => $result->replayed, 'attempts' => $result->attempts]);
            break;

        case 'cancel':
            $changed = app(OrderCancellationService::class)->cancel(Order::query()->findOrFail($args['orderId']), null, 'concurrency-test');
            $out(['ok' => true, 'changed' => $changed]);
            break;

        case 'rogue':
            DB::beginTransaction();
            // 實際改值（不是 no-op），讓此交易在死鎖選犧牲者時有「權重」；extraRows 讓它更重
            $touched = array_merge([$args['lockFirst']], $args['extraRows'] ?? []);
            DB::table('product_variants')->whereIn('id', $touched)->update(['updated_at' => now()->subYears(1)->subSeconds(random_int(1, 999))]);
            fwrite(STDOUT, "LOCKED\n");
            fflush(STDOUT);
            $waitFor('GO2');
            try {
                DB::table('product_variants')->where('id', $args['lockSecond'])->lockForUpdate()->first();
                usleep(200_000);
                DB::commit();
                $out(['ok' => true, 'deadlock' => false]);
            } catch (QueryException $e) {
                DB::rollBack();
                $out(['ok' => false, 'deadlock' => ($e->errorInfo[1] ?? null) === 1213]);
            }
            break;

        default:
            $out(['ok' => false, 'error' => 'unknown mode']);
    }
} catch (CheckoutRejected $e) {
    $out(['ok' => false, 'rejected' => array_values($e->problems)]);
} catch (Throwable $e) {
    $out(['ok' => false, 'error' => get_class($e), 'message' => mb_substr($e->getMessage(), 0, 300)]);
}
