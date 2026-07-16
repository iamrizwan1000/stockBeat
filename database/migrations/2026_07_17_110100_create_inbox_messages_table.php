<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inbox_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('thread_id')->constrained('inbox_threads')->cascadeOnDelete();
            $table->string('direction');
            $table->text('body');
            $table->foreignId('sent_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('external_id')->nullable();
            $table->string('status')->default('sent');
            $table->timestamp('created_at')->nullable();

            $table->index('thread_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inbox_messages');
    }
};
