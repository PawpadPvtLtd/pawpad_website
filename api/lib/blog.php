<?php
/**
 * Blog & Vlogs: articles and videos written in the admin panel
 * (Website Content CMS → Blog & Vlogs).
 *
 * Posts live in their own table (not in the page content every visitor loads).
 * Nothing is public until the "Show the Blog on the website" switch is on:
 * until then only signed-in staff see posts (a preview).
 */

declare(strict_types=1);

if (!defined('PAWPAD_API')) {
    http_response_code(404);
    exit;
}

const BLOG_KINDS = ['article', 'video'];
const BLOG_STATUSES = ['draft', 'published'];

/** The "Show the Blog on the website" switch (Website Content CMS → Blog & Vlogs). */
function blog_enabled(): bool
{
    $stmt = db()->prepare('SELECT data FROM site_content WHERE page_key = ?');
    $stmt->execute(['blog']);
    $data = json_decode((string) $stmt->fetchColumn(), true);
    return is_array($data) && !empty($data['enabled']);
}

/**
 * The signed-in staff member when the request carries a valid token, else null.
 * Never stops the request (public pages call this too).
 */
function optional_staff(): ?array
{
    $token = current_token();
    if (!preg_match('/^[a-f0-9]{64}$/', $token)) {
        return null;
    }
    $stmt = db()->prepare(
        'SELECT u.* FROM admin_sessions s JOIN admin_users u ON u.id = s.user_id
         WHERE s.token_hash = ? AND s.expires_at > ?'
    );
    $stmt->execute([hash('sha256', $token), now_utc()]);
    $user = $stmt->fetch();
    return $user && in_array($user['role'], STAFF_ROLES, true) ? $user : null;
}

/** "Grooming a nervous cat: 5 tips!" → "grooming-a-nervous-cat-5-tips". */
function blog_slugify(string $text): string
{
    $slug = strtolower(trim($text));
    $slug = preg_replace('/[^a-z0-9]+/', '-', $slug) ?? '';
    $slug = trim($slug, '-');
    return substr($slug, 0, 80) ?: 'post';
}

/**
 * The video address, if it is a YouTube, Vimeo or Instagram link; '' otherwise.
 */
function blog_clean_video_url(string $url): string
{
    $url = trim($url);
    if ($url === '') {
        return '';
    }
    $parts = parse_url($url);
    if (!$parts || ($parts['scheme'] ?? '') !== 'https' || empty($parts['host'])) {
        return '';
    }
    $host = strtolower(preg_replace('/^www\./', '', $parts['host']) ?? '');
    $allowed = ['youtube.com', 'm.youtube.com', 'youtu.be', 'vimeo.com', 'player.vimeo.com', 'instagram.com'];
    return in_array($host, $allowed, true) ? substr($url, 0, 500) : '';
}

/** An image address the site can show: the Pawpad server, the site's own images, or another https address. */
function blog_clean_image(string $url): string
{
    $url = trim($url);
    if ($url === '') {
        return '';
    }
    if (preg_match('#^https://#i', $url) || preg_match('#^assets/img/[A-Za-z0-9/_.-]+$#', $url)) {
        return substr($url, 0, 500);
    }
    return '';
}

function blog_post_to_array(array $row, bool $withBody): array
{
    $post = [
        'id' => (int) $row['id'],
        'slug' => $row['slug'],
        'kind' => $row['kind'],
        'status' => $row['status'],
        'title' => $row['title'],
        'summary' => $row['summary'],
        'cover' => $row['cover'],
        'coverAlt' => $row['cover_alt'],
        'videoUrl' => $row['video_url'],
        'keywords' => $row['keywords'],
        'seoTitle' => $row['seo_title'],
        'seoDescription' => $row['seo_description'],
        'author' => $row['author'],
        'publishedAt' => to_iso($row['published_at'] ?? null),
        'updatedAt' => to_iso($row['updated_at'] ?? null),
    ];
    if ($withBody) {
        $post['body'] = $row['body'];
    }
    return $post;
}

/**
 * Public: published posts, newest first. Staff also see drafts when they ask
 * for a preview, and see everything while the Blog is still switched off.
 * With "full", each post carries its text (used to build the post pages).
 */
function list_posts(array $input): array
{
    $enabled = blog_enabled();
    $staff = optional_staff();
    $preview = $staff !== null && !empty($input['preview']);
    if (!$enabled && !$preview) {
        return ['enabled' => false, 'preview' => false, 'posts' => []];
    }
    $kind = (string) ($input['kind'] ?? '');
    $sql = 'SELECT * FROM blog_posts WHERE 1 = 1';
    $args = [];
    if (!$preview) {
        $sql .= " AND status = 'published'";
    }
    if (in_array($kind, BLOG_KINDS, true)) {
        $sql .= ' AND kind = ?';
        $args[] = $kind;
    }
    $sql .= ' ORDER BY (published_at IS NULL) DESC, published_at DESC, id DESC LIMIT 500';
    $stmt = db()->prepare($sql);
    $stmt->execute($args);
    $full = !empty($input['full']);
    $posts = array_map(function (array $row) use ($full): array {
        return blog_post_to_array($row, $full);
    }, $stmt->fetchAll());
    return ['enabled' => $enabled, 'preview' => $preview, 'posts' => $posts];
}

/** Public: one post by its address. Drafts and a switched-off Blog are staff-only (preview). */
function get_post(array $input): array
{
    $slug = (string) ($input['slug'] ?? '');
    if (!preg_match('/^[a-z0-9-]{1,80}$/', $slug)) {
        json_error('This post could not be found.', 404);
    }
    $stmt = db()->prepare('SELECT * FROM blog_posts WHERE slug = ?');
    $stmt->execute([$slug]);
    $row = $stmt->fetch();
    $staff = optional_staff();
    $public = $row && $row['status'] === 'published' && blog_enabled();
    if (!$row || (!$public && $staff === null)) {
        json_error('This post could not be found.', 404);
    }
    return ['post' => blog_post_to_array($row, true), 'preview' => !$public];
}

/** Admin: every post, drafts included, newest first. */
function admin_list_posts(): array
{
    $rows = db()->query('SELECT * FROM blog_posts ORDER BY (published_at IS NULL) DESC, published_at DESC, id DESC')->fetchAll();
    return [
        'posts' => array_map(function (array $row): array {
            return blog_post_to_array($row, true);
        }, $rows),
        'enabled' => blog_enabled(),
    ];
}

/** Admin: create or update one post. Publishing sets the date shown on the post. */
function save_post(array $admin, array $input): array
{
    $id = (int) ($input['id'] ?? 0);
    $kind = (string) ($input['kind'] ?? 'article');
    if (!in_array($kind, BLOG_KINDS, true)) {
        json_error('Choose whether this is an article or a video.');
    }
    $status = (string) ($input['status'] ?? 'draft');
    if (!in_array($status, BLOG_STATUSES, true)) {
        json_error('Unknown status.');
    }
    $title = clean_text($input['title'] ?? '', 200);
    if ($title === '') {
        json_error('Please give the post a title.');
    }
    $videoUrl = blog_clean_video_url((string) ($input['videoUrl'] ?? ''));
    if ($kind === 'video' && $videoUrl === '') {
        json_error('Please paste a YouTube, Vimeo or Instagram link for the video (it starts with https://).');
    }
    $body = str_replace("\r\n", "\n", (string) ($input['body'] ?? ''));
    $body = substr($body, 0, 100000);
    if ($kind === 'article' && $status === 'published' && trim($body) === '') {
        json_error('Please write the article before publishing it.');
    }
    $slug = blog_slugify((string) (($input['slug'] ?? '') !== '' ? $input['slug'] : $title));

    $pdo = db();
    $existing = null;
    if ($id > 0) {
        $stmt = $pdo->prepare('SELECT * FROM blog_posts WHERE id = ?');
        $stmt->execute([$id]);
        $existing = $stmt->fetch();
        if (!$existing) {
            json_error('This post no longer exists. Please reload the page.', 404);
        }
    }
    // Keep each address unique: add -2, -3… if another post already uses it.
    $base = $slug;
    for ($n = 2; ; $n++) {
        $stmt = $pdo->prepare('SELECT id FROM blog_posts WHERE slug = ? AND id <> ?');
        $stmt->execute([$slug, $id]);
        if (!$stmt->fetchColumn()) {
            break;
        }
        $slug = substr($base, 0, 75) . '-' . $n;
    }

    $now = now_utc();
    $publishedAt = $existing['published_at'] ?? null;
    if ($status === 'published' && !$publishedAt) {
        $publishedAt = $now;
    }
    $fields = [
        'slug' => $slug,
        'kind' => $kind,
        'status' => $status,
        'title' => $title,
        'summary' => clean_text($input['summary'] ?? '', 400),
        'body' => $body,
        'cover' => blog_clean_image((string) ($input['cover'] ?? '')),
        'cover_alt' => clean_text($input['coverAlt'] ?? '', 200),
        'video_url' => $videoUrl,
        'keywords' => clean_text($input['keywords'] ?? '', 400),
        'seo_title' => clean_text($input['seoTitle'] ?? '', 200),
        'seo_description' => clean_text($input['seoDescription'] ?? '', 300),
        'author' => clean_text($input['author'] ?? '', 120),
        'published_at' => $publishedAt,
        'updated_at' => $now,
        'updated_by' => $admin['email'],
    ];
    if ($existing) {
        $sets = implode(', ', array_map(function (string $k): string {
            return "`$k` = ?";
        }, array_keys($fields)));
        $pdo->prepare("UPDATE blog_posts SET $sets WHERE id = ?")->execute(array_merge(array_values($fields), [$id]));
    } else {
        $fields['created_at'] = $now;
        $fields['created_by'] = $admin['email'];
        $cols = implode(', ', array_map(function (string $k): string {
            return "`$k`";
        }, array_keys($fields)));
        $marks = implode(', ', array_fill(0, count($fields), '?'));
        $pdo->prepare("INSERT INTO blog_posts ($cols) VALUES ($marks)")->execute(array_values($fields));
        $id = (int) $pdo->lastInsertId();
    }
    $stmt = $pdo->prepare('SELECT * FROM blog_posts WHERE id = ?');
    $stmt->execute([$id]);
    return ['post' => blog_post_to_array($stmt->fetch(), true)];
}

/** Admin: remove a post for good. */
function delete_post(array $input): array
{
    $id = (int) ($input['id'] ?? 0);
    $stmt = db()->prepare('DELETE FROM blog_posts WHERE id = ?');
    $stmt->execute([$id]);
    if ($stmt->rowCount() === 0) {
        json_error('This post no longer exists.', 404);
    }
    return ['deleted' => $id];
}
