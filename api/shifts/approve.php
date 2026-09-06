<?php
/**
 * API: Approve shift change request (Admin)
 */
header('Content-Type: application/json');
session_start();
require_once __DIR__ . '/../../config/config.php';

// Check admin/supervisor access
$userId = $_SESSION['user_id'] ?? null;
$role = $_SESSION['role_name'] ?? $_SESSION['user_role'] ?? $_SESSION['role'] ?? '';
$allowedRoles = ['admin', 'super_admin', 'super admin', 'supervisor', 'unit_head', 'hr_admin', 'hr admin'];

if (!$userId || !in_array(strtolower($role), $allowedRoles)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Access denied. Role: ' . $role]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
$requestId = (int)($input['request_id'] ?? 0);
$notes = trim($input['notes'] ?? '');

if (!$requestId) {
    echo json_encode(['success' => false, 'message' => 'Invalid request ID']);
    exit;
}

try {
    // Get the request
    $stmt = $db->prepare("SELECT * FROM shift_change_requests WHERE id = ? AND status = 'pending'");
    $stmt->execute([$requestId]);
    $request = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$request) {
        echo json_encode(['success' => false, 'message' => 'Request not found or already processed']);
        exit;
    }
    
    // Get reviewer employee ID
    $reviewerId = null;
    $stmt = $db->prepare("SELECT id FROM employees WHERE user_id = ? LIMIT 1");
    $stmt->execute([$userId]);
    $reviewer = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($reviewer) {
        $reviewerId = $reviewer['id'];
    }
    
    // Update the request status
    $stmt = $db->prepare("
        UPDATE shift_change_requests 
        SET status = 'approved', reviewed_by = ?, reviewed_at = NOW(), review_notes = ?
        WHERE id = ?
    ");
    $stmt->execute([$reviewerId, $notes, $requestId]);
    
    // Update employee's shift assignment
    $stmt = $db->prepare("
        UPDATE shift_assignments 
        SET shift_template_id = ?, updated_at = NOW()
        WHERE employee_id = ? AND (end_date IS NULL OR end_date >= ?)
    ");
    $stmt->execute([
        $request['requested_shift_id'], 
        $request['employee_id'],
        $request['request_date']
    ]);
    
    // If no existing assignment, create one
    if ($stmt->rowCount() === 0) {
        $stmt = $db->prepare("
            INSERT INTO shift_assignments (employee_id, shift_template_id, start_date, created_at)
            VALUES (?, ?, ?, NOW())
        ");
        $stmt->execute([
            $request['employee_id'],
            $request['requested_shift_id'],
            $request['request_date']
        ]);
    }
    
    echo json_encode(['success' => true, 'message' => 'Shift change request approved']);
    
} catch (PDOException $e) {
    error_log('Approve shift request error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Database error occurred']);
}
