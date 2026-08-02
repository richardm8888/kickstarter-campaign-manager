<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('subscribers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->string('email');
            $table->boolean('is_vip')->default(false);
            $table->string('source')->default('landing_page');
            $table->timestamps();

            $table->unique(['project_id', 'email']);
        });

        Schema::create('insights', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->string('kind')->default('insight');
            $table->string('severity')->default('info');
            $table->string('title');
            $table->text('body');
            $table->string('action')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('acknowledged_at')->nullable();
            $table->timestamps();

            $table->index(['project_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('insights');
        Schema::dropIfExists('subscribers');
    }
};
