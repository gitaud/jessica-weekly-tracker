<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

$primaryDir = __DIR__ . '/data';
$primaryFile = $primaryDir . '/tracker-data.json';
$fallbackFile = '/tmp/jessica-tracker-data.json';

function readDataFile(string $primaryFile, string $fallbackFile): array
{
    foreach ([$primaryFile, $fallbackFile] as $file) {
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

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $decoded = readDataFile($primaryFile, $fallbackFile);
    if ($decoded === []) {
        echo json_encode(['ok' => true, 'data' => new stdClass()]);
        exit;
    }

    echo json_encode(['ok' => true, 'data' => $decoded]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = file_get_contents('php://input');
    $payload = json_decode((string) $input, true);
    $data = $payload['data'] ?? null;

    if (!is_array($data)) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'invalid_payload']);
        exit;
    }

    $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    if ($json === false) {
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => 'encode_failed']);
        exit;
    }

    if (!is_dir($primaryDir)) {
        @mkdir($primaryDir, 0775, true);
    }

    $written = @file_put_contents($primaryFile, $json, LOCK_EX);
    if ($written === false) {
        // Fallback for environments where web root is read-only.
        $written = @file_put_contents($fallbackFile, $json, LOCK_EX);
    }

    if ($written === false) {
        http_response_code(500);
        echo json_encode([
            'ok' => false,
            'error' => 'write_failed',
            'paths' => [$primaryFile, $fallbackFile]
        ]);
        exit;
    }

    echo json_encode(['ok' => true]);
    exit;
}

http_response_code(405);
echo json_encode(['ok' => false, 'error' => 'method_not_allowed']);
