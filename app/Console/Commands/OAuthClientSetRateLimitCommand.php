<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Laravel\Passport\ClientRepository;

final class OAuthClientSetRateLimitCommand extends Command
{
    protected $signature = 'crm:oauth-client:set-rate-limit
        {id : The OAuth client\'s id}
        {limit : Requests per minute; pass 0 to clear it back to the app default}';

    protected $description = 'Set (or clear) an OAuth client\'s requests-per-minute limit (Z-5.3)';

    public function handle(ClientRepository $clients): int
    {
        $client = $clients->find((string) $this->argument('id'));

        if ($client === null) {
            $this->error('No client found with that id.');

            return self::FAILURE;
        }

        $limit = $this->argument('limit');
        if (! is_numeric($limit)) {
            $this->error('limit must be a number.');

            return self::FAILURE;
        }

        $value = (int) $limit;
        $client->rate_limit_per_minute = $value > 0 ? $value : null;
        $client->save();

        $this->info($value > 0
            ? "Client {$client->id} rate limit set to {$value}/minute."
            : "Client {$client->id} rate limit cleared -- back to the app default.");

        return self::SUCCESS;
    }
}
