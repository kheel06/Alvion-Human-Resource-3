<?php
/**
 * POST: Generate timesheets for cut-off period (hr_admin)
 */
header('Content-Type: application/json');
require_once __DIR__ . '/../../config/config.php';

if (session_status() === PHP_SESSION_NONE) session_start();

$role = normalizeRoleName($_SESSION['role_name'] ?? $_SESSION['user_role'] ?? '');
if (!in_array($role, ['admin', 'super admin', 'hr_admin'], true)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Forbidden']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true) ?: $_POST;
$periodStart = $input['period_start'] ?? date('Y-m-01');
$periodEnd = $input['period_end'] ?? date('Y-m-t');

if (!isset($db)) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database error']);
    exit;
}

try {
    $stmt = $db->prepare("
        SELECT DISTINCT da.employee_id, e.unit_id
        FROM daily_attendance da
        JOIN employees e ON da.employee_id = e.id
        WHERE da.date BETWEEN ? AND ?
    ");
    $stmt->execute([$periodStart, $periodEnd]);
    $employees = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $created = 0;
    foreach ($employees as $emp) {
        $exists = $db->prepare("SELECT id FROM timesheets WHERE employee_id = ? AND period_start = ? AND period_end = ?");
        $exists->execute([$emp['employee_id'], $periodStart, $periodEnd]);
        if (!$exists->fetch()) {
            $ins = $db->prepare("INSERT INTO timesheets (employee_id, period_start, period_end, unit_id, status) VALUES (?, ?, ?, ?, 'draft')");
            $ins->execute([$emp['employee_id'], $periodStart, $periodEnd, $emp['unit_id'] ?? null]);
            $tsId = $db->lastInsertId();
            $sum = $db->prepare("
                SELECT SUM(regular_hours) as reg, SUM(ot_hours) as ot, SUM(nd_hours) as nd
                FROM daily_attendance WHERE employee_id = ? AND date BETWEEN ? AND ?
            ");
            $sum->execute([$emp['employee_id'], $periodStart, $periodEnd]);
            $row = $sum->fetch(PDO::FETCH_ASSOC);
            $db->prepare("UPDATE timesheets SET total_hours = ?, total_ot_hours = ?, total_nd_hours = ? WHERE id = ?")->execute([
                $row['reg'] ?? 0, $row['ot'] ?? 0, $row['nd'] ?? 0, $tsId
            ]);
            
            // Generate timesheet_lines from daily_attendance
            $dailyRows = $db->prepare("
                SELECT date, regular_hours, ot_hours, nd_hours, 
                       FLOOR(late_seconds / 60) as late_minutes, 
                       FLOOR(undertime_seconds / 60) as undertime_minutes,
                       holiday_premium_hours, rest_day_hours
                FROM daily_attendance 
                WHERE employee_id = ? AND date BETWEEN ? AND ?
                ORDER BY date
            ");
            $dailyRows->execute([$emp['employee_id'], $periodStart, $periodEnd]);
            while ($dayRow = $dailyRows->fetch(PDO::FETCH_ASSOC)) {
                try {
                    $db->prepare("
                        INSERT INTO timesheet_lines (timesheet_id, date, regular_hours, ot_hours, nd_hours, late_minutes, undertime_minutes, holiday_premium_hours, rest_day_hours)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
                        ON DUPLICATE KEY UPDATE regular_hours = VALUES(regular_hours)
                    ")->execute([
                        $tsId, $dayRow['date'], $dayRow['regular_hours'], $dayRow['ot_hours'], $dayRow['nd_hours'],
                        $dayRow['late_minutes'] ?? 0, $dayRow['undertime_minutes'] ?? 0,
                        $dayRow['holiday_premium_hours'] ?? 0, $dayRow['rest_day_hours'] ?? 0
                    ]);
                } catch (PDOException $e2) { /* skip duplicates */ }
            }
            $created++;
        }
    }
    echo json_encode(['success' => true, 'message' => "Generated {$created} timesheets", 'created' => $created]);
} catch (PDOException $e) {
    error_log('Timesheet generate: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Generation failed']);
}
