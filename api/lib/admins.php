<?php
/**
 * Admin team: every admin can see the list; only owners add or remove people.
 */

declare(strict_types=1);

if (!defined('PAWPAD_API')) {
    http_response_code(404);
    exit;
}

function require_owner(array $admin): void
{
    if ($admin['role'] !== 'owner') {
        json_error('Only the owner can change the admin team.', 403);
    }
}

function list_admins(): array
{
    $rows = db()->query("SELECT email, role, created_at FROM admin_users ORDER BY FIELD(role, 'owner', 'admin', 'manager'), email")->fetchAll();
    return ['admins' => array_map(function (array $row): array {
        return ['email' => $row['email'], 'role' => $row['role'], 'roleLabel' => role_label($row['role']), 'createdAt' => to_iso($row['created_at'])];
    }, $rows)];
}

function add_admin(array $admin, array $input): array
{
    require_owner($admin);
    $email = strtolower(clean_text($input['email'] ?? '', 190));
    $password = (string) ($input['password'] ?? '');
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        json_error('Please enter a valid email address.');
    }
    if (strlen($password) < MIN_PASSWORD_LENGTH) {
        json_error('The starting password must be at least ' . MIN_PASSWORD_LENGTH . ' characters.');
    }
    $stmt = db()->prepare('SELECT id FROM admin_users WHERE email = ?');
    $stmt->execute([$email]);
    if ($stmt->fetchColumn()) {
        json_error('This email is already an admin.');
    }
    $role = (string) ($input['role'] ?? 'admin');
    if (!in_array($role, ['admin', 'manager'], true)) {
        json_error('The role must be Administrator or Manager.');
    }
    upsert_admin($email, $password, $role);
    return list_admins();
}

function remove_admin(array $admin, array $input): array
{
    require_owner($admin);
    $email = strtolower(clean_text($input['email'] ?? '', 190));
    if ($email === strtolower($admin['email'])) {
        json_error('You cannot remove your own account.');
    }
    $stmt = db()->prepare('SELECT id FROM admin_users WHERE email = ?');
    $stmt->execute([$email]);
    $id = $stmt->fetchColumn();
    if (!$id) {
        json_error('Admin not found.', 404);
    }
    db()->prepare('DELETE FROM admin_sessions WHERE user_id = ?')->execute([$id]);
    db()->prepare('DELETE FROM admin_users WHERE id = ?')->execute([$id]);
    return list_admins();
}
