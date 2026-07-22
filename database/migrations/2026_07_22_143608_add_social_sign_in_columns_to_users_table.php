<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Nullable + unique, not a composite/polymorphic table — a user
            // has at most one Apple and one Google identity (Plan §4.1/§17.1:
            // all sign-in paths converge on one User record by verified
            // email; these columns let a *returning* social user be matched
            // by stable subject id first, without needing a lookup table).
            $table->string('apple_sub')->nullable()->unique()->after('signup_ip');
            $table->string('google_sub')->nullable()->unique()->after('apple_sub');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['apple_sub', 'google_sub']);
        });
    }
};
