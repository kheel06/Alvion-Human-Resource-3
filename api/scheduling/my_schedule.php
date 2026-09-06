<?php
/**
 * GET ?from=YYYY-MM-DD&to=YYYY-MM-DD - Current employee's shift assignments.
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

$from = $_GET['from'] ?? date('Y-m-d');
$to = $_GET['to'] ?? date('Y-m-d', strtotime('+14 days'));

if (!isset($db)) {
    echo json_encode(['success' => true, 'next_shift' => null, 'shifts' => []]);
    exit;
}

$rows = [];
try {
    $stmt = $db->prepare("
        SELECT ra.id, ra.assignment_date as start_date, ra.assignment_date as end_date, st.name as shift_name, st.start_time, st.end_time, st.is_night_shift
        FROM roster_assignments ra
        JOIN rosters r ON ra.roster_id = r.id
        JOIN shift_templates st ON ra.shift_template_id = st.id
        WHERE ra.employee_id = :emp_id AND r.status = 'published'
          AND ra.assignment_date BETWEEN :from_date AND :to_date
        ORDER BY ra.assignment_date
    ");
    $stmt->execute([':emp_id' => $employeeId, ':from_date' => $from, ':to_date' => $to]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $rows = [];
}
if (empty($rows)) {
    try {
        $stmt = $db->prepare("
            SELECT sa.id, sa.start_date, sa.end_date, st.name as shift_name, st.start_time, st.end_time, st.is_night_shift
            FROM shift_assignments sa
            JOIN shift_templates st ON sa.shift_template_id = st.id
            WHERE sa.employee_id = :emp_id
              AND sa.start_date <= :to_date AND COALESCE(sa.end_date, sa.start_date) >= :from_date
            ORDER BY sa.start_date
        ");
        $stmt->execute([':emp_id' => $employeeId, ':from_date' => $from, ':to_date' => $to]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {}
}

$nextShift = null;
$shifts = [];
foreach ($rows as $r) {
    $shifts[] = [
        'id' => (int) $r['id'],
        'start_date' => $r['start_date'],
        'end_date' => $r['end_date'],
        'shift_code' => substr($r['shift_name'] ?? '', 0, 5),
        'shift_name' => $r['shift_name'],
        'start_time' => $r['start_time'],
        'end_time' => $r['end_time'],
        'is_night_shift' => (bool) ($r['is_night_shift'] ?? 0),
    ];
    if (!$nextShift && $r['start_date'] >= date('Y-m-d')) {
        $nextShift = $shifts[count($shifts) - 1];
    }
}
if (!$nextShift && !empty($shifts)) {
    $nextShift = $shifts[0];
}

echo json_encode(['success' => true, 'next_shift' => $nextShift, 'shifts' => $shifts]);
