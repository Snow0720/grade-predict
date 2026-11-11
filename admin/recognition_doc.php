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
$logoUrl = url('img/logo.png');

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

        /* Certificate — A4 landscape proportions, one page per student */
        .cert-wrap {
            width: min(1000px, calc(100vw - 32px));
            aspect-ratio: 297 / 210;
            margin: 24px auto;
            page-break-after: always;
            break-after: page;
        }
        .cert-wrap:last-of-type { page-break-after: auto; break-after: auto; }
        .cert {
            position: relative;
            width: 100%;
            height: 100%;
            background:
                radial-gradient(ellipse at center, rgba(197,164,91,.08) 0%, transparent 55%),
                linear-gradient(180deg, #0E2340 0%, var(--navy) 45%, var(--navy-deep) 100%);
            color: #fff;
            padding: 3.2%;
            text-align: center;
            overflow: hidden;
            box-shadow: 0 18px 50px rgba(7,19,37,.35);
        }
        .cert-frame {
            position: absolute; inset: 2.4%;
            border: 1px solid rgba(197,164,91,.85);
            pointer-events: none;
        }
        .cert-frame::before {
            content: "";
            position: absolute; inset: 1.1%;
            border: 1px solid rgba(255,255,255,.55);
        }
        .cert-ornament {
            position: absolute; inset: 3.6%;
            border: 8px solid transparent;
            border-image: repeating-linear-gradient(
                90deg,
                var(--gold) 0 8px,
                transparent 8px 14px
            ) 8;
            opacity: .9;
            pointer-events: none;
        }
        .cert-ornament::before,
        .cert-ornament::after {
            content: "";
            position: absolute;
            left: 10px; right: 10px; height: 10px;
            background-image: radial-gradient(circle, var(--gold) 1.4px, transparent 1.6px);
            background-size: 16px 10px;
            background-repeat: repeat-x;
            opacity: .95;
        }
        .cert-ornament::before { top: -1px; }
        .cert-ornament::after { bottom: -1px; }
        .cert-side-dots {
            position: absolute; top: 3.6%; bottom: 3.6%; width: 10px;
            background-image: radial-gradient(circle, var(--gold) 1.4px, transparent 1.6px);
            background-size: 10px 16px;
            background-repeat: repeat-y;
            opacity: .95;
            pointer-events: none;
        }
        .cert-side-dots.left { left: 3.6%; }
        .cert-side-dots.right { right: 3.6%; }
        .cert-inner {
            position: relative;
            z-index: 1;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
            height: 100%;
            padding: 2.2% 3.5% 1.2%;
            overflow: hidden;
        }
        .cert-logo {
            width: 52px; height: 52px; object-fit: contain;
            margin: 0 auto 6px;
            filter: drop-shadow(0 4px 10px rgba(0,0,0,.25));
            background: rgba(255,255,255,.92);
            border-radius: 50%;
            padding: 4px;
        }
        .cert-school {
            font-size: 10px;
            letter-spacing: .24em;
            text-transform: uppercase;
            font-weight: 600;
            color: rgba(255,255,255,.92);
            margin: 0 0 8px;
        }
        .cert-title {
            font-family: "Cormorant Garamond", Georgia, serif;
            font-size: clamp(28px, 4.6vw, 42px);
            font-weight: 600;
            line-height: 1.05;
            margin: 0 0 8px;
            color: #fff;
        }
        .cert-presented {
            font-size: 10px;
            letter-spacing: .28em;
            text-transform: uppercase;
            color: rgba(255,255,255,.78);
            margin: 0 0 4px;
            font-weight: 500;
        }
        .cert-divider {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            margin: 4px auto;
            max-width: 460px;
            color: var(--gold);
        }
        .cert-divider span.line {
            flex: 1;
            height: 1px;
            background: linear-gradient(90deg, transparent, var(--gold), transparent);
        }
        .cert-divider span.gem {
            width: 7px; height: 7px;
            border: 1px solid var(--gold);
            transform: rotate(45deg);
            background: rgba(197,164,91,.35);
            box-shadow: 0 0 0 3px rgba(197,164,91,.12);
        }
        .cert-name {
            font-family: "Great Vibes", "Segoe Script", cursive;
            font-size: clamp(34px, 5.2vw, 52px);
            line-height: 1.1;
            color: var(--gold-soft);
            margin: 2px 0 0;
            text-shadow: 0 2px 18px rgba(0,0,0,.25);
            word-break: break-word;
        }
        .cert-meta {
            font-size: 11px;
            letter-spacing: .08em;
            color: rgba(255,255,255,.72);
            margin: 0 0 2px;
        }
        .cert-for {
            font-size: 10px;
            letter-spacing: .24em;
            text-transform: uppercase;
            color: rgba(255,255,255,.78);
            margin: 10px 0 4px;
            font-weight: 500;
        }
        .cert-award {
            font-family: "Cormorant Garamond", Georgia, serif;
            font-size: clamp(22px, 3.2vw, 30px);
            font-weight: 600;
            color: #fff;
            margin: 0;
        }
        .cert-detail {
            margin: 8px 0 0;
            font-size: 12px;
            color: rgba(255,255,255,.78);
            letter-spacing: .04em;
        }
        .cert-detail strong { color: var(--gold-soft); font-weight: 600; }
        .cert-blurb {
            max-width: 640px;
            margin: 10px auto 0;
            font-size: 12px;
            line-height: 1.45;
            font-weight: 400;
            color: rgba(255,255,255,.82);
            letter-spacing: .01em;
        }
        .cert-given {
            margin: 8px 0 0;
            font-size: 12px;
            color: rgba(255,255,255,.78);
            letter-spacing: .02em;
        }
        .cert-signs {
            display: flex;
            justify-content: space-between;
            gap: 24px;
            margin-top: 14px;
            padding: 0 18px;
        }
        .cert-sign {
            flex: 1;
            text-align: center;
        }
        .cert-sign .sig {
            font-family: "Great Vibes", cursive;
            font-size: 22px;
            color: rgba(255,255,255,.95);
            min-height: 28px;
            margin-bottom: 2px;
        }
        .cert-sign .who {
            font-size: 10px;
            letter-spacing: .14em;
            text-transform: uppercase;
            font-weight: 600;
            color: rgba(255,255,255,.9);
        }
        .cert-sign .role {
            font-size: 9px;
            letter-spacing: .16em;
            text-transform: uppercase;
            color: rgba(255,255,255,.55);
            margin-top: 2px;
        }
        .cert-foot {
            display: flex;
            justify-content: space-between;
            gap: 12px;
            margin-top: 10px;
            padding: 0 4px;
            font-size: 9px;
            letter-spacing: .12em;
            text-transform: uppercase;
            color: rgba(255,255,255,.55);
        }
        .empty-note { max-width: 980px; margin: 24px auto; background: #fff; padding: 32px; }

        @page {
            size: A4 landscape;
            margin: 0;
        }

        @media print {
            html, body {
                width: 297mm;
                height: 210mm;
                margin: 0 !important;
                padding: 0 !important;
                background: #fff !important;
            }
            .toolbar { display: none !important; }
            .sheet { box-shadow: none; margin: 0; max-width: none; width: 100%; }
            .cert-wrap {
                width: 297mm;
                height: 210mm;
                max-width: none;
                aspect-ratio: auto;
                margin: 0;
                padding: 0;
                overflow: hidden;
                page-break-after: always;
                break-after: page;
                page-break-inside: avoid;
                break-inside: avoid;
            }
            .cert-wrap:last-of-type {
                page-break-after: auto;
                break-after: auto;
            }
            .cert {
                width: 297mm;
                height: 210mm;
                min-height: 0;
                max-height: 210mm;
                padding: 10mm;
                box-shadow: none;
                -webkit-print-color-adjust: exact;
                print-color-adjust: exact;
            }
            .cert-inner {
                min-height: 0;
                height: 100%;
                padding: 4mm 8mm 2mm;
            }
            .cert-logo { width: 46px; height: 46px; margin-bottom: 4px; }
            .cert-school { margin-bottom: 6px; font-size: 9px; }
            .cert-title { font-size: 36px; margin-bottom: 6px; }
            .cert-name { font-size: 44px; }
            .cert-award { font-size: 26px; }
            .cert-blurb { font-size: 11px; max-width: 190mm; margin-top: 8px; line-height: 1.4; }
            .cert-given { margin-top: 6px; font-size: 11px; }
            .cert-signs { margin-top: 10px; }
            .cert-sign .sig { font-size: 20px; min-height: 24px; }
            .cert-foot { margin-top: 8px; }
        }

        @media (max-width: 800px) {
            .cert-wrap {
                width: calc(100vw - 16px);
                aspect-ratio: 297 / 210;
            }
            .cert-title { font-size: 28px; }
            .cert-name { font-size: 34px; }
            .cert-award { font-size: 22px; }
            .cert-signs { gap: 12px; padding: 0 8px; }
        }
    </style>
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
$listType = (string) ($row['list_type'] ?? '');
$listLabel = function_exists('recognition_list_label') ? recognition_list_label($listType) : ($listType ?: "Dean's List");
$chairDisplay = trim((string) ($row['chair_title'] ?? '') . ' ' . (string) ($row['chair_name'] ?? ''));
$oicDisplay = trim((string) ($row['oic_title'] ?? '') . ' ' . (string) ($row['oic_name'] ?? ''));
$chairScript = trim((string) ($row['chair_name'] ?? '')) ?: 'Program Chair';
$oicScript = trim((string) ($row['oic_name'] ?? '')) ?: 'College OIC';
$gwa = $isTemplate ? '0.00' : fmt_eqv(isset($row['gwa']) ? (float) $row['gwa'] : null);
$issueTs = $isTemplate ? time() : cert_issue_timestamp($row);
$issueDate = date('d.m.Y', $issueTs);
$givenLine = cert_given_line($issueTs);
$certificateId = $isTemplate ? 'SPCC-0000-0000' : cert_id($row);
$programLabel = trim((string) ($row['program_code'] ?? ''));
if ($programLabel === '' || $programLabel === 'PROGRAM') {
    $programLabel = 'their academic program';
}
$awardPhrase = $listLabel === "President's List"
    ? "the President’s List"
    : "the Dean’s List";
$gwaPhrase = ($gwa !== '—' && $gwa !== '')
    ? ' with a General Weighted Average of ' . $gwa
    : '';
$blurb = 'for outstanding academic performance and excellence as an official qualifier of '
    . $awardPhrase
    . ' under '
    . $school
    . ' for '
    . ($termLabel !== '' ? $termLabel : 'the current academic term')
    . ($programLabel !== 'their academic program' ? ' in the ' . $programLabel . ' program' : '')
    . $gwaPhrase
    . '. This achievement is recognized with appreciation.';
?>
<section class="cert-wrap">
    <article class="cert" aria-label="Certificate for <?= h($row['full_name']) ?>">
        <div class="cert-frame"></div>
        <div class="cert-ornament"></div>
        <div class="cert-side-dots left"></div>
        <div class="cert-side-dots right"></div>
        <div class="cert-inner">
            <div>
                <img class="cert-logo" src="<?= h($logoUrl) ?>" alt="<?= h($school) ?> logo">
                <p class="cert-school"><?= h($school) ?></p>
                <h1 class="cert-title">Certificate of Recognition</h1>
                <p class="cert-presented">Presented to</p>
                <div class="cert-divider" aria-hidden="true">
                    <span class="line"></span><span class="gem"></span><span class="line"></span>
                </div>
                <div class="cert-name"><?= h($row['full_name']) ?></div>
                <p class="cert-meta"><?= h($row['student_number']) ?> · <?= h($row['program_code'] ?? '') ?> · <?= h($row['year_level'] ?? '') ?></p>
                <div class="cert-divider" aria-hidden="true">
                    <span class="line"></span><span class="gem"></span><span class="line"></span>
                </div>
                <p class="cert-for">For achievement and excellence in</p>
                <p class="cert-award"><?= h($listLabel) ?></p>
                <p class="cert-blurb"><?= h($blurb) ?></p>
                <p class="cert-given"><?= h($givenLine) ?></p>
            </div>

            <div>
                <div class="cert-signs">
                    <div class="cert-sign">
                        <div class="sig"><?= h($chairScript) ?></div>
                        <div class="who"><?= h($chairDisplay !== '' ? $chairDisplay : 'Program Chair') ?></div>
                        <div class="role">Program Chair · <?= h($school) ?></div>
                    </div>
                    <div class="cert-sign">
                        <div class="sig"><?= h($oicScript) ?></div>
                        <div class="who"><?= h($oicDisplay !== '' ? $oicDisplay : 'College OIC') ?></div>
                        <div class="role">College OIC · <?= h($school) ?></div>
                    </div>
                </div>
                <div class="cert-foot">
                    <span>Issuing date: <?= h($issueDate) ?></span>
                    <span>Certificate ID: <?= h($certificateId) ?></span>
                </div>
            </div>
        </div>
    </article>
</section>
<?php endforeach; ?>

<?php endif; ?>
</body>
</html>
