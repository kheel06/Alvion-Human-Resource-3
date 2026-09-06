<?php
/**
 * GET ?location_id=1
 * Returns current valid QR token for the location (dynamic rotation)
 */
header('Content-Type: application/json');
require_once __DIR__ . '/../../config/config.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$employeeId = $_SESSION['hr3_employee_id'] ?? $_SESSION['employee_id'] ?? $_SESSION['user_id'] ?? null;
if (!$employeeId) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Session expired']);
    exit;
}

$locationId = isset($_GET['location_id']) ? (int) $_GET['location_id'] : 0;
if (!$locationId) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'location_id required']);
    exit;
}

if (!isset($db)) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database error']);
    exit;
}

try {
    $tokenData = qrGenerateToken($db, $locationId);
    $token = [
        'payload' => $tokenData['payload'],
        'signature' => $tokenData['signature'],
        'location_id' => $locationId,
        'valid_from' => $tokenData['valid_from'],
        'valid_to' => $tokenData['valid_to'],
        'nonce' => $tokenData['nonce'],
    ];
    echo json_encode([
        'success' => true,
        'token' => $token,
        'expires_in' => (int) (getHr3Setting($db, 'qr_rotation_seconds', 60) ?: 60),
    ]);
} catch (Exception $e) {
    error_log('QR token error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Failed to generate token']);
}
