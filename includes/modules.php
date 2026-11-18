<?php
declare(strict_types=1);

/** Canonical parent routes. Original controllers remain available for existing links. */
function module_routes(): array
{
    return [
        'admin/analytics.php' => ['admin/dashboard.php', 'analytics'],
        'admin/reports.php' => ['admin/dashboard.php', 'reports'],
        'admin/audit.php' => ['admin/dashboard.php', 'logs'],
        'admin/rules.php' => ['admin/evaluations.php', 'criteria'],
        'admin/scholarships.php' => ['admin/evaluations.php', 'scholarships'],
        'admin/queue.php' => ['admin/evaluations.php', 'review'],
        'admin/review.php' => ['admin/evaluations.php', 'review-record'],
        'admin/recognition.php' => ['admin/evaluations.php', 'recognition'],
        'admin/recognition_review.php' => ['admin/evaluations.php', 'recognition-record'],
        'admin/faculty.php' => ['admin/users.php', 'faculty'],
        'admin/import.php' => ['admin/users.php', 'import'],
        'admin/students.php' => ['admin/users.php', 'students'],
        'admin/student.php' => ['admin/users.php', 'student-record'],
        'admin/scale.php' => ['admin/settings.php', 'grade-equivalent'],
    ];
}

function module_groups(): array
{
    return [
        'dash' => ['label' => 'Dashboard', 'icon' => 'dashboard', 'items' => [
            ['id' => 'dash', 'label' => 'Dashboard', 'href' => 'admin/dashboard.php', 'permission' => null, 'tab' => false],
            ['id' => 'reports', 'label' => 'Reports', 'href' => 'admin/reports.php', 'permission' => 'reports'],
            ['id' => 'audit', 'label' => 'Logs', 'href' => 'admin/audit.php', 'permission' => 'audit'],
        ]],
        'evaluations' => ['label' => 'DL / PL', 'icon' => 'award', 'items' => [
            ['id' => 'evaluations', 'label' => 'Candidates', 'href' => 'admin/evaluations.php', 'permission' => 'evaluations'],
            ['id' => 'rules', 'label' => 'Criteria', 'href' => 'admin/rules.php', 'permission' => 'rules'],
            ['id' => 'scholarships', 'label' => 'Scholarships', 'href' => 'admin/scholarships.php', 'permission' => 'scholarships'],
            ['id' => 'queue', 'label' => 'Review', 'href' => 'admin/queue.php', 'permission' => 'queue'],
            ['id' => 'recognition', 'label' => 'Recognition', 'href' => 'admin/recognition.php', 'permission' => 'recognition'],
        ]],
        'users' => ['label' => 'User Management', 'icon' => 'users', 'items' => [
            ['id' => 'users', 'label' => 'Accounts', 'href' => 'admin/users.php', 'permission' => 'manage_users'],
            ['id' => 'faculty', 'label' => 'Faculty', 'href' => 'admin/faculty.php', 'permission' => 'manage_faculty'],
            ['id' => 'student-accounts', 'label' => 'Student Records', 'href' => 'admin/users.php?view=students', 'permission' => 'manage_users'],
        ]],
        'settings' => ['label' => 'Settings', 'icon' => 'settings', 'items' => [
            ['id' => 'settings', 'label' => 'General', 'href' => 'admin/settings.php', 'permission' => 'settings'],
            ['id' => 'scale', 'label' => 'Grade Equivalent', 'href' => 'admin/scale.php', 'permission' => 'rules'],
        ]],
    ];
}

function module_visible_items(array $user, array $group): array
{
    if (is_view_only_oic($user)) {
        return [];
    }
    return array_values(array_filter($group['items'], static fn (array $item): bool =>
        !($item['id'] === 'analytics' && in_array('system_admin', $user['roles'] ?? [], true))
        && !($item['id'] === 'audit' && array_intersect(['osa', 'program_chair'], $user['roles'] ?? []))
        && ($item['permission'] === null || can($user, $item['permission']))
    ));
}

/** Dispatch only fixed controllers; each controller still enforces its own permission and CSRF. */
function module_controller(string $parent, string $view): ?string
{
    foreach (module_routes() as $controller => [$route, $section]) {
        if ($route === $parent && $section === $view) {
            return basename($controller);
        }
    }
    return null;
}
