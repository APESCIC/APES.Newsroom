<?php

namespace App\Console\Commands;

use App\Enums\Role;
use App\Models\ApiToken;
use App\Models\User;
use App\Notifications\Security\ApiTokenCreatedNotification;
use App\Services\Audit\AuditLogger;
use Illuminate\Console\Command;

class IssueAdminApiTokenCommand extends Command
{
    protected $signature = 'newsroom:issue-admin-api-token
                            {email : Staff user email}
                            {--name=default : Token label}
                            {--ability=* : Limit the token to these abilities (posts:read, posts:write, posts:publish); omit for all}
                            {--expires-days= : Expire the token after this many days; omit for no expiry}';

    protected $description = 'Issue a staff Admin API bearer token (shown once)';

    public function handle(AuditLogger $audit): int
    {
        $user = User::query()->where('email', $this->argument('email'))->first();

        if (! $user || ! $user->role->atLeast(Role::Staff)) {
            $this->error('Staff user not found.');

            return self::FAILURE;
        }

        $abilities = array_values(array_unique((array) $this->option('ability')));
        $unknown = array_diff($abilities, array_keys(ApiToken::ABILITIES));

        if ($unknown !== []) {
            $this->error('Unknown abilities: '.implode(', ', $unknown));

            return self::FAILURE;
        }

        $days = $this->option('expires-days');

        if ($days !== null && (! ctype_digit((string) $days) || (int) $days < 1)) {
            $this->error('--expires-days must be a whole number of days.');

            return self::FAILURE;
        }

        $issued = ApiToken::issue(
            $user,
            (string) $this->option('name'),
            $abilities === [] ? null : $abilities,
            $days === null ? null : now()->addDays((int) $days),
        );
        $audit->record(null, 'api_token.issued', $issued['model'], [
            'name' => $issued['model']->name,
            'owner_id' => $user->id,
            'abilities' => $issued['model']->abilities,
            'expires_at' => $issued['model']->expires_at?->toIso8601String(),
            'source' => 'cli',
        ]);
        $user->notify(new ApiTokenCreatedNotification($issued['model']->name));
        $this->info('Token id: '.$issued['model']->id);
        $this->line($issued['token']);
        $this->warn('Store this token now; it will not be shown again.');

        return self::SUCCESS;
    }
}
