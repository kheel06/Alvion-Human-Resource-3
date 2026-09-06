<?php
header('Content-Type: application/json');
require_once __DIR__ . '/../../config/config.php';
if (session_status() === PHP_SESSION_NONE) session_start();

$employeeId = $_SESSION['employee_id'] ?? $_SESSION['user_id'] ?? null;
if (!$employeeId) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

if (!isset($db)) {
    echo json_encode(['success' => true, 'claims' => []]);
    exit;
}

$stmt = $db->prepare("
    SELECT c.id, c.category_id, c.amount, c.currency, c.description, c.status, 
           c.submitted_at, c.approved_at, c.paid_at, c.payment_reference,
           cc.name as category_name, cc.code as category_code
    FROM claims c
    LEFT JOIN claim_categories cc ON c.category_id = cc.id
    WHERE c.employee_id = :emp_id 
    ORDER BY COALESCE(c.submitted_at, c.created_at) DESC
");
$stmt->execute([':emp_id' => $employeeId]);
$list = $stmt->fetchAll(PDO::FETCH_ASSOC);
foreach ($list as &$c) {
    $c['id'] = (int) $c['id'];
    $c['amount'] = (float) $c['amount'];
}
echo json_encode(['success' => true, 'claims' => $list]);
