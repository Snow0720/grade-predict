<?php
declare(strict_types=1);
require dirname(__DIR__) . '/includes/scholarship_summary.php';
$programs = [['id' => 1, 'code' => 'BSIT'], ['id' => 2, 'code' => 'BSHM'], ['id' => 3, 'code' => 'BSED']];
$rows = [
    ['program_id' => 1, 'student_id' => 10, 'category_id' => 1, 'status' => 'assigned', 'year' => 1, 'semester' => 'First'],
    ['program_id' => 1, 'student_id' => 10, 'category_id' => 2, 'status' => 'assigned', 'year' => 1, 'semester' => 'Second'],
    ['program_id' => 2, 'student_id' => 11, 'category_id' => 1, 'status' => 'revoked', 'year' => 2, 'semester' => 'First'],
];
function check_summary(bool $condition): void {
    if (!$condition) throw new RuntimeException('Scholarship summary mismatch');
}
$all = scholarship_summary($rows, $programs);
check_summary($all['active'] === 2 && $all['beneficiaries'] === 2 && $all['categories'] === 2);
check_summary($all['programs'][0]['beneficiaries'] === 1 && $all['programs'][0]['categories'] === 2);
check_summary($all['programs'][1]['active'] === 0 && $all['programs'][1]['beneficiaries'] === 1);
check_summary($all['programs'][2]['active'] === 0);
foreach ([[1, 'First'], [1, ''], [0, 'First'], [2, 'Second']] as [$year, $semester]) {
    $filtered = array_filter($rows, fn($row) => (!$year || $row['year'] === $year) && (!$semester || $row['semester'] === $semester));
    $summary = scholarship_summary($filtered, $programs);
    check_summary($summary['active'] === array_sum(array_column($summary['programs'], 'active')));
    check_summary($summary['beneficiaries'] === array_sum(array_column($summary['programs'], 'beneficiaries')));
    if ($year === 1 && $semester === 'First') check_summary($summary['active'] === 1 && $summary['categories'] === 1);
    if ($year === 2 && $semester === 'Second') check_summary($summary['active'] === 0 && $summary['beneficiaries'] === 0 && $summary['categories'] === 0);
}
echo "Scholarship totals, deduplication, program breakdowns and period selection checks passed\n";
