<?php

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Encrypts plaintext secrets in place. Rows that already decrypt are
     * skipped, so the migration is safe to run again.
     */
    public function up(): void
    {
        DB::table('webhook_endpoints')->orderBy('id')->each(function (object $row) {
            if ($this->isEncrypted((string) $row->secret)) {
                return;
            }

            DB::table('webhook_endpoints')
                ->where('id', $row->id)
                ->update(['secret' => Crypt::encryptString((string) $row->secret)]);
        });
    }

    /**
     * Decrypts back to plaintext so the column can be narrowed again.
     */
    public function down(): void
    {
        DB::table('webhook_endpoints')->orderBy('id')->each(function (object $row) {
            if (! $this->isEncrypted((string) $row->secret)) {
                return;
            }

            DB::table('webhook_endpoints')
                ->where('id', $row->id)
                ->update(['secret' => Crypt::decryptString((string) $row->secret)]);
        });
    }

    private function isEncrypted(string $value): bool
    {
        try {
            Crypt::decryptString($value);

            return true;
        } catch (DecryptException) {
            return false;
        }
    }
};
