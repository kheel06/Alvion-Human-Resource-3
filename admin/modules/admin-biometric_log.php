<?php
require_once __DIR__ . '/../../config/config.php';
requireAuth();
checkRole(['admin', 'super admin', 'supervisor', 'unit_head', 'hr_admin', 'finance']);

$page_title = 'Biometric Log';
$today = date('Y-m-d');
$from = isset($_GET['from']) ? $_GET['from'] : $today;
$to = isset($_GET['to']) ? $_GET['to'] : $today;
$employeeFilter = trim($_GET['employee'] ?? '');
// When viewing today only: default to "Time In" so punch-ins match Present on Attendance
$isTodayOnly = ($from === $today && $to === $today);
$logType = isset($_GET['log_type']) ? $_GET['log_type'] : ($isTodayOnly ? 'in' : '');

$logs = [];
$error = '';

if (isset($db)) {
    try {
        $check = $db->query("SHOW TABLES LIKE 'attendance_logs'");
        if ($check && $check->rowCount() > 0) {
            $sql = "
                SELECT al.id, al.log_time, al.log_type, al.source, al.ip_address,
                       e.employee_number, e.first_name, e.last_name
                FROM attendance_logs al
                JOIN employees e ON al.employee_id = e.id
                WHERE DATE(al.log_time) BETWEEN :from_date AND :to_date
            ";
            $params = [':from_date' => $from, ':to_date' => $to];
            if ($employeeFilter !== '') {
                $sql .= " AND (e.employee_number LIKE :emp OR e.first_name LIKE :emp2 OR e.last_name LIKE :emp3)";
                $like = '%' . $employeeFilter . '%';
                $params[':emp'] = $like;
                $params[':emp2'] = $like;
                $params[':emp3'] = $like;
            }
            if (in_array($logType, ['in', 'out', 'break_start', 'break_end'])) {
                $sql .= " AND al.log_type = :log_type";
                $params[':log_type'] = $logType;
            }
            // Single-day view: show Time In first (same people as Present on Attendance), then by time
            if ($from === $to) {
                $sql .= " ORDER BY al.log_type ASC, al.log_time ASC LIMIT 500";
            } else {
                $sql .= " ORDER BY al.log_time DESC LIMIT 500";
            }
            $stmt = $db->prepare($sql);
            $stmt->execute($params);
            $logs = $stmt->fetchAll(PDO::FETCH_ASSOC);
            foreach ($logs as &$r) {
                $s = strtolower(trim($r['source'] ?? ''));
                if (in_array($s, ['qr', 'mobile', 'biometric'])) {
                    $r['source'] = 'Biometric';
                }
            }
            unset($r);
        }
    } catch (PDOException $e) {
        error_log('Biometric log error: ' . $e->getMessage());
        $error = 'Failed to load biometric logs.';
    }
}

include __DIR__ . '/../../includes/header.php';
?>

<div class="mb-6 flex flex-wrap items-start justify-between gap-3">
    <div>
        <h1 class="text-2xl font-semibold text-gray-900 dark:text-white">Biometric Log</h1>
        <p class="mt-1 text-sm text-gray-600 dark:text-gray-400">View and export attendance punch logs (time in/out, break) from biometric devices and web.</p>
    </div>
    <a href="<?php echo htmlspecialchars(BASE_URL ?? ''); ?>/admin/modules/admin-attendance.php?date=<?php echo urlencode($to); ?>" class="text-sm text-indigo-600 dark:text-indigo-400 hover:underline whitespace-nowrap">View attendance summary for <?php echo $from === $to ? 'this date' : 'period'; ?> →</a>
</div>

<?php if ($error): ?>
    <div class="mb-4 bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-800 text-red-800 dark:text-red-200 px-4 py-3 rounded-lg text-sm">
        <?php echo htmlspecialchars($error); ?>
    </div>
<?php endif; ?>

<?php if ($isTodayOnly && $logType === 'in'): ?>
    <div class="mb-4 flex flex-wrap items-center gap-2 rounded-lg border border-emerald-200 dark:border-emerald-800 bg-emerald-50 dark:bg-emerald-900/20 px-4 py-3 text-sm text-emerald-800 dark:text-emerald-200">
        <span>Time In for today = employees marked <strong>Present</strong> on Attendance.</span>
        <a href="<?php echo htmlspecialchars(BASE_URL ?? ''); ?>/admin/modules/admin-attendance.php?date=<?php echo urlencode($today); ?>" class="font-medium underline">View Attendance →</a>
    </div>
<?php endif; ?>

<div class="bg-white dark:bg-gray-800 rounded-xl shadow-sm border border-gray-100 dark:border-gray-700 mb-6">
    <div class="px-5 py-4 border-b border-gray-100 dark:border-gray-700 flex flex-wrap items-center gap-4">
        <form method="get" action="" class="flex flex-wrap items-center gap-3">
            <input type="date" name="from" value="<?php echo htmlspecialchars($from); ?>" class="text-sm border border-gray-300 dark:border-gray-600 rounded px-3 py-1.5 dark:bg-gray-700 dark:text-white">
            <span class="text-gray-500">to</span>
            <input type="date" name="to" value="<?php echo htmlspecialchars($to); ?>" class="text-sm border border-gray-300 dark:border-gray-600 rounded px-3 py-1.5 dark:bg-gray-700 dark:text-white">
            <input type="text" name="employee" value="<?php echo htmlspecialchars($employeeFilter); ?>" placeholder="Employee no or name" class="text-sm border border-gray-300 dark:border-gray-600 rounded px-3 py-1.5 dark:bg-gray-700 dark:text-white w-40">
            <select name="log_type" class="text-sm border border-gray-300 dark:border-gray-600 rounded px-3 py-1.5 dark:bg-gray-700 dark:text-white">
                <option value="">All types</option>
                <option value="in" <?php echo $logType === 'in' ? 'selected' : ''; ?>>Time In</option>
                <option value="out" <?php echo $logType === 'out' ? 'selected' : ''; ?>>Time Out</option>
                <option value="break_start" <?php echo $logType === 'break_start' ? 'selected' : ''; ?>>Break Start</option>
                <option value="break_end" <?php echo $logType === 'break_end' ? 'selected' : ''; ?>>Break End</option>
            </select>
            <button type="submit" class="px-3 py-1.5 text-sm bg-gray-800 dark:bg-gray-600 text-white rounded hover:bg-gray-700">Apply</button>
        </form>
        <div class="ml-auto flex gap-2">
            <?php
            $exportParams = http_build_query(['type' => 'attendance_logs', 'format' => 'csv', 'from' => $from, 'to' => $to]);
            $exportUrl = BASE_URL . '/api/reports/export.php?' . $exportParams;
            ?>
            <a href="<?php echo htmlspecialchars($exportUrl); ?>" class="px-3 py-1.5 text-sm border border-gray-300 dark:border-gray-600 rounded hover:bg-gray-50 dark:hover:bg-gray-700">Export CSV</a>
            <a href="<?php echo htmlspecialchars($exportUrl . '&format=pdf'); ?>" class="px-3 py-1.5 text-sm border border-gray-300 dark:border-gray-600 rounded hover:bg-gray-50 dark:hover:bg-gray-700">Export PDF</a>
        </div>
    </div>
    <div class="overflow-x-auto">
        <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
            <thead class="bg-gray-50 dark:bg-gray-700">
                <tr>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase">Log Time</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase">Employee No</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase">Name</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase">Type</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase">Source</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase">IP Address</th>
                </tr>
            </thead>
            <tbody class="bg-white dark:bg-gray-800 divide-y divide-gray-200 dark:divide-gray-700">
                <?php if (empty($logs)): ?>
                    <tr>
                        <td colspan="6" class="px-6 py-8 text-center text-sm text-gray-500">No biometric logs found for the selected period.</td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($logs as $row): ?>
                        <tr>
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900 dark:text-white">
                                <?php echo date('M d, Y H:i:s', strtotime($row['log_time'])); ?>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900 dark:text-white">
                                <?php echo htmlspecialchars($row['employee_number'] ?? ''); ?>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900 dark:text-white">
                                <?php echo htmlspecialchars(trim(($row['first_name'] ?? '') . ' ' . ($row['last_name'] ?? ''))); ?>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm">
                                <span class="px-2 py-0.5 rounded text-xs font-medium <?php echo $row['log_type'] === 'in' ? 'bg-emerald-100 text-emerald-800 dark:bg-emerald-900/30 dark:text-emerald-300' : ($row['log_type'] === 'out' ? 'bg-amber-100 text-amber-800 dark:bg-amber-900/30 dark:text-amber-300' : 'bg-gray-100 text-gray-800 dark:bg-gray-700 dark:text-gray-300'); ?>">
                                    <?php echo htmlspecialchars(ucfirst(str_replace('_', ' ', $row['log_type'] ?? ''))); ?>
                                </span>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-600 dark:text-gray-400">
                                <?php echo htmlspecialchars($row['source'] ?? '—'); ?>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                <?php echo htmlspecialchars($row['ip_address'] ?? '—'); ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
    <?php if (count($logs) >= 500): ?>
        <p class="px-5 py-2 text-xs text-gray-500">Showing latest 500 records. Use date range or filters to narrow results.</p>
    <?php endif; ?>
</div>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
