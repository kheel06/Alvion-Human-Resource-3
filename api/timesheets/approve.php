<?php
/**
 * POST { timesheet_id } - Approve timesheet (admin/supervisor).
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

$stmt = $db->prepare("SELECT id, status FROM timesheets WHERE id = ?");
$stmt->execute([$timesheetId]);
$row = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$row) {
    echo json_encode(['success' => false, 'message' => 'Timesheet not found.']);
    exit;
}
if (!in_array($row['status'], ['submitted', 'endorsed'], true)) {
    echo json_encode(['success' => false, 'message' => 'Only submitted/endorsed timesheets can be approved.']);
    exit;
}

$db->prepare("UPDATE timesheets SET status = 'approved', updated_at = NOW() WHERE id = ?")->execute([$timesheetId]);

// Audit log
try {
    $db->prepare("INSERT INTO audit_logs (employee_id, action, table_name, record_id, new_values, ip_address) VALUES (?, 'approve_timesheet', 'timesheets', ?, ?, ?)")
       ->execute([
           $_SESSION['employee_id'] ?? $_SESSION['user_id'] ?? null,
           $timesheetId,
           json_encode(['status' => 'approved']),
           $_SERVER['REMOTE_ADDR'] ?? null
       ]);
} catch (PDOException $e) { /* non-fatal */ }

echo json_encode(['success' => true, 'message' => 'Timesheet approved.']);
