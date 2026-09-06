<?php
/**
 * POST { timesheet_id } - Set timesheet status to endorsed (submit for approval).
 */
header('Content-Type: application/json');
require_once __DIR__ . '/../../config/config.php';
if (session_status() === PHP_SESSION_NONE) session_start();

$employeeId = function_exists('getCurrentEmployeeId') ? getCurrentEmployeeId($db ?? null) : null;
if (!$employeeId) {
    $employeeId = $_SESSION['employee_id'] ?? $_SESSION['user_id'] ?? null;
    if ($employeeId && !is_numeric($employeeId) && isset($db)) {
        try {
            $stmt = $db->prepare("SELECT id FROM employees WHERE employee_number = ? LIMIT 1");
            $stmt->execute([$employeeId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            $employeeId = $row ? (int) $row['id'] : null;
        } catch (PDOException $e) { $employeeId = null; }
    } elseif ($employeeId) { $employeeId = (int) $employeeId; }
}
if (!$employeeId) {
    http_response_code(401);
    echo json_encode(['success' => false]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true) ?: [];
$timesheetId = (int) ($input['timesheet_id'] ?? 0);
if (!$timesheetId || !isset($db)) {
    echo json_encode(['success' => false, 'message' => 'Invalid request.']);
    exit;
}

$stmt = $db->prepare("SELECT id, status FROM timesheets WHERE id = ? AND employee_id = ?");
$stmt->execute([$timesheetId, $employeeId]);
$row = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$row) {
    echo json_encode(['success' => false, 'message' => 'Timesheet not found.']);
    exit;
}
if ($row['status'] !== 'draft') {
    echo json_encode(['success' => false, 'message' => 'Only draft timesheets can be submitted.']);
    exit;
}

$db->prepare("UPDATE timesheets SET status = 'submitted', updated_at = NOW() WHERE id = ?")->execute([$timesheetId]);
echo json_encode(['success' => true, 'message' => 'Timesheet submitted for approval.']);
