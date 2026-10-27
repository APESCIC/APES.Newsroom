<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

return new class extends Migration
{
    /**
     * Confirmed with Bambie for v1.18.0 (#479): apesnews.org.uk for newsletter
     * senders, apes.org.uk for the info@apes.org.uk reply-to.
     *
     * @var array<string, string>
     */
    private const DOMAINS = [
        'apesnews.org.uk' => 'APES Newsroom newsletters',
        'apes.org.uk' => 'APES main domain (reply-to)',
    ];

    public function up(): void
    {
        $domains = self::DOMAINS;
        foreach ((array) config('mailing.sender_domains', []) as $extra) {
            $extra = Str::lower(trim((string) $extra));
            if ($extra !== '' && ! isset($domains[$extra])) {
                $domains[$extra] = null;
            }
        }

        $now = now();

        foreach ($domains as $domain => $label) {
            if (DB::table('email_sending_domains')->where('domain', $domain)->exists()) {
                continue;
            }

            $verified = $this->hasVerifiedNewsletterSender($domain);

            DB::table('email_sending_domains')->insert([
                'domain' => $domain,
                'label' => $label,
                'status' => $verified ? 'verified' : 'pending',
                'verified_at' => $verified ? $now : null,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        DB::table('email_sending_domains')->whereIn('domain', array_keys(self::DOMAINS))->delete();
    }

    /**
     * Carry over an existing Mailgun verification so sends are not blocked
     * between deploy and the first domain check.
     */
    private function hasVerifiedNewsletterSender(string $domain): bool
    {
        return DB::table('newsletters')
            ->whereNotNull('sender_email')
            ->whereNotNull('sender_verification')
            ->get(['sender_email', 'sender_verification'])
            ->contains(function (object $row) use ($domain) {
                $verification = json_decode((string) $row->sender_verification, true);

                return is_array($verification)
                    && ($verification['status'] ?? null) === 'verified'
                    && Str::lower(Str::after((string) $row->sender_email, '@')) === $domain;
            });
    }
};
