<?php
/**
 * HR3 QR Stations API
 * GET: list stations (admin). POST: create or update station (admin); or regenerate token (id + regenerate_token=1).
 */
header('Content-Type: application/json');
require_once __DIR__ . '/../../config/config.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$role = $_SESSION['role_name'] ?? $_SESSION['user_role'] ?? 'employee';
$normalized = strtolower(trim($role));
$isAdmin = in_array($normalized, ['admin', 'super admin'], true);

if (!$isAdmin) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'You do not have permission to manage QR stations.']);
    exit;
}

if (!isset($db) || $db->query("SHOW TABLES LIKE 'qr_stations'")->rowCount() === 0) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'QR stations table not available.']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $stmt = $db->query("SELECT id, name, location, code, token_updated_at, last_used_at, is_active, created_at FROM qr_stations ORDER BY name");
    $list = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo json_encode(['success' => true, 'data' => $list]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true) ?: [];

if (!empty($input['regenerate_token']) && !empty($input['id'])) {
    $id = (int) $input['id'];
    $token = bin2hex(random_bytes(32));
    $stmt = $db->prepare("UPDATE qr_stations SET token = ?, token_updated_at = NOW() WHERE id = ?");
    $stmt->execute([$token, $id]);
    if ($stmt->rowCount()) {
        echo json_encode(['success' => true, 'message' => 'Token regenerated.', 'token' => $token]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Station not found.']);
    }
    exit;
}

$name = trim($input['name'] ?? '');
$location = trim($input['location'] ?? '');
$code = trim($input['code'] ?? '');
$isActive = isset($input['is_active']) ? (int) $input['is_active'] : 1;
$id = isset($input['id']) ? (int) $input['id'] : null;

if (!$name || !$code) {
    echo json_encode(['success' => false, 'message' => 'Name and code are required.']);
    exit;
}

try {
    if ($id) {
        $stmt = $db->prepare("UPDATE qr_stations SET name = ?, location = ?, code = ?, is_active = ? WHERE id = ?");
        $stmt->execute([$name, $location, $code, $isActive, $id]);
        echo json_encode(['success' => true, 'message' => 'Station updated.', 'id' => $id]);
    } else {
        $token = bin2hex(random_bytes(32));
        $stmt = $db->prepare("INSERT INTO qr_stations (name, location, code, token, token_updated_at, is_active) VALUES (?, ?, ?, ?, NOW(), ?)");
        $stmt->execute([$name, $location, $code, $token, $isActive]);
        echo json_encode(['success' => true, 'message' => 'Station created.', 'id' => (int) $db->lastInsertId(), 'token' => $token]);
    }
} catch (PDOException $e) {
    if ($e->getCode() == 23000) {
        echo json_encode(['success' => false, 'message' => 'Station code already exists.']);
    } else {
        echo json_encode(['success' => false, 'message' => 'Failed to save station.']);
    }
}
