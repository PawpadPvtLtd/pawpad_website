<?php
/**
 * One-time setup page: creates the database tables and an admin login
 * (or resets an admin's password).
 *
 * Works only while 'setup_key' in config.php is at least 16 characters.
 * Set it back to '' afterwards to switch this page off.
 */

declare(strict_types=1);

define('PAWPAD_API', true);

require __DIR__ . '/lib/bootstrap.php';
require __DIR__ . '/lib/schema.php';
require __DIR__ . '/lib/auth.php';

apply_security_headers();
header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store');

$setupKey = (string) (pawpad_config()['setup_key'] ?? '');
$enabled = strlen($setupKey) >= 16;
$message = '';
$isError = false;

if ($enabled && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $pdo = db();
    create_tables($pdo);
    rate_limit('setup', 10, 900);

    $key = (string) ($_POST['setup_key'] ?? '');
    $email = strtolower(trim((string) ($_POST['email'] ?? '')));
    $password = (string) ($_POST['password'] ?? '');
    $confirm = (string) ($_POST['password_confirm'] ?? '');

    if (!hash_equals($setupKey, $key)) {
        $message = 'The setup key does not match the one in config.php.';
        $isError = true;
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $message = 'Please enter a valid email address.';
        $isError = true;
    } elseif (strlen($password) < MIN_PASSWORD_LENGTH) {
        $message = 'The password must be at least ' . MIN_PASSWORD_LENGTH . ' characters.';
        $isError = true;
    } elseif (!hash_equals($password, $confirm)) {
        $message = 'The two passwords do not match.';
        $isError = true;
    } else {
        upsert_admin($email, $password, 'owner');
        $message = 'Done. The database is ready and ' . $email . ' can now sign in to the admin panel. '
            . 'Now open config.php and set setup_key back to \'\' to switch this page off.';
    }
}

function h(string $text): string
{
    return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
}
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex">
<title>Pawpad API setup</title>
<style>
  body { font-family: system-ui, sans-serif; background: #f7f3ee; color: #2b2622; margin: 0; padding: 32px 16px; }
  main { max-width: 440px; margin: 0 auto; background: #fff; border-radius: 12px; padding: 28px; box-shadow: 0 2px 12px rgba(0,0,0,.06); }
  h1 { font-size: 22px; margin: 0 0 8px; }
  label { display: block; font-size: 14px; font-weight: 600; margin: 16px 0 6px; }
  input { width: 100%; box-sizing: border-box; padding: 10px 12px; font-size: 16px; border: 1px solid #cfc6bb; border-radius: 8px; }
  button { margin-top: 22px; width: 100%; padding: 12px; font-size: 16px; border: 0; border-radius: 8px; background: #8a6a4f; color: #fff; cursor: pointer; }
  .msg { padding: 12px; border-radius: 8px; margin-top: 16px; font-size: 14px; background: #e7f4e7; }
  .msg.error { background: #fbe9e7; }
  p { font-size: 14px; line-height: 1.5; }
</style>
</head>
<body>
<main>
  <h1>Pawpad API setup</h1>
<?php if (!$enabled): ?>
  <p>This page is switched off. To use it, put a setup key of at least 16 characters in <code>config.php</code> (<code>'setup_key' =&gt; '...'</code>), then reload this page.</p>
<?php else: ?>
  <p>Creates the database tables and an admin login. Using an email that already exists resets its password.</p>
  <?php if ($message !== ''): ?>
    <div class="msg<?= $isError ? ' error' : '' ?>"><?= h($message) ?></div>
  <?php endif; ?>
  <form method="post" autocomplete="off">
    <label for="setup_key">Setup key (from config.php)</label>
    <input type="password" id="setup_key" name="setup_key" required>
    <label for="email">Admin email</label>
    <input type="email" id="email" name="email" required value="<?= h((string) ($_POST['email'] ?? '')) ?>">
    <label for="password">New admin password (at least <?= MIN_PASSWORD_LENGTH ?> characters)</label>
    <input type="password" id="password" name="password" required minlength="<?= MIN_PASSWORD_LENGTH ?>">
    <label for="password_confirm">Type the password again</label>
    <input type="password" id="password_confirm" name="password_confirm" required minlength="<?= MIN_PASSWORD_LENGTH ?>">
    <button type="submit">Create tables &amp; save admin</button>
  </form>
<?php endif; ?>
</main>
</body>
</html>
