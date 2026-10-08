<?php

namespace App\Console\Commands;

use App\Models\ApiToken;
use Illuminate\Console\Command;

class ListAdminApiTokensCommand extends Command
{
    protected $signature = 'newsroom:list-admin-api-tokens
                            {--user= : Only tokens owned by this email}';

    protected $description = 'List Admin API tokens (never shows token secrets)';

    public function handle(): int
    {
        $tokens = ApiToken::query()
            ->with('user')
            ->when($this->option('user'), fn ($query, $email) => $query->whereRelation('user', 'email', $email))
            ->orderBy('id')
            ->get();

        if ($tokens->isEmpty()) {
            $this->info('No Admin API tokens found.');

            return self::SUCCESS;
        }

        $this->table(
            ['ID', 'Name', 'Owner', 'Role', 'Last used', 'Created'],
            $tokens->map(fn (ApiToken $token) => [
                $token->id,
                $token->name,
                $token->user?->email ?? '(deleted user)',
                $token->user?->role->value ?? '-',
                $token->last_used_at?->toDateTimeString() ?? 'never',
                $token->created_at?->toDateTimeString() ?? '-',
            ])->all(),
        );

        return self::SUCCESS;
    }
}
