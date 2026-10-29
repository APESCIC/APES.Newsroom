<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

return new class extends Migration
{
    /**
     * Settled in #595 (v1.24.0). Each domain sends through its own Mailgun EU
     * domain. myapes.me.uk keeps strict DMARC, so its only From address is on
     * the sending subdomain itself.
     *
     * @var array<string, array{label: string, mailgun_domain: string, addresses: array<string, string>}>
     */
    private const DOMAINS = [
        'apespetcare.org.uk' => [
            'label' => 'APES Pet Care Clinic',
            'mailgun_domain' => 'mail.apespetcare.org.uk',
            'addresses' => ['news@apespetcare.org.uk' => 'news', 'noreply@apespetcare.org.uk' => 'noreply'],
        ],
        'apesshelter.org.uk' => [
            'label' => 'APES Shelter & Rescue',
            'mailgun_domain' => 'mail.apesshelter.org.uk',
            'addresses' => ['news@apesshelter.org.uk' => 'news', 'noreply@apesshelter.org.uk' => 'noreply'],
        ],
        'myapes.me.uk' => [
            'label' => 'MyAPES member and account newsletters',
            'mailgun_domain' => 'news.myapes.me.uk',
            'addresses' => ['news@news.myapes.me.uk' => 'news'],
        ],
    ];

    public function up(): void
    {
        $now = now();

        foreach (self::DOMAINS as $domain => $definition) {
            if (! DB::table('email_sending_domains')->where('domain', $domain)->exists()) {
                DB::table('email_sending_domains')->insert([
                    'domain' => $domain,
                    'label' => $definition['label'],
                    'mailgun_domain' => $definition['mailgun_domain'],
                    'status' => 'pending',
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }

            $domainId = DB::table('email_sending_domains')->where('domain', $domain)->value('id');
            foreach ($definition['addresses'] as $address => $purpose) {
                $this->addAddress((int) $domainId, $address, $purpose, $now);
            }
        }

        $this->addExistingNewsletterSenders($now);
    }

    /**
     * Only rows this migration seeded (no creator, seeded Mailgun domain) are
     * removed; a domain an admin added before this ran is kept.
     */
    public function down(): void
    {
        foreach (self::DOMAINS as $domain => $definition) {
            DB::table('email_sending_domains')
                ->where('domain', $domain)
                ->where('mailgun_domain', $definition['mailgun_domain'])
                ->whereNull('created_by')
                ->delete();
        }
    }

    /**
     * Existing newsletter From addresses join the list so they stay selectable.
     */
    private function addExistingNewsletterSenders(mixed $now): void
    {
        $domains = DB::table('email_sending_domains')->get(['id', 'domain', 'mailgun_domain']);

        $senders = DB::table('newsletters')->whereNotNull('sender_email')->pluck('sender_email');
        foreach ($senders as $sender) {
            $address = Str::lower(trim((string) $sender));
            $name = Str::after($address, '@');
            $domain = $domains->firstWhere('domain', $name) ?? $domains->firstWhere('mailgun_domain', $name);
            if ($domain !== null) {
                $this->addAddress((int) $domain->id, $address, 'news', $now);
            }
        }
    }

    private function addAddress(int $domainId, string $address, string $purpose, mixed $now): void
    {
        if (DB::table('email_sender_addresses')->where('address', $address)->exists()) {
            return;
        }

        DB::table('email_sender_addresses')->insert([
            'email_sending_domain_id' => $domainId,
            'address' => $address,
            'purpose' => $purpose,
            'enabled' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }
};
