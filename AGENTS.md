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

---

## Real-Database Test Suite

Two test targets exist:

```bash
composer test            # fast suite — runs everything except real-db tests
composer test:real-db    # gated target — runs only tests/RealDatabase/ with MariaDB
```

`composer test` excludes the `real-db` group via `--exclude-group real-db`. The gated target requires a MariaDB 11.7+ instance and runs the package's real migrations against it.

### Docker (default)

With Docker available, `composer test:real-db` automatically starts a `mariadb:11.8` container, runs migrations, executes the checks, and removes the container. No configuration needed.

Without Docker, every check skips with a stated reason and the command exits green.

### Supplied Database Instance

To point the harness at a database you supply instead of starting a container:

```bash
LLM_CLIENT_REAL_DB_ALLOW_SUPPLIED=1 \
LLM_CLIENT_REAL_DB_HOST=127.0.0.1 \
LLM_CLIENT_REAL_DB_PORT=3306 \
LLM_CLIENT_REAL_DB_DATABASE=clarion_realdb_scratch \
LLM_CLIENT_REAL_DB_USERNAME=... \
LLM_CLIENT_REAL_DB_PASSWORD=... \
composer test:real-db
```

The `ALLOW_SUPPLIED=1` opt-in flag is **required**. Supplying connection details without it is an error (not a silent fall-back to Docker). This prevents accidentally pointing the harness at a production database.

The harness will refuse a database that already contains tables it did not create — it runs migrations, and a schema it does not own is treated as somebody's data.

### Strict Mode

```bash
LLM_CLIENT_REAL_DB_STRICT=1 composer test:real-db
```

In strict mode, an unavailable database is a **failure** rather than a skip. CI uses strict mode so that a broken database configuration fails the pipeline.

### Concurrency Limitation

Runs that each start their own Docker container are isolated by construction — each gets a unique database name and password.

**Two concurrent runs pointed at one supplied instance will corrupt each other and that arrangement is unsupported.** The harness truncates tables between tests but does not coordinate across processes.

### Troubleshooting

| Symptom | Meaning |
|---|---|
| `No tests executed!` from `composer test:real-db` | The gate is broken, not idle. The command carries `--fail-on-empty-test-suite` so this exits non-zero. |
| Everything skipped, "no usable Docker daemon" | Docker is not running or unreachable. Expected on machines without it. |
| "Incapable: VEC_DISTANCE_COSINE unavailable" | The instance is below MariaDB 11.7 or is MySQL. Not a skip — the product cannot work there. |
| "Refusing to migrate: schema contains tables this run did not create" | The isolation guard fired. Point at an empty database or let the harness start its own container. |
| A query returns nothing and the fixture looks right | Check the search term against the token floor (≥ 3 characters) and the stopword list. |
| Scores differ in the fifth decimal | Expected — `VECTOR` is float32. Compare with the `1e-4` tolerance helper, never with equality. |

### Writing a New Real-Database Check

```php
#[Group('real-db')]
final class MyRealDatabaseTest extends RealDatabaseTestCase
{
    public function test_something_on_the_real_engine(): void
    {
        // Base class has already: resolved a database, probed capabilities,
        // verified isolation, run the real migrations, and truncated.
        // It has also asserted the driver is mysql — a scenario that lands on
        // SQLite fails as inconclusive rather than passing against the fallback.

        $fixture = EmbeddingFixture::orthogonalSet(dimension: 8);
        $fixture->seed();

        $results = app(MemoryService::class)->searchSemantic(/* ... */);

        $this->assertRankingMatches($fixture->expectedOrder(), $results);
    }
}
```

Key rules:
1. Assert order, not presence — a reversal must fail.
2. Cross-check meaning-based ranking against `EmbeddingService::cosineSimilarity()` within `1e-4`.
3. Fixture terms are ≥ 3 characters and not stopwords.
4. Never call an embedding or model service — fixture vectors are literals.
