<?php

declare(strict_types=1);

/**
 * SPMS migration runner (M0 foundation).
 *
 * Usage:
 *   php database/migrate.php                 apply pending migrations
 *   php database/migrate.php --status        show applied/pending migrations
 *
 * Each migration file lives in database/migrations/ and returns an array:
 *   [
 *     'id'   => '0001_create_migrations_table',
 *     'up'   => static function (PDO $pdo): void { ... },
 *   ]
 *
 * Applied migrations are recorded in the `migrations` table.
 * The runner applies migrations in filename order inside a transaction.
 */

require dirname(__DIR__) . '/vendor/autoload.php';

use App\Support\Config;

$configPath = dirname(__DIR__);
Config::initialize($configPath);

$pdo = new PDO(
    sprintf(
        'mysql:host=%s;port=%s;charset=%s',
        Config::get('DB_HOST', '127.0.0.1'),
        Config::get('DB_PORT', '3306'),
        Config::get('DB_CHARSET', 'utf8mb4')
    ),
    Config::get('DB_USERNAME', 'root'),
    Config::get('DB_PASSWORD', ''),
    [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]
);

$database = Config::get('DB_DATABASE');
if ($database === null || $database === '') {
    fwrite(STDERR, "DB_DATABASE is not configured" . PHP_EOL);
    exit(1);
}

$pdo->exec("CREATE DATABASE IF NOT EXISTS `{$database}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
$pdo->exec("USE `{$database}`");

$pdo->exec(<<<'SQL'
    CREATE TABLE IF NOT EXISTS migrations (
        id VARCHAR(191) NOT NULL,
        applied_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    SQL);

echo "[ok] migrations tracking table ready" . PHP_EOL;

$migrationDir = __DIR__ . '/migrations';
$files = glob($migrationDir . '/*.php') ?: [];
sort($files);

$applied = $pdo->query('SELECT id FROM migrations')->fetchAll(PDO::FETCH_COLUMN);
$appliedMap = array_flip($applied);

$statusOnly = in_array('--status', $argv, true);

$pendingCount = 0;
foreach ($files as $file) {
    $migration = require $file;

    $id = $migration['id'] ?? basename($file, '.php');

    if (isset($appliedMap[$id])) {
        echo "[applied] {$id}" . PHP_EOL;
        continue;
    }

    if ($statusOnly) {
        echo "[pending] {$id}" . PHP_EOL;
        $pendingCount++;
        continue;
    }

    echo "[running] {$id} ..." . PHP_EOL;

    $pdo->beginTransaction();
    try {
        ($migration['up'])($pdo);
        $record = $pdo->prepare('INSERT INTO migrations (id) VALUES (:id)');
        $record->execute(['id' => $id]);
        $pdo->commit();
        echo "[applied] {$id}" . PHP_EOL;
    } catch (Throwable $e) {
        $pdo->rollBack();
        fwrite(STDERR, "[failed]  {$id}: " . $e->getMessage() . PHP_EOL);
        exit(1);
    }
}

if ($statusOnly && $pendingCount === 0) {
    echo "No pending migrations." . PHP_EOL;
}

if (!$statusOnly && $pendingCount === 0) {
    echo "Nothing to migrate." . PHP_EOL;
}
