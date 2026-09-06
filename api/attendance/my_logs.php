<?php
/**
 * HR3 My Attendance Logs API
 * GET ?from=YYYY-MM-DD&to=YYYY-MM-DD
 * Returns summary + daily logs for current employee.
 */
header('Content-Type: application/json');
require_once __DIR__ . '/../../config/config.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

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
    echo json_encode(['success' => false, 'message' => 'Session expired. Please log in again.']);
    exit;
}

$from = $_GET['from'] ?? date('Y-m-d', strtotime('-14 days'));
$to = $_GET['to'] ?? date('Y-m-d');
$maxDays = 90;
if (strtotime($to) - strtotime($from) > $maxDays * 86400) {
    $to = date('Y-m-d', strtotime($from . ' +' . $maxDays . ' days'));
}

if (!isset($db)) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database error']);
    exit;
}

try {
    $stmt = $db->prepare("
        SELECT al.log_time, al.log_type, al.source, al.device_id
        FROM attendance_logs al
        WHERE al.employee_id = :emp_id
          AND DATE(al.log_time) BETWEEN :from_date AND :to_date
          AND (al.source IN ('biometric','qr','mobile') OR al.source IS NULL)
        ORDER BY al.log_time ASC
    ");
    $stmt->execute([':emp_id' => $employeeId, ':from_date' => $from, ':to_date' => $to]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $hasStations = $db->query("SHOW TABLES LIKE 'qr_stations'")->rowCount() > 0;
    $stationNames = [];
    if ($hasStations) {
        $st = $db->query("SELECT id, name FROM qr_stations");
        while ($r = $st->fetch(PDO::FETCH_ASSOC)) {
            $stationNames[(int)$r['id']] = $r['name'];
        }
    }

    $byDate = [];
    foreach ($rows as $r) {
        $d = date('Y-m-d', strtotime($r['log_time']));
        if (!isset($byDate[$d])) {
            $byDate[$d] = ['time_in' => null, 'time_out' => null, 'station_name' => null, 'hours' => null, 'status' => 'On Time'];
        }
        if (in_array($r['log_type'], ['in', 'break_end'])) {
            if (!$byDate[$d]['time_in']) {
                $byDate[$d]['time_in'] = date('H:i', strtotime($r['log_time']));
                $byDate[$d]['station_name'] = $hasStations && $r['device_id'] ? ($stationNames[(int)$r['device_id']] ?? 'N/A') : 'Web';
            }
        } elseif (in_array($r['log_type'], ['out', 'break_start'])) {
            $byDate[$d]['time_out'] = date('H:i', strtotime($r['log_time']));
        }
    }

    $logs = [];
    $summary = ['total_days' => 0, 'on_time' => 0, 'late_in' => 0, 'early_out' => 0, 'missing_out' => 0];
    foreach ($byDate as $date => $day) {
        $summary['total_days']++;
        $hours = null;
        if ($day['time_in'] && $day['time_out']) {
            $h = (strtotime($date . ' ' . $day['time_out']) - strtotime($date . ' ' . $day['time_in'])) / 3600;
            $hours = round($h, 2);
        } elseif ($day['time_in']) {
            $summary['missing_out']++;
            $day['status'] = 'No Out';
        }
        $day['hours'] = $hours;
        $day['date'] = $date;
        $logs[] = $day;
    }
    usort($logs, function ($a, $b) {
        return strcmp($b['date'], $a['date']);
    });

    echo json_encode([
        'success' => true,
        'summary' => $summary,
        'logs' => $logs,
        'from' => $from,
        'to' => $to,
    ]);
} catch (PDOException $e) {
    error_log('My logs error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Failed to load attendance logs.']);
}
