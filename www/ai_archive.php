<?php
// ai_archive.php
session_start();
require_once 'includes/auth_check.php';
require_once 'config/database.php';
require_once 'includes/security.php';
require_once 'includes/AIService.php';

$filters = [
    'type' => isset($_GET['type']) ? $_GET['type'] : 'all',
    'year' => isset($_GET['year']) ? (int)$_GET['year'] : 0,
    'limit' => 50
];

$ai = new AIService($pdo);
$reports = $ai->getReports($filters);

$pageTitle = 'أرشيف التحليلات الذكية';
include 'includes/header.php';
?>

<link rel="stylesheet" href="assets/css/ai_archive.css">

<div class="container mt-4">
    <h2>🧠 أرشيف التحليلات الذكية</h2>
    <p class="text-muted">عرض جميع التحليلات السابقة التي قام بها الذكاء الاصطناعي</p>
    
    <div class="row mb-4">
        <div class="col-md-3">
            <div class="card text-center">
                <div class="card-body">
                    <h5 class="card-title">📊 إجمالي التحليلات</h5>
                    <h3><?= count($reports) ?></h3>
                </div>
            </div>
        </div>
    </div>
    
    <form method="GET" class="row g-3 mb-4">
        <div class="col-md-3">
            <select name="type" class="form-select">
                <option value="all">جميع الأنواع</option>
                <option value="budget" <?= $filters['type'] === 'budget' ? 'selected' : '' ?>>تحليل الميزانية</option>
            </select>
        </div>
        <div class="col-md-2">
            <input type="number" name="year" class="form-control" placeholder="السنة" value="<?= $filters['year'] ?: '' ?>">
        </div>
        <div class="col-md-2">
            <button type="submit" class="btn btn-primary">🔍 بحث</button>
        </div>
        <div class="col-md-2">
            <a href="ai_archive.php" class="btn btn-secondary">🗑️ إلغاء</a>
        </div>
    </form>
    
    <?php if (empty($reports)): ?>
        <div class="alert alert-info">لا توجد تحليلات مسجلة</div>
    <?php else: ?>
        <div class="reports-list">
            <?php foreach ($reports as $report): ?>
                <div class="card mb-3">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <div>
                            <span class="badge bg-primary"><?= htmlspecialchars($report['report_type']) ?></span>
                            <span class="badge bg-secondary"><?= $report['report_year'] ?></span>
                            <span class="badge bg-info"><?= $report['model_name'] ?></span>
                        </div>
                        <span class="text-muted small"><?= date('d/m/Y H:i', strtotime($report['created_at'])) ?></span>
                    </div>
                    <div class="card-body">
                        <h6 class="card-title">📌 <?= htmlspecialchars($report['summary']) ?></h6>
                        <p class="card-text"><?= nl2br(htmlspecialchars(substr($report['analysis'], 0, 200))) ?>...</p>
                    </div>
                    <div class="card-footer">
                        <button onclick="viewReport(<?= $report['id'] ?>)" class="btn btn-sm btn-info">📄 عرض التفاصيل</button>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>

<div class="modal fade" id="reportModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">📄 تفاصيل التقرير</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" id="reportModalBody">
                <div class="text-center p-5"><div class="spinner-border text-primary"></div></div>
            </div>
        </div>
    </div>
</div>

<script>
function viewReport(id) {
    const modal = new bootstrap.Modal(document.getElementById('reportModal'));
    modal.show();
    
    document.getElementById('reportModalBody').innerHTML = `
        <div class="text-center p-5"><div class="spinner-border text-primary"></div><p>جاري التحميل...</p></div>
    `;
    
    fetch('ai_get_report.php?id=' + id)
        .then(response => response.json())
        .then(data => {
            if (data.error) {
                document.getElementById('reportModalBody').innerHTML = `<div class="alert alert-danger">${data.error}</div>`;
                return;
            }
            
            let html = `
                <div class="alert alert-info"><strong>📊 الملخص:</strong> ${data.summary || ''}</div>
                <h6>📝 التحليل السردي</h6>
                <div class="p-3 bg-light rounded mb-3">${(data.analysis || '').replace(/\n/g, '<br>')}</div>
            `;
            
            if (data.recommendations && data.recommendations.length > 0) {
                html += `<h6>💡 التوصيات</h6><ul class="list-group mb-3">`;
                data.recommendations.forEach(rec => {
                    html += `<li class="list-group-item">${rec}</li>`;
                });
                html += `</ul>`;
            }
            
            if (data.alerts && data.alerts.length > 0) {
                html += `<h6>⚠️ التنبيهات</h6><ul class="list-group mb-3">`;
                data.alerts.forEach(alert => {
                    html += `<li class="list-group-item list-group-item-warning">${alert}</li>`;
                });
                html += `</ul>`;
            }
            
            html += `<p class="text-muted small">تم التحليل بواسطة: ${data.model || 'غير معروف'} | ${data.created_at || ''}</p>`;
            document.getElementById('reportModalBody').innerHTML = html;
        })
        .catch(error => {
            document.getElementById('reportModalBody').innerHTML = `<div class="alert alert-danger">${error.message}</div>`;
        });
}
</script>

<?php include 'includes/footer.php'; ?>