<?php
header('Content-Type: application/json');
require_once __DIR__ . '/../../config/config.php';
if (session_status() === PHP_SESSION_NONE) session_start();

$employeeId = $_SESSION['employee_id'] ?? $_SESSION['user_id'] ?? null;
if (!$employeeId) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Session expired. Please log in again.']);
    exit;
}

$year = isset($_GET['year']) ? (int) $_GET['year'] : (int) date('Y');
if (!isset($db)) {
    http_response_code(500);
    echo json_encode(['success' => false]);
    exit;
}

$stmt = $db->prepare("
    SELECT leave_type, entitlement, used, pending,
           (entitlement - used - pending) as available
    FROM leave_balances
    WHERE employee_id = :emp_id AND year = :yr
");
$stmt->execute([':emp_id' => $employeeId, ':yr' => $year]);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

$balances = [];
foreach ($rows as $r) {
    $balances[] = [
        'leave_type' => $r['leave_type'],
        'name' => $r['leave_type'],
        'entitlement' => (float) $r['entitlement'],
        'used' => (float) $r['used'],
        'pending' => (float) $r['pending'],
        'available' => (float) $r['available'],
    ];
}

echo json_encode(['success' => true, 'balances' => $balances, 'year' => $year]);
