<?php
declare(strict_types=1);
$scale = $book['scale'] ?? load_scale();
?>

<div class="card">
    <div class="card-head">
        <h2>Grade equivalent scale</h2>
    </div>
    <p class="lede">Percentage scores convert to these grade points. The scale is set by college offices.</p>
    <div class="table-wrap">
        <table>
            <thead>
                <tr>
                    <th>Percentage range</th>
                    <th>Remarks</th>
                    <th>Grade point</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($scale as $band): ?>
                <tr>
                    <td><?= h(scale_range_label($band)) ?></td>
                    <td><?= badge((string) $band['description'], scale_remark_key((string) $band['description'])) ?></td>
                    <td class="mono"><?= number_format((float) $band['equivalent'], 2) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <p class="hint">1.00 is the highest mark. 3.00 is the lowest passing mark. Values below the lowest passing range are Failed.</p>
</div>
