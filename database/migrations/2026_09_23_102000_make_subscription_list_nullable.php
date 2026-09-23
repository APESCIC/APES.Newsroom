<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mailing_list_subscriptions', function (Blueprint $table) {
            $table->string('list')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('mailing_list_subscriptions', function (Blueprint $table) {
            $table->string('list')->nullable(false)->change();
        });
    }
};
