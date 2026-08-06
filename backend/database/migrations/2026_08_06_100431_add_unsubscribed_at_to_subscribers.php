<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('subscribers', function (Blueprint $table) {
            // Someone who has left is not an audience. Counting them
            // inflates every forecast built on the list, and the error
            // grows exactly as a campaign ages.
            //
            // The row stays rather than being deleted: it is how a
            // re-subscribe is recognised as a returning person, and how a
            // departed contact avoids being re-imported as brand new.
            $table->timestamp('unsubscribed_at')->nullable()->after('synced_to_email_at');

            $table->index(['project_id', 'unsubscribed_at']);
        });
    }

    public function down(): void
    {
        Schema::table('subscribers', function (Blueprint $table) {
            $table->dropIndex(['project_id', 'unsubscribed_at']);
            $table->dropColumn('unsubscribed_at');
        });
    }
};
