<?php
/**
 * GET: My swap requests
 * POST: Create swap request
 */
header('Content-Type: application/json');
require_once __DIR__ . '/../../config/config.php';

if (session_status() === PHP_SESSION_NONE) session_start();

$employeeId = getCurrentEmployeeId($db ?? null);
if (!$employeeId) {
    $employeeId = $_SESSION['hr3_employee_id'] ?? $_SESSION['employee_id'] ?? $_SESSION['user_id'] ?? null;
}
if ($employeeId && !is_numeric($employeeId) && isset($db)) {
    try {
        $stmt = $db->prepare("SELECT id FROM employees WHERE employee_number = ? LIMIT 1");
        $stmt->execute([$employeeId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        $employeeId = $row ? (int) $row['id'] : null;
    } catch (PDOException $e) {
        $employeeId = null;
    }
} elseif ($employeeId) {
    $employeeId = (int) $employeeId;
}
if (!$employeeId) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Session expired']);
    exit;
}

if (!isset($db)) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database error']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $stmt = $db->prepare("
        SELECT sr.*, r.period_start, r.period_end
        FROM shift_swap_requests sr
        JOIN rosters r ON sr.roster_id = r.id
        WHERE sr.requester_employee_id = ?
        ORDER BY sr.created_at DESC
        LIMIT 30
    ");
    $stmt->execute([$employeeId]);
    echo json_encode(['success' => true, 'data' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true) ?: $_POST;
    $rosterId = (int) ($input['roster_id'] ?? 0);
    $swapDate = $input['swap_date'] ?? $input['shift_date'] ?? '';
    $reason = trim($input['reason'] ?? '');

    if (!$swapDate || strlen($reason) < 5) {
        echo json_encode(['success' => false, 'message' => 'swap_date and reason (min 5 chars) required']);
        exit;
    }

    if (!$rosterId) {
        $r = $db->prepare("SELECT r.id FROM rosters r JOIN roster_assignments ra ON ra.roster_id = r.id WHERE ra.employee_id = ? AND ra.assignment_date = ? AND r.status = 'published' LIMIT 1");
        $r->execute([$employeeId, $swapDate]);
        $row = $r->fetch(PDO::FETCH_ASSOC);
        $rosterId = $row ? (int) $row['id'] : 0;
    }

    if (!$rosterId) {
        echo json_encode(['success' => false, 'message' => 'No published roster found for that date']);
        exit;
    }

    try {
        $stmt = $db->prepare("INSERT INTO shift_swap_requests (roster_id, requester_employee_id, swap_date, reason, status) VALUES (?, ?, ?, ?, 'pending')");
        $stmt->execute([$rosterId, $employeeId, $swapDate, $reason]);
        echo json_encode(['success' => true, 'id' => (int) $db->lastInsertId(), 'message' => 'Swap request submitted']);
    } catch (PDOException $e) {
        echo json_encode(['success' => false, 'message' => 'Failed to submit']);
    }
    exit;
}

http_response_code(405);
echo json_encode(['success' => false, 'message' => 'Method not allowed']);
