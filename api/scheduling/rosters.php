<?php
/**
 * GET: List rosters (admin)
 * POST: Create/update roster (admin)
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

if (!isset($db)) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database error']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $unitId = isset($_GET['unit_id']) ? (int) $_GET['unit_id'] : null;
    $from = $_GET['from'] ?? date('Y-m-01');
    $to = $_GET['to'] ?? date('Y-m-t');
    $sql = "
        SELECT r.*, u.name as unit_name
        FROM rosters r
        LEFT JOIN units u ON r.unit_id = u.id
        WHERE r.period_start <= ? AND r.period_end >= ?
    ";
    $params = [$to, $from];
    if ($unitId) {
        $sql .= " AND r.unit_id = ?";
        $params[] = $unitId;
    }
    $sql .= " ORDER BY r.period_start DESC, r.unit_id LIMIT 50";
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $rosters = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo json_encode(['success' => true, 'data' => $rosters]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true) ?: $_POST;
    $action = $input['action'] ?? 'create';
    $id = isset($input['id']) ? (int) $input['id'] : null;
    $unitId = (int) ($input['unit_id'] ?? 0);
    $periodStart = $input['period_start'] ?? '';
    $periodEnd = $input['period_end'] ?? '';
    $status = $input['status'] ?? 'draft';

    if ($action === 'publish' && $id) {
        $stmt = $db->prepare("UPDATE rosters SET status = 'published', published_at = NOW() WHERE id = ?");
        $stmt->execute([$id]);
        echo json_encode(['success' => true, 'message' => 'Roster published']);
        exit;
    }

    if ($action === 'create' && $unitId && $periodStart && $periodEnd) {
        $stmt = $db->prepare("INSERT INTO rosters (unit_id, period_start, period_end, status) VALUES (?, ?, ?, ?)");
        $stmt->execute([$unitId, $periodStart, $periodEnd, $status]);
        echo json_encode(['success' => true, 'id' => (int) $db->lastInsertId(), 'message' => 'Roster created']);
        exit;
    }

    echo json_encode(['success' => false, 'message' => 'Invalid request']);
    exit;
}

http_response_code(405);
echo json_encode(['success' => false, 'message' => 'Method not allowed']);
