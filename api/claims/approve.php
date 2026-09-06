<?php
/**
 * POST { claim_id, note? } - Approve claim (admin).
 */
header('Content-Type: application/json');
require_once __DIR__ . '/../../config/config.php';
if (session_status() === PHP_SESSION_NONE) session_start();

$role = $_SESSION['role_name'] ?? $_SESSION['user_role'] ?? 'employee';
$normalized = strtolower(trim($role));
if (!in_array($normalized, ['admin', 'super admin', 'staff', 'hr_admin', 'finance', 'supervisor', 'unit_head'], true)) {
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
$approvedBy = $_SESSION['employee_id'] ?? $_SESSION['user_id'] ?? null;
// approved_by column is INT: use only numeric value or NULL (session may store string ID e.g. H3-2025-02-...)
$approvedBy = is_numeric($approvedBy) ? (int) $approvedBy : null;
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
if (!in_array($row['status'], ['submitted', 'endorsed'], true)) {
    echo json_encode(['success' => false, 'message' => 'This claim has already been processed.']);
    exit;
}

$db->prepare("UPDATE claims SET status = 'approved', approved_by = ?, approved_at = NOW() WHERE id = ?")->execute([$approvedBy, $claimId]);
echo json_encode(['success' => true, 'message' => 'Claim approved.']);
