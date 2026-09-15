<?php

declare(strict_types=1);

/**
 * SPMS migration runner (M0 foundation; D-071 multi-database).
 *
 * Usage:
 *   php database/migrate.php                        apply pending migrations
 *                                                   (configured data environment)
 *   php database/migrate.php --status               show applied/pending migrations
 *   php database/migrate.php --environment=demo     target a named environment
 *                                                   (real|demo|test — trusted CLI
 *                                                   input resolved through .env
 *                                                   groups, never a request value)
 *   php database/migrate.php --all-environments     apply to real AND demo (one
 *                                                   identical migration set)
 *   php database/migrate.php --fresh --confirm=DROP:<dbname>
 *                                                   drop + recreate + re-migrate
 *                                                   (explicit name-bound confirm)
 *   php database/migrate.php --verify               compare applied migration id
 *                                                   sets across the configured
 *                                                   environments (schema drift)
 *
 * Each migration file lives in database/migrations/ and returns an array:
 *   [
 *     'id'   => '0001_create_migrations_table',
 *     'up'   => static function (PDO $pdo): void { ... },
 *   ]
 *
 * Applied migrations are recorded in the per-database `migrations` table —
 * each of spms_real / spms_demo / spms_test carries its own tracking table
 * over the identical migration set (docs/10 D-071).
 * The runner applies migrations in filename order inside a transaction.
 */

require dirname(__DIR__) . '/vendor/autoload.php';

use App\Support\Config;
use App\Support\DataEnvironments;

Config::initialize(dirname(__DIR__));

/**
 * Resolve CLI flags. --environment overrides the process-wide profile for
 * targeting purposes; database names are validated against a strict
 * identifier pattern and must come from a configured SPMS_DB_*_DATABASE
 * group (free-form names are refused: the CLI can only address databases
 * the server configuration already declares).
 */
$argvMap = array_slice($argv, 1);

$statusOnly = in_array('--status', $argvMap, true);
$verifyOnly = in_array('--verify', $argvMap, true);
$allEnvironments = in_array('--all-environments', $argvMap, true);
$fresh = in_array('--fresh', $argvMap, true);

$knownFlags = ['--status', '--verify', '--all-environments', '--fresh'];
$targetEnvironment = null;
$confirm = null;
foreach ($argvMap as $arg) {
    if (str_starts_with($arg, '--environment=')) {
        try {
            $targetEnvironment = DataEnvironments::assertProfile(substr($arg, strlen('--environment=')));
        } catch (Throwable $e) {
            fwrite(STDERR, $e->getMessage() . PHP_EOL);
            exit(1);
        }
    } elseif (str_starts_with($arg, '--confirm=')) {
        $confirm = substr($arg, strlen('--confirm='));
    } elseif (in_array($arg, $knownFlags, true)) {
        continue;
    } elseif (str_starts_with($arg, '--')) {
        fwrite(STDERR, "Unknown option: {$arg}" . PHP_EOL);
        exit(1);
    }
}

/** @var list<string> $environments */
if ($allEnvironments) {
    $environments = [DataEnvironments::REAL, DataEnvironments::DEMO];
} elseif ($targetEnvironment !== null) {
    $environments = [$targetEnvironment];
} else {
    $environments = [(string) Config::get('SPMS_DATA_ENVIRONMENT')];
}

$migrations = collectMigrations();

if ($verifyOnly) {
    exit(verifyEnvironments($environments, $migrations) ? 0 : 1);
}

$exitCode = 0;
foreach ($environments as $environment) {
    if (!runEnvironment($environment, $migrations, $statusOnly, $fresh, $confirm)) {
        $exitCode = 1;
    }
}
exit($exitCode);

/** @return array<string, array{id:string, up:callable}> */
function collectMigrations(): array
{
    $files = glob(__DIR__ . '/migrations/*.php') ?: [];
    sort($files);

    $set = [];
    foreach ($files as $file) {
        $migration = require $file;
        $id = $migration['id'] ?? basename($file, '.php');
        $set[$id] = $migration;
    }

    return $set;
}

function connect(array $config, bool $create, bool $select = true): PDO
{
    $dsn = sprintf(
        'mysql:host=%s;port=%s;charset=%s',
        $config['HOST'] !== '' ? $config['HOST'] : '127.0.0.1',
        $config['PORT'] !== '' ? $config['PORT'] : '3306',
        $config['CHARSET'] !== '' ? $config['CHARSET'] : 'utf8mb4'
    );

    $pdo = new PDO(
        $dsn,
        $config['USERNAME'],
        $config['PASSWORD'],
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]
    );

    $database = $config['DATABASE'];

    if (preg_match(DataEnvironments::NAME_PATTERN, $database) !== 1) {
        throw new RuntimeException('Refusing to address an invalid database name.');
    }

    if ($create) {
        $pdo->exec("CREATE DATABASE IF NOT EXISTS `{$database}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    }
    if ($select) {
        $pdo->exec("USE `{$database}`");
    }

    return $pdo;
}

function runEnvironment(string $environment, array $migrations, bool $statusOnly, bool $fresh, ?string $confirm): bool
{
    try {
        $config = DataEnvironments::connectionConfig($environment);
    } catch (Throwable $e) {
        fwrite(STDERR, "[{$environment}] " . $e->getMessage() . PHP_EOL);
        return false;
    }

    $database = $config['DATABASE'];
    echo "--- environment: {$environment}  database: `{$database}` ---" . PHP_EOL;

    try {
        if ($fresh) {
            $expected = 'DROP:' . $database;
            if ($confirm !== $expected) {
                fwrite(STDERR, "[{$environment}] --fresh refused: pass exactly --confirm={$expected}" . PHP_EOL);
                return false;
            }
            $pdo = connect($config, false, select: false);
            $pdo->exec("DROP DATABASE IF EXISTS `{$database}`");
            echo "[{$environment}] dropped `{$database}` (if it existed)" . PHP_EOL;
        }

        $pdo = connect($config, true);
    } catch (Throwable $e) {
        fwrite(STDERR, "[{$environment}] connection failed: " . $e->getMessage() . PHP_EOL);
        return false;
    }

    $pdo->exec(<<<'SQL'
        CREATE TABLE IF NOT EXISTS migrations (
            id VARCHAR(191) NOT NULL,
            applied_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        SQL);

    echo "[{$environment}] migrations tracking table ready" . PHP_EOL;

    $applied = $pdo->query('SELECT id FROM migrations')->fetchAll(PDO::FETCH_COLUMN);
    $appliedMap = array_flip($applied);

    $pendingCount = 0;
    foreach ($migrations as $id => $migration) {
        if (isset($appliedMap[$id])) {
            echo "[{$environment}] [applied] {$id}" . PHP_EOL;
            continue;
        }

        if ($statusOnly) {
            echo "[{$environment}] [pending] {$id}" . PHP_EOL;
            $pendingCount++;
            continue;
        }

        echo "[{$environment}] [running] {$id} ..." . PHP_EOL;

        $pdo->beginTransaction();
        try {
            ($migration['up'])($pdo);
            $record = $pdo->prepare('INSERT INTO migrations (id) VALUES (:id)');
            $record->execute(['id' => $id]);
            // DDL statements end the active transaction (implicit commit), so
            // only commit when a transaction is still open.
            if ($pdo->inTransaction()) {
                $pdo->commit();
            }
            echo "[{$environment}] [applied] {$id}" . PHP_EOL;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            fwrite(STDERR, "[{$environment}] [failed]  {$id}: " . $e->getMessage() . PHP_EOL);
            return false;
        }
    }

    if ($statusOnly && $pendingCount === 0) {
        echo "[{$environment}] No pending migrations." . PHP_EOL;
    }

    if (!$statusOnly && $pendingCount === 0) {
        echo "[{$environment}] Nothing to migrate." . PHP_EOL;
    }

    return true;
}

/**
 * Drift check: every addressed environment must have applied exactly the
 * current migration id set (per-database `migrations` tables are the
 * legitimate metadata and stay in place — D-071).
 */
function verifyEnvironments(array $environments, array $migrations): bool
{
    $expected = array_keys($migrations);
    $seen = [];
    $driftFound = false;

    foreach ($environments as $environment) {
        try {
            $config = DataEnvironments::connectionConfig($environment);
            $pdo = connect($config, false);
            $applied = $pdo->query('SELECT id FROM migrations ORDER BY id')->fetchAll(PDO::FETCH_COLUMN);
        } catch (Throwable $e) {
            fwrite(STDERR, "[verify] [{$environment}] " . $e->getMessage() . PHP_EOL);
            return false;
        }

        $missing = array_diff($expected, $applied);
        $unknown = array_diff($applied, $expected);
        $seen[$environment] = $applied;

        if ($missing === [] && $unknown === []) {
            echo "[verify] [{$environment}] `{$config['DATABASE']}` at " . (end($applied) ?: 'baseline') . " (" . count($applied) . " migrations)" . PHP_EOL;
        } else {
            $driftFound = true;
            echo "[verify] [{$environment}] DRIFT:" . PHP_EOL;
            foreach ($missing as $id) {
                echo "    missing: {$id}" . PHP_EOL;
            }
            foreach ($unknown as $id) {
                echo "    unknown: {$id}" . PHP_EOL;
            }
        }
    }

    if (count($environments) > 1 && count(array_unique(array_map(static fn (array $a): string => implode(',', $a), $seen))) !== 1) {
        echo "[verify] databases are NOT at an identical migration version" . PHP_EOL;
        return false;
    }

    if ($driftFound) {
        echo "[verify] drift detected" . PHP_EOL;
        return false;
    }

    echo "[verify] OK — identical migration set" . PHP_EOL;

    return true;
}
