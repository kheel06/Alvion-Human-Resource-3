<?php
/**
 * GET - Admin list of pending/endorsed claims. ?unit_id=&category=&from=&to=
 */
header('Content-Type: application/json');
require_once __DIR__ . '/../../config/config.php';
if (session_status() === PHP_SESSION_NONE) session_start();

$role = $_SESSION['role_name'] ?? $_SESSION['user_role'] ?? 'employee';
$normalized = strtolower(trim($role));
if (!in_array($normalized, ['admin', 'super admin', 'staff', 'hr_admin', 'finance', 'supervisor', 'unit_head'], true)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Forbidden']);
    exit;
}

$unitId = isset($_GET['unit_id']) ? (int) $_GET['unit_id'] : null;
$category = $_GET['category'] ?? '';
$from = $_GET['from'] ?? '';
$to = $_GET['to'] ?? '';

if (!isset($db)) {
    echo json_encode(['success' => true, 'data' => [], 'total' => 0]);
    exit;
}

$sql = "SELECT c.id, c.employee_id, c.category_id, c.amount, c.currency, c.description, c.status, c.submitted_at,
               cc.name as category_name, cc.code as category_code,
               e.first_name, e.last_name, e.employee_number, e.unit_id
        FROM claims c
        JOIN employees e ON c.employee_id = e.id
        LEFT JOIN claim_categories cc ON c.category_id = cc.id
        WHERE c.status IN ('submitted','endorsed')";
$params = [];
if ($unitId) {
    $sql .= " AND e.unit_id = :unit_id";
    $params[':unit_id'] = $unitId;
}
if ($category !== '') {
    $sql .= " AND cc.code = :cat_code";
    $params[':cat_code'] = $category;
}
if ($from !== '') {
    $sql .= " AND c.submitted_at >= :from_date";
    $params[':from_date'] = $from . ' 00:00:00';
}
if ($to !== '') {
    $sql .= " AND c.submitted_at <= :to_date";
    $params[':to_date'] = $to . ' 23:59:59';
}
$sql .= " ORDER BY c.submitted_at ASC";
$stmt = $db->prepare($sql);
$stmt->execute($params);
$data = $stmt->fetchAll(PDO::FETCH_ASSOC);
echo json_encode(['success' => true, 'data' => $data, 'total' => count($data)]);
