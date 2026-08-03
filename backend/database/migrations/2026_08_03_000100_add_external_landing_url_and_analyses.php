<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            // Set when the creator uses their own landing page instead of ours.
            $table->string('external_landing_url')->nullable()->after('description');
        });

        // Append-only: every analysis is kept so progress is visible over time.
        Schema::create('landing_page_analyses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->string('url');
            $table->unsignedTinyInteger('score');
            $table->json('checks');
            $table->text('summary')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['project_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('landing_page_analyses');

        Schema::table('projects', function (Blueprint $table) {
            $table->dropColumn('external_landing_url');
        });
    }
};
