<?php
/**
 * GET ?period_start=&period_end=&unit_id=&status= - Admin list of timesheets for review.
 */
header('Content-Type: application/json');
require_once __DIR__ . '/../../config/config.php';
if (session_status() === PHP_SESSION_NONE) session_start();

$role = $_SESSION['role_name'] ?? $_SESSION['user_role'] ?? 'employee';
$normalized = strtolower(trim($role));
if (!in_array($normalized, ['admin', 'super admin', 'staff', 'hr_admin', 'supervisor', 'unit_head'], true)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Forbidden']);
    exit;
}

$periodStart = $_GET['period_start'] ?? date('Y-m-01');
$periodEnd = $_GET['period_end'] ?? date('Y-m-t');
$unitId = isset($_GET['unit_id']) ? (int) $_GET['unit_id'] : null;
$status = $_GET['status'] ?? '';

if (!isset($db)) {
    echo json_encode(['success' => true, 'data' => [], 'total' => 0]);
    exit;
}

$sql = "SELECT t.id, t.employee_id, t.period_start, t.period_end, t.total_hours, t.total_ot_hours, t.total_nd_hours, t.status, t.updated_at,
               e.first_name, e.last_name, e.employee_number, e.unit_id
        FROM timesheets t
        JOIN employees e ON t.employee_id = e.id
        WHERE t.period_start >= :ps AND t.period_end <= :pe";
$params = [':ps' => $periodStart, ':pe' => $periodEnd];
if ($unitId) {
    $sql .= " AND e.unit_id = :unit_id";
    $params[':unit_id'] = $unitId;
}
if ($status !== '') {
    $sql .= " AND t.status = :status";
    $params[':status'] = $status;
}
$sql .= " ORDER BY t.updated_at DESC";
$stmt = $db->prepare($sql);
$stmt->execute($params);
$data = $stmt->fetchAll(PDO::FETCH_ASSOC);
echo json_encode(['success' => true, 'data' => $data, 'total' => count($data)]);
