<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * 刪除／匿名化紀錄（DAT-06）：還原舊備份後以 `php artisan mysite:deletion-log:reapply` 重套，避免個資復活。
 * 本表只記錄對象型別、ID 與條件，不含被刪除的內容。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('data_deletion_log', function (Blueprint $table): void {
            $table->id();
            $table->string('subject_type', 32);
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->string('action', 16);
            $table->json('criteria')->nullable();
            $table->string('reason', 32);
            $table->unsignedInteger('affected_rows')->default(0);
            $table->timestamp('executed_at');
            $table->timestamp('created_at')->useCurrent();

            $table->index(['subject_type', 'subject_id']);
            $table->index('executed_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('data_deletion_log');
    }
};
