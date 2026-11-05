<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 預覽 session（API-11、API-13）：cookie 只帶隨機 token，DB 只存其 SHA-256；
 * 票券以 APP_KEY 加密保存；到期時間 = 票券 exp，到期由排程清除。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('preview_sessions', function (Blueprint $table): void {
            $table->id();
            $table->char('token_hash', 64)->unique();
            $table->char('jti', 32)->index();
            $table->string('content_type', 16);
            $table->unsignedBigInteger('content_id');
            $table->char('snapshot_id', 32);
            $table->unsignedBigInteger('revision_id');
            $table->text('ticket_ciphertext');
            $table->timestamp('expires_at')->index();
            $table->timestamp('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('preview_sessions');
    }
};
