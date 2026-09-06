<?php
require_once __DIR__ . '/../../config/config.php';
requireAuth();
checkRole(['employee', 'supervisor', 'unit_head']);

$page_title = 'My Reports';

$employeeId = getCurrentEmployeeId($db ?? null);
if (!$employeeId) {
    $employeeId = $_SESSION['employee_id'] ?? $_SESSION['user_id'] ?? null;
}

if (!$employeeId) {
    $_SESSION['error'] = "Employee identifier not found. Please log in again.";
    header("Location: " . BASE_URL . "/auth/employee-login.php");
    exit();
}

// Date range filter
$range = $_GET['range'] ?? 'this_month';
switch ($range) {
    case 'this_week':
        $startDate = date('Y-m-d', strtotime('monday this week'));
        $endDate   = date('Y-m-d');
        $rangeLabel = 'This Week';
        break;
    case 'last_month':
        $startDate = date('Y-m-01', strtotime('first day of last month'));
        $endDate   = date('Y-m-t', strtotime('last day of last month'));
        $rangeLabel = 'Last Month';
        break;
    case 'this_year':
        $startDate = date('Y-01-01');
        $endDate   = date('Y-m-d');
        $rangeLabel = 'Year to Date';
        break;
    case 'custom':
        $startDate = $_GET['start_date'] ?? date('Y-m-01');
        $endDate   = $_GET['end_date'] ?? date('Y-m-d');
        $rangeLabel = date('M d', strtotime($startDate)) . ' – ' . date('M d, Y', strtotime($endDate));
        break;
    default: // this_month
        $startDate = date('Y-m-01');
        $endDate   = date('Y-m-d');
        $rangeLabel = 'This Month';
        break;
}

// Initialize report data
$attendanceSummary = ['total_days' => 0, 'present' => 0, 'late' => 0, 'absent' => 0, 'total_hours' => 0];
$leaveSummary = [];
$claimsSummary = ['total_filed' => 0, 'total_amount' => 0, 'approved_amount' => 0, 'pending' => 0, 'approved' => 0, 'rejected' => 0];
$timesheetSummary = ['total_hours' => 0, 'overtime_hours' => 0, 'regular_hours' => 0];

if (isset($db)) {
    try {
        // ── Attendance Summary ──
        $check = $db->query("SHOW TABLES LIKE 'daily_attendance'");
        if ($check && $check->rowCount() > 0) {
            $stmt = $db->prepare("
                SELECT 
                    COUNT(*) as total_days,
                    SUM(CASE WHEN status IN ('present','half_day') THEN 1 ELSE 0 END) as present,
                    SUM(CASE WHEN COALESCE(late_seconds,0) > 0 THEN 1 ELSE 0 END) as late,
                    SUM(CASE WHEN status = 'absent' THEN 1 ELSE 0 END) as absent,
                    ROUND(SUM(COALESCE(total_work_seconds,0)) / 3600, 1) as total_hours
                FROM daily_attendance
                WHERE employee_id = ? AND date BETWEEN ? AND ?
            ");
            $stmt->execute([$employeeId, $startDate, $endDate]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($row) {
                $attendanceSummary['total_days']  = (int)($row['total_days'] ?? 0);
                $attendanceSummary['present']     = (int)($row['present'] ?? 0);
                $attendanceSummary['late']        = (int)($row['late'] ?? 0);
                $attendanceSummary['absent']      = (int)($row['absent'] ?? 0);
                $attendanceSummary['total_hours'] = (float)($row['total_hours'] ?? 0);
            }
        }

        // ── Leave Summary ──
        $check = $db->query("SHOW TABLES LIKE 'leave_requests'");
        if ($check && $check->rowCount() > 0) {
            $stmt = $db->prepare("
                SELECT leave_type, status, COUNT(*) as cnt, SUM(total_days) as days
                FROM leave_requests
                WHERE employee_id = ?
                  AND ((start_date BETWEEN ? AND ?) OR (end_date BETWEEN ? AND ?))
                GROUP BY leave_type, status
            ");
            $stmt->execute([$employeeId, $startDate, $endDate, $startDate, $endDate]);
            $leaveSummary = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }

        // ── Leave Balances ──
        $leaveBalances = [];
        $check = $db->query("SHOW TABLES LIKE 'leave_balances'");
        if ($check && $check->rowCount() > 0) {
            $stmt = $db->prepare("
                SELECT lb.*, lt.name as leave_type_name
                FROM leave_balances lb
                LEFT JOIN leave_types lt ON lb.leave_type = lt.code
                WHERE lb.employee_id = ? AND lb.year = YEAR(CURDATE())
            ");
            $stmt->execute([$employeeId]);
            $leaveBalances = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }

        // ── Claims Summary ──
        $check = $db->query("SHOW TABLES LIKE 'claims'");
        if ($check && $check->rowCount() > 0) {
            $stmt = $db->prepare("
                SELECT 
                    COUNT(*) as total_filed,
                    COALESCE(SUM(amount), 0) as total_amount,
                    COALESCE(SUM(CASE WHEN status = 'approved' OR status = 'paid' THEN amount ELSE 0 END), 0) as approved_amount,
                    SUM(CASE WHEN status IN ('submitted','pending') THEN 1 ELSE 0 END) as pending,
                    SUM(CASE WHEN status IN ('approved','paid') THEN 1 ELSE 0 END) as approved,
                    SUM(CASE WHEN status = 'rejected' THEN 1 ELSE 0 END) as rejected
                FROM claims
                WHERE employee_id = ?
                  AND created_at BETWEEN ? AND CONCAT(?, ' 23:59:59')
            ");
            $stmt->execute([$employeeId, $startDate, $endDate]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($row) {
                $claimsSummary = [
                    'total_filed'     => (int)($row['total_filed'] ?? 0),
                    'total_amount'    => (float)($row['total_amount'] ?? 0),
                    'approved_amount' => (float)($row['approved_amount'] ?? 0),
                    'pending'         => (int)($row['pending'] ?? 0),
                    'approved'        => (int)($row['approved'] ?? 0),
                    'rejected'        => (int)($row['rejected'] ?? 0),
                ];
            }
        }

        // ── Timesheet Summary ──
        $check = $db->query("SHOW TABLES LIKE 'timesheets'");
        if ($check && $check->rowCount() > 0) {
            $stmt = $db->prepare("
                SELECT 
                    COALESCE(SUM(total_hours), 0) as total_hours,
                    COALESCE(SUM(total_ot_hours), 0) as overtime_hours,
                    COALESCE(SUM(total_hours) - SUM(total_ot_hours), 0) as regular_hours
                FROM timesheets
                WHERE employee_id = ?
                  AND period_start >= ? AND period_end <= ?
            ");
            $stmt->execute([$employeeId, $startDate, $endDate]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($row) {
                $timesheetSummary = [
                    'total_hours'    => (float)($row['total_hours'] ?? 0),
                    'overtime_hours' => (float)($row['overtime_hours'] ?? 0),
                    'regular_hours'  => (float)($row['regular_hours'] ?? 0),
                ];
            }
        }

    } catch (PDOException $e) {
        error_log('Employee reports error: ' . $e->getMessage());
    }
}

include __DIR__ . '/../../includes/header.php';
?>

<div class="mb-6">
    <h1 class="text-2xl font-semibold text-gray-900 dark:text-white">My Reports</h1>
    <p class="mt-1 text-sm text-gray-600 dark:text-gray-400">Personal attendance, leave, claims, and timesheet reports</p>
</div>

<!-- Date Range Filter -->
<div class="bg-white dark:bg-gray-800 rounded-xl shadow-sm border border-gray-100 dark:border-gray-700 mb-6">
    <div class="px-5 py-4 flex flex-wrap items-center gap-3">
        <span class="text-sm font-medium text-gray-700 dark:text-gray-300">Period:</span>
        <div class="flex flex-wrap gap-2">
            <?php
            $ranges = [
                'this_week'  => 'This Week',
                'this_month' => 'This Month',
                'last_month' => 'Last Month',
                'this_year'  => 'Year to Date',
            ];
            foreach ($ranges as $key => $label): ?>
                <a href="?range=<?php echo $key; ?>"
                   class="px-3 py-1.5 text-xs font-medium rounded-lg transition-colors <?php echo $range === $key ? 'bg-primary-600 text-white' : 'bg-gray-100 dark:bg-gray-700 text-gray-700 dark:text-gray-300 hover:bg-gray-200 dark:hover:bg-gray-600'; ?>">
                    <?php echo $label; ?>
                </a>
            <?php endforeach; ?>
        </div>
        <form method="GET" class="flex items-center gap-2 ml-auto">
            <input type="hidden" name="range" value="custom">
            <input type="date" name="start_date" value="<?php echo htmlspecialchars($startDate); ?>"
                   class="px-2 py-1 text-xs border border-gray-300 dark:border-gray-600 rounded-lg dark:bg-gray-700 dark:text-white">
            <span class="text-xs text-gray-500">to</span>
            <input type="date" name="end_date" value="<?php echo htmlspecialchars($endDate); ?>"
                   class="px-2 py-1 text-xs border border-gray-300 dark:border-gray-600 rounded-lg dark:bg-gray-700 dark:text-white">
            <button type="submit" class="px-3 py-1.5 text-xs font-medium bg-primary-600 text-white rounded-lg hover:bg-primary-700 transition-colors">
                Apply
            </button>
        </form>
    </div>
</div>

<!-- Summary Cards -->
<div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4 mb-6">
    <!-- Attendance Days -->
    <div class="bg-white dark:bg-gray-800 rounded-xl shadow-sm border border-gray-100 dark:border-gray-700 p-5">
        <div class="flex items-center justify-between">
            <div>
                <p class="text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wide">Days Present</p>
                <p class="mt-2 text-2xl font-bold text-gray-900 dark:text-white"><?php echo $attendanceSummary['present']; ?></p>
                <p class="mt-1 text-xs text-gray-500"><?php echo $rangeLabel; ?></p>
            </div>
            <div class="w-12 h-12 rounded-lg bg-emerald-100 dark:bg-emerald-900 flex items-center justify-center">
                <svg xmlns="http://www.w3.org/2000/svg" class="w-6 h-6 text-emerald-600 dark:text-emerald-300" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" />
                </svg>
            </div>
        </div>
    </div>

    <!-- Total Hours Worked -->
    <div class="bg-white dark:bg-gray-800 rounded-xl shadow-sm border border-gray-100 dark:border-gray-700 p-5">
        <div class="flex items-center justify-between">
            <div>
                <p class="text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wide">Hours Worked</p>
                <p class="mt-2 text-2xl font-bold text-gray-900 dark:text-white"><?php echo number_format($attendanceSummary['total_hours'], 1); ?></p>
                <p class="mt-1 text-xs text-gray-500"><?php echo $rangeLabel; ?></p>
            </div>
            <div class="w-12 h-12 rounded-lg bg-blue-100 dark:bg-blue-900 flex items-center justify-center">
                <svg xmlns="http://www.w3.org/2000/svg" class="w-6 h-6 text-blue-600 dark:text-blue-300" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" />
                </svg>
            </div>
        </div>
    </div>

    <!-- Late Count -->
    <div class="bg-white dark:bg-gray-800 rounded-xl shadow-sm border border-gray-100 dark:border-gray-700 p-5">
        <div class="flex items-center justify-between">
            <div>
                <p class="text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wide">Late Arrivals</p>
                <p class="mt-2 text-2xl font-bold text-gray-900 dark:text-white"><?php echo $attendanceSummary['late']; ?></p>
                <p class="mt-1 text-xs text-gray-500"><?php echo $rangeLabel; ?></p>
            </div>
            <div class="w-12 h-12 rounded-lg bg-amber-100 dark:bg-amber-900 flex items-center justify-center">
                <svg xmlns="http://www.w3.org/2000/svg" class="w-6 h-6 text-amber-600 dark:text-amber-300" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
                </svg>
            </div>
        </div>
    </div>

    <!-- Claims Filed -->
    <div class="bg-white dark:bg-gray-800 rounded-xl shadow-sm border border-gray-100 dark:border-gray-700 p-5">
        <div class="flex items-center justify-between">
            <div>
                <p class="text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wide">Claims Filed</p>
                <p class="mt-2 text-2xl font-bold text-gray-900 dark:text-white"><?php echo $claimsSummary['total_filed']; ?></p>
                <p class="mt-1 text-xs text-gray-500">PHP <?php echo number_format($claimsSummary['total_amount'], 2); ?></p>
            </div>
            <div class="w-12 h-12 rounded-lg bg-purple-100 dark:bg-purple-900 flex items-center justify-center">
                <svg xmlns="http://www.w3.org/2000/svg" class="w-6 h-6 text-purple-600 dark:text-purple-300" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
                </svg>
            </div>
        </div>
    </div>
</div>

<!-- Detailed Reports Grid -->
<div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-6">

    <!-- Attendance Report -->
    <div class="bg-white dark:bg-gray-800 rounded-xl shadow-sm border border-gray-100 dark:border-gray-700">
        <div class="px-5 py-4 border-b border-gray-100 dark:border-gray-700 flex items-center justify-between">
            <h2 class="text-sm font-semibold text-gray-900 dark:text-white">Attendance Report</h2>
            <a href="<?php echo BASE_URL; ?>/employee/modules/employee-timeclock.php?view=attendance_log"
               class="text-xs text-primary-600 hover:text-primary-700">View Logs →</a>
        </div>
        <div class="p-5">
            <div class="space-y-4">
                <div class="flex items-center justify-between">
                    <span class="text-sm text-gray-600 dark:text-gray-400">Total Working Days</span>
                    <span class="text-sm font-semibold text-gray-900 dark:text-white"><?php echo $attendanceSummary['total_days']; ?></span>
                </div>
                <div class="flex items-center justify-between">
                    <span class="text-sm text-gray-600 dark:text-gray-400">Days Present</span>
                    <span class="text-sm font-semibold text-emerald-600"><?php echo $attendanceSummary['present']; ?></span>
                </div>
                <div class="flex items-center justify-between">
                    <span class="text-sm text-gray-600 dark:text-gray-400">Late Arrivals</span>
                    <span class="text-sm font-semibold text-amber-600"><?php echo $attendanceSummary['late']; ?></span>
                </div>
                <div class="flex items-center justify-between">
                    <span class="text-sm text-gray-600 dark:text-gray-400">Total Hours Worked</span>
                    <span class="text-sm font-semibold text-blue-600"><?php echo number_format($attendanceSummary['total_hours'], 1); ?> hrs</span>
                </div>
                <?php if ($attendanceSummary['total_days'] > 0): ?>
                    <div class="pt-3 border-t border-gray-100 dark:border-gray-700">
                        <div class="flex items-center justify-between mb-1">
                            <span class="text-xs text-gray-500">Attendance Rate</span>
                            <span class="text-xs font-semibold text-gray-900 dark:text-white">
                                <?php echo round(($attendanceSummary['present'] / $attendanceSummary['total_days']) * 100, 1); ?>%
                            </span>
                        </div>
                        <div class="w-full bg-gray-200 dark:bg-gray-700 rounded-full h-2">
                            <div class="bg-emerald-500 h-2 rounded-full transition-all duration-300"
                                 style="width: <?php echo min(100, round(($attendanceSummary['present'] / $attendanceSummary['total_days']) * 100)); ?>%"></div>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Leave Report -->
    <div class="bg-white dark:bg-gray-800 rounded-xl shadow-sm border border-gray-100 dark:border-gray-700">
        <div class="px-5 py-4 border-b border-gray-100 dark:border-gray-700 flex items-center justify-between">
            <h2 class="text-sm font-semibold text-gray-900 dark:text-white">Leave Report</h2>
            <a href="<?php echo BASE_URL; ?>/employee/modules/employee-leave_management.php?view=leave_balances"
               class="text-xs text-primary-600 hover:text-primary-700">View Balances →</a>
        </div>
        <div class="p-5">
            <?php if (!empty($leaveBalances)): ?>
                <div class="space-y-3">
                    <?php foreach ($leaveBalances as $lb): ?>
                        <div>
                            <div class="flex items-center justify-between mb-1">
                                <span class="text-sm text-gray-600 dark:text-gray-400"><?php echo htmlspecialchars($lb['leave_type_name'] ?? 'Leave Type'); ?></span>
                                <span class="text-xs text-gray-500">
                                    <?php echo ($lb['entitlement'] - $lb['used'] - $lb['pending']); ?> / <?php echo $lb['entitlement']; ?> days left
                                </span>
                            </div>
                            <div class="w-full bg-gray-200 dark:bg-gray-700 rounded-full h-2">
                                <?php $usedPct = $lb['entitlement'] > 0 ? min(100, round((($lb['used'] + $lb['pending']) / $lb['entitlement']) * 100)) : 0; ?>
                                <div class="bg-blue-500 h-2 rounded-full transition-all duration-300" style="width: <?php echo $usedPct; ?>%"></div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php elseif (!empty($leaveSummary)): ?>
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                        <thead>
                            <tr>
                                <th class="px-3 py-2 text-left text-xs font-medium text-gray-500 uppercase">Type</th>
                                <th class="px-3 py-2 text-left text-xs font-medium text-gray-500 uppercase">Status</th>
                                <th class="px-3 py-2 text-right text-xs font-medium text-gray-500 uppercase">Count</th>
                                <th class="px-3 py-2 text-right text-xs font-medium text-gray-500 uppercase">Days</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-200 dark:divide-gray-700">
                            <?php foreach ($leaveSummary as $ls): ?>
                                <tr>
                                    <td class="px-3 py-2 text-sm text-gray-900 dark:text-white"><?php echo htmlspecialchars($ls['leave_type']); ?></td>
                                    <td class="px-3 py-2 text-sm">
                                        <span class="px-2 py-0.5 text-xs rounded-full <?php
                                            echo $ls['status'] === 'approved' ? 'bg-emerald-100 text-emerald-800' :
                                                ($ls['status'] === 'pending' ? 'bg-amber-100 text-amber-800' : 'bg-red-100 text-red-800');
                                        ?>"><?php echo ucfirst($ls['status']); ?></span>
                                    </td>
                                    <td class="px-3 py-2 text-sm text-right text-gray-900 dark:text-white"><?php echo $ls['cnt']; ?></td>
                                    <td class="px-3 py-2 text-sm text-right text-gray-900 dark:text-white"><?php echo $ls['days'] ?? '-'; ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php else: ?>
                <div class="text-center py-8">
                    <svg xmlns="http://www.w3.org/2000/svg" class="w-10 h-10 mx-auto text-gray-400 mb-2" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z" />
                    </svg>
                    <p class="text-sm text-gray-500">No leave data for this period</p>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Claims Report -->
    <div class="bg-white dark:bg-gray-800 rounded-xl shadow-sm border border-gray-100 dark:border-gray-700">
        <div class="px-5 py-4 border-b border-gray-100 dark:border-gray-700 flex items-center justify-between">
            <h2 class="text-sm font-semibold text-gray-900 dark:text-white">Claims Report</h2>
            <a href="<?php echo BASE_URL; ?>/employee/modules/employee-claims.php?view=claim_status_history"
               class="text-xs text-primary-600 hover:text-primary-700">View Claims →</a>
        </div>
        <div class="p-5">
            <div class="space-y-4">
                <div class="flex items-center justify-between">
                    <span class="text-sm text-gray-600 dark:text-gray-400">Total Claims Filed</span>
                    <span class="text-sm font-semibold text-gray-900 dark:text-white"><?php echo $claimsSummary['total_filed']; ?></span>
                </div>
                <div class="flex items-center justify-between">
                    <span class="text-sm text-gray-600 dark:text-gray-400">Total Amount</span>
                    <span class="text-sm font-semibold text-gray-900 dark:text-white">PHP <?php echo number_format($claimsSummary['total_amount'], 2); ?></span>
                </div>
                <div class="flex items-center justify-between">
                    <span class="text-sm text-gray-600 dark:text-gray-400">Approved / Paid</span>
                    <span class="text-sm font-semibold text-emerald-600">PHP <?php echo number_format($claimsSummary['approved_amount'], 2); ?></span>
                </div>
                <div class="pt-3 border-t border-gray-100 dark:border-gray-700">
                    <div class="grid grid-cols-3 gap-3 text-center">
                        <div class="p-2 bg-amber-50 dark:bg-amber-900/20 rounded-lg">
                            <p class="text-lg font-bold text-amber-600"><?php echo $claimsSummary['pending']; ?></p>
                            <p class="text-xs text-gray-500">Pending</p>
                        </div>
                        <div class="p-2 bg-emerald-50 dark:bg-emerald-900/20 rounded-lg">
                            <p class="text-lg font-bold text-emerald-600"><?php echo $claimsSummary['approved']; ?></p>
                            <p class="text-xs text-gray-500">Approved</p>
                        </div>
                        <div class="p-2 bg-red-50 dark:bg-red-900/20 rounded-lg">
                            <p class="text-lg font-bold text-red-600"><?php echo $claimsSummary['rejected']; ?></p>
                            <p class="text-xs text-gray-500">Rejected</p>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Timesheet Report -->
    <div class="bg-white dark:bg-gray-800 rounded-xl shadow-sm border border-gray-100 dark:border-gray-700">
        <div class="px-5 py-4 border-b border-gray-100 dark:border-gray-700 flex items-center justify-between">
            <h2 class="text-sm font-semibold text-gray-900 dark:text-white">Timesheet Report</h2>
            <a href="<?php echo BASE_URL; ?>/employee/modules/employee-timesheets.php?view=history"
               class="text-xs text-primary-600 hover:text-primary-700">View History →</a>
        </div>
        <div class="p-5">
            <div class="space-y-4">
                <div class="flex items-center justify-between">
                    <span class="text-sm text-gray-600 dark:text-gray-400">Total Hours</span>
                    <span class="text-sm font-semibold text-gray-900 dark:text-white"><?php echo number_format($timesheetSummary['total_hours'], 1); ?> hrs</span>
                </div>
                <div class="flex items-center justify-between">
                    <span class="text-sm text-gray-600 dark:text-gray-400">Regular Hours</span>
                    <span class="text-sm font-semibold text-blue-600"><?php echo number_format($timesheetSummary['regular_hours'], 1); ?> hrs</span>
                </div>
                <div class="flex items-center justify-between">
                    <span class="text-sm text-gray-600 dark:text-gray-400">Overtime Hours</span>
                    <span class="text-sm font-semibold text-purple-600"><?php echo number_format($timesheetSummary['overtime_hours'], 1); ?> hrs</span>
                </div>
                <?php if ($timesheetSummary['total_hours'] > 0): ?>
                    <div class="pt-3 border-t border-gray-100 dark:border-gray-700">
                        <div class="flex items-center justify-between mb-1">
                            <span class="text-xs text-gray-500">Regular vs Overtime</span>
                        </div>
                        <div class="w-full bg-gray-200 dark:bg-gray-700 rounded-full h-3 flex overflow-hidden">
                            <?php $regularPct = round(($timesheetSummary['regular_hours'] / $timesheetSummary['total_hours']) * 100); ?>
                            <div class="bg-blue-500 h-3 transition-all duration-300" style="width: <?php echo $regularPct; ?>%"></div>
                            <div class="bg-purple-500 h-3 transition-all duration-300" style="width: <?php echo 100 - $regularPct; ?>%"></div>
                        </div>
                        <div class="flex justify-between mt-1">
                            <span class="text-xs text-blue-600">Regular <?php echo $regularPct; ?>%</span>
                            <span class="text-xs text-purple-600">OT <?php echo 100 - $regularPct; ?>%</span>
                        </div>
                    </div>
                <?php else: ?>
                    <div class="text-center py-4">
                        <p class="text-sm text-gray-500">No timesheet data for this period</p>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
