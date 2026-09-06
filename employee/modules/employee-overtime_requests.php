<?php
require_once __DIR__ . '/../../config/config.php';
requireAuth();
checkRole(['employee', 'supervisor', 'unit_head']);

$page_title = 'Overtime Request';
$employeeId = getCurrentEmployeeId($db ?? null);
if (!$employeeId) {
    $employeeId = $_SESSION['employee_id'] ?? $_SESSION['user_id'] ?? null;
}
if (!$employeeId) {
    $_SESSION['error'] = "Employee identifier not found. Please log in again.";
    header("Location: " . BASE_URL . "/auth/employee-login.php");
    exit();
}

$otRequests = [];
if (isset($db)) {
    try {
        $tables = $db->query("SHOW TABLES LIKE 'overtime_requests'");
        if ($tables && $tables->rowCount() > 0) {
            $stmt = $db->prepare("
                SELECT id, request_date, start_time, end_time, hours, reason, status, created_at
                FROM overtime_requests
                WHERE employee_id = ?
                ORDER BY created_at DESC
                LIMIT 30
            ");
            $stmt->execute([$employeeId]);
            $otRequests = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }
    } catch (PDOException $e) {}
}

include __DIR__ . '/../../includes/header.php';
?>

<div class="mb-6">
    <h1 class="text-2xl font-semibold text-gray-900 dark:text-white">Overtime Request</h1>
    <p class="mt-1 text-sm text-gray-600 dark:text-gray-400">Request overtime approval for work beyond scheduled hours</p>
</div>

<div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
    <div class="bg-white dark:bg-gray-800 rounded-xl shadow-sm border border-gray-100 dark:border-gray-700">
        <div class="px-5 py-4 border-b border-gray-100 dark:border-gray-700">
            <h2 class="text-sm font-semibold text-gray-900 dark:text-white">New Overtime Request</h2>
        </div>
        <div class="p-5">
            <form id="otForm" class="space-y-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Date</label>
                    <input type="date" name="request_date" required class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-700 text-gray-900 dark:text-white">
                </div>
                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Start Time</label>
                        <input type="time" name="start_time" required class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-700 text-gray-900 dark:text-white">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">End Time</label>
                        <input type="time" name="end_time" required class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-700 text-gray-900 dark:text-white">
                    </div>
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Reason</label>
                    <textarea name="reason" required minlength="10" rows="3" placeholder="Describe the reason for overtime..." class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-700 text-gray-900 dark:text-white"></textarea>
                </div>
                <button type="submit" id="otSubmitBtn" class="w-full px-4 py-2 bg-primary-600 text-white rounded-lg hover:bg-primary-700">Submit Request</button>
            </form>
        </div>
    </div>
    <div class="bg-white dark:bg-gray-800 rounded-xl shadow-sm border border-gray-100 dark:border-gray-700">
        <div class="px-5 py-4 border-b border-gray-100 dark:border-gray-700">
            <h2 class="text-sm font-semibold text-gray-900 dark:text-white">My Overtime Requests</h2>
        </div>
        <div class="p-5">
            <?php if (empty($otRequests)): ?>
                <p class="text-sm text-gray-500 dark:text-gray-400">No overtime requests yet. Submit a request to see it here.</p>
            <?php else: ?>
                <div class="space-y-3">
                    <?php foreach ($otRequests as $r): ?>
                        <div class="p-3 bg-gray-50 dark:bg-gray-700/50 rounded-lg">
                            <div class="flex justify-between items-start">
                                <div>
                                    <p class="text-sm font-medium text-gray-900 dark:text-white"><?php echo date('M d, Y', strtotime($r['request_date'])); ?></p>
                                    <p class="text-xs text-gray-500"><?php echo htmlspecialchars($r['start_time'] ?? ''); ?> - <?php echo htmlspecialchars($r['end_time'] ?? ''); ?> (<?php echo $r['hours'] ?? 'N/A'; ?> hrs)</p>
                                    <p class="text-xs text-gray-600 dark:text-gray-400 mt-1"><?php echo htmlspecialchars($r['reason'] ?? ''); ?></p>
                                </div>
                                <span class="px-2 py-1 text-xs font-semibold rounded-full <?php 
                                    echo ($r['status'] ?? '') === 'approved' ? 'bg-emerald-100 text-emerald-800 dark:bg-emerald-900 dark:text-emerald-200' : 
                                        (($r['status'] ?? '') === 'rejected' ? 'bg-red-100 text-red-800 dark:bg-red-900 dark:text-red-200' : 'bg-amber-100 text-amber-800 dark:bg-amber-900 dark:text-amber-200'); ?>">
                                    <?php echo strtoupper($r['status'] ?? 'pending'); ?>
                                </span>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<script>
document.getElementById('otForm')?.addEventListener('submit', function(e) {
    e.preventDefault();
    const form = this;
    const btn = document.getElementById('otSubmitBtn');
    const start = form.querySelector('[name="start_time"]').value;
    const end = form.querySelector('[name="end_time"]').value;
    const payload = {
        request_date: form.querySelector('[name="request_date"]').value,
        start_time: start,
        end_time: end,
        reason: form.querySelector('[name="reason"]').value
    };
    btn.disabled = true;
    btn.textContent = 'Submitting...';
    fetch('<?php echo BASE_URL; ?>/api/scheduling/overtime_requests.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(payload)
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) { alert('Overtime request submitted.'); form.reset(); location.reload(); }
        else alert(data.message || 'Failed to submit');
    })
    .catch(() => alert('An error occurred.'))
    .finally(() => { btn.disabled = false; btn.textContent = 'Submit Request'; });
});
</script>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
