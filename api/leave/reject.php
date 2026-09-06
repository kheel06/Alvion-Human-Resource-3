<?php
/**
 * POST { request_id, reason } - Reject leave request (admin). Reason required (10+ chars).
 */
header('Content-Type: application/json');
require_once __DIR__ . '/../../config/config.php';
if (session_status() === PHP_SESSION_NONE) session_start();

$role = $_SESSION['role_name'] ?? $_SESSION['user_role'] ?? 'employee';
$normalized = strtolower(trim($role));
if (!in_array($normalized, ['admin', 'super admin', 'staff', 'hr_admin', 'supervisor', 'unit_head'], true)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Forbidden']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true) ?: [];
$requestId = (int) ($input['request_id'] ?? 0);
$reason = trim($input['reason'] ?? '');
if (strlen($reason) < 10) {
    echo json_encode(['success' => false, 'message' => 'Please provide a reason for rejection (at least 10 characters, visible to employee).']);
    exit;
}
if (!$requestId || !isset($db)) {
    echo json_encode(['success' => false, 'message' => 'Invalid request.']);
    exit;
}

$stmt = $db->prepare("SELECT id, employee_id, leave_type, total_days, status FROM leave_requests WHERE id = ?");
$stmt->execute([$requestId]);
$row = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$row) {
    echo json_encode(['success' => false, 'message' => 'Leave request not found.']);
    exit;
}
if (!in_array($row['status'], ['pending', 'endorsed'], true)) {
    echo json_encode(['success' => false, 'message' => 'This leave request has already been processed.']);
    exit;
}

$approvedBy = $_SESSION['employee_id'] ?? $_SESSION['user_id'] ?? null;
$approvedBy = is_numeric($approvedBy) ? (int) $approvedBy : null;
$db->beginTransaction();
try {
    $db->prepare("UPDATE leave_requests SET status = 'rejected', rejection_reason = ?, approved_by = ?, approved_at = NOW() WHERE id = ?")->execute([$reason, $approvedBy, $requestId]);
    $db->prepare("UPDATE leave_balances SET pending = GREATEST(0, COALESCE(pending, 0) - ?) WHERE employee_id = ? AND leave_type = ? AND year = YEAR(CURDATE())")
        ->execute([$row['total_days'], $row['employee_id'], $row['leave_type']]);
    $db->commit();
    echo json_encode(['success' => true, 'message' => 'Leave request rejected. Employee has been notified.']);
} catch (PDOException $e) {
    $db->rollBack();
    echo json_encode(['success' => false, 'message' => 'Failed to reject.']);
}
