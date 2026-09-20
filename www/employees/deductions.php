<?php
/**
 * employees/view.php - عرض تفاصيل الموظف مع قائمة الاقتطاعات
 * مع بطاقات إحصائية للاقتطاعات النشطة حسب المصدر
 */
session_start();
require_once '../includes/auth_check.php';
require_once '../config/database.php';
require_once '../includes/security.php';
require_once '../includes/functions.php';

$employeeId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if (!$employeeId) {
    header('Location: list.php');
    exit;
}

// جلب بيانات الموظف
$stmt = $pdo->prepare("SELECT * FROM employees WHERE id = ?");
$stmt->execute([$employeeId]);
$employee = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$employee) {
    header('Location: list.php?error=notfound');
    exit;
}

// ============================================================
// جلب الاقتطاعات مع حساب التقدم
// ============================================================
$stmt = $pdo->prepare("
    SELECT 
        d.*,
        s.name as source_name,
        COALESCE((
            SELECT COUNT(*) 
            FROM monthly_installments mi 
            WHERE mi.deduction_id = d.id AND mi.is_paid = 1
        ), 0) as paid_installments,
        COALESCE((
            SELECT COUNT(*) 
            FROM monthly_installments mi 
            WHERE mi.deduction_id = d.id
        ), d.total_months) as total_installments
    FROM deductions d
    JOIN sources s ON d.source_id = s.id
    WHERE d.employee_id = ?
    ORDER BY d.created_at DESC
");
$stmt->execute([$employeeId]);
$deductions = $stmt->fetchAll(PDO::FETCH_ASSOC);

// ============================================================
// إحصائيات الاقتطاعات النشطة حسب المصدر
// ============================================================
$stmtStats = $pdo->prepare("
    SELECT 
        s.id as source_id,
        s.name as source_name,
        COUNT(d.id) as count,
        COALESCE(SUM(
            CASE 
                WHEN d.remaining_months IS NOT NULL AND d.remaining_months > 0 
                    THEN d.monthly_amount * d.remaining_months
                WHEN d.total_months > COALESCE(d.paid_months, 0)
                    THEN d.monthly_amount * (d.total_months - COALESCE(d.paid_months, 0))
                ELSE COALESCE(d.credit_balance, 0)
            END
        ), 0) as total_remaining,
        COALESCE(SUM(d.monthly_amount), 0) as total_monthly
    FROM deductions d
    JOIN sources s ON d.source_id = s.id
    WHERE d.employee_id = ? 
      AND d.end_date >= date('now')
    GROUP BY s.id, s.name
    ORDER BY total_remaining DESC
");
$stmtStats->execute([$employeeId]);
$sourceStats = $stmtStats->fetchAll(PDO::FETCH_ASSOC);

// الإجماليات الكلية
$grandTotalMonthly   = array_sum(array_column($sourceStats, 'total_monthly'));
$grandTotalRemaining = array_sum(array_column($sourceStats, 'total_remaining'));
$grandTotalCount     = array_sum(array_column($sourceStats, 'count'));

$pageTitle = 'تفاصيل الموظف';
include '../includes/header.php';
?>

<style>
    /* ============================================================
       أنماط مخصصة
       ============================================================ */
    .progress-bar-wrapper {
        min-width: 100px;
    }
    .progress {
        height: 8px;
        border-radius: 4px;
        background: #e9ecef;
    }
    .progress-bar {
        border-radius: 4px;
        transition: width 0.6s ease;
    }
    .progress-label {
        font-size: 12px;
        color: #6c757d;
        display: block;
        text-align: center;
        margin-top: 2px;
    }
    .badge-type {
        padding: 3px 10px;
        border-radius: 12px;
        font-size: 11px;
        font-weight: 600;
    }
    .badge-type.loan {
        background: #fff3cd;
        color: #856404;
    }
    .badge-type.regular {
        background: #d1ecf1;
        color: #0c5460;
    }
    .table td {
        vertical-align: middle;
    }

    /* ============================================================
       أزرار الإجراءات المحسّنة (أيقونات فقط)
       ============================================================ */
    .btn-group .btn {
        border-radius: 0;
        padding: 4px 10px;
        font-size: 14px;
        transition: all 0.2s ease;
        border-width: 1.5px;
        background: transparent;
    }
    .btn-group .btn:first-child {
        border-radius: 4px 0 0 4px;
    }
    .btn-group .btn:last-child {
        border-radius: 0 4px 4px 0;
    }
    .btn-group .btn:hover {
        transform: scale(1.08);
        box-shadow: 0 2px 10px rgba(0,0,0,0.15);
        z-index: 2;
    }
    .btn-group .btn-outline-info {
        color: #17a2b8;
        border-color: #17a2b8;
    }
    .btn-group .btn-outline-info:hover {
        background: #17a2b8;
        color: #fff;
    }
    .btn-group .btn-outline-warning {
        color: #ffc107;
        border-color: #ffc107;
    }
    .btn-group .btn-outline-warning:hover {
        background: #ffc107;
        color: #212529;
    }
    .btn-group .btn-outline-danger {
        color: #dc3545;
        border-color: #dc3545;
    }
    .btn-group .btn-outline-danger:hover {
        background: #dc3545;
        color: #fff;
    }
    .btn-group .btn:focus {
        box-shadow: none;
    }

    /* تحسين مظهر المودال */
    .modal-content {
        border: none;
        border-radius: 16px;
        box-shadow: 0 10px 40px rgba(0,0,0,0.2);
    }
    .modal-header {
        border-radius: 16px 16px 0 0;
    }

    /* ============================================================
       شبكة البطاقات الإحصائية
       ============================================================ */
    .stats-grid {
        display: grid;
        grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
        gap: 15px;
    }

    .stat-card {
        background: white;
        border-radius: 16px;
        padding: 20px 18px;
        box-shadow: 0 3px 12px rgba(0,0,0,0.08);
        transition: all 0.3s ease;
        position: relative;
        overflow: hidden;
    }

    .stat-card:hover {
        transform: translateY(-3px);
        box-shadow: 0 6px 20px rgba(0,0,0,0.15);
    }

    .stat-card .stat-icon {
        font-size: 28px;
        margin-bottom: 8px;
        display: block;
        opacity: 0.95;
    }

    .stat-card .stat-label {
        font-size: 13px;
        font-weight: 600;
        margin-bottom: 6px;
        line-height: 1.3;
    }

    .stat-card .stat-value {
        font-size: 20px;
        font-weight: 700;
        line-height: 1.2;
        margin-bottom: 4px;
    }

    .stat-card small {
        font-size: 11px;
        display: block;
        line-height: 1.4;
    }

    @media (prefers-color-scheme: dark) {
        .stat-card {
            box-shadow: 0 3px 12px rgba(0,0,0,0.3);
        }
    }
</style>

<!-- ========================================================== -->
<!-- المحتوى الرئيسي -->
<!-- ========================================================== -->
<div class="container mt-4">
    <!-- معلومات الموظف -->
    <div class="card mb-4">
        <div class="card-header bg-primary text-white">
            <h4 class="mb-0">👤 تفاصيل الموظف</h4>
        </div>
        <div class="card-body">
            <div class="row">
                <div class="col-md-6">
                    <p><strong>الاسم:</strong> <?= htmlspecialchars($employee['name']) ?></p>
                    <p><strong>رقم الحساب:</strong> <?= htmlspecialchars($employee['account_number'] ?? '—') ?></p>
                    <p><strong>نوع العقد:</strong> <?= $employee['category'] == 'permanent' ? 'دائم' : 'مت�eعاقد' ?></p>
                </div>
                <div class="col-md-6">
                    <p><strong>تاريخ التعيين:</strong> <?= safeFormatDate($employee['hire_date']) ?></p>
                    <p><strong>بدل الوجبات:</strong> <?= number_format($employee['meal_allowance'] ?? 0, 2) ?> دج</p>
                    <p><strong>عدد الاقتطاعات:</strong> <?= count($deductions) ?></p>
                </div>
            </div>
        </div>
    </div>

    <!-- ========================================================== -->
    <!-- بطاقات إحصائية: الاقتطاعات النشطة حسب المصدر -->
    <!-- ========================================================== -->
    <div class="stats-grid mb-4">
        <!-- بطاقة إجمالي الاقتطاعات النشطة -->
        <div class="stat-card" style="background: linear-gradient(135deg, #2a5298, #1e3c72); color: white;">
            <div class="stat-icon">📊</div>
            <div class="stat-label" style="color: rgba(255,255,255,0.95);">إجمالي الاقتطاعات النشطة</div>
            <div class="stat-value" style="color: white; font-weight: 700;"><?= number_format($grandTotalMonthly, 2) ?> دج</div>
            <small style="color: rgba(255,255,255,0.85);">شهرياً</small>
        </div>

        <!-- بطاقة الرصيد المتبقي الكلي -->
        <div class="stat-card" style="background: linear-gradient(135deg, #e67e22, #d35400); color: white;">
            <div class="stat-icon">💰</div>
            <div class="stat-label" style="color: rgba(255,255,255,0.95);">إجمالي الرصيد المتبقي</div>
            <div class="stat-value" style="color: white; font-weight: 700;"><?= number_format($grandTotalRemaining, 2) ?> دج</div>
            <small style="color: rgba(255,255,255,0.85);"><?= $grandTotalCount ?> اقتطاع نشط</small>
        </div>

        <!-- بطاقات المصادر (ديناميكية) -->
        <?php foreach ($sourceStats as $src): 
            $hash = md5($src['source_name']);
            $color1 = substr($hash, 0, 6);
            $color2 = substr($hash, 6, 6);
        ?>
            <div class="stat-card" style="background: linear-gradient(135deg, #<?= $color1 ?>, #<?= $color2 ?>); color: #ffffff; text-shadow: 0 1px 3px rgba(0,0,0,0.4);">
                <div class="stat-icon" style="color:#ffffff; opacity:0.9;">🏦</div>
                <div class="stat-label" style="color:#ffffff; font-weight:600; font-size:14px;">
                    <?= htmlspecialchars($src['source_name']) ?>
                </div>
                <div class="stat-value" style="color:#ffffff; font-weight:700; font-size:22px;">
                    <?= number_format($src['total_remaining'], 2) ?> دج
                </div>
                <small style="color:#ffffff; opacity:0.9; font-weight:500;">
                    <?= $src['count'] ?> اقتطاع • شهرياً: <?= number_format($src['total_monthly'], 2) ?> دج
                </small>
            </div>
        <?php endforeach; ?>

        <!-- إذا لم توجد اقتطاعات نشطة -->
        <?php if (empty($sourceStats)): ?>
            <div class="stat-card" style="background: #f8f9fa; border: 2px dashed #ccc; color: #666;">
                <div class="stat-icon">ℹ️</div>
                <div class="stat-label">لا توجد اقتطاعات نشطة</div>
                <div class="stat-value" style="font-size: 16px;">—</div>
            </div>
        <?php endif; ?>
    </div>

    <!-- قائمة الاقتطاعات -->
    <div class="card">
        <div class="card-header bg-success text-white d-flex justify-content-between align-items-center">
            <h5 class="mb-0">📋 قائمة الاقتطاعات</h5>
            <a href="../deductions/add.php?employee_id=<?= $employeeId ?>" class="btn btn-light btn-sm">
                ➕ إضافة اقتطاع
            </a>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-striped table-hover mb-0">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>المصدر</th>
                            <th>المبلغ الشهري</th>
                            <th>عدد الأشهر</th>
                            <th>الرصيد المتبقي</th>
                            <th>تاريخ البداية</th>
                            <th>تاريخ النهاية</th>
                            <th>الأقساط</th>
                            <th>التقدم</th>
                            <th>الحالة</th>
                            <th>الإجراءات</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($deductions)): ?>
                            <tr>
                                <td colspan="11" class="text-center py-4">لا توجد اقتطاعات لهذا الموظف</td>
                            </tr>
                        <?php else: 
                            $index = 1;
                            foreach ($deductions as $d):
                                $isActive = strtotime($d['end_date']) >= time();
                                $isLoan = $d['is_loan'] == 1;
                                
                                $total = (int)($d['total_installments'] ?? $d['total_months']);
                                $paid = (int)($d['paid_installments'] ?? 0);
                                $unpaid = $total - $paid;
                                $progress = $total > 0 ? round(($paid / $total) * 100) : 0;
                                $progressColor = $progress >= 80 ? 'bg-success' : ($progress >= 50 ? 'bg-warning' : 'bg-danger');
                        ?>
                            <tr>
                                <td><?= $index++ ?></td>
                                <td><?= htmlspecialchars($d['source_name'] ?? '—') ?></td>
                                <td><?= number_format($d['monthly_amount'], 2) ?> دج</td>
                                <td><?= $d['total_months'] ?> شهر</td>
                                <td><?= number_format($d['credit_balance'] ?? 0, 2) ?> دج</td>
                                <td><?= safeFormatDate($d['start_date']) ?></td>
                                <td><?= safeFormatDate($d['end_date']) ?></td>
                                <td>
                                    <span class="badge <?= $unpaid > 0 ? 'bg-warning' : 'bg-success' ?>">
                                        <?= $paid ?> / <?= $total ?>
                                    </span>
                                    <?php if ($unpaid > 0): ?>
                                        <br><small class="text-danger">(<?= $unpaid ?> متبقية)</small>
                                    <?php else: ?>
                                        <br><small class="text-success">✓ مكتملة</small>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($isActive): ?>
                                        <div class="progress-bar-wrapper">
                                            <div class="progress">
                                                <div class="progress-bar <?= $progressColor ?>" 
                                                     role="progressbar" 
                                                     style="width: <?= $progress ?>%;"
                                                     aria-valuenow="<?= $progress ?>" 
                                                     aria-valuemin="0" 
                                                     aria-valuemax="100">
                                                </div>
                                            </div>
                                            <span class="progress-label"><?= $progress ?>%</span>
                                        </div>
                                    <?php else: ?>
                                        <span class="text-muted">—</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($isActive): ?>
                                        <span class="badge bg-success">نشط</span>
                                    <?php else: ?>
                                        <span class="badge bg-secondary">منتهي</span>
                                    <?php endif; ?>
                                    <?php if ($isLoan): ?>
                                        <span class="badge bg-warning text-dark ms-1">سلفة</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <!-- أزرار الإجراءات المحسّنة (أيقونات فقط) -->
                                    <div class="btn-group btn-group-sm" role="group" aria-label="إجراءات الاقتطاع">
                                        <a href="../deductions/view.php?id=<?= $d['id'] ?>" 
                                           class="btn btn-outline-info" 
                                           title="عرض التفاصيل">
                                            <i class="fas fa-eye"></i>
                                        </a>
                                        <a href="../deductions/edit.php?id=<?= $d['id'] ?>" 
                                           class="btn btn-outline-warning" 
                                           title="تعديل الاقتطاع">
                                            <i class="fas fa-edit"></i>
                                        </a>
                                        <button type="button" 
                                                class="btn btn-outline-danger delete-btn" 
                                                data-id="<?= $d['id'] ?>" 
                                                data-employee-name="<?= htmlspecialchars($employee['name']) ?>"
                                                title="حذف الاقتطاع">
                                            <i class="fas fa-trash-alt"></i>
                                        </button>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <div class="mt-3">
        <a href="list.php" class="btn btn-secondary">⬅️ العودة إلى قائمة الموظفين</a>
    </div>
</div>

<!-- ========================================================== -->
<!-- مودال تأكيد الحذف -->
<!-- ========================================================== -->
<div class="modal fade" id="deleteModal" tabindex="-1" aria-hidden="true" style="display: none;">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header bg-danger text-white">
                <h5 class="modal-title">⚠️ تأكيد الحذف</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body text-center">
                <p style="font-size: 18px;">
                    هل أنت متأكد من حذف الاقتطاع الخاص بـ 
                    <strong id="deleteEmployeeName" style="color: #1E5A4A; font-size: 20px;"></strong>
                    ؟
                </p>
                <p class="text-danger"><small>سيتم استرجاع المبلغ المتبقي إلى الميزانية تلقائياً.</small></p>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">إلغاء</button>
                <form method="POST" action="../deductions/delete.php" id="deleteForm">
                    <input type="hidden" name="id" id="deleteId">
                    <input type="hidden" name="csrf_token" value="<?= generateCSRFToken() ?>">
                    <button type="submit" class="btn btn-danger">🗑️ حذف</button>
                </form>
            </div>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // تفعيل مودال الحذف
    const deleteModal = new bootstrap.Modal(document.getElementById('deleteModal'));
    const deleteIdInput = document.getElementById('deleteId');
    const deleteNameSpan = document.getElementById('deleteEmployeeName');

    document.querySelectorAll('.delete-btn').forEach(function(btn) {
        btn.addEventListener('click', function() {
            const id = this.getAttribute('data-id');
            const name = this.getAttribute('data-employee-name');
            
            deleteIdInput.value = id;
            deleteNameSpan.textContent = name || 'غير معروف';
            
            deleteModal.show();
        });
    });
});
</script>

<?php include '../includes/footer.php'; ?>