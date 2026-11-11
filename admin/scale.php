<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/gradebook.php';
require_once __DIR__ . '/../includes/layout.php';

db_or_setup();
$user = require_portal('admin');
require_permission($user, 'rules');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    try {
        $count = save_grading_scale($user, (array) ($_POST['scale'] ?? []));
        flash('ok', "Grade equivalent scale updated ({$count} bands). Professor sheets will use the new ranges.");
    } catch (Throwable $e) {
        flash('error', $e->getMessage());
    }
    redirect('admin/scale.php');
}

$scale = load_scale();

layout_start('Grade Equivalent Scale', 'scale', [['label' => 'Grade equivalent']]);
?>
<div class="page-head">
    <div>
        <h1>Grade Equivalent Scale</h1>
        <p class="lede">Set the percentage ranges and grade points used across the college. Professors see this same scale on their class sheet.</p>
    </div>
</div>

<section class="card">
    <div class="card-head">
        <h2><?= icon('award') ?> Official conversion table</h2>
    </div>
    <p class="hint">Edit min/max percent and grade point. Changes apply immediately to computed equivalents on office and professor sides.</p>
    <form method="post">
        <?= csrf_field() ?>
        <div class="table-wrap" style="margin-top: 12px;">
            <table>
                <thead>
                    <tr>
                        <th>Min %</th>
                        <th>Max %</th>
                        <th>Remarks</th>
                        <th>Grade point</th>
                        <th>Preview range</th>
                    </tr>
                </thead>
                <tbody id="scaleBody">
                <?php foreach ($scale as $i => $band): ?>
                    <tr>
                        <td>
                            <input type="number" name="scale[<?= (int) $i ?>][min_percent]" min="0" max="100" step="0.01"
                                   value="<?= h(number_format((float) $band['min_percent'], 2, '.', '')) ?>" required style="width: 100px;">
                        </td>
                        <td>
                            <input type="number" name="scale[<?= (int) $i ?>][max_percent]" min="0" max="100" step="0.01"
                                   value="<?= h(number_format((float) $band['max_percent'], 2, '.', '')) ?>" required style="width: 100px;">
                        </td>
                        <td>
                            <input type="text" name="scale[<?= (int) $i ?>][description]" maxlength="40"
                                   value="<?= h((string) $band['description']) ?>" required style="min-width: 180px;">
                        </td>
                        <td>
                            <input type="number" name="scale[<?= (int) $i ?>][equivalent]" min="1" max="5" step="0.01"
                                   value="<?= h(number_format((float) $band['equivalent'], 2, '.', '')) ?>" required style="width: 100px;">
                        </td>
                        <td class="mono"><?= h(scale_range_label($band)) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <div style="margin-top: 14px; display: flex; gap: 10px; flex-wrap: wrap;">
            <button class="btn" type="submit"><?= icon('check') ?> Save Scale</button>
            <button class="btn btn-ghost" type="button" id="addScaleRow"><?= icon('plus') ?> Add row</button>
        </div>
    </form>
</section>

<script>
(function () {
    const body = document.getElementById('scaleBody');
    const addBtn = document.getElementById('addScaleRow');
    if (!body || !addBtn) return;
    addBtn.addEventListener('click', function () {
        const index = body.querySelectorAll('tr').length;
        const tr = document.createElement('tr');
        tr.innerHTML =
            '<td><input type="number" name="scale[' + index + '][min_percent]" min="0" max="100" step="0.01" value="" style="width:100px;"></td>' +
            '<td><input type="number" name="scale[' + index + '][max_percent]" min="0" max="100" step="0.01" value="" style="width:100px;"></td>' +
            '<td><input type="text" name="scale[' + index + '][description]" maxlength="40" value="" style="min-width:180px;"></td>' +
            '<td><input type="number" name="scale[' + index + '][equivalent]" min="1" max="5" step="0.01" value="" style="width:100px;"></td>' +
            '<td class="mono">—</td>';
        body.appendChild(tr);
    });
})();
</script>
<?php layout_end(); ?>
