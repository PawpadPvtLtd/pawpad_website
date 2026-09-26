<?php
/**
 * Boarding requests: the website checkout saves every boarding order here, the
 * customer and info@ get an email, and the team confirms it in the admin panel.
 */

declare(strict_types=1);

if (!defined('PAWPAD_API')) {
    http_response_code(404);
    exit;
}

const BOARDING_STATUSES = ['new' => 'New', 'confirmed' => 'Confirmed', 'declined' => 'Declined', 'completed' => 'Completed', 'cancelled' => 'Cancelled'];

function boarding_to_array(array $row): array
{
    return [
        'id' => (int) $row['id'],
        'ref' => $row['ref'],
        'status' => $row['status'],
        'statusLabel' => BOARDING_STATUSES[$row['status']] ?? $row['status'],
        'customer' => [
            'name' => $row['customer_name'],
            'email' => $row['customer_email'],
            'phone' => $row['customer_phone'],
            'contactMethod' => $row['contact_method'],
        ],
        'stayDate' => $row['stay_date'],
        'stayTime' => $row['stay_time'],
        'pets' => json_decode((string) $row['pets'], true) ?: [],
        'items' => json_decode((string) $row['items'], true) ?: [],
        'total' => $row['total'],
        'trialFee' => $row['trial_fee'],
        'notes' => $row['notes'],
        'adminLog' => json_decode((string) ($row['admin_log'] ?? ''), true) ?: [],
        'emailStatus' => $row['email_status'],
        'createdAt' => to_iso($row['created_at']),
    ];
}

/** Public: the checkout sends a boarding order. */
function create_boarding_request(array $input): array
{
    if (!empty($input['botcheck'])) {
        json_error('Request rejected.');
    }
    rate_limit('boarding', 20, 3600);
    $customer = is_array($input['customer'] ?? null) ? $input['customer'] : [];
    $name = clean_text($customer['name'] ?? '', 255);
    $email = clean_text($customer['email'] ?? '', 255);
    $phone = clean_text($customer['phone'] ?? '', 60);
    if ($name === '' || $phone === '') {
        json_error('Please fill in your name and phone number.');
    }
    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        json_error('Please enter a valid email address.');
    }
    $pets = [];
    foreach (array_slice(is_array($input['pets'] ?? null) ? $input['pets'] : [], 0, 8) as $pet) {
        if (!is_array($pet)) {
            continue;
        }
        $trial = $pet['hasCompletedTrialDay'] ?? null;
        $pets[] = [
            'name' => clean_text($pet['name'] ?? '', 100),
            'breed' => clean_text($pet['breed'] ?? '', 100),
            'age' => clean_text($pet['age'] ?? '', 40),
            'size' => clean_text($pet['size'] ?? '', 60),
            'temperament' => clean_text($pet['temperament'] ?? '', 60),
            'healthNotes' => clean_text($pet['healthNotes'] ?? '', 1000),
            'service' => clean_text($pet['serviceTitle'] ?? '', 255),
            'trialDone' => $trial === true ? 'yes' : ($trial === false ? 'no' : ''),
        ];
    }
    $items = [];
    foreach (array_slice(is_array($input['items'] ?? null) ? $input['items'] : [], 0, 20) as $item) {
        if (is_array($item)) {
            $items[] = [
                'title' => clean_text($item['title'] ?? '', 255),
                'quantity' => max(1, min(30, (int) ($item['quantity'] ?? 1))),
                'price' => clean_text($item['priceDisplay'] ?? ($item['price'] ?? ''), 60),
            ];
        }
    }
    if (!$items) {
        json_error('There is no boarding stay in this order.');
    }
    $date = clean_text($input['stayDate'] ?? '', 40);
    $time = clean_text($input['stayTime'] ?? '', 40);
    $total = clean_text($input['total'] ?? '', 40);
    $trialFee = clean_text($input['trialFee'] ?? '', 40);
    $notes = clean_text($input['notes'] ?? '', 2000);
    $now = now_utc();

    $pdo = db();
    $pdo->beginTransaction();
    $ref = 'BRD-' . str_pad((string) next_sequence($pdo, '#BOARDING'), 4, '0', STR_PAD_LEFT);
    $pdo->prepare(
        'INSERT INTO boarding_requests (ref, status, customer_name, customer_email, customer_phone, contact_method, stay_date, stay_time,
            pets, items, total, trial_fee, notes, admin_log, created_at, updated_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    )->execute([$ref, 'new', $name, $email, $phone, clean_text($customer['contactMethod'] ?? '', 30), $date, $time,
        json_encode($pets, JSON_UNESCAPED_UNICODE), json_encode($items, JSON_UNESCAPED_UNICODE), $total, $trialFee, $notes, '[]', $now, $now]);
    $id = (int) $pdo->lastInsertId();
    $pdo->commit();

    // One email: to the customer (if they gave an address) with a copy to info@, or to info@ only.
    $lines = array_map(function (array $i): string {
        return '• ' . $i['title'] . ($i['quantity'] > 1 ? ' × ' . $i['quantity'] : '') . ($i['price'] !== '' ? ' — ' . $i['price'] : '');
    }, $items);
    $petLines = array_map(function (array $p): string {
        return '• ' . ($p['name'] !== '' ? $p['name'] : 'Dog') . ($p['breed'] !== '' ? ' (' . $p['breed'] . ')' : '')
            . ($p['trialDone'] === 'no' ? ' — first stay, trial day needed' : ($p['trialDone'] === 'yes' ? ' — trial day done' : ''));
    }, $pets);
    $body = 'Dear ' . $name . ",\n\nThank you for your boarding request with Pawpad. We have received it and will confirm your dates by "
        . ($customer['contactMethod'] ?? 'phone') . " shortly. Nothing is booked until we confirm.\n\n"
        . 'Request reference: ' . $ref . "\n"
        . ($date !== '' ? 'Requested: ' . $date . ($time !== '' ? ' · ' . $time : '') . "\n" : '')
        . "\n" . implode("\n", $lines) . "\n"
        . ($petLines ? "\nDogs:\n" . implode("\n", $petLines) . "\n" : '')
        . ($trialFee !== '' && $trialFee !== '0' && $trialFee !== '₹0' ? "\nTrial day fee: " . $trialFee . "\n" : '')
        . ($total !== '' ? 'Estimated total: ' . $total . "\n" : '')
        . ($notes !== '' ? "\nNotes: " . $notes . "\n" : '')
        . "\nPayment: at the studio.\nStudio: " . STUDIO_ADDRESS . "\nQuestions? Reply to this email or WhatsApp us on " . STUDIO_WHATSAPP . ".\n\nWarm regards,\nPawpad\nhttps://pawpad.in\n";
    $to = $email !== '' ? $email : mail_account('info')['from'];
    $mail = send_mail('info', $to, $name, 'Your Pawpad boarding request (' . $ref . ')', $body);
    db()->prepare('UPDATE boarding_requests SET email_status = ? WHERE id = ?')->execute([$mail['sent'] ? 'sent' : 'failed', $id]);
    return ['ref' => $ref, 'emailSent' => $mail['sent']];
}

function list_boarding_requests(): array
{
    $rows = db()->query('SELECT * FROM boarding_requests ORDER BY created_at DESC, id DESC LIMIT 500')->fetchAll();
    return ['requests' => array_map('boarding_to_array', $rows), 'statuses' => BOARDING_STATUSES];
}

/** Staff: change the status and/or add a note; both are logged with who did it. */
function update_boarding_request(array $admin, array $input): array
{
    $stmt = db()->prepare('SELECT * FROM boarding_requests WHERE id = ?');
    $stmt->execute([(int) ($input['id'] ?? 0)]);
    $row = $stmt->fetch();
    if (!$row) {
        json_error('Boarding request not found.', 404);
    }
    $status = (string) ($input['status'] ?? $row['status']);
    if (!isset(BOARDING_STATUSES[$status])) {
        json_error('Unknown status.');
    }
    $note = clean_text($input['note'] ?? '', 1000);
    $log = json_decode((string) ($row['admin_log'] ?? ''), true) ?: [];
    $now = to_iso(now_utc());
    if ($status !== $row['status']) {
        $log[] = ['date' => $now, 'by' => $admin['email'], 'text' => 'Status: ' . BOARDING_STATUSES[$row['status']] . ' → ' . BOARDING_STATUSES[$status]];
    }
    if ($note !== '') {
        $log[] = ['date' => $now, 'by' => $admin['email'], 'text' => $note];
    }
    db()->prepare('UPDATE boarding_requests SET status = ?, admin_log = ?, updated_at = ? WHERE id = ?')
        ->execute([$status, json_encode($log, JSON_UNESCAPED_UNICODE), now_utc(), $row['id']]);
    $stmt->execute([$row['id']]);
    return ['request' => boarding_to_array($stmt->fetch())];
}
