<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ml_observations', function (Blueprint $table) {
            $table->string('path_state')->default('PENDING')->index();
            $table->json('path_metrics')->nullable();
            $table->timestamp('path_end_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('ml_observations', function (Blueprint $table) {
            $table->dropIndex(['path_state']);
            $table->dropColumn(['path_state', 'path_metrics', 'path_end_at']);
        });
    }
};
