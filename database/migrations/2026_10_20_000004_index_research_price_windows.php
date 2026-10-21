<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('market_snapshots', function (Blueprint $t) {
            $t->index(['symbol', 'recorded_at'], 'market_symbol_time_idx');
        });
        Schema::table('telegram_messages', function (Blueprint $t) {
            $t->index(['channel_id', 'posted_at'], 'messages_channel_time_idx');
        });
    }

    public function down(): void
    {
        Schema::table('market_snapshots', function (Blueprint $t) {
            $t->dropIndex('market_symbol_time_idx');
        });
        Schema::table('telegram_messages', function (Blueprint $t) {
            $t->dropIndex('messages_channel_time_idx');
        });
    }
};
