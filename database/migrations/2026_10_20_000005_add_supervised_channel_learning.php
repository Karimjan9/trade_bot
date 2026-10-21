<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ml_observations', function (Blueprint $t) {
            $t->id();
            $t->foreignId('signal_id')->unique()->constrained();
            $t->foreignId('channel_id')->constrained('telegram_channels');
            $t->foreignId('snapshot_id')->constrained('market_snapshots');
            $t->string('feature_version');
            $t->json('features');
            $t->json('context');
            $t->json('plan');
            $t->string('origin');
            $t->string('state')->default('PENDING')->index();
            $t->timestamp('observed_at')->index();
            $t->timestamp('label_end_at')->nullable();
            $t->json('outcome')->nullable();
            $t->timestamps();
        });
        Schema::create('channel_ml_models', function (Blueprint $t) {
            $t->id();
            $t->foreignId('channel_id')->constrained('telegram_channels');
            $t->string('fingerprint')->unique();
            $t->string('state')->index();
            $t->string('feature_version');
            $t->json('artifact')->nullable();
            $t->json('report');
            $t->timestamp('data_until')->nullable();
            $t->timestamp('activated_at')->nullable();
            $t->timestamp('expires_at')->nullable();
            $t->timestamps();
        });
        Schema::create('ml_predictions', function (Blueprint $t) {
            $t->id();
            $t->foreignId('observation_id')->constrained('ml_observations');
            $t->foreignId('model_id')->constrained('channel_ml_models');
            $t->double('probability');
            $t->double('expected_r');
            $t->string('action');
            $t->json('explanation');
            $t->timestamps();
            $t->unique(['observation_id', 'model_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ml_predictions');
        Schema::dropIfExists('channel_ml_models');
        Schema::dropIfExists('ml_observations');
    }
};
