<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/recognition.php';

db_or_setup();
$user = require_portal('admin');
require_permission($user, 'recognition');

if (!array_intersect($user['roles'], office_roles())) {
    http_response_code(403);
    exit('Only authorized personnel can open the Official Masterlist and certificates.');
}

$semesterId = (int) ($_GET['semester'] ?? 0);
if ($semesterId <= 0) {
    $semesterId = (int) db()->query('SELECT id FROM semesters WHERE is_current = 1 ORDER BY id DESC LIMIT 1')->fetchColumn();
}

$doc = ($_GET['doc'] ?? '') === 'certificates' ? 'certificates' : 'masterlist';
$school = defined('SCHOOL_NAME') ? SCHOOL_NAME : 'Systems Plus Computer College';
$logoUrl = url('img/Newest Logo.webp');

$rows = recognition_official($semesterId);
$scope = scoped_program_ids($user);
if (is_array($scope)) {
    if ($scope === []) {
        $rows = [];
    } else {
        $rows = array_values(array_filter(
            $rows,
            static fn (array $row): bool => in_array((int) ($row['program_id'] ?? 0), $scope, true)
        ));
    }
}

audit(
    (int) $user['id'],
    $doc === 'certificates' ? 'recognition_certificates' : 'recognition_masterlist',
    'semester',
    (string) $semesterId,
    count($rows) . ' verified students'
);

$term = $rows[0]['year_label'] ?? '';
$semesterName = $rows[0]['semester_name'] ?? '';

if ($term === '') {
    $meta = db()->prepare('SELECT ay.label, sem.name FROM semesters sem JOIN academic_years ay ON ay.id = sem.academic_year_id WHERE sem.id = ?');
    $meta->execute([$semesterId]);
    $meta = $meta->fetch();
    $term = (string) ($meta['label'] ?? '');
    $semesterName = (string) ($meta['name'] ?? '');
}

$termLabel = trim($term . ' · ' . $semesterName);

$groupedRows = [];
foreach ($rows as $row) {
    $programKey = ($row['program_code'] ?? 'GENERAL') . ' - ' . ($row['year_level'] ?? '1st Year');
    $groupedRows[$programKey][] = $row;
}

function cert_issue_timestamp(array $row): int
{
    foreach (['verified_at', 'osa_at', 'committee_at', 'endorsed_at'] as $key) {
        if (!empty($row[$key])) {
            $ts = strtotime((string) $row[$key]);
            if ($ts) {
                return $ts;
            }
        }
    }
    return time();
}

function cert_issue_date(array $row): string
{
    return date('d.m.Y', cert_issue_timestamp($row));
}

function cert_given_line(int $ts): string
{
    $day = (int) date('j', $ts);
    $suffix = 'th';
    if (!in_array($day % 100, [11, 12, 13], true)) {
        $suffix = match ($day % 10) {
            1 => 'st',
            2 => 'nd',
            3 => 'rd',
            default => 'th',
        };
    }
    return 'Given this ' . $day . $suffix . ' day of ' . date('F, Y', $ts) . '.';
}

function cert_id(array $row): string
{
    $id = (int) ($row['id'] ?? 0);
    $num = preg_replace('/\D+/', '', (string) ($row['student_number'] ?? '')) ?: '0000';
    return sprintf('SPCC-%04d-%s', $id, substr($num, -4));
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <?php theme_tags(); ?>
    <title><?= $doc === 'certificates' ? 'Certificates' : 'Official Masterlist' ?> · <?= h($school) ?></title>
    <link rel="icon" href="<?= h($logoUrl) ?>">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:wght@500;600;700&family=Great+Vibes&family=Montserrat:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --navy: #0B1C33;
            --navy-deep: #071325;
            --gold: #C5A45B;
            --gold-soft: #E6D3A3;
            --ink: #142B49;
            --muted: #5C6B7A;
        }
        * { box-sizing: border-box; }
        body { margin: 0; background: #F5F7FA; color: var(--ink); font-family: "Montserrat", "Segoe UI", sans-serif; }
        .toolbar {
            display: flex; justify-content: space-between; gap: 12px; align-items: center;
            padding: 16px 24px; background: var(--ink); color: #fff; position: sticky; top: 0; z-index: 5;
        }
        .toolbar a, .toolbar button {
            color: var(--ink); background: var(--gold); border: 0; border-radius: 8px;
            padding: 8px 14px; text-decoration: none; font: inherit; cursor: pointer; font-weight: 600;
        }
        .sheet {
            max-width: 980px; margin: 24px auto; background: #fff; padding: 32px;
            box-shadow: 0 10px 30px rgba(20,43,73,.08);
        }
        h1 { font-weight: 500; margin: 8px 0; font-family: "Cormorant Garamond", Georgia, serif; }
        .group-header {
            margin-top: 28px; margin-bottom: 10px; padding-bottom: 6px;
            border-bottom: 2px solid var(--gold); color: var(--ink);
            display: flex; justify-content: space-between; align-items: flex-end;
        }
        .group-header h2 { font-size: 16px; margin: 0; text-transform: uppercase; letter-spacing: .02em; }
        .group-header span { font-size: 13px; color: var(--muted); font-weight: normal; }
        table { width: 100%; border-collapse: collapse; margin-bottom: 16px; }
        th, td { text-align: left; padding: 10px 12px; border-bottom: 1px solid #E2E8F0; font-size: 14px; }
        th { font-size: 12px; letter-spacing: .04em; text-transform: uppercase; color: var(--muted); background: #F8FAFC; }
        .gold-label { color: #8C6B2F; letter-spacing: .14em; text-transform: uppercase; font-size: 13px; font-weight: 600; }
        img.logo-sm { width: 72px; height: 72px; object-fit: contain; }

        /* One original certificate per printed landscape page. */
        .empty-note { max-width: 980px; margin: 24px auto; background: #fff; padding: 32px; }
        @page { size: A4 landscape; margin: 0; }
        @media print {
            html, body { margin: 0 !important; padding: 0 !important; background: #fff !important; }
            .toolbar { display: none !important; }
            .sheet { box-shadow: none; margin: 0; max-width: none; width: 100%; }
        }
    </style>
    <link rel="stylesheet" href="<?= h(url('assets/css/certificates.css')) ?>">
</head>
<body>
<div class="toolbar">
    <strong><?= $doc === 'certificates' ? 'Certificates of Recognition' : 'Official Masterlist' ?></strong>
    <div>
        <button type="button" onclick="window.print()">Print Document</button>
        <a href="<?= h(url('admin/recognition.php', ['semester' => $semesterId])) ?>">Back to Recognition</a>
    </div>
</div>

<?php if ($doc === 'masterlist'): ?>
<main class="sheet">
    <img class="logo-sm" src="<?= h($logoUrl) ?>" alt="Logo">
    <div class="gold-label"><?= h($school) ?></div>
    <h1>Official Masterlist</h1>
    <p><strong>Dean’s List and President’s List</strong> · <?= h($termLabel) ?></p>
    <p>Students listed here are officially endorsed candidates by the Program Chair.</p>

    <?php if ($rows === []): ?>
        <p>No honor students recorded for this semester yet.</p>
    <?php else: ?>
        <?php foreach ($groupedRows as $groupName => $candidates): ?>
            <div class="group-header">
                <h2><?= h($groupName) ?></h2>
                <span>Total Candidates: <?= count($candidates) ?></span>
            </div>
            <table>
                <thead>
                    <tr><th style="width: 40px;">No.</th><th>Student Details</th><th>Classification</th><th style="width: 80px;">GWA</th></tr>
                </thead>
                <tbody>
                <?php foreach ($candidates as $index => $row): ?>
                    <?php
                    $listType = (string) ($row['list_type'] ?? '');
                    $listLabel = function_exists('recognition_list_label') ? recognition_list_label($listType) : ($listType ?: "Dean's List");
                    ?>
                    <tr>
                        <td><?= $index + 1 ?></td>
                        <td><strong><?= h($row['full_name']) ?></strong><br><small><?= h($row['student_number']) ?></small></td>
                        <td><strong><?= h($listLabel) ?></strong></td>
                        <td><?= h(fmt_eqv(isset($row['gwa']) ? (float) $row['gwa'] : null)) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endforeach; ?>
    <?php endif; ?>
</main>

<?php else: ?>

<?php
$isTemplate = $rows === [];
if ($isTemplate) {
    $rows = [[
        'id' => 0,
        'full_name' => 'NAME',
        'student_number' => 'STUDENT NO.',
        'program_code' => 'PROGRAM',
        'year_level' => 'Year Level',
        'list_type' => 'DL',
        'gwa' => null,
        'chair_name' => 'Program Chair',
        'chair_title' => '',
        'oic_name' => 'College OIC',
        'oic_title' => '',
        'endorsed_at' => null,
        'committee_at' => null,
        'osa_at' => null,
        'verified_at' => null,
    ]];
    if ($termLabel === '' || $termLabel === '·') {
        $termLabel = 'Academic Year · Semester';
    }
}
?>

<?php foreach ($rows as $row): ?>
<?php
// Use only the user's original artwork, never the generated Finished exports.
$certificateType = strtoupper((string) ($row['list_type'] ?? 'DL')) === 'PL' ? 'PL' : 'DL';
$certificateProgram = strtoupper(trim((string) ($row['program_code'] ?? '')));
$artProgram = $certificateProgram === 'BSHM' ? 'BSHM' : 'IT';
$artUrl = url('Certificate/' . $certificateType . '_Certificate_' . $artProgram . '.webp');
$recipientName = (string) $row['full_name'];
$nameLength = function_exists('mb_strlen') ? mb_strlen($recipientName) : strlen($recipientName);
$nameClass = $nameLength > 40 ? ' name-very-long' : ($nameLength > 28 ? ' name-long' : '');
?>
<section class="cert-wrap">
    <article class="original-cert" aria-label="<?= h($certificateType) ?> certificate for <?= h($recipientName) ?>">
        <img class="original-cert-art" src="<?= h($artUrl) ?>" alt="<?= h($certificateType) ?> Certificate of Recognition, <?= h($artProgram) ?>" width="1280" height="905">
        <div class="original-cert-name<?= h($nameClass) ?>"><?= h($recipientName) ?></div>
        <div class="original-cert-meta"><?= h($row['student_number']) ?> · <?= h($row['program_code'] ?? '') ?> · <?= h($row['year_level'] ?? '') ?></div>
    </article>
</section>
<?php endforeach; ?>

<?php endif; ?>
</body>
</html>
