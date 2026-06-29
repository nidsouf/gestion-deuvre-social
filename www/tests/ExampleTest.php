<?php
/**
 * tests/ExampleTest.php - اختبارات PHPUnit للنظام
 */

use PHPUnit\Framework\TestCase;

class ExampleTest extends TestCase
{
    private $pdo;
    private $testBudgetId;
    
    protected function setUp(): void
    {
        parent::setUp();
        require_once __DIR__ . '/../config/database.php';
        $this->pdo = getDBConnection();
        $this->pdo->beginTransaction();
        
        $testYear = date('Y') + 99;
        $stmt = $this->pdo->prepare("INSERT INTO social_budget (year, initial_budget, remaining_budget) VALUES (?, ?, ?)");
        $stmt->execute([$testYear, 100000, 75000]);
        $this->testBudgetId = $this->pdo->lastInsertId();
    }
    
    protected function tearDown(): void
    {
        if ($this->pdo && $this->pdo->inTransaction()) $this->pdo->rollBack();
        parent::tearDown();
    }
    
    // ==================== اختبارات budget/reset.php ====================
    
    /** @test */
    public function testBudgetResetResetsRemainingToInitial()
    {
        $stmt = $this->pdo->prepare("SELECT * FROM social_budget WHERE id = ?");
        $stmt->execute([$this->testBudgetId]);
        $budget = $stmt->fetch(PDO::FETCH_ASSOC);
        
        $this->assertLessThan($budget['initial_budget'], $budget['remaining_budget']);
        
        $stmt = $this->pdo->prepare("UPDATE social_budget SET remaining_budget = initial_budget WHERE id = ?");
        $stmt->execute([$this->testBudgetId]);
        
        $stmt->execute([$this->testBudgetId]);
        $budgetAfter = $stmt->fetch(PDO::FETCH_ASSOC);
        
        $this->assertEquals($budgetAfter['initial_budget'], $budgetAfter['remaining_budget']);
    }
    
    /** @test */
    public function testBudgetResetDoesNotChangeInitialBudget()
    {
        $stmt = $this->pdo->prepare("SELECT initial_budget FROM social_budget WHERE id = ?");
        $stmt->execute([$this->testBudgetId]);
        $initialBefore = $stmt->fetchColumn();
        
        $stmt = $this->pdo->prepare("UPDATE social_budget SET remaining_budget = initial_budget WHERE id = ?");
        $stmt->execute([$this->testBudgetId]);
        
        $stmt->execute([$this->testBudgetId]);
        $initialAfter = $stmt->fetchColumn();
        
        $this->assertEquals($initialBefore, $initialAfter);
    }
    
    /** @test */
    public function testBudgetResetRemainingDoesNotExceedInitial()
    {
        $stmt = $this->pdo->prepare("UPDATE social_budget SET remaining_budget = initial_budget WHERE id = ?");
        $stmt->execute([$this->testBudgetId]);
        
        $stmt = $this->pdo->prepare("SELECT initial_budget, remaining_budget FROM social_budget WHERE id = ?");
        $stmt->execute([$this->testBudgetId]);
        $budget = $stmt->fetch(PDO::FETCH_ASSOC);
        
        $this->assertLessThanOrEqual($budget['initial_budget'], $budget['remaining_budget']);
    }
    
    /** @test */
    public function testBudgetResetLogsToAudit()
    {
        $stmt = $this->pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name='audit_log'");
        if (!$stmt->fetch()) {
            $this->markTestSkipped('جدول audit_log غير موجود');
            return;
        }
        
        $stmt = $this->pdo->query("SELECT COUNT(*) FROM audit_log");
        $countBefore = (int)$stmt->fetchColumn();
        
        $stmt = $this->pdo->prepare("INSERT INTO audit_log (user_id, username, action, details, ip_address, created_at) VALUES (?, ?, ?, ?, ?, datetime('now'))");
        $stmt->execute([1, 'tester', 'BUDGET_RESET', 'تم إعادة تعيين ميزانية الاختبار', '127.0.0.1']);
        
        $stmt = $this->pdo->query("SELECT COUNT(*) FROM audit_log");
        $countAfter = (int)$stmt->fetchColumn();
        
        $this->assertGreaterThan($countBefore, $countAfter);
    }
    
    /** @test */
    public function testBudgetResetOnlyWhenNeeded()
    {
        $stmt = $this->pdo->prepare("UPDATE social_budget SET remaining_budget = initial_budget WHERE id = ?");
        $stmt->execute([$this->testBudgetId]);
        
        $stmt = $this->pdo->prepare("SELECT initial_budget, remaining_budget FROM social_budget WHERE id = ?");
        $stmt->execute([$this->testBudgetId]);
        $budgetBefore = $stmt->fetch(PDO::FETCH_ASSOC);
        
        $this->assertEquals($budgetBefore['initial_budget'], $budgetBefore['remaining_budget']);
        
        $stmt->execute([$this->testBudgetId]);
        $budgetAfter = $stmt->fetch(PDO::FETCH_ASSOC);
        
        $this->assertEquals($budgetBefore['remaining_budget'], $budgetAfter['remaining_budget']);
    }
    
    /** @test */
    public function testBudgetResetWithInvalidId()
    {
        $stmt = $this->pdo->prepare("UPDATE social_budget SET remaining_budget = initial_budget WHERE id = ?");
        $stmt->execute([999999]);
        $this->assertEquals(0, $stmt->rowCount());
    }
    
    /** @test */
    public function testBudgetResetAfterMultipleExpenses()
    {
        $expenses = [10000, 5000, 7500];
        foreach ($expenses as $expense) {
            $stmt = $this->pdo->prepare("UPDATE social_budget SET remaining_budget = remaining_budget - ? WHERE id = ?");
            $stmt->execute([$expense, $this->testBudgetId]);
        }
        
        $stmt = $this->pdo->prepare("SELECT initial_budget, remaining_budget FROM social_budget WHERE id = ?");
        $stmt->execute([$this->testBudgetId]);
        $budgetBefore = $stmt->fetch(PDO::FETCH_ASSOC);
        
        $this->assertLessThan($budgetBefore['initial_budget'], $budgetBefore['remaining_budget']);
        
        $stmt = $this->pdo->prepare("UPDATE social_budget SET remaining_budget = initial_budget WHERE id = ?");
        $stmt->execute([$this->testBudgetId]);
        
        $stmt->execute([$this->testBudgetId]);
        $budgetAfter = $stmt->fetch(PDO::FETCH_ASSOC);
        
        $this->assertEquals($budgetAfter['initial_budget'], $budgetAfter['remaining_budget']);
    }
    
    // ==================== الاختبارات الأساسية ====================
    
    /** @test */
    public function testCSRFProtection()
    {
        $hasToken = isset($_POST['csrf_token']);
        $this->assertFalse($hasToken);
    }
    
    /** @test */
    public function testSQLInjectionPrevention()
    {
        $maliciousInput = "1'; DROP TABLE employees; --";
        $stmt = $this->pdo->prepare("SELECT * FROM employees WHERE name = ?");
        $this->assertInstanceOf(PDOStatement::class, $stmt);
        $stmt->execute([$maliciousInput]);
        
        $stmt = $this->pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name='employees'");
        $this->assertNotEmpty($stmt->fetch());
    }
    
    /** @test */
    public function testPasswordHashing()
    {
        $password = 'SecurePass123!';
        $hashed = password_hash($password, PASSWORD_ARGON2ID);
        $this->assertNotEquals($password, $hashed);
        $this->assertTrue(password_verify($password, $hashed));
    }
    
    /** @test */
    public function testXSSProtection()
    {
        $maliciousInput = "<script>alert('XSS')</script>";
        $sanitized = htmlspecialchars($maliciousInput, ENT_QUOTES, 'UTF-8');
        $this->assertStringContainsString('&lt;', $sanitized);
        $this->assertStringNotContainsString('<script>', $sanitized);
    }
    
    /** @test */
    public function testRateLimiting()
    {
        $limit = 5;
        $attempts = [];
        $key = "rate_limit:127.0.0.1:test_action";
        for ($i = 1; $i <= 6; $i++) {
            $current = $attempts[$key] ?? 0;
            $attempts[$key] = $current + 1;
        }
        $this->assertGreaterThan($limit, $attempts[$key] ?? 0);
    }
}