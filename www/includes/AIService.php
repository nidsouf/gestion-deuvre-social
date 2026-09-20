<?php
/**
 * includes/AIService.php
 * خدمة الذكاء الاصطناعي – متوافقة مع PDO و budget_helpers.php
 */
require_once __DIR__ . '/../config/ai_config.php';
require_once __DIR__ . '/budget_helpers.php';

class AIService {
    private $pdo;
    private $apiKey;
    private $model;
    private $timeout;
    
    public function __construct($pdo) {
        $this->pdo = $pdo;
        $this->apiKey = defined('AI_API_KEY') ? AI_API_KEY : '';
        $this->model = defined('AI_MODEL') ? AI_MODEL : 'gemini-pro';
        $this->timeout = defined('AI_TIMEOUT') ? AI_TIMEOUT : 30;
        
        if (empty($this->apiKey)) {
            throw new Exception('❌ مفتاح API غير موجود في config/ai_config.php');
        }
    }
    
    /**
     * تحليل الميزانية لسنة معينة
     */
    public function analyzeBudget($year, $forceRefresh = false) {
        // 1. التحقق من التخزين المؤقت
        if (!$forceRefresh) {
            $cached = $this->getCachedReport('budget', $year);
            if ($cached) {
                return $cached;
            }
        }
        
        // 2. تجهيز البيانات باستخدام الدوال الحالية
        $data = $this->prepareBudgetData($year);
        
        // 3. بناء النص المُرسل للذكاء الاصطناعي
        $prompt = $this->buildPrompt($data);
        
        // 4. استدعاء Gemini
        $result = $this->callGemini($prompt);
        
        // 5. حفظ التقرير
        $this->saveReport($year, 'budget', $result, $data);
        
        return $result;
    }
    
    /**
     * تجهيز بيانات الميزانية باستخدام الدوال الموجودة
     */
    private function prepareBudgetData($year) {
        // استخدام getBudgetStats من budget_helpers
        $stats = getBudgetStats($this->pdo, $year);
        
        // بيانات السلف
        $stmt = $this->pdo->prepare("
            SELECT 
                COUNT(*) as total_loans,
                COALESCE(AVG(monthly_amount * total_months), 0) as avg_loan_amount,
                COALESCE(SUM(CASE WHEN end_date >= date('now') THEN 1 ELSE 0 END), 0) as active_loans,
                COALESCE(SUM(CASE WHEN end_date < date('now') THEN 1 ELSE 0 END), 0) as completed_loans
            FROM deductions 
            WHERE is_loan = 1 AND strftime('%Y', created_at) = ?
        ");
        $stmt->execute([$year]);
        $loanStats = $stmt->fetch(PDO::FETCH_ASSOC);
        
        // بيانات المنح
        $stmt = $this->pdo->prepare("
            SELECT 
                COUNT(*) as total_grants,
                COALESCE(AVG(amount), 0) as avg_grant_amount,
                COALESCE(SUM(amount), 0) as total_grant_amount
            FROM employee_grants 
            WHERE strftime('%Y', grant_date) = ?
        ");
        $stmt->execute([$year]);
        $grantStats = $stmt->fetch(PDO::FETCH_ASSOC);
        
        // توزيع المنح حسب النوع
        $stmt = $this->pdo->prepare("
            SELECT g.name, COUNT(eg.id) as count, COALESCE(SUM(eg.amount), 0) as total
            FROM employee_grants eg
            JOIN grants g ON eg.grant_id = g.id
            WHERE strftime('%Y', eg.grant_date) = ?
            GROUP BY g.id
            ORDER BY total DESC
        ");
        $stmt->execute([$year]);
        $grantTypes = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // البيانات الشهرية
        $monthlyData = [];
        for ($month = 1; $month <= 12; $month++) {
            $monthStr = sprintf('%02d', $month);
            $stmt = $this->pdo->prepare("
                SELECT 
                    COALESCE(SUM(CASE WHEN is_deduct = 1 THEN amount ELSE 0 END), 0) as expenses,
                    COALESCE(SUM(CASE WHEN is_deduct = 0 THEN amount ELSE 0 END), 0) as refunds
                FROM budget_transactions
                WHERE strftime('%Y', transaction_date) = ? 
                  AND strftime('%m', transaction_date) = ?
            ");
            $stmt->execute([$year, $monthStr]);
            $monthStats = $stmt->fetch(PDO::FETCH_ASSOC);
            
            $monthlyData[$month] = [
                'expenses' => (float)$monthStats['expenses'],
                'refunds' => (float)$monthStats['refunds']
            ];
        }
        
        return [
            'year' => $year,
            'budget' => $stats,
            'loans' => [
                'total' => (int)($loanStats['total_loans'] ?? 0),
                'average' => (float)($loanStats['avg_loan_amount'] ?? 0),
                'active' => (int)($loanStats['active_loans'] ?? 0),
                'completed' => (int)($loanStats['completed_loans'] ?? 0)
            ],
            'grants' => [
                'total' => (int)($grantStats['total_grants'] ?? 0),
                'average' => (float)($grantStats['avg_grant_amount'] ?? 0),
                'total_amount' => (float)($grantStats['total_grant_amount'] ?? 0),
                'by_type' => $grantTypes
            ],
            'monthly' => $monthlyData
        ];
    }
    
    /**
     * بناء النص المُرسل للذكاء الاصطناعي
     */
    private function buildPrompt($data) {
        $year = $data['year'];
        $b = $data['budget'];
        $l = $data['loans'];
        $g = $data['grants'];
        
        $monthNames = ['جانفي','فيفري','مارس','أفريل','ماي','جوان','جويلية','أوت','سبتمبر','أكتوبر','نوفمبر','ديسمبر'];
        $monthlyText = '';
        foreach ($data['monthly'] as $m => $monthData) {
            if ($monthData['expenses'] == 0 && $monthData['refunds'] == 0) continue;
            $monthlyText .= "- {$monthNames[$m-1]}: صرف " . number_format($monthData['expenses'], 0) . " دج، استرجاع " . number_format($monthData['refunds'], 0) . " دج\n";
        }
        
        $grantTypesText = '';
        foreach ($g['by_type'] as $type) {
            $grantTypesText .= "- {$type['name']}: {$type['count']} منحة (إجمالي " . number_format($type['total'], 0) . " دج)\n";
        }
        
        return "
أنت خبير مالي متخصص في تحليل ميزانيات المؤسسات الاجتماعية.
قم بتحليل البيانات التالية لميزانية سنة $year.

الميزانية:
- الميزانية الأولية: " . number_format($b['initial'], 0) . " دج
- الميزانية المتبقية: " . number_format($b['remaining'], 0) . " دج
- إجمالي الصرف: " . number_format($b['total_expenses'], 0) . " دج
- إجمالي الاسترجاعات: " . number_format($b['total_refunds'], 0) . " دج
- نسبة الاستهلاك: {$b['spent_percent']}%

السلف:
- عدد السلف: {$l['total']}
- متوسط قيمة السلفة: " . number_format($l['average'], 0) . " دج
- السلف النشطة: {$l['active']}
- السلف المنتهية: {$l['completed']}

المنح:
- عدد المنح: {$g['total']}
- متوسط قيمة المنحة: " . number_format($g['average'], 0) . " دج
- إجمالي المنح: " . number_format($g['total_amount'], 0) . " دج
- توزيع المنح حسب النوع:
$grantTypesText

التفاصيل الشهرية:
$monthlyText

المطلوب:
قدّم تحليلًا شاملاً ومفيدًا للجنة الخدمات الاجتماعية.

يجب أن يكون الرد بصيغة JSON بالهيكل التالي:
{
    \"summary\": \"ملخص تنفيذي في جملة واحدة\",
    \"analysis\": \"تحليل سردي مفصل (3-4 فقرات)\",
    \"recommendations\": [\"توصية 1\", \"توصية 2\", \"توصية 3\"],
    \"alerts\": [\"تنبيه 1\", \"تنبيه 2\"]
}

استخدم لغة عربية رسمية وواضحة.";
    }
    
    /**
     * استدعاء Gemini API
     */
    private function callGemini($prompt) {
    $url = "https://generativelanguage.googleapis.com/v1beta/models/" . $this->model . ":generateContent?key=" . $this->apiKey;      
    $payload = [
            'contents' => [
                ['parts' => [['text' => $prompt]]]
            ],
            'generationConfig' => [
                'temperature' => 0.3,
                'maxOutputTokens' => 512,
                'topP' => 0.8
            ]
        ];
        
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_CAINFO, __DIR__ . '/../cacert.pem');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
        curl_setopt($ch, CURLOPT_TIMEOUT, $this->timeout);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);
        
        if ($curlError) {
            throw new Exception('خطأ في الاتصال: ' . $curlError);
        }
        
        if ($httpCode !== 200) {
            throw new Exception("فشل استدعاء Gemini API (HTTP $httpCode): " . substr($response, 0, 200));
        }
        
        $result = json_decode($response, true);
        $text = $result['candidates'][0]['content']['parts'][0]['text'] ?? '{}';
        
        // استخراج JSON من النص
        if (preg_match('/\{[\s\S]*\}/', $text, $matches)) {
            $text = $matches[0];
        }
        
        $parsed = json_decode($text, true);
        
        if (!is_array($parsed)) {
            throw new Exception('النموذج لم يعد JSON صحيحاً');
        }
        
        return array_merge([
            'summary' => 'تم التحليل بنجاح.',
            'analysis' => $text,
            'recommendations' => [],
            'alerts' => []
        ], $parsed);
    }
    
    /**
     * حفظ التقرير في قاعدة البيانات
     */
    private function saveReport($year, $type, $result, $data) {
        $userId = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : 1;
        
        $stmt = $this->pdo->prepare("
            INSERT INTO ai_reports (
                user_id, report_type, report_year, 
                summary, analysis, recommendations, alerts, 
                raw_stats, model_name, created_at
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, datetime('now'))
        ");
        
        $stmt->execute([
            $userId,
            $type,
            $year,
            $result['summary'] ?? '',
            $result['analysis'] ?? '',
            json_encode($result['recommendations'] ?? [], JSON_UNESCAPED_UNICODE),
            json_encode($result['alerts'] ?? [], JSON_UNESCAPED_UNICODE),
            json_encode($data, JSON_UNESCAPED_UNICODE),
            $this->model
        ]);
        
        return $this->pdo->lastInsertId();
    }
    
    /**
     * الحصول على تقرير مخزّن مسبقاً
     */
    private function getCachedReport($type, $year) {
        $duration = defined('AI_CACHE_DURATION') ? AI_CACHE_DURATION : 86400;
        
        $stmt = $this->pdo->prepare("
            SELECT * FROM ai_reports 
            WHERE report_type = ? AND report_year = ? 
            AND created_at > datetime('now', '-' || ? || ' seconds')
            ORDER BY created_at DESC 
            LIMIT 1
        ");
        $stmt->execute([$type, $year, $duration]);
        $report = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$report) {
            return null;
        }
        
        return [
            'summary' => $report['summary'],
            'analysis' => $report['analysis'],
            'recommendations' => json_decode($report['recommendations'] ?? '[]', true),
            'alerts' => json_decode($report['alerts'] ?? '[]', true),
            'cached' => true,
            'cached_at' => $report['created_at']
        ];
    }
    
    /**
     * الحصول على تقرير محفوظ
     */
    public function getReport($id) {
        $stmt = $this->pdo->prepare("SELECT * FROM ai_reports WHERE id = ?");
        $stmt->execute([$id]);
        $report = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$report) {
            return null;
        }
        
        return [
            'id' => $report['id'],
            'type' => $report['report_type'],
            'year' => $report['report_year'],
            'summary' => $report['summary'],
            'analysis' => $report['analysis'],
            'recommendations' => json_decode($report['recommendations'] ?? '[]', true),
            'alerts' => json_decode($report['alerts'] ?? '[]', true),
            'model' => $report['model_name'],
            'created_at' => $report['created_at']
        ];
    }
    
    /**
     * الحصول على قائمة التحليلات
     */
    public function getReports($filters = []) {
        $where = [];
        $params = [];
        
        if (!empty($filters['type'])) {
            $where[] = "report_type = ?";
            $params[] = $filters['type'];
        }
        if (!empty($filters['year'])) {
            $where[] = "report_year = ?";
            $params[] = (int)$filters['year'];
        }
        if (!empty($filters['user_id'])) {
            $where[] = "user_id = ?";
            $params[] = (int)$filters['user_id'];
        }
        
        $whereClause = $where ? 'WHERE ' . implode(' AND ', $where) : '';
        $limit = isset($filters['limit']) ? (int)$filters['limit'] : 50;
        
        $sql = "SELECT * FROM ai_reports $whereClause ORDER BY created_at DESC LIMIT ?";
        $params[] = $limit;
        
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}