<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            // Names, not accounts: the people sharing a project split the
            // work between them long before they each need a login.
            $table->json('team')->nullable();

            // What the AI needs to know to sound like the creators and get
            // the facts right — the game, the voice, the things to avoid.
            $table->text('content_brief')->nullable();

            // A provisional date still schedules the plan, but nothing
            // written for the public may announce it.
            $table->boolean('launch_date_confirmed')->default(false);
        });

        Schema::create('launch_tasks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();

            // Which playbook step this came from, so loading the playbook
            // twice adds what is missing instead of duplicating the plan.
            $table->string('template_key', 60)->nullable();

            $table->string('phase', 20);
            $table->string('category', 20);
            $table->string('title');
            $table->text('details')->nullable();
            $table->string('assignee', 60)->nullable();

            // Days relative to launch, negative before it. Stored instead
            // of a date so that moving the launch moves the whole plan.
            $table->smallInteger('offset_days')->nullable();
            // A fixed date for the few things that do not move with launch.
            $table->date('due_on')->nullable();

            $table->string('content_type', 30)->nullable();
            $table->unsignedInteger('sort_order')->default(0);

            $table->string('status', 10)->default('todo');
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->unique(['project_id', 'template_key']);
            $table->index(['project_id', 'status']);
        });

        Schema::create('launch_task_drafts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('launch_task_id')->constrained()->cascadeOnDelete();
            $table->longText('content');
            $table->text('notes')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('launch_task_drafts');
        Schema::dropIfExists('launch_tasks');

        Schema::table('projects', function (Blueprint $table) {
            $table->dropColumn(['team', 'content_brief', 'launch_date_confirmed']);
        });
    }
};
