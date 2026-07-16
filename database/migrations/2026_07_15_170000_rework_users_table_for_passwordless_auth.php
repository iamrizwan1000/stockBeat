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
            $table->dropColumn(['password', 'remember_token', 'email_verified_at']);

            $table->string('business_name')->nullable()->after('email');
            $table->string('base_currency', 3)->default('USD')->after('business_name');
            $table->string('timezone')->nullable()->after('base_currency');
            $table->json('sells_on')->nullable()->after('timezone');
            $table->boolean('marketing_opt_in')->default(false)->after('sells_on');
            $table->timestamp('last_active_at')->nullable()->after('marketing_opt_in');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn([
                'business_name',
                'base_currency',
                'timezone',
                'sells_on',
                'marketing_opt_in',
                'last_active_at',
            ]);

            $table->string('password')->nullable();
            $table->rememberToken();
            $table->timestamp('email_verified_at')->nullable();
        });
    }
};
