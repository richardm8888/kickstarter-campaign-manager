<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('landing_page_analyses', function (Blueprint $table) {
            // Landing pages and Kickstarter pages are audited against
            // different checks but share this history, so each row says
            // which it is. Everything already stored is a landing page.
            $table->string('page_type', 20)->default('landing')->after('url');

            // The AI's UX walk, kept apart from the deterministic checks:
            // it never moves the score, so a model's opinion cannot shift
            // a number creators track week to week.
            $table->json('findings')->nullable()->after('checks');
        });
    }

    public function down(): void
    {
        Schema::table('landing_page_analyses', function (Blueprint $table) {
            $table->dropColumn(['page_type', 'findings']);
        });
    }
};
