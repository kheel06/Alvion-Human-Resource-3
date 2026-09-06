<?php
/**
 * POST - Create claim. JSON: claim_type (category code), amount, expense_date, purpose.
 */
header('Content-Type: application/json');
require_once __DIR__ . '/../../config/config.php';
if (session_status() === PHP_SESSION_NONE) session_start();

$employeeId = function_exists('getCurrentEmployeeId') ? getCurrentEmployeeId($db ?? null) : null;
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
if (!$employeeId) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Session expired.']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
if (!$input && !empty($_POST)) {
    $input = [
        'claim_type' => $_POST['claim_type'] ?? '',
        'amount' => $_POST['amount'] ?? 0,
        'description' => $_POST['description'] ?? $_POST['purpose'] ?? ''
    ];
}
$input = $input ?: [];

$claimType = trim($input['claim_type'] ?? '');
$amount = (float) ($input['amount'] ?? 0);
$description = trim($input['description'] ?? $input['purpose'] ?? '');

if (!$claimType || $amount <= 0) {
    echo json_encode(['success' => false, 'message' => 'Claim category and amount (greater than 0) are required.']);
    exit;
}
if (!isset($db)) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database error']);
    exit;
}

// Resolve category_id from claim_categories
$categoryId = null;
$maxAmount = null;
try {
    if ($db->query("SHOW TABLES LIKE 'claim_categories'")->rowCount() > 0) {
        $stmt = $db->prepare("SELECT id, max_amount FROM claim_categories WHERE code = ? LIMIT 1");
        $stmt->execute([$claimType]);
        $cat = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($cat) {
            $categoryId = (int) $cat['id'];
            $maxAmount = $cat['max_amount'] !== null ? (float) $cat['max_amount'] : null;
        }
    }
} catch (PDOException $e) {
    // ignore
}

if ($maxAmount !== null && $amount > $maxAmount) {
    echo json_encode(['success' => false, 'message' => 'Amount exceeds limit of PHP ' . number_format($maxAmount, 2) . ' for this claim type.']);
    exit;
}

try {
    $stmt = $db->prepare("
        INSERT INTO claims (employee_id, category_id, amount, currency, description, status, submitted_at, created_at, updated_at)
        VALUES (?, ?, ?, 'PHP', ?, 'submitted', NOW(), NOW(), NOW())
    ");
    $stmt->execute([$employeeId, $categoryId, $amount, $description ?: null]);
    $claimId = $db->lastInsertId();

    // Audit log
    try {
        $db->prepare("
            INSERT INTO audit_logs (employee_id, action, table_name, record_id, new_values, ip_address, user_agent)
            VALUES (?, 'submit_claim', 'claims', ?, ?, ?, ?)
        ")->execute([
            $employeeId, $claimId,
            json_encode(['category' => $claimType, 'amount' => $amount]),
            $_SERVER['REMOTE_ADDR'] ?? null, $_SERVER['HTTP_USER_AGENT'] ?? null
        ]);
    } catch (PDOException $e) { /* ignore audit errors */ }

    echo json_encode(['success' => true, 'message' => 'Claim submitted.', 'claim_id' => (int) $claimId]);
} catch (PDOException $e) {
    error_log('Claim submit error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Failed to submit claim.']);
}
