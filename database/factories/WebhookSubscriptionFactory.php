<?php

namespace Database\Factories;

use App\Models\WebhookSubscription;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<WebhookSubscription>
 */
class WebhookSubscriptionFactory extends Factory
{
    protected $model = WebhookSubscription::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'event' => 'leads.created',
            'url' => fake()->url(),
            'secret' => Str::random(40),
            'active' => true,
        ];
    }
}
