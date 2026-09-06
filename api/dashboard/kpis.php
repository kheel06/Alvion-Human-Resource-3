<?php
/**
 * GET - Role-based dashboard KPIs for HR3.
 * Employee: hours_this_week, leave_balance, pending_approvals, today_shift, last_scan, cutoff_progress.
 * Admin: pending_exceptions, late_today, on_leave_today, pending_approvals, pending_timesheets, cutoff_locked_count.
 */
header('Content-Type: application/json');
require_once __DIR__ . '/../../config/config.php';
if (session_status() === PHP_SESSION_NONE) session_start();

$employeeId = $_SESSION['employee_id'] ?? $_SESSION['user_id'] ?? null;
$role = $_SESSION['role_name'] ?? $_SESSION['user_role'] ?? 'employee';
$normalized = strtolower(trim($role));

if (!$employeeId && $normalized !== 'admin' && $normalized !== 'super admin') {
    http_response_code(401);
    echo json_encode(['success' => false]);
    exit;
}

if (!isset($db)) {
    echo json_encode(['success' => true, 'metrics' => []]);
    exit;
}

$metrics = [];

if (in_array($normalized, ['employee', 'staff'])) {
    $weekStart = date('Y-m-d', strtotime('monday this week'));
    $stmt = $db->prepare("SELECT COALESCE(SUM(total_work_seconds), 0) / 3600 as h FROM daily_attendance WHERE employee_id = ? AND date >= ?");
    $stmt->execute([$employeeId, $weekStart]);
    $metrics['hours_this_week'] = round((float) ($stmt->fetch(PDO::FETCH_ASSOC)['h'] ?? 0), 1);

    $stmt = $db->prepare("SELECT COALESCE(SUM(entitlement - used - pending), 0) as av FROM leave_balances WHERE employee_id = ? AND year = YEAR(CURDATE())");
    $stmt->execute([$employeeId]);
    $metrics['leave_balance'] = round((float) ($stmt->fetch(PDO::FETCH_ASSOC)['av'] ?? 0), 1);

    $stmt = $db->prepare("SELECT log_type, log_time FROM attendance_logs WHERE employee_id = ? AND DATE(log_time) = CURDATE() AND (source IN ('biometric','qr','mobile') OR source IS NULL) ORDER BY log_time DESC LIMIT 1");
    $stmt->execute([$employeeId]);
    $last = $stmt->fetch(PDO::FETCH_ASSOC);
    $metrics['last_scan'] = $last ? ['type' => $last['log_type'], 'time' => $last['log_time']] : null;

    $stmt = $db->prepare("SELECT st.name, st.start_time, st.end_time FROM shift_assignments sa JOIN shift_templates st ON sa.shift_template_id = st.id WHERE sa.employee_id = ? AND CURDATE() BETWEEN sa.start_date AND COALESCE(sa.end_date, CURDATE()) LIMIT 1");
    $stmt->execute([$employeeId]);
    $metrics['today_shift'] = $stmt->fetch(PDO::FETCH_ASSOC);

    $pending = 0;
    $stmt = $db->prepare("SELECT COUNT(*) as c FROM leave_requests WHERE employee_id = ? AND status IN ('pending','endorsed')");
    $stmt->execute([$employeeId]);
    $pending += (int) $stmt->fetch(PDO::FETCH_ASSOC)['c'];
    $stmt = $db->prepare("SELECT COUNT(*) as c FROM claims WHERE employee_id = ? AND status IN ('submitted','endorsed')");
    $stmt->execute([$employeeId]);
    $pending += (int) $stmt->fetch(PDO::FETCH_ASSOC)['c'];
    $metrics['pending_approvals'] = $pending;

    $periodStart = date('Y-m-01');
    $periodEnd = date('Y-m-t');
    $stmt = $db->prepare("SELECT id, status FROM timesheets WHERE employee_id = ? AND period_start = ? AND period_end = ?");
    $stmt->execute([$employeeId, $periodStart, $periodEnd]);
    $ts = $stmt->fetch(PDO::FETCH_ASSOC);
    $metrics['cutoff_progress'] = $ts ? ['status' => $ts['status'], 'period_start' => $periodStart, 'period_end' => $periodEnd] : null;

    if ($db->query("SHOW TABLES LIKE 'attendance_exceptions'")->rowCount() > 0) {
        $stmt = $db->prepare("SELECT COUNT(*) as c FROM attendance_exceptions WHERE employee_id = ? AND status = 'pending'");
        $stmt->execute([$employeeId]);
        $metrics['pending_exceptions'] = (int) $stmt->fetch(PDO::FETCH_ASSOC)['c'];
    }
}

if (in_array($normalized, ['admin', 'super admin'])) {
    if ($db->query("SHOW TABLES LIKE 'attendance_exceptions'")->rowCount() > 0) {
        $stmt = $db->query("SELECT COUNT(*) as c FROM attendance_exceptions WHERE status = 'pending' AND log_date = CURDATE()");
        $metrics['exceptions_today'] = (int) $stmt->fetch(PDO::FETCH_ASSOC)['c'];
    } else {
        $metrics['exceptions_today'] = 0;
    }
    $stmt = $db->query("SELECT COUNT(*) as c FROM daily_attendance WHERE date = CURDATE() AND late_seconds > 0");
    $metrics['late_today'] = (int) $stmt->fetch(PDO::FETCH_ASSOC)['c'];
    // On leave today — same as Attendance page (daily_attendance)
    if ($db->query("SHOW TABLES LIKE 'daily_attendance'")->rowCount() > 0) {
        $stmt = $db->query("SELECT COUNT(*) as c FROM daily_attendance WHERE date = CURDATE() AND status = 'leave'");
        $metrics['on_leave_today'] = (int) $stmt->fetch(PDO::FETCH_ASSOC)['c'];
    } else {
        $metrics['on_leave_today'] = 0;
    }
    $pending = 0;
    $stmt = $db->query("SELECT COUNT(*) as c FROM leave_requests WHERE status IN ('pending','endorsed')");
    $pending += (int) $stmt->fetch(PDO::FETCH_ASSOC)['c'];
    $stmt = $db->query("SELECT COUNT(*) as c FROM claims WHERE status IN ('submitted','endorsed')");
    $pending += (int) $stmt->fetch(PDO::FETCH_ASSOC)['c'];
    $metrics['pending_approvals'] = $pending;
    $stmt = $db->query("SELECT COUNT(*) as c FROM timesheets WHERE status = 'endorsed'");
    $metrics['pending_timesheets'] = (int) $stmt->fetch(PDO::FETCH_ASSOC)['c'];
    $stmt = $db->query("SELECT COUNT(*) as c FROM timesheets WHERE status = 'locked' AND period_end >= DATE_SUB(CURDATE(), INTERVAL 1 MONTH)");
    $metrics['cutoff_locked_count'] = (int) $stmt->fetch(PDO::FETCH_ASSOC)['c'];

    // Today's attendance counts — same as Attendance page and dashboard (daily_attendance)
    if ($db->query("SHOW TABLES LIKE 'daily_attendance'")->rowCount() > 0) {
        $stmt = $db->query("
            SELECT
                SUM(CASE WHEN status IN ('present','half_day') THEN 1 ELSE 0 END) as present_cnt,
                SUM(CASE WHEN status = 'absent' THEN 1 ELSE 0 END) as absent_cnt
            FROM daily_attendance WHERE date = CURDATE()
        ");
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        $metrics['present_today'] = (int) ($row['present_cnt'] ?? 0);
        $metrics['absent_today'] = (int) ($row['absent_cnt'] ?? 0);
    } else {
        $metrics['present_today'] = 0;
        $metrics['absent_today'] = 0;
    }
    if ($db->query("SHOW TABLES LIKE 'employees'")->rowCount() > 0 && $db->query("SHOW TABLES LIKE 'daily_attendance'")->rowCount() > 0) {
        $stmt = $db->query("
            SELECT COUNT(*) as c FROM employees e
            WHERE e.status = 'active'
              AND NOT EXISTS (SELECT 1 FROM daily_attendance da WHERE da.employee_id = e.id AND da.date = CURDATE())
        ");
        $metrics['no_record_today'] = (int) $stmt->fetch(PDO::FETCH_ASSOC)['c'];
    } else {
        $metrics['no_record_today'] = 0;
    }
    // Biometric scans today — same as Biometric Log page (attendance_logs)
    if ($db->query("SHOW TABLES LIKE 'attendance_logs'")->rowCount() > 0) {
        $stmt = $db->query("SELECT COUNT(*) as c FROM attendance_logs WHERE DATE(log_time) = CURDATE()");
        $metrics['biometric_scans_today'] = (int) $stmt->fetch(PDO::FETCH_ASSOC)['c'];
    } else {
        $metrics['biometric_scans_today'] = 0;
    }
}

echo json_encode(['success' => true, 'metrics' => $metrics]);
