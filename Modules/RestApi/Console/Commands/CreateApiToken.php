<?php

namespace Modules\RestApi\Console\Commands;

use Illuminate\Console\Command;
use Modules\RestApi\Entities\ApiKey;
use Modules\RestApi\Support\MailboxAccess;

class CreateApiToken extends Command
{
    protected $signature = 'restapi:create-token 
                            {user_id : The user ID}
                            {name : Token name}
                            {--mailbox_ids= : Comma-separated mailbox IDs (leave empty for all)}
                            {--rate_limit=1000 : Rate limit per hour}
                            {--expires= : Expiration date (Y-m-d format)}';

    protected $description = 'Create a new API token for a user';

    public function handle()
    {
        $userId = $this->argument('user_id');
        $name = $this->argument('name');
        $mailboxIds = $this->option('mailbox_ids');
        $rateLimit = $this->option('rate_limit');
        $expires = $this->option('expires');

        // Verify user exists
        $user = \App\User::find($userId);
        if (!$user) {
            $this->error("User #{$userId} not found");
            return 1;
        }

        // Parse mailbox IDs. normalize() returns null (every mailbox) or
        // an array of integers, which is what the ApiKey json cast expects.
        $mailboxIdsList = MailboxAccess::normalize($mailboxIds);

        // Generate token
        $token = ApiKey::generateToken();
        $tokenHash = hash('sha256', $token);

        // Create API key
        $apiKey = ApiKey::create([
            'user_id' => $userId,
            'name' => $name,
            'token' => $token,
            'token_hash' => $tokenHash,
            'mailbox_ids' => $mailboxIdsList,
            'rate_limit' => $rateLimit,
            'active' => true,
            'expires_at' => $expires ? \Carbon\Carbon::parse($expires) : null,
        ]);

        $this->info("✓ API token created successfully!");
        $this->line("");
        $this->line("Token ID: {$apiKey->id}");
        $this->line("Token: {$token}");
        $this->line("User: {$user->getFullName()} (#{$userId})");
        if ($mailboxIdsList) {
            $this->line("Mailboxes: " . implode(', ', $mailboxIdsList));
        } else {
            $this->line("Mailboxes: All");
        }
        $this->line("Rate Limit: {$rateLimit}/hour");
        if ($expires) {
            $this->line("Expires: {$expires}");
        }
        $this->line("");
        $this->warn("⚠ Keep this token safe! It will not be shown again.");
        $this->line("");
        $this->info("Usage:");
        $this->line("curl -H 'Authorization: Bearer {$token}' http://yourhost/api/v1/conversations");

        return 0;
    }
}
