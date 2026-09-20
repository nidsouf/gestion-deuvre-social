<?php
/**
 * requests_helpers.php - دوال مساعدة لوحدة طلبات الموظفين
 */

// ============================================================
// دوال الإحصائيات والقوائم
// ============================================================

function getRequestsStats($pdo) {
    $stats = [];
    $types = ['pending', 'reviewing', 'approved', 'rejected', 'cancelled'];
    foreach ($types as $type) {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM requests WHERE status = ?");
        $stmt->execute([$type]);
        $stats[$type] = $stmt->fetchColumn();
    }
    $stats['total'] = array_sum($stats);
    return $stats;
}

function getRequestsList($pdo, $filters = [], $limit = 50, $offset = 0) {
    $where = [];
    $params = [];

    if (!empty($filters['status']) && $filters['status'] !== 'all') {
        $where[] = "r.status = ?";
        $params[] = $filters['status'];
    }
    if (!empty($filters['type']) && $filters['type'] !== 'all') {
        $where[] = "r.request_type = ?";
        $params[] = $filters['type'];
    }
    if (!empty($filters['employee_id'])) {
        $where[] = "r.employee_id = ?";
        $params[] = $filters['employee_id'];
    }
    if (!empty($filters['search'])) {
        $where[] = "(e.name LIKE ? OR r.title LIKE ?)";
        $search = '%' . $filters['search'] . '%';
        $params[] = $search;
        $params[] = $search;
    }
    if (!empty($filters['source']) && $filters['source'] !== 'all') {
        $where[] = "r.source = ?";
        $params[] = $filters['source'];
    }
    if (!empty($filters['from_date'])) {
        $where[] = "r.requested_date >= ?";
        $params[] = $filters['from_date'];
    }
    if (!empty($filters['to_date'])) {
        $where[] = "r.requested_date <= ?";
        $params[] = $filters['to_date'];
    }

    $whereClause = $where ? 'WHERE ' . implode(' AND ', $where) : '';

    $sql = "
        SELECT 
            r.*,
            e.name as employee_name,
            e.account_number,
            e.category,
            g.name as grant_name,
            s.name as source_name,
            u1.username as reviewed_by_name,
            u2.username as approved_by_name
        FROM requests r
        LEFT JOIN employees e ON r.employee_id = e.id
        LEFT JOIN grants g ON r.grant_id = g.id
        LEFT JOIN sources s ON r.source_id = s.id
        LEFT JOIN users u1 ON r.reviewed_by = u1.id
        LEFT JOIN users u2 ON r.approved_by = u2.id
        $whereClause
        ORDER BY r.created_at DESC
        LIMIT ? OFFSET ?
    ";
    $params[] = $limit;
    $params[] = $offset;

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function countRequests($pdo, $filters = []) {
    $where = [];
    $params = [];

    if (!empty($filters['status']) && $filters['status'] !== 'all') {
        $where[] = "r.status = ?";
        $params[] = $filters['status'];
    }
    if (!empty($filters['type']) && $filters['type'] !== 'all') {
        $where[] = "r.request_type = ?";
        $params[] = $filters['type'];
    }
    if (!empty($filters['employee_id'])) {
        $where[] = "r.employee_id = ?";
        $params[] = $filters['employee_id'];
    }
    if (!empty($filters['search'])) {
        $where[] = "(e.name LIKE ? OR r.title LIKE ?)";
        $search = '%' . $filters['search'] . '%';
        $params[] = $search;
        $params[] = $search;
    }
    if (!empty($filters['source']) && $filters['source'] !== 'all') {
        $where[] = "r.source = ?";
        $params[] = $filters['source'];
    }

    $whereClause = $where ? 'WHERE ' . implode(' AND ', $where) : '';

    $sql = "SELECT COUNT(*) FROM requests r LEFT JOIN employees e ON r.employee_id = e.id $whereClause";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchColumn();
}

// ============================================================
// دوال التفاصيل والتعليقات
// ============================================================

function getRequestDetails($pdo, $requestId) {
    $stmt = $pdo->prepare("
        SELECT 
            r.*,
            e.name as employee_name,
            e.account_number,
            e.category,
            g.name as grant_name,
            s.name as source_name,
            u1.username as reviewed_by_name,
            u2.username as approved_by_name
        FROM requests r
        LEFT JOIN employees e ON r.employee_id = e.id
        LEFT JOIN grants g ON r.grant_id = g.id
        LEFT JOIN sources s ON r.source_id = s.id
        LEFT JOIN users u1 ON r.reviewed_by = u1.id
        LEFT JOIN users u2 ON r.approved_by = u2.id
        WHERE r.id = ?
    ");
    $stmt->execute([$requestId]);
    return $stmt->fetch(PDO::FETCH_ASSOC);
}

function getRequestComments($pdo, $requestId) {
    $stmt = $pdo->prepare("
        SELECT c.*, u.username as user_name
        FROM request_comments c
        LEFT JOIN users u ON c.user_id = u.id
        WHERE c.request_id = ?
        ORDER BY c.created_at ASC
    ");
    $stmt->execute([$requestId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// ============================================================
// دوال إدارة الحالة
// ============================================================

function updateRequestStatus($pdo, $requestId, $status, $userId, $comment = null) {
    try {
        $pdo->beginTransaction();
        $stmt = $pdo->prepare("UPDATE requests SET status = ?, updated_at = datetime('now') WHERE id = ?");
        $stmt->execute([$status, $requestId]);
        if ($comment) {
            $stmt = $pdo->prepare("INSERT INTO request_comments (request_id, user_id, comment) VALUES (?, ?, ?)");
            $stmt->execute([$requestId, $userId, $comment]);
        }
        $pdo->commit();
        return true;
    } catch (Exception $e) {
        $pdo->rollBack();
        error_log("updateRequestStatus error: " . $e->getMessage());
        return false;
    }
}

function approveRequest($pdo, $requestId, $userId, $approvedAmount, $approvedMonths, $committeeDecision, $comment = null, $grantId = null, $sourceId = null) {
    try {
        $pdo->beginTransaction();
        $sql = "UPDATE requests SET status = 'approved', approved_amount = ?, approved_months = ?, committee_decision = ?, approved_by = ?, approved_at = datetime('now'), updated_at = datetime('now')";
        $params = [$approvedAmount, $approvedMonths, $committeeDecision, $userId];
        if ($grantId !== null) {
            $sql .= ", grant_id = ?";
            $params[] = $grantId;
        }
        if ($sourceId !== null) {
            $sql .= ", source_id = ?";
            $params[] = $sourceId;
        }
        $sql .= " WHERE id = ?";
        $params[] = $requestId;

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);

        if ($comment) {
            $stmt = $pdo->prepare("INSERT INTO request_comments (request_id, user_id, comment) VALUES (?, ?, ?)");
            $stmt->execute([$requestId, $userId, $comment]);
        }
        $pdo->commit();
        return true;
    } catch (Exception $e) {
        $pdo->rollBack();
        error_log("approveRequest error: " . $e->getMessage());
        return false;
    }
}

function rejectRequest($pdo, $requestId, $userId, $reason, $comment = null) {
    try {
        $pdo->beginTransaction();
        $stmt = $pdo->prepare("UPDATE requests SET status = 'rejected', rejection_reason = ?, reviewed_by = ?, reviewed_at = datetime('now'), updated_at = datetime('now') WHERE id = ?");
        $stmt->execute([$reason, $userId, $requestId]);
        if ($comment) {
            $stmt = $pdo->prepare("INSERT INTO request_comments (request_id, user_id, comment) VALUES (?, ?, ?)");
            $stmt->execute([$requestId, $userId, $comment]);
        }
        $pdo->commit();
        return true;
    } catch (Exception $e) {
        $pdo->rollBack();
        error_log("rejectRequest error: " . $e->getMessage());
        return false;
    }
}

function cancelRequest($pdo, $requestId, $userId, $reason = null) {
    try {
        $pdo->beginTransaction();
        $stmt = $pdo->prepare("UPDATE requests SET status = 'cancelled', updated_at = datetime('now') WHERE id = ? AND status = 'pending'");
        $stmt->execute([$requestId]);
        if ($stmt->rowCount() === 0) {
            throw new Exception('الطلب غير موجود أو غير قابل للإلغاء');
        }
        if ($reason) {
            $stmt = $pdo->prepare("INSERT INTO request_comments (request_id, user_id, comment) VALUES (?, ?, ?)");
            $stmt->execute([$requestId, $userId, "تم إلغاء الطلب: $reason"]);
        }
        $pdo->commit();
        return true;
    } catch (Exception $e) {
        $pdo->rollBack();
        error_log("cancelRequest error: " . $e->getMessage());
        return false;
    }
}

// ============================================================
// دوال التنفيذ (تستخدم الدوال المركزية في functions.php)
// ============================================================

// ============================================================
// تنفيذ الطلب (مستقل - لا يعتمد على دوال خارجية)
// ============================================================
// ============================================================
// تنفيذ الطلب (متوافق مع هيكل الجداول الفعلي)
// ============================================================
function executeRequest($pdo, $requestId, $userId) {
    $request = getRequestDetails($pdo, $requestId);
    if (!$request || $request['status'] !== 'approved' || $request['executed'] == 1) {
        return ['success' => false, 'message' => 'الطلب غير قابل للتنفيذ'];
    }

    try {
        $pdo->beginTransaction();

        $referenceId  = null;
        $requestType  = $request['request_type'];
        $employeeId   = (int)$request['employee_id'];
        $amount       = (float)$request['approved_amount'];
        $months       = (int)($request['approved_months'] ?: 1);
        $grantId      = (int)($request['grant_id'] ?? 0);
        $sourceId     = (int)($request['source_id'] ?? 1);
        $today        = date('Y-m-d');

        // ============================================================
        // 1. السلفة أو الاقتطاع
        // ============================================================
        if ($requestType === 'loan' || $requestType === 'deduction') {
            if ($amount <= 0) throw new Exception('المبلغ يجب أن يكون موجباً');
            if ($months <= 0) $months = 1;

            $monthlyAmount = round($amount / $months, 2);
            $endDate = date('Y-m-d', strtotime("+$months months -1 day", strtotime($today)));
            $isLoan = ($requestType === 'loan') ? 1 : 0;

            // أ. إدراج الاقتطاع/السلفة في جدول deductions
            $stmt = $pdo->prepare("
                INSERT INTO deductions 
                    (employee_id, source_id, monthly_amount, total_months, 
                     start_date, end_date, is_loan, created_at, 
                     paid_months, remaining_months, credit_balance, notes)
                VALUES (?, ?, ?, ?, ?, ?, ?, datetime('now'), 0, ?, ?, ?)
            ");
            $notes = "من الطلب رقم {$requestId}";
            $stmt->execute([
                $employeeId, $sourceId, $monthlyAmount, $months,
                $today, $endDate, $isLoan,
                $months, $amount, $notes
            ]);
            $referenceId = $pdo->lastInsertId();

            // ب. إنشاء الأقساط الشهرية في monthly_installments
            $installmentStmt = $pdo->prepare("
                INSERT INTO monthly_installments 
                    (deduction_id, employee_id, source_id, year, month, amount, is_paid, created_at)
                VALUES (?, ?, ?, ?, ?, ?, 0, datetime('now'))
            ");
            for ($i = 0; $i < $months; $i++) {
                $timestamp = strtotime("+$i months", strtotime($today));
                $y = (int)date('Y', $timestamp);
                $m = (int)date('n', $timestamp);
                $installmentStmt->execute([
                    $referenceId, $employeeId, $sourceId, $y, $m, $monthlyAmount
                ]);
            }

            // ج. تسجيل في budget_transactions
            // (type='loan' للسلف، type='installment' للاقتطاع)
            $btType = ($requestType === 'loan') ? 'loan' : 'installment';
            $desc = ($requestType === 'loan' ? 'سلفة جديدة' : 'اقتطاع جديد') 
                  . " - الطلب رقم {$requestId}";
            $stmt = $pdo->prepare("
                INSERT INTO budget_transactions 
                    (type, reference_id, amount, is_deduct, description, transaction_date)
                VALUES (?, ?, ?, 1, ?, datetime('now'))
            ");
            $stmt->execute([$btType, $referenceId, $amount, $desc]);

            // د. خصم من الميزانية
            $stmt = $pdo->prepare("
                UPDATE social_budget 
                SET remaining_budget = remaining_budget - ?, 
                    last_updated = datetime('now')
                WHERE id = 1
            ");
            $stmt->execute([$amount]);
        }

        // ============================================================
        // 2. المنحة
        // ============================================================
        elseif ($requestType === 'grant') {
            if ($amount <= 0) throw new Exception('المبلغ يجب أن يكون موجباً');
            if ($grantId <= 0) throw new Exception('نوع المنحة غير محدد');

            // أ. إدراج المنحة في employee_grants
            $stmt = $pdo->prepare("
                INSERT INTO employee_grants 
                    (employee_id, grant_id, grant_date, amount, notes, created_at)
                VALUES (?, ?, ?, ?, ?, datetime('now'))
            ");
            $notes = "من الطلب رقم {$requestId}";
            $stmt->execute([$employeeId, $grantId, $today, $amount, $notes]);
            $referenceId = $pdo->lastInsertId();

            // ب. تسجيل في budget_transactions
            $desc = "منحة جديدة - الطلب رقم {$requestId}";
            $stmt = $pdo->prepare("
                INSERT INTO budget_transactions 
                    (type, reference_id, amount, is_deduct, description, transaction_date)
                VALUES ('grant', ?, ?, 1, ?, datetime('now'))
            ");
            $stmt->execute([$referenceId, $amount, $desc]);

            // ج. خصم من الميزانية
            $stmt = $pdo->prepare("
                UPDATE social_budget 
                SET remaining_budget = remaining_budget - ?, 
                    last_updated = datetime('now')
                WHERE id = 1
            ");
            $stmt->execute([$amount]);
        }
        else {
            throw new Exception('نوع الطلب غير معروف: ' . $requestType);
        }

        // ============================================================
        // 3. تحديث الطلب بأنه منفذ
        // ============================================================
        if ($referenceId) {
            $stmt = $pdo->prepare("
                UPDATE requests 
                SET executed = 1, reference_id = ?, updated_at = datetime('now') 
                WHERE id = ?
            ");
            $stmt->execute([$referenceId, $requestId]);

            $stmt = $pdo->prepare("
                INSERT INTO request_comments (request_id, user_id, comment) 
                VALUES (?, ?, ?)
            ");
            $stmt->execute([$requestId, $userId, "✅ تم تنفيذ الطلب (المرجع: $referenceId)"]);

            if (function_exists('auditLog')) {
                auditLog($pdo, 'REQUEST_EXECUTED', "تنفيذ الطلب رقم $requestId (مرجع: $referenceId)");
            }

            $pdo->commit();
            return ['success' => true, 'message' => 'تم تنفيذ الطلب بنجاح', 'reference_id' => $referenceId];
        } else {
            throw new Exception('فشل إنشاء السجل المرجعي');
        }

    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log("❌ executeRequest error [requestId=$requestId]: " . $e->getMessage());
        return ['success' => false, 'message' => $e->getMessage()];
    }
}