<?php
header('Content-Type: application/json');
require_once __DIR__ . '/../../config/config.php';
if (session_status() === PHP_SESSION_NONE) session_start();

if (!isset($db)) {
    echo json_encode(['success' => true, 'data' => []]);
    exit;
}

$stmt = $db->query("SELECT id, code, name, start_time, end_time, break_minutes, is_night_shift FROM shift_templates ORDER BY start_time");
$list = $stmt->fetchAll(PDO::FETCH_ASSOC);
foreach ($list as &$t) {
    $t['id'] = (int) $t['id'];
    $t['break_minutes'] = (int) $t['break_minutes'];
    $t['is_night_shift'] = (bool) $t['is_night_shift'];
}
echo json_encode(['success' => true, 'data' => $list]);
