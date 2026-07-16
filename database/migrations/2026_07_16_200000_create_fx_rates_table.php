<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fx_rates', function (Blueprint $table) {
            $table->id();
            $table->string('base', 3);
            $table->string('quote', 3);
            $table->decimal('rate', 18, 8);
            $table->date('date');
            $table->timestamps();

            $table->unique(['base', 'quote', 'date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fx_rates');
    }
};
