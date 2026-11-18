<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/auth.php';

db_or_setup();
$user = require_portal('admin');
require_permission($user, 'analytics');
$query = [];
foreach (['program', 'year_level', 'semester'] as $filter) {
    $value = $_GET[$filter] ?? $_GET['trend_' . $filter] ?? null;
    if (is_scalar($value) && (string) $value !== '') $query['trend_' . $filter] = $value;
}
redirect('admin/dashboard.php#population-trends', $query);
