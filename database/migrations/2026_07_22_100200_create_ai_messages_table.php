<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('conversation_id')->constrained('ai_conversations')->cascadeOnDelete();
            $table->string('role');
            $table->text('content')->nullable();
            $table->json('tool_calls')->nullable();
            $table->timestamps();

            $table->index('conversation_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_messages');
    }
};
