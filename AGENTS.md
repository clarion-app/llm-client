# LLM-Client Test Setup Notes

## Running Tests

```bash
vendor/bin/phpunit
vendor/bin/phpunit tests/Unit/ConversationMassAssignmentTest.php --filter update_with_extra_user_id_does_not_change_owner
```

## Why Tests Need Special Setup in `TestCase.php`

The llm-client package is tested with `Orchestra\Testbench` (not a full Laravel app). The `TestCase` must manually provide infrastructure that a real Laravel app would have:

### 1. `users` table (`defineDatabaseMigrations`)

Tests that use `User::factory()` need the `users` table. The `ClarionBackendServiceProvider` creates it, but we can't load that provider in tests because it pulls in Passport, multi-chain, and other heavy dependencies. Instead, `TestCase::defineDatabaseMigrations()` creates the table directly via `Schema::create()`.

### 2. `EloquentMultiChainBridge` disabled (`getEnvironmentSetUp`)

Many models (e.g., `Server`, `User`) use the `EloquentMultiChainBridge` trait which fires on `created`/`updated`/`deleted` events and tries to publish to a blockchain via the `multichain` service. In tests, set:

```php
$app['config']->set('eloquent-multichain-bridge.disabled', true);
```

This short-circuits the trait and avoids needing the `multichain` service, `data_stream_registries` table, etc.

### 3. Auth guard configured (`getEnvironmentSetUp`)

Tests using `$this->actingAs()` need an auth guard. `TestCase` configures a simple token-based `api` guard:

```php
$app['config']->set('auth.guards.api', [
    'driver'   => 'token',
    'provider' => 'users',
]);
$app['config']->set('auth.providers.users', [
    'driver' => 'eloquent',
    'model'  => \ClarionApp\Backend\Models\User::class,
]);
```

### 4. `UserFactory` autoload (`composer.json`)

The `User` model (`ClarionApp\Backend\Models\User`) uses `HasFactory`, which resolves to `Database\Factories\Backend\Models\UserFactory`. The `composer.json` autoload maps `Database\Factories\` to `database/factories/`. After adding a factory, run `composer dump-autoload`.

### 5. Stub `App\Http\Controllers\Controller` (`defineEnvironment`)

Package controllers extend `App\Http\Controllers\Controller`. A stub is created via `eval()` if the class doesn't exist.
