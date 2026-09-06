<?php
/**
 * AI Chat API
 * POST { message, history? } → { success, message, source }
 */
header('Content-Type: application/json');
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/ai_service.php';

if (session_status() === PHP_SESSION_NONE) session_start();

if (!AI_ENABLED) {
    echo json_encode(['success' => false, 'message' => 'AI assistant is currently disabled.']);
    exit;
}

$employeeId = null;
if (function_exists('getCurrentEmployeeId')) {
    $employeeId = getCurrentEmployeeId($db ?? null);
}
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
$role = $_SESSION['role_name'] ?? $_SESSION['user_role'] ?? 'employee';

if (!$employeeId) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Please log in to use the AI assistant.']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true) ?: [];
$message = trim($input['message'] ?? '');
$history = $input['history'] ?? [];

if (empty($message)) {
    echo json_encode(['success' => false, 'message' => 'Please enter a message.']);
    exit;
}

if (strlen($message) > 2000) {
    echo json_encode(['success' => false, 'message' => 'Message too long. Maximum 2000 characters.']);
    exit;
}

try {
    $ai = new HrAiService($db ?? null, (int)$employeeId, $role);
    $result = $ai->chat($message, $history);

    // Log the interaction
    try {
        if (isset($db)) {
            $db->prepare("
                INSERT INTO audit_logs (employee_id, action, table_name, record_id, new_values, ip_address) 
                VALUES (?, 'ai_chat', 'ai_interactions', 0, ?, ?)
            ")->execute([
                $employeeId,
                json_encode(['query' => substr($message, 0, 200), 'source' => $result['source'] ?? 'unknown']),
                $_SERVER['REMOTE_ADDR'] ?? null
            ]);
        }
    } catch (PDOException $e) { /* non-fatal */ }

    echo json_encode($result);
} catch (Exception $e) {
    error_log('AI Chat error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'An error occurred. Please try again.']);
}
