<?php
/**
 * Website content published from the admin panel. Visitors read it
 * (get_content); signed-in admins publish one or more pages (save_content).
 */

declare(strict_types=1);

if (!defined('PAWPAD_API')) {
    http_response_code(404);
    exit;
}

const MAX_CONTENT_BYTES = 1500000;

/**
 * All published pages plus a version string that changes on every publish,
 * so browsers can tell whether their cached copy is still current.
 */
function get_content(): array
{
    $rows = db()->query('SELECT page_key, data, updated_at FROM site_content ORDER BY page_key')->fetchAll();
    $pages = [];
    $latest = '';
    $fingerprint = '';
    foreach ($rows as $row) {
        $decoded = json_decode($row['data'], true);
        if (is_array($decoded)) {
            $pages[$row['page_key']] = $decoded;
            $fingerprint .= $row['page_key'] . "\n" . $row['data'] . "\n";
        }
        if ($row['updated_at'] > $latest) {
            $latest = $row['updated_at'];
        }
    }
    $version = $pages ? substr(hash('sha256', $fingerprint), 0, 16) : '';
    return ['content' => (object) $pages, 'version' => $version, 'updatedAt' => to_iso($latest ?: null)];
}

/**
 * Finds a "data:" image left inside the content (an old browser-only upload).
 * Returns its location, e.g. "home.heroImage", or '' when there is none.
 */
function find_inline_image($value, string $path): string
{
    if (is_string($value)) {
        return strncmp($value, 'data:', 5) === 0 ? $path : '';
    }
    if (is_array($value)) {
        foreach ($value as $key => $child) {
            $found = find_inline_image($child, $path . '.' . $key);
            if ($found !== '') {
                return $found;
            }
        }
    }
    return '';
}

function save_content(array $admin, array $input): array
{
    $pages = $input['pages'] ?? null;
    if (!is_array($pages) || !$pages) {
        json_error('Nothing to publish.');
    }
    if (strlen(json_encode($pages)) > MAX_CONTENT_BYTES) {
        json_error('The content is too large to publish.', 413);
    }

    $now = now_utc();
    $pdo = db();
    $stmt = $pdo->prepare(
        'INSERT INTO site_content (page_key, data, updated_at, updated_by) VALUES (?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE data = VALUES(data), updated_at = VALUES(updated_at), updated_by = VALUES(updated_by)'
    );
    $pdo->beginTransaction();
    try {
        foreach ($pages as $key => $data) {
            if (!is_string($key) || !preg_match('/^[A-Za-z][A-Za-z0-9_]{0,39}$/', $key) || !is_array($data)) {
                throw new InvalidArgumentException('Invalid page: ' . (string) $key);
            }
            $inline = find_inline_image($data, $key);
            if ($inline !== '') {
                throw new InvalidArgumentException('Please upload the image for "' . $inline . '" again before publishing.');
            }
            $stmt->execute([$key, json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $now, $admin['email']]);
        }
        $pdo->commit();
    } catch (InvalidArgumentException $e) {
        $pdo->rollBack();
        json_error($e->getMessage());
    }

    return ['version' => get_content()['version'], 'pages' => array_keys($pages)];
}
