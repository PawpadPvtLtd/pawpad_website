<?php
/**
 * Database tables. setup.php runs this; every statement is safe to run again.
 */

declare(strict_types=1);

if (!defined('PAWPAD_API')) {
    http_response_code(404);
    exit;
}

// Raise this whenever create_tables() gains a table, so servers add it on the next request.
const SCHEMA_VERSION = 2;

/**
 * Creates any missing tables after an update, without needing setup.php again.
 */
function ensure_schema(PDO $pdo): void
{
    try {
        $current = (int) $pdo->query('SELECT version FROM schema_info WHERE id = 1')->fetchColumn();
    } catch (PDOException $e) {
        $current = 0;
    }
    if ($current >= SCHEMA_VERSION) {
        return;
    }
    create_tables($pdo);
    $pdo->prepare('INSERT INTO schema_info (id, version) VALUES (1, ?) ON DUPLICATE KEY UPDATE version = VALUES(version)')
        ->execute([SCHEMA_VERSION]);
}

function create_tables(PDO $pdo): void
{
    $opts = 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';

    $pdo->exec("CREATE TABLE IF NOT EXISTS schema_info (
        id TINYINT UNSIGNED NOT NULL PRIMARY KEY,
        version INT UNSIGNED NOT NULL
    ) $opts");

    $pdo->exec("CREATE TABLE IF NOT EXISTS admin_users (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        email VARCHAR(190) NOT NULL UNIQUE,
        password_hash VARCHAR(255) NOT NULL,
        role VARCHAR(20) NOT NULL DEFAULT 'admin',
        created_at DATETIME NOT NULL,
        updated_at DATETIME NOT NULL
    ) $opts");

    $pdo->exec("CREATE TABLE IF NOT EXISTS admin_sessions (
        token_hash CHAR(64) NOT NULL PRIMARY KEY,
        user_id INT UNSIGNED NOT NULL,
        created_at DATETIME NOT NULL,
        expires_at DATETIME NOT NULL,
        INDEX idx_sessions_user (user_id),
        INDEX idx_sessions_expiry (expires_at)
    ) $opts");

    $pdo->exec("CREATE TABLE IF NOT EXISTS course_sequences (
        prefix VARCHAR(20) NOT NULL PRIMARY KEY,
        last_number INT UNSIGNED NOT NULL DEFAULT 0
    ) $opts");

    $pdo->exec("CREATE TABLE IF NOT EXISTS applications (
        id VARCHAR(40) NOT NULL PRIMARY KEY,
        course_key VARCHAR(60) NOT NULL DEFAULT '',
        course_code VARCHAR(20) NOT NULL DEFAULT '',
        course_name VARCHAR(255) NOT NULL DEFAULT '',
        course_fee VARCHAR(60) NOT NULL DEFAULT '',
        status VARCHAR(30) NOT NULL DEFAULT 'pending_review',
        interview_date VARCHAR(40) NOT NULL DEFAULT '',
        applicant_name VARCHAR(255) NOT NULL DEFAULT '',
        applicant_phone VARCHAR(60) NOT NULL DEFAULT '',
        applicant_email VARCHAR(255) NOT NULL DEFAULT '',
        applicant_city VARCHAR(120) NOT NULL DEFAULT '',
        responses MEDIUMTEXT NOT NULL,
        acknowledgments TEXT NOT NULL,
        staff_notes MEDIUMTEXT NOT NULL,
        communications MEDIUMTEXT NOT NULL,
        created_at DATETIME NOT NULL,
        updated_at DATETIME NOT NULL,
        INDEX idx_applications_created (created_at),
        INDEX idx_applications_status (status)
    ) $opts");

    // Website content published from the admin panel: one row per page (home, grooming, ...).
    $pdo->exec("CREATE TABLE IF NOT EXISTS site_content (
        page_key VARCHAR(40) NOT NULL PRIMARY KEY,
        data MEDIUMTEXT NOT NULL,
        updated_at DATETIME NOT NULL,
        updated_by VARCHAR(190) NOT NULL DEFAULT ''
    ) $opts");

    // Images and PDFs uploaded from the admin panel (files live in uploads/).
    $pdo->exec("CREATE TABLE IF NOT EXISTS uploads (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        kind VARCHAR(10) NOT NULL,
        path VARCHAR(255) NOT NULL UNIQUE,
        original_name VARCHAR(255) NOT NULL DEFAULT '',
        bytes INT UNSIGNED NOT NULL,
        created_at DATETIME NOT NULL,
        created_by VARCHAR(190) NOT NULL DEFAULT ''
    ) $opts");

    $pdo->exec("CREATE TABLE IF NOT EXISTS rate_events (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        kind VARCHAR(20) NOT NULL,
        ip VARCHAR(64) NOT NULL,
        created_at DATETIME NOT NULL,
        INDEX idx_rate_lookup (kind, ip, created_at)
    ) $opts");
}
