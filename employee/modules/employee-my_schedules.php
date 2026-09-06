<?php
require_once __DIR__ . '/../../config/config.php';
requireAuth();
checkRole(['employee', 'supervisor', 'unit_head']);

$page_title = 'Shift & Schedule';
$view = $_GET['view'] ?? 'weekly_view';

// Get current employee ID (employees.id) for roster queries
$employeeId = getCurrentEmployeeId($db ?? null);
if (!$employeeId) {
    $employeeId = $_SESSION['employee_id'] ?? $_SESSION['user_id'] ?? null;
}
if (!$employeeId) {
    $_SESSION['error'] = "Employee identifier not found. Please log in again.";
    header("Location: " . BASE_URL . "/auth/employee-login.php");
    exit();
}

$schedules = [];
$currentWeekStart = null;
$currentWeekEnd = null;

if (isset($db) && is_numeric($employeeId)) {
    try {
        $today = new DateTime();
        $dayOfWeek = $today->format('N');
        $daysToMonday = $dayOfWeek - 1;
        $currentWeekStart = clone $today;
        $currentWeekStart->modify("-{$daysToMonday} days");
        $currentWeekEnd = clone $currentWeekStart;
        $currentWeekEnd->modify('+6 days');
        $weekStartStr = $currentWeekStart->format('Y-m-d');
        $weekEndStr = $currentWeekEnd->format('Y-m-d');

        $schedules = [];
        if ($db->query("SHOW TABLES LIKE 'roster_assignments'")->rowCount() > 0) {
            $stmt = $db->prepare("
                SELECT ra.assignment_date as shift_date, ra.assignment_date as start_date, ra.assignment_date as end_date,
                       st.name as shift_name, st.start_time, st.end_time, st.id as shift_template_id,
                       u.name as unit_name
                FROM roster_assignments ra
                JOIN rosters r ON ra.roster_id = r.id
                JOIN shift_templates st ON ra.shift_template_id = st.id
                LEFT JOIN units u ON r.unit_id = u.id
                WHERE ra.employee_id = ? AND r.status = 'published'
                  AND ra.assignment_date BETWEEN ? AND ?
                ORDER BY ra.assignment_date, st.start_time
            ");
            $stmt->execute([$employeeId, $weekStartStr, $weekEndStr]);
            $schedules = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }
        if (empty($schedules) && $db->query("SHOW TABLES LIKE 'shift_assignments'")->rowCount() > 0) {
            $stmt = $db->prepare("
                SELECT sa.start_date as shift_date, sa.start_date as start_date, sa.end_date,
                       st.name as shift_name, st.start_time, st.end_time,
                       u.name as unit_name
                FROM shift_assignments sa
                JOIN shift_templates st ON sa.shift_template_id = st.id
                LEFT JOIN units u ON sa.unit_id = u.id
                WHERE sa.employee_id = ?
                  AND sa.start_date <= ? AND COALESCE(sa.end_date, sa.start_date) >= ?
                ORDER BY sa.start_date, st.start_time
            ");
            $stmt->execute([$employeeId, $weekEndStr, $weekStartStr]);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
                for ($d = strtotime($r['start_date']); $d <= strtotime($r['end_date'] ?? $r['start_date']); $d += 86400) {
                    $dateStr = date('Y-m-d', $d);
                    if ($dateStr >= $weekStartStr && $dateStr <= $weekEndStr) {
                        $r['shift_date'] = $dateStr;
                        $r['start_date'] = $dateStr;
                        $schedules[] = $r;
                    }
                }
            }
        }
    } catch (PDOException $e) {
        error_log('My Schedule error: ' . $e->getMessage());
    }
}

// No sample data fallback – real data only

include __DIR__ . '/../../includes/header.php';
?>

<div class="mb-6">
    <h1 class="text-2xl font-semibold text-gray-900 dark:text-white">Shift & Schedule</h1>
    <p class="mt-1 text-sm text-gray-600 dark:text-gray-400">Weekly calendar, shift details, and swap requests</p>
</div>

<?php if ($view === 'weekly_view'): ?>
    <!-- Weekly Schedule Calendar -->
    <div class="bg-white dark:bg-gray-800 rounded-xl shadow-sm border border-gray-100 dark:border-gray-700 mb-6">
        <div class="px-5 py-4 border-b border-gray-100 dark:border-gray-700 flex items-center justify-between">
            <h2 class="text-sm font-semibold text-gray-900 dark:text-white">
                Weekly Schedule Calendar
            </h2>
            <div class="flex gap-2">
                <button onclick="changeWeek(-1)" class="px-3 py-1 text-xs border border-gray-300 dark:border-gray-600 rounded hover:bg-gray-50 dark:hover:bg-gray-700">Previous</button>
                <button onclick="changeWeek(0)" class="px-3 py-1 text-xs border border-gray-300 dark:border-gray-600 rounded hover:bg-gray-50 dark:hover:bg-gray-700">This Week</button>
                <button onclick="changeWeek(1)" class="px-3 py-1 text-xs border border-gray-300 dark:border-gray-600 rounded hover:bg-gray-50 dark:hover:bg-gray-700">Next</button>
            </div>
        </div>
        <div class="p-5">
            <div class="grid grid-cols-7 gap-2">
                <?php
                $daysOfWeek = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];
                $currentDay = clone $currentWeekStart;
                for ($i = 0; $i < 7; $i++):
                    $dayStr = $currentDay->format('Y-m-d');
                    $daySchedules = array_filter($schedules, function($s) use ($dayStr) {
                        return isset($s['shift_date']) ? $s['shift_date'] === $dayStr : 
                               (isset($s['start_date']) && $s['start_date'] <= $dayStr && (!$s['end_date'] || $s['end_date'] >= $dayStr));
                    });
                    $isToday = $currentDay->format('Y-m-d') === date('Y-m-d');
                ?>
                    <div class="border border-gray-200 dark:border-gray-700 rounded-lg p-3 min-h-[140px] <?php echo $isToday ? 'bg-primary-50 dark:bg-primary-900 border-primary-300 dark:border-primary-700' : ''; ?>">
                        <div class="text-xs font-semibold text-gray-700 dark:text-gray-300 mb-2 text-center">
                            <div class="font-bold"><?php echo $daysOfWeek[$i]; ?></div>
                            <div class="text-gray-500 mt-1"><?php echo $currentDay->format('M d'); ?></div>
                        </div>
                        <?php if (!empty($daySchedules)): ?>
                            <?php foreach ($daySchedules as $schedule): ?>
                                <div class="text-xs bg-primary-100 dark:bg-primary-800 text-primary-800 dark:text-primary-200 rounded p-2 mb-1 cursor-pointer hover:bg-primary-200 dark:hover:bg-primary-700 transition-colors" onclick="showShiftDetails(<?php echo htmlspecialchars(json_encode($schedule)); ?>)">
                                    <div class="font-semibold"><?php 
                                    $startTime = $schedule['start_time'] ?? '';
                                    $endTime = $schedule['end_time'] ?? '';
                                    echo htmlspecialchars(date('H:i', strtotime($startTime)) . ' - ' . date('H:i', strtotime($endTime)));
                                    ?></div>
                                    <?php if (isset($schedule['shift_name'])): ?>
                                        <div class="text-[10px] mt-1"><?php echo htmlspecialchars($schedule['shift_name']); ?></div>
                                    <?php endif; ?>
                                </div>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <div class="text-xs text-gray-400 text-center mt-2">No shift</div>
                        <?php endif; ?>
                    </div>
                <?php
                    $currentDay->modify('+1 day');
                endfor;
                ?>
            </div>
        </div>
    </div>

<?php elseif ($view === 'shift_details'): ?>
    <!-- Shift Details & Location -->
    <div class="bg-white dark:bg-gray-800 rounded-xl shadow-sm border border-gray-100 dark:border-gray-700">
        <div class="px-5 py-4 border-b border-gray-100 dark:border-gray-700">
            <h2 class="text-sm font-semibold text-gray-900 dark:text-white">Shift Details & Location</h2>
        </div>
        <div class="p-5">
            <?php if (empty($schedules)): ?>
                <p class="text-sm text-gray-500 text-center py-8">No shift details available</p>
            <?php else: ?>
                <div class="space-y-4">
                    <?php foreach ($schedules as $schedule): ?>
                        <div class="border border-gray-200 dark:border-gray-700 rounded-lg p-4 hover:bg-gray-50 dark:hover:bg-gray-700/50 transition-colors">
                            <div class="flex justify-between items-start">
                                <div class="flex-1">
                                    <h3 class="font-semibold text-gray-900 dark:text-white mb-2">
                                        <?php echo htmlspecialchars($schedule['shift_name'] ?? 'Scheduled Shift'); ?>
                                    </h3>
                                    <div class="grid grid-cols-2 gap-4 mt-3">
                                        <div>
                                            <p class="text-xs text-gray-500 dark:text-gray-400">Date</p>
                                            <p class="text-sm font-medium text-gray-900 dark:text-white mt-1">
                                                <?php 
                                                $date = $schedule['shift_date'] ?? $schedule['start_date'] ?? '';
                                                echo date('l, F d, Y', strtotime($date));
                                                ?>
                                            </p>
                                        </div>
                                        <div>
                                            <p class="text-xs text-gray-500 dark:text-gray-400">Time</p>
                                            <p class="text-sm font-medium text-gray-900 dark:text-white mt-1">
                                                <?php echo htmlspecialchars(($schedule['start_time'] ?? '') . ' - ' . ($schedule['end_time'] ?? '')); ?>
                                            </p>
                                        </div>
                                        <div>
                                            <p class="text-xs text-gray-500 dark:text-gray-400">Location</p>
                                            <p class="text-sm font-medium text-gray-900 dark:text-white mt-1">
                                                <?php echo htmlspecialchars($schedule['unit_name'] ?? 'Main Building'); ?>
                                            </p>
                                        </div>
                                        <div>
                                            <p class="text-xs text-gray-500 dark:text-gray-400">Shift Code</p>
                                            <p class="text-sm font-medium text-gray-900 dark:text-white mt-1">
                                                <?php echo htmlspecialchars($schedule['shift_code'] ?? 'N/A'); ?>
                                            </p>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>

<?php elseif ($view === 'swap_cover'): ?>
    <!-- Request Shift Swap / Cover Panel -->
    <div class="bg-white dark:bg-gray-800 rounded-xl shadow-sm border border-gray-100 dark:border-gray-700">
        <div class="px-5 py-4 border-b border-gray-100 dark:border-gray-700">
            <h2 class="text-sm font-semibold text-gray-900 dark:text-white">Request Shift Swap / Cover</h2>
        </div>
        <div class="p-5">
            <form id="swapForm" class="space-y-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Shift Date to Swap/Cover</label>
                    <input type="date" name="swap_date" required class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg dark:bg-gray-700 dark:text-white">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Reason (min 5 characters)</label>
                    <textarea name="reason" rows="3" required minlength="5" placeholder="Please provide a reason for this request..." class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg dark:bg-gray-700 dark:text-white"></textarea>
                </div>
                <div class="flex gap-3">
                    <button type="submit" id="swapSubmitBtn" class="flex-1 px-4 py-2 bg-primary-600 text-white rounded-lg hover:bg-primary-700 transition-colors">Submit Request</button>
                    <button type="reset" class="px-4 py-2 border border-gray-300 dark:border-gray-600 text-gray-700 dark:text-gray-300 rounded-lg hover:bg-gray-50 dark:hover:bg-gray-700 transition-colors">Clear</button>
                </div>
            </form>
        </div>
    </div>
    <script>
        document.getElementById('swapForm')?.addEventListener('submit', function(e) {
            e.preventDefault();
            const form = e.target;
            const btn = document.getElementById('swapSubmitBtn');
            const payload = { swap_date: form.querySelector('[name="swap_date"]').value, reason: form.querySelector('[name="reason"]').value };
            if (btn) btn.disabled = true;
            fetch('<?php echo BASE_URL; ?>/api/scheduling/swap_requests.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(payload)
            })
            .then(r => r.json())
            .then(data => {
                if (data.success) { alert('Swap request submitted.'); form.reset(); }
                else alert(data.message || 'Failed to submit');
            })
            .catch(() => alert('An error occurred.'))
            .finally(() => { if (btn) btn.disabled = false; });
        });
    </script>

<?php elseif ($view === 'published_roster'): ?>
    <!-- Published Roster -->
    <div class="bg-white dark:bg-gray-800 rounded-xl shadow-sm border border-gray-100 dark:border-gray-700">
        <div class="px-5 py-4 border-b border-gray-100 dark:border-gray-700 flex items-center justify-between">
            <h2 class="text-sm font-semibold text-gray-900 dark:text-white">Published Roster</h2>
            <div class="flex gap-2">
                <select id="rosterMonth" onchange="filterRoster()" class="text-xs border border-gray-300 dark:border-gray-600 rounded px-2 py-1 dark:bg-gray-700 dark:text-white">
                    <option value="">All Months</option>
                    <?php for ($i = 1; $i <= 12; $i++): ?>
                        <option value="<?php echo $i; ?>"><?php echo date('F', mktime(0, 0, 0, $i, 1)); ?></option>
                    <?php endfor; ?>
                </select>
                <button onclick="exportRoster()" class="px-3 py-1 text-xs border border-gray-300 dark:border-gray-600 rounded hover:bg-gray-50 dark:hover:bg-gray-700">
                    Export
                </button>
            </div>
        </div>
        <div class="p-5">
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                    <thead class="bg-gray-50 dark:bg-gray-700">
                        <tr>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase">Date</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase">Shift</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase">Time</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase">Location</th>
                        </tr>
                    </thead>
                    <tbody class="bg-white dark:bg-gray-800 divide-y divide-gray-200 dark:divide-gray-700">
                        <?php if (empty($schedules)): ?>
                            <tr>
                                <td colspan="4" class="px-6 py-4 text-center text-sm text-gray-500">No published roster available</td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($schedules as $schedule): ?>
                                <tr>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900 dark:text-white">
                                        <?php 
                                        $date = $schedule['shift_date'] ?? $schedule['start_date'] ?? '';
                                        echo date('M d, Y', strtotime($date));
                                        ?>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900 dark:text-white">
                                        <?php echo htmlspecialchars($schedule['shift_name'] ?? 'N/A'); ?>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900 dark:text-white">
                                        <?php echo htmlspecialchars(($schedule['start_time'] ?? '') . ' - ' . ($schedule['end_time'] ?? '')); ?>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900 dark:text-white">
                                        <?php echo htmlspecialchars($schedule['unit_name'] ?? 'Main Building'); ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <script>
        function changeWeek(direction) {
            // This would typically update the week via AJAX or reload with new week parameter
            const url = new URL(window.location);
            if (direction === 0) {
                url.searchParams.delete('week');
            } else {
                const currentWeek = url.searchParams.get('week') || '0';
                url.searchParams.set('week', parseInt(currentWeek) + direction);
            }
            window.location.href = url.toString();
        }

        function showShiftDetails(schedule) {
            // Show modal or navigate to shift details
            window.location.href = '?view=shift_details&id=' + (schedule.id || '');
        }

        function filterRoster() {
            // Filter roster by month
            const month = document.getElementById('rosterMonth').value;
            // Implementation would filter the table
        }

        function exportRoster() {
            const table = document.querySelector('table');
            if (!table) return;
            const rows = table.querySelectorAll('tbody tr');
            let csv = 'Date,Shift,Time,Location\n';
            rows.forEach(r => {
                const cells = r.querySelectorAll('td');
                if (cells.length >= 4) csv += Array.from(cells).map(c => c.textContent.trim().replace(/,/g, ';')).join(',') + '\n';
            });
            const a = document.createElement('a');
            a.href = 'data:text/csv;charset=utf-8,' + encodeURIComponent(csv);
            a.download = 'roster_' + new Date().toISOString().split('T')[0] + '.csv';
            a.click();
        }

            </script>
<?php endif; ?>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
