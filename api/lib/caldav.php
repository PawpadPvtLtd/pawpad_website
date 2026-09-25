<?php
/**
 * Talks to the info@pawpad.in calendar on the cPanel calendar server
 * (CalDAV at https://cpcalendars.pawpad.in:2080). Reads every event in a
 * date range (so manual events block slots), and adds / removes booking events.
 */

declare(strict_types=1);

if (!defined('PAWPAD_API')) {
    http_response_code(404);
    exit;
}

const STUDIO_TIMEZONE = 'Asia/Kolkata';

function caldav_settings(): array
{
    $c = pawpad_config();
    $email = (string) ($c['info_email'] ?? 'info@pawpad.in');
    $url = (string) ($c['caldav_calendar_url'] ?? ('https://cpcalendars.pawpad.in:2080/calendars/' . $email . '/calendar'));
    return [
        'url' => rtrim($url, '/') . '/',
        'user' => (string) ($c['caldav_user'] ?? $email),
        'password' => (string) ($c['caldav_password'] ?? $c['info_password'] ?? ''),
    ];
}

function caldav_configured(): bool
{
    $s = caldav_settings();
    return $s['password'] !== '' && $s['user'] !== '';
}

/**
 * One HTTP request to the calendar server.
 * @return array{status: int, body: string, error: string}
 */
function caldav_request(string $method, string $url, string $body = '', array $headers = []): array
{
    if (!function_exists('curl_init')) {
        return ['status' => 0, 'body' => '', 'error' => 'The PHP curl extension is not installed.'];
    }
    $s = caldav_settings();
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_USERPWD => $s['user'] . ':' . $s['password'],
        CURLOPT_HTTPAUTH => CURLAUTH_BASIC,
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
    ]);
    if ($body !== '') {
        curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
    }
    $response = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = $response === false ? curl_error($ch) : '';
    curl_close($ch);
    return ['status' => $status, 'body' => $response === false ? '' : (string) $response, 'error' => $error];
}

/**
 * Busy periods in the calendar between two moments, as [startTimestamp, endTimestamp] pairs.
 * @return array{ok: bool, busy: array, error: string}
 */
function caldav_busy(DateTimeImmutable $from, DateTimeImmutable $to): array
{
    if (!caldav_configured()) {
        return ['ok' => false, 'busy' => [], 'error' => 'The calendar is not set up yet (info_password in config.php).'];
    }
    $utc = new DateTimeZone('UTC');
    $start = $from->setTimezone($utc)->format('Ymd\THis\Z');
    $end = $to->setTimezone($utc)->format('Ymd\THis\Z');
    $query = '<?xml version="1.0" encoding="utf-8" ?>'
        . '<C:calendar-query xmlns:D="DAV:" xmlns:C="urn:ietf:params:xml:ns:caldav">'
        . '<D:prop><D:getetag/><C:calendar-data/></D:prop>'
        . '<C:filter><C:comp-filter name="VCALENDAR"><C:comp-filter name="VEVENT">'
        . '<C:time-range start="' . $start . '" end="' . $end . '"/>'
        . '</C:comp-filter></C:comp-filter></C:filter>'
        . '</C:calendar-query>';

    $result = caldav_request('REPORT', caldav_settings()['url'], $query, [
        'Depth: 1',
        'Content-Type: application/xml; charset=utf-8',
    ]);
    if ($result['status'] !== 207) {
        error_log('Pawpad API calendar read failed: HTTP ' . $result['status'] . ' ' . $result['error']);
        $why = $result['status'] === 401 ? 'the calendar password was refused' : ($result['error'] ?: 'HTTP ' . $result['status']);
        return ['ok' => false, 'busy' => [], 'error' => 'Could not read the info@ calendar (' . $why . ').'];
    }

    $busy = [];
    $fromTs = $from->getTimestamp();
    $toTs = $to->getTimestamp();
    preg_match_all('#<(?:[A-Za-z0-9_-]+:)?calendar-data[^>]*>(.*?)</(?:[A-Za-z0-9_-]+:)?calendar-data>#s', $result['body'], $matches);
    foreach ($matches[1] as $raw) {
        $raw = preg_replace('#^\s*<!\[CDATA\[(.*)\]\]>\s*$#s', '$1', $raw);
        $ics = html_entity_decode($raw, ENT_QUOTES | ENT_XML1, 'UTF-8');
        foreach (ics_event_periods($ics, $fromTs, $toTs) as $period) {
            $busy[] = $period;
        }
    }
    return ['ok' => true, 'busy' => $busy, 'error' => ''];
}

/**
 * Adds an event to the calendar (a booking, or a "Blocked" time or day).
 * Returns the event's address, or '' on failure.
 * The UID only uses letters, digits and dashes, so its file name is the same on every server.
 */
function caldav_create_event(string $uid, DateTimeImmutable $start, DateTimeImmutable $end, string $summary, string $description, string $location, bool $allDay = false): string
{
    if (!caldav_configured()) {
        return '';
    }
    $utc = new DateTimeZone('UTC');
    $lines = [
        'BEGIN:VCALENDAR',
        'VERSION:2.0',
        'PRODID:-//Pawpad//Grooming bookings//EN',
        'CALSCALE:GREGORIAN',
        'BEGIN:VEVENT',
        'UID:' . $uid,
        'DTSTAMP:' . gmdate('Ymd\THis\Z'),
        $allDay ? 'DTSTART;VALUE=DATE:' . $start->format('Ymd') : 'DTSTART:' . $start->setTimezone($utc)->format('Ymd\THis\Z'),
        $allDay ? 'DTEND;VALUE=DATE:' . $end->format('Ymd') : 'DTEND:' . $end->setTimezone($utc)->format('Ymd\THis\Z'),
        'SUMMARY:' . ics_escape($summary),
        'DESCRIPTION:' . ics_escape($description),
        'LOCATION:' . ics_escape($location),
        'STATUS:CONFIRMED',
        'END:VEVENT',
        'END:VCALENDAR',
    ];
    $ics = implode("\r\n", array_map('ics_fold', $lines)) . "\r\n";
    $href = caldav_settings()['url'] . $uid . '.ics';
    $result = caldav_request('PUT', $href, $ics, [
        'Content-Type: text/calendar; charset=utf-8',
        'If-None-Match: *',
    ]);
    // Servers answer 201 Created, 204 No Content or sometimes 200 OK.
    if (!in_array($result['status'], [200, 201, 204], true)) {
        error_log('Pawpad API calendar event create failed: HTTP ' . $result['status'] . ' ' . $result['error']);
        return '';
    }
    return $href;
}

/**
 * Turns an href from the calendar server (usually a path like /calendars/...) into a full address.
 */
function caldav_absolute_href(string $href): string
{
    if (preg_match('#^https?://#i', $href)) {
        return $href;
    }
    $parts = parse_url(caldav_settings()['url']);
    $origin = ($parts['scheme'] ?? 'https') . '://' . ($parts['host'] ?? '') . (isset($parts['port']) ? ':' . $parts['port'] : '');
    return $origin . '/' . ltrim($href, '/');
}

/**
 * Finds our own events in a date range whose UID starts with $uidPrefix
 * (e.g. "pawpad-booking-12-"), whatever file name the server gave them.
 * @return array{ok: bool, hrefs: string[]}
 */
function caldav_find_events(string $uidPrefix, DateTimeImmutable $from, DateTimeImmutable $to): array
{
    if (!caldav_configured()) {
        return ['ok' => false, 'hrefs' => []];
    }
    $utc = new DateTimeZone('UTC');
    $query = '<?xml version="1.0" encoding="utf-8" ?>'
        . '<C:calendar-query xmlns:D="DAV:" xmlns:C="urn:ietf:params:xml:ns:caldav">'
        . '<D:prop><D:getetag/><C:calendar-data/></D:prop>'
        . '<C:filter><C:comp-filter name="VCALENDAR"><C:comp-filter name="VEVENT">'
        . '<C:time-range start="' . $from->setTimezone($utc)->format('Ymd\THis\Z') . '" end="' . $to->setTimezone($utc)->format('Ymd\THis\Z') . '"/>'
        . '</C:comp-filter></C:comp-filter></C:filter>'
        . '</C:calendar-query>';
    $result = caldav_request('REPORT', caldav_settings()['url'], $query, ['Depth: 1', 'Content-Type: application/xml; charset=utf-8']);
    if ($result['status'] !== 207) {
        return ['ok' => false, 'hrefs' => []];
    }
    $hrefs = [];
    preg_match_all('#<(?:[A-Za-z0-9_-]+:)?response[^>]*>(.*?)</(?:[A-Za-z0-9_-]+:)?response>#s', $result['body'], $responses);
    foreach ($responses[1] as $response) {
        if (!preg_match('#<(?:[A-Za-z0-9_-]+:)?href[^>]*>(.*?)</(?:[A-Za-z0-9_-]+:)?href>#s', $response, $h)) {
            continue;
        }
        $data = '';
        if (preg_match('#<(?:[A-Za-z0-9_-]+:)?calendar-data[^>]*>(.*?)</(?:[A-Za-z0-9_-]+:)?calendar-data>#s', $response, $c)) {
            $data = html_entity_decode(preg_replace('#^\s*<!\[CDATA\[(.*)\]\]>\s*$#s', '$1', $c[1]), ENT_QUOTES | ENT_XML1, 'UTF-8');
        }
        $data = preg_replace("/\r\n[ \t]|\n[ \t]/", '', $data);
        if (preg_match('/^UID:(.*)$/mi', $data, $u) && strpos(trim($u[1]), $uidPrefix) === 0) {
            $hrefs[] = caldav_absolute_href(trim(html_entity_decode($h[1], ENT_QUOTES | ENT_XML1, 'UTF-8')));
        }
    }
    return ['ok' => true, 'hrefs' => array_values(array_unique($hrefs))];
}

function caldav_delete_event(string $href): bool
{
    if ($href === '' || !caldav_configured()) {
        return false;
    }
    $result = caldav_request('DELETE', $href);
    return in_array($result['status'], [200, 204], true);
}

/**
 * Removes every event of ours with this UID prefix around a date: first the
 * saved address, then anything the calendar still has with that UID.
 * Returns true when none are left.
 */
function caldav_remove_events(string $uidPrefix, string $savedHref, DateTimeImmutable $day): bool
{
    if (!caldav_configured()) {
        return false;
    }
    if ($savedHref !== '') {
        caldav_delete_event($savedHref);
    }
    $from = $day->modify('-1 day');
    $to = $day->modify('+2 days');
    $found = caldav_find_events($uidPrefix, $from, $to);
    if (!$found['ok']) {
        return false;
    }
    foreach ($found['hrefs'] as $href) {
        caldav_delete_event($href);
    }
    $check = caldav_find_events($uidPrefix, $from, $to);
    return $check['ok'] && !$check['hrefs'];
}

function ics_escape(string $text): string
{
    return str_replace(["\\", ";", ",", "\r\n", "\n", "\r"], ["\\\\", "\\;", "\\,", "\\n", "\\n", "\\n"], $text);
}

/** Lines longer than 75 bytes are folded onto continuation lines. */
function ics_fold(string $line): string
{
    $out = '';
    while (strlen($line) > 75) {
        $cut = 75;
        // Don't split a multi-byte UTF-8 character.
        while ($cut > 0 && (ord($line[$cut]) & 0xC0) === 0x80) {
            $cut--;
        }
        $out .= substr($line, 0, $cut) . "\r\n ";
        $line = substr($line, $cut);
    }
    return $out . $line;
}

// ---------------------------------------------------------------------------
// Reading events (including repeating ones) from iCalendar text
// ---------------------------------------------------------------------------

/**
 * @return array<int, array{name: string, params: array, value: string}>
 */
function ics_lines(string $ics): array
{
    $ics = preg_replace("/\r\n[ \t]|\n[ \t]/", '', $ics);
    $lines = [];
    foreach (preg_split("/\r\n|\n|\r/", $ics) as $line) {
        if ($line === '' || strpos($line, ':') === false) {
            continue;
        }
        [$head, $value] = explode(':', $line, 2);
        $parts = explode(';', $head);
        $name = strtoupper(array_shift($parts));
        $params = [];
        foreach ($parts as $p) {
            if (strpos($p, '=') !== false) {
                [$k, $v] = explode('=', $p, 2);
                $params[strtoupper($k)] = trim($v, '"');
            }
        }
        $lines[] = ['name' => $name, 'params' => $params, 'value' => $value];
    }
    return $lines;
}

function ics_timezone(array $params): DateTimeZone
{
    if (!empty($params['TZID'])) {
        try {
            return new DateTimeZone($params['TZID']);
        } catch (Exception $e) {
            // e.g. a Windows name such as "India Standard Time": the studio's own zone is the best guess.
        }
    }
    return new DateTimeZone(STUDIO_TIMEZONE);
}

/**
 * @return array{0: DateTimeImmutable, 1: bool} the moment and whether it is a whole-day date
 */
function ics_parse_time(string $value, array $params): ?array
{
    $value = trim($value);
    if (($params['VALUE'] ?? '') === 'DATE' || preg_match('/^\d{8}$/', $value)) {
        $d = DateTimeImmutable::createFromFormat('!Ymd', substr($value, 0, 8), new DateTimeZone(STUDIO_TIMEZONE));
        return $d ? [$d, true] : null;
    }
    if (substr($value, -1) === 'Z') {
        $d = DateTimeImmutable::createFromFormat('Ymd\THis\Z', $value, new DateTimeZone('UTC'));
        return $d ? [$d, false] : null;
    }
    $d = DateTimeImmutable::createFromFormat('Ymd\THis', $value, ics_timezone($params));
    return $d ? [$d, false] : null;
}

function ics_duration_seconds(string $value): int
{
    if (!preg_match('/^([+-])?P(?:(\d+)W)?(?:(\d+)D)?(?:T(?:(\d+)H)?(?:(\d+)M)?(?:(\d+)S)?)?$/', trim($value), $m)) {
        return 3600;
    }
    $seconds = ((int) ($m[2] ?? 0)) * 604800 + ((int) ($m[3] ?? 0)) * 86400
        + ((int) ($m[4] ?? 0)) * 3600 + ((int) ($m[5] ?? 0)) * 60 + ((int) ($m[6] ?? 0));
    return ($m[1] ?? '') === '-' ? 0 : $seconds;
}

/**
 * Busy periods of every event in the calendar text that overlap [fromTs, toTs).
 * @return array<int, array{0: int, 1: int}>
 */
function ics_event_periods(string $ics, int $fromTs, int $toTs): array
{
    $periods = [];
    $event = null;
    $depth = 0;
    foreach (ics_lines($ics) as $line) {
        if ($line['name'] === 'BEGIN') {
            if (strtoupper($line['value']) === 'VEVENT' && $event === null) {
                $event = ['exdates' => []];
                $depth = 0;
            } elseif ($event !== null) {
                $depth++; // e.g. a VALARM inside the event
            }
            continue;
        }
        if ($line['name'] === 'END') {
            if ($event !== null && $depth > 0) {
                $depth--;
            } elseif ($event !== null && strtoupper($line['value']) === 'VEVENT') {
                foreach (ics_expand_event($event, $fromTs, $toTs) as $p) {
                    $periods[] = $p;
                }
                $event = null;
            }
            continue;
        }
        if ($event === null || $depth > 0) {
            continue;
        }
        switch ($line['name']) {
            case 'DTSTART':
            case 'DTEND':
                $event[$line['name']] = ics_parse_time($line['value'], $line['params']);
                break;
            case 'DURATION':
                $event['DURATION'] = ics_duration_seconds($line['value']);
                break;
            case 'RRULE':
                $event['RRULE'] = $line['value'];
                break;
            case 'EXDATE':
                foreach (explode(',', $line['value']) as $v) {
                    $t = ics_parse_time($v, $line['params']);
                    if ($t) {
                        $event['exdates'][] = $t[0]->getTimestamp();
                    }
                }
                break;
            case 'STATUS':
                $event['STATUS'] = strtoupper(trim($line['value']));
                break;
        }
    }
    return $periods;
}

/**
 * @return array<int, array{0: int, 1: int}>
 */
function ics_expand_event(array $event, int $fromTs, int $toTs): array
{
    if (empty($event['DTSTART']) || ($event['STATUS'] ?? '') === 'CANCELLED') {
        return [];
    }
    [$start, $allDay] = $event['DTSTART'];
    if (!empty($event['DTEND'])) {
        $length = $event['DTEND'][0]->getTimestamp() - $start->getTimestamp();
    } elseif (isset($event['DURATION'])) {
        $length = $event['DURATION'];
    } else {
        $length = $allDay ? 86400 : 0;
    }
    $length = max($length, 60); // an event with no length still blocks its start minute

    $starts = empty($event['RRULE']) ? [$start] : ics_rrule_starts($start, $event['RRULE'], $toTs);
    $periods = [];
    foreach ($starts as $occurrence) {
        $s = $occurrence->getTimestamp();
        if (in_array($s, $event['exdates'], true)) {
            continue;
        }
        $e = $s + $length;
        if ($s < $toTs && $e > $fromTs) {
            $periods[] = [$s, $e];
        }
    }
    return $periods;
}

/**
 * Start times of a repeating event (RRULE) up to $toTs. Handles the rules
 * phone and computer calendars create: DAILY, WEEKLY (with BYDAY),
 * MONTHLY (BYMONTHDAY or BYDAY such as 2TU / -1FR) and YEARLY, with
 * INTERVAL, COUNT and UNTIL.
 * @return DateTimeImmutable[]
 */
function ics_rrule_starts(DateTimeImmutable $start, string $rrule, int $toTs): array
{
    $rule = [];
    foreach (explode(';', strtoupper($rrule)) as $part) {
        if (strpos($part, '=') !== false) {
            [$k, $v] = explode('=', $part, 2);
            $rule[$k] = $v;
        }
    }
    $freq = $rule['FREQ'] ?? '';
    $interval = max(1, (int) ($rule['INTERVAL'] ?? 1));
    $count = isset($rule['COUNT']) ? (int) $rule['COUNT'] : null;
    $until = null;
    if (!empty($rule['UNTIL'])) {
        $u = ics_parse_time($rule['UNTIL'], []);
        if ($u) {
            $until = $u[1] ? $u[0]->modify('+1 day')->getTimestamp() - 1 : $u[0]->getTimestamp();
        }
    }
    $weekdays = ['MO' => 1, 'TU' => 2, 'WE' => 3, 'TH' => 4, 'FR' => 5, 'SA' => 6, 'SU' => 7];
    $byDay = [];
    foreach (array_filter(explode(',', $rule['BYDAY'] ?? '')) as $d) {
        if (preg_match('/^([+-]?\d+)?(MO|TU|WE|TH|FR|SA|SU)$/', $d, $m)) {
            $byDay[] = ['n' => ($m[1] ?? '') === '' ? 0 : (int) $m[1], 'day' => $weekdays[$m[2]]];
        }
    }
    $byMonthDay = array_map('intval', array_filter(explode(',', $rule['BYMONTHDAY'] ?? ''), 'strlen'));
    $time = $start->format('H:i:s');

    $result = [];
    $emitted = 0;
    $emit = function (DateTimeImmutable $candidate) use (&$result, &$emitted, $start, $count, $until, $toTs): bool {
        // Returns false once no more occurrences are needed.
        if ($candidate < $start) {
            return true;
        }
        if (($until !== null && $candidate->getTimestamp() > $until) || ($count !== null && $emitted >= $count)
            || $candidate->getTimestamp() >= $toTs) {
            return false;
        }
        $emitted++;
        $result[] = $candidate;
        return true;
    };

    for ($i = 0; $i < 1000; $i++) {
        $candidates = [];
        if ($freq === 'DAILY') {
            $candidates[] = $start->modify('+' . ($i * $interval) . ' days');
        } elseif ($freq === 'WEEKLY') {
            $weekStart = $start->modify('-' . ((int) $start->format('N') - 1) . ' days')->modify('+' . ($i * $interval) . ' weeks');
            $days = $byDay ? array_column($byDay, 'day') : [(int) $start->format('N')];
            sort($days);
            foreach ($days as $day) {
                $candidates[] = $weekStart->modify('+' . ($day - 1) . ' days');
            }
        } elseif ($freq === 'MONTHLY') {
            $month = $start->modify('first day of this month')->modify('+' . ($i * $interval) . ' months');
            $daysInMonth = (int) $month->format('t');
            if ($byDay) {
                foreach ($byDay as $bd) {
                    $matches = [];
                    for ($d = 1; $d <= $daysInMonth; $d++) {
                        $date = $month->setDate((int) $month->format('Y'), (int) $month->format('m'), $d);
                        if ((int) $date->format('N') === $bd['day']) {
                            $matches[] = $date;
                        }
                    }
                    if ($bd['n'] === 0) {
                        $candidates = array_merge($candidates, $matches);
                    } else {
                        $idx = $bd['n'] > 0 ? $bd['n'] - 1 : count($matches) + $bd['n'];
                        if (isset($matches[$idx])) {
                            $candidates[] = $matches[$idx];
                        }
                    }
                }
            } else {
                $days = $byMonthDay ?: [(int) $start->format('j')];
                foreach ($days as $d) {
                    $day = $d < 0 ? $daysInMonth + $d + 1 : $d;
                    if ($day >= 1 && $day <= $daysInMonth) {
                        $candidates[] = $month->setDate((int) $month->format('Y'), (int) $month->format('m'), $day);
                    }
                }
            }
        } elseif ($freq === 'YEARLY') {
            $year = (int) $start->format('Y') + $i * $interval;
            if (checkdate((int) $start->format('m'), (int) $start->format('d'), $year)) {
                $candidates[] = $start->setDate($year, (int) $start->format('m'), (int) $start->format('d'));
            }
        } else {
            return [$start]; // unknown rule: at least block the first occurrence
        }

        usort($candidates, function ($a, $b) {
            return $a <=> $b;
        });
        foreach ($candidates as $c) {
            [$h, $m, $s] = array_map('intval', explode(':', $time));
            if (!$emit($c->setTime($h, $m, $s))) {
                return $result;
            }
        }
    }
    return $result;
}
