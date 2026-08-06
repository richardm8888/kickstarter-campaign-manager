<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            // Friends and family who will back whatever happens. They are
            // not part of the audience the funnel measures — no ad bought
            // them and no conversion rate applies — so they are held
            // separately and added on top rather than mixed into a segment
            // whose rate would then be wrong in both directions.
            //
            // They matter most in the first 48 hours, when early momentum
            // decides whether Kickstarter's algorithm shows a project to
            // anyone else at all.
            $table->unsignedInteger('guaranteed_backers')->default(0)->after('average_pledge');
        });
    }

    public function down(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->dropColumn('guaranteed_backers');
        });
    }
};
