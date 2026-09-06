<?php
require_once __DIR__ . '/../../config/config.php';
requireAuth();
checkRole(['admin', 'super admin', 'supervisor', 'unit_head', 'hr_admin']);

$page_title = 'Leave Management';
$view = $_GET['view'] ?? 'leave_types_policies';
$queueStatus = $_GET['queue_status'] ?? '';

$leaveTypes = [];
$leaveBalances = [];
$approvalQueue = [];
$metrics = [
    'pending_requests' => 0,
    'approved_today' => 0,
    'on_leave_today' => 0,
    'total_leave_days' => 0
];

if (isset($db)) {
    try {
        // Handle form submissions
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $action = $_POST['action'] ?? '';
            
            if ($action === 'create_leave_type') {
                $code = trim($_POST['code'] ?? '');
                $name = trim($_POST['name'] ?? '');
                $is_paid = isset($_POST['is_paid']) ? 1 : 0;
                $max_days = (float)($_POST['max_days_per_year'] ?? 0.00);
                
                if ($code && $name) {
                    $stmt = $db->prepare("
                        INSERT INTO leave_types (code, name, is_paid, max_days_per_year, created_at, updated_at)
                        VALUES (:code, :name, :is_paid, :max_days, NOW(), NOW())
                    ");
                    $stmt->execute([
                        ':code' => $code,
                        ':name' => $name,
                        ':is_paid' => $is_paid,
                        ':max_days' => $max_days
                    ]);
                    $_SESSION['success'] = 'Leave type created successfully';
                    header('Location: ' . $_SERVER['PHP_SELF'] . '?view=leave_types_policies');
                    exit;
                } else {
                    $_SESSION['error'] = 'Please fill in all required fields';
                }
            } elseif ($action === 'update_leave_type') {
                $id = (int)($_POST['id'] ?? 0);
                $name = trim($_POST['name'] ?? '');
                $is_paid = isset($_POST['is_paid']) ? 1 : 0;
                $max_days = (float)($_POST['max_days_per_year'] ?? 0.00);
                if ($id && $name !== '') {
                    $stmt = $db->prepare("
                        UPDATE leave_types SET name = :name, is_paid = :is_paid, max_days_per_year = :max_days, updated_at = NOW()
                        WHERE id = :id
                    ");
                    $stmt->execute([
                        ':name' => $name,
                        ':is_paid' => $is_paid,
                        ':max_days' => $max_days,
                        ':id' => $id
                    ]);
                    $_SESSION['success'] = 'Leave type updated successfully';
                    header('Location: ' . $_SERVER['PHP_SELF'] . '?view=leave_types_policies');
                    exit;
                } else {
                    $_SESSION['error'] = 'Invalid data for update';
                }
            } elseif ($action === 'delete_leave_type') {
                $id = (int)($_POST['id'] ?? 0);
                if ($id) {
                    $stmt = $db->prepare("DELETE FROM leave_types WHERE id = :id");
                    $stmt->execute([':id' => $id]);
                    $_SESSION['success'] = 'Leave type deleted successfully';
                    header('Location: ' . $_SERVER['PHP_SELF'] . '?view=leave_types_policies');
                    exit;
                }
            }
        }
        
        // Get pending leave requests count
        $stmt = $db->prepare("SELECT COUNT(*) as cnt FROM leave_requests WHERE status = 'pending'");
        $stmt->execute();
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        $metrics['pending_requests'] = (int)($result['cnt'] ?? 0);

        // Approved today
        $stmt = $db->query("SELECT COUNT(*) as cnt FROM leave_requests WHERE status = 'approved' AND DATE(approved_at) = CURDATE()");
        $metrics['approved_today'] = (int)($stmt->fetch(PDO::FETCH_ASSOC)['cnt'] ?? 0);

        // On leave today
        $stmt = $db->query("SELECT COUNT(*) as cnt FROM leave_requests WHERE status = 'approved' AND CURDATE() BETWEEN start_date AND end_date");
        $metrics['on_leave_today'] = (int)($stmt->fetch(PDO::FETCH_ASSOC)['cnt'] ?? 0);

        // Total leave days this month
        $stmt = $db->query("SELECT COALESCE(SUM(total_days), 0) as total FROM leave_requests WHERE status IN ('approved','pending') AND MONTH(start_date) = MONTH(CURDATE()) AND YEAR(start_date) = YEAR(CURDATE())");
        $metrics['total_leave_days'] = (float)($stmt->fetch(PDO::FETCH_ASSOC)['total'] ?? 0);
        
        // Get leave types
        if ($view === 'leave_types_policies') {
            $stmt = $db->prepare("SELECT * FROM leave_types ORDER BY name");
            $stmt->execute();
            $leaveTypes = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }
        
        // Get leave balances
        if ($view === 'leave_balances') {
            $stmt = $db->prepare("
                SELECT lb.*, e.first_name, e.last_name, e.employee_number, lt.name as leave_type_name
                FROM leave_balances lb
                JOIN employees e ON lb.employee_id = e.id
                JOIN leave_types lt ON lb.leave_type = lt.code
                WHERE lb.year = YEAR(CURDATE())
                ORDER BY e.last_name, lb.leave_type
                LIMIT 100
            ");
            $stmt->execute();
            $leaveBalances = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }
        
        // Get approval queue; optional filter: approved, on_leave_today, or default pending/endorsed
        if ($view === 'leave_requests_queue') {
            if ($queueStatus === 'on_leave_today') {
                $stmt = $db->prepare("
                    SELECT lr.*, e.first_name, e.last_name, e.employee_number, lt.name as leave_type_name
                    FROM leave_requests lr
                    JOIN employees e ON lr.employee_id = e.id
                    LEFT JOIN leave_types lt ON lr.leave_type = lt.code
                    WHERE lr.status = 'approved' AND CURDATE() BETWEEN lr.start_date AND lr.end_date
                    ORDER BY lr.start_date DESC
                    LIMIT 50
                ");
            } elseif ($queueStatus === 'approved') {
                $stmt = $db->prepare("
                    SELECT lr.*, e.first_name, e.last_name, e.employee_number, lt.name as leave_type_name
                    FROM leave_requests lr
                    JOIN employees e ON lr.employee_id = e.id
                    LEFT JOIN leave_types lt ON lr.leave_type = lt.code
                    WHERE lr.status = 'approved'
                    ORDER BY lr.approved_at DESC, lr.start_date DESC
                    LIMIT 50
                ");
            } else {
                $stmt = $db->prepare("
                    SELECT lr.*, e.first_name, e.last_name, e.employee_number, lt.name as leave_type_name
                    FROM leave_requests lr
                    JOIN employees e ON lr.employee_id = e.id
                    LEFT JOIN leave_types lt ON lr.leave_type = lt.code
                    WHERE lr.status IN ('pending', 'endorsed')
                    ORDER BY lr.submitted_at DESC
                    LIMIT 50
                ");
            }
            $stmt->execute();
            $approvalQueue = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }
    } catch (PDOException $e) {
        error_log('Admin Leave Management error: ' . $e->getMessage());
    }
}

include __DIR__ . '/../../includes/header.php';
?>

<div class="mb-6">
    <h1 class="text-2xl font-semibold text-gray-900 dark:text-white">Leave Management</h1>
    <p class="mt-1 text-sm text-gray-600 dark:text-gray-400">Policy settings, leave balances, approval queue, and department calendar</p>
</div>

<!-- Metrics Cards -->
<div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4 mb-6">
    <div class="bg-white dark:bg-gray-800 rounded-xl shadow-sm border border-gray-100 dark:border-gray-700 p-5">
        <div class="flex items-center justify-between">
            <div>
                <p class="text-xs font-medium text-gray-500 dark:text-gray-400 uppercase">Pending Requests</p>
                <p class="mt-2 text-2xl font-bold text-gray-900 dark:text-white"><?php echo $metrics['pending_requests']; ?></p>
                <p class="mt-1 text-xs text-amber-600">awaiting approval</p>
            </div>
            <div class="w-12 h-12 rounded-lg bg-amber-100 dark:bg-amber-900 flex items-center justify-center">
                <svg xmlns="http://www.w3.org/2000/svg" class="w-6 h-6 text-amber-600 dark:text-amber-300" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
                </svg>
            </div>
        </div>
    </div>

    <div class="bg-white dark:bg-gray-800 rounded-xl shadow-sm border border-gray-100 dark:border-gray-700 p-5">
        <div class="flex items-center justify-between">
            <div>
                <p class="text-xs font-medium text-gray-500 dark:text-gray-400 uppercase">On Leave Today</p>
                <p class="mt-2 text-2xl font-bold text-gray-900 dark:text-white"><?php echo $metrics['on_leave_today']; ?></p>
                <p class="mt-1 text-xs text-purple-600">employees</p>
            </div>
            <div class="w-12 h-12 rounded-lg bg-purple-100 dark:bg-purple-900 flex items-center justify-center">
                <svg xmlns="http://www.w3.org/2000/svg" class="w-6 h-6 text-purple-600 dark:text-purple-300" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z" />
                </svg>
            </div>
        </div>
    </div>

    <div class="bg-white dark:bg-gray-800 rounded-xl shadow-sm border border-gray-100 dark:border-gray-700 p-5">
        <div class="flex items-center justify-between">
            <div>
                <p class="text-xs font-medium text-gray-500 dark:text-gray-400 uppercase">Approved Today</p>
                <p class="mt-2 text-2xl font-bold text-gray-900 dark:text-white"><?php echo $metrics['approved_today']; ?></p>
                <p class="mt-1 text-xs text-emerald-600">requests</p>
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
                <p class="text-xs font-medium text-gray-500 dark:text-gray-400 uppercase">Total Leave Days</p>
                <p class="mt-2 text-2xl font-bold text-gray-900 dark:text-white"><?php echo number_format($metrics['total_leave_days'], 0); ?></p>
                <p class="mt-1 text-xs text-gray-500">this month</p>
            </div>
            <div class="w-12 h-12 rounded-lg bg-blue-100 dark:bg-blue-900 flex items-center justify-center">
                <svg xmlns="http://www.w3.org/2000/svg" class="w-6 h-6 text-blue-600 dark:text-blue-300" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z" />
                </svg>
            </div>
        </div>
    </div>
</div>

<?php if ($view === 'leave_types_policies'): ?>
    <!-- Leave Types & Policies -->
    <div class="bg-white dark:bg-gray-800 rounded-xl shadow-sm border border-gray-100 dark:border-gray-700 mb-6">
        <div class="px-5 py-4 border-b border-gray-100 dark:border-gray-700 flex items-center justify-between">
            <h2 class="text-sm font-semibold text-gray-900 dark:text-white">Leave Types & Policies</h2>
            <button onclick="openLeaveTypeModal()" class="px-4 py-2 text-xs bg-primary-600 text-white rounded hover:bg-primary-700">
                Add Leave Type
            </button>
        </div>
        <?php if (isset($_SESSION['success'])): ?>
            <div class="mx-5 mt-4 bg-emerald-50 dark:bg-emerald-900/20 border border-emerald-200 dark:border-emerald-800 text-emerald-800 dark:text-emerald-200 px-4 py-3 rounded-lg">
                <?php echo htmlspecialchars($_SESSION['success']); unset($_SESSION['success']); ?>
            </div>
        <?php endif; ?>
        <?php if (isset($_SESSION['error'])): ?>
            <div class="mx-5 mt-4 bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-800 text-red-800 dark:text-red-200 px-4 py-3 rounded-lg">
                <?php echo htmlspecialchars($_SESSION['error']); unset($_SESSION['error']); ?>
            </div>
        <?php endif; ?>
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                <thead class="bg-gray-50 dark:bg-gray-700">
                    <tr>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase">Code</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase">Name</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase">Paid</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase">Max Days/Year</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase">Actions</th>
                    </tr>
                </thead>
                <tbody class="bg-white dark:bg-gray-800 divide-y divide-gray-200 dark:divide-gray-700">
                    <?php if (empty($leaveTypes)): ?>
                        <tr>
                            <td colspan="5" class="px-6 py-4 text-center text-sm text-gray-500">No leave types found</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($leaveTypes as $type): ?>
                            <tr>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900 dark:text-white font-semibold">
                                    <?php echo htmlspecialchars($type['code']); ?>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900 dark:text-white">
                                    <?php echo htmlspecialchars($type['name']); ?>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm">
                                    <?php if ($type['is_paid']): ?>
                                        <span class="px-2 py-1 text-xs bg-emerald-100 text-emerald-800 dark:bg-emerald-900 dark:text-emerald-200 rounded">Yes</span>
                                    <?php else: ?>
                                        <span class="px-2 py-1 text-xs bg-gray-100 text-gray-800 dark:bg-gray-700 dark:text-gray-200 rounded">No</span>
                                    <?php endif; ?>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900 dark:text-white">
                                    <?php echo number_format($type['max_days_per_year'], 1); ?>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm">
                                    <div class="inline-flex items-center gap-1">
                                        <button onclick="openEditLeaveTypeModal(<?php echo $type['id']; ?>)" title="Edit" class="p-1.5 rounded-lg text-blue-600 hover:bg-blue-50 dark:hover:bg-blue-900/30 transition-colors">
                                            <svg xmlns="http://www.w3.org/2000/svg" class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z" /></svg>
                                        </button>
                                        <form method="POST" class="inline" onsubmit="return confirm('Delete this leave type?');">
                                            <input type="hidden" name="action" value="delete_leave_type">
                                            <input type="hidden" name="id" value="<?php echo $type['id']; ?>">
                                            <button type="submit" title="Delete" class="p-1.5 rounded-lg text-red-600 hover:bg-red-50 dark:hover:bg-red-900/30 transition-colors">
                                                <svg xmlns="http://www.w3.org/2000/svg" class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" /></svg>
                                            </button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
    
    <!-- Add/Edit Leave Type Modal -->
    <div id="leaveTypeModal" class="hidden fixed inset-0 bg-black bg-opacity-50 z-50 flex items-center justify-center p-4" onclick="if(event.target===this) closeLeaveTypeModal()">
        <div class="bg-white dark:bg-gray-800 rounded-xl shadow-xl max-w-md w-full mx-4" onclick="event.stopPropagation()">
            <div class="px-5 py-4 border-b border-gray-100 dark:border-gray-700 flex items-center justify-between">
                <h3 id="leaveTypeModalTitle" class="text-sm font-semibold text-gray-900 dark:text-white">Add Leave Type</h3>
                <button type="button" onclick="closeLeaveTypeModal()" class="text-gray-400 hover:text-gray-600 dark:hover:text-gray-300">&times;</button>
            </div>
            <form method="POST" id="leaveTypeForm" class="p-5">
                <input type="hidden" name="action" id="leaveTypeFormAction" value="create_leave_type">
                <input type="hidden" name="id" id="leaveTypeFormId" value="">
                <div class="space-y-4">
                    <div id="leaveTypeCodeWrap">
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Code <span class="text-red-500">*</span></label>
                        <input type="text" name="code" id="leaveTypeCode" maxlength="50" class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg dark:bg-gray-700 dark:text-white" placeholder="e.g., VL, SL, EL">
                        <p class="mt-1 text-xs text-gray-500">Unique code for this leave type</p>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Name <span class="text-red-500">*</span></label>
                        <input type="text" name="name" id="leaveTypeName" required maxlength="150" class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg dark:bg-gray-700 dark:text-white" placeholder="e.g., Vacation Leave, Sick Leave">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Max Days Per Year</label>
                        <input type="number" name="max_days_per_year" id="leaveTypeMaxDays" step="0.5" value="0" min="0" class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg dark:bg-gray-700 dark:text-white" placeholder="0">
                        <p class="mt-1 text-xs text-gray-500">Set to 0 for unlimited</p>
                    </div>
                    <div>
                        <label class="flex items-center">
                            <input type="checkbox" name="is_paid" id="leaveTypeIsPaid" value="1" checked class="rounded border-gray-300 text-primary-600">
                            <span class="ml-2 text-sm text-gray-700 dark:text-gray-300">Paid Leave</span>
                        </label>
                        <p class="mt-1 text-xs text-gray-500">If checked, this leave type is paid</p>
                    </div>
                </div>
                <div class="flex gap-2 mt-6">
                    <button type="submit" id="leaveTypeSubmitBtn" class="flex-1 px-4 py-2 bg-primary-600 text-white rounded-lg hover:bg-primary-700">
                        Create Leave Type
                    </button>
                    <button type="button" onclick="closeLeaveTypeModal()" class="px-4 py-2 border border-gray-300 dark:border-gray-600 rounded-lg hover:bg-gray-50 dark:hover:bg-gray-700 text-gray-700 dark:text-gray-300">
                        Cancel
                    </button>
                </div>
            </form>
        </div>
    </div>
    
    <script>
    var leaveTypesData = <?php echo json_encode($leaveTypes); ?>;
    var leaveTypeModalEl = document.getElementById('leaveTypeModal');
    var leaveTypeForm = document.getElementById('leaveTypeForm');
    var leaveTypeFormAction = document.getElementById('leaveTypeFormAction');
    var leaveTypeFormId = document.getElementById('leaveTypeFormId');
    var leaveTypeCode = document.getElementById('leaveTypeCode');
    var leaveTypeCodeWrap = document.getElementById('leaveTypeCodeWrap');
    var leaveTypeName = document.getElementById('leaveTypeName');
    var leaveTypeMaxDays = document.getElementById('leaveTypeMaxDays');
    var leaveTypeIsPaid = document.getElementById('leaveTypeIsPaid');
    var leaveTypeModalTitle = document.getElementById('leaveTypeModalTitle');
    var leaveTypeSubmitBtn = document.getElementById('leaveTypeSubmitBtn');

    function openLeaveTypeModal() {
        leaveTypeFormAction.value = 'create_leave_type';
        leaveTypeFormId.value = '';
        leaveTypeCode.value = '';
        leaveTypeCode.removeAttribute('readonly');
        leaveTypeCode.removeAttribute('disabled');
        leaveTypeCode.required = true;
        leaveTypeCodeWrap.style.display = '';
        leaveTypeName.value = '';
        leaveTypeMaxDays.value = '0';
        leaveTypeIsPaid.checked = true;
        leaveTypeModalTitle.textContent = 'Add Leave Type';
        leaveTypeSubmitBtn.textContent = 'Create Leave Type';
        leaveTypeModalEl.classList.remove('hidden');
    }

    function closeLeaveTypeModal() {
        leaveTypeModalEl.classList.add('hidden');
    }

    function openEditLeaveTypeModal(id) {
        var type = leaveTypesData.find(function(t) { return parseInt(t.id, 10) === parseInt(id, 10); });
        if (!type) return;
        leaveTypeFormAction.value = 'update_leave_type';
        leaveTypeFormId.value = type.id;
        leaveTypeCode.value = type.code || '';
        leaveTypeCode.setAttribute('readonly', 'readonly');
        leaveTypeCode.setAttribute('disabled', 'disabled');
        leaveTypeCode.required = false;
        leaveTypeCodeWrap.style.display = '';
        leaveTypeName.value = type.name || '';
        leaveTypeMaxDays.value = type.max_days_per_year != null ? parseFloat(type.max_days_per_year) : 0;
        leaveTypeIsPaid.checked = type.is_paid == 1 || type.is_paid === '1';
        leaveTypeModalTitle.textContent = 'Edit Leave Type';
        leaveTypeSubmitBtn.textContent = 'Update Leave Type';
        leaveTypeModalEl.classList.remove('hidden');
    }

    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') closeLeaveTypeModal();
    });
    </script>

<?php elseif ($view === 'leave_balances'): ?>
    <!-- Leave Balances -->
    <div class="bg-white dark:bg-gray-800 rounded-xl shadow-sm border border-gray-100 dark:border-gray-700">
        <div class="px-5 py-4 border-b border-gray-100 dark:border-gray-700 flex items-center justify-between">
            <h2 class="text-sm font-semibold text-gray-900 dark:text-white">Leave Balances</h2>
            <div class="flex gap-2">
                <input type="text" id="searchBalances" placeholder="Search employee..." class="text-xs border border-gray-300 dark:border-gray-600 rounded px-3 py-1 dark:bg-gray-700 dark:text-white">
                <button onclick="exportBalances()" class="px-3 py-1 text-xs border border-gray-300 dark:border-gray-600 rounded hover:bg-gray-50 dark:hover:bg-gray-700">
                    Export
                </button>
            </div>
        </div>
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700" id="leaveBalancesTable">
                <thead class="bg-gray-50 dark:bg-gray-700">
                    <tr>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase">Employee</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase">Leave Type</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase">Entitlement</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase">Used</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase">Pending</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase">Available</th>
                    </tr>
                </thead>
                <tbody class="bg-white dark:bg-gray-800 divide-y divide-gray-200 dark:divide-gray-700">
                    <?php if (empty($leaveBalances)): ?>
                        <tr>
                            <td colspan="6" class="px-6 py-4 text-center text-sm text-gray-500">No leave balances found</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($leaveBalances as $balance): 
                            $available = $balance['entitlement'] - $balance['used'] - $balance['pending'];
                            $searchData = strtolower(($balance['employee_number'] ?? '') . ' ' . ($balance['first_name'] ?? '') . ' ' . ($balance['last_name'] ?? '') . ' ' . ($balance['leave_type_name'] ?? ''));
                        ?>
                            <tr data-search="<?php echo htmlspecialchars($searchData); ?>">
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900 dark:text-white">
                                    <?php echo htmlspecialchars($balance['employee_number'] . ' - ' . $balance['first_name'] . ' ' . $balance['last_name']); ?>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900 dark:text-white">
                                    <?php echo htmlspecialchars($balance['leave_type_name']); ?>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900 dark:text-white">
                                    <?php echo number_format($balance['entitlement'], 1); ?>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900 dark:text-white">
                                    <?php echo number_format($balance['used'], 1); ?>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-amber-600">
                                    <?php echo number_format($balance['pending'], 1); ?>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm font-semibold text-emerald-600">
                                    <?php echo number_format($available, 1); ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <script>
    function exportBalances() {
        const url = '<?php echo rtrim(BASE_URL, "/"); ?>/api/reports/export.php?type=leave_balances&format=csv';
        window.location.href = url;
    }
    
    document.getElementById('searchBalances')?.addEventListener('input', function(e) {
        const search = e.target.value.toLowerCase().trim();
        const rows = document.querySelectorAll('#leaveBalancesTable tbody tr');
        rows.forEach(row => {
            const data = row.getAttribute('data-search') || row.textContent.toLowerCase();
            row.style.display = !search || data.includes(search) ? '' : 'none';
        });
    });
    </script>

<?php elseif ($view === 'leave_requests_queue'): ?>
    <!-- Toast notification -->
    <div id="toast" class="hidden fixed top-6 right-6 z-50 max-w-sm w-full">
        <div id="toastInner" class="flex items-center gap-3 px-4 py-3 rounded-lg shadow-lg border text-sm">
            <span id="toastIcon" class="flex-shrink-0"></span>
            <span id="toastMsg" class="flex-1"></span>
            <button onclick="hideToast()" class="text-gray-400 hover:text-gray-600">&times;</button>
        </div>
    </div>

    <!-- Reject modal -->
    <div id="rejectModal" class="hidden fixed inset-0 bg-black bg-opacity-50 z-50 flex items-center justify-center p-4" onclick="if(event.target===this) closeRejectModal()">
        <div class="bg-white dark:bg-gray-800 rounded-xl shadow-xl max-w-md w-full" onclick="event.stopPropagation()">
            <div class="px-5 py-4 border-b border-gray-100 dark:border-gray-700 flex items-center justify-between">
                <h3 class="text-sm font-semibold text-gray-900 dark:text-white">Reject Leave Request</h3>
                <button onclick="closeRejectModal()" class="text-gray-400 hover:text-gray-600 dark:hover:text-gray-300 text-lg">&times;</button>
            </div>
            <div class="p-5">
                <p class="text-xs text-gray-500 dark:text-gray-400 mb-3">This reason will be visible to the employee.</p>
                <textarea id="rejectReason" rows="3" class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg dark:bg-gray-700 dark:text-white text-sm" placeholder="Enter rejection reason (at least 10 characters)..."></textarea>
                <p id="rejectError" class="hidden mt-1 text-xs text-red-500"></p>
                <div class="flex gap-2 mt-4">
                    <button onclick="submitReject()" class="flex-1 px-4 py-2 bg-red-600 text-white rounded-lg hover:bg-red-700 text-sm font-medium">Reject</button>
                    <button onclick="closeRejectModal()" class="px-4 py-2 border border-gray-300 dark:border-gray-600 rounded-lg hover:bg-gray-50 dark:hover:bg-gray-700 text-sm text-gray-700 dark:text-gray-300">Cancel</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Leave Requests Queue -->
    <div class="bg-white dark:bg-gray-800 rounded-xl shadow-sm border border-gray-100 dark:border-gray-700">
        <div class="px-5 py-4 border-b border-gray-100 dark:border-gray-700 flex items-center justify-between">
            <h2 class="text-sm font-semibold text-gray-900 dark:text-white">Leave Requests Queue</h2>
            <div class="flex items-center gap-2">
                <form method="get" class="inline" id="queueFilterForm">
                    <input type="hidden" name="view" value="leave_requests_queue">
                    <select name="queue_status" onchange="this.form.submit()" class="text-xs border border-gray-300 dark:border-gray-600 rounded px-2 py-1 dark:bg-gray-700 dark:text-white">
                        <option value=""<?php echo $queueStatus === '' ? ' selected' : ''; ?>>Pending / Endorsed (queue)</option>
                        <option value="pending"<?php echo $queueStatus === 'pending' ? ' selected' : ''; ?>>Pending</option>
                        <option value="endorsed"<?php echo $queueStatus === 'endorsed' ? ' selected' : ''; ?>>Endorsed</option>
                        <option value="approved"<?php echo $queueStatus === 'approved' ? ' selected' : ''; ?>>Approved</option>
                        <option value="on_leave_today"<?php echo $queueStatus === 'on_leave_today' ? ' selected' : ''; ?>>On Leave Today</option>
                    </select>
                </form>
                <button onclick="bulkApprove()" title="Bulk Approve Selected" class="inline-flex items-center gap-1.5 px-3 py-1.5 text-xs bg-emerald-600 text-white rounded-lg hover:bg-emerald-700 transition-colors">
                    <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" /></svg>
                    <span class="hidden sm:inline">Bulk Approve</span>
                </button>
            </div>
        </div>
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700" id="leaveQueueTable">
                <thead class="bg-gray-50 dark:bg-gray-700">
                    <tr>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase">
                            <input type="checkbox" class="rounded border-gray-300" onchange="toggleAll(this)">
                        </th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase">Employee</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase">Leave Type</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase">Date Range</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase">Days</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase">Status</th>
                        <th class="px-6 py-3 text-center text-xs font-medium text-gray-500 dark:text-gray-300 uppercase">Actions</th>
                    </tr>
                </thead>
                <tbody class="bg-white dark:bg-gray-800 divide-y divide-gray-200 dark:divide-gray-700">
                    <?php if (empty($approvalQueue)): ?>
                        <tr>
                            <td colspan="7" class="px-6 py-8 text-center text-sm text-gray-500">
                                <svg xmlns="http://www.w3.org/2000/svg" class="w-10 h-10 mx-auto text-gray-300 dark:text-gray-600 mb-2" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" /></svg>
                                No pending leave requests
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($approvalQueue as $request): ?>
                            <tr data-id="<?php echo $request['id']; ?>" data-status="<?php echo htmlspecialchars($request['status']); ?>">
                                <td class="px-6 py-4">
                                    <input type="checkbox" class="row-checkbox rounded border-gray-300" value="<?php echo $request['id']; ?>">
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900 dark:text-white">
                                    <?php echo htmlspecialchars($request['employee_number'] . ' - ' . $request['first_name'] . ' ' . $request['last_name']); ?>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900 dark:text-white">
                                    <?php echo htmlspecialchars($request['leave_type_name']); ?>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900 dark:text-white">
                                    <?php echo date('M d', strtotime($request['start_date'])); ?> - <?php echo date('M d, Y', strtotime($request['end_date'])); ?>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900 dark:text-white">
                                    <?php echo number_format($request['total_days'], 1); ?>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm">
                                    <span class="px-2 py-1 text-xs font-semibold rounded-full bg-amber-100 text-amber-800 dark:bg-amber-900 dark:text-amber-200">
                                        <?php echo strtoupper($request['status']); ?>
                                    </span>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-center">
                                    <div class="inline-flex items-center gap-1">
                                        <button type="button" onclick="approveLeave(<?php echo (int)$request['id']; ?>, this)" title="Approve" class="p-1.5 rounded-lg text-emerald-600 hover:bg-emerald-50 dark:hover:bg-emerald-900/30 transition-colors">
                                            <svg xmlns="http://www.w3.org/2000/svg" class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7" /></svg>
                                        </button>
                                        <button type="button" onclick="openRejectModal(<?php echo (int)$request['id']; ?>)" title="Reject" class="p-1.5 rounded-lg text-red-600 hover:bg-red-50 dark:hover:bg-red-900/30 transition-colors">
                                            <svg xmlns="http://www.w3.org/2000/svg" class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" /></svg>
                                        </button>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <script>
    (function() {
        var origin = typeof window !== 'undefined' && window.location && window.location.origin ? window.location.origin : '';
        var pathname = (window.location && window.location.pathname) || '';
        var basePath = (pathname.indexOf('/admin') !== -1) ? pathname.substring(0, pathname.indexOf('/admin')) : '';
        var base = origin + basePath;
        var rejectTargetId = null;
        var toastTimer = null;

        function showToast(msg, type) {
            var toast = document.getElementById('toast');
            var inner = document.getElementById('toastInner');
            var icon = document.getElementById('toastIcon');
            var msgEl = document.getElementById('toastMsg');
            if (!msgEl) {
                try { alert(msg); } catch (e) {}
                return;
            }
            clearTimeout(toastTimer);
            msgEl.textContent = msg;
            if (inner) {
                inner.className = 'flex items-center gap-3 px-4 py-3 rounded-lg shadow-lg border text-sm ';
                if (type === 'success') {
                    inner.className += 'bg-emerald-50 dark:bg-emerald-900/40 border-emerald-200 dark:border-emerald-800 text-emerald-800 dark:text-emerald-200';
                    if (icon) icon.innerHTML = '<svg xmlns="http://www.w3.org/2000/svg" class="w-5 h-5 text-emerald-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7" /></svg>';
                } else {
                    inner.className += 'bg-red-50 dark:bg-red-900/40 border-red-200 dark:border-red-800 text-red-800 dark:text-red-200';
                    if (icon) icon.innerHTML = '<svg xmlns="http://www.w3.org/2000/svg" class="w-5 h-5 text-red-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" /></svg>';
                }
            }
            if (toast) {
                toast.classList.remove('hidden');
                toastTimer = setTimeout(function() { toast.classList.add('hidden'); }, 4000);
            }
        }
        window.hideToast = function() {
            var t = document.getElementById('toast');
            if (t) t.classList.add('hidden');
        };

        function removeRow(id) {
            var row = document.querySelector('tr[data-id="' + id + '"]');
            if (row) {
                row.style.transition = 'opacity 0.3s';
                row.style.opacity = '0';
                setTimeout(function() { row.remove(); }, 300);
            }
        }

        function updateRowStatus(id, status, message) {
            var row = document.querySelector('tr[data-id="' + id + '"]');
            if (!row) return;
            row.setAttribute('data-status', status);
            var statusCell = row.querySelector('td:nth-child(6)');
            var actionsCell = row.querySelector('td:nth-child(7)');
            if (statusCell) {
                statusCell.innerHTML = '<span class="px-2 py-1 text-xs font-semibold rounded-full ' +
                    (status === 'approved' ? 'bg-emerald-100 text-emerald-800 dark:bg-emerald-900 dark:text-emerald-200"' : 'bg-red-100 text-red-800 dark:bg-red-900 dark:text-red-200"') +
                    '>' + (status === 'approved' ? 'APPROVED' : 'REJECTED') + '</span>';
            }
            if (actionsCell) {
                actionsCell.innerHTML = '<span class="text-xs text-gray-500">' + (message || status) + '</span>';
            }
            var cb = row.querySelector('.row-checkbox');
            if (cb) cb.disabled = true;
        }

        window.toggleAll = function(checkbox) {
            document.querySelectorAll('.row-checkbox').forEach(function(cb) { cb.checked = checkbox.checked; });
        };

        window.approveLeave = function(requestId, btnEl) {
            if (btnEl) { btnEl.disabled = true; btnEl.style.opacity = '0.5'; }
            var url = base + '/api/leave/approve.php';
            fetch(url, {
                method: 'POST',
                headers: {'Content-Type': 'application/json'},
                body: JSON.stringify({request_id: requestId}),
                credentials: 'same-origin'
            })
            .then(function(r) {
                return r.text().then(function(text) {
                    try { return { ok: r.ok, data: JSON.parse(text) }; }
                    catch (e) { return { ok: r.ok, data: { success: false, message: text || 'Server error' } }; }
                });
            })
            .then(function(result) {
                var data = result.data;
                if (data && data.success) {
                    showToast(data.message || 'Leave request approved', 'success');
                    updateRowStatus(requestId, 'approved', data.message);
                    setTimeout(function() { removeRow(requestId); }, 1500);
                } else {
                    showToast((data && data.message) || 'Error approving leave', 'error');
                    if (btnEl) { btnEl.disabled = false; btnEl.style.opacity = '1'; }
                }
            })
            .catch(function(err) {
                showToast('Network error. Please try again.', 'error');
                if (btnEl) { btnEl.disabled = false; btnEl.style.opacity = '1'; }
            });
        };

        window.openRejectModal = function(id) {
            rejectTargetId = id;
            var reasonEl = document.getElementById('rejectReason');
            var errEl = document.getElementById('rejectError');
            var modal = document.getElementById('rejectModal');
            if (reasonEl) reasonEl.value = '';
            if (errEl) errEl.classList.add('hidden');
            if (modal) modal.classList.remove('hidden');
            if (reasonEl) reasonEl.focus();
        };
        window.closeRejectModal = function() {
            var modal = document.getElementById('rejectModal');
            if (modal) modal.classList.add('hidden');
            rejectTargetId = null;
        };

        window.submitReject = function() {
            var reasonEl = document.getElementById('rejectReason');
            var reason = reasonEl ? reasonEl.value.trim() : '';
            var errEl = document.getElementById('rejectError');
            if (reason.length < 10) {
                if (errEl) { errEl.textContent = 'Reason must be at least 10 characters'; errEl.classList.remove('hidden'); }
                return;
            }
            if (errEl) errEl.classList.add('hidden');
            var id = rejectTargetId;
            closeRejectModal();
            var url = base + '/api/leave/reject.php';
            fetch(url, {
                method: 'POST',
                headers: {'Content-Type': 'application/json'},
                body: JSON.stringify({request_id: id, reason: reason}),
                credentials: 'same-origin'
            })
            .then(function(r) {
                return r.text().then(function(text) {
                    try { return { ok: r.ok, data: JSON.parse(text) }; }
                    catch (e) { return { ok: r.ok, data: { success: false, message: text || 'Server error' } }; }
                });
            })
            .then(function(result) {
                var data = result.data;
                if (data && data.success) {
                    showToast(data.message || 'Leave request rejected', 'success');
                    updateRowStatus(id, 'rejected', data.message);
                    setTimeout(function() { removeRow(id); }, 1500);
                } else {
                    showToast((data && data.message) || 'Error rejecting leave', 'error');
                }
            })
            .catch(function() { showToast('Network error. Please try again.', 'error'); });
        };

        window.bulkApprove = function() {
            var selected = Array.from(document.querySelectorAll('.row-checkbox:checked'));
            if (selected.length === 0) {
                showToast('Please select at least one leave request', 'error');
                return;
            }
            if (!confirm('Approve ' + selected.length + ' leave request(s)?')) return;
            var ids = selected.map(function(cb) { return parseInt(cb.value, 10); }).filter(Boolean);
            var done = 0, failed = 0;
            var url = base + '/api/leave/approve.php';
            ids.forEach(function(id) {
                fetch(url, {
                    method: 'POST',
                    headers: {'Content-Type': 'application/json'},
                    body: JSON.stringify({request_id: id}),
                    credentials: 'same-origin'
                })
                .then(function(r) {
                    return r.text().then(function(text) {
                        try { return JSON.parse(text); }
                        catch (e) { return { success: false }; }
                    });
                })
                .then(function(data) {
                    if (data && data.success) { done++; removeRow(id); } else { failed++; }
                })
                .catch(function() { failed++; })
                .finally(function() {
                    if (done + failed === ids.length) {
                        if (failed === 0) showToast(done + ' request(s) approved successfully', 'success');
                        else showToast(done + ' approved, ' + failed + ' failed', failed > 0 ? 'error' : 'success');
                    }
                });
            });
        };

        window.filterTable = function() {
            var filter = document.getElementById('filterStatus').value;
            document.querySelectorAll('#leaveQueueTable tbody tr').forEach(function(row) {
                var status = row.getAttribute('data-status') || '';
                row.style.display = (!filter || status.toLowerCase() === filter.toLowerCase()) ? '' : 'none';
            });
        };

        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') closeRejectModal();
        });
    })();
    </script>

<?php elseif ($view === 'leave_calendar'): ?>
    <!-- Leave Calendar (unit/department) -->
    <?php
    // Fetch approved/pending leaves for the current week
    $calWeekStart = (new DateTime('monday this week'))->format('Y-m-d');
    $calWeekEnd = (new DateTime('sunday this week'))->format('Y-m-d');
    $leaveCalData = [];
    $calUnits = [];
    if (isset($db)) {
        try {
            $calUnits = $db->query("SELECT id, name FROM units ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
            $stmt = $db->prepare("
                SELECT lr.employee_id, lr.leave_type, lr.start_date, lr.end_date, lr.status,
                       e.first_name, e.last_name, e.employee_number, e.unit_id,
                       lt.name as leave_type_name
                FROM leave_requests lr
                JOIN employees e ON lr.employee_id = e.id
                LEFT JOIN leave_types lt ON lr.leave_type = lt.code
                WHERE lr.status IN ('approved','pending')
                  AND lr.start_date <= ? AND lr.end_date >= ?
                ORDER BY lr.start_date, e.last_name
            ");
            $stmt->execute([$calWeekEnd, $calWeekStart]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            // Expand to per-day entries
            foreach ($rows as $r) {
                $s = max(strtotime($r['start_date']), strtotime($calWeekStart));
                $e2 = min(strtotime($r['end_date']), strtotime($calWeekEnd));
                for ($d = $s; $d <= $e2; $d += 86400) {
                    $leaveCalData[date('Y-m-d', $d)][] = $r;
                }
            }
        } catch (PDOException $e) { error_log('Leave calendar: ' . $e->getMessage()); }
    }
    $leaveColors = ['VL' => 'bg-purple-100 text-purple-800 dark:bg-purple-900 dark:text-purple-200', 'SL' => 'bg-red-100 text-red-800 dark:bg-red-900 dark:text-red-200', 'SIL' => 'bg-amber-100 text-amber-800 dark:bg-amber-900 dark:text-amber-200', 'EL' => 'bg-blue-100 text-blue-800 dark:bg-blue-900 dark:text-blue-200', 'ML' => 'bg-pink-100 text-pink-800 dark:bg-pink-900 dark:text-pink-200'];
    ?>
    <div class="bg-white dark:bg-gray-800 rounded-xl shadow-sm border border-gray-100 dark:border-gray-700">
        <div class="px-5 py-4 border-b border-gray-100 dark:border-gray-700">
            <h2 class="text-sm font-semibold text-gray-900 dark:text-white">Leave Calendar (Unit/Department)</h2>
        </div>
        <div class="p-5">
            <div class="mb-4">
                <select class="text-sm border border-gray-300 dark:border-gray-600 rounded px-3 py-2 dark:bg-gray-700 dark:text-white">
                    <option value="">All Departments</option>
                    <?php foreach ($calUnits as $u): ?>
                        <option value="<?php echo $u['id']; ?>"><?php echo htmlspecialchars($u['name']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="grid grid-cols-7 gap-2">
                <?php
                $daysOfWeek = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];
                $currentDay = new DateTime('monday this week');
                for ($i = 0; $i < 7; $i++):
                    $dayStr = $currentDay->format('Y-m-d');
                    $dayLeaves = $leaveCalData[$dayStr] ?? [];
                ?>
                    <div class="border border-gray-200 dark:border-gray-700 rounded-lg p-3 min-h-[100px]">
                        <div class="text-xs font-semibold text-gray-700 dark:text-gray-300 mb-2 text-center">
                            <div class="font-bold"><?php echo $daysOfWeek[$i]; ?></div>
                            <div class="text-gray-500 mt-1"><?php echo $currentDay->format('M d'); ?></div>
                        </div>
                        <?php if (empty($dayLeaves)): ?>
                            <div class="text-xs text-gray-400 text-center mt-2">No leaves</div>
                        <?php else: ?>
                            <div class="space-y-1">
                                <?php foreach (array_slice($dayLeaves, 0, 4) as $lv):
                                    $colorCls = $leaveColors[$lv['leave_type'] ?? ''] ?? 'bg-gray-100 text-gray-700 dark:bg-gray-700 dark:text-gray-300';
                                    $isPending = ($lv['status'] ?? '') === 'pending';
                                ?>
                                    <div class="px-1.5 py-0.5 rounded text-[10px] leading-tight <?php echo $colorCls; ?> <?php echo $isPending ? 'border border-dashed border-current opacity-80' : ''; ?>" title="<?php echo htmlspecialchars($lv['first_name'] . ' ' . $lv['last_name'] . ' - ' . ($lv['leave_type_name'] ?? $lv['leave_type'])); ?>">
                                        <?php echo htmlspecialchars(substr($lv['first_name'], 0, 1) . '. ' . $lv['last_name']); ?>
                                        <span class="opacity-70"><?php echo htmlspecialchars($lv['leave_type']); ?></span>
                                    </div>
                                <?php endforeach; ?>
                                <?php if (count($dayLeaves) > 4): ?>
                                    <div class="text-[10px] text-gray-500 text-center">+<?php echo count($dayLeaves) - 4; ?> more</div>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php
                    $currentDay->modify('+1 day');
                endfor;
                ?>
            </div>
        </div>
    </div>
<?php endif; ?>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
