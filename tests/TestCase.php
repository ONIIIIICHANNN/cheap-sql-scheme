<?php

namespace CheapSqlScheme\Tests;

use CheapSqlScheme\CheapSqlSchemeServiceProvider;
use CheapSqlScheme\Tests\Support\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    use RefreshDatabase;

    protected function getPackageProviders($app): array
    {
        return [CheapSqlSchemeServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('app.key', 'base64:'.base64_encode(str_repeat('k', 32)));
        $app['config']->set('database.default', 'testing');
        $app['config']->set('auth.providers.users.model', User::class);
        // Схему проекта проверяют отдельные тесты — остальным она в списке мешает.
        $app['config']->set('cheap-sql-scheme.project_schema.enabled', false);
    }

    protected function defineDatabaseMigrations(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/database/migrations');
    }

    protected function makeUser(string $email = 'dev@example.test'): User
    {
        return User::create(['name' => 'Dev', 'email' => $email, 'password' => 'secret']);
    }
}
