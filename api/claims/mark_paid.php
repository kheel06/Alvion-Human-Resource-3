<?php
/**
 * POST { claim_id } - Mark claim as paid (admin/finance).
 */
header('Content-Type: application/json');
require_once __DIR__ . '/../../config/config.php';
if (session_status() === PHP_SESSION_NONE) session_start();

$role = $_SESSION['role_name'] ?? $_SESSION['user_role'] ?? 'employee';
$normalized = strtolower(trim($role));
if (!in_array($normalized, ['admin', 'super admin', 'hr_admin', 'finance'], true)) {
    http_response_code(403);
    echo json_encode(['success' => false]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true) ?: [];
$claimId = (int) ($input['claim_id'] ?? 0);
if (!$claimId || !isset($db)) {
    echo json_encode(['success' => false, 'message' => 'Invalid request.']);
    exit;
}

$stmt = $db->prepare("SELECT id, status FROM claims WHERE id = ?");
$stmt->execute([$claimId]);
$row = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$row) {
    echo json_encode(['success' => false, 'message' => 'Claim not found.']);
    exit;
}
if ($row['status'] !== 'approved') {
    echo json_encode(['success' => false, 'message' => 'Only approved claims can be marked as paid.']);
    exit;
}

$db->prepare("UPDATE claims SET status = 'paid', paid_at = NOW() WHERE id = ?")->execute([$claimId]);
echo json_encode(['success' => true, 'message' => 'Claim marked as paid.']);
