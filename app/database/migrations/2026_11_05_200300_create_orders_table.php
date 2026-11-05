<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * 模擬訂單（不收款、不出貨）。
 * - token_hash：查詢 token 的 SHA-256；token 明文不入此表。匿名化時設為 NULL（連結失效）。
 * - (session_binding_hash, idempotency_key) 唯一：同一 Session 重送同鍵只會有一筆；他人 Session 用同鍵不會命中。
 * - buyer_code：可選的合成測試代號（非個資）。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('orders', function (Blueprint $table): void {
            $table->id();
            $table->char('token_hash', 64)->nullable()->unique();
            $table->string('status', 16)->index();
            $table->char('currency', 3);
            $table->decimal('subtotal', 12, 2);
            $table->decimal('total', 12, 2);
            $table->unsignedInteger('item_count');
            $table->string('idempotency_key', 64)->nullable();
            $table->char('session_binding_hash', 64)->nullable();
            $table->string('buyer_code', 32)->nullable();
            $table->timestamp('placed_at')->index();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamp('anonymized_at')->nullable()->index();
            $table->timestamps();

            $table->unique(['session_binding_hash', 'idempotency_key'], 'orders_session_idempotency_unique');
        });

        DB::statement("ALTER TABLE orders ADD CONSTRAINT chk_orders_status CHECK (status IN ('placed','cancelled'))");
        DB::statement('ALTER TABLE orders ADD CONSTRAINT chk_orders_amounts_nonnegative CHECK (subtotal >= 0 AND total >= 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('orders');
    }
};
