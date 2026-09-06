<?php
header('Content-Type: application/json');
require_once __DIR__ . '/../../config/config.php';
if (session_status() === PHP_SESSION_NONE) session_start();

$employeeId = $_SESSION['employee_id'] ?? $_SESSION['user_id'] ?? null;
if (!$employeeId) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Session expired.']);
    exit;
}

$year = isset($_GET['year']) ? (int) $_GET['year'] : null;
$type = $_GET['type'] ?? '';
$status = $_GET['status'] ?? '';

if (!isset($db)) {
    echo json_encode(['success' => true, 'requests' => []]);
    exit;
}

$sql = "SELECT id, leave_type, start_date, end_date, total_days, reason, status, submitted_at, attachment_path
        FROM leave_requests WHERE employee_id = :emp_id";
$params = [':emp_id' => $employeeId];
if ($year) {
    $sql .= " AND (YEAR(start_date) = :yr OR YEAR(end_date) = :yr)";
    $params[':yr'] = $year;
}
if ($type !== '') {
    $sql .= " AND leave_type = :lt";
    $params[':lt'] = $type;
}
if ($status !== '') {
    $sql .= " AND status = :status";
    $params[':status'] = $status;
}
$sql .= " ORDER BY submitted_at DESC";
$stmt = $db->prepare($sql);
$stmt->execute($params);
$list = $stmt->fetchAll(PDO::FETCH_ASSOC);
foreach ($list as &$r) {
    $r['id'] = (int) $r['id'];
    $r['total_days'] = (float) $r['total_days'];
}
echo json_encode(['success' => true, 'requests' => $list]);
