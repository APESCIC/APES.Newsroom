<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Settled in #598 (v1.25.1). The MyAPES newsletter sends from
     * news@myapes.me.uk, and DMARC on myapes.me.uk is strict, so the Mailgun
     * domain must be myapes.me.uk itself rather than news.myapes.me.uk.
     */
    private const DOMAIN = 'myapes.me.uk';

    private const SUBDOMAIN = 'news.myapes.me.uk';

    private const ROOT_ADDRESS = 'news@myapes.me.uk';

    private const SUBDOMAIN_ADDRESS = 'news@news.myapes.me.uk';

    public function up(): void
    {
        $this->move(from: self::SUBDOMAIN, to: self::DOMAIN, oldAddress: self::SUBDOMAIN_ADDRESS, newAddress: self::ROOT_ADDRESS);
    }

    public function down(): void
    {
        $this->move(from: self::DOMAIN, to: self::SUBDOMAIN, oldAddress: self::ROOT_ADDRESS, newAddress: self::SUBDOMAIN_ADDRESS);
    }

    /**
     * Only the seeded row (no creator) is changed. It goes back to pending so
     * the new Mailgun domain is checked before anything sends through it. An
     * old address a newsletter still uses is kept alongside the new one.
     */
    private function move(string $from, string $to, string $oldAddress, string $newAddress): void
    {
        $domain = DB::table('email_sending_domains')
            ->where('domain', self::DOMAIN)
            ->whereIn('mailgun_domain', [$from, $to])
            ->whereNull('created_by')
            ->first(['id', 'mailgun_domain']);

        if ($domain === null) {
            return;
        }

        $domainId = $domain->id;
        $now = now();

        if ($domain->mailgun_domain === $from) {
            DB::table('email_sending_domains')->where('id', $domainId)->update([
                'mailgun_domain' => $to,
                'status' => 'pending',
                'last_check' => null,
                'last_checked_at' => null,
                'verified_at' => null,
                'webhooks' => null,
                'webhooks_checked_at' => null,
                'updated_at' => $now,
            ]);
        }

        $old = DB::table('email_sender_addresses')->where('address', $oldAddress)->where('email_sending_domain_id', $domainId)->first();
        $newExists = DB::table('email_sender_addresses')->where('address', $newAddress)->exists();
        $oldInUse = $old !== null && $this->usedByNewsletter($oldAddress);

        if ($old !== null && ! $oldInUse && ! $newExists) {
            DB::table('email_sender_addresses')->where('id', $old->id)->update([
                'address' => $newAddress,
                'last_tested_at' => null,
                'last_test_result' => null,
                'updated_at' => $now,
            ]);

            return;
        }

        if ($old !== null && ! $oldInUse) {
            DB::table('email_sender_addresses')->where('id', $old->id)->delete();
        }

        if (! $newExists) {
            DB::table('email_sender_addresses')->insert([
                'email_sending_domain_id' => $domainId,
                'address' => $newAddress,
                'purpose' => 'news',
                'enabled' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    private function usedByNewsletter(string $address): bool
    {
        return DB::table('newsletters')
            ->whereRaw('LOWER(sender_email) = ?', [$address])
            ->orWhereRaw('LOWER(reply_to) = ?', [$address])
            ->exists();
    }
};
