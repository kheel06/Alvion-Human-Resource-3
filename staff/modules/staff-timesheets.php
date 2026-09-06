<?php
require_once __DIR__ . '/../../config/config.php';
requireAuth();
checkRole(['staff', 'staff supervisor', 'super admin']);

$page_title = 'Team Timesheets';
$currentEmpId = getCurrentEmployeeId($db);
$staffUnitId = null;
if ($currentEmpId && isset($db)) {
    $stmt = $db->prepare("SELECT unit_id FROM employees WHERE id = ?");
    $stmt->execute([$currentEmpId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    $staffUnitId = $row ? ($row['unit_id'] ?? null) : null;
}

$statusFilter = $_GET['status'] ?? '';
$message = '';
$error = '';

// Endorse timesheet (staff moves submitted -> endorsed)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($db)) {
    $action = $_POST['action'] ?? '';
    $timesheetId = (int)($_POST['timesheet_id'] ?? 0);
    if ($timesheetId && $action === 'endorse') {
        try {
            $sql = "UPDATE timesheets SET status = 'endorsed', updated_at = NOW() WHERE id = :id AND status = 'submitted'";
            if ($staffUnitId) {
                $sql .= " AND unit_id = :unit_id";
            }
            $stmt = $db->prepare($sql);
            $stmt->bindValue(':id', $timesheetId, PDO::PARAM_INT);
            if ($staffUnitId) $stmt->bindValue(':unit_id', $staffUnitId, PDO::PARAM_INT);
            $stmt->execute();
            if ($stmt->rowCount() > 0) {
                $message = 'Timesheet endorsed.';
            } else {
                $error = 'Timesheet not found or already processed.';
            }
        } catch (PDOException $e) {
            error_log('Staff endorse timesheet error: ' . $e->getMessage());
            $error = 'Failed to endorse timesheet.';
        }
    }
    if ($message) {
        header('Location: ' . $_SERVER['PHP_SELF'] . '?' . http_build_query(array_filter(['status' => $statusFilter])));
        exit;
    }
}

$timesheets = [];
if (isset($db)) {
    try {
        $check = $db->query("SHOW TABLES LIKE 'timesheets'");
        if ($check && $check->rowCount() > 0) {
            $sql = "
                SELECT t.id, t.employee_id, t.period_start, t.period_end, t.total_hours, t.total_ot_hours, t.status, t.unit_id,
                       e.employee_number, e.first_name, e.last_name,
                       u.name as unit_name
                FROM timesheets t
                JOIN employees e ON t.employee_id = e.id
                LEFT JOIN units u ON t.unit_id = u.id
                WHERE 1=1
            ";
            $params = [];
            if ($staffUnitId) {
                $sql .= " AND (t.unit_id = :unit_id OR e.unit_id = :unit_id2)";
                $params[':unit_id'] = $staffUnitId;
                $params[':unit_id2'] = $staffUnitId;
            }
            if (in_array($statusFilter, ['draft', 'submitted', 'endorsed', 'approved', 'locked'])) {
                $sql .= " AND t.status = :status";
                $params[':status'] = $statusFilter;
            }
            $sql .= " ORDER BY t.period_start DESC, e.last_name LIMIT 150";
            $stmt = $db->prepare($sql);
            $stmt->execute($params);
            $timesheets = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }
    } catch (PDOException $e) {
        error_log('Staff timesheets error: ' . $e->getMessage());
        $error = 'Failed to load timesheets.';
    }
}

include __DIR__ . '/../../includes/header.php';
?>

<div class="mb-6">
    <h1 class="text-2xl font-semibold text-gray-900 dark:text-white">Team Timesheets</h1>
    <p class="mt-1 text-sm text-gray-600 dark:text-gray-400">View and endorse timesheets for your department.</p>
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

<div class="bg-white dark:bg-gray-800 rounded-xl shadow-sm border border-gray-100 dark:border-gray-700">
    <div class="px-5 py-4 border-b border-gray-100 dark:border-gray-700">
        <form method="get" class="flex items-center gap-3">
            <select name="status" class="text-sm border border-gray-300 dark:border-gray-600 rounded px-3 py-1.5 dark:bg-gray-700 dark:text-white bg-slate-800 text-slate-200 border-slate-600">
                <option value="">All statuses</option>
                <option value="draft" <?php echo $statusFilter === 'draft' ? 'selected' : ''; ?>>Draft</option>
                <option value="submitted" <?php echo $statusFilter === 'submitted' ? 'selected' : ''; ?>>Submitted</option>
                <option value="endorsed" <?php echo $statusFilter === 'endorsed' ? 'selected' : ''; ?>>Endorsed</option>
                <option value="approved" <?php echo $statusFilter === 'approved' ? 'selected' : ''; ?>>Approved</option>
                <option value="locked" <?php echo $statusFilter === 'locked' ? 'selected' : ''; ?>>Locked</option>
            </select>
            <button type="submit" class="px-3 py-1.5 text-sm bg-slate-700 text-white rounded hover:bg-slate-600">Filter</button>
        </form>
    </div>
    <div class="overflow-x-auto">
        <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
            <thead class="bg-slate-800 text-slate-200">
                <tr>
                    <th class="px-6 py-3 text-left text-xs font-medium uppercase">Employee</th>
                    <th class="px-6 py-3 text-left text-xs font-medium uppercase">Unit</th>
                    <th class="px-6 py-3 text-left text-xs font-medium uppercase">Period</th>
                    <th class="px-6 py-3 text-left text-xs font-medium uppercase">Total Hrs</th>
                    <th class="px-6 py-3 text-left text-xs font-medium uppercase">OT</th>
                    <th class="px-6 py-3 text-left text-xs font-medium uppercase">Status</th>
                    <th class="px-6 py-3 text-left text-xs font-medium uppercase">Action</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-200 dark:divide-gray-700">
                <?php if (empty($timesheets)): ?>
                    <tr class="text-slate-200">
                        <td colspan="7" class="px-6 py-8 text-center text-sm text-gray-500">No timesheets found for your team.</td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($timesheets as $ts): ?>
                        <tr class="text-slate-200">
                            <td class="px-6 py-4 whitespace-nowrap text-sm"><?php echo htmlspecialchars($ts['employee_number'] . ' – ' . trim($ts['first_name'] . ' ' . $ts['last_name'])); ?></td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm"><?php echo htmlspecialchars($ts['unit_name'] ?? '—'); ?></td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm"><?php echo date('M d', strtotime($ts['period_start'])); ?> – <?php echo date('M d', strtotime($ts['period_end'])); ?></td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm"><?php echo number_format($ts['total_hours'], 1); ?></td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm"><?php echo number_format($ts['total_ot_hours'] ?? 0, 1); ?></td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm">
                                <span class="px-2 py-0.5 rounded text-xs font-medium
                                    <?php
                                    $s = $ts['status'];
                                    echo $s === 'draft' ? 'bg-gray-600 text-gray-200' : ($s === 'submitted' ? 'bg-blue-600/80 text-blue-100' : ($s === 'endorsed' ? 'bg-amber-600/80 text-amber-100' : ($s === 'approved' ? 'bg-emerald-600/80 text-emerald-100' : 'bg-slate-600 text-slate-200')));
                                    ?>"><?php echo htmlspecialchars(ucfirst($ts['status'])); ?></span>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm">
                                <?php if ($ts['status'] === 'submitted'): ?>
                                    <form method="post" class="inline" onsubmit="return confirm('Endorse this timesheet for HR approval?');">
                                        <input type="hidden" name="action" value="endorse">
                                        <input type="hidden" name="timesheet_id" value="<?php echo (int)$ts['id']; ?>">
                                        <button type="submit" class="text-amber-400 hover:text-amber-300 font-medium text-xs">Endorse</button>
                                    </form>
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

<?php include __DIR__ . '/../../includes/footer.php'; ?>
