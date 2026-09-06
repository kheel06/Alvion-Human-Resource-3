<?php
require_once __DIR__ . '/../config/config.php';
requireAuth();
checkRole(['admin', 'super admin', 'supervisor', 'unit_head', 'hr_admin', 'finance']);

// Prevent caching so dashboard content and sidebar updates are always visible
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

$page_title = 'Dashboard';
$view = $_GET['view'] ?? 'overview';

// Get metrics data
$metrics = [
    'todays_exceptions' => 0,
    'pending_approvals' => 0,
    'active_shift_count' => 0,
    'late_employees' => 0,
    'on_leave_today' => 0,
    'pending_timesheets' => 0,
    'total_employees' => 0,
    'attendance_rate' => 0,
    'pending_leave' => 0,
    'pending_claims' => 0,
    'biometric_scans_today' => 0,
    'present_today' => 0,
    'absent_today' => 0,
    'no_record_today' => 0
];

$pendingItems = [];
$exceptions = [];
$attendanceChartData = ['labels' => [], 'present' => [], 'absent' => [], 'late' => [], 'on_leave' => []];
$statusDistribution = ['present' => 0, 'absent' => 0, 'on_leave' => 0, 'late' => 0, 'half_day' => 0];
$recentActivity = [];
$employeesByUnit = [];

// Helper: run query only if table exists
$tableExists = function ($tbl) use ($db) {
    static $cache = [];
    if (!isset($cache[$tbl])) {
        try {
            $stmt = $db->query("SHOW TABLES LIKE " . $db->quote($tbl));
            $cache[$tbl] = $stmt && $stmt->rowCount() > 0;
        } catch (Throwable $e) {
            $cache[$tbl] = false;
        }
    }
    return $cache[$tbl];
};

if (isset($db)) {
    try {
        // Today's Exceptions
        if ($tableExists('daily_attendance')) {
            $stmt = $db->prepare("
                SELECT COUNT(*) as cnt
                FROM daily_attendance
                WHERE date = CURDATE()
                  AND (late_seconds > 0 OR undertime_seconds > 0 OR status = 'absent')
            ");
            $stmt->execute();
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            $metrics['todays_exceptions'] = (int)($result['cnt'] ?? 0);

            $stmt = $db->prepare("
                SELECT COUNT(*) as cnt FROM daily_attendance
                WHERE date = CURDATE() AND late_seconds > 0
            ");
            $stmt->execute();
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            $metrics['late_employees'] = (int)($result['cnt'] ?? 0);
        }

        // Pending Approvals (leave + timesheets + claims)
        $pendingCount = 0;
        if ($tableExists('leave_requests')) {
            $stmt = $db->prepare("SELECT COUNT(*) as cnt FROM leave_requests WHERE status IN ('pending','endorsed')");
            $stmt->execute();
            $metrics['pending_leave'] = (int)($stmt->fetch(PDO::FETCH_ASSOC)['cnt'] ?? 0);
            $pendingCount += $metrics['pending_leave'];
        }
        if ($tableExists('timesheets')) {
            $stmt = $db->prepare("SELECT COUNT(*) as cnt FROM timesheets WHERE status = 'endorsed'");
            $stmt->execute();
            $cnt = (int)($stmt->fetch(PDO::FETCH_ASSOC)['cnt'] ?? 0);
            $metrics['pending_timesheets'] = $cnt;
            $pendingCount += $cnt;
        }
        if ($tableExists('claims')) {
            $stmt = $db->prepare("SELECT COUNT(*) as cnt FROM claims WHERE status IN ('submitted','endorsed')");
            $stmt->execute();
            $metrics['pending_claims'] = (int)($stmt->fetch(PDO::FETCH_ASSOC)['cnt'] ?? 0);
            $pendingCount += $metrics['pending_claims'];
        }
        $metrics['pending_approvals'] = $pendingCount;

        // Biometric scans today (attendance_logs) — same source as Biometric Log page
        if ($tableExists('attendance_logs')) {
            $stmt = $db->prepare("SELECT COUNT(*) as cnt FROM attendance_logs WHERE DATE(log_time) = CURDATE()");
            $stmt->execute();
            $metrics['biometric_scans_today'] = (int)($stmt->fetch(PDO::FETCH_ASSOC)['cnt'] ?? 0);
        }

        // Today's attendance counts — same logic as Attendance page (daily_attendance)
        if ($tableExists('daily_attendance')) {
            $stmt = $db->prepare("
                SELECT
                    SUM(CASE WHEN status IN ('present','half_day') THEN 1 ELSE 0 END) as present_cnt,
                    SUM(CASE WHEN status = 'absent' THEN 1 ELSE 0 END) as absent_cnt
                FROM daily_attendance WHERE date = CURDATE()
            ");
            $stmt->execute();
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            $metrics['present_today'] = (int)($row['present_cnt'] ?? 0);
            $metrics['absent_today'] = (int)($row['absent_cnt'] ?? 0);
        }
        if ($tableExists('employees') && $tableExists('daily_attendance')) {
            $stmt = $db->prepare("
                SELECT COUNT(*) as cnt FROM employees e
                WHERE e.status = 'active'
                  AND NOT EXISTS (SELECT 1 FROM daily_attendance da WHERE da.employee_id = e.id AND da.date = CURDATE())
            ");
            $stmt->execute();
            $metrics['no_record_today'] = (int)($stmt->fetch(PDO::FETCH_ASSOC)['cnt'] ?? 0);
        }

        // Active Shift Count (shift_assignments or roster_assignments for today)
        if ($tableExists('shift_assignments')) {
            $stmt = $db->prepare("
                SELECT COUNT(DISTINCT employee_id) as cnt
                FROM shift_assignments
                WHERE CURDATE() BETWEEN start_date AND COALESCE(end_date, CURDATE())
            ");
            $stmt->execute();
            $metrics['active_shift_count'] = (int)($stmt->fetch(PDO::FETCH_ASSOC)['cnt'] ?? 0);
        }
        if ($metrics['active_shift_count'] === 0 && $tableExists('roster_assignments')) {
            $stmt = $db->prepare("
                SELECT COUNT(DISTINCT ra.employee_id) as cnt
                FROM roster_assignments ra
                JOIN rosters r ON ra.roster_id = r.id
                WHERE ra.assignment_date = CURDATE() AND r.status = 'published'
            ");
            $stmt->execute();
            $metrics['active_shift_count'] = (int)($stmt->fetch(PDO::FETCH_ASSOC)['cnt'] ?? 0);
        }

        // On Leave Today — same source as Attendance page (daily_attendance)
        if ($tableExists('daily_attendance')) {
            $stmt = $db->prepare("
                SELECT COUNT(*) as cnt FROM daily_attendance
                WHERE date = CURDATE() AND status = 'leave'
            ");
            $stmt->execute();
            $metrics['on_leave_today'] = (int)($stmt->fetch(PDO::FETCH_ASSOC)['cnt'] ?? 0);
        }

        // Total Employees
        if ($tableExists('employees')) {
            $stmt = $db->prepare("SELECT COUNT(*) as cnt FROM employees WHERE (deleted_at IS NULL OR deleted_at = 0) AND status = 'active'");
            $stmt->execute();
            $metrics['total_employees'] = (int)($stmt->fetch(PDO::FETCH_ASSOC)['cnt'] ?? 0);
        }

        // Attendance Rate (last 7 days)
        if ($tableExists('daily_attendance')) {
            $stmt = $db->prepare("
                SELECT COUNT(*) as total,
                       SUM(CASE WHEN status IN ('present','half_day') THEN 1 ELSE 0 END) as present_count
                FROM daily_attendance
                WHERE date >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)
            ");
            $stmt->execute();
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            $total = (int)($result['total'] ?? 0);
            $presentCount = (int)($result['present_count'] ?? 0);
            $metrics['attendance_rate'] = $total > 0 ? round(($presentCount / $total) * 100, 1) : 0;
        }

        // Chart: Last 7 days — always 7 labels, fill with zeros
        $last7Days = [];
        for ($i = 6; $i >= 0; $i--) {
            $d = date('Y-m-d', strtotime("-$i days"));
            $last7Days[$d] = ['present' => 0, 'absent' => 0, 'late' => 0, 'on_leave' => 0];
        }
        if ($tableExists('daily_attendance')) {
            $stmt = $db->prepare("
                SELECT date,
                    SUM(CASE WHEN status IN ('present','half_day') THEN 1 ELSE 0 END) as present_count,
                    SUM(CASE WHEN status = 'absent' THEN 1 ELSE 0 END) as absent_count,
                    SUM(CASE WHEN late_seconds > 0 THEN 1 ELSE 0 END) as late_count
                FROM daily_attendance
                WHERE date >= DATE_SUB(CURDATE(), INTERVAL 6 DAY)
                GROUP BY date
            ");
            $stmt->execute();
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                if (isset($last7Days[$row['date']])) {
                    $last7Days[$row['date']]['present'] = (int)$row['present_count'];
                    $last7Days[$row['date']]['absent'] = (int)$row['absent_count'];
                    $last7Days[$row['date']]['late'] = (int)$row['late_count'];
                }
            }
        }
        if ($tableExists('daily_attendance')) {
            foreach (array_keys($last7Days) as $d) {
                $stmt = $db->prepare("SELECT COUNT(*) as cnt FROM daily_attendance WHERE date = :d AND status = 'leave'");
                $stmt->execute([':d' => $d]);
                $last7Days[$d]['on_leave'] = (int)($stmt->fetch(PDO::FETCH_ASSOC)['cnt'] ?? 0);
            }
        }
        foreach ($last7Days as $date => $vals) {
            $attendanceChartData['labels'][] = date('D M j', strtotime($date));
            $attendanceChartData['present'][] = $vals['present'];
            $attendanceChartData['absent'][] = $vals['absent'];
            $attendanceChartData['late'][] = $vals['late'];
            $attendanceChartData['on_leave'][] = $vals['on_leave'];
        }

        // Today's status distribution
        if ($tableExists('daily_attendance')) {
            $stmt = $db->prepare("
                SELECT
                    SUM(CASE WHEN status IN ('present','half_day') AND (late_seconds = 0 OR late_seconds IS NULL) THEN 1 ELSE 0 END) as on_time,
                    SUM(CASE WHEN late_seconds > 0 THEN 1 ELSE 0 END) as late,
                    SUM(CASE WHEN status = 'absent' THEN 1 ELSE 0 END) as absent,
                    SUM(CASE WHEN status = 'half_day' THEN 1 ELSE 0 END) as half_day
                FROM daily_attendance
                WHERE date = CURDATE()
            ");
            $stmt->execute();
            $todayStats = $stmt->fetch(PDO::FETCH_ASSOC);
            $statusDistribution = [
                'present'  => (int)($todayStats['on_time'] ?? 0),
                'late'     => (int)($todayStats['late'] ?? 0),
                'absent'   => (int)($todayStats['absent'] ?? 0),
                'on_leave' => $metrics['on_leave_today'],
                'half_day' => (int)($todayStats['half_day'] ?? 0)
            ];
        }

        // Recent Activity — fetch from each source and merge by date
        $recentActivity = [];
        $activities = [];
        if ($tableExists('daily_attendance') && $tableExists('employees')) {
            $stmt = $db->prepare("
                SELECT 'attendance' as type, CONCAT(e.first_name, ' ', e.last_name) as name,
                       da.status as detail, CONCAT(da.date, ' ', COALESCE(da.time_in, '00:00:00')) as activity_date, da.time_in as extra
                FROM daily_attendance da
                JOIN employees e ON da.employee_id = e.id
                WHERE da.date >= DATE_SUB(CURDATE(), INTERVAL 3 DAY)
                ORDER BY da.date DESC, da.time_in DESC
                LIMIT 6
            ");
            $stmt->execute();
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $activities[] = $row;
            }
        }
        if ($tableExists('leave_requests') && $tableExists('employees')) {
            $stmt = $db->prepare("
                SELECT 'leave' as type, CONCAT(e.first_name, ' ', e.last_name) as name,
                       lr.leave_type as detail, COALESCE(lr.submitted_at, lr.created_at) as activity_date, lr.status as extra
                FROM leave_requests lr
                JOIN employees e ON lr.employee_id = e.id
                ORDER BY COALESCE(lr.submitted_at, lr.created_at) DESC
                LIMIT 5
            ");
            $stmt->execute();
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $activities[] = $row;
            }
        }
        if ($tableExists('claims') && $tableExists('employees')) {
            $stmt = $db->prepare("
                SELECT 'claim' as type, CONCAT(e.first_name, ' ', e.last_name) as name,
                       COALESCE(c.description, 'Claim') as detail, COALESCE(c.submitted_at, c.created_at) as activity_date, c.status as extra
                FROM claims c
                JOIN employees e ON c.employee_id = e.id
                WHERE c.status NOT IN ('draft')
                ORDER BY COALESCE(c.submitted_at, c.created_at) DESC
                LIMIT 5
            ");
            $stmt->execute();
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $activities[] = $row;
            }
        }
        if ($tableExists('attendance_logs') && $tableExists('employees')) {
            $stmt = $db->prepare("
                SELECT 'biometric' as type, CONCAT(e.first_name, ' ', e.last_name) as name,
                       COALESCE(al.log_type, 'in') as detail, al.log_time as activity_date, DATE_FORMAT(al.log_time, '%H:%i') as extra
                FROM attendance_logs al
                JOIN employees e ON al.employee_id = e.id
                WHERE al.log_time >= DATE_SUB(NOW(), INTERVAL 2 DAY)
                ORDER BY al.log_time DESC
                LIMIT 5
            ");
            $stmt->execute();
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $activities[] = $row;
            }
        }
        usort($activities, function ($a, $b) {
            return strcmp($b['activity_date'] ?? '', $a['activity_date'] ?? '');
        });
        $recentActivity = array_slice($activities, 0, 10);

        // Employees by Unit (include Unassigned)
        if ($tableExists('units')) {
            $stmt = $db->prepare("
                SELECT u.name as unit_name, COUNT(e.id) as count
                FROM units u
                LEFT JOIN employees e ON u.id = e.unit_id AND (e.deleted_at IS NULL) AND e.status = 'active'
                WHERE u.is_active = 1
                GROUP BY u.id, u.name
                ORDER BY count DESC
            ");
            $stmt->execute();
            $employeesByUnit = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }
        if ($tableExists('employees')) {
            $stmt = $db->prepare("SELECT COUNT(*) as cnt FROM employees WHERE (unit_id IS NULL OR unit_id = 0) AND (deleted_at IS NULL) AND status = 'active'");
            $stmt->execute();
            $unassigned = (int)($stmt->fetch(PDO::FETCH_ASSOC)['cnt'] ?? 0);
            if ($unassigned > 0) {
                $employeesByUnit[] = ['unit_name' => 'Unassigned', 'count' => $unassigned];
            }
        }

        
        // Get pending items for review queue
        $pendingLeaves = [];
        $pendingTimesheets = [];
        $pendingClaims = [];
        if ($view === 'pending_approvals') {
            if ($tableExists('leave_requests') && $tableExists('employees')) {
                $stmt = $db->prepare("
                    SELECT lr.*, e.first_name, e.last_name, e.employee_number
                    FROM leave_requests lr
                    JOIN employees e ON lr.employee_id = e.id
                    WHERE lr.status IN ('pending','endorsed')
                    ORDER BY COALESCE(lr.submitted_at, lr.created_at) DESC
                    LIMIT 20
                ");
                $stmt->execute();
                $pendingLeaves = $stmt->fetchAll(PDO::FETCH_ASSOC);
            }
            if ($tableExists('timesheets') && $tableExists('employees')) {
                $stmt = $db->prepare("
                    SELECT t.*, e.first_name, e.last_name, e.employee_number
                    FROM timesheets t
                    JOIN employees e ON t.employee_id = e.id
                    WHERE t.status = 'endorsed'
                    ORDER BY t.updated_at DESC
                    LIMIT 20
                ");
                $stmt->execute();
                $pendingTimesheets = $stmt->fetchAll(PDO::FETCH_ASSOC);
            }
            if ($tableExists('claims') && $tableExists('employees')) {
                $claimsJoin = $tableExists('claim_categories')
                    ? "LEFT JOIN claim_categories cc ON c.category_id = cc.id"
                    : "";
                $stmt = $db->prepare("
                    SELECT c.*, e.first_name, e.last_name, e.employee_number,
                           " . ($tableExists('claim_categories') ? "COALESCE(cc.name, 'Reimbursement') as claim_type" : "'Reimbursement' as claim_type") . "
                    FROM claims c
                    JOIN employees e ON c.employee_id = e.id
                    {$claimsJoin}
                    WHERE c.status IN ('submitted', 'endorsed')
                    ORDER BY COALESCE(c.submitted_at, c.created_at) DESC
                    LIMIT 20
                ");
                $stmt->execute();
                $pendingClaims = $stmt->fetchAll(PDO::FETCH_ASSOC);
            }
        }

        // Get exceptions for today
        if ($view === 'exceptions_alerts' && $tableExists('daily_attendance') && $tableExists('employees')) {
            $stmt = $db->prepare("
                SELECT da.*, e.first_name, e.last_name, e.employee_number
                FROM daily_attendance da
                JOIN employees e ON da.employee_id = e.id
                WHERE da.date = CURDATE()
                  AND (da.late_seconds > 0 OR da.undertime_seconds > 0 OR da.status = 'absent')
                ORDER BY da.late_seconds DESC, da.undertime_seconds DESC
                LIMIT 50
            ");
            $stmt->execute();
            $exceptions = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }
    } catch (PDOException $e) {
        error_log('Admin Dashboard error: ' . $e->getMessage());
    }
}

include __DIR__ . '/../includes/header.php';
?>

<div class="mb-6">
    <h1 class="text-2xl font-semibold text-gray-900 dark:text-white">Dashboard</h1>
    <p class="mt-1 text-sm text-gray-600 dark:text-gray-400">Overview of attendance, exceptions, and pending approvals</p>
</div>

<?php if ($view === 'overview' || $view === 'attendance_overview'): ?>
    <!-- KPI Cards — 6 essential metrics, all clickable -->
    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-5 gap-4 mb-6">
        <!-- Total Employees -->
        <a href="<?php echo htmlspecialchars(BASE_URL ?? ''); ?>/admin/modules/admin-employee_management.php?view=masterlist" class="block bg-white dark:bg-gray-800 rounded-xl shadow-sm border border-gray-100 dark:border-gray-700 p-5 hover:shadow-md hover:border-indigo-200 dark:hover:border-indigo-800 transition-all group">
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wide">Total Employees</p>
                    <p class="mt-2 text-2xl font-bold text-gray-900 dark:text-white"><?php echo $metrics['total_employees']; ?></p>
                    <p class="mt-1 text-xs text-indigo-600 dark:text-indigo-400 group-hover:underline">View masterlist</p>
                </div>
                <div class="w-12 h-12 rounded-lg bg-indigo-100 dark:bg-indigo-900 flex items-center justify-center">
                    <svg xmlns="http://www.w3.org/2000/svg" class="w-6 h-6 text-indigo-600 dark:text-indigo-300" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0z" /></svg>
                </div>
            </div>
        </a>

        <!-- Pending Approvals -->
        <a href="?view=pending_approvals" class="block bg-white dark:bg-gray-800 rounded-xl shadow-sm border border-gray-100 dark:border-gray-700 p-5 hover:shadow-md hover:border-blue-200 dark:hover:border-blue-800 transition-all group">
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wide">Pending Approvals</p>
                    <p class="mt-2 text-2xl font-bold text-gray-900 dark:text-white"><?php echo $metrics['pending_approvals']; ?></p>
                    <p class="mt-1 text-xs text-blue-600 dark:text-blue-400"><?php echo $metrics['pending_leave']; ?> leave, <?php echo $metrics['pending_timesheets']; ?> timesheets, <?php echo $metrics['pending_claims']; ?> claims</p>
                </div>
                <div class="w-12 h-12 rounded-lg bg-blue-100 dark:bg-blue-900 flex items-center justify-center">
                    <svg xmlns="http://www.w3.org/2000/svg" class="w-6 h-6 text-blue-600 dark:text-blue-300" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" /></svg>
                </div>
            </div>
        </a>

        <!-- Active Shifts -->
        <a href="<?php echo htmlspecialchars(BASE_URL ?? ''); ?>/admin/modules/admin-shift_&_scheduling.php?view=roster_calendar" class="block bg-white dark:bg-gray-800 rounded-xl shadow-sm border border-gray-100 dark:border-gray-700 p-5 hover:shadow-md hover:border-emerald-200 dark:hover:border-emerald-800 transition-all group">
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wide">Active Shifts</p>
                    <p class="mt-2 text-2xl font-bold text-gray-900 dark:text-white"><?php echo $metrics['active_shift_count']; ?></p>
                    <p class="mt-1 text-xs text-emerald-600 dark:text-emerald-400 group-hover:underline">View schedule</p>
                </div>
                <div class="w-12 h-12 rounded-lg bg-emerald-100 dark:bg-emerald-900 flex items-center justify-center">
                    <svg xmlns="http://www.w3.org/2000/svg" class="w-6 h-6 text-emerald-600 dark:text-emerald-300" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" /></svg>
                </div>
            </div>
        </a>

        <!-- On Leave Today -->
        <a href="<?php echo htmlspecialchars(BASE_URL ?? ''); ?>/admin/modules/admin-leave_management.php?view=leave_requests_queue" class="block bg-white dark:bg-gray-800 rounded-xl shadow-sm border border-gray-100 dark:border-gray-700 p-5 hover:shadow-md hover:border-purple-200 dark:hover:border-purple-800 transition-all group">
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wide">On Leave Today</p>
                    <p class="mt-2 text-2xl font-bold text-gray-900 dark:text-white"><?php echo $metrics['on_leave_today']; ?></p>
                    <p class="mt-1 text-xs text-purple-600 dark:text-purple-400 group-hover:underline">View leave</p>
                </div>
                <div class="w-12 h-12 rounded-lg bg-purple-100 dark:bg-purple-900 flex items-center justify-center">
                    <svg xmlns="http://www.w3.org/2000/svg" class="w-6 h-6 text-purple-600 dark:text-purple-300" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z" /></svg>
                </div>
            </div>
        </a>

        <!-- Attendance Rate -->
        <a href="<?php echo htmlspecialchars(BASE_URL ?? ''); ?>/admin/modules/admin-reports.php?view=attendance_summary" class="block bg-white dark:bg-gray-800 rounded-xl shadow-sm border border-gray-100 dark:border-gray-700 p-5 hover:shadow-md hover:border-cyan-200 dark:hover:border-cyan-800 transition-all group">
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wide">Attendance Rate</p>
                    <p class="mt-2 text-2xl font-bold <?php echo $metrics['attendance_rate'] >= 90 ? 'text-emerald-600' : ($metrics['attendance_rate'] >= 75 ? 'text-amber-600' : 'text-red-600'); ?>"><?php echo $metrics['attendance_rate']; ?>%</p>
                    <p class="mt-1 text-xs text-cyan-600 dark:text-cyan-400 group-hover:underline">Last 7 days</p>
                </div>
                <div class="w-12 h-12 rounded-lg bg-cyan-100 dark:bg-cyan-900 flex items-center justify-center">
                    <svg xmlns="http://www.w3.org/2000/svg" class="w-6 h-6 text-cyan-600 dark:text-cyan-300" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z" /></svg>
                </div>
            </div>
        </a>

        <!-- Present Today — same data as Attendance page -->
        <?php $todayDate = date('Y-m-d'); ?>
        <a href="<?php echo htmlspecialchars(BASE_URL ?? ''); ?>/admin/modules/admin-attendance.php?date=<?php echo $todayDate; ?>" class="block bg-white dark:bg-gray-800 rounded-xl shadow-sm border border-gray-100 dark:border-gray-700 p-5 hover:shadow-md hover:border-emerald-200 dark:hover:border-emerald-800 transition-all group">
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wide">Present Today</p>
                    <p class="mt-2 text-2xl font-bold text-gray-900 dark:text-white"><?php echo $metrics['present_today']; ?></p>
                    <p class="mt-1 text-xs text-emerald-600 dark:text-emerald-400 group-hover:underline">View attendance</p>
                </div>
                <div class="w-12 h-12 rounded-lg bg-emerald-100 dark:bg-emerald-900 flex items-center justify-center">
                    <svg xmlns="http://www.w3.org/2000/svg" class="w-6 h-6 text-emerald-600 dark:text-emerald-300" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" /></svg>
                </div>
            </div>
        </a>

        <!-- Biometric Log — same data as Biometric Log page -->
        <a href="<?php echo htmlspecialchars(BASE_URL ?? ''); ?>/admin/modules/admin-biometric_log.php?from=<?php echo $todayDate; ?>&to=<?php echo $todayDate; ?>" class="block bg-white dark:bg-gray-800 rounded-xl shadow-sm border border-gray-100 dark:border-gray-700 p-5 hover:shadow-md hover:border-amber-200 dark:hover:border-amber-800 transition-all group">
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wide">Biometric Scans Today</p>
                    <p class="mt-2 text-2xl font-bold text-gray-900 dark:text-white"><?php echo $metrics['biometric_scans_today']; ?></p>
                    <p class="mt-1 text-xs text-amber-600 dark:text-amber-400 group-hover:underline">View biometric log</p>
                </div>
                <div class="w-12 h-12 rounded-lg bg-amber-100 dark:bg-amber-900 flex items-center justify-center">
                    <svg xmlns="http://www.w3.org/2000/svg" class="w-6 h-6 text-amber-600 dark:text-amber-300" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z" /></svg>
                </div>
            </div>
        </a>
    </div>

    <!-- Charts -->
    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-6">
        <div class="bg-white dark:bg-gray-800 rounded-xl shadow-sm border border-gray-100 dark:border-gray-700">
            <div class="px-5 py-4 border-b border-gray-100 dark:border-gray-700">
                <h2 class="text-sm font-semibold text-gray-900 dark:text-white">Attendance Overview (Last 7 Days)</h2>
            </div>
            <div class="p-5" style="height: 300px;">
                <canvas id="attendanceChart"></canvas>
            </div>
        </div>

        <div class="bg-white dark:bg-gray-800 rounded-xl shadow-sm border border-gray-100 dark:border-gray-700">
            <div class="px-5 py-4 border-b border-gray-100 dark:border-gray-700 flex items-center justify-between">
                <h2 class="text-sm font-semibold text-gray-900 dark:text-white">Today's Status Distribution</h2>
                <a href="<?php echo htmlspecialchars(BASE_URL ?? ''); ?>/admin/modules/admin-attendance.php?date=<?php echo date('Y-m-d'); ?>" class="text-xs font-medium text-indigo-600 dark:text-indigo-400 hover:underline">View full attendance →</a>
            </div>
            <div class="p-5" style="height: 300px;">
                <?php $statusTotal = array_sum($statusDistribution); ?>
                <?php if ($statusTotal === 0): ?>
                    <div class="flex flex-col items-center justify-center h-full">
                        <svg xmlns="http://www.w3.org/2000/svg" class="w-12 h-12 text-gray-300 dark:text-gray-600 mb-3" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z" /></svg>
                        <p class="text-sm text-gray-500 dark:text-gray-400">No attendance data for today yet</p>
                        <p class="text-xs text-gray-400 dark:text-gray-500 mt-1">Data will appear once employees clock in</p>
                    </div>
                <?php else: ?>
                    <canvas id="statusChart"></canvas>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script>
    (function() {
        var isDark = document.documentElement.classList.contains('dark');
        var gridColor = isDark ? 'rgba(255,255,255,0.08)' : 'rgba(0,0,0,0.06)';
        var textColor = isDark ? '#9ca3af' : '#6b7280';

        var attendanceCtx = document.getElementById('attendanceChart');
        if (attendanceCtx) {
            var chartData = <?php echo json_encode($attendanceChartData); ?>;
            var allVals = [].concat(chartData.present || [], chartData.absent || [], chartData.late || [], chartData.on_leave || []);
            var maxVal = allVals.length ? Math.max.apply(null, allVals) : 0;
            new Chart(attendanceCtx, {
                type: 'bar',
                data: {
                    labels: chartData.labels,
                    datasets: [
                        { label: 'Present', data: chartData.present, backgroundColor: 'rgba(34,197,94,0.8)', borderRadius: 4, order: 1 },
                        { label: 'Late', data: chartData.late, backgroundColor: 'rgba(245,158,11,0.8)', borderRadius: 4, order: 2 },
                        { label: 'Absent', data: chartData.absent, backgroundColor: 'rgba(239,68,68,0.8)', borderRadius: 4, order: 3 },
                        { label: 'On Leave', data: chartData.on_leave, backgroundColor: 'rgba(168,85,247,0.8)', borderRadius: 4, order: 4 }
                    ]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: { position: 'top', labels: { color: textColor, usePointStyle: true, pointStyle: 'rectRounded', padding: 16 } },
                        tooltip: { mode: 'index', intersect: false }
                    },
                    scales: {
                        x: { grid: { display: false }, ticks: { color: textColor, font: { size: 11 } } },
                        y: {
                            beginAtZero: true,
                            suggestedMax: Math.max(5, Math.ceil((maxVal || 1) * 1.2)),
                            grid: { color: gridColor },
                            ticks: { color: textColor, stepSize: maxVal > 20 ? undefined : 1, font: { size: 11 } }
                        }
                    }
                }
            });
        }

        <?php if ($statusTotal > 0): ?>
        var statusCtx = document.getElementById('statusChart');
        if (statusCtx) {
            var statusData = <?php echo json_encode($statusDistribution); ?>;
            var sLabels = [], sValues = [], sColors = [];
            var mapping = [
                { key: 'present', label: 'On Time', color: 'rgb(34,197,94)' },
                { key: 'late', label: 'Late', color: 'rgb(245,158,11)' },
                { key: 'absent', label: 'Absent', color: 'rgb(239,68,68)' },
                { key: 'on_leave', label: 'On Leave', color: 'rgb(168,85,247)' },
                { key: 'half_day', label: 'Half Day', color: 'rgb(59,130,246)' }
            ];
            for (var i = 0; i < mapping.length; i++) {
                if (statusData[mapping[i].key] > 0) {
                    sLabels.push(mapping[i].label + ' (' + statusData[mapping[i].key] + ')');
                    sValues.push(statusData[mapping[i].key]);
                    sColors.push(mapping[i].color);
                }
            }
            new Chart(statusCtx, {
                type: 'doughnut',
                data: { labels: sLabels, datasets: [{ data: sValues, backgroundColor: sColors, borderWidth: 2, borderColor: isDark ? '#1f2937' : '#ffffff' }] },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    cutout: '60%',
                    plugins: {
                        legend: { position: 'bottom', labels: { color: textColor, usePointStyle: true, pointStyle: 'circle', padding: 14 } },
                        tooltip: { callbacks: { label: function(ctx) { var t = ctx.dataset.data.reduce(function(a,b){return a+b;},0); return ctx.label + ' - ' + Math.round(ctx.parsed/t*100) + '%'; } } }
                    }
                }
            });
        }
        <?php endif; ?>
    })();
    </script>

    <!-- Recent Activity & Quick Stats -->
    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6 mb-6">
        <!-- Recent Activity -->
        <div class="lg:col-span-2 bg-white dark:bg-gray-800 rounded-xl shadow-sm border border-gray-100 dark:border-gray-700">
            <div class="px-5 py-4 border-b border-gray-100 dark:border-gray-700">
                <h2 class="text-sm font-semibold text-gray-900 dark:text-white">Recent Activity</h2>
            </div>
            <div class="p-5">
                <div class="space-y-3 max-h-80 overflow-y-auto">
                    <?php if (empty($recentActivity)): ?>
                        <p class="text-sm text-gray-500 text-center py-4">No recent activity</p>
                    <?php else: ?>
                        <?php foreach ($recentActivity as $activity): ?>
                            <div class="flex items-start gap-3 p-3 bg-gray-50 dark:bg-gray-700/50 rounded-lg">
                                <?php if ($activity['type'] === 'attendance'): ?>
                                    <div class="w-8 h-8 rounded-full bg-emerald-100 dark:bg-emerald-900 flex items-center justify-center flex-shrink-0">
                                        <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4 text-emerald-600" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" /></svg>
                                    </div>
                                    <div class="flex-1 min-w-0">
                                        <p class="text-sm font-medium text-gray-900 dark:text-white"><?php echo htmlspecialchars($activity['name']); ?></p>
                                        <p class="text-xs text-gray-500">Clocked in <?php echo $activity['extra'] ? date('g:i A', strtotime($activity['extra'])) : ''; ?> — <span class="font-medium"><?php echo ucfirst($activity['detail']); ?></span></p>
                                        <p class="text-xs text-gray-400 mt-0.5"><?php echo date('M d, Y', strtotime($activity['activity_date'])); ?></p>
                                    </div>
                                <?php elseif ($activity['type'] === 'leave'): ?>
                                    <div class="w-8 h-8 rounded-full bg-purple-100 dark:bg-purple-900 flex items-center justify-center flex-shrink-0">
                                        <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4 text-purple-600" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z" /></svg>
                                    </div>
                                    <div class="flex-1 min-w-0">
                                        <p class="text-sm font-medium text-gray-900 dark:text-white"><?php echo htmlspecialchars($activity['name']); ?></p>
                                        <p class="text-xs text-gray-500">Leave request (<?php echo htmlspecialchars($activity['detail']); ?>) — <span class="px-1.5 py-0.5 text-xs rounded <?php echo $activity['extra'] === 'pending' ? 'bg-amber-100 text-amber-700' : ($activity['extra'] === 'approved' ? 'bg-emerald-100 text-emerald-700' : 'bg-gray-100 text-gray-700'); ?>"><?php echo ucfirst($activity['extra']); ?></span></p>
                                        <p class="text-xs text-gray-400 mt-0.5"><?php echo date('M d, Y g:i A', strtotime($activity['activity_date'])); ?></p>
                                    </div>
                                <?php elseif ($activity['type'] === 'claim'): ?>
                                    <div class="w-8 h-8 rounded-full bg-blue-100 dark:bg-blue-900 flex items-center justify-center flex-shrink-0">
                                        <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4 text-blue-600" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" /></svg>
                                    </div>
                                    <div class="flex-1 min-w-0">
                                        <p class="text-sm font-medium text-gray-900 dark:text-white"><?php echo htmlspecialchars($activity['name']); ?></p>
                                        <p class="text-xs text-gray-500">Claim: <?php echo htmlspecialchars(mb_strimwidth($activity['detail'], 0, 40, '...')); ?> — <span class="px-1.5 py-0.5 text-xs rounded <?php echo $activity['extra'] === 'submitted' ? 'bg-amber-100 text-amber-700' : ($activity['extra'] === 'approved' ? 'bg-emerald-100 text-emerald-700' : 'bg-gray-100 text-gray-700'); ?>"><?php echo ucfirst($activity['extra']); ?></span></p>
                                        <p class="text-xs text-gray-400 mt-0.5"><?php echo $activity['activity_date'] ? date('M d, Y g:i A', strtotime($activity['activity_date'])) : ''; ?></p>
                                    </div>
                                <?php elseif ($activity['type'] === 'biometric'): ?>
                                    <div class="w-8 h-8 rounded-full bg-cyan-100 dark:bg-cyan-900 flex items-center justify-center flex-shrink-0">
                                        <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4 text-cyan-600" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 11c0 3.517-1.009 6.799-2.753 9.571m-3.44-2.04l.054-.09A13.916 13.916 0 008 11a4 4 0 118 0c0 1.017-.07 2.019-.203 3m-2.118 6.844A21.88 21.88 0 0015.171 17m3.839 1.132c.645-2.266.99-4.659.99-7.132A8 8 0 008 4.07M3 15.364c.64-1.319 1-2.8 1-4.364 0-1.457.39-2.823 1.07-4" /></svg>
                                    </div>
                                    <div class="flex-1 min-w-0">
                                        <p class="text-sm font-medium text-gray-900 dark:text-white"><?php echo htmlspecialchars($activity['name']); ?></p>
                                        <p class="text-xs text-gray-500">Biometric <?php echo strtoupper(htmlspecialchars($activity['detail'])); ?> — <?php echo $activity['extra'] ? $activity['extra'] : ''; ?></p>
                                        <p class="text-xs text-gray-400 mt-0.5"><?php echo $activity['activity_date'] ? date('M d, Y g:i A', strtotime($activity['activity_date'])) : ''; ?></p>
                                    </div>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Employee Stats by Unit -->
        <div class="bg-white dark:bg-gray-800 rounded-xl shadow-sm border border-gray-100 dark:border-gray-700">
            <div class="px-5 py-4 border-b border-gray-100 dark:border-gray-700 flex items-center justify-between">
                <h2 class="text-sm font-semibold text-gray-900 dark:text-white">Staff by Unit</h2>
                <span class="text-xs text-gray-500"><?php echo $metrics['total_employees']; ?> total</span>
            </div>
            <div class="p-5">
                <div class="space-y-3">
                    <?php foreach ($employeesByUnit as $unit): ?>
                        <div>
                            <div class="flex items-center justify-between mb-1">
                                <span class="text-xs text-gray-700 dark:text-gray-300"><?php echo htmlspecialchars($unit['unit_name']); ?></span>
                                <span class="text-xs font-semibold text-gray-900 dark:text-white"><?php echo $unit['count']; ?></span>
                            </div>
                            <div class="w-full bg-gray-200 dark:bg-gray-700 rounded-full h-1.5">
                                <div class="bg-primary-600 h-1.5 rounded-full" style="width: <?php echo $metrics['total_employees'] > 0 ? round(($unit['count'] / $metrics['total_employees']) * 100) : 0; ?>%"></div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
                <div class="mt-4 pt-4 border-t border-gray-100 dark:border-gray-700">
                    <div class="flex items-center justify-between">
                        <span class="text-xs text-gray-500">Attendance Rate (7d)</span>
                        <span class="text-sm font-bold <?php echo $metrics['attendance_rate'] >= 90 ? 'text-emerald-600' : ($metrics['attendance_rate'] >= 75 ? 'text-amber-600' : 'text-red-600'); ?>">
                            <?php echo $metrics['attendance_rate']; ?>%
                        </span>
                    </div>
                </div>
            </div>
        </div>
    </div>

<?php elseif ($view === 'exceptions_alerts'): ?>
    <!-- Exceptions & Late/Early Alerts -->
    <div class="bg-white dark:bg-gray-800 rounded-xl shadow-sm border border-gray-100 dark:border-gray-700">
        <div class="px-5 py-4 border-b border-gray-100 dark:border-gray-700 flex items-center justify-between">
            <h2 class="text-sm font-semibold text-gray-900 dark:text-white">Exceptions & Late/Early Alerts</h2>
            <div class="flex gap-2">
                <select id="filterException" onchange="filterTable()" class="text-xs border border-gray-300 dark:border-gray-600 rounded px-2 py-1 dark:bg-gray-700 dark:text-white">
                    <option value="">All Types</option>
                    <option value="late">Late</option>
                    <option value="undertime">Undertime</option>
                    <option value="absent">Absent</option>
                </select>
                <button onclick="exportExceptions()" class="px-3 py-1 text-xs border border-gray-300 dark:border-gray-600 rounded hover:bg-gray-50 dark:hover:bg-gray-700">
                    Export
                </button>
            </div>
        </div>
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700" id="exceptionsTable">
                <thead class="bg-gray-50 dark:bg-gray-700">
                    <tr>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase">
                            <input type="checkbox" class="rounded border-gray-300" onchange="toggleAll(this)">
                        </th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase cursor-pointer" onclick="sortTable(1)">
                            Employee <span class="sort-indicator">↕</span>
                        </th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase">Date</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase">Status</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase">Late</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase">Undertime</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase">Actions</th>
                    </tr>
                </thead>
                <tbody class="bg-white dark:bg-gray-800 divide-y divide-gray-200 dark:divide-gray-700">
                    <?php if (empty($exceptions)): ?>
                        <tr>
                            <td colspan="7" class="px-6 py-4 text-center text-sm text-gray-500">No exceptions found for today</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($exceptions as $exception): ?>
                            <tr data-type="<?php echo $exception['status'] === 'absent' ? 'absent' : ($exception['late_seconds'] > 0 ? 'late' : 'undertime'); ?>">
                                <td class="px-6 py-4">
                                    <input type="checkbox" class="row-checkbox rounded border-gray-300">
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900 dark:text-white">
                                    <?php echo htmlspecialchars($exception['employee_number'] . ' - ' . $exception['first_name'] . ' ' . $exception['last_name']); ?>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900 dark:text-white">
                                    <?php echo date('M d, Y', strtotime($exception['date'])); ?>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm">
                                    <span class="px-2 py-1 text-xs font-semibold rounded-full <?php 
                                        echo $exception['status'] === 'absent' ? 'bg-red-100 text-red-800 dark:bg-red-900 dark:text-red-200' : 
                                            'bg-yellow-100 text-yellow-800 dark:bg-yellow-900 dark:text-yellow-200';
                                    ?>">
                                        <?php echo strtoupper($exception['status']); ?>
                                    </span>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900 dark:text-white">
                                    <?php 
                                    if ($exception['late_seconds'] > 0) {
                                        $minutes = round($exception['late_seconds'] / 60);
                                        echo $minutes . ' min';
                                    } else {
                                        echo '-';
                                    }
                                    ?>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900 dark:text-white">
                                    <?php 
                                    if ($exception['undertime_seconds'] > 0) {
                                        $minutes = round($exception['undertime_seconds'] / 60);
                                        echo $minutes . ' min';
                                    } else {
                                        echo '-';
                                    }
                                    ?>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm">
                                    <button class="text-primary-600 hover:text-primary-700 text-xs">Review</button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        <div class="px-5 py-3 border-t border-gray-100 dark:border-gray-700 bg-gray-50 dark:bg-gray-700/50">
            <button onclick="bulkAction()" class="px-4 py-2 text-xs bg-primary-600 text-white rounded hover:bg-primary-700">
                Bulk Action
            </button>
        </div>
    </div>

    <script>
        function filterTable() {
            const filter = document.getElementById('filterException').value;
            const rows = document.querySelectorAll('#exceptionsTable tbody tr');
            rows.forEach(row => {
                const type = row.getAttribute('data-type') || '';
                if (!filter || type === filter) {
                    row.style.display = '';
                } else {
                    row.style.display = 'none';
                }
            });
        }

        function toggleAll(checkbox) {
            document.querySelectorAll('.row-checkbox').forEach(cb => cb.checked = checkbox.checked);
        }

        function bulkAction() {
            const selected = Array.from(document.querySelectorAll('.row-checkbox:checked')).map(cb => cb.closest('tr'));
            if (selected.length === 0) {
                alert('Please select at least one item');
                return;
            }
            // Implement bulk action logic
            console.log('Bulk action on', selected.length, 'items');
        }

        function exportExceptions() {
            const table = document.getElementById('exceptionsTable');
            if (!table) return;
            const rows = table.querySelectorAll('tbody tr');
            let csv = 'Employee,Date,Status,Late,Undertime\n';
            rows.forEach(r => {
                const cells = r.querySelectorAll('td');
                if (cells.length >= 6) {
                    const employee = cells[1]?.textContent?.trim() || '';
                    const date = cells[2]?.textContent?.trim() || '';
                    const status = cells[3]?.textContent?.trim() || '';
                    const late = cells[4]?.textContent?.trim() || '';
                    const undertime = cells[5]?.textContent?.trim() || '';
                    csv += '"' + employee + '","' + date + '","' + status + '","' + late + '","' + undertime + '"\n';
                }
            });
            const blob = new Blob(['\uFEFF' + csv], { type: 'text/csv;charset=utf-8;' });
            const link = document.createElement('a');
            link.href = URL.createObjectURL(blob);
            link.download = 'hr3_exceptions_' + new Date().toISOString().slice(0,10) + '.csv';
            link.click();
        }
    </script>

<?php elseif ($view === 'pending_approvals'): ?>
    <!-- Pending Approvals (Leave / Timesheet / Claims) -->
    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
        <!-- Pending Leave Requests -->
        <div class="bg-white dark:bg-gray-800 rounded-xl shadow-sm border border-gray-100 dark:border-gray-700">
            <div class="px-5 py-4 border-b border-gray-100 dark:border-gray-700">
                <h2 class="text-sm font-semibold text-gray-900 dark:text-white">Leave Requests</h2>
            </div>
            <div class="p-5">
                <div class="space-y-3 max-h-96 overflow-y-auto">
                    <?php if (empty($pendingLeaves)): ?>
                        <p class="text-sm text-gray-500 text-center py-4">No pending leave requests</p>
                    <?php else: ?>
                        <?php foreach ($pendingLeaves as $leave): ?>
                            <div class="p-3 bg-gray-50 dark:bg-gray-700/50 rounded-lg pending-card" data-type="leave" data-id="<?php echo (int)$leave['id']; ?>">
                                <div class="flex items-center justify-between mb-2">
                                    <p class="text-sm font-medium text-gray-900 dark:text-white">
                                        <?php echo htmlspecialchars($leave['first_name'] . ' ' . $leave['last_name']); ?>
                                    </p>
                                    <span class="px-2 py-1 text-xs font-semibold rounded-full bg-amber-100 text-amber-800 dark:bg-amber-900 dark:text-amber-200">
                                        Pending
                                    </span>
                                </div>
                                <p class="text-xs text-gray-500"><?php echo htmlspecialchars($leave['leave_type']); ?> - <?php echo date('M d', strtotime($leave['start_date'])); ?> to <?php echo date('M d', strtotime($leave['end_date'])); ?></p>
                                <div class="mt-2 flex gap-2">
                                    <button type="button" onclick="dashboardApproveLeave(<?php echo (int)$leave['id']; ?>, this)" class="flex-1 px-3 py-1 text-xs bg-emerald-600 text-white rounded hover:bg-emerald-700">Approve</button>
                                    <button type="button" onclick="dashboardRejectLeave(<?php echo (int)$leave['id']; ?>)" class="flex-1 px-3 py-1 text-xs bg-red-600 text-white rounded hover:bg-red-700">Reject</button>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Pending Timesheets -->
        <div class="bg-white dark:bg-gray-800 rounded-xl shadow-sm border border-gray-100 dark:border-gray-700">
            <div class="px-5 py-4 border-b border-gray-100 dark:border-gray-700">
                <h2 class="text-sm font-semibold text-gray-900 dark:text-white">Timesheets</h2>
            </div>
            <div class="p-5">
                <div class="space-y-3 max-h-96 overflow-y-auto">
                    <?php if (empty($pendingTimesheets)): ?>
                        <p class="text-sm text-gray-500 text-center py-4">No pending timesheets</p>
                    <?php else: ?>
                        <?php foreach ($pendingTimesheets as $timesheet): ?>
                            <div class="p-3 bg-gray-50 dark:bg-gray-700/50 rounded-lg pending-card" data-type="timesheet" data-id="<?php echo (int)$timesheet['id']; ?>">
                                <div class="flex items-center justify-between mb-2">
                                    <p class="text-sm font-medium text-gray-900 dark:text-white">
                                        <?php echo htmlspecialchars($timesheet['first_name'] . ' ' . $timesheet['last_name']); ?>
                                    </p>
                                    <span class="px-2 py-1 text-xs font-semibold rounded-full bg-blue-100 text-blue-800 dark:bg-blue-900 dark:text-blue-200">
                                        Endorsed
                                    </span>
                                </div>
                                <p class="text-xs text-gray-500"><?php echo date('M d', strtotime($timesheet['period_start'])); ?> - <?php echo date('M d', strtotime($timesheet['period_end'])); ?></p>
                                <p class="text-xs text-gray-600 dark:text-gray-400 mt-1"><?php echo number_format($timesheet['total_hours'], 1); ?> hours</p>
                                <div class="mt-2 flex gap-2">
                                    <button type="button" onclick="dashboardApproveTimesheet(<?php echo (int)$timesheet['id']; ?>, this)" class="flex-1 px-3 py-1 text-xs bg-emerald-600 text-white rounded hover:bg-emerald-700">Approve</button>
                                    <a href="<?php echo htmlspecialchars(BASE_URL); ?>/admin/modules/admin-timesheets.php?status=endorsed" class="flex-1 px-3 py-1 text-xs bg-gray-600 text-white rounded hover:bg-gray-700 text-center inline-block">Review</a>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Pending Claims -->
        <div class="bg-white dark:bg-gray-800 rounded-xl shadow-sm border border-gray-100 dark:border-gray-700">
            <div class="px-5 py-4 border-b border-gray-100 dark:border-gray-700">
                <h2 class="text-sm font-semibold text-gray-900 dark:text-white">Claims</h2>
            </div>
            <div class="p-5">
                <div class="space-y-3 max-h-96 overflow-y-auto">
                    <?php if (empty($pendingClaims)): ?>
                        <p class="text-sm text-gray-500 text-center py-4">No pending claims</p>
                    <?php else: ?>
                        <?php foreach ($pendingClaims as $claim): ?>
                            <div class="p-3 bg-gray-50 dark:bg-gray-700/50 rounded-lg pending-card" data-type="claim" data-id="<?php echo (int)$claim['id']; ?>">
                                <div class="flex items-center justify-between mb-2">
                                    <p class="text-sm font-medium text-gray-900 dark:text-white">
                                        <?php echo htmlspecialchars($claim['first_name'] . ' ' . $claim['last_name']); ?>
                                    </p>
                                    <span class="px-2 py-1 text-xs font-semibold rounded-full bg-amber-100 text-amber-800 dark:bg-amber-900 dark:text-amber-200">
                                        <?php echo strtoupper($claim['status']); ?>
                                    </span>
                                </div>
                                <p class="text-xs text-gray-500"><?php echo htmlspecialchars(ucfirst($claim['claim_type'])); ?></p>
                                <p class="text-xs text-gray-600 dark:text-gray-400 mt-1">PHP <?php echo number_format($claim['amount'], 2); ?></p>
                                <div class="mt-2 flex gap-2">
                                    <button type="button" onclick="dashboardApproveClaim(<?php echo (int)$claim['id']; ?>, this)" class="flex-1 px-3 py-1 text-xs bg-emerald-600 text-white rounded hover:bg-emerald-700">Approve</button>
                                    <button type="button" onclick="dashboardRejectClaim(<?php echo (int)$claim['id']; ?>, this)" class="flex-1 px-3 py-1 text-xs bg-red-600 text-white rounded hover:bg-red-700">Reject</button>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- Toast for feedback -->
    <div id="dashboardPendingToast" class="hidden fixed bottom-4 right-4 z-50 max-w-sm">
        <div id="dashboardPendingToastInner" class="flex items-center gap-3 px-4 py-3 rounded-lg shadow-lg border text-sm bg-emerald-50 dark:bg-emerald-900/40 border-emerald-200 dark:border-emerald-800 text-emerald-800 dark:text-emerald-200"></div>
    </div>

    <script>
    (function() {
        // Use current page origin so API calls work when site is opened via different host (e.g. hospital.hr3.system.test)
        var API_BASE = window.location.origin;
        var toastEl = document.getElementById('dashboardPendingToast');
        var toastInner = document.getElementById('dashboardPendingToastInner');
        var toastTimer = null;

        function showToast(msg, isError) {
            if (!toastEl || !toastInner) return;
            toastInner.textContent = msg;
            toastInner.className = 'flex items-center gap-3 px-4 py-3 rounded-lg shadow-lg border text-sm ';
            if (isError) {
                toastInner.className += 'bg-red-50 dark:bg-red-900/40 border-red-200 dark:border-red-800 text-red-800 dark:text-red-200';
            } else {
                toastInner.className += 'bg-emerald-50 dark:bg-emerald-900/40 border-emerald-200 dark:border-emerald-800 text-emerald-800 dark:text-emerald-200';
            }
            toastEl.classList.remove('hidden');
            clearTimeout(toastTimer);
            toastTimer = setTimeout(function() { toastEl.classList.add('hidden'); }, 4000);
        }

        function removeCard(type, id) {
            var card = document.querySelector('.pending-card[data-type="' + type + '"][data-id="' + id + '"]');
            if (card) {
                card.style.opacity = '0';
                card.style.transition = 'opacity 0.3s';
                setTimeout(function() { card.remove(); }, 300);
            }
        }

        function disableBtn(btn) {
            if (btn) { btn.disabled = true; btn.style.opacity = '0.5'; }
        }
        function enableBtn(btn) {
            if (btn) { btn.disabled = false; btn.style.opacity = '1'; }
        }

        function parseResponse(r) {
            return r.text().then(function(text) {
                try { return { ok: r.ok, data: JSON.parse(text) }; }
                catch (e) { return { ok: r.ok, data: { success: false, message: text || 'Server error' } }; }
            });
        }

        window.dashboardApproveLeave = function(requestId, btn) {
            disableBtn(btn);
            fetch(API_BASE + '/api/leave/approve.php', {
                method: 'POST',
                headers: {'Content-Type': 'application/json'},
                body: JSON.stringify({request_id: requestId}),
                credentials: 'same-origin'
            })
            .then(parseResponse)
            .then(function(result) {
                var data = result.data;
                if (data && data.success) {
                    showToast(data.message || 'Leave request approved');
                    removeCard('leave', requestId);
                } else {
                    showToast((data && data.message) || 'Could not approve leave', true);
                    enableBtn(btn);
                }
            })
            .catch(function() {
                showToast('Network error', true);
                enableBtn(btn);
            });
        };

        window.dashboardRejectLeave = function(requestId) {
            var reason = prompt('Enter reason for rejection (at least 10 characters, visible to employee):');
            if (reason === null) return;
            reason = reason.trim();
            if (reason.length < 10) {
                showToast('Reason must be at least 10 characters', true);
                return;
            }
            fetch(API_BASE + '/api/leave/reject.php', {
                method: 'POST',
                headers: {'Content-Type': 'application/json'},
                body: JSON.stringify({request_id: requestId, reason: reason}),
                credentials: 'same-origin'
            })
            .then(parseResponse)
            .then(function(result) {
                var data = result.data;
                if (data && data.success) {
                    showToast(data.message || 'Leave request rejected');
                    removeCard('leave', requestId);
                } else {
                    showToast((data && data.message) || 'Could not reject leave', true);
                }
            })
            .catch(function() {
                showToast('Network error', true);
            });
        };

        window.dashboardApproveTimesheet = function(timesheetId, btn) {
            disableBtn(btn);
            fetch(API_BASE + '/api/timesheets/approve.php', {
                method: 'POST',
                headers: {'Content-Type': 'application/json'},
                body: JSON.stringify({timesheet_id: timesheetId}),
                credentials: 'same-origin'
            })
            .then(parseResponse)
            .then(function(result) {
                var data = result.data;
                if (data && data.success) {
                    showToast(data.message || 'Timesheet approved');
                    removeCard('timesheet', timesheetId);
                } else {
                    showToast((data && data.message) || 'Could not approve timesheet', true);
                    enableBtn(btn);
                }
            })
            .catch(function() {
                showToast('Network error', true);
                enableBtn(btn);
            });
        };

        window.dashboardApproveClaim = function(claimId, btn) {
            disableBtn(btn);
            fetch(API_BASE + '/api/claims/approve.php', {
                method: 'POST',
                headers: {'Content-Type': 'application/json'},
                body: JSON.stringify({claim_id: claimId}),
                credentials: 'same-origin'
            })
            .then(parseResponse)
            .then(function(result) {
                var data = result.data;
                if (data && data.success) {
                    showToast(data.message || 'Claim approved');
                    removeCard('claim', claimId);
                } else {
                    showToast((data && data.message) || 'Could not approve claim', true);
                    enableBtn(btn);
                }
            })
            .catch(function() {
                showToast('Network error', true);
                enableBtn(btn);
            });
        };

        window.dashboardRejectClaim = function(claimId, btn) {
            disableBtn(btn);
            fetch(API_BASE + '/api/claims/reject.php', {
                method: 'POST',
                headers: {'Content-Type': 'application/json'},
                body: JSON.stringify({claim_id: claimId}),
                credentials: 'same-origin'
            })
            .then(parseResponse)
            .then(function(result) {
                var data = result.data;
                if (data && data.success) {
                    showToast(data.message || 'Claim rejected');
                    removeCard('claim', claimId);
                } else {
                    showToast((data && data.message) || 'Could not reject claim', true);
                    enableBtn(btn);
                }
            })
            .catch(function() {
                showToast('Network error', true);
                enableBtn(btn);
            });
        };
    })();
    </script>
<?php endif; ?>

<?php include __DIR__ . '/../includes/footer.php'; ?>

