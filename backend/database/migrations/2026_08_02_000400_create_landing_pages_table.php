<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('landing_pages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->string('template')->default('classic');
            $table->string('slug')->unique();
            $table->boolean('published')->default(false);
            $table->json('theme')->nullable();
            $table->timestamps();
        });

        Schema::create('landing_page_sections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('landing_page_id')->constrained()->cascadeOnDelete();
            $table->string('type');
            $table->unsignedInteger('position')->default(0);
            $table->boolean('enabled')->default(true);
            $table->json('content')->nullable();
            $table->timestamps();

            $table->index(['landing_page_id', 'position']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('landing_page_sections');
        Schema::dropIfExists('landing_pages');
    }
};
