<?php
/**
 * Pawpad API — https://api.pawpad.in/index.php?action=<name>
 *
 * Public:  health (GET), get_content (GET or POST), submit_application
 * Admin:   login, logout, me, change_password,
 *          list_applications, update_application, delete_applications,
 *          save_content, upload_file, list_uploads, delete_upload,
 *          list_admins, add_admin, remove_admin (owner only: add/remove)
 * Admin actions need the X-Pawpad-Token header returned by "login".
 */

declare(strict_types=1);

define('PAWPAD_API', true);

require __DIR__ . '/lib/bootstrap.php';
require __DIR__ . '/lib/schema.php';
require __DIR__ . '/lib/auth.php';
require __DIR__ . '/lib/admins.php';
require __DIR__ . '/lib/applications.php';
require __DIR__ . '/lib/content.php';
require __DIR__ . '/lib/uploads.php';
require __DIR__ . '/lib/mailer.php';

apply_security_headers();
apply_cors();

$action = (string) ($_GET['action'] ?? '');
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

try {
    ensure_schema(db());
} catch (PDOException $e) {
    error_log('Pawpad API schema update failed: ' . $e->getMessage());
    json_error('The database could not be prepared. Check the db_* settings in config.php.', 500);
}

if ($action === 'health') {
    send_json(['ok' => true, 'database' => true, 'email' => mail_configured()]);
}
if ($action === 'get_content' && $method === 'GET') {
    send_json(['ok' => true] + get_content());
}

if ($method !== 'POST') {
    json_error('Use POST.', 405);
}

// Uploads carry a whole file, so they may be bigger than other requests.
$input = request_json($action === 'upload_file' ? 8000000 : 200000);

try {
    switch ($action) {
        case 'get_content':
            send_json(['ok' => true] + get_content());
            break;
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
        case 'save_content':
            send_json(['ok' => true] + save_content(require_admin(), $input));
            break;
        case 'upload_file':
            send_json(['ok' => true] + upload_file(require_admin(), $input), 201);
            break;
        case 'list_uploads':
            require_admin();
            send_json(['ok' => true] + list_uploads($input));
            break;
        case 'delete_upload':
            require_admin();
            send_json(['ok' => true] + delete_upload($input));
            break;
        case 'list_admins':
            require_admin();
            send_json(['ok' => true] + list_admins());
            break;
        case 'add_admin':
            send_json(['ok' => true] + add_admin(require_admin(), $input));
            break;
        case 'remove_admin':
            send_json(['ok' => true] + remove_admin(require_admin(), $input));
            break;
        default:
            json_error('Unknown action.', 404);
    }
} catch (Throwable $e) {
    error_log('Pawpad API error in ' . $action . ': ' . $e->getMessage());
    json_error('Something went wrong on the server.', 500);
}
