<?php
/**
 * GET ?period_start=YYYY-MM-DD&period_end=YYYY-MM-DD - Current employee's timesheet + rows.
 */
header('Content-Type: application/json');
require_once __DIR__ . '/../../config/config.php';
if (session_status() === PHP_SESSION_NONE) session_start();

$employeeId = function_exists('getCurrentEmployeeId') ? getCurrentEmployeeId($db ?? null) : null;
if (!$employeeId) {
    $employeeId = $_SESSION['employee_id'] ?? $_SESSION['user_id'] ?? null;
    if ($employeeId && !is_numeric($employeeId) && isset($db)) {
        try {
            $stmt = $db->prepare("SELECT id FROM employees WHERE employee_number = ? LIMIT 1");
            $stmt->execute([$employeeId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            $employeeId = $row ? (int) $row['id'] : null;
        } catch (PDOException $e) { $employeeId = null; }
    } elseif ($employeeId) { $employeeId = (int) $employeeId; }
}
if (!$employeeId) {
    http_response_code(401);
    echo json_encode(['success' => false]);
    exit;
}

$periodStart = $_GET['period_start'] ?? date('Y-m-01');
$periodEnd = $_GET['period_end'] ?? date('Y-m-t');

if (!isset($db)) {
    echo json_encode(['success' => true, 'timesheet' => null, 'rows' => []]);
    exit;
}

$stmt = $db->prepare("SELECT id, employee_id, period_start, period_end, total_hours, total_ot_hours, total_nd_hours, status, approved_at FROM timesheets WHERE employee_id = ? AND period_start = ? AND period_end = ?");
$stmt->execute([$employeeId, $periodStart, $periodEnd]);
$timesheet = $stmt->fetch(PDO::FETCH_ASSOC);
$rows = [];
if ($timesheet) {
    $timesheet['id'] = (int) $timesheet['id'];
    $timesheet['total_hours'] = (float) $timesheet['total_hours'];
    $timesheet['total_ot_hours'] = (float) $timesheet['total_ot_hours'];
    $timesheet['total_nd_hours'] = (float) $timesheet['total_nd_hours'];
    if ($db->query("SHOW TABLES LIKE 'timesheet_lines'")->rowCount() > 0) {
        $st = $db->prepare("SELECT date, regular_hours, ot_hours, nd_hours, late_minutes, undertime_minutes, holiday_premium_hours, rest_day_hours, notes FROM timesheet_lines WHERE timesheet_id = ? ORDER BY date");
        $st->execute([$timesheet['id']]);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    }
}
echo json_encode(['success' => true, 'timesheet' => $timesheet, 'rows' => $rows]);
