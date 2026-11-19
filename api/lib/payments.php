<?php
/**
 * Booking deposits through Razorpay (rules from Leena, 10 Nov 2026):
 * - ₹500 per pet (or the service's full price when it costs less, e.g. Nail Clipping),
 *   for grooming and boarding. Deducted from the final bill.
 * - Website: the time is held 15 minutes while the customer pays (Razorpay Checkout).
 * - Phone / WhatsApp bookings and boarding stays: a Razorpay payment link, valid 2 hours.
 *   The booking is confirmed once the deposit is paid; unpaid, the time is released.
 * - Refunded in full for a cancellation at least 24 hours before; kept for a no-show
 *   or a later cancellation. A reschedule keeps the deposit on the booking.
 *
 * Nothing changes until the Razorpay keys are in config.php on the server
 * (razorpay_key_id, razorpay_key_secret, razorpay_webhook_secret).
 * Every payment is checked with Razorpay's signature (and on Razorpay's servers)
 * before a booking is confirmed.
 */

declare(strict_types=1);

if (!defined('PAWPAD_API')) {
    http_response_code(404);
    exit;
}

const DEPOSIT_DEFAULT = 500;
// Prices the server knows, for deposits on cheap services (the CMS price is used when it has one).
const DEPOSIT_KNOWN_PRICES = ['nail-clipping' => 250];
const REFUND_NOTICE_HOURS = 24;

function razorpay_settings(): array
{
    $c = pawpad_config();
    return [
        'key_id' => trim((string) ($c['razorpay_key_id'] ?? '')),
        'key_secret' => trim((string) ($c['razorpay_key_secret'] ?? '')),
        'webhook_secret' => trim((string) ($c['razorpay_webhook_secret'] ?? '')),
        'api' => rtrim((string) ($c['razorpay_api'] ?? 'https://api.razorpay.com'), '/'),
    ];
}

/** Deposits are taken only when the Razorpay keys are set and deposits are switched on. */
function deposits_active(): bool
{
    $s = razorpay_settings();
    return $s['key_id'] !== '' && $s['key_secret'] !== '' && (pawpad_config()['deposits_enabled'] ?? true) !== false;
}

function deposit_amount_setting(): float
{
    return (float) max(1, (int) (pawpad_config()['deposit_amount'] ?? DEPOSIT_DEFAULT));
}

function deposit_hold_minutes(): int
{
    return max(5, min(60, (int) (pawpad_config()['deposit_hold_minutes'] ?? 15)));
}

function deposit_link_hours(): float
{
    return max(0.5, min(72, (float) (pawpad_config()['deposit_link_hours'] ?? 2)));
}

/** The price of a grooming service as the server knows it (CMS first), or null. */
function server_service_price(string $serviceId): ?float
{
    static $prices = null;
    if ($prices === null) {
        $prices = DEPOSIT_KNOWN_PRICES;
        $stmt = db()->prepare('SELECT data FROM site_content WHERE page_key = ?');
        $stmt->execute(['grooming']);
        $g = json_decode((string) $stmt->fetchColumn(), true);
        foreach ((is_array($g) && is_array($g['packages'] ?? null)) ? $g['packages'] : [] as $p) {
            if (!is_array($p) || empty($p['key'])) {
                continue;
            }
            $price = isset($p['priceNum']) && is_numeric($p['priceNum']) ? (float) $p['priceNum'] : parse_amount($p['price'] ?? '');
            if ($price !== null && $price > 0) {
                $prices[$p['key']] = $price;
            }
        }
    }
    return $prices[$serviceId] ?? null;
}

/** ₹500, or the full price when the service costs less. */
function deposit_for(?float $price): float
{
    $deposit = deposit_amount_setting();
    return ($price !== null && $price > 0 && $price < $deposit) ? round($price, 2) : $deposit;
}

function deposit_for_service(string $serviceId): float
{
    return deposit_for(server_service_price($serviceId));
}

// ---------------------------------------------------------------------------
// Razorpay API
// ---------------------------------------------------------------------------

/** @return array{status: int, data: array} */
function razorpay_request(string $method, string $path, ?array $body = null): array
{
    $s = razorpay_settings();
    $ch = curl_init($s['api'] . $path);
    $headers = ['Accept: application/json'];
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_USERPWD => $s['key_id'] . ':' . $s['key_secret'],
        CURLOPT_TIMEOUT => 20,
        CURLOPT_CONNECTTIMEOUT => 8,
    ]);
    if ($body !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        $headers[] = 'Content-Type: application/json';
    }
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    $raw = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = $raw === false ? curl_error($ch) : '';
    curl_close($ch);
    $data = is_string($raw) ? (json_decode($raw, true) ?: []) : [];
    if ($status < 200 || $status >= 300) {
        error_log('Pawpad API Razorpay ' . $method . ' ' . $path . ' failed: HTTP ' . $status . ' ' . $error . ' ' . ($data['error']['description'] ?? ''));
    }
    return ['status' => $status, 'data' => $data];
}

function paise(float $rupees): int
{
    return (int) round($rupees * 100);
}

/** Razorpay Checkout's signature: HMAC-SHA256 of "order_id|payment_id" with the key secret. */
function razorpay_checkout_signature_ok(string $orderId, string $paymentId, string $signature): bool
{
    $secret = razorpay_settings()['key_secret'];
    return $secret !== '' && $signature !== '' && hash_equals(hash_hmac('sha256', $orderId . '|' . $paymentId, $secret), $signature);
}

/** A payment link's callback signature: HMAC-SHA256 of "link_id|reference_id|status|payment_id". */
function razorpay_link_signature_ok(string $linkId, string $reference, string $status, string $paymentId, string $signature): bool
{
    $secret = razorpay_settings()['key_secret'];
    return $secret !== '' && $signature !== '' && hash_equals(hash_hmac('sha256', $linkId . '|' . $reference . '|' . $status . '|' . $paymentId, $secret), $signature);
}

// ---------------------------------------------------------------------------
// Payments table
// ---------------------------------------------------------------------------

function payment_row(int $id, bool $lock = false): ?array
{
    $stmt = db()->prepare('SELECT * FROM payments WHERE id = ?' . ($lock ? ' FOR UPDATE' : ''));
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function payment_by_razorpay_id(string $razorpayId): ?array
{
    $stmt = db()->prepare('SELECT * FROM payments WHERE razorpay_id = ?');
    $stmt->execute([$razorpayId]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function deposit_payment_to_array(?array $row): ?array
{
    if (!$row) {
        return null;
    }
    return [
        'id' => (int) $row['id'],
        'kind' => $row['kind'],
        'purpose' => $row['purpose'],
        'amount' => (float) $row['amount'],
        'status' => $row['status'],
        'linkUrl' => $row['link_url'],
        'holdUntil' => to_iso($row['hold_until']),
        'paidAt' => to_iso($row['paid_at']),
        'refunded' => (float) $row['refunded_amount'],
    ];
}

function new_payment(string $kind, string $purpose, string $razorpayId, string $ref, float $amount, DateTimeImmutable $holdUntil, string $linkUrl = '', int $boardingId = 0): int
{
    db()->prepare('INSERT INTO payments (kind, purpose, razorpay_id, booking_ref, boarding_id, amount, status, link_url, hold_until, created_at, updated_at)
                   VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
        ->execute([$kind, $purpose, $razorpayId, $ref, $boardingId ?: null, $amount, 'created', $linkUrl,
            $holdUntil->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s'), now_utc(), now_utc()]);
    return (int) db()->lastInsertId();
}

/** A Razorpay order for the website checkout. Returns its id, or '' if Razorpay couldn't be reached. */
function create_razorpay_order(float $amount, string $receipt, array $notes): string
{
    $r = razorpay_request('POST', '/v1/orders', [
        'amount' => paise($amount), 'currency' => 'INR', 'receipt' => substr($receipt, 0, 40), 'notes' => $notes,
    ]);
    return ($r['status'] >= 200 && $r['status'] < 300 && !empty($r['data']['id'])) ? (string) $r['data']['id'] : '';
}

/**
 * A Razorpay payment link (phone / WhatsApp bookings, boarding). Razorpay also sends it
 * by SMS / email when a number or address is given. Returns ['id', 'url'] or null.
 */
function create_razorpay_link(float $amount, string $description, array $customer, DateTimeImmutable $expires, string $reference, array $notes): ?array
{
    $cust = array_filter(['name' => $customer['name'] ?? '', 'contact' => $customer['phone'] ?? '', 'email' => $customer['email'] ?? '']);
    $r = razorpay_request('POST', '/v1/payment_links', [
        'amount' => paise($amount),
        'currency' => 'INR',
        'accept_partial' => false,
        'description' => substr($description, 0, 2048),
        'customer' => $cust ?: new stdClass(),
        'notify' => ['sms' => !empty($cust['contact']), 'email' => !empty($cust['email'])],
        'reminder_enable' => false,
        'expire_by' => $expires->getTimestamp(),
        'reference_id' => substr($reference, 0, 40),
        'notes' => $notes,
    ]);
    if ($r['status'] < 200 || $r['status'] >= 300 || empty($r['data']['id'])) {
        return null;
    }
    return ['id' => (string) $r['data']['id'], 'url' => (string) ($r['data']['short_url'] ?? '')];
}

/**
 * Checks a payment on Razorpay's own servers (amount and status) and captures it if
 * it is only authorised. Returns true when the money is captured.
 */
function razorpay_payment_captured(string $paymentId, float $amount, string $orderId = ''): bool
{
    $r = razorpay_request('GET', '/v1/payments/' . rawurlencode($paymentId));
    if ($r['status'] !== 200) {
        return false;
    }
    $p = $r['data'];
    if ((int) ($p['amount'] ?? 0) !== paise($amount) || ($p['currency'] ?? 'INR') !== 'INR') {
        error_log('Pawpad API Razorpay: amount mismatch for ' . $paymentId);
        return false;
    }
    // A checkout payment must belong to this booking's own order.
    if ($orderId !== '' && (string) ($p['order_id'] ?? '') !== $orderId) {
        error_log('Pawpad API Razorpay: payment ' . $paymentId . ' is not for order ' . $orderId);
        return false;
    }
    if (($p['status'] ?? '') === 'captured') {
        return true;
    }
    if (($p['status'] ?? '') === 'authorized') {
        $c = razorpay_request('POST', '/v1/payments/' . rawurlencode($paymentId) . '/capture', ['amount' => paise($amount), 'currency' => 'INR']);
        return $c['status'] === 200 && ($c['data']['status'] ?? '') === 'captured';
    }
    return false;
}

/** Refunds part or all of a payment. Returns the refund id, or '' on failure. */
function razorpay_refund(array $payment, float $amount, string $reason): string
{
    if ($payment['payment_id'] === '' || $amount <= 0) {
        return '';
    }
    $r = razorpay_request('POST', '/v1/payments/' . rawurlencode($payment['payment_id']) . '/refund', [
        'amount' => paise($amount), 'speed' => 'normal', 'notes' => ['reason' => substr($reason, 0, 250)],
    ]);
    if ($r['status'] < 200 || $r['status'] >= 300 || empty($r['data']['id'])) {
        return '';
    }
    // (status first: MySQL applies the SET parts in order)
    db()->prepare("UPDATE payments SET status = IF(refunded_amount + ? >= amount, 'refunded', 'partly_refunded'), refunded_amount = refunded_amount + ?, updated_at = ? WHERE id = ?")
        ->execute([$amount, $amount, now_utc(), $payment['id']]);
    return (string) $r['data']['id'];
}

// ---------------------------------------------------------------------------
// Holds that ran out
// ---------------------------------------------------------------------------

/**
 * Releases bookings and boarding stays whose deposit wasn't paid in time.
 * Runs at the start of every API request (one quick query when there is nothing to do).
 */
function expire_payment_holds(): void
{
    $now = now_utc();
    $stmt = db()->prepare("SELECT * FROM payments WHERE status = 'created' AND hold_until <= ?");
    $stmt->execute([$now]);
    foreach ($stmt->fetchAll() as $p) {
        db()->prepare("UPDATE payments SET status = 'expired', updated_at = ? WHERE id = ? AND status = 'created'")->execute([$now, $p['id']]);
        if ($p['purpose'] === 'boarding') {
            $b = db()->prepare("SELECT * FROM boarding_requests WHERE id = ? AND status = 'awaiting_deposit'");
            $b->execute([(int) $p['boarding_id']]);
            if ($row = $b->fetch()) {
                $log = json_decode((string) ($row['admin_log'] ?? ''), true) ?: [];
                $log[] = ['date' => to_iso($now), 'by' => 'Pawpad server', 'text' => 'The deposit link expired unpaid: back to "New".'];
                db()->prepare("UPDATE boarding_requests SET status = 'new', deposit_status = 'expired', admin_log = ?, updated_at = ? WHERE id = ?")
                    ->execute([json_encode($log, JSON_UNESCAPED_UNICODE), $now, $row['id']]);
            }
        } else {
            db()->prepare("UPDATE bookings SET status = 'expired', deposit_status = 'expired' WHERE payment_ref = ? AND status = 'pending'")->execute([$p['id']]);
        }
    }
}

// ---------------------------------------------------------------------------
// A deposit was paid
// ---------------------------------------------------------------------------

/**
 * Marks a payment paid (once, even if the browser and Razorpay's webhook both report it)
 * and confirms what it was for. Returns the bookings / stay it confirmed.
 */
function finalize_payment(int $paymentRowId, string $paymentId): array
{
    $pdo = db();
    $pdo->beginTransaction();
    $p = payment_row($paymentRowId, true);
    if (!$p) {
        $pdo->rollBack();
        json_error('Payment not found.', 404);
    }
    if ($p['status'] === 'paid' || $p['status'] === 'refunded' || $p['status'] === 'partly_refunded') {
        $pdo->commit();
        return ['already' => true, 'payment' => $p];
    }
    if (!razorpay_payment_captured($paymentId, (float) $p['amount'], $p['kind'] === 'order' ? (string) $p['razorpay_id'] : '')) {
        $pdo->rollBack();
        json_error('The payment could not be confirmed with Razorpay. If money was taken, it will be refunded automatically by Razorpay; please WhatsApp us on ' . STUDIO_WHATSAPP . '.', 402);
    }
    $pdo->prepare("UPDATE payments SET status = 'paid', payment_id = ?, paid_at = ?, updated_at = ? WHERE id = ?")
        ->execute([$paymentId, now_utc(), now_utc(), $p['id']]);
    $pdo->commit();
    $p = payment_row((int) $p['id']);
    return $p['purpose'] === 'boarding' ? confirm_paid_boarding($p) : confirm_paid_bookings($p);
}

/**
 * The grooming bookings of a paid deposit become "booked": calendar events and the
 * confirmation email follow. A hold that had run out is booked again if the time is
 * still free; otherwise the deposit is refunded.
 */
function confirm_paid_bookings(array $p): array
{
    $stmt = db()->prepare('SELECT * FROM bookings WHERE payment_ref = ? ORDER BY slot_date, slot_time, id');
    $stmt->execute([$p['id']]);
    $rows = $stmt->fetchAll();
    $pdo = db();
    $lost = [];
    foreach ($rows as $row) {
        if ($row['status'] === 'pending') {
            $pdo->prepare("UPDATE bookings SET status = 'booked', deposit_status = 'paid' WHERE id = ? AND status = 'pending'")->execute([$row['id']]);
            continue;
        }
        if ($row['status'] === 'expired') {
            // Paid after the hold ran out: book it if the team's time is still free.
            $pdo->beginTransaction();
            lock_schedule_day($pdo, $row['slot_date']);
            $schedule = day_schedules([$row['slot_date']], true, null, (int) $row['id'])[$row['slot_date']];
            $fit = plan_fit($schedule, service_plan($row['service_id']), hm_to_min($row['slot_time']));
            if ($fit['segments'] !== null) {
                $pdo->prepare('DELETE FROM booking_segments WHERE booking_id = ?')->execute([$row['id']]);
                save_segments($pdo, (int) $row['id'], $row['slot_date'], $fit['segments']);
                $pdo->prepare("UPDATE bookings SET status = 'booked', deposit_status = 'paid' WHERE id = ?")->execute([$row['id']]);
            } else {
                $lost[] = $row;
            }
            $pdo->commit();
        }
    }
    if ($lost) {
        $amount = (float) array_sum(array_column($lost, 'deposit'));
        $refundId = razorpay_refund($p, $amount, 'Paid after the time was released and taken');
        foreach ($lost as $row) {
            $pdo->prepare('UPDATE bookings SET deposit_status = ?, admin_log = ? WHERE id = ?')
                ->execute([$refundId !== '' ? 'refunded' : 'refund_failed', booking_log_entry($row, ['email' => 'Pawpad server'],
                    'Deposit paid after the hold ran out and the time was taken: ' . ($refundId !== '' ? 'refunded.' : 'REFUND FAILED, please refund in Razorpay.')), $row['id']]);
        }
    }

    // Calendar events and the confirmation email for what is now booked.
    $stmt = db()->prepare("SELECT * FROM bookings WHERE payment_ref = ? AND status = 'booked' ORDER BY slot_date, slot_time, id");
    $stmt->execute([$p['id']]);
    $booked = $stmt->fetchAll();
    $saved = [];
    $calendarOk = true;
    foreach ($booked as $row) {
        $pet = (json_decode($row['pet'], true) ?: []) + ['name' => '', 'type' => '', 'breed' => '', 'age' => '', 'size' => '', 'coat' => '', 'temperament' => '', 'healthNotes' => ''];
        $item = ['id' => (int) $row['id'], 'ref' => $row['ref'], 'date' => $row['slot_date'], 'time' => $row['slot_time'], 'pet' => $pet,
            'serviceTitle' => $row['service_title'], 'deposit' => (float) $row['deposit']];
        if ($row['calendar_href'] === '' || $row['calendar_href'] === null) {
            $href = add_booking_event($item, $row['customer_name'], $row['customer_phone'], $row['customer_email'] ?: '(walk-in / phone)', (string) $row['notes']);
            $calendarOk = $calendarOk && $href !== '';
            db()->prepare('UPDATE bookings SET calendar_href = ?, calendar_status = ? WHERE id = ?')->execute([$href, $href !== '' ? 'synced' : 'failed', $row['id']]);
        }
        $saved[] = $item;
    }
    forget_calendar_cache();
    $mail = ['sent' => false, 'error' => ''];
    if ($saved && $booked[0]['customer_email'] !== '' && $booked[0]['email_status'] !== 'sent') {
        $mail = send_mail('info', $booked[0]['customer_email'], $booked[0]['customer_name'],
            'Your Pawpad grooming booking is confirmed (' . $booked[0]['ref'] . ')',
            booking_confirmation_text($booked[0]['customer_name'], $saved, '', (float) $p['amount']));
        db()->prepare('UPDATE bookings SET email_status = ? WHERE payment_ref = ?')->execute([$mail['sent'] ? 'sent' : 'failed', $p['id']]);
    }
    return [
        'ref' => $p['booking_ref'],
        'bookings' => array_map(function (array $item): array {
            return ['date' => $item['date'], 'time' => $item['time'], 'label' => friendly_slot($item['date'], $item['time']),
                'serviceTitle' => $item['serviceTitle'], 'petName' => $item['pet']['name']];
        }, $saved),
        'lost' => count($lost),
        'emailSent' => $mail['sent'],
        'calendarSynced' => $calendarOk,
        'depositPaid' => (float) $p['amount'],
    ];
}

// ---------------------------------------------------------------------------
// Public actions
// ---------------------------------------------------------------------------

/** The website checkout reports a successful Razorpay payment (checked by signature). */
function confirm_payment(array $input): array
{
    $orderId = (string) ($input['orderId'] ?? '');
    $paymentId = (string) ($input['paymentId'] ?? '');
    $signature = (string) ($input['signature'] ?? '');
    if (!razorpay_checkout_signature_ok($orderId, $paymentId, $signature)) {
        json_error('The payment could not be verified. Please WhatsApp us on ' . STUDIO_WHATSAPP . '.', 400);
    }
    $p = payment_by_razorpay_id($orderId);
    if (!$p) {
        json_error('Payment not found.', 404);
    }
    $result = finalize_payment((int) $p['id'], $paymentId);
    if (!empty($result['already'])) {
        $result = confirm_paid_bookings($result['payment']);
    }
    return $result;
}

/**
 * Razorpay's webhook (Dashboard → Webhooks): payment.captured, order.paid and
 * payment_link.paid. Confirms bookings even if the customer closed the page.
 */
function razorpay_webhook(string $raw): array
{
    $secret = razorpay_settings()['webhook_secret'];
    $signature = (string) ($_SERVER['HTTP_X_RAZORPAY_SIGNATURE'] ?? '');
    if ($secret === '' || $signature === '' || !hash_equals(hash_hmac('sha256', $raw, $secret), $signature)) {
        json_error('Invalid signature.', 401);
    }
    $event = json_decode($raw, true);
    if (!is_array($event)) {
        json_error('Invalid body.');
    }
    $type = (string) ($event['event'] ?? '');
    $payment = $event['payload']['payment']['entity'] ?? [];
    $row = null;
    if ($type === 'payment_link.paid') {
        $row = payment_by_razorpay_id((string) ($event['payload']['payment_link']['entity']['id'] ?? ''));
    } elseif (in_array($type, ['payment.captured', 'order.paid', 'payment.authorized'], true)) {
        $row = payment_by_razorpay_id((string) ($payment['order_id'] ?? ($event['payload']['order']['entity']['id'] ?? '')));
    }
    if (!$row || empty($payment['id'])) {
        return ['ignored' => true];
    }
    finalize_payment((int) $row['id'], (string) $payment['id']);
    return ['handled' => $type];
}

// ---------------------------------------------------------------------------
// Refunds when a booking is cancelled
// ---------------------------------------------------------------------------

/** Hours from now until the booking starts. */
function hours_until_booking(array $row): float
{
    $start = slot_start($row['slot_date'], $row['slot_time']);
    return ($start->getTimestamp() - time()) / 3600;
}

/**
 * The deposit of a cancelled booking: refunded ("refund"), kept ("keep"), or by the
 * rule ("auto": refunded when cancelled at least 24 hours before the slot).
 * Returns a sentence for the admin panel and the customer's email.
 */
function settle_deposit_on_cancel(array $row, string $choice): array
{
    if (($row['deposit_status'] ?? '') !== 'paid' || empty($row['payment_ref'])) {
        if (($row['deposit_status'] ?? '') === 'awaiting' && !empty($row['payment_ref'])) {
            $p = payment_row((int) $row['payment_ref']);
            if ($p && $p['kind'] === 'link' && $p['status'] === 'created') {
                razorpay_request('POST', '/v1/payment_links/' . rawurlencode($p['razorpay_id']) . '/cancel');
                db()->prepare("UPDATE payments SET status = 'cancelled', updated_at = ? WHERE id = ?")->execute([now_utc(), $p['id']]);
            }
        }
        return ['text' => '', 'refunded' => false];
    }
    $refund = $choice === 'refund' || ($choice !== 'keep' && hours_until_booking($row) >= REFUND_NOTICE_HOURS);
    $amount = (float) $row['deposit'];
    if (!$refund) {
        db()->prepare("UPDATE bookings SET deposit_status = 'kept' WHERE id = ?")->execute([$row['id']]);
        return ['text' => 'The booking deposit of ' . inr($amount) . ' is not refundable (cancelled less than 24 hours before the appointment).', 'refunded' => false, 'kept' => true];
    }
    $p = payment_row((int) $row['payment_ref']);
    $refundId = $p ? razorpay_refund($p, $amount, 'Booking ' . $row['ref'] . ' cancelled') : '';
    db()->prepare('UPDATE bookings SET deposit_status = ? WHERE id = ?')->execute([$refundId !== '' ? 'refunded' : 'refund_failed', $row['id']]);
    return $refundId !== ''
        ? ['text' => 'Your booking deposit of ' . inr($amount) . ' has been refunded. It usually reaches your account in 5 to 7 working days.', 'refunded' => true]
        : ['text' => '', 'refunded' => false, 'error' => 'The refund could not be made in Razorpay. Please refund ' . inr($amount) . ' in the Razorpay Dashboard.'];
}

/** Admin: refund a kept or paid deposit by hand (e.g. a goodwill refund). */
function refund_deposit(array $admin, array $input): array
{
    $row = find_booking((int) ($input['id'] ?? 0));
    if (!in_array($row['deposit_status'] ?? '', ['paid', 'kept', 'refund_failed'], true) || empty($row['payment_ref'])) {
        json_error('This booking has no deposit to refund.');
    }
    $p = payment_row((int) $row['payment_ref']);
    $amount = (float) $row['deposit'];
    $refundId = $p ? razorpay_refund($p, $amount, 'Refunded by ' . $admin['email']) : '';
    if ($refundId === '') {
        json_error('Razorpay could not make the refund. Please try again, or refund it in the Razorpay Dashboard.', 502);
    }
    db()->prepare("UPDATE bookings SET deposit_status = 'refunded', admin_log = ? WHERE id = ?")
        ->execute([booking_log_entry($row, $admin, 'Deposit of ' . inr($amount) . ' refunded'), $row['id']]);
    return ['booking' => bookings_with_segments([find_booking((int) $row['id'])])[0]];
}

// ---------------------------------------------------------------------------
// Boarding deposits (a link when the team confirms the stay)
// ---------------------------------------------------------------------------

/** The paid stay becomes "Confirmed" and the customer gets an email. */
function confirm_paid_boarding(array $p): array
{
    $stmt = db()->prepare('SELECT * FROM boarding_requests WHERE id = ?');
    $stmt->execute([(int) $p['boarding_id']]);
    $row = $stmt->fetch();
    if (!$row) {
        return ['ref' => $p['booking_ref']];
    }
    $now = now_utc();
    $log = json_decode((string) ($row['admin_log'] ?? ''), true) ?: [];
    $log[] = ['date' => to_iso($now), 'by' => 'Razorpay', 'text' => 'Deposit of ' . inr((float) $p['amount']) . ' paid: stay confirmed.'];
    db()->prepare("UPDATE boarding_requests SET status = 'confirmed', deposit_status = 'paid', admin_log = ?, updated_at = ? WHERE id = ?")
        ->execute([json_encode($log, JSON_UNESCAPED_UNICODE), $now, $row['id']]);
    $mail = ['sent' => false];
    if ($row['customer_email'] !== '') {
        $mail = send_mail('info', $row['customer_email'], $row['customer_name'], 'Your Pawpad boarding stay is confirmed (' . $row['ref'] . ')',
            'Dear ' . $row['customer_name'] . ",\n\nThank you! We received your booking deposit of " . inr((float) $p['amount'])
            . ' and your boarding stay (' . $row['ref'] . ") is confirmed. The deposit is deducted from your final bill.\n\n"
            . 'Cancellation: the deposit is refunded in full if you cancel at least 24 hours before the stay begins.' . "\n\n"
            . 'For any questions, WhatsApp us on ' . STUDIO_WHATSAPP . ".\n\nWarm regards,\nPawpad\nhttps://pawpad.in\n");
    }
    return ['ref' => $row['ref'], 'boarding' => true, 'emailSent' => $mail['sent']];
}

/**
 * Staff: confirm a boarding request by sending a ₹500-per-dog deposit link (valid 2 hours).
 * The nights are held while the link is valid; paid, the stay is confirmed.
 */
function send_boarding_deposit_link(array $admin, array $input): array
{
    if (!deposits_active()) {
        json_error('Deposits are not switched on yet (Razorpay keys in config.php).');
    }
    $stmt = db()->prepare('SELECT * FROM boarding_requests WHERE id = ?');
    $stmt->execute([(int) ($input['id'] ?? 0)]);
    $row = $stmt->fetch();
    if (!$row) {
        json_error('Boarding request not found.', 404);
    }
    if (in_array($row['status'], ['confirmed', 'awaiting_deposit'], true)) {
        json_error('This stay is already confirmed or waiting for its deposit.');
    }
    $dogs = max(1, (int) $row['dogs']);
    if (!empty($row['check_in']) && !empty($row['check_out'])) {
        $full = boarding_full_nights($row['check_in'], $row['check_out'], $dogs, (int) $row['id']);
        if ($full) {
            json_error('Boarding is full on ' . boarding_nights_text($full) . '.', 409);
        }
    }
    $amount = deposit_amount_setting() * $dogs;
    $expires = (new DateTimeImmutable('now', studio_tz()))->modify('+' . (int) round(deposit_link_hours() * 60) . ' minutes');
    $link = create_razorpay_link($amount, 'Pawpad boarding deposit (' . $row['ref'] . ', ' . $dogs . ' dog' . ($dogs > 1 ? 's' : '') . ')',
        ['name' => $row['customer_name'], 'phone' => $row['customer_phone'], 'email' => $row['customer_email']], $expires, $row['ref'], ['ref' => $row['ref'], 'purpose' => 'boarding']);
    if (!$link) {
        json_error('Razorpay could not make the payment link. Please try again in a minute.', 502);
    }
    $paymentId = new_payment('link', 'boarding', $link['id'], $row['ref'], $amount, $expires, $link['url'], (int) $row['id']);
    $log = json_decode((string) ($row['admin_log'] ?? ''), true) ?: [];
    $log[] = ['date' => to_iso(now_utc()), 'by' => $admin['email'], 'text' => 'Deposit link of ' . inr($amount) . ' sent (valid until ' . $expires->format('g:i A, j M') . ').'];
    db()->prepare("UPDATE boarding_requests SET status = 'awaiting_deposit', deposit = ?, deposit_status = 'awaiting', payment_ref = ?, admin_log = ?, updated_at = ? WHERE id = ?")
        ->execute([$amount, $paymentId, json_encode($log, JSON_UNESCAPED_UNICODE), now_utc(), $row['id']]);
    return ['link' => $link['url'], 'amount' => $amount, 'expires' => $expires->format('g:i A, j M')] + list_boarding_requests();
}

/** Staff (Owner / Administrator): refund a boarding stay's deposit (e.g. cancelled in time). */
function refund_boarding_deposit(array $admin, array $input): array
{
    $stmt = db()->prepare('SELECT * FROM boarding_requests WHERE id = ?');
    $stmt->execute([(int) ($input['id'] ?? 0)]);
    $row = $stmt->fetch();
    if (!$row || ($row['deposit_status'] ?? '') !== 'paid' || empty($row['payment_ref'])) {
        json_error('This stay has no paid deposit to refund.');
    }
    $p = payment_row((int) $row['payment_ref']);
    $amount = (float) $row['deposit'];
    $refundId = $p ? razorpay_refund($p, $amount, 'Boarding ' . $row['ref'] . ' refunded by ' . $admin['email']) : '';
    if ($refundId === '') {
        json_error('Razorpay could not make the refund. Please try again, or refund it in the Razorpay Dashboard.', 502);
    }
    $log = json_decode((string) ($row['admin_log'] ?? ''), true) ?: [];
    $log[] = ['date' => to_iso(now_utc()), 'by' => $admin['email'], 'text' => 'Deposit of ' . inr($amount) . ' refunded.'];
    db()->prepare("UPDATE boarding_requests SET deposit_status = 'refunded', admin_log = ?, updated_at = ? WHERE id = ?")
        ->execute([json_encode($log, JSON_UNESCAPED_UNICODE), now_utc(), $row['id']]);
    return list_boarding_requests();
}
