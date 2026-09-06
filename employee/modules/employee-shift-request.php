<?php
require_once __DIR__ . '/../../config/config.php';
requireAuth();

$page_title = 'Shift Change Request';

$employeeId = getCurrentEmployeeId($db ?? null);
if (!$employeeId) {
    $employeeId = $_SESSION['hr3_employee_id'] ?? $_SESSION['employee_id'] ?? null;
}

if (!$employeeId) {
    $_SESSION['error'] = 'Employee not found. Please log in again.';
    header('Location: ' . BASE_URL . '/auth/employee-login.php');
    exit;
}

$shifts = [];
$myRequests = [];
$currentShift = null;

if (isset($db)) {
    try {
        // Get all shift templates
        $stmt = $db->prepare("SELECT id, name, start_time, end_time FROM shift_templates ORDER BY start_time");
        $stmt->execute();
        $shifts = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Get employee's current shift assignment
        $stmt = $db->prepare("
            SELECT st.id, st.name, st.start_time, st.end_time 
            FROM shift_assignments sa
            JOIN shift_templates st ON sa.shift_template_id = st.id
            WHERE sa.employee_id = ? AND (sa.end_date IS NULL OR sa.end_date >= CURDATE())
            ORDER BY sa.start_date DESC LIMIT 1
        ");
        $stmt->execute([$employeeId]);
        $currentShift = $stmt->fetch(PDO::FETCH_ASSOC);
        
        // Get my shift change requests
        $stmt = $db->prepare("
            SELECT scr.*, 
                   cs.name as current_shift_name, cs.start_time as current_start, cs.end_time as current_end,
                   rs.name as requested_shift_name, rs.start_time as requested_start, rs.end_time as requested_end,
                   CONCAT(rev.first_name, ' ', rev.last_name) as reviewer_name
            FROM shift_change_requests scr
            JOIN shift_templates cs ON scr.current_shift_id = cs.id
            JOIN shift_templates rs ON scr.requested_shift_id = rs.id
            LEFT JOIN employees rev ON scr.reviewed_by = rev.id
            WHERE scr.employee_id = ?
            ORDER BY scr.created_at DESC
            LIMIT 20
        ");
        $stmt->execute([$employeeId]);
        $myRequests = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
    } catch (PDOException $e) {
        error_log('Shift request error: ' . $e->getMessage());
    }
}

include __DIR__ . '/../../includes/header.php';
?>

<div class="mb-6">
    <h1 class="text-2xl font-semibold text-gray-900 dark:text-white">Shift Change Request</h1>
    <p class="mt-1 text-sm text-gray-600 dark:text-gray-400">Request a change to your work shift schedule</p>
</div>

<?php if (isset($_SESSION['success'])): ?>
    <div class="mb-4 bg-emerald-50 dark:bg-emerald-900/20 border border-emerald-200 dark:border-emerald-800 text-emerald-800 dark:text-emerald-200 px-4 py-3 rounded-lg">
        <?php echo htmlspecialchars($_SESSION['success']); unset($_SESSION['success']); ?>
    </div>
<?php endif; ?>

<?php if (isset($_SESSION['error'])): ?>
    <div class="mb-4 bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-800 text-red-800 dark:text-red-200 px-4 py-3 rounded-lg">
        <?php echo htmlspecialchars($_SESSION['error']); unset($_SESSION['error']); ?>
    </div>
<?php endif; ?>

<div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
    <!-- Request Form -->
    <div class="lg:col-span-1">
        <div class="bg-white dark:bg-gray-800 rounded-xl shadow-sm border border-gray-100 dark:border-gray-700">
            <div class="px-5 py-4 border-b border-gray-100 dark:border-gray-700">
                <h2 class="text-sm font-semibold text-gray-900 dark:text-white">New Shift Change Request</h2>
            </div>
            <form id="shiftRequestForm" class="p-5 space-y-4">
                <!-- Current Shift -->
                <div>
                    <label class="block text-xs font-medium text-gray-700 dark:text-gray-300 mb-1">Current Shift <span class="text-red-500">*</span></label>
                    <select name="current_shift_id" id="current_shift_id" required class="w-full px-3 py-2 text-sm border border-gray-300 dark:border-gray-600 rounded-lg dark:bg-gray-700 dark:text-white">
                        <option value="">Select current shift</option>
                        <?php foreach ($shifts as $shift): ?>
                            <option value="<?php echo $shift['id']; ?>" <?php echo ($currentShift && $currentShift['id'] == $shift['id']) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($shift['name']); ?> (<?php echo date('g:i A', strtotime($shift['start_time'])); ?> - <?php echo date('g:i A', strtotime($shift['end_time'])); ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <!-- Requested Shift -->
                <div>
                    <label class="block text-xs font-medium text-gray-700 dark:text-gray-300 mb-1">Requested Shift <span class="text-red-500">*</span></label>
                    <select name="requested_shift_id" id="requested_shift_id" required class="w-full px-3 py-2 text-sm border border-gray-300 dark:border-gray-600 rounded-lg dark:bg-gray-700 dark:text-white">
                        <option value="">Select desired shift</option>
                        <?php foreach ($shifts as $shift): ?>
                            <option value="<?php echo $shift['id']; ?>">
                                <?php echo htmlspecialchars($shift['name']); ?> (<?php echo date('g:i A', strtotime($shift['start_time'])); ?> - <?php echo date('g:i A', strtotime($shift['end_time'])); ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <!-- Request Date -->
                <div>
                    <label class="block text-xs font-medium text-gray-700 dark:text-gray-300 mb-1">Effective Date <span class="text-red-500">*</span></label>
                    <input type="date" name="request_date" id="request_date" required 
                           min="<?php echo date('Y-m-d', strtotime('+1 day')); ?>"
                           class="w-full px-3 py-2 text-sm border border-gray-300 dark:border-gray-600 rounded-lg dark:bg-gray-700 dark:text-white">
                </div>
                
                <!-- Reason -->
                <div>
                    <label class="block text-xs font-medium text-gray-700 dark:text-gray-300 mb-1">Reason <span class="text-red-500">*</span></label>
                    <textarea name="reason" id="reason" required rows="3" placeholder="Please explain why you need this shift change..."
                              class="w-full px-3 py-2 text-sm border border-gray-300 dark:border-gray-600 rounded-lg dark:bg-gray-700 dark:text-white resize-none"></textarea>
                </div>
                
                <button type="submit" id="submitBtn" class="w-full px-4 py-2 bg-primary-600 text-white rounded-lg hover:bg-primary-700 text-sm font-medium transition-colors">
                    Submit Request
                </button>
            </form>
        </div>
    </div>
    
    <!-- My Requests -->
    <div class="lg:col-span-2">
        <div class="bg-white dark:bg-gray-800 rounded-xl shadow-sm border border-gray-100 dark:border-gray-700">
            <div class="px-5 py-4 border-b border-gray-100 dark:border-gray-700">
                <h2 class="text-sm font-semibold text-gray-900 dark:text-white">My Shift Change Requests</h2>
            </div>
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                    <thead class="bg-gray-50 dark:bg-gray-700">
                        <tr>
                            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase">Date</th>
                            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase">Current → Requested</th>
                            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase">Effective Date</th>
                            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase">Status</th>
                        </tr>
                    </thead>
                    <tbody class="bg-white dark:bg-gray-800 divide-y divide-gray-200 dark:divide-gray-700">
                        <?php if (empty($myRequests)): ?>
                            <tr>
                                <td colspan="4" class="px-4 py-8 text-center text-sm text-gray-500">No shift change requests yet</td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($myRequests as $req): ?>
                                <tr>
                                    <td class="px-4 py-3 whitespace-nowrap text-sm text-gray-900 dark:text-white">
                                        <?php echo date('M d, Y', strtotime($req['created_at'])); ?>
                                    </td>
                                    <td class="px-4 py-3 text-sm text-gray-900 dark:text-white">
                                        <span class="text-gray-500"><?php echo htmlspecialchars($req['current_shift_name']); ?></span>
                                        <span class="mx-1">→</span>
                                        <span class="font-medium"><?php echo htmlspecialchars($req['requested_shift_name']); ?></span>
                                    </td>
                                    <td class="px-4 py-3 whitespace-nowrap text-sm text-gray-900 dark:text-white">
                                        <?php echo date('M d, Y', strtotime($req['request_date'])); ?>
                                    </td>
                                    <td class="px-4 py-3 whitespace-nowrap text-sm">
                                        <?php
                                        $statusColors = [
                                            'pending' => 'bg-amber-100 text-amber-800 dark:bg-amber-900 dark:text-amber-200',
                                            'approved' => 'bg-emerald-100 text-emerald-800 dark:bg-emerald-900 dark:text-emerald-200',
                                            'rejected' => 'bg-red-100 text-red-800 dark:bg-red-900 dark:text-red-200'
                                        ];
                                        $color = $statusColors[$req['status']] ?? $statusColors['pending'];
                                        ?>
                                        <span class="px-2 py-1 text-xs font-semibold rounded-full <?php echo $color; ?>">
                                            <?php echo strtoupper($req['status']); ?>
                                        </span>
                                        <?php if ($req['reviewed_by'] && $req['status'] !== 'pending'): ?>
                                            <p class="text-xs text-gray-500 mt-1">by <?php echo htmlspecialchars($req['reviewer_name']); ?></p>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<!-- Toast -->
<div id="toast" class="hidden fixed bottom-4 right-4 z-50 max-w-sm">
    <div id="toastInner" class="flex items-center gap-3 px-4 py-3 rounded-lg shadow-lg border text-sm"></div>
</div>

<script>
(function() {
    var base = window.location.origin;
    
    function showToast(msg, isError) {
        var el = document.getElementById('toast');
        var inner = document.getElementById('toastInner');
        if (!inner) return;
        inner.textContent = msg;
        inner.className = 'flex items-center gap-3 px-4 py-3 rounded-lg shadow-lg border text-sm ' +
            (isError ? 'bg-red-50 dark:bg-red-900/40 border-red-200 text-red-800 dark:text-red-200' : 'bg-emerald-50 dark:bg-emerald-900/40 border-emerald-200 text-emerald-800 dark:text-emerald-200');
        if (el) { el.classList.remove('hidden'); setTimeout(function() { el.classList.add('hidden'); }, 4000); }
    }
    
    document.getElementById('shiftRequestForm').addEventListener('submit', function(e) {
        e.preventDefault();
        
        var currentShift = document.getElementById('current_shift_id').value;
        var requestedShift = document.getElementById('requested_shift_id').value;
        var requestDate = document.getElementById('request_date').value;
        var reason = document.getElementById('reason').value.trim();
        
        if (!currentShift || !requestedShift || !requestDate || !reason) {
            showToast('Please fill in all required fields', true);
            return;
        }
        
        if (currentShift === requestedShift) {
            showToast('Current and requested shift cannot be the same', true);
            return;
        }
        
        var btn = document.getElementById('submitBtn');
        btn.disabled = true;
        btn.textContent = 'Submitting...';
        
        fetch(base + '/api/shifts/request_change.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({
                current_shift_id: currentShift,
                requested_shift_id: requestedShift,
                request_date: requestDate,
                reason: reason
            }),
            credentials: 'same-origin'
        })
        .then(function(r) { return r.json(); })
        .then(function(data) {
            if (data.success) {
                showToast(data.message || 'Request submitted successfully');
                setTimeout(function() { location.reload(); }, 1500);
            } else {
                showToast(data.message || 'Error submitting request', true);
                btn.disabled = false;
                btn.textContent = 'Submit Request';
            }
        })
        .catch(function() {
            showToast('Network error. Please try again.', true);
            btn.disabled = false;
            btn.textContent = 'Submit Request';
        });
    });
})();
</script>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
