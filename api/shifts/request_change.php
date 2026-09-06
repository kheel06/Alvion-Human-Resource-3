<?php
/**
 * API: Submit shift change request (Employee)
 */
header('Content-Type: application/json');
session_start();
require_once __DIR__ . '/../../config/config.php';

$employeeId = getCurrentEmployeeId($db ?? null);
if (!$employeeId) {
    $employeeId = $_SESSION['hr3_employee_id'] ?? $_SESSION['employee_id'] ?? null;
}

if (!$employeeId) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);

$currentShiftId = (int)($input['current_shift_id'] ?? 0);
$requestedShiftId = (int)($input['requested_shift_id'] ?? 0);
$requestDate = $input['request_date'] ?? '';
$reason = trim($input['reason'] ?? '');

if (!$currentShiftId || !$requestedShiftId || !$requestDate || !$reason) {
    echo json_encode(['success' => false, 'message' => 'All fields are required']);
    exit;
}

if ($currentShiftId === $requestedShiftId) {
    echo json_encode(['success' => false, 'message' => 'Current and requested shift cannot be the same']);
    exit;
}

// Validate date is in the future
if (strtotime($requestDate) <= strtotime('today')) {
    echo json_encode(['success' => false, 'message' => 'Effective date must be in the future']);
    exit;
}

try {
    // Check if shifts exist
    $stmt = $db->prepare("SELECT COUNT(*) FROM shift_templates WHERE id IN (?, ?)");
    $stmt->execute([$currentShiftId, $requestedShiftId]);
    if ($stmt->fetchColumn() < 2) {
        echo json_encode(['success' => false, 'message' => 'Invalid shift selection']);
        exit;
    }
    
    // Check for existing pending request for same date
    $stmt = $db->prepare("
        SELECT id FROM shift_change_requests 
        WHERE employee_id = ? AND request_date = ? AND status = 'pending'
    ");
    $stmt->execute([$employeeId, $requestDate]);
    if ($stmt->fetch()) {
        echo json_encode(['success' => false, 'message' => 'You already have a pending request for this date']);
        exit;
    }
    
    // Insert the request
    $stmt = $db->prepare("
        INSERT INTO shift_change_requests 
        (employee_id, current_shift_id, requested_shift_id, request_date, reason, status, created_at)
        VALUES (?, ?, ?, ?, ?, 'pending', NOW())
    ");
    $stmt->execute([$employeeId, $currentShiftId, $requestedShiftId, $requestDate, $reason]);
    
    echo json_encode(['success' => true, 'message' => 'Shift change request submitted successfully']);
    
} catch (PDOException $e) {
    error_log('Shift request error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Database error occurred']);
}
