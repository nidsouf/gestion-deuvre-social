<?php
/**
 * archive_view.php - عرض الأرشيف (الاقتطاعات المؤرشفة)
 */
session_start();
require_once 'includes/auth_check.php';
require_once 'config/database.php';
require_once 'includes/security.php';
require_once 'includes/functions.php';

// صلاحية المدير فقط
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    setToast('⚠️ غير مصرح لك', 'warning');
    header('Location: index.php');
    exit;
}

// ============================================================
// معالجة الإجراءات
// ============================================================
$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCSRFToken();
    $action = $_POST['action'] ?? '';
    
    // ============================================================
    // استعادة من الأرشيف
    // ============================================================
    if ($action === 'restore') {
        $archiveId = (int)($_POST['archive_id'] ?? 0);
        
        try {
            $pdo->beginTransaction();
            
            // جلب بيانات الأرشيف
            $stmt = $pdo->prepare("SELECT * FROM deductions_archive WHERE archive_id = ?");
            $stmt->execute([$archiveId]);
            $arch = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$arch) throw new Exception('سجل الأرشيف غير موجود');
            
            // التحقق من عدم وجوده في الجدول الأصلي
            $check = $pdo->prepare("SELECT id FROM deductions WHERE id = ?");
            $check->execute([$arch['original_id']]);
            if ($check->fetchColumn()) {
                throw new Exception('الاقتطاع موجود مسبقاً في الجدول الأصلي');
            }
            
            // استعادة الاقتطاع
            $pdo->prepare("
                INSERT INTO deductions (
                    id, employee_id, source_id, monthly_amount, total_months,
                    start_date, end_date, is_loan, created_at, grant_date, updated_at,
                    included_in_minute_id, paid_months, remaining_months, credit_balance, notes
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ")->execute([
                $arch['original_id'],
                $arch['employee_id'],
                $arch['source_id'],
                $arch['monthly_amount'],
                $arch['total_months'],
                $arch['start_date'],
                $arch['end_date'],
                $arch['is_loan'],
                $arch['created_at'],
                $arch['grant_date'],
                $arch['updated_at'],
                $arch['included_in_minute_id'],
                $arch['paid_months'],
                $arch['remaining_months'],
                $arch['credit_balance'],
                $arch['notes']
            ]);
            
            // استعادة الأقساط
            $instStmt = $pdo->prepare("SELECT * FROM monthly_installments_archive WHERE deduction_original_id = ?");
            $instStmt->execute([$arch['original_id']]);
            $insts = $instStmt->fetchAll(PDO::FETCH_ASSOC);
            
            $insertInst = $pdo->prepare("
                INSERT INTO monthly_installments (
                    id, deduction_id, employee_id, source_id, year, month,
                    amount, is_paid, paid_date, created_at, is_postponed, postponed_from_month
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            
            foreach ($insts as $inst) {
                $insertInst->execute([
                    $inst['original_id'],
                    $arch['original_id'],
                    $inst['employee_id'],
                    $inst['source_id'],
                    $inst['year'],
                    $inst['month'],
                    $inst['amount'],
                    $inst['is_paid'],
                    $inst['paid_date'],
                    $inst['created_at'],
                    $inst['is_postponed'],
                    $inst['postponed_from_month']
                ]);
            }
            
            // حذف من الأرشيف
            $pdo->prepare("DELETE FROM monthly_installments_archive WHERE deduction_original_id = ?")->execute([$arch['original_id']]);
            $pdo->prepare("DELETE FROM deductions_archive WHERE archive_id = ?")->execute([$archiveId]);
            
            $pdo->commit();
            setToast("✅ تمت استعادة الاقتطاع و $(" . count($insts) . ") قسط بنجاح", 'success');
            header('Location: archive_view.php');
            exit;
            
        } catch (Exception $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            setToast('❌ ' . $e->getMessage(), 'error');
            header('Location: archive_view.php');
            exit;
        }
    }
    
    // ============================================================
    // حذف نهائي من الأرشيف
    // ============================================================
    if ($action === 'delete_permanent') {
        $archiveId = (int)($_POST['archive_id'] ?? 0);
        
        try {
            $pdo->beginTransaction();
            
            $stmt = $pdo->prepare("SELECT original_id FROM deductions_archive WHERE archive_id = ?");
            $stmt->execute([$archiveId]);
            $originalId = $stmt->fetchColumn();
            
            if (!$originalId) throw new Exception('سجل غير موجود');
            
            $pdo->prepare("DELETE FROM monthly_installments_archive WHERE deduction_original_id = ?")->execute([$originalId]);
            $pdo->prepare("DELETE FROM deductions_archive WHERE archive_id = ?")->execute([$archiveId]);
            
            $pdo->commit();
            setToast('🗑️ تم الحذف النهائي من الأرشيف', 'success');
            header('Location: archive_view.php');
            exit;
            
        } catch (Exception $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            setToast('❌ ' . $e->getMessage(), 'error');
            header('Location: archive_view.php');
            exit;
        }
    }
    
    // ============================================================
    // تفريغ الأرشيف بالكامل
    // ============================================================
    if ($action === 'empty_archive') {
        try {
            $pdo->beginTransaction();
            $count = $pdo->query("SELECT COUNT(*) FROM deductions_archive")->fetchColumn();
            $pdo->exec("DELETE FROM monthly_installments_archive");
            $pdo->exec("DELETE FROM deductions_archive");
            $pdo->commit();
            setToast("🗑️ تم حذف $count سجل من الأرشيف نهائياً", 'success');
            header('Location: archive_view.php');
            exit;
        } catch (Exception $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            setToast('❌ ' . $e->getMessage(), 'error');
            header('Location: archive_view.php');
            exit;
        }
    }
}

// ============================================================
// جلب البيانات
// ============================================================
$search = trim($_GET['search'] ?? '');
$sourceFilter = (int)($_GET['source_id'] ?? 0);

$sql = "
    SELECT 
        da.*,
        e.name as employee_name,
        e.category as employee_category,
        s.name as source_name,
        (SELECT COUNT(*) FROM monthly_installments_archive mia WHERE mia.deduction_original_id = da.original_id) as installments_count
    FROM deductions_archive da
    LEFT JOIN employees e ON da.employee_id = e.id
    LEFT JOIN sources s ON da.source_id = s.id
    WHERE 1=1
";

$params = [];

if ($search) {
    $sql .= " AND (e.name LIKE ? OR da.notes LIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
}

if ($sourceFilter > 0) {
    $sql .= " AND da.source_id = ?";
    $params[] = $sourceFilter;
}

$sql .= " ORDER BY da.archived_at DESC";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$archives = $stmt->fetchAll(PDO::FETCH_ASSOC);

// الإحصائيات
$totalArchived = count($archives);
$totalArchivedAmount = array_sum(array_column($archives, 'monthly_amount'));
$totalInstallments = array_sum(array_column($archives, 'installments_count'));

// قائمة المصادر للفلتر
$sources = $pdo->query("SELECT id, name FROM sources ORDER BY name")->fetchAll();

$csrf_token = generateCSRFToken();
$pageTitle = 'الأرشيف';
include 'includes/header.php';
?>

<style>
    .archive-container { max-width: 1400px; margin: 0 auto; padding: 20px; }
    .header-bar { display: flex; justify-content: space-between; align-items: center; margin-bottom: 25px; flex-wrap: wrap; gap: 15px; }
    .header-bar h2 { margin: 0; color: #2a5298; }
    
    .stats-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 15px; margin-bottom: 25px; }
    .stat-card { background: linear-gradient(135deg, #6c757d, #495057); color: white; padding: 18px; border-radius: 15px; text-align: center; }
    .stat-card.info { background: linear-gradient(135deg, #17a2b8, #0c5460); }
    .stat-card.warning { background: linear-gradient(135deg, #f39c12, #e67e22); }
    .stat-card .label { font-size: 13px; opacity: 0.9; }
    .stat-card .value { font-size: 26px; font-weight: bold; margin-top: 5px; }
    
    .filters { background: white; padding: 20px; border-radius: 15px; margin-bottom: 20px; box-shadow: 0 2px 10px rgba(0,0,0,0.05); }
    .filters form { display: flex; gap: 10px; flex-wrap: wrap; align-items: center; }
    .filters input, .filters select { padding: 8px 12px; border: 1px solid #ddd; border-radius: 10px; font-size: 14px; }
    .filters input[type="text"] { flex: 1; min-width: 200px; }
    .btn { padding: 8px 20px; border: none; border-radius: 20px; cursor: pointer; font-weight: bold; font-size: 14px; text-decoration: none; display: inline-block; transition: all 0.2s; }
    .btn-primary { background: #2a5298; color: white; }
    .btn-primary:hover { background: #1e3c72; color: white; }
    .btn-secondary { background: #6c757d; color: white; }
    .btn-secondary:hover { background: #5a6268; color: white; }
    .btn-warning { background: #ffc107; color: #212529; }
    .btn-warning:hover { background: #e0a800; color: #212529; }
    .btn-danger { background: #dc3545; color: white; }
    .btn-danger:hover { background: #c82333; color: white; }
    .btn-sm { padding: 5px 12px; font-size: 12px; }
    
    .archive-table { width: 100%; border-collapse: collapse; background: white; border-radius: 15px; overflow: hidden; box-shadow: 0 2px 10px rgba(0,0,0,0.05); }
    .archive-table th { background: #2a5298; color: white; padding: 12px 10px; text-align: center; font-size: 13px; }
    .archive-table td { padding: 10px; border-bottom: 1px solid #eee; text-align: center; font-size: 13px; }
    .archive-table tr:hover td { background: #f8f9fa; }
    .archive-table tr:last-child td { border-bottom: none; }
    
    .empty-state { text-align: center; padding: 60px 20px; background: white; border-radius: 15px; color: #6c757d; }
    .empty-state .icon { font-size: 64px; margin-bottom: 15px; }
    
    .info-banner { background: #e3f2fd; padding: 15px 20px; border-radius: 12px; margin-bottom: 20px; border-right: 5px solid #2196f3; font-size: 14px; line-height: 1.8; }
    .danger-banner { background: #fff3cd; padding: 15px 20px; border-radius: 12px; margin-bottom: 20px; border-right: 5px solid #ffc107; font-size: 14px; }
    
    .action-buttons { display: flex; gap: 5px; justify-content: center; }
    
    /* Modal */
    .modal-content { border-radius: 20px; border: none; direction: rtl; }
    .modal-header { border-radius: 20px 20px 0 0; padding: 20px 25px; }
    .modal-body { padding: 25px; }
    .modal-footer { padding: 15px 25px; gap: 10px; }
    
    .modal-icon { font-size: 48px; display: block; margin-bottom: 15px; text-align: center; }
    
    .details-box {
        background: #f8f9fa;
        padding: 15px;
        border-radius: 10px;
        margin: 15px 0;
        text-align: right;
        font-size: 13px;
    }
    .details-box .row {
        display: flex;
        justify-content: space-between;
        padding: 5px 0;
        border-bottom: 1px dotted #ddd;
    }
    .details-box .row:last-child { border-bottom: none; }
    .details-box .label { font-weight: 700; color: #555; }
    .details-box .value { color: #1a1a2e; font-weight: 600; }
</style>

<div class="archive-container">
    <div class="header-bar">
        <h2>📦 أرشيف الاقتطاعات</h2>
        <div>
            <a href="database_optimize.php" class="btn btn-secondary">🔙 الصيانة</a>
            <a href="index.php" class="btn btn-primary">🏠 الرئيسية</a>
        </div>
    </div>
    
    <div class="info-banner">
        ℹ️ <strong>هذا الأرشيف يحتوي على اقتراطات منتهية.</strong>
        يمكنك <strong>استعادتها</strong> في أي وقت، أو <strong>حذفها نهائياً</strong>.
        يتم الاحتفاظ بالبيانات لأغراض المحاسبة والمراجعة.
    </div>
    
    <!-- إحصائيات -->
    <div class="stats-grid">
        <div class="stat-card">
            <div class="label">📦 إجمالي المؤرشف</div>
            <div class="value"><?= number_format($totalArchived) ?></div>
        </div>
        <div class="stat-card info">
            <div class="label">📋 الأقساط المؤرشفة</div>
            <div class="value"><?= number_format($totalInstallments) ?></div>
        </div>
        <div class="stat-card warning">
            <div class="label">💰 إجمالي المبالغ الشهرية</div>
            <div class="value"><?= number_format($totalArchivedAmount, 0) ?> دج</div>
        </div>
    </div>
    
    <!-- فلاتر -->
    <div class="filters">
        <form method="GET">
            <input type="text" name="search" placeholder="🔍 بحث باسم الموظف أو الملاحظات..." value="<?= htmlspecialchars($search) ?>">
            <select name="source_id">
                <option value="0">جميع المصادر</option>
                <?php foreach ($sources as $s): ?>
                    <option value="<?= $s['id'] ?>" <?= $sourceFilter == $s['id'] ? 'selected' : '' ?>>
                        <?= htmlspecialchars($s['name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <button type="submit" class="btn btn-primary">🔍 بحث</button>
            <?php if ($search || $sourceFilter): ?>
                <a href="archive_view.php" class="btn btn-secondary">🗑️ مسح الفلاتر</a>
            <?php endif; ?>
        </form>
    </div>
    
    <!-- زر تفريغ الأرشيف -->
    <?php if ($totalArchived > 0): ?>
        <div class="danger-banner">
            ⚠️ <strong>منطقة الخطر:</strong>
            يمكنك حذف جميع سجلات الأرشيف نهائياً. هذه العملية <strong>لا يمكن التراجع عنها</strong>.
            <button type="button" class="btn btn-danger btn-sm" style="margin-right: 15px;" 
                    data-bs-toggle="modal" data-bs-target="#emptyArchiveModal">
                🗑️ تفريغ الأرشيف بالكامل
            </button>
        </div>
    <?php endif; ?>
    
    <!-- الجدول -->
    <?php if (empty($archives)): ?>
        <div class="empty-state">
            <div class="icon">📭</div>
            <h3>الأرشيف فارغ</h3>
            <p>لا توجد اقتطاعات مؤرشفة حالياً.</p>
        </div>
    <?php else: ?>
        <div style="overflow-x:auto;">
            <table class="archive-table">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>الموظف</th>
                        <th>الفئة</th>
                        <th>المصدر</th>
                        <th>المبلغ الشهري</th>
                        <th>عدد الأشهر</th>
                        <th>الأقساط</th>
                        <th>تاريخ الانتهاء</th>
                        <th>تاريخ الأرشفة</th>
                        <th>أرشفة بواسطة</th>
                        <th>الإجراءات</th>
                    </tr>
                </thead>
                <tbody>
                    <?php $i = 1; foreach ($archives as $a): ?>
                        <tr>
                            <td><?= $i++ ?></td>
                            <td>
                                <?= htmlspecialchars($a['employee_name'] ?? 'غير معروف') ?>
                                <br>
                                <small style="color:#6c757d;">ID: <?= $a['original_id'] ?></small>
                            </td>
                            <td>
                                <?php if ($a['employee_category'] === 'Permanent'): ?>
                                    <span style="background:#d4edda; color:#155724; padding:3px 10px; border-radius:10px; font-size:11px;">👔 دائم</span>
                                <?php else: ?>
                                    <span style="background:#fff3cd; color:#856404; padding:3px 10px; border-radius:10px; font-size:11px;">👕 متعاقد</span>
                                <?php endif; ?>
                            </td>
                            <td><?= htmlspecialchars($a['source_name'] ?? '—') ?></td>
                            <td><strong><?= number_format($a['monthly_amount'], 2) ?> دج</strong></td>
                            <td><?= $a['total_months'] ?> شهر</td>
                            <td>
                                <span style="background:#17a2b8; color:white; padding:3px 10px; border-radius:10px; font-size:11px;">
                                    <?= $a['installments_count'] ?> قسط
                                </span>
                            </td>
                            <td><?= safeFormatDate($a['end_date']) ?></td>
                            <td><?= safeFormatDate($a['archived_at']) ?></td>
                            <td><small><?= htmlspecialchars($a['archived_by'] ?? '—') ?></small></td>
                            <td>
                                <div class="action-buttons">
                                    <button type="button" class="btn btn-warning btn-sm"
                                            data-bs-toggle="modal" data-bs-target="#restoreModal"
                                            data-archive-id="<?= $a['archive_id'] ?>"
                                            data-employee="<?= htmlspecialchars($a['employee_name'] ?? '') ?>"
                                            data-amount="<?= number_format($a['monthly_amount'], 2) ?>"
                                            data-months="<?= $a['total_months'] ?>"
                                            data-installments="<?= $a['installments_count'] ?>"
                                            data-source="<?= htmlspecialchars($a['source_name'] ?? '') ?>">
                                        🔄 استعادة
                                    </button>
                                    <button type="button" class="btn btn-danger btn-sm"
                                            data-bs-toggle="modal" data-bs-target="#deleteModal"
                                            data-archive-id="<?= $a['archive_id'] ?>"
                                            data-employee="<?= htmlspecialchars($a['employee_name'] ?? '') ?>"
                                            data-amount="<?= number_format($a['monthly_amount'], 2) ?>"
                                            data-months="<?= $a['total_months'] ?>">
                                        🗑️ حذف نهائي
                                    </button>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<!-- ============================================================
     Modal: استعادة من الأرشيف
============================================================ -->
<div class="modal fade" id="restoreModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header" style="background: #ffc107; color: #212529;">
                <h5 class="modal-title">🔄 تأكيد الاستعادة</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="modal-icon">🔄</div>
                <h5 style="text-align:center; margin-bottom:20px;">هل تريد استعادة هذا الاقتطاع؟</h5>
                
                <div class="details-box">
                    <div class="row">
                        <span class="label">👤 الموظف:</span>
                        <span class="value" id="restoreEmployee"></span>
                    </div>
                    <div class="row">
                        <span class="label">💰 المبلغ الشهري:</span>
                        <span class="value" id="restoreAmount"></span>
                    </div>
                    <div class="row">
                        <span class="label">📅 عدد الأشهر:</span>
                        <span class="value" id="restoreMonths"></span>
                    </div>
                    <div class="row">
                        <span class="label">📋 عدد الأقساط:</span>
                        <span class="value" id="restoreInstallments"></span>
                    </div>
                    <div class="row">
                        <span class="label">🏦 المصدر:</span>
                        <span class="value" id="restoreSource"></span>
                    </div>
                </div>
                
                <div style="background:#d4edda; padding:12px; border-radius:10px; font-size:13px; color:#155724; text-align:right;">
                    ✅ سيتم إعادة الاقتطاع وجميع أقساطه إلى الجدول الرئيسي.
                </div>
            </div>
            <div class="modal-footer">
                <form method="POST" style="display:inline;">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
                    <input type="hidden" name="action" value="restore">
                    <input type="hidden" name="archive_id" id="restoreIdInput">
                    <button type="submit" class="btn btn-warning">✅ نعم، استعادة</button>
                </form>
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">إلغاء</button>
            </div>
        </div>
    </div>
</div>

<!-- ============================================================
     Modal: حذف نهائي
============================================================ -->
<div class="modal fade" id="deleteModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header" style="background: #dc3545; color: white;">
                <h5 class="modal-title">🗑️ حذف نهائي</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="modal-icon">⚠️</div>
                <h5 style="text-align:center; margin-bottom:20px;">هل أنت متأكد من الحذف النهائي؟</h5>
                
                <div class="details-box">
                    <div class="row">
                        <span class="label">👤 الموظف:</span>
                        <span class="value" id="deleteEmployee"></span>
                    </div>
                    <div class="row">
                        <span class="label">💰 المبلغ الشهري:</span>
                        <span class="value" id="deleteAmount"></span>
                    </div>
                    <div class="row">
                        <span class="label">📅 عدد الأشهر:</span>
                        <span class="value" id="deleteMonths"></span>
                    </div>
                </div>
                
                <div style="background:#f8d7da; padding:12px; border-radius:10px; font-size:13px; color:#721c24; text-align:right;">
                    ⚠️ <strong>تحذير:</strong> سيتم حذف الاقتطاع وجميع أقساطه من الأرشيف نهائياً.
                    <br>
                    <strong>هذه العملية لا يمكن التراجع عنها!</strong>
                </div>
            </div>
            <div class="modal-footer">
                <form method="POST" style="display:inline;">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
                    <input type="hidden" name="action" value="delete_permanent">
                    <input type="hidden" name="archive_id" id="deleteIdInput">
                    <button type="submit" class="btn btn-danger">🗑️ نعم، احذف نهائياً</button>
                </form>
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">إلغاء</button>
            </div>
        </div>
    </div>
</div>

<!-- ============================================================
     Modal: تفريغ الأرشيف بالكامل
============================================================ -->
<div class="modal fade" id="emptyArchiveModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header" style="background: #721c24; color: white;">
                <h5 class="modal-title">🚨 تفريغ الأرشيف</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="modal-icon">🚨</div>
                <h5 style="text-align:center; color: #721c24; margin-bottom:20px;">تحذير خطير!</h5>
                
                <div style="background:#f8d7da; padding:15px; border-radius:10px; font-size:14px; color:#721c24; text-align:right; line-height:1.8;">
                    سيتم حذف <strong><?= number_format($totalArchived) ?></strong> اقتطاع
                    و <strong><?= number_format($totalInstallments) ?></strong> قسط <strong>نهائياً</strong>.
                    <br><br>
                    <strong>⚠️ هذه العملية لا يمكن التراجع عنها أبداً!</strong>
                    <br>
                    يُنصح بعمل نسخة احتياطية أولاً.
                </div>
            </div>
            <div class="modal-footer">
                <form method="POST" style="display:inline;">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
                    <input type="hidden" name="action" value="empty_archive">
                    <button type="submit" class="btn btn-danger">🗑️ نعم، فرّغ الأرشيف نهائياً</button>
                </form>
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">إلغاء</button>
            </div>
        </div>
    </div>
</div>

<script>
// ============================================================
// تعبئة Modal الاستعادة
// ============================================================
document.getElementById('restoreModal')?.addEventListener('show.bs.modal', function(event) {
    const b = event.relatedTarget;
    document.getElementById('restoreEmployee').textContent = b.getAttribute('data-employee');
    document.getElementById('restoreAmount').textContent = b.getAttribute('data-amount') + ' دج';
    document.getElementById('restoreMonths').textContent = b.getAttribute('data-months') + ' شهر';
    document.getElementById('restoreInstallments').textContent = b.getAttribute('data-installments') + ' قسط';
    document.getElementById('restoreSource').textContent = b.getAttribute('data-source') || '—';
    document.getElementById('restoreIdInput').value = b.getAttribute('data-archive-id');
});

// ============================================================
// تعبئة Modal الحذف
// ============================================================
document.getElementById('deleteModal')?.addEventListener('show.bs.modal', function(event) {
    const b = event.relatedTarget;
    document.getElementById('deleteEmployee').textContent = b.getAttribute('data-employee');
    document.getElementById('deleteAmount').textContent = b.getAttribute('data-amount') + ' دج';
    document.getElementById('deleteMonths').textContent = b.getAttribute('data-months') + ' شهر';
    document.getElementById('deleteIdInput').value = b.getAttribute('data-archive-id');
});
</script>

<?php include 'includes/footer.php'; ?>