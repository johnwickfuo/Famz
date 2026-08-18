<?php

namespace Tests;

use Dotenv\Dotenv;
use PDO;
use Throwable;

/**
 * A test case that runs against a real MySQL rather than the suite's SQLite.
 *
 * Some behaviour only exists on the production driver — the FULLTEXT index and
 * boolean-mode search above all — and testing it on SQLite would only prove the
 * fallback works. The environment is switched before the application boots, so
 * RefreshDatabase migrates MySQL rather than SQLite.
 *
 * When no MySQL is reachable the whole case skips, so a laptop without one
 * still gets a green suite while CI covers the real thing.
 */
abstract class MySqlTestCase extends TestCase
{
    /**
     * @var array<string, string|false>
     */
    private array $originalEnvironment = [];

    protected function setUp(): void
    {
        // The application has not booted yet, so .env has not been read.
        // Load it here or the reachability probe would try credentials the
        // real connection never uses and skip a perfectly good MySQL.
        $this->loadProjectEnvironmentFile();

        $database = $this->mysqlDatabase();

        if (! $this->mysqlIsReachable($database)) {
            $this->markTestSkipped(
                'No MySQL reachable at '.$this->mysqlHost().':'.$this->mysqlPort().'; skipping the driver-specific tests.'
            );
        }

        // Set before parent::setUp(), because that is what boots the
        // application and runs the migrations.
        $this->overrideEnvironment([
            'DB_CONNECTION' => 'mysql',
            'DB_DATABASE' => $database,
        ]);

        parent::setUp();
    }

    protected function tearDown(): void
    {
        parent::tearDown();

        $this->restoreEnvironment();
    }

    /**
     * Read .env into the environment if it has not been read already.
     */
    private function loadProjectEnvironmentFile(): void
    {
        $path = dirname(__DIR__);

        if (! is_file($path.'/.env') || getenv('DB_USERNAME') !== false) {
            return;
        }

        Dotenv::createImmutable($path)->safeLoad();
    }

    protected function mysqlDatabase(): string
    {
        return (string) (env('DB_TEST_DATABASE') ?: 'agriplatform_testing');
    }

    private function mysqlHost(): string
    {
        return (string) (env('DB_HOST') ?: '127.0.0.1');
    }

    private function mysqlPort(): string
    {
        return (string) (env('DB_PORT') ?: '3306');
    }

    private function mysqlIsReachable(string $database): bool
    {
        try {
            new PDO(
                sprintf('mysql:host=%s;port=%s;dbname=%s', $this->mysqlHost(), $this->mysqlPort(), $database),
                (string) (env('DB_USERNAME') ?: 'root'),
                (string) (env('DB_PASSWORD') ?: ''),
                [PDO::ATTR_TIMEOUT => 2],
            );

            return true;
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * @param  array<string, string>  $values
     */
    private function overrideEnvironment(array $values): void
    {
        foreach ($values as $key => $value) {
            $this->originalEnvironment[$key] = getenv($key);

            putenv("{$key}={$value}");
            $_ENV[$key] = $value;
            $_SERVER[$key] = $value;
        }
    }

    private function restoreEnvironment(): void
    {
        foreach ($this->originalEnvironment as $key => $value) {
            if ($value === false) {
                putenv($key);
                unset($_ENV[$key], $_SERVER[$key]);

                continue;
            }

            putenv("{$key}={$value}");
            $_ENV[$key] = $value;
            $_SERVER[$key] = $value;
        }

        $this->originalEnvironment = [];
    }
}
