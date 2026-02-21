<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/mail-service.php';

try {
    appSecrets(); // validate secrets early
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
    exit;
}

$SCHEDULE = [
    ['week' => 1, 'wedDate' => 'Feb 25', 'sunDate' => 'Mar 1'],
    ['week' => 2, 'wedDate' => 'Mar 4',  'sunDate' => 'Mar 8'],
    ['week' => 3, 'wedDate' => 'Mar 11', 'sunDate' => 'Mar 15'],
    ['week' => 4, 'wedDate' => 'Mar 18', 'sunDate' => 'Mar 22'],
    ['week' => 5, 'wedDate' => 'Mar 25', 'sunDate' => 'Mar 29'],
    ['week' => 6, 'wedDate' => 'Apr 1',  'sunDate' => 'Apr 5'],
    ['week' => 7, 'wedDate' => 'Apr 8',  'sunDate' => 'Apr 12'],
    ['week' => 8, 'wedDate' => 'Apr 15', 'sunDate' => 'Apr 19'],
];

$CLIENTS = [
    'Carletta' => ['Course 1', 'Course 2'],
    'Ahmed'    => ['Course 1'],
    'Palmer'   => ['Course 1', 'Course 2'],
];

$TASK_TYPES = [
    ['key' => 'disc_post', 'label' => 'Discussion Post', 'icon' => '💬'],
    ['key' => 'disc_response', 'label' => 'Discussion Response', 'icon' => '↩️'],
    ['key' => 'assignment', 'label' => 'Assignment', 'icon' => '📝'],
    ['key' => 'quiz', 'label' => 'Quiz', 'icon' => '📋'],
];

$STATUS_LABELS = [
    'not_started' => '○ Not Started',
    'in_progress' => '◑ In Progress',
    'completed'   => '✓ Completed',
    'missed'      => '✕ Missed',
    'na'          => '— N/A',
];

$cronSecret = appCronSecret();
if ($cronSecret !== '') {
    $secret = (string) ($_GET['secret'] ?? '');
    if (!hash_equals($cronSecret, $secret)) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'unauthorized']);
        exit;
    }
}

$jessEmail = appDefaultJessicaEmail();
$adminEmail = appDefaultAdminEmail();
if ($jessEmail === '') {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'missing defaultJessicaEmail in secrets.json']);
    exit;
}

$rawDay = extractDayParam();
$normalizedDay = strtolower(trim($rawDay));
$normalizedDay = trim($normalizedDay, " \t\n\r\0\x0B\"'");
$normalizedDay = preg_replace('/[^a-z]/', '', $normalizedDay) ?? '';
$dayMap = [
    'monday' => 'monday',
    'mon' => 'monday',
    'friday' => 'friday',
    'fri' => 'friday',
];
$day = $dayMap[$normalizedDay] ?? '';
if ($day === '') {
    http_response_code(400);
    echo json_encode([
        'ok' => false,
        'error' => 'invalid_day',
        'message' => 'Use ?day=monday or ?day=friday',
        'received_day' => $rawDay,
        'normalized_day' => $normalizedDay,
        'query_string' => (string) ($_SERVER['QUERY_STRING'] ?? ''),
    ]);
    exit;
}

$week = getWeekNum();
$sched = getSchedule($SCHEDULE, $week);
$allData = loadTrackerData();
$mail = buildReminderEmail($CLIENTS, $TASK_TYPES, $STATUS_LABELS, $allData, $week, $sched, $day);

$first = appSendEmail($jessEmail, $mail['subject'], $mail['body']);
if (!$first['ok']) {
    http_response_code(502);
    echo json_encode([
        'ok' => false,
        'error' => 'failed sending reminder',
        'details' => $first['error'] ?? 'send_failed',
    ]);
    exit;
}

$adminSent = false;
if ($adminEmail !== '' && $adminEmail !== $jessEmail) {
    $admin = appSendEmail(
        $adminEmail,
        '[COPY] ' . $mail['subject'],
        "Admin copy of reminder sent to Jessica.\n\n" . $mail['body']
    );
    $adminSent = (bool) ($admin['ok'] ?? false);
}

echo json_encode([
    'ok' => true,
    'day' => $day,
    'week' => $week,
    'subject' => $mail['subject'],
    'adminCopySent' => $adminSent,
]);

function getWeekNum(): int
{
    $start = new DateTimeImmutable('2026-02-23T00:00:00Z');
    $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
    $seconds = $now->getTimestamp() - $start->getTimestamp();
    $diffWeeks = (int) floor($seconds / (7 * 24 * 3600));
    $week = $diffWeeks + 1;
    if ($week < 1) {
        return 1;
    }
    if ($week > 8) {
        return 8;
    }
    return $week;
}

function getSchedule(array $schedule, int $week): array
{
    foreach ($schedule as $entry) {
        if ((int) $entry['week'] === $week) {
            return $entry;
        }
    }
    return $schedule[0];
}

function buildReminderEmail(
    array $clients,
    array $taskTypes,
    array $statusLabels,
    array $allData,
    int $week,
    array $sched,
    string $day
): array {
    $isMonday = $day === 'monday';
    $subject = $isMonday
        ? "📌 Week {$week} — Discussion Posts due Wednesday {$sched['wedDate']}"
        : "📌 Week {$week} — Assignments, Quizzes & Responses due Sunday {$sched['sunDate']}";

    $body = "Hi Jessica!\n\n";
    $body .= "Week {$week} of 8 — ";
    $body .= $isMonday
        ? "Discussion Posts are due Wednesday, {$sched['wedDate']}.\n\n"
        : "Assignments, Quizzes & Discussion Responses are due Sunday, {$sched['sunDate']}.\n\n";

    $hasWarnings = false;
    foreach ($clients as $client => $courses) {
        $body .= "━━ " . strtoupper($client) . " ━━\n";
        foreach ($courses as $course) {
            foreach ($taskTypes as $task) {
                $status = getStoredStatus($allData, $client, $week, $course, (string) $task['key']);
                $label = $statusLabels[$status] ?? $status;
                $warn = $status === 'not_started' || $status === 'missed';
                if ($warn) {
                    $hasWarnings = true;
                }

                $body .= "{$task['icon']} {$client} {$course} — {$task['label']}\n";
                $body .= "Status: {$label}" . ($warn ? " ⚠️ NEEDS ATTENTION" : "") . "\n";
            }
        }
        $body .= "\n";
    }

    $body .= $hasWarnings
        ? "⚠️ Some items still need attention — please update your tracker!\n\n"
        : "✅ Great job — everything is on track!\n\n";
    $body .= "Week {$week} of 8 — You've got this! 💪";

    return ['subject' => $subject, 'body' => $body];
}

function loadTrackerData(): array
{
    $files = [
        __DIR__ . '/data/tracker-data.json',
        '/tmp/jessica-tracker-data.json',
        __DIR__ . '/tracker-data.json',
    ];

    foreach ($files as $file) {
        if (!is_file($file)) {
            continue;
        }

        $raw = @file_get_contents($file);
        if ($raw === false || trim($raw) === '') {
            continue;
        }

        $decoded = json_decode($raw, true);
        if (is_array($decoded)) {
            return $decoded;
        }
    }

    return [];
}

function getStoredStatus(array $allData, string $client, int $week, string $course, string $typeKey): string
{
    $rowKey = "{$course}__{$typeKey}";
    $value = $allData[$client][(string) $week][$rowKey]['status'] ?? null;
    if (!is_string($value) || $value === '') {
        return 'not_started';
    }
    return $value;
}

function extractDayParam(): string
{
    $candidates = [
        $_GET['day'] ?? null,
        $_GET['Day'] ?? null,
        $_GET['DAY'] ?? null,
        $_GET['amp;day'] ?? null,
        $_REQUEST['day'] ?? null,
        $_POST['day'] ?? null,
    ];

    foreach ($candidates as $value) {
        if ($value !== null && trim((string) $value) !== '') {
            return (string) $value;
        }
    }

    $query = (string) ($_SERVER['QUERY_STRING'] ?? '');
    if ($query !== '') {
        $parsed = [];
        parse_str(html_entity_decode($query, ENT_QUOTES | ENT_HTML5), $parsed);
        foreach (['day', 'Day', 'DAY', 'amp;day', 'd', 'weekday', 'run_day'] as $key) {
            if (isset($parsed[$key]) && trim((string) $parsed[$key]) !== '') {
                return (string) $parsed[$key];
            }
        }
    }

    $pathInfo = (string) ($_SERVER['PATH_INFO'] ?? '');
    if ($pathInfo !== '') {
        $parts = array_values(array_filter(explode('/', trim($pathInfo, '/'))));
        if (count($parts) > 0) {
            return (string) $parts[count($parts) - 1];
        }
    }

    $uriPath = (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH);
    if ($uriPath !== '') {
        $segments = array_values(array_filter(explode('/', trim($uriPath, '/'))));
        if (count($segments) >= 2 && str_ends_with($segments[count($segments) - 2], '.php')) {
            return (string) $segments[count($segments) - 1];
        }
    }

    return '';
}
