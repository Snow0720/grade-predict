<?php
declare(strict_types=1);
require dirname(__DIR__) . '/includes/bootstrap.php';
require dirname(__DIR__) . '/includes/auth.php';
require dirname(__DIR__) . '/includes/layout.php';
require dirname(__DIR__) . '/includes/dashboard_charts.php';

$checks = 0;
function module_check(bool $condition, string $label): void
{
    global $checks;
    if (!$condition) throw new RuntimeException($label);
    $checks++;
}

foreach (array_diff(office_roles(), ['college_oic']) as $role) {
    $user = ['portal' => 'admin', 'roles' => [$role]];
    $items = nav_items($user);
    module_check(array_column($items, 'id') === ['dash', 'evaluations', 'users', 'academics'], "$role has four main modules with Settings in the footer");
    $userTabs = array_column(module_visible_items($user, module_groups()['users']), 'href', 'id');
    module_check(($userTabs['student-accounts'] ?? null) === 'admin/users.php?view=students', "$role finds Student Records under User Management");
    $dashboardItems = array_column(module_visible_items($user, module_groups()['dash']), 'id');
    module_check(in_array('reports', $dashboardItems, true) === ($role === 'system_admin'), "$role has the expected Dashboard Reports tab visibility");
    foreach (module_groups() as $group) {
        foreach (module_visible_items($user, $group) as $child) {
            module_check($child['permission'] === null || can($user, $child['permission']), 'Tabs retain permissions');
        }
    }
}
module_check(array_column(nav_items(['portal' => 'student']), 'id') === ['grades'], 'Student navigation unchanged');
module_check(array_column(nav_items(['portal' => 'professor']), 'id') === ['dash', 'classes'], 'Faculty navigation unchanged');
foreach (module_groups() as $id => $group) {
    $visible = module_visible_items(['roles' => ['professor']], $group);
    module_check($id === 'dash' ? count($visible) === 1 : $visible === [], 'Unauthorized submodules hidden');
}
foreach (module_routes() as $legacy => [$parent, $view]) {
    $link = url($legacy, ['program' => 2, 'page' => 3]);
    parse_str((string) parse_url($link, PHP_URL_QUERY), $query);
    module_check(str_ends_with((string) parse_url($link, PHP_URL_PATH), $parent), "$legacy routes to its parent");
    module_check($query === ['program' => '2', 'page' => '3', 'view' => $view], 'Filter and pagination state retained');
    module_check(module_controller($parent, $view) === basename($legacy), 'Parent dispatches original controller');
}
$link = url('admin/reports.php?type=gwa&program=2#output', ['export' => 'csv']);
parse_str((string) parse_url($link, PHP_URL_QUERY), $query);
module_check($query['type'] === 'gwa' && $query['export'] === 'csv' && $query['program'] === '2' && $query['view'] === 'reports', 'CSV export preserves its report and scope');
module_check(parse_url($link, PHP_URL_FRAGMENT) === 'output', 'Anchors retained');
module_check(module_controller('admin/dashboard.php', '../../config/database') === null, 'Traversal is never a controller');
module_check(module_controller('admin/dashboard.php', 'criteria') === null, 'Cannot dispatch another parent submodule');
module_check(module_controller('admin/users.php', 'students') === 'students.php', 'Student accounts reuse the masterlist');
ob_start();
dashboard_bars('empty', 'Empty chart', ['Empty' => 0]);
dashboard_donut('empty-mix', 'Empty mix', ['PL' => 0, 'DL' => 0]);
dashboard_trend([], [], []);
dashboard_trend(['Semester <one>'], [2], [1]);
$html = ob_get_clean();
module_check(!str_contains($html, 'NAN') && !str_contains($html, 'INF'), 'Zero and single-semester charts have valid geometry');
module_check(str_contains($html, 'Semester &lt;one&gt;'), 'Chart labels are escaped');
module_check(!str_contains($html, '<script'), 'Charts work without a script or external service');
echo "module navigation and charts: $checks checks passed\n";
