<?php
/**
 * Pawpad API — https://api.pawpad.in/index.php?action=<name>
 *
 * Public:  health (GET), submit_application
 * Admin:   login, logout, me, change_password, list_applications,
 *          update_application, delete_applications
 * Admin actions need the X-Pawpad-Token header returned by "login".
 */

declare(strict_types=1);

define('PAWPAD_API', true);

require __DIR__ . '/lib/bootstrap.php';
require __DIR__ . '/lib/auth.php';
require __DIR__ . '/lib/applications.php';
require __DIR__ . '/lib/mailer.php';

apply_security_headers();
apply_cors();

$action = (string) ($_GET['action'] ?? '');
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($action === 'health') {
    try {
        db()->query('SELECT 1 FROM applications LIMIT 1');
    } catch (PDOException $e) {
        json_error('Database tables are missing. Run setup.php first.', 500);
    }
    send_json(['ok' => true, 'database' => true, 'email' => mail_configured()]);
}

if ($method !== 'POST') {
    json_error('Use POST.', 405);
}

$input = request_json();

try {
    switch ($action) {
        case 'submit_application':
            send_json(['ok' => true] + submit_application($input), 201);
            break;
        case 'login':
            send_json(['ok' => true] + login($input));
            break;
        case 'logout':
            send_json(['ok' => true] + logout());
            break;
        case 'me':
            send_json(['ok' => true, 'user' => public_user(require_admin())]);
            break;
        case 'change_password':
            send_json(['ok' => true] + change_password(require_admin(), $input));
            break;
        case 'list_applications':
            require_admin();
            send_json(['ok' => true] + list_applications());
            break;
        case 'update_application':
            send_json(['ok' => true] + update_application(require_admin(), $input));
            break;
        case 'delete_applications':
            require_admin();
            send_json(['ok' => true] + delete_applications($input));
            break;
        default:
            json_error('Unknown action.', 404);
    }
} catch (Throwable $e) {
    error_log('Pawpad API error in ' . $action . ': ' . $e->getMessage());
    json_error('Something went wrong on the server.', 500);
}
