<?php
/**
 * GET ?timesheet_id=X&format=csv - Export timesheet as CSV (employee's own only)
 */
require_once __DIR__ . '/../../config/config.php';
if (session_status() === PHP_SESSION_NONE) session_start();

$employeeId = getCurrentEmployeeId($db ?? null);
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
    header('HTTP/1.1 401 Unauthorized');
    exit;
}

$timesheetId = (int) ($_GET['timesheet_id'] ?? 0);
$format = $_GET['format'] ?? 'csv';

if (!$timesheetId || !isset($db)) {
    header('HTTP/1.1 400 Bad Request');
    exit;
}

try {
    $stmt = $db->prepare("SELECT id, period_start, period_end, total_hours, total_ot_hours, status FROM timesheets WHERE id = ? AND employee_id = ? LIMIT 1");
    $stmt->execute([$timesheetId, $employeeId]);
    $ts = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$ts) {
        header('HTTP/1.1 403 Forbidden');
        exit;
    }

    $stmt = $db->prepare("SELECT date, regular_hours, ot_hours, nd_hours, late_minutes, undertime_minutes, holiday_premium_hours, rest_day_hours, notes FROM timesheet_lines WHERE timesheet_id = ? ORDER BY date");
    $stmt->execute([$timesheetId]);
    $lines = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    header('HTTP/1.1 500 Internal Server Error');
    exit;
}

if ($format === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="timesheet_' . $ts['period_start'] . '_' . $ts['period_end'] . '.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Timesheet', $ts['period_start'] . ' - ' . $ts['period_end'], 'Total Hours: ' . $ts['total_hours'], 'OT: ' . $ts['total_ot_hours']]);
    fputcsv($out, []);
    fputcsv($out, ['Date', 'Regular', 'OT', 'ND', 'Late(min)', 'Undertime(min)', 'Holiday Premium', 'Rest Day', 'Notes']);
    foreach ($lines as $row) {
        fputcsv($out, [$row['date'], $row['regular_hours'], $row['ot_hours'], $row['nd_hours'], $row['late_minutes'], $row['undertime_minutes'], $row['holiday_premium_hours'], $row['rest_day_hours'], $row['notes'] ?? '']);
    }
    fclose($out);
    exit;
}

header('HTTP/1.1 400 Bad Request');
