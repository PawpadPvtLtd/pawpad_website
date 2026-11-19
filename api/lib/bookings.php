<?php
/**
 * Grooming bookings.
 *
 * Each booking reserves the time of the team members who do the work (the bather,
 * the groomer, Leena for massages): see schedule.php for the rules and times.
 * - Website: hourly start times (11 AM, or 10 AM on Saturday and Sunday, to 7 PM),
 *   closed on Thursdays, every service finishes by 8 PM, today (times at least an
 *   hour away) up to 30 days ahead. Payment at the studio.
 * - Admin panel: any start time; a clash shows a warning that can be overridden.
 * Calendar events, admin blocks and studio closures stop the whole studio.
 */

declare(strict_types=1);

if (!defined('PAWPAD_API')) {
    http_response_code(404);
    exit;
}

const STUDIO_WHATSAPP = '+91 91484 43330';
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

/**
 * How soon a same-day slot can still be booked online (config: booking_same_day_notice_minutes,
 * default 60). The admin panel can use any slot that hasn't started yet.
 */
function same_day_notice_minutes(): int
{
    return max(0, min(720, (int) (pawpad_config()['booking_same_day_notice_minutes'] ?? 60)));
}

/** True when the slot starts too soon (or has already started) to be booked. */
function slot_too_soon(string $date, string $time, int $noticeMinutes): bool
{
    $now = new DateTimeImmutable('now', studio_tz());
    return slot_start($date, $time) <= $now->modify('+' . $noticeMinutes . ' minutes');
}

/** Dates customers can book: today up to 30 days ahead. */
function bookable_dates(): array
{
    $today = studio_today();
    $dates = [];
    for ($i = 0; $i <= booking_days_ahead(); $i++) {
        $dates[] = $today->modify('+' . $i . ' days')->format('Y-m-d');
    }
    return $dates;
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

/**
 * Public: for the next 30 days, the hourly start times and the time the team is
 * already busy (no names), plus how long each service takes and who does it.
 * The website shows only the times a service fits; the server checks again on booking.
 */
function booking_availability(): array
{
    $dates = bookable_dates();
    $calendar = calendar_busy(parse_studio_date($dates[0]), parse_studio_date($dates[count($dates) - 1])->modify('+1 day'), false);
    if (!$calendar['ok']) {
        error_log('Pawpad API availability: ' . $calendar['error']);
        json_error('Online booking is not available right now. Please WhatsApp us on ' . STUDIO_WHATSAPP . ' to book.', 503);
    }
    $schedules = day_schedules($dates, false, $calendar);
    $now = new DateTimeImmutable('now', studio_tz());
    $today = studio_today()->format('Y-m-d');
    $earliestToday = (int) $now->format('G') * 60 + (int) $now->format('i') + same_day_notice_minutes() + 1;
    $days = [];
    foreach ($dates as $date) {
        $days[] = [
            'date' => $date,
            'starts' => website_start_times(parse_studio_date($date)),
            'minStart' => $date === $today ? $earliestToday : 0,
            'busy' => public_busy($schedules[$date]),
        ];
    }
    return ['days' => $days] + public_plans();
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
        'price' => isset($row['price']) ? (float) $row['price'] : null,
        'source' => $row['source'] ?? 'website',
        'matted' => !empty($row['matted']),
        'createdAt' => to_iso($row['created_at']),
        'cancelledAt' => to_iso($row['cancelled_at']),
        'cancelledBy' => $row['cancelled_by'],
        'adminLog' => json_decode((string) ($row['admin_log'] ?? ''), true) ?: [],
    ];
}

function send_slot_taken(array $slots, string $message): void
{
    send_json(['ok' => false, 'error' => $message, 'taken' => $slots], 409);
}

/**
 * Fits every pet of one order into the team's time, in the order given (or, if that
 * fails, the other way round: two pets can sometimes fit only one way).
 * @return array{segments?: array, failed?: array, studio?: bool}
 */
function fit_order(array $items, array $schedules): array
{
    $tryOrder = function (array $order) use ($items, $schedules): array {
        $extra = [];
        $segments = [];
        foreach ($order as $i) {
            $item = $items[$i];
            $fit = plan_fit($schedules[$item['date']], service_plan($item['serviceId']), hm_to_min($item['time']), $extra[$item['date']] ?? []);
            if ($fit['segments'] === null) {
                return ['failed' => $item, 'studio' => strpos($fit['clash'], 'busy') === false || strpos($fit['clash'], 'unavailable') !== false];
            }
            $segments[$i] = $fit['segments'];
            $extra[$item['date']] = add_to_extra($extra[$item['date']] ?? [], $fit['segments']);
        }
        ksort($segments);
        return ['segments' => $segments];
    };
    $order = array_keys($items);
    $result = $tryOrder($order);
    if (isset($result['failed']) && count($order) > 1) {
        $second = $tryOrder(array_reverse($order));
        if (!isset($second['failed'])) {
            return $second;
        }
    }
    return $result;
}

/**
 * Public: book a time for each pet. Either every pet in the order is booked, or none.
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
    foreach ($requested as $index => $item) {
        if (!is_array($item)) {
            json_error('Invalid booking.');
        }
        $serviceId = strtolower(preg_replace('/[^A-Za-z0-9-]/', '', (string) ($item['serviceId'] ?? '')));
        $serviceId = substr($serviceId, 0, 60) ?: 'grooming-unspecified';
        $serviceTitle = clean_text($item['serviceTitle'] ?? '', 255) ?: 'Grooming';
        $date = (string) ($item['date'] ?? '');
        $time = (string) ($item['time'] ?? '');
        $day = parse_studio_date($date);
        $label = 'Pet ' . ($index + 1);
        if (in_array($serviceId, ONLINE_OFF_SERVICES, true)) {
            json_error($label . ': ' . $serviceTitle . ' is booked after a quick chat. Please WhatsApp us on ' . STUDIO_WHATSAPP . '.');
        }
        if (!$day || !isset($allowedDates[$date])) {
            json_error($label . ': please choose a date from today up to ' . booking_days_ahead() . ' days ahead.');
        }
        if (!in_array($time, website_start_times($day), true)) {
            json_error($label . ': the studio has no ' . $time . ' start time on ' . $day->format('l') . 's.');
        }
        if (slot_too_soon($date, $time, same_day_notice_minutes())) {
            send_slot_taken([['date' => $date, 'time' => $time]], $label . ': ' . friendly_slot($date, $time)
                . ' is too soon to book online. Please choose a later time, or WhatsApp us on ' . STUDIO_WHATSAPP . '.');
        }
        $items[] = [
            'serviceId' => $serviceId,
            'serviceTitle' => $serviceTitle,
            // The price shown at checkout, only used to pre-fill the daily closing.
            'price' => parse_amount($item['price'] ?? ''),
            'date' => $date,
            'time' => $time,
            'pet' => clean_pet(is_array($item['pet'] ?? null) ? $item['pet'] : []),
        ];
    }

    // Read the calendar right now (not the cached copy) for events added by hand.
    $dates = array_values(array_unique(array_column($items, 'date')));
    sort($dates);
    $calendar = calendar_busy(parse_studio_date($dates[0]), parse_studio_date($dates[count($dates) - 1])->modify('+1 day'), true);
    if (!$calendar['ok']) {
        error_log('Pawpad API booking: ' . $calendar['error']);
        json_error('Online booking is not available right now. Please WhatsApp us on ' . STUDIO_WHATSAPP . ' to book.', 503);
    }

    $now = now_utc();
    $pdo = db();
    $saved = [];
    for ($attempt = 1; ; $attempt++) {
        $saved = [];
        $pdo->beginTransaction();
        try {
            // One booking per day at a time: read the team's time only after taking the day's lock.
            foreach ($dates as $date) {
                lock_schedule_day($pdo, $date);
            }
            $schedules = day_schedules($dates, true, $calendar);
            $fits = fit_order($items, $schedules);
            if (isset($fits['failed'])) {
                throw new SlotTakenException(['date' => $fits['failed']['date'], 'time' => $fits['failed']['time']], $fits['studio']);
            }
            // Sequential reference (PAW-0001, PAW-0002, ...), from the same counter table as
            // course applications. "#GROOMING" can never clash with a course code (letters/digits only).
            // The counter is part of this transaction, so a failed booking leaves no gap.
            $ref = 'PAW-' . str_pad((string) next_sequence($pdo, '#GROOMING'), 4, '0', STR_PAD_LEFT);
            $insert = $pdo->prepare(
                'INSERT INTO bookings (ref, slot_date, slot_time, status, service_id, service_title, pet, customer_name,
                    customer_email, customer_phone, customer_area, contact_method, notes, created_at, price, source)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );
            foreach ($items as $i => $item) {
                $insert->execute([
                    $ref, $item['date'], $item['time'], 'booked', $item['serviceId'], $item['serviceTitle'],
                    json_encode($item['pet'], JSON_UNESCAPED_UNICODE), $name, $email, $phone,
                    clean_text($customer['area'] ?? '', 255), clean_text($customer['contactMethod'] ?? '', 30),
                    clean_text($input['notes'] ?? '', 2000), $now, $item['price'], 'website',
                ]);
                $id = (int) $pdo->lastInsertId();
                save_segments($pdo, $id, $item['date'], $fits['segments'][$i]);
                $saved[] = $item + ['id' => $id, 'ref' => $ref, 'segments' => $fits['segments'][$i]];
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
        $href = add_booking_event($item, $name, $phone, $email, clean_text($input['notes'] ?? '', 2000));
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
        'Team: ' . segments_label($item['segments'] ?? []),
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

/** Bookings as arrays, each with its team time (segments) and end time. */
function bookings_with_segments(array $rows): array
{
    if (!$rows) {
        return [];
    }
    $ids = array_map('intval', array_column($rows, 'id'));
    $stmt = db()->prepare('SELECT * FROM booking_segments WHERE booking_id IN (' . implode(',', array_fill(0, count($ids), '?')) . ') ORDER BY start_min, id');
    $stmt->execute($ids);
    $byBooking = [];
    foreach ($stmt->fetchAll() as $seg) {
        $byBooking[(int) $seg['booking_id']][] = ['staff' => $seg['staff'], 'start' => (int) $seg['start_min'], 'end' => (int) $seg['end_min'], 'kind' => $seg['kind']];
    }
    return array_map(function (array $row) use ($byBooking): array {
        $b = booking_to_array($row);
        $b['segments'] = $byBooking[$b['id']] ?? [];
        $b['endTime'] = $b['segments'] ? min_to_hm(max(array_column($b['segments'], 'end'))) : '';
        $b['label'] = friendly_slot($row['slot_date'], $row['slot_time']);
        return $b;
    }, $rows);
}

function block_to_array(array $row): array
{
    [$s, $e] = block_interval($row);
    return [
        'id' => (int) $row['id'],
        'date' => $row['slot_date'],
        'time' => $row['slot_time'],
        'endTime' => $row['slot_time'] === '' ? '' : min_to_hm($e),
        'start' => $s,
        'end' => $e,
        'staff' => (string) ($row['staff'] ?? ''),
        'reason' => $row['reason'],
        'createdBy' => $row['created_by'],
    ];
}

/**
 * Admin: one day of the team's schedule: bookings (with who does what, when),
 * blocks, closures, calendar events. "exceptBooking" leaves one booking out
 * (to find a new time for it).
 */
function list_bookings(array $input): array
{
    $date = (string) ($input['date'] ?? '');
    $day = $date === '' ? studio_today() : parse_studio_date($date);
    if (!$day) {
        json_error('Invalid date.');
    }
    $date = $day->format('Y-m-d');
    $calendar = calendar_busy($day, $day->modify('+1 day'), false);
    $schedule = day_schedules([$date], false, $calendar, (int) ($input['exceptBooking'] ?? 0))[$date];

    $stmt = db()->prepare('SELECT * FROM bookings WHERE slot_date = ? ORDER BY slot_time, id');
    $stmt->execute([$date]);
    $bookings = bookings_with_segments($stmt->fetchAll());

    $stmt = db()->prepare('SELECT * FROM slot_blocks WHERE slot_date = ? ORDER BY slot_time');
    $stmt->execute([$date]);
    $blocks = array_map('block_to_array', $stmt->fetchAll());
    $dayBlock = null;
    foreach ($blocks as $bl) {
        if ($bl['time'] === '' && $bl['staff'] === '') {
            $dayBlock = $bl;
        }
    }
    $closures = array_map('closure_to_array', closure_rows_between($date, $date));

    // How many bookings each coming day has, for the overview.
    $today = studio_today();
    $stmt = db()->prepare("SELECT slot_date, COUNT(*) AS n FROM bookings WHERE status = 'booked' AND slot_date BETWEEN ? AND ? GROUP BY slot_date");
    $stmt->execute([$today->format('Y-m-d'), $today->modify('+' . booking_days_ahead() . ' days')->format('Y-m-d')]);
    $upcoming = [];
    foreach ($stmt->fetchAll() as $row) {
        $upcoming[] = ['date' => $row['slot_date'], 'booked' => (int) $row['n']];
    }

    $studio = array_map(function (array $iv): array {
        return ['start' => $iv[0], 'end' => $iv[1], 'why' => $iv[2]];
    }, $schedule['studio']);
    return [
        'date' => $date,
        'label' => $day->format('l, j F Y'),
        'closed' => website_start_times($day) === [],
        'openMin' => studio_open_min($day),
        'starts' => website_start_times($day),
        'dayBlock' => $dayBlock,
        'bookings' => $bookings,
        'blocks' => $blocks,
        'closures' => $closures,
        'studio' => $studio,
        'busy' => public_busy($schedule),
        'upcoming' => $upcoming,
        'calendarError' => $calendar['ok'] ? '' : $calendar['error'],
        'staff' => STAFF_LABELS,
        'mattedMinutes' => MATTED_MINUTES,
    ] + public_plans();
}

/**
 * Every booked grooming session from today onwards, soonest first.
 */
function list_upcoming_bookings(): array
{
    $stmt = db()->prepare("SELECT * FROM bookings WHERE status = 'booked' AND slot_date >= ? ORDER BY slot_date, slot_time, id LIMIT 1000");
    $stmt->execute([studio_today()->format('Y-m-d')]);
    return ['bookings' => bookings_with_segments($stmt->fetchAll())];
}

/**
 * Adds one booking to the info@ calendar, from its first to its last minute of team time.
 * Every booking's events share the UID prefix "pawpad-booking-<id>-", so they can always
 * be found and removed. $item needs id, ref, date, time, pet, serviceTitle (and segments).
 */
function add_booking_event(array $item, string $name, string $phone, string $email, string $notes): string
{
    $item['segments'] = $item['segments'] ?? booking_segments((int) $item['id']);
    $day = parse_studio_date($item['date']);
    $start = hm_to_min($item['time']);
    $end = $start + slot_minutes();
    if ($item['segments']) {
        $start = min(array_column($item['segments'], 1));
        $end = max(array_column($item['segments'], 2));
    }
    return caldav_create_event(
        'pawpad-booking-' . $item['id'] . '-' . bin2hex(random_bytes(4)),
        $day->modify('+' . $start . ' minutes'),
        $day->modify('+' . $end . ' minutes'),
        'Grooming: ' . ($item['pet']['name'] ?: 'Pet') . ($item['pet']['type'] ? ' (' . $item['pet']['type'] . ')' : '') . ' – ' . $item['serviceTitle'],
        booking_event_description($item, $name, $phone, $email, $notes),
        STUDIO_ADDRESS
    );
}

/** Replaces a booking's calendar event (after a change of time or length). */
function refresh_booking_event(array $row): bool
{
    caldav_remove_events('pawpad-booking-' . $row['id'] . '-', (string) $row['calendar_href'], parse_studio_date($row['slot_date']));
    $pet = json_decode($row['pet'], true) ?: [];
    $pet += ['name' => '', 'type' => '', 'breed' => '', 'age' => '', 'size' => '', 'coat' => '', 'temperament' => '', 'healthNotes' => ''];
    $href = add_booking_event(
        ['id' => (int) $row['id'], 'ref' => $row['ref'], 'date' => $row['slot_date'], 'time' => $row['slot_time'], 'pet' => $pet, 'serviceTitle' => $row['service_title']],
        $row['customer_name'], $row['customer_phone'], $row['customer_email'] !== '' ? $row['customer_email'] : '(walk-in / phone)', (string) $row['notes']
    );
    db()->prepare('UPDATE bookings SET calendar_href = ?, calendar_status = ? WHERE id = ?')
        ->execute([$href, $href !== '' ? 'synced' : 'failed', $row['id']]);
    forget_calendar_cache();
    return $href !== '';
}

/** The calendar changed: make the next availability check read it fresh. */
function forget_calendar_cache(): void
{
    db()->exec('DELETE FROM calendar_cache');
}

function find_booking(int $id): array
{
    $stmt = db()->prepare('SELECT * FROM bookings WHERE id = ?');
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    if (!$row) {
        json_error('Booking not found.', 404);
    }
    return $row;
}

/** Adds a line to the booking's admin history (cancelled, rescheduled, ...). */
function booking_log_entry(array $row, array $admin, string $text): string
{
    $log = json_decode((string) ($row['admin_log'] ?? ''), true);
    $log = is_array($log) ? $log : [];
    $log[] = ['date' => to_iso(now_utc()), 'by' => $admin['email'], 'text' => $text];
    return json_encode($log, JSON_UNESCAPED_UNICODE);
}

/** Customer email for a cancelled or rescheduled appointment (a copy goes to info@). */
function booking_change_email(array $row, string $subject, string $intro, string $reason): array
{
    $pet = json_decode($row['pet'], true) ?: [];
    $body = 'Dear ' . $row['customer_name'] . ",\n\n" . $intro . "\n\n"
        . ($reason !== '' ? 'Reason: ' . $reason . "\n\n" : '')
        . 'Booking reference: ' . $row['ref'] . "\n"
        . 'Pet: ' . (($pet['name'] ?? '') !== '' ? $pet['name'] : 'Your pet') . ' — ' . $row['service_title'] . "\n\n"
        . 'Studio: ' . STUDIO_ADDRESS . "\n"
        . 'Questions? Reply to this email or WhatsApp us on ' . STUDIO_WHATSAPP . ".\n\n"
        . "Warm regards,\nPawpad Grooming Studio\nhttps://pawpad.in\n";
    return send_mail('info', $row['customer_email'], $row['customer_name'], $subject, $body);
}

function cancel_booking(array $admin, array $input): array
{
    $row = find_booking((int) ($input['id'] ?? 0));
    if ($row['status'] !== 'booked') {
        json_error('This booking is already cancelled.');
    }
    $reason = clean_text($input['reason'] ?? '', 500);
    $when = friendly_slot($row['slot_date'], $row['slot_time']);
    $pdo = db();
    $pdo->beginTransaction();
    $pdo->prepare("UPDATE bookings SET status = 'cancelled', cancelled_at = ?, cancelled_by = ?, admin_log = ? WHERE id = ?")
        ->execute([now_utc(), $admin['email'], booking_log_entry($row, $admin, 'Cancelled (' . $when . ')' . ($reason !== '' ? ': ' . $reason : '')), $row['id']]);
    $pdo->commit();

    $removed = caldav_remove_events('pawpad-booking-' . $row['id'] . '-', $row['calendar_href'], parse_studio_date($row['slot_date']));
    $pdo->prepare('UPDATE bookings SET calendar_status = ? WHERE id = ?')
        ->execute([$removed ? 'removed' : 'remove_failed', $row['id']]);
    forget_calendar_cache();

    $mail = $row['customer_email'] !== ''
        ? booking_change_email($row, 'Your Pawpad grooming appointment has been cancelled (' . $row['ref'] . ')',
            'Your grooming appointment on ' . $when . ' has been cancelled.', $reason)
        : ['sent' => false, 'error' => 'no email address (walk-in / phone booking)'];
    return ['calendarRemoved' => $removed, 'emailSent' => $mail['sent'], 'emailError' => $mail['error']];
}

/**
 * A booking's team time starting at $start: the service, then (if the dog is matted)
 * 30 more minutes for the groomer. A clash of the Matted extra is only a warning.
 * @return array{segments: ?array, clash: string, warning: string}
 */
function fit_booking(array $day, string $serviceId, bool $matted, int $start, bool $force): array
{
    $fit = plan_fit($day, service_plan($serviceId), $start, [], $force);
    if ($fit['segments'] === null) {
        return ['segments' => null, 'clash' => $fit['clash'], 'warning' => ''];
    }
    $segments = $fit['segments'];
    $warning = '';
    if ($matted) {
        $end = max(array_column($segments, 2));
        $extra = plan_fit($day, [['groomer', MATTED_MINUTES]], $end, add_to_extra([], $segments), true);
        $warning = $extra['clash'] !== '' ? 'Matted Dogs (+' . MATTED_MINUTES . ' min): ' . $extra['clash'] . '.' : '';
        foreach ($extra['segments'] as $seg) {
            $segments[] = [$seg[0], $seg[1], $seg[2], 'matted'];
        }
    }
    return ['segments' => $segments, 'clash' => $fit['clash'], 'warning' => $warning];
}

/** Stops with the clash, so the admin panel can offer "Book anyway". */
function send_clash(string $clash): void
{
    send_json(['ok' => false, 'error' => 'This time clashes: ' . $clash . '.', 'clash' => $clash], 409);
}

/**
 * Admin: moves a booking to another day or time (any start time), updates the
 * calendar event and emails the customer. A clash needs "force" (Move anyway).
 */
function reschedule_booking(array $admin, array $input): array
{
    $row = find_booking((int) ($input['id'] ?? 0));
    if ($row['status'] !== 'booked') {
        json_error('Only active bookings can be rescheduled.');
    }
    $reason = clean_text($input['reason'] ?? '', 500);
    if ($reason === '') {
        json_error('Please write the reason for rescheduling.');
    }
    $date = (string) ($input['date'] ?? '');
    $time = (string) ($input['time'] ?? '');
    $day = parse_studio_date($date);
    if (!$day || !in_array($date, bookable_dates(), true)) {
        json_error('Please choose a date from today up to ' . booking_days_ahead() . ' days ahead.');
    }
    if (!valid_hm($time) || hm_to_min($time) < ADMIN_EARLIEST_MIN) {
        json_error('Please choose a start time from 8:00 AM.');
    }
    if (slot_too_soon($date, $time, 0)) {
        json_error(friendly_slot($date, $time) . ' has already started. Please choose a later time.', 409);
    }
    if ($date === $row['slot_date'] && $time === $row['slot_time']) {
        json_error('That is the current time of this booking. Please choose a different one.');
    }
    $force = !empty($input['force']);
    $calendar = calendar_busy($day, $day->modify('+1 day'), true);
    if (!$calendar['ok'] && !$force) {
        json_error('The info@ calendar could not be read, so the new time can\'t be checked. Please try again.', 503);
    }

    $oldWhen = friendly_slot($row['slot_date'], $row['slot_time']);
    $newWhen = friendly_slot($date, $time);
    $pdo = db();
    $pdo->beginTransaction();
    try {
        lock_schedule_day($pdo, $date);
        $schedule = day_schedules([$date], true, $calendar['ok'] ? $calendar : ['ok' => true, 'busy' => []], (int) $row['id'])[$date];
        $fit = fit_booking($schedule, $row['service_id'], !empty($row['matted']), hm_to_min($time), $force);
        if ($fit['segments'] === null) {
            $pdo->rollBack();
            send_clash($fit['clash']);
        }
        $pdo->prepare('DELETE FROM booking_segments WHERE booking_id = ?')->execute([$row['id']]);
        save_segments($pdo, (int) $row['id'], $date, $fit['segments']);
        $note = 'Rescheduled from ' . $oldWhen . ' to ' . $newWhen . ': ' . $reason . ($fit['clash'] !== '' ? ' (moved despite a clash: ' . $fit['clash'] . ')' : '');
        $pdo->prepare('UPDATE bookings SET slot_date = ?, slot_time = ?, admin_log = ? WHERE id = ?')
            ->execute([$date, $time, booking_log_entry($row, $admin, $note), $row['id']]);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }

    // Move the calendar event: remove the old one(s), then add the new time.
    caldav_remove_events('pawpad-booking-' . $row['id'] . '-', (string) $row['calendar_href'], parse_studio_date($row['slot_date']));
    $updated = find_booking((int) $row['id']);
    $synced = refresh_booking_event($updated);

    $mail = $row['customer_email'] !== ''
        ? booking_change_email($row, 'Your Pawpad grooming appointment has been rescheduled (' . $row['ref'] . ')',
            "Your grooming appointment has been moved.\n\nNew time: " . $newWhen . "\n(was: " . $oldWhen . ')', $reason)
        : ['sent' => false, 'error' => 'no email address (walk-in / phone booking)'];
    return [
        'booking' => bookings_with_segments([$updated])[0],
        'calendarSynced' => $synced,
        'emailSent' => $mail['sent'],
        'emailError' => $mail['error'],
        'warning' => trim($fit['clash'] . ' ' . $fit['warning']),
    ];
}

/**
 * Admin: the dog is matted: 30 more minutes for the groomer, straight after the
 * service (and the Matted Dogs price added to the booking). A clash is only a warning.
 */
function set_matted(array $admin, array $input): array
{
    $row = find_booking((int) ($input['id'] ?? 0));
    if ($row['status'] !== 'booked') {
        json_error('Only active bookings can be changed.');
    }
    $on = !empty($input['on']);
    if ((bool) $row['matted'] === $on) {
        return ['booking' => bookings_with_segments([$row])[0], 'warning' => ''];
    }
    $extraPrice = parse_amount($input['price'] ?? '');
    $extraPrice = $extraPrice !== null && $extraPrice >= 0 ? $extraPrice : 850.0;
    $date = $row['slot_date'];
    $warning = '';
    $pdo = db();
    $pdo->beginTransaction();
    try {
        lock_schedule_day($pdo, $date);
        $pdo->prepare("DELETE FROM booking_segments WHERE booking_id = ? AND kind = 'matted'")->execute([$row['id']]);
        if ($on) {
            $own = array_map(function (array $s): array {
                return [$s[0], $s[1], $s[2]];
            }, booking_segments((int) $row['id']));
            $end = $own ? max(array_column($own, 2)) : hm_to_min($row['slot_time']) + slot_minutes();
            $schedule = day_schedules([$date], true, null, (int) $row['id'])[$date];
            $fit = plan_fit($schedule, [['groomer', MATTED_MINUTES]], $end, add_to_extra([], $own), true);
            $warning = $fit['clash'];
            save_segments($pdo, (int) $row['id'], $date, $fit['segments'], 'matted');
        }
        $price = $row['price'] !== null ? max(0, (float) $row['price'] + ($on ? $extraPrice : -$extraPrice)) : null;
        $text = $on ? 'Matted Dogs added (+' . MATTED_MINUTES . ' min groomer' . ($warning !== '' ? '; clash: ' . $warning : '') . ')' : 'Matted Dogs removed';
        $pdo->prepare('UPDATE bookings SET matted = ?, price = ?, admin_log = ? WHERE id = ?')
            ->execute([$on ? 1 : 0, $price, booking_log_entry($row, $admin, $text), $row['id']]);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
    $updated = find_booking((int) $row['id']);
    $synced = refresh_booking_event($updated);
    return ['booking' => bookings_with_segments([$updated])[0], 'warning' => $warning, 'calendarSynced' => $synced];
}

/**
 * Removes calendar events still left by bookings that were cancelled earlier.
 */
function cleanup_calendar(): array
{
    $stmt = db()->prepare("SELECT * FROM bookings WHERE status = 'cancelled' AND slot_date >= ?");
    $stmt->execute([studio_today()->modify('-1 day')->format('Y-m-d')]);
    $cleaned = 0;
    $failed = 0;
    foreach ($stmt->fetchAll() as $row) {
        if (caldav_remove_events('pawpad-booking-' . $row['id'] . '-', $row['calendar_href'], parse_studio_date($row['slot_date']))) {
            $cleaned++;
            db()->prepare("UPDATE bookings SET calendar_status = 'removed' WHERE id = ?")->execute([$row['id']]);
        } else {
            $failed++;
        }
    }
    forget_calendar_cache();
    return ['checked' => $cleaned + $failed, 'failed' => $failed];
}

/**
 * Admin: blocks the whole studio (or only Leena: no massages) for a whole day,
 * one hour, or from a time to a time. Shown in the info@ calendar too.
 */
function block_slot(array $admin, array $input): array
{
    $day = parse_studio_date((string) ($input['date'] ?? ''));
    if (!$day || $day < studio_today()) {
        json_error('Please choose today or a later date.');
    }
    $date = $day->format('Y-m-d');
    $staff = (string) ($input['staff'] ?? '');
    if (!in_array($staff, ['', 'leena'], true)) {
        json_error('Unknown team member.');
    }
    $time = (string) ($input['time'] ?? '');
    $endTime = (string) ($input['endTime'] ?? '');
    if ($time !== '') {
        if (!valid_hm($time) || ($endTime !== '' && !valid_hm($endTime))) {
            json_error('Please choose the times.');
        }
        if ($endTime === '') {
            $endTime = min_to_hm(min(1439, hm_to_min($time) + 60));
        }
        if (hm_to_min($endTime) <= hm_to_min($time)) {
            json_error('The end time must be after the start time.');
        }
    } else {
        $endTime = '';
    }
    [$start, $end] = $time === '' ? [0, 1440] : [hm_to_min($time), hm_to_min($endTime)];
    $reason = clean_text($input['reason'] ?? '', 255);
    $who = $staff === 'leena' ? 'Leena' : 'the studio';

    $pdo = db();
    $pdo->beginTransaction();
    try {
        lock_schedule_day($pdo, $date);
        $sql = "SELECT b.ref, b.slot_time, s.start_min FROM booking_segments s JOIN bookings b ON b.id = s.booking_id
                WHERE b.status = 'booked' AND s.slot_date = ? AND s.start_min < ? AND s.end_min > ?" . ($staff === 'leena' ? " AND s.staff = 'leena'" : '');
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$date, $end, $start]);
        $clashes = $stmt->fetchAll();
        if ($clashes) {
            $pdo->rollBack();
            $refs = array_values(array_unique(array_column($clashes, 'ref')));
            json_error('There ' . (count($refs) > 1 ? 'are bookings' : 'is a booking') . ' for ' . $who . ' at that time (' . implode(', ', $refs)
                . '). Cancel or move ' . (count($refs) > 1 ? 'them' : 'it') . ' first, then block the time.', 409);
        }
        $pdo->prepare('INSERT INTO slot_blocks (slot_date, slot_time, end_time, staff, reason, created_at, created_by) VALUES (?, ?, ?, ?, ?, ?, ?)')
            ->execute([$date, $time, $endTime, $staff, $reason, now_utc(), $admin['email']]);
        $blockId = (int) $pdo->lastInsertId();
        $pdo->commit();
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        if ((int) ($e->errorInfo[1] ?? 0) === 1062) {
            json_error('This is already blocked.');
        }
        throw $e;
    }

    // Show the block in the info@ calendar too, so it's visible on the phone.
    $summary = ($staff === 'leena' ? 'Leena unavailable (no massages)' : 'Blocked') . ($reason !== '' ? ': ' . $reason : '') . ' (Pawpad admin)';
    $description = 'Blocked by ' . $admin['email'] . ' in the Pawpad admin panel. Unblock it there.';
    $uid = 'pawpad-block-' . $blockId . '-' . bin2hex(random_bytes(4));
    $href = $time === ''
        ? caldav_create_event($uid, $day, $day->modify('+1 day'), $summary, $description, STUDIO_ADDRESS, true)
        : caldav_create_event($uid, $day->modify('+' . $start . ' minutes'), $day->modify('+' . $end . ' minutes'), $summary, $description, STUDIO_ADDRESS);
    db()->prepare('UPDATE slot_blocks SET calendar_href = ? WHERE id = ?')->execute([$href, $blockId]);
    forget_calendar_cache();
    return list_bookings(['date' => $date]) + ['calendarSynced' => $href !== ''];
}

function unblock_slot(array $input): array
{
    $stmt = db()->prepare('SELECT * FROM slot_blocks WHERE id = ?');
    $stmt->execute([(int) ($input['id'] ?? 0)]);
    $block = $stmt->fetch();
    if (!$block) {
        json_error('Block not found.', 404);
    }
    db()->prepare('DELETE FROM slot_blocks WHERE id = ?')->execute([(int) $block['id']]);
    $removed = caldav_remove_events('pawpad-block-' . $block['id'] . '-', (string) ($block['calendar_href'] ?? ''), parse_studio_date($block['slot_date']));
    forget_calendar_cache();
    return list_bookings(['date' => $block['slot_date']]) + ['calendarRemoved' => $removed];
}
