<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Laravel\Passport\Client;

final class OAuthClientListCommand extends Command
{
    protected $signature = 'crm:oauth-client:list';

    protected $description = 'List OAuth clients: id, name, owner, rate limit, revoked (Z-5.3)';

    public function handle(): int
    {
        $rows = Client::query()->orderBy('name')->get()->map(function (Client $client): array {
            $owner = $client->owner_type === User::class
                ? User::query()->find($client->owner_id)
                : null;

            return [
                $client->id,
                $client->name,
                $owner === null ? '—' : $owner->email,
                $client->rate_limit_per_minute ?? '(default)',
                $client->revoked ? 'yes' : 'no',
            ];
        })->all();

        $this->table(['ID', 'Name', 'Owner', 'Rate limit/min', 'Revoked'], $rows);

        return self::SUCCESS;
    }
}
