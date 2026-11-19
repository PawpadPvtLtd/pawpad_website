<?php
/**
 * Grooming invoices (PDF on the Pawpad letterhead, no GST: Pawpad is not GST-registered).
 *
 * - When a booking is marked "Completed" in Today's Closing and its payment mode is
 *   chosen (or a walk-in is added that way), the invoice is made automatically once every
 *   pet of that booking reference on that day is done, and emailed from info@ if there is
 *   an email address.
 * - Staff can open, edit (add or remove items, e.g. extras), download and re-send it.
 *   An edited invoice keeps its number and is marked "Revised".
 * - Customers without an email: staff download the PDF and share it on WhatsApp.
 *
 * Numbers run per financial year: INV/2026-27/0001, INV/2026-27/0002, …
 */

declare(strict_types=1);

if (!defined('PAWPAD_API')) {
    http_response_code(404);
    exit;
}

// The studio details printed on every invoice (the letterhead's footer, with the studio's number).
const INVOICE_COMPANY = 'PAWPAD PRIVATE LIMITED';
const INVOICE_TAGLINE = 'Conscious Pet Grooming & Wellness';
const INVOICE_SINCE = 'Bengaluru · Since 2017';
const INVOICE_STUDIO_ADDRESS = '#426, 5th Main Road, HRBR 2nd Block, Kalyan Nagar, Bengaluru – 560043, Karnataka, India';
const INVOICE_CONTACT = ['+91 91484 43330', 'info@pawpad.in', 'www.pawpad.in'];
const INVOICE_CIN = 'U96092KA2026PTC227754';
const INVOICE_MAX_LINES = 40;

/** "2026-27" for a date from April 2026 to March 2027. */
function invoice_fy(DateTimeImmutable $day): string
{
    $y = (int) $day->format('Y');
    $start = (int) $day->format('n') >= 4 ? $y : $y - 1;
    return $start . '-' . substr((string) ($start + 1), 2);
}

/** 250000.5 → "₹2,50,000.50" (Indian grouping). */
function inr(float $amount): string
{
    $negative = $amount < 0;
    $fixed = number_format(abs($amount), 2, '.', '');
    [$whole, $paise] = explode('.', $fixed);
    if (strlen($whole) > 3) {
        $last3 = substr($whole, -3);
        $rest = substr($whole, 0, -3);
        $rest = preg_replace('/\B(?=(\d{2})+(?!\d))/', ',', $rest);
        $whole = $rest . ',' . $last3;
    }
    return ($negative ? '-' : '') . '₹' . $whole . '.' . $paise;
}

function invoice_row(int $id): array
{
    $stmt = db()->prepare('SELECT * FROM invoices WHERE id = ?');
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    if (!$row) {
        json_error('Invoice not found.', 404);
    }
    return $row;
}

/** The invoice a booking is on, or null. */
function invoice_for_booking(int $bookingId): ?array
{
    $stmt = db()->prepare('SELECT i.* FROM invoices i JOIN invoice_bookings ib ON ib.invoice_id = i.id WHERE ib.booking_id = ?');
    $stmt->execute([$bookingId]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function invoice_to_array(array $row): array
{
    $data = json_decode((string) $row['data'], true) ?: [];
    return [
        'id' => (int) $row['id'],
        'number' => $row['number'],
        'date' => $row['invoice_date'],
        'bookingRef' => $row['booking_ref'],
        'revision' => (int) $row['revision'],
        'customer' => $data['customer'] ?? ['name' => '', 'phone' => '', 'email' => ''],
        'lines' => $data['lines'] ?? [],
        'subtotal' => (float) ($data['subtotal'] ?? 0),
        'deposit' => (float) ($data['deposit'] ?? 0),
        'total' => (float) ($data['total'] ?? 0),
        'paid' => (float) ($data['paid'] ?? 0),
        'balance' => (float) ($data['balance'] ?? 0),
        'payment' => $data['payment'] ?? '',
        'bookingIds' => $data['bookingIds'] ?? [],
        'emailStatus' => $row['email_status'],
        'emailedTo' => $row['emailed_to'],
        'emailedAt' => to_iso($row['emailed_at']),
        'createdBy' => $row['created_by'],
        'createdAt' => to_iso($row['created_at']),
        'updatedBy' => $row['updated_by'],
        'updatedAt' => to_iso($row['updated_at']),
    ];
}

/** The bookings of one booking reference on one day (with their closing details). */
function invoice_group_rows(string $ref, string $date): array
{
    return array_values(array_filter(closing_rows($date), function (array $b) use ($ref): bool {
        return $b['ref'] === $ref;
    }));
}

/**
 * Items, customer and payment for an invoice, from the bookings' closing details:
 * the amount collected for each pet (the Matted Dogs extra on its own line).
 */
function invoice_draft(array $rows): array
{
    $first = $rows[0];
    $lines = [];
    $modes = [];
    $paidAll = true;
    foreach ($rows as $b) {
        $c = $b['closing'];
        $amount = $c['amount'] !== null ? (float) $c['amount'] : (float) ($b['price'] ?? 0);
        $matted = !empty($b['matted']) ? (float) ($b['mattedPrice'] ?? 0) : 0.0;
        $pet = trim(($b['pet']['name'] ?? '') . (($b['pet']['type'] ?? '') !== '' ? ' (' . $b['pet']['type'] . ')' : ''));
        $detail = trim(($pet !== '' ? $pet . ' · ' : '') . friendly_slot($b['date'], $b['time']));
        $lines[] = ['description' => $b['serviceTitle'], 'detail' => $detail, 'amount' => round(max(0, $amount - $matted), 2)];
        if ($matted > 0) {
            $lines[] = ['description' => 'Matted Dogs', 'detail' => 'Extra time for a matted coat' . ($pet !== '' ? ' · ' . $pet : ''), 'amount' => round(min($matted, $amount), 2)];
        }
        if ($c['mode'] !== '' && $c['mode'] !== 'not_paid') {
            $modes[$c['mode']] = closing_modes()[$c['mode']] . ($c['reference'] !== '' ? ' (ref ' . $c['reference'] . ')' : '');
        } else {
            $paidAll = false;
        }
    }
    return [
        'customer' => ['name' => $first['customer']['name'], 'phone' => $first['customer']['phone'], 'email' => $first['customer']['email']],
        'lines' => $lines,
        'paidInFull' => $paidAll,
        'payment' => $paidAll ? 'Paid by ' . implode(', ', $modes) : '',
        'bookingIds' => array_map(function (array $b): int {
            return (int) $b['id'];
        }, $rows),
        'bookingRef' => $first['ref'],
        'date' => $first['date'],
    ];
}

/** Checks the items and works out the totals. */
function invoice_totals(array $lines, bool $paidInFull, float $deposit = 0.0): array
{
    $clean = [];
    foreach (array_slice($lines, 0, INVOICE_MAX_LINES) as $line) {
        if (!is_array($line)) {
            continue;
        }
        $description = clean_text($line['description'] ?? '', 160);
        $amount = parse_amount($line['amount'] ?? '');
        if ($description === '' && ($amount === null || $amount == 0.0)) {
            continue; // an empty row
        }
        if ($description === '') {
            json_error('Every item needs a description.');
        }
        if ($amount === null || $amount < 0) {
            json_error('The amount for "' . $description . '" must be a number, for example 850.');
        }
        $clean[] = ['description' => $description, 'detail' => clean_text($line['detail'] ?? '', 200), 'amount' => round($amount, 2)];
    }
    if (!$clean) {
        json_error('The invoice needs at least one item.');
    }
    $subtotal = round((float) array_sum(array_column($clean, 'amount')), 2);
    $total = round(max(0, $subtotal - $deposit), 2);
    $paid = $paidInFull ? $total : 0.0;
    return ['lines' => $clean, 'subtotal' => $subtotal, 'deposit' => round($deposit, 2), 'total' => $total, 'paid' => $paid, 'balance' => round($total - $paid, 2)];
}

/**
 * Makes a new invoice (or revises one) and emails it when asked and possible.
 * $draft: invoice_draft() output, possibly edited by staff.
 */
function store_invoice(array $admin, array $draft, ?array $existing, bool $send): array
{
    $customer = [
        'name' => clean_text($draft['customer']['name'] ?? '', 255),
        'phone' => clean_text($draft['customer']['phone'] ?? '', 60),
        'email' => clean_text($draft['customer']['email'] ?? '', 255),
    ];
    if ($customer['name'] === '') {
        json_error('Please enter the customer\'s name.');
    }
    if ($customer['email'] !== '' && !filter_var($customer['email'], FILTER_VALIDATE_EMAIL)) {
        json_error('The email address doesn\'t look right.');
    }
    $paidInFull = !empty($draft['paidInFull']);
    $totals = invoice_totals(is_array($draft['lines'] ?? null) ? $draft['lines'] : [], $paidInFull, (float) ($draft['deposit'] ?? 0));
    $data = $totals + [
        'customer' => $customer,
        'payment' => $paidInFull ? clean_text($draft['payment'] ?? 'Paid', 200) : '',
        'paidInFull' => $paidInFull,
        'bookingIds' => array_values(array_map('intval', $draft['bookingIds'] ?? [])),
    ];
    $now = now_utc();
    $pdo = db();
    if ($existing) {
        $pdo->prepare('UPDATE invoices SET data = ?, revision = revision + 1, updated_by = ?, updated_at = ? WHERE id = ?')
            ->execute([json_encode($data, JSON_UNESCAPED_UNICODE), $admin['email'], $now, $existing['id']]);
        $id = (int) $existing['id'];
    } else {
        $day = parse_studio_date((string) $draft['date']) ?? studio_today();
        $fy = invoice_fy($day);
        for ($attempt = 1; ; $attempt++) {
            $pdo->beginTransaction();
            try {
                $number = 'INV/' . $fy . '/' . str_pad((string) next_sequence($pdo, '#INV-' . $fy), 4, '0', STR_PAD_LEFT);
                $pdo->prepare('INSERT INTO invoices (number, invoice_date, booking_ref, data, revision, email_status, created_by, created_at, updated_by, updated_at)
                               VALUES (?, ?, ?, ?, 1, ?, ?, ?, ?, ?)')
                    ->execute([$number, $day->format('Y-m-d'), (string) ($draft['bookingRef'] ?? ''), json_encode($data, JSON_UNESCAPED_UNICODE), 'not_sent', $admin['email'], $now, $admin['email'], $now]);
                $id = (int) $pdo->lastInsertId();
                $link = $pdo->prepare('INSERT INTO invoice_bookings (invoice_id, booking_id) VALUES (?, ?)');
                foreach ($data['bookingIds'] as $bookingId) {
                    $link->execute([$id, $bookingId]);
                }
                $pdo->commit();
                break;
            } catch (Throwable $e) {
                $pdo->rollBack();
                if ($e instanceof PDOException && (int) ($e->errorInfo[1] ?? 0) === 1062) {
                    json_error('This booking already has an invoice. Please reload the page.', 409);
                }
                if ($attempt < 5 && is_retryable_lock_error($e)) {
                    usleep(random_int(20000, 120000));
                    continue;
                }
                throw $e;
            }
        }
    }
    $invoice = invoice_to_array(invoice_row($id));
    $mail = ['sent' => false, 'error' => ''];
    if ($send && $customer['email'] !== '') {
        $mail = email_invoice_to($invoice, $customer['email']);
        $invoice = invoice_to_array(invoice_row($id));
    } elseif ($customer['email'] === '' && $invoice['emailStatus'] !== 'sent') {
        $pdo->prepare("UPDATE invoices SET email_status = 'no_email' WHERE id = ?")->execute([$id]);
        $invoice['emailStatus'] = 'no_email';
    }
    return ['invoice' => $invoice, 'emailSent' => $mail['sent'], 'emailError' => $mail['error']];
}

function invoice_filename(array $invoice): string
{
    return 'Pawpad-' . str_replace('/', '-', $invoice['number']) . '.pdf';
}

/** Emails the PDF from info@ (a copy goes to info@ as for every booking email). */
function email_invoice_to(array $invoice, string $email): array
{
    $c = $invoice['customer'];
    $revised = $invoice['revision'] > 1;
    $body = 'Dear ' . $c['name'] . ",\n\n"
        . 'Thank you for choosing Pawpad! Your ' . ($revised ? 'revised ' : '') . 'invoice ' . $invoice['number'] . " is attached.\n\n"
        . 'Total: ' . inr($invoice['total']) . "\n"
        . ($invoice['balance'] > 0 ? 'Amount due: ' . inr($invoice['balance']) . "\n" : ($invoice['payment'] !== '' ? $invoice['payment'] . "\n" : ''))
        . "\nFor any questions, WhatsApp us on " . STUDIO_WHATSAPP . " or reply to this email.\n\n"
        . "Warm regards,\nPawpad Grooming Studio\nhttps://pawpad.in\n";
    $mail = send_mail('info', $email, $c['name'], ($revised ? 'Revised invoice ' : 'Your Pawpad invoice ') . $invoice['number'], $body, [
        ['name' => invoice_filename($invoice), 'data' => render_invoice_pdf($invoice), 'type' => 'application/pdf'],
    ]);
    db()->prepare('UPDATE invoices SET email_status = ?, emailed_to = ?, emailed_at = ? WHERE id = ?')
        ->execute([$mail['sent'] ? 'sent' : 'failed', $email, $mail['sent'] ? now_utc() : null, $invoice['id']]);
    return $mail;
}

/**
 * Called after a booking's closing is saved as "Completed" with a payment mode: makes and
 * emails the invoice once every pet of the same booking reference that day is done.
 * Returns a short summary for the admin panel, or null when nothing was done.
 */
function auto_invoice(array $admin, int $bookingId): ?array
{
    $booking = find_booking($bookingId);
    if (invoice_for_booking($bookingId)) {
        return null;
    }
    $group = invoice_group_rows($booking['ref'], $booking['slot_date']);
    foreach ($group as $b) {
        $c = $b['closing'];
        if (($b['status'] === 'booked' && $c['status'] === '') || ($c['status'] === 'completed' && $c['mode'] === '')) {
            return ['waiting' => true, 'message' => 'The invoice will be made when every pet of ' . $booking['ref'] . ' has a status and a payment mode.'];
        }
    }
    $completed = array_values(array_filter($group, function (array $b): bool {
        return $b['closing']['status'] === 'completed' && !invoice_for_booking((int) $b['id']);
    }));
    if (!$completed) {
        return null;
    }
    $result = store_invoice($admin, invoice_draft($completed), null, true);
    $inv = $result['invoice'];
    return [
        'invoice' => $inv,
        'message' => 'Invoice ' . $inv['number'] . ' made'
            . ($result['emailSent'] ? ' and emailed to ' . $inv['customer']['email'] . '.'
                : ($inv['customer']['email'] === '' ? ' (no email address: download the PDF to share it on WhatsApp).'
                    : ', but the email could not be sent: ' . $result['emailError'])),
        'emailSent' => $result['emailSent'],
    ];
}

// ---------------------------------------------------------------------------
// Admin actions
// ---------------------------------------------------------------------------

/** The invoice of a booking (or by id); without one, a draft to make it from. */
function get_invoice(array $input): array
{
    if (!empty($input['id'])) {
        return ['invoice' => invoice_to_array(invoice_row((int) $input['id']))];
    }
    $booking = find_booking((int) ($input['bookingId'] ?? 0));
    $existing = invoice_for_booking((int) $booking['id']);
    if ($existing) {
        return ['invoice' => invoice_to_array($existing)];
    }
    $group = invoice_group_rows($booking['ref'], $booking['slot_date']);
    $rows = array_values(array_filter($group, function (array $b) use ($booking): bool {
        return ((int) $b['id'] === (int) $booking['id'] || $b['closing']['status'] === 'completed') && !invoice_for_booking((int) $b['id']);
    }));
    return ['invoice' => null, 'draft' => invoice_draft($rows)];
}

/** Admin: make the invoice (from a booking) or save changes to it (revised, same number). */
function save_invoice(array $admin, array $input): array
{
    $send = !empty($input['send']);
    $draft = [
        'customer' => is_array($input['customer'] ?? null) ? $input['customer'] : [],
        'lines' => is_array($input['lines'] ?? null) ? $input['lines'] : [],
        'paidInFull' => !empty($input['paidInFull']),
        'payment' => (string) ($input['payment'] ?? ''),
    ];
    if (!empty($input['id'])) {
        $row = invoice_row((int) $input['id']);
        $old = invoice_to_array($row);
        $draft += ['bookingIds' => $old['bookingIds'], 'deposit' => $old['deposit'], 'date' => $old['date'], 'bookingRef' => $old['bookingRef']];
        return store_invoice($admin, $draft, $row, $send);
    }
    $booking = find_booking((int) ($input['bookingId'] ?? 0));
    if (invoice_for_booking((int) $booking['id'])) {
        json_error('This booking already has an invoice. Please reload the page.', 409);
    }
    $base = get_invoice(['bookingId' => $booking['id']])['draft'];
    $draft += ['bookingIds' => $base['bookingIds'], 'date' => $base['date'], 'bookingRef' => $base['bookingRef']];
    return store_invoice($admin, $draft, null, $send);
}

/** Admin: the PDF as base64 (the admin panel saves it as a file). */
function invoice_pdf(array $input): array
{
    $invoice = invoice_to_array(invoice_row((int) ($input['id'] ?? 0)));
    return ['filename' => invoice_filename($invoice), 'pdf' => base64_encode(render_invoice_pdf($invoice))];
}

/** Admin: email the invoice again (optionally to another address). */
function email_invoice(array $input): array
{
    $invoice = invoice_to_array(invoice_row((int) ($input['id'] ?? 0)));
    $email = clean_text($input['email'] ?? '', 255) ?: $invoice['customer']['email'];
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        json_error('There is no valid email address for this invoice. Type one in, or download the PDF.');
    }
    $mail = email_invoice_to($invoice, $email);
    if (!$mail['sent']) {
        json_error('The invoice could not be emailed: ' . $mail['error'], 502);
    }
    return ['invoice' => invoice_to_array(invoice_row($invoice['id'])), 'emailSent' => true];
}

/** Admin: invoices, newest first (optionally searched by number, name, phone or booking). */
function list_invoices(array $input): array
{
    $q = trim((string) ($input['q'] ?? ''));
    $sql = 'SELECT * FROM invoices';
    $args = [];
    if ($q !== '') {
        $sql .= ' WHERE number LIKE ? OR booking_ref LIKE ? OR data LIKE ?';
        $like = '%' . str_replace(['%', '_'], ['\\%', '\\_'], $q) . '%';
        $args = [$like, $like, $like];
    }
    $sql .= ' ORDER BY id DESC LIMIT 200';
    $stmt = db()->prepare($sql);
    $stmt->execute($args);
    return ['invoices' => array_map('invoice_to_array', $stmt->fetchAll())];
}

// ---------------------------------------------------------------------------
// The PDF (A4, letterhead: logo and company name at the top, paw trail behind,
// studio details at the bottom)
// ---------------------------------------------------------------------------

require_once __DIR__ . '/tFPDF/font/unifont/ttfonts.php';
require_once __DIR__ . '/tFPDF/tfpdf.php';

class PawpadInvoicePdf extends tFPDF
{
    public function Header(): void
    {
        $dir = __DIR__ . '/invoice/';
        $this->Image($dir . 'paw-trail.png', 0, 0, 210, 297);
        $this->Image($dir . 'logo.png', 18, 13, 56);
        $this->SetXY(100, 15);
        $this->SetFont('Carlito', 'B', 13);
        $this->SetTextColor(0x2D, 0x3B, 0x36);
        $this->Cell(92, 6, INVOICE_COMPANY, 0, 2, 'R');
        $this->SetFont('Carlito', '', 9.5);
        $this->SetTextColor(0xB1, 0x8D, 0x4E);
        $this->Cell(92, 5, INVOICE_TAGLINE, 0, 2, 'R');
        $this->SetFont('Carlito', '', 8.5);
        $this->SetTextColor(0x6B, 0x6B, 0x6B);
        $this->Cell(92, 4.5, INVOICE_SINCE, 0, 2, 'R');
        $this->SetDrawColor(0xB1, 0x8D, 0x4E);
        $this->SetLineWidth(0.4);
        $this->Line(18, 36, 192, 36);
        $this->SetY(44);
    }

    public function Footer(): void
    {
        $this->SetY(-26);
        $this->SetDrawColor(0xB1, 0x8D, 0x4E);
        $this->SetLineWidth(0.3);
        $this->Line(18, $this->GetY(), 192, $this->GetY());
        $this->Ln(2.5);
        $this->SetFont('Carlito', '', 8);
        $this->SetTextColor(0x6B, 0x6B, 0x6B);
        $this->Cell(0, 4, 'Studio: ' . INVOICE_STUDIO_ADDRESS, 0, 1, 'C');
        $this->Cell(0, 4, implode('   •   ', INVOICE_CONTACT), 0, 1, 'C');
        $this->SetFont('Carlito', '', 7.5);
        $this->SetTextColor(0x2D, 0x3B, 0x36);
        $this->Cell(0, 4, 'CIN: ' . INVOICE_CIN, 0, 1, 'C');
        if ($this->PageNo() > 1) {
            $this->SetTextColor(0x9A, 0x9A, 0x9A);
            $this->Cell(0, 3.5, 'Page ' . $this->PageNo(), 0, 0, 'R');
        }
    }
}

function render_invoice_pdf(array $invoice): string
{
    $pdf = new PawpadInvoicePdf('P', 'mm', 'A4');
    $pdf->AddFont('Carlito', '', 'Carlito-Regular.ttf', true);
    $pdf->AddFont('Carlito', 'B', 'Carlito-Bold.ttf', true);
    $pdf->SetMargins(18, 44, 18);
    $pdf->SetAutoPageBreak(true, 32);
    $pdf->SetTitle('Pawpad invoice ' . $invoice['number'], true);
    $pdf->SetAuthor(INVOICE_COMPANY, true);
    $pdf->SetCreator('Pawpad admin panel', true);
    $pdf->AddPage();
    $ink = [0x2D, 0x3B, 0x36];
    $muted = [0x6B, 0x6B, 0x6B];
    $gold = [0xB1, 0x8D, 0x4E];
    $color = function (array $c) use ($pdf): void {
        $pdf->SetTextColor($c[0], $c[1], $c[2]);
    };
    $date = parse_studio_date($invoice['date']);
    $revised = $invoice['revision'] > 1;

    // Title and invoice details
    $top = $pdf->GetY();
    $pdf->SetFont('Carlito', 'B', 22);
    $color($ink);
    $pdf->Cell(90, 10, 'INVOICE', 0, 2);
    if ($revised) {
        $pdf->SetFont('Carlito', '', 9);
        $color($gold);
        $pdf->Cell(90, 5, 'Revised ' . (new DateTimeImmutable($invoice['updatedAt'] ?? 'now'))->setTimezone(studio_tz())->format('j F Y'), 0, 2);
    }
    $pdf->SetXY(112, $top + 1);
    $details = [['Invoice no.', $invoice['number']], ['Date', $date ? $date->format('j F Y') : $invoice['date']]];
    if ($invoice['bookingRef'] !== '') {
        $details[] = ['Booking', $invoice['bookingRef']];
    }
    foreach ($details as [$label, $value]) {
        $pdf->SetX(112);
        $pdf->SetFont('Carlito', '', 9.5);
        $color($muted);
        $pdf->Cell(28, 5.5, $label, 0, 0, 'R');
        $pdf->SetFont('Carlito', 'B', 10);
        $color($ink);
        $pdf->Cell(52, 5.5, $value, 0, 1, 'R');
    }

    // Billed to, and payment
    $pdf->SetY(max($pdf->GetY(), $top + 20) + 4);
    $y = $pdf->GetY();
    $pdf->SetFont('Carlito', 'B', 8.5);
    $color($gold);
    $pdf->Cell(100, 5, 'BILLED TO', 0, 2);
    $pdf->SetFont('Carlito', 'B', 11.5);
    $color($ink);
    $pdf->Cell(100, 6, $invoice['customer']['name'], 0, 2);
    $pdf->SetFont('Carlito', '', 10);
    $color($muted);
    foreach ([$invoice['customer']['phone'], $invoice['customer']['email']] as $line) {
        if ($line !== '') {
            $pdf->Cell(100, 5, $line, 0, 2);
        }
    }
    $afterCustomer = $pdf->GetY();
    $pdf->SetXY(122, $y);
    $pdf->SetFont('Carlito', 'B', 8.5);
    $color($gold);
    $pdf->Cell(70, 5, 'PAYMENT', 0, 2, 'R');
    $pdf->SetFont('Carlito', '', 10);
    $color($ink);
    $pdf->MultiCell(70, 5, $invoice['balance'] > 0 ? 'Amount due: ' . inr($invoice['balance']) : ($invoice['payment'] !== '' ? $invoice['payment'] : 'Paid'), 0, 'R');
    $pdf->SetY(max($afterCustomer, $pdf->GetY()) + 7);

    // Items
    $pdf->SetFillColor(0xF3, 0xEE, 0xE4);
    $pdf->SetFont('Carlito', 'B', 9.5);
    $color($ink);
    $pdf->Cell(10, 8, '#', 0, 0, 'C', true);
    $pdf->Cell(122, 8, 'Description', 0, 0, 'L', true);
    $pdf->Cell(42, 8, 'Amount', 0, 1, 'R', true);
    $pdf->SetDrawColor(0xE2, 0xDA, 0xCB);
    $pdf->SetLineWidth(0.2);
    foreach ($invoice['lines'] as $i => $line) {
        if ($pdf->GetY() > 245) {
            $pdf->AddPage();
        }
        $rowTop = $pdf->GetY() + 2;
        $pdf->SetXY(18, $rowTop);
        $pdf->SetFont('Carlito', '', 10);
        $color($muted);
        $pdf->Cell(10, 5.5, (string) ($i + 1), 0, 0, 'C');
        $pdf->SetXY(28, $rowTop);
        $pdf->SetFont('Carlito', 'B', 10.5);
        $color($ink);
        $pdf->MultiCell(122, 5.5, $line['description'], 0, 'L');
        if (($line['detail'] ?? '') !== '') {
            $pdf->SetX(28);
            $pdf->SetFont('Carlito', '', 9);
            $color($muted);
            $pdf->MultiCell(122, 4.5, $line['detail'], 0, 'L');
        }
        $rowBottom = $pdf->GetY() + 2;
        $pdf->SetXY(150, $rowTop);
        $pdf->SetFont('Carlito', '', 10.5);
        $color($ink);
        $pdf->Cell(42, 5.5, inr((float) $line['amount']), 0, 0, 'R');
        $pdf->SetY($rowBottom);
        $pdf->Line(18, $rowBottom, 192, $rowBottom);
    }

    // Totals
    $pdf->Ln(3);
    $total = function (string $label, string $value, bool $strong = false) use ($pdf, $color, $ink, $muted): void {
        $pdf->SetX(112);
        $pdf->SetFont('Carlito', $strong ? 'B' : '', $strong ? 12 : 10);
        $color($strong ? $ink : $muted);
        $pdf->Cell(38, $strong ? 8 : 6, $label, 0, 0, 'R');
        $color($ink);
        $pdf->Cell(42, $strong ? 8 : 6, $value, 0, 1, 'R');
    };
    if ($invoice['deposit'] > 0 || count($invoice['lines']) > 1) {
        $total('Subtotal', inr($invoice['subtotal']));
    }
    if ($invoice['deposit'] > 0) {
        $total('Less: booking deposit paid', '-' . inr($invoice['deposit']));
    }
    $total('Total', inr($invoice['total']), true);
    if ($invoice['paid'] > 0) {
        $total('Paid', inr($invoice['paid']));
    }
    if ($invoice['balance'] > 0) {
        $total('Balance due', inr($invoice['balance']), true);
    }

    // Thank you
    $pdf->Ln(10);
    $pdf->SetFont('Carlito', '', 10);
    $color($muted);
    $pdf->MultiCell(0, 5, "Thank you for trusting Pawpad with your pet's care.\nFor any questions, WhatsApp us on " . STUDIO_WHATSAPP . '.', 0, 'L');

    return $pdf->Output('S');
}
