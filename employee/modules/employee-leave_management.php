<?php
require_once __DIR__ . '/../../config/config.php';
requireAuth();
checkRole(['employee', 'supervisor', 'unit_head']);

$page_title = 'Leave';
$view = $_GET['view'] ?? 'leave_balances';

$employeeId = getCurrentEmployeeId($db ?? null);
if (!$employeeId) {
    $employeeId = $_SESSION['hr3_employee_id'] ?? $_SESSION['employee_id'] ?? $_SESSION['user_id'] ?? null;
}
if ($employeeId && !is_numeric($employeeId) && isset($db)) {
    try {
        $stmt = $db->prepare("SELECT id FROM employees WHERE employee_number = ? LIMIT 1");
        $stmt->execute([$employeeId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        $employeeId = $row ? (int) $row['id'] : null;
    } catch (PDOException $e) {
        $employeeId = null;
    }
}
if ($employeeId) {
    $employeeId = (int) $employeeId;
}
if (!$employeeId) {
    $_SESSION['error'] = "Employee identifier not found. Please log in again.";
    header("Location: " . BASE_URL . "/auth/employee-login.php");
    exit();
}

$leaveBalances = [];
$leaveRequests = [];
$leaveTypes = [];
$message = '';

// Handle leave application
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['apply_leave'])) {
    $leaveType = $_POST['leave_type'] ?? '';
    $startDate = $_POST['start_date'] ?? '';
    $endDate = $_POST['end_date'] ?? '';
    $reason = $_POST['reason'] ?? '';
    
    if ($leaveType && $startDate && $endDate && isset($db)) {
        try {
            // Calculate total days
            $start = new DateTime($startDate);
            $end = new DateTime($endDate);
            $totalDays = $start->diff($end)->days + 1;
            
            // Check if it's a paid leave and validate balance
            $checkPaid = $db->prepare("
                SELECT lb.entitlement, lb.used, lb.pending, lt.is_paid
                FROM leave_balances lb
                JOIN leave_types lt ON lb.leave_type = lt.code
                WHERE lb.employee_id = :emp_id AND lb.leave_type = :leave_type AND lb.year = YEAR(CURDATE())
            ");
            $checkPaid->bindValue(':emp_id', $employeeId, PDO::PARAM_INT);
            $checkPaid->bindValue(':leave_type', $leaveType);
            $checkPaid->execute();
            $balance = $checkPaid->fetch(PDO::FETCH_ASSOC);
            
            if ($balance && $balance['is_paid']) {
                $available = $balance['entitlement'] - $balance['used'] - $balance['pending'];
                if ($totalDays > $available) {
                    $message = "Insufficient leave balance. Available: {$available} days, Requested: {$totalDays} days.";
                    $_SESSION['error'] = $message;
                    header("Location: " . $_SERVER['PHP_SELF'] . "?view=apply_leave");
                    exit();
                }
            }
            
            // Insert leave request with PENDING status
            $stmt = $db->prepare("
                INSERT INTO leave_requests (employee_id, leave_type, start_date, end_date, total_days, reason, status, submitted_at)
                VALUES (:emp_id, :leave_type, :start_date, :end_date, :total_days, :reason, 'pending', NOW())
            ");
            $stmt->bindValue(':emp_id', $employeeId, PDO::PARAM_INT);
            $stmt->bindValue(':leave_type', $leaveType);
            $stmt->bindValue(':start_date', $startDate);
            $stmt->bindValue(':end_date', $endDate);
            $stmt->bindValue(':total_days', $totalDays);
            $stmt->bindValue(':reason', $reason);
            $stmt->execute();
            
            $requestId = $db->lastInsertId();
            
            // Update leave_balances.pending so available = entitlement - used - pending (admin approve will decrement pending and add to used)
            try {
                $upd = $db->prepare("
                    UPDATE leave_balances SET pending = COALESCE(pending, 0) + :total_days
                    WHERE employee_id = :emp_id AND leave_type = :leave_type AND year = YEAR(CURDATE())
                ");
                $upd->bindValue(':emp_id', $employeeId, PDO::PARAM_INT);
                $upd->bindValue(':leave_type', $leaveType);
                $upd->bindValue(':total_days', $totalDays, PDO::PARAM_INT);
                $upd->execute();
            } catch (PDOException $e) {
                error_log('Leave balance pending update (non-fatal): ' . $e->getMessage());
            }
            
            // Log audit
            $auditStmt = $db->prepare("
                INSERT INTO audit_logs (employee_id, action, table_name, record_id, new_values, ip_address, user_agent)
                VALUES (:emp_id, 'apply_leave', 'leave_requests', :record_id, :new_values, :ip, :ua)
            ");
            $auditStmt->bindValue(':emp_id', $_SESSION['employee_id'] ?? $employeeId);
            $auditStmt->bindValue(':record_id', $requestId, PDO::PARAM_INT);
            $auditStmt->bindValue(':new_values', json_encode([
                'leave_type' => $leaveType,
                'start_date' => $startDate,
                'end_date' => $endDate,
                'total_days' => $totalDays,
                'status' => 'pending'
            ]));
            $auditStmt->bindValue(':ip', $_SERVER['REMOTE_ADDR'] ?? null);
            $auditStmt->bindValue(':ua', $_SERVER['HTTP_USER_AGENT'] ?? null);
            $auditStmt->execute();
            
            $message = 'Leave request submitted successfully!';
            $_SESSION['success'] = $message;
            header("Location: " . $_SERVER['PHP_SELF'] . "?view=leave_status_history");
            exit();
        } catch (PDOException $e) {
            error_log('Leave application error: ' . $e->getMessage());
            $message = 'An error occurred while submitting the leave request.';
            $_SESSION['error'] = $message;
        }
    }
}

if (isset($db)) {
    try {
        // Get leave balances joined with leave_types
        $check = $db->query("SHOW TABLES LIKE 'leave_balances'");
        if ($check && $check->rowCount() > 0) {
            $stmt = $db->prepare("
                SELECT lb.*, lt.name as leave_type_name, lt.is_paid
                FROM leave_balances lb
                LEFT JOIN leave_types lt ON lb.leave_type = lt.code
                WHERE lb.employee_id = :emp_id AND lb.year = YEAR(CURDATE())
                ORDER BY lt.name
            ");
            $stmt->bindValue(':emp_id', $employeeId, PDO::PARAM_INT);
            $stmt->execute();
            $leaveBalances = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }
        
        // Get leave types for dropdown
        $check = $db->query("SHOW TABLES LIKE 'leave_types'");
        if ($check && $check->rowCount() > 0) {
            $stmt = $db->query("SELECT code, name, is_paid FROM leave_types ORDER BY name");
            $leaveTypes = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }
        
        // Get all leave requests
        $check = $db->query("SHOW TABLES LIKE 'leave_requests'");
        if ($check && $check->rowCount() > 0) {
            $stmt = $db->prepare("
                SELECT lr.*, lt.name as leave_type_name
                FROM leave_requests lr
                LEFT JOIN leave_types lt ON lr.leave_type = lt.code
                WHERE lr.employee_id = :emp_id
                ORDER BY lr.submitted_at DESC
            ");
            $stmt->bindValue(':emp_id', $employeeId, PDO::PARAM_INT);
            $stmt->execute();
            $leaveRequests = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }
    } catch (PDOException $e) {
        error_log('Leave Management error: ' . $e->getMessage());
    }
}

// No sample data fallback – real data only

include __DIR__ . '/../../includes/header.php';
?>

<div class="mb-6">
    <h1 class="text-2xl font-semibold text-gray-900 dark:text-white">Leave</h1>
    <p class="mt-1 text-sm text-gray-600 dark:text-gray-400">Balance overview, application form, and history table</p>
</div>

<?php if ($view === 'leave_balances'): ?>
    <!-- Leave Balance Dashboard -->
    <div class="bg-white dark:bg-gray-800 rounded-xl shadow-sm border border-gray-100 dark:border-gray-700 mb-6">
        <div class="px-5 py-4 border-b border-gray-100 dark:border-gray-700">
            <h2 class="text-sm font-semibold text-gray-900 dark:text-white">Leave Balance Dashboard</h2>
        </div>
        <div class="p-5">
            <?php if (empty($leaveBalances)): ?>
                <p class="text-sm text-gray-500 text-center py-8">No leave balances found</p>
            <?php else: ?>
                <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
                    <?php foreach ($leaveBalances as $balance): 
                        $available = $balance['entitlement'] - $balance['used'] - $balance['pending'];
                        $percentage = $balance['entitlement'] > 0 ? ($available / $balance['entitlement']) * 100 : 0;
                    ?>
                        <div class="border border-gray-200 dark:border-gray-700 rounded-lg p-4">
                            <div class="flex items-center justify-between mb-3">
                                <h3 class="text-sm font-semibold text-gray-900 dark:text-white">
                                    <?php echo htmlspecialchars($balance['leave_type_name'] ?? $balance['leave_type']); ?>
                                </h3>
                                <?php if ($balance['is_paid']): ?>
                                    <span class="px-2 py-1 text-xs bg-blue-100 text-blue-800 dark:bg-blue-900 dark:text-blue-200 rounded">Paid</span>
                                <?php endif; ?>
                            </div>
                            <div class="space-y-2">
                                <div>
                                    <div class="flex justify-between text-xs text-gray-500 dark:text-gray-400 mb-1">
                                        <span>Available</span>
                                        <span><?php echo number_format($available, 1); ?> / <?php echo number_format($balance['entitlement'], 1); ?></span>
                                    </div>
                                    <div class="w-full bg-gray-200 dark:bg-gray-700 rounded-full h-2">
                                        <div class="bg-emerald-600 h-2 rounded-full" style="width: <?php echo min($percentage, 100); ?>%"></div>
                                    </div>
                                </div>
                                <div class="grid grid-cols-3 gap-2 text-xs">
                                    <div>
                                        <p class="text-gray-500 dark:text-gray-400">Used</p>
                                        <p class="font-semibold text-gray-900 dark:text-white"><?php echo number_format($balance['used'], 1); ?></p>
                                    </div>
                                    <div>
                                        <p class="text-gray-500 dark:text-gray-400">Pending</p>
                                        <p class="font-semibold text-amber-600"><?php echo number_format($balance['pending'], 1); ?></p>
                                    </div>
                                    <div>
                                        <p class="text-gray-500 dark:text-gray-400">Available</p>
                                        <p class="font-semibold text-emerald-600"><?php echo number_format($available, 1); ?></p>
                                    </div>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>

<?php elseif ($view === 'apply_leave'): ?>
    <!-- Apply for Leave (Sick, Vacation, Emergency) -->
    <div class="bg-white dark:bg-gray-800 rounded-xl shadow-sm border border-gray-100 dark:border-gray-700">
        <div class="px-5 py-4 border-b border-gray-100 dark:border-gray-700">
            <h2 class="text-sm font-semibold text-gray-900 dark:text-white">Apply for Leave (Sick, Vacation, Emergency)</h2>
        </div>
        <div class="p-5">
            <form method="POST" id="leaveForm">
                <div class="space-y-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Leave Type</label>
                        <select name="leave_type" required onchange="updateLeaveInfo(this)" class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg dark:bg-gray-700 dark:text-white">
                            <option value="">Select leave type</option>
                            <?php foreach ($leaveTypes as $type): ?>
                                <option value="<?php echo htmlspecialchars($type['code']); ?>" data-paid="<?php echo $type['is_paid'] ? '1' : '0'; ?>">
                                    <?php echo htmlspecialchars($type['name']); ?>
                                    <?php if ($type['is_paid']): ?>
                                        (Paid)
                                    <?php endif; ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <p id="leaveInfo" class="mt-1 text-xs text-gray-500 hidden"></p>
                    </div>
                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Start Date</label>
                            <input type="date" name="start_date" required onchange="calculateDays()" class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg dark:bg-gray-700 dark:text-white">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">End Date</label>
                            <input type="date" name="end_date" required onchange="calculateDays()" class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg dark:bg-gray-700 dark:text-white">
                        </div>
                    </div>
                    <div>
                        <p class="text-sm text-gray-600 dark:text-gray-400">
                            Total Days: <span id="totalDays" class="font-semibold">0</span>
                        </p>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Reason</label>
                        <textarea name="reason" rows="4" required placeholder="Please provide a reason for your leave request..." class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg dark:bg-gray-700 dark:text-white"></textarea>
                    </div>
                    <div class="flex gap-3">
                        <button type="submit" name="apply_leave" class="flex-1 px-4 py-2 bg-primary-600 text-white rounded-lg hover:bg-primary-700 transition-colors">
                            Submit Leave Request
                        </button>
                        <button type="reset" class="px-4 py-2 border border-gray-300 dark:border-gray-600 text-gray-700 dark:text-gray-300 rounded-lg hover:bg-gray-50 dark:hover:bg-gray-700 transition-colors">
                            Clear
                        </button>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <script>
        function updateLeaveInfo(select) {
            const option = select.options[select.selectedIndex];
            const isPaid = option.getAttribute('data-paid') === '1';
            const infoEl = document.getElementById('leaveInfo');
            if (option.value) {
                infoEl.textContent = isPaid ? 'This is a paid leave. Balance will be checked.' : 'This is an unpaid leave.';
                infoEl.classList.remove('hidden');
            } else {
                infoEl.classList.add('hidden');
            }
        }

        function calculateDays() {
            const start = document.querySelector('input[name="start_date"]').value;
            const end = document.querySelector('input[name="end_date"]').value;
            if (start && end) {
                const startDate = new Date(start);
                const endDate = new Date(end);
                const diffTime = Math.abs(endDate - startDate);
                const diffDays = Math.ceil(diffTime / (1000 * 60 * 60 * 24)) + 1;
                document.getElementById('totalDays').textContent = diffDays;
            }
        }
    </script>

<?php elseif ($view === 'leave_status_history'): ?>
    <!-- Leave History & Approvals -->
    <div class="bg-white dark:bg-gray-800 rounded-xl shadow-sm border border-gray-100 dark:border-gray-700">
        <div class="px-5 py-4 border-b border-gray-100 dark:border-gray-700 flex items-center justify-between">
            <h2 class="text-sm font-semibold text-gray-900 dark:text-white">Leave History & Approvals</h2>
            <div class="flex gap-2">
                <select id="filterStatus" onchange="filterTable()" class="text-xs border border-gray-300 dark:border-gray-600 rounded px-2 py-1 dark:bg-gray-700 dark:text-white">
                    <option value="">All Status</option>
                    <option value="pending">Pending</option>
                    <option value="approved">Approved</option>
                    <option value="rejected">Rejected</option>
                </select>
                <button onclick="exportHistory()" class="px-3 py-1 text-xs border border-gray-300 dark:border-gray-600 rounded hover:bg-gray-50 dark:hover:bg-gray-700">
                    Export
                </button>
            </div>
        </div>
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700" id="leaveHistoryTable">
                <thead class="bg-gray-50 dark:bg-gray-700">
                    <tr>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase cursor-pointer" onclick="sortTable(0)">
                            Leave Type <span class="sort-indicator">↕</span>
                        </th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase cursor-pointer" onclick="sortTable(1)">
                            Start Date <span class="sort-indicator">↕</span>
                        </th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase">End Date</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase">Days</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase">Status</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase">Submitted</th>
                    </tr>
                </thead>
                <tbody class="bg-white dark:bg-gray-800 divide-y divide-gray-200 dark:divide-gray-700">
                    <?php if (empty($leaveRequests)): ?>
                        <tr>
                            <td colspan="6" class="px-6 py-4 text-center text-sm text-gray-500">No leave requests found</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($leaveRequests as $request): ?>
                            <tr data-status="<?php echo htmlspecialchars($request['status']); ?>">
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900 dark:text-white">
                                    <?php echo htmlspecialchars($request['leave_type_name'] ?? $request['leave_type']); ?>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900 dark:text-white">
                                    <?php echo date('M d, Y', strtotime($request['start_date'])); ?>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900 dark:text-white">
                                    <?php echo date('M d, Y', strtotime($request['end_date'])); ?>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900 dark:text-white">
                                    <?php echo number_format($request['total_days'], 1); ?>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm">
                                    <span class="px-2 py-1 text-xs font-semibold rounded-full <?php 
                                        echo $request['status'] === 'approved' ? 'bg-emerald-100 text-emerald-800 dark:bg-emerald-900 dark:text-emerald-200' : 
                                            ($request['status'] === 'pending' ? 'bg-amber-100 text-amber-800 dark:bg-amber-900 dark:text-amber-200' : 
                                            ($request['status'] === 'rejected' ? 'bg-red-100 text-red-800 dark:bg-red-900 dark:text-red-200' : 'bg-gray-100 text-gray-800 dark:bg-gray-700 dark:text-gray-200'));
                                    ?>">
                                        <?php echo strtoupper($request['status']); ?>
                                    </span>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500 dark:text-gray-400">
                                    <?php echo date('M d, Y H:i', strtotime($request['submitted_at'])); ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <script>
        function filterTable() {
            const filter = document.getElementById('filterStatus').value;
            const rows = document.querySelectorAll('#leaveHistoryTable tbody tr');
            rows.forEach(row => {
                const status = row.getAttribute('data-status') || '';
                if (!filter || status.toLowerCase() === filter.toLowerCase()) {
                    row.style.display = '';
                } else {
                    row.style.display = 'none';
                }
            });
        }

        let sortDirection = {};
        function sortTable(columnIndex) {
            const tbody = document.querySelector('#leaveHistoryTable tbody');
            const rows = Array.from(tbody.querySelectorAll('tr:not([style*="display: none"])'));
            
            sortDirection[columnIndex] = sortDirection[columnIndex] === 'asc' ? 'desc' : 'asc';
            
            rows.sort((a, b) => {
                const aText = a.cells[columnIndex].textContent.trim();
                const bText = b.cells[columnIndex].textContent.trim();
                return sortDirection[columnIndex] === 'asc' ? aText.localeCompare(bText) : bText.localeCompare(aText);
            });
            
            rows.forEach(row => tbody.appendChild(row));
        }

        function exportHistory() {
            const table = document.getElementById('leaveHistoryTable');
            const rows = table.querySelectorAll('tbody tr:not([style*="display: none"])');
            let csv = 'Leave Type,Start Date,End Date,Days,Status,Submitted\n';
            
            rows.forEach(row => {
                const cells = row.querySelectorAll('td');
                if (cells.length > 0) {
                    csv += Array.from(cells).map(cell => cell.textContent.trim().replace(/,/g, ';')).join(',') + '\n';
                }
            });
            
            const blob = new Blob([csv], { type: 'text/csv' });
            const url = window.URL.createObjectURL(blob);
            const a = document.createElement('a');
            a.href = url;
            a.download = 'leave_history_' + new Date().toISOString().split('T')[0] + '.csv';
            a.click();
        }
    </script>
<?php elseif ($view === 'upload_docs'): ?>
    <div class="bg-white dark:bg-gray-800 rounded-xl shadow-sm border border-gray-100 dark:border-gray-700">
        <div class="px-5 py-4 border-b border-gray-100 dark:border-gray-700">
            <h2 class="text-sm font-semibold text-gray-900 dark:text-white">Upload Supporting Documents</h2>
        </div>
        <div class="p-5">
            <p class="text-sm text-gray-600 dark:text-gray-400 mb-4">Upload documents (medical certificate, travel tickets, etc.) for your leave requests.</p>
            <form method="POST" enctype="multipart/form-data" action="<?php echo BASE_URL; ?>/api/leave/upload_docs.php">
                <div class="space-y-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Select Leave Request</label>
                        <select name="leave_request_id" required class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg dark:bg-gray-700 dark:text-white">
                            <option value="">Select leave request</option>
                            <?php 
                            $pendingLeave = array_filter($leaveRequests, function($r) { return in_array($r['status'] ?? '', ['pending', 'endorsed']); });
                            foreach ($pendingLeave as $lr): ?>
                                <option value="<?php echo $lr['id']; ?>"><?php echo htmlspecialchars($lr['leave_type_name'] ?? $lr['leave_type']); ?> - <?php echo date('M d', strtotime($lr['start_date'])); ?> to <?php echo date('M d, Y', strtotime($lr['end_date'])); ?></option>
                            <?php endforeach; ?>
                            <?php if (empty($pendingLeave)): ?><option value="" disabled>No pending leave requests</option><?php endif; ?>
                        </select>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Upload Document</label>
                        <input type="file" name="document" accept=".pdf,.jpg,.jpeg,.png" required class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg dark:bg-gray-700 dark:text-white">
                    </div>
                    <button type="submit" class="px-4 py-2 bg-primary-600 text-white rounded-lg hover:bg-primary-700">Upload</button>
                </div>
            </form>
        </div>
    </div>
<?php endif; ?>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
