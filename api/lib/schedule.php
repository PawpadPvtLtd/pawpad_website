<?php
/**
 * Team scheduling: each grooming booking reserves the time of the people who do
 * the work, not a "grooming place" (rules confirmed by Leena, 10 Nov 2026).
 *
 * - Two-stage groomings: the bather for the first hour, then the groomer
 *   (Dog Long Hair, Long Hair with haircut: 1 h + 1 h; Breed-Specific Haircut: 1 h + 1½ h).
 * - 1-hour services (puppies, Dog Short Hair, Bath & Brush): whoever is free.
 * - Cats, haircuts, Hygiene Clip, Nail Clipping, Hand Stripping, Matted Dogs: the groomer.
 * - Massage: Leena, 30 minutes. An admin can mark Leena unavailable.
 * - Website: hourly start times (11 AM, or 10 AM on Saturday and Sunday, to 7 PM),
 *   and every service must finish by 8 PM. The admin panel can use any start time.
 * - Calendar events, admin blocks and studio closures stop everyone (the whole studio).
 *
 * Times inside a day are minutes from midnight (660 = 11:00).
 */

declare(strict_types=1);

if (!defined('PAWPAD_API')) {
    http_response_code(404);
    exit;
}

const STAFF_MEMBERS = ['bather', 'groomer', 'leena'];
const STAFF_LABELS = ['bather' => 'Bather', 'groomer' => 'Groomer', 'leena' => 'Leena'];
const STUDIO_CLOSE_MIN = 20 * 60;        // every service finishes by 8 PM
const WEBSITE_LAST_START_MIN = 19 * 60;  // last hourly start on the website
const ADMIN_EARLIEST_MIN = 8 * 60;       // the admin panel can book from 8 AM
const MATTED_MINUTES = 30;
const MATTED_SERVICE = 'matted-dogs';

/**
 * Who does each service, and for how long, in order. "either" = the bather or
 * the groomer, whoever is free (the bather first, so the groomer stays free for
 * cats and haircuts).
 */
const SERVICE_PLANS = [
    'puppy-short' => [['either', 60]],
    'puppy-long' => [['either', 60]],
    'dog-short' => [['either', 60]],
    'dog-long' => [['bather', 60], ['groomer', 60]],
    'dog-grooming-long-hair' => [['bather', 60], ['groomer', 60]],
    'breed-specific-haircut' => [['bather', 60], ['groomer', 90]],
    'hand-stripping' => [['groomer', 150]],
    'cat-short' => [['groomer', 60]],
    'cat-long' => [['groomer', 90]],
    'cat-haircut' => [['groomer', 90]],
    'bath-brush-dogs' => [['either', 60]],
    'bath-brush-cats' => [['either', 60]],
    'bath-brush-subscription' => [['either', 60]],
    'massage' => [['leena', 30]],
    'hygiene-clip' => [['groomer', 20]],
    'nail-clipping' => [['groomer', 20]],
    'matted-dogs' => [['groomer', MATTED_MINUTES]],
    // Booked as "Grooming" without choosing the service yet: keep the longest common plan free.
    'grooming-unspecified' => [['bather', 60], ['groomer', 60]],
];
// A grooming service added later in the CMS: one hour with whoever is free.
const DEFAULT_SERVICE_PLAN = [['either', 60]];
// Not bookable online: Hand Stripping (enquire on WhatsApp first), Matted Dogs (added by the team).
const ONLINE_OFF_SERVICES = ['hand-stripping', 'matted-dogs'];

function service_plan(string $serviceId): array
{
    return SERVICE_PLANS[$serviceId] ?? DEFAULT_SERVICE_PLAN;
}

function plan_minutes(array $plan): int
{
    return (int) array_sum(array_column($plan, 1));
}

function hm_to_min(string $time): int
{
    [$h, $m] = array_map('intval', explode(':', $time) + [0, 0]);
    return $h * 60 + $m;
}

function min_to_hm(int $minutes): string
{
    return sprintf('%02d:%02d', intdiv($minutes, 60), $minutes % 60);
}

function valid_hm(string $time): bool
{
    return (bool) preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $time);
}

/** "2:30 PM". */
function friendly_time(int $minutes): string
{
    $h = intdiv($minutes, 60);
    return ($h % 12 === 0 ? 12 : $h % 12) . ':' . sprintf('%02d', $minutes % 60) . ($h < 12 ? ' AM' : ' PM');
}

function studio_open_min(DateTimeImmutable $day): int
{
    return (int) $day->format('N') >= 6 ? 10 * 60 : 11 * 60;
}

/** Hourly start times customers can choose on the website (none on Thursdays). */
function website_start_times(DateTimeImmutable $day): array
{
    if ((int) $day->format('N') === 4) {
        return [];
    }
    $times = [];
    for ($m = studio_open_min($day); $m <= WEBSITE_LAST_START_MIN; $m += 60) {
        $times[] = min_to_hm($m);
    }
    return $times;
}

function overlaps(array $intervals, int $start, int $end): ?array
{
    foreach ($intervals as $iv) {
        if ($iv[0] < $end && $iv[1] > $start) {
            return $iv;
        }
    }
    return null;
}

/** The studio's day lock: bookings and blocks for one day are saved one at a time. */
function lock_schedule_day(PDO $pdo, string $date): void
{
    $pdo->prepare('INSERT IGNORE INTO schedule_locks (slot_date) VALUES (?)')->execute([$date]);
    $pdo->prepare('SELECT slot_date FROM schedule_locks WHERE slot_date = ? FOR UPDATE')->execute([$date]);
}

/**
 * Everything that takes time on the given days.
 * Per date: busy[bather|groomer|leena] = [[start, end, bookingId, kind]], studio = [[start, end, why]],
 * leenaOff = [[start, end, blockId]], calendarOk.
 *
 * $lock: read the newest rows (inside the booking transaction, after lock_schedule_day).
 * $calendar: calendar_busy() result covering the days (null = read it here, cached).
 */
function day_schedules(array $dates, bool $lock = false, ?array $calendar = null, int $exceptBooking = 0): array
{
    $out = [];
    if (!$dates) {
        return $out;
    }
    sort($dates);
    $first = $dates[0];
    $last = $dates[count($dates) - 1];
    foreach ($dates as $date) {
        $out[$date] = ['busy' => ['bather' => [], 'groomer' => [], 'leena' => []], 'studio' => [], 'leenaOff' => [], 'calendarOk' => true];
    }
    $forUpdate = $lock ? ' FOR UPDATE' : '';

    $stmt = db()->prepare("SELECT s.* FROM booking_segments s JOIN bookings b ON b.id = s.booking_id
        WHERE b.status = 'booked' AND s.slot_date BETWEEN ? AND ? AND s.booking_id <> ? ORDER BY s.start_min" . $forUpdate);
    $stmt->execute([$first, $last, $exceptBooking]);
    foreach ($stmt->fetchAll() as $row) {
        if (isset($out[$row['slot_date']]['busy'][$row['staff']])) {
            $out[$row['slot_date']]['busy'][$row['staff']][] = [(int) $row['start_min'], (int) $row['end_min'], (int) $row['booking_id'], $row['kind']];
        }
    }

    $stmt = db()->prepare('SELECT * FROM slot_blocks WHERE slot_date BETWEEN ? AND ?' . $forUpdate);
    $stmt->execute([$first, $last]);
    foreach ($stmt->fetchAll() as $row) {
        if (!isset($out[$row['slot_date']])) {
            continue;
        }
        [$s, $e] = block_interval($row);
        if (($row['staff'] ?? '') === 'leena') {
            $out[$row['slot_date']]['leenaOff'][] = [$s, $e, (int) $row['id']];
        } else {
            $out[$row['slot_date']]['studio'][] = [$s, $e, 'Blocked by an admin' . ($row['reason'] !== '' ? ': ' . $row['reason'] : '')];
        }
    }

    foreach (closure_rows_between($first, $last) as $row) {
        foreach ($dates as $date) {
            if ($row['start_date'] > $date || $row['end_date'] < $date) {
                continue;
            }
            $why = 'Studio closure' . ($row['reason'] !== '' ? ': ' . $row['reason'] : '');
            if ((int) $row['all_day'] === 1) {
                $out[$date]['studio'][] = [0, 1440, $why];
            } else {
                foreach (closure_times($row) as $t) {
                    $out[$date]['studio'][] = [hm_to_min($t), hm_to_min($t) + 60, $why];
                }
            }
        }
    }

    if ($calendar === null) {
        $calendar = calendar_busy(parse_studio_date($first), parse_studio_date($last)->modify('+1 day'), false);
    }
    foreach ($dates as $date) {
        if (!$calendar['ok']) {
            $out[$date]['calendarOk'] = false;
            continue;
        }
        $dayStart = parse_studio_date($date)->getTimestamp();
        foreach ($calendar['busy'] as $p) {
            $s = (int) floor(($p[0] - $dayStart) / 60);
            $e = (int) ceil(($p[1] - $dayStart) / 60);
            if ($e > 0 && $s < 1440) {
                $out[$date]['studio'][] = [max(0, $s), min(1440, $e), 'Event in the info@ calendar'];
            }
        }
    }
    return $out;
}

/** A block row as [start, end] minutes (a time block without an end lasts one hour). */
function block_interval(array $row): array
{
    if ($row['slot_time'] === '') {
        return [0, 1440];
    }
    $s = hm_to_min($row['slot_time']);
    $end = (string) ($row['end_time'] ?? '');
    return [$s, $end !== '' ? hm_to_min($end) : $s + 60];
}

/**
 * Tries to fit a service plan starting at $start.
 * $extra: time already taken by other pets in the same order ([staff => [[s, e]]]).
 * Returns ['segments' => [[staff, start, end]], 'clash' => ''] or ['segments' => null, 'clash' => why].
 * With $force, the segments are returned even when something clashes (the clash is still reported).
 */
function plan_fit(array $day, array $plan, int $start, array $extra = [], bool $force = false): array
{
    $t = $start;
    $segments = [];
    $clash = '';
    foreach ($plan as [$role, $minutes]) {
        $end = $t + (int) $minutes;
        if ($clash === '' && ($hit = overlaps($day['studio'], $t, $end))) {
            $clash = $hit[2] . ' (' . friendly_time(max($hit[0], 0)) . '–' . friendly_time(min($hit[1], 1440)) . ')';
        }
        $candidates = $role === 'either' ? ['bather', 'groomer'] : [$role];
        $chosen = null;
        foreach ($candidates as $who) {
            $busy = array_merge($day['busy'][$who], $extra[$who] ?? []);
            if ($who === 'leena') {
                $busy = array_merge($busy, $day['leenaOff']);
            }
            if (!overlaps($busy, $t, $end)) {
                $chosen = $who;
                break;
            }
        }
        if ($chosen === null) {
            if ($clash === '') {
                $clash = ($role === 'either' ? 'The bather and the groomer are both' : STAFF_LABELS[$role] . ' is')
                    . ' busy ' . friendly_time($t) . '–' . friendly_time($end)
                    . ($role === 'leena' && overlaps($day['leenaOff'], $t, $end) ? ' (Leena is marked unavailable)' : '');
            }
            $chosen = $candidates[0];
        }
        $segments[] = [$chosen, $t, $end];
        $t = $end;
    }
    if ($clash === '' && $t > STUDIO_CLOSE_MIN) {
        $clash = 'It would finish at ' . friendly_time($t) . ', after the studio closes at 8:00 PM';
    }
    return ['segments' => ($clash === '' || $force) ? $segments : null, 'clash' => $clash];
}

/** Adds a list of segments to an $extra map (for the next pet in the same order). */
function add_to_extra(array $extra, array $segments): array
{
    foreach ($segments as $seg) {
        $extra[$seg[0]][] = [$seg[1], $seg[2]];
    }
    return $extra;
}

function save_segments(PDO $pdo, int $bookingId, string $date, array $segments, string $kind = 'service'): void
{
    $stmt = $pdo->prepare('INSERT INTO booking_segments (booking_id, slot_date, staff, start_min, end_min, kind) VALUES (?, ?, ?, ?, ?, ?)');
    foreach ($segments as $seg) {
        $stmt->execute([$bookingId, $date, $seg[0], $seg[1], $seg[2], $seg[3] ?? $kind]);
    }
}

/** A booking's segments: [[staff, start, end, kind]]. */
function booking_segments(int $bookingId): array
{
    $stmt = db()->prepare('SELECT * FROM booking_segments WHERE booking_id = ? ORDER BY start_min, id');
    $stmt->execute([$bookingId]);
    return array_map(function (array $r): array {
        return [$r['staff'], (int) $r['start_min'], (int) $r['end_min'], $r['kind']];
    }, $stmt->fetchAll());
}

/** "Bather 11:00 AM–12:00 PM, then Groomer 12:00 PM–1:00 PM". */
function segments_label(array $segments): string
{
    return implode(', then ', array_map(function (array $s): string {
        return STAFF_LABELS[$s[0]] . ' ' . friendly_time($s[1]) . '–' . friendly_time($s[2]);
    }, $segments));
}

/** The plans the website needs (per service), to show only times that fit. */
function public_plans(): array
{
    return ['plans' => SERVICE_PLANS, 'defaultPlan' => DEFAULT_SERVICE_PLAN, 'onlineOff' => ONLINE_OFF_SERVICES, 'closeMin' => STUDIO_CLOSE_MIN];
}

/** The day's busy time for the website (no names or booking details). */
function public_busy(array $day): array
{
    $strip = function (array $list): array {
        return array_map(function (array $iv): array {
            return [$iv[0], $iv[1]];
        }, $list);
    };
    return [
        'bather' => $strip($day['busy']['bather']),
        'groomer' => $strip($day['busy']['groomer']),
        'leena' => $strip(array_merge($day['busy']['leena'], $day['leenaOff'])),
        'studio' => $strip($day['studio']),
    ];
}

/**
 * Adds team-time rows for bookings made before scheduling by person (schema 10).
 * Their old one-hour-per-slot times are kept; nothing is moved.
 */
function backfill_booking_segments(PDO $pdo): void
{
    $rows = $pdo->query("SELECT b.id, b.slot_date, b.slot_time, b.service_id FROM bookings b
        LEFT JOIN booking_segments s ON s.booking_id = b.id
        WHERE b.status = 'booked' AND s.id IS NULL")->fetchAll();
    foreach ($rows as $row) {
        if (!valid_hm((string) $row['slot_time'])) {
            continue;
        }
        $day = ['busy' => ['bather' => [], 'groomer' => [], 'leena' => []], 'studio' => [], 'leenaOff' => []];
        $stmt = $pdo->prepare('SELECT staff, start_min, end_min FROM booking_segments WHERE slot_date = ?');
        $stmt->execute([$row['slot_date']]);
        foreach ($stmt->fetchAll() as $s) {
            $day['busy'][$s['staff']][] = [(int) $s['start_min'], (int) $s['end_min']];
        }
        $fit = plan_fit($day, service_plan((string) $row['service_id']), hm_to_min($row['slot_time']), [], true);
        save_segments($pdo, (int) $row['id'], $row['slot_date'], $fit['segments']);
    }
}
