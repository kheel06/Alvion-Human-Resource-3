<?php
require_once __DIR__ . '/../../config/config.php';
requireAuth();
checkRole(['staff', 'staff supervisor', 'super admin']);

$page_title = 'Team Attendance';
$view = $_GET['view'] ?? 'logs';

// Handle correction approve/reject
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'], $_POST['correction_id']) && isset($db)) {
    $correctionId = (int)$_POST['correction_id'];
    $action = $_POST['action'] === 'approve' ? 'approved' : 'rejected';
    $stmt = $db->prepare("UPDATE timesheet_correction_requests SET status = ?, reviewed_at = NOW() WHERE id = ? AND status = 'pending'");
    $stmt->execute([$action, $correctionId]);
    header('Location: ' . $_SERVER['PHP_SELF'] . '?view=corrections');
    exit;
}

$currentEmpId = getCurrentEmployeeId($db);
$staffUnitId = null;
if ($currentEmpId && isset($db)) {
    $stmt = $db->prepare("SELECT unit_id FROM employees WHERE id = ?");
    $stmt->execute([$currentEmpId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    $staffUnitId = $row ? ($row['unit_id'] ?? null) : null;
}
// If no unit, supervisor may see all (optional): leave $staffUnitId null and don't filter by unit in SQL (or filter 1=0 for safety). We filter by unit when set.
$from = $_GET['from'] ?? date('Y-m-d', strtotime('-7 days'));
$to = $_GET['to'] ?? date('Y-m-d');

$teamLogs = [];
$exceptions = [];
$corrections = [];
$error = '';

if (isset($db)) {
    $unitCondition = $staffUnitId ? " AND e.unit_id = " . (int)$staffUnitId : " AND 1=1 ";
    try {
        if ($view === 'logs') {
            $check = $db->query("SHOW TABLES LIKE 'attendance_logs'");
            if ($check && $check->rowCount() > 0) {
                $stmt = $db->prepare("
                    SELECT al.log_time, al.log_type, al.source,
                           e.employee_number, e.first_name, e.last_name
                    FROM attendance_logs al
                    JOIN employees e ON al.employee_id = e.id
                    WHERE DATE(al.log_time) BETWEEN :from_date AND :to_date
                    {$unitCondition}
                    ORDER BY al.log_time DESC
                    LIMIT 300
                ");
                $stmt->execute([':from_date' => $from, ':to_date' => $to]);
                $teamLogs = $stmt->fetchAll(PDO::FETCH_ASSOC);
            }
        } elseif ($view === 'exceptions') {
            $check = $db->query("SHOW TABLES LIKE 'attendance_exceptions'");
            if ($check && $check->rowCount() > 0) {
                $stmt = $db->prepare("
                    SELECT ae.id, ae.employee_id, ae.log_date, ae.exception_type, ae.details, ae.status, ae.created_at,
                           e.employee_number, e.first_name, e.last_name
                    FROM attendance_exceptions ae
                    JOIN employees e ON ae.employee_id = e.id
                    WHERE ae.log_date BETWEEN :from_date AND :to_date
                    {$unitCondition}
                    ORDER BY ae.log_date DESC, ae.created_at DESC
                    LIMIT 100
                ");
                $stmt->execute([':from_date' => $from, ':to_date' => $to]);
                $exceptions = $stmt->fetchAll(PDO::FETCH_ASSOC);
            }
        } elseif ($view === 'corrections') {
            $check = $db->query("SHOW TABLES LIKE 'timesheet_correction_requests'");
            if ($check && $check->rowCount() > 0) {
                $stmt = $db->prepare("
                    SELECT tcr.id, tcr.employee_id, tcr.date, tcr.requested_time_in, tcr.requested_time_out, tcr.reason, tcr.status, tcr.created_at,
                           e.employee_number, e.first_name, e.last_name
                    FROM timesheet_correction_requests tcr
                    JOIN employees e ON tcr.employee_id = e.id
                    WHERE tcr.status = 'pending'
                    {$unitCondition}
                    ORDER BY tcr.created_at DESC
                    LIMIT 50
                ");
                $stmt->execute();
                $corrections = $stmt->fetchAll(PDO::FETCH_ASSOC);
            }
        }
    } catch (PDOException $e) {
        error_log('Staff team attendance error: ' . $e->getMessage());
        $error = 'Failed to load data.';
    }
}

include __DIR__ . '/../../includes/header.php';
?>

<div class="mb-6">
    <h1 class="text-2xl font-semibold text-gray-900 dark:text-white">Team Attendance</h1>
    <p class="mt-1 text-sm text-gray-600 dark:text-gray-400">
        <?php if ($view === 'logs') echo 'Department biometric logs (time in/out).'; ?>
        <?php if ($view === 'exceptions') echo 'Biometric exceptions (missing in/out, etc.).'; ?>
        <?php if ($view === 'corrections') echo 'Approve or reject attendance correction requests.'; ?>
    </p>
</div>

<?php if ($error): ?>
    <div class="mb-4 bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-800 text-red-800 dark:text-red-200 px-4 py-3 rounded-lg text-sm">
        <?php echo htmlspecialchars($error); ?>
    </div>
<?php endif; ?>

<ul class="flex gap-2 mb-4 border-b border-gray-200 dark:border-gray-700">
    <li>
        <a href="?view=logs&from=<?php echo urlencode($from); ?>&to=<?php echo urlencode($to); ?>" class="inline-block px-4 py-2 text-sm font-medium rounded-t <?php echo $view === 'logs' ? 'bg-white dark:bg-gray-800 text-gray-900 dark:text-white border border-b-0 border-gray-200 dark:border-gray-700' : 'text-gray-600 dark:text-gray-400 hover:text-gray-900 dark:hover:text-white'; ?>">
            Department Biometric Logs
        </a>
    </li>
    <li>
        <a href="?view=exceptions&from=<?php echo urlencode($from); ?>&to=<?php echo urlencode($to); ?>" class="inline-block px-4 py-2 text-sm font-medium rounded-t <?php echo $view === 'exceptions' ? 'bg-white dark:bg-gray-800 text-gray-900 dark:text-white border border-b-0 border-gray-200 dark:border-gray-700' : 'text-gray-600 dark:text-gray-400 hover:text-gray-900 dark:hover:text-white'; ?>">
            Biometric Exceptions
        </a>
    </li>
    <li>
        <a href="?view=corrections" class="inline-block px-4 py-2 text-sm font-medium rounded-t <?php echo $view === 'corrections' ? 'bg-white dark:bg-gray-800 text-gray-900 dark:text-white border border-b-0 border-gray-200 dark:border-gray-700' : 'text-gray-600 dark:text-gray-400 hover:text-gray-900 dark:hover:text-white'; ?>">
            Approve Attendance Corrections
        </a>
    </li>
</ul>

<?php if ($view === 'logs'): ?>
    <div class="bg-white dark:bg-gray-800 rounded-xl shadow-sm border border-gray-100 dark:border-gray-700">
        <div class="px-5 py-4 border-b border-gray-100 dark:border-gray-700">
            <form method="get" class="flex items-center gap-3">
                <input type="hidden" name="view" value="logs">
                <input type="date" name="from" value="<?php echo htmlspecialchars($from); ?>" class="text-sm border border-gray-300 dark:border-gray-600 rounded px-3 py-1.5 dark:bg-gray-700 dark:text-white">
                <span class="text-gray-500">to</span>
                <input type="date" name="to" value="<?php echo htmlspecialchars($to); ?>" class="text-sm border border-gray-300 dark:border-gray-600 rounded px-3 py-1.5 dark:bg-gray-700 dark:text-white">
                <button type="submit" class="px-3 py-1.5 text-sm bg-slate-700 text-white rounded hover:bg-slate-600">Apply</button>
            </form>
        </div>
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                <thead class="bg-slate-800 text-slate-200">
                    <tr>
                        <th class="px-6 py-3 text-left text-xs font-medium uppercase">Log Time</th>
                        <th class="px-6 py-3 text-left text-xs font-medium uppercase">Employee</th>
                        <th class="px-6 py-3 text-left text-xs font-medium uppercase">Type</th>
                        <th class="px-6 py-3 text-left text-xs font-medium uppercase">Source</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-200 dark:divide-gray-700">
                    <?php if (empty($teamLogs)): ?>
                        <tr><td colspan="4" class="px-6 py-8 text-center text-sm text-gray-500">No logs in this period for your team.</td></tr>
                    <?php else: ?>
                        <?php foreach ($teamLogs as $r): ?>
                            <tr class="text-slate-200">
                                <td class="px-6 py-3 text-sm"><?php echo date('M d, Y H:i', strtotime($r['log_time'])); ?></td>
                                <td class="px-6 py-3 text-sm"><?php echo htmlspecialchars($r['employee_number'] . ' – ' . trim($r['first_name'] . ' ' . $r['last_name'])); ?></td>
                                <td class="px-6 py-3 text-sm"><?php echo htmlspecialchars(ucfirst(str_replace('_', ' ', $r['log_type'] ?? ''))); ?></td>
                                <td class="px-6 py-3 text-sm"><?php echo htmlspecialchars($r['source'] ?? '—'); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
<?php endif; ?>

<?php if ($view === 'exceptions'): ?>
    <div class="bg-white dark:bg-gray-800 rounded-xl shadow-sm border border-gray-100 dark:border-gray-700">
        <div class="px-5 py-4 border-b border-gray-100 dark:border-gray-700">
            <form method="get" class="flex items-center gap-3">
                <input type="hidden" name="view" value="exceptions">
                <input type="date" name="from" value="<?php echo htmlspecialchars($from); ?>" class="text-sm border border-gray-300 dark:border-gray-600 rounded px-3 py-1.5 dark:bg-gray-700 dark:text-white">
                <input type="date" name="to" value="<?php echo htmlspecialchars($to); ?>" class="text-sm border border-gray-300 dark:border-gray-600 rounded px-3 py-1.5 dark:bg-gray-700 dark:text-white">
                <button type="submit" class="px-3 py-1.5 text-sm bg-slate-700 text-white rounded hover:bg-slate-600">Apply</button>
            </form>
        </div>
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                <thead class="bg-slate-800 text-slate-200">
                    <tr>
                        <th class="px-6 py-3 text-left text-xs font-medium uppercase">Date</th>
                        <th class="px-6 py-3 text-left text-xs font-medium uppercase">Employee</th>
                        <th class="px-6 py-3 text-left text-xs font-medium uppercase">Type</th>
                        <th class="px-6 py-3 text-left text-xs font-medium uppercase">Details</th>
                        <th class="px-6 py-3 text-left text-xs font-medium uppercase">Status</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-200 dark:divide-gray-700">
                    <?php if (empty($exceptions)): ?>
                        <tr><td colspan="5" class="px-6 py-8 text-center text-sm text-gray-500">No exceptions in this period.</td></tr>
                    <?php else: ?>
                        <?php foreach ($exceptions as $ex): ?>
                            <tr class="text-slate-200">
                                <td class="px-6 py-3 text-sm"><?php echo date('M d, Y', strtotime($ex['log_date'])); ?></td>
                                <td class="px-6 py-3 text-sm"><?php echo htmlspecialchars($ex['employee_number'] . ' – ' . trim($ex['first_name'] . ' ' . $ex['last_name'])); ?></td>
                                <td class="px-6 py-3 text-sm"><?php echo htmlspecialchars(str_replace('_', ' ', $ex['exception_type'] ?? '')); ?></td>
                                <td class="px-6 py-3 text-sm"><?php echo htmlspecialchars($ex['details'] ?? '—'); ?></td>
                                <td class="px-6 py-3 text-sm"><?php echo htmlspecialchars($ex['status'] ?? '—'); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
<?php endif; ?>

<?php if ($view === 'corrections'): ?>
    <div class="bg-white dark:bg-gray-800 rounded-xl shadow-sm border border-gray-100 dark:border-gray-700">
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                <thead class="bg-slate-800 text-slate-200">
                    <tr>
                        <th class="px-6 py-3 text-left text-xs font-medium uppercase">Employee</th>
                        <th class="px-6 py-3 text-left text-xs font-medium uppercase">Date</th>
                        <th class="px-6 py-3 text-left text-xs font-medium uppercase">Requested In</th>
                        <th class="px-6 py-3 text-left text-xs font-medium uppercase">Requested Out</th>
                        <th class="px-6 py-3 text-left text-xs font-medium uppercase">Reason</th>
                        <th class="px-6 py-3 text-left text-xs font-medium uppercase">Action</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-200 dark:divide-gray-700">
                    <?php if (empty($corrections)): ?>
                        <tr><td colspan="6" class="px-6 py-8 text-center text-sm text-gray-500">No pending correction requests.</td></tr>
                    <?php else: ?>
                        <?php foreach ($corrections as $cr): ?>
                            <tr class="text-slate-200">
                                <td class="px-6 py-3 text-sm"><?php echo htmlspecialchars($cr['employee_number'] . ' – ' . trim($cr['first_name'] . ' ' . $cr['last_name'])); ?></td>
                                <td class="px-6 py-3 text-sm"><?php echo date('M d, Y', strtotime($cr['date'])); ?></td>
                                <td class="px-6 py-3 text-sm"><?php echo $cr['requested_time_in'] ? date('H:i', strtotime($cr['requested_time_in'])) : '—'; ?></td>
                                <td class="px-6 py-3 text-sm"><?php echo $cr['requested_time_out'] ? date('H:i', strtotime($cr['requested_time_out'])) : '—'; ?></td>
                                <td class="px-6 py-3 text-sm"><?php echo htmlspecialchars($cr['reason'] ?? '—'); ?></td>
                                <td class="px-6 py-3 text-sm">
                                    <form method="post" action="?view=corrections" class="inline flex gap-1">
                                        <input type="hidden" name="correction_id" value="<?php echo (int)$cr['id']; ?>">
                                        <input type="hidden" name="action" value="approve">
                                        <button type="submit" class="text-emerald-400 hover:text-emerald-300 text-xs font-medium">Approve</button>
                                    </form>
                                    <form method="post" action="?view=corrections" class="inline">
                                        <input type="hidden" name="correction_id" value="<?php echo (int)$cr['id']; ?>">
                                        <input type="hidden" name="action" value="reject">
                                        <button type="submit" class="text-red-400 hover:text-red-300 text-xs font-medium">Reject</button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
<?php endif; ?>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
