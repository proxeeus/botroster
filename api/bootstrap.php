<?php
/**
 * Common bootstrap for every API endpoint.
 *   - Loads config
 *   - Opens PDO connection (lazy singleton via cfg())
 *   - Sets JSON response headers
 *   - Provides helpers: json_out(), fail(), require_method(), require_auth()
 */

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');

require_once __DIR__ . '/errors.php';
require_once __DIR__ . '/session.php';

function cfg(?string $key = null) {
    static $cfg = null;
    if ($cfg === null) {
        $path = __DIR__ . '/config.php';
        if (!is_file($path)) {
            fail(500, 'server_misconfigured', 'api/config.php is missing. Copy config.example.php to config.php and edit it.');
        }
        $cfg = require $path;
    }
    if ($key === null) return $cfg;
    return $cfg[$key] ?? null;
}

function db(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        $d = cfg('db');
        $dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=%s',
            $d['host'], (int)$d['port'], $d['name'], $d['charset']
        );
        try {
            $pdo = new PDO($dsn, $d['user'], $d['password'], [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ]);
        } catch (PDOException $e) {
            error_log('[botroster] DB connect failed: ' . $e->getMessage());
            fail(500, 'db_unavailable', 'Database connection failed. Check api/config.php.');
        }
    }
    return $pdo;
}

function json_out($data, int $code = 200): void {
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

function fail(int $code, string $key, string $message = '', array $extra = []): void {
    http_response_code($code);
    echo json_encode(array_merge(['error' => $key, 'message' => $message], $extra));
    exit;
}

function require_method(string $method): void {
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== $method) {
        fail(405, 'method_not_allowed', "Use $method for this endpoint.");
    }
}

function read_json(): array {
    $raw = file_get_contents('php://input');
    if ($raw === false || $raw === '') return [];
    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : [];
}

/** Reads and returns the current session data, or fails with 401. */
function require_auth(): array {
    $s = session_read();
    if (!$s) {
        fail(401, 'not_authenticated', 'You must log in first.');
    }
    return $s;
}
