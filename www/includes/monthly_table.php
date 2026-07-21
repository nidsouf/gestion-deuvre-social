<?php
if (!function_exists('renderMonthlyTable')) {
    function renderMonthlyTable($items, $title, $total, $showPayButton = true, $allItems = [], $installments = [], $month_name_ar = '') {
        ?>
        <div class="section-title"><?= $title ?></div>
        <table class="data-table">
            <thead>
                <tr>
                    <th>#</th>
                    <th>الموظف</th>
                    <th>المصدر</th>
                    <th>المبلغ (دج)</th>
                    <th>النوع</th>
                    <th>الحالة</th>
                    <?php if ($showPayButton): ?>
                        <th>تسديد</th>
                    <?php endif; ?>
                </tr>
            </thead>
            <tbody>
            <?php if (empty($items)): ?>
                <tr><td colspan="<?= $showPayButton ? 7 : 6 ?>" style="text-align:center;">لا توجد بيانات</td></tr>
            <?php else: $i=1; foreach($items as $it):
                $amount = $it['total_amount'];
                $typeLabel = getTypeLabel($it);
                $hasUnpaid = false;
                if ($showPayButton && $it['source_name'] != 'Djezzy') {
                    foreach ($installments as $orig) {
                        if ($orig['employee_id'] == $it['employee_id'] && $orig['source_name'] == $it['source_name'] && $orig['is_paid'] == 0) {
                            $hasUnpaid = true;
                            break;
                        }
                    }
                }
                $status = getStatusLabel($it, $hasUnpaid);
                $rowClass = ($it['source_name'] == 'Djezzy') 
                    ? 'djezzy-row' 
                    : ($hasUnpaid ? '' : 'paid-row');
                
                $installment_id_for_pay = 0;
                if ($hasUnpaid && $showPayButton) {
                    foreach ($installments as $orig) {
                        if ($orig['employee_id'] == $it['employee_id'] && $orig['source_name'] == $it['source_name'] && $orig['is_paid'] == 0) {
                            $installment_id_for_pay = $orig['installment_id'];
                            break;
                        }
                    }
                }
                $canPay = ($hasUnpaid && $it['source_name'] != 'Djezzy' && $showPayButton);
            ?>
                <tr class="<?= $rowClass ?>">
                    <td><?= $i++ ?></td>
                    <td><?= htmlspecialchars($it['employee_name']) ?></td>
                    <td><?= htmlspecialchars($it['source_name']) ?></td>
                    <td><?= formatAmount($amount) ?></td>
                    <td><?= $typeLabel ?></td>
                    <td><span class="<?= $status['class'] ?>"><?= $status['text'] ?></span></td>
                    <?php if ($showPayButton): ?>
                        <td>
                            <?php if ($canPay): ?>
                                <button type="button" class="btn-pay" onclick="openPayModal(<?= $installment_id_for_pay ?>, '<?= htmlspecialchars($it['employee_name']) ?>', '<?= $month_name_ar ?>', '<?= formatAmount($amount) ?>')">
                                    💰 تسديد
                                </button>
                            <?php else: ?>
                                <span class="btn-pay-disabled">✔ تم</span>
                            <?php endif; ?>
                        </td>
                    <?php endif; ?>
                </tr>
            <?php endforeach; ?>
            <tr class="total-row">
                <td colspan="<?= $showPayButton ? 3 : 3 ?>"><strong>الإجمالي</strong></td>
                <td colspan="<?= $showPayButton ? 4 : 3 ?>"><strong><?= formatAmount($total) ?></strong></td>
            </tr>
            <?php endif; ?>
            </tbody>
        </table>
        <?php
    }
}