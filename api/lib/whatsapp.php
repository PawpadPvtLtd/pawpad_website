<?php
/**
 * The Google review request on WhatsApp (Leena's message, from +91 91484 43330):
 *
 *   Hi {{1}}, it’s Leena from Pawpad 🐾
 *   It was lovely having {{2}} with us today 🛁✨ … how {{3}}’s visit went ⭐ …
 *
 * {{1}} = the customer's first name, {{2}} and {{3}} = the pet's name (from the booking).
 * WhatsApp only allows a business to start a chat with a message template that Meta has
 * approved, so the text lives in WhatsApp Manager as the template "pawpad_review_request";
 * this file fills in the names and sends it.
 *
 * - Sent once per visit, when its invoice is made (the visit is "Completed" in Today's Closing).
 * - Only to customers who agreed to WhatsApp messages (the tick box at booking, or ticked by
 *   staff for walk-ins and phone bookings) and have a usable mobile number. Otherwise the
 *   reason is recorded and nothing is sent.
 * - Delivered / read / failed come back through Meta's webhook.
 * Nothing is sent until whatsapp_token and whatsapp_phone_number_id are in config.php.
 */

declare(strict_types=1);

if (!defined('PAWPAD_API')) {
    http_response_code(404);
    exit;
}

function whatsapp_settings(): array
{
    $c = pawpad_config();
    $params = $c['whatsapp_review_params'] ?? ['name', 'pet', 'pet'];
    return [
        'token' => trim((string) ($c['whatsapp_token'] ?? '')),
        'phone_id' => trim((string) ($c['whatsapp_phone_number_id'] ?? '')),
        'app_secret' => trim((string) ($c['whatsapp_app_secret'] ?? '')),
        'verify_token' => trim((string) ($c['whatsapp_verify_token'] ?? '')),
        'template' => (string) ($c['whatsapp_review_template'] ?? 'pawpad_review_request'),
        'language' => (string) ($c['whatsapp_template_language'] ?? 'en_US'),
        'params' => is_array($params) ? array_values($params) : ['name', 'pet', 'pet'],
        'api' => rtrim((string) ($c['whatsapp_api'] ?? 'https://graph.facebook.com/v21.0'), '/'),
        'enabled' => ($c['whatsapp_reviews_enabled'] ?? true) !== false,
    ];
}

function whatsapp_active(): bool
{
    $s = whatsapp_settings();
    return $s['enabled'] && $s['token'] !== '' && $s['phone_id'] !== '';
}

/** "98765 43210" / "+91 98765-43210" / "098765 43210" → "919876543210"; '' when it isn't a mobile number. */
function whatsapp_number(string $phone): string
{
    $digits = preg_replace('/\D+/', '', $phone) ?? '';
    if (strlen($digits) === 11 && $digits[0] === '0') {
        $digits = substr($digits, 1);
    }
    if (strlen($digits) === 10 && preg_match('/^[6-9]/', $digits)) {
        return '91' . $digits;
    }
    if (strlen($digits) === 12 && strpos($digits, '91') === 0 && preg_match('/^91[6-9]/', $digits)) {
        return $digits;
    }
    // Other countries: a full international number (starting with its country code).
    if (strlen($digits) >= 11 && strlen($digits) <= 15 && strpos(trim($phone), '+') === 0) {
        return $digits;
    }
    return '';
}

/** "asha rao" → "Asha". */
function whatsapp_first_name(string $name): string
{
    $first = preg_split('/\s+/', trim($name))[0] ?? '';
    return $first !== '' ? mb_strtoupper(mb_substr($first, 0, 1)) . mb_substr($first, 1) : 'there';
}

/** ["Bruno"] → "Bruno"; ["Bruno", "Coco"] → "Bruno and Coco"; none → "your pet". */
function whatsapp_pet_names(array $names): string
{
    $names = array_values(array_unique(array_filter(array_map('trim', $names))));
    if (!$names) {
        return 'your pet';
    }
    if (count($names) === 1) {
        return $names[0];
    }
    $last = array_pop($names);
    return implode(', ', $names) . ' and ' . $last;
}

/** The review message record of an invoice, or null. */
function review_message_for_invoice(int $invoiceId): ?array
{
    $stmt = db()->prepare('SELECT * FROM whatsapp_messages WHERE invoice_id = ?');
    $stmt->execute([$invoiceId]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function review_message_to_array(?array $row): ?array
{
    if (!$row) {
        return null;
    }
    return ['status' => $row['status'], 'reason' => $row['reason'], 'error' => $row['error'], 'phone' => $row['phone'], 'sentAt' => to_iso($row['sent_at'])];
}

/** Records what happened (sent, skipped and why, failed and why) for one visit. */
function record_review_message(int $invoiceId, string $ref, string $phone, string $status, string $reason = '', string $error = '', string $wamid = ''): void
{
    db()->prepare('INSERT INTO whatsapp_messages (invoice_id, booking_ref, kind, phone, status, reason, error, wamid, sent_at, created_at, updated_at)
                   VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                   ON DUPLICATE KEY UPDATE phone = VALUES(phone), status = VALUES(status), reason = VALUES(reason), error = VALUES(error),
                       wamid = VALUES(wamid), sent_at = VALUES(sent_at), updated_at = VALUES(updated_at)')
        ->execute([$invoiceId, $ref, 'review', $phone, $status, $reason, $error, $wamid, $status === 'sent' ? now_utc() : null, now_utc(), now_utc()]);
}

/**
 * Sends the review request for a visit (an invoice), once. Called when the invoice is
 * made; staff can also send it from the admin panel (consent: the customer agreed).
 * Returns the record as an array.
 */
function send_review_request(array $invoice, bool $staffConsent = false): array
{
    $existing = review_message_for_invoice((int) $invoice['id']);
    if ($existing && in_array($existing['status'], ['sent', 'delivered', 'read'], true)) {
        return review_message_to_array($existing);
    }
    $ids = array_map('intval', $invoice['bookingIds'] ?? []);
    $rows = [];
    if ($ids) {
        $stmt = db()->prepare('SELECT * FROM bookings WHERE id IN (' . implode(',', array_fill(0, count($ids), '?')) . ') ORDER BY slot_time, id');
        $stmt->execute($ids);
        $rows = $stmt->fetchAll();
    }
    if ($staffConsent && $rows) {
        db()->prepare('UPDATE bookings SET whatsapp_optin = 1 WHERE id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')')->execute($ids);
        foreach ($rows as &$r) {
            $r['whatsapp_optin'] = 1;
        }
        unset($r);
    }
    $ref = (string) $invoice['bookingRef'];
    $phone = whatsapp_number((string) ($invoice['customer']['phone'] ?? ''));
    $skip = function (string $reason) use ($invoice, $ref, $phone): array {
        record_review_message((int) $invoice['id'], $ref, $phone, 'skipped', $reason);
        return review_message_to_array(review_message_for_invoice((int) $invoice['id']));
    };
    if (!whatsapp_active()) {
        return $skip('WhatsApp is not set up yet (whatsapp_token in config.php)');
    }
    $optedIn = $rows && count(array_filter($rows, function (array $r): bool {
        return !empty($r['whatsapp_optin']);
    })) > 0;
    if (!$optedIn) {
        return $skip('The customer did not agree to WhatsApp messages');
    }
    if ($phone === '') {
        return $skip('No usable mobile number');
    }

    $pets = [];
    foreach ($rows as $r) {
        $pet = json_decode((string) $r['pet'], true) ?: [];
        $pets[] = (string) ($pet['name'] ?? '');
    }
    $values = ['name' => whatsapp_first_name((string) $invoice['customer']['name']), 'pet' => whatsapp_pet_names($pets)];
    $s = whatsapp_settings();
    $parameters = array_map(function (string $key) use ($values): array {
        return ['type' => 'text', 'text' => $values[$key] ?? ''];
    }, $s['params']);

    [$status, $data, $curlError] = whatsapp_send_template($s, $phone, $s['language'], $parameters);
    // 132001 = no approved template in that language. Meta lists "English (US)" as en_US and
    // "English" as en, so try the other one once before giving up.
    $other = ['en' => 'en_US', 'en_US' => 'en'][$s['language']] ?? '';
    if ($other !== '' && (int) ($data['error']['code'] ?? 0) === 132001) {
        [$status, $data, $curlError] = whatsapp_send_template($s, $phone, $other, $parameters);
    }
    $wamid = (string) ($data['messages'][0]['id'] ?? '');
    if ($status >= 200 && $status < 300 && $wamid !== '') {
        record_review_message((int) $invoice['id'], $ref, $phone, 'sent', '', '', $wamid);
    } else {
        $error = whatsapp_error_text($data, $curlError, $status);
        error_log('Pawpad API WhatsApp review failed for ' . $ref . ': ' . $error);
        record_review_message((int) $invoice['id'], $ref, $phone, 'failed', '', substr($error, 0, 250));
    }
    return review_message_to_array(review_message_for_invoice((int) $invoice['id']));
}

/** Staff: send (or try again) the review request of an invoice; the customer agreed. */
function send_review_whatsapp(array $input): array
{
    $invoice = invoice_to_array(invoice_row((int) ($input['invoiceId'] ?? 0)));
    if (!whatsapp_active()) {
        json_error('WhatsApp is not set up yet (whatsapp_token and whatsapp_phone_number_id in config.php).');
    }
    if (empty($input['consent'])) {
        json_error('Please confirm the customer agreed to WhatsApp messages.');
    }
    $result = send_review_request($invoice, true);
    if ($result['status'] === 'failed') {
        json_error('WhatsApp did not accept the message: ' . $result['error'], 502);
    }
    if ($result['status'] === 'skipped') {
        json_error('Not sent: ' . $result['reason'] . '.');
    }
    return ['review' => $result];
}

/**
 * Meta's webhook (WhatsApp → Configuration → Webhook): the GET check when it is set up,
 * then delivery updates (sent / delivered / read / failed) for the review messages.
 */
function whatsapp_webhook_verify(): void
{
    $s = whatsapp_settings();
    $mode = (string) ($_GET['hub_mode'] ?? '');
    $token = (string) ($_GET['hub_verify_token'] ?? '');
    if ($mode === 'subscribe' && $s['verify_token'] !== '' && hash_equals($s['verify_token'], $token)) {
        header('Content-Type: text/plain');
        echo (string) ($_GET['hub_challenge'] ?? '');
        exit;
    }
    http_response_code(403);
    exit;
}

function whatsapp_webhook(string $raw): array
{
    $secret = whatsapp_settings()['app_secret'];
    $signature = (string) ($_SERVER['HTTP_X_HUB_SIGNATURE_256'] ?? '');
    if ($secret === '' || !hash_equals('sha256=' . hash_hmac('sha256', $raw, $secret), $signature)) {
        json_error('Invalid signature.', 401);
    }
    $event = json_decode($raw, true) ?: [];
    $updated = 0;
    foreach ($event['entry'] ?? [] as $entry) {
        foreach ($entry['changes'] ?? [] as $change) {
            foreach ($change['value']['statuses'] ?? [] as $st) {
                $state = (string) ($st['status'] ?? '');
                if (!in_array($state, ['sent', 'delivered', 'read', 'failed'], true) || empty($st['id'])) {
                    continue;
                }
                $error = $state === 'failed' ? substr((string) ($st['errors'][0]['title'] ?? $st['errors'][0]['message'] ?? 'Failed'), 0, 250) : '';
                // Never step back (a late "sent" after "read").
                $stmt = db()->prepare("UPDATE whatsapp_messages SET status = ?, error = ?, updated_at = ? WHERE wamid = ?
                    AND NOT (status = 'read' OR (status = 'delivered' AND ? = 'sent'))");
                $stmt->execute([$state, $error, now_utc(), $st['id'], $state]);
                $updated += $stmt->rowCount();
            }
        }
    }
    return ['updated' => $updated];
}

/** Meta's error in plain words, with its code (and what usually fixes it) so it can be looked up. */
function whatsapp_error_text(array $data, string $curlError, int $status): string
{
    $e = is_array($data['error'] ?? null) ? $data['error'] : [];
    $text = (string) ($e['error_user_msg'] ?? $e['message'] ?? ($curlError ?: 'HTTP ' . $status));
    $code = isset($e['code']) ? (int) $e['code'] : 0;
    $codes = array_filter([$code ?: null, isset($e['error_subcode']) ? (int) $e['error_subcode'] : null]);
    if ($codes) {
        $text .= ' (Meta code ' . implode('/', $codes) . ')';
    }
    $hints = [
        190 => 'the token has expired or was revoked: make a new permanent System User token',
        200 => 'Meta blocked the app or token: check the app and the Business account in Meta for restrictions, and that the System User has the WhatsApp account and the app assigned',
        10 => 'the token lacks the whatsapp_business_messaging permission',
        131042 => 'add a payment method in WhatsApp Manager',
        131031 => 'the WhatsApp account is locked: see Account Quality in WhatsApp Manager',
        132001 => 'the template name or language does not match an approved template',
    ];
    if (isset($hints[$code])) {
        $text .= ': ' . $hints[$code];
    }
    return $text;
}

/** One template message to Meta. Returns [HTTP status, decoded reply, connection error]. */
function whatsapp_send_template(array $s, string $phone, string $language, array $parameters): array
{
    $ch = curl_init($s['api'] . '/' . rawurlencode($s['phone_id']) . '/messages');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $s['token'], 'Content-Type: application/json'],
        CURLOPT_POSTFIELDS => json_encode([
            'messaging_product' => 'whatsapp',
            'to' => $phone,
            'type' => 'template',
            'template' => [
                'name' => $s['template'],
                'language' => ['code' => $language],
                'components' => [['type' => 'body', 'parameters' => $parameters]],
            ],
        ], JSON_UNESCAPED_UNICODE),
    ]);
    $raw = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = $raw === false ? curl_error($ch) : '';
    curl_close($ch);
    return [$status, is_string($raw) ? (json_decode($raw, true) ?: []) : [], $curlError];
}
