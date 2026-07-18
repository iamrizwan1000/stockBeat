<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Custom notification sound per rule (Plan §4.4: "push notification (with
 * custom sound option — the 'cha-ching')"). Nullable — `null` means "use
 * the device/app default", same convention as every other optional rule
 * setting (`controls`, `conditions`). Restricted to a small fixed catalog
 * (see `Rule::SOUNDS`) rather than free text, since these are bundled
 * sound-file keys the mobile app ships, not arbitrary strings.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rules', function (Blueprint $table) {
            $table->string('sound')->nullable()->after('actions');
        });
    }

    public function down(): void
    {
        Schema::table('rules', function (Blueprint $table) {
            $table->dropColumn('sound');
        });
    }
};
