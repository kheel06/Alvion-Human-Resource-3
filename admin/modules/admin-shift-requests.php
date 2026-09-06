<?php
require_once __DIR__ . '/../../config/config.php';
requireAuth();
checkRole(['admin', 'super admin', 'supervisor', 'unit_head', 'hr_admin']);

$page_title = 'Shift Change Requests';

$pendingRequests = [];
$processedRequests = [];
$metrics = [
    'pending' => 0,
    'approved_today' => 0,
    'rejected_today' => 0
];

if (isset($db)) {
    try {
        // Get metrics
        $stmt = $db->prepare("SELECT COUNT(*) FROM shift_change_requests WHERE status = 'pending'");
        $stmt->execute();
        $metrics['pending'] = (int)$stmt->fetchColumn();
        
        $stmt = $db->prepare("SELECT COUNT(*) FROM shift_change_requests WHERE status = 'approved' AND DATE(reviewed_at) = CURDATE()");
        $stmt->execute();
        $metrics['approved_today'] = (int)$stmt->fetchColumn();
        
        $stmt = $db->prepare("SELECT COUNT(*) FROM shift_change_requests WHERE status = 'rejected' AND DATE(reviewed_at) = CURDATE()");
        $stmt->execute();
        $metrics['rejected_today'] = (int)$stmt->fetchColumn();
        
        // Get pending requests
        $stmt = $db->prepare("
            SELECT scr.*, 
                   CONCAT(e.first_name, ' ', e.last_name) as employee_name,
                   e.employee_number,
                   cs.name as current_shift_name, cs.start_time as current_start, cs.end_time as current_end,
                   rs.name as requested_shift_name, rs.start_time as requested_start, rs.end_time as requested_end
            FROM shift_change_requests scr
            JOIN employees e ON scr.employee_id = e.id
            JOIN shift_templates cs ON scr.current_shift_id = cs.id
            JOIN shift_templates rs ON scr.requested_shift_id = rs.id
            WHERE scr.status = 'pending'
            ORDER BY scr.created_at ASC
        ");
        $stmt->execute();
        $pendingRequests = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Get recently processed requests
        $stmt = $db->prepare("
            SELECT scr.*, 
                   CONCAT(e.first_name, ' ', e.last_name) as employee_name,
                   e.employee_number,
                   cs.name as current_shift_name,
                   rs.name as requested_shift_name,
                   CONCAT(rev.first_name, ' ', rev.last_name) as reviewer_name
            FROM shift_change_requests scr
            JOIN employees e ON scr.employee_id = e.id
            JOIN shift_templates cs ON scr.current_shift_id = cs.id
            JOIN shift_templates rs ON scr.requested_shift_id = rs.id
            LEFT JOIN employees rev ON scr.reviewed_by = rev.id
            WHERE scr.status IN ('approved', 'rejected')
            ORDER BY scr.reviewed_at DESC
            LIMIT 20
        ");
        $stmt->execute();
        $processedRequests = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
    } catch (PDOException $e) {
        error_log('Admin shift requests error: ' . $e->getMessage());
    }
}

include __DIR__ . '/../../includes/header.php';
?>

<div class="mb-6">
    <h1 class="text-2xl font-semibold text-gray-900 dark:text-white">Shift Change Requests</h1>
    <p class="mt-1 text-sm text-gray-600 dark:text-gray-400">Review and manage employee shift change requests</p>
</div>

<!-- Metrics Cards -->
<div class="grid grid-cols-1 sm:grid-cols-3 gap-4 mb-6">
    <div class="bg-white dark:bg-gray-800 rounded-xl shadow-sm border border-gray-100 dark:border-gray-700 p-5">
        <div class="flex items-center justify-between">
            <div>
                <p class="text-xs font-medium text-gray-500 dark:text-gray-400 uppercase">Pending Requests</p>
                <p class="mt-2 text-2xl font-bold text-gray-900 dark:text-white"><?php echo $metrics['pending']; ?></p>
            </div>
            <div class="w-12 h-12 rounded-lg bg-amber-100 dark:bg-amber-900 flex items-center justify-center">
                <svg xmlns="http://www.w3.org/2000/svg" class="w-6 h-6 text-amber-600 dark:text-amber-300" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" />
                </svg>
            </div>
        </div>
    </div>
    
    <div class="bg-white dark:bg-gray-800 rounded-xl shadow-sm border border-gray-100 dark:border-gray-700 p-5">
        <div class="flex items-center justify-between">
            <div>
                <p class="text-xs font-medium text-gray-500 dark:text-gray-400 uppercase">Approved Today</p>
                <p class="mt-2 text-2xl font-bold text-emerald-600"><?php echo $metrics['approved_today']; ?></p>
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
                <p class="text-xs font-medium text-gray-500 dark:text-gray-400 uppercase">Rejected Today</p>
                <p class="mt-2 text-2xl font-bold text-red-600"><?php echo $metrics['rejected_today']; ?></p>
            </div>
            <div class="w-12 h-12 rounded-lg bg-red-100 dark:bg-red-900 flex items-center justify-center">
                <svg xmlns="http://www.w3.org/2000/svg" class="w-6 h-6 text-red-600 dark:text-red-300" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 14l2-2m0 0l2-2m-2 2l-2-2m2 2l2 2m7-2a9 9 0 11-18 0 9 9 0 0118 0z" />
                </svg>
            </div>
        </div>
    </div>
</div>

<!-- Pending Requests -->
<div class="bg-white dark:bg-gray-800 rounded-xl shadow-sm border border-gray-100 dark:border-gray-700 mb-6">
    <div class="px-5 py-4 border-b border-gray-100 dark:border-gray-700">
        <h2 class="text-sm font-semibold text-gray-900 dark:text-white">Pending Requests</h2>
    </div>
    <div class="overflow-x-auto">
        <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
            <thead class="bg-gray-50 dark:bg-gray-700">
                <tr>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase">Employee</th>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase">Current Shift</th>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase">Requested Shift</th>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase">Effective Date</th>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase">Reason</th>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase">Actions</th>
                </tr>
            </thead>
            <tbody class="bg-white dark:bg-gray-800 divide-y divide-gray-200 dark:divide-gray-700">
                <?php if (empty($pendingRequests)): ?>
                    <tr>
                        <td colspan="6" class="px-4 py-8 text-center text-sm text-gray-500">No pending shift change requests</td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($pendingRequests as $req): ?>
                        <tr data-request-id="<?php echo $req['id']; ?>">
                            <td class="px-4 py-3 whitespace-nowrap">
                                <div class="text-sm font-medium text-gray-900 dark:text-white"><?php echo htmlspecialchars($req['employee_name']); ?></div>
                                <div class="text-xs text-gray-500"><?php echo htmlspecialchars($req['employee_number']); ?></div>
                            </td>
                            <td class="px-4 py-3 whitespace-nowrap">
                                <div class="text-sm text-gray-900 dark:text-white"><?php echo htmlspecialchars($req['current_shift_name']); ?></div>
                                <div class="text-xs text-gray-500"><?php echo date('g:i A', strtotime($req['current_start'])); ?> - <?php echo date('g:i A', strtotime($req['current_end'])); ?></div>
                            </td>
                            <td class="px-4 py-3 whitespace-nowrap">
                                <div class="text-sm font-medium text-primary-600"><?php echo htmlspecialchars($req['requested_shift_name']); ?></div>
                                <div class="text-xs text-gray-500"><?php echo date('g:i A', strtotime($req['requested_start'])); ?> - <?php echo date('g:i A', strtotime($req['requested_end'])); ?></div>
                            </td>
                            <td class="px-4 py-3 whitespace-nowrap text-sm text-gray-900 dark:text-white">
                                <?php echo date('M d, Y', strtotime($req['request_date'])); ?>
                            </td>
                            <td class="px-4 py-3 text-sm text-gray-900 dark:text-white max-w-xs">
                                <p class="truncate" title="<?php echo htmlspecialchars($req['reason']); ?>"><?php echo htmlspecialchars(mb_strimwidth($req['reason'], 0, 50, '...')); ?></p>
                            </td>
                            <td class="px-4 py-3 whitespace-nowrap text-sm">
                                <div class="flex items-center gap-1">
                                    <button type="button" onclick="approveRequest(<?php echo $req['id']; ?>)" 
                                            class="p-1.5 text-emerald-600 hover:text-emerald-700 hover:bg-emerald-50 dark:hover:bg-emerald-900/30 rounded-lg transition-colors" title="Approve">
                                        <svg xmlns="http://www.w3.org/2000/svg" class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" />
                                        </svg>
                                    </button>
                                    <button type="button" onclick="rejectRequest(<?php echo $req['id']; ?>)" 
                                            class="p-1.5 text-red-600 hover:text-red-700 hover:bg-red-50 dark:hover:bg-red-900/30 rounded-lg transition-colors" title="Reject">
                                        <svg xmlns="http://www.w3.org/2000/svg" class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                                        </svg>
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

<!-- Recently Processed -->
<div class="bg-white dark:bg-gray-800 rounded-xl shadow-sm border border-gray-100 dark:border-gray-700">
    <div class="px-5 py-4 border-b border-gray-100 dark:border-gray-700">
        <h2 class="text-sm font-semibold text-gray-900 dark:text-white">Recently Processed</h2>
    </div>
    <div class="overflow-x-auto">
        <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
            <thead class="bg-gray-50 dark:bg-gray-700">
                <tr>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase">Employee</th>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase">Shift Change</th>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase">Status</th>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase">Reviewed By</th>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase">Date</th>
                </tr>
            </thead>
            <tbody class="bg-white dark:bg-gray-800 divide-y divide-gray-200 dark:divide-gray-700">
                <?php if (empty($processedRequests)): ?>
                    <tr>
                        <td colspan="5" class="px-4 py-8 text-center text-sm text-gray-500">No processed requests yet</td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($processedRequests as $req): ?>
                        <tr>
                            <td class="px-4 py-3 whitespace-nowrap">
                                <div class="text-sm font-medium text-gray-900 dark:text-white"><?php echo htmlspecialchars($req['employee_name']); ?></div>
                                <div class="text-xs text-gray-500"><?php echo htmlspecialchars($req['employee_number']); ?></div>
                            </td>
                            <td class="px-4 py-3 whitespace-nowrap text-sm text-gray-900 dark:text-white">
                                <?php echo htmlspecialchars($req['current_shift_name']); ?> → <?php echo htmlspecialchars($req['requested_shift_name']); ?>
                            </td>
                            <td class="px-4 py-3 whitespace-nowrap text-sm">
                                <?php
                                $statusColors = [
                                    'approved' => 'bg-emerald-100 text-emerald-800 dark:bg-emerald-900 dark:text-emerald-200',
                                    'rejected' => 'bg-red-100 text-red-800 dark:bg-red-900 dark:text-red-200'
                                ];
                                $color = $statusColors[$req['status']] ?? '';
                                ?>
                                <span class="px-2 py-1 text-xs font-semibold rounded-full <?php echo $color; ?>">
                                    <?php echo strtoupper($req['status']); ?>
                                </span>
                            </td>
                            <td class="px-4 py-3 whitespace-nowrap text-sm text-gray-900 dark:text-white">
                                <?php echo htmlspecialchars($req['reviewer_name'] ?? 'System'); ?>
                            </td>
                            <td class="px-4 py-3 whitespace-nowrap text-sm text-gray-500">
                                <?php echo $req['reviewed_at'] ? date('M d, Y g:i A', strtotime($req['reviewed_at'])) : '-'; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
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
    
    function removeRow(requestId) {
        var row = document.querySelector('tr[data-request-id="' + requestId + '"]');
        if (row) {
            row.style.opacity = '0';
            row.style.transition = 'opacity 0.3s';
            setTimeout(function() { row.remove(); }, 300);
        }
    }
    
    window.approveRequest = function(requestId) {
        if (!confirm('Approve this shift change request?')) return;
        
        fetch(base + '/api/shifts/approve.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({request_id: requestId, notes: ''}),
            credentials: 'same-origin'
        })
        .then(function(r) { return r.json(); })
        .then(function(data) {
            if (data.success) {
                showToast(data.message || 'Request approved');
                removeRow(requestId);
                setTimeout(function() { location.reload(); }, 1500);
            } else {
                showToast(data.message || 'Error approving request', true);
            }
        })
        .catch(function() {
            showToast('Network error. Please try again.', true);
        });
    };
    
    window.rejectRequest = function(requestId) {
        var reason = prompt('Rejection reason (optional):');
        if (reason === null) return;
        
        fetch(base + '/api/shifts/reject.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({request_id: requestId, notes: reason || ''}),
            credentials: 'same-origin'
        })
        .then(function(r) { return r.json(); })
        .then(function(data) {
            if (data.success) {
                showToast(data.message || 'Request rejected');
                removeRow(requestId);
                setTimeout(function() { location.reload(); }, 1500);
            } else {
                showToast(data.message || 'Error rejecting request', true);
            }
        })
        .catch(function() {
            showToast('Network error. Please try again.', true);
        });
    };
})();
</script>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
