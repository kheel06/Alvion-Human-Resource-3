<?php
/**
 * POST { request_id, note? } - Approve leave request (admin).
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
$approvedBy = $_SESSION['employee_id'] ?? $_SESSION['user_id'] ?? null;
$approvedBy = is_numeric($approvedBy) ? (int) $approvedBy : null;
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

// Check balance only if leave_balances table has a row (optional: allow approval when no balance row)
$available = null;
try {
    $stmt = $db->prepare("SELECT entitlement, used, pending FROM leave_balances WHERE employee_id = ? AND leave_type = ? AND year = YEAR(CURDATE())");
    $stmt->execute([$row['employee_id'], $row['leave_type']]);
    $bal = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($bal) {
        $available = (float)$bal['entitlement'] - (float)$bal['used'] - (float)$bal['pending'];
        if ($row['total_days'] > $available) {
            echo json_encode(['success' => false, 'message' => 'Cannot approve: employee balance is insufficient for this request.']);
            exit;
        }
    }
} catch (PDOException $e) {
    error_log('Leave approve balance check: ' . $e->getMessage());
    // Continue without balance check if table missing or error
}

$db->beginTransaction();
try {
    $db->prepare("UPDATE leave_requests SET status = 'approved', approved_by = ?, approved_at = NOW() WHERE id = ?")->execute([$approvedBy, $requestId]);
    // Update balance if leave_balances exists and has a row (best-effort)
    try {
        $upd = $db->prepare("UPDATE leave_balances SET used = used + ?, pending = GREATEST(0, COALESCE(pending, 0) - ?) WHERE employee_id = ? AND leave_type = ? AND year = YEAR(CURDATE())");
        $upd->execute([$row['total_days'], $row['total_days'], $row['employee_id'], $row['leave_type']]);
    } catch (PDOException $e) {
        error_log('Leave approve balance update: ' . $e->getMessage());
        // Don't fail approval if balance update fails (e.g. no row or schema difference)
    }
    $db->commit();
    echo json_encode(['success' => true, 'message' => 'Leave request approved.']);
} catch (PDOException $e) {
    $db->rollBack();
    error_log('Leave approve: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Failed to approve.']);
}
