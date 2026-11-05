<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * 庫存異動（只追加）。(order_id, product_variant_id, reason) 唯一：
 * 同一訂單同一規格的「下單扣減」與「取消回補」各最多一筆，DB 層面防止重複回補（COM-08）。
 * 人工調整的 order_id 為 NULL（MySQL 唯一索引允許多個 NULL）。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory_mutations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('product_variant_id')->constrained('product_variants')->restrictOnDelete();
            $table->integer('delta');
            $table->unsignedInteger('stock_after');
            $table->string('reason', 32);
            $table->foreignId('order_id')->nullable()->constrained('orders')->restrictOnDelete();
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('note', 200)->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['order_id', 'product_variant_id', 'reason'], 'inventory_mutations_order_variant_reason_unique');
            $table->index(['product_variant_id', 'id']);
        });

        DB::statement('ALTER TABLE inventory_mutations ADD CONSTRAINT chk_inventory_mutations_delta_nonzero CHECK (delta <> 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_mutations');
    }
};
