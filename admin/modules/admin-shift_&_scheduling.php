<?php
require_once __DIR__ . '/../../config/config.php';
requireAuth();
checkRole(['admin', 'super admin', 'hr_admin']);

$page_title = 'Shift & Schedule Management';
$view = $_GET['view'] ?? 'roster_calendar';
if ($view === 'department_scheduling') {
    header('Location: ' . $_SERVER['PHP_SELF'] . '?view=roster_calendar');
    exit;
}

$shiftTemplates = [];
$swapRequests = [];
$schedules = [];

if (isset($db)) {
    try {
        // Handle form submissions
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $action = $_POST['action'] ?? '';
            
            if ($action === 'create_shift_template') {
                $code = trim($_POST['code'] ?? '');
                $name = trim($_POST['name'] ?? '');
                $start_time = $_POST['start_time'] ?? '';
                $end_time = $_POST['end_time'] ?? '';
                $break_minutes = (int)($_POST['break_minutes'] ?? 60);
                $is_night_shift = isset($_POST['is_night_shift']) ? 1 : 0;
                $notes = trim($_POST['notes'] ?? '');
                
                if ($code && $name && $start_time && $end_time) {
                    $stmt = $db->prepare("
                        INSERT INTO shift_templates (code, name, start_time, end_time, break_minutes, is_night_shift, notes, created_at, updated_at)
                        VALUES (:code, :name, :start_time, :end_time, :break_minutes, :is_night_shift, :notes, NOW(), NOW())
                    ");
                    $stmt->execute([
                        ':code' => $code,
                        ':name' => $name,
                        ':start_time' => $start_time,
                        ':end_time' => $end_time,
                        ':break_minutes' => $break_minutes,
                        ':is_night_shift' => $is_night_shift,
                        ':notes' => $notes
                    ]);
                    $_SESSION['success'] = 'Shift template created successfully';
                    header('Location: ' . $_SERVER['PHP_SELF'] . '?view=shift_templates');
                    exit;
                } else {
                    $_SESSION['error'] = 'Please fill in all required fields';
                }
            } elseif ($action === 'delete_shift_template') {
                $id = (int)($_POST['id'] ?? 0);
                if ($id) {
                    $stmt = $db->prepare("DELETE FROM shift_templates WHERE id = :id");
                    $stmt->execute([':id' => $id]);
                    $_SESSION['success'] = 'Shift template deleted successfully';
                    header('Location: ' . $_SERVER['PHP_SELF'] . '?view=shift_templates');
                    exit;
                }
            }
        }
        
        // Get shift templates
        if ($view === 'shift_templates') {
            $stmt = $db->prepare("SELECT * FROM shift_templates ORDER BY name");
            $stmt->execute();
            $shiftTemplates = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }
        
        // Get swap requests
        if ($view === 'swap_cover') {
            // Handle approve/reject actions
            if ($_SERVER['REQUEST_METHOD'] === 'POST') {
                $swapAction = $_POST['swap_action'] ?? '';
                $swapId = (int) ($_POST['swap_id'] ?? 0);
                if ($swapId && in_array($swapAction, ['approve', 'reject'])) {
                    $newStatus = $swapAction === 'approve' ? 'approved' : 'rejected';
                    $adminId = getCurrentEmployeeId($db) ?? $_SESSION['employee_id'] ?? null;
                    $db->prepare("UPDATE shift_swap_requests SET status = ?, reviewed_by = ?, reviewed_at = NOW() WHERE id = ? AND status = 'pending'")
                       ->execute([$newStatus, $adminId, $swapId]);
                    $_SESSION['success'] = 'Swap request ' . $newStatus;
                    header('Location: ' . $_SERVER['PHP_SELF'] . '?view=swap_cover');
                    exit;
                }
            }

            $stmt = $db->prepare("
                SELECT ssr.id, ssr.swap_date, ssr.reason, ssr.status, ssr.created_at,
                       e1.first_name as requester_first, e1.last_name as requester_last, e1.employee_number as requester_emp,
                       e2.first_name as target_first, e2.last_name as target_last, e2.employee_number as target_emp
                FROM shift_swap_requests ssr
                JOIN employees e1 ON ssr.requester_employee_id = e1.id
                LEFT JOIN employees e2 ON ssr.target_employee_id = e2.id
                ORDER BY ssr.created_at DESC
                LIMIT 50
            ");
            $stmt->execute();
            $swapRequests = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }
    } catch (PDOException $e) {
        error_log('Admin Shift Management error: ' . $e->getMessage());
        if (!isset($_SESSION['error'])) {
            $_SESSION['error'] = 'Database error: ' . $e->getMessage();
        }
    }
}

include __DIR__ . '/../../includes/header.php';
?>

<div class="mb-6">
    <h1 class="text-2xl font-semibold text-gray-900 dark:text-white">Shift & Schedule Management</h1>
    <p class="mt-1 text-sm text-gray-600 dark:text-gray-400">Roster calendar, shift templates, and swap requests</p>
</div>

<?php if ($view === 'roster_calendar'): ?>
    <!-- Roster / Schedule Calendar -->
    <div class="bg-white dark:bg-gray-800 rounded-xl shadow-sm border border-gray-100 dark:border-gray-700 mb-6">
        <div class="px-5 py-4 border-b border-gray-100 dark:border-gray-700 flex items-center justify-between">
            <h2 class="text-sm font-semibold text-gray-900 dark:text-white">Roster / Schedule Calendar</h2>
            <div class="flex gap-2">
                <button onclick="changeWeek(-1)" class="px-3 py-1 text-xs border border-gray-300 dark:border-gray-600 rounded hover:bg-gray-50 dark:hover:bg-gray-700">Previous</button>
                <button onclick="changeWeek(0)" class="px-3 py-1 text-xs border border-gray-300 dark:border-gray-600 rounded hover:bg-gray-50 dark:hover:bg-gray-700">This Week</button>
                <button onclick="changeWeek(1)" class="px-3 py-1 text-xs border border-gray-300 dark:border-gray-600 rounded hover:bg-gray-50 dark:hover:bg-gray-700">Next</button>
            </div>
        </div>
        <div class="p-5">
            <?php
            // Fetch roster assignments for the current week
            $weekStart = (new DateTime('monday this week'))->format('Y-m-d');
            $weekEnd = (new DateTime('sunday this week'))->format('Y-m-d');
            $rosterShifts = [];
            if (isset($db)) {
                try {
                    $stmt = $db->prepare("
                        SELECT ra.assignment_date, ra.employee_id, e.first_name, e.last_name, e.employee_number,
                               st.name as shift_name, st.start_time, st.end_time, st.code as shift_code
                        FROM roster_assignments ra
                        JOIN rosters r ON ra.roster_id = r.id
                        JOIN employees e ON ra.employee_id = e.id
                        LEFT JOIN shift_templates st ON ra.shift_template_id = st.id
                        WHERE ra.assignment_date BETWEEN ? AND ?
                        ORDER BY ra.assignment_date, st.start_time, e.last_name
                    ");
                    $stmt->execute([$weekStart, $weekEnd]);
                    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                        $rosterShifts[$row['assignment_date']][] = $row;
                    }
                } catch (PDOException $e) { error_log('Roster calendar: ' . $e->getMessage()); }
            }
            $shiftColors = ['DAY' => 'bg-sky-100 text-sky-800 dark:bg-sky-900 dark:text-sky-200', 'AFT' => 'bg-amber-100 text-amber-800 dark:bg-amber-900 dark:text-amber-200', 'NGT' => 'bg-indigo-100 text-indigo-800 dark:bg-indigo-900 dark:text-indigo-200'];
            ?>
            <div class="grid grid-cols-7 gap-2">
                <?php
                $daysOfWeek = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];
                $currentDay = new DateTime('monday this week');
                for ($i = 0; $i < 7; $i++):
                    $dayStr = $currentDay->format('Y-m-d');
                    $isToday = $dayStr === date('Y-m-d');
                    $dayShifts = $rosterShifts[$dayStr] ?? [];
                ?>
                    <div class="border border-gray-200 dark:border-gray-700 rounded-lg p-3 min-h-[120px] <?php echo $isToday ? 'bg-primary-50 dark:bg-primary-900/30 border-primary-300' : ''; ?>">
                        <div class="text-xs font-semibold text-gray-700 dark:text-gray-300 mb-2 text-center">
                            <div class="font-bold"><?php echo $daysOfWeek[$i]; ?></div>
                            <div class="text-gray-500 mt-1"><?php echo $currentDay->format('M d'); ?></div>
                        </div>
                        <?php if (empty($dayShifts)): ?>
                            <div class="text-xs text-gray-400 text-center mt-4">No shifts</div>
                        <?php else: ?>
                            <div class="space-y-1 max-h-24 overflow-y-auto">
                                <?php foreach (array_slice($dayShifts, 0, 5) as $ds):
                                    $colorCls = $shiftColors[$ds['shift_code'] ?? ''] ?? 'bg-gray-100 text-gray-700 dark:bg-gray-700 dark:text-gray-300';
                                ?>
                                    <div class="px-1.5 py-0.5 rounded text-[10px] leading-tight <?php echo $colorCls; ?>" title="<?php echo htmlspecialchars($ds['first_name'] . ' ' . $ds['last_name'] . ' - ' . ($ds['shift_name'] ?? 'Shift')); ?>">
                                        <?php echo htmlspecialchars(substr($ds['first_name'], 0, 1) . '. ' . $ds['last_name']); ?>
                                        <span class="opacity-70"><?php echo htmlspecialchars($ds['shift_code'] ?? ''); ?></span>
                                    </div>
                                <?php endforeach; ?>
                                <?php if (count($dayShifts) > 5): ?>
                                    <div class="text-[10px] text-gray-500 text-center">+<?php echo count($dayShifts) - 5; ?> more</div>
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

<?php elseif ($view === 'shift_templates'): ?>
    <!-- Shift Templates -->
    <div class="bg-white dark:bg-gray-800 rounded-xl shadow-sm border border-gray-100 dark:border-gray-700 mb-6">
        <div class="px-5 py-4 border-b border-gray-100 dark:border-gray-700 flex items-center justify-between">
            <h2 class="text-sm font-semibold text-gray-900 dark:text-white">Shift Templates</h2>
            <button onclick="openTemplateModal()" class="px-4 py-2 text-xs bg-primary-600 text-white rounded hover:bg-primary-700">
                Add Template
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
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase">Start Time</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase">End Time</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase">Break (min)</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase">Night Shift</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase">Actions</th>
                    </tr>
                </thead>
                <tbody class="bg-white dark:bg-gray-800 divide-y divide-gray-200 dark:divide-gray-700">
                    <?php if (empty($shiftTemplates)): ?>
                        <tr>
                            <td colspan="7" class="px-6 py-4 text-center text-sm text-gray-500">No shift templates found</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($shiftTemplates as $template): ?>
                            <tr>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900 dark:text-white font-semibold">
                                    <?php echo htmlspecialchars($template['code']); ?>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900 dark:text-white">
                                    <?php echo htmlspecialchars($template['name']); ?>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900 dark:text-white">
                                    <?php echo date('H:i', strtotime($template['start_time'])); ?>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900 dark:text-white">
                                    <?php echo date('H:i', strtotime($template['end_time'])); ?>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900 dark:text-white">
                                    <?php echo $template['break_minutes']; ?>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm">
                                    <?php if ($template['is_night_shift']): ?>
                                        <span class="px-2 py-1 text-xs bg-purple-100 text-purple-800 dark:bg-purple-900 dark:text-purple-200 rounded">Yes</span>
                                    <?php else: ?>
                                        <span class="px-2 py-1 text-xs bg-gray-100 text-gray-800 dark:bg-gray-700 dark:text-gray-200 rounded">No</span>
                                    <?php endif; ?>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm">
                                    <div class="inline-flex items-center gap-1">
                                        <button type="button" onclick="openEditTemplateModal(<?php echo $template['id']; ?>)" title="Edit" class="inline-flex p-1.5 rounded-lg text-blue-600 hover:bg-blue-50 dark:hover:bg-blue-900/30 transition-colors">
                                            <svg xmlns="http://www.w3.org/2000/svg" class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z" /></svg>
                                        </button>
                                        <form method="POST" class="inline" onsubmit="return confirm('Delete this shift template?');">
                                            <input type="hidden" name="action" value="delete_shift_template">
                                            <input type="hidden" name="id" value="<?php echo $template['id']; ?>">
                                            <button type="submit" title="Delete" class="inline-flex p-1.5 rounded-lg text-red-600 hover:bg-red-50 dark:hover:bg-red-900/30 transition-colors">
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
    
    <!-- Add/Edit Shift Template Modal -->
    <div id="templateModal" class="hidden fixed inset-0 bg-black bg-opacity-50 z-50 flex items-center justify-center">
        <div class="bg-white dark:bg-gray-800 rounded-xl shadow-xl max-w-md w-full mx-4">
            <div class="px-5 py-4 border-b border-gray-100 dark:border-gray-700">
                <h3 class="text-sm font-semibold text-gray-900 dark:text-white">Add Shift Template</h3>
            </div>
            <form method="POST" class="p-5">
                <input type="hidden" name="action" value="create_shift_template">
                <div class="space-y-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Code <span class="text-red-500">*</span></label>
                        <input type="text" name="code" required maxlength="50" class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg dark:bg-gray-700 dark:text-white" placeholder="e.g., DAY-1">
                        <p class="mt-1 text-xs text-gray-500">Unique code for this shift template</p>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Name <span class="text-red-500">*</span></label>
                        <input type="text" name="name" required maxlength="150" class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg dark:bg-gray-700 dark:text-white" placeholder="e.g., Day Shift 1">
                    </div>
                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Start Time <span class="text-red-500">*</span></label>
                            <input type="time" name="start_time" required class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg dark:bg-gray-700 dark:text-white">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">End Time <span class="text-red-500">*</span></label>
                            <input type="time" name="end_time" required class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg dark:bg-gray-700 dark:text-white">
                        </div>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Break Duration (minutes)</label>
                        <input type="number" name="break_minutes" value="60" min="0" max="480" class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg dark:bg-gray-700 dark:text-white">
                    </div>
                    <div>
                        <label class="flex items-center">
                            <input type="checkbox" name="is_night_shift" value="1" class="rounded border-gray-300 text-primary-600">
                            <span class="ml-2 text-sm text-gray-700 dark:text-gray-300">Night Shift</span>
                        </label>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Notes</label>
                        <textarea name="notes" rows="3" class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg dark:bg-gray-700 dark:text-white" placeholder="Optional notes about this shift template"></textarea>
                    </div>
                </div>
                <div class="flex gap-2 mt-6">
                    <button type="submit" class="flex-1 px-4 py-2 bg-primary-600 text-white rounded-lg hover:bg-primary-700">
                        Create Template
                    </button>
                    <button type="button" onclick="closeTemplateModal()" class="px-4 py-2 border border-gray-300 dark:border-gray-600 rounded-lg hover:bg-gray-50 dark:hover:bg-gray-700">
                        Cancel
                    </button>
                </div>
            </form>
        </div>
    </div>
    
    <script>
    function openTemplateModal() {
        document.getElementById('templateModal').classList.remove('hidden');
    }
    function closeTemplateModal() {
        document.getElementById('templateModal').classList.add('hidden');
    }
    function openEditTemplateModal(id) {
        // TODO: Implement edit functionality
        alert('Edit functionality will be implemented');
    }
    </script>

<?php elseif ($view === 'swap_cover'): ?>
    <!-- Swap / Cover Requests -->
    <div class="bg-white dark:bg-gray-800 rounded-xl shadow-sm border border-gray-100 dark:border-gray-700">
        <div class="px-5 py-4 border-b border-gray-100 dark:border-gray-700">
            <h2 class="text-sm font-semibold text-gray-900 dark:text-white">Swap / Cover Requests</h2>
        </div>
        <?php if (isset($_SESSION['success'])): ?>
            <div class="mx-5 mt-4 bg-emerald-50 dark:bg-emerald-900/20 border border-emerald-200 dark:border-emerald-800 text-emerald-800 dark:text-emerald-200 px-4 py-3 rounded-lg">
                <?php echo htmlspecialchars($_SESSION['success']); unset($_SESSION['success']); ?>
            </div>
        <?php endif; ?>
        <div class="p-5">
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                    <thead class="bg-gray-50 dark:bg-gray-700">
                        <tr>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase">Requester</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase">Swap Date</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase">Reason</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase">Target Employee</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase">Status</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="bg-white dark:bg-gray-800 divide-y divide-gray-200 dark:divide-gray-700">
                        <?php if (empty($swapRequests)): ?>
                            <tr>
                                <td colspan="6" class="px-6 py-4 text-center text-sm text-gray-500">No swap requests found</td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($swapRequests as $request): ?>
                                <tr>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900 dark:text-white">
                                        <?php echo htmlspecialchars(($request['requester_emp'] ?? '') . ' - ' . ($request['requester_first'] ?? '') . ' ' . ($request['requester_last'] ?? '')); ?>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900 dark:text-white">
                                        <?php echo date('M d, Y', strtotime($request['swap_date'])); ?>
                                    </td>
                                    <td class="px-6 py-4 text-sm text-gray-900 dark:text-white max-w-xs truncate">
                                        <?php echo htmlspecialchars($request['reason'] ?? '-'); ?>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900 dark:text-white">
                                        <?php
                                        if (!empty($request['target_emp'])) {
                                            echo htmlspecialchars($request['target_emp'] . ' - ' . $request['target_first'] . ' ' . $request['target_last']);
                                        } else {
                                            echo '<span class="text-gray-400">Open</span>';
                                        }
                                        ?>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm">
                                        <span class="px-2 py-1 text-xs font-semibold rounded-full <?php
                                            echo $request['status'] === 'approved' ? 'bg-emerald-100 text-emerald-800 dark:bg-emerald-900 dark:text-emerald-200' :
                                                ($request['status'] === 'rejected' ? 'bg-red-100 text-red-800 dark:bg-red-900 dark:text-red-200' :
                                                'bg-amber-100 text-amber-800 dark:bg-amber-900 dark:text-amber-200');
                                        ?>">
                                            <?php echo ucfirst($request['status']); ?>
                                        </span>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm">
                                        <?php if ($request['status'] === 'pending'): ?>
                                            <form method="POST" class="inline">
                                                <input type="hidden" name="swap_id" value="<?php echo $request['id']; ?>">
                                                <input type="hidden" name="swap_action" value="approve">
                                                <button type="submit" class="text-emerald-600 hover:text-emerald-700 text-xs mr-2" onclick="return confirm('Approve this swap request?')">Approve</button>
                                            </form>
                                            <form method="POST" class="inline">
                                                <input type="hidden" name="swap_id" value="<?php echo $request['id']; ?>">
                                                <input type="hidden" name="swap_action" value="reject">
                                                <button type="submit" class="text-red-600 hover:text-red-700 text-xs" onclick="return confirm('Reject this swap request?')">Reject</button>
                                            </form>
                                        <?php else: ?>
                                            <span class="text-xs text-gray-400"><?php echo ucfirst($request['status']); ?></span>
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
<?php endif; ?>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
