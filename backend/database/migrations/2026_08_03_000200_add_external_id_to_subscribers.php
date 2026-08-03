<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('subscribers', function (Blueprint $table) {
            // Provider's id for this contact (e.g. a Meta lead id), so an
            // import can run repeatedly without creating duplicates.
            $table->string('external_id')->nullable()->after('source');
            $table->timestamp('synced_to_email_at')->nullable()->after('external_id');

            $table->unique(['project_id', 'external_id']);
        });
    }

    public function down(): void
    {
        Schema::table('subscribers', function (Blueprint $table) {
            $table->dropUnique(['project_id', 'external_id']);
            $table->dropColumn(['external_id', 'synced_to_email_at']);
        });
    }
};
