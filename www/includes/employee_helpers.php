<?php
function getEmployeesList($pdo, $search = '') {
    $sql = "SELECT * FROM employees WHERE 1=1";
    $params = [];
    if ($search) {
        $sql .= " AND name LIKE ?";
        $params[] = "%$search%";
    }
    $sql .= " ORDER BY name ASC";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}
// دوال أخرى (إضافة، تعديل، حذف) يمكن إضافتها حسب الحاجة