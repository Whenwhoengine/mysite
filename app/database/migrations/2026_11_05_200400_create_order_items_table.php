<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * 訂單明細＝下單當下的快照（名稱、SKU、單價、數量、小計）。商品改價不回寫此表（COM-07）。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('order_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('order_id')->constrained('orders')->restrictOnDelete();
            $table->foreignId('product_variant_id')->constrained('product_variants')->restrictOnDelete();
            $table->unsignedBigInteger('product_id');
            $table->string('product_title', 200);
            $table->string('variant_name', 120);
            $table->string('sku', 64);
            $table->decimal('unit_price', 12, 2);
            $table->unsignedSmallInteger('quantity');
            $table->decimal('line_total', 12, 2);
            $table->timestamps();

            $table->unique(['order_id', 'product_variant_id']);
            $table->index('product_id');
        });

        DB::statement('ALTER TABLE order_items ADD CONSTRAINT chk_order_items_quantity CHECK (quantity >= 1)');
        DB::statement('ALTER TABLE order_items ADD CONSTRAINT chk_order_items_amounts CHECK (unit_price >= 0 AND line_total >= 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('order_items');
    }
};
