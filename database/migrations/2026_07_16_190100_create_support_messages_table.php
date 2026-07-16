<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('support_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('thread_id')->constrained('support_threads')->cascadeOnDelete();
            $table->string('direction');
            $table->foreignId('admin_id')->nullable()->constrained('admin_users')->nullOnDelete();
            $table->text('body');
            $table->json('attachments')->nullable();
            $table->json('delivered_via')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index('thread_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('support_messages');
    }
};
