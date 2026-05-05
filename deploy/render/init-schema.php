<?php

declare(strict_types=1);

require __DIR__ . '/mysql_env.php';

$enabledRaw = getenv('PORTAL_AUTO_MIGRATE');
$enabled = $enabledRaw === false || $enabledRaw === '' || $enabledRaw === '1' || strtolower((string) $enabledRaw) === 'true';
if (!$enabled) {
    fwrite(STDOUT, "[init-schema] PORTAL_AUTO_MIGRATE desabilitado.\n");
    exit(0);
}

$db = portal_wct_db_from_environment();
$driver = strtolower((string) ($db['driver'] ?? 'mysql'));
$host = $db['host'];
$port = (int) $db['port'];
$name = (string) $db['name'];
$user = (string) $db['user'];
$pass = (string) $db['pass'];

fwrite(STDOUT, "[init-schema] driver={$driver} host={$host} port={$port} db={$name} user={$user}\n");

if (!preg_match('/^[a-zA-Z0-9_]+$/', $name)) {
    fwrite(STDERR, "[init-schema] Nome de banco invalido para criacao automatica.\n");
    exit(1);
}

$schemaFileRaw = getenv('PORTAL_SCHEMA_FILE');
$schemaFile = is_string($schemaFileRaw) && trim($schemaFileRaw) !== ''
    ? trim($schemaFileRaw)
    : ($driver === 'pgsql'
        ? '/var/www/html/portal_wct/database.pgsql.sql'
        : '/var/www/html/portal_wct/database.sql');

if (!is_file($schemaFile)) {
    fwrite(STDERR, "[init-schema] Arquivo de schema nao encontrado: {$schemaFile}\n");
    exit(1);
}

if ($driver === 'mysql') {
    try {
        $adminDsn = sprintf('mysql:host=%s;port=%d;charset=utf8mb4', $host, $port);
        $adminPdo = new \PDO($adminDsn, $user, $pass, [
            \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
        ]);
        $adminPdo->exec("CREATE DATABASE IF NOT EXISTS `{$name}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    } catch (\PDOException $e) {
        fwrite(STDERR, "[init-schema] Falha ao criar/verificar database: {$e->getMessage()}\n");
        exit(0);
    }
}

try {
    $dsn = $driver === 'pgsql'
        ? sprintf('pgsql:host=%s;port=%d;dbname=%s', $host, $port, $name)
        : sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $host, $port, $name);
    $pdo = new \PDO($dsn, $user, $pass, [
        \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
    ]);
} catch (\PDOException $e) {
    fwrite(STDERR, "[init-schema] Falha ao conectar no database {$name}: {$e->getMessage()}\n");
    exit(0);
}

$sql = (string) file_get_contents($schemaFile);
if ($driver === 'mysql') {
    $sql = preg_replace('/^\s*CREATE\s+DATABASE\s+.*?;\s*$/im', '', $sql) ?? $sql;
    $sql = preg_replace('/^\s*USE\s+.*?;\s*$/im', '', $sql) ?? $sql;
    $sql = str_replace('`', '', $sql);
    $sql = preg_replace('/\)\s*ENGINE=InnoDB[^;]*;/i', ');', $sql) ?? $sql;
}

$statements = preg_split('/;\s*(?:\r?\n|$)/', $sql) ?: [];
$executed = 0;

foreach ($statements as $statement) {
    $stmt = trim($statement);
    if ($stmt === '' || str_starts_with($stmt, '--')) {
        continue;
    }
    $pdo->exec($stmt);
    $executed++;
}

fwrite(STDOUT, "[init-schema] Schema aplicado com sucesso ({$executed} comandos).\n");
