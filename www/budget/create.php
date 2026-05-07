<?php
session_start();
if (!isset($_SESSION['user_id']) || $_SESSION['role'] != 'admin') {
    header("Location: ../login.php");
    exit;
}
require_once '../config/database.php';
include '../includes/header.php';

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $year = (int)$_POST['year'];
    $initial = (float)$_POST['initial_budget'];
    
    // التحقق من عدم وجود السنة
    $stmt = $pdo->prepare("SELECT id FROM social_budget WHERE year = ?");
    $stmt->execute([$year]);
    if ($stmt->fetch()) {
        $error = "⚠️ السنة $year موجودة مسبقاً!";
    } else {
        $stmt = $pdo->prepare("INSERT INTO social_budget (year, initial_budget, remaining_budget) VALUES (?, ?, ?)");
        $stmt->execute([$year, $initial, $initial]);
        header("Location: index.php");
        exit;
    }
}
?>

<style>.form-container{max-width:500px;margin:30px auto;background:white;padding:25px;border-radius:20px;}</style>
<div class="form-container">
    <h2>➕ إضافة ميزانية جديدة</h2>
    <?php if(isset($error)) echo "<div style='color:red;margin-bottom:15px;'>$error</div>"; ?>
    <form method="POST">
        <div class="form-group"><label>السنة</label><input type="number" name="year" value="<?= date('Y')+1 ?>" required></div>
        <div class="form-group"><label>الميزانية الأولية (دج)</label><input type="number" step="0.01" name="initial_budget" required></div>
        <button type="submit" class="btn-sm">💾 حفظ</button>
        <a href="index.php" class="btn-sm" style="background:#6c757d;">إلغاء</a>
    </form>
</div>
<?php include '../includes/footer.php'; ?>