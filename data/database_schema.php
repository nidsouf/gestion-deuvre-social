-- ============================================================
-- نظام إدارة الاقتطاعات والمنح الاجتماعية
-- هيكل قاعدة البيانات (SQLite)
-- ============================================================

-- ----------------------------
-- 1. المستخدمون (صلاحيات)
-- ----------------------------
CREATE TABLE IF NOT EXISTS users (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    username TEXT NOT NULL UNIQUE,
    password TEXT NOT NULL,
    full_name TEXT,
    role TEXT DEFAULT 'user',
    is_active INTEGER DEFAULT 1,
    created_at TEXT DEFAULT CURRENT_TIMESTAMP
);

-- ----------------------------
-- 2. الموظفون
-- ----------------------------
CREATE TABLE IF NOT EXISTS employees (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name TEXT NOT NULL,
    category TEXT CHECK(category IN ('Permanent','Contract')) DEFAULT 'Contract',
    account_number TEXT,   -- رقم الحساب البنكي/الاجتماعي
    is_active INTEGER DEFAULT 1,
    created_at TEXT DEFAULT CURRENT_TIMESTAMP
);

-- ----------------------------
-- 3. مصادر الاقتطاع
-- ----------------------------
CREATE TABLE IF NOT EXISTS sources (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name TEXT NOT NULL UNIQUE,
    created_at TEXT DEFAULT CURRENT_TIMESTAMP
);

-- ----------------------------
-- 4. أنواع المنح (مع دعم النسب المئوية)
-- ----------------------------
CREATE TABLE IF NOT EXISTS grants (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name TEXT NOT NULL,
    amount REAL DEFAULT 0,                      -- القيمة الثابتة (لـ fixed)
    calculation_type TEXT DEFAULT 'fixed' CHECK(calculation_type IN ('fixed','percentage')),
    percentage_value REAL DEFAULT 0,           -- النسبة المئوية (لـ percentage)
    max_amount REAL DEFAULT 0,                 -- الحد الأقصى (0 = بدون حد)
    created_at TEXT DEFAULT CURRENT_TIMESTAMP,
    updated_at TEXT DEFAULT CURRENT_TIMESTAMP
);

-- ----------------------------
-- 5. المنح الموزعة على الموظفين
-- ----------------------------
CREATE TABLE IF NOT EXISTS employee_grants (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    employee_id INTEGER NOT NULL,
    grant_id INTEGER NOT NULL,
    grant_date TEXT NOT NULL,
    amount REAL DEFAULT 0,                     -- المبلغ الفعلي المحسوب (الثابت أو النسبة)
    invoice_amount REAL DEFAULT 0,             -- قيمة الفاتورة (للمنح النسبية)
    notes TEXT,
    created_at TEXT DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (employee_id) REFERENCES employees(id) ON DELETE CASCADE,
    FOREIGN KEY (grant_id) REFERENCES grants(id) ON DELETE CASCADE
);

-- ----------------------------
-- 6. الاقتطاعات (سلف / قروض / اقتطاعات عادية)
-- ----------------------------
CREATE TABLE IF NOT EXISTS deductions (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    employee_id INTEGER NOT NULL,
    source_id INTEGER NOT NULL,
    monthly_amount REAL NOT NULL,              -- القسط الشهري
    total_months INTEGER NOT NULL,             -- عدد الأشهر الإجمالي
    start_date TEXT NOT NULL,                  -- تاريخ بداية الاقتطاع
    end_date TEXT NOT NULL,                    -- تاريخ النهاية المتوقع
    is_loan INTEGER DEFAULT 0,                 -- 1 = سلفة, 0 = اقتطاع عادي
    grant_date TEXT,                           -- تاريخ صرف السلفة
    credit_balance REAL DEFAULT 0,             -- الرصيد الدائن (للتسديد المقدم)
    included_in_minute_id INTEGER DEFAULT NULL,-- معرف المحضر الذي تضمن هذه السلفة
    notes TEXT,
    created_at TEXT DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (employee_id) REFERENCES employees(id) ON DELETE CASCADE,
    FOREIGN KEY (source_id) REFERENCES sources(id) ON DELETE CASCADE
);

-- ----------------------------
-- 7. الأقساط الشهرية (للكل اقتطاع)
-- ----------------------------
CREATE TABLE IF NOT EXISTS monthly_installments (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    deduction_id INTEGER NOT NULL,
    employee_id INTEGER NOT NULL,
    source_id INTEGER NOT NULL,
    year INTEGER NOT NULL,
    month INTEGER NOT NULL,
    amount REAL NOT NULL,
    is_paid INTEGER DEFAULT 0,
    paid_date TEXT,
    is_postponed INTEGER DEFAULT 0,            -- 1 = مؤجل
    postponed_from_month TEXT,                 -- الشهر الأصلي (YYYY-MM)
    created_at TEXT DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (deduction_id) REFERENCES deductions(id) ON DELETE CASCADE,
    FOREIGN KEY (employee_id) REFERENCES employees(id),
    FOREIGN KEY (source_id) REFERENCES sources(id)
);

-- ----------------------------
-- 8. سجل تأجيل الأقساط
-- ----------------------------
CREATE TABLE IF NOT EXISTS installment_postponements (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    installment_id INTEGER NOT NULL,
    deduction_id INTEGER NOT NULL,
    employee_id INTEGER NOT NULL,
    original_month TEXT NOT NULL,              -- YYYY-MM
    new_month TEXT NOT NULL,                   -- YYYY-MM (الشهر الجديد)
    reason TEXT,
    postponed_by INTEGER,
    postponed_at TEXT DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (installment_id) REFERENCES monthly_installments(id) ON DELETE CASCADE,
    FOREIGN KEY (deduction_id) REFERENCES deductions(id),
    FOREIGN KEY (employee_id) REFERENCES employees(id)
);

-- ----------------------------
-- 9. التسديد المقدم (للسلف فقط)
-- ----------------------------
CREATE TABLE IF NOT EXISTS early_payments (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    deduction_id INTEGER NOT NULL,
    months_paid INTEGER NOT NULL,
    amount REAL NOT NULL,
    original_end_date TEXT NOT NULL,
    original_total_months INTEGER NOT NULL,
    payment_date TEXT DEFAULT CURRENT_TIMESTAMP,
    is_reversed INTEGER DEFAULT 0,
    reversed_date TEXT,
    FOREIGN KEY (deduction_id) REFERENCES deductions(id) ON DELETE CASCADE
);

-- ----------------------------
-- 10. مدفوعات المصادر (شيكات)
-- ----------------------------
CREATE TABLE IF NOT EXISTS source_payments (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    source_id INTEGER NOT NULL,
    amount REAL NOT NULL,
    cheque_date TEXT NOT NULL,
    cheque_number TEXT,
    quarter INTEGER,
    notes TEXT,
    included_in_minute_id INTEGER DEFAULT NULL,
    created_at TEXT DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (source_id) REFERENCES sources(id)
);

-- ----------------------------
-- 11. المحاضر الشهرية
-- ----------------------------
CREATE TABLE IF NOT EXISTS meeting_minutes (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    month INTEGER NOT NULL,
    year INTEGER NOT NULL,
    session_number INTEGER DEFAULT 1,
    meeting_date TEXT NOT NULL,
    meeting_number TEXT,
    content TEXT,
    notes TEXT,
    total_grants_amount REAL DEFAULT 0,
    umrah_draw_event_id INTEGER DEFAULT NULL,
    show_honorees INTEGER DEFAULT 0,
    honorees_year INTEGER DEFAULT NULL,
    show_cheques INTEGER DEFAULT 0,
    show_djezzy INTEGER DEFAULT 0,
    created_by TEXT,
    created_at TEXT DEFAULT CURRENT_TIMESTAMP,
    updated_at TEXT DEFAULT CURRENT_TIMESTAMP
);

-- ----------------------------
-- 12. الميزانية الاجتماعية
-- ----------------------------
CREATE TABLE IF NOT EXISTS social_budget (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    year INTEGER NOT NULL,
    initial_budget REAL DEFAULT 0,
    remaining_budget REAL DEFAULT 0,
    total_budget REAL DEFAULT 0,
    last_updated TEXT DEFAULT CURRENT_TIMESTAMP
);

-- ----------------------------
-- 13. المعاملات المالية (دفتر الأستاذ)
-- ----------------------------
CREATE TABLE IF NOT EXISTS budget_transactions (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    reference_id INTEGER NOT NULL,             -- معرف الكيان المرتبط (منحة، سلفة، قسط...)
    type TEXT NOT NULL CHECK(type IN ('grant','loan','installment','other')),
    amount REAL NOT NULL,
    description TEXT,
    is_deduct INTEGER DEFAULT 1,               -- 1 = خصم (صرف), 0 = إضافة (استرجاع)
    transaction_date TEXT DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (reference_id) REFERENCES employee_grants(id) ON DELETE CASCADE
    -- ملاحظة: reference_id يمكن أن يشير إلى جداول مختلفة، لذا لا نضيف FOREIGN KEY صارم هنا
);

-- ----------------------------
-- 14. أرقام هواتف الموظفين (جيزي)
-- ----------------------------
CREATE TABLE IF NOT EXISTS employee_phone_numbers (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    employee_id INTEGER NOT NULL,
    phone_number TEXT NOT NULL,
    monthly_amount REAL NOT NULL,
    is_active INTEGER DEFAULT 1,
    created_at TEXT DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (employee_id) REFERENCES employees(id) ON DELETE CASCADE
);

-- ----------------------------
-- 15. وجبات المطعم (تثبيت شهري)
-- ----------------------------
CREATE TABLE IF NOT EXISTS meal_installments (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    employee_id INTEGER NOT NULL,
    year INTEGER NOT NULL,
    month INTEGER NOT NULL,
    total_meals INTEGER DEFAULT 0,
    total_amount REAL DEFAULT 0,
    grant_amount REAL DEFAULT 0,
    is_processed INTEGER DEFAULT 0,
    created_at TEXT DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (employee_id) REFERENCES employees(id) ON DELETE CASCADE
);

-- ----------------------------
-- 16. مكرمي عيد العمال
-- ----------------------------
CREATE TABLE IF NOT EXISTS labor_day_honorees (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    employee_id INTEGER NOT NULL,
    year INTEGER NOT NULL,
    prize_type TEXT NOT NULL,
    prize_value REAL NOT NULL,
    honor_date TEXT NOT NULL,
    reason TEXT,
    created_at TEXT DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (employee_id) REFERENCES employees(id) ON DELETE CASCADE
);

-- ----------------------------
-- 17. سحوبات العمرة (الحدث)
-- ----------------------------
CREATE TABLE IF NOT EXISTS umrah_draw_events (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    title TEXT,
    draw_date TEXT NOT NULL,
    winner_id INTEGER,
    status TEXT DEFAULT 'pending' CHECK(status IN ('pending','completed','cancelled')),
    created_at TEXT DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (winner_id) REFERENCES employees(id)
);

-- ----------------------------
-- 18. المشاركون في سحب العمرة
-- ----------------------------
CREATE TABLE IF NOT EXISTS umrah_draws (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    draw_event_id INTEGER NOT NULL,
    employee_id INTEGER NOT NULL,
    tickets_count INTEGER DEFAULT 1,
    is_winner INTEGER DEFAULT 0,
    reserve_order INTEGER DEFAULT NULL,
    created_at TEXT DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (draw_event_id) REFERENCES umrah_draw_events(id) ON DELETE CASCADE,
    FOREIGN KEY (employee_id) REFERENCES employees(id)
);

-- ----------------------------
-- 19. الإشعارات
-- ----------------------------
CREATE TABLE IF NOT EXISTS notifications (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER DEFAULT NULL,
    title TEXT NOT NULL,
    message TEXT NOT NULL,
    type TEXT DEFAULT 'info',
    priority TEXT DEFAULT 'normal',
    link TEXT,
    is_read INTEGER DEFAULT 0,
    created_at TEXT DEFAULT CURRENT_TIMESTAMP
);

-- ----------------------------
-- 20. سجل التدقيق (Audit)
-- ----------------------------
CREATE TABLE IF NOT EXISTS audit_logs (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER,
    username TEXT,
    action TEXT NOT NULL,
    entity_type TEXT,
    entity_id INTEGER,
    old_values TEXT,
    new_values TEXT,
    ip_address TEXT,
    user_agent TEXT,
    created_at TEXT DEFAULT CURRENT_TIMESTAMP
);

-- ----------------------------
-- 21. قواعد النظام (إعدادات)
-- ----------------------------
CREATE TABLE IF NOT EXISTS system_rules (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    rule_code TEXT NOT NULL UNIQUE,
    rule_value TEXT NOT NULL,
    rule_type TEXT DEFAULT 'string',
    is_active INTEGER DEFAULT 1,
    created_at TEXT DEFAULT CURRENT_TIMESTAMP,
    updated_at TEXT DEFAULT CURRENT_TIMESTAMP
);

-- ----------------------------
-- 22. ملفات مرفقة (للأوراق الثبوتية)
-- ----------------------------
CREATE TABLE IF NOT EXISTS attachments (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    reference_id INTEGER NOT NULL,   -- معرف الكيان المرتبط (منحة، سلفة، إلخ)
    reference_type TEXT NOT NULL,    -- 'grant', 'loan', 'meeting', ...
    file_name TEXT NOT NULL,
    file_path TEXT NOT NULL,
    uploaded_by INTEGER,
    uploaded_at TEXT DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (uploaded_by) REFERENCES users(id)
);

-- ----------------------------
-- 23. إعدادات الطباعة (اختياري)
-- ----------------------------
CREATE TABLE IF NOT EXISTS print_settings (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    setting_key TEXT NOT NULL UNIQUE,
    setting_value TEXT,
    updated_at TEXT DEFAULT CURRENT_TIMESTAMP
);

-- ============================================================
-- إنشاء بعض الفهارس لتحسين الأداء
-- ============================================================
CREATE INDEX idx_installments_deduction ON monthly_installments(deduction_id);
CREATE INDEX idx_installments_paid ON monthly_installments(is_paid);
CREATE INDEX idx_installments_postponed ON monthly_installments(is_postponed);
CREATE INDEX idx_employee_grants_employee ON employee_grants(employee_id);
CREATE INDEX idx_employee_grants_grant ON employee_grants(grant_id);
CREATE INDEX idx_employee_grants_date ON employee_grants(grant_date);
CREATE INDEX idx_deductions_employee ON deductions(employee_id);
CREATE INDEX idx_deductions_source ON deductions(source_id);
CREATE INDEX idx_deductions_loan ON deductions(is_loan);
CREATE INDEX idx_budget_transactions_type ON budget_transactions(type);
CREATE INDEX idx_budget_transactions_date ON budget_transactions(transaction_date);
CREATE INDEX idx_meeting_minutes_date ON meeting_minutes(year, month);
CREATE INDEX idx_installment_postponements_deduction ON installment_postponements(deduction_id);
CREATE INDEX idx_early_payments_deduction ON early_payments(deduction_id);