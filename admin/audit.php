<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/layout.php';

db_or_setup();
$user = require_portal('admin');
require_permission($user, 'audit');

$q = trim((string) ($_GET['q'] ?? ''));
$page = (int) ($_GET['page'] ?? 1);
$where = '';
$params = [];

if ($q !== '') {
    $where = 'WHERE (a.action LIKE ? OR a.entity LIKE ? OR a.details LIKE ? OR u.username LIKE ? OR u.full_name LIKE ?)';
    $params = array_fill(0, 5, '%' . $q . '%');
}

if (($_GET['live'] ?? '') === '1') {
    $after = (int) ($_GET['after'] ?? 0);
    $liveWhere = $where === '' ? 'WHERE a.id > ?' : $where . ' AND a.id > ?';
    $liveParams = array_merge($params, [$after]);
    $rows = db()->prepare(
        "SELECT a.id, a.action, a.entity, a.entity_id, a.details, a.created_at, u.username, u.full_name
         FROM audit_logs a
         LEFT JOIN users u ON u.id = a.user_id
         $liveWhere
         ORDER BY a.id ASC
         LIMIT 50"
    );
    $rows->execute($liveParams);
    $rows = $rows->fetchAll();

    $countStmt = db()->prepare("SELECT COUNT(*) FROM audit_logs a LEFT JOIN users u ON u.id = a.user_id $where");
    $countStmt->execute($params);
    $totalLogs = (int) $countStmt->fetchColumn();
    $systemLogsCount = (int) db()->query("SELECT COUNT(*) FROM audit_logs WHERE user_id IS NULL OR action LIKE '%system%'")->fetchColumn();

    $payload = [];
    foreach ($rows as $row) {
        $payload[] = [
            'id' => (int) $row['id'],
            'when' => fmt_when($row['created_at']),
            'name' => (string) ($row['full_name'] ?: 'System'),
            'username' => (string) ($row['username'] ?? ''),
            'action' => (string) $row['action'],
            'entity' => trim((string) $row['entity'] . ' ' . (string) $row['entity_id']),
            'details' => (string) ($row['details'] ?? ''),
            'badge' => in_array((string) $row['action'], ['login', 'logout', 'import', 'scholarship_assign', 'scholarship_revoke'], true),
        ];
    }

    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode([
        'ok' => true,
        'total' => $totalLogs,
        'system' => $systemLogsCount,
        'rows' => $payload,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$count = db()->prepare("SELECT COUNT(*) FROM audit_logs a LEFT JOIN users u ON u.id = a.user_id $where");
$count->execute($params);
$totalLogs = (int) $count->fetchColumn();

$pager = paginate($totalLogs, $page, 20);

$rows = db()->prepare(
    "SELECT a.*, u.username, u.full_name
     FROM audit_logs a
     LEFT JOIN users u ON u.id = a.user_id
     $where
     ORDER BY a.id DESC
     LIMIT {$pager['per']} OFFSET {$pager['offset']}"
);
$rows->execute($params);
$rows = $rows->fetchAll();

$systemLogsCount = (int) db()->query("SELECT COUNT(*) FROM audit_logs WHERE user_id IS NULL OR action LIKE '%system%'")->fetchColumn();
$latestId = $rows === [] ? 0 : (int) $rows[0]['id'];

layout_start('History Logs', 'audit', [['label' => 'Logs']]);
?>
<div class="page-head">
    <div>
        <h1>History Logs</h1>
        <p class="lede">Track activities as they happen</p>
    </div>
    <span class="live-pill" id="auditLivePill" title="Listening for new events">
        <span class="live-dot" aria-hidden="true"></span>
        Live
    </span>
</div>

<section class="kpis" style="grid-template-columns: repeat(3, minmax(0, 1fr));">
    <article class="kpi">
        <span>Total Logged Events</span>
        <strong id="auditTotal"><?= $totalLogs ?></strong>
    </article>
    <article class="kpi">
        <span>System Automated Events</span>
        <strong id="auditSystem"><?= $systemLogsCount ?></strong>
    </article>
    <article class="kpi">
        <span>Active Search Scope</span>
        <strong class="kpi-text"><?= $q !== '' ? h($q) : 'All System Activity' ?></strong>
    </article>
</section>

<section class="card" style="margin-bottom: 18px;">
    <form class="filters" method="get" style="display: flex; gap: 12px; align-items: flex-end; flex-wrap: wrap;">
        <input type="hidden" name="view" value="logs">
        <div style="flex: 1; min-width: 280px;">
            <label for="q">Search History Logs</label>
            <input id="q" name="q" value="<?= h($q) ?>" placeholder="Action, user, record, or details..." style="width: 100%;">
        </div>
        <div>
            <button class="btn" type="submit"><?= icon('search') ?> Search</button>
        </div>
    </form>
</section>

<section class="card">
    <h2><?= icon('audit') ?> Recent Activities</h2>
    <?php if ($rows === []): ?>
        <p class="empty" id="auditEmpty">No activity records found matching your search parameter.</p>
        <div class="table-wrap" style="margin-top: 12px; display:none;" id="auditTableWrap">
            <table>
                <thead>
                    <tr>
                        <th>When</th>
                        <th>User Account</th>
                        <th>Action</th>
                        <th>Record Entity</th>
                        <th>Details</th>
                    </tr>
                </thead>
                <tbody id="auditBody"></tbody>
            </table>
        </div>
    <?php else: ?>
        <div class="table-wrap" style="margin-top: 12px;" id="auditTableWrap">
            <table>
                <thead>
                    <tr>
                        <th>When</th>
                        <th>User Account</th>
                        <th>Action</th>
                        <th>Record Entity</th>
                        <th>Details</th>
                    </tr>
                </thead>
                <tbody id="auditBody">
                <?php foreach ($rows as $row): ?>
                    <tr data-id="<?= (int) $row['id'] ?>">
                        <td style="white-space: nowrap;"><?= fmt_when($row['created_at']) ?></td>
                        <td>
                            <strong><?= h($row['full_name'] ?: 'System') ?></strong>
                            <?php if (!empty($row['username'])): ?>
                                <br><span class="mono" style="font-size: 12px; color: var(--text-muted, #666);"><?= h($row['username']) ?></span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php
                            $act = (string) $row['action'];
                            if (in_array($act, ['login', 'logout', 'import', 'scholarship_assign', 'scholarship_revoke'], true)): ?>
                                <?= badge($act, $act) ?>
                            <?php else: ?>
                                <span class="badge" style="background: var(--theme-raised, #f0f4f8); color: var(--ink, #102a43); font-weight: 600;"><?= h($act) ?></span>
                            <?php endif; ?>
                        </td>
                        <td><span class="mono"><?= h($row['entity']) ?> <?= h((string) $row['entity_id']) ?></span></td>
                        <td><?= h($row['details']) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <div style="margin-top: 16px;">
            <?= pager_html($pager, 'admin/audit.php', ['q' => $q]) ?>
        </div>
    <?php endif; ?>
</section>

<script>
(function () {
    const body = document.getElementById('auditBody');
    const pill = document.getElementById('auditLivePill');
    const totalNode = document.getElementById('auditTotal');
    const systemNode = document.getElementById('auditSystem');
    const emptyNode = document.getElementById('auditEmpty');
    const wrap = document.getElementById('auditTableWrap');
    if (!body || !pill) return;

    const page = <?= (int) $page ?>;
    const query = <?= json_encode($q, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    const feedUrl = <?= json_encode(url('admin/audit.php'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    let after = <?= (int) $latestId ?>;
    let timer = null;

    function esc(value) {
        return String(value == null ? '' : value)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }

    function rowHtml(row) {
        const actionHtml = row.badge
            ? '<span class="badge badge-info">' + esc(row.action) + '</span>'
            : '<span class="badge" style="background:var(--theme-raised, #f0f4f8);color:var(--ink, #102a43);font-weight:600;">' + esc(row.action) + '</span>';
        const userHtml = '<strong>' + esc(row.name) + '</strong>'
            + (row.username
                ? '<br><span class="mono" style="font-size:12px;color:var(--text-muted,#666);">' + esc(row.username) + '</span>'
                : '');
        return '<tr data-id="' + row.id + '" class="audit-live-row">'
            + '<td style="white-space:nowrap;">' + esc(row.when) + '</td>'
            + '<td>' + userHtml + '</td>'
            + '<td>' + actionHtml + '</td>'
            + '<td><span class="mono">' + esc(row.entity) + '</span></td>'
            + '<td>' + esc(row.details) + '</td>'
            + '</tr>';
    }

    async function poll() {
        if (document.hidden || page !== 1) return;
        try {
            const url = feedUrl
                + (feedUrl.includes('?') ? '&' : '?') + 'live=1&after=' + encodeURIComponent(String(after))
                + '&q=' + encodeURIComponent(query);
            const response = await fetch(url, {
                credentials: 'same-origin',
                cache: 'no-store',
                headers: { 'Accept': 'application/json', 'X-Requested-With': 'fetch' }
            });
            const payload = await response.json().catch(function () { return { ok: false }; });
            if (!response.ok || !payload.ok) {
                pill.classList.add('is-paused');
                return;
            }
            pill.classList.remove('is-paused');
            if (totalNode) totalNode.textContent = String(payload.total);
            if (systemNode) systemNode.textContent = String(payload.system);
            if (!Array.isArray(payload.rows) || payload.rows.length === 0) return;

            if (emptyNode) emptyNode.hidden = true;
            if (wrap) wrap.style.display = '';

            const html = payload.rows.map(rowHtml).reverse().join('');
            body.insertAdjacentHTML('afterbegin', html);
            after = payload.rows[payload.rows.length - 1].id;

            Array.prototype.forEach.call(body.querySelectorAll('tr.audit-live-row'), function (tr) {
                window.setTimeout(function () { tr.classList.remove('audit-live-row'); }, 1800);
            });

            while (body.children.length > 40) {
                body.removeChild(body.lastElementChild);
            }
        } catch (error) {
            pill.classList.add('is-paused');
        }
    }

    timer = setInterval(poll, 3000);
    document.addEventListener('visibilitychange', function () {
        if (!document.hidden) poll();
    });
    window.addEventListener('online', poll);
})();
</script>
<?php layout_end(); ?>
