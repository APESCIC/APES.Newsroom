<?php

namespace App\Console\Commands;

use App\Models\ApiToken;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;

class RevokeAdminApiTokenCommand extends Command
{
    protected $signature = 'newsroom:revoke-admin-api-token
                            {id? : Token id (see newsroom:list-admin-api-tokens)}
                            {--name= : Revoke tokens with this name}
                            {--user= : Revoke tokens owned by this email}
                            {--all : Revoke every Admin API token}
                            {--force : Do not ask for confirmation}';

    protected $description = 'Revoke (delete) Admin API tokens by id, name, owner, or all';

    public function handle(): int
    {
        $query = $this->selection();

        if ($query === null) {
            return self::FAILURE;
        }

        $count = $query->count();

        if ($count === 0) {
            $this->warn('No matching Admin API tokens.');

            return self::SUCCESS;
        }

        if (! $this->option('force') && ! $this->confirm("Revoke {$count} Admin API token(s)?")) {
            $this->line('Nothing revoked.');

            return self::FAILURE;
        }

        $query->delete();
        $this->info("Revoked {$count} Admin API token(s).");

        return self::SUCCESS;
    }

    /**
     * @return Builder<ApiToken>|null
     */
    private function selection(): ?Builder
    {
        $id = $this->argument('id');
        $name = $this->option('name');
        $email = $this->option('user');

        if ($this->option('all')) {
            if ($id !== null || $name !== null || $email !== null) {
                $this->error('--all cannot be combined with an id, --name or --user.');

                return null;
            }

            return ApiToken::query();
        }

        if ($id === null && $name === null && $email === null) {
            $this->error('Specify a token id, --name, --user, or --all.');

            return null;
        }

        return ApiToken::query()
            ->when($id !== null, fn (Builder $query) => $query->whereKey($id))
            ->when($name !== null, fn (Builder $query) => $query->where('name', $name))
            ->when($email !== null, fn (Builder $query) => $query->whereRelation('user', 'email', $email));
    }
}
