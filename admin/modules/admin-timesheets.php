<?php
require_once __DIR__ . '/../../config/config.php';
requireAuth();
checkRole(['admin', 'super admin', 'supervisor', 'unit_head', 'hr_admin', 'finance']);

$page_title = 'Timesheets';
$statusFilter = $_GET['status'] ?? '';
$from = $_GET['from'] ?? '';
$to = $_GET['to'] ?? '';
$message = '';
$error = '';

// Handle approve/reject
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($db)) {
    $action = $_POST['action'] ?? '';
    $timesheetId = (int)($_POST['timesheet_id'] ?? 0);
    if ($timesheetId && $action === 'approve') {
        try {
            $stmt = $db->prepare("
                UPDATE timesheets
                SET status = 'approved', updated_at = NOW()
                WHERE id = :id AND status IN ('submitted', 'endorsed')
            ");
            $stmt->bindValue(':id', $timesheetId, PDO::PARAM_INT);
            $stmt->execute();
            if ($stmt->rowCount() > 0) {
                $message = 'Timesheet approved.';
                $_SESSION['success'] = $message;
            } else {
                $error = 'Timesheet not found or already processed.';
            }
        } catch (PDOException $e) {
            error_log('Timesheet approve error: ' . $e->getMessage());
            $error = 'Failed to approve timesheet.';
        }
    }
    if ($message) {
        header('Location: ' . $_SERVER['PHP_SELF'] . '?' . http_build_query(array_filter(['status' => $statusFilter, 'from' => $from, 'to' => $to])));
        exit;
    }
}

if (isset($_SESSION['success'])) {
    $message = $_SESSION['success'];
    unset($_SESSION['success']);
}
if (isset($_SESSION['error'])) {
    $error = $_SESSION['error'];
    unset($_SESSION['error']);
}

$timesheets = [];
if (isset($db)) {
    try {
        $check = $db->query("SHOW TABLES LIKE 'timesheets'");
        if ($check && $check->rowCount() > 0) {
            $sql = "
                SELECT t.id, t.employee_id, t.period_start, t.period_end, t.total_hours, t.total_ot_hours, t.total_nd_hours, t.status, t.created_at,
                       e.employee_number, e.first_name, e.last_name,
                       u.name as unit_name
                FROM timesheets t
                JOIN employees e ON t.employee_id = e.id
                LEFT JOIN units u ON t.unit_id = u.id
                WHERE 1=1
            ";
            $params = [];
            if (in_array($statusFilter, ['draft', 'submitted', 'endorsed', 'approved', 'locked'])) {
                $sql .= " AND t.status = :status";
                $params[':status'] = $statusFilter;
            }
            if ($from !== '') {
                $sql .= " AND t.period_end >= :from";
                $params[':from'] = $from;
            }
            if ($to !== '') {
                $sql .= " AND t.period_start <= :to";
                $params[':to'] = $to;
            }
            $sql .= " ORDER BY t.period_start DESC, e.last_name LIMIT 200";
            $stmt = $db->prepare($sql);
            $stmt->execute($params);
            $timesheets = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }
    } catch (PDOException $e) {
        error_log('Admin timesheets error: ' . $e->getMessage());
        $error = 'Failed to load timesheets.';
    }
}

include __DIR__ . '/../../includes/header.php';
?>

<div class="mb-6">
    <h1 class="text-2xl font-semibold text-gray-900 dark:text-white">Timesheets</h1>
    <p class="mt-1 text-sm text-gray-600 dark:text-gray-400">Review, approve, and export employee timesheets by pay period.</p>
</div>

<?php if ($message): ?>
    <div class="mb-4 bg-emerald-50 dark:bg-emerald-900/20 border border-emerald-200 dark:border-emerald-800 text-emerald-800 dark:text-emerald-200 px-4 py-3 rounded-lg text-sm">
        <?php echo htmlspecialchars($message); ?>
    </div>
<?php endif; ?>
<?php if ($error): ?>
    <div class="mb-4 bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-800 text-red-800 dark:text-red-200 px-4 py-3 rounded-lg text-sm">
        <?php echo htmlspecialchars($error); ?>
    </div>
<?php endif; ?>

<div class="bg-white dark:bg-gray-800 rounded-xl shadow-sm border border-gray-100 dark:border-gray-700 mb-6">
    <div class="px-5 py-4 border-b border-gray-100 dark:border-gray-700 flex flex-wrap items-center gap-4">
        <form method="get" action="" class="flex flex-wrap items-center gap-3">
            <select name="status" class="text-sm border border-gray-300 dark:border-gray-600 rounded px-3 py-1.5 dark:bg-gray-700 dark:text-white">
                <option value="">All statuses</option>
                <option value="draft" <?php echo $statusFilter === 'draft' ? 'selected' : ''; ?>>Draft</option>
                <option value="submitted" <?php echo $statusFilter === 'submitted' ? 'selected' : ''; ?>>Submitted</option>
                <option value="endorsed" <?php echo $statusFilter === 'endorsed' ? 'selected' : ''; ?>>Endorsed</option>
                <option value="approved" <?php echo $statusFilter === 'approved' ? 'selected' : ''; ?>>Approved</option>
                <option value="locked" <?php echo $statusFilter === 'locked' ? 'selected' : ''; ?>>Locked</option>
            </select>
            <input type="date" name="from" value="<?php echo htmlspecialchars($from); ?>" placeholder="Period from" class="text-sm border border-gray-300 dark:border-gray-600 rounded px-3 py-1.5 dark:bg-gray-700 dark:text-white">
            <input type="date" name="to" value="<?php echo htmlspecialchars($to); ?>" placeholder="Period to" class="text-sm border border-gray-300 dark:border-gray-600 rounded px-3 py-1.5 dark:bg-gray-700 dark:text-white">
            <button type="submit" class="px-3 py-1.5 text-sm bg-gray-800 dark:bg-gray-600 text-white rounded hover:bg-gray-700">Filter</button>
        </form>
        <input type="text" id="searchTimesheets" placeholder="Search employee..." class="text-sm border border-gray-300 dark:border-gray-600 rounded px-3 py-1.5 dark:bg-gray-700 dark:text-white">
        <div class="ml-auto">
            <?php
            $exportParams = http_build_query(['type' => 'timesheets', 'format' => 'csv']);
            if ($from) $exportParams .= '&from=' . urlencode($from);
            if ($to) $exportParams .= '&to=' . urlencode($to);
            ?>
            <a href="<?php echo BASE_URL; ?>/api/reports/export.php?<?php echo $exportParams; ?>" class="px-3 py-1.5 text-sm border border-gray-300 dark:border-gray-600 rounded hover:bg-gray-50 dark:hover:bg-gray-700">Export CSV</a>
        </div>
    </div>
    <div class="overflow-x-auto">
        <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
            <thead class="bg-gray-50 dark:bg-gray-700">
                <tr>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase">Employee</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase">Unit</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase">Period</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase">Total Hrs</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase">OT</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase">Status</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase">Action</th>
                </tr>
            </thead>
            <tbody class="bg-white dark:bg-gray-800 divide-y divide-gray-200 dark:divide-gray-700">
                <?php if (empty($timesheets)): ?>
                    <tr>
                        <td colspan="7" class="px-6 py-8 text-center text-sm text-gray-500">No timesheets found.</td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($timesheets as $ts): ?>
                        <tr>
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900 dark:text-white">
                                <?php echo htmlspecialchars($ts['employee_number'] . ' – ' . trim($ts['first_name'] . ' ' . $ts['last_name'])); ?>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-600 dark:text-gray-400">
                                <?php echo htmlspecialchars($ts['unit_name'] ?? '—'); ?>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900 dark:text-white">
                                <?php echo date('M d', strtotime($ts['period_start'])); ?> – <?php echo date('M d', strtotime($ts['period_end'])); ?>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900 dark:text-white">
                                <?php echo number_format($ts['total_hours'], 1); ?>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-600 dark:text-gray-400">
                                <?php echo number_format($ts['total_ot_hours'] ?? 0, 1); ?>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm">
                                <?php
                                $status = $ts['status'];
                                $badge = ['draft' => 'bg-gray-100 text-gray-800 dark:bg-gray-700 dark:text-gray-300', 'submitted' => 'bg-blue-100 text-blue-800 dark:bg-blue-900/30 dark:text-blue-300', 'endorsed' => 'bg-amber-100 text-amber-800 dark:bg-amber-900/30 dark:text-amber-300', 'approved' => 'bg-emerald-100 text-emerald-800 dark:bg-emerald-900/30 dark:text-emerald-300', 'locked' => 'bg-slate-100 text-slate-800 dark:bg-slate-700 dark:text-slate-300'];
                                $cls = $badge[$status] ?? 'bg-gray-100 text-gray-800';
                                ?>
                                <span class="px-2 py-0.5 rounded text-xs font-medium <?php echo $cls; ?>"><?php echo htmlspecialchars(ucfirst($status)); ?></span>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm">
                                <?php if (in_array($ts['status'], ['submitted', 'endorsed'], true)): ?>
                                    <form method="post" class="inline" onsubmit="return confirm('Approve this timesheet?');">
                                        <input type="hidden" name="action" value="approve">
                                        <input type="hidden" name="timesheet_id" value="<?php echo (int)$ts['id']; ?>">
                                        <button type="submit" class="px-2 py-1 text-xs font-medium text-emerald-600 hover:text-emerald-700 hover:bg-emerald-50 dark:hover:bg-emerald-900/30 rounded">Approve</button>
                                    </form>
                                <?php elseif ($ts['status'] === 'draft'): ?>
                                    <span class="text-gray-400 text-xs">Awaiting submit</span>
                                <?php else: ?>
                                    —
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<script>
document.getElementById('searchTimesheets')?.addEventListener('input', function(e) {
    const search = e.target.value.toLowerCase().trim();
    document.querySelectorAll('table tbody tr').forEach(row => {
        const text = row.textContent.toLowerCase();
        row.style.display = !search || text.includes(search) ? '' : 'none';
    });
});
</script>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
