-- =====================================================
-- Improved & migrated SQLite schema for "gestion-deuvre-social"
-- File: improved_schema.sql
-- Generated: 2026-05-09
-- Purpose: Normalized schema with money stored as integer cents,
--          stronger CHECK constraints, updated_at triggers, and
--          budget consistency triggers. Also includes migration steps
--          to convert existing DB to this schema.
-- =====================================================

PRAGMA foreign_keys = ON;

-- NOTE: This file both defines the improved schema and provides
-- migration snippets. Run migrations in maintenance window with backup.

-- =====================================================
-- 0. Helper: ensure FK enforcement for every connection (run from app)
-- PRAGMA foreign_keys = ON;

-- =====================================================
-- 1. employees
-- Keep minimal change; timestamps as TEXT (ISO8601)
CREATE TABLE IF NOT EXISTS employees (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name TEXT NOT NULL,
    category TEXT DEFAULT 'Contract',
    hire_date TEXT,
    created_at TEXT DEFAULT CURRENT_TIMESTAMP,
    updated_at TEXT DEFAULT CURRENT_TIMESTAMP
);

-- Trigger to update updated_at
CREATE TRIGGER IF NOT EXISTS trg_employees_updated_at
AFTER UPDATE ON employees
FOR EACH ROW
BEGIN
  UPDATE employees SET updated_at = CURRENT_TIMESTAMP WHERE id = NEW.id;
END;

-- =====================================================
-- 2. sources (unchanged semantics)
CREATE TABLE IF NOT EXISTS sources (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name TEXT NOT NULL UNIQUE,
    description TEXT,
    is_loan INTEGER DEFAULT 0 CHECK(is_loan IN (0,1)),
    created_at TEXT DEFAULT CURRENT_TIMESTAMP
);

-- =====================================================
-- 3. deductions - store money as integer cents (monthly_amount_cents)
CREATE TABLE IF NOT EXISTS deductions (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    employee_id INTEGER NOT NULL,
    source_id INTEGER NOT NULL,
    monthly_amount_cents INTEGER NOT NULL CHECK(monthly_amount_cents >= 0),
    total_months INTEGER NOT NULL CHECK(total_months >= 0),
    start_date TEXT NOT NULL,
    end_date TEXT NOT NULL,
    is_loan INTEGER DEFAULT 0 CHECK(is_loan IN (0,1)),
    grant_date TEXT,
    created_at TEXT DEFAULT CURRENT_TIMESTAMP,
    updated_at TEXT DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (employee_id) REFERENCES employees(id) ON DELETE CASCADE,
    FOREIGN KEY (source_id) REFERENCES sources(id) ON DELETE CASCADE
);

CREATE TRIGGER IF NOT EXISTS trg_deductions_updated_at
AFTER UPDATE ON deductions
FOR EACH ROW
BEGIN
  UPDATE deductions SET updated_at = CURRENT_TIMESTAMP WHERE id = NEW.id;
END;

CREATE INDEX IF NOT EXISTS idx_deductions_employee_id ON deductions(employee_id);
CREATE INDEX IF NOT EXISTS idx_deductions_source_id ON deductions(source_id);
CREATE INDEX IF NOT EXISTS idx_deductions_start_date ON deductions(start_date);
CREATE INDEX IF NOT EXISTS idx_deductions_end_date ON deductions(end_date);
CREATE INDEX IF NOT EXISTS idx_deductions_is_loan ON deductions(is_loan);

-- =====================================================
-- 4. grants
CREATE TABLE IF NOT EXISTS grants (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name TEXT NOT NULL UNIQUE,
    amount_cents INTEGER NOT NULL CHECK(amount_cents >= 0),
    created_at TEXT DEFAULT CURRENT_TIMESTAMP
);

-- =====================================================
-- 5. employee_grants
CREATE TABLE IF NOT EXISTS employee_grants (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    employee_id INTEGER NOT NULL,
    grant_id INTEGER NOT NULL,
    grant_date TEXT NOT NULL,
    notes TEXT,
    created_at TEXT DEFAULT CURRENT_TIMESTAMP,
    updated_at TEXT DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (employee_id) REFERENCES employees(id) ON DELETE CASCADE,
    FOREIGN KEY (grant_id) REFERENCES grants(id) ON DELETE CASCADE
);

CREATE TRIGGER IF NOT EXISTS trg_employee_grants_updated_at
AFTER UPDATE ON employee_grants
FOR EACH ROW
BEGIN
  UPDATE employee_grants SET updated_at = CURRENT_TIMESTAMP WHERE id = NEW.id;
END;

CREATE INDEX IF NOT EXISTS idx_employee_grants_employee_id ON employee_grants(employee_id);
CREATE INDEX IF NOT EXISTS idx_employee_grants_grant_date ON employee_grants(grant_date);

-- =====================================================
-- 6. social_budget (store money in cents)
CREATE TABLE IF NOT EXISTS social_budget (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    year INTEGER NOT NULL,
    initial_budget_cents INTEGER NOT NULL CHECK(initial_budget_cents >= 0),
    remaining_budget_cents INTEGER NOT NULL CHECK(remaining_budget_cents >= 0),
    last_updated TEXT DEFAULT CURRENT_TIMESTAMP,
    UNIQUE(year)
);

-- =====================================================
-- 7. budget_transactions (amount in cents)
CREATE TABLE IF NOT EXISTS budget_transactions (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    transaction_date TEXT DEFAULT CURRENT_TIMESTAMP,
    amount_cents INTEGER NOT NULL CHECK(amount_cents >= 0),
    type TEXT NOT NULL CHECK(type IN ('grant', 'loan', 'installment', 'source_payment')),
    reference_id INTEGER NOT NULL,
    description TEXT,
    is_deduct INTEGER DEFAULT 1 CHECK(is_deduct IN (0,1))
);

CREATE INDEX IF NOT EXISTS idx_budget_transactions_type_ref ON budget_transactions(type, reference_id);

-- Trigger: ensure there's a social_budget row for the transaction year
CREATE TRIGGER IF NOT EXISTS trg_budget_transactions_before_insert_check_budget_exist
BEFORE INSERT ON budget_transactions
FOR EACH ROW
BEGIN
  SELECT CASE
    WHEN (SELECT COUNT(1) FROM social_budget WHERE year = CAST(strftime('%Y', NEW.transaction_date) AS INTEGER)) = 0
    THEN RAISE(ABORT, 'No social_budget row for transaction year')
  END;
END;

-- Trigger: update social_budget.remaining_budget after insert
CREATE TRIGGER IF NOT EXISTS trg_budget_transactions_after_insert
AFTER INSERT ON budget_transactions
FOR EACH ROW
BEGIN
  UPDATE social_budget
  SET remaining_budget_cents = remaining_budget_cents - CASE WHEN NEW.is_deduct = 1 THEN NEW.amount_cents ELSE -NEW.amount_cents END,
      last_updated = CURRENT_TIMESTAMP
  WHERE year = CAST(strftime('%Y', NEW.transaction_date) AS INTEGER);
END;

-- Trigger: rollback remaining_budget on delete (reverse effect)
CREATE TRIGGER IF NOT EXISTS trg_budget_transactions_after_delete
AFTER DELETE ON budget_transactions
FOR EACH ROW
BEGIN
  UPDATE social_budget
  SET remaining_budget_cents = remaining_budget_cents + CASE WHEN OLD.is_deduct = 1 THEN OLD.amount_cents ELSE -OLD.amount_cents END,
      last_updated = CURRENT_TIMESTAMP
  WHERE year = CAST(strftime('%Y', OLD.transaction_date) AS INTEGER);
END;

-- =====================================================
-- 8. source_payments (amount in cents)
CREATE TABLE IF NOT EXISTS source_payments (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    source_id INTEGER NOT NULL,
    cheque_number TEXT NOT NULL,
    cheque_date TEXT NOT NULL,
    amount_cents INTEGER NOT NULL CHECK(amount_cents >= 0),
    notes TEXT,
    quarter INTEGER CHECK(quarter BETWEEN 1 AND 4),
    created_at TEXT DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (source_id) REFERENCES sources(id) ON DELETE CASCADE
);

CREATE INDEX IF NOT EXISTS idx_source_payments_source_id ON source_payments(source_id);
CREATE INDEX IF NOT EXISTS idx_source_payments_cheque_date ON source_payments(cheque_date);

-- =====================================================
-- 9. employee_phone_numbers
CREATE TABLE IF NOT EXISTS employee_phone_numbers (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    employee_id INTEGER NOT NULL,
    phone_number TEXT NOT NULL,
    monthly_amount_cents INTEGER DEFAULT 30000 CHECK(monthly_amount_cents >= 0), -- default 300.00 -> 30000 cents
    is_active INTEGER DEFAULT 1 CHECK(is_active IN (0,1)),
    created_at TEXT DEFAULT CURRENT_TIMESTAMP,
    updated_at TEXT DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (employee_id) REFERENCES employees(id) ON DELETE CASCADE,
    UNIQUE(employee_id, phone_number)
);

CREATE TRIGGER IF NOT EXISTS trg_employee_phone_numbers_updated_at
AFTER UPDATE ON employee_phone_numbers
FOR EACH ROW
BEGIN
  UPDATE employee_phone_numbers SET updated_at = CURRENT_TIMESTAMP WHERE id = NEW.id;
END;

CREATE INDEX IF NOT EXISTS idx_employee_phone_numbers_employee ON employee_phone_numbers(employee_id);
CREATE INDEX IF NOT EXISTS idx_employee_phone_numbers_active ON employee_phone_numbers(is_active);

-- =====================================================
-- 10. meeting_minutes
CREATE TABLE IF NOT EXISTS meeting_minutes (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    month INTEGER NOT NULL CHECK(month BETWEEN 1 AND 12),
    year INTEGER NOT NULL,
    session_number INTEGER DEFAULT 1,
    meeting_date TEXT,
    meeting_number TEXT,
    content TEXT,
    total_grants_amount_cents INTEGER DEFAULT 0 CHECK(total_grants_amount_cents >= 0),
    total_deductions_amount_cents INTEGER DEFAULT 0 CHECK(total_deductions_amount_cents >= 0),
    umrah_draw_event_id INTEGER DEFAULT NULL,
    show_honorees INTEGER DEFAULT 0 CHECK(show_honorees IN (0,1)),
    honorees_year INTEGER DEFAULT NULL,
    notes TEXT,
    created_by TEXT,
    created_at TEXT DEFAULT CURRENT_TIMESTAMP,
    updated_at TEXT DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (umrah_draw_event_id) REFERENCES umrah_draw_events(id) ON DELETE SET NULL,
    UNIQUE(month, year, session_number)
);

CREATE TRIGGER IF NOT EXISTS trg_meeting_minutes_updated_at
AFTER UPDATE ON meeting_minutes
FOR EACH ROW
BEGIN
  UPDATE meeting_minutes SET updated_at = CURRENT_TIMESTAMP WHERE id = NEW.id;
END;

CREATE INDEX IF NOT EXISTS idx_meeting_minutes_month_year ON meeting_minutes(month, year, session_number);

-- =====================================================
-- 11. meal_records
CREATE TABLE IF NOT EXISTS meal_records (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    employee_id INTEGER NOT NULL,
    year INTEGER NOT NULL,
    month INTEGER NOT NULL CHECK(month BETWEEN 1 AND 12),
    meal_count INTEGER NOT NULL DEFAULT 0 CHECK(meal_count >= 0),
    total_amount_cents INTEGER NOT NULL DEFAULT 0 CHECK(total_amount_cents >= 0),
    created_at TEXT DEFAULT CURRENT_TIMESTAMP,
    updated_at TEXT DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (employee_id) REFERENCES employees(id) ON DELETE CASCADE,
    UNIQUE(employee_id, year, month)
);

CREATE TRIGGER IF NOT EXISTS trg_meal_records_updated_at
AFTER UPDATE ON meal_records
FOR EACH ROW
BEGIN
  UPDATE meal_records SET updated_at = CURRENT_TIMESTAMP WHERE id = NEW.id;
END;

CREATE INDEX IF NOT EXISTS idx_meal_records_employee_year_month ON meal_records(employee_id, year, month);

-- =====================================================
-- 12. meal_trimesters
CREATE TABLE IF NOT EXISTS meal_trimesters (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    trimester_number INTEGER NOT NULL CHECK(trimester_number BETWEEN 1 AND 4),
    year INTEGER NOT NULL,
    start_date TEXT NOT NULL,
    end_date TEXT NOT NULL,
    total_meals INTEGER NOT NULL DEFAULT 0 CHECK(total_meals >= 0),
    total_amount_cents INTEGER NOT NULL DEFAULT 0 CHECK(total_amount_cents >= 0),
    half_amount_cents INTEGER NOT NULL DEFAULT 0 CHECK(half_amount_cents >= 0),
    status TEXT DEFAULT 'pending',
    deduction_id INTEGER DEFAULT NULL,
    created_at TEXT DEFAULT CURRENT_TIMESTAMP,
    updated_at TEXT DEFAULT CURRENT_TIMESTAMP
);

CREATE TRIGGER IF NOT EXISTS trg_meal_trimesters_updated_at
AFTER UPDATE ON meal_trimesters
FOR EACH ROW
BEGIN
  UPDATE meal_trimesters SET updated_at = CURRENT_TIMESTAMP WHERE id = NEW.id;
END;

-- =====================================================
-- 13. umrah_draw_events
CREATE TABLE IF NOT EXISTS umrah_draw_events (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    draw_date TEXT NOT NULL,
    title TEXT,
    winner_id INTEGER DEFAULT NULL,
    status TEXT DEFAULT 'pending',
    created_at TEXT DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (winner_id) REFERENCES employees(id) ON DELETE SET NULL
);

-- =====================================================
-- 14. umrah_draws
CREATE TABLE IF NOT EXISTS umrah_draws (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    draw_event_id INTEGER NOT NULL,
    employee_id INTEGER NOT NULL,
    draw_date TEXT NOT NULL,
    tickets_count INTEGER NOT NULL DEFAULT 0 CHECK(tickets_count >= 0),
    is_winner INTEGER DEFAULT 0 CHECK(is_winner IN (0,1)),
    reserve_order INTEGER DEFAULT NULL,
    is_selected INTEGER DEFAULT 1 CHECK(is_selected IN (0,1)),
    notes TEXT,
    created_at TEXT DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (draw_event_id) REFERENCES umrah_draw_events(id) ON DELETE CASCADE,
    FOREIGN KEY (employee_id) REFERENCES employees(id) ON DELETE CASCADE
);

CREATE INDEX IF NOT EXISTS idx_umrah_draws_event_id ON umrah_draws(draw_event_id);
CREATE INDEX IF NOT EXISTS idx_umrah_draws_employee_id ON umrah_draws(employee_id);

-- =====================================================
-- 15. labor_day_honorees
CREATE TABLE IF NOT EXISTS labor_day_honorees (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    employee_id INTEGER NOT NULL,
    year INTEGER NOT NULL,
    honor_date TEXT DEFAULT CURRENT_DATE,
    reason TEXT,
    prize_type TEXT DEFAULT 'شهادة تقدير',
    prize_value_cents INTEGER DEFAULT 0 CHECK(prize_value_cents >= 0),
    certificate_path TEXT,
    created_at TEXT DEFAULT CURRENT_TIMESTAMP,
    updated_at TEXT DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (employee_id) REFERENCES employees(id) ON DELETE CASCADE,
    UNIQUE(employee_id, year)
);

CREATE TRIGGER IF NOT EXISTS trg_labor_day_honorees_updated_at
AFTER UPDATE ON labor_day_honorees
FOR EACH ROW
BEGIN
  UPDATE labor_day_honorees SET updated_at = CURRENT_TIMESTAMP WHERE id = NEW.id;
END;

-- =====================================================
-- 16. internal_regulations
CREATE TABLE IF NOT EXISTS internal_regulations (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    title TEXT NOT NULL,
    version TEXT NOT NULL,
    description TEXT,
    file_name TEXT NOT NULL,
    file_path TEXT NOT NULL,
    file_size INTEGER,
    uploaded_by TEXT,
    is_active INTEGER DEFAULT 1 CHECK(is_active IN (0,1)),
    upload_date TEXT DEFAULT CURRENT_TIMESTAMP
);

-- =====================================================
-- 17. monthly_deductions_log
CREATE TABLE IF NOT EXISTS monthly_deductions_log (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    year INTEGER NOT NULL,
    month INTEGER NOT NULL CHECK(month BETWEEN 1 AND 12),
    employee_id INTEGER NOT NULL,
    deduction_type TEXT NOT NULL,
    amount_cents INTEGER NOT NULL CHECK(amount_cents >= 0),
    calculated_at TEXT DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (employee_id) REFERENCES employees(id) ON DELETE CASCADE
);

-- =====================================================
-- 18. users
CREATE TABLE IF NOT EXISTS users (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    username TEXT UNIQUE NOT NULL,
    password TEXT NOT NULL,
    role TEXT DEFAULT 'user',
    last_login TEXT,
    is_active INTEGER DEFAULT 1 CHECK(is_active IN (0,1)),
    created_at TEXT DEFAULT CURRENT_TIMESTAMP,
    updated_at TEXT DEFAULT CURRENT_TIMESTAMP
);

CREATE TRIGGER IF NOT EXISTS trg_users_updated_at
AFTER UPDATE ON users
FOR EACH ROW
BEGIN
  UPDATE users SET updated_at = CURRENT_TIMESTAMP WHERE id = NEW.id;
END;

-- =====================================================
-- 19. audit_log
CREATE TABLE IF NOT EXISTS audit_log (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER,
    username TEXT,
    action TEXT NOT NULL,
    table_name TEXT,
    record_id INTEGER,
    old_value TEXT,
    new_value TEXT,
    ip_address TEXT,
    user_agent TEXT,
    created_at TEXT DEFAULT CURRENT_TIMESTAMP
);

-- =====================================================
-- 20. settings
CREATE TABLE IF NOT EXISTS settings (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    setting_key TEXT NOT NULL UNIQUE,
    setting_value TEXT,
    created_at TEXT DEFAULT CURRENT_TIMESTAMP,
    updated_at TEXT DEFAULT CURRENT_TIMESTAMP
);

CREATE TRIGGER IF NOT EXISTS trg_settings_updated_at
AFTER UPDATE ON settings
FOR EACH ROW
BEGIN
  UPDATE settings SET updated_at = CURRENT_TIMESTAMP WHERE id = NEW.id;
END;

-- =====================================================
-- Indexes (additional)
CREATE INDEX IF NOT EXISTS idx_budget_transactions_date ON budget_transactions(transaction_date);
CREATE INDEX IF NOT EXISTS idx_social_budget_year ON social_budget(year);

-- =====================================================
-- Default data (safe inserts) - only if not present
INSERT OR IGNORE INTO users (username, password, role, is_active) VALUES
('admin', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'admin', 1);

INSERT OR IGNORE INTO sources (name, description, is_loan) VALUES
('سلفية اجتماعية', 'سلفة بفائدة 0% تصرف للموظفين', 1),
('سعدين للتجهير', 'اقتطاع شهري لسعدين للتجهير', 0),
('djezzy', 'اقتطاع شهري لشركة جيزي', 0);

-- =====================================================
-- Migration guide (run after backing up current DB)
-- 1) Create a backup of existing DB file.
-- 2) Run this file on a new empty DB to create the improved schema OR
--    to migrate an existing DB, follow the pattern below for tables that
--    need column type changes (monetary columns). Example for `deductions`:

-- Example migration steps for converting monetary REAL -> INTEGER cents
-- BEGIN TRANSACTION;
-- CREATE TABLE deductions_new AS (same definition as deductions but with monthly_amount_cents INTEGER ...);
-- INSERT INTO deductions_new (id, employee_id, source_id, monthly_amount_cents, total_months, start_date, end_date, is_loan, grant_date, created_at)
--   SELECT id, employee_id, source_id, ROUND(COALESCE(monthly_amount,0) * 100), total_months, start_date, end_date, is_loan, grant_date, created_at
--   FROM deductions;
-- DROP TABLE deductions;
-- ALTER TABLE deductions_new RENAME TO deductions;
-- COMMIT;

-- Repeat similar pattern for other tables containing REAL money columns.

-- =====================================================
-- End of improved_schema.sql
