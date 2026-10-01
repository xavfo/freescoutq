<?php

namespace Modules\RestApi\Console\Commands;

use Illuminate\Console\Command;
use Modules\RestApi\Entities\ApiKey;

class RevokeApiToken extends Command
{
    protected $signature = 'restapi:revoke-token {token_id : The API token ID}';

    protected $description = 'Revoke an API token';

    public function handle()
    {
        $tokenId = $this->argument('token_id');

        $apiKey = ApiKey::find($tokenId);
        if (!$apiKey) {
            $this->error("API token #{$tokenId} not found");
            return 1;
        }

        $apiKey->active = false;
        $apiKey->save();

        $this->info("✓ API token revoked successfully!");
        $this->line("Token: {$apiKey->name} (#{$tokenId})");

        return 0;
    }
}
