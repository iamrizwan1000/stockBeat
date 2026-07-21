<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_provider_settings', function (Blueprint $table) {
            $table->id();
            $table->string('provider')->unique();
            $table->text('api_key')->nullable();
            $table->string('model')->nullable();
            $table->boolean('active')->default(false);
            $table->foreignId('updated_by')->nullable()->constrained('admin_users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_provider_settings');
    }
};
