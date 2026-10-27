<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Complimentary access sits beside the Stripe-driven status, so webhooks
     * never overwrite it (#570). The staff note is encrypted, so it is text.
     */
    public function up(): void
    {
        Schema::table('memberships', function (Blueprint $table) {
            $table->timestamp('comped_at')->nullable();
            $table->foreignId('comped_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('comp_reason', 500)->nullable();
            $table->text('staff_note')->nullable();
            $table->timestamp('staff_note_updated_at')->nullable();
            $table->foreignId('staff_note_updated_by')->nullable()->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('memberships', function (Blueprint $table) {
            $table->dropConstrainedForeignId('comped_by');
            $table->dropConstrainedForeignId('staff_note_updated_by');
            $table->dropColumn(['comped_at', 'comp_reason', 'staff_note', 'staff_note_updated_at']);
        });
    }
};
