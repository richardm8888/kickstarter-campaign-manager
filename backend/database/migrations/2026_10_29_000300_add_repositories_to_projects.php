<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            // Where the work behind a task actually happens, so a prompt
            // handed to a coding agent can say which repository to open.
            $table->string('website_repo')->nullable();
            $table->string('assets_repo')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->dropColumn(['website_repo', 'assets_repo']);
        });
    }
};
