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
        Schema::create('ops_health_snapshots', function (Blueprint $table) {
            $table->id();
            $table->date('date')->unique();
            $table->unsignedInteger('active_teams')->default(0);
            $table->decimal('mrr', 12, 2)->default(0);
            $table->unsignedInteger('churned_teams')->default(0);
            $table->unsignedBigInteger('total_orders_synced')->default(0);
            $table->unsignedInteger('failed_jobs_total')->default(0);
            $table->unsignedInteger('sms_cost_total')->default(0);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ops_health_snapshots');
    }
};
