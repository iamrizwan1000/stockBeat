<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contact_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('thread_id')->constrained('contact_threads')->cascadeOnDelete();
            $table->string('direction');
            $table->foreignId('admin_id')->nullable()->constrained('admin_users')->nullOnDelete();
            $table->text('body');
            $table->timestamp('created_at')->nullable();

            $table->index('thread_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contact_messages');
    }
};
