<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('daily_tasks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();

            // The day this task was first raised for. Tasks carry forward
            // rather than being re-raised, so this is when it appeared,
            // not the only day it is shown.
            $table->date('for_date');

            // The detector that raised it. Stable across days so the same
            // problem is recognised as the same task: without it a task
            // done yesterday reappears this morning as new work.
            $table->string('signal_key', 60);

            $table->string('priority', 10);
            $table->string('title');
            $table->text('why');
            $table->text('action');
            $table->unsignedSmallInteger('effort_minutes');
            $table->string('impact', 10);

            // The numbers behind it, kept so the reasoning can be shown
            // and so a stale task can be recognised when they move.
            $table->json('evidence')->nullable();
            $table->decimal('score', 6, 3);

            $table->string('status', 12)->default('open');
            $table->timestamp('completed_at')->nullable();

            $table->timestamps();

            // One live task per problem per project.
            $table->unique(['project_id', 'signal_key', 'for_date']);
            $table->index(['project_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('daily_tasks');
    }
};
