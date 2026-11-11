<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/auth.php';

$student = current_student();
if ($student) {
    try {
        audit(null, 'student_logout', 'student', (string) $student['id'], 'Student signed out');
    } catch (Throwable $e) {}
}
$_SESSION = [];
if (ini_get('session.use_cookies')) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], (bool) $params['secure'], (bool) $params['httponly']);
}
session_destroy();
redirect('student/login.php');
