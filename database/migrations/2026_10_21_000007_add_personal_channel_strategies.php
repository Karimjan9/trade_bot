<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('channel_strategies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('channel_id')->constrained('telegram_channels');
            $table->string('symbol');
            $table->string('direction');
            $table->string('fingerprint')->unique();
            $table->string('status')->index();
            $table->json('parameters')->nullable();
            $table->json('report');
            $table->timestamp('evidence_end_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();
            $table->index(['channel_id', 'symbol', 'direction']);
        });
        Schema::table('telegram_channels', function (Blueprint $table) {
            $table->string('trade_state')->default('PAPER_BASELINE');
            $table->string('trade_reason')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('telegram_channels', fn (Blueprint $table) => $table->dropColumn(['trade_state', 'trade_reason']));
        Schema::dropIfExists('channel_strategies');
    }
};
