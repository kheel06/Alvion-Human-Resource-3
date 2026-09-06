<?php
/**
 * POST: Upload receipts for an existing claim (employee).
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
    $_SESSION['error'] = 'Please log in again.';
    header('Location: ' . BASE_URL . '/auth/employee-login.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !isset($_POST['claim_id'])) {
    $_SESSION['error'] = 'Invalid request.';
    header('Location: ' . BASE_URL . '/employee/modules/employee-claims.php?view=upload_receipts');
    exit;
}

$claimId = (int) $_POST['claim_id'];
$files = $_FILES['receipts'] ?? [];
if (empty($files['name'][0])) {
    $_SESSION['error'] = 'Please select at least one file.';
    header('Location: ' . BASE_URL . '/employee/modules/employee-claims.php?view=upload_receipts');
    exit;
}

try {
    $stmt = $db->prepare("SELECT id FROM claims WHERE id = ? AND employee_id = ? LIMIT 1");
    $stmt->execute([$claimId, $employeeId]);
    if (!$stmt->fetch()) {
        $_SESSION['error'] = 'Claim not found or access denied.';
        header('Location: ' . BASE_URL . '/employee/modules/employee-claims.php?view=upload_receipts');
        exit;
    }

    $uploadDir = __DIR__ . '/../../assets/uploads/claim_receipts/';
    if (!is_dir($uploadDir)) {
        mkdir($uploadDir, 0755, true);
    }
    $count = 0;
    foreach ($files['name'] as $key => $name) {
        if ($files['error'][$key] !== UPLOAD_ERR_OK || !is_uploaded_file($files['tmp_name'][$key])) {
            continue;
        }
        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        if (!in_array($ext, ['pdf', 'jpg', 'jpeg', 'png', 'gif'])) {
            continue;
        }
        $newName = 'claim_' . $claimId . '_' . time() . '_' . $key . '.' . $ext;
        $targetPath = $uploadDir . $newName;
        if (move_uploaded_file($files['tmp_name'][$key], $targetPath)) {
            $relativePath = 'assets/uploads/claim_receipts/' . $newName;
            $ins = $db->prepare("INSERT INTO claim_attachments (claim_id, file_path, file_name, created_at) VALUES (?, ?, ?, NOW())");
            $ins->execute([$claimId, $relativePath, $name]);
            $count++;
        }
    }
    if ($count > 0) {
        $_SESSION['success'] = $count . ' receipt(s) uploaded successfully.';
    } else {
        $_SESSION['error'] = 'No valid files uploaded. Allowed: PDF, JPG, PNG, GIF.';
    }
} catch (PDOException $e) {
    error_log('Claim upload receipts: ' . $e->getMessage());
    $_SESSION['error'] = 'An error occurred.';
}
header('Location: ' . BASE_URL . '/employee/modules/employee-claims.php?view=upload_receipts');
exit;
