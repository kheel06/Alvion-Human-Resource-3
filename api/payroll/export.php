<?php
/**
 * GET ?period_start=YYYY-MM-DD&period_end=YYYY-MM-DD&format=csv
 * Returns locked timesheets for the period (admin only). format=csv triggers file download.
 */
header('Content-Type: application/json');
require_once __DIR__ . '/../../config/config.php';
if (session_status() === PHP_SESSION_NONE) session_start();

$role = strtolower(trim($_SESSION['role_name'] ?? $_SESSION['user_role'] ?? 'employee'));
if (!in_array($role, ['admin', 'super admin', 'hr_admin', 'finance'], true)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Forbidden']);
    exit;
}

$periodStart = $_GET['period_start'] ?? date('Y-m-01');
$periodEnd = $_GET['period_end'] ?? date('Y-m-t');
$format = strtolower($_GET['format'] ?? 'json');

if (!isset($db)) {
    http_response_code(500);
    echo json_encode(['success' => false]);
    exit;
}

$stmt = $db->prepare("
    SELECT t.id, t.employee_id, t.period_start, t.period_end, t.total_hours, t.total_ot_hours, t.total_nd_hours, t.status,
           e.employee_number, e.first_name, e.last_name, e.unit_id,
           u.name as unit_name
    FROM timesheets t
    JOIN employees e ON t.employee_id = e.id
    LEFT JOIN units u ON e.unit_id = u.id
    WHERE t.period_start = ? AND t.period_end = ? AND t.status = 'locked'
    ORDER BY u.name, e.employee_number
");
$stmt->execute([$periodStart, $periodEnd]);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

if ($format === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="payroll_timesheets_' . $periodStart . '_' . $periodEnd . '.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['employee_number', 'first_name', 'last_name', 'unit', 'period_start', 'period_end', 'total_hours', 'total_ot_hours', 'total_nd_hours']);
    foreach ($rows as $r) {
        fputcsv($out, [$r['employee_number'], $r['first_name'], $r['last_name'], $r['unit_name'] ?? '', $r['period_start'], $r['period_end'], $r['total_hours'], $r['total_ot_hours'], $r['total_nd_hours']]);
    }
    fclose($out);
    exit;
}

echo json_encode(['success' => true, 'data' => $rows, 'total' => count($rows)]);
