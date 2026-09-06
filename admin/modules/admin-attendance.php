<?php
require_once __DIR__ . '/../../config/config.php';
requireAuth();
checkRole(['admin', 'super admin', 'supervisor', 'unit_head', 'hr_admin', 'finance']);

$page_title = 'Attendance';
$date = $_GET['date'] ?? date('Y-m-d');

$present = [];
$absent = [];
$onLeave = [];
$otherStatus = []; // rest_day, holiday, half_day if not grouped
$noRecord = [];
$error = '';

if (isset($db)) {
    try {
        $tableCheck = $db->query("SHOW TABLES LIKE 'daily_attendance'");
        if ($tableCheck && $tableCheck->rowCount() > 0) {
            $stmt = $db->prepare("
                SELECT da.employee_id, da.date, da.time_in, da.time_out, da.status, da.regular_hours, da.late_seconds,
                       e.employee_number, e.first_name, e.last_name,
                       u.name as unit_name
                FROM daily_attendance da
                JOIN employees e ON da.employee_id = e.id
                LEFT JOIN units u ON e.unit_id = u.id
                WHERE da.date = :d
                ORDER BY e.last_name, e.first_name
            ");
            $stmt->execute([':d' => $date]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

            foreach ($rows as $r) {
                $r['unit_name'] = $r['unit_name'] ?? '—';
                switch ($r['status']) {
                    case 'present':
                    case 'half_day':
                        $present[] = $r;
                        break;
                    case 'absent':
                        $absent[] = $r;
                        break;
                    case 'leave':
                        $onLeave[] = $r;
                        break;
                    case 'rest_day':
                    case 'holiday':
                        $otherStatus[] = $r;
                        break;
                    default:
                        $otherStatus[] = $r;
                }
            }

            // Employees with no daily_attendance record for this date (active only)
            $stmt = $db->prepare("
                SELECT e.id as employee_id, e.employee_number, e.first_name, e.last_name, u.name as unit_name
                FROM employees e
                LEFT JOIN units u ON e.unit_id = u.id
                WHERE e.status = 'active' AND e.deleted_at IS NULL
                  AND NOT EXISTS (SELECT 1 FROM daily_attendance da WHERE da.employee_id = e.id AND da.date = :d)
                ORDER BY e.last_name, e.first_name
            ");
            $stmt->execute([':d' => $date]);
            $noRecord = $stmt->fetchAll(PDO::FETCH_ASSOC);
            foreach ($noRecord as &$nr) {
                $nr['unit_name'] = $nr['unit_name'] ?? '—';
                $nr['status'] = 'no_record';
            }
            unset($nr);
        }
    } catch (PDOException $e) {
        error_log('Admin attendance page: ' . $e->getMessage());
        $error = 'Failed to load attendance data.';
    }
}

include __DIR__ . '/../../includes/header.php';
?>

<div class="mb-6">
    <h1 class="text-2xl font-semibold text-gray-900 dark:text-white">Attendance</h1>
    <p class="mt-1 text-sm text-gray-600 dark:text-gray-400">View employees present and absent by date.</p>
</div>

<?php if ($error): ?>
    <div class="mb-4 bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-800 text-red-800 dark:text-red-200 px-4 py-3 rounded-lg text-sm">
        <?php echo htmlspecialchars($error); ?>
    </div>
<?php endif; ?>

<div class="mb-4 flex flex-wrap items-center gap-3">
    <form method="get" action="" class="flex items-center gap-2">
        <label class="text-sm text-gray-600 dark:text-gray-400">Date</label>
        <input type="date" name="date" value="<?php echo htmlspecialchars($date); ?>" class="text-sm border border-gray-300 dark:border-gray-600 rounded px-3 py-2 dark:bg-gray-700 dark:text-white">
        <button type="submit" class="px-3 py-2 text-sm bg-gray-800 dark:bg-gray-600 text-white rounded hover:bg-gray-700">View</button>
    </form>
    <input type="text" id="searchAttendance" placeholder="Search employee..." class="text-sm border border-gray-300 dark:border-gray-600 rounded px-3 py-2 dark:bg-gray-700 dark:text-white" onkeyup="filterAttendanceTables(this.value)">
    <span class="text-sm text-gray-500"><?php echo date('l, F j, Y', strtotime($date)); ?></span>
    <a href="<?php echo htmlspecialchars(BASE_URL ?? ''); ?>/admin/modules/admin-biometric_log.php?from=<?php echo urlencode($date); ?>&to=<?php echo urlencode($date); ?>" class="ml-auto text-sm text-indigo-600 dark:text-indigo-400 hover:underline">View biometric logs for this date →</a>
</div>

<div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
    <!-- Present -->
    <div class="bg-white dark:bg-gray-800 rounded-xl shadow-sm border border-gray-100 dark:border-gray-700">
        <div class="px-5 py-4 border-b border-gray-100 dark:border-gray-700 flex items-center justify-between">
            <h2 class="text-sm font-semibold text-gray-900 dark:text-white">Present (<?php echo count($present); ?>)</h2>
            <span class="w-8 h-8 rounded-full bg-emerald-100 dark:bg-emerald-900/50 text-emerald-700 dark:text-emerald-300 flex items-center justify-center text-sm font-bold"><?php echo count($present); ?></span>
        </div>
        <div class="overflow-x-auto max-h-[420px] overflow-y-auto">
            <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                <thead class="bg-gray-50 dark:bg-gray-700 sticky top-0">
                    <tr>
                        <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase">Employee</th>
                        <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase">Unit</th>
                        <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase">Time In</th>
                        <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase">Time Out</th>
                        <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase">Hours</th>
                    </tr>
                </thead>
                <tbody class="bg-white dark:bg-gray-800 divide-y divide-gray-200 dark:divide-gray-700">
                    <?php if (empty($present)): ?>
                        <tr>
                            <td colspan="5" class="px-4 py-6 text-center text-sm text-gray-500">No one marked present for this date.</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($present as $p): ?>
                            <tr>
                                <td class="px-4 py-3 text-sm text-gray-900 dark:text-white"><?php echo htmlspecialchars($p['employee_number'] . ' – ' . trim($p['first_name'] . ' ' . $p['last_name'])); ?></td>
                                <td class="px-4 py-3 text-sm text-gray-600 dark:text-gray-400"><?php echo htmlspecialchars($p['unit_name']); ?></td>
                                <td class="px-4 py-3 text-sm text-gray-900 dark:text-white"><?php echo $p['time_in'] ? date('g:i A', strtotime($p['time_in'])) : '—'; ?></td>
                                <td class="px-4 py-3 text-sm text-gray-900 dark:text-white"><?php echo $p['time_out'] ? date('g:i A', strtotime($p['time_out'])) : '—'; ?></td>
                                <td class="px-4 py-3 text-sm text-gray-900 dark:text-white"><?php echo number_format((float)($p['regular_hours'] ?? 0), 1); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Absent -->
    <div class="bg-white dark:bg-gray-800 rounded-xl shadow-sm border border-gray-100 dark:border-gray-700">
        <div class="px-5 py-4 border-b border-gray-100 dark:border-gray-700 flex items-center justify-between">
            <h2 class="text-sm font-semibold text-gray-900 dark:text-white">Absent (<?php echo count($absent); ?>)</h2>
            <span class="w-8 h-8 rounded-full bg-red-100 dark:bg-red-900/50 text-red-700 dark:text-red-300 flex items-center justify-center text-sm font-bold"><?php echo count($absent); ?></span>
        </div>
        <div class="overflow-x-auto max-h-[420px] overflow-y-auto">
            <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                <thead class="bg-gray-50 dark:bg-gray-700 sticky top-0">
                    <tr>
                        <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase">Employee</th>
                        <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase">Unit</th>
                    </tr>
                </thead>
                <tbody class="bg-white dark:bg-gray-800 divide-y divide-gray-200 dark:divide-gray-700">
                    <?php if (empty($absent)): ?>
                        <tr>
                            <td colspan="2" class="px-4 py-6 text-center text-sm text-gray-500">No one marked absent for this date.</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($absent as $a): ?>
                            <tr>
                                <td class="px-4 py-3 text-sm text-gray-900 dark:text-white"><?php echo htmlspecialchars($a['employee_number'] . ' – ' . trim($a['first_name'] . ' ' . $a['last_name'])); ?></td>
                                <td class="px-4 py-3 text-sm text-gray-600 dark:text-gray-400"><?php echo htmlspecialchars($a['unit_name']); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- On Leave / No record -->
<div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mt-6">
    <!-- On Leave -->
    <div class="bg-white dark:bg-gray-800 rounded-xl shadow-sm border border-gray-100 dark:border-gray-700">
        <div class="px-5 py-4 border-b border-gray-100 dark:border-gray-700">
            <h2 class="text-sm font-semibold text-gray-900 dark:text-white">On Leave (<?php echo count($onLeave); ?>)</h2>
        </div>
        <div class="overflow-x-auto max-h-[280px] overflow-y-auto">
            <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                <thead class="bg-gray-50 dark:bg-gray-700 sticky top-0">
                    <tr>
                        <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase">Employee</th>
                        <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase">Unit</th>
                    </tr>
                </thead>
                <tbody class="bg-white dark:bg-gray-800 divide-y divide-gray-200 dark:divide-gray-700">
                    <?php if (empty($onLeave)): ?>
                        <tr><td colspan="2" class="px-4 py-4 text-center text-sm text-gray-500">None</td></tr>
                    <?php else: ?>
                        <?php foreach ($onLeave as $l): ?>
                            <tr>
                                <td class="px-4 py-3 text-sm text-gray-900 dark:text-white"><?php echo htmlspecialchars($l['employee_number'] . ' – ' . trim($l['first_name'] . ' ' . $l['last_name'])); ?></td>
                                <td class="px-4 py-3 text-sm text-gray-600 dark:text-gray-400"><?php echo htmlspecialchars($l['unit_name']); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- No record today -->
    <div class="bg-white dark:bg-gray-800 rounded-xl shadow-sm border border-gray-100 dark:border-gray-700">
        <div class="px-5 py-4 border-b border-gray-100 dark:border-gray-700">
            <h2 class="text-sm font-semibold text-gray-900 dark:text-white">No attendance record (<?php echo count($noRecord); ?>)</h2>
            <p class="text-xs text-gray-500 mt-0.5">Active employees with no entry for this date</p>
        </div>
        <div class="overflow-x-auto max-h-[280px] overflow-y-auto">
            <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                <thead class="bg-gray-50 dark:bg-gray-700 sticky top-0">
                    <tr>
                        <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase">Employee</th>
                        <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase">Unit</th>
                    </tr>
                </thead>
                <tbody class="bg-white dark:bg-gray-800 divide-y divide-gray-200 dark:divide-gray-700">
                    <?php if (empty($noRecord)): ?>
                        <tr><td colspan="2" class="px-4 py-4 text-center text-sm text-gray-500">All active employees have a record for this date.</td></tr>
                    <?php else: ?>
                        <?php foreach ($noRecord as $n): ?>
                            <tr>
                                <td class="px-4 py-3 text-sm text-gray-900 dark:text-white"><?php echo htmlspecialchars($n['employee_number'] . ' – ' . trim($n['first_name'] . ' ' . $n['last_name'])); ?></td>
                                <td class="px-4 py-3 text-sm text-gray-600 dark:text-gray-400"><?php echo htmlspecialchars($n['unit_name']); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php if (!empty($otherStatus)): ?>
<div class="mt-6 bg-white dark:bg-gray-800 rounded-xl shadow-sm border border-gray-100 dark:border-gray-700">
    <div class="px-5 py-4 border-b border-gray-100 dark:border-gray-700">
        <h2 class="text-sm font-semibold text-gray-900 dark:text-white">Rest Day / Holiday (<?php echo count($otherStatus); ?>)</h2>
    </div>
    <div class="overflow-x-auto">
        <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
            <thead class="bg-gray-50 dark:bg-gray-700">
                <tr>
                    <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase">Employee</th>
                    <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase">Unit</th>
                    <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase">Status</th>
                </tr>
            </thead>
            <tbody class="bg-white dark:bg-gray-800 divide-y divide-gray-200 dark:divide-gray-700">
                <?php foreach ($otherStatus as $o): ?>
                    <tr>
                        <td class="px-4 py-3 text-sm text-gray-900 dark:text-white"><?php echo htmlspecialchars($o['employee_number'] . ' – ' . trim($o['first_name'] . ' ' . $o['last_name'])); ?></td>
                        <td class="px-4 py-3 text-sm text-gray-600 dark:text-gray-400"><?php echo htmlspecialchars($o['unit_name']); ?></td>
                        <td class="px-4 py-3 text-sm"><?php echo htmlspecialchars(ucfirst(str_replace('_', ' ', $o['status']))); ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>

<script>
function filterAttendanceTables(search) {
    search = search.toLowerCase().trim();
    document.querySelectorAll('.grid table tbody tr').forEach(row => {
        const text = row.textContent.toLowerCase();
        row.style.display = !search || text.includes(search) ? '' : 'none';
    });
}
</script>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
