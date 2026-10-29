<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Settled in #596 and #597 (v1.25.2). Both domains are already Mailgun EU
     * domains at the root, with their DNS published, so the mail. subdomains
     * seeded in v1.24.0 are not used.
     *
     * @var array<string, string>
     */
    private const DOMAINS = [
        'apespetcare.org.uk' => 'mail.apespetcare.org.uk',
        'apesshelter.org.uk' => 'mail.apesshelter.org.uk',
    ];

    public function up(): void
    {
        foreach (self::DOMAINS as $domain => $subdomain) {
            $this->move($domain, from: $subdomain, to: $domain);
        }
    }

    public function down(): void
    {
        foreach (self::DOMAINS as $domain => $subdomain) {
            $this->move($domain, from: $domain, to: $subdomain);
        }
    }

    /**
     * Only the seeded row (no creator) is changed. It goes back to pending so
     * the Mailgun domain is checked before anything sends through it.
     */
    private function move(string $domain, string $from, string $to): void
    {
        DB::table('email_sending_domains')
            ->where('domain', $domain)
            ->where('mailgun_domain', $from)
            ->whereNull('created_by')
            ->update([
                'mailgun_domain' => $to,
                'status' => 'pending',
                'last_check' => null,
                'last_checked_at' => null,
                'verified_at' => null,
                'webhooks' => null,
                'webhooks_checked_at' => null,
                'updated_at' => now(),
            ]);
    }
};
