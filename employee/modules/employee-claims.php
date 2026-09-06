<?php
require_once __DIR__ . '/../../config/config.php';
requireAuth();
checkRole(['employee', 'supervisor', 'unit_head']);

$page_title = 'Claims & Reimbursement';
$view = $_GET['view'] ?? 'file_claim';

$employeeId = getCurrentEmployeeId($db ?? null);
if (!$employeeId) {
    $employeeId = $_SESSION['employee_id'] ?? $_SESSION['user_id'] ?? null;
}

// Ensure employee_id is a valid integer (resolve employee_number like "EMP001" to numeric id)
if ($employeeId && !is_numeric($employeeId) && isset($db)) {
    try {
        $empStmt = $db->prepare("SELECT id FROM employees WHERE employee_number = ? LIMIT 1");
        $empStmt->execute([$employeeId]);
        $empRow = $empStmt->fetch(PDO::FETCH_ASSOC);
        $employeeId = $empRow ? (int) $empRow['id'] : null;
    } catch (PDOException $e) {
        $employeeId = null;
    }
} elseif ($employeeId && is_numeric($employeeId)) {
    $employeeId = (int) $employeeId;
}

if (!$employeeId) {
    $_SESSION['error'] = "Employee identifier not found. Please log in again.";
    header("Location: " . BASE_URL . "/auth/employee-login.php");
    exit();
}

$claims = [];
$message = '';

// Load claim categories for the form
$claimCategories = [];
if (isset($db)) {
    try {
        $claimCategories = $db->query("SELECT id, code, name, max_amount, requires_receipt FROM claim_categories ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) { /* ignore */ }
}

// Handle claim filing
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['file_claim'])) {
    $categoryCode = $_POST['claim_type'] ?? '';
    $amount = (float) ($_POST['amount'] ?? 0);
    $description = trim($_POST['description'] ?? '');
    
    if ($categoryCode && $amount > 0 && $description && isset($db)) {
        try {
            // Resolve category_id from code
            $categoryId = null;
            try {
                $cc = $db->prepare("SELECT id, max_amount FROM claim_categories WHERE code = ? LIMIT 1");
                $cc->execute([$categoryCode]);
                $row = $cc->fetch(PDO::FETCH_ASSOC);
                if ($row) {
                    $categoryId = (int) $row['id'];
                    // Validate max amount
                    if ($row['max_amount'] && $amount > (float) $row['max_amount']) {
                        $_SESSION['error'] = 'Amount exceeds the maximum allowed (' . number_format($row['max_amount'], 2) . ') for this category.';
                        header("Location: " . $_SERVER['PHP_SELF'] . "?view=file_claim");
                        exit();
                    }
                }
            } catch (PDOException $e) {
                // claim_categories may not exist; proceed with null category_id
                error_log('Category lookup failed: ' . $e->getMessage());
            }

            $stmt = $db->prepare("
                INSERT INTO claims (employee_id, category_id, amount, currency, description, status, submitted_at, created_at, updated_at) 
                VALUES (?, ?, ?, 'PHP', ?, 'submitted', NOW(), NOW(), NOW())
            ");
            $stmt->execute([(int) $employeeId, $categoryId, $amount, $description]);
            $claimId = $db->lastInsertId();
            
            // Handle file uploads
            if (isset($_FILES['receipts']) && !empty($_FILES['receipts']['name'][0])) {
                $uploadDir = __DIR__ . '/../../assets/uploads/claim_receipts/';
                if (!is_dir($uploadDir)) {
                    mkdir($uploadDir, 0755, true);
                }
                
                foreach ($_FILES['receipts']['name'] as $key => $filename) {
                    if ($_FILES['receipts']['error'][$key] === UPLOAD_ERR_OK) {
                        $ext = pathinfo($filename, PATHINFO_EXTENSION);
                        $newFilename = 'claim_' . $claimId . '_' . time() . '_' . $key . '.' . $ext;
                        $filePath = $uploadDir . $newFilename;
                        
                        if (move_uploaded_file($_FILES['receipts']['tmp_name'][$key], $filePath)) {
                            $attachStmt = $db->prepare("
                                INSERT INTO claim_attachments (claim_id, file_path, file_name, created_at)
                                VALUES (?, ?, ?, NOW())
                            ");
                            $attachStmt->execute([$claimId, 'assets/uploads/claim_receipts/' . $newFilename, $filename]);
                        }
                    }
                }
            }
            
            // Audit log (non-fatal — catch ALL exceptions, not just PDOException)
            try {
                $auditEmpId = (string) ($_SESSION['employee_id'] ?? $employeeId);
                $db->prepare("
                    INSERT INTO audit_logs (employee_id, action, table_name, record_id, new_values, ip_address, user_agent)
                    VALUES (?, 'file_claim', 'claims', ?, ?, ?, ?)
                ")->execute([
                    $auditEmpId,
                    (int) $claimId,
                    json_encode(['category' => $categoryCode, 'amount' => $amount, 'status' => 'submitted']),
                    $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0',
                    substr($_SERVER['HTTP_USER_AGENT'] ?? 'unknown', 0, 500)
                ]);
            } catch (\Throwable $e) { 
                error_log('Claim audit log error (non-fatal): ' . $e->getMessage()); 
            }
            
            $_SESSION['success'] = 'Claim filed successfully!';
            header("Location: " . $_SERVER['PHP_SELF'] . "?view=claim_status_history");
            exit();
        } catch (\Throwable $e) {
            error_log('Claim filing error: ' . $e->getMessage());
            $_SESSION['error'] = 'Error filing claim: ' . $e->getMessage();
        }
    } else {
        $_SESSION['error'] = 'Please fill in all required fields.';
    }
}

if (isset($db) && $employeeId) {
    try {
        $stmt = $db->prepare("
            SELECT c.id, c.category_id, c.amount, c.currency, c.description, c.status,
                   c.submitted_at, c.approved_by, c.approved_at, c.paid_at, c.payment_reference,
                   c.created_at, c.updated_at,
                   cc.name as category_name, cc.code as category_code,
                   (SELECT COUNT(*) FROM claim_attachments WHERE claim_id = c.id) as attachment_count
            FROM claims c
            LEFT JOIN claim_categories cc ON c.category_id = cc.id
            WHERE c.employee_id = ?
            ORDER BY COALESCE(c.submitted_at, c.created_at) DESC
        ");
        $stmt->execute([$employeeId]);
        $claims = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log('Claims fetch error: ' . $e->getMessage());
    }
}

include __DIR__ . '/../../includes/header.php';
?>

<div class="mb-6">
    <h1 class="text-2xl font-semibold text-gray-900 dark:text-white">Claims & Reimbursement</h1>
    <p class="mt-1 text-sm text-gray-600 dark:text-gray-400">Claim form, receipt upload, and tracking table</p>
</div>

<?php if ($view === 'file_claim'): ?>
    <!-- New Claim Submission -->
    <div class="bg-white dark:bg-gray-800 rounded-xl shadow-sm border border-gray-100 dark:border-gray-700 mb-6">
        <div class="px-5 py-4 border-b border-gray-100 dark:border-gray-700">
            <h2 class="text-sm font-semibold text-gray-900 dark:text-white">New Claim Submission</h2>
        </div>
        <div class="p-5">
            <form method="POST" enctype="multipart/form-data" id="claimForm">
                <div class="space-y-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Claim Category</label>
                        <select name="claim_type" required class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg dark:bg-gray-700 dark:text-white">
                            <option value="">Select category</option>
                            <?php if (!empty($claimCategories)): ?>
                                <?php foreach ($claimCategories as $cat): ?>
                                    <option value="<?php echo htmlspecialchars($cat['code']); ?>" data-max="<?php echo $cat['max_amount'] ?? ''; ?>">
                                        <?php echo htmlspecialchars($cat['name']); ?>
                                        <?php if ($cat['max_amount']): ?> (max: PHP <?php echo number_format($cat['max_amount'], 2); ?>)<?php endif; ?>
                                    </option>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <option value="TRANS">Transportation</option>
                                <option value="MEAL">Meal Allowance</option>
                                <option value="MED">Medical Reimbursement</option>
                                <option value="SUPPLIES">Office Supplies</option>
                            <?php endif; ?>
                        </select>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Amount (PHP)</label>
                        <input type="number" name="amount" step="0.01" min="0.01" required placeholder="0.00" class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg dark:bg-gray-700 dark:text-white">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Description</label>
                        <textarea name="description" rows="3" required placeholder="Describe the purpose of this expense..." class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg dark:bg-gray-700 dark:text-white"></textarea>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Upload Receipts</label>
                        <div class="mt-1 flex justify-center px-6 pt-5 pb-6 border-2 border-gray-300 dark:border-gray-600 border-dashed rounded-lg hover:border-primary-400 dark:hover:border-primary-500 transition-colors">
                            <div class="space-y-1 text-center">
                                <svg class="mx-auto h-12 w-12 text-gray-400" stroke="currentColor" fill="none" viewBox="0 0 48 48">
                                    <path d="M28 8H12a4 4 0 00-4 4v20m32-12v8m0 0v8a4 4 0 01-4 4H12a4 4 0 01-4-4v-4m32-4l-3.172-3.172a4 4 0 00-5.656 0L28 28M8 32l9.172-9.172a4 4 0 015.656 0L28 28m0 0l4 4m4-24h8m-4-4v8m-12 4h.02" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" />
                                </svg>
                                <div class="flex text-sm text-gray-600 dark:text-gray-400">
                                    <label class="relative cursor-pointer rounded-md font-medium text-primary-600 hover:text-primary-500">
                                        <span>Upload files</span>
                                        <input type="file" name="receipts[]" multiple accept="image/*,.pdf" class="sr-only" onchange="updateFileList(this)">
                                    </label>
                                    <p class="pl-1">or drag and drop</p>
                                </div>
                                <p class="text-xs text-gray-500">PNG, JPG, PDF up to 10MB each</p>
                            </div>
                        </div>
                        <div id="fileList" class="mt-2 text-xs text-gray-500"></div>
                    </div>
                    <div class="flex gap-3">
                        <button type="submit" name="file_claim" class="flex-1 px-4 py-2 bg-primary-600 text-white rounded-lg hover:bg-primary-700 transition-colors">
                            Submit Claim
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
        function updateFileList(input) {
            const fileList = document.getElementById('fileList');
            if (input.files.length > 0) {
                let list = 'Selected files: ';
                for (let i = 0; i < input.files.length; i++) {
                    list += input.files[i].name;
                    if (i < input.files.length - 1) list += ', ';
                }
                fileList.textContent = list;
            } else {
                fileList.textContent = '';
            }
        }
    </script>

<?php elseif ($view === 'upload_receipts'): ?>
    <!-- Upload Receipts (for existing claims) -->
    <div class="bg-white dark:bg-gray-800 rounded-xl shadow-sm border border-gray-100 dark:border-gray-700">
        <div class="px-5 py-4 border-b border-gray-100 dark:border-gray-700">
            <h2 class="text-sm font-semibold text-gray-900 dark:text-white">Upload Receipts</h2>
        </div>
        <div class="p-5">
            <form method="POST" enctype="multipart/form-data" action="<?php echo BASE_URL; ?>/api/claims/upload_receipts.php">
                <div class="space-y-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Select Claim</label>
                        <select name="claim_id" required class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg dark:bg-gray-700 dark:text-white">
                            <option value="">Select claim</option>
                            <?php foreach ($claims as $claim): ?>
                                <option value="<?php echo $claim['id']; ?>">
                                    <?php echo htmlspecialchars($claim['category_name'] ?? 'Claim #' . $claim['id']); ?> - 
                                    PHP <?php echo number_format($claim['amount'], 2); ?> -
                                    <?php echo date('M d, Y', strtotime($claim['submitted_at'] ?? $claim['created_at'])); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Upload Receipts</label>
                        <input type="file" name="receipts[]" multiple accept="image/*,.pdf" required class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg">
                    </div>
                    <button type="submit" class="px-4 py-2 bg-primary-600 text-white rounded-lg hover:bg-primary-700">
                        Upload Receipts
                    </button>
                </div>
            </form>
        </div>
    </div>

<?php elseif ($view === 'claim_status_history'): ?>
    <!-- Claim Tracker & History -->
    <div class="bg-white dark:bg-gray-800 rounded-xl shadow-sm border border-gray-100 dark:border-gray-700">
        <div class="px-5 py-4 border-b border-gray-100 dark:border-gray-700 flex items-center justify-between">
            <h2 class="text-sm font-semibold text-gray-900 dark:text-white">Claim Tracker & History</h2>
            <div class="flex gap-2">
                <select id="filterStatus" onchange="filterTable()" class="text-xs border border-gray-300 dark:border-gray-600 rounded px-2 py-1 dark:bg-gray-700 dark:text-white">
                    <option value="">All Status</option>
                    <option value="submitted">Submitted</option>
                    <option value="approved">Approved</option>
                    <option value="rejected">Rejected</option>
                    <option value="paid">Paid</option>
                </select>
                <button onclick="exportClaims()" class="px-3 py-1 text-xs border border-gray-300 dark:border-gray-600 rounded hover:bg-gray-50 dark:hover:bg-gray-700">
                    Export
                </button>
            </div>
        </div>
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700" id="claimsTable">
                <thead class="bg-gray-50 dark:bg-gray-700">
                    <tr>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase cursor-pointer" onclick="sortTable(0)">
                            Category <span class="sort-indicator">↕</span>
                        </th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase cursor-pointer" onclick="sortTable(1)">
                            Amount <span class="sort-indicator">↕</span>
                        </th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase">Description</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase">Status</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase">Attachments</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase">Submitted</th>
                    </tr>
                </thead>
                <tbody class="bg-white dark:bg-gray-800 divide-y divide-gray-200 dark:divide-gray-700">
                    <?php if (empty($claims)): ?>
                        <tr>
                            <td colspan="6" class="px-6 py-4 text-center text-sm text-gray-500">No claims found. <a href="?view=file_claim" class="text-primary-600 hover:underline">File a new claim</a>.</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($claims as $claim): ?>
                            <tr data-status="<?php echo htmlspecialchars($claim['status']); ?>">
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900 dark:text-white">
                                    <?php echo htmlspecialchars($claim['category_name'] ?? 'Uncategorized'); ?>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900 dark:text-white font-semibold">
                                    PHP <?php echo number_format($claim['amount'], 2); ?>
                                </td>
                                <td class="px-6 py-4 text-sm text-gray-900 dark:text-white max-w-xs truncate">
                                    <?php echo htmlspecialchars($claim['description'] ?? '-'); ?>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm">
                                    <span class="px-2 py-1 text-xs font-semibold rounded-full <?php 
                                        echo $claim['status'] === 'approved' ? 'bg-emerald-100 text-emerald-800 dark:bg-emerald-900 dark:text-emerald-200' : 
                                            ($claim['status'] === 'submitted' ? 'bg-blue-100 text-blue-800 dark:bg-blue-900 dark:text-blue-200' : 
                                            ($claim['status'] === 'rejected' ? 'bg-red-100 text-red-800 dark:bg-red-900 dark:text-red-200' : 
                                            ($claim['status'] === 'paid' ? 'bg-green-100 text-green-800 dark:bg-green-900 dark:text-green-200' :
                                            ($claim['status'] === 'endorsed' ? 'bg-amber-100 text-amber-800 dark:bg-amber-900 dark:text-amber-200' : 'bg-gray-100 text-gray-800 dark:bg-gray-700 dark:text-gray-200'))));
                                    ?>">
                                        <?php echo strtoupper($claim['status']); ?>
                                    </span>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900 dark:text-white">
                                    <span class="flex items-center">
                                        <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4 mr-1" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15.172 7l-6.586 6.586a2 2 0 102.828 2.828l6.414-6.586a4 4 0 00-5.656-5.656l-6.415 6.585a6 6 0 108.486 8.486L20.5 13" />
                                        </svg>
                                        <?php echo $claim['attachment_count'] ?? 0; ?> file(s)
                                    </span>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500 dark:text-gray-400">
                                    <?php echo $claim['submitted_at'] ? date('M d, Y H:i', strtotime($claim['submitted_at'])) : 'N/A'; ?>
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
            const rows = document.querySelectorAll('#claimsTable tbody tr');
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
            const tbody = document.querySelector('#claimsTable tbody');
            const rows = Array.from(tbody.querySelectorAll('tr:not([style*="display: none"])'));
            
            sortDirection[columnIndex] = sortDirection[columnIndex] === 'asc' ? 'desc' : 'asc';
            
            rows.sort((a, b) => {
                const aText = a.cells[columnIndex].textContent.trim();
                const bText = b.cells[columnIndex].textContent.trim();
                return sortDirection[columnIndex] === 'asc' ? aText.localeCompare(bText) : bText.localeCompare(aText);
            });
            
            rows.forEach(row => tbody.appendChild(row));
        }

        function exportClaims() {
            const table = document.getElementById('claimsTable');
            const rows = table.querySelectorAll('tbody tr:not([style*="display: none"])');
            let csv = 'Claim Type,Amount,Expense Date,Status,Attachments,Submitted\n';
            
            rows.forEach(row => {
                const cells = row.querySelectorAll('td');
                if (cells.length > 0) {
                    csv += Array.from(cells).map(cell => cell.textContent.trim().replace(/,/g, ';').replace(/PHP /g, '')).join(',') + '\n';
                }
            });
            
            const blob = new Blob([csv], { type: 'text/csv' });
            const url = window.URL.createObjectURL(blob);
            const a = document.createElement('a');
            a.href = url;
            a.download = 'claims_history_' + new Date().toISOString().split('T')[0] + '.csv';
            a.click();
        }
    </script>
<?php elseif ($view === 'claim_history'): ?>
    <div class="bg-white dark:bg-gray-800 rounded-xl shadow-sm border border-gray-100 dark:border-gray-700">
        <div class="px-5 py-4 border-b border-gray-100 dark:border-gray-700 flex items-center justify-between">
            <h2 class="text-sm font-semibold text-gray-900 dark:text-white">Claim History</h2>
            <a href="?view=claim_status_history" class="px-3 py-1 text-xs border border-gray-300 dark:border-gray-600 rounded hover:bg-gray-50 dark:hover:bg-gray-700">View Status</a>
        </div>
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                <thead class="bg-gray-50 dark:bg-gray-700"><tr><th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase">Type</th><th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase">Amount</th><th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase">Date</th><th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase">Status</th><th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase">Submitted</th></tr></thead>
                <tbody class="bg-white dark:bg-gray-800 divide-y divide-gray-200 dark:divide-gray-700">
                    <?php if (empty($claims)): ?><tr><td colspan="5" class="px-6 py-4 text-center text-sm text-gray-500">No claims found</td></tr>
                    <?php else: foreach ($claims as $c): $typeName = $c['category_name'] ?? 'Uncategorized'; ?>
                        <tr>
                            <td class="px-6 py-4 text-sm text-gray-900 dark:text-white"><?php echo htmlspecialchars($typeName); ?></td>
                            <td class="px-6 py-4 text-sm font-semibold">PHP <?php echo number_format($c['amount'] ?? 0, 2); ?></td>
                            <td class="px-6 py-4 text-sm"><?php echo isset($c['submitted_at']) && $c['submitted_at'] ? date('M d, Y', strtotime($c['submitted_at'])) : '-'; ?></td>
                            <td class="px-6 py-4 text-sm"><span class="px-2 py-1 text-xs rounded-full <?php echo ($c['status'] ?? '') === 'paid' ? 'bg-green-100 text-green-800' : (($c['status'] ?? '') === 'approved' ? 'bg-emerald-100 text-emerald-800' : (($c['status'] ?? '') === 'rejected' ? 'bg-red-100 text-red-800' : 'bg-amber-100 text-amber-800')); ?>"><?php echo strtoupper($c['status'] ?? 'draft'); ?></span></td>
                            <td class="px-6 py-4 text-sm text-gray-500"><?php echo isset($c['submitted_at']) && $c['submitted_at'] ? date('M d, Y', strtotime($c['submitted_at'])) : '-'; ?></td>
                        </tr>
                    <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
    </div>
<?php elseif ($view === 'payment_updates'): ?>
    <div class="bg-white dark:bg-gray-800 rounded-xl shadow-sm border border-gray-100 dark:border-gray-700">
        <div class="px-5 py-4 border-b border-gray-100 dark:border-gray-700"><h2 class="text-sm font-semibold text-gray-900 dark:text-white">Payment Updates</h2></div>
        <div class="p-5">
            <?php $paidClaims = array_filter($claims ?? [], function($c) { return ($c['status'] ?? '') === 'paid'; }); ?>
            <?php if (empty($paidClaims)): ?>
                <p class="text-sm text-gray-500">No paid claims yet. Approved claims will appear here once payment is processed.</p>
            <?php else: foreach ($paidClaims as $c): ?>
                <div class="p-4 bg-emerald-50 dark:bg-emerald-900/20 rounded-lg border border-emerald-200 dark:border-emerald-800">
                    <p class="text-sm font-semibold text-gray-900 dark:text-white"><?php echo htmlspecialchars($c['category_name'] ?? 'Claim'); ?> — PHP <?php echo number_format($c['amount'] ?? 0, 2); ?></p>
                    <p class="text-xs text-gray-600 dark:text-gray-400 mt-1"><?php echo htmlspecialchars($c['description'] ?? ''); ?></p>
                    <?php if (!empty($c['payment_reference'])): ?><p class="text-xs font-medium text-emerald-700 mt-2">Reference: <?php echo htmlspecialchars($c['payment_reference']); ?></p><?php endif; ?>
                    <?php if (!empty($c['paid_at'])): ?><p class="text-xs text-gray-500 mt-1">Paid: <?php echo date('M d, Y', strtotime($c['paid_at'])); ?></p><?php endif; ?>
                </div>
            <?php endforeach; endif; ?>
        </div>
    </div>
<?php endif; ?>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
