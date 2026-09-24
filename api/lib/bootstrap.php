<?php
/**
 * Shared setup for every API request: config, database, JSON helpers, CORS.
 */

declare(strict_types=1);

if (!defined('PAWPAD_API')) {
    http_response_code(404);
    exit;
}

function pawpad_config(): array
{
    static $config = null;
    if ($config === null) {
        $path = getenv('PAWPAD_CONFIG') ?: dirname(__DIR__) . '/config.php';
        if (!is_file($path)) {
            json_error('Server is not configured yet (config.php missing).', 500);
        }
        $config = require $path;
        if (!is_array($config)) {
            json_error('config.php is invalid.', 500);
        }
    }
    return $config;
}

function db(): PDO
{
    static $pdo = null;
    if ($pdo === null) {
        $c = pawpad_config();
        $dsn = 'mysql:host=' . $c['db_host'] . ';dbname=' . $c['db_name'] . ';charset=utf8mb4';
        if (!empty($c['db_port'])) {
            $dsn .= ';port=' . (int) $c['db_port'];
        }
        try {
            $pdo = new PDO($dsn, $c['db_user'], $c['db_password'], [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]);
            $pdo->exec("SET time_zone = '+00:00'");
        } catch (PDOException $e) {
            error_log('Pawpad API database connection failed: ' . $e->getMessage());
            json_error('Database connection failed. Check the db_* settings in config.php.', 500);
        }
    }
    return $pdo;
}

function now_utc(): string
{
    return gmdate('Y-m-d H:i:s');
}

function to_iso(?string $mysqlDate): string
{
    if (!$mysqlDate) {
        return '';
    }
    return str_replace(' ', 'T', $mysqlDate) . '.000Z';
}

function send_json(array $payload, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function json_error(string $message, int $status = 400): void
{
    send_json(['ok' => false, 'error' => $message], $status);
}

function request_json(int $maxBytes = 200000): array
{
    $raw = file_get_contents('php://input');
    if ($raw === false || $raw === '') {
        return [];
    }
    if (strlen($raw) > $maxBytes) {
        json_error('Request is too large.', 413);
    }
    $data = json_decode($raw, true);
    if (!is_array($data)) {
        json_error('Request body must be JSON.');
    }
    return $data;
}

function client_ip(): string
{
    return substr((string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown'), 0, 64);
}

function clean_text($value, int $maxLength): string
{
    if (!is_scalar($value)) {
        return '';
    }
    $text = trim((string) $value);
    if (function_exists('mb_substr')) {
        return mb_substr($text, 0, $maxLength, 'UTF-8');
    }
    return substr($text, 0, $maxLength);
}

function apply_security_headers(): void
{
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
    header('Referrer-Policy: no-referrer');
}

/**
 * Only the website origins listed in config.php may call the API from a browser.
 */
function apply_cors(): void
{
    $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
    $allowed = pawpad_config()['allowed_origins'] ?? [];
    if ($origin !== '' && in_array($origin, $allowed, true)) {
        header('Access-Control-Allow-Origin: ' . $origin);
        header('Vary: Origin');
        header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type, X-Pawpad-Token');
        header('Access-Control-Max-Age: 600');
    }
    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
        http_response_code(204);
        exit;
    }
}

/**
 * Simple rate limit: at most $max events of $kind per IP in the last $windowSeconds.
 */
function rate_limit(string $kind, int $max, int $windowSeconds): void
{
    $pdo = db();
    $since = gmdate('Y-m-d H:i:s', time() - $windowSeconds);
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM rate_events WHERE kind = ? AND ip = ? AND created_at > ?');
    $stmt->execute([$kind, client_ip(), $since]);
    if ((int) $stmt->fetchColumn() >= $max) {
        json_error('Too many attempts. Please wait a few minutes and try again.', 429);
    }
    $pdo->prepare('INSERT INTO rate_events (kind, ip, created_at) VALUES (?, ?, ?)')
        ->execute([$kind, client_ip(), now_utc()]);
    if (random_int(1, 50) === 1) {
        $pdo->prepare('DELETE FROM rate_events WHERE created_at < ?')
            ->execute([gmdate('Y-m-d H:i:s', time() - 86400)]);
    }
}
