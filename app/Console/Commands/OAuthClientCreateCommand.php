<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Laravel\Passport\ClientRepository;

/**
 * Z-5.3's own scope names "client management," but nothing beyond
 * `php artisan passport:client` (which doesn't know about this app's
 * `rate_limit_per_minute` column or its owner-carries-ACL design) existed to
 * create one. Every API client here is a client-credentials-grant client
 * (api-contract.md §1.1: "a client carries a user identity, its owner" --
 * that's how record-level ACL applies to client-credentials calls too), so
 * this only builds that grant type, not an authorization-code client.
 */
final class OAuthClientCreateCommand extends Command
{
    protected $signature = 'crm:oauth-client:create
        {name : A human-readable name for the client, e.g. "n8n integration"}
        {--owner= : Email of the user whose ACL this client\'s calls run under}
        {--rate-limit= : Requests per minute; omit to use the app default}';

    protected $description = 'Create a client-credentials OAuth client (Z-5.3)';

    public function handle(ClientRepository $clients): int
    {
        $name = (string) $this->argument('name');
        $ownerEmail = $this->stringOption('owner');
        $rateLimit = $this->stringOption('rate-limit');

        $owner = null;
        if ($ownerEmail !== null) {
            $owner = User::query()->where('email', $ownerEmail)->first();
            if ($owner === null) {
                $this->error("No user found with email [{$ownerEmail}].");

                return self::FAILURE;
            }
        }

        $client = $clients->createClientCredentialsGrantClient($name);

        if ($owner !== null) {
            $client->owner()->associate($owner);
        }

        if ($rateLimit !== null) {
            $limit = is_numeric($rateLimit) ? (int) $rateLimit : -1;
            if ($limit < 0) {
                $this->error('--rate-limit must be a non-negative number.');

                return self::FAILURE;
            }
            $client->rate_limit_per_minute = $limit;
        }

        $client->save();

        $this->info("Client created: {$client->id}");
        $this->warn("Client secret (shown once): {$client->plainSecret}");

        return self::SUCCESS;
    }

    private function stringOption(string $key): ?string
    {
        $value = $this->option($key);

        return is_string($value) && $value !== '' ? $value : null;
    }
}
