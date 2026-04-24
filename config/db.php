<?php
/**
 * Database-verbinding (PDO, strict mode, prepared statements only)
 */

if (!defined('DKG_BOOT')) { http_response_code(403); exit('Forbidden'); }

// Credentials worden uit .env gelezen — dit bestand bevat géén secrets meer.
// Voor lokale MAMP-dev staan defaults; zet in .env alle waarden om op productie.
define('DB_HOST',    env('DB_HOST', '127.0.0.1'));
define('DB_PORT',    env('DB_PORT', '8889'));           // MAMP MySQL default
define('DB_NAME',    env('DB_NAME', ''));
define('DB_USER',    env('DB_USER', ''));
define('DB_PASS',    env('DB_PASS', ''));
define('DB_CHARSET', env('DB_CHARSET', 'utf8mb4'));

function db(): PDO {
    static $pdo = null;
    if ($pdo instanceof PDO) return $pdo;

    $dsn = sprintf(
        'mysql:host=%s;port=%s;dbname=%s;charset=%s',
        DB_HOST, DB_PORT, DB_NAME, DB_CHARSET
    );
    $options = [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,          // echte prepared statements
        PDO::ATTR_STRINGIFY_FETCHES  => false,
        PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4, "
                                      . "sql_mode='STRICT_ALL_TABLES,NO_ZERO_DATE,"
                                      . "NO_ZERO_IN_DATE,ERROR_FOR_DIVISION_BY_ZERO'",
    ];

    try {
        $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
    } catch (PDOException $e) {
        if (defined('APP_ENV') && APP_ENV === 'development') {
            http_response_code(500);
            exit('DB-verbinding mislukt: ' . htmlspecialchars($e->getMessage()));
        }
        error_log('DB connection failed: ' . $e->getMessage());
        http_response_code(503);
        exit('Service tijdelijk niet beschikbaar.');
    }
    return $pdo;
}
