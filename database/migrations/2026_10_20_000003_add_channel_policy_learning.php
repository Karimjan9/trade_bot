<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('telegram_channels', function (Blueprint $t) {
            $t->json('research_settings')->nullable();
        });
        Schema::table('signals', function (Blueprint $t) {
            $t->double('stop_loss')->nullable()->change();
        });
        Schema::table('paper_trades', function (Blueprint $t) {
            $t->double('take_profit')->nullable()->change();
            $t->json('management_policy')->nullable();
            $t->timestamp('expires_at')->nullable();
        });
        Schema::create('channel_policies', function (Blueprint $t) {
            $t->id();
            $t->foreignId('channel_id')->constrained('telegram_channels');
            $t->string('symbol');
            $t->string('direction');
            $t->string('status');
            $t->string('method')->default('chronological-grid-1');
            $t->string('fingerprint')->unique();
            $t->double('pip_size');
            $t->double('sl_pips')->nullable();
            $t->double('target_pips')->nullable();
            $t->double('lock_step_pips')->nullable();
            $t->unsignedInteger('horizon_seconds');
            $t->json('evidence');
            $t->timestamp('evidence_end_at')->nullable();
            $t->timestamp('expires_at')->nullable();
            $t->timestamps();
            $t->index(['channel_id', 'symbol', 'direction', 'status']);
        });
        Schema::create('claim_checks', function (Blueprint $t) {
            $t->id();
            $t->foreignId('signal_event_id')->constrained('signal_events');
            $t->unsignedInteger('claim_index');
            $t->string('fingerprint')->unique();
            $t->string('status');
            $t->double('claimed_pips')->nullable();
            $t->double('observed_pips')->nullable();
            $t->double('shortfall_pips')->nullable();
            $t->json('evidence');
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('claim_checks');
        Schema::dropIfExists('channel_policies');
        Schema::table('paper_trades', function (Blueprint $t) {
            $t->dropColumn(['management_policy', 'expires_at']);
        });
        Schema::table('telegram_channels', function (Blueprint $t) {
            $t->dropColumn('research_settings');
        });
        // Nullable provider fields are retained to preserve incomplete historical evidence.
    }
};
