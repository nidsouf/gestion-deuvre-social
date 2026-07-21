<?php
ob_start();
session_start();
require_once '../includes/auth_check.php';
require_once '../config/database.php';
require_once '../includes/security.php';
require_once '../includes/functions.php';
require_once '../includes/common_helpers.php';

$year = isset($_GET['year']) ? (int)$_GET['year'] : date('Y');
$quarter = isset($_GET['quarter']) ? (int)$_GET['quarter'] : 0;
$source_id = isset($_GET['source_id']) ? (int)$_GET['source_id'] : 0;

$sources = $pdo->query("SELECT id, name FROM sources ORDER BY name")->fetchAll();

$sql = "SELECT sp.*, s.name as source_name FROM source_payments sp JOIN sources s ON sp.source_id = s.id WHERE 1=1";
$params = [];
if ($source_id > 0) { $sql .= " AND sp.source_id = :source_id"; $params[':source_id'] = $source_id; }
if ($year > 0) { $sql .= " AND strftime('%Y', sp.cheque_date) = :year"; $params[':year'] = (string)$year; }
if ($quarter > 0) { $sql .= " AND sp.quarter = :quarter"; $params[':quarter'] = $quarter; }
$sql .= " ORDER BY sp.cheque_date DESC";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$payments = $stmt->fetchAll();
$total = array_sum(array_column($payments, 'amount'));

include '../includes/header.php';
?>
<link rel="stylesheet" href="../assets/css/payments.css">

<div class="payments-container">
    <h2>📊 تقرير الشيكات - <?= $year ?></h2>

    <div class="filter-section">
        <form method="GET" class="filter-form">
            <div class="filter-group"><label>📁 المصدر</label><select name="source_id"><option value="0">جميع المصادر</option><?php foreach($sources as $src): ?><option value="<?= $src['id'] ?>" <?= $source_id==$src['id']?'selected':'' ?>><?= htmlspecialchars($src['name']) ?></option><?php endforeach; ?></select></div>
            <div class="filter-group"><label>📅 السنة</label><select name="year"><?php for($y=2020;$y<=date('Y')+1;$y++): ?><option value="<?= $y ?>" <?= $y==$year?'selected':'' ?>><?= $y ?></option><?php endfor; ?></select></div>
            <div class="filter-group"><label>📆 الربع</label><select name="quarter"><option value="0">جميع الأرباع</option><option value="1" <?= $quarter==1?'selected':'' ?>>الربع الأول</option><option value="2" <?= $quarter==2?'selected':'' ?>>الربع الثاني</option><option value="3" <?= $quarter==3?'selected':'' ?>>الربع الثالث</option><option value="4" <?= $quarter==4?'selected':'' ?>>الربع الرابع</option></select></div>
            <button type="submit" class="btn-filter">عرض</button>
            <button type="button" class="btn-filter" onclick="window.print()" style="background:#28a745;">🖨️ طباعة</button>
        </form>
    </div>

    <div style="background:#e8f5e9; padding:15px; border-radius:12px; margin-bottom:20px;">
        <strong>💰 إجمالي الشيكات في الفترة المحددة:</strong> <?= formatAmount($total) ?>
    </div>

    <div style="overflow-x:auto;">
        <table class="payments-table">
            <thead><tr><th>#</th><th>المصدر</th><th>رقم الشيك</th><th>التاريخ</th><th>الربع</th><th>المبلغ</th><th>ملاحظات</th></tr></thead>
            <tbody>
                <?php if (empty($payments)): ?>
                    <tr><td colspan="7" style="text-align:center;">لا توجد شيكات في هذه الفترة</td></tr>
                <?php else: $i=1; foreach($payments as $p): ?>
                    <tr>
                        <td><?= $i++ ?></td>
                        <td><?= htmlspecialchars($p['source_name']) ?></td>
                        <td><?= htmlspecialchars($p['cheque_number'] ?? '-') ?></td>
                        <td><?= safeFormatDate($p['cheque_date']) ?></td>
                        <td><?= !empty($p['quarter']) ? $p['quarter'] : '---' ?></td>
                        <td><?= number_format($p['amount'], 2) ?> دج</td>
                        <td><?= htmlspecialchars($p['notes'] ?? '-') ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<?php include '../includes/footer.php'; ?>