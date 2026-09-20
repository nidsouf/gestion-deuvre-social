<?php
/**
 * includes/grant_table.php - دالة عرض جدول المنح
 * مع إصلاح زر الحذف (رابط مباشر إلى صفحة التأكيد)
 */

/**
 * عرض جدول منح لفئة معينة
 * 
 * @param array  $grants       قائمة المنح
 * @param string $title        عنوان الجدول
 * @param float  $totalAmount  إجمالي المبلغ
 * @param bool   $showActions  إظهار أزرار الإجراءات
 * @param string $csrf_token   رمز CSRF
 * @param string $search       فلتر البحث
 * @param int    $grant_filter فلتر نوع المنحة
 */
function renderGrantTable($grants, $title, $totalAmount, $showActions = true, $csrf_token = '', $search = '', $grant_filter = 0) {
    if (empty($grants)) {
        echo '<div style="background:#f8f9fa; padding:15px; border-radius:10px; margin-bottom:15px; text-align:center; color:#6c757d;">';
        echo '📭 ' . htmlspecialchars($title) . ' - لا توجد منح';
        echo '</div>';
        return;
    }
    ?>

    <div class="table-wrapper" style="margin-bottom: 30px;">
        <h3 style="background: linear-gradient(135deg, #1E5A4A, #2E7D64); color: white; padding: 12px 20px; border-radius: 10px 10px 0 0; margin: 0;">
            <?= htmlspecialchars($title) ?> (<?= count($grants) ?>)
            <span style="float: left; font-size: 16px;">
                الإجمالي: <?= number_format($totalAmount, 2) ?> دج
            </span>
        </h3>

        <table class="data-table" style="width: 100%; border-collapse: collapse;">
            <thead>
                <tr style="background: #f8f9fa;">
                    <th style="padding: 10px; border: 1px solid #dee2e6;">#</th>
                    <th style="padding: 10px; border: 1px solid #dee2e6;">الموظف</th>
                    <th style="padding: 10px; border: 1px solid #dee2e6;">نوع المنحة</th>
                    <th style="padding: 10px; border: 1px solid #dee2e6;">المبلغ (دج)</th>
                    <th style="padding: 10px; border: 1px solid #dee2e6;">تاريخ المنح</th>
                    <th style="padding: 10px; border: 1px solid #dee2e6;">ملاحظات</th>
                    <?php if ($showActions): ?>
                        <th style="padding: 10px; border: 1px solid #dee2e6;">الإجراءات</th>
                    <?php endif; ?>
                </tr>
            </thead>
            <tbody>
                <?php 
                $i = 1;
                foreach ($grants as $g): 
                    $amount = ($g['stored_amount'] > 0) ? $g['stored_amount'] : $g['current_amount'];
                    $isPercentage = ($g['calculation_type'] === 'percentage');
                ?>
                    <tr style="<?= $isPercentage ? 'background:#f8f0ff;' : '' ?>">
                        <td style="padding: 8px; border: 1px solid #dee2e6; text-align: center;"><?= $i++ ?></td>
                        <td style="padding: 8px; border: 1px solid #dee2e6;">
                            <strong><?= htmlspecialchars($g['employee_name']) ?></strong>
                            <br>
                            <small style="color: #6c757d;">
                                <?= $g['category'] === 'Permanent' ? '👔 دائم' : '👕 متعاقد' ?>
                            </small>
                        </td>
                        <td style="padding: 8px; border: 1px solid #dee2e6;">
                            <?= htmlspecialchars($g['grant_name']) ?>
                            <?php if ($isPercentage): ?>
                                <br><small style="color: #6c3483;">
                                    (<?= $g['percentage_value'] ?>%
                                    <?php if ($g['max_amount'] > 0): ?>
                                        - حد أقصى: <?= number_format($g['max_amount'], 2) ?>
                                    <?php endif; ?>)
                                </small>
                            <?php endif; ?>
                        </td>
                        <td style="padding: 8px; border: 1px solid #dee2e6; text-align: center; font-weight: bold; color: #1E5A4A;">
                            <?= number_format($amount, 2) ?> دج
                            <?php if ($g['invoice_amount'] > 0): ?>
                                <br><small style="color: #6c757d;">
                                    (فاتورة: <?= number_format($g['invoice_amount'], 2) ?>)
                                </small>
                            <?php endif; ?>
                        </td>
                        <td style="padding: 8px; border: 1px solid #dee2e6; text-align: center;">
                            <?= date('d/m/Y', strtotime($g['grant_date'])) ?>
                        </td>
                        <td style="padding: 8px; border: 1px solid #dee2e6; font-size: 13px; color: #6c757d;">
                            <?= htmlspecialchars($g['grant_notes'] ?? '—') ?>
                        </td>
                        <?php if ($showActions): ?>
                            <td style="padding: 8px; border: 1px solid #dee2e6; text-align: center;">
                                <!-- ✏️ زر التعديل -->
                                <a href="edit_employee_grant.php?id=<?= $g['id'] ?>" 
                                   style="display:inline-block; padding: 5px 12px; background: #ffc107; color: #212529; text-decoration: none; border-radius: 5px; font-size: 12px; font-weight: bold; margin: 2px;">
                                    ✏️ تعديل
                                </a>
                                
                                <!-- 🗑️ زر الحذف - رابط مباشر لصفحة التأكيد -->
                                <a href="delete_employee_grant.php?id=<?= $g['id'] ?>" 
                                   style="display:inline-block; padding: 5px 12px; background: #dc3545; color: white; text-decoration: none; border-radius: 5px; font-size: 12px; font-weight: bold; margin: 2px;">
                                    🗑️ حذف
                                </a>
                            </td>
                        <?php endif; ?>
                    </tr>
                <?php endforeach; ?>
            </tbody>
            <tfoot>
                <tr style="background: #e8f5e9; font-weight: bold;">
                    <td colspan="<?= $showActions ? 6 : 5 ?>" style="padding: 10px; border: 1px solid #dee2e6; text-align: left;">
                        الإجمالي:
                    </td>
                    <td style="padding: 10px; border: 1px solid #dee2e6; text-align: center; color: #1E5A4A; font-size: 16px;">
                        <?= number_format($totalAmount, 2) ?> دج
                    </td>
                    <?php if ($showActions): ?>
                        <td style="padding: 10px; border: 1px solid #dee2e6;"></td>
                    <?php endif; ?>
                </tr>
            </tfoot>
        </table>
    </div>

    <?php
}