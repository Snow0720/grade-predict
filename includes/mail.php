<?php
declare(strict_types=1);

function mail_is_configured(): bool
{
    return trim((string) ($_ENV['MAIL_HOST'] ?? '')) !== ''
        && trim((string) ($_ENV['MAIL_USER'] ?? '')) !== ''
        && trim((string) ($_ENV['MAIL_PASS'] ?? '')) !== '';
}

function absolute_url(string $path, array $query = []): string
{
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https')
        || ((int) ($_SERVER['SERVER_PORT'] ?? 0) === 443);
    $host = (string) ($_SERVER['HTTP_HOST'] ?? 'localhost');
    return ($https ? 'https://' : 'http://') . $host . url($path, $query);
}

function send_app_mail(string $to, string $subject, string $body): void
{
    $host = trim((string) ($_ENV['MAIL_HOST'] ?? ''));
    $port = (int) ($_ENV['MAIL_PORT'] ?? 587);
    $user = trim((string) ($_ENV['MAIL_USER'] ?? ''));
    $pass = (string) ($_ENV['MAIL_PASS'] ?? '');
    $from = trim((string) ($_ENV['MAIL_FROM'] ?? $user));
    $fromName = trim((string) ($_ENV['MAIL_FROM_NAME'] ?? SCHOOL_NAME));
    $encryption = strtolower(trim((string) ($_ENV['MAIL_ENCRYPTION'] ?? 'tls')));

    if ($host === '' || $user === '' || $pass === '' || $from === '') {
        throw new RuntimeException('Recovery email is not configured yet. Ask the college office to reset your password.');
    }
    if (!filter_var($to, FILTER_VALIDATE_EMAIL) || !filter_var($from, FILTER_VALIDATE_EMAIL)) {
        throw new RuntimeException('The recovery email address is not valid.');
    }

    $remote = ($encryption === 'ssl' ? 'ssl://' : 'tcp://') . $host . ':' . $port;
    $errno = 0;
    $errstr = '';
    $socket = @stream_socket_client($remote, $errno, $errstr, 20, STREAM_CLIENT_CONNECT);
    if (!$socket) {
        throw new RuntimeException('The recovery email could not be sent. Ask the college office to reset your password.');
    }
    stream_set_timeout($socket, 20);

    try {
        smtp_expect($socket, [220]);
        smtp_command($socket, 'EHLO ' . smtp_client_name(), [250]);
        if ($encryption === 'tls') {
            smtp_command($socket, 'STARTTLS', [220]);
            $crypto = stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);
            if ($crypto !== true) {
                throw new RuntimeException('The recovery email could not be sent. Ask the college office to reset your password.');
            }
            smtp_command($socket, 'EHLO ' . smtp_client_name(), [250]);
        }
        smtp_command($socket, 'AUTH LOGIN', [334]);
        smtp_command($socket, base64_encode($user), [334]);
        smtp_command($socket, base64_encode($pass), [235]);
        smtp_command($socket, 'MAIL FROM:<' . $from . '>', [250]);
        smtp_command($socket, 'RCPT TO:<' . $to . '>', [250, 251]);
        smtp_command($socket, 'DATA', [354]);

        $encodedSubject = '=?UTF-8?B?' . base64_encode($subject) . '?=';
        $safeName = str_replace(['"', "\r", "\n"], '', $fromName);
        $message = implode("\r\n", [
            'From: "' . $safeName . '" <' . $from . '>',
            'To: <' . $to . '>',
            'Subject: ' . $encodedSubject,
            'MIME-Version: 1.0',
            'Content-Type: text/plain; charset=UTF-8',
            'Content-Transfer-Encoding: 8bit',
            '',
            smtp_dot_stuff($body),
            '',
        ]);
        fwrite($socket, $message . "\r\n.\r\n");
        smtp_expect($socket, [250]);
        smtp_command($socket, 'QUIT', [221]);
    } finally {
        fclose($socket);
    }
}

function smtp_client_name(): string
{
    $host = (string) ($_SERVER['SERVER_NAME'] ?? 'localhost');
    $host = preg_replace('/[^A-Za-z0-9.-]/', '', $host) ?: 'localhost';
    return $host;
}

function smtp_dot_stuff(string $body): string
{
    $body = str_replace(["\r\n", "\r"], "\n", $body);
    $lines = explode("\n", $body);
    foreach ($lines as &$line) {
        if (str_starts_with($line, '.')) {
            $line = '.' . $line;
        }
    }
    unset($line);
    return implode("\r\n", $lines);
}

function smtp_command($socket, string $command, array $ok): string
{
    fwrite($socket, $command . "\r\n");
    return smtp_expect($socket, $ok);
}

function smtp_expect($socket, array $ok): string
{
    $response = '';
    while (($line = fgets($socket, 1024)) !== false) {
        $response .= $line;
        if (isset($line[3]) && $line[3] === ' ') {
            break;
        }
    }
    $code = (int) substr($response, 0, 3);
    if (!in_array($code, $ok, true)) {
        error_log('SMTP response: ' . trim($response));
        throw new RuntimeException('The recovery email could not be sent. Ask the college office to reset your password.');
    }
    return $response;
}

function issue_student_reset(array $student): void
{
    ensure_student_auth_columns();
    $email = strtolower(trim((string) ($student['email'] ?? '')));
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return;
    }
    $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    $hash = hash('sha256', $code);
    db()->prepare('DELETE FROM student_password_resets WHERE student_id = ?')->execute([(int) $student['id']]);
    db()->prepare('INSERT INTO student_password_resets (student_id, token_hash, expires_at) VALUES (?, ?, DATE_ADD(NOW(), INTERVAL 15 MINUTE))')
        ->execute([(int) $student['id'], $hash]);
    $body = "Hello " . trim((string) ($student['full_name'] ?? 'student')) . ",\n\n"
        . "Your student portal password code is:\n\n"
        . $code . "\n\n"
        . "Enter this code on the forgot password page for student number " . (string) $student['student_number'] . ".\n"
        . "The code expires in 15 minutes.\n\n"
        . "If you did not ask for this, you can ignore this email. Your password will stay the same.\n";
    send_app_mail($email, 'Student portal password code', $body);
}

function student_for_reset_code(string $studentNumber, string $code): array
{
    ensure_student_auth_columns();
    $studentNumber = trim($studentNumber);
    $code = preg_replace('/\D/', '', $code) ?? '';
    if (!preg_match('/^\d{6}$/', $code)) {
        throw new RuntimeException('Enter the 6-digit code from the email.');
    }
    $stmt = db()->prepare('SELECT * FROM students WHERE student_number = ?');
    $stmt->execute([$studentNumber]);
    $student = $stmt->fetch();
    if (!$student) {
        throw new RuntimeException('That code is not valid or it has expired.');
    }
    $resetStmt = db()->prepare(
        'SELECT * FROM student_password_resets
         WHERE student_id = ? AND used_at IS NULL AND expires_at > NOW()
         ORDER BY id DESC LIMIT 1'
    );
    $resetStmt->execute([(int) $student['id']]);
    $reset = $resetStmt->fetch();
    if (!$reset) {
        throw new RuntimeException('That code is not valid or it has expired.');
    }
    if ((int) $reset['attempts'] >= 5) {
        throw new RuntimeException('Too many tries. Request a new code.');
    }
    if (!hash_equals((string) $reset['token_hash'], hash('sha256', $code))) {
        db()->prepare('UPDATE student_password_resets SET attempts = attempts + 1 WHERE id = ?')
            ->execute([(int) $reset['id']]);
        throw new RuntimeException('That code is not valid or it has expired.');
    }
    $student['reset_id'] = (int) $reset['id'];
    return $student;
}

function student_from_reset_token(string $token): ?array
{
    ensure_student_auth_columns();
    if (!preg_match('/^[a-f0-9]{64}$/', $token)) {
        return null;
    }
    $stmt = db()->prepare(
        'SELECT st.*, r.id AS reset_id
         FROM student_password_resets r
         JOIN students st ON st.id = r.student_id
         WHERE r.token_hash = ? AND r.used_at IS NULL AND r.expires_at > NOW()
         LIMIT 1'
    );
    $stmt->execute([hash('sha256', $token)]);
    $row = $stmt->fetch();
    return $row ?: null;
}
