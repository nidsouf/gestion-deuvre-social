<?php
session_start();
if (!isset($_SESSION['user_id']) || $_SESSION['role'] != 'admin') {
    die("غير مصرح بهذه العملية. يلزم تسجيل الدخول كمدير.");
}
require_once 'config/database.php';

echo "<pre>";
try {
    $pdo->beginTransaction();
    
    // ========== إضافة الأعمدة المفقودة (تتجاوز الأخطاء) ==========
    $alterStatements = [
        "ALTER TABLE users ADD COLUMN is_active INTEGER DEFAULT 1",
        "ALTER TABLE users ADD COLUMN password_changed_at TEXT",
        "ALTER TABLE employees ADD COLUMN updated_at TEXT DEFAULT CURRENT_TIMESTAMP",
        "ALTER TABLE deductions ADD COLUMN updated_at TEXT DEFAULT CURRENT_TIMESTAMP",
        "ALTER TABLE deductions ADD COLUMN grant_date TEXT",
        "ALTER TABLE employee_grants ADD COLUMN updated_at TEXT DEFAULT CURRENT_TIMESTAMP",
        "ALTER TABLE employee_phone_numbers ADD COLUMN updated_at TEXT DEFAULT CURRENT_TIMESTAMP",
        "ALTER TABLE meeting_minutes ADD COLUMN updated_at TEXT DEFAULT CURRENT_TIMESTAMP",
        "ALTER TABLE meal_records ADD COLUMN updated_at TEXT DEFAULT CURRENT_TIMESTAMP",
        "ALTER TABLE meal_trimesters ADD COLUMN updated_at TEXT DEFAULT CURRENT_TIMESTAMP",
        "ALTER TABLE labor_day_honorees ADD COLUMN updated_at TEXT DEFAULT CURRENT_TIMESTAMP",
        "ALTER TABLE settings ADD COLUMN updated_at TEXT DEFAULT CURRENT_TIMESTAMP",
    ];
    
    foreach ($alterStatements as $sql) {
        try {
            $pdo->exec($sql);
            echo "✓ " . substr($sql, 0, 60) . "...\n";
        } catch (PDOException $e) {
            if (strpos($e->getMessage(), 'duplicate column name') !== false) {
                echo "⚠️ تجاهل (عمود موجود): " . substr($sql, 0, 60) . "...\n";
            } else {
                throw $e;
            }
        }
    }
    
    // تحديث القيم الافتراضية
    $pdo->exec("UPDATE users SET is_active = 1 WHERE is_active IS NULL");
    $pdo->exec("UPDATE users SET password_changed_at = CURRENT_TIMESTAMP WHERE password_changed_at IS NULL");
    echo "✓ تم تحديث القيم الافتراضية للمستخدمين.\n";
    
    // ========== الفهارس ==========
    $indexStatements = [
        "CREATE INDEX IF NOT EXISTS idx_deductions_employee_id ON deductions(employee_id)",
        "CREATE INDEX IF NOT EXISTS idx_deductions_source_id ON deductions(source_id)",
        "CREATE INDEX IF NOT EXISTS idx_deductions_start_date ON deductions(start_date)",
        "CREATE INDEX IF NOT EXISTS idx_deductions_end_date ON deductions(end_date)",
        "CREATE INDEX IF NOT EXISTS idx_deductions_is_loan ON deductions(is_loan)",
        "CREATE INDEX IF NOT EXISTS idx_deductions_dates ON deductions(start_date, end_date)",
        "CREATE INDEX IF NOT EXISTS idx_deductions_employee_dates ON deductions(employee_id, start_date, end_date)",
        "CREATE INDEX IF NOT EXISTS idx_grants_amount ON grants(amount)",
        "CREATE INDEX IF NOT EXISTS idx_employee_grants_employee_id ON employee_grants(employee_id)",
        "CREATE INDEX IF NOT EXISTS idx_employee_grants_grant_date ON employee_grants(grant_date)",
        "CREATE INDEX IF NOT EXISTS idx_social_budget_year ON social_budget(year)",
        "CREATE INDEX IF NOT EXISTS idx_social_budget_remaining ON social_budget(remaining_budget)",
        "CREATE INDEX IF NOT EXISTS idx_budget_transactions_type_ref ON budget_transactions(type, reference_id)",
        "CREATE INDEX IF NOT EXISTS idx_budget_transactions_date ON budget_transactions(transaction_date)",
        "CREATE INDEX IF NOT EXISTS idx_budget_transactions_date_type ON budget_transactions(transaction_date, type)",
        "CREATE INDEX IF NOT EXISTS idx_source_payments_source_id ON source_payments(source_id)",
        "CREATE INDEX IF NOT EXISTS idx_source_payments_cheque_date ON source_payments(cheque_date)",
        "CREATE INDEX IF NOT EXISTS idx_employee_phone_numbers_employee ON employee_phone_numbers(employee_id)",
        "CREATE INDEX IF NOT EXISTS idx_employee_phone_numbers_active ON employee_phone_numbers(is_active)",
        "CREATE INDEX IF NOT EXISTS idx_meeting_minutes_month_year ON meeting_minutes(month, year, session_number)",
        "CREATE INDEX IF NOT EXISTS idx_meeting_minutes_honorees ON meeting_minutes(show_honorees, honorees_year)",
        "CREATE INDEX IF NOT EXISTS idx_meal_records_employee_year_month ON meal_records(employee_id, year, month)",
        "CREATE INDEX IF NOT EXISTS idx_umrah_draws_event_id ON umrah_draws(draw_event_id)",
        "CREATE INDEX IF NOT EXISTS idx_umrah_draws_employee_id ON umrah_draws(employee_id)",
        "CREATE INDEX IF NOT EXISTS idx_umrah_draws_winner ON umrah_draws(is_winner)",
        "CREATE INDEX IF NOT EXISTS idx_umrah_draws_reserve ON umrah_draws(reserve_order)",
        "CREATE INDEX IF NOT EXISTS idx_monthly_deductions_log_employee ON monthly_deductions_log(employee_id, year, month)",
        "CREATE INDEX IF NOT EXISTS idx_audit_log_created_at ON audit_log(created_at)",
        "CREATE INDEX IF NOT EXISTS idx_audit_log_user ON audit_log(user_id)",
        "CREATE INDEX IF NOT EXISTS idx_audit_log_cleanup ON audit_log(created_at)",
    ];
    
    foreach ($indexStatements as $sql) {
        try {
            $pdo->exec($sql);
            echo "✓ فهرس: " . substr($sql, 0, 60) . "...\n";
        } catch (PDOException $e) {
            echo "⚠️ خطأ في الفهرس (قد يكون موجوداً): " . $e->getMessage() . "\n";
        }
    }
    
    // ========== المشغلات (Triggers) ==========
    $triggers = [
        "DROP TRIGGER IF EXISTS trg_employees_updated_at",
        "CREATE TRIGGER IF NOT EXISTS trg_employees_updated_at
        AFTER UPDATE ON employees
        FOR EACH ROW
        WHEN OLD.name IS NOT NEW.name 
           OR OLD.category IS NOT NEW.category 
           OR OLD.hire_date IS NOT NEW.hire_date
        BEGIN
            UPDATE employees SET updated_at = CURRENT_TIMESTAMP WHERE id = NEW.id;
        END",
        "DROP TRIGGER IF EXISTS trg_deductions_updated_at",
        "CREATE TRIGGER IF NOT EXISTS trg_deductions_updated_at
        AFTER UPDATE ON deductions
        FOR EACH ROW
        WHEN OLD.employee_id IS NOT NEW.employee_id 
           OR OLD.source_id IS NOT NEW.source_id 
           OR OLD.monthly_amount IS NOT NEW.monthly_amount 
           OR OLD.total_months IS NOT NEW.total_months 
           OR OLD.start_date IS NOT NEW.start_date 
           OR OLD.end_date IS NOT NEW.end_date 
           OR OLD.is_loan IS NOT NEW.is_loan 
           OR OLD.grant_date IS NOT NEW.grant_date
        BEGIN
            UPDATE deductions SET updated_at = CURRENT_TIMESTAMP WHERE id = NEW.id;
        END",
        "DROP TRIGGER IF EXISTS trg_deductions_validate_date",
        "CREATE TRIGGER IF NOT EXISTS trg_deductions_validate_date
        BEFORE INSERT ON deductions
        FOR EACH ROW
        WHEN NEW.start_date IS NOT NULL
        BEGIN
            SELECT CASE
                WHEN NEW.start_date NOT GLOB '[0-9][0-9][0-9][0-9]-[0-9][0-9]-[0-9][0-9]'
                THEN RAISE(ABORT, '⚠️ صيغة التاريخ غير صحيحة. يجب أن تكون YYYY-MM-DD')
            END;
        END",
        "DROP TRIGGER IF EXISTS trg_employee_grants_updated_at",
        "CREATE TRIGGER IF NOT EXISTS trg_employee_grants_updated_at
        AFTER UPDATE ON employee_grants
        FOR EACH ROW
        WHEN OLD.grant_date IS NOT NEW.grant_date OR OLD.notes IS NOT NEW.notes
        BEGIN
            UPDATE employee_grants SET updated_at = CURRENT_TIMESTAMP WHERE id = NEW.id;
        END",
        "DROP TRIGGER IF EXISTS trg_employee_phone_numbers_updated_at",
        "CREATE TRIGGER IF NOT EXISTS trg_employee_phone_numbers_updated_at
        AFTER UPDATE ON employee_phone_numbers
        FOR EACH ROW
        WHEN OLD.is_active IS NOT NEW.is_active OR OLD.monthly_amount IS NOT NEW.monthly_amount
        BEGIN
            UPDATE employee_phone_numbers SET updated_at = CURRENT_TIMESTAMP WHERE id = NEW.id;
        END",
        "DROP TRIGGER IF EXISTS trg_meeting_minutes_updated_at",
        "CREATE TRIGGER IF NOT EXISTS trg_meeting_minutes_updated_at
        AFTER UPDATE ON meeting_minutes
        FOR EACH ROW
        WHEN OLD.content IS NOT NEW.content OR OLD.notes IS NOT NEW.notes
        BEGIN
            UPDATE meeting_minutes SET updated_at = CURRENT_TIMESTAMP WHERE id = NEW.id;
        END",
        "DROP TRIGGER IF EXISTS trg_meal_records_updated_at",
        "CREATE TRIGGER IF NOT EXISTS trg_meal_records_updated_at
        AFTER UPDATE ON meal_records
        FOR EACH ROW
        WHEN OLD.meal_count IS NOT NEW.meal_count OR OLD.total_amount IS NOT NEW.total_amount
        BEGIN
            UPDATE meal_records SET updated_at = CURRENT_TIMESTAMP WHERE id = NEW.id;
        END",
        "DROP TRIGGER IF EXISTS trg_meal_trimesters_updated_at",
        "CREATE TRIGGER IF NOT EXISTS trg_meal_trimesters_updated_at
        AFTER UPDATE ON meal_trimesters
        FOR EACH ROW
        WHEN OLD.total_meals IS NOT NEW.total_meals 
           OR OLD.total_amount IS NOT NEW.total_amount 
           OR OLD.half_amount IS NOT NEW.half_amount 
           OR OLD.status IS NOT NEW.status
        BEGIN
            UPDATE meal_trimesters SET updated_at = CURRENT_TIMESTAMP WHERE id = NEW.id;
        END",
        "DROP TRIGGER IF EXISTS trg_labor_day_honorees_updated_at",
        "CREATE TRIGGER IF NOT EXISTS trg_labor_day_honorees_updated_at
        AFTER UPDATE ON labor_day_honorees
        FOR EACH ROW
        WHEN OLD.prize_type IS NOT NEW.prize_type OR OLD.prize_value IS NOT NEW.prize_value
        BEGIN
            UPDATE labor_day_honorees SET updated_at = CURRENT_TIMESTAMP WHERE id = NEW.id;
        END",
        "DROP TRIGGER IF EXISTS trg_users_updated_at",
        "CREATE TRIGGER IF NOT EXISTS trg_users_updated_at
        AFTER UPDATE ON users
        FOR EACH ROW
        WHEN OLD.username IS NOT NEW.username OR OLD.password IS NOT NEW.password OR OLD.role IS NOT NEW.role
        BEGIN
            UPDATE users SET updated_at = CURRENT_TIMESTAMP WHERE id = NEW.id;
        END",
        "DROP TRIGGER IF EXISTS trg_settings_updated_at",
        "CREATE TRIGGER IF NOT EXISTS trg_settings_updated_at
        AFTER UPDATE ON settings
        FOR EACH ROW
        WHEN OLD.setting_value IS NOT NEW.setting_value
        BEGIN
            UPDATE settings SET updated_at = CURRENT_TIMESTAMP WHERE id = NEW.id;
        END",
        "DROP TRIGGER IF EXISTS trg_budget_transactions_check_budget",
        "CREATE TRIGGER IF NOT EXISTS trg_budget_transactions_check_budget
        BEFORE INSERT ON budget_transactions
        FOR EACH ROW
        BEGIN
            SELECT CASE
                WHEN (SELECT COUNT(1) FROM social_budget WHERE year = CAST(strftime('%Y', COALESCE(NEW.transaction_date, 'now')) AS INTEGER)) = 0
                THEN RAISE(ABORT, '⚠️ لا توجد ميزانية مسجلة لهذه السنة')
            END;
        END",
        "DROP TRIGGER IF EXISTS trg_budget_transactions_check_sufficient",
        "CREATE TRIGGER IF NOT EXISTS trg_budget_transactions_check_sufficient
        BEFORE INSERT ON budget_transactions
        FOR EACH ROW
        WHEN NEW.is_deduct = 1
        BEGIN
            SELECT CASE
                WHEN (
                    SELECT remaining_budget FROM social_budget 
                    WHERE year = CAST(strftime('%Y', COALESCE(NEW.transaction_date, 'now')) AS INTEGER)
                ) < NEW.amount
                THEN RAISE(ABORT, '⚠️ الميزانية المتبقية غير كافية لإتمام هذه المعاملة')
            END;
        END",
        "DROP TRIGGER IF EXISTS trg_budget_transactions_after_insert",
        "CREATE TRIGGER IF NOT EXISTS trg_budget_transactions_after_insert
        AFTER INSERT ON budget_transactions
        FOR EACH ROW
        BEGIN
            UPDATE social_budget
            SET remaining_budget = remaining_budget - CASE WHEN NEW.is_deduct = 1 THEN NEW.amount ELSE -NEW.amount END,
                last_updated = CURRENT_TIMESTAMP
            WHERE year = CAST(strftime('%Y', COALESCE(NEW.transaction_date, 'now')) AS INTEGER);
        END",
        "DROP TRIGGER IF EXISTS trg_budget_transactions_after_delete",
        "CREATE TRIGGER IF NOT EXISTS trg_budget_transactions_after_delete
        AFTER DELETE ON budget_transactions
        FOR EACH ROW
        BEGIN
            UPDATE social_budget
            SET remaining_budget = remaining_budget + CASE WHEN OLD.is_deduct = 1 THEN OLD.amount ELSE -OLD.amount END,
                last_updated = CURRENT_TIMESTAMP
            WHERE year = CAST(strftime('%Y', COALESCE(OLD.transaction_date, 'now')) AS INTEGER);
        END",
    ];
    
    for ($i = 0; $i < count($triggers); $i += 2) {
        $dropSql = $triggers[$i];
        $createSql = $triggers[$i+1];
        try {
            $pdo->exec($dropSql);
            $pdo->exec($createSql);
            echo "✓ مشغل: " . substr($createSql, 0, 60) . "...\n";
        } catch (PDOException $e) {
            echo "⚠️ خطأ في المشغل: " . $e->getMessage() . "\n";
        }
    }
    
    // إضافة بيانات افتراضية (إذا لم تكن موجودة)
    $pdo->exec("INSERT OR IGNORE INTO users (username, password, role, created_at) 
                VALUES ('admin', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'admin', CURRENT_TIMESTAMP)");
    $pdo->exec("UPDATE users SET is_active = 1, password_changed_at = CURRENT_TIMESTAMP WHERE username = 'admin' AND (is_active IS NULL OR password_changed_at IS NULL)");
    
    $pdo->exec("INSERT OR IGNORE INTO sources (name, description, is_loan, created_at) VALUES 
                ('سلفية اجتماعية', 'سلفة بفائدة 0% تصرف للموظفين', 1, CURRENT_TIMESTAMP),
                ('سعدين للتجهير', 'اقتطاع شهري لسعدين للتجهير', 0, CURRENT_TIMESTAMP),
                ('djezzy', 'اقتطاع شهري لشركة جيزي', 0, CURRENT_TIMESTAMP)");
    
    $pdo->exec("INSERT OR IGNORE INTO settings (setting_key, setting_value, created_at) VALUES 
                ('app_version', '2.0', CURRENT_TIMESTAMP),
                ('db_version', '7', CURRENT_TIMESTAMP),
                ('last_migration', datetime('now'), CURRENT_TIMESTAMP)");
    
    $pdo->commit();
    echo "\n✅ تمت الترقية بنجاح!\n";
} catch (PDOException $e) {
    $pdo->rollBack();
    echo "\n❌ حدث خطأ: " . $e->getMessage() . "\n";
}
echo "</pre>";
echo '<br><a href="settings.php">🔙 العودة إلى الإعدادات</a>';
?>