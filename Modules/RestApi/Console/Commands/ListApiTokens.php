<?php

namespace Modules\RestApi\Console\Commands;

use Illuminate\Console\Command;
use Modules\RestApi\Entities\ApiKey;

class ListApiTokens extends Command
{
    protected $signature = 'restapi:list-tokens {--user_id= : Filter by user ID}';

    protected $description = 'List all API tokens';

    public function handle()
    {
        $query = ApiKey::with('user');

        if ($this->option('user_id')) {
            $query->where('user_id', $this->option('user_id'));
        }

        $tokens = $query->get();

        if ($tokens->isEmpty()) {
            $this->info("No API tokens found");
            return 0;
        }

        $this->table(
            ['ID', 'User', 'Name', 'Active', 'Rate Limit', 'Created', 'Expires'],
            $tokens->map(function ($token) {
                return [
                    $token->id,
                    $token->user->getFullName(),
                    $token->name,
                    $token->active ? '✓' : '✗',
                    $token->rate_limit . '/h',
                    $token->created_at->format('Y-m-d H:i'),
                    $token->expires_at ? $token->expires_at->format('Y-m-d') : 'Never',
                ];
            })->toArray()
        );

        return 0;
    }
}
