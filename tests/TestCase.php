<?php

namespace Tests;

use App\Mail\DriverFactory;
use App\Models\ApiKey;
use App\Models\Provider;
use App\Models\Role;
use App\Models\RoutingRule;
use App\Models\User;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Tests\Support\FakeDriverFactory;

abstract class TestCase extends BaseTestCase
{
    protected FakeDriverFactory $drivers;

    protected function setUp(): void
    {
        parent::setUp();

        $this->drivers = new FakeDriverFactory;
        $this->app->instance(DriverFactory::class, $this->drivers);
    }

    protected function provider(string $name, array $attrs = []): Provider
    {
        return Provider::create(array_merge([
            'name' => $name,
            'type' => 'smtp',
            'config' => ['host' => 'localhost', 'port' => 25],
        ], $attrs));
    }

    /**
     * @param  array<string, array{0: int, 1: int}>  $providers  name => [priority, weight]
     */
    protected function rule(string $name, array $providers, array $attrs = []): RoutingRule
    {
        $rule = RoutingRule::create(array_merge(['name' => $name], $attrs));
        foreach ($providers as $provider => [$priority, $weight]) {
            $p = Provider::where('name', $provider)->first() ?? $this->provider($provider);
            $rule->providers()->attach($p->id, ['priority' => $priority, 'weight' => $weight]);
        }

        return $rule->load('providers');
    }

    /** A signed-in-able user with the built-in owner role (all permissions). */
    protected function owner(array $attrs = []): User
    {
        return User::factory()->create(array_merge(['role_id' => Role::owner()->id], $attrs));
    }

    protected function userWithRole(string $roleName): User
    {
        return User::factory()->create(['role_id' => Role::where('name', $roleName)->firstOrFail()->id]);
    }

    /** @return array{0: ApiKey, 1: string} */
    protected function apiKey(string $permission = 'full_access'): array
    {
        return ApiKey::generate('test', $permission);
    }
}
