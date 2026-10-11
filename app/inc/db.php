<?php
/**
 * inc/db.php — the ONLY module allowed to touch the database (docs/09 §5).
 * PDO sqlsrv, UTF-8, prepared statements ONLY. No SQL string interpolation anywhere.
 */
declare(strict_types=1);

function config(): array
{
    static $cfg = null;
    if ($cfg === null) {
        $cfg = require __DIR__ . '/config.php';
    }
    return $cfg;
}

function db(): PDO
{
    static $pdo = null;
    if ($pdo === null) {
        $c = config()['db'];
        $dsn = sprintf(
            // TrustServerCertificate: dev containers use a self-signed SQL cert; docs/07 staging uses
            // a real internal-CA cert on SQL (set 'trust_cert' => false there, or keep 1 if DBA says so)
            'sqlsrv:Server=%s,%s;Database=%s;TrustServerCertificate=%s',
            $c['host'], $c['port'], $c['database'], ($c['trust_cert'] ?? true) ? '1' : '0'
        );
        $pdo = new PDO($dsn, $c['user'], $c['password'], [
            PDO::ATTR_ERRMODE               => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE    => PDO::FETCH_ASSOC,
            PDO::SQLSRV_ATTR_ENCODING       => PDO::SQLSRV_ENCODING_UTF8,
            PDO::SQLSRV_ATTR_QUERY_TIMEOUT  => (int)$c['timeout'],
        ]);
    }
    return $pdo;
}

/** Fetch all rows. */
function q(string $sql, array $params = []): array
{
    $st = db()->prepare($sql);
    $st->execute($params);
    return $st->fetchAll();
}

/** Execute and return affected row count. */
function x(string $sql, array $params = []): int
{
    $st = db()->prepare($sql);
    $st->execute($params);
    return $st->rowCount();
}

/** Fetch first column of first row (or null). */
function scalar(string $sql, array $params = [])
{
    $rows = q($sql, $params);
    return $rows ? array_values($rows[0])[0] : null;
}
