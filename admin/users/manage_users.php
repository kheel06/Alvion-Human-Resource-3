<?php
/**
 * User Management
 * Admin can create user accounts and lock/unlock accounts
 * Includes both users and department_accounts
 */
require_once '../../config/config.php';
requireAuth();
checkRole(['admin']);

$page_title = "User Management";

// Handle actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action'])) {
        $action = $_POST['action'];
        $user_id = $_POST['user_id'] ?? null;
        $account_type = $_POST['account_type'] ?? 'users'; // 'users' or 'department_accounts'
        
        if ($action === 'lock' && $user_id) {
            try {
                if ($account_type === 'department_accounts') {
                    $stmt = $db->prepare("UPDATE department_accounts SET is_active = 0 WHERE employee_id = :id");
                } else {
                    $stmt = $db->prepare("UPDATE users SET status = 'inactive' WHERE id = :id");
                }
                $stmt->bindParam(':id', $user_id);
                $stmt->execute();
                $_SESSION['success'] = "Account locked successfully.";
            } catch (PDOException $e) {
                $_SESSION['error'] = "Error locking account: " . $e->getMessage();
            }
        } elseif ($action === 'unlock' && $user_id) {
            try {
                if ($account_type === 'department_accounts') {
                    $stmt = $db->prepare("UPDATE department_accounts SET is_active = 1 WHERE employee_id = :id");
                } else {
                    $stmt = $db->prepare("UPDATE users SET status = 'active' WHERE id = :id");
                }
                $stmt->bindParam(':id', $user_id);
                $stmt->execute();
                $_SESSION['success'] = "Account unlocked successfully.";
            } catch (PDOException $e) {
                $_SESSION['error'] = "Error unlocking account: " . $e->getMessage();
            }
        } elseif ($action === 'delete' && $user_id) {
            try {
                if ($account_type === 'department_accounts') {
                    $stmt = $db->prepare("UPDATE department_accounts SET is_active = 0 WHERE employee_id = :id");
                } else {
                    $stmt = $db->prepare("UPDATE users SET status = 'deleted', deleted_at = NOW() WHERE id = :id");
                }
                $stmt->bindParam(':id', $user_id);
                $stmt->execute();
                $_SESSION['success'] = "Account deleted successfully.";
            } catch (PDOException $e) {
                $_SESSION['error'] = "Error deleting account: " . $e->getMessage();
            }
        }
        
        header("Location: manage_users.php");
        exit();
    }
}

// Get filter parameters
$search = $_GET['search'] ?? '';
$initial_filter = $_GET['initial'] ?? ''; // Filter by first name initial (A-Z)
$role_filter = $_GET['role'] ?? 'all';
$status_filter = $_GET['status'] ?? 'all';
$account_type_filter = $_GET['account_type'] ?? 'all'; // 'all', 'users', 'department_accounts'

// Build query for users table (exclude doctors and admins)
$users_query = "SELECT 
    u.id as user_id,
    e.first_name,
    e.last_name,
    e.first_name as sort_name,
    COALESCE(e.email, u.email) as email,
    u.username,
    u.is_active,
    CASE WHEN u.is_active = 1 THEN 'active' ELSE 'inactive' END as status,
    u.last_login_at as last_login,
    u.created_at,
    e.photo_path as profile_picture,
    u.updated_at,
    r.role_name,
    'users' as account_type
    FROM users u 
    LEFT JOIN roles r ON u.role_id = r.id 
    LEFT JOIN employees e ON u.employee_id = e.id
    WHERE u.deleted_at IS NULL";

$users_params = [];

if ($search) {
    $users_query .= " AND (CONCAT(e.first_name, ' ', COALESCE(e.middle_name, ''), ' ', e.last_name, ' ', COALESCE(e.suffix, '')) LIKE :search_users 
                  OR e.first_name LIKE :search_users 
                  OR e.last_name LIKE :search_users 
                  OR COALESCE(e.email, u.email) LIKE :search_users 
                  OR u.username LIKE :search_users)";
    $users_params[':search_users'] = "%$search%";
}

if (!empty($initial_filter) && strlen($initial_filter) === 1) {
    $users_query .= " AND UPPER(SUBSTRING(e.first_name, 1, 1)) = :initial_users";
    $users_params[':initial_users'] = strtoupper($initial_filter);
}

if ($role_filter !== 'all') {
    $users_query .= " AND u.role_id = :role_id_users";
    $users_params[':role_id_users'] = $role_filter;
}

if ($status_filter !== 'all') {
    if ($status_filter === 'active') {
        $users_query .= " AND u.is_active = 1";
    } elseif ($status_filter === 'inactive' || $status_filter === 'locked') {
        $users_query .= " AND u.is_active = 0";
    }
}

// Build query for department_accounts table (exclude doctors and admins)
$dept_query = "SELECT 
    da.employee_id as user_id,
    da.employee_fname as first_name,
    da.employee_lname as last_name,
    da.employee_fname as sort_name,
    da.employee_email as email,
    da.employee_id as username,
    CASE WHEN da.is_active = 1 THEN 'active' ELSE 'inactive' END as status,
    da.last_login,
    da.created_at,
    da.profile_picture,
    da.updated_at,
    da.role_name,
    'department_accounts' as account_type
    FROM department_accounts da";

$dept_params = [];

if ($search) {
    $dept_query .= " AND (CONCAT(da.employee_fname, ' ', COALESCE(da.employee_mname, ''), ' ', da.employee_lname) LIKE :search_dept 
                  OR da.employee_fname LIKE :search_dept 
                  OR da.employee_lname LIKE :search_dept 
                  OR da.employee_email LIKE :search_dept 
                  OR da.employee_id LIKE :search_dept)";
    $dept_params[':search_dept'] = "%$search%";
}

if (!empty($initial_filter) && strlen($initial_filter) === 1) {
    $dept_query .= " AND UPPER(SUBSTRING(da.employee_fname, 1, 1)) = :initial_dept";
    $dept_params[':initial_dept'] = strtoupper($initial_filter);
}

if ($role_filter !== 'all') {
    // For department_accounts, we need to match role_name
    try {
        $role_name_stmt = $db->prepare("SELECT role_name FROM roles WHERE id = :role_id");
        $role_name_stmt->bindParam(':role_id', $role_filter);
        $role_name_stmt->execute();
        $role_data = $role_name_stmt->fetch();
        if ($role_data) {
            $target_role = normalizeRoleName($role_data['role_name']);
            $dept_query .= " AND LOWER(da.role_name) = :role_name_dept";
            $dept_params[':role_name_dept'] = $target_role;
        }
    } catch (PDOException $e) {
        // If role lookup fails, skip the filter
    }
}

if ($status_filter !== 'all') {
    if ($status_filter === 'active') {
        $dept_query .= " AND da.is_active = 1";
    } elseif ($status_filter === 'inactive' || $status_filter === 'locked') {
        $dept_query .= " AND da.is_active = 0";
    }
}

// Combine queries based on account_type filter
if ($account_type_filter === 'users') {
    $query = $users_query . " ORDER BY sort_name ASC, last_name ASC";
    $params = $users_params;
} elseif ($account_type_filter === 'department_accounts') {
    $query = $dept_query . " ORDER BY sort_name ASC, last_name ASC";
    $params = $dept_params;
} else {
    // Combine both with UNION
    $query = "(" . $users_query . ") UNION (" . $dept_query . ") ORDER BY sort_name ASC, last_name ASC";
    $params = array_merge($users_params, $dept_params);
}

$stmt = $db->prepare($query);
foreach ($params as $key => $value) {
    $stmt->bindValue($key, $value);
}
$stmt->execute();
$all_users = $stmt->fetchAll();

$allowed_role_map = array_flip(getAllowedRoleNames());
$all_users = array_values(array_filter($all_users, function ($user) use ($allowed_role_map) {
    if (empty($user['role_name'])) {
        return true;
    }
    return isset($allowed_role_map[normalizeRoleName($user['role_name'])]);
}));

// Get roles for filter
$roles = getSystemRoles($db);

// Get statistics - combine both tables (exclude doctors and admins)
try {
    $stats_users = $db->query("SELECT 
        COUNT(*) as total,
        SUM(CASE WHEN u.is_active = 1 THEN 1 ELSE 0 END) as active,
        SUM(CASE WHEN u.is_active = 0 THEN 1 ELSE 0 END) as inactive
        FROM users u
        WHERE u.deleted_at IS NULL")->fetch();
    
    $stats_dept = $db->query("SELECT 
        COUNT(*) as total,
        SUM(CASE WHEN is_active = 1 THEN 1 ELSE 0 END) as active,
        SUM(CASE WHEN is_active = 0 THEN 1 ELSE 0 END) as inactive
        FROM department_accounts")->fetch();
    
    // For display: 'locked' represents inactive accounts
    $stats = [
        'total' => ($stats_users['total'] ?? 0) + ($stats_dept['total'] ?? 0),
        'active' => ($stats_users['active'] ?? 0) + ($stats_dept['active'] ?? 0),
        'locked' => ($stats_users['inactive'] ?? 0) + ($stats_dept['inactive'] ?? 0), // Inactive = locked
        'inactive' => ($stats_users['inactive'] ?? 0) + ($stats_dept['inactive'] ?? 0)
    ];
} catch (PDOException $e) {
    $stats = ['total' => 0, 'active' => 0, 'locked' => 0, 'inactive' => 0];
}

include '../../includes/header.php';
?>

<div class="mb-6">
    <div class="flex justify-between items-center">
        <div>
            <h1 class="text-2xl font-bold text-gray-900 dark:text-white">User Management</h1>
            <p class="text-gray-600 dark:text-gray-400">Create user accounts and manage access</p>
        </div>
        <a href="create_user.php" class="px-4 py-2 bg-primary-600 text-white rounded-md hover:bg-primary-700 flex items-center">
            <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="mr-2"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"></path><circle cx="9" cy="7" r="4"></circle><path d="M22 21v-2a4 4 0 0 0-3-3.87"></path><path d="M16 3.13a4 4 0 0 1 0 7.75"></path></svg>
            Create User Account
        </a>
    </div>
</div>

<!-- Statistics -->
<div class="grid grid-cols-1 gap-6 sm:grid-cols-4 mb-6">
    <div class="bg-white dark:bg-gray-800 shadow rounded-lg p-4">
        <p class="text-sm text-gray-500 dark:text-gray-400">Total Users</p>
        <p class="text-2xl font-bold text-gray-900 dark:text-white"><?php echo $stats['total']; ?></p>
    </div>
    <div class="bg-white dark:bg-gray-800 shadow rounded-lg p-4">
        <p class="text-sm text-gray-500 dark:text-gray-400">Active</p>
        <p class="text-2xl font-bold text-green-600"><?php echo $stats['active']; ?></p>
    </div>
    <div class="bg-white dark:bg-gray-800 shadow rounded-lg p-4">
        <p class="text-sm text-gray-500 dark:text-gray-400">Locked</p>
        <p class="text-2xl font-bold text-red-600"><?php echo $stats['locked']; ?></p>
    </div>
    <div class="bg-white dark:bg-gray-800 shadow rounded-lg p-4">
        <p class="text-sm text-gray-500 dark:text-gray-400">Inactive</p>
        <p class="text-2xl font-bold text-orange-600"><?php echo $stats['inactive']; ?></p>
    </div>
</div>

<!-- Filters -->
<div class="bg-white dark:bg-gray-800 shadow rounded-lg mb-6">
    <div class="px-4 py-5 sm:p-6">
        <!-- Search and Filter Controls -->
        <div class="mb-4 flex items-center justify-end gap-0">
            <!-- Search Icon (transforms to search bar) -->
            <div class="relative">
                <div id="searchIconContainer" class="flex items-center <?php echo !empty($search) ? 'hidden' : ''; ?>">
                    <button type="button" onclick="toggleSearchBar()" 
                            class="p-2 text-gray-500 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-200 hover:bg-gray-100 dark:hover:bg-gray-700 rounded-l-md transition-colors border border-r-0 border-gray-300 dark:border-gray-600">
                        <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <circle cx="11" cy="11" r="8"></circle>
                            <path d="m21 21-4.35-4.35"></path>
                        </svg>
                    </button>
                </div>
                <div id="searchBarContainer" class="<?php echo !empty($search) ? '' : 'hidden'; ?>">
                    <div class="relative">
                        <input type="text" 
                               id="searchInput" 
                               value="<?php echo htmlspecialchars($search); ?>" 
                               placeholder="Search by full name..."
                               autocomplete="off"
                               class="w-64 border border-gray-300 dark:border-gray-600 rounded-l-md shadow-sm py-2 pl-10 pr-4 focus:outline-none focus:ring-primary-500 focus:border-primary-500 dark:bg-gray-700 dark:text-white">
                        <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="text-gray-400">
                                <circle cx="11" cy="11" r="8"></circle>
                                <path d="m21 21-4.35-4.35"></path>
                            </svg>
                        </div>
                        <button type="button" onclick="closeSearchBar()" 
                                class="absolute inset-y-0 right-0 pr-3 flex items-center text-gray-400 hover:text-gray-600 dark:hover:text-gray-300">
                            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <line x1="18" y1="6" x2="6" y2="18"></line>
                                <line x1="6" y1="6" x2="18" y2="18"></line>
                            </svg>
                        </button>
                    </div>
                </div>
            </div>
            
            <!-- Filter by Initial (A-Z) -->
            <div class="relative">
                <button type="button" onclick="toggleFilterMenu()" 
                        class="p-2 text-gray-500 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-200 hover:bg-gray-100 dark:hover:bg-gray-700 rounded-r-md transition-colors border border-l-0 border-gray-300 dark:border-gray-600">
                    <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <polygon points="22 3 2 3 10 12.46 10 19 14 21 14 12.46 22 3"></polygon>
                    </svg>
                </button>
                <div id="filterMenu" class="hidden absolute right-0 mt-2 w-80 bg-white dark:bg-gray-800 rounded-md shadow-lg z-10 border border-gray-200 dark:border-gray-700 p-4">
                    <div class="mb-4">
                        <div class="mb-2 text-sm font-medium text-gray-700 dark:text-gray-300">Filter by First Name Initial</div>
                        <div class="grid grid-cols-6 gap-2">
                            <a href="?<?php echo http_build_query(array_merge($_GET, ['initial' => ''])); ?>" 
                               class="px-2 py-1 text-center text-sm rounded <?php echo empty($initial_filter) ? 'bg-primary-600 text-white' : 'bg-gray-100 dark:bg-gray-700 text-gray-700 dark:text-gray-300 hover:bg-gray-200 dark:hover:bg-gray-600'; ?>">
                                All
                            </a>
                            <?php foreach (range('A', 'Z') as $letter): ?>
                                <a href="?<?php echo http_build_query(array_merge($_GET, ['initial' => $letter])); ?>" 
                                   class="px-2 py-1 text-center text-sm rounded <?php echo $initial_filter === $letter ? 'bg-primary-600 text-white' : 'bg-gray-100 dark:bg-gray-700 text-gray-700 dark:text-gray-300 hover:bg-gray-200 dark:hover:bg-gray-600'; ?>">
                                    <?php echo $letter; ?>
                                </a>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    
                    <div class="border-t border-gray-200 dark:border-gray-700 pt-4 space-y-3">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Role</label>
                            <select id="roleFilter" onchange="applyFilter()" class="w-full border border-gray-300 dark:border-gray-600 rounded-md shadow-sm py-2 px-3 dark:bg-gray-700 dark:text-white text-sm">
                                <option value="all" <?php echo $role_filter === 'all' ? 'selected' : ''; ?>>All Roles</option>
                                <?php foreach ($roles as $role): ?>
                                    <option value="<?php echo $role['id']; ?>" <?php echo $role_filter == $role['id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($role['display_name'] ?? getRoleDisplayName($role['role_name'])); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Status</label>
                            <select id="statusFilter" onchange="applyFilter()" class="w-full border border-gray-300 dark:border-gray-600 rounded-md shadow-sm py-2 px-3 dark:bg-gray-700 dark:text-white text-sm">
                                <option value="all" <?php echo $status_filter === 'all' ? 'selected' : ''; ?>>All Status</option>
                                <option value="active" <?php echo $status_filter === 'active' ? 'selected' : ''; ?>>Active</option>
                                <option value="locked" <?php echo $status_filter === 'locked' ? 'selected' : ''; ?>>Locked</option>
                                <option value="inactive" <?php echo $status_filter === 'inactive' ? 'selected' : ''; ?>>Inactive</option>
                            </select>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Account Type</label>
                            <select id="accountTypeFilter" onchange="applyFilter()" class="w-full border border-gray-300 dark:border-gray-600 rounded-md shadow-sm py-2 px-3 dark:bg-gray-700 dark:text-white text-sm">
                                <option value="all" <?php echo $account_type_filter === 'all' ? 'selected' : ''; ?>>All Types</option>
                                <option value="users" <?php echo $account_type_filter === 'users' ? 'selected' : ''; ?>>Users</option>
                                <option value="department_accounts" <?php echo $account_type_filter === 'department_accounts' ? 'selected' : ''; ?>>Department Accounts</option>
                            </select>
                        </div>
                    </div>
                </div>
            </div>
            
            <?php if (!empty($search) || !empty($initial_filter) || $role_filter !== 'all' || $status_filter !== 'all' || $account_type_filter !== 'all'): ?>
                <a href="manage_users.php" 
                   class="ml-2 px-3 py-2 text-sm bg-gray-200 text-gray-700 rounded-md hover:bg-gray-300 dark:bg-gray-600 dark:text-gray-200 dark:hover:bg-gray-500">
                    Clear
                </a>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Users Cards -->
<div class="mb-6">
    <div class="flex justify-between items-center mb-4">
        <h3 class="text-lg leading-6 font-medium text-gray-900 dark:text-white">User Accounts</h3>
        <p class="text-sm text-gray-500 dark:text-gray-400"><?php echo count($all_users); ?> user(s) found</p>
    </div>
    
    <?php if (count($all_users) > 0): ?>
        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 gap-6">
            <?php foreach ($all_users as $user): ?>
                <?php
                // Get profile picture path
                $profile_picture = $user['profile_picture'] ?? null;
                $profile_path = null;
                $cache_buster = '';
                
                if (!empty($profile_picture)) {
                    // Check if it's a relative path or absolute
                    if (strpos($profile_picture, 'http') === 0) {
                        $profile_path = $profile_picture;
                    } else {
                        // Build full path
                        $full_path = __DIR__ . '/../../' . ltrim($profile_picture, '/');
                        if (file_exists($full_path)) {
                            $profile_path = BASE_URL . '/' . ltrim($profile_picture, '/');
                            // Add cache buster using file modification time for real-time updates
                            $cache_buster = '?t=' . filemtime($full_path);
                        }
                    }
                }
                
                // Generate initials as fallback
                $first_initial = strtoupper(substr($user['first_name'] ?? '', 0, 1));
                $last_initial = strtoupper(substr($user['last_name'] ?? '', 0, 1));
                $initials = $first_initial . $last_initial;
                
                // Determine status display
                $status = $user['status'] ?? 'inactive';
                $display_status = $status;
                
                // For users table, 'inactive' means locked
                if ($user['account_type'] === 'users' && $status === 'inactive') {
                    $display_status = 'locked';
                }
                
                // Status badge classes
                $status_classes = '';
                if ($status === 'active') {
                    $status_classes = 'bg-green-100 text-green-800 dark:bg-green-900 dark:text-green-200';
                } elseif ($display_status === 'locked' || $status === 'inactive') {
                    $status_classes = 'bg-red-100 text-red-800 dark:bg-red-900 dark:text-red-200';
                } else {
                    $status_classes = 'bg-gray-100 text-gray-800 dark:bg-gray-700 dark:text-gray-200';
                }
                
                // Account type badge classes
                $account_type_classes = $user['account_type'] === 'department_accounts' 
                    ? 'bg-purple-100 text-purple-800 dark:bg-purple-900 dark:text-purple-200' 
                    : 'bg-gray-100 text-gray-800 dark:bg-gray-700 dark:text-gray-200';
                ?>
                
                <div class="bg-white dark:bg-gray-800 rounded-xl shadow-md hover:shadow-lg transition-all duration-300 border border-gray-200 dark:border-gray-700 overflow-hidden group">
                    <!-- Card Header with Status Indicator -->
                    <div class="relative h-2 <?php echo $status === 'active' ? 'bg-green-500' : 'bg-red-500'; ?>"></div>
                    
                    <!-- Card Body -->
                    <div class="p-6">
                        <!-- Profile Section -->
                        <div class="flex flex-col items-center mb-4">
                            <div class="relative mb-3">
                                <?php if ($profile_path): ?>
                                    <img src="<?php echo htmlspecialchars($profile_path . $cache_buster); ?>" 
                                         alt="<?php echo htmlspecialchars(($user['first_name'] ?? '') . ' ' . ($user['last_name'] ?? '')); ?>"
                                         class="h-20 w-20 rounded-full object-cover border-4 border-gray-200 dark:border-gray-700 shadow-md"
                                         onerror="this.style.display='none'; this.nextElementSibling.style.display='flex';">
                                    <div class="h-20 w-20 rounded-full bg-primary-100 dark:bg-primary-900 flex items-center justify-center hidden border-4 border-gray-200 dark:border-gray-700 shadow-md">
                                        <span class="text-primary-600 dark:text-primary-300 font-semibold text-xl">
                                            <?php echo htmlspecialchars($initials); ?>
                                        </span>
                                    </div>
                                <?php else: ?>
                                    <div class="h-20 w-20 rounded-full bg-primary-100 dark:bg-primary-900 flex items-center justify-center border-4 border-gray-200 dark:border-gray-700 shadow-md">
                                        <span class="text-primary-600 dark:text-primary-300 font-semibold text-xl">
                                            <?php echo htmlspecialchars($initials); ?>
                                        </span>
                                    </div>
                                <?php endif; ?>
                                
                                <!-- Status Indicator Dot -->
                                <div class="absolute bottom-0 right-0 h-5 w-5 rounded-full border-2 border-white dark:border-gray-800 <?php echo $status === 'active' ? 'bg-green-500' : 'bg-red-500'; ?>"></div>
                            </div>
                            
                            <h4 class="text-lg font-semibold text-gray-900 dark:text-white text-center mb-1">
                                <?php echo htmlspecialchars(($user['first_name'] ?? '') . ' ' . ($user['last_name'] ?? '')); ?>
                            </h4>
                            <p class="text-sm text-gray-500 dark:text-gray-400 mb-3">
                                @<?php echo htmlspecialchars($user['username'] ?? 'N/A'); ?>
                            </p>
                            
                            <!-- Badges -->
                            <div class="flex flex-wrap gap-2 justify-center mb-4">
                                <span class="px-3 py-1 text-xs font-semibold rounded-full bg-blue-100 text-blue-800 dark:bg-blue-900 dark:text-blue-200">
                                    <?php
                                        $roleLabel = $user['role_name'] ?? null;
                                        echo htmlspecialchars($roleLabel ? getRoleDisplayName($roleLabel) : 'No Role');
                                    ?>
                                </span>
                                <span class="px-3 py-1 text-xs font-semibold rounded-full <?php echo $account_type_classes; ?>">
                                    <?php echo $user['account_type'] === 'department_accounts' ? 'Dept Account' : 'User'; ?>
                                </span>
                            </div>
                        </div>
                        
                        <!-- Divider -->
                        <div class="border-t border-gray-200 dark:border-gray-700 my-4"></div>
                        
                        <!-- User Details -->
                        <div class="space-y-3 mb-4">
                            <div class="flex items-start">
                                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="text-gray-400 dark:text-gray-500 mt-0.5 mr-2 flex-shrink-0">
                                    <path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"></path>
                                    <polyline points="22,6 12,13 2,6"></polyline>
                                </svg>
                                <div class="flex-1 min-w-0">
                                    <p class="text-xs text-gray-500 dark:text-gray-400">Email</p>
                                    <p class="text-sm font-medium text-gray-900 dark:text-white truncate" title="<?php echo htmlspecialchars($user['email'] ?? 'N/A'); ?>">
                                        <?php echo htmlspecialchars($user['email'] ?? 'N/A'); ?>
                                    </p>
                                </div>
                            </div>
                            
                            <div class="flex items-start">
                                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="text-gray-400 dark:text-gray-500 mt-0.5 mr-2 flex-shrink-0">
                                    <circle cx="12" cy="12" r="10"></circle>
                                    <polyline points="12 6 12 12 16 14"></polyline>
                                </svg>
                                <div class="flex-1 min-w-0">
                                    <p class="text-xs text-gray-500 dark:text-gray-400">Last Login</p>
                                    <p class="text-sm font-medium text-gray-900 dark:text-white">
                                        <?php echo $user['last_login'] ? date('M d, Y H:i', strtotime($user['last_login'])) : 'Never'; ?>
                                    </p>
                                </div>
                            </div>
                            
                            <div class="flex items-center">
                                <span class="px-3 py-1 text-xs font-semibold rounded-full <?php echo $status_classes; ?>">
                                    <?php echo ucfirst($display_status); ?>
                                </span>
                            </div>
                        </div>
                        
                        <!-- Action Button -->
                        <?php
                            $user_payload = $user;
                            $user_payload['role_display_name'] = $user['role_name'] ? getRoleDisplayName($user['role_name']) : 'No Role';
                        ?>
                        <button onclick="openUserModal(<?php echo htmlspecialchars(json_encode($user_payload)); ?>)" 
                                class="w-full mt-4 px-4 py-2.5 bg-primary-600 hover:bg-primary-700 text-white text-sm font-medium rounded-lg transition-colors duration-200 flex items-center justify-center group-hover:shadow-md">
                            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="mr-2">
                                <path d="M2 12s3-7 10-7 10 7 10 7-3 7-10 7-10-7-10-7Z"></path>
                                <circle cx="12" cy="12" r="3"></circle>
                            </svg>
                            View Details
                        </button>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php else: ?>
        <div class="bg-white dark:bg-gray-800 shadow rounded-lg">
            <div class="text-center py-12">
                <svg xmlns="http://www.w3.org/2000/svg" width="64" height="64" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="text-gray-300 dark:text-gray-600 mx-auto mb-4">
                    <path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"></path>
                    <circle cx="9" cy="7" r="4"></circle>
                    <path d="M22 21v-2a4 4 0 0 0-3-3.87"></path>
                    <path d="M16 3.13a4 4 0 0 1 0 7.75"></path>
                </svg>
                <h3 class="text-lg font-medium text-gray-900 dark:text-white mb-2">No users found</h3>
                <p class="text-gray-500 dark:text-gray-400">Try adjusting your search or filter criteria</p>
            </div>
        </div>
    <?php endif; ?>
</div>

<!-- User Details Modal -->
<div id="userModal" class="hidden fixed inset-0 z-50 overflow-y-auto flex items-center justify-center p-4">
    <!-- Background overlay -->
    <div class="fixed inset-0 transition-opacity bg-gray-500 bg-opacity-75 dark:bg-gray-900 dark:bg-opacity-75" onclick="closeUserModal()"></div>

    <!-- Modal panel -->
    <div class="relative bg-white dark:bg-gray-800 rounded-lg text-left overflow-hidden shadow-xl transform transition-all max-w-2xl w-full max-h-[90vh] overflow-y-auto">
        <div class="bg-white dark:bg-gray-800 px-4 pt-5 pb-4 sm:p-6 sm:pb-4">
            <div class="flex items-center justify-end mb-4">
                <button onclick="closeUserModal()" class="text-gray-400 hover:text-gray-500 dark:hover:text-gray-300">
                    <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <line x1="18" y1="6" x2="6" y2="18"></line>
                        <line x1="6" y1="6" x2="18" y2="18"></line>
                    </svg>
                </button>
            </div>
            
            <div id="modalContent" class="space-y-4">
                <!-- Content will be populated by JavaScript -->
            </div>
        </div>
        
        <div class="bg-gray-50 dark:bg-gray-700 px-4 py-3 sm:px-6 sm:flex sm:flex-row-reverse border-t border-gray-200 dark:border-gray-600">
                <div class="flex space-x-2">
                    <button id="modalEditBtn" onclick="editUser()" class="w-full sm:w-auto inline-flex justify-center rounded-md border border-transparent shadow-sm px-4 py-2 bg-primary-600 text-base font-medium text-white hover:bg-primary-700 sm:ml-3 sm:w-auto sm:text-sm">
                        Edit
                    </button>
                    <button id="modalLockBtn" onclick="lockUnlockUser()" class="w-full sm:w-auto inline-flex justify-center rounded-md border border-gray-300 dark:border-gray-600 shadow-sm px-4 py-2 bg-white dark:bg-gray-600 text-base font-medium text-gray-700 dark:text-gray-200 hover:bg-gray-50 dark:hover:bg-gray-500 sm:ml-3 sm:w-auto sm:text-sm">
                        Lock
                    </button>
                    <button onclick="closeUserModal()" class="mt-3 w-full sm:mt-0 sm:w-auto inline-flex justify-center rounded-md border border-gray-300 dark:border-gray-600 shadow-sm px-4 py-2 bg-white dark:bg-gray-600 text-base font-medium text-gray-700 dark:text-gray-200 hover:bg-gray-50 dark:hover:bg-gray-500 sm:ml-3 sm:w-auto sm:text-sm">
                        Close
                    </button>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
let currentUser = null;

function openUserModal(user) {
    currentUser = user;
    const modal = document.getElementById('userModal');
    const modalContent = document.getElementById('modalContent');
    const editBtn = document.getElementById('modalEditBtn');
    const lockBtn = document.getElementById('modalLockBtn');
    
    // Build profile picture HTML
    let profilePictureHtml = '';
    if (user.profile_picture) {
        const profilePath = '<?php echo BASE_URL; ?>/' + user.profile_picture;
        profilePictureHtml = `
            <img src="${profilePath}?t=${new Date().getTime()}" 
                 alt="${user.first_name} ${user.last_name}"
                 class="w-24 h-24 rounded-full object-cover border-4 border-gray-200 dark:border-gray-700 mx-auto"
                 onerror="this.style.display='none'; this.nextElementSibling.style.display='flex';">
            <div class="w-24 h-24 rounded-full bg-primary-100 dark:bg-primary-900 flex items-center justify-center hidden mx-auto">
                <span class="text-primary-600 dark:text-primary-300 font-medium text-2xl">
                    ${(user.first_name?.[0] || '').toUpperCase()}${(user.last_name?.[0] || '').toUpperCase()}
                </span>
            </div>
        `;
    } else {
        const initials = ((user.first_name?.[0] || '') + (user.last_name?.[0] || '')).toUpperCase();
        profilePictureHtml = `
            <div class="w-24 h-24 rounded-full bg-primary-100 dark:bg-primary-900 flex items-center justify-center mx-auto">
                <span class="text-primary-600 dark:text-primary-300 font-medium text-2xl">${initials}</span>
            </div>
        `;
    }
    
    // Build user details HTML
    // For users table, 'inactive' means locked
    const displayStatus = (user.account_type === 'users' && user.status === 'inactive') ? 'locked' : user.status;
    const statusBadge = displayStatus === 'active' 
        ? '<span class="px-2 py-1 text-xs font-semibold rounded-full bg-green-100 text-green-800 dark:bg-green-900 dark:text-green-200">Active</span>'
        : displayStatus === 'locked' || user.status === 'inactive'
        ? '<span class="px-2 py-1 text-xs font-semibold rounded-full bg-red-100 text-red-800 dark:bg-red-900 dark:text-red-200">Locked</span>'
        : '<span class="px-2 py-1 text-xs font-semibold rounded-full bg-gray-100 text-gray-800 dark:bg-gray-700 dark:text-gray-200">Inactive</span>';
    
    const accountTypeBadge = user.account_type === 'department_accounts'
        ? '<span class="px-2 py-1 text-xs font-semibold rounded-full bg-purple-100 text-purple-800 dark:bg-purple-900 dark:text-purple-200">Department Account</span>'
        : '<span class="px-2 py-1 text-xs font-semibold rounded-full bg-gray-100 text-gray-800 dark:bg-gray-700 dark:text-gray-200">User Account</span>';
    
    modalContent.innerHTML = `
        <div class="text-center mb-6">
            ${profilePictureHtml}
            <h4 class="mt-4 text-xl font-semibold text-gray-900 dark:text-white">
                ${user.first_name || ''} ${user.last_name || ''}
            </h4>
            <p class="text-sm text-gray-500 dark:text-gray-400">@${user.username || 'N/A'}</p>
            <div class="flex justify-center space-x-2 mt-2">
                ${statusBadge}
                ${accountTypeBadge}
            </div>
        </div>
        
        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
            <div>
                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">Email</label>
                <p class="mt-1 text-sm text-gray-900 dark:text-white">${user.email || 'N/A'}</p>
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">Role</label>
                <p class="mt-1 text-sm text-gray-900 dark:text-white">${user.role_display_name || user.role_name || 'No Role'}</p>
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">Account Type</label>
                <p class="mt-1 text-sm text-gray-900 dark:text-white">${user.account_type === 'department_accounts' ? 'Department Account' : 'User Account'}</p>
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">Status</label>
                <p class="mt-1 text-sm text-gray-900 dark:text-white capitalize">${(user.account_type === 'users' && user.status === 'inactive') ? 'Locked' : (user.status || 'N/A')}</p>
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">Last Login</label>
                <p class="mt-1 text-sm text-gray-900 dark:text-white">${user.last_login ? new Date(user.last_login).toLocaleString() : 'Never'}</p>
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">Account Created</label>
                <p class="mt-1 text-sm text-gray-900 dark:text-white">${user.created_at ? new Date(user.created_at).toLocaleDateString() : 'N/A'}</p>
            </div>
            ${user.account_type === 'department_accounts' ? `
            <div>
                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">Employee ID</label>
                <p class="mt-1 text-sm text-gray-900 dark:text-white">${user.user_id || 'N/A'}</p>
            </div>
            ` : ''}
        </div>
    `;
    
    // Update lock/unlock button
    if (user.status === 'active') {
        lockBtn.textContent = 'Lock';
        lockBtn.className = 'w-full sm:w-auto inline-flex justify-center rounded-md border border-gray-300 dark:border-gray-600 shadow-sm px-4 py-2 bg-white dark:bg-gray-600 text-base font-medium text-red-700 dark:text-red-300 hover:bg-red-50 dark:hover:bg-red-900 sm:ml-3 sm:w-auto sm:text-sm';
    } else {
        lockBtn.textContent = 'Unlock';
        lockBtn.className = 'w-full sm:w-auto inline-flex justify-center rounded-md border border-gray-300 dark:border-gray-600 shadow-sm px-4 py-2 bg-white dark:bg-gray-600 text-base font-medium text-green-700 dark:text-green-300 hover:bg-green-50 dark:hover:bg-green-900 sm:ml-3 sm:w-auto sm:text-sm';
    }
    
    // Show/hide edit button based on account type
    if (user.account_type === 'users') {
        editBtn.style.display = 'inline-flex';
    } else {
        editBtn.style.display = 'none';
    }
    
    modal.classList.remove('hidden');
    document.body.style.overflow = 'hidden';
}

function closeUserModal() {
    const modal = document.getElementById('userModal');
    modal.classList.add('hidden');
    document.body.style.overflow = 'auto';
    currentUser = null;
}

function editUser() {
    if (currentUser && currentUser.account_type === 'users') {
        window.location.href = 'edit_user.php?id=' + currentUser.user_id;
    }
}

function lockUnlockUser() {
    if (!currentUser) return;
    
    // For users table, 'inactive' means locked
    const isLocked = currentUser.status === 'inactive' || currentUser.status === 'locked' || 
                     (currentUser.account_type === 'department_accounts' && currentUser.status !== 'active');
    
    const action = currentUser.status === 'active' ? 'lock' : 'unlock';
    const title = action === 'lock' ? 'Lock Account' : 'Unlock Account';
    const message = action === 'lock' 
        ? 'Are you sure you want to lock this account?'
        : 'Are you sure you want to unlock this account?';
    
    showConfirmAlert(title, message)
        .then((confirmed) => {
            if (confirmed) {
                const form = document.createElement('form');
                form.method = 'POST';
                form.innerHTML = `
                    <input type="hidden" name="action" value="${action}">
                    <input type="hidden" name="user_id" value="${currentUser.user_id}">
                    <input type="hidden" name="account_type" value="${currentUser.account_type}">
                `;
                document.body.appendChild(form);
                form.submit();
            }
        });
}

// Close modal on Escape key
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        closeUserModal();
    }
});

// Search and Filter Functions
let searchTimeout = null;

function toggleSearchBar() {
    const searchIconContainer = document.getElementById('searchIconContainer');
    const searchBarContainer = document.getElementById('searchBarContainer');
    const searchInput = document.getElementById('searchInput');
    
    if (searchBarContainer.classList.contains('hidden')) {
        searchIconContainer.classList.add('hidden');
        searchBarContainer.classList.remove('hidden');
        searchInput.focus();
    } else {
        closeSearchBar();
    }
}

function closeSearchBar() {
    const searchIconContainer = document.getElementById('searchIconContainer');
    const searchBarContainer = document.getElementById('searchBarContainer');
    const searchInput = document.getElementById('searchInput');
    
    searchBarContainer.classList.add('hidden');
    searchIconContainer.classList.remove('hidden');
    searchInput.value = '';
    
    // Clear search and reload
    if (window.location.search.includes('search=')) {
        const url = new URL(window.location);
        url.searchParams.delete('search');
        window.location.href = url.toString();
    }
}

function toggleFilterMenu() {
    const filterMenu = document.getElementById('filterMenu');
    filterMenu.classList.toggle('hidden');
}

function applyFilter() {
    const roleFilter = document.getElementById('roleFilter').value;
    const statusFilter = document.getElementById('statusFilter').value;
    const accountTypeFilter = document.getElementById('accountTypeFilter').value;
    
    const url = new URL(window.location);
    
    if (roleFilter !== 'all') {
        url.searchParams.set('role', roleFilter);
    } else {
        url.searchParams.delete('role');
    }
    
    if (statusFilter !== 'all') {
        url.searchParams.set('status', statusFilter);
    } else {
        url.searchParams.delete('status');
    }
    
    if (accountTypeFilter !== 'all') {
        url.searchParams.set('account_type', accountTypeFilter);
    } else {
        url.searchParams.delete('account_type');
    }
    
    window.location.href = url.toString();
}

// Close filter menu when clicking outside
document.addEventListener('click', function(event) {
    const filterMenu = document.getElementById('filterMenu');
    const filterButton = event.target.closest('[onclick="toggleFilterMenu()"]');
    
    if (!filterMenu.contains(event.target) && !filterButton) {
        filterMenu.classList.add('hidden');
    }
});

// Real-time search
document.addEventListener('DOMContentLoaded', function() {
    const searchInput = document.getElementById('searchInput');
    
    if (searchInput) {
        searchInput.addEventListener('input', function(e) {
            const searchTerm = e.target.value.trim();
            
            // Clear previous timeout
            if (searchTimeout) {
                clearTimeout(searchTimeout);
            }
            
            // Debounce search - wait 300ms after user stops typing
            searchTimeout = setTimeout(function() {
                const url = new URL(window.location);
                
                if (searchTerm.length > 0) {
                    url.searchParams.set('search', searchTerm);
                    // Remove initial filter when searching
                    url.searchParams.delete('initial');
                } else {
                    url.searchParams.delete('search');
                }
                
                // Update URL and reload
                window.location.href = url.toString();
            }, 300);
        });
        
        // Handle Enter key to search immediately
        searchInput.addEventListener('keydown', function(e) {
            if (e.key === 'Enter') {
                e.preventDefault();
                if (searchTimeout) {
                    clearTimeout(searchTimeout);
                }
                const searchTerm = e.target.value.trim();
                const url = new URL(window.location);
                
                if (searchTerm.length > 0) {
                    url.searchParams.set('search', searchTerm);
                    url.searchParams.delete('initial');
                } else {
                    url.searchParams.delete('search');
                }
                
                window.location.href = url.toString();
            }
        });
    }
});

// Function to refresh profile pictures on page focus or visibility change
document.addEventListener('DOMContentLoaded', function() {
    // Refresh images when page becomes visible (user switches back to tab)
    document.addEventListener('visibilitychange', function() {
        if (!document.hidden) {
            // Reload all profile images to get latest versions
            const profileImages = document.querySelectorAll('img[src*="profile"]');
            profileImages.forEach(function(img) {
                const src = img.src;
                // Remove old cache buster and add new one
                const baseSrc = src.split('?')[0];
                img.src = baseSrc + '?t=' + new Date().getTime();
            });
        }
    });
    
    // Handle image loading errors - show fallback initials
    document.querySelectorAll('img[src*="profile"]').forEach(function(img) {
        img.addEventListener('error', function() {
            this.style.display = 'none';
            const fallback = this.nextElementSibling;
            if (fallback) {
                fallback.style.display = 'flex';
            }
        });
    });
});
</script>

<?php include '../../includes/footer.php'; ?>
