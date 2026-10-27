<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Encrypted values are stored as ciphertext, so those columns are text.
     *
     * @var list<string>
     */
    private const COLUMNS = [
        'address_line1',
        'address_line2',
        'address_town',
        'address_county',
        'address_postcode',
        'address_country',
        'mobile_number',
        'whatsapp_type',
        'whatsapp_value',
        'whatsapp_same_as_mobile',
    ];

    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->text('address_line1')->nullable();
            $table->text('address_line2')->nullable();
            $table->text('address_town')->nullable();
            $table->text('address_county')->nullable();
            $table->text('address_postcode')->nullable();
            $table->char('address_country', 2)->nullable()->default('GB');
            $table->text('mobile_number')->nullable();
            $table->string('whatsapp_type', 16)->nullable();
            $table->text('whatsapp_value')->nullable();
            $table->boolean('whatsapp_same_as_mobile')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(self::COLUMNS);
        });
    }
};
