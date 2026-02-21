<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'method_not_allowed']);
    exit;
}

require_once __DIR__ . '/mail-service.php';

try {
    appSecrets(); // validate secrets early for clear API errors
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
    exit;
}

$raw = file_get_contents('php://input');
$payload = json_decode((string) $raw, true);

$toEmail = trim((string) ($payload['to_email'] ?? ''));
$recipientKey = strtolower(trim((string) ($payload['recipient_key'] ?? '')));
$subject = trim((string) ($payload['subject'] ?? ''));
$message = (string) ($payload['message'] ?? '');

if ($subject === '' || $message === '') {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'missing_fields']);
    exit;
}

if ($toEmail === '') {
    if ($recipientKey === 'jess' || $recipientKey === 'jessica') {
        $toEmail = appDefaultJessicaEmail();
    } elseif ($recipientKey === 'admin') {
        $toEmail = appDefaultAdminEmail();
    }
}

if ($toEmail === '') {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'missing_recipient']);
    exit;
}

$sent = appSendEmail($toEmail, $subject, $message);
if (!$sent['ok']) {
    http_response_code(502);
    echo json_encode(['ok' => false, 'error' => $sent['error'] ?? 'send_failed']);
    exit;
}

echo json_encode(['ok' => true]);
