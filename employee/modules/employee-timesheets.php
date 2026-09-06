<?php
require_once __DIR__ . '/../../config/config.php';
requireAuth();
checkRole(['employee', 'supervisor', 'unit_head']);

$page_title = 'Timesheet';
$view = $_GET['view'] ?? 'current_cutoff';

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

$timesheets = [];
$currentTimesheet = null;
$message = '';

// Handle timesheet submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_timesheet'])) {
    $timesheetId = $_POST['timesheet_id'] ?? null;
    
    if ($timesheetId && isset($db)) {
        try {
            $stmt = $db->prepare("
                UPDATE timesheets 
                SET status = 'submitted', updated_at = NOW()
                WHERE id = :id AND employee_id = :emp_id AND status = 'draft'
            ");
            $stmt->bindValue(':id', $timesheetId, PDO::PARAM_INT);
            $stmt->bindValue(':emp_id', $employeeId, PDO::PARAM_INT);
            $stmt->execute();
            
            if ($stmt->rowCount() > 0) {
                // Log audit
                $auditStmt = $db->prepare("
                    INSERT INTO audit_logs (employee_id, action, table_name, record_id, new_values, ip_address, user_agent)
                    VALUES (:emp_id, 'submit_timesheet', 'timesheets', :record_id, :new_values, :ip, :ua)
                ");
                $auditStmt->bindValue(':emp_id', $_SESSION['employee_id'] ?? $employeeId);
                $auditStmt->bindValue(':record_id', $timesheetId, PDO::PARAM_INT);
                $auditStmt->bindValue(':new_values', json_encode(['status' => 'submitted']));
                $auditStmt->bindValue(':ip', $_SERVER['REMOTE_ADDR'] ?? null);
                $auditStmt->bindValue(':ua', $_SERVER['HTTP_USER_AGENT'] ?? null);
                $auditStmt->execute();
                
                $message = 'Timesheet submitted successfully!';
                $_SESSION['success'] = $message;
            } else {
                $message = 'Failed to submit timesheet. It may have already been submitted.';
                $_SESSION['error'] = $message;
            }
        } catch (PDOException $e) {
            error_log('Timesheet submission error: ' . $e->getMessage());
            $message = 'An error occurred while submitting the timesheet.';
            $_SESSION['error'] = $message;
        }
    }
    header("Location: " . $_SERVER['PHP_SELF']);
    exit();
}

if (isset($db) && is_numeric($employeeId)) {
    try {
        $check = $db->query("SHOW TABLES LIKE 'timesheets'");
        if ($check && $check->rowCount() > 0) {
            // Get last 3 periods
            $stmt = $db->prepare("
                SELECT id, period_start, period_end, total_hours, total_ot_hours, status, created_at
                FROM timesheets
                WHERE employee_id = :emp_id
                ORDER BY period_start DESC
                LIMIT 3
            ");
            $stmt->bindValue(':emp_id', $employeeId, PDO::PARAM_INT);
            $stmt->execute();
            $timesheets = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Get current/open timesheet (draft status)
            $stmt = $db->prepare("
                SELECT id, period_start, period_end, total_hours, total_ot_hours, status, created_at
                FROM timesheets
                WHERE employee_id = :emp_id
                  AND status = 'draft'
                ORDER BY period_start DESC
                LIMIT 1
            ");
            $stmt->bindValue(':emp_id', $employeeId, PDO::PARAM_INT);
            $stmt->execute();
            $currentTimesheet = $stmt->fetch(PDO::FETCH_ASSOC);
        }

        // Correction requests for dispute view
        $correctionRequests = [];
        $corrCheck = $db->query("SHOW TABLES LIKE 'timesheet_correction_requests'");
        if ($corrCheck && $corrCheck->rowCount() > 0) {
            $stmt = $db->prepare("SELECT id, date, requested_time_in, requested_time_out, reason, status, created_at FROM timesheet_correction_requests WHERE employee_id = ? ORDER BY created_at DESC LIMIT 20");
            $stmt->execute([$employeeId]);
            $correctionRequests = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }

        // If no draft found, get the most recent timesheet regardless of status
        if (!$currentTimesheet) {
            $stmt = $db->prepare("
                SELECT id, period_start, period_end, total_hours, total_ot_hours, total_nd_hours, status, created_at
                FROM timesheets
                WHERE employee_id = :emp_id
                ORDER BY period_start DESC
                LIMIT 1
            ");
            $stmt->bindValue(':emp_id', $employeeId, PDO::PARAM_INT);
            $stmt->execute();
            $currentTimesheet = $stmt->fetch(PDO::FETCH_ASSOC);
        }

        // Load timesheet lines for the current timesheet
        $timesheetLines = [];
        if ($currentTimesheet && !empty($currentTimesheet['id'])) {
            $stmt = $db->prepare("
                SELECT tl.date, tl.regular_hours, tl.ot_hours, tl.nd_hours, tl.late_minutes, tl.undertime_minutes,
                       tl.holiday_premium_hours, tl.rest_day_hours
                FROM timesheet_lines tl
                WHERE tl.timesheet_id = :ts_id
                ORDER BY tl.date
            ");
            $stmt->bindValue(':ts_id', $currentTimesheet['id'], PDO::PARAM_INT);
            $stmt->execute();
            $timesheetLines = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }
    } catch (PDOException $e) {
        error_log('My Timesheets error: ' . $e->getMessage());
    }
}

include __DIR__ . '/../../includes/header.php';
?>

<div class="mb-6">
    <h1 class="text-2xl font-semibold text-gray-900 dark:text-white">Timesheet</h1>
    <p class="mt-1 text-sm text-gray-600 dark:text-gray-400">Editable hours table, totals, and submission panel</p>
</div>

<?php if ($view === 'current_cutoff' || $view === 'submit_timesheet' || $view === 'hours_metrics'): ?>
    <!-- Current Pay Period Summary -->
    <?php if ($currentTimesheet): ?>
        <div class="grid grid-cols-1 lg:grid-cols-3 gap-6 mb-6">
            <!-- Pay Period Summary Card -->
            <div class="bg-white dark:bg-gray-800 rounded-xl shadow-sm border border-gray-100 dark:border-gray-700">
                <div class="px-5 py-4 border-b border-gray-100 dark:border-gray-700">
                    <h2 class="text-sm font-semibold text-gray-900 dark:text-white">Current Pay Period Summary</h2>
                </div>
                <div class="p-5">
                    <div class="space-y-3">
                        <div>
                            <p class="text-xs text-gray-500 dark:text-gray-400">Period</p>
                            <p class="mt-1 text-sm font-semibold text-gray-900 dark:text-white">
                                <?php echo date('M d', strtotime($currentTimesheet['period_start'])); ?> - 
                                <?php echo date('M d, Y', strtotime($currentTimesheet['period_end'])); ?>
                            </p>
                        </div>
                        <div>
                            <p class="text-xs text-gray-500 dark:text-gray-400">Status</p>
                            <span class="mt-1 inline-block px-3 py-1 text-xs font-semibold rounded-full bg-amber-100 text-amber-800 dark:bg-amber-900 dark:text-amber-200">
                                <?php echo strtoupper($currentTimesheet['status']); ?>
                            </span>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Hours Worked / Overtime Metrics -->
            <div class="lg:col-span-2 bg-white dark:bg-gray-800 rounded-xl shadow-sm border border-gray-100 dark:border-gray-700">
                <div class="px-5 py-4 border-b border-gray-100 dark:border-gray-700">
                    <h2 class="text-sm font-semibold text-gray-900 dark:text-white">Hours Worked / Overtime Metrics</h2>
                </div>
                <div class="p-5">
                    <div class="grid grid-cols-3 gap-4">
                        <div class="text-center p-4 bg-blue-50 dark:bg-blue-900/20 rounded-lg">
                            <p class="text-xs text-gray-500 dark:text-gray-400 mb-1">Total Hours</p>
                            <p class="text-2xl font-bold text-blue-600 dark:text-blue-400">
                                <?php echo number_format($currentTimesheet['total_hours'], 1); ?>
                            </p>
                            <p class="text-xs text-gray-500 mt-1">hours</p>
                        </div>
                        <div class="text-center p-4 bg-amber-50 dark:bg-amber-900/20 rounded-lg">
                            <p class="text-xs text-gray-500 dark:text-gray-400 mb-1">Overtime</p>
                            <p class="text-2xl font-bold text-amber-600 dark:text-amber-400">
                                <?php echo number_format($currentTimesheet['total_ot_hours'], 1); ?>
                            </p>
                            <p class="text-xs text-gray-500 mt-1">hours</p>
                        </div>
                        <div class="text-center p-4 bg-emerald-50 dark:bg-emerald-900/20 rounded-lg">
                            <p class="text-xs text-gray-500 dark:text-gray-400 mb-1">Regular</p>
                            <p class="text-2xl font-bold text-emerald-600 dark:text-emerald-400">
                                <?php echo number_format($currentTimesheet['total_hours'] - $currentTimesheet['total_ot_hours'], 1); ?>
                            </p>
                            <p class="text-xs text-gray-500 mt-1">hours</p>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Editable Hours Table -->
        <div class="bg-white dark:bg-gray-800 rounded-xl shadow-sm border border-gray-100 dark:border-gray-700 mb-6">
            <div class="px-5 py-4 border-b border-gray-100 dark:border-gray-700 flex items-center justify-between">
                <h2 class="text-sm font-semibold text-gray-900 dark:text-white">Editable Hours Table</h2>
                <div class="flex gap-2">
                    <button onclick="addRow()" class="px-3 py-1 text-xs border border-gray-300 dark:border-gray-600 rounded hover:bg-gray-50 dark:hover:bg-gray-700">
                        Add Row
                    </button>
                    <button onclick="calculateTotals()" class="px-3 py-1 text-xs border border-gray-300 dark:border-gray-600 rounded hover:bg-gray-50 dark:hover:bg-gray-700">
                        Recalculate
                    </button>
                </div>
            </div>
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700" id="hoursTable">
                    <thead class="bg-gray-50 dark:bg-gray-700">
                        <tr>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase">Date</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase">Regular Hours</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase">Overtime Hours</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase">Notes</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="bg-white dark:bg-gray-800 divide-y divide-gray-200 dark:divide-gray-700">
                        <?php if (!empty($timesheetLines)): ?>
                            <?php foreach ($timesheetLines as $line): ?>
                            <tr>
                                <td class="px-6 py-4 text-sm text-gray-900 dark:text-white whitespace-nowrap">
                                    <?php echo date('D, M d', strtotime($line['date'])); ?>
                                </td>
                                <td class="px-6 py-4 text-sm text-gray-900 dark:text-white">
                                    <?php echo number_format((float)$line['regular_hours'], 1); ?>
                                </td>
                                <td class="px-6 py-4 text-sm <?php echo (float)$line['ot_hours'] > 0 ? 'text-amber-600 dark:text-amber-400 font-medium' : 'text-gray-500'; ?>">
                                    <?php echo number_format((float)$line['ot_hours'], 1); ?>
                                </td>
                                <td class="px-6 py-4 text-xs text-gray-500 dark:text-gray-400">
                                    <?php
                                    $notes = [];
                                    if ((int)($line['late_minutes'] ?? 0) > 0) $notes[] = 'Late: ' . $line['late_minutes'] . 'min';
                                    if ((float)($line['nd_hours'] ?? 0) > 0) $notes[] = 'ND: ' . number_format((float)$line['nd_hours'], 1) . 'h';
                                    if ((float)($line['holiday_premium_hours'] ?? 0) > 0) $notes[] = 'Holiday: ' . number_format((float)$line['holiday_premium_hours'], 1) . 'h';
                                    if ((float)($line['rest_day_hours'] ?? 0) > 0) $notes[] = 'Rest Day: ' . number_format((float)$line['rest_day_hours'], 1) . 'h';
                                    echo !empty($notes) ? implode(', ', $notes) : '-';
                                    ?>
                                </td>
                                <td class="px-6 py-4"></td>
                            </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                        <tr>
                            <td colspan="5" class="px-6 py-8 text-center text-sm text-gray-500 dark:text-gray-400">No timesheet line entries for this period</td>
                        </tr>
                        <?php endif; ?>
                        <!-- Editable row (hidden by default, shown when user clicks "Add Row") -->
                        <tr id="newRowTemplate" style="display:none;">
                            <td class="px-6 py-4">
                                <input type="date" class="w-full px-2 py-1 text-sm border border-gray-300 dark:border-gray-600 rounded dark:bg-gray-700 dark:text-white" value="<?php echo date('Y-m-d'); ?>">
                            </td>
                            <td class="px-6 py-4">
                                <input type="number" step="0.25" class="w-full px-2 py-1 text-sm border border-gray-300 dark:border-gray-600 rounded dark:bg-gray-700 dark:text-white hours-input" value="8.00">
                            </td>
                            <td class="px-6 py-4">
                                <input type="number" step="0.25" class="w-full px-2 py-1 text-sm border border-gray-300 dark:border-gray-600 rounded dark:bg-gray-700 dark:text-white ot-input" value="0.00">
                            </td>
                            <td class="px-6 py-4">
                                <input type="text" class="w-full px-2 py-1 text-sm border border-gray-300 dark:border-gray-600 rounded dark:bg-gray-700 dark:text-white" placeholder="Notes...">
                            </td>
                            <td class="px-6 py-4">
                                <button onclick="removeRow(this)" class="text-red-600 hover:text-red-700 text-xs">Remove</button>
                            </td>
                        </tr>
                    </tbody>
                    <tfoot class="bg-gray-50 dark:bg-gray-700">
                        <tr>
                            <td class="px-6 py-3 text-sm font-semibold text-gray-900 dark:text-white">Totals</td>
                            <td class="px-6 py-3 text-sm font-semibold text-gray-900 dark:text-white" id="totalRegular">0.00</td>
                            <td class="px-6 py-3 text-sm font-semibold text-gray-900 dark:text-white" id="totalOT">0.00</td>
                            <td colspan="2"></td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </div>

        <!-- Submit Timesheet Panel -->
        <?php if ($currentTimesheet['status'] === 'draft'): ?>
            <div class="bg-white dark:bg-gray-800 rounded-xl shadow-sm border border-gray-100 dark:border-gray-700">
                <div class="px-5 py-4 border-b border-gray-100 dark:border-gray-700">
                    <h2 class="text-sm font-semibold text-gray-900 dark:text-white">Submit Timesheet</h2>
                </div>
                <div class="p-5">
                    <form method="POST" onsubmit="return confirm('Are you sure you want to submit this timesheet? This action cannot be undone.');">
                        <input type="hidden" name="timesheet_id" value="<?php echo $currentTimesheet['id']; ?>">
                        <div class="flex items-center justify-between">
                            <div>
                                <p class="text-sm text-gray-600 dark:text-gray-400">
                                    Review your timesheet before submission. Once submitted, changes will require approval.
                                </p>
                            </div>
                            <button type="submit" name="submit_timesheet" 
                                    class="px-6 py-2 bg-primary-600 text-white font-medium rounded-lg hover:bg-primary-700 transition-colors">
                                Submit Timesheet
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        <?php endif; ?>
    <?php else: ?>
        <div class="bg-white dark:bg-gray-800 rounded-xl shadow-sm border border-gray-100 dark:border-gray-700">
            <div class="p-8 text-center">
                <svg xmlns="http://www.w3.org/2000/svg" class="w-12 h-12 mx-auto text-gray-400 mb-3" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2" />
                </svg>
                <p class="text-sm text-gray-500 dark:text-gray-400">No current timesheet available</p>
            </div>
        </div>
    <?php endif; ?>

    <!-- Last 3 Periods -->
    <div class="bg-white dark:bg-gray-800 rounded-xl shadow-sm border border-gray-100 dark:border-gray-700">
        <div class="px-5 py-4 border-b border-gray-100 dark:border-gray-700">
            <h2 class="text-sm font-semibold text-gray-900 dark:text-white">Recent Timesheets (Last 3 Periods)</h2>
        </div>
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                <thead class="bg-gray-50 dark:bg-gray-700">
                    <tr>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase">Period</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase">Total Hours</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase">OT Hours</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase">Status</th>
                    </tr>
                </thead>
                <tbody class="bg-white dark:bg-gray-800 divide-y divide-gray-200 dark:divide-gray-700">
                    <?php if (empty($timesheets)): ?>
                        <tr>
                            <td colspan="4" class="px-6 py-4 text-center text-sm text-gray-500">No timesheets found</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($timesheets as $ts): ?>
                            <tr>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900 dark:text-white">
                                    <?php echo date('M d', strtotime($ts['period_start'])); ?> - 
                                    <?php echo date('M d, Y', strtotime($ts['period_end'])); ?>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900 dark:text-white">
                                    <?php echo number_format($ts['total_hours'], 2); ?>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900 dark:text-white">
                                    <?php echo number_format($ts['total_ot_hours'], 2); ?>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm">
                                    <span class="px-2 py-1 text-xs font-semibold rounded-full <?php 
                                        echo $ts['status'] === 'approved' ? 'bg-emerald-100 text-emerald-800' : 
                                            ($ts['status'] === 'submitted' ? 'bg-blue-100 text-blue-800' : 'bg-gray-100 text-gray-800');
                                    ?>">
                                        <?php echo strtoupper($ts['status']); ?>
                                    </span>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

<?php elseif ($view === 'corrections_request'): ?>
    <!-- Correction Requests & Status -->
    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
        <!-- Request Correction Form -->
        <div class="bg-white dark:bg-gray-800 rounded-xl shadow-sm border border-gray-100 dark:border-gray-700">
            <div class="px-5 py-4 border-b border-gray-100 dark:border-gray-700">
                <h2 class="text-sm font-semibold text-gray-900 dark:text-white">Request Correction</h2>
            </div>
            <div class="p-5">
                <form id="timesheetCorrectionForm" method="POST" action="<?php echo BASE_URL; ?>/api/attendance/corrections.php" class="space-y-4">
                    <input type="hidden" name="date" id="correctionDateField">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Timesheet (optional)</label>
                        <select name="timesheet_id" class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg dark:bg-gray-700 dark:text-white">
                            <option value="">None</option>
                            <?php foreach ($timesheets as $ts): ?>
                                <option value="<?php echo $ts['id']; ?>">
                                    <?php echo date('M d', strtotime($ts['period_start'])); ?> - <?php echo date('M d, Y', strtotime($ts['period_end'])); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Date to Correct (past dates only)</label>
                        <input type="date" name="correction_date" id="correctionDateInput" max="<?php echo date('Y-m-d', strtotime('-1 day')); ?>" required class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg dark:bg-gray-700 dark:text-white">
                    </div>
                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Requested Time In</label>
                            <input type="time" name="requested_time_in" class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg dark:bg-gray-700 dark:text-white">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Requested Time Out</label>
                            <input type="time" name="requested_time_out" class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg dark:bg-gray-700 dark:text-white">
                        </div>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Reason (min 10 characters)</label>
                        <textarea name="reason" rows="3" required minlength="10" placeholder="Please explain why this correction is needed..." class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg dark:bg-gray-700 dark:text-white"></textarea>
                    </div>
                    <button type="submit" class="w-full px-4 py-2 bg-primary-600 text-white rounded-lg hover:bg-primary-700 transition-colors">
                        Submit Correction Request
                    </button>
                </form>
                <script>
                (function() {
                    var form = document.getElementById('timesheetCorrectionForm');
                    var dateInput = document.getElementById('correctionDateInput');
                    var dateField = document.getElementById('correctionDateField');
                    if (form && dateInput && dateField) {
                        form.addEventListener('submit', function(e) {
                            dateField.value = dateInput.value;
                            var reason = form.querySelector('[name="reason"]').value;
                            if (reason.length < 10) { e.preventDefault(); alert('Reason must be at least 10 characters.'); return; }
                            if (new Date(dateInput.value) >= new Date(new Date().toDateString())) { e.preventDefault(); alert('Correction must be for a past date.'); return; }
                            e.preventDefault();
                            var fd = new FormData(form);
                            var payload = {
                                date: fd.get('correction_date'),
                                requested_time_in: fd.get('requested_time_in') || null,
                                requested_time_out: fd.get('requested_time_out') || null,
                                reason: fd.get('reason'),
                                timesheet_id: fd.get('timesheet_id') || null
                            };
                            fetch(form.action, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(payload) })
                                .then(function(r) { return r.json(); })
                                .then(function(data) {
                                    if (data.success) { alert('Correction request submitted.'); form.reset(); location.reload(); }
                                    else alert(data.message || 'Failed to submit');
                                })
                                .catch(function() { alert('An error occurred.'); });
                        });
                    }
                })();
                </script>
            </div>
        </div>

        <!-- Correction Status -->
        <div class="bg-white dark:bg-gray-800 rounded-xl shadow-sm border border-gray-100 dark:border-gray-700">
            <div class="px-5 py-4 border-b border-gray-100 dark:border-gray-700">
                <h2 class="text-sm font-semibold text-gray-900 dark:text-white">Correction Status</h2>
            </div>
            <div class="p-5">
                <div class="space-y-3">
                    <?php if (empty($correctionRequests)): ?>
                        <p class="text-sm text-gray-500 dark:text-gray-400 text-center py-6">No correction requests yet. Submit one above to see status here.</p>
                    <?php else: ?>
                        <?php foreach ($correctionRequests as $cr): ?>
                            <div class="p-3 bg-gray-50 dark:bg-gray-700/50 rounded-lg">
                                <div class="flex items-center justify-between mb-2">
                                    <p class="text-sm font-medium text-gray-900 dark:text-white"><?php echo htmlspecialchars($cr['date']); ?></p>
                                    <span class="px-2 py-1 text-xs font-semibold rounded-full <?php echo ($cr['status'] ?? '') === 'approved' ? 'bg-emerald-100 text-emerald-800 dark:bg-emerald-900 dark:text-emerald-200' : (($cr['status'] ?? '') === 'rejected' ? 'bg-red-100 text-red-800 dark:bg-red-900 dark:text-red-200' : 'bg-amber-100 text-amber-800 dark:bg-amber-900 dark:text-amber-200'); ?>">
                                        <?php echo strtoupper($cr['status'] ?? 'pending'); ?>
                                    </span>
                                </div>
                                <p class="text-xs text-gray-500"><?php echo htmlspecialchars(($cr['requested_time_in'] ?? '—') . ' - ' . ($cr['requested_time_out'] ?? '—')); ?></p>
                                <p class="text-xs text-gray-600 dark:text-gray-400 mt-1"><?php echo htmlspecialchars($cr['reason'] ?? ''); ?></p>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
<?php elseif ($view === 'history'): ?>
    <!-- Timesheet History -->
    <div class="bg-white dark:bg-gray-800 rounded-xl shadow-sm border border-gray-100 dark:border-gray-700">
        <div class="px-5 py-4 border-b border-gray-100 dark:border-gray-700">
            <h2 class="text-sm font-semibold text-gray-900 dark:text-white">Timesheet History</h2>
        </div>
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                <thead class="bg-gray-50 dark:bg-gray-700">
                    <tr>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase">Period</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase">Total Hours</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase">OT Hours</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase">Status</th>
                    </tr>
                </thead>
                <tbody class="bg-white dark:bg-gray-800 divide-y divide-gray-200 dark:divide-gray-700">
                    <?php
                    $historySheets = [];
                    if (isset($db) && is_numeric($employeeId)) {
                        try {
                            $stmt = $db->prepare("SELECT id, period_start, period_end, total_hours, total_ot_hours, status FROM timesheets WHERE employee_id = ? ORDER BY period_start DESC LIMIT 20");
                            $stmt->execute([$employeeId]);
                            $historySheets = $stmt->fetchAll(PDO::FETCH_ASSOC);
                        } catch (PDOException $e) {}
                    }
                    if (empty($historySheets)) {
                        $historySheets = $timesheets;
                    }
                    if (empty($historySheets)): ?>
                        <tr><td colspan="4" class="px-6 py-4 text-center text-sm text-gray-500">No timesheets found</td></tr>
                    <?php else:
                        foreach ($historySheets as $ts): ?>
                        <tr>
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900 dark:text-white">
                                <?php echo date('M d', strtotime($ts['period_start'])); ?> - <?php echo date('M d, Y', strtotime($ts['period_end'])); ?>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900 dark:text-white"><?php echo number_format($ts['total_hours'] ?? 0, 2); ?></td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900 dark:text-white"><?php echo number_format($ts['total_ot_hours'] ?? 0, 2); ?></td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm">
                                <span class="px-2 py-1 text-xs font-semibold rounded-full <?php echo ($ts['status'] ?? '') === 'approved' ? 'bg-emerald-100 text-emerald-800 dark:bg-emerald-900 dark:text-emerald-200' : (($ts['status'] ?? '') === 'locked' ? 'bg-blue-100 text-blue-800 dark:bg-blue-900 dark:text-blue-200' : 'bg-gray-100 text-gray-800 dark:bg-gray-700 dark:text-gray-200'); ?>"><?php echo strtoupper($ts['status'] ?? 'draft'); ?></span>
                            </td>
                        </tr>
                    <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
    </div>
<?php elseif ($view === 'dispute'): ?>
    <!-- Dispute Timesheet -->
    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
        <div class="bg-white dark:bg-gray-800 rounded-xl shadow-sm border border-gray-100 dark:border-gray-700">
            <div class="px-5 py-4 border-b border-gray-100 dark:border-gray-700">
                <h2 class="text-sm font-semibold text-gray-900 dark:text-white">File Dispute / Correction Request</h2>
            </div>
            <div class="p-5">
                <p class="text-sm text-gray-600 dark:text-gray-400 mb-4">Use the <a href="<?php echo BASE_URL; ?>/employee/modules/employee-timeclock.php?view=correction_request" class="text-primary-600 hover:underline">Time Correction Request</a> page to request corrections for missed or incorrect punches.</p>
                <p class="text-sm text-gray-600 dark:text-gray-400">For timesheet disputes, submit a correction request with the affected date and requested time in/out.</p>
            </div>
        </div>
        <div class="bg-white dark:bg-gray-800 rounded-xl shadow-sm border border-gray-100 dark:border-gray-700">
            <div class="px-5 py-4 border-b border-gray-100 dark:border-gray-700">
                <h2 class="text-sm font-semibold text-gray-900 dark:text-white">Recent Correction Requests</h2>
            </div>
            <div class="p-5">
                <div id="disputeCorrectionList" class="space-y-3"><p class="text-sm text-gray-500">Loading...</p></div>
            </div>
        </div>
    </div>
    <script>
        fetch('<?php echo BASE_URL; ?>/api/attendance/corrections.php')
            .then(r => r.json())
            .then(data => {
                const el = document.getElementById('disputeCorrectionList');
                if (!data.success || !data.data || data.data.length === 0) {
                    el.innerHTML = '<p class="text-sm text-gray-500">No correction requests</p>';
                    return;
                }
                el.innerHTML = data.data.slice(0, 5).map(c => `
                    <div class="p-3 bg-gray-50 dark:bg-gray-700/50 rounded-lg">
                        <p class="text-sm font-medium text-gray-900 dark:text-white">${c.date}</p>
                        <p class="text-xs text-gray-500">${c.reason || ''} — ${(c.status || 'pending').toUpperCase()}</p>
                    </div>
                `).join('');
            })
            .catch(() => { document.getElementById('disputeCorrectionList').innerHTML = '<p class="text-sm text-gray-500">Unable to load</p>'; });
    </script>
<?php elseif ($view === 'download'): ?>
    <!-- Download Timesheet -->
    <div class="bg-white dark:bg-gray-800 rounded-xl shadow-sm border border-gray-100 dark:border-gray-700">
        <div class="px-5 py-4 border-b border-gray-100 dark:border-gray-700">
            <h2 class="text-sm font-semibold text-gray-900 dark:text-white">Download Timesheet</h2>
        </div>
        <div class="p-5">
            <?php
            $downloadSheets = [];
            if (isset($db) && is_numeric($employeeId)) {
                try {
                    $stmt = $db->prepare("SELECT id, period_start, period_end, total_hours, total_ot_hours, status FROM timesheets WHERE employee_id = ? ORDER BY period_start DESC LIMIT 12");
                    $stmt->execute([$employeeId]);
                    $downloadSheets = $stmt->fetchAll(PDO::FETCH_ASSOC);
                } catch (PDOException $e) {}
            }
            if (empty($downloadSheets)) {
                $downloadSheets = $timesheets ?? [];
            }
            ?>
            <p class="text-sm text-gray-600 dark:text-gray-400 mb-4">Select a timesheet period to download as CSV.</p>
            <div class="space-y-2">
                <?php foreach ($downloadSheets as $ts): ?>
                    <div class="flex items-center justify-between p-3 bg-gray-50 dark:bg-gray-700/50 rounded-lg">
                        <span class="text-sm text-gray-900 dark:text-white"><?php echo date('M d', strtotime($ts['period_start'])); ?> - <?php echo date('M d, Y', strtotime($ts['period_end'])); ?></span>
                        <a href="<?php echo BASE_URL; ?>/api/timesheets/export.php?timesheet_id=<?php echo $ts['id']; ?>&format=csv" class="px-3 py-1 text-xs bg-primary-600 text-white rounded hover:bg-primary-700">Download CSV</a>
                    </div>
                <?php endforeach; ?>
            </div>
            <?php if (empty($downloadSheets)): ?>
                <p class="text-sm text-gray-500 mt-4">No timesheets available to download.</p>
            <?php endif; ?>
        </div>
    </div>
<?php endif; ?>

<script>
    function addRow() {
        const tbody = document.querySelector('#hoursTable tbody');
        const newRow = document.createElement('tr');
        newRow.innerHTML = `
            <td class="px-6 py-4">
                <input type="date" class="w-full px-2 py-1 text-sm border border-gray-300 dark:border-gray-600 rounded dark:bg-gray-700 dark:text-white" value="${new Date().toISOString().split('T')[0]}">
            </td>
            <td class="px-6 py-4">
                <input type="number" step="0.25" class="w-full px-2 py-1 text-sm border border-gray-300 dark:border-gray-600 rounded dark:bg-gray-700 dark:text-white hours-input" value="8.00" onchange="calculateTotals()">
            </td>
            <td class="px-6 py-4">
                <input type="number" step="0.25" class="w-full px-2 py-1 text-sm border border-gray-300 dark:border-gray-600 rounded dark:bg-gray-700 dark:text-white ot-input" value="0.00" onchange="calculateTotals()">
            </td>
            <td class="px-6 py-4">
                <input type="text" class="w-full px-2 py-1 text-sm border border-gray-300 dark:border-gray-600 rounded dark:bg-gray-700 dark:text-white" placeholder="Notes...">
            </td>
            <td class="px-6 py-4">
                <button onclick="removeRow(this)" class="text-red-600 hover:text-red-700 text-xs">Remove</button>
            </td>
        `;
        tbody.appendChild(newRow);
        calculateTotals();
    }

    function removeRow(btn) {
        btn.closest('tr').remove();
        calculateTotals();
    }

    function calculateTotals() {
        const regularInputs = document.querySelectorAll('.hours-input');
        const otInputs = document.querySelectorAll('.ot-input');
        
        let totalRegular = 0;
        let totalOT = 0;
        
        regularInputs.forEach(input => {
            totalRegular += parseFloat(input.value) || 0;
        });
        
        otInputs.forEach(input => {
            totalOT += parseFloat(input.value) || 0;
        });
        
        document.getElementById('totalRegular').textContent = totalRegular.toFixed(2);
        document.getElementById('totalOT').textContent = totalOT.toFixed(2);
    }

    // Initialize totals on page load
    document.addEventListener('DOMContentLoaded', calculateTotals);
</script>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
