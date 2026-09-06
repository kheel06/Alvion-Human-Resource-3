<?php
/**
 * HR3 Resolve Attendance Exception (Admin)
 * POST { exception_id, action: approve|reject, note? }
 */
header('Content-Type: application/json');
require_once __DIR__ . '/../../config/config.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

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
$exceptionId = (int) ($input['exception_id'] ?? 0);
$action = strtolower(trim($input['action'] ?? ''));
$note = trim($input['note'] ?? '');

if (!$exceptionId || !in_array($action, ['approve', 'reject'], true)) {
    echo json_encode(['success' => false, 'message' => 'Invalid exception_id or action.']);
    exit;
}

if (!isset($db) || $db->query("SHOW TABLES LIKE 'attendance_exceptions'")->rowCount() === 0) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Exceptions table not available.']);
    exit;
}

$resolvedBy = $_SESSION['employee_id'] ?? $_SESSION['user_id'] ?? null;
$stmt = $db->prepare("SELECT id, status FROM attendance_exceptions WHERE id = ?");
$stmt->execute([$exceptionId]);
$row = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$row) {
    echo json_encode(['success' => false, 'message' => 'Exception not found.']);
    exit;
}
if ($row['status'] !== 'pending') {
    echo json_encode(['success' => false, 'message' => 'This exception has already been resolved.']);
    exit;
}

$newStatus = $action === 'approve' ? 'resolved' : 'dismissed';
$stmt = $db->prepare("UPDATE attendance_exceptions SET status = ?, resolved_at = NOW(), resolved_by = ?, resolution_notes = ? WHERE id = ?");
$stmt->execute([$newStatus, $resolvedBy, $note, $exceptionId]);

echo json_encode(['success' => true, 'message' => 'Exception ' . $newStatus . '.']);
exit;
