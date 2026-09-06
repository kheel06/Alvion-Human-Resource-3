<?php
/**
 * GET: My overtime requests
 * POST: Create overtime request
 */
header('Content-Type: application/json');
require_once __DIR__ . '/../../config/config.php';
if (session_status() === PHP_SESSION_NONE) session_start();

$employeeId = getCurrentEmployeeId($db ?? null);
if (!$employeeId) {
    $employeeId = $_SESSION['hr3_employee_id'] ?? $_SESSION['employee_id'] ?? $_SESSION['user_id'] ?? null;
}
if ($employeeId && !is_numeric($employeeId) && isset($db)) {
    try {
        $stmt = $db->prepare("SELECT id FROM employees WHERE employee_number = ? LIMIT 1");
        $stmt->execute([$employeeId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        $employeeId = $row ? (int) $row['id'] : null;
    } catch (PDOException $e) {
        $employeeId = null;
    }
} elseif ($employeeId) {
    $employeeId = (int) $employeeId;
}
if (!$employeeId) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Session expired']);
    exit;
}

if (!isset($db)) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database error']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    try {
        $stmt = $db->prepare("
            SELECT id, request_date, start_time, end_time, hours, reason, status, created_at
            FROM overtime_requests
            WHERE employee_id = ?
            ORDER BY created_at DESC
            LIMIT 50
        ");
        $stmt->execute([$employeeId]);
        echo json_encode(['success' => true, 'data' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
    } catch (PDOException $e) {
        echo json_encode(['success' => true, 'data' => []]);
    }
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true) ?: $_POST;
    $requestDate = $input['request_date'] ?? '';
    $startTime = $input['start_time'] ?? '';
    $endTime = $input['end_time'] ?? '';
    $reason = trim($input['reason'] ?? '');

    if (!$requestDate || !$startTime || !$endTime || strlen($reason) < 10) {
        echo json_encode(['success' => false, 'message' => 'Date, start time, end time, and reason (min 10 chars) required']);
        exit;
    }

    $start = strtotime($requestDate . ' ' . $startTime);
    $end = strtotime($requestDate . ' ' . $endTime);
    $hours = $end > $start ? round(($end - $start) / 3600, 2) : 0;

    try {
        $stmt = $db->prepare("
            INSERT INTO overtime_requests (employee_id, request_date, start_time, end_time, hours, reason, status)
            VALUES (?, ?, ?, ?, ?, ?, 'pending')
        ");
        $stmt->execute([$employeeId, $requestDate, $startTime, $endTime, $hours, $reason]);
        echo json_encode(['success' => true, 'id' => (int) $db->lastInsertId(), 'message' => 'Overtime request submitted']);
    } catch (PDOException $e) {
        error_log('OT request error: ' . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'Failed to submit']);
    }
    exit;
}

http_response_code(405);
echo json_encode(['success' => false, 'message' => 'Method not allowed']);
