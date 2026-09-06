<?php
require_once __DIR__ . '/../../config/config.php';
requireAuth();
checkRole(['admin', 'super admin']);

$page_title = 'Holiday Calendar (PH)';

$holidays = [];
$metrics = [
    'total_holidays' => 0,
    'upcoming_holidays' => 0,
    'this_month' => 0,
    'this_year' => 0
];

if (isset($db)) {
    try {
        // Check if holidays table exists, if not create it
        $table_check = $db->query("SHOW TABLES LIKE 'holidays'");
        if ($table_check->rowCount() == 0) {
            $db->exec("
                CREATE TABLE IF NOT EXISTS `holidays` (
                    `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
                    `name` varchar(255) NOT NULL,
                    `date` date NOT NULL,
                    `type` enum('regular','special','regional') DEFAULT 'regular',
                    `is_recurring` tinyint(1) DEFAULT 0,
                    `description` text DEFAULT NULL,
                    `created_at` timestamp NULL DEFAULT NULL,
                    `updated_at` timestamp NULL DEFAULT NULL,
                    PRIMARY KEY (`id`),
                    UNIQUE KEY `date` (`date`, `name`),
                    KEY `idx_holidays_date` (`date`),
                    KEY `idx_holidays_type` (`type`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
            ");
        }
        
        // Get holidays
        $stmt = $db->prepare("
            SELECT * FROM holidays 
            WHERE YEAR(date) >= YEAR(CURDATE()) - 1
            ORDER BY date ASC
        ");
        $stmt->execute();
        $holidays = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Get metrics
        $stmt = $db->prepare("SELECT COUNT(*) as cnt FROM holidays WHERE YEAR(date) = YEAR(CURDATE())");
        $stmt->execute();
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        $metrics['this_year'] = (int)($result['cnt'] ?? 0);
        $metrics['total_holidays'] = (int)($result['cnt'] ?? 0);
        
        $stmt = $db->prepare("
            SELECT COUNT(*) as cnt FROM holidays 
            WHERE date >= CURDATE() 
            AND YEAR(date) = YEAR(CURDATE())
        ");
        $stmt->execute();
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        $metrics['upcoming_holidays'] = (int)($result['cnt'] ?? 0);
        
        $stmt = $db->prepare("
            SELECT COUNT(*) as cnt FROM holidays 
            WHERE MONTH(date) = MONTH(CURDATE()) 
            AND YEAR(date) = YEAR(CURDATE())
        ");
        $stmt->execute();
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        $metrics['this_month'] = (int)($result['cnt'] ?? 0);
        
        // Handle form submissions
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $action = $_POST['action'] ?? '';
            
            if ($action === 'add_holiday') {
                $name = trim($_POST['name'] ?? '');
                $date = $_POST['date'] ?? '';
                $type = $_POST['type'] ?? 'regular';
                $is_recurring = isset($_POST['is_recurring']) ? 1 : 0;
                $description = trim($_POST['description'] ?? '');
                
                if ($name && $date) {
                    $stmt = $db->prepare("
                        INSERT INTO holidays (name, date, type, is_recurring, description, created_at, updated_at)
                        VALUES (:name, :date, :type, :is_recurring, :description, NOW(), NOW())
                    ");
                    $stmt->execute([
                        ':name' => $name,
                        ':date' => $date,
                        ':type' => $type,
                        ':is_recurring' => $is_recurring,
                        ':description' => $description
                    ]);
                    $_SESSION['success'] = 'Holiday added successfully';
                    header('Location: ' . $_SERVER['PHP_SELF']);
                    exit;
                }
            } elseif ($action === 'delete_holiday') {
                $id = (int)($_POST['id'] ?? 0);
                if ($id) {
                    $stmt = $db->prepare("DELETE FROM holidays WHERE id = :id");
                    $stmt->execute([':id' => $id]);
                    $_SESSION['success'] = 'Holiday deleted successfully';
                    header('Location: ' . $_SERVER['PHP_SELF']);
                    exit;
                }
            }
        }
    } catch (PDOException $e) {
        error_log('Holiday Calendar error: ' . $e->getMessage());
    }
}

include __DIR__ . '/../../includes/header.php';
?>

<div class="mb-6">
    <h1 class="text-2xl font-semibold text-gray-900 dark:text-white">Holiday Calendar (PH)</h1>
    <p class="mt-1 text-sm text-gray-600 dark:text-gray-400">Manage Philippine holidays and special non-working days</p>
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
                <p class="text-xs font-medium text-gray-500 dark:text-gray-400 uppercase">Total Holidays</p>
                <p class="mt-2 text-2xl font-bold text-gray-900 dark:text-white"><?php echo $metrics['total_holidays']; ?></p>
                <p class="mt-1 text-xs text-gray-500">this year</p>
            </div>
            <div class="w-12 h-12 rounded-lg bg-blue-100 dark:bg-blue-900 flex items-center justify-center">
                <svg xmlns="http://www.w3.org/2000/svg" class="w-6 h-6 text-blue-600 dark:text-blue-300" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z" />
                </svg>
            </div>
        </div>
    </div>

    <div class="bg-white dark:bg-gray-800 rounded-xl shadow-sm border border-gray-100 dark:border-gray-700 p-5">
        <div class="flex items-center justify-between">
            <div>
                <p class="text-xs font-medium text-gray-500 dark:text-gray-400 uppercase">Upcoming Holidays</p>
                <p class="mt-2 text-2xl font-bold text-gray-900 dark:text-white"><?php echo $metrics['upcoming_holidays']; ?></p>
                <p class="mt-1 text-xs text-emerald-600">remaining this year</p>
            </div>
            <div class="w-12 h-12 rounded-lg bg-emerald-100 dark:bg-emerald-900 flex items-center justify-center">
                <svg xmlns="http://www.w3.org/2000/svg" class="w-6 h-6 text-emerald-600 dark:text-emerald-300" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" />
                </svg>
            </div>
        </div>
    </div>

    <div class="bg-white dark:bg-gray-800 rounded-xl shadow-sm border border-gray-100 dark:border-gray-700 p-5">
        <div class="flex items-center justify-between">
            <div>
                <p class="text-xs font-medium text-gray-500 dark:text-gray-400 uppercase">This Month</p>
                <p class="mt-2 text-2xl font-bold text-gray-900 dark:text-white"><?php echo $metrics['this_month']; ?></p>
                <p class="mt-1 text-xs text-gray-500">holidays</p>
            </div>
            <div class="w-12 h-12 rounded-lg bg-purple-100 dark:bg-purple-900 flex items-center justify-center">
                <svg xmlns="http://www.w3.org/2000/svg" class="w-6 h-6 text-purple-600 dark:text-purple-300" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z" />
                </svg>
            </div>
        </div>
    </div>

    <div class="bg-white dark:bg-gray-800 rounded-xl shadow-sm border border-gray-100 dark:border-gray-700 p-5">
        <div class="flex items-center justify-between">
            <div>
                <p class="text-xs font-medium text-gray-500 dark:text-gray-400 uppercase">This Year</p>
                <p class="mt-2 text-2xl font-bold text-gray-900 dark:text-white"><?php echo $metrics['this_year']; ?></p>
                <p class="mt-1 text-xs text-gray-500"><?php echo date('Y'); ?></p>
            </div>
            <div class="w-12 h-12 rounded-lg bg-amber-100 dark:bg-amber-900 flex items-center justify-center">
                <svg xmlns="http://www.w3.org/2000/svg" class="w-6 h-6 text-amber-600 dark:text-amber-300" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z" />
                </svg>
            </div>
        </div>
    </div>
</div>

<div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
    <!-- Add Holiday Form -->
    <div class="lg:col-span-1">
        <div class="bg-white dark:bg-gray-800 rounded-xl shadow-sm border border-gray-100 dark:border-gray-700">
            <div class="px-5 py-4 border-b border-gray-100 dark:border-gray-700">
                <h2 class="text-sm font-semibold text-gray-900 dark:text-white">Add Holiday</h2>
            </div>
            <div class="p-5">
                <form method="POST" class="space-y-4">
                    <input type="hidden" name="action" value="add_holiday">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Holiday Name</label>
                        <input type="text" name="name" required class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg dark:bg-gray-700 dark:text-white">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Date</label>
                        <input type="date" name="date" required class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg dark:bg-gray-700 dark:text-white">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Type</label>
                        <select name="type" class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg dark:bg-gray-700 dark:text-white">
                            <option value="regular">Regular Holiday</option>
                            <option value="special">Special Non-Working Day</option>
                            <option value="regional">Regional Holiday</option>
                        </select>
                    </div>
                    <div>
                        <label class="flex items-center">
                            <input type="checkbox" name="is_recurring" value="1" class="rounded border-gray-300 text-primary-600">
                            <span class="ml-2 text-sm text-gray-700 dark:text-gray-300">Recurring (annual)</span>
                        </label>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Description</label>
                        <textarea name="description" rows="3" class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg dark:bg-gray-700 dark:text-white"></textarea>
                    </div>
                    <button type="submit" class="w-full px-4 py-2 bg-primary-600 text-white rounded-lg hover:bg-primary-700">
                        Add Holiday
                    </button>
                </form>
            </div>
        </div>
    </div>

    <!-- Holiday Calendar View -->
    <div class="lg:col-span-2">
        <div class="bg-white dark:bg-gray-800 rounded-xl shadow-sm border border-gray-100 dark:border-gray-700">
            <div class="px-5 py-4 border-b border-gray-100 dark:border-gray-700 flex items-center justify-between">
                <h2 class="text-sm font-semibold text-gray-900 dark:text-white">Holiday Calendar</h2>
                <div class="flex gap-2">
                    <select id="filterYear" onchange="filterHolidays()" class="text-xs border border-gray-300 dark:border-gray-600 rounded px-2 py-1 dark:bg-gray-700 dark:text-white">
                        <option value="<?php echo date('Y') - 1; ?>"><?php echo date('Y') - 1; ?></option>
                        <option value="<?php echo date('Y'); ?>" selected><?php echo date('Y'); ?></option>
                        <option value="<?php echo date('Y') + 1; ?>"><?php echo date('Y') + 1; ?></option>
                    </select>
                    <button onclick="exportHolidays()" class="px-3 py-1 text-xs border border-gray-300 dark:border-gray-600 rounded hover:bg-gray-50 dark:hover:bg-gray-700">
                        Export
                    </button>
                </div>
            </div>
            <div class="p-5">
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                        <thead class="bg-gray-50 dark:bg-gray-700">
                            <tr>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase">Date</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase">Holiday Name</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase">Type</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase">Recurring</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase">Actions</th>
                            </tr>
                        </thead>
                        <tbody class="bg-white dark:bg-gray-800 divide-y divide-gray-200 dark:divide-gray-700">
                            <?php if (empty($holidays)): ?>
                                <tr>
                                    <td colspan="5" class="px-6 py-4 text-center text-sm text-gray-500">No holidays found. Add a holiday to get started.</td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($holidays as $holiday): 
                                    $isPast = strtotime($holiday['date']) < time();
                                    $isToday = $holiday['date'] === date('Y-m-d');
                                ?>
                                    <tr class="<?php echo $isToday ? 'bg-primary-50 dark:bg-primary-900/20' : ''; ?>">
                                        <td class="px-6 py-4 whitespace-nowrap text-sm <?php echo $isPast ? 'text-gray-500' : 'text-gray-900 dark:text-white font-semibold'; ?>">
                                            <?php echo date('M d, Y', strtotime($holiday['date'])); ?>
                                            <?php if ($isToday): ?>
                                                <span class="ml-2 px-2 py-0.5 text-xs bg-primary-600 text-white rounded">Today</span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="px-6 py-4 text-sm text-gray-900 dark:text-white">
                                            <?php echo htmlspecialchars($holiday['name']); ?>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm">
                                            <span class="px-2 py-1 text-xs font-semibold rounded-full <?php 
                                                echo $holiday['type'] === 'regular' ? 'bg-blue-100 text-blue-800 dark:bg-blue-900 dark:text-blue-200' : 
                                                    ($holiday['type'] === 'special' ? 'bg-amber-100 text-amber-800 dark:bg-amber-900 dark:text-amber-200' : 
                                                    'bg-purple-100 text-purple-800 dark:bg-purple-900 dark:text-purple-200');
                                            ?>">
                                                <?php echo ucfirst($holiday['type']); ?>
                                            </span>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900 dark:text-white">
                                            <?php if ($holiday['is_recurring']): ?>
                                                <span class="text-emerald-600">Yes</span>
                                            <?php else: ?>
                                                <span class="text-gray-400">No</span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm">
                                            <form method="POST" class="inline" onsubmit="return confirm('Delete this holiday?');">
                                                <input type="hidden" name="action" value="delete_holiday">
                                                <input type="hidden" name="id" value="<?php echo $holiday['id']; ?>">
                                                <button type="submit" class="text-red-600 hover:text-red-700 text-xs">Delete</button>
                                            </form>
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
</div>

<script>
function filterHolidays() {
    const year = document.getElementById('filterYear').value;
    // Implement year filtering
    window.location.href = '?year=' + year;
}

function exportHolidays() {
    const table = document.querySelector('table');
    if (!table) return;
    const rows = table.querySelectorAll('tbody tr');
    let csv = 'Date,Holiday Name,Type,Recurring\n';
    rows.forEach(r => {
        const cells = r.querySelectorAll('td');
        if (cells.length >= 4) {
            const date = cells[0]?.textContent?.trim().replace(/\s+/g, ' ') || '';
            const name = cells[1]?.textContent?.trim() || '';
            const type = cells[2]?.textContent?.trim() || '';
            const recurring = cells[3]?.textContent?.trim() || '';
            csv += '"' + date + '","' + name + '","' + type + '","' + recurring + '"\n';
        }
    });
    const blob = new Blob(['\uFEFF' + csv], { type: 'text/csv;charset=utf-8;' });
    const link = document.createElement('a');
    link.href = URL.createObjectURL(blob);
    link.download = 'hr3_holidays_' + new Date().toISOString().slice(0,10) + '.csv';
    link.click();
}
</script>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
