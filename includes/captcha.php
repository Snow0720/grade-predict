<?php
declare(strict_types=1);

function captcha_field(): string
{
    if (RECAPTCHA_SITE_KEY === '') {
        return '';
    }
    return '<div class="g-recaptcha" data-sitekey="' . h(RECAPTCHA_SITE_KEY) . '"></div>'
        . '<script src="https://www.google.com/recaptcha/api.js" async defer></script>';
}

function captcha_valid(): bool
{
    if (RECAPTCHA_SECRET_KEY === '' || RECAPTCHA_SITE_KEY === '') {
        return true;
    }
    $response = trim((string) ($_POST['g-recaptcha-response'] ?? ''));
    if ($response === '') {
        return false;
    }
    $payload = http_build_query([
        'secret' => RECAPTCHA_SECRET_KEY,
        'response' => $response,
        'remoteip' => (string) ($_SERVER['REMOTE_ADDR'] ?? ''),
    ]);
    $raw = captcha_post('https://www.google.com/recaptcha/api/siteverify', $payload);
    if ($raw === null) {
        // If outbound network requests are blocked by the host (e.g. InfinityFree free tier firewall),
        // accept the client token so users are not trapped from signing in.
        error_log('reCAPTCHA siteverify unreachable from server; accepting client token.');
        return true;
    }
    $data = json_decode($raw, true);
    return is_array($data) && !empty($data['success']);
}

function captcha_post(string $url, string $payload): ?string
{
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        if ($ch === false) {
            return null;
        }
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 7,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => 0,
        ]);
        $raw = curl_exec($ch);
        curl_close($ch);
        return is_string($raw) && $raw !== '' ? $raw : null;
    }

    $context = stream_context_create([
        'http' => [
            'method' => 'POST',
            'header' => "Content-Type: application/x-www-form-urlencoded\r\n",
            'content' => $payload,
            'timeout' => 7,
        ],
        'ssl' => [
            'verify_peer' => false,
            'verify_peer_name' => false,
        ],
    ]);
    $raw = @file_get_contents($url, false, $context);
    return is_string($raw) && $raw !== '' ? $raw : null;
}
