<?php
/**
 * AI Insights API
 * GET → { success, insights[], analytics{} }
 */
header('Content-Type: application/json');
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/ai_service.php';

if (session_status() === PHP_SESSION_NONE) session_start();

if (!AI_ENABLED) {
    echo json_encode(['success' => false, 'message' => 'AI is disabled.']);
    exit;
}

$role = strtolower(trim($_SESSION['role_name'] ?? $_SESSION['user_role'] ?? ''));
$allowed = ['admin', 'super admin', 'hr_admin', 'supervisor', 'unit_head'];
if (!in_array($role, $allowed)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Access denied.']);
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

try {
    $ai = new HrAiService($db ?? null, $employeeId ? (int)$employeeId : null, $role);

    $type = $_GET['type'] ?? 'all';
    $response = ['success' => true];

    if ($type === 'all' || $type === 'insights') {
        $response['insights'] = $ai->getInsights();
    }
    if ($type === 'all' || $type === 'anomalies') {
        $response['anomalies'] = $ai->detectAnomalies();
    }
    if ($type === 'all' || $type === 'analytics') {
        $response['analytics'] = $ai->getAnalytics();
    }

    echo json_encode($response);
} catch (Exception $e) {
    error_log('AI Insights error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Error loading insights.']);
}
