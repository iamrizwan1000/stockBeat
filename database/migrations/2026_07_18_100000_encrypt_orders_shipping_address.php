<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Encrypts `orders.shipping_address` at rest. Safe to encrypt (unlike
 * `customer_name`/`customer_email`, deliberately left plaintext — see
 * app/Models/Order.php) because it is never queried at the SQL level
 * anywhere in the codebase, only read in PHP after Eloquent decrypts it
 * (e.g. `ConditionEvaluator`'s `customer_country`/`shipping_method`
 * conditions). Column type must move from `json` to `text` first —
 * ciphertext isn't valid JSON, so a `json` column would reject every write
 * once the model's cast starts encrypting on save. Uses `Schema::table`'s
 * native `change()` (Laravel 11+ no longer needs doctrine/dbal for this,
 * and it works on both MariaDB and the SQLite test DB) rather than a raw
 * `ALTER TABLE ... MODIFY` statement, which is MySQL/MariaDB-only syntax.
 */
return new class extends Migration
{
    public function up(): void
    {
        $rows = DB::table('orders')->select('id', 'shipping_address')->whereNotNull('shipping_address')->get();

        Schema::table('orders', function (Blueprint $table) {
            $table->text('shipping_address')->nullable()->change();
        });

        foreach ($rows as $row) {
            DB::table('orders')->where('id', $row->id)->update([
                'shipping_address' => Crypt::encryptString($row->shipping_address),
            ]);
        }
    }

    public function down(): void
    {
        $rows = DB::table('orders')->select('id', 'shipping_address')->whereNotNull('shipping_address')->get();

        foreach ($rows as $row) {
            DB::table('orders')->where('id', $row->id)->update([
                'shipping_address' => Crypt::decryptString($row->shipping_address),
            ]);
        }

        Schema::table('orders', function (Blueprint $table) {
            $table->json('shipping_address')->nullable()->change();
        });
    }
};
