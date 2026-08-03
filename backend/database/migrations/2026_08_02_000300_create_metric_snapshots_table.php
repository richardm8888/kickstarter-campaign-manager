<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Append-only time series. Rows are never updated or deleted by the
     * application; history is preserved and new observations are inserted.
     */
    public function up(): void
    {
        Schema::create('metric_snapshots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->string('source');
            $table->string('metric');
            $table->decimal('value', 16, 4);
            $table->json('dimensions')->nullable();
            $table->timestamp('recorded_at');
            $table->timestamp('created_at')->useCurrent();

            $table->index(['project_id', 'metric', 'recorded_at']);
            $table->index(['project_id', 'source', 'recorded_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('metric_snapshots');
    }
};
