<?php
/**
 * Course applications: public submission with real sequential IDs, and the
 * admin actions (list, status changes, notes, candidate emails, delete).
 */

declare(strict_types=1);

if (!defined('PAWPAD_API')) {
    http_response_code(404);
    exit;
}

const APPLICATION_STATUSES = ['pending_review', 'interview_scheduled', 'approved', 'rejected', 'enrolled'];

const STATUS_LABELS = [
    'pending_review' => 'Pending Review',
    'interview_scheduled' => 'Interview Scheduled',
    'approved' => 'Approved & Accepted',
    'rejected' => 'Rejected / Declined',
    'enrolled' => 'Enrolled & Deposit Confirmed',
];

function decode_list(?string $json): array
{
    $value = json_decode((string) $json, true);
    return is_array($value) ? $value : [];
}

function encode_json($value): string
{
    return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

/**
 * Database row → the same shape the admin panel already uses.
 */
function application_to_array(array $row): array
{
    return [
        'id' => $row['id'],
        'courseKey' => $row['course_key'],
        'courseCode' => $row['course_code'],
        'courseName' => $row['course_name'],
        'courseFee' => $row['course_fee'],
        'createdAt' => to_iso($row['created_at']),
        'status' => $row['status'],
        'interviewDate' => $row['interview_date'],
        'applicant' => [
            'name' => $row['applicant_name'],
            'phone' => $row['applicant_phone'],
            'email' => $row['applicant_email'],
            'city' => $row['applicant_city'],
        ],
        'responses' => decode_list($row['responses']),
        'acknowledgments' => decode_list($row['acknowledgments']),
        'staffNotes' => decode_list($row['staff_notes']),
        'communications' => decode_list($row['communications']),
    ];
}

function find_application(string $id): array
{
    $stmt = db()->prepare('SELECT * FROM applications WHERE id = ?');
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    if (!$row) {
        json_error('Application not found.', 404);
    }
    return $row;
}

/**
 * Next number for a course prefix, e.g. PCGEC → 7, in one atomic statement.
 * The row stays locked until COMMIT, so two people applying at the same
 * moment always get different numbers, and a failed save leaves no gap.
 */
function next_sequence(PDO $pdo, string $prefix): int
{
    $pdo->prepare(
        'INSERT INTO course_sequences (prefix, last_number) VALUES (?, LAST_INSERT_ID(1))
         ON DUPLICATE KEY UPDATE last_number = LAST_INSERT_ID(last_number + 1)'
    )->execute([$prefix]);
    return (int) $pdo->query('SELECT LAST_INSERT_ID()')->fetchColumn();
}

function is_retryable_lock_error(Throwable $e): bool
{
    // 1213 = deadlock, 1205 = lock wait timeout: MySQL asks us to simply try again.
    return $e instanceof PDOException && isset($e->errorInfo[1]) && in_array((int) $e->errorInfo[1], [1205, 1213], true);
}

function submit_application(array $input): array
{
    if (!empty($input['botcheck'])) {
        json_error('Submission rejected.');
    }
    rate_limit('submit', 30, 3600);

    $applicant = is_array($input['applicant'] ?? null) ? $input['applicant'] : [];
    $name = clean_text($applicant['name'] ?? '', 255);
    $email = clean_text($applicant['email'] ?? '', 255);
    $phone = clean_text($applicant['phone'] ?? '', 60);
    if ($name === '' || $phone === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        json_error('Please fill in your name, phone number and a valid email address.');
    }

    $prefix = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string) ($input['courseCode'] ?? '')));
    $prefix = substr($prefix, 0, 12) ?: 'APP';

    $responses = [];
    if (is_array($input['responses'] ?? null)) {
        foreach (array_slice($input['responses'], 0, 40, true) as $key => $value) {
            $cleanKey = preg_replace('/[^A-Za-z0-9_]/', '', (string) $key);
            if ($cleanKey !== '') {
                $responses[substr($cleanKey, 0, 60)] = clean_text($value, 5000);
            }
        }
    }
    $acks = [];
    if (is_array($input['acknowledgments'] ?? null)) {
        foreach (array_slice($input['acknowledgments'], 0, 30, true) as $key => $value) {
            $cleanKey = preg_replace('/[^A-Za-z0-9_]/', '', (string) $key);
            if ($cleanKey !== '') {
                $acks[substr($cleanKey, 0, 60)] = true;
            }
        }
    }

    $now = now_utc();
    $notes = [['author' => 'System', 'date' => to_iso($now), 'text' => 'Application submitted online.']];

    $pdo = db();
    for ($attempt = 1; ; $attempt++) {
        $pdo->beginTransaction();
        try {
            $number = next_sequence($pdo, $prefix);
            $id = $prefix . ' - ' . str_pad((string) $number, 3, '0', STR_PAD_LEFT);
            $pdo->prepare(
                'INSERT INTO applications (id, course_key, course_code, course_name, course_fee, status, interview_date,
                    applicant_name, applicant_phone, applicant_email, applicant_city, responses, acknowledgments,
                    staff_notes, communications, created_at, updated_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            )->execute([
                $id,
                clean_text($input['courseKey'] ?? '', 60),
                $prefix,
                clean_text($input['courseName'] ?? '', 255),
                clean_text($input['courseFee'] ?? '', 60),
                'pending_review',
                '',
                $name,
                $phone,
                $email,
                clean_text($applicant['city'] ?? '', 120),
                encode_json((object) $responses),
                encode_json((object) $acks),
                encode_json($notes),
                '[]',
                $now,
                $now,
            ]);
            $pdo->commit();
            return ['id' => $id];
        } catch (Throwable $e) {
            $pdo->rollBack();
            if ($attempt < 5 && is_retryable_lock_error($e)) {
                usleep(random_int(20000, 120000));
                continue;
            }
            error_log('Pawpad API submit failed: ' . $e->getMessage());
            json_error('Could not save your application. Please try again.', 500);
        }
    }
}

function list_applications(): array
{
    $rows = db()->query('SELECT * FROM applications ORDER BY created_at DESC, id DESC')->fetchAll();
    return ['applications' => array_map('application_to_array', $rows)];
}

/**
 * Change status / interview date, add a note, and optionally email the candidate.
 * The email always goes to the address stored on the application, never to an
 * address sent by the browser.
 */
function update_application(array $admin, array $input): array
{
    $row = find_application(clean_text($input['id'] ?? '', 40));
    $notes = decode_list($row['staff_notes']);
    $communications = decode_list($row['communications']);
    $status = $row['status'];
    $interviewDate = $row['interview_date'];
    $now = to_iso(now_utc());
    $author = $admin['email'];

    if (array_key_exists('status', $input)) {
        $newStatus = (string) $input['status'];
        if (!in_array($newStatus, APPLICATION_STATUSES, true)) {
            json_error('Unknown status.');
        }
        if ($newStatus !== $status) {
            $notes[] = [
                'author' => $author,
                'date' => $now,
                'text' => "Status changed from '" . (STATUS_LABELS[$status] ?? $status) . "' to '" . (STATUS_LABELS[$newStatus] ?? $newStatus) . "'.",
            ];
            $status = $newStatus;
        }
    }
    if (array_key_exists('interviewDate', $input)) {
        $interviewDate = clean_text($input['interviewDate'], 40);
    }
    $noteText = clean_text($input['note'] ?? '', 5000);
    if ($noteText !== '') {
        $notes[] = ['author' => $author, 'date' => $now, 'text' => $noteText];
    }

    $emailResult = null;
    if (is_array($input['email'] ?? null)) {
        $subject = clean_text(str_replace(["\r", "\n"], ' ', (string) ($input['email']['subject'] ?? '')), 300);
        $body = clean_text($input['email']['body'] ?? '', 20000);
        $type = preg_replace('/[^a-z_]/', '', (string) ($input['email']['type'] ?? 'notification'));
        if ($subject === '' || $body === '') {
            json_error('The email needs a subject and a message.');
        }
        $emailResult = send_candidate_email($row['applicant_email'], $row['applicant_name'], $subject, $body);
        $communications[] = [
            'type' => $type,
            'date' => $now,
            'recipient' => $row['applicant_email'],
            'subject' => $subject,
            'body' => $body,
            'status' => $emailResult['sent'] ? 'sent' : 'failed',
        ];
        $notes[] = [
            'author' => $author,
            'date' => $now,
            'text' => $emailResult['sent']
                ? 'Email "' . $subject . '" sent to ' . $row['applicant_email'] . ' from courses@pawpad.in.'
                : 'Email "' . $subject . '" could NOT be sent: ' . $emailResult['error'],
        ];
    }

    db()->prepare(
        'UPDATE applications SET status = ?, interview_date = ?, staff_notes = ?, communications = ?, updated_at = ? WHERE id = ?'
    )->execute([$status, $interviewDate, encode_json($notes), encode_json($communications), now_utc(), $row['id']]);

    return [
        'application' => application_to_array(find_application($row['id'])),
        'email' => $emailResult,
    ];
}

function delete_applications(array $input): array
{
    $ids = is_array($input['ids'] ?? null) ? $input['ids'] : [];
    $ids = array_values(array_filter(array_map(function ($id) {
        return clean_text($id, 40);
    }, $ids)));
    if (!$ids) {
        json_error('No applications selected.');
    }
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $stmt = db()->prepare("DELETE FROM applications WHERE id IN ($placeholders)");
    $stmt->execute($ids);
    return ['deleted' => $stmt->rowCount()];
}
