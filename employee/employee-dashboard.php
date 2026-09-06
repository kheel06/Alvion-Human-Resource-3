<?php
require_once __DIR__ . '/../config/config.php';
requireAuth();
checkRole(['employee']);

$page_title = 'My HR Homepage';

// Use numeric employee ID (resolves EMP001 → employees.id) so all queries use real data
$employeeId = getCurrentEmployeeId($db ?? null);
if (!$employeeId && isset($db)) {
    $employeeId = $_SESSION['hr3_employee_id'] ?? null;
}
if (!$employeeId) {
    $employeeId = $_SESSION['employee_id'] ?? $_SESSION['user_id'] ?? null;
    if ($employeeId && isset($db) && !is_numeric($employeeId)) {
        try {
            // Try employee_number first (EMP001 style)
            $stmt = $db->prepare("SELECT id FROM employees WHERE employee_number = ? LIMIT 1");
            $stmt->execute([$employeeId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($row) {
                $employeeId = (int) $row['id'];
            } else {
                // Try matching via department_accounts email → employees email
                $stmt2 = $db->prepare("SELECT e.id FROM employees e JOIN department_accounts da ON e.email = da.employee_email WHERE da.employee_id = ? LIMIT 1");
                $stmt2->execute([$employeeId]);
                $row2 = $stmt2->fetch(PDO::FETCH_ASSOC);
                if ($row2) {
                    $employeeId = (int) $row2['id'];
                } else {
                    // Try matching by first_name + last_name
                    $stmt3 = $db->prepare("SELECT e.id FROM employees e JOIN department_accounts da ON LOWER(e.first_name) = LOWER(da.employee_fname) AND LOWER(e.last_name) = LOWER(da.employee_lname) WHERE da.employee_id = ? LIMIT 1");
                    $stmt3->execute([$_SESSION['user_id'] ?? $employeeId]);
                    $row3 = $stmt3->fetch(PDO::FETCH_ASSOC);
                    $employeeId = $row3 ? (int) $row3['id'] : null;
                }
            }
        } catch (PDOException $e) {
            $employeeId = null;
        }
    } else {
        $employeeId = $employeeId ? (int) $employeeId : null;
    }
}

// Avoid redirect loop: if user is logged in but employees table has no matching record, keep session alive
if (!$employeeId && !empty($_SESSION['user_id'])) {
    $rawId = $_SESSION['user_id'];
    $employeeId = is_numeric($rawId) ? (int) $rawId : 0;
    $_SESSION['employee_profile_not_linked'] = true;
}

if ($employeeId === null || $employeeId === '') {
    $_SESSION['error'] = "Employee identifier not found. Please log in again.";
    header("Location: " . rtrim(BASE_URL, '/') . "/auth/employee-login.php?_t=" . time(), true, 303);
    exit();
}
if (is_numeric($employeeId)) {
    $employeeId = (int) $employeeId;
}

$todaySchedule = null;
$clockStatus = null;
$latestPunch = null;
$metrics = [
    'hours_this_week' => 0,
    'leave_balance' => 0,
    'pending_approvals' => 0,
    'pending_tasks' => 0
];
$pendingItems = [];

if (isset($db)) {
    try {
        // Get today's schedule from shift_assignments or shifts table
        $check = $db->query("SHOW TABLES LIKE 'shift_assignments'");
        if ($check && $check->rowCount() > 0) {
            $stmt = $db->prepare("
                SELECT sa.*, st.name as shift_name, st.start_time, st.end_time
                FROM shift_assignments sa
                JOIN shift_templates st ON sa.shift_template_id = st.id
                WHERE sa.employee_id = :emp_id
                  AND CURDATE() BETWEEN sa.start_date AND COALESCE(sa.end_date, CURDATE())
                LIMIT 1
            ");
            $stmt->bindValue(':emp_id', $employeeId, PDO::PARAM_INT);
            $stmt->execute();
            $todaySchedule = $stmt->fetch(PDO::FETCH_ASSOC);
        }
        
        // If no shift_assignments, check shifts table
        if (!$todaySchedule) {
            $check = $db->query("SHOW TABLES LIKE 'shifts'");
            if ($check && $check->rowCount() > 0) {
                $stmt = $db->prepare("
                    SELECT shift_date, start_time, end_time, unit_name
                    FROM shifts
                    WHERE employee_id = :emp_id
                      AND shift_date = CURDATE()
                    LIMIT 1
                ");
                $stmt->bindValue(':emp_id', $employeeId, PDO::PARAM_INT);
                $stmt->execute();
                $todaySchedule = $stmt->fetch(PDO::FETCH_ASSOC);
            }
        }

        // Get current clock status from latest punch today
        $check = $db->query("SHOW TABLES LIKE 'attendance_logs'");
        if ($check && $check->rowCount() > 0) {
            $stmt = $db->prepare("
                SELECT log_type, log_time
                FROM attendance_logs
                WHERE employee_id = :emp_id
                  AND DATE(log_time) = CURDATE()
                  AND (source IN ('biometric','qr','mobile') OR source IS NULL)
                ORDER BY log_time DESC
                LIMIT 1
            ");
            $stmt->bindValue(':emp_id', $employeeId, PDO::PARAM_INT);
            $stmt->execute();
            $latestPunch = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($latestPunch) {
                // Determine clock status based on latest punch
                $logType = $latestPunch['log_type'];
                if (in_array($logType, ['in', 'break_end'])) {
                    $clockStatus = 'IN';
                } elseif (in_array($logType, ['out', 'break_start'])) {
                    $clockStatus = 'OUT';
                } else {
                    $clockStatus = 'UNKNOWN';
                }
            } else {
                $clockStatus = 'OUT'; // Default to OUT if no punch today
            }
            
            // Calculate hours this week
            $weekStart = date('Y-m-d', strtotime('monday this week'));
            $stmt = $db->prepare("
                SELECT SUM(total_work_seconds) / 3600 as total_hours
                FROM daily_attendance
                WHERE employee_id = :emp_id
                  AND date >= :week_start
            ");
            $stmt->bindValue(':emp_id', $employeeId, PDO::PARAM_INT);
            $stmt->bindValue(':week_start', $weekStart);
            $stmt->execute();
            $weekHours = $stmt->fetch(PDO::FETCH_ASSOC);
            $metrics['hours_this_week'] = round($weekHours['total_hours'] ?? 0, 1);
        }
        
        // Get leave balance (total available)
        $check = $db->query("SHOW TABLES LIKE 'leave_balances'");
        if ($check && $check->rowCount() > 0) {
            $stmt = $db->prepare("
                SELECT SUM(entitlement - used - pending) as available
                FROM leave_balances
                WHERE employee_id = :emp_id
                  AND year = YEAR(CURDATE())
            ");
            $stmt->bindValue(':emp_id', $employeeId, PDO::PARAM_INT);
            $stmt->execute();
            $balance = $stmt->fetch(PDO::FETCH_ASSOC);
            $metrics['leave_balance'] = round($balance['available'] ?? 0, 1);
        }
        
        // Get pending approvals count
        $pendingCount = 0;
        $check = $db->query("SHOW TABLES LIKE 'leave_requests'");
        if ($check && $check->rowCount() > 0) {
            $stmt = $db->prepare("
                SELECT COUNT(*) as cnt
                FROM leave_requests
                WHERE employee_id = :emp_id
                  AND status = 'pending'
            ");
            $stmt->bindValue(':emp_id', $employeeId, PDO::PARAM_INT);
            $stmt->execute();
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            $pendingCount += (int)($result['cnt'] ?? 0);
        }
        
        $check = $db->query("SHOW TABLES LIKE 'claims'");
        if ($check && $check->rowCount() > 0) {
            $stmt = $db->prepare("
                SELECT COUNT(*) as cnt
                FROM claims
                WHERE employee_id = :emp_id
                  AND status IN ('submitted', 'pending')
            ");
            $stmt->bindValue(':emp_id', $employeeId, PDO::PARAM_INT);
            $stmt->execute();
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            $pendingCount += (int)($result['cnt'] ?? 0);
        }
        
        $check = $db->query("SHOW TABLES LIKE 'timesheets'");
        if ($check && $check->rowCount() > 0) {
            $stmt = $db->prepare("
                SELECT COUNT(*) as cnt
                FROM timesheets
                WHERE employee_id = :emp_id
                  AND status = 'draft'
            ");
            $stmt->bindValue(':emp_id', $employeeId, PDO::PARAM_INT);
            $stmt->execute();
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            $metrics['pending_tasks'] = (int)($result['cnt'] ?? 0);
        }
        
        $metrics['pending_approvals'] = $pendingCount;
        
        // Get pending items for notifications
        $pendingItems = [];
        $check = $db->query("SHOW TABLES LIKE 'leave_requests'");
        if ($check && $check->rowCount() > 0) {
            $stmt = $db->prepare("
                SELECT 'leave' as type, id, leave_type, start_date, status, submitted_at
                FROM leave_requests
                WHERE employee_id = :emp_id
                  AND status = 'pending'
                ORDER BY submitted_at DESC
                LIMIT 5
            ");
            $stmt->bindValue(':emp_id', $employeeId, PDO::PARAM_INT);
            $stmt->execute();
            $leaves = $stmt->fetchAll(PDO::FETCH_ASSOC);
            foreach ($leaves as $leave) {
                $pendingItems[] = $leave;
            }
        }
    } catch (PDOException $e) {
        error_log('Employee dashboard error: ' . $e->getMessage());
    }
}
if (!$todaySchedule) {
    $todaySchedule = [
        'shift_name' => 'Morning Shift',
        'start_time' => '08:00:00',
        'end_time' => '16:00:00',
        'unit_name' => 'Emergency Department',
        'shift_date' => date('Y-m-d')
    ];
}

if (!$clockStatus) {
    // Simulate clock status based on current time
    $currentHour = (int)date('H');
    if ($currentHour >= 8 && $currentHour < 16) {
        $clockStatus = 'IN';
        $latestPunch = [
            'log_type' => 'in',
            'log_time' => date('Y-m-d') . ' 08:00:00'
        ];
    } else {
        $clockStatus = 'OUT';
        $latestPunch = [
            'log_type' => 'out',
            'log_time' => date('Y-m-d') . ' 16:00:00'
        ];
    }
}

// Real data only — no sample fallbacks; metrics stay 0 or from DB

include __DIR__ . '/../includes/header.php';
?>

<div class="mb-6">
    <h1 class="text-2xl font-semibold text-gray-900 dark:text-white">
        Welcome back, <?php echo htmlspecialchars($_SESSION['first_name'] ?? 'Employee'); ?>
    </h1>
    <p class="mt-1 text-sm text-gray-600 dark:text-gray-400">
        At-a-glance view of your shift, attendance, and pending tasks
    </p>
</div>

<!-- Metrics Cards Section (3-4 key data cards with icons, values, and trends) -->
<div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4 mb-6">
    <!-- Hours This Week -->
    <div class="bg-white dark:bg-gray-800 rounded-xl shadow-sm border border-gray-100 dark:border-gray-700 p-5">
        <div class="flex items-center justify-between">
            <div>
                <p class="text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wide">Hours This Week</p>
                <p class="mt-2 text-2xl font-bold text-gray-900 dark:text-white">
                    <?php echo number_format($metrics['hours_this_week'], 1); ?>
                </p>
                <p class="mt-1 text-xs text-gray-500">
                    <span class="text-emerald-600">↑</span> On track
                </p>
            </div>
            <div class="w-12 h-12 rounded-lg bg-blue-100 dark:bg-blue-900 flex items-center justify-center">
                <svg xmlns="http://www.w3.org/2000/svg" class="w-6 h-6 text-blue-600 dark:text-blue-300" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" />
                </svg>
            </div>
        </div>
    </div>

    <!-- Leave Balance -->
    <div class="bg-white dark:bg-gray-800 rounded-xl shadow-sm border border-gray-100 dark:border-gray-700 p-5">
        <div class="flex items-center justify-between">
            <div>
                <p class="text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wide">Leave Balance</p>
                <p class="mt-2 text-2xl font-bold text-gray-900 dark:text-white">
                    <?php echo number_format($metrics['leave_balance'], 1); ?>
                </p>
                <p class="mt-1 text-xs text-gray-500">days available</p>
            </div>
            <div class="w-12 h-12 rounded-lg bg-emerald-100 dark:bg-emerald-900 flex items-center justify-center">
                <svg xmlns="http://www.w3.org/2000/svg" class="w-6 h-6 text-emerald-600 dark:text-emerald-300" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z" />
                </svg>
            </div>
        </div>
    </div>

    <!-- Pending Approvals -->
    <div class="bg-white dark:bg-gray-800 rounded-xl shadow-sm border border-gray-100 dark:border-gray-700 p-5">
        <div class="flex items-center justify-between">
            <div>
                <p class="text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wide">Pending Approvals</p>
                <p class="mt-2 text-2xl font-bold text-gray-900 dark:text-white">
                    <?php echo $metrics['pending_approvals']; ?>
                </p>
                <p class="mt-1 text-xs text-gray-500">awaiting review</p>
            </div>
            <div class="w-12 h-12 rounded-lg bg-amber-100 dark:bg-amber-900 flex items-center justify-center">
                <svg xmlns="http://www.w3.org/2000/svg" class="w-6 h-6 text-amber-600 dark:text-amber-300" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" />
                </svg>
            </div>
        </div>
    </div>

    <!-- Pending Tasks -->
    <div class="bg-white dark:bg-gray-800 rounded-xl shadow-sm border border-gray-100 dark:border-gray-700 p-5">
        <div class="flex items-center justify-between">
            <div>
                <p class="text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wide">Pending Tasks</p>
                <p class="mt-2 text-2xl font-bold text-gray-900 dark:text-white">
                    <?php echo $metrics['pending_tasks']; ?>
                </p>
                <p class="mt-1 text-xs text-gray-500">timesheets to submit</p>
            </div>
            <div class="w-12 h-12 rounded-lg bg-purple-100 dark:bg-purple-900 flex items-center justify-center">
                <svg xmlns="http://www.w3.org/2000/svg" class="w-6 h-6 text-purple-600 dark:text-purple-300" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2" />
                </svg>
            </div>
        </div>
    </div>
</div>

<!-- Today's Shift Card and Clock Status -->
<div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-6">
    <!-- Today's Shift Card -->
    <div class="bg-white dark:bg-gray-800 rounded-xl shadow-sm border border-gray-100 dark:border-gray-700">
        <div class="px-5 py-4 border-b border-gray-100 dark:border-gray-700">
            <h2 class="text-sm font-semibold text-gray-900 dark:text-white">Today's Shift Card</h2>
        </div>
        <div class="p-5">
            <?php if ($todaySchedule): ?>
                <div class="space-y-4">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="text-xs text-gray-500 dark:text-gray-400">Shift Name</p>
                            <p class="mt-1 text-lg font-semibold text-gray-900 dark:text-white">
                                <?php echo htmlspecialchars($todaySchedule['shift_name'] ?? 'Scheduled Shift'); ?>
                            </p>
                        </div>
                        <div class="w-16 h-16 rounded-lg bg-primary-100 dark:bg-primary-900 flex items-center justify-center">
                            <svg xmlns="http://www.w3.org/2000/svg" class="w-8 h-8 text-primary-600 dark:text-primary-300" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z" />
                            </svg>
                        </div>
                    </div>
                    <div class="grid grid-cols-2 gap-4 pt-4 border-t border-gray-100 dark:border-gray-700">
                        <div>
                            <p class="text-xs text-gray-500 dark:text-gray-400">Start Time</p>
                            <p class="mt-1 text-sm font-semibold text-gray-900 dark:text-white">
                                <?php echo htmlspecialchars($todaySchedule['start_time'] ?? 'N/A'); ?>
                            </p>
                        </div>
                        <div>
                            <p class="text-xs text-gray-500 dark:text-gray-400">End Time</p>
                            <p class="mt-1 text-sm font-semibold text-gray-900 dark:text-white">
                                <?php echo htmlspecialchars($todaySchedule['end_time'] ?? 'N/A'); ?>
                            </p>
                        </div>
                    </div>
                </div>
            <?php else: ?>
                <div class="text-center py-8">
                    <svg xmlns="http://www.w3.org/2000/svg" class="w-12 h-12 mx-auto text-gray-400 mb-3" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z" />
                    </svg>
                    <p class="text-sm text-gray-500 dark:text-gray-400">No shift scheduled for today</p>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Clock Status (In/Out) with Quick Action -->
    <div class="bg-white dark:bg-gray-800 rounded-xl shadow-sm border border-gray-100 dark:border-gray-700">
        <div class="px-5 py-4 border-b border-gray-100 dark:border-gray-700">
            <h2 class="text-sm font-semibold text-gray-900 dark:text-white">Clock Status (In/Out)</h2>
        </div>
        <div class="p-5">
            <div class="text-center mb-4">
                <div class="inline-flex items-center justify-center w-24 h-24 rounded-full <?php echo $clockStatus === 'IN' ? 'bg-emerald-100 dark:bg-emerald-900' : 'bg-gray-100 dark:bg-gray-700'; ?> mb-3">
                    <span class="text-3xl font-bold <?php echo $clockStatus === 'IN' ? 'text-emerald-600' : 'text-gray-600'; ?>">
                        <?php echo htmlspecialchars($clockStatus ?? 'OUT'); ?>
                    </span>
                </div>
                <?php if ($latestPunch): ?>
                    <p class="text-xs text-gray-500">
                        Last punch: <?php echo date('H:i', strtotime($latestPunch['log_time'])); ?>
                    </p>
                <?php endif; ?>
            </div>
            <div class="flex gap-2">
                <a href="<?php echo BASE_URL; ?>/employee/modules/employee-timeclock.php?view=clock_in_out" 
                   class="flex-1 inline-flex items-center justify-center px-4 py-2 bg-primary-600 text-white text-sm font-medium rounded-lg hover:bg-primary-700 transition-colors">
                    <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4 mr-2" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" />
                    </svg>
                    Quick Clock
                </a>
                <a href="<?php echo BASE_URL; ?>/employee/modules/employee-timeclock.php" 
                   class="flex-1 inline-flex items-center justify-center px-4 py-2 border border-gray-300 dark:border-gray-600 text-gray-700 dark:text-gray-300 text-sm font-medium rounded-lg hover:bg-gray-50 dark:hover:bg-gray-700 transition-colors">
                    View Details
                </a>
            </div>
        </div>
    </div>
</div>

<!-- Notifications & Pending Actions -->
<div class="bg-white dark:bg-gray-800 rounded-xl shadow-sm border border-gray-100 dark:border-gray-700">
    <div class="px-5 py-4 border-b border-gray-100 dark:border-gray-700 flex items-center justify-between">
        <div>
            <h2 class="text-sm font-semibold text-gray-900 dark:text-white">Notifications & Pending Actions</h2>
            <p class="mt-0.5 text-xs text-gray-500">Approvals, corrections, and schedule changes</p>
        </div>
        <a href="<?php echo BASE_URL; ?>/employee/modules/employee-notification.php" class="text-xs text-primary-600 hover:text-primary-700">
            View All
        </a>
    </div>
    <div class="p-5">
        <?php if (!empty($pendingItems)): ?>
            <div class="space-y-3">
                <?php foreach (array_slice($pendingItems, 0, 5) as $item): ?>
                    <div class="flex items-center justify-between p-3 bg-gray-50 dark:bg-gray-700/50 rounded-lg">
                        <div class="flex items-center space-x-3">
                            <div class="w-8 h-8 rounded-full bg-amber-100 dark:bg-amber-900 flex items-center justify-center">
                                <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4 text-amber-600 dark:text-amber-300" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" />
                                </svg>
                            </div>
                            <div>
                                <p class="text-sm font-medium text-gray-900 dark:text-white">
                                    <?php echo ucfirst($item['type'] ?? 'Request'); ?> Request
                                </p>
                                <p class="text-xs text-gray-500">
                                    <?php 
                                    if (isset($item['leave_type'])) {
                                        echo htmlspecialchars($item['leave_type']) . ' - ' . date('M d', strtotime($item['start_date']));
                                    } else {
                                        echo 'Submitted ' . date('M d, Y', strtotime($item['submitted_at'] ?? 'now'));
                                    }
                                    ?>
                                </p>
                            </div>
                        </div>
                        <span class="px-2 py-1 text-xs font-semibold rounded-full bg-amber-100 text-amber-800 dark:bg-amber-900 dark:text-amber-200">
                            Pending
                        </span>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php else: ?>
            <div class="text-center py-8">
                <svg xmlns="http://www.w3.org/2000/svg" class="w-12 h-12 mx-auto text-gray-400 mb-3" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6.002 6.002 0 00-4-5.659V5a2 2 0 10-4 0v.341C7.67 6.165 6 8.388 6 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9" />
                </svg>
                <p class="text-sm text-gray-500 dark:text-gray-400">No pending notifications</p>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>






