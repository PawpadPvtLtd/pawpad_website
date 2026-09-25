<?php
/**
 * Pawpad API — https://api.pawpad.in/index.php?action=<name>
 *
 * Public:  health (GET), get_content (GET or POST), submit_application,
 *          booking_availability (GET or POST), create_booking
 * Admin:   login, logout, me, change_password,
 *          list_bookings, cancel_booking, block_slot, unblock_slot,
 *          list_applications, update_application, delete_applications,
 *          save_content, upload_file, list_uploads, delete_upload, convert_uploads,
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
require __DIR__ . '/lib/caldav.php';
require __DIR__ . '/lib/bookings.php';

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
    send_json([
        'ok' => true,
        'database' => true,
        'email' => mail_configured(),
        'bookingEmail' => mail_configured('info'),
        'calendar' => caldav_configured(),
    ]);
}
// Public reads may use a plain GET (no CORS preflight for visitors).
$publicGet = ['get_content', 'booking_availability'];
if ($method !== 'POST' && !($method === 'GET' && in_array($action, $publicGet, true))) {
    json_error('Use POST.', 405);
}

// Uploads carry a whole file, so they may be bigger than other requests.
$input = $method === 'POST' ? request_json($action === 'upload_file' ? 8000000 : 200000) : [];

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
        case 'booking_availability':
            send_json(['ok' => true] + booking_availability());
            break;
        case 'create_booking':
            send_json(['ok' => true] + create_booking($input), 201);
            break;
        case 'list_bookings':
            require_admin();
            send_json(['ok' => true] + list_bookings($input));
            break;
        case 'cancel_booking':
            send_json(['ok' => true] + cancel_booking(require_admin(), $input));
            break;
        case 'block_slot':
            send_json(['ok' => true] + block_slot(require_admin(), $input));
            break;
        case 'unblock_slot':
            require_admin();
            send_json(['ok' => true] + unblock_slot($input));
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
        case 'convert_uploads':
            require_admin();
            send_json(['ok' => true] + convert_existing_uploads());
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
