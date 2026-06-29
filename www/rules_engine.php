<?php
session_start();
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header("Location: index.php");
    exit;
}
require_once 'config/database.php';
require_once 'includes/functions.php';

$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_rule'])) {
    requireCSRFToken();
    $rule_code = $_POST['rule_code'];
    $rule_value = $_POST['rule_value'];
    
    if (updateRule($rule_code, $rule_value)) {
        $message = "✅ تم تحديث القاعدة {$rule_code} بنجاح";
    } else {
        $error = "❌ فشل تحديث القاعدة";
    }
}

$rules = $pdo->query("SELECT * FROM system_rules ORDER BY rule_code")->fetchAll();

include 'includes/header.php';
?>

<style>
    .rules-container { max-width: 1000px; margin: 0 auto; }
    .rule-card { background: white; border-radius: 15px; padding: 20px; margin-bottom: 20px; box-shadow: 0 2px 10px rgba(0,0,0,0.05); }
    .rule-code { font-family: monospace; font-size: 16px; font-weight: bold; color: #2a5298; }
    .rule-name { font-size: 14px; color: #666; margin-bottom: 10px; }
    .rule-value { font-size: 20px; font-weight: bold; }
    .rule-description { font-size: 12px; color: #999; margin-top: 10px; }
    .edit-form { display: flex; gap: 10px; align-items: center; flex-wrap: wrap; margin-top: 15px; }
    .edit-form input, .edit-form select, .edit-form textarea { padding: 8px 12px; border-radius: 20px; border: 1px solid #ddd; }
    .btn-save { background: #28a745; color: white; border: none; padding: 8px 20px; border-radius: 20px; cursor: pointer; }
    .btn-edit { background: #ffc107; color: #333; border: none; padding: 5px 15px; border-radius: 20px; cursor: pointer; }
    .rule-status { display: inline-block; padding: 2px 8px; border-radius: 15px; font-size: 10px; margin-right: 10px; }
    .status-active { background: #d4edda; color: #155724; }
    .status-inactive { background: #f8d7da; color: #721c24; }
</style>

<div class="rules-container">
    <h2>⚙️ محرك القواعد (Rules Engine)</h2>
    <p>هنا يمكنك تعديل إعدادات النظام دون الحاجة لتغيير الكود.</p>
    
    <?php if ($message): ?>
        <div style="background:#d4edda; padding:10px; border-radius:8px; margin-bottom:15px;"><?= htmlspecialchars($message) ?></div>
    <?php endif; ?>
    <?php if ($error): ?>
        <div style="background:#f8d7da; padding:10px; border-radius:8px; margin-bottom:15px;"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>
    
    <?php foreach ($rules as $rule): ?>
        <div class="rule-card">
            <div>
                <span class="rule-status <?= $rule['is_active'] ? 'status-active' : 'status-inactive' ?>">
                    <?= $rule['is_active'] ? 'نشط' : 'غير نشط' ?>
                </span>
                <span class="rule-code"><?= htmlspecialchars($rule['rule_code']) ?></span>
            </div>
            <div class="rule-name"><?= htmlspecialchars($rule['rule_name']) ?></div>
            <div class="rule-value" id="value-<?= $rule['id'] ?>">
                <?php 
                    if ($rule['rule_type'] == 'boolean') {
                        echo $rule['rule_value'] == '1' ? 'نعم ✅' : 'لا ❌';
                    } elseif ($rule['rule_type'] == 'json') {
                        echo '<pre style="font-size:11px;">' . htmlspecialchars($rule['rule_value']) . '</pre>';
                    } else {
                        echo htmlspecialchars($rule['rule_value']);
                    }
                ?>
            </div>
            <div class="rule-description"><?= htmlspecialchars($rule['description']) ?></div>
            
            <div class="edit-form" id="form-<?= $rule['id'] ?>" style="display:none;">
                <form method="POST" style="display: flex; gap: 10px; flex-wrap: wrap; width: 100%;">
                    <input type="hidden" name="csrf_token" value="<?= generateCSRFToken() ?>">
                    <input type="hidden" name="rule_code" value="<?= htmlspecialchars($rule['rule_code']) ?>">
                    <input type="hidden" name="update_rule" value="1">
                    
                    <?php if ($rule['rule_type'] == 'boolean'): ?>
                        <select name="rule_value">
                            <option value="1" <?= $rule['rule_value'] == '1' ? 'selected' : '' ?>>نعم</option>
                            <option value="0" <?= $rule['rule_value'] == '0' ? 'selected' : '' ?>>لا</option>
                        </select>
                    <?php elseif ($rule['rule_type'] == 'json'): ?>
                        <textarea name="rule_value" rows="3" style="width:300px;"><?= htmlspecialchars($rule['rule_value']) ?></textarea>
                    <?php else: ?>
                        <input type="text" name="rule_value" value="<?= htmlspecialchars($rule['rule_value']) ?>">
                    <?php endif; ?>
                    
                    <button type="submit" class="btn-save">💾 حفظ</button>
                    <button type="button" class="btn-cancel" onclick="cancelEdit(<?= $rule['id'] ?>)">إلغاء</button>
                </form>
            </div>
            
            <div style="margin-top: 15px;">
                <button class="btn-edit" onclick="showEdit(<?= $rule['id'] ?>)">✏️ تعديل</button>
            </div>
        </div>
    <?php endforeach; ?>
    
    <div style="margin-top: 30px; background:#e3f2fd; padding:15px; border-radius:10px;">
        <h4>💡 كيف يعمل محرك القواعد؟</h4>
        <p>يمكنك تعديل أي قاعدة من خلال الضغط على زر "تعديل" ثم تغيير القيمة وحفظها. ستطبق التغييرات فوراً على النظام دون الحاجة لتعديل أي ملفات.</p>
    </div>
</div>

<script>
    function showEdit(id) {
        document.getElementById('form-' + id).style.display = 'block';
    }
    function cancelEdit(id) {
        document.getElementById('form-' + id).style.display = 'none';
    }
</script>

<?php include 'includes/footer.php'; ?>