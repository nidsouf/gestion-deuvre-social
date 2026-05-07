<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header("Location: ../login.php");
    exit;
}
require_once '../config/database.php';
include '../includes/header.php';

$year = isset($_GET['year']) ? (int)$_GET['year'] : date('Y');
$type = isset($_GET['type']) ? $_GET['type'] : 'all';

$sql = "SELECT * FROM budget_transactions WHERE strftime('%Y', transaction_date) = :year";
if ($type != 'all') $sql .= " AND type = :type";
$sql .= " ORDER BY transaction_date DESC";

$stmt = $pdo->prepare($sql);
if ($type != 'all') $stmt->execute([':year' => $year, ':type' => $type]);
else $stmt->execute([':year' => $year]);
$transactions = $stmt->fetchAll();
?>

<h2>📋 سجل معاملات الميزانية - سنة <?= $year ?></h2>
<form method="GET" style="margin-bottom:20px; display:flex; gap:10px;">
    <select name="year"><?php for($y=2020;$y<=date('Y')+1;$y++): ?><option value="<?= $y ?>" <?= $y==$year?'selected':'' ?>><?= $y ?></option><?php endfor; ?></select>
    <select name="type"><option value="all" <?= $type=='all'?'selected':'' ?>>الكل</option><option value="grant" <?= $type=='grant'?'selected':'' ?>>منح</option><option value="loan" <?= $type=='loan'?'selected':'' ?>>سلف</option><option value="installment" <?= $type=='installment'?'selected':'' ?>>أقساط</option></select>
    <button type="submit" class="btn-sm">فلترة</button>
</form>
<table class="data-table"><thead><tr><th>التاريخ</th><th>النوع</th><th>الوصف</th><th>المبلغ</th><th>نوع العملية</th></tr></thead>
<tbody><?php foreach($transactions as $t): ?><tr><td><?= date('d/m/Y H:i', strtotime($t['transaction_date'])) ?></td><td><?= $t['type']=='grant'?'🎁 منحة':($t['type']=='loan'?'💰 سلفة':'🔄 قسط مردود') ?></td><td><?= htmlspecialchars($t['description']) ?></td><td><?= number_format($t['amount'],2) ?> دج</td><td><?= $t['is_deduct']?'🧾 خصم':'➕ إضافة' ?></td></tr><?php endforeach; ?></tbody></table>
<?php include '../includes/footer.php'; ?>