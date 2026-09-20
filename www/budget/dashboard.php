<?php
/**
 * budget/dashboard.php - لوحة تحكم الميزانية (محسّنة مع صافي الميزانية)
 */
session_start();
require_once '../includes/auth_check.php';
require_once '../config/database.php';
require_once '../includes/security.php';
require_once '../includes/functions.php';
require_once '../includes/budget_helpers.php';

// ============================================================
// تعريف دالة formatCurrency إذا لم تكن موجودة
// ============================================================
if (!function_exists('formatCurrency')) {
    function formatCurrency($amount) {
        return number_format($amount, 2) . ' دج';
    }
}

$year = isset($_GET['year']) ? (int)$_GET['year'] : date('Y');

// ============================================================
// جلب الإحصائيات
// ============================================================
$stats = getBudgetStats($pdo, $year);

if (!$stats || !isset($stats['initial'])) {
    $stats = [
        'initial' => 0,
        'remaining' => 0,
        'total_expenses' => 0,
        'total_refunds' => 0,
        'total_loans' => 0,
        'total_grants' => 0,
        'total_installments' => 0,
        'spent_percent' => 0
    ];
}

// ============================================================
// حساب صافي الفائض
// ============================================================
$netSurplus = $stats['total_refunds'] - $stats['total_expenses'];

// ============================================================
// إجمالي الشيكات المدفوعة (حسب السنة المحددة)
// ============================================================
$stmt = $pdo->prepare("SELECT COALESCE(SUM(amount), 0) FROM source_payments WHERE strftime('%Y', cheque_date) = ?");
$stmt->execute([(string)$year]);
$totalCheques = (float)$stmt->fetchColumn();

// ============================================================
// ✅ حساب الالتزامات المستقبلية للمصادر
// ============================================================

// 1. اقتطاعات سعدين النشطة (الأشهر المتبقية × القسط الشهري)
$saadineCommitment = 0;
$stmtSaadine = $pdo->prepare("
    SELECT 
        COALESCE(SUM(
            monthly_amount * (
                CASE 
                    WHEN remaining_months IS NOT NULL AND remaining_months > 0 
                        THEN remaining_months
                    WHEN total_months > 0 AND paid_months IS NOT NULL 
                        THEN MAX(0, total_months - paid_months)
                    WHEN total_months > 0 
                        THEN total_months
                    ELSE 0
                END
            )
        ), 0) as total
    FROM deductions 
    WHERE source_id = (SELECT id FROM sources WHERE name = 'سعدين للتجهير')
      AND (
          (remaining_months IS NOT NULL AND remaining_months > 0)
          OR (total_months > COALESCE(paid_months, 0))
      )
");
$stmtSaadine->execute();
$saadineCommitment = (float)$stmtSaadine->fetchColumn();

// 2. اقتطاعات الهواتف النشطة (شهرياً، نضربها في 12 شهراً للالتزام السنوي)
$phoneCommitment = 0;
try {
    $stmtPhones = $pdo->query("
        SELECT COALESCE(SUM(monthly_amount), 0) as total 
        FROM employee_phone_numbers 
        WHERE is_active = 1
    ");
    $phoneCommitment = (float)$stmtPhones->fetchColumn();
} catch (Exception $e) {
    $phoneCommitment = 0;
}

// 3. الالتزامات الكلية
$totalCommitments = $saadineCommitment + $phoneCommitment;

// 4. ✅ صافي الميزانية المتاحة (بعد خصم الالتزامات)
$netAvailableBudget = $stats['remaining'] - $totalCommitments;

// ============================================================
// جلب المعاملات والسنوات
// ============================================================
$transactions = getBudgetTransactions($pdo, ['year' => $year, 'limit' => 10]);
$years = getBudgetYears($pdo);

$csrf_token = generateCSRFToken();

include '../includes/header.php';
?>
<link rel="stylesheet" href="../assets/css/budget.css">

<div class="budget-container">
    <div class="budget-header">
        <h2>📊 لوحة تحكم الميزانية - سنة <?= $year ?></h2>
    </div>

    <!-- اختيار السنة + أزرار الإجراءات -->
    <div style="margin-bottom:20px; display:flex; gap:10px; align-items:center; flex-wrap:wrap;">
        <form method="GET" style="display:flex; gap:10px; align-items:center;">
            <label for="year">السنة:</label>
            <select name="year" id="year">
                <?php foreach ($years as $y): ?>
                    <option value="<?= $y ?>" <?= $y == $year ? 'selected' : '' ?>><?= $y ?></option>
                <?php endforeach; ?>
            </select>
            <button type="submit" class="btn btn-primary">عرض</button>
        </form>
        
        <form method="POST" action="recalculate.php" style="display:inline;">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
            <input type="hidden" name="year" value="<?= $year ?>">
            <button type="submit" class="btn btn-warning" onclick="return confirm('⚠️ هل أنت متأكد من إعادة حساب الميزانية؟')">
                🔄 إعادة حساب الميزانية
            </button>
            <button type="button" class="btn btn-info" onclick="openBudgetAnalysis(<?= $year ?>)">
                🧠 تحليل الميزانية
            </button>
        </form>
        
        <a href="index.php" class="btn btn-secondary" style="margin-right:auto;">⚙️ إدارة الميزانية</a>
    </div>

    <!-- ============================================================
         شبكة البطاقات - التصنيفات المحسّنة
    ============================================================ -->
    <div class="stats-grid">
        
        <!-- 1. الميزانية المتبقية (الإجمالية) -->
        <div class="stat-card remaining">
            <div class="stat-label">✅ الميزانية المتبقية</div>
            <div class="stat-value" style="color: <?= $stats['remaining'] >= 0 ? '#17a2b8' : '#dc3545' ?>;">
                <?= formatCurrency($stats['remaining']) ?>
            </div>
        </div>

        <!-- ⭐ 2. صافي الميزانية المتاحة (بعد خصم التزامات المصادر) - البطاقة الجديدة -->
        <div class="stat-card" style="background: <?= $netAvailableBudget >= 0 ? 'linear-gradient(135deg, #17a2b8, #0dcaf0)' : 'linear-gradient(135deg, #dc3545, #e4606d)' ?>; color: white; position: relative;">
            <div class="stat-label" style="color: rgba(255,255,255,0.95); font-weight: 700;">
                💎 صافي الميزانية المتاحة
            </div>
            <div class="stat-value" style="color: white; font-weight: 700; font-size: 26px;">
                <?= formatCurrency($netAvailableBudget) ?>
            </div>
            <small style="color: rgba(255,255,255,0.9); display: block; margin-top: 5px;">
                (بعد خصم التزامات المصادر)
            </small>
            <div style="margin-top: 8px; padding-top: 8px; border-top: 1px solid rgba(255,255,255,0.3); font-size: 12px;">
                <div>🏦 سعدين: <?= formatCurrency($saadineCommitment) ?></div>
                <div>📱 هواتف: <?= formatCurrency($phoneCommitment) ?></div>
            </div>
        </div>

        <!-- 3. الالتزامات المستقبلية للمصادر (بطاقة توضيحية) -->
        <div class="stat-card" style="background: linear-gradient(135deg, #6f42c1, #9b59b6); color: white;">
            <div class="stat-label" style="color: rgba(255,255,255,0.9);">📌 إجمالي الالتزامات للمصادر</div>
            <div class="stat-value" style="color: white; font-weight: 700;"><?= formatCurrency($totalCommitments) ?></div>
            <small style="color: rgba(255,255,255,0.8);">اقتطاعات قادمة ستدفع</small>
        </div>

        <!-- 4. إجمالي الشيكات المدفوعة -->
        <div class="stat-card cheques">
            <div class="stat-label">💳 إجمالي الشيكات المدفوعة</div>
            <div class="stat-value" style="color: #6f42c1;"><?= formatCurrency($totalCheques) ?></div>
        </div>

        <!-- 5. صافي الفائض/العجز -->
        <div class="stat-card" style="background: <?= $netSurplus >= 0 ? 'linear-gradient(135deg, #28a745, #20c997)' : 'linear-gradient(135deg, #dc3545, #e4606d)' ?>; color: white;">
            <div class="stat-label" style="color: rgba(255,255,255,0.9);">
                <?= $netSurplus >= 0 ? '✅ صافي الفائض' : '⚠️ صافي العجز' ?>
            </div>
            <div class="stat-value" style="color: white; font-weight: 700;"><?= formatCurrency(abs($netSurplus)) ?></div>
            <small style="color: rgba(255,255,255,0.8);">
                <?= $netSurplus >= 0 ? '(الإيرادات > المصروفات)' : '(المصروفات > الإيرادات)' ?>
            </small>
        </div>

        <!-- 6. إجمالي المصروفات -->
        <div class="stat-card expenses">
            <div class="stat-label">💸 إجمالي المصروفات</div>
            <div class="stat-value"><?= formatCurrency($stats['total_expenses']) ?></div>
            <small style="color: #666;">سلف + منح</small>
        </div>

        <!-- 7. إجمالي الإيرادات -->
        <div class="stat-card refunds" style="border-right: 4px solid #28a745;">
            <div class="stat-label">💰 إجمالي الإيرادات</div>
            <div class="stat-value" style="color: #28a745;"><?= formatCurrency($stats['total_refunds']) ?></div>
            <small style="color: #666;">جميع المبالغ المعادة</small>
        </div>

        <!-- 8. السلف الجديدة -->
        <div class="stat-card loans">
            <div class="stat-label">📌 السلف الجديدة</div>
            <div class="stat-value"><?= formatCurrency($stats['total_loans']) ?></div>
        </div>

        <!-- 9. المنح الجديدة -->
        <div class="stat-card grants">
            <div class="stat-label">🎁 المنح الجديدة</div>
            <div class="stat-value"><?= formatCurrency($stats['total_grants']) ?></div>
        </div>

        <!-- 10. استرجاعات الأقساط -->
        <div class="stat-card installments" style="background: #f8f9fa; border: 1px dashed #17a2b8;">
            <div class="stat-label">📦 استرجاعات الأقساط</div>
            <div class="stat-value" style="color: #17a2b8;"><?= formatCurrency($stats['total_installments']) ?></div>
            <small style="color: #888;">ضمن الإيرادات</small>
        </div>
    </div>

    <!-- ============================================================
         صندوق تفصيلي لصافي الميزانية (اختياري - يوضح الحساب)
    ============================================================ -->
    <div style="background: linear-gradient(135deg, #e0f7fa, #b2ebf2); padding: 20px; border-radius: 15px; margin: 20px 0; border-right: 5px solid #17a2b8;">
        <h4 style="margin-top: 0; color: #006064;">💎 كيف تم حساب صافي الميزانية المتاحة؟</h4>
        <table style="width: 100%; border-collapse: collapse; font-size: 14px;">
            <tr style="border-bottom: 1px solid #b2ebf2;">
                <td style="padding: 8px;">✅ الميزانية المتبقية</td>
                <td style="text-align: left; font-weight: 700; color: #17a2b8;">
                    <?= formatCurrency($stats['remaining']) ?>
                </td>
            </tr>
            <tr style="border-bottom: 1px solid #b2ebf2;">
                <td style="padding: 8px;">➖ اقتطاعات سعدين (الأشهر المتبقية)</td>
                <td style="text-align: left; color: #dc3545;">
                    - <?= formatCurrency($saadineCommitment) ?>
                </td>
            </tr>
            <tr style="border-bottom: 1px solid #b2ebf2;">
                <td style="padding: 8px;">➖ اقتطاعات الهواتف النشطة</td>
                <td style="text-align: left; color: #dc3545;">
                    - <?= formatCurrency($phoneCommitment) ?>
                </td>
            </tr>
            <tr style="background: rgba(23, 162, 184, 0.15);">
                <td style="padding: 12px 8px; font-weight: 700; font-size: 16px;">💎 صافي الميزانية المتاحة</td>
                <td style="text-align: left; font-weight: 700; font-size: 18px; color: <?= $netAvailableBudget >= 0 ? '#28a745' : '#dc3545' ?>;">
                    <?= formatCurrency($netAvailableBudget) ?>
                </td>
            </tr>
        </table>
        <small style="color: #006064; display: block; margin-top: 10px;">
            ℹ️ "صافي الميزانية المتاحة" = ما يمكن صرفه فعلياً دون المساس بالالتزامات المستقبلية تجاه المصادر.
        </small>
    </div>

    <!-- ============================================================
         شريط التقدم
    ============================================================ -->
    <div class="progress-section">
        <div style="display:flex; justify-content:space-between; margin-bottom:8px;">
            <span>الميزانية المستخدمة</span>
            <span><?= $stats['spent_percent'] ?>%</span>
        </div>
        <div class="progress-bar-container">
            <div class="progress-bar-fill" style="width: <?= $stats['spent_percent'] ?>%;">
                <?= $stats['spent_percent'] ?>%
            </div>
        </div>
        <div style="margin-top:10px; color:#666; font-size:14px;">
            الميزانية الأولية: <?= formatCurrency($stats['initial']) ?>
        </div>
        <div style="margin-top:5px; font-size:13px; color:#888;">
            <small>ℹ️ تم تحديث الميزانية من <?= count($transactions) ?> معاملة</small>
        </div>
    </div>

    <!-- ============================================================
         الرسوم البيانية
    ============================================================ -->
    <div style="display:grid; grid-template-columns:1fr 1fr; gap:20px;">
        <div class="chart-container">
            <h4>📊 توزيع المصروفات</h4>
            <div>
                <div><span style="background:#2a5298; display:inline-block; width:20px; height:20px;"></span> سلف: <?= formatCurrency($stats['total_loans']) ?></div>
                <div><span style="background:#28a745; display:inline-block; width:20px; height:20px;"></span> منح: <?= formatCurrency($stats['total_grants']) ?></div>
                <div><span style="background:#6c757d; display:inline-block; width:20px; height:20px;"></span> أقساط (استرجاع): <?= formatCurrency($stats['total_installments']) ?></div>
            </div>
        </div>
        
        <div class="chart-container">
            <h4>📊 الإيرادات vs المصروفات</h4>
            <?php 
            $balance = $stats['total_refunds'] - $stats['total_expenses'];
            $balanceText = $balance > 0 ? '✅ فائض' : ($balance < 0 ? '⚠️ عجز' : '⚖️ متوازن');
            ?>
            <div>
                <div><span style="background:#28a745; display:inline-block; width:20px; height:20px;"></span> إيرادات: <?= formatCurrency($stats['total_refunds']) ?></div>
                <div><span style="background:#dc3545; display:inline-block; width:20px; height:20px;"></span> مصروفات: <?= formatCurrency($stats['total_expenses']) ?></div>
                <div><span style="background:#17a2b8; display:inline-block; width:20px; height:20px;"></span> ⚖️ صافي: <?= formatCurrency($balance) ?> <small style="color:#666;">(<?= $balanceText ?>)</small></div>
            </div>
        </div>
    </div>

    <!-- ============================================================
         آخر المعاملات
    ============================================================ -->
    <h3 style="margin:30px 0 15px;">🕒 آخر المعاملات</h3>
    <table class="data-table">
        <thead>
            <tr><th>التاريخ</th><th>النوع</th><th>الوصف</th><th>المبلغ (دج)</th><th>اتجاه</th></tr>
        </thead>
        <tbody>
            <?php if (empty($transactions)): ?>
                <tr><td colspan="5" style="text-align:center;">لا توجد معاملات</td></tr>
            <?php else: ?>
                <?php foreach ($transactions as $t): ?>
                <tr>
                    <td><?= date('d/m/Y H:i', strtotime($t['transaction_date'])) ?></td>
                    <td><?= $t['type_label'] ?></td>
                    <td><?= htmlspecialchars($t['description'] ?? '') ?></td>
                    <td class="<?= $t['is_deduct'] ? 'debit' : 'credit' ?>">
                        <?= $t['is_deduct'] ? '-' : '+' ?> <?= formatCurrency($t['amount']) ?>
                    </td>
                    <td><?= $t['direction'] ?></td>
                </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>

    <div style="margin-top:20px; text-align:center;">
        <a href="report.php?year=<?= $year ?>" class="btn btn-primary">📄 عرض التقرير الكامل</a>
        <a href="index.php" class="btn btn-secondary">⚙️ إدارة الميزانية</a>
    </div>
</div>

<!-- ============================================================
     سكربت تحليل الذكاء الاصطناعي
============================================================ -->
<script>
function openBudgetAnalysis(year) {
    const modalHtml = `
        <div class="modal fade" id="analysisModal" tabindex="-1" aria-hidden="true">
            <div class="modal-dialog modal-lg">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title">🧠 تحليل ذكي للميزانية</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body" id="analysisBody">
                        <div class="text-center p-5">
                            <div class="spinner-border text-primary" role="status"></div>
                            <p class="mt-3">جاري تحليل البيانات... يرجى الانتظار</p>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">إغلاق</button>
                    </div>
                </div>
            </div>
        </div>
    `;
    
    document.body.insertAdjacentHTML('beforeend', modalHtml);
    const modal = new bootstrap.Modal(document.getElementById('analysisModal'));
    modal.show();
    
    const csrfToken = document.querySelector('input[name="csrf_token"]')?.value || '';
    
    fetch('../ai_analyze_budget.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'year=' + year + '&csrf_token=' + encodeURIComponent(csrfToken) + '&mode=sync'
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            renderAnalysis(data.analysis);
        } else {
            document.getElementById('analysisBody').innerHTML = `
                <div class="alert alert-danger">${data.error || 'حدث خطأ أثناء التحليل'}</div>
            `;
        }
    })
    .catch(error => {
        document.getElementById('analysisBody').innerHTML = `
            <div class="alert alert-danger">حدث خطأ في الاتصال: ${error.message}</div>
        `;
    });
}

function renderAnalysis(data) {
    const recommendations = data.recommendations || [];
    const alerts = data.alerts || [];
    
    let html = `
        <div class="analysis-result">
            <div class="alert alert-info">
                <strong>📊 الملخص التنفيذي:</strong> ${data.summary || 'تم تحليل الميزانية بنجاح.'}
            </div>
            <h6>📝 التحليل السردي</h6>
            <div class="p-3 bg-light rounded mb-3">
                ${(data.analysis || '').replace(/\n/g, '<br>')}
            </div>
    `;
    
    if (recommendations.length > 0) {
        html += `
            <h6>💡 التوصيات</h6>
            <ul class="list-group mb-3">
                ${recommendations.map(rec => `<li class="list-group-item">${rec}</li>`).join('')}
            </ul>
        `;
    }
    
    if (alerts.length > 0) {
        html += `
            <h6>⚠️ التنبيهات</h6>
            <ul class="list-group mb-3">
                ${alerts.map(alert => `<li class="list-group-item list-group-item-warning">${alert}</li>`).join('')}
            </ul>
        `;
    }
    
    if (data.cached) {
        html += `<p><small>✅ تم استخدام نسخة مخزنة من ${data.cached_at || ''}</small></p>`;
    }
    
    html += `</div>`;
    document.getElementById('analysisBody').innerHTML = html;
}
</script>

<?php include '../includes/footer.php'; ?>