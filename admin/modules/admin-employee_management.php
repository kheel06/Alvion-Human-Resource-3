<?php
require_once __DIR__ . '/../../config/config.php';
requireAuth();
checkRole(['admin', 'super admin', 'hr_admin']);

$page_title = 'Employees & Assignments (dept/unit)';

$employees = [];
$departments = [];
$units = [];
$viewEmployee = null;
$viewEmployeeShifts = [];
$metrics = [
    'total_employees' => 0,
    'active_employees' => 0,
    'by_department' => [],
    'by_unit' => []
];

// Check if employees table has department_id column
$hasDeptId = false;
if (isset($db)) {
    try {
        $cols = $db->query("SHOW COLUMNS FROM employees LIKE 'department_id'");
        $hasDeptId = $cols && $cols->rowCount() > 0;
    } catch (PDOException $e) { /* ignore */ }
}

if (isset($db)) {
    try {
        // Get departments/units for grouping
        try {
            $stmt = $db->prepare("SELECT * FROM departments ORDER BY name");
            $stmt->execute();
            $departments = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) { $departments = []; }
        
        try {
            $stmt = $db->prepare("SELECT * FROM units WHERE is_active = 1 ORDER BY name");
            $stmt->execute();
            $units = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) { $units = []; }
        
        // Get employees with unit info (and department if column exists)
        if ($hasDeptId) {
            $stmt = $db->prepare("
                SELECT e.*, 
                       u.name as unit_name, u.code as unit_code,
                       d.name as department_name, d.code as department_code
                FROM employees e
                LEFT JOIN units u ON e.unit_id = u.id
                LEFT JOIN departments d ON e.department_id = d.id
                WHERE e.deleted_at IS NULL
                ORDER BY e.last_name, e.first_name
                LIMIT 100
            ");
        } else {
            $stmt = $db->prepare("
                SELECT e.*, 
                       u.name as unit_name, u.code as unit_code
                FROM employees e
                LEFT JOIN units u ON e.unit_id = u.id
                WHERE e.deleted_at IS NULL
                ORDER BY e.last_name, e.first_name
                LIMIT 100
            ");
        }
        $stmt->execute();
        $employees = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Get metrics
        $stmt = $db->prepare("SELECT COUNT(*) as cnt FROM employees WHERE deleted_at IS NULL");
        $stmt->execute();
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        $metrics['total_employees'] = (int)($result['cnt'] ?? 0);
        
        $stmt = $db->prepare("SELECT COUNT(*) as cnt FROM employees WHERE status = 'active' AND deleted_at IS NULL");
        $stmt->execute();
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        $metrics['active_employees'] = (int)($result['cnt'] ?? 0);
        
        // Get employees by unit (or department if column exists)
        if ($hasDeptId && !empty($departments)) {
            $stmt = $db->prepare("
                SELECT d.name, COUNT(e.id) as count
                FROM departments d
                LEFT JOIN employees e ON d.id = e.department_id AND e.deleted_at IS NULL
                GROUP BY d.id, d.name
                ORDER BY count DESC
            ");
            $stmt->execute();
            $metrics['by_department'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } else {
            $stmt = $db->prepare("
                SELECT u.name, COUNT(e.id) as count
                FROM units u
                LEFT JOIN employees e ON u.id = e.unit_id AND e.deleted_at IS NULL
                WHERE u.is_active = 1
                GROUP BY u.id, u.name
                ORDER BY count DESC
            ");
            $stmt->execute();
            $metrics['by_department'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }
        
        // Handle form submissions
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $action = $_POST['action'] ?? '';
            
            if ($action === 'assign_department') {
                $employee_id = (int)($_POST['employee_id'] ?? 0);
                $unit_id = !empty($_POST['department_id']) ? (int)$_POST['department_id'] : null;
                
                if ($employee_id) {
                    $stmt = $db->prepare("UPDATE employees SET unit_id = :unit_id WHERE id = :emp_id");
                    $stmt->execute([':unit_id' => $unit_id, ':emp_id' => $employee_id]);
                    $_SESSION['success'] = 'Unit assignment updated successfully';
                    header('Location: ' . $_SERVER['PHP_SELF']);
                    exit;
                }
            }
        }

        // View employee: fetch details + schedule (shift_assignments) + roster (roster_assignments)
        $view_employee_id = isset($_GET['view_employee']) ? (int)$_GET['view_employee'] : 0;
        if ($view_employee_id > 0) {
            $stmt = $db->prepare("
                SELECT e.*, u.name as unit_name, u.code as unit_code
                FROM employees e
                LEFT JOIN units u ON e.unit_id = u.id
                WHERE e.id = ? AND e.deleted_at IS NULL
            ");
            $stmt->execute([$view_employee_id]);
            $viewEmployee = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($viewEmployee) {
                $hasShiftAssign = $db->query("SHOW TABLES LIKE 'shift_assignments'")->rowCount() > 0;
                if ($hasShiftAssign && $db->query("SHOW TABLES LIKE 'shift_templates'")->rowCount() > 0) {
                    $stmt = $db->prepare("
                        SELECT sa.start_date, sa.end_date, st.name as shift_name, st.code as shift_code,
                               st.start_time, st.end_time, st.is_night_shift
                        FROM shift_assignments sa
                        JOIN shift_templates st ON sa.shift_template_id = st.id
                        WHERE sa.employee_id = ?
                        ORDER BY sa.start_date DESC
                        LIMIT 20
                    ");
                    $stmt->execute([$view_employee_id]);
                    $viewEmployeeShifts = $stmt->fetchAll(PDO::FETCH_ASSOC);
                }
            }
        }
    } catch (PDOException $e) {
        error_log('Employee Management error: ' . $e->getMessage());
    }
}

include __DIR__ . '/../../includes/header.php';
?>

<div class="mb-6">
    <h1 class="text-2xl font-semibold text-gray-900 dark:text-white">Employees & Assignments (dept/unit)</h1>
    <p class="mt-1 text-sm text-gray-600 dark:text-gray-400">Manage employee assignments to departments and units</p>
</div>

<?php if (isset($_SESSION['success'])): ?>
    <div class="mb-4 bg-emerald-50 dark:bg-emerald-900/20 border border-emerald-200 dark:border-emerald-800 text-emerald-800 dark:text-emerald-200 px-4 py-3 rounded-lg">
        <?php echo htmlspecialchars($_SESSION['success']); unset($_SESSION['success']); ?>
    </div>
<?php endif; ?>

<!-- Metrics Cards -->
<div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4 mb-6">
    <div class="bg-white dark:bg-gray-800 rounded-xl shadow-sm border border-gray-100 dark:border-gray-700 p-5">
        <div class="flex items-center justify-between">
            <div>
                <p class="text-xs font-medium text-gray-500 dark:text-gray-400 uppercase">Total Employees</p>
                <p class="mt-2 text-2xl font-bold text-gray-900 dark:text-white"><?php echo $metrics['total_employees']; ?></p>
                <p class="mt-1 text-xs text-gray-500">all employees</p>
            </div>
            <div class="w-12 h-12 rounded-lg bg-blue-100 dark:bg-blue-900 flex items-center justify-center">
                <svg xmlns="http://www.w3.org/2000/svg" class="w-6 h-6 text-blue-600 dark:text-blue-300" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z" />
                </svg>
            </div>
        </div>
    </div>

    <div class="bg-white dark:bg-gray-800 rounded-xl shadow-sm border border-gray-100 dark:border-gray-700 p-5">
        <div class="flex items-center justify-between">
            <div>
                <p class="text-xs font-medium text-gray-500 dark:text-gray-400 uppercase">Active Employees</p>
                <p class="mt-2 text-2xl font-bold text-gray-900 dark:text-white"><?php echo $metrics['active_employees']; ?></p>
                <p class="mt-1 text-xs text-emerald-600">currently active</p>
            </div>
            <div class="w-12 h-12 rounded-lg bg-emerald-100 dark:bg-emerald-900 flex items-center justify-center">
                <svg xmlns="http://www.w3.org/2000/svg" class="w-6 h-6 text-emerald-600 dark:text-emerald-300" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" />
                </svg>
            </div>
        </div>
    </div>

    <div class="bg-white dark:bg-gray-800 rounded-xl shadow-sm border border-gray-100 dark:border-gray-700 p-5">
        <div class="flex items-center justify-between">
            <div>
                <p class="text-xs font-medium text-gray-500 dark:text-gray-400 uppercase">Departments</p>
                <p class="mt-2 text-2xl font-bold text-gray-900 dark:text-white"><?php echo count($departments); ?></p>
                <p class="mt-1 text-xs text-gray-500">total departments</p>
            </div>
            <div class="w-12 h-12 rounded-lg bg-purple-100 dark:bg-purple-900 flex items-center justify-center">
                <svg xmlns="http://www.w3.org/2000/svg" class="w-6 h-6 text-purple-600 dark:text-purple-300" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4" />
                </svg>
            </div>
        </div>
    </div>

    <div class="bg-white dark:bg-gray-800 rounded-xl shadow-sm border border-gray-100 dark:border-gray-700 p-5">
        <div class="flex items-center justify-between">
            <div>
                <p class="text-xs font-medium text-gray-500 dark:text-gray-400 uppercase">Unassigned</p>
                <p class="mt-2 text-2xl font-bold text-gray-900 dark:text-white">
                    <?php 
                    $unassigned = array_filter($employees, fn($e) => empty($e['unit_id']) && empty($e['department_id'] ?? null));
                    echo count($unassigned);
                    ?>
                </p>
                <p class="mt-1 text-xs text-amber-600">needs assignment</p>
            </div>
            <div class="w-12 h-12 rounded-lg bg-amber-100 dark:bg-amber-900 flex items-center justify-center">
                <svg xmlns="http://www.w3.org/2000/svg" class="w-6 h-6 text-amber-600 dark:text-amber-300" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
                </svg>
            </div>
        </div>
    </div>
</div>

<div class="grid grid-cols-1 lg:grid-cols-3 gap-6 mb-6">
    <!-- Department Distribution Chart -->
    <div class="lg:col-span-2 bg-white dark:bg-gray-800 rounded-xl shadow-sm border border-gray-100 dark:border-gray-700">
        <div class="px-5 py-4 border-b border-gray-100 dark:border-gray-700">
            <h2 class="text-sm font-semibold text-gray-900 dark:text-white">Employees by Department</h2>
        </div>
        <div class="p-5">
            <canvas id="departmentChart" height="200"></canvas>
        </div>
    </div>

    <!-- Quick Stats -->
    <div class="bg-white dark:bg-gray-800 rounded-xl shadow-sm border border-gray-100 dark:border-gray-700">
        <div class="px-5 py-4 border-b border-gray-100 dark:border-gray-700">
            <h2 class="text-sm font-semibold text-gray-900 dark:text-white">Top Departments</h2>
        </div>
        <div class="p-5">
            <div class="space-y-3">
                <?php 
                $topDepts = array_slice($metrics['by_department'], 0, 5);
                foreach ($topDepts as $dept): 
                ?>
                    <div class="flex items-center justify-between">
                        <span class="text-sm text-gray-700 dark:text-gray-300"><?php echo htmlspecialchars($dept['name']); ?></span>
                        <span class="text-sm font-semibold text-gray-900 dark:text-white"><?php echo $dept['count']; ?></span>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
</div>

<!-- Employee Assignment Table -->
<div class="bg-white dark:bg-gray-800 rounded-xl shadow-sm border border-gray-100 dark:border-gray-700">
    <div class="px-5 py-4 border-b border-gray-100 dark:border-gray-700 flex items-center justify-between">
        <h2 class="text-sm font-semibold text-gray-900 dark:text-white">Employee Assignments</h2>
        <div class="flex gap-2">
            <input type="text" id="searchEmployees" placeholder="Search employee..." class="text-xs border border-gray-300 dark:border-gray-600 rounded px-3 py-1 dark:bg-gray-700 dark:text-white">
            <select id="filterDepartment" onchange="filterTable()" class="text-xs border border-gray-300 dark:border-gray-600 rounded px-2 py-1 dark:bg-gray-700 dark:text-white">
                <option value="">All Departments/Units</option>
                <?php if (!empty($departments)): ?>
                    <?php foreach ($departments as $dept): ?>
                        <option value="dept_<?php echo $dept['id']; ?>"><?php echo htmlspecialchars($dept['name']); ?></option>
                    <?php endforeach; ?>
                <?php endif; ?>
                <?php foreach ($units as $unit): ?>
                    <option value="unit_<?php echo $unit['id']; ?>"><?php echo htmlspecialchars($unit['name']); ?></option>
                <?php endforeach; ?>
            </select>
            <button onclick="exportEmployees()" class="px-3 py-1 text-xs border border-gray-300 dark:border-gray-600 rounded hover:bg-gray-50 dark:hover:bg-gray-700">
                Export
            </button>
        </div>
    </div>
    <div class="overflow-x-auto">
        <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700" id="employeesTable">
            <thead class="bg-gray-50 dark:bg-gray-700">
                <tr>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase">Employee Number</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase">Name</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase">Position</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase">Department</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase">Employment</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase">Status</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase">Actions</th>
                </tr>
            </thead>
            <tbody class="bg-white dark:bg-gray-800 divide-y divide-gray-200 dark:divide-gray-700">
                <?php if (empty($employees)): ?>
                    <tr>
                        <td colspan="7" class="px-6 py-4 text-center text-sm text-gray-500">No employees found</td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($employees as $emp): 
                        $assignedName = $emp['department_name'] ?? $emp['unit_name'] ?? null;
                        $assignedId = $emp['unit_id'] ?? null;
                    ?>
                        <tr data-dept="unit_<?php echo $emp['unit_id'] ?? ''; ?>" data-name="<?php echo strtolower(($emp['first_name'] ?? '') . ' ' . ($emp['last_name'] ?? '') . ' ' . ($emp['employee_number'] ?? '')); ?>">
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900 dark:text-white font-semibold">
                                <?php echo htmlspecialchars($emp['employee_number'] ?? ''); ?>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900 dark:text-white">
                                <?php echo htmlspecialchars(($emp['first_name'] ?? '') . ' ' . ($emp['last_name'] ?? '')); ?>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900 dark:text-white">
                                <?php echo htmlspecialchars($emp['position'] ?? 'N/A'); ?>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm">
                                <?php if ($assignedName): ?>
                                    <span class="px-2 py-1 text-xs bg-blue-100 text-blue-800 dark:bg-blue-900 dark:text-blue-200 rounded">
                                        <?php echo htmlspecialchars($assignedName); ?>
                                    </span>
                                <?php else: ?>
                                    <span class="px-2 py-1 text-xs bg-amber-100 text-amber-800 dark:bg-amber-900 dark:text-amber-200 rounded">
                                        Unassigned
                                    </span>
                                <?php endif; ?>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm">
                                <?php
                                $empType = $emp['employment_type'] ?? 'regular';
                                $empTypeClass = $empType === 'regular' ? 'bg-emerald-100 text-emerald-800 dark:bg-emerald-900 dark:text-emerald-200' :
                                    ($empType === 'contractual' ? 'bg-blue-100 text-blue-800 dark:bg-blue-900 dark:text-blue-200' :
                                    ($empType === 'probationary' ? 'bg-amber-100 text-amber-800 dark:bg-amber-900 dark:text-amber-200' :
                                    'bg-gray-100 text-gray-800 dark:bg-gray-700 dark:text-gray-200'));
                                ?>
                                <span class="px-2 py-1 text-xs font-semibold rounded-full <?php echo $empTypeClass; ?>">
                                    <?php echo ucfirst(str_replace('_', ' ', $empType)); ?>
                                </span>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm">
                                <span class="px-2 py-1 text-xs font-semibold rounded-full <?php 
                                    echo ($emp['status'] ?? '') === 'active' ? 'bg-emerald-100 text-emerald-800 dark:bg-emerald-900 dark:text-emerald-200' : 
                                        'bg-gray-100 text-gray-800 dark:bg-gray-700 dark:text-gray-200';
                                ?>">
                                    <?php echo ucfirst($emp['status'] ?? 'N/A'); ?>
                                </span>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm">
                                <a href="?view_employee=<?php echo (int)$emp['id']; ?>" title="View" class="inline-flex p-1.5 rounded-lg text-blue-600 hover:bg-blue-50 dark:hover:bg-blue-900/30 transition-colors">
                                    <svg xmlns="http://www.w3.org/2000/svg" class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" /><path stroke-linecap="round" stroke-linejoin="round" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z" /></svg>
                                </a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- View Employee Details Modal -->
<div id="viewEmployeeModal" class="hidden fixed inset-0 bg-black bg-opacity-50 z-50 flex items-center justify-center p-4 overflow-y-auto">
    <div class="bg-white dark:bg-gray-800 rounded-xl shadow-xl max-w-2xl w-full my-8 max-h-[90vh] overflow-y-auto">
        <div class="px-5 py-4 border-b border-gray-100 dark:border-gray-700 flex items-center justify-between sticky top-0 bg-white dark:bg-gray-800 z-10">
            <h3 class="text-lg font-semibold text-gray-900 dark:text-white">Employee Details</h3>
            <a href="<?php echo $_SERVER['PHP_SELF']; ?>" class="text-gray-500 hover:text-gray-700 dark:hover:text-gray-300 text-2xl leading-none">&times;</a>
        </div>
        <div class="p-5">
            <?php if ($viewEmployee): ?>
                <div class="space-y-5">
                    <div class="grid grid-cols-2 gap-4 text-sm">
                        <div><span class="text-gray-500 dark:text-gray-400">Employee Number</span><br><span class="font-medium text-gray-900 dark:text-white"><?php echo htmlspecialchars($viewEmployee['employee_number'] ?? '—'); ?></span></div>
                        <div><span class="text-gray-500 dark:text-gray-400">Name</span><br><span class="font-medium text-gray-900 dark:text-white"><?php echo htmlspecialchars(($viewEmployee['first_name'] ?? '') . ' ' . ($viewEmployee['last_name'] ?? '')); ?></span></div>
                        <div><span class="text-gray-500 dark:text-gray-400">Position</span><br><span class="font-medium text-gray-900 dark:text-white"><?php echo htmlspecialchars($viewEmployee['position'] ?? '—'); ?></span></div>
                        <div><span class="text-gray-500 dark:text-gray-400">Department / Unit</span><br><span class="font-medium text-gray-900 dark:text-white"><?php echo htmlspecialchars($viewEmployee['unit_name'] ?? 'Unassigned'); ?></span></div>
                        <div><span class="text-gray-500 dark:text-gray-400">Employment</span><br><span class="font-medium text-gray-900 dark:text-white"><?php echo ucfirst($viewEmployee['employment_type'] ?? '—'); ?></span></div>
                        <div><span class="text-gray-500 dark:text-gray-400">Status</span><br><span class="font-medium text-gray-900 dark:text-white"><?php echo ucfirst($viewEmployee['status'] ?? '—'); ?></span></div>
                        <div><span class="text-gray-500 dark:text-gray-400">Date Hired</span><br><span class="font-medium text-gray-900 dark:text-white"><?php echo $viewEmployee['hire_date'] ? date('F j, Y', strtotime($viewEmployee['hire_date'])) : '—'; ?></span></div>
                        <div><span class="text-gray-500 dark:text-gray-400">Email</span><br><span class="font-medium text-gray-900 dark:text-white"><?php echo htmlspecialchars($viewEmployee['email'] ?? '—'); ?></span></div>
                    </div>

                    <div>
                        <h4 class="text-sm font-semibold text-gray-900 dark:text-white mb-2">Recurring Shift Assignment</h4>
                        <?php if (empty($viewEmployeeShifts)): ?>
                            <p class="text-sm text-gray-500 dark:text-gray-400">No recurring shift assigned.</p>
                        <?php else: ?>
                            <div class="overflow-x-auto">
                                <table class="min-w-full text-sm">
                                    <thead class="bg-gray-50 dark:bg-gray-700"><tr><th class="px-3 py-2 text-left text-xs font-medium text-gray-500 dark:text-gray-300">Shift</th><th class="px-3 py-2 text-left text-xs font-medium text-gray-500 dark:text-gray-300">Time</th><th class="px-3 py-2 text-left text-xs font-medium text-gray-500 dark:text-gray-300">Valid From</th><th class="px-3 py-2 text-left text-xs font-medium text-gray-500 dark:text-gray-300">Valid To</th></tr></thead>
                                    <tbody class="divide-y divide-gray-200 dark:divide-gray-700">
                                        <?php foreach ($viewEmployeeShifts as $s): ?>
                                            <tr>
                                                <td class="px-3 py-2 text-gray-900 dark:text-white"><?php echo htmlspecialchars($s['shift_name'] . ($s['shift_code'] ? ' (' . $s['shift_code'] . ')' : '')); ?></td>
                                                <td class="px-3 py-2 text-gray-700 dark:text-gray-300"><?php echo date('g:i A', strtotime($s['start_time'])); ?> – <?php echo date('g:i A', strtotime($s['end_time'])); ?></td>
                                                <td class="px-3 py-2 text-gray-700 dark:text-gray-300"><?php echo date('M j, Y', strtotime($s['start_date'])); ?></td>
                                                <td class="px-3 py-2 text-gray-700 dark:text-gray-300"><?php echo $s['end_date'] ? date('M j, Y', strtotime($s['end_date'])) : 'Ongoing'; ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            <?php else: ?>
                <p class="text-sm text-gray-500">Employee not found.</p>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Assign Department Modal -->
<div id="assignModal" class="hidden fixed inset-0 bg-black bg-opacity-50 z-50 flex items-center justify-center">
    <div class="bg-white dark:bg-gray-800 rounded-xl shadow-xl max-w-md w-full mx-4">
        <div class="px-5 py-4 border-b border-gray-100 dark:border-gray-700">
            <h3 class="text-sm font-semibold text-gray-900 dark:text-white">Assign Department</h3>
        </div>
        <form method="POST" class="p-5">
            <input type="hidden" name="action" value="assign_department">
            <input type="hidden" name="employee_id" id="modal_employee_id">
            <div class="mb-4">
                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Employee</label>
                <input type="text" id="modal_employee_name" readonly class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg dark:bg-gray-700 dark:text-white bg-gray-50">
            </div>
            <div class="mb-4">
                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Unit / Department</label>
                <select name="department_id" id="modal_department_id" class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg dark:bg-gray-700 dark:text-white">
                    <option value="">Unassigned</option>
                    <?php foreach ($units as $unit): ?>
                        <option value="<?php echo $unit['id']; ?>"><?php echo htmlspecialchars($unit['name']); ?></option>
                    <?php endforeach; ?>
                    <?php if (!empty($departments)): ?>
                        <optgroup label="Departments">
                        <?php foreach ($departments as $dept): ?>
                            <option value="<?php echo $dept['id']; ?>"><?php echo htmlspecialchars($dept['name']); ?></option>
                        <?php endforeach; ?>
                        </optgroup>
                    <?php endif; ?>
                </select>
            </div>
            <div class="flex gap-2">
                <button type="submit" class="flex-1 px-4 py-2 bg-primary-600 text-white rounded-lg hover:bg-primary-700">
                    Save Assignment
                </button>
                <button type="button" onclick="closeAssignModal()" class="px-4 py-2 border border-gray-300 dark:border-gray-600 rounded-lg hover:bg-gray-50 dark:hover:bg-gray-700">
                    Cancel
                </button>
            </div>
        </form>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
// Department Chart
const deptCtx = document.getElementById('departmentChart');
if (deptCtx) {
    const deptData = <?php echo json_encode($metrics['by_department']); ?>;
    new Chart(deptCtx, {
        type: 'bar',
        data: {
            labels: deptData.map(d => d.name),
            datasets: [{
                label: 'Employees',
                data: deptData.map(d => d.count),
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

function filterTable() {
    const filter = document.getElementById('filterDepartment').value;
    const rows = document.querySelectorAll('#employeesTable tbody tr');
    rows.forEach(row => {
        const dept = row.getAttribute('data-dept') || '';
        if (!filter || dept === filter) {
            row.style.display = '';
        } else {
            row.style.display = 'none';
        }
    });
}


document.getElementById('searchEmployees')?.addEventListener('input', function(e) {
    const search = e.target.value.toLowerCase();
    const rows = document.querySelectorAll('#employeesTable tbody tr');
    rows.forEach(row => {
        const name = row.getAttribute('data-name') || '';
        if (name.includes(search)) {
            row.style.display = '';
        } else {
            row.style.display = 'none';
        }
    });
});

function openAssignModal(empId, empName, deptId) {
    document.getElementById('modal_employee_id').value = empId;
    document.getElementById('modal_employee_name').value = empName;
    document.getElementById('modal_department_id').value = deptId || '';
    document.getElementById('assignModal').classList.remove('hidden');
}

function closeAssignModal() {
    document.getElementById('assignModal').classList.add('hidden');
    if (window.location.search.indexOf('view_employee=') !== -1) {
        document.getElementById('viewEmployeeModal').classList.remove('hidden');
    }
}

<?php if ($viewEmployee): ?>
document.addEventListener('DOMContentLoaded', function() {
    document.getElementById('viewEmployeeModal').classList.remove('hidden');
});
<?php endif; ?>

function exportEmployees() {
    const url = '<?php echo rtrim(BASE_URL, "/"); ?>/api/reports/export.php?type=employees&format=csv';
    window.location.href = url;
}
</script>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
