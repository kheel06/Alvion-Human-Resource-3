<?php
require_once __DIR__ . '/../../config/config.php';
requireAuth();

$page_title = 'Notifications';
$employeeId = getCurrentEmployeeId($db);

$notifications = [];
if (isset($db) && $employeeId) {
    try {
        // Gather recent activity as notifications from various tables
        $items = [];

        // Leave request status changes
        $stmt = $db->prepare("
            SELECT 'leave' as type, lr.status, lr.leave_type, lr.total_days, lr.start_date, lr.submitted_at as created_at,
                   CASE 
                       WHEN lr.status = 'approved' THEN CONCAT('Your ', lr.leave_type, ' leave (', lr.total_days, ' days from ', lr.start_date, ') has been approved')
                       WHEN lr.status = 'rejected' THEN CONCAT('Your ', lr.leave_type, ' leave (', lr.total_days, ' days from ', lr.start_date, ') was declined')
                       WHEN lr.status = 'pending' THEN CONCAT('Your ', lr.leave_type, ' leave request (', lr.total_days, ' days from ', lr.start_date, ') is pending review')
                       ELSE CONCAT('Leave request update: ', lr.status)
                   END as message
            FROM leave_requests lr
            WHERE lr.employee_id = ?
            ORDER BY lr.submitted_at DESC
            LIMIT 10
        ");
        $stmt->execute([$employeeId]);
        $items = array_merge($items, $stmt->fetchAll(PDO::FETCH_ASSOC));

        // Claim status changes
        $stmt = $db->prepare("
            SELECT 'claim' as type, c.status, c.amount, c.description, COALESCE(c.submitted_at, c.created_at) as created_at,
                   CASE
                       WHEN c.status = 'approved' THEN CONCAT('Claim of ₱', FORMAT(c.amount, 2), ' (', LEFT(c.description, 40), ') approved')
                       WHEN c.status = 'paid' THEN CONCAT('Payment processed for ₱', FORMAT(c.amount, 2), ' claim')
                       WHEN c.status = 'rejected' THEN CONCAT('Claim of ₱', FORMAT(c.amount, 2), ' was rejected')
                       WHEN c.status = 'submitted' THEN CONCAT('Claim of ₱', FORMAT(c.amount, 2), ' submitted for review')
                       WHEN c.status = 'endorsed' THEN CONCAT('Claim of ₱', FORMAT(c.amount, 2), ' endorsed for processing')
                       ELSE CONCAT('Claim update: ', c.status)
                   END as message
            FROM claims c
            WHERE c.employee_id = ?
            ORDER BY COALESCE(c.submitted_at, c.created_at) DESC
            LIMIT 10
        ");
        $stmt->execute([$employeeId]);
        $items = array_merge($items, $stmt->fetchAll(PDO::FETCH_ASSOC));

        // Attendance exceptions / corrections
        $stmt = $db->prepare("
            SELECT 'attendance' as type, ae.status, ae.log_date as created_at, ae.exception_type,
                   CONCAT('Attendance exception on ', ae.log_date, ': ', REPLACE(ae.exception_type, '_', ' '), ' (', ae.status, ')') as message
            FROM attendance_exceptions ae
            WHERE ae.employee_id = ?
            ORDER BY ae.log_date DESC
            LIMIT 10
        ");
        $stmt->execute([$employeeId]);
        $items = array_merge($items, $stmt->fetchAll(PDO::FETCH_ASSOC));

        // Overtime request updates
        $stmt = $db->prepare("
            SELECT 'overtime' as type, o.status, o.request_date as created_at, o.hours,
                   CASE
                       WHEN o.status = 'approved' THEN CONCAT('Overtime request for ', o.hours, 'hrs on ', o.request_date, ' approved')
                       WHEN o.status = 'pending' THEN CONCAT('Overtime request for ', o.hours, 'hrs on ', o.request_date, ' pending')
                       WHEN o.status = 'rejected' THEN CONCAT('Overtime request for ', o.hours, 'hrs on ', o.request_date, ' rejected')
                       ELSE CONCAT('Overtime update: ', o.status)
                   END as message
            FROM overtime_requests o
            WHERE o.employee_id = ?
            ORDER BY o.created_at DESC
            LIMIT 10
        ");
        $stmt->execute([$employeeId]);
        $items = array_merge($items, $stmt->fetchAll(PDO::FETCH_ASSOC));

        // Sort by date descending
        usort($items, function ($a, $b) {
            return strtotime($b['created_at'] ?? '2000-01-01') - strtotime($a['created_at'] ?? '2000-01-01');
        });

        $notifications = array_slice($items, 0, 25);
    } catch (PDOException $e) {
        error_log('Notifications error: ' . $e->getMessage());
    }
}

include __DIR__ . '/../../includes/header.php';
?>

<div class="mb-6">
    <h1 class="text-2xl font-semibold text-gray-900 dark:text-white">Notifications</h1>
    <p class="mt-1 text-sm text-gray-600 dark:text-gray-400">Recent updates on your requests, attendance, and claims</p>
</div>

<div class="bg-white dark:bg-gray-800 rounded-xl shadow-sm border border-gray-100 dark:border-gray-700">
    <?php if (empty($notifications)): ?>
        <div class="p-12 text-center">
            <svg xmlns="http://www.w3.org/2000/svg" class="w-12 h-12 mx-auto text-gray-300 dark:text-gray-600 mb-3" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6.002 6.002 0 00-4-5.659V5a2 2 0 10-4 0v.341C7.67 6.165 6 8.388 6 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9" />
            </svg>
            <p class="text-gray-500 dark:text-gray-400 text-sm">No notifications yet</p>
        </div>
    <?php else: ?>
        <div class="divide-y divide-gray-100 dark:divide-gray-700">
            <?php foreach ($notifications as $n): 
                $icon = 'bell'; $iconColor = 'text-gray-400'; $bgColor = 'bg-gray-100 dark:bg-gray-700';
                switch ($n['type'] ?? '') {
                    case 'leave':
                        $iconColor = 'text-purple-500';
                        $bgColor = 'bg-purple-50 dark:bg-purple-900/30';
                        break;
                    case 'claim':
                        $iconColor = 'text-green-500';
                        $bgColor = 'bg-green-50 dark:bg-green-900/30';
                        break;
                    case 'attendance':
                        $iconColor = 'text-amber-500';
                        $bgColor = 'bg-amber-50 dark:bg-amber-900/30';
                        break;
                    case 'overtime':
                        $iconColor = 'text-blue-500';
                        $bgColor = 'bg-blue-50 dark:bg-blue-900/30';
                        break;
                }
                $statusBadge = '';
                $status = $n['status'] ?? '';
                if ($status === 'approved' || $status === 'paid') $statusBadge = '<span class="ml-2 px-2 py-0.5 text-xs font-medium bg-emerald-100 text-emerald-700 dark:bg-emerald-900 dark:text-emerald-200 rounded-full">'.ucfirst($status).'</span>';
                elseif ($status === 'pending' || $status === 'submitted') $statusBadge = '<span class="ml-2 px-2 py-0.5 text-xs font-medium bg-amber-100 text-amber-700 dark:bg-amber-900 dark:text-amber-200 rounded-full">'.ucfirst($status).'</span>';
                elseif ($status === 'rejected') $statusBadge = '<span class="ml-2 px-2 py-0.5 text-xs font-medium bg-red-100 text-red-700 dark:bg-red-900 dark:text-red-200 rounded-full">Rejected</span>';
            ?>
                <div class="flex items-start gap-4 px-5 py-4 hover:bg-gray-50 dark:hover:bg-gray-700/50 transition-colors">
                    <div class="w-9 h-9 rounded-full flex items-center justify-center flex-shrink-0 <?php echo $bgColor; ?>">
                        <?php if ($n['type'] === 'leave'): ?>
                            <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4 <?php echo $iconColor; ?>" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z" /></svg>
                        <?php elseif ($n['type'] === 'claim'): ?>
                            <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4 <?php echo $iconColor; ?>" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z" /></svg>
                        <?php elseif ($n['type'] === 'attendance'): ?>
                            <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4 <?php echo $iconColor; ?>" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" /></svg>
                        <?php else: ?>
                            <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4 <?php echo $iconColor; ?>" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6.002 6.002 0 00-4-5.659V5a2 2 0 10-4 0v.341C7.67 6.165 6 8.388 6 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9" /></svg>
                        <?php endif; ?>
                    </div>
                    <div class="flex-1 min-w-0">
                        <p class="text-sm text-gray-900 dark:text-white"><?php echo htmlspecialchars($n['message'] ?? 'Update'); ?><?php echo $statusBadge; ?></p>
                        <p class="text-xs text-gray-500 dark:text-gray-400 mt-1">
                            <?php 
                                $dt = $n['created_at'] ?? null;
                                if ($dt) {
                                    $ts = strtotime($dt);
                                    $diff = time() - $ts;
                                    if ($diff < 3600) echo floor($diff/60) . ' minutes ago';
                                    elseif ($diff < 86400) echo floor($diff/3600) . ' hours ago';
                                    elseif ($diff < 604800) echo floor($diff/86400) . ' days ago';
                                    else echo date('M j, Y', $ts);
                                }
                            ?>
                        </p>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
