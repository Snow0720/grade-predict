<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';

function db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }
    $dsn = 'mysql:host=' . DB_HOST . ';port=' . DB_PORT . ';dbname=' . DB_NAME . ';charset=utf8mb4';
    $pdo = new PDO($dsn, DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
    // Keep TIMESTAMP/CURRENT_TIMESTAMP aligned with PHP Asia/Manila (shared hosts often use UTC).
    try {
        $pdo->exec("SET time_zone = '+08:00'");
    } catch (Throwable $e) {
        // Some shared hosts block timezone changes; audit() still writes PHP local time explicitly.
    }
    return $pdo;
}
