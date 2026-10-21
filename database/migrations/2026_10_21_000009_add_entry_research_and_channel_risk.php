<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('telegram_channels', fn (Blueprint $table) => $table->json('risk_settings')->nullable());
        Schema::table('signals', fn (Blueprint $table) => $table->json('entry_plan')->nullable());
        Schema::table('paper_trades', fn (Blueprint $table) => $table->json('entry_legs')->nullable());
        Schema::create('entry_experiments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('observation_id')->constrained('ml_observations');
            $table->string('fingerprint', 64)->unique();
            $table->json('report');
            $table->timestamp('evidence_end_at');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('entry_experiments');
        Schema::table('paper_trades', fn (Blueprint $table) => $table->dropColumn('entry_legs'));
        Schema::table('signals', fn (Blueprint $table) => $table->dropColumn('entry_plan'));
        Schema::table('telegram_channels', fn (Blueprint $table) => $table->dropColumn('risk_settings'));
    }
};
