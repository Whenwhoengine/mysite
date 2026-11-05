<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * 模擬商店商品（v5 §10.2）。金額一律 DECIMAL(12,2)；不在 WordPress 另存一份。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('products', function (Blueprint $table): void {
            $table->id();
            $table->string('slug', 120)->unique();
            $table->string('title', 200);
            $table->text('description')->nullable();
            $table->decimal('price', 12, 2);
            $table->string('status', 16)->default('draft')->index();
            $table->string('cover_url', 500)->nullable();
            $table->boolean('is_sample')->default(false);
            $table->timestamps();
        });

        DB::statement('ALTER TABLE products ADD CONSTRAINT chk_products_price_nonnegative CHECK (price >= 0)');
        DB::statement("ALTER TABLE products ADD CONSTRAINT chk_products_status CHECK (status IN ('draft','active','archived'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('products');
    }
};
