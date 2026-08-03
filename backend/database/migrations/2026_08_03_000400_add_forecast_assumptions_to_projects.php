<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            // Assumptions the creator tuned by hand, so the forecast screen
            // reopens where they left it rather than resetting to defaults.
            $table->json('forecast_assumptions')->nullable()->after('launch_date');
        });
    }

    public function down(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->dropColumn('forecast_assumptions');
        });
    }
};
