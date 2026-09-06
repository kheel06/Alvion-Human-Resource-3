<?php
require_once __DIR__ . '/../../config/config.php';
requireAuth();
checkRole(['admin', 'super admin', 'supervisor', 'unit_head', 'hr_admin', 'finance']);

$page_title = 'Claims & Reimbursement';
$view = $_GET['view'] ?? 'claim_review_queue';

$claimTypes = [];
$reviewQueue = [];
$auditTrail = [];
$metrics = [
    'pending_claims' => 0,
    'approved_today' => 0,
    'total_amount' => 0,
    'paid_this_month' => 0
];

if (isset($db)) {
    try {
        // Get pending claims count
        $stmt = $db->prepare("SELECT COUNT(*) as cnt FROM claims WHERE status IN ('submitted', 'endorsed')");
        $stmt->execute();
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        $metrics['pending_claims'] = (int)($result['cnt'] ?? 0);
        
        // Get total amount
        $stmt = $db->prepare("SELECT SUM(amount) as total FROM claims WHERE status IN ('submitted', 'endorsed')");
        $stmt->execute();
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        $metrics['total_amount'] = (float)($result['total'] ?? 0);
        
        // Get approved today
        $stmt = $db->prepare("SELECT COUNT(*) as cnt FROM claims WHERE status = 'approved' AND DATE(approved_at) = CURDATE()");
        $stmt->execute();
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        $metrics['approved_today'] = (int)($result['cnt'] ?? 0);
        
        // Get paid this month
        $stmt = $db->prepare("SELECT SUM(amount) as total FROM claims WHERE status = 'paid' AND MONTH(paid_at) = MONTH(CURDATE())");
        $stmt->execute();
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        $metrics['paid_this_month'] = (float)($result['total'] ?? 0);
        
        // Get claim categories
        if ($view === 'claim_types_limits') {
            $stmt = $db->prepare("SELECT id, code, name, max_amount, requires_receipt, approval_route FROM claim_categories ORDER BY name");
            $stmt->execute();
            $claimTypes = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Handle claim category update
            if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_limits') {
                foreach ($_POST['limits'] as $typeId => $limitData) {
                    $stmt = $db->prepare("
                        UPDATE claim_categories 
                        SET max_amount = :limit, requires_receipt = :receipt, updated_at = NOW()
                        WHERE id = :id
                    ");
                    $stmt->execute([
                        ':limit' => (float)$limitData['amount'],
                        ':receipt' => isset($limitData['requires_receipt']) ? 1 : 0,
                        ':id' => (int)$typeId
                    ]);
                }
                $_SESSION['success'] = 'Claim limits updated successfully';
                header('Location: ' . $_SERVER['PHP_SELF'] . '?view=claim_types_limits');
                exit;
            }
            // Handle add new claim type
            if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'create_claim_type') {
                $name = trim($_POST['name'] ?? '');
                $code = strtoupper(trim(preg_replace('/[^A-Za-z0-9_]/', '', $_POST['code'] ?? '')));
                $maxAmount = isset($_POST['max_amount']) && $_POST['max_amount'] !== '' ? (float)$_POST['max_amount'] : null;
                $requiresReceipt = isset($_POST['requires_receipt']) ? 1 : 0;
                $approvalRoute = in_array($_POST['approval_route'] ?? '', ['supervisor', 'hr', 'finance', 'supervisor_hr', 'supervisor_finance']) ? $_POST['approval_route'] : 'supervisor';
                if ($name !== '' && $code !== '') {
                    try {
                        $stmt = $db->prepare("INSERT INTO claim_categories (name, code, max_amount, requires_receipt, approval_route) VALUES (:name, :code, :max_amount, :requires_receipt, :approval_route)");
                        $stmt->execute([
                            ':name' => $name,
                            ':code' => $code,
                            ':max_amount' => $maxAmount,
                            ':requires_receipt' => $requiresReceipt,
                            ':approval_route' => $approvalRoute
                        ]);
                        $_SESSION['success'] = 'Claim type added successfully.';
                    } catch (PDOException $e) {
                        if ($e->getCode() == 23000) {
                            $_SESSION['error'] = 'A claim type with that code already exists.';
                        } else {
                            $_SESSION['error'] = 'Could not add claim type.';
                            error_log('Add claim type: ' . $e->getMessage());
                        }
                    }
                } else {
                    $_SESSION['error'] = 'Name and code are required.';
                }
                header('Location: ' . $_SERVER['PHP_SELF'] . '?view=claim_types_limits');
                exit;
            }
        }
        
        // Get review queue
        if ($view === 'claim_review_queue') {
            $stmt = $db->prepare("
                SELECT c.id, c.employee_id, c.category_id, c.amount, c.currency, c.description, c.status,
                       c.submitted_at, c.created_at,
                       cc.name as category_name, cc.code as category_code,
                       e.first_name, e.last_name, e.employee_number,
                       (SELECT COUNT(*) FROM claim_attachments WHERE claim_id = c.id) as attachment_count,
                       (SELECT GROUP_CONCAT(CONCAT(id, '|', file_path, '|', COALESCE(file_name, '')) SEPARATOR ';;') FROM claim_attachments WHERE claim_id = c.id) as attachments_data
                FROM claims c
                JOIN employees e ON c.employee_id = e.id
                LEFT JOIN claim_categories cc ON c.category_id = cc.id
                WHERE c.status IN ('submitted', 'endorsed')
                ORDER BY COALESCE(c.submitted_at, c.created_at) DESC
                LIMIT 50
            ");
            $stmt->execute();
            $reviewQueue = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }
        
        // Get disbursement data
        if ($view === 'disbursement_status') {
            $stmt = $db->prepare("
                SELECT c.id, c.employee_id, c.category_id, c.amount, c.currency, c.status,
                       c.approved_at, c.paid_at, c.payment_reference,
                       cc.name as category_name,
                       e.first_name, e.last_name, e.employee_number
                FROM claims c
                JOIN employees e ON c.employee_id = e.id
                LEFT JOIN claim_categories cc ON c.category_id = cc.id
                WHERE c.status IN ('approved', 'paid')
                ORDER BY c.approved_at DESC
                LIMIT 50
            ");
            $stmt->execute();
            $disbursements = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }
        
        // Get audit trail
        if ($view === 'audit_trail') {
            $stmt = $db->prepare("
                SELECT al.*, e.first_name, e.last_name, e.employee_number
                FROM audit_logs al
                LEFT JOIN employees e ON al.employee_id = e.id
                WHERE al.table_name = 'claims'
                ORDER BY al.created_at DESC
                LIMIT 100
            ");
            $stmt->execute();
            $auditTrail = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }
    } catch (PDOException $e) {
        error_log('Admin Claims error: ' . $e->getMessage());
    }
}

include __DIR__ . '/../../includes/header.php';
?>

<div class="mb-6">
    <h1 class="text-2xl font-semibold text-gray-900 dark:text-white">Claims & Reimbursement</h1>
    <p class="mt-1 text-sm text-gray-600 dark:text-gray-400">Claim review, limits configuration, and audit trail</p>
</div>

<!-- Metrics Cards -->
<div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4 mb-6">
    <div class="bg-white dark:bg-gray-800 rounded-xl shadow-sm border border-gray-100 dark:border-gray-700 p-5">
        <div class="flex items-center justify-between">
            <div>
                <p class="text-xs font-medium text-gray-500 dark:text-gray-400 uppercase">Pending Claims</p>
                <p class="mt-2 text-2xl font-bold text-gray-900 dark:text-white"><?php echo $metrics['pending_claims']; ?></p>
                <p class="mt-1 text-xs text-amber-600">needs review</p>
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
                <p class="text-xs font-medium text-gray-500 dark:text-gray-400 uppercase">Total Amount</p>
                <p class="mt-2 text-2xl font-bold text-gray-900 dark:text-white">PHP <?php echo number_format($metrics['total_amount'], 0); ?></p>
                <p class="mt-1 text-xs text-gray-500">pending</p>
            </div>
            <div class="w-12 h-12 rounded-lg bg-blue-100 dark:bg-blue-900 flex items-center justify-center">
                <svg xmlns="http://www.w3.org/2000/svg" class="w-6 h-6 text-blue-600 dark:text-blue-300" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                </svg>
            </div>
        </div>
    </div>

    <div class="bg-white dark:bg-gray-800 rounded-xl shadow-sm border border-gray-100 dark:border-gray-700 p-5">
        <div class="flex items-center justify-between">
            <div>
                <p class="text-xs font-medium text-gray-500 dark:text-gray-400 uppercase">Approved Today</p>
                <p class="mt-2 text-2xl font-bold text-gray-900 dark:text-white"><?php echo $metrics['approved_today']; ?></p>
                <p class="mt-1 text-xs text-emerald-600">claims</p>
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
                <p class="text-xs font-medium text-gray-500 dark:text-gray-400 uppercase">Paid This Month</p>
                <p class="mt-2 text-2xl font-bold text-gray-900 dark:text-white">PHP <?php echo number_format($metrics['paid_this_month'], 0); ?></p>
                <p class="mt-1 text-xs text-gray-500">disbursed</p>
            </div>
            <div class="w-12 h-12 rounded-lg bg-purple-100 dark:bg-purple-900 flex items-center justify-center">
                <svg xmlns="http://www.w3.org/2000/svg" class="w-6 h-6 text-purple-600 dark:text-purple-300" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 9V7a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2m2 4h10a2 2 0 002-2v-6a2 2 0 00-2-2H9a2 2 0 00-2 2v2a2 2 0 002 2zm7-5a2 2 0 11-4 0 2 2 0 014 0z" />
                </svg>
            </div>
        </div>
    </div>
</div>

<?php if ($view === 'claim_types_limits'): ?>
    <!-- Modal: Add Claim Type -->
    <div id="addClaimTypeModal" class="hidden fixed inset-0 bg-black bg-opacity-50 z-50 flex items-center justify-center p-4" onclick="if(event.target===this) closeClaimTypeModal()">
        <div class="bg-white dark:bg-gray-800 rounded-xl shadow-xl max-w-md w-full" onclick="event.stopPropagation()">
            <div class="px-5 py-4 border-b border-gray-100 dark:border-gray-700 flex items-center justify-between">
                <h3 class="text-sm font-semibold text-gray-900 dark:text-white">Add Claim Type</h3>
                <button type="button" onclick="closeClaimTypeModal()" class="text-gray-400 hover:text-gray-600 dark:hover:text-gray-300 text-lg">&times;</button>
            </div>
            <form method="post" action="">
                <input type="hidden" name="action" value="create_claim_type">
                <div class="p-5 space-y-4">
                    <div>
                        <label class="block text-xs font-medium text-gray-700 dark:text-gray-300 mb-1">Name <span class="text-red-500">*</span></label>
                        <input type="text" name="name" required maxlength="100" placeholder="e.g. Meal Allowance" class="w-full px-3 py-2 text-sm border border-gray-300 dark:border-gray-600 rounded-lg dark:bg-gray-700 dark:text-white">
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-gray-700 dark:text-gray-300 mb-1">Code <span class="text-red-500">*</span></label>
                        <input type="text" name="code" required maxlength="50" placeholder="e.g. MEAL" class="w-full px-3 py-2 text-sm border border-gray-300 dark:border-gray-600 rounded-lg dark:bg-gray-700 dark:text-white" pattern="[A-Za-z0-9_]+" title="Letters, numbers, and underscore only">
                        <p class="mt-1 text-xs text-gray-500">Unique code (letters, numbers, underscore). Stored in uppercase.</p>
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-gray-700 dark:text-gray-300 mb-1">Max Amount (PHP)</label>
                        <input type="number" name="max_amount" step="0.01" min="0" placeholder="Optional" class="w-full px-3 py-2 text-sm border border-gray-300 dark:border-gray-600 rounded-lg dark:bg-gray-700 dark:text-white">
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-gray-700 dark:text-gray-300 mb-1">Approval Route</label>
                        <select name="approval_route" class="w-full px-3 py-2 text-sm border border-gray-300 dark:border-gray-600 rounded-lg dark:bg-gray-700 dark:text-white">
                            <option value="supervisor">Supervisor</option>
                            <option value="hr">HR</option>
                            <option value="finance">Finance</option>
                            <option value="supervisor_hr">Supervisor then HR</option>
                            <option value="supervisor_finance">Supervisor then Finance</option>
                        </select>
                    </div>
                    <div>
                        <label class="flex items-center">
                            <input type="checkbox" name="requires_receipt" value="1" checked class="rounded border-gray-300 dark:border-gray-600">
                            <span class="ml-2 text-xs text-gray-600 dark:text-gray-400">Requires receipt</span>
                        </label>
                    </div>
                </div>
                <div class="px-5 py-4 border-t border-gray-100 dark:border-gray-700 flex justify-end gap-2">
                    <button type="button" onclick="closeClaimTypeModal()" class="px-4 py-2 text-sm border border-gray-300 dark:border-gray-600 rounded-lg hover:bg-gray-50 dark:hover:bg-gray-700 text-gray-700 dark:text-gray-300">Cancel</button>
                    <button type="submit" class="px-4 py-2 text-sm bg-primary-600 text-white rounded-lg hover:bg-primary-700">Add Claim Type</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Claim Types & Limits -->
    <div class="bg-white dark:bg-gray-800 rounded-xl shadow-sm border border-gray-100 dark:border-gray-700 mb-6">
        <div class="px-5 py-4 border-b border-gray-100 dark:border-gray-700 flex items-center justify-between">
            <h2 class="text-sm font-semibold text-gray-900 dark:text-white">Claim Types & Limits</h2>
            <button type="button" onclick="openClaimTypeModal()" class="px-4 py-2 text-xs bg-primary-600 text-white rounded hover:bg-primary-700">
                Add Claim Type
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
        <div class="p-5">
            <form method="POST">
                <input type="hidden" name="action" value="update_limits">
                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                    <?php if (empty($claimTypes)): ?>
                        <div class="col-span-2 text-center py-8 text-sm text-gray-500">No claim types found</div>
                    <?php else: ?>
                        <?php foreach ($claimTypes as $type): ?>
                            <div class="p-4 bg-gray-50 dark:bg-gray-700/50 rounded-lg">
                                <h3 class="text-sm font-semibold text-gray-900 dark:text-white mb-2"><?php echo htmlspecialchars($type['name']); ?></h3>
                                <p class="text-xs text-gray-500 mb-3">Code: <?php echo htmlspecialchars($type['code']); ?> | Route: <?php echo htmlspecialchars($type['approval_route'] ?? 'supervisor'); ?></p>
                                <div class="space-y-2">
                                    <div>
                                        <label class="block text-xs text-gray-600 dark:text-gray-400 mb-1">
                                            Max Amount (PHP)
                                        </label>
                                        <input type="number" step="0.01" name="limits[<?php echo $type['id']; ?>][amount]" 
                                               value="<?php echo $type['max_amount'] ? number_format((float)$type['max_amount'], 2, '.', '') : '0.00'; ?>" 
                                               required class="w-full px-3 py-2 text-sm border border-gray-300 dark:border-gray-600 rounded dark:bg-gray-700 dark:text-white">
                                    </div>
                                    <div>
                                        <label class="flex items-center">
                                            <input type="checkbox" name="limits[<?php echo $type['id']; ?>][requires_receipt]" value="1" 
                                                   class="rounded border-gray-300" <?php echo $type['requires_receipt'] ? 'checked' : ''; ?>>
                                            <span class="ml-2 text-xs text-gray-600 dark:text-gray-400">Requires Receipt</span>
                                        </label>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
                <div class="mt-6">
                    <button type="submit" class="px-4 py-2 bg-primary-600 text-white rounded-lg hover:bg-primary-700">
                        Save Limits
                    </button>
                </div>
            </form>
        </div>
    </div>

    <script>
    function openClaimTypeModal() {
        var modal = document.getElementById('addClaimTypeModal');
        if (modal) modal.classList.remove('hidden');
    }
    function closeClaimTypeModal() {
        var modal = document.getElementById('addClaimTypeModal');
        if (modal) modal.classList.add('hidden');
    }
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') closeClaimTypeModal();
    });
    </script>

<?php elseif ($view === 'claim_review_queue'): ?>
    <!-- Claim Review Queue -->
    <div class="bg-white dark:bg-gray-800 rounded-xl shadow-sm border border-gray-100 dark:border-gray-700">
        <div class="px-5 py-4 border-b border-gray-100 dark:border-gray-700 flex items-center justify-between">
            <h2 class="text-sm font-semibold text-gray-900 dark:text-white">Claim Review Queue</h2>
            <div class="flex gap-2">
                <input type="text" id="searchClaims" placeholder="Search employee..." class="text-xs border border-gray-300 dark:border-gray-600 rounded px-3 py-1 dark:bg-gray-700 dark:text-white">
                <select id="filterStatus" onchange="filterTable()" class="text-xs border border-gray-300 dark:border-gray-600 rounded px-2 py-1 dark:bg-gray-700 dark:text-white">
                    <option value="">All Status</option>
                    <option value="submitted">Submitted</option>
                    <option value="endorsed">Endorsed</option>
                </select>
                <button onclick="bulkApprove()" class="px-4 py-2 text-xs bg-emerald-600 text-white rounded hover:bg-emerald-700">
                    Bulk Approve
                </button>
            </div>
        </div>
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700" id="claimQueueTable">
                <thead class="bg-gray-50 dark:bg-gray-700">
                    <tr>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase">
                            <input type="checkbox" class="rounded border-gray-300" onchange="toggleAll(this)">
                        </th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase">Employee</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase">Claim Type</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase">Amount</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase">Date</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase">Attachments</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase">Status</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase">Actions</th>
                    </tr>
                </thead>
                <tbody class="bg-white dark:bg-gray-800 divide-y divide-gray-200 dark:divide-gray-700">
                    <?php if (empty($reviewQueue)): ?>
                        <tr>
                            <td colspan="8" class="px-6 py-4 text-center text-sm text-gray-500">No claims pending review</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($reviewQueue as $claim): ?>
                            <tr data-status="<?php echo htmlspecialchars($claim['status']); ?>" data-claim-id="<?php echo $claim['id']; ?>">
                                <td class="px-6 py-4">
                                    <input type="checkbox" class="row-checkbox rounded border-gray-300" value="<?php echo $claim['id']; ?>">
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900 dark:text-white">
                                    <?php echo htmlspecialchars($claim['employee_number'] . ' - ' . $claim['first_name'] . ' ' . $claim['last_name']); ?>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900 dark:text-white">
                                    <?php echo htmlspecialchars($claim['category_name'] ?? 'Uncategorized'); ?>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm font-semibold text-gray-900 dark:text-white">
                                    <?php echo htmlspecialchars($claim['currency'] ?? 'PHP'); ?> <?php echo number_format($claim['amount'], 2); ?>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900 dark:text-white">
                                    <?php 
                                    $claimDate = $claim['submitted_at'] ?? $claim['created_at'] ?? null;
                                    echo $claimDate ? date('M d, Y', strtotime($claimDate)) : '-';
                                    ?>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900 dark:text-white">
                                    <?php 
                                    $attachmentCount = $claim['attachment_count'] ?? 0;
                                    $attachmentsData = $claim['attachments_data'] ?? '';
                                    ?>
                                    <?php echo $attachmentCount; ?> file(s)
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm">
                                    <span class="px-2 py-1 text-xs font-semibold rounded-full bg-amber-100 text-amber-800 dark:bg-amber-900 dark:text-amber-200">
                                        <?php echo strtoupper($claim['status']); ?>
                                    </span>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm">
                                    <div class="flex items-center gap-1">
                                        <?php if ($attachmentCount > 0): ?>
                                            <button type="button" onclick='viewReceipt(<?php echo json_encode($attachmentsData); ?>, <?php echo (int)$claim["id"]; ?>)' 
                                                    class="p-1.5 text-blue-600 hover:text-blue-700 hover:bg-blue-50 dark:hover:bg-blue-900/30 rounded-lg transition-colors" title="View Receipt">
                                                <svg xmlns="http://www.w3.org/2000/svg" class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z" />
                                                </svg>
                                            </button>
                                        <?php endif; ?>
                                        <button type="button" onclick="approveClaim(<?php echo (int)$claim['id']; ?>, this)" 
                                                class="p-1.5 text-emerald-600 hover:text-emerald-700 hover:bg-emerald-50 dark:hover:bg-emerald-900/30 rounded-lg transition-colors" title="Approve">
                                            <svg xmlns="http://www.w3.org/2000/svg" class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" />
                                            </svg>
                                        </button>
                                        <button type="button" onclick="rejectClaim(<?php echo (int)$claim['id']; ?>, this)" 
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

    <!-- Receipt Preview Modal -->
    <div id="receiptModal" class="hidden fixed inset-0 bg-black bg-opacity-60 z-50 flex items-center justify-center p-4" onclick="if(event.target===this) closeReceiptModal()">
        <div class="bg-white dark:bg-gray-800 rounded-xl shadow-2xl max-w-4xl w-full max-h-[90vh] flex flex-col" onclick="event.stopPropagation()">
            <div class="px-5 py-4 border-b border-gray-100 dark:border-gray-700 flex items-center justify-between flex-shrink-0">
                <h3 class="text-sm font-semibold text-gray-900 dark:text-white">
                    <span id="receiptModalTitle">Receipt Preview</span>
                </h3>
                <div class="flex items-center gap-2">
                    <a id="receiptDownloadLink" href="#" target="_blank" class="flex items-center gap-1 text-sm text-blue-600 hover:text-blue-700 px-3 py-1.5 rounded-lg hover:bg-blue-50 dark:hover:bg-blue-900/30">
                        <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4" />
                        </svg>
                        Download
                    </a>
                    <button type="button" onclick="closeReceiptModal()" class="text-gray-400 hover:text-gray-600 dark:hover:text-gray-300 p-1 rounded-lg hover:bg-gray-100 dark:hover:bg-gray-700 transition-colors">
                        <svg xmlns="http://www.w3.org/2000/svg" class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                        </svg>
                    </button>
                </div>
            </div>
            <div class="flex-grow overflow-hidden">
                <div id="receiptPreview" class="w-full h-full flex items-center justify-center bg-gray-50 dark:bg-gray-900 min-h-[500px]"></div>
            </div>
        </div>
    </div>

    <!-- Toast for feedback -->
    <div id="claimToast" class="hidden fixed bottom-4 right-4 z-50 max-w-sm">
        <div id="claimToastInner" class="flex items-center gap-3 px-4 py-3 rounded-lg shadow-lg border text-sm"></div>
    </div>

    <script>
    (function() {
        // Use current page origin so Approve/Reject work when site is opened via different host
        var base = window.location.origin;

        // Receipt Modal - Show preview directly
        window.viewReceipt = function(attachmentsData, claimId) {
            var rawPath = '';
            var fileName = '';
            
            if (attachmentsData) {
                var items = attachmentsData.split(';;');
                if (items.length > 0 && items[0]) {
                    var parts = items[0].split('|');
                    if (parts.length >= 2) {
                        rawPath = parts[1];
                        fileName = parts[2] || 'Receipt';
                    }
                }
            }

            // Use file serving endpoint
            var filePath = base + '/api/files/serve.php?path=' + encodeURIComponent(rawPath);

            var modalTitle = document.getElementById('receiptModalTitle');
            var previewEl = document.getElementById('receiptPreview');
            var downloadLink = document.getElementById('receiptDownloadLink');
            
            if (modalTitle) modalTitle.textContent = 'Receipt - Claim #' + claimId;
            if (downloadLink) downloadLink.href = filePath;

            if (previewEl && rawPath) {
                var ext = rawPath.split('.').pop().toLowerCase();
                var isImage = ['jpg', 'jpeg', 'png', 'gif', 'webp'].indexOf(ext) !== -1;
                var isPdf = ext === 'pdf';
                var isHtml = ext === 'html' || ext === 'htm';

                if (isImage) {
                    previewEl.innerHTML = '<img src="' + filePath + '" alt="Receipt" class="max-w-full max-h-[70vh] rounded-lg shadow-lg" onerror="this.outerHTML=\'<p class=\\\'text-gray-500\\\'>Unable to load image</p>\'">';
                } else if (isPdf || isHtml) {
                    previewEl.innerHTML = '<iframe src="' + filePath + '" class="w-full h-[70vh] rounded-lg border border-gray-200 dark:border-gray-700 bg-white" frameborder="0"></iframe>';
                } else {
                    previewEl.innerHTML = '<div class="text-center py-8"><p class="text-gray-500 mb-4">Preview not available</p><a href="' + filePath + '" target="_blank" class="px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700">Download File</a></div>';
                }
            }

            var modal = document.getElementById('receiptModal');
            if (modal) modal.classList.remove('hidden');
        };

        window.closeReceiptModal = function() {
            var modal = document.getElementById('receiptModal');
            var previewEl = document.getElementById('receiptPreview');
            if (modal) modal.classList.add('hidden');
            if (previewEl) previewEl.innerHTML = '';
        };

        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') closeReceiptModal();
        });

        function showToast(msg, isError) {
            var el = document.getElementById('claimToast');
            var inner = document.getElementById('claimToastInner');
            if (!inner) { try { alert(msg); } catch (e) {} return; }
            inner.textContent = msg;
            inner.className = 'flex items-center gap-3 px-4 py-3 rounded-lg shadow-lg border text-sm ' +
                (isError ? 'bg-red-50 dark:bg-red-900/40 border-red-200 text-red-800 dark:text-red-200' : 'bg-emerald-50 dark:bg-emerald-900/40 border-emerald-200 text-emerald-800 dark:text-emerald-200');
            if (el) { el.classList.remove('hidden'); setTimeout(function() { el.classList.add('hidden'); }, 4000); }
        }

        function removeClaimRow(claimId) {
            var row = document.querySelector('tr[data-claim-id="' + claimId + '"]');
            if (row) { row.style.opacity = '0'; row.style.transition = 'opacity 0.3s'; setTimeout(function() { row.remove(); }, 300); }
        }

        function disableBtn(btn) { if (btn) { btn.disabled = true; btn.style.opacity = '0.5'; } }
        function enableBtn(btn) { if (btn) { btn.disabled = false; btn.style.opacity = '1'; } }

        function toggleAll(checkbox) {
            document.querySelectorAll('.row-checkbox').forEach(function(cb) { cb.checked = checkbox.checked; });
        }

        window.approveClaim = function(claimId, btnEl) {
            if (!confirm('Approve this claim?')) return;
            disableBtn(btnEl);
            fetch(base + '/api/claims/approve.php', {
                method: 'POST',
                headers: {'Content-Type': 'application/json'},
                body: JSON.stringify({claim_id: claimId}),
                credentials: 'same-origin'
            })
            .then(function(r) { return r.text().then(function(text) { try { return JSON.parse(text); } catch (e) { return { success: false, message: text || 'Server error' }; } }); })
            .then(function(data) {
                if (data && data.success) {
                    showToast(data.message || 'Claim approved');
                    removeClaimRow(claimId);
                } else {
                    showToast((data && data.message) || 'Error approving claim', true);
                    enableBtn(btnEl);
                }
            })
            .catch(function() { showToast('Network error. Please try again.', true); enableBtn(btnEl); });
        };

        window.rejectClaim = function(claimId, btnEl) {
            var reason = prompt('Rejection reason (optional):');
            if (reason === null) return;
            disableBtn(btnEl);
            fetch(base + '/api/claims/reject.php', {
                method: 'POST',
                headers: {'Content-Type': 'application/json'},
                body: JSON.stringify({claim_id: claimId, reason: reason || ''}),
                credentials: 'same-origin'
            })
            .then(function(r) { return r.text().then(function(text) { try { return JSON.parse(text); } catch (e) { return { success: false, message: text || 'Server error' }; } }); })
            .then(function(data) {
                if (data && data.success) {
                    showToast(data.message || 'Claim rejected');
                    removeClaimRow(claimId);
                } else {
                    showToast((data && data.message) || 'Error rejecting claim', true);
                    enableBtn(btnEl);
                }
            })
            .catch(function() { showToast('Network error. Please try again.', true); enableBtn(btnEl); });
        };

        window.bulkApprove = function() {
            var selected = Array.from(document.querySelectorAll('.row-checkbox:checked'));
            if (selected.length === 0) { showToast('Please select at least one claim', true); return; }
            if (!confirm('Approve ' + selected.length + ' claim(s)?')) return;
            var ids = selected.map(function(cb) { return parseInt(cb.value, 10); }).filter(Boolean);
            var done = 0, failed = 0;
            ids.forEach(function(id) {
                fetch(base + '/api/claims/approve.php', {
                    method: 'POST',
                    headers: {'Content-Type': 'application/json'},
                    body: JSON.stringify({claim_id: id}),
                    credentials: 'same-origin'
                })
                .then(function(r) { return r.text().then(function(text) { try { return JSON.parse(text); } catch (e) { return { success: false }; } }); })
                .then(function(data) {
                    if (data && data.success) { done++; removeClaimRow(id); } else { failed++; }
                })
                .catch(function() { failed++; })
                .finally(function() {
                    if (done + failed === ids.length) {
                        if (failed === 0) showToast(done + ' claim(s) approved');
                        else showToast(done + ' approved, ' + failed + ' failed', failed > 0);
                    }
                });
            });
        };

        window.filterTable = function() {
            var filter = (document.getElementById('filterStatus') || {}).value || '';
            var search = (document.getElementById('searchClaims') || {}).value.toLowerCase().trim();
            var rows = document.querySelectorAll('#claimQueueTable tbody tr');
            rows.forEach(function(row) {
                var status = row.getAttribute('data-status') || '';
                var text = row.textContent.toLowerCase();
                var statusMatch = !filter || status.toLowerCase() === filter.toLowerCase();
                var searchMatch = !search || text.includes(search);
                row.style.display = (statusMatch && searchMatch) ? '' : 'none';
            });
        };

        // Add search event listener
        document.getElementById('searchClaims')?.addEventListener('input', filterTable);
    })();
    </script>

<?php elseif ($view === 'disbursement_status'): ?>
    <!-- Disbursement Status -->
    <div class="bg-white dark:bg-gray-800 rounded-xl shadow-sm border border-gray-100 dark:border-gray-700">
        <div class="px-5 py-4 border-b border-gray-100 dark:border-gray-700">
            <h2 class="text-sm font-semibold text-gray-900 dark:text-white">Disbursement Status</h2>
        </div>
        <div class="p-5">
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                    <thead class="bg-gray-50 dark:bg-gray-700">
                        <tr>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase">Employee</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase">Claim Type</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase">Amount</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase">Approved Date</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase">Disbursement Status</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="bg-white dark:bg-gray-800 divide-y divide-gray-200 dark:divide-gray-700">
                        <?php if (empty($disbursements ?? [])): ?>
                            <tr>
                                <td colspan="6" class="px-6 py-4 text-center text-sm text-gray-500">No approved/paid claims found</td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($disbursements as $d): ?>
                                <tr>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900 dark:text-white">
                                        <?php echo htmlspecialchars($d['employee_number'] . ' - ' . $d['first_name'] . ' ' . $d['last_name']); ?>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900 dark:text-white">
                                        <?php echo htmlspecialchars($d['category_name'] ?? 'Uncategorized'); ?>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm font-semibold">
                                        PHP <?php echo number_format($d['amount'], 2); ?>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                        <?php echo $d['approved_at'] ? date('M d, Y', strtotime($d['approved_at'])) : '-'; ?>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm">
                                        <span class="px-2 py-1 text-xs font-semibold rounded-full <?php echo $d['status'] === 'paid' ? 'bg-green-100 text-green-800' : 'bg-amber-100 text-amber-800'; ?>">
                                            <?php echo strtoupper($d['status']); ?>
                                        </span>
                                        <?php if ($d['payment_reference']): ?>
                                            <span class="ml-1 text-xs text-gray-500">Ref: <?php echo htmlspecialchars($d['payment_reference']); ?></span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm">
                                        <?php if ($d['status'] === 'approved'): ?>
                                            <button onclick="markPaid(<?php echo $d['id']; ?>)" class="text-emerald-600 hover:text-emerald-700 text-xs font-medium">Mark Paid</button>
                                        <?php else: ?>
                                            <span class="text-xs text-gray-400">Completed</span>
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

    <script>
        async function markPaid(claimId) {
            if (!confirm('Mark this claim as paid?')) return;
            const base = window.location.origin || <?php echo json_encode(rtrim(BASE_URL, '/')); ?>;
            try {
                const res = await fetch(base + '/api/claims/mark_paid.php', {
                    method: 'POST',
                    headers: {'Content-Type': 'application/json'},
                    body: JSON.stringify({claim_id: claimId}),
                    credentials: 'same-origin'
                });
                const data = await res.json();
                if (data.success) {
                    alert('Claim marked as paid');
                    location.reload();
                } else {
                    alert(data.message || 'Error');
                }
            } catch(e) { alert('Network error'); }
        }
    </script>

<?php elseif ($view === 'audit_trail'): ?>
    <!-- Audit Trail -->
    <div class="bg-white dark:bg-gray-800 rounded-xl shadow-sm border border-gray-100 dark:border-gray-700">
        <div class="px-5 py-4 border-b border-gray-100 dark:border-gray-700 flex items-center justify-between">
            <h2 class="text-sm font-semibold text-gray-900 dark:text-white">Audit Trail</h2>
            <button onclick="exportAudit()" class="px-3 py-1 text-xs border border-gray-300 dark:border-gray-600 rounded hover:bg-gray-50 dark:hover:bg-gray-700">
                Export
            </button>
        </div>
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                <thead class="bg-gray-50 dark:bg-gray-700">
                    <tr>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase">Date & Time</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase">Employee</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase">Action</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase">Details</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase">IP Address</th>
                    </tr>
                </thead>
                <tbody class="bg-white dark:bg-gray-800 divide-y divide-gray-200 dark:divide-gray-700">
                    <?php if (empty($auditTrail)): ?>
                        <tr>
                            <td colspan="5" class="px-6 py-4 text-center text-sm text-gray-500">No audit trail entries found</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($auditTrail as $audit): ?>
                            <tr>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900 dark:text-white">
                                    <?php echo date('M d, Y H:i:s', strtotime($audit['created_at'])); ?>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900 dark:text-white">
                                    <?php echo htmlspecialchars($audit['employee_number'] ?? $audit['employee_id'] ?? 'N/A'); ?>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900 dark:text-white">
                                    <?php echo htmlspecialchars(ucfirst(str_replace('_', ' ', $audit['action']))); ?>
                                </td>
                                <td class="px-6 py-4 text-sm text-gray-900 dark:text-white">
                                    <?php 
                                    $newValues = json_decode($audit['new_values'] ?? '{}', true);
                                    echo htmlspecialchars(json_encode($newValues, JSON_PRETTY_PRINT));
                                    ?>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500 dark:text-gray-400">
                                    <?php echo htmlspecialchars($audit['ip_address'] ?? 'N/A'); ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <script>
    function exportAudit() {
        const url = '<?php echo rtrim(BASE_URL, "/"); ?>/api/reports/export.php?type=audit_trail&format=csv';
        window.location.href = url;
    }
    </script>
<?php endif; ?>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
