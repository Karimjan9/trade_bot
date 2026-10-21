<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('telegram_channels', fn (Blueprint $table) => $table->uuid('monitoring_error_key')->nullable());
        Schema::table('service_heartbeats', fn (Blueprint $table) => $table->uuid('monitoring_error_key')->nullable());
        Schema::create('monitoring_issues', function (Blueprint $table) {
            $table->id();
            $table->string('fingerprint', 64)->unique();
            $table->string('code');
            $table->string('kind');
            $table->string('severity');
            $table->string('status')->default('OPEN')->index();
            $table->string('title');
            $table->text('description');
            $table->text('question')->nullable();
            $table->foreignId('channel_id')->nullable()->constrained('telegram_channels');
            $table->foreignId('signal_id')->nullable()->constrained('signals');
            $table->foreignId('message_id')->nullable()->constrained('telegram_messages');
            $table->boolean('automatic')->default(true);
            $table->unsignedInteger('occurrence_count')->default(0);
            $table->unsignedInteger('status_version')->default(1);
            $table->json('first_evidence');
            $table->json('last_evidence');
            $table->timestamp('first_seen_at');
            $table->timestamp('last_seen_at');
            $table->timestamp('resolved_at')->nullable();
            $table->text('resolution')->nullable();
            $table->timestamps();
            $table->index(['channel_id', 'kind']);
        });
        Schema::create('monitoring_issue_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('issue_id')->constrained('monitoring_issues');
            $table->foreignId('user_id')->nullable()->constrained('users');
            $table->string('action');
            $table->string('event_key', 64)->nullable();
            $table->text('body')->nullable();
            $table->json('evidence')->nullable();
            $table->timestamp('created_at');
            $table->unique(['issue_id', 'event_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('monitoring_issue_events');
        Schema::dropIfExists('monitoring_issues');
        Schema::table('service_heartbeats', fn (Blueprint $table) => $table->dropColumn('monitoring_error_key'));
        Schema::table('telegram_channels', fn (Blueprint $table) => $table->dropColumn('monitoring_error_key'));
    }
};
