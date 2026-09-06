<?php
/**
 * POST { request_id } - Cancel own pending leave request.
 */
header('Content-Type: application/json');
require_once __DIR__ . '/../../config/config.php';
if (session_status() === PHP_SESSION_NONE) session_start();

$employeeId = $_SESSION['employee_id'] ?? $_SESSION['user_id'] ?? null;
if (!$employeeId) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Session expired.']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true) ?: [];
$requestId = (int) ($input['request_id'] ?? 0);
if (!$requestId || !isset($db)) {
    echo json_encode(['success' => false, 'message' => 'Invalid request.']);
    exit;
}

$stmt = $db->prepare("SELECT id, status, leave_type, total_days FROM leave_requests WHERE id = ? AND employee_id = ?");
$stmt->execute([$requestId, $employeeId]);
$row = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$row) {
    echo json_encode(['success' => false, 'message' => 'Leave request not found.']);
    exit;
}
if ($row['status'] !== 'pending') {
    echo json_encode(['success' => false, 'message' => 'Only pending requests can be cancelled.']);
    exit;
}

$db->beginTransaction();
try {
    $db->prepare("UPDATE leave_requests SET status = 'cancelled' WHERE id = ?")->execute([$requestId]);
    $db->prepare("UPDATE leave_balances SET pending = pending - ? WHERE employee_id = ? AND leave_type = ? AND year = YEAR(CURDATE())")
        ->execute([$row['total_days'], $employeeId, $row['leave_type']]);
    $db->commit();
    echo json_encode(['success' => true, 'message' => 'Leave request cancelled.']);
} catch (PDOException $e) {
    $db->rollBack();
    echo json_encode(['success' => false, 'message' => 'Failed to cancel.']);
}
