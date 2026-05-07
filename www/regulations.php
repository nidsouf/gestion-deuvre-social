<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}
require_once 'config/database.php';
require_once 'includes/functions.php';

// معالجة رفع ملف جديد
$message = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['pdf_file'])) {
    $title = trim($_POST['title']);
    $version = trim($_POST['version']);
    $description = trim($_POST['description']);
    $uploaded_by = $_SESSION['username'] ?? 'admin';
    
    if (empty($title) || empty($version)) {
        $message = "⚠️ العنوان والإصدار إلزاميان.";
    } elseif ($_FILES['pdf_file']['error'] !== UPLOAD_ERR_OK) {
        $message = "⚠️ خطأ في رفع الملف: " . $_FILES['pdf_file']['error'];
    } else {
        // التحقق من امتداد الملف
        $originalName = basename($_FILES['pdf_file']['name']);
        $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
        
        if ($extension !== 'pdf') {
            $message = "⚠️ يسمح فقط بملفات PDF (امتداد .pdf).";
        } else {
            $uploadDir = 'uploads/regulations/';
            if (!is_dir($uploadDir)) mkdir($uploadDir, 0777, true);
            
            $safeName = time() . '_' . preg_replace('/[^a-zA-Z0-9._-]/', '_', $originalName);
            $filePath = $uploadDir . $safeName;
            
            if (move_uploaded_file($_FILES['pdf_file']['tmp_name'], $filePath)) {
                $fileSize = $_FILES['pdf_file']['size'];
                $stmt = $pdo->prepare("INSERT INTO internal_regulations (title, version, description, file_name, file_path, file_size, uploaded_by) VALUES (?, ?, ?, ?, ?, ?, ?)");
                $stmt->execute([$title, $version, $description, $originalName, $filePath, $fileSize, $uploaded_by]);
                $message = "✅ تم رفع القانون بنجاح.";
            } else {
                $message = "⚠️ فشل نقل الملف إلى المجلد.";
            }
        }
    }
}

// معالجة حذف قانون
if (isset($_GET['delete'])) {
    $id = (int)$_GET['delete'];
    $stmt = $pdo->prepare("SELECT file_path FROM internal_regulations WHERE id = ?");
    $stmt->execute([$id]);
    $reg = $stmt->fetch();
    if ($reg && file_exists($reg['file_path'])) {
        unlink($reg['file_path']);
    }
    $pdo->prepare("DELETE FROM internal_regulations WHERE id = ?")->execute([$id]);
    $message = "🗑️ تم حذف القانون.";
    header("Location: regulations.php");
    exit;
}

// جلب جميع القوانين
$regulations = $pdo->query("SELECT * FROM internal_regulations ORDER BY upload_date DESC")->fetchAll();

include 'includes/header.php';
?>

<style>
    .regulations-container {
        direction: rtl;
        padding: 20px;
        max-width: 1200px;
        margin: 0 auto;
    }
    .upload-form {
        background: #f8f9fa;
        padding: 20px;
        border-radius: 12px;
        margin-bottom: 30px;
        border: 1px solid #ddd;
    }
    .upload-form input, .upload-form textarea, .upload-form button {
        margin-top: 8px;
        margin-bottom: 12px;
        width: 100%;
        padding: 10px;
        border-radius: 8px;
        border: 1px solid #ccc;
    }
    .upload-form button {
        background: #28a745;
        color: white;
        border: none;
        cursor: pointer;
        font-size: 16px;
    }
    .regulations-table {
        width: 100%;
        border-collapse: collapse;
        background: white;
        box-shadow: 0 0 10px rgba(0,0,0,0.05);
    }
    .regulations-table th, .regulations-table td {
        border: 1px solid #ddd;
        padding: 12px;
        text-align: center;
        vertical-align: middle;
    }
    .regulations-table th {
        background: #2a5298;
        color: white;
    }
    .btn-view, .btn-download, .btn-delete {
        padding: 5px 12px;
        border-radius: 20px;
        text-decoration: none;
        margin: 0 3px;
        display: inline-block;
        font-size: 13px;
    }
    .btn-view { background: #17a2b8; color: white; }
    .btn-download { background: #28a745; color: white; }
    .btn-delete { background: #dc3545; color: white; }
    .badge-active { background: #28a745; color: white; padding: 3px 8px; border-radius: 20px; }
</style>

<div class="regulations-container">
    <h2>📘 القوانين الداخلية للجنة الخدمات الاجتماعية</h2>
    
    <?php if($message): ?>
        <div style="background:#e9ecef; padding:10px; margin-bottom:15px; border-radius:8px;"><?= htmlspecialchars($message) ?></div>
    <?php endif; ?>

    <!-- نموذج رفع قانون جديد -->
    <div class="upload-form">
        <h3>📤 إضافة نسخة جديدة من القانون الداخلي</h3>
        <form method="POST" enctype="multipart/form-data">
            <input type="text" name="title" placeholder="عنوان القانون (مثلاً: القانون الداخلي للخدمات الاجتماعية)" required>
            <input type="text" name="version" placeholder="الإصدار (مثلاً: v1.0 - 2025)" required>
            <textarea name="description" rows="3" placeholder="وصف مختصر (اختياري)"></textarea>
            <input type="file" name="pdf_file" accept="application/pdf" required>
            <button type="submit">🚀 رفع القانون</button>
        </form>
    </div>

    <!-- قائمة القوانين -->
    <h3>📑 القوانين المرفوعة</h3>
    <?php if(count($regulations) == 0): ?>
        <p>⚠️ لا توجد قوانين مرفوعة بعد. قم برفع أول قانون باستخدام النموذج أعلاه.</p>
    <?php else: ?>
        <table class="regulations-table">
            <thead>
                <tr><th>#</th><th>العنوان</th><th>الإصدار</th><th>الوصف</th><th>تاريخ الرفع</th><th>الحجم</th><th>العمليات</th></tr>
            </thead>
            <tbody>
                <?php foreach($regulations as $idx => $r): ?>
                <tr>
                    <td><?= $idx+1 ?></td>
                    <td><?= htmlspecialchars($r['title']) ?></td>
                    <td><?= htmlspecialchars($r['version']) ?></td>
                    <td><?= htmlspecialchars($r['description']) ?></td>
                    <td><?= date('d/m/Y H:i', strtotime($r['upload_date'])) ?></td>
                    <td><?= round($r['file_size']/1024) ?> ك.ب</span></small></td>
                    <td>
                        <a href="<?= htmlspecialchars($r['file_path']) ?>" target="_blank" class="btn-view">👁️ معاينة</a>
                        <a href="<?= htmlspecialchars($r['file_path']) ?>" download class="btn-download">⬇️ تحميل</a>
                        <a href="?delete=<?= $r['id'] ?>" class="btn-delete" onclick="return confirm('هل أنت متأكد من حذف هذا القانون؟')">🗑️ حذف</a>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>

<?php include 'includes/footer.php'; ?>