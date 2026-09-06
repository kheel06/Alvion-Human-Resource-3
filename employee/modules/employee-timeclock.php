<?php
require_once __DIR__ . '/../../config/config.php';
requireAuth();
checkRole(['employee', 'supervisor', 'unit_head']);

$page_title = 'My Attendance';
$view = $_GET['view'] ?? 'attendance_log';

// Redirect old clock_history view to attendance log
if ($view === 'clock_history') {
    header("Location: " . BASE_URL . "/employee/modules/employee-timeclock.php?view=attendance_log");
    exit;
}

$employeeId = getCurrentEmployeeId($db ?? null);
if (!$employeeId) {
    $employeeId = $_SESSION['employee_id'] ?? $_SESSION['user_id'] ?? null;
}
if (!$employeeId) {
    $_SESSION['error'] = "Employee identifier not found. Please log in again.";
    header("Location: " . BASE_URL . "/auth/employee-login.php");
    exit();
}

$attendanceLog = [];
$recentCorrections = [];
$hasExceptions = false;

if (isset($db)) {
    try {
        $check = $db->query("SHOW TABLES LIKE 'attendance_logs'");
        if ($check && $check->rowCount() > 0 && is_numeric($employeeId)) {
            // Get attendance logs (last 14 days)
            $from = $_GET['from'] ?? date('Y-m-d', strtotime('-14 days'));
            $to = $_GET['to'] ?? date('Y-m-d');
            $stmt = $db->prepare("
                SELECT log_type, log_time, source, ip_address, location_id
                FROM attendance_logs
                WHERE employee_id = :emp_id
                  AND DATE(log_time) BETWEEN :from_date AND :to_date
                  AND (source IN ('biometric','qr','mobile') OR source IS NULL)
                ORDER BY log_time DESC
            ");
            $stmt->execute([':emp_id' => $employeeId, ':from_date' => $from, ':to_date' => $to]);
            $attendanceLog = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }

        // Load corrections
        $corrCheck = $db->query("SHOW TABLES LIKE 'timesheet_correction_requests'");
        if ($corrCheck && $corrCheck->rowCount() > 0) {
            $stmt = $db->prepare("
                SELECT id, date, requested_time_in, requested_time_out, reason, status, created_at
                FROM timesheet_correction_requests
                WHERE employee_id = :emp_id
                ORDER BY created_at DESC LIMIT 20
            ");
            $stmt->execute([':emp_id' => $employeeId]);
            $recentCorrections = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }

        $excCheck = $db->query("SHOW TABLES LIKE 'attendance_exceptions'");
        $hasExceptions = $excCheck && $excCheck->rowCount() > 0;
    } catch (PDOException $e) {
        error_log('Attendance page error: ' . $e->getMessage());
    }
}

include __DIR__ . '/../../includes/header.php';
?>

<div class="mb-6">
    <h1 class="text-2xl font-semibold text-gray-900 dark:text-white">My Attendance</h1>
    <p class="mt-1 text-sm text-gray-600 dark:text-gray-400">Attendance logs, time corrections, and exception tracking</p>
</div>

<?php if ($view === 'attendance_log'): ?>
    <!-- Date Filter -->
    <div class="bg-white dark:bg-gray-800 rounded-xl shadow-sm border border-gray-100 dark:border-gray-700 mb-6">
        <div class="px-5 py-4 flex flex-wrap items-center gap-4">
            <form method="GET" class="flex items-center gap-3 flex-wrap">
                <input type="hidden" name="view" value="attendance_log">
                <div>
                    <label class="text-xs text-gray-500 mr-1">From:</label>
                    <input type="date" name="from" value="<?php echo htmlspecialchars($_GET['from'] ?? date('Y-m-d', strtotime('-14 days'))); ?>" class="px-3 py-1.5 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-700 text-gray-900 dark:text-white text-sm">
                </div>
                <div>
                    <label class="text-xs text-gray-500 mr-1">To:</label>
                    <input type="date" name="to" value="<?php echo htmlspecialchars($_GET['to'] ?? date('Y-m-d')); ?>" class="px-3 py-1.5 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-700 text-gray-900 dark:text-white text-sm">
                </div>
                <button type="submit" class="px-4 py-1.5 bg-primary-600 text-white rounded-lg hover:bg-primary-700 text-sm">Filter</button>
            </form>
            <div class="ml-auto flex gap-2">
                <select id="filterSource" onchange="filterBySource()" class="text-xs border border-gray-300 dark:border-gray-600 rounded px-2 py-1 dark:bg-gray-700 dark:text-white">
                    <option value="">All Sources</option>
                    <option value="web">Web</option>
                </select>
                <button onclick="exportAttendanceLog()" class="px-3 py-1 text-xs border border-gray-300 dark:border-gray-600 rounded hover:bg-gray-50 dark:hover:bg-gray-700">Export CSV</button>
            </div>
        </div>
    </div>

    <!-- Attendance Log Table -->
    <div class="bg-white dark:bg-gray-800 rounded-xl shadow-sm border border-gray-100 dark:border-gray-700">
        <div class="px-5 py-4 border-b border-gray-100 dark:border-gray-700 flex items-center justify-between">
            <h2 class="text-sm font-semibold text-gray-900 dark:text-white">Attendance Logs</h2>
            <span class="text-xs text-gray-500"><?php echo count($attendanceLog); ?> records</span>
        </div>
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700" id="attendanceTable">
                <thead class="bg-gray-50 dark:bg-gray-700">
                    <tr>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase cursor-pointer" onclick="sortAttendance(0)">Date & Time <span>↕</span></th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase cursor-pointer" onclick="sortAttendance(1)">Type <span>↕</span></th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase">Source</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase">IP Address</th>
                    </tr>
                </thead>
                <tbody class="bg-white dark:bg-gray-800 divide-y divide-gray-200 dark:divide-gray-700" id="attendanceBody">
                    <?php if (empty($attendanceLog)): ?>
                        <tr><td colspan="4" class="px-6 py-8 text-center text-sm text-gray-500">
                            <svg xmlns="http://www.w3.org/2000/svg" class="w-10 h-10 mx-auto mb-2 text-gray-300" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                            No attendance records found for this period.
                        </td></tr>
                    <?php else: ?>
                        <?php foreach ($attendanceLog as $log): ?>
                            <tr data-source="<?php echo htmlspecialchars($log['source'] ?? ''); ?>">
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900 dark:text-white">
                                    <?php echo date('M d, Y H:i:s', strtotime($log['log_time'])); ?>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm">
                                    <span class="px-2 py-1 text-xs font-semibold rounded-full <?php
                                        echo in_array($log['log_type'], ['in', 'break_end']) ? 'bg-emerald-100 text-emerald-800 dark:bg-emerald-900 dark:text-emerald-200' : 'bg-gray-100 text-gray-800 dark:bg-gray-700 dark:text-gray-200';
                                    ?>">
                                        <?php echo strtoupper(str_replace('_', ' ', $log['log_type'])); ?>
                                    </span>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500 dark:text-gray-400">
                                    <?php
                                    $src = strtolower(trim($log['source'] ?? ''));
                                    echo in_array($src, ['qr', 'mobile', 'biometric']) ? 'Station' : ucfirst($log['source'] ?? 'web');
                                    ?>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500 dark:text-gray-400">
                                    <?php echo htmlspecialchars($log['ip_address'] ?? 'N/A'); ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <script>
        let sortDir = {};
        function sortAttendance(col) {
            const tbody = document.getElementById('attendanceBody');
            const rows = Array.from(tbody.querySelectorAll('tr'));
            sortDir[col] = sortDir[col] === 'asc' ? 'desc' : 'asc';
            rows.sort((a, b) => {
                const aT = a.cells[col]?.textContent.trim() || '';
                const bT = b.cells[col]?.textContent.trim() || '';
                return sortDir[col] === 'asc' ? aT.localeCompare(bT) : bT.localeCompare(aT);
            });
            rows.forEach(r => tbody.appendChild(r));
        }

        function filterBySource() {
            const filter = document.getElementById('filterSource').value.toLowerCase();
            document.querySelectorAll('#attendanceBody tr').forEach(row => {
                const src = (row.getAttribute('data-source') || '').toLowerCase();
                const match = !filter || src === filter;
                row.style.display = match ? '' : 'none';
            });
        }

        function exportAttendanceLog() {
            const rows = document.querySelectorAll('#attendanceBody tr:not([style*="display: none"])');
            let csv = 'Date & Time,Type,Source,IP Address\n';
            rows.forEach(r => {
                const cells = r.querySelectorAll('td');
                if (cells.length > 0) csv += Array.from(cells).map(c => '"' + c.textContent.trim().replace(/"/g, '""') + '"').join(',') + '\n';
            });
            const blob = new Blob([csv], { type: 'text/csv' });
            const a = document.createElement('a'); a.href = URL.createObjectURL(blob);
            a.download = 'attendance_' + new Date().toISOString().split('T')[0] + '.csv'; a.click();
        }
    </script>

<?php elseif ($view === 'correction_request'): ?>
    <!-- Time Correction Request -->
    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
        <div class="bg-white dark:bg-gray-800 rounded-xl shadow-sm border border-gray-100 dark:border-gray-700">
            <div class="px-5 py-4 border-b border-gray-100 dark:border-gray-700">
                <h2 class="text-sm font-semibold text-gray-900 dark:text-white">Submit Time Correction Request</h2>
            </div>
            <div class="p-5">
                <form id="correctionForm" class="space-y-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Date (past date only)</label>
                        <input type="date" name="date" required id="corrDate" max="<?php echo date('Y-m-d', strtotime('-1 day')); ?>" class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-700 text-gray-900 dark:text-white">
                    </div>
                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Requested Time In</label>
                            <input type="time" name="requested_time_in" id="corrTimeIn" class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-700 text-gray-900 dark:text-white">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Requested Time Out</label>
                            <input type="time" name="requested_time_out" id="corrTimeOut" class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-700 text-gray-900 dark:text-white">
                        </div>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Reason (min 10 characters)</label>
                        <textarea name="reason" required minlength="10" rows="3" placeholder="Explain why this correction is needed..." class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-700 text-gray-900 dark:text-white"></textarea>
                    </div>
                    <button type="submit" id="correctionSubmitBtn" class="w-full px-4 py-2 bg-primary-600 text-white rounded-lg hover:bg-primary-700">Submit Correction Request</button>
                </form>
            </div>
        </div>
        <div class="bg-white dark:bg-gray-800 rounded-xl shadow-sm border border-gray-100 dark:border-gray-700">
            <div class="px-5 py-4 border-b border-gray-100 dark:border-gray-700">
                <h2 class="text-sm font-semibold text-gray-900 dark:text-white">My Correction Requests</h2>
            </div>
            <div class="p-5">
                <div id="correctionList" class="space-y-3">
                    <?php if (empty($recentCorrections)): ?>
                        <p class="text-sm text-gray-500 dark:text-gray-400">No correction requests found</p>
                    <?php else: ?>
                        <?php foreach ($recentCorrections as $c): ?>
                            <div class="p-3 bg-gray-50 dark:bg-gray-700/50 rounded-lg">
                                <div class="flex justify-between items-start">
                                    <div>
                                        <p class="text-sm font-medium text-gray-900 dark:text-white"><?php echo htmlspecialchars($c['date']); ?></p>
                                        <p class="text-xs text-gray-500 mt-1"><?php echo htmlspecialchars(($c['requested_time_in'] ?? '') . ' - ' . ($c['requested_time_out'] ?? 'N/A')); ?></p>
                                        <p class="text-xs text-gray-600 dark:text-gray-400 mt-1"><?php echo htmlspecialchars($c['reason'] ?? ''); ?></p>
                                    </div>
                                    <span class="px-2 py-1 text-xs font-semibold rounded-full <?php
                                        echo ($c['status'] ?? '') === 'approved' ? 'bg-emerald-100 text-emerald-800 dark:bg-emerald-900 dark:text-emerald-200' :
                                            (($c['status'] ?? '') === 'rejected' ? 'bg-red-100 text-red-800 dark:bg-red-900 dark:text-red-200' :
                                            'bg-amber-100 text-amber-800 dark:bg-amber-900 dark:text-amber-200');
                                    ?>"><?php echo strtoupper($c['status'] ?? 'pending'); ?></span>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
    <script>
    (function() {
        const form = document.getElementById('correctionForm');
        const btn = document.getElementById('correctionSubmitBtn');
        form.addEventListener('submit', function(e) {
            e.preventDefault();
            const fd = new FormData(form);
            const payload = {
                date: fd.get('date'),
                requested_time_in: fd.get('requested_time_in') || null,
                requested_time_out: fd.get('requested_time_out') || null,
                reason: fd.get('reason'),
                timesheet_id: null
            };
            btn.disabled = true; btn.textContent = 'Submitting...';
            fetch('<?php echo BASE_URL; ?>/api/attendance/corrections.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(payload)
            })
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    alert('Correction request submitted successfully.');
                    window.location.reload();
                } else {
                    alert(data.message || 'Failed to submit');
                }
            })
            .catch(() => alert('An error occurred.'))
            .finally(() => { btn.disabled = false; btn.textContent = 'Submit Correction Request'; });
        });
    })();
    </script>

<?php elseif ($view === 'recent_corrections'): ?>
    <!-- Recent Corrections -->
    <div class="bg-white dark:bg-gray-800 rounded-xl shadow-sm border border-gray-100 dark:border-gray-700">
        <div class="px-5 py-4 border-b border-gray-100 dark:border-gray-700">
            <h2 class="text-sm font-semibold text-gray-900 dark:text-white">Recent Corrections</h2>
        </div>
        <div class="p-5">
            <?php if (empty($recentCorrections)): ?>
                <p class="text-sm text-gray-500 dark:text-gray-400 text-center py-8">No recent corrections found</p>
            <?php else: ?>
                <div class="space-y-3">
                    <?php foreach ($recentCorrections as $correction): ?>
                        <div class="flex items-center justify-between p-3 bg-gray-50 dark:bg-gray-700/50 rounded-lg">
                            <div>
                                <p class="text-sm font-medium text-gray-900 dark:text-white"><?php echo htmlspecialchars($correction['date'] ?? ''); ?></p>
                                <p class="text-xs text-gray-500"><?php echo htmlspecialchars($correction['reason'] ?? ''); ?></p>
                            </div>
                            <span class="px-2 py-1 text-xs font-semibold rounded-full <?php
                                echo ($correction['status'] ?? '') === 'approved' ? 'bg-emerald-100 text-emerald-800' :
                                    (($correction['status'] ?? '') === 'rejected' ? 'bg-red-100 text-red-800' : 'bg-amber-100 text-amber-800');
                            ?>">
                                <?php echo strtoupper($correction['status'] ?? 'pending'); ?>
                            </span>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>
<?php elseif ($view === 'clock_in_out'): ?>
    <!-- Quick Clock In/Out -->
    <?php
    $currentClockStatus = 'OUT';
    if (isset($db) && is_numeric($employeeId)) {
        try {
            $stmt = $db->prepare("SELECT log_type, log_time FROM attendance_logs WHERE employee_id = :emp_id AND DATE(log_time) = CURDATE() ORDER BY log_time DESC LIMIT 1");
            $stmt->bindValue(':emp_id', $employeeId, PDO::PARAM_INT);
            $stmt->execute();
            $lastPunch = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($lastPunch && in_array($lastPunch['log_type'], ['in', 'break_end'])) {
                $currentClockStatus = 'IN';
            }
        } catch (PDOException $e) {}
    }
    ?>
    <div class="max-w-md mx-auto">
        <div class="bg-white dark:bg-gray-800 rounded-xl shadow-sm border border-gray-100 dark:border-gray-700">
            <div class="px-5 py-4 border-b border-gray-100 dark:border-gray-700">
                <h2 class="text-sm font-semibold text-gray-900 dark:text-white">Quick Clock</h2>
            </div>
            <div class="p-6 text-center">
                <div class="inline-flex items-center justify-center w-28 h-28 rounded-full <?php echo $currentClockStatus === 'IN' ? 'bg-emerald-100 dark:bg-emerald-900' : 'bg-gray-100 dark:bg-gray-700'; ?> mb-4">
                    <span id="clockStatusLabel" class="text-4xl font-bold <?php echo $currentClockStatus === 'IN' ? 'text-emerald-600' : 'text-gray-500'; ?>">
                        <?php echo $currentClockStatus; ?>
                    </span>
                </div>
                <p class="text-sm text-gray-500 dark:text-gray-400 mb-1"><?php echo date('l, F j, Y'); ?></p>
                <p id="liveTime" class="text-2xl font-mono font-bold text-gray-900 dark:text-white mb-6"><?php echo date('H:i:s'); ?></p>

                <div id="clockMsg" class="hidden mb-4 px-4 py-3 rounded-lg text-sm"></div>

                <div class="grid grid-cols-2 gap-3">
                    <button type="button" onclick="quickPunch('in')" id="btnClockIn"
                        class="px-4 py-3 bg-emerald-600 text-white rounded-lg hover:bg-emerald-700 font-medium text-sm disabled:opacity-50">
                        Clock In
                    </button>
                    <button type="button" onclick="quickPunch('out')" id="btnClockOut"
                        class="px-4 py-3 bg-red-600 text-white rounded-lg hover:bg-red-700 font-medium text-sm disabled:opacity-50">
                        Clock Out
                    </button>
                </div>
                <a href="<?php echo BASE_URL; ?>/employee/modules/employee-timeclock.php?view=attendance_log" class="inline-block mt-4 text-xs text-primary-600 hover:underline">View Attendance Log →</a>
            </div>
        </div>
    </div>
    <script>
    (function(){
        // Live clock
        setInterval(function(){
            var now = new Date();
            var el = document.getElementById('liveTime');
            if(el) el.textContent = now.toTimeString().split(' ')[0];
        }, 1000);

        window.quickPunch = function(punchType) {
            var btn = punchType === 'in' ? document.getElementById('btnClockIn') : document.getElementById('btnClockOut');
            if(btn) { btn.disabled = true; btn.textContent = 'Processing...'; }
            var msgEl = document.getElementById('clockMsg');

            fetch('<?php echo BASE_URL; ?>/api/attendance/punch.php', {
                method: 'POST',
                headers: {'Content-Type': 'application/json'},
                body: JSON.stringify({ punch_type: punchType }),
                credentials: 'same-origin'
            })
            .then(function(r){ return r.json(); })
            .then(function(data){
                if(msgEl){
                    msgEl.classList.remove('hidden');
                    if(data.success){
                        msgEl.className = 'mb-4 px-4 py-3 rounded-lg text-sm bg-emerald-50 dark:bg-emerald-900/30 text-emerald-800 dark:text-emerald-200 border border-emerald-200';
                        msgEl.textContent = data.message || 'Punch recorded!';
                        var label = document.getElementById('clockStatusLabel');
                        if(label){ label.textContent = punchType === 'in' ? 'IN' : 'OUT'; }
                    } else {
                        msgEl.className = 'mb-4 px-4 py-3 rounded-lg text-sm bg-red-50 dark:bg-red-900/30 text-red-800 dark:text-red-200 border border-red-200';
                        msgEl.textContent = data.message || 'Failed to punch.';
                    }
                }
            })
            .catch(function(){ if(msgEl){ msgEl.classList.remove('hidden'); msgEl.className='mb-4 px-4 py-3 rounded-lg text-sm bg-red-50 text-red-800 border border-red-200'; msgEl.textContent='Network error.'; } })
            .finally(function(){
                if(btn){ btn.disabled = false; btn.textContent = punchType === 'in' ? 'Clock In' : 'Clock Out'; }
            });
        };
    })();
    </script>

<?php endif; ?>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
