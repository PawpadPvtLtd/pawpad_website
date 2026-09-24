<?php
/**
 * Grooming slot bookings.
 *
 * Rules (confirmed by the owner):
 * - Start times 11:00, 12:00, 13:00, 16:00, 17:00, 18:00, 19:00; Saturday and
 *   Sunday also 10:00. Closed on Thursdays.
 * - 19:00 is only for services without a haircut / clipping.
 * - One booking per start time; every grooming service (one pet) uses one slot.
 * - Bookings from tomorrow up to 30 days ahead. Payment at the studio.
 * A slot is free when it isn't booked, isn't blocked by an admin, and has no
 * event in the info@pawpad.in calendar.
 */

declare(strict_types=1);

if (!defined('PAWPAD_API')) {
    http_response_code(404);
    exit;
}

const WEEKDAY_TIMES = ['11:00', '12:00', '13:00', '16:00', '17:00', '18:00', '19:00'];
const WEEKEND_EXTRA_TIME = '10:00';
const EVENING_TIME = '19:00';
// Services with a haircut or clipping: never at 19:00.
// "grooming-unspecified" is a booking where the service is chosen later at the studio.
const NO_EVENING_SERVICES = ['dog-grooming-long-hair', 'cat-haircut', 'puppy-long', 'matted-dogs', 'hygiene-clip', 'grooming-unspecified'];
const STUDIO_WHATSAPP = '+91 98450 01809';
const STUDIO_ADDRESS = 'Pawpad, #426, 5th Main Road, HRBR 2nd Block, Kalyan Nagar, Bangalore - 560043';

class SlotTakenException extends RuntimeException
{
    /** @var array */
    public $slot;
    /** @var bool true when an admin blocked the slot, false when someone else booked it */
    public $blocked;

    public function __construct(array $slot, bool $blocked = false)
    {
        parent::__construct('Slot taken');
        $this->slot = $slot;
        $this->blocked = $blocked;
    }
}

function studio_tz(): DateTimeZone
{
    return new DateTimeZone(STUDIO_TIMEZONE);
}

function studio_today(): DateTimeImmutable
{
    return new DateTimeImmutable('today', studio_tz());
}

function booking_days_ahead(): int
{
    return max(1, min(90, (int) (pawpad_config()['booking_days_ahead'] ?? 30)));
}

function slot_minutes(): int
{
    return max(15, (int) (pawpad_config()['booking_slot_minutes'] ?? 60));
}

function parse_studio_date(string $date): ?DateTimeImmutable
{
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        return null;
    }
    $d = DateTimeImmutable::createFromFormat('!Y-m-d', $date, studio_tz());
    return ($d && $d->format('Y-m-d') === $date) ? $d : null;
}

/** Start times the studio offers on a day (none on Thursdays). */
function slot_times_for(DateTimeImmutable $day): array
{
    $weekday = (int) $day->format('N'); // 1 = Monday ... 7 = Sunday
    if ($weekday === 4) {
        return [];
    }
    return $weekday >= 6 ? array_merge([WEEKEND_EXTRA_TIME], WEEKDAY_TIMES) : WEEKDAY_TIMES;
}

/** Dates customers can book: tomorrow up to 30 days ahead. */
function bookable_dates(): array
{
    $today = studio_today();
    $dates = [];
    for ($i = 1; $i <= booking_days_ahead(); $i++) {
        $dates[] = $today->modify('+' . $i . ' days')->format('Y-m-d');
    }
    return $dates;
}

function service_allows_time(string $serviceId, string $time): bool
{
    return $time !== EVENING_TIME || !in_array($serviceId, NO_EVENING_SERVICES, true);
}

function slot_start(string $date, string $time): DateTimeImmutable
{
    return new DateTimeImmutable($date . ' ' . $time . ':00', studio_tz());
}

function friendly_slot(string $date, string $time): string
{
    return slot_start($date, $time)->format('l, j F Y \a\t g:i A');
}

/**
 * Calendar busy periods, cached for a minute so visitors don't each query the calendar.
 */
function calendar_busy(DateTimeImmutable $from, DateTimeImmutable $to, bool $fresh): array
{
    $key = substr(hash('sha256', $from->getTimestamp() . '|' . $to->getTimestamp()), 0, 32);
    if (!$fresh) {
        $stmt = db()->prepare('SELECT fetched_at, data FROM calendar_cache WHERE cache_key = ?');
        $stmt->execute([$key]);
        $row = $stmt->fetch();
        if ($row && time() - (int) $row['fetched_at'] < 60) {
            return ['ok' => true, 'busy' => json_decode($row['data'], true) ?: [], 'error' => ''];
        }
    }
    $result = caldav_busy($from, $to);
    if ($result['ok']) {
        db()->prepare('INSERT INTO calendar_cache (cache_key, fetched_at, data) VALUES (?, ?, ?)
                       ON DUPLICATE KEY UPDATE fetched_at = VALUES(fetched_at), data = VALUES(data)')
            ->execute([$key, time(), json_encode($result['busy'])]);
        if (random_int(1, 20) === 1) {
            db()->prepare('DELETE FROM calendar_cache WHERE fetched_at < ?')->execute([time() - 3600]);
        }
    }
    return $result;
}

function slot_is_busy_in_calendar(array $busy, string $date, string $time): bool
{
    $start = slot_start($date, $time)->getTimestamp();
    $end = $start + slot_minutes() * 60;
    foreach ($busy as $period) {
        if ($period[0] < $end && $period[1] > $start) {
            return true;
        }
    }
    return false;
}

/**
 * What is happening at every start time on the given days.
 * @return array{days: array, calendarError: string}
 */
function slot_states(array $dates, bool $freshCalendar): array
{
    if (!$dates) {
        return ['days' => [], 'calendarError' => ''];
    }
    sort($dates);
    $first = $dates[0];
    $last = $dates[count($dates) - 1];

    $locks = [];
    $stmt = db()->prepare('SELECT slot_date, slot_time, booking_id FROM slot_locks WHERE slot_date BETWEEN ? AND ?');
    $stmt->execute([$first, $last]);
    foreach ($stmt->fetchAll() as $row) {
        $locks[$row['slot_date']][$row['slot_time']] = (int) $row['booking_id'];
    }

    $blocks = [];
    $stmt = db()->prepare('SELECT * FROM slot_blocks WHERE slot_date BETWEEN ? AND ?');
    $stmt->execute([$first, $last]);
    foreach ($stmt->fetchAll() as $row) {
        $blocks[$row['slot_date']][$row['slot_time']] = $row;
    }

    $calendar = calendar_busy(
        parse_studio_date($first),
        parse_studio_date($last)->modify('+1 day'),
        $freshCalendar
    );

    $days = [];
    foreach ($dates as $date) {
        $day = parse_studio_date($date);
        $slots = [];
        foreach (slot_times_for($day) as $time) {
            $state = ['time' => $time, 'state' => 'free'];
            if (isset($locks[$date][$time])) {
                $state['state'] = 'booked';
                $state['bookingId'] = $locks[$date][$time];
            } elseif (isset($blocks[$date][''])) {
                $state['state'] = 'blocked';
                $state['blockId'] = (int) $blocks[$date]['']['id'];
            } elseif (isset($blocks[$date][$time])) {
                $state['state'] = 'blocked';
                $state['blockId'] = (int) $blocks[$date][$time]['id'];
            } elseif (!$calendar['ok']) {
                $state['state'] = 'unknown';
            } elseif (slot_is_busy_in_calendar($calendar['busy'], $date, $time)) {
                $state['state'] = 'calendar';
            }
            $slots[] = $state;
        }
        $days[$date] = [
            'slots' => $slots,
            'dayBlock' => isset($blocks[$date]['']) ? block_to_array($blocks[$date]['']) : null,
        ];
    }
    return ['days' => $days, 'calendarError' => $calendar['ok'] ? '' : $calendar['error']];
}

function block_to_array(array $row): array
{
    return [
        'id' => (int) $row['id'],
        'date' => $row['slot_date'],
        'time' => $row['slot_time'],
        'reason' => $row['reason'],
        'createdBy' => $row['created_by'],
    ];
}

/**
 * Public: free start times for the next 30 days.
 */
function booking_availability(): array
{
    $states = slot_states(bookable_dates(), false);
    if ($states['calendarError'] !== '') {
        error_log('Pawpad API availability: ' . $states['calendarError']);
        json_error('Online booking is not available right now. Please WhatsApp us on ' . STUDIO_WHATSAPP . ' to book.', 503);
    }
    $days = [];
    foreach ($states['days'] as $date => $day) {
        $free = [];
        foreach ($day['slots'] as $slot) {
            if ($slot['state'] === 'free') {
                $free[] = $slot['time'];
            }
        }
        $days[] = ['date' => $date, 'times' => $free];
    }
    return [
        'days' => $days,
        'eveningTime' => EVENING_TIME,
        'noEveningServices' => NO_EVENING_SERVICES,
        'slotMinutes' => slot_minutes(),
    ];
}

function clean_pet(array $pet): array
{
    $type = clean_text($pet['type'] ?? '', 10);
    return [
        'name' => clean_text($pet['name'] ?? '', 100),
        'type' => in_array($type, ['Dog', 'Cat'], true) ? $type : '',
        'breed' => clean_text($pet['breed'] ?? '', 100),
        'age' => clean_text($pet['age'] ?? '', 40),
        'coat' => clean_text($pet['coat'] ?? '', 40),
        'size' => clean_text($pet['size'] ?? '', 60),
        'temperament' => clean_text($pet['temperament'] ?? '', 60),
        'healthNotes' => clean_text($pet['healthNotes'] ?? '', 1000),
    ];
}

function booking_to_array(array $row): array
{
    return [
        'id' => (int) $row['id'],
        'ref' => $row['ref'],
        'date' => $row['slot_date'],
        'time' => $row['slot_time'],
        'status' => $row['status'],
        'serviceId' => $row['service_id'],
        'serviceTitle' => $row['service_title'],
        'pet' => json_decode($row['pet'], true) ?: [],
        'customer' => [
            'name' => $row['customer_name'],
            'email' => $row['customer_email'],
            'phone' => $row['customer_phone'],
            'area' => $row['customer_area'],
            'contactMethod' => $row['contact_method'],
        ],
        'notes' => $row['notes'],
        'calendarStatus' => $row['calendar_status'],
        'emailStatus' => $row['email_status'],
        'createdAt' => to_iso($row['created_at']),
        'cancelledAt' => to_iso($row['cancelled_at']),
        'cancelledBy' => $row['cancelled_by'],
    ];
}

function send_slot_taken(array $slots, string $message): void
{
    send_json(['ok' => false, 'error' => $message, 'taken' => $slots], 409);
}

/**
 * Public: book one slot per pet. Either every pet in the order is booked, or none.
 */
function create_booking(array $input): array
{
    if (!empty($input['botcheck'])) {
        json_error('Booking rejected.');
    }
    rate_limit('booking', max(1, (int) (pawpad_config()['booking_rate_limit_per_hour'] ?? 20)), 3600);

    $customer = is_array($input['customer'] ?? null) ? $input['customer'] : [];
    $name = clean_text($customer['name'] ?? '', 255);
    $email = clean_text($customer['email'] ?? '', 255);
    $phone = clean_text($customer['phone'] ?? '', 60);
    if ($name === '' || $phone === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        json_error('Please fill in your name, phone number and a valid email address.');
    }

    $requested = is_array($input['pets'] ?? null) ? array_values($input['pets']) : [];
    if (count($requested) < 1 || count($requested) > 8) {
        json_error('Please choose a time for each pet (up to 8 pets per booking).');
    }

    $allowedDates = array_flip(bookable_dates());
    $items = [];
    $seen = [];
    foreach ($requested as $index => $item) {
        if (!is_array($item)) {
            json_error('Invalid booking.');
        }
        $serviceId = strtolower(preg_replace('/[^A-Za-z0-9-]/', '', (string) ($item['serviceId'] ?? '')));
        $serviceId = substr($serviceId, 0, 60) ?: 'grooming-unspecified';
        $date = (string) ($item['date'] ?? '');
        $time = (string) ($item['time'] ?? '');
        $day = parse_studio_date($date);
        $label = 'Pet ' . ($index + 1);
        if (!$day || !isset($allowedDates[$date])) {
            json_error($label . ': please choose a date from tomorrow up to ' . booking_days_ahead() . ' days ahead.');
        }
        if (!in_array($time, slot_times_for($day), true)) {
            json_error($label . ': the studio has no ' . $time . ' slot on ' . $day->format('l') . 's.');
        }
        if (!service_allows_time($serviceId, $time)) {
            json_error($label . ': the 7 PM slot is only for services without a haircut or clipping. Please choose an earlier time.');
        }
        if (isset($seen[$date . ' ' . $time])) {
            json_error('Each pet needs its own time slot. Two pets are booked for ' . friendly_slot($date, $time) . '.');
        }
        $seen[$date . ' ' . $time] = true;
        $items[] = [
            'serviceId' => $serviceId,
            'serviceTitle' => clean_text($item['serviceTitle'] ?? '', 255) ?: 'Grooming',
            'date' => $date,
            'time' => $time,
            'pet' => clean_pet(is_array($item['pet'] ?? null) ? $item['pet'] : []),
        ];
    }

    // Check the calendar right now (not the cached copy) for manual events.
    $dates = array_values(array_unique(array_column($items, 'date')));
    sort($dates);
    $calendar = calendar_busy(parse_studio_date($dates[0]), parse_studio_date($dates[count($dates) - 1])->modify('+1 day'), true);
    if (!$calendar['ok']) {
        error_log('Pawpad API booking: ' . $calendar['error']);
        json_error('Online booking is not available right now. Please WhatsApp us on ' . STUDIO_WHATSAPP . ' to book.', 503);
    }
    $taken = [];
    foreach ($items as $item) {
        if (slot_is_busy_in_calendar($calendar['busy'], $item['date'], $item['time'])) {
            $taken[] = ['date' => $item['date'], 'time' => $item['time']];
        }
    }
    if ($taken) {
        send_slot_taken($taken, 'Sorry, ' . friendly_slot($taken[0]['date'], $taken[0]['time']) . ' is no longer available. Please choose another time.');
    }

    $now = now_utc();
    $pdo = db();
    $saved = [];
    for ($attempt = 1; ; $attempt++) {
        $saved = [];
        $pdo->beginTransaction();
        try {
            $ref = '';
            for ($i = 0; $i < 10 && $ref === ''; $i++) {
                $candidate = 'PAW-' . random_int(100000, 999999);
                $check = $pdo->prepare('SELECT COUNT(*) FROM bookings WHERE ref = ?');
                $check->execute([$candidate]);
                if ((int) $check->fetchColumn() === 0) {
                    $ref = $candidate;
                }
            }
            $insert = $pdo->prepare(
                'INSERT INTO bookings (ref, slot_date, slot_time, status, service_id, service_title, pet, customer_name,
                    customer_email, customer_phone, customer_area, contact_method, notes, created_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $lock = $pdo->prepare('INSERT INTO slot_locks (slot_date, slot_time, booking_id) VALUES (?, ?, ?)');
            $blocked = $pdo->prepare("SELECT COUNT(*) FROM slot_blocks WHERE slot_date = ? AND (slot_time = '' OR slot_time = ?)");
            foreach ($items as $item) {
                $insert->execute([
                    $ref, $item['date'], $item['time'], 'booked', $item['serviceId'], $item['serviceTitle'],
                    json_encode($item['pet'], JSON_UNESCAPED_UNICODE), $name, $email, $phone,
                    clean_text($customer['area'] ?? '', 255), clean_text($customer['contactMethod'] ?? '', 30),
                    clean_text($input['notes'] ?? '', 2000), $now,
                ]);
                $id = (int) $pdo->lastInsertId();
                try {
                    $lock->execute([$item['date'], $item['time'], $id]);
                } catch (PDOException $e) {
                    if ((int) ($e->errorInfo[1] ?? 0) === 1062) {
                        throw new SlotTakenException(['date' => $item['date'], 'time' => $item['time']]);
                    }
                    throw $e;
                }
                $blocked->execute([$item['date'], $item['time']]);
                if ((int) $blocked->fetchColumn() > 0) {
                    throw new SlotTakenException(['date' => $item['date'], 'time' => $item['time']], true);
                }
                $saved[] = $item + ['id' => $id, 'ref' => $ref];
            }
            $pdo->commit();
            break;
        } catch (SlotTakenException $e) {
            $pdo->rollBack();
            send_slot_taken([$e->slot], 'Sorry, ' . friendly_slot($e->slot['date'], $e->slot['time'])
                . ($e->blocked ? ' is no longer available.' : ' was just booked by someone else.') . ' Please choose another time.');
        } catch (Throwable $e) {
            $pdo->rollBack();
            if ($attempt < 5 && is_retryable_lock_error($e)) {
                usleep(random_int(20000, 120000));
                continue;
            }
            error_log('Pawpad API booking failed: ' . $e->getMessage());
            json_error('Could not save your booking. Please try again.', 500);
        }
    }

    // The slots are safely reserved. Now add them to the info@ calendar and email the customer.
    $calendarOk = true;
    foreach ($saved as $item) {
        $href = caldav_create_event(
            'pawpad-booking-' . $item['id'] . '-' . bin2hex(random_bytes(4)) . '@pawpad.in',
            slot_start($item['date'], $item['time']),
            slot_start($item['date'], $item['time'])->modify('+' . slot_minutes() . ' minutes'),
            'Grooming: ' . ($item['pet']['name'] ?: 'Pet') . ($item['pet']['type'] ? ' (' . $item['pet']['type'] . ')' : '') . ' – ' . $item['serviceTitle'],
            booking_event_description($item, $name, $phone, $email, clean_text($input['notes'] ?? '', 2000)),
            STUDIO_ADDRESS
        );
        $calendarOk = $calendarOk && $href !== '';
        $pdo->prepare('UPDATE bookings SET calendar_href = ?, calendar_status = ? WHERE id = ?')
            ->execute([$href, $href !== '' ? 'synced' : 'failed', $item['id']]);
    }

    $mailResult = send_mail('info', $email, $name, 'Your Pawpad grooming booking is confirmed (' . $saved[0]['ref'] . ')',
        booking_confirmation_text($name, $saved, clean_text($input['estimatedTotal'] ?? '', 40)));
    $pdo->prepare('UPDATE bookings SET email_status = ? WHERE ref = ?')
        ->execute([$mailResult['sent'] ? 'sent' : 'failed', $saved[0]['ref']]);

    return [
        'ref' => $saved[0]['ref'],
        'bookings' => array_map(function (array $item): array {
            return [
                'date' => $item['date'],
                'time' => $item['time'],
                'label' => friendly_slot($item['date'], $item['time']),
                'serviceTitle' => $item['serviceTitle'],
                'petName' => $item['pet']['name'],
            ];
        }, $saved),
        'emailSent' => $mailResult['sent'],
        'calendarSynced' => $calendarOk,
    ];
}

function booking_event_description(array $item, string $name, string $phone, string $email, string $notes): string
{
    $pet = $item['pet'];
    $lines = [
        'Booking ' . $item['ref'],
        'Customer: ' . $name . ' · ' . $phone . ' · ' . $email,
        'Service: ' . $item['serviceTitle'],
        'Pet: ' . trim($pet['name'] . ' · ' . $pet['type'] . ' · ' . $pet['breed'], ' ·'),
    ];
    foreach (['age' => 'Age', 'size' => 'Size', 'coat' => 'Coat', 'temperament' => 'Temperament', 'healthNotes' => 'Health notes'] as $key => $label) {
        if ($pet[$key] !== '') {
            $lines[] = $label . ': ' . $pet[$key];
        }
    }
    if ($notes !== '') {
        $lines[] = 'Notes: ' . $notes;
    }
    $lines[] = 'Payment at the studio.';
    return implode("\n", $lines);
}

function booking_confirmation_text(string $name, array $saved, string $estimatedTotal): string
{
    $lines = [];
    foreach ($saved as $item) {
        $pet = $item['pet']['name'] !== '' ? $item['pet']['name'] : 'Your pet';
        $lines[] = '• ' . friendly_slot($item['date'], $item['time']) . ' — ' . $pet . ' — ' . $item['serviceTitle'];
    }
    $plural = count($saved) > 1;
    return 'Dear ' . $name . ",\n\n"
        . 'Thank you for booking with Pawpad! Your grooming appointment' . ($plural ? 's are' : ' is') . " confirmed:\n\n"
        . implode("\n", $lines) . "\n\n"
        . 'Booking reference: ' . $saved[0]['ref'] . "\n"
        . ($estimatedTotal !== '' ? 'Estimated total: ' . $estimatedTotal . "\n" : '')
        . "Payment: at the studio after the session (UPI, card or cash). No advance payment needed.\n\n"
        . 'Studio: ' . STUDIO_ADDRESS . "\n"
        . 'Need to change or cancel? Reply to this email or WhatsApp us on ' . STUDIO_WHATSAPP . ".\n\n"
        . "Warm regards,\nPawpad Grooming Studio\nhttps://pawpad.in\n";
}

// ---------------------------------------------------------------------------
// Admin
// ---------------------------------------------------------------------------

function list_bookings(array $input): array
{
    $date = (string) ($input['date'] ?? '');
    $day = $date === '' ? studio_today() : parse_studio_date($date);
    if (!$day) {
        json_error('Invalid date.');
    }
    $date = $day->format('Y-m-d');
    $states = slot_states([$date], false);
    $dayState = $states['days'][$date];

    $stmt = db()->prepare('SELECT * FROM bookings WHERE slot_date = ? ORDER BY slot_time, id');
    $stmt->execute([$date]);
    $bookings = array_map('booking_to_array', $stmt->fetchAll());
    $byId = [];
    foreach ($bookings as $b) {
        $byId[$b['id']] = $b;
    }
    $slots = array_map(function (array $slot) use ($byId): array {
        if (isset($slot['bookingId'], $byId[$slot['bookingId']])) {
            $slot['booking'] = $byId[$slot['bookingId']];
        }
        return $slot;
    }, $dayState['slots']);

    $stmt = db()->prepare("SELECT * FROM slot_blocks WHERE slot_date = ? ORDER BY slot_time");
    $stmt->execute([$date]);
    $blocks = array_map('block_to_array', $stmt->fetchAll());

    // How many bookings each coming day has, for the overview.
    $today = studio_today();
    $stmt = db()->prepare("SELECT slot_date, COUNT(*) AS n FROM bookings WHERE status = 'booked' AND slot_date BETWEEN ? AND ? GROUP BY slot_date");
    $stmt->execute([$today->format('Y-m-d'), $today->modify('+' . booking_days_ahead() . ' days')->format('Y-m-d')]);
    $upcoming = [];
    foreach ($stmt->fetchAll() as $row) {
        $upcoming[] = ['date' => $row['slot_date'], 'booked' => (int) $row['n']];
    }

    return [
        'date' => $date,
        'label' => $day->format('l, j F Y'),
        'closed' => slot_times_for($day) === [],
        'dayBlock' => $dayState['dayBlock'],
        'slots' => $slots,
        'bookings' => $bookings,
        'blocks' => $blocks,
        'upcoming' => $upcoming,
        'calendarError' => $states['calendarError'],
        'eveningTime' => EVENING_TIME,
    ];
}

function cancel_booking(array $admin, array $input): array
{
    $stmt = db()->prepare('SELECT * FROM bookings WHERE id = ?');
    $stmt->execute([(int) ($input['id'] ?? 0)]);
    $row = $stmt->fetch();
    if (!$row) {
        json_error('Booking not found.', 404);
    }
    if ($row['status'] !== 'booked') {
        json_error('This booking is already cancelled.');
    }
    $pdo = db();
    $pdo->beginTransaction();
    $pdo->prepare("UPDATE bookings SET status = 'cancelled', cancelled_at = ?, cancelled_by = ? WHERE id = ?")
        ->execute([now_utc(), $admin['email'], $row['id']]);
    $pdo->prepare('DELETE FROM slot_locks WHERE booking_id = ?')->execute([$row['id']]);
    $pdo->commit();

    $removed = $row['calendar_href'] !== '' ? caldav_delete_event($row['calendar_href']) : true;
    $pdo->prepare('UPDATE bookings SET calendar_status = ? WHERE id = ?')
        ->execute([$removed ? 'removed' : 'remove_failed', $row['id']]);
    return ['calendarRemoved' => $removed];
}

function block_slot(array $admin, array $input): array
{
    $day = parse_studio_date((string) ($input['date'] ?? ''));
    if (!$day || $day < studio_today()) {
        json_error('Please choose today or a later date.');
    }
    $date = $day->format('Y-m-d');
    $time = (string) ($input['time'] ?? '');
    if ($time !== '' && !in_array($time, slot_times_for($day), true)) {
        json_error('The studio has no ' . $time . ' slot on that day.');
    }

    if ($time === '') {
        $stmt = db()->prepare('SELECT COUNT(*) FROM slot_locks WHERE slot_date = ?');
        $stmt->execute([$date]);
        $count = (int) $stmt->fetchColumn();
        if ($count > 0) {
            json_error('This day has ' . $count . ' booking' . ($count > 1 ? 's' : '') . '. Cancel ' . ($count > 1 ? 'them' : 'it') . ' first, then block the day.', 409);
        }
    } else {
        $stmt = db()->prepare('SELECT COUNT(*) FROM slot_locks WHERE slot_date = ? AND slot_time = ?');
        $stmt->execute([$date, $time]);
        if ((int) $stmt->fetchColumn() > 0) {
            json_error('This slot has a booking. Cancel the booking first, then block the slot.', 409);
        }
    }

    try {
        db()->prepare('INSERT INTO slot_blocks (slot_date, slot_time, reason, created_at, created_by) VALUES (?, ?, ?, ?, ?)')
            ->execute([$date, $time, clean_text($input['reason'] ?? '', 255), now_utc(), $admin['email']]);
    } catch (PDOException $e) {
        if ((int) ($e->errorInfo[1] ?? 0) === 1062) {
            json_error('This is already blocked.');
        }
        throw $e;
    }
    return list_bookings(['date' => $date]);
}

function unblock_slot(array $input): array
{
    $stmt = db()->prepare('SELECT slot_date FROM slot_blocks WHERE id = ?');
    $stmt->execute([(int) ($input['id'] ?? 0)]);
    $date = $stmt->fetchColumn();
    if (!$date) {
        json_error('Block not found.', 404);
    }
    db()->prepare('DELETE FROM slot_blocks WHERE id = ?')->execute([(int) $input['id']]);
    return list_bookings(['date' => $date]);
}
