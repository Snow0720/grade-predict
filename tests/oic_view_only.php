<?php
declare(strict_types=1);
require dirname(__DIR__) . '/includes/bootstrap.php';
require dirname(__DIR__) . '/includes/auth.php';
require dirname(__DIR__) . '/includes/layout.php';
require dirname(__DIR__) . '/includes/workflow.php';

$checks = 0;
function oic_check(bool $ok, string $label): void
{
    global $checks;
    if (!$ok) throw new RuntimeException($label);
    $checks++;
}
$oic = ['portal' => 'admin', 'roles' => ['college_oic']];
$permissions = ['manage_users', 'manage_faculty', 'manage_academics', 'import', 'rules', 'settings', 'queue', 'scholarships', 'reports', 'evaluations', 'recognition', 'analytics', 'audit', 'students_write', 'finalize'];
oic_check(can($oic, 'students_read'), 'OIC can view students and their grades');
foreach ($permissions as $permission) oic_check(!can($oic, $permission), "OIC cannot $permission");
oic_check(array_column(nav_items($oic), 'id') === ['students'], 'Only student viewing appears in OIC navigation');
foreach (module_groups() as $group) oic_check(module_visible_items($oic, $group) === [], 'OIC has no management or dashboard tabs');
oic_check(home_path('admin', $oic) === 'admin/users.php?view=students', 'OIC lands on the consolidated student list');
oic_check(nav_items($oic)[0]['href'] === home_path('admin', $oic), 'OIC navigation opens the consolidated student list');
foreach (['GET', 'HEAD'] as $method) {
    oic_check(oic_request_allowed('admin/students.php', $method), 'Student list can be browsed');
    oic_check(oic_request_allowed('admin/users.php', $method, 'students'), 'Student list can be browsed through the consolidated route');
    oic_check(oic_request_allowed('admin/student.php', $method), 'Grades can be browsed through the original route');
    oic_check(oic_request_allowed('admin/users.php', $method, 'student-record'), 'Grades can be browsed through the consolidated route');
}
foreach (['POST', 'PUT', 'PATCH', 'DELETE'] as $method) {
    foreach (['admin/students.php' => [''], 'admin/student.php' => [''], 'admin/users.php' => ['students', 'student-record']] as $path => $views) {
        foreach ($views as $view) {
            oic_check(!oic_request_allowed($path, $method, $view), 'All record mutations are blocked');
        }
    }
}
foreach (['admin/dashboard.php', 'admin/analytics.php', 'admin/reports.php', 'admin/audit.php', 'admin/rules.php', 'admin/scale.php', 'admin/queue.php', 'admin/review.php', 'admin/recognition.php', 'admin/recognition_review.php', 'admin/recognition_doc.php', 'admin/faculty.php', 'admin/import.php', 'admin/users.php', 'admin/settings.php', 'admin/academics.php', 'professor/class.php', 'api/score.php', 'api/draft.php', 'password.php', 'notifications.php'] as $path) {
    oic_check(!oic_request_allowed($path, 'GET'), "Direct route $path cannot expose another module");
    oic_check(!oic_request_allowed($path, 'POST'), "Direct mutation $path is denied");
}
foreach (['analytics', 'reports', 'logs', 'criteria', 'review', 'recognition', 'faculty', 'import', 'grade-equivalent'] as $view) {
    oic_check(!oic_request_allowed('admin/users.php', 'GET', $view), 'Changing the view parameter cannot bypass OIC restrictions');
}
oic_check(oic_request_allowed('logout.php', 'GET'), 'OIC can sign out');
oic_check(!user_can_review($oic, [], 'chair_verified'), 'OIC cannot approve queued grades');
oic_check(!can(['roles' => ['college_oic', 'system_admin']], 'students_write'), 'Additional roles cannot bypass the OIC restriction');
foreach (['system_admin', 'program_chair', 'osa'] as $role) {
    $user = ['portal' => 'admin', 'roles' => [$role]];
    $denied = match ($role) {
        'program_chair' => ['import', 'scholarships', 'reports'],
        'osa' => ['import', 'reports', 'recognition', 'queue'],
        default => ['import'],
    };
    foreach (array_merge($permissions, ['students_read']) as $permission) {
        oic_check(can($user, $permission) === !in_array($permission, $denied, true), "$role has the expected access to $permission");
    }
    oic_check(count(nav_items($user)) === 4, "$role retains the main modules with Settings in the footer");
    oic_check(home_path('admin', $user) === 'admin/dashboard.php', "$role retains the dashboard landing page");
}
echo "OIC view-only permissions: $checks checks passed\n";
