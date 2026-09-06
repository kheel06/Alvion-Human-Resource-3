<?php
/**
 * Audit Logs
 * Track all actions by users from department_accounts
 */
require_once '../../config/config.php';
requireAuth();
checkRole(['admin']);

$page_title = "Audit Logs";

// Get filter parameters
$start_date = $_GET['start_date'] ?? date('Y-m-d', strtotime('-30 days'));
$end_date = $_GET['end_date'] ?? date('Y-m-d');
$employee_id = $_GET['employee_id'] ?? 'all';
$action_filter = $_GET['action'] ?? 'all';
$module_filter = $_GET['module'] ?? 'all';
$search = $_GET['search'] ?? '';

// Build query - join audit_logs with department_accounts
$query = "SELECT al.*, 
          da.employee_id, 
          da.employee_fname as first_name, 
          da.employee_lname as last_name, 
          da.employee_email as email,
          da.role_name
          FROM audit_logs al
          LEFT JOIN department_accounts da ON al.employee_id = da.employee_id
          WHERE DATE(al.created_at) BETWEEN :start_date AND :end_date";

$params = [':start_date' => $start_date, ':end_date' => $end_date];

if ($employee_id !== 'all') {
    $query .= " AND al.employee_id = :employee_id";
    $params[':employee_id'] = $employee_id;
}

if ($action_filter !== 'all') {
    $query .= " AND al.action = :action";
    $params[':action'] = $action_filter;
}

if ($module_filter !== 'all') {
    $query .= " AND al.module = :module";
    $params[':module'] = $module_filter;
}

if ($search) {
    $query .= " AND (al.action LIKE :search OR al.module LIKE :search OR al.ip_address LIKE :search OR da.employee_fname LIKE :search OR da.employee_lname LIKE :search OR da.employee_email LIKE :search)";
    $params[':search'] = "%$search%";
}

$query .= " ORDER BY al.created_at DESC LIMIT 500";

$stmt = $db->prepare($query);
foreach ($params as $key => $value) {
    $stmt->bindValue($key, $value);
}
$stmt->execute();
$logs = $stmt->fetchAll();

// Get employees for filter
try {
    $employees_query = "SELECT DISTINCT da.employee_id, da.employee_fname, da.employee_lname, da.employee_email 
                        FROM department_accounts da 
                        INNER JOIN audit_logs al ON da.employee_id = al.employee_id 
                        ORDER BY da.employee_fname, da.employee_lname";
    $employees_stmt = $db->prepare($employees_query);
    $employees_stmt->execute();
    $employees = $employees_stmt->fetchAll();
} catch (PDOException $e) {
    $employees = [];
}

// Get unique actions and modules from audit_logs
try {
    $actions_query = "SELECT DISTINCT action FROM audit_logs ORDER BY action";
    $actions_stmt = $db->prepare($actions_query);
    $actions_stmt->execute();
    $available_actions = array_column($actions_stmt->fetchAll(), 'action');
} catch (PDOException $e) {
    $available_actions = [];
}

try {
    $modules_query = "SELECT DISTINCT module FROM audit_logs ORDER BY module";
    $modules_stmt = $db->prepare($modules_query);
    $modules_stmt->execute();
    $available_modules = array_column($modules_stmt->fetchAll(), 'module');
} catch (PDOException $e) {
    $available_modules = [];
}

// Get statistics
$stats_query = "SELECT 
    COUNT(*) as total,
    COUNT(DISTINCT employee_id) as unique_users,
    SUM(CASE WHEN action = 'login' THEN 1 ELSE 0 END) as logins,
    SUM(CASE WHEN action = 'create' THEN 1 ELSE 0 END) as creates,
    SUM(CASE WHEN action = 'update' THEN 1 ELSE 0 END) as updates,
    SUM(CASE WHEN action = 'delete' THEN 1 ELSE 0 END) as deletes
    FROM audit_logs
    WHERE DATE(created_at) BETWEEN :start_date AND :end_date";
$stats_stmt = $db->prepare($stats_query);
$stats_stmt->bindParam(':start_date', $start_date);
$stats_stmt->bindParam(':end_date', $end_date);
$stats_stmt->execute();
$stats = $stats_stmt->fetch();

include '../../includes/header.php';
?>

<div class="mb-6">
    <div class="flex justify-between items-center">
        <div>
            <h1 class="text-2xl font-bold text-gray-900 dark:text-white">Audit Logs</h1>
            <p class="text-gray-600 dark:text-gray-400">Track all actions by users</p>
        </div>
        <?php if (count($logs) > 0): ?>
            <div class="flex space-x-2">
                <a href="?<?php echo http_build_query(array_merge($_GET, ['export' => 'csv'])); ?>" 
                   class="px-4 py-2 border border-gray-300 dark:border-gray-600 rounded-md shadow-sm text-sm font-medium text-gray-700 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-gray-700">
                    Export CSV
                </a>
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- Statistics -->
<div class="grid grid-cols-1 gap-6 sm:grid-cols-6 mb-6">
    <div class="bg-white dark:bg-gray-800 shadow rounded-lg p-4">
        <p class="text-sm text-gray-500 dark:text-gray-400">Total Actions</p>
        <p class="text-2xl font-bold text-gray-900 dark:text-white"><?php echo $stats['total'] ?? 0; ?></p>
    </div>
    <div class="bg-white dark:bg-gray-800 shadow rounded-lg p-4">
        <p class="text-sm text-gray-500 dark:text-gray-400">Unique Users</p>
        <p class="text-2xl font-bold text-blue-600"><?php echo $stats['unique_users'] ?? 0; ?></p>
    </div>
    <div class="bg-white dark:bg-gray-800 shadow rounded-lg p-4">
        <p class="text-sm text-gray-500 dark:text-gray-400">Logins</p>
        <p class="text-2xl font-bold text-green-600"><?php echo $stats['logins'] ?? 0; ?></p>
    </div>
    <div class="bg-white dark:bg-gray-800 shadow rounded-lg p-4">
        <p class="text-sm text-gray-500 dark:text-gray-400">Creates</p>
        <p class="text-2xl font-bold text-purple-600"><?php echo $stats['creates'] ?? 0; ?></p>
    </div>
    <div class="bg-white dark:bg-gray-800 shadow rounded-lg p-4">
        <p class="text-sm text-gray-500 dark:text-gray-400">Updates</p>
        <p class="text-2xl font-bold text-yellow-600"><?php echo $stats['updates'] ?? 0; ?></p>
    </div>
    <div class="bg-white dark:bg-gray-800 shadow rounded-lg p-4">
        <p class="text-sm text-gray-500 dark:text-gray-400">Deletes</p>
        <p class="text-2xl font-bold text-red-600"><?php echo $stats['deletes'] ?? 0; ?></p>
    </div>
</div>

<!-- Filters -->
<div class="bg-white dark:bg-gray-800 shadow rounded-lg mb-6">
    <div class="px-4 py-5 sm:p-6">
        <form method="GET" class="grid grid-cols-1 gap-4 sm:grid-cols-6">
            <div>
                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">Start Date</label>
                <input type="date" name="start_date" value="<?php echo $start_date; ?>"
                    class="mt-1 block w-full border border-gray-300 dark:border-gray-600 rounded-md shadow-sm py-2 px-3 dark:bg-gray-700 dark:text-white">
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">End Date</label>
                <input type="date" name="end_date" value="<?php echo $end_date; ?>"
                    class="mt-1 block w-full border border-gray-300 dark:border-gray-600 rounded-md shadow-sm py-2 px-3 dark:bg-gray-700 dark:text-white">
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">Employee</label>
                <select name="employee_id" class="mt-1 block w-full border border-gray-300 dark:border-gray-600 rounded-md shadow-sm py-2 px-3 dark:bg-gray-700 dark:text-white">
                    <option value="all" <?php echo $employee_id === 'all' ? 'selected' : ''; ?>>All Employees</option>
                    <?php foreach ($employees as $emp): ?>
                        <option value="<?php echo htmlspecialchars($emp['employee_id']); ?>" <?php echo $employee_id == $emp['employee_id'] ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($emp['employee_fname'] . ' ' . $emp['employee_lname'] . ' (' . $emp['employee_id'] . ')'); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">Action</label>
                <select name="action" class="mt-1 block w-full border border-gray-300 dark:border-gray-600 rounded-md shadow-sm py-2 px-3 dark:bg-gray-700 dark:text-white">
                    <option value="all" <?php echo $action_filter === 'all' ? 'selected' : ''; ?>>All Actions</option>
                    <?php foreach ($available_actions as $act): ?>
                        <option value="<?php echo htmlspecialchars($act); ?>" <?php echo $action_filter === $act ? 'selected' : ''; ?>>
                            <?php echo ucfirst(htmlspecialchars($act)); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">Module</label>
                <select name="module" class="mt-1 block w-full border border-gray-300 dark:border-gray-600 rounded-md shadow-sm py-2 px-3 dark:bg-gray-700 dark:text-white">
                    <option value="all" <?php echo $module_filter === 'all' ? 'selected' : ''; ?>>All Modules</option>
                    <?php foreach ($available_modules as $mod): ?>
                        <option value="<?php echo htmlspecialchars($mod); ?>" <?php echo $module_filter === $mod ? 'selected' : ''; ?>>
                            <?php echo ucfirst(htmlspecialchars($mod)); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">Search</label>
                <input type="text" name="search" value="<?php echo htmlspecialchars($search); ?>" placeholder="Search logs..."
                    class="mt-1 block w-full border border-gray-300 dark:border-gray-600 rounded-md shadow-sm py-2 px-3 dark:bg-gray-700 dark:text-white">
            </div>
            <div class="sm:col-span-6">
                <button type="submit" class="px-4 py-2 border border-transparent rounded-md shadow-sm text-sm font-medium text-white bg-primary-600 hover:bg-primary-700">
                    Filter Logs
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Audit Logs Table -->
<div class="bg-white dark:bg-gray-800 shadow rounded-lg">
    <div class="px-4 py-5 sm:px-6 border-b border-gray-200 dark:border-gray-700">
        <h3 class="text-lg leading-6 font-medium text-gray-900 dark:text-white">Activity Log</h3>
    </div>
    <div class="px-4 py-5 sm:p-6">
        <?php if (count($logs) > 0): ?>
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                    <thead class="bg-gray-50 dark:bg-gray-700">
                        <tr>
                            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Timestamp</th>
                            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Employee</th>
                            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Action</th>
                            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Module</th>
                            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Details</th>
                            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">IP Address</th>
                        </tr>
                    </thead>
                    <tbody class="bg-white dark:bg-gray-800 divide-y divide-gray-200 dark:divide-gray-700">
                        <?php foreach ($logs as $log): ?>
                            <tr>
                                <td class="px-4 py-4 whitespace-nowrap text-sm text-gray-700 dark:text-gray-300">
                                    <?php echo date('M d, Y H:i:s', strtotime($log['created_at'])); ?>
                                </td>
                                <td class="px-4 py-4 whitespace-nowrap">
                                    <div class="text-sm font-medium text-gray-900 dark:text-white">
                                        <?php echo htmlspecialchars(($log['first_name'] ?? 'Unknown') . ' ' . ($log['last_name'] ?? '')); ?>
                                    </div>
                                    <div class="text-xs text-gray-500 dark:text-gray-400">
                                        <?php echo htmlspecialchars($log['employee_id'] ?? 'N/A'); ?>
                                        <?php if ($log['role_name']): ?>
                                            <span class="ml-1 text-blue-600 dark:text-blue-400">(<?php echo htmlspecialchars($log['role_name']); ?>)</span>
                                        <?php endif; ?>
                                    </div>
                                </td>
                                <td class="px-4 py-4 whitespace-nowrap">
                                    <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full 
                                        <?php 
                                        $action = $log['action'] ?? 'other';
                                        if ($action === 'login') {
                                            echo 'bg-green-100 text-green-800 dark:bg-green-900 dark:text-green-200';
                                        } elseif ($action === 'logout') {
                                            echo 'bg-gray-100 text-gray-800 dark:bg-gray-700 dark:text-gray-200';
                                        } elseif ($action === 'create') {
                                            echo 'bg-blue-100 text-blue-800 dark:bg-blue-900 dark:text-blue-200';
                                        } elseif ($action === 'update') {
                                            echo 'bg-yellow-100 text-yellow-800 dark:bg-yellow-900 dark:text-yellow-200';
                                        } elseif ($action === 'delete') {
                                            echo 'bg-red-100 text-red-800 dark:bg-red-900 dark:text-red-200';
                                        } else {
                                            echo 'bg-purple-100 text-purple-800 dark:bg-purple-900 dark:text-purple-200';
                                        }
                                        ?>">
                                        <?php echo ucfirst(htmlspecialchars($action)); ?>
                                    </span>
                                </td>
                                <td class="px-4 py-4 whitespace-nowrap text-sm text-gray-700 dark:text-gray-300">
                                    <?php echo htmlspecialchars($log['module'] ?? 'N/A'); ?>
                                </td>
                                <td class="px-4 py-4 text-sm text-gray-700 dark:text-gray-300">
                                    <?php if ($log['record_id']): ?>
                                        <span class="text-xs text-gray-500 dark:text-gray-400">Record ID: <?php echo htmlspecialchars($log['record_id']); ?></span>
                                    <?php endif; ?>
                                    <?php if ($log['old_values'] || $log['new_values']): ?>
                                        <details class="mt-1">
                                            <summary class="text-xs text-blue-600 dark:text-blue-400 cursor-pointer">View Changes</summary>
                                            <div class="mt-2 text-xs">
                                                <?php if ($log['old_values']): ?>
                                                    <div class="text-red-600 dark:text-red-400">Old: <?php echo htmlspecialchars(substr($log['old_values'], 0, 100)); ?></div>
                                                <?php endif; ?>
                                                <?php if ($log['new_values']): ?>
                                                    <div class="text-green-600 dark:text-green-400">New: <?php echo htmlspecialchars(substr($log['new_values'], 0, 100)); ?></div>
                                                <?php endif; ?>
                                            </div>
                                        </details>
                                    <?php endif; ?>
                                </td>
                                <td class="px-4 py-4 whitespace-nowrap text-sm text-gray-700 dark:text-gray-300">
                                    <?php echo htmlspecialchars($log['ip_address'] ?? 'N/A'); ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php else: ?>
            <div class="text-center py-8">
                <svg xmlns="http://www.w3.org/2000/svg" width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="text-gray-300 dark:text-gray-600 mx-auto mb-2">
                    <rect width="8" height="4" x="8" y="2" rx="1" ry="1"></rect>
                    <path d="M16 4h2a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h2"></path>
                    <path d="M12 11h4"></path>
                    <path d="M12 16h4"></path>
                    <path d="M8 11h.01"></path>
                    <path d="M8 16h.01"></path>
                </svg>
                <p class="text-gray-500 dark:text-gray-400">No audit logs found for the selected period</p>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php include '../../includes/footer.php'; ?>
