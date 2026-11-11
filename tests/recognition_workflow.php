<?php
declare(strict_types=1);
require dirname(__DIR__) . '/includes/bootstrap.php';
require dirname(__DIR__) . '/includes/auth.php';
require dirname(__DIR__) . '/includes/recognition.php';

$failed = 0;
function recognition_check(bool $ok, string $label): void
{
    global $failed;
    echo ($ok ? 'ok  ' : 'FAIL ') . $label . PHP_EOL;
    if (!$ok) {
        $failed++;
    }
}

$pdo = db();
$pdo->beginTransaction();
try {
    $pdo->prepare(
        'INSERT INTO students (student_number, full_name, student_type, program_id, year_level)
         VALUES ("WF-TEMP", "Workflow Test Student", "Regular", 1, "4th Year")'
    )->execute();
    $studentId = (int) $pdo->lastInsertId();
    $semesterId = (int) $pdo->query('SELECT id FROM semesters ORDER BY is_current DESC, id DESC LIMIT 1')->fetchColumn();
    $pdo->prepare(
        'INSERT INTO dl_pl_evaluations (student_id, semester_id, basis, gwa, status, reason)
         VALUES (?, ?, "official", 1.50, "Possible DL", "Workflow test")'
    )->execute([$studentId, $semesterId]);
    $pdo->prepare(
        'INSERT INTO recognition_cases
            (student_id, semester_id, program_id, list_type, gwa, standing, stage)
         VALUES (?, ?, 1, "DL", 1.50, "Possible DL", "chair_review")'
    )->execute([$studentId, $semesterId]);
    $caseId = (int) $pdo->lastInsertId();

    $chair = ['id' => 4, 'roles' => ['program_chair'], 'role_rows' => [['code' => 'program_chair', 'program_id' => 1]]];
    $wrongChair = ['id' => 5, 'roles' => ['program_chair'], 'role_rows' => [['code' => 'program_chair', 'program_id' => 2]]];
    $admin = ['id' => 1, 'roles' => ['system_admin'], 'role_rows' => [['code' => 'system_admin', 'program_id' => null]]];

    $blocked = false;
    try {
        recognition_act($wrongChair, $semesterId, [$caseId], 'chair_reject', 'Outside program.');
    } catch (RuntimeException) {
        $blocked = true;
    }
    recognition_check($blocked, 'Program Chair cannot act on another program');

    recognition_act($chair, $semesterId, [$caseId], 'chair_return', 'Needs another look.');
    $stage = $pdo->query("SELECT stage FROM recognition_cases WHERE id = $caseId")->fetchColumn();
    recognition_check($stage === 'chair_returned', 'Program Chair can return a candidate');

    $pdo->prepare('UPDATE recognition_cases SET stage = "chair_review" WHERE id = ?')->execute([$caseId]);
    recognition_endorse($chair, $semesterId, [$studentId], 'Endorsed for recognition.');
    $stage = $pdo->query("SELECT stage FROM recognition_cases WHERE id = $caseId")->fetchColumn();
    recognition_check($stage === 'chair_endorsed', 'Program Chair endorsement finalizes the recognition record');

    $blocked = false;
    try {
        recognition_act($admin, $semesterId, [$caseId], 'chair_reject', 'Admin should not act.');
    } catch (RuntimeException) {
        $blocked = true;
    }
    recognition_check($blocked, 'System Admin cannot perform recognition approval decisions');

    $events = (int) $pdo->query("SELECT COUNT(*) FROM recognition_events WHERE case_id = $caseId")->fetchColumn();
    recognition_check($events >= 2, 'Recognition decisions leave an immutable history');
} finally {
    $pdo->rollBack();
}

if ($failed > 0) {
    exit(1);
}
echo "recognition workflow ok" . PHP_EOL;
