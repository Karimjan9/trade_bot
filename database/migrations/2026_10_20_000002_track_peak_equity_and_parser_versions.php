<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('risk_profiles', function (Blueprint $t) {
            $t->double('peak_equity')->default(10000);
        });
        Schema::table('signal_parses', function (Blueprint $t) {
            $t->dropUnique(['source_event_id']);
            $t->unique(['source_event_id', 'parser_version']);
        });
    }

    public function down(): void
    {
        Schema::table('signal_parses', function (Blueprint $t) {
            $t->dropUnique(['source_event_id', 'parser_version']);
        });
        Schema::table('risk_profiles', function (Blueprint $t) {
            $t->dropColumn('peak_equity');
        });
    }
};
