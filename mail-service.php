<?php
declare(strict_types=1);

require_once __DIR__ . '/mailer.php';

function appSecrets(): array
{
    static $cache = null;
    if (is_array($cache)) {
        return $cache;
    }
    $cache = loadSecrets(__DIR__ . '/secrets.json');
    return $cache;
}

function appSendEmail(string $toEmail, string $subject, string $message): array
{
    return sendEmailSmtp(appSecrets(), $toEmail, $subject, $message);
}

function appCronSecret(): string
{
    $s = appSecrets();
    return trim((string) ($s['cronSecret'] ?? ''));
}

function appDefaultJessicaEmail(): string
{
    $s = appSecrets();
    return trim((string) ($s['defaultJessicaEmail'] ?? ''));
}

function appDefaultAdminEmail(): string
{
    $s = appSecrets();
    return trim((string) ($s['defaultAdminEmail'] ?? ''));
}
