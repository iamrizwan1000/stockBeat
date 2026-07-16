<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('signup_ip')->nullable()->after('marketing_opt_in');
        });

        Schema::table('store_connections', function (Blueprint $table) {
            $table->string('fingerprint')->nullable()->after('credentials');
            $table->index('fingerprint');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('signup_ip');
        });

        Schema::table('store_connections', function (Blueprint $table) {
            $table->dropIndex(['fingerprint']);
            $table->dropColumn('fingerprint');
        });
    }
};
