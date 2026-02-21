<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

// Server-side cron endpoint for cron-job.org (curl/wget style requests).
// Example:
//   /reminder-cron.php?secret=CHANGE_ME&day=monday
//   /reminder-cron.php?secret=CHANGE_ME&day=friday

$CONFIG = [
    'smtpHost'   => 'smtp.gmail.com',
    'smtpPort'   => 587, // 587 (STARTTLS) or 465 (implicit TLS)
    'smtpSecure' => 'tls', // tls or ssl
    'smtpUser'   => '0x1ab983639@gmail.com',
    'smtpPass'   => 'jnai fews jcir tonq',
    'fromEmail'  => '0x1ab983639@gmail.com',
    'fromName'   => 'Jessica Tracker',
    'jessEmail'  => 'denisgitau12@gmail.com',
    'adminEmail' => '0x1ab983639@gmail.com',
    'cronSecret' => '1d35fase5146gw45134daA',
];

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

if ($CONFIG['cronSecret'] === 'test_cron_secret') {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'cronSecret not configured']);
    exit;
}

$secret = $_GET['secret'] ?? '';
if (!hash_equals($CONFIG['cronSecret'], (string) $secret)) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'unauthorized']);
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
$mail = buildReminderEmail($CLIENTS, $week, $sched, $day);

$first = sendEmailSmtp(
    $CONFIG,
    $CONFIG['jessEmail'],
    $mail['subject'],
    $mail['body']
);

if (!$first['ok']) {
    http_response_code(502);
    echo json_encode([
        'ok' => false,
        'error' => 'failed sending reminder',
        'details' => $first['error'],
    ]);
    exit;
}

$adminSent = false;
if ($CONFIG['adminEmail'] !== '' && $CONFIG['adminEmail'] !== $CONFIG['jessEmail']) {
    $admin = sendEmailSmtp(
        $CONFIG,
        $CONFIG['adminEmail'],
        '[COPY] ' . $mail['subject'],
        "Admin copy of reminder sent to Jessica.\n\n" . $mail['body']
    );
    $adminSent = $admin['ok'];
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

function buildReminderEmail(array $clients, int $week, array $sched, string $day): array
{
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
            if ($isMonday) {
                $body .= "↩️ {$client} {$course} — Discussion Post\n";
                $body .= "Status: ○ Not Started ⚠️ NEEDS ATTENTION\n";
                $hasWarnings = true;
            } else {
                $body .= "↩️ {$client} {$course} — Discussion Response\n";
                $body .= "Status: ○ Not Started ⚠️ NEEDS ATTENTION\n";
                $body .= "📝 {$client} {$course} — Assignment\n";
                $body .= "Status: ○ Not Started ⚠️ NEEDS ATTENTION\n";
                $body .= "📋 {$client} {$course} — Quiz\n";
                $body .= "Status: ○ Not Started ⚠️ NEEDS ATTENTION\n";
                $hasWarnings = true;
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

function sendEmailSmtp(array $config, string $toEmail, string $subject, string $message): array
{
    $required = ['smtpHost', 'smtpPort', 'smtpSecure', 'smtpUser', 'smtpPass', 'fromEmail'];
    foreach ($required as $key) {
        if (!isset($config[$key]) || trim((string) $config[$key]) === '') {
            return ['ok' => false, 'error' => "missing smtp config: {$key}"];
        }
    }

    $port = (int) $config['smtpPort'];
    $secure = strtolower(trim((string) $config['smtpSecure']));
    $host = trim((string) $config['smtpHost']);
    $user = trim((string) $config['smtpUser']);
    $pass = (string) $config['smtpPass'];
    $fromEmail = trim((string) $config['fromEmail']);
    $fromName = trim((string) ($config['fromName'] ?? 'Jessica Tracker'));

    $transport = $secure === 'ssl' ? "ssl://{$host}:{$port}" : "tcp://{$host}:{$port}";
    $socket = @stream_socket_client($transport, $errno, $errstr, 20, STREAM_CLIENT_CONNECT);
    if (!$socket) {
        return ['ok' => false, 'error' => "smtp connect failed: {$errstr} ({$errno})"];
    }

    stream_set_timeout($socket, 20);
    $read = smtpRead($socket);
    if (!smtpOk($read, [220])) {
        fclose($socket);
        return ['ok' => false, 'error' => "smtp greeting failed: {$read}"];
    }

    $hostname = gethostname() ?: 'localhost';
    if (!smtpCmd($socket, "EHLO {$hostname}", [250], $reply)) {
        fclose($socket);
        return ['ok' => false, 'error' => "ehlo failed: {$reply}"];
    }

    if ($secure === 'tls') {
        if (!smtpCmd($socket, 'STARTTLS', [220], $reply)) {
            fclose($socket);
            return ['ok' => false, 'error' => "starttls failed: {$reply}"];
        }
        if (!stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
            fclose($socket);
            return ['ok' => false, 'error' => 'failed enabling tls'];
        }
        if (!smtpCmd($socket, "EHLO {$hostname}", [250], $reply)) {
            fclose($socket);
            return ['ok' => false, 'error' => "ehlo after tls failed: {$reply}"];
        }
    }

    if (!smtpCmd($socket, 'AUTH LOGIN', [334], $reply)) {
        fclose($socket);
        return ['ok' => false, 'error' => "auth login failed: {$reply}"];
    }
    if (!smtpCmd($socket, base64_encode($user), [334], $reply)) {
        fclose($socket);
        return ['ok' => false, 'error' => "smtp user rejected: {$reply}"];
    }
    if (!smtpCmd($socket, base64_encode($pass), [235], $reply)) {
        fclose($socket);
        return ['ok' => false, 'error' => "smtp password rejected: {$reply}"];
    }

    if (!smtpCmd($socket, "MAIL FROM:<{$fromEmail}>", [250], $reply)) {
        fclose($socket);
        return ['ok' => false, 'error' => "mail from failed: {$reply}"];
    }
    if (!smtpCmd($socket, "RCPT TO:<{$toEmail}>", [250, 251], $reply)) {
        fclose($socket);
        return ['ok' => false, 'error' => "rcpt failed: {$reply}"];
    }
    if (!smtpCmd($socket, 'DATA', [354], $reply)) {
        fclose($socket);
        return ['ok' => false, 'error' => "data failed: {$reply}"];
    }

    $headers = [];
    $headers[] = 'From: ' . formatAddress($fromName, $fromEmail);
    $headers[] = 'To: ' . formatAddress('', $toEmail);
    $headers[] = 'Subject: ' . encodeHeader($subject);
    $headers[] = 'MIME-Version: 1.0';
    $headers[] = 'Content-Type: text/plain; charset=UTF-8';
    $headers[] = 'Content-Transfer-Encoding: 8bit';
    $headers[] = 'Date: ' . date(DATE_RFC2822);

    $body = preg_replace("/\r\n|\r|\n/", "\r\n", $message) ?? $message;
    $data = implode("\r\n", $headers) . "\r\n\r\n" . dotStuff($body) . "\r\n.";

    fwrite($socket, $data . "\r\n");
    $reply = smtpRead($socket);
    if (!smtpOk($reply, [250])) {
        fclose($socket);
        return ['ok' => false, 'error' => "message rejected: {$reply}"];
    }

    smtpCmd($socket, 'QUIT', [221], $reply);
    fclose($socket);
    return ['ok' => true];
}

function smtpCmd($socket, string $command, array $okCodes, ?string &$reply = null): bool
{
    fwrite($socket, $command . "\r\n");
    $reply = smtpRead($socket);
    return smtpOk($reply, $okCodes);
}

function smtpRead($socket): string
{
    $data = '';
    while (!feof($socket)) {
        $line = fgets($socket, 2048);
        if ($line === false) {
            break;
        }
        $data .= $line;
        if (preg_match('/^\d{3}\s/', $line)) {
            break;
        }
    }
    return trim($data);
}

function smtpOk(string $reply, array $okCodes): bool
{
    if (!preg_match('/^(\d{3})/', $reply, $m)) {
        return false;
    }
    return in_array((int) $m[1], $okCodes, true);
}

function formatAddress(string $name, string $email): string
{
    $email = trim($email);
    if ($name === '') {
        return "<{$email}>";
    }
    return encodeHeader($name) . " <{$email}>";
}

function encodeHeader(string $value): string
{
    if (preg_match('/[^\x20-\x7E]/', $value)) {
        return '=?UTF-8?B?' . base64_encode($value) . '?=';
    }
    return $value;
}

function dotStuff(string $text): string
{
    return preg_replace('/(?m)^\./', '..', $text) ?? $text;
}

function extractDayParam(): string
{
    // Direct query/form keys first.
    $candidates = [
        $_GET['day'] ?? null,
        $_GET['Day'] ?? null,
        $_GET['DAY'] ?? null,
        $_GET['amp;day'] ?? null, // Handles badly escaped URLs that contain &amp;day=...
        $_REQUEST['day'] ?? null,
        $_POST['day'] ?? null,
    ];

    foreach ($candidates as $value) {
        if ($value !== null && trim((string) $value) !== '') {
            return (string) $value;
        }
    }

    // Fallback: parse raw query string defensively.
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

    // Path fallback: /reminder-cron.php/monday or /reminder-cron.php/friday
    $pathInfo = (string) ($_SERVER['PATH_INFO'] ?? '');
    if ($pathInfo !== '') {
        $parts = array_values(array_filter(explode('/', trim($pathInfo, '/'))));
        if (count($parts) > 0) {
            return (string) $parts[count($parts) - 1];
        }
    }

    // REQUEST_URI fallback when PATH_INFO is unavailable.
    $uriPath = (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH);
    if ($uriPath !== '') {
        $segments = array_values(array_filter(explode('/', trim($uriPath, '/'))));
        if (count($segments) >= 2 && str_ends_with($segments[count($segments) - 2], '.php')) {
            return (string) $segments[count($segments) - 1];
        }
    }

    return '';
}
