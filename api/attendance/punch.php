<?php
/**
 * HR3 Attendance Punch API (QR or Web)
 * POST: station_id + token + log_type (QR) OR punch_type (web fallback)
 * Validates QR token when station_id/token provided; otherwise session-only (web clock).
 */
header('Content-Type: application/json');
require_once __DIR__ . '/../../config/config.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
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

$input = json_decode(file_get_contents('php://input'), true) ?: [];
$logType = $input['log_type'] ?? $input['punch_type'] ?? '';
$scanAction = strtoupper($input['scan_action'] ?? '');
$actionMap = ['TIME_IN' => 'in', 'TIME_OUT' => 'out', 'BREAK_IN' => 'break_start', 'BREAK_OUT' => 'break_end'];
if ($scanAction && isset($actionMap[$scanAction])) {
    $logType = $actionMap[$scanAction];
}
$locationId = isset($input['location_id']) ? (int) $input['location_id'] : null;
$stationId = isset($input['station_id']) ? (int) $input['station_id'] : null;
$token = $input['token'] ?? '';
$scanTimestamp = $input['scan_timestamp'] ?? null;

$allowedTypes = ['in', 'out', 'break_start', 'break_end'];
if (!in_array($logType, $allowedTypes)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid punch type. Use in, out, break_start, or break_end.']);
    exit;
}

if (!isset($db)) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database connection error']);
    exit;
}

$deviceId = null;
$source = 'biometric'; // Employee attendance is biometric only (station/kiosk or normalized web)
$stationName = null;
$locationIdUsed = null;

// QR mode (new): dynamic token via location_id
if ($locationId && $token !== '') {
    $tokenData = is_string($token) ? json_decode($token, true) : $token;
    $tokenStr = is_array($tokenData) ? json_encode($tokenData) : $token;
    $validation = qrValidateToken($db, $tokenStr, $locationId);
    if (!$validation['valid']) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => $validation['error'] ?? 'Invalid or expired QR. Please scan again.']);
        exit;
    }
    $locationIdUsed = $locationId;
    $source = 'biometric';
    $dupWindow = (int) ($validation['dup_window'] ?? 30);
    $stmt = $db->prepare("
        SELECT id FROM attendance_logs
        WHERE employee_id = :emp_id AND log_type = :log_type
          AND log_time >= DATE_SUB(NOW(), INTERVAL :dup SECOND)
    ");
    $stmt->bindValue(':emp_id', $employeeId, PDO::PARAM_INT);
    $stmt->bindValue(':log_type', $logType);
    $stmt->bindValue(':dup', $dupWindow, PDO::PARAM_INT);
    $stmt->execute();
    if ($stmt->fetch()) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Duplicate scan. Please wait before scanning again.']);
        exit;
    }
    $stmt = $db->prepare("SELECT name FROM qr_locations WHERE id = ? AND is_active = 1");
    $stmt->execute([$locationId]);
    $loc = $stmt->fetch(PDO::FETCH_ASSOC);
    $stationName = $loc['name'] ?? 'QR Location';
}
// Legacy QR mode: static station token
elseif ($stationId && $token !== '') {
    $hasQrStations = $db->query("SHOW TABLES LIKE 'qr_stations'")->rowCount() > 0;
    if (!$hasQrStations) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'QR stations not configured. Use web clock.']);
        exit;
    }
    $stmt = $db->prepare("SELECT id, name, token, is_active FROM qr_stations WHERE id = :id AND is_active = 1");
    $stmt->execute([':id' => $stationId]);
    $station = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$station || $station['token'] !== $token) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Invalid or expired QR. Please scan the current screen.']);
        exit;
    }
    $deviceId = (int) $station['id'];
    $source = 'biometric';
    $stationName = $station['name'];
}

// Duplicate check (web/legacy): same log_type within 5 minutes
$stmt = $db->prepare("
    SELECT id FROM attendance_logs
    WHERE employee_id = :emp_id AND log_type = :log_type
      AND log_time >= DATE_SUB(NOW(), INTERVAL 5 MINUTE)
");
$stmt->execute([':emp_id' => $employeeId, ':log_type' => $logType]);
if ($stmt->fetch()) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Already punched ' . $logType . ' within the last 5 minutes.']);
    exit;
}

// Missing clock-out: create exception for previous day if logging IN and last punch was IN
if ($logType === 'in') {
    $stmt = $db->prepare("
        SELECT log_time, DATE(log_time) as d
        FROM attendance_logs
        WHERE employee_id = :emp_id AND log_type IN ('in','break_end')
        ORDER BY log_time DESC LIMIT 1
    ");
    $stmt->execute([':emp_id' => $employeeId]);
    $lastIn = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($lastIn) {
        $stmt = $db->prepare("
            SELECT id FROM attendance_logs
            WHERE employee_id = :emp_id AND log_time > :log_time AND log_type IN ('out','break_start')
            LIMIT 1
        ");
        $stmt->execute([':emp_id' => $employeeId, ':log_time' => $lastIn['log_time']]);
        if (!$stmt->fetch() && $db->query("SHOW TABLES LIKE 'attendance_exceptions'")->rowCount() > 0) {
            $ins = $db->prepare("
                INSERT INTO attendance_exceptions (employee_id, log_date, exception_type, details, status)
                VALUES (:emp_id, :log_date, 'missing_out', 'No clock-out after last in', 'pending')
            ");
            $ins->execute([':emp_id' => $employeeId, ':log_date' => $lastIn['d']]);
        }
    }
}

try {
    $hasLocationCol = false;
    try {
        $chk = $db->query("SHOW COLUMNS FROM attendance_logs LIKE 'location_id'");
        $hasLocationCol = $chk && $chk->rowCount() > 0;
    } catch (PDOException $e) {}
    $sql = "INSERT INTO attendance_logs (employee_id, log_time, log_type, device_id, source, ip_address, created_at" . ($hasLocationCol && $locationIdUsed ? ", location_id" : "") . ")
            VALUES (:emp_id, NOW(), :log_type, :device_id, :source, :ip, NOW()" . ($hasLocationCol && $locationIdUsed ? ", :location_id" : "") . ")";
    $stmt = $db->prepare($sql);
    $stmt->bindValue(':emp_id', $employeeId, PDO::PARAM_INT);
    $stmt->bindValue(':log_type', $logType);
    $stmt->bindValue(':device_id', $deviceId ?: null, $deviceId ? PDO::PARAM_INT : PDO::PARAM_NULL);
    $stmt->bindValue(':source', $source);
    $stmt->bindValue(':ip', $_SERVER['REMOTE_ADDR'] ?? null);
    if ($hasLocationCol && $locationIdUsed) {
        $stmt->bindValue(':location_id', $locationIdUsed, PDO::PARAM_INT);
    }
    $stmt->execute();
    $logId = $db->lastInsertId();

    if ($deviceId && $db->query("SHOW TABLES LIKE 'qr_stations'")->rowCount() > 0) {
        $db->prepare("UPDATE qr_stations SET last_used_at = NOW() WHERE id = ?")->execute([$deviceId]);
    }

    $punchTime = date('Y-m-d H:i:s');
    echo json_encode([
        'success' => true,
        'message' => $logType === 'in' ? 'Clocked in at ' . date('H:i', strtotime($punchTime)) : 'Clocked out at ' . date('H:i', strtotime($punchTime)),
        'data' => [
            'log_id' => (int) $logId,
            'punch_type' => $logType,
            'timestamp' => $punchTime,
            'station_name' => $stationName,
        ],
    ]);
} catch (PDOException $e) {
    error_log('Attendance punch error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Failed to record punch. Please try again.']);
}
