<?php
declare(strict_types=1);

function loadSecrets(string $path): array
{
    if (!is_file($path)) {
        throw new RuntimeException('secrets.json not found');
    }

    $raw = file_get_contents($path);
    if ($raw === false || trim($raw) === '') {
        throw new RuntimeException('secrets.json is empty');
    }

    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        throw new RuntimeException('secrets.json is invalid JSON');
    }

    return $decoded;
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
