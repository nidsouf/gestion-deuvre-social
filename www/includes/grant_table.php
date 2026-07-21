<?php
/**
 * grant_table.php - دالة عرض جدول المنح
 * ============================================================
 */

if (!function_exists('renderGrantTable')) {
    /**
     * عرض جدول المنح للموظفين (دائمين أو متعاقدين)
     */
    function renderGrantTable($items, $title, $total, $showActions = true, $csrf_token = '', $search = '', $grant_filter = 0) {
        ?>
        <div class="section-title"><?= htmlspecialchars($title) ?></div>
        <div class="table-wrapper">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>الموظف</th>
                        <th>نوع المنحة</th>
                        <th>المبلغ (دج)</th>
                        <th>قيمة الفاتورة</th>
                        <th>تاريخ المنح</th>
                        <th>السبب</th>
                        <th>الحالة</th>
                        <?php if ($showActions): ?>
                            <th>الإجراءات</th>
                        <?php endif; ?>
                    </tr>
                </thead>
                <tbody>
                <?php if (empty($items)): ?>
                    <tr><td colspan="<?= $showActions ? 9 : 8 ?>" style="text-align:center;">لا توجد بيانات</td></tr>
                <?php else: $i = 1; foreach ($items as $it):
                    $isPercentage = ($it['calculation_type'] == 'percentage');
                    $displayAmount = ($it['stored_amount'] > 0) ? $it['stored_amount'] : $it['current_amount'];
                    $isOutdated = ($it['stored_amount'] > 0 && abs($it['stored_amount'] - $it['current_amount']) > 0.01);
                    $rowClass = $isOutdated ? 'outdated-row' : '';
                    $status = getGrantStatusLabel($it);
                    $badge = getGrantBadge($it);
                ?>
                    <tr class="<?= $rowClass ?>">
                        <td><?= $i++ ?></td>
                        <td>
                            <?= htmlspecialchars($it['employee_name']) ?>
                            <br><small>(<?= $it['category'] == 'Permanent' ? 'دائم' : 'متعاقد' ?>)</small>
                        </td>
                        <td>
                            <?= htmlspecialchars($it['grant_name']) ?>
                            <?= $badge ?>
                        </td>
                        <td>
                            <?= formatGrantAmount($displayAmount) ?>
                            <?php if ($isOutdated): ?>
                                <br><small class="badge-warning">قيمة قديمة: <?= formatGrantAmount($it['stored_amount']) ?></small>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($isPercentage && $it['invoice_amount'] > 0): ?>
                                <?= formatGrantAmount($it['invoice_amount']) ?>
                            <?php else: ?>
                                —
                            <?php endif; ?>
                        </td>
                        <td><?= safeFormatDate($it['grant_date']) ?></td>
                        <td><?= htmlspecialchars($it['grant_notes'] ?? '-') ?></td>
                        <td>
                            <?php if ($status['class'] == 'badge-warning'): ?>
                                <span class="badge-warning"><?= $status['text'] ?></span>
                            <?php elseif ($status['class'] == 'badge-percentage'): ?>
                                <span class="badge-percentage"><?= $status['text'] ?></span>
                            <?php else: ?>
                                <span class="badge-fixed"><?= $status['text'] ?></span>
                            <?php endif; ?>
                        </td>
                        <?php if ($showActions): ?>
                            <td>
                                <?php if ($isOutdated && $it['current_amount'] > 0): ?>
                                    <form method="POST" style="display:inline;" novalidate>
                                        <input type="hidden" name="csrf_token" value="<?= $csrf_token ?>">
                                        <input type="hidden" name="grant_id" value="<?= $it['id'] ?>">
                                        <input type="hidden" name="new_amount" value="<?= $it['current_amount'] ?>">
                                        <input type="hidden" name="search" value="<?= htmlspecialchars($search) ?>">
                                        <input type="hidden" name="grant_filter" value="<?= $grant_filter ?>">
                                        <button type="submit" name="update_grant" class="btn-update">🔄 تحديث</button>
                                    </form>
                                <?php elseif ($isOutdated && $it['current_amount'] <= 0): ?>
                                    <form method="POST" style="display:inline;" novalidate>
                                        <input type="hidden" name="csrf_token" value="<?= $csrf_token ?>">
                                        <input type="hidden" name="grant_id" value="<?= $it['id'] ?>">
                                        <input type="hidden" name="new_amount" value="<?= $it['stored_amount'] ?>">
                                        <input type="hidden" name="search" value="<?= htmlspecialchars($search) ?>">
                                        <input type="hidden" name="grant_filter" value="<?= $grant_filter ?>">
                                        <button type="submit" name="update_grant" class="btn-update">🔧 تعيين القيمة</button>
                                    </form>
                                <?php endif; ?>
                                <?php if ($isPercentage && $it['invoice_amount'] > 0): ?>
                                    <form method="POST" style="display:inline;" novalidate>
                                        <input type="hidden" name="csrf_token" value="<?= $csrf_token ?>">
                                        <input type="hidden" name="grant_id" value="<?= $it['id'] ?>">
                                        <input type="hidden" name="search" value="<?= htmlspecialchars($search) ?>">
                                        <input type="hidden" name="grant_filter" value="<?= $grant_filter ?>">
                                        <button type="submit" name="recalc_grant" class="btn-recalc">🧮 إعادة حساب</button>
                                    </form>
                                <?php endif; ?>
                                <button class="btn-delete" onclick="openDeleteModal(<?= $it['id'] ?>, '<?= addslashes($it['employee_name']) ?>')">🗑️ حذف</button>
                            </td>
                        <?php endif; ?>
                    </tr>
                <?php endforeach; ?>
                <tr class="total-row">
                    <td colspan="<?= $showActions ? 3 : 3 ?>"><strong>الإجمالي</strong></td>
                    <td colspan="<?= $showActions ? 6 : 5 ?>"><strong><?= formatGrantAmount($total) ?></strong></td>
                </tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
        <?php
    }
}