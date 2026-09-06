<?php
/**
 * HR3 Attendance Exceptions API (Admin)
 * GET ?from=&to=&type=&status=&department_id=
 */
header('Content-Type: application/json');
require_once __DIR__ . '/../../config/config.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$role = $_SESSION['role_name'] ?? $_SESSION['user_role'] ?? 'employee';
$normalized = strtolower(trim($role));
if (!in_array($normalized, ['admin', 'super admin', 'staff'], true)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Forbidden']);
    exit;
}

if (!isset($db) || $db->query("SHOW TABLES LIKE 'attendance_exceptions'")->rowCount() === 0) {
    echo json_encode(['success' => true, 'data' => [], 'total' => 0]);
    exit;
}

$from = $_GET['from'] ?? date('Y-m-d', strtotime('-30 days'));
$to = $_GET['to'] ?? date('Y-m-d');
$type = $_GET['type'] ?? '';
$status = $_GET['status'] ?? '';
$unitId = isset($_GET['department_id']) ? (int) $_GET['department_id'] : (isset($_GET['unit_id']) ? (int) $_GET['unit_id'] : null);

$sql = "SELECT ae.*, e.first_name, e.last_name, e.employee_number, e.unit_id
        FROM attendance_exceptions ae
        JOIN employees e ON ae.employee_id = e.id
        WHERE ae.log_date BETWEEN :from_date AND :to_date";
$params = [':from_date' => $from, ':to_date' => $to];

if ($type !== '') {
    $sql .= " AND ae.exception_type = :etype";
    $params[':etype'] = $type;
}
if ($status !== '') {
    $sql .= " AND ae.status = :status";
    $params[':status'] = $status;
}
if ($unitId) {
    $sql .= " AND e.unit_id = :unit_id";
    $params[':unit_id'] = $unitId;
}

$sql .= " ORDER BY ae.log_date DESC, ae.requested_at DESC";
$stmt = $db->prepare($sql);
$stmt->execute($params);
$data = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo json_encode(['success' => true, 'data' => $data, 'total' => count($data)]);
exit;
