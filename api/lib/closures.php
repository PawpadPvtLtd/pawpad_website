<?php
/**
 * Studio closures (Owner / Administrator only): close the studio for a range
 * of dates, either all day or only at some start times. The slots disappear
 * from the booking page and the closure is shown in the info@ calendar.
 * Bookings already inside a closure are kept (they are listed before saving).
 */

declare(strict_types=1);

if (!defined('PAWPAD_API')) {
    http_response_code(404);
    exit;
}

// Every start time the studio has (10:00 only exists on Saturday and Sunday).
const CLOSURE_TIMES = ['10:00', '11:00', '12:00', '13:00', '16:00', '17:00', '18:00', '19:00'];
const CLOSURE_MAX_DAYS = 90;

/** Closures that overlap the dates $first..$last (YYYY-MM-DD). */
function closure_rows_between(string $first, string $last): array
{
    $stmt = db()->prepare('SELECT * FROM closures WHERE start_date <= ? AND end_date >= ? ORDER BY start_date, id');
    $stmt->execute([$last, $first]);
    return $stmt->fetchAll();
}

function closure_times(array $row): array
{
    $times = json_decode((string) $row['times'], true);
    return is_array($times) ? $times : [];
}

function closure_covers(array $row, string $date, string $time): bool
{
    return $row['start_date'] <= $date && $row['end_date'] >= $date
        && ((int) $row['all_day'] === 1 || in_array($time, closure_times($row), true));
}

function closure_for_slot(array $rows, string $date, string $time): ?array
{
    foreach ($rows as $row) {
        if (closure_covers($row, $date, $time)) {
            return $row;
        }
    }
    return null;
}

/** Checked inside the booking transaction, so a new closure is always respected. */
function slot_is_closed(PDO $pdo, string $date, string $time): bool
{
    $stmt = $pdo->prepare('SELECT * FROM closures WHERE start_date <= ? AND end_date >= ?');
    $stmt->execute([$date, $date]);
    return closure_for_slot($stmt->fetchAll(), $date, $time) !== null;
}

function closure_label(string $start, string $end, bool $allDay, array $times): string
{
    $s = parse_studio_date($start);
    $e = parse_studio_date($end);
    $range = $start === $end ? $s->format('D j M Y') : $s->format('D j M') . ' – ' . $e->format('D j M Y');
    return $range . ' · ' . ($allDay ? 'all day' : implode(', ', $times));
}

function closure_to_array(array $row): array
{
    $times = closure_times($row);
    return [
        'id' => (int) $row['id'],
        'startDate' => $row['start_date'],
        'endDate' => $row['end_date'],
        'allDay' => (int) $row['all_day'] === 1,
        'times' => $times,
        'reason' => $row['reason'],
        'label' => closure_label($row['start_date'], $row['end_date'], (int) $row['all_day'] === 1, $times),
        'createdBy' => $row['created_by'],
        'createdAt' => to_iso($row['created_at']),
    ];
}

/**
 * Checks the form: dates, all day or chosen times, reason.
 * @return array{start: string, end: string, allDay: bool, times: string[], reason: string}
 */
function closure_input(array $input): array
{
    $start = parse_studio_date((string) ($input['startDate'] ?? ''));
    $end = parse_studio_date((string) ($input['endDate'] ?? ''));
    if (!$start || !$end) {
        json_error('Please choose a start date and an end date.');
    }
    if ($start < studio_today()) {
        json_error('The closure must start today or later.');
    }
    if ($end < $start) {
        json_error('The end date must be the same as, or after, the start date.');
    }
    if ((int) $start->diff($end)->days + 1 > CLOSURE_MAX_DAYS) {
        json_error('A closure can be at most ' . CLOSURE_MAX_DAYS . ' days. Add a second closure for a longer time.');
    }
    $allDay = !empty($input['allDay']);
    $times = [];
    if (!$allDay) {
        $chosen = is_array($input['times'] ?? null) ? $input['times'] : [];
        $times = array_values(array_filter(CLOSURE_TIMES, function (string $t) use ($chosen): bool {
            return in_array($t, $chosen, true);
        }));
        if (!$times) {
            json_error('Please tick "All day" or at least one time slot.');
        }
        if (count($times) === count(CLOSURE_TIMES)) {
            $allDay = true;
            $times = [];
        }
    }
    return [
        'start' => $start->format('Y-m-d'),
        'end' => $end->format('Y-m-d'),
        'allDay' => $allDay,
        'times' => $times,
        'reason' => clean_text(str_replace(["\r", "\n"], ' ', (string) ($input['reason'] ?? '')), 255),
    ];
}

/** Active bookings inside the closure. They are kept, but the admin sees them first. */
function closure_affected_bookings(array $c): array
{
    $stmt = db()->prepare("SELECT * FROM bookings WHERE status = 'booked' AND slot_date BETWEEN ? AND ? ORDER BY slot_date, slot_time, id");
    $stmt->execute([$c['start'], $c['end']]);
    $affected = [];
    foreach ($stmt->fetchAll() as $row) {
        if ($c['allDay'] || in_array($row['slot_time'], $c['times'], true)) {
            $b = booking_to_array($row);
            $b['label'] = friendly_slot($row['slot_date'], $row['slot_time']);
            $affected[] = $b;
        }
    }
    return $affected;
}

function closure_preview(array $input): array
{
    $c = closure_input($input);
    return [
        'label' => closure_label($c['start'], $c['end'], $c['allDay'], $c['times']),
        'affected' => closure_affected_bookings($c),
    ];
}

/**
 * Calendar events for a closure: one all-day event across the dates, or for
 * chosen times one repeating event per run of back-to-back slots
 * (e.g. 11:00–14:00 every day), skipping Thursdays when the studio is shut.
 * @return string[] the event addresses
 */
function closure_calendar_events(int $id, array $c, string $by): array
{
    $summary = 'Studio closed' . ($c['reason'] !== '' ? ': ' . $c['reason'] : '') . ' (Pawpad admin)';
    $description = 'Studio closure ' . closure_label($c['start'], $c['end'], $c['allDay'], $c['times'])
        . ', set by ' . $by . ' in the Pawpad admin panel. Remove it there (Studio Closures) to re-open the slots.';
    $first = parse_studio_date($c['start']);
    $last = parse_studio_date($c['end']);

    if ($c['allDay']) {
        $href = caldav_create_event('pawpad-closure-' . $id . '-' . bin2hex(random_bytes(4)), $first, $last->modify('+1 day'), $summary, $description, STUDIO_ADDRESS, true);
        return $href !== '' ? [$href] : [];
    }

    // Group back-to-back times; 10:00 (weekends only) always gets its own event.
    $groups = [];
    $previous = null;
    foreach ($c['times'] as $time) {
        $hour = (int) substr($time, 0, 2);
        if ($time !== WEEKEND_EXTRA_TIME && $previous !== null && $previous !== WEEKEND_EXTRA_TIME && $hour === (int) substr($previous, 0, 2) + 1) {
            $groups[count($groups) - 1][] = $time;
        } else {
            $groups[] = [$time];
        }
        $previous = $time;
    }

    $until = slot_start($c['end'], '23:59')->setTimezone(new DateTimeZone('UTC'))->format('Ymd\THis\Z');
    $hrefs = [];
    foreach ($groups as $group) {
        $weekendOnly = $group === [WEEKEND_EXTRA_TIME];
        $days = $weekendOnly ? [6, 7] : [1, 2, 3, 5, 6, 7];
        // The first matching day in the range (the event's first occurrence).
        $day = $first;
        while ($day <= $last && !in_array((int) $day->format('N'), $days, true)) {
            $day = $day->modify('+1 day');
        }
        if ($day > $last) {
            continue;
        }
        $date = $day->format('Y-m-d');
        $rrule = 'FREQ=WEEKLY;BYDAY=' . ($weekendOnly ? 'SA,SU' : 'MO,TU,WE,FR,SA,SU') . ';UNTIL=' . $until;
        $href = caldav_create_event(
            'pawpad-closure-' . $id . '-' . bin2hex(random_bytes(4)),
            slot_start($date, $group[0]),
            slot_start($date, $group[count($group) - 1])->modify('+' . slot_minutes() . ' minutes'),
            $summary, $description, STUDIO_ADDRESS, false,
            $c['start'] === $c['end'] ? '' : $rrule
        );
        if ($href !== '') {
            $hrefs[] = $href;
        }
    }
    return $hrefs;
}

function create_closure(array $admin, array $input): array
{
    $c = closure_input($input);
    $affected = closure_affected_bookings($c);
    db()->prepare('INSERT INTO closures (start_date, end_date, all_day, times, reason, calendar_hrefs, created_by, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)')
        ->execute([$c['start'], $c['end'], $c['allDay'] ? 1 : 0, json_encode($c['times']), $c['reason'], '[]', $admin['email'], now_utc()]);
    $id = (int) db()->lastInsertId();

    $hrefs = closure_calendar_events($id, $c, $admin['email']);
    db()->prepare('UPDATE closures SET calendar_hrefs = ? WHERE id = ?')->execute([json_encode($hrefs), $id]);
    forget_calendar_cache();
    return list_closures() + ['calendarSynced' => $hrefs !== [], 'affected' => $affected];
}

/** Closures that haven't ended yet, soonest first. */
function list_closures(): array
{
    $stmt = db()->prepare('SELECT * FROM closures WHERE end_date >= ? ORDER BY start_date, id');
    $stmt->execute([studio_today()->format('Y-m-d')]);
    return ['closures' => array_map('closure_to_array', $stmt->fetchAll()), 'times' => CLOSURE_TIMES];
}

/** Removes a closure: its slots open again and its calendar events are deleted. */
function delete_closure(array $input): array
{
    $stmt = db()->prepare('SELECT * FROM closures WHERE id = ?');
    $stmt->execute([(int) ($input['id'] ?? 0)]);
    $row = $stmt->fetch();
    if (!$row) {
        json_error('Closure not found.', 404);
    }
    db()->prepare('DELETE FROM closures WHERE id = ?')->execute([(int) $row['id']]);
    foreach (json_decode((string) $row['calendar_hrefs'], true) ?: [] as $href) {
        caldav_delete_event((string) $href);
    }
    $removed = caldav_remove_events('pawpad-closure-' . $row['id'] . '-', '', parse_studio_date($row['start_date']), parse_studio_date($row['end_date']));
    forget_calendar_cache();
    return list_closures() + ['calendarRemoved' => $removed];
}
