<?php
require_once __DIR__ . '/../../config/config.php';
requireAuth();
checkRole(['admin', 'super admin', 'supervisor', 'unit_head', 'hr_admin', 'finance']);

$page_title = 'Reports';
$view = $_GET['view'] ?? 'attendance_summary';

$attendanceData = [];
$overtimeData = [];
$leaveData = [];
$claimsData = [];
$reportError = '';

if (isset($db)) {
    // Get attendance summary data
    if ($view === 'attendance_summary') {
        try {
            // Detect available columns in daily_attendance
            $cols = [];
            $colStmt = $db->query("SHOW COLUMNS FROM daily_attendance");
            while ($c = $colStmt->fetch(PDO::FETCH_ASSOC)) {
                $cols[] = $c['Field'];
            }

            $hasLateSeconds = in_array('late_seconds', $cols);
            $lateExpr = $hasLateSeconds
                ? "SUM(CASE WHEN da.late_seconds > 0 THEN 1 ELSE 0 END)"
                : "0";

            $stmt = $db->prepare("
                SELECT da.date as date, 
                       COUNT(*) as total_employees,
                       SUM(CASE WHEN da.status IN ('present','half_day') THEN 1 ELSE 0 END) as present,
                       SUM(CASE WHEN da.status = 'absent' THEN 1 ELSE 0 END) as absent,
                       {$lateExpr} as late_count
                FROM daily_attendance da
                WHERE da.date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
                GROUP BY da.date
                ORDER BY da.date DESC
            ");
            $stmt->execute();
            $attendanceData = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            error_log('Admin Reports attendance error: ' . $e->getMessage());
            $reportError = 'Attendance query error: ' . $e->getMessage();
        }
    }

    // Get overtime/undertime data
    if ($view === 'overtime_undertime') {
        try {
            // Detect OT column
            $cols = [];
            try { $colStmt = $db->query("SHOW COLUMNS FROM daily_attendance"); while ($c = $colStmt->fetch(PDO::FETCH_ASSOC)) { $cols[] = $c['Field']; } } catch (PDOException $e) {}
            $otExpr = in_array('ot_hours', $cols)
                ? "ROUND(SUM(COALESCE(da.ot_hours, 0)), 2)"
                : "0";
            $utExpr = in_array('undertime_seconds', $cols)
                ? "ROUND(SUM(COALESCE(da.undertime_seconds, 0)) / 3600, 2)"
                : "0";

            $stmt = $db->prepare("
                SELECT e.employee_number, e.first_name, e.last_name,
                       {$otExpr} as total_ot_hours,
                       {$utExpr} as total_undertime_hours
                FROM daily_attendance da
                JOIN employees e ON da.employee_id = e.id
                WHERE da.date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
                GROUP BY da.employee_id, e.employee_number, e.first_name, e.last_name
                ORDER BY total_ot_hours DESC
                LIMIT 50
            ");
            $stmt->execute();
            $overtimeData = $stmt->fetchAll(PDO::FETCH_ASSOC);
            // Filter out rows where both are zero
            $overtimeData = array_filter($overtimeData, function($r) {
                return (float)$r['total_ot_hours'] > 0 || (float)$r['total_undertime_hours'] > 0;
            });
            $overtimeData = array_values($overtimeData);
        } catch (PDOException $e) {
            error_log('Admin Reports overtime error: ' . $e->getMessage());
            $reportError = 'Overtime query error: ' . $e->getMessage();
        }
    }

    // Get leave utilization data
    if ($view === 'leave_utilization') {
        try {
            $stmt = $db->prepare("
                SELECT lt.name as leave_type,
                       COUNT(lr.id) as total_requests,
                       SUM(lr.total_days) as total_days,
                       ROUND(AVG(lr.total_days), 1) as avg_days
                FROM leave_requests lr
                JOIN leave_types lt ON lr.leave_type = lt.code
                WHERE lr.status = 'approved'
                  AND YEAR(lr.start_date) = YEAR(CURDATE())
                GROUP BY lr.leave_type, lt.name
                ORDER BY total_days DESC
            ");
            $stmt->execute();
            $leaveData = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            error_log('Admin Reports leave error: ' . $e->getMessage());
            $reportError = 'Leave query error: ' . $e->getMessage();
        }
    }

    // Get claims summary
    if ($view === 'claims_summary') {
        try {
            $stmt = $db->prepare("
                SELECT COALESCE(cc.name, 'Uncategorized') as claim_type,
                       COUNT(*) as total_claims,
                       ROUND(SUM(c.amount), 2) as total_amount,
                       ROUND(AVG(c.amount), 2) as avg_amount,
                       ROUND(SUM(CASE WHEN c.status IN ('approved','paid') THEN c.amount ELSE 0 END), 2) as approved_amount
                FROM claims c
                LEFT JOIN claim_categories cc ON c.category_id = cc.id
                WHERE YEAR(COALESCE(c.submitted_at, c.created_at)) = YEAR(CURDATE())
                GROUP BY c.category_id, cc.name
                ORDER BY total_amount DESC
            ");
            $stmt->execute();
            $claimsData = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            error_log('Admin Reports claims error: ' . $e->getMessage());
            $reportError = 'Claims query error: ' . $e->getMessage();
        }
    }
}

include __DIR__ . '/../../includes/header.php';
?>

<div class="mb-6">
    <h1 class="text-2xl font-semibold text-gray-900 dark:text-white">Reports</h1>
    <p class="mt-1 text-sm text-gray-600 dark:text-gray-400">Attendance, overtime, leave utilization, and claims summaries</p>
</div>

<?php if (!empty($reportError)): ?>
    <div class="mb-4 bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-800 text-red-800 dark:text-red-200 px-4 py-3 rounded-lg text-sm">
        <?php echo htmlspecialchars($reportError); ?>
    </div>
<?php endif; ?>

<?php if ($view === 'attendance_summary'): ?>
    <!-- Attendance Summary -->
    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-6">
        <!-- Attendance Trend Chart -->
        <div class="bg-white dark:bg-gray-800 rounded-xl shadow-sm border border-gray-100 dark:border-gray-700">
            <div class="px-5 py-4 border-b border-gray-100 dark:border-gray-700">
                <h2 class="text-sm font-semibold text-gray-900 dark:text-white">Attendance Trend (Last 30 Days)</h2>
            </div>
            <div class="p-5">
                <canvas id="attendanceTrendChart" height="250"></canvas>
            </div>
        </div>

        <!-- Status Distribution -->
        <div class="bg-white dark:bg-gray-800 rounded-xl shadow-sm border border-gray-100 dark:border-gray-700">
            <div class="px-5 py-4 border-b border-gray-100 dark:border-gray-700">
                <h2 class="text-sm font-semibold text-gray-900 dark:text-white">Status Distribution</h2>
            </div>
            <div class="p-5">
                <canvas id="statusDistributionChart" height="250"></canvas>
            </div>
        </div>
    </div>

    <!-- Attendance Summary Table -->
    <div class="bg-white dark:bg-gray-800 rounded-xl shadow-sm border border-gray-100 dark:border-gray-700">
        <div class="px-5 py-4 border-b border-gray-100 dark:border-gray-700 flex items-center justify-between">
            <h2 class="text-sm font-semibold text-gray-900 dark:text-white">Attendance Summary Table</h2>
            <div class="flex gap-2">
                <input type="date" id="filterDate" class="text-xs border border-gray-300 dark:border-gray-600 rounded px-3 py-1 dark:bg-gray-700 dark:text-white">
                <button onclick="exportAttendance()" class="px-3 py-1 text-xs border border-gray-300 dark:border-gray-600 rounded hover:bg-gray-50 dark:hover:bg-gray-700">
                    Export
                </button>
            </div>
        </div>
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                <thead class="bg-gray-50 dark:bg-gray-700">
                    <tr>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase">Date</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase">Total Employees</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase">Present</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase">Absent</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase">Late</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase">Attendance Rate</th>
                    </tr>
                </thead>
                <tbody class="bg-white dark:bg-gray-800 divide-y divide-gray-200 dark:divide-gray-700">
                    <?php if (empty($attendanceData)): ?>
                        <tr>
                            <td colspan="6" class="px-6 py-4 text-center text-sm text-gray-500">No attendance data found</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($attendanceData as $data): 
                            $attendanceRate = $data['total_employees'] > 0 ? ($data['present'] / $data['total_employees']) * 100 : 0;
                        ?>
                            <tr>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900 dark:text-white">
                                    <?php echo date('M d, Y', strtotime($data['date'])); ?>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900 dark:text-white">
                                    <?php echo $data['total_employees']; ?>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-emerald-600 font-semibold">
                                    <?php echo $data['present']; ?>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-red-600">
                                    <?php echo $data['absent']; ?>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-amber-600">
                                    <?php echo $data['late_count']; ?>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900 dark:text-white">
                                    <?php echo number_format($attendanceRate, 1); ?>%
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script>
        // Attendance Trend Chart
        const trendCtx = document.getElementById('attendanceTrendChart');
        if (trendCtx) {
            new Chart(trendCtx, {
                type: 'line',
                data: {
                    labels: <?php echo json_encode(array_column($attendanceData, 'date')); ?>,
                    datasets: [{
                        label: 'Present',
                        data: <?php echo json_encode(array_column($attendanceData, 'present')); ?>,
                        borderColor: 'rgb(34, 197, 94)',
                        backgroundColor: 'rgba(34, 197, 94, 0.1)',
                        tension: 0.4
                    }, {
                        label: 'Absent',
                        data: <?php echo json_encode(array_column($attendanceData, 'absent')); ?>,
                        borderColor: 'rgb(239, 68, 68)',
                        backgroundColor: 'rgba(239, 68, 68, 0.1)',
                        tension: 0.4
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: { position: 'top' }
                    }
                }
            });
        }

        // Status Distribution Chart
        const statusCtx = document.getElementById('statusDistributionChart');
        if (statusCtx) {
            const totalPresent = <?php echo array_sum(array_column($attendanceData, 'present')); ?>;
            const totalAbsent = <?php echo array_sum(array_column($attendanceData, 'absent')); ?>;
            const totalLate = <?php echo array_sum(array_column($attendanceData, 'late_count')); ?>;
            
            new Chart(statusCtx, {
                type: 'bar',
                data: {
                    labels: ['Present', 'Absent', 'Late'],
                    datasets: [{
                        label: 'Count',
                        data: [totalPresent, totalAbsent, totalLate],
                        backgroundColor: [
                            'rgb(34, 197, 94)',
                            'rgb(239, 68, 68)',
                            'rgb(245, 158, 11)'
                        ]
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: { display: false }
                    }
                }
            });
        }

        // Export Attendance
        function exportAttendance() {
            const filterDate = document.getElementById('filterDate')?.value;
            let params = 'type=attendance&format=csv';
            if (filterDate) {
                params += '&from=' + filterDate + '&to=' + filterDate;
            }
            const url = '<?php echo rtrim(BASE_URL, "/"); ?>/api/reports/export.php?' + params;
            window.location.href = url;
        }
    </script>

<?php elseif ($view === 'overtime_undertime'): ?>
    <!-- Overtime/Undertime Report -->
    <div class="bg-white dark:bg-gray-800 rounded-xl shadow-sm border border-gray-100 dark:border-gray-700 mb-6">
        <div class="px-5 py-4 border-b border-gray-100 dark:border-gray-700">
            <h2 class="text-sm font-semibold text-gray-900 dark:text-white">Overtime/Undertime Report</h2>
        </div>
        <div class="p-5">
            <canvas id="overtimeChart" height="100"></canvas>
        </div>
    </div>

    <div class="bg-white dark:bg-gray-800 rounded-xl shadow-sm border border-gray-100 dark:border-gray-700">
        <div class="px-5 py-4 border-b border-gray-100 dark:border-gray-700 flex items-center justify-between">
            <h2 class="text-sm font-semibold text-gray-900 dark:text-white">Overtime/Undertime Details</h2>
            <button onclick="exportOvertime()" class="px-3 py-1 text-xs border border-gray-300 dark:border-gray-600 rounded hover:bg-gray-50 dark:hover:bg-gray-700">
                Export
            </button>
        </div>
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                <thead class="bg-gray-50 dark:bg-gray-700">
                    <tr>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase">Employee</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase">Total OT Hours</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase">Total Undertime Hours</th>
                    </tr>
                </thead>
                <tbody class="bg-white dark:bg-gray-800 divide-y divide-gray-200 dark:divide-gray-700">
                    <?php if (empty($overtimeData)): ?>
                        <tr>
                            <td colspan="3" class="px-6 py-4 text-center text-sm text-gray-500">No overtime/undertime data found</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($overtimeData as $data): ?>
                            <tr>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900 dark:text-white">
                                    <?php echo htmlspecialchars($data['employee_number'] . ' - ' . $data['first_name'] . ' ' . $data['last_name']); ?>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-emerald-600 font-semibold">
                                    <?php echo number_format($data['total_ot_hours'], 1); ?> hrs
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-red-600">
                                    <?php echo number_format($data['total_undertime_hours'], 1); ?> hrs
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script>
        const otCtx = document.getElementById('overtimeChart');
        if (otCtx) {
            new Chart(otCtx, {
                type: 'bar',
                data: {
                    labels: <?php echo json_encode(array_map(fn($d) => $d['employee_number'], $overtimeData)); ?>,
                    datasets: [{
                        label: 'Overtime Hours',
                        data: <?php echo json_encode(array_column($overtimeData, 'total_ot_hours')); ?>,
                        backgroundColor: 'rgb(34, 197, 94)'
                    }, {
                        label: 'Undertime Hours',
                        data: <?php echo json_encode(array_column($overtimeData, 'total_undertime_hours')); ?>,
                        backgroundColor: 'rgb(239, 68, 68)'
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: { position: 'top' }
                    }
                }
            });
        }

        // Export Overtime
        function exportOvertime() {
            const url = '<?php echo rtrim(BASE_URL, "/"); ?>/api/reports/export.php?type=overtime&format=csv';
            window.location.href = url;
        }
    </script>

<?php elseif ($view === 'leave_utilization'): ?>
    <!-- Leave Utilization Report -->
    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-6">
        <div class="bg-white dark:bg-gray-800 rounded-xl shadow-sm border border-gray-100 dark:border-gray-700">
            <div class="px-5 py-4 border-b border-gray-100 dark:border-gray-700">
                <h2 class="text-sm font-semibold text-gray-900 dark:text-white">Leave Utilization by Type</h2>
            </div>
            <div class="p-5">
                <canvas id="leaveUtilizationChart" height="250"></canvas>
            </div>
        </div>

        <div class="bg-white dark:bg-gray-800 rounded-xl shadow-sm border border-gray-100 dark:border-gray-700">
            <div class="px-5 py-4 border-b border-gray-100 dark:border-gray-700">
                <h2 class="text-sm font-semibold text-gray-900 dark:text-white">Average Days per Request</h2>
            </div>
            <div class="p-5">
                <canvas id="avgDaysChart" height="250"></canvas>
            </div>
        </div>
    </div>

    <div class="bg-white dark:bg-gray-800 rounded-xl shadow-sm border border-gray-100 dark:border-gray-700">
        <div class="px-5 py-4 border-b border-gray-100 dark:border-gray-700">
            <h2 class="text-sm font-semibold text-gray-900 dark:text-white">Leave Utilization Details</h2>
        </div>
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                <thead class="bg-gray-50 dark:bg-gray-700">
                    <tr>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase">Leave Type</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase">Total Requests</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase">Total Days</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase">Average Days</th>
                    </tr>
                </thead>
                <tbody class="bg-white dark:bg-gray-800 divide-y divide-gray-200 dark:divide-gray-700">
                    <?php if (empty($leaveData)): ?>
                        <tr>
                            <td colspan="4" class="px-6 py-4 text-center text-sm text-gray-500">No leave utilization data found</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($leaveData as $data): ?>
                            <tr>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900 dark:text-white font-semibold">
                                    <?php echo htmlspecialchars($data['leave_type']); ?>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900 dark:text-white">
                                    <?php echo $data['total_requests']; ?>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900 dark:text-white">
                                    <?php echo number_format($data['total_days'], 1); ?>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900 dark:text-white">
                                    <?php echo number_format($data['avg_days'], 1); ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script>
        // Leave Utilization Chart
        const leaveCtx = document.getElementById('leaveUtilizationChart');
        if (leaveCtx) {
            new Chart(leaveCtx, {
                type: 'pie',
                data: {
                    labels: <?php echo json_encode(array_column($leaveData, 'leave_type')); ?>,
                    datasets: [{
                        data: <?php echo json_encode(array_column($leaveData, 'total_days')); ?>,
                        backgroundColor: [
                            'rgb(59, 130, 246)',
                            'rgb(34, 197, 94)',
                            'rgb(245, 158, 11)',
                            'rgb(239, 68, 68)',
                            'rgb(168, 85, 247)'
                        ]
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: { position: 'bottom' }
                    }
                }
            });
        }

        // Average Days Chart
        const avgCtx = document.getElementById('avgDaysChart');
        if (avgCtx) {
            new Chart(avgCtx, {
                type: 'bar',
                data: {
                    labels: <?php echo json_encode(array_column($leaveData, 'leave_type')); ?>,
                    datasets: [{
                        label: 'Average Days',
                        data: <?php echo json_encode(array_column($leaveData, 'avg_days')); ?>,
                        backgroundColor: 'rgb(59, 130, 246)'
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: { display: false }
                    }
                }
            });
        }
    </script>

<?php elseif ($view === 'claims_summary'): ?>
    <!-- Claims Summary -->
    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-6">
        <div class="bg-white dark:bg-gray-800 rounded-xl shadow-sm border border-gray-100 dark:border-gray-700">
            <div class="px-5 py-4 border-b border-gray-100 dark:border-gray-700">
                <h2 class="text-sm font-semibold text-gray-900 dark:text-white">Claims by Type</h2>
            </div>
            <div class="p-5">
                <canvas id="claimsByTypeChart" height="250"></canvas>
            </div>
        </div>

        <div class="bg-white dark:bg-gray-800 rounded-xl shadow-sm border border-gray-100 dark:border-gray-700">
            <div class="px-5 py-4 border-b border-gray-100 dark:border-gray-700">
                <h2 class="text-sm font-semibold text-gray-900 dark:text-white">Amount Distribution</h2>
            </div>
            <div class="p-5">
                <canvas id="claimsAmountChart" height="250"></canvas>
            </div>
        </div>
    </div>

    <div class="bg-white dark:bg-gray-800 rounded-xl shadow-sm border border-gray-100 dark:border-gray-700">
        <div class="px-5 py-4 border-b border-gray-100 dark:border-gray-700">
            <h2 class="text-sm font-semibold text-gray-900 dark:text-white">Claims Summary Details</h2>
        </div>
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                <thead class="bg-gray-50 dark:bg-gray-700">
                    <tr>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase">Claim Type</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase">Total Claims</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase">Total Amount</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase">Average Amount</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase">Approved Amount</th>
                    </tr>
                </thead>
                <tbody class="bg-white dark:bg-gray-800 divide-y divide-gray-200 dark:divide-gray-700">
                    <?php if (empty($claimsData)): ?>
                        <tr>
                            <td colspan="5" class="px-6 py-4 text-center text-sm text-gray-500">No claims data found</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($claimsData as $data): ?>
                            <tr>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900 dark:text-white font-semibold">
                                    <?php echo htmlspecialchars(ucfirst($data['claim_type'])); ?>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900 dark:text-white">
                                    <?php echo $data['total_claims']; ?>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900 dark:text-white">
                                    PHP <?php echo number_format($data['total_amount'], 2); ?>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900 dark:text-white">
                                    PHP <?php echo number_format($data['avg_amount'], 2); ?>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-emerald-600 font-semibold">
                                    PHP <?php echo number_format($data['approved_amount'], 2); ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script>
        // Claims by Type Chart
        const claimsTypeCtx = document.getElementById('claimsByTypeChart');
        if (claimsTypeCtx) {
            new Chart(claimsTypeCtx, {
                type: 'doughnut',
                data: {
                    labels: <?php echo json_encode(array_column($claimsData, 'claim_type')); ?>,
                    datasets: [{
                        data: <?php echo json_encode(array_column($claimsData, 'total_claims')); ?>,
                        backgroundColor: [
                            'rgb(59, 130, 246)',
                            'rgb(34, 197, 94)',
                            'rgb(245, 158, 11)',
                            'rgb(239, 68, 68)',
                            'rgb(168, 85, 247)'
                        ]
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: { position: 'bottom' }
                    }
                }
            });
        }

        // Claims Amount Chart
        const claimsAmountCtx = document.getElementById('claimsAmountChart');
        if (claimsAmountCtx) {
            new Chart(claimsAmountCtx, {
                type: 'bar',
                data: {
                    labels: <?php echo json_encode(array_column($claimsData, 'claim_type')); ?>,
                    datasets: [{
                        label: 'Total Amount',
                        data: <?php echo json_encode(array_column($claimsData, 'total_amount')); ?>,
                        backgroundColor: 'rgb(59, 130, 246)'
                    }, {
                        label: 'Approved Amount',
                        data: <?php echo json_encode(array_column($claimsData, 'approved_amount')); ?>,
                        backgroundColor: 'rgb(34, 197, 94)'
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: { position: 'top' }
                    }
                }
            });
        }
    </script>

<?php elseif ($view === 'export'): ?>
    <!-- Export Center -->
    <?php
    // Gather export summaries
    $exportSummaries = [];
    if (isset($db)) {
        try {
            // Attendance export summary
            $row = $db->query("SELECT COUNT(*) as cnt, MIN(date) as earliest, MAX(date) as latest FROM daily_attendance")->fetch(PDO::FETCH_ASSOC);
            $exportSummaries['attendance'] = $row;

            // Employees
            $row = $db->query("SELECT COUNT(*) as cnt FROM employees WHERE deleted_at IS NULL")->fetch(PDO::FETCH_ASSOC);
            $exportSummaries['employees'] = $row;

            // Leave requests
            $row = $db->query("SELECT COUNT(*) as cnt, MIN(start_date) as earliest, MAX(end_date) as latest FROM leave_requests")->fetch(PDO::FETCH_ASSOC);
            $exportSummaries['leave'] = $row;

            // Claims
            $row = $db->query("SELECT COUNT(*) as cnt, ROUND(SUM(amount),2) as total_amount FROM claims")->fetch(PDO::FETCH_ASSOC);
            $exportSummaries['claims'] = $row;

            // Timesheets
            $row = $db->query("SELECT COUNT(*) as cnt FROM timesheets")->fetch(PDO::FETCH_ASSOC);
            $exportSummaries['timesheets'] = $row;

            // Overtime requests
            $row = $db->query("SELECT COUNT(*) as cnt FROM overtime_requests")->fetch(PDO::FETCH_ASSOC);
            $exportSummaries['overtime'] = $row;
        } catch (PDOException $e) { error_log('Export summary: ' . $e->getMessage()); }
    }
    ?>

    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6 mb-6">
        <!-- Attendance Export -->
        <div class="bg-white dark:bg-gray-800 rounded-xl shadow-sm border border-gray-100 dark:border-gray-700">
            <div class="px-5 py-4 border-b border-gray-100 dark:border-gray-700 flex items-center gap-3">
                <div class="w-10 h-10 rounded-lg bg-blue-100 dark:bg-blue-900/40 flex items-center justify-center">
                    <svg xmlns="http://www.w3.org/2000/svg" class="w-5 h-5 text-blue-600 dark:text-blue-400" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" /></svg>
                </div>
                <div>
                    <h3 class="text-sm font-semibold text-gray-900 dark:text-white">Attendance Records</h3>
                    <p class="text-xs text-gray-500"><?php echo number_format($exportSummaries['attendance']['cnt'] ?? 0); ?> records</p>
                </div>
            </div>
            <div class="p-5 space-y-3">
                <p class="text-xs text-gray-500 dark:text-gray-400">
                    Date range: <?php echo isset($exportSummaries['attendance']['earliest']) ? date('M d, Y', strtotime($exportSummaries['attendance']['earliest'])) . ' – ' . date('M d, Y', strtotime($exportSummaries['attendance']['latest'])) : 'N/A'; ?>
                </p>
                <div class="flex gap-2">
                    <input type="date" id="att_from" value="<?php echo date('Y-m-01'); ?>" class="flex-1 text-xs border border-gray-300 dark:border-gray-600 rounded px-2 py-1.5 dark:bg-gray-700 dark:text-white">
                    <input type="date" id="att_to" value="<?php echo date('Y-m-d'); ?>" class="flex-1 text-xs border border-gray-300 dark:border-gray-600 rounded px-2 py-1.5 dark:bg-gray-700 dark:text-white">
                </div>
                <div class="flex gap-2">
                    <button onclick="exportData('attendance','csv')" class="flex-1 px-3 py-2 text-xs bg-emerald-600 text-white rounded-lg hover:bg-emerald-700 transition font-medium">CSV</button>
                    <button onclick="exportData('attendance','pdf')" class="flex-1 px-3 py-2 text-xs bg-red-600 text-white rounded-lg hover:bg-red-700 transition font-medium">PDF</button>
                </div>
            </div>
        </div>

        <!-- Employee Masterlist Export -->
        <div class="bg-white dark:bg-gray-800 rounded-xl shadow-sm border border-gray-100 dark:border-gray-700">
            <div class="px-5 py-4 border-b border-gray-100 dark:border-gray-700 flex items-center gap-3">
                <div class="w-10 h-10 rounded-lg bg-purple-100 dark:bg-purple-900/40 flex items-center justify-center">
                    <svg xmlns="http://www.w3.org/2000/svg" class="w-5 h-5 text-purple-600 dark:text-purple-400" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0z" /></svg>
                </div>
                <div>
                    <h3 class="text-sm font-semibold text-gray-900 dark:text-white">Employee Masterlist</h3>
                    <p class="text-xs text-gray-500"><?php echo number_format($exportSummaries['employees']['cnt'] ?? 0); ?> employees</p>
                </div>
            </div>
            <div class="p-5 space-y-3">
                <p class="text-xs text-gray-500 dark:text-gray-400">Export all active employees with unit assignments, roles, and hire dates.</p>
                <div class="flex gap-2">
                    <button onclick="exportData('employees','csv')" class="flex-1 px-3 py-2 text-xs bg-emerald-600 text-white rounded-lg hover:bg-emerald-700 transition font-medium">CSV</button>
                    <button onclick="exportData('employees','pdf')" class="flex-1 px-3 py-2 text-xs bg-red-600 text-white rounded-lg hover:bg-red-700 transition font-medium">PDF</button>
                </div>
            </div>
        </div>

        <!-- Leave Requests Export -->
        <div class="bg-white dark:bg-gray-800 rounded-xl shadow-sm border border-gray-100 dark:border-gray-700">
            <div class="px-5 py-4 border-b border-gray-100 dark:border-gray-700 flex items-center gap-3">
                <div class="w-10 h-10 rounded-lg bg-amber-100 dark:bg-amber-900/40 flex items-center justify-center">
                    <svg xmlns="http://www.w3.org/2000/svg" class="w-5 h-5 text-amber-600 dark:text-amber-400" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z" /></svg>
                </div>
                <div>
                    <h3 class="text-sm font-semibold text-gray-900 dark:text-white">Leave Requests</h3>
                    <p class="text-xs text-gray-500"><?php echo number_format($exportSummaries['leave']['cnt'] ?? 0); ?> requests</p>
                </div>
            </div>
            <div class="p-5 space-y-3">
                <p class="text-xs text-gray-500 dark:text-gray-400">
                    Range: <?php echo isset($exportSummaries['leave']['earliest']) ? date('M d, Y', strtotime($exportSummaries['leave']['earliest'])) . ' – ' . date('M d, Y', strtotime($exportSummaries['leave']['latest'])) : 'N/A'; ?>
                </p>
                <div class="flex gap-2">
                    <button onclick="exportData('leave','csv')" class="flex-1 px-3 py-2 text-xs bg-emerald-600 text-white rounded-lg hover:bg-emerald-700 transition font-medium">CSV</button>
                    <button onclick="exportData('leave','pdf')" class="flex-1 px-3 py-2 text-xs bg-red-600 text-white rounded-lg hover:bg-red-700 transition font-medium">PDF</button>
                </div>
            </div>
        </div>

        <!-- Claims Export -->
        <div class="bg-white dark:bg-gray-800 rounded-xl shadow-sm border border-gray-100 dark:border-gray-700">
            <div class="px-5 py-4 border-b border-gray-100 dark:border-gray-700 flex items-center gap-3">
                <div class="w-10 h-10 rounded-lg bg-green-100 dark:bg-green-900/40 flex items-center justify-center">
                    <svg xmlns="http://www.w3.org/2000/svg" class="w-5 h-5 text-green-600 dark:text-green-400" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z" /></svg>
                </div>
                <div>
                    <h3 class="text-sm font-semibold text-gray-900 dark:text-white">Claims & Reimbursement</h3>
                    <p class="text-xs text-gray-500"><?php echo number_format($exportSummaries['claims']['cnt'] ?? 0); ?> claims &middot; PHP <?php echo number_format($exportSummaries['claims']['total_amount'] ?? 0, 2); ?></p>
                </div>
            </div>
            <div class="p-5 space-y-3">
                <select id="claim_status" class="w-full text-xs border border-gray-300 dark:border-gray-600 rounded px-2 py-1.5 dark:bg-gray-700 dark:text-white">
                    <option value="">All Statuses</option>
                    <option value="submitted">Submitted</option>
                    <option value="approved">Approved</option>
                    <option value="paid">Paid</option>
                    <option value="rejected">Rejected</option>
                </select>
                <div class="flex gap-2">
                    <button onclick="exportData('claims','csv')" class="flex-1 px-3 py-2 text-xs bg-emerald-600 text-white rounded-lg hover:bg-emerald-700 transition font-medium">CSV</button>
                    <button onclick="exportData('claims','pdf')" class="flex-1 px-3 py-2 text-xs bg-red-600 text-white rounded-lg hover:bg-red-700 transition font-medium">PDF</button>
                </div>
            </div>
        </div>

        <!-- Timesheets Export -->
        <div class="bg-white dark:bg-gray-800 rounded-xl shadow-sm border border-gray-100 dark:border-gray-700">
            <div class="px-5 py-4 border-b border-gray-100 dark:border-gray-700 flex items-center gap-3">
                <div class="w-10 h-10 rounded-lg bg-sky-100 dark:bg-sky-900/40 flex items-center justify-center">
                    <svg xmlns="http://www.w3.org/2000/svg" class="w-5 h-5 text-sky-600 dark:text-sky-400" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 17v-2m3 2v-4m3 4v-6m2 10H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" /></svg>
                </div>
                <div>
                    <h3 class="text-sm font-semibold text-gray-900 dark:text-white">Timesheets</h3>
                    <p class="text-xs text-gray-500"><?php echo number_format($exportSummaries['timesheets']['cnt'] ?? 0); ?> timesheets</p>
                </div>
            </div>
            <div class="p-5 space-y-3">
                <p class="text-xs text-gray-500 dark:text-gray-400">Export timesheet summaries with hours, overtime, and approval status.</p>
                <div class="flex gap-2">
                    <button onclick="exportData('timesheets','csv')" class="flex-1 px-3 py-2 text-xs bg-emerald-600 text-white rounded-lg hover:bg-emerald-700 transition font-medium">CSV</button>
                    <button onclick="exportData('timesheets','pdf')" class="flex-1 px-3 py-2 text-xs bg-red-600 text-white rounded-lg hover:bg-red-700 transition font-medium">PDF</button>
                </div>
            </div>
        </div>

        <!-- Overtime Requests Export -->
        <div class="bg-white dark:bg-gray-800 rounded-xl shadow-sm border border-gray-100 dark:border-gray-700">
            <div class="px-5 py-4 border-b border-gray-100 dark:border-gray-700 flex items-center gap-3">
                <div class="w-10 h-10 rounded-lg bg-rose-100 dark:bg-rose-900/40 flex items-center justify-center">
                    <svg xmlns="http://www.w3.org/2000/svg" class="w-5 h-5 text-rose-600 dark:text-rose-400" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z" /></svg>
                </div>
                <div>
                    <h3 class="text-sm font-semibold text-gray-900 dark:text-white">Overtime Requests</h3>
                    <p class="text-xs text-gray-500"><?php echo number_format($exportSummaries['overtime']['cnt'] ?? 0); ?> requests</p>
                </div>
            </div>
            <div class="p-5 space-y-3">
                <p class="text-xs text-gray-500 dark:text-gray-400">Export all overtime request records with approval status.</p>
                <div class="flex gap-2">
                    <button onclick="exportData('overtime','csv')" class="flex-1 px-3 py-2 text-xs bg-emerald-600 text-white rounded-lg hover:bg-emerald-700 transition font-medium">CSV</button>
                    <button onclick="exportData('overtime','pdf')" class="flex-1 px-3 py-2 text-xs bg-red-600 text-white rounded-lg hover:bg-red-700 transition font-medium">PDF</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Export Activity Log -->
    <div class="bg-white dark:bg-gray-800 rounded-xl shadow-sm border border-gray-100 dark:border-gray-700">
        <div class="px-5 py-4 border-b border-gray-100 dark:border-gray-700">
            <h2 class="text-sm font-semibold text-gray-900 dark:text-white">Quick Export Summary</h2>
        </div>
        <div class="p-5">
            <div class="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-6 gap-4 text-center">
                <div class="p-3 bg-gray-50 dark:bg-gray-700/50 rounded-lg">
                    <p class="text-2xl font-bold text-blue-600 dark:text-blue-400"><?php echo number_format($exportSummaries['attendance']['cnt'] ?? 0); ?></p>
                    <p class="text-xs text-gray-500 mt-1">Attendance</p>
                </div>
                <div class="p-3 bg-gray-50 dark:bg-gray-700/50 rounded-lg">
                    <p class="text-2xl font-bold text-purple-600 dark:text-purple-400"><?php echo number_format($exportSummaries['employees']['cnt'] ?? 0); ?></p>
                    <p class="text-xs text-gray-500 mt-1">Employees</p>
                </div>
                <div class="p-3 bg-gray-50 dark:bg-gray-700/50 rounded-lg">
                    <p class="text-2xl font-bold text-amber-600 dark:text-amber-400"><?php echo number_format($exportSummaries['leave']['cnt'] ?? 0); ?></p>
                    <p class="text-xs text-gray-500 mt-1">Leave Requests</p>
                </div>
                <div class="p-3 bg-gray-50 dark:bg-gray-700/50 rounded-lg">
                    <p class="text-2xl font-bold text-green-600 dark:text-green-400"><?php echo number_format($exportSummaries['claims']['cnt'] ?? 0); ?></p>
                    <p class="text-xs text-gray-500 mt-1">Claims</p>
                </div>
                <div class="p-3 bg-gray-50 dark:bg-gray-700/50 rounded-lg">
                    <p class="text-2xl font-bold text-sky-600 dark:text-sky-400"><?php echo number_format($exportSummaries['timesheets']['cnt'] ?? 0); ?></p>
                    <p class="text-xs text-gray-500 mt-1">Timesheets</p>
                </div>
                <div class="p-3 bg-gray-50 dark:bg-gray-700/50 rounded-lg">
                    <p class="text-2xl font-bold text-rose-600 dark:text-rose-400"><?php echo number_format($exportSummaries['overtime']['cnt'] ?? 0); ?></p>
                    <p class="text-xs text-gray-500 mt-1">OT Requests</p>
                </div>
            </div>
        </div>
    </div>

    <!-- Export download status -->
    <div id="exportStatus" class="hidden mt-4 p-4 rounded-lg border text-sm"></div>

    <script>
    function exportData(type, format) {
        const statusEl = document.getElementById('exportStatus');
        statusEl.className = 'mt-4 p-4 rounded-lg border text-sm bg-blue-50 dark:bg-blue-900/20 border-blue-200 dark:border-blue-800 text-blue-800 dark:text-blue-200';
        statusEl.innerHTML = '<div class="flex items-center gap-2"><svg class="animate-spin h-4 w-4" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path></svg> Generating ' + type + ' ' + format.toUpperCase() + ' export...</div>';
        statusEl.classList.remove('hidden');

        let params = 'type=' + encodeURIComponent(type) + '&format=' + encodeURIComponent(format);
        if (type === 'attendance') {
            const from = document.getElementById('att_from')?.value;
            const to = document.getElementById('att_to')?.value;
            if (from) params += '&from=' + from;
            if (to) params += '&to=' + to;
        }
        if (type === 'claims') {
            const st = document.getElementById('claim_status')?.value;
            if (st) params += '&status=' + encodeURIComponent(st);
        }

        const url = '<?php echo rtrim(BASE_URL, "/"); ?>/api/reports/export.php?' + params;

        if (format === 'pdf') {
            window.open(url, '_blank');
        } else {
            // Use a hidden iframe to trigger download without navigating away
            let iframe = document.getElementById('exportIframe');
            if (!iframe) {
                iframe = document.createElement('iframe');
                iframe.id = 'exportIframe';
                iframe.style.display = 'none';
                document.body.appendChild(iframe);
            }
            iframe.src = url;
        }

        setTimeout(() => {
            statusEl.className = 'mt-4 p-4 rounded-lg border text-sm bg-emerald-50 dark:bg-emerald-900/20 border-emerald-200 dark:border-emerald-800 text-emerald-800 dark:text-emerald-200';
            statusEl.innerHTML = '&#10003; ' + (format === 'pdf' ? 'PDF opened in new tab. Use Print > Save as PDF.' : 'CSV download started.');
        }, 1000);
    }
    </script>
<?php endif; ?>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
