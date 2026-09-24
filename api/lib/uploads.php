<?php
/**
 * Admin uploads: WebP images (already compressed in the browser) and PDF
 * course documents, stored in uploads/ with a total storage cap so the
 * hosting account never fills up.
 */

declare(strict_types=1);

if (!defined('PAWPAD_API')) {
    http_response_code(404);
    exit;
}

function upload_limits(): array
{
    $c = pawpad_config();
    return [
        'image_bytes' => (int) ($c['upload_max_image_kb'] ?? 1500) * 1024,
        'pdf_bytes' => (int) ($c['upload_max_pdf_kb'] ?? 4000) * 1024,
        'total_bytes' => (int) ($c['upload_quota_mb'] ?? 150) * 1024 * 1024,
    ];
}

function uploads_dir(): string
{
    return dirname(__DIR__) . '/uploads';
}

function public_url(string $relativePath): string
{
    $base = rtrim((string) (pawpad_config()['public_base_url'] ?? 'https://api.pawpad.in'), '/');
    return $base . '/' . $relativePath;
}

function upload_usage(): array
{
    $used = (int) db()->query('SELECT COALESCE(SUM(bytes), 0) FROM uploads')->fetchColumn();
    $limits = upload_limits();
    return ['usedBytes' => $used, 'quotaBytes' => $limits['total_bytes']];
}

function format_mb(int $bytes): string
{
    return number_format($bytes / 1048576, 2) . ' MB';
}

function upload_file(array $admin, array $input): array
{
    $kind = (string) ($input['kind'] ?? '');
    if ($kind !== 'image' && $kind !== 'pdf') {
        json_error('Only images and PDF files can be uploaded.');
    }

    $content = (string) ($input['content'] ?? '');
    if (strncmp($content, 'data:', 5) === 0) {
        $comma = strpos($content, ',');
        $content = $comma === false ? '' : substr($content, $comma + 1);
    }
    $bytes = base64_decode($content, true);
    if ($bytes === false || $bytes === '') {
        json_error('The file could not be read. Please try again.');
    }

    $limits = upload_limits();
    $size = strlen($bytes);
    if ($kind === 'image') {
        // The admin panel compresses every photo to WebP first (JPEG on Safari, which can't write WebP).
        $types = ['image/webp' => 'webp', 'image/jpeg' => 'jpg', 'image/png' => 'png'];
        $info = @getimagesizefromstring($bytes);
        if ($info === false || !isset($types[$info['mime'] ?? '']) || $info[0] < 1 || $info[1] < 1 || $info[0] > 5000 || $info[1] > 5000) {
            json_error('Please upload a WebP, JPEG or PNG image through the admin panel.');
        }
        if ($size > $limits['image_bytes']) {
            json_error('This image is ' . format_mb($size) . ' after compression; the limit is ' . format_mb($limits['image_bytes']) . '. Please use a smaller photo.', 413);
        }
        $extension = $types[$info['mime']];
        $folder = 'images/' . gmdate('Y/m');
    } else {
        if (strncmp($bytes, '%PDF-', 5) !== 0) {
            json_error('This file is not a PDF.');
        }
        if ($size > $limits['pdf_bytes']) {
            json_error('This PDF is ' . format_mb($size) . '; the limit is ' . format_mb($limits['pdf_bytes']) . '.', 413);
        }
        $extension = 'pdf';
        $folder = 'docs';
    }

    $usage = upload_usage();
    if ($usage['usedBytes'] + $size > $usage['quotaBytes']) {
        json_error('Upload storage is full (' . format_mb($usage['usedBytes']) . ' of ' . format_mb($usage['quotaBytes'])
            . ' used). Delete old uploads in the admin panel first.', 507);
    }

    $original = clean_text($input['filename'] ?? '', 255);
    $slug = strtolower(preg_replace('/[^A-Za-z0-9]+/', '-', pathinfo($original, PATHINFO_FILENAME)));
    $slug = trim(substr($slug, 0, 50), '-') ?: 'file';
    $relative = 'uploads/' . $folder . '/' . $slug . '-' . bin2hex(random_bytes(4)) . '.' . $extension;

    $absolute = dirname(__DIR__) . '/' . $relative;
    if (!is_dir(dirname($absolute)) && !mkdir(dirname($absolute), 0755, true)) {
        json_error('The server could not create the uploads folder.', 500);
    }
    if (file_put_contents($absolute, $bytes, LOCK_EX) !== $size) {
        @unlink($absolute);
        json_error('The server could not save the file.', 500);
    }
    @chmod($absolute, 0644);

    db()->prepare('INSERT INTO uploads (kind, path, original_name, bytes, created_at, created_by) VALUES (?, ?, ?, ?, ?, ?)')
        ->execute([$kind, $relative, $original, $size, now_utc(), $admin['email']]);

    return ['url' => public_url($relative), 'bytes' => $size, 'usage' => upload_usage()];
}

function list_uploads(array $input): array
{
    $kind = (string) ($input['kind'] ?? '');
    if ($kind === 'image' || $kind === 'pdf') {
        $stmt = db()->prepare('SELECT * FROM uploads WHERE kind = ? ORDER BY created_at DESC, id DESC');
        $stmt->execute([$kind]);
    } else {
        $stmt = db()->query('SELECT * FROM uploads ORDER BY created_at DESC, id DESC');
    }
    $files = array_map(function (array $row): array {
        return [
            'id' => (int) $row['id'],
            'kind' => $row['kind'],
            'url' => public_url($row['path']),
            'name' => $row['original_name'],
            'bytes' => (int) $row['bytes'],
            'createdAt' => to_iso($row['created_at']),
        ];
    }, $stmt->fetchAll());
    return ['files' => $files, 'usage' => upload_usage()];
}

function delete_upload(array $input): array
{
    $stmt = db()->prepare('SELECT * FROM uploads WHERE id = ?');
    $stmt->execute([(int) ($input['id'] ?? 0)]);
    $row = $stmt->fetch();
    if (!$row) {
        json_error('File not found.', 404);
    }
    // Only ever delete files inside uploads/.
    $absolute = realpath(dirname(__DIR__) . '/' . $row['path']);
    $root = realpath(uploads_dir());
    if ($absolute !== false && $root !== false && strpos($absolute, $root . DIRECTORY_SEPARATOR) === 0) {
        @unlink($absolute);
    }
    db()->prepare('DELETE FROM uploads WHERE id = ?')->execute([$row['id']]);
    return ['usage' => upload_usage()];
}
