<?php
/**
 * POST: Upload supporting document for leave request
 */
session_start();
require_once __DIR__ . '/../../config/config.php';

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
    header('Location: ' . BASE_URL . '/auth/employee-login.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !isset($_FILES['document']) || !isset($_POST['leave_request_id'])) {
    $_SESSION['error'] = 'Invalid request';
    header('Location: ' . (BASE_URL . '/employee/modules/employee-leave_management.php?view=upload_docs'));
    exit;
}

$leaveRequestId = (int) $_POST['leave_request_id'];
$file = $_FILES['document'];

if ($file['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name'])) {
    $_SESSION['error'] = 'File upload failed';
    header('Location: ' . (BASE_URL . '/employee/modules/employee-leave_management.php?view=upload_docs'));
    exit;
}

$ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
if (!in_array($ext, ['pdf', 'jpg', 'jpeg', 'png'])) {
    $_SESSION['error'] = 'Only PDF, JPG, PNG allowed';
    header('Location: ' . (BASE_URL . '/employee/modules/employee-leave_management.php?view=upload_docs'));
    exit;
}

try {
    $stmt = $db->prepare("SELECT id FROM leave_requests WHERE id = ? AND employee_id = ? LIMIT 1");
    $stmt->execute([$leaveRequestId, $employeeId]);
    if (!$stmt->fetch()) {
        $_SESSION['error'] = 'Leave request not found';
        header('Location: ' . (BASE_URL . '/employee/modules/employee-leave_management.php?view=upload_docs'));
        exit;
    }

    $uploadDir = __DIR__ . '/../../assets/uploads/leave_docs/';
    if (!is_dir($uploadDir)) {
        mkdir($uploadDir, 0755, true);
    }
    $subDir = $uploadDir . $leaveRequestId . '/';
    if (!is_dir($subDir)) {
        mkdir($subDir, 0755, true);
    }
    $newName = 'doc_' . time() . '_' . preg_replace('/[^a-zA-Z0-9._-]/', '_', $file['name']);
    $targetPath = $subDir . $newName;

    if (!move_uploaded_file($file['tmp_name'], $targetPath)) {
        $_SESSION['error'] = 'Failed to save file';
        header('Location: ' . (BASE_URL . '/employee/modules/employee-leave_management.php?view=upload_docs'));
        exit;
    }

    $relativePath = 'assets/uploads/leave_docs/' . $leaveRequestId . '/' . $newName;
    $stmt = $db->prepare("INSERT INTO leave_request_attachments (leave_request_id, file_path, file_name) VALUES (?, ?, ?)");
    $stmt->execute([$leaveRequestId, $relativePath, $file['name']]);

    $_SESSION['success'] = 'Document uploaded successfully';
} catch (PDOException $e) {
    $_SESSION['error'] = 'An error occurred';
}
header('Location: ' . (BASE_URL . '/employee/modules/employee-leave_management.php?view=upload_docs'));
exit;
