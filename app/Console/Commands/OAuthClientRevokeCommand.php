<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Laravel\Passport\ClientRepository;

/**
 * Revoking sets `revoked = true` -- the exact flag
 * ClientRepository::findActive() (and so AuthenticateApiToken) already checks
 * to reject a client's calls. Reversible: crm:oauth-client:create has no
 * "unrevoke" counterpart because nothing needs one yet -- create a fresh
 * client instead if a revoked one needs to work again.
 */
final class OAuthClientRevokeCommand extends Command
{
    protected $signature = 'crm:oauth-client:revoke {id : The OAuth client\'s id}';

    protected $description = 'Revoke an OAuth client so its tokens stop authenticating (Z-5.3)';

    public function handle(ClientRepository $clients): int
    {
        $client = $clients->find((string) $this->argument('id'));

        if ($client === null) {
            $this->error('No client found with that id.');

            return self::FAILURE;
        }

        $client->forceFill(['revoked' => true])->save();

        $this->info("Client {$client->id} ({$client->name}) revoked.");

        return self::SUCCESS;
    }
}
