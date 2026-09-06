<?php
header('Content-Type: application/json');
require_once __DIR__ . '/../../config/config.php';
if (session_status() === PHP_SESSION_NONE) session_start();

if (!isset($db)) {
    echo json_encode(['success' => true, 'types' => []]);
    exit;
}

$types = [];
try {
    $stmt = $db->query("SELECT id, code, name, max_amount, requires_receipt, approval_route FROM claim_categories ORDER BY name");
    while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $types[] = [
            'id' => (int) $r['id'],
            'code' => $r['code'],
            'name' => $r['name'],
            'max_amount' => $r['max_amount'] ? (float) $r['max_amount'] : null,
            'requires_receipt' => (bool) $r['requires_receipt'],
            'approval_route' => $r['approval_route']
        ];
    }
} catch (PDOException $e) {
    // fallback
}
if (empty($types)) {
    $types = [
        ['id' => 0, 'code' => 'MEAL', 'name' => 'Meals & Allowance', 'max_amount' => 5000, 'requires_receipt' => true, 'approval_route' => 'supervisor'],
        ['id' => 0, 'code' => 'TRANS', 'name' => 'Transport', 'max_amount' => 3000, 'requires_receipt' => true, 'approval_route' => 'supervisor'],
        ['id' => 0, 'code' => 'MED', 'name' => 'Medical Reimbursement', 'max_amount' => 10000, 'requires_receipt' => true, 'approval_route' => 'hr'],
    ];
}
echo json_encode(['success' => true, 'types' => $types]);
