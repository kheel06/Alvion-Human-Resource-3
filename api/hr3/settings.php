<?php
/**
 * GET: List HR3 settings
 * PUT: Update setting (hr_admin)
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
    $stmt = $db->query("SELECT setting_key, setting_value FROM hr3_settings ORDER BY setting_key");
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $settings = [];
    foreach ($rows as $r) {
        $settings[$r['setting_key']] = $r['setting_value'];
    }
    echo json_encode(['success' => true, 'data' => $settings, 'can_edit' => $canEdit]);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] === 'PUT' || $_SERVER['REQUEST_METHOD'] === 'POST') && $canEdit) {
    $input = json_decode(file_get_contents('php://input'), true) ?: $_POST;
    $key = $input['setting_key'] ?? $input['key'] ?? '';
    $value = $input['setting_value'] ?? $input['value'] ?? '';
    if (!$key) {
        echo json_encode(['success' => false, 'message' => 'setting_key required']);
        exit;
    }
    try {
        $stmt = $db->prepare("INSERT INTO hr3_settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)");
        $stmt->execute([$key, $value]);
        echo json_encode(['success' => true, 'message' => 'Setting updated']);
    } catch (PDOException $e) {
        echo json_encode(['success' => false, 'message' => 'Update failed']);
    }
    exit;
}

http_response_code(403);
echo json_encode(['success' => false, 'message' => 'Forbidden']);
