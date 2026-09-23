<?php

namespace App\Console\Commands;

use App\Enums\Role;
use App\Models\ApiToken;
use App\Models\User;
use Illuminate\Console\Command;

class IssueAdminApiTokenCommand extends Command
{
    protected $signature = 'newsroom:issue-admin-api-token
                            {email : Staff user email}
                            {--name=default : Token label}';

    protected $description = 'Issue a staff Admin API bearer token (shown once)';

    public function handle(): int
    {
        $user = User::query()->where('email', $this->argument('email'))->first();

        if (! $user || ! $user->role->atLeast(Role::Staff)) {
            $this->error('Staff user not found.');

            return self::FAILURE;
        }

        $issued = ApiToken::issue($user, (string) $this->option('name'));
        $this->info('Token id: '.$issued['model']->id);
        $this->line($issued['token']);
        $this->warn('Store this token now; it will not be shown again.');

        return self::SUCCESS;
    }
}
