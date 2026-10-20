<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('telegram_channels', function (Blueprint $t) {
            $t->id();
            $t->string('username')->unique();
            $t->string('title');
            $t->string('source')->default('telegram');
            $t->string('telegram_id')->nullable()->unique();
            $t->boolean('enabled')->default(true);
            $t->boolean('learning_eligible')->default(false);
            $t->string('status')->default('NEW');
            $t->unsignedBigInteger('last_message_id')->default(0);
            $t->timestamp('last_seen_at')->nullable();
            $t->text('last_error')->nullable();
            $t->timestamps();
        });
        Schema::create('telegram_messages', function (Blueprint $t) {
            $t->id();
            $t->foreignId('channel_id')->constrained('telegram_channels');
            $t->unsignedBigInteger('telegram_message_id');
            $t->unsignedBigInteger('reply_to_message_id')->nullable();
            $t->text('raw_text');
            $t->json('raw_json');
            $t->text('latest_text');
            $t->string('message_type')->default('GENERAL_MESSAGE');
            $t->string('processing_status')->default('pending');
            $t->boolean('is_historical')->default(false);
            $t->timestamp('posted_at');
            $t->timestamp('edited_at')->nullable();
            $t->timestamp('deleted_at')->nullable();
            $t->timestamps();
            $t->unique(['channel_id', 'telegram_message_id']);
        });
        Schema::create('telegram_message_events', function (Blueprint $t) {
            $t->id();
            $t->uuid('event_id')->unique();
            $t->foreignId('channel_id')->constrained('telegram_channels');
            $t->unsignedBigInteger('telegram_message_id');
            $t->string('kind');
            $t->json('payload');
            $t->string('status')->default('pending')->index();
            $t->timestamp('occurred_at');
            $t->timestamp('processed_at')->nullable();
            $t->timestamps();
        });
        Schema::create('signals', function (Blueprint $t) {
            $t->id();
            $t->foreignId('channel_id')->constrained('telegram_channels');
            $t->foreignId('message_id')->unique()->constrained('telegram_messages');
            $t->string('symbol');
            $t->string('direction');
            $t->string('entry_type');
            $t->double('entry_min');
            $t->double('entry_max');
            $t->double('stop_loss');
            $t->json('take_profits');
            $t->double('confidence');
            $t->string('status')->default('ready')->index();
            $t->string('fingerprint')->index();
            $t->timestamp('signal_time');
            $t->timestamps();
        });
        Schema::create('signal_parses', function (Blueprint $t) {
            $t->id();
            $t->foreignId('message_id')->constrained('telegram_messages');
            $t->foreignId('source_event_id')->unique()->constrained('telegram_message_events');
            $t->string('parser_version');
            $t->json('result');
            $t->timestamps();
        });
        Schema::create('signal_events', function (Blueprint $t) {
            $t->id();
            $t->foreignId('signal_id')->constrained();
            $t->foreignId('source_event_id')->nullable()->constrained('telegram_message_events');
            $t->string('type');
            $t->json('payload');
            $t->timestamp('occurred_at');
            $t->timestamps();
            $t->unique(['signal_id', 'source_event_id', 'type']);
        });
        Schema::create('agents', function (Blueprint $t) {
            $t->id();
            $t->foreignId('channel_id')->constrained('telegram_channels');
            $t->string('name');
            $t->string('role')->default('champion');
            $t->string('state')->default('PAPER');
            $t->unsignedInteger('version')->default(1);
            $t->json('parameters');
            $t->timestamps();
            $t->unique(['channel_id', 'role']);
        });
        Schema::create('market_snapshots', function (Blueprint $t) {
            $t->id();
            $t->string('symbol')->index();
            $t->double('bid');
            $t->double('ask');
            $t->double('atr')->nullable();
            $t->string('regime')->default('UNKNOWN');
            $t->string('session')->default('UNKNOWN');
            $t->string('source');
            $t->boolean('market_open')->default(true);
            $t->boolean('is_historical')->default(false);
            $t->timestamp('recorded_at')->index();
            $t->timestamps();
        });
        Schema::create('risk_profiles', function (Blueprint $t) {
            $t->id();
            $t->boolean('emergency_stop')->default(false);
            $t->double('starting_balance')->default(10000);
            $t->double('max_trade_risk_pct')->default(0.5);
            $t->double('max_channel_risk_pct')->default(1);
            $t->double('max_symbol_risk_pct')->default(1.5);
            $t->double('max_portfolio_risk_pct')->default(3);
            $t->double('max_daily_loss_pct')->default(2);
            $t->double('max_weekly_loss_pct')->default(5);
            $t->double('max_drawdown_pct')->default(10);
            $t->unsignedInteger('max_positions')->default(5);
            $t->double('max_spread_r')->default(0.15);
            $t->double('min_reward_risk')->default(1);
            $t->timestamps();
        });
        DB::table('risk_profiles')->insert(['id' => 1, 'created_at' => now(), 'updated_at' => now()]);
        Schema::create('paper_trades', function (Blueprint $t) {
            $t->id();
            $t->foreignId('signal_id')->constrained();
            $t->foreignId('channel_id')->constrained('telegram_channels');
            $t->foreignId('agent_id')->constrained();
            $t->foreignId('snapshot_id')->constrained('market_snapshots');
            $t->string('mode')->default('paper');
            $t->string('strategy')->default('market');
            $t->string('symbol')->index();
            $t->string('direction');
            $t->string('status')->default('open')->index();
            $t->double('entry_price');
            $t->double('stop_loss');
            $t->double('take_profit');
            $t->double('initial_stop');
            $t->double('volume');
            $t->double('value_per_unit');
            $t->double('risk_amount');
            $t->double('remaining_fraction')->default(1);
            $t->double('commission');
            $t->double('realized_pnl')->default(0);
            $t->double('mae_r')->default(0);
            $t->double('mfe_r')->default(0);
            $t->double('exit_price')->nullable();
            $t->string('close_reason')->nullable();
            $t->timestamp('opened_at');
            $t->timestamp('closed_at')->nullable();
            $t->timestamps();
            $t->unique(['signal_id', 'mode', 'strategy']);
        });
        Schema::create('trade_events', function (Blueprint $t) {
            $t->id();
            $t->foreignId('trade_id')->constrained('paper_trades');
            $t->string('type');
            $t->json('payload');
            $t->timestamp('occurred_at');
            $t->timestamps();
        });
        Schema::create('agent_decisions', function (Blueprint $t) {
            $t->id();
            $t->foreignId('signal_id')->constrained();
            $t->foreignId('agent_id')->nullable()->constrained();
            $t->foreignId('snapshot_id')->nullable()->constrained('market_snapshots');
            $t->string('action');
            $t->double('score')->default(0);
            $t->json('reasons');
            $t->json('context');
            $t->timestamps();
        });
        Schema::create('channel_profiles', function (Blueprint $t) {
            $t->id();
            $t->foreignId('channel_id')->unique()->constrained('telegram_channels');
            $t->json('metrics');
            $t->json('dna');
            $t->double('trust_score')->nullable();
            $t->string('drift')->default('INSUFFICIENT_DATA');
            $t->timestamp('computed_at');
            $t->timestamps();
        });
        Schema::create('backtests', function (Blueprint $t) {
            $t->id();
            $t->foreignId('channel_id')->constrained('telegram_channels');
            $t->string('status');
            $t->string('strategy');
            $t->json('parameters');
            $t->json('results');
            $t->timestamps();
        });
        Schema::create('service_heartbeats', function (Blueprint $t) {
            $t->id();
            $t->string('service')->unique();
            $t->string('status');
            $t->json('metadata');
            $t->timestamp('seen_at');
            $t->timestamps();
        });
        Schema::create('audit_logs', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->nullable()->constrained();
            $t->string('action')->index();
            $t->string('subject')->nullable();
            $t->json('before')->nullable();
            $t->json('after')->nullable();
            $t->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        foreach (['audit_logs', 'service_heartbeats', 'backtests', 'channel_profiles', 'agent_decisions', 'trade_events', 'paper_trades', 'risk_profiles', 'market_snapshots', 'agents', 'signal_events', 'signal_parses', 'signals', 'telegram_message_events', 'telegram_messages', 'telegram_channels'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
