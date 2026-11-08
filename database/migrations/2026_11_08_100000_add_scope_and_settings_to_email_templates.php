<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Newsletter templates share the email template tables with system templates.
 * scope is non-null ("system", "newsletter" or "newsletter:<id>") because
 * MySQL does not enforce a unique index across NULL values.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('email_templates', function (Blueprint $table) {
            $table->string('scope', 64)->default('system')->after('key');
            $table->json('settings')->nullable()->after('body');
        });

        Schema::table('email_templates', function (Blueprint $table) {
            $table->dropUnique(['key']);
            $table->unique(['key', 'scope']);
        });

        Schema::table('email_template_versions', function (Blueprint $table) {
            $table->json('settings')->nullable()->after('body');
        });
    }

    public function down(): void
    {
        DB::table('email_templates')->where('scope', '!=', 'system')->delete();

        Schema::table('email_template_versions', function (Blueprint $table) {
            $table->dropColumn('settings');
        });

        Schema::table('email_templates', function (Blueprint $table) {
            $table->dropUnique(['key', 'scope']);
            $table->unique(['key']);
        });

        Schema::table('email_templates', function (Blueprint $table) {
            $table->dropColumn(['scope', 'settings']);
        });
    }
};
