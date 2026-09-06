<?php
/**
 * POST { period_start, period_end } - Lock all approved timesheets in period (admin).
 */
header('Content-Type: application/json');
require_once __DIR__ . '/../../config/config.php';
if (session_status() === PHP_SESSION_NONE) session_start();

$role = $_SESSION['role_name'] ?? $_SESSION['user_role'] ?? 'employee';
$normalized = strtolower(trim($role));
if (!in_array($normalized, ['admin', 'super admin'], true)) {
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
$periodStart = $input['period_start'] ?? date('Y-m-01');
$periodEnd = $input['period_end'] ?? date('Y-m-t');
if (!isset($db)) {
    echo json_encode(['success' => false, 'message' => 'Database error']);
    exit;
}

$stmt = $db->prepare("UPDATE timesheets SET status = 'locked' WHERE period_start = ? AND period_end = ? AND status = 'approved'");
$stmt->execute([$periodStart, $periodEnd]);
$count = $stmt->rowCount();
echo json_encode(['success' => true, 'message' => 'Locked ' . $count . ' timesheet(s) for payroll.']);
exit;
