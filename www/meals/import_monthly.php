<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header("Location: ../login.php");
    exit;
}
require_once '../config/database.php';
require_once '../includes/security.php';
require_once '../includes/functions.php';

$csrf_token = generateCSRFToken();
include '../includes/header.php';
?>
<?php if (isset($_SESSION['toast'])): ?>
    <div style="background: <?= $_SESSION['toast']['type'] == 'success' ? '#d4edda' : '#f8d7da' ?>; padding: 15px; border-radius: 10px; margin-bottom: 20px;">
        <?= htmlspecialchars($_SESSION['toast']['message']) ?>
    </div>
    <?php unset($_SESSION['toast']); ?>
<?php endif; ?>
<style>
    .import-container { max-width: 700px; margin: 30px auto; background: white; padding: 30px; border-radius: 20px; box-shadow: 0 2px 15px rgba(0,0,0,0.1); }
    .import-container h2 { text-align: center; color: #2a5298; margin-bottom: 20px; }
    .upload-area { border: 2px dashed #ccc; border-radius: 15px; padding: 40px; text-align: center; cursor: pointer; transition: 0.3s; }
    .upload-area:hover { border-color: #2a5298; background: #f8f9fa; }
    .upload-area .icon { font-size: 48px; color: #2a5298; }
    .file-info { margin: 15px 0; padding: 10px; background: #e3f2fd; border-radius: 10px; display: none; }
    .format-info { background: #fff3cd; padding: 15px; border-radius: 10px; margin: 20px 0; font-size: 14px; }
    .format-info table { width: 100%; border-collapse: collapse; margin-top: 10px; }
    .format-info th, .format-info td { border: 1px solid #ddd; padding: 8px; text-align: center; font-size: 13px; }
    .format-info th { background: #2a5298; color: white; }
    .btn-import { background: #28a745; color: white; border: none; padding: 12px 30px; border-radius: 30px; cursor: pointer; font-size: 16px; }
    .btn-import:disabled { background: #6c757d; cursor: not-allowed; }
</style>

<div class="import-container">
    <h2>📥 استيراد تقرير الوجبات الشهري</h2>
    
    <div class="format-info">
        <strong>📋 صيغة الملف المطلوبة (CSV بفاصل `;`):</strong>
        <table>
            <thead><tr><th>رقم التسجيل</th><th>اللقب</th><th>الاسم</th><th>النوع</th><th>عدد الوجبات</th><th>حاضر</th><th>غائب</th><th>سعر الوجبة</th><th>المبلغ المستحق</th></tr></thead>
            <tbody>
                <tr><td>17</td><td>العيفاوي</td><td>سوهاء</td><td>موظف</td><td>0</td><td>0</td><td>0</td><td>25.00</td><td>0.00</td></tr>
                <tr><td>23</td><td>اللبي</td><td>حكيمة</td><td>موظف</td><td>1</td><td>1</td><td>0</td><td>25.00</td><td>25.00</td></tr>
            </tbody>
        </table>
        <p style="margin-top:10px;"><strong>ملاحظة:</strong> سيتم ربط الموظفين عبر <code>رقم التسجيل</code> (يجب أن يتطابق مع <code>code</code> في جدول المستفيدين أو الموظفين).</p>
    </div>
    
    <form id="importForm" method="POST" action="import_monthly_process.php" enctype="multipart/form-data">
        <input type="hidden" name="csrf_token" value="<?= $csrf_token ?>">
        
        <div class="form-group">
            <label>📅 الشهر والسنة (البيانات المرفوعة):</label>
            <div style="display: flex; gap: 10px; flex-wrap: wrap;">
                <select name="year" required>
                    <?php for($y=2020;$y<=date('Y')+1;$y++): ?>
                        <option value="<?=$y?>" <?=$y==date('Y')?'selected':''?>><?=$y?></option>
                    <?php endfor; ?>
                </select>
                <select name="month" required>
                    <?php for($m=1;$m<=12;$m++): ?>
                        <option value="<?=$m?>" <?=$m==date('m')?'selected':''?>><?=date('F',mktime(0,0,0,$m,1))?></option>
                    <?php endfor; ?>
                </select>
            </div>
        </div>
        
        <div class="upload-area" id="uploadArea">
            <div class="icon">📂</div>
            <h3>اسحب ملف CSV هنا أو اضغط للاختيار</h3>
            <p style="color:#666;">يدعم ملفات .csv فقط (بفاصل `;`)</p>
            <input type="file" name="csv_file" id="csv_file" accept=".csv" style="display:none;" required>
        </div>
        
        <div class="file-info" id="fileInfo">
            <span id="fileName"></span>
            <span id="fileSize"></span>
        </div>
        
        <div style="text-align: center; margin-top: 20px;">
            <button type="submit" class="btn-import" id="importBtn" disabled>📤 استيراد التقرير</button>
        </div>
    </form>
</div>

<script>
    const uploadArea = document.getElementById('uploadArea');
    const fileInput = document.getElementById('csv_file');
    const fileInfo = document.getElementById('fileInfo');
    const fileName = document.getElementById('fileName');
    const fileSize = document.getElementById('fileSize');
    const importBtn = document.getElementById('importBtn');

    uploadArea.addEventListener('click', () => fileInput.click());
    
    uploadArea.addEventListener('dragover', (e) => {
        e.preventDefault();
        uploadArea.style.borderColor = '#28a745';
        uploadArea.style.background = '#e8f5e9';
    });
    
    uploadArea.addEventListener('dragleave', () => {
        uploadArea.style.borderColor = '#ccc';
        uploadArea.style.background = '';
    });
    
    uploadArea.addEventListener('drop', (e) => {
        e.preventDefault();
        uploadArea.style.borderColor = '#ccc';
        uploadArea.style.background = '';
        if (e.dataTransfer.files.length) {
            fileInput.files = e.dataTransfer.files;
            handleFile(e.dataTransfer.files[0]);
        }
    });

    fileInput.addEventListener('change', function() {
        if (this.files.length) handleFile(this.files[0]);
    });

    function handleFile(file) {
        if (!file.name.endsWith('.csv')) {
            alert('⚠️ يرجى اختيار ملف CSV فقط.');
            fileInput.value = '';
            fileInfo.style.display = 'none';
            importBtn.disabled = true;
            return;
        }
        fileName.textContent = '📄 ' + file.name;
        fileSize.textContent = ' (' + (file.size / 1024).toFixed(2) + ' ك.ب)';
        fileInfo.style.display = 'block';
        importBtn.disabled = false;
    }
</script>

<?php include '../includes/footer.php'; ?>