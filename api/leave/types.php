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
    $stmt = $db->query("SELECT id, code, name, is_paid, requires_approval, max_days_per_year, carry_over FROM leave_types ORDER BY code");
    while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $types[] = [
            'id' => (int) $r['id'],
            'code' => $r['code'],
            'name' => $r['name'],
            'is_paid' => (bool) $r['is_paid'],
            'requires_approval' => (bool) $r['requires_approval'],
            'max_days_per_year' => (int) ($r['max_days_per_year'] ?? 15),
        ];
    }
} catch (PDOException $e) { /* fallback */ }

if (empty($types)) {
    $types = [
        ['id' => 0, 'code' => 'VL', 'name' => 'Vacation Leave', 'is_paid' => true, 'requires_approval' => true, 'max_days_per_year' => 15],
        ['id' => 0, 'code' => 'SL', 'name' => 'Sick Leave', 'is_paid' => true, 'requires_approval' => true, 'max_days_per_year' => 15],
        ['id' => 0, 'code' => 'EL', 'name' => 'Emergency Leave', 'is_paid' => true, 'requires_approval' => true, 'max_days_per_year' => 5],
        ['id' => 0, 'code' => 'ML', 'name' => 'Maternity Leave', 'is_paid' => true, 'requires_approval' => true, 'max_days_per_year' => 105],
        ['id' => 0, 'code' => 'PL', 'name' => 'Paternity Leave', 'is_paid' => true, 'requires_approval' => true, 'max_days_per_year' => 7],
        ['id' => 0, 'code' => 'SPL', 'name' => 'Solo Parent Leave', 'is_paid' => true, 'requires_approval' => true, 'max_days_per_year' => 7],
    ];
}

echo json_encode(['success' => true, 'types' => $types]);
