<?php
/**
 * GET: List holidays
 * POST: Add/update holiday (hr_admin)
 */
header('Content-Type: application/json');
require_once __DIR__ . '/../../config/config.php';

if (session_status() === PHP_SESSION_NONE) session_start();

$role = normalizeRoleName($_SESSION['role_name'] ?? $_SESSION['user_role'] ?? '');
$canEdit = in_array($role, ['admin', 'super admin', 'hr_admin'], true);

if (!isset($db)) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database error']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $from = $_GET['from'] ?? date('Y-01-01');
    $to = $_GET['to'] ?? date('Y-12-31');
    $stmt = $db->prepare("SELECT * FROM holiday_calendar WHERE date BETWEEN ? AND ? ORDER BY date");
    $stmt->execute([$from, $to]);
    echo json_encode(['success' => true, 'data' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $canEdit) {
    $input = json_decode(file_get_contents('php://input'), true) ?: $_POST;
    $date = $input['date'] ?? '';
    $name = trim($input['name'] ?? '');
    $type = $input['type'] ?? 'regular';
    if (!$date || !$name) {
        echo json_encode(['success' => false, 'message' => 'date and name required']);
        exit;
    }
    try {
        $stmt = $db->prepare("INSERT INTO holiday_calendar (date, name, type, multiplier_worked, multiplier_rest_day) VALUES (?, ?, ?, 2.00, 2.60) ON DUPLICATE KEY UPDATE name = VALUES(name), type = VALUES(type)");
        $stmt->execute([$date, $name, $type]);
        echo json_encode(['success' => true, 'message' => 'Holiday saved']);
    } catch (PDOException $e) {
        echo json_encode(['success' => false, 'message' => 'Failed to save']);
    }
    exit;
}

http_response_code(405);
echo json_encode(['success' => false, 'message' => 'Method not allowed']);
