<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/auth.php';

header('Cache-Control: no-store');
set_exception_handler(static function (Throwable $error): void {
    json_out(['ok' => false, 'message' => 'Notifications are temporarily unavailable. Please try again.'], 500);
});
$user = current_user();
if (!$user) json_out(['ok' => false, 'message' => 'Please sign in again to view notifications.'], 401);
if (($user['portal'] ?? '') === 'student' || is_view_only_oic($user)) {
    json_out(['ok' => false, 'message' => 'Notifications are unavailable for this account.'], 403);
}
$userId = (int) $user['id'];
$method = $_SERVER['REQUEST_METHOD'];
if ($method === 'POST') {
    require_csrf();
    $action = (string) ($_POST['action'] ?? '');
    if ($action === 'mark_all_read') {
        db()->prepare('UPDATE notifications SET is_read = 1 WHERE user_id = ? AND is_read = 0')->execute([$userId]);
    } elseif (in_array($action, ['mark_read', 'mark_unread'], true)) {
        $id = (int) ($_POST['notification_id'] ?? 0);
        $found = db()->prepare('SELECT id FROM notifications WHERE id = ? AND user_id = ?');
        $found->execute([$id, $userId]);
        if (!$found->fetchColumn()) json_out(['ok' => false, 'message' => 'Notification not found.'], 404);
        db()->prepare('UPDATE notifications SET is_read = ? WHERE id = ? AND user_id = ?')
            ->execute([$action === 'mark_read' ? 1 : 0, $id, $userId]);
    } else {
        json_out(['ok' => false, 'message' => 'Unknown notification action.'], 400);
    }
} elseif ($method !== 'GET') {
    header('Allow: GET, POST');
    json_out(['ok' => false, 'message' => 'Method not allowed.'], 405);
}
$limit = max(30, min(500, (int) ($_GET['limit'] ?? 30)));
$unreadOnly = ($_GET['filter'] ?? '') === 'unread';
$query = db()->prepare('SELECT id, title, body, link_url, is_read, created_at FROM notifications WHERE user_id = ?'
    . ($unreadOnly ? ' AND is_read = 0' : '') . ' ORDER BY created_at DESC, id DESC LIMIT ' . ($limit + 1));
$query->execute([$userId]);
$rows = $query->fetchAll();
$hasMore = count($rows) > $limit;
$rows = array_slice($rows, 0, $limit);
foreach ($rows as &$row) {
    $row['id'] = (int) $row['id'];
    $row['is_read'] = (bool) $row['is_read'];
    $row['when'] = fmt_when($row['created_at']);
    $path = trim((string) $row['link_url']);
    // Only application-relative notification destinations may be opened.
    $row['url'] = $path !== '' && !preg_match('~^(?:[a-z][a-z0-9+.-]*:|[/\\\\])|(?:^|/)\.\.(?:/|$)~i', $path) ? url($path) : '';
    unset($row['link_url']);
}
unset($row);
json_out(['ok' => true, 'rows' => $rows, 'unread' => unread_count($userId), 'has_more' => $hasMore && $limit < 500]);
