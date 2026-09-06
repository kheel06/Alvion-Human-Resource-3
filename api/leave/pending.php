<?php
/**
 * GET - Admin list of pending/endorsed leave requests. ?department_id=&from=&to=&type=
 */
header('Content-Type: application/json');
require_once __DIR__ . '/../../config/config.php';
if (session_status() === PHP_SESSION_NONE) session_start();

$role = $_SESSION['role_name'] ?? $_SESSION['user_role'] ?? 'employee';
$normalized = strtolower(trim($role));
if (!in_array($normalized, ['admin', 'super admin', 'staff'], true)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Forbidden']);
    exit;
}

$unitId = isset($_GET['department_id']) ? (int) $_GET['department_id'] : (isset($_GET['unit_id']) ? (int) $_GET['unit_id'] : null);
$from = $_GET['from'] ?? date('Y-m-d');
$to = $_GET['to'] ?? date('Y-m-d', strtotime('+1 year'));
$type = $_GET['type'] ?? '';

if (!isset($db)) {
    echo json_encode(['success' => true, 'data' => [], 'total' => 0]);
    exit;
}

$sql = "SELECT lr.id, lr.employee_id, lr.leave_type, lr.start_date, lr.end_date, lr.total_days, lr.reason, lr.status, lr.submitted_at,
               e.first_name, e.last_name, e.employee_number, e.unit_id,
               u.name as unit_name
        FROM leave_requests lr
        JOIN employees e ON lr.employee_id = e.id
        LEFT JOIN units u ON e.unit_id = u.id
        WHERE lr.status IN ('pending','endorsed') AND lr.start_date BETWEEN :from_date AND :to_date";
$params = [':from_date' => $from, ':to_date' => $to];
if ($unitId) {
    $sql .= " AND e.unit_id = :unit_id";
    $params[':unit_id'] = $unitId;
}
if ($type !== '') {
    $sql .= " AND lr.leave_type = :lt";
    $params[':lt'] = $type;
}
$sql .= " ORDER BY lr.submitted_at ASC";
$stmt = $db->prepare($sql);
$stmt->execute($params);
$data = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Balance after (available - pending for this request)
foreach ($data as &$r) {
    $st = $db->prepare("SELECT entitlement, used, pending FROM leave_balances WHERE employee_id = ? AND leave_type = ? AND year = YEAR(CURDATE())");
    $st->execute([$r['employee_id'], $r['leave_type']]);
    $b = $st->fetch(PDO::FETCH_ASSOC);
    $r['balance_after'] = $b ? ($b['entitlement'] - $b['used'] - $b['pending']) : 0;
}
echo json_encode(['success' => true, 'data' => $data, 'total' => count($data)]);
