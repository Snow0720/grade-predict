<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/auth.php';
$user = require_user();
$portal = $user['portal'] ?? '';
redirect($portal === 'student' ? 'student/dashboard.php' : ($portal === 'professor' ? 'professor/dashboard.php?notifications=open' : 'admin/dashboard.php?notifications=open'));
