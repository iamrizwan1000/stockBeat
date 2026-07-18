<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Pairs with `approved_by` for the real send-approval gate (Plan
     * §8.7.5 audit gap #2): a genuine `POST .../approve` stamps both
     * together, rather than `approved_by` being set as a side effect of
     * the send itself.
     */
    public function up(): void
    {
        Schema::table('broadcasts', function (Blueprint $table) {
            $table->timestamp('approved_at')->nullable()->after('approved_by');
        });
    }

    public function down(): void
    {
        Schema::table('broadcasts', function (Blueprint $table) {
            $table->dropColumn('approved_at');
        });
    }
};
