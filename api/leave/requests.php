<?php
/**
 * POST: create leave request. Validates balance and overlap.
 */
header('Content-Type: application/json');
require_once __DIR__ . '/../../config/config.php';
if (session_status() === PHP_SESSION_NONE) session_start();

$employeeId = $_SESSION['employee_id'] ?? $_SESSION['user_id'] ?? null;
if (!$employeeId) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Session expired. Please log in again.']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true) ?: [];
$leaveType = trim($input['leave_type'] ?? '');
$startDate = $input['start_date'] ?? '';
$endDate = $input['end_date'] ?? '';
$reason = trim($input['reason'] ?? '');

if (!$leaveType || !$startDate || !$endDate) {
    echo json_encode(['success' => false, 'message' => 'Leave type, start date, and end date are required.']);
    exit;
}
if (strlen($reason) < 10) {
    echo json_encode(['success' => false, 'message' => 'Reason must be at least 10 characters.']);
    exit;
}
$start = strtotime($startDate);
$end = strtotime($endDate);
if ($start === false || $end === false || $end < $start) {
    echo json_encode(['success' => false, 'message' => 'End date must be on or after start date.']);
    exit;
}
if ($start < strtotime('today')) {
    echo json_encode(['success' => false, 'message' => 'Start date cannot be in the past.']);
    exit;
}

$totalDays = floor(($end - $start) / 86400) + 1;
if (!isset($db)) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database error']);
    exit;
}

// Check balance (leave_balances uses leave_type as varchar)
$stmt = $db->prepare("
    SELECT entitlement, used, pending FROM leave_balances
    WHERE employee_id = :emp_id AND leave_type = :lt AND year = YEAR(CURDATE())
");
$stmt->execute([':emp_id' => $employeeId, ':lt' => $leaveType]);
$bal = $stmt->fetch(PDO::FETCH_ASSOC);
$available = $bal ? ($bal['entitlement'] - $bal['used'] - $bal['pending']) : 15;
if ($totalDays > $available) {
    echo json_encode(['success' => false, 'message' => 'Insufficient leave balance. Available: ' . (int) $available . ' days.']);
    exit;
}

// Overlap check
$stmt = $db->prepare("
    SELECT id FROM leave_requests
    WHERE employee_id = :emp_id AND status IN ('pending','endorsed','approved')
      AND ((start_date BETWEEN :s AND :e) OR (end_date BETWEEN :s AND :e) OR (start_date <= :s AND end_date >= :e))
");
$stmt->execute([':emp_id' => $employeeId, ':s' => $startDate, ':e' => $endDate]);
if ($stmt->fetch()) {
    echo json_encode(['success' => false, 'message' => 'These dates overlap with an existing leave request.']);
    exit;
}

try {
    $db->prepare("
        INSERT INTO leave_requests (employee_id, leave_type, start_date, end_date, total_days, reason, status, submitted_at)
        VALUES (?, ?, ?, ?, ?, ?, 'pending', NOW())
    ")->execute([$employeeId, $leaveType, $startDate, $endDate, $totalDays, $reason]);

    $requestId = $db->lastInsertId();
    $stmt = $db->prepare("UPDATE leave_balances SET pending = pending + ? WHERE employee_id = ? AND leave_type = ? AND year = YEAR(CURDATE())");
    $stmt->execute([$totalDays, $employeeId, $leaveType]);
    if ($stmt->rowCount() === 0) {
        $db->prepare("INSERT INTO leave_balances (employee_id, leave_type, entitlement, used, pending, year) VALUES (?, ?, 15, 0, ?, YEAR(CURDATE()))")
            ->execute([$employeeId, $leaveType, $totalDays]);
    }

    echo json_encode(['success' => true, 'message' => 'Leave request submitted.', 'request_id' => (int) $requestId]);
} catch (PDOException $e) {
    error_log('Leave request error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Something went wrong. Please try again.']);
}
