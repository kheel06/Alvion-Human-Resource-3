<?php
/**
 * Role Management
 * Admin can assign or update permissions
 */
require_once '../../config/config.php';
requireAuth();
checkRole(['admin']);

$page_title = "Role Management";

// Get filter parameters for user accounts table
$search = $_GET['search'] ?? '';
$role_filter = $_GET['role'] ?? 'all';
$status_filter = $_GET['status'] ?? 'all';
$account_type_filter = $_GET['account_type'] ?? 'all';

// Handle actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action'])) {
        $action = $_POST['action'];
        
        if ($action === 'update_permissions' && isset($_POST['role_id'])) {
            $role_id = $_POST['role_id'];
            $permissions = $_POST['permissions'] ?? [];
            
            // Check if permissions and role_permissions tables exist
            try {
                $check_permissions = $db->query("SHOW TABLES LIKE 'permissions'");
                $check_role_permissions = $db->query("SHOW TABLES LIKE 'role_permissions'");
                
                if ($check_permissions->rowCount() === 0 || $check_role_permissions->rowCount() === 0) {
                    $_SESSION['error'] = "Permissions tables do not exist. Please create the permissions and role_permissions tables first.";
                } else {
                    // Delete existing permissions for this role
                    $delete_stmt = $db->prepare("DELETE FROM role_permissions WHERE role_id = :role_id");
                    $delete_stmt->bindParam(':role_id', $role_id);
                    $delete_stmt->execute();
                    
                    // Insert new permissions
                    $insert_stmt = $db->prepare("INSERT INTO role_permissions (role_id, permission_id) VALUES (:role_id, :permission_id)");
                    foreach ($permissions as $permission_id) {
                        $insert_stmt->bindParam(':role_id', $role_id);
                        $insert_stmt->bindParam(':permission_id', $permission_id);
                        $insert_stmt->execute();
                    }
                    
                    $_SESSION['success'] = "Permissions updated successfully.";
                }
            } catch (PDOException $e) {
                $_SESSION['error'] = "Error updating permissions: " . $e->getMessage();
            }
        }
        
        header("Location: manage_roles.php");
        exit();
    }
}

// Get all roles
try {
    $roles_query = "SELECT r.*, COUNT(DISTINCT u.id) as user_count 
                    FROM roles r 
                    LEFT JOIN users u ON r.id = u.role_id AND u.deleted_at IS NULL
                    GROUP BY r.id
                    ORDER BY r.role_name";
    $roles_stmt = $db->prepare($roles_query);
    $roles_stmt->execute();
    $roles = $roles_stmt->fetchAll();

    $allowed_roles = getAllowedRoleNames();
    $allowed_map = array_flip($allowed_roles);
    $roles = array_values(array_filter($roles, function ($role) use ($allowed_map) {
        return isset($allowed_map[normalizeRoleName($role['role_name'])]);
    }));

    usort($roles, function ($a, $b) use ($allowed_map) {
        $a_index = $allowed_map[normalizeRoleName($a['role_name'])] ?? PHP_INT_MAX;
        $b_index = $allowed_map[normalizeRoleName($b['role_name'])] ?? PHP_INT_MAX;
        return $a_index <=> $b_index;
    });

    $roles = array_map(function ($role) {
        $role['display_name'] = getRoleDisplayName($role['role_name']);
        return $role;
    }, $roles);
} catch (PDOException $e) {
    $roles = [];
    $_SESSION['error'] = "Error loading roles: " . $e->getMessage();
}

// Check if permissions table exists
$permissions_table_exists = false;
try {
    $check_table = $db->query("SHOW TABLES LIKE 'permissions'");
    $permissions_table_exists = $check_table->rowCount() > 0;
} catch (PDOException $e) {
    $permissions_table_exists = false;
}

// Get all permissions if table exists
$permissions_by_category = [];
if ($permissions_table_exists) {
    try {
        $permissions_query = "SELECT * FROM permissions ORDER BY module, permission_name";
        $permissions_stmt = $db->prepare($permissions_query);
        $permissions_stmt->execute();
        $all_permissions = $permissions_stmt->fetchAll();
        
        // Group permissions by module
        foreach ($all_permissions as $perm) {
            $module = $perm['module'] ?? 'General';
            if (!isset($permissions_by_category[$module])) {
                $permissions_by_category[$module] = [];
            }
            $permissions_by_category[$module][] = $perm;
        }
    } catch (PDOException $e) {
        $permissions_by_category = [];
        // Don't show error if table doesn't exist - we already checked
        if (strpos($e->getMessage(), "doesn't exist") === false) {
            $_SESSION['error'] = "Error loading permissions: " . $e->getMessage();
        }
    }
}

// Get permissions for each role
$role_permissions = [];
foreach ($roles as $role) {
    try {
        $rp_query = "SELECT permission_id FROM role_permissions WHERE role_id = :role_id";
        $rp_stmt = $db->prepare($rp_query);
        $rp_stmt->bindParam(':role_id', $role['id']);
        $rp_stmt->execute();
        $role_permissions[$role['id']] = array_column($rp_stmt->fetchAll(), 'permission_id');
    } catch (PDOException $e) {
        $role_permissions[$role['id']] = [];
    }
}

// Build query for users table (exclude doctors and admins) for user accounts table
$users_query = "SELECT 
    u.id as user_id,
    u.username,
    e.first_name,
    e.last_name,
    e.email,
    u.is_active,
    CASE WHEN u.is_active = 1 THEN 'active' ELSE 'inactive' END as status,
    u.last_login_at as last_login,
    u.created_at,
    e.photo_path as profile_picture,
    r.role_name,
    'users' as account_type
    FROM users u 
    LEFT JOIN roles r ON u.role_id = r.id 
    LEFT JOIN employees e ON u.employee_id = e.id
    WHERE u.deleted_at IS NULL 
    AND (r.role_name != 'doctor' OR r.role_name IS NULL)
    AND (r.role_name != 'admin' OR r.role_name IS NULL)";

$users_params = [];

if ($search) {
    $users_query .= " AND (u.first_name LIKE :search_users OR u.last_name LIKE :search_users OR u.email LIKE :search_users OR u.username LIKE :search_users)";
    $users_params[':search_users'] = "%$search%";
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
    da.employee_email as email,
    da.employee_id as username,
    CASE WHEN da.is_active = 1 THEN 'active' ELSE 'inactive' END as status,
    da.last_login,
    da.created_at,
    da.profile_picture,
    da.role_name,
    'department_accounts' as account_type
    FROM department_accounts da 
    WHERE da.role_name != 'doctor'
    AND da.role_name != 'admin'";

$dept_params = [];

if ($search) {
    $dept_query .= " AND (da.employee_fname LIKE :search_dept OR da.employee_lname LIKE :search_dept OR da.employee_email LIKE :search_dept OR da.employee_id LIKE :search_dept)";
    $dept_params[':search_dept'] = "%$search%";
}

if ($role_filter !== 'all') {
    try {
        $role_name_stmt = $db->prepare("SELECT role_name FROM roles WHERE id = :role_id");
        $role_name_stmt->bindParam(':role_id', $role_filter);
        $role_name_stmt->execute();
        $role_data = $role_name_stmt->fetch();
        if ($role_data) {
            $dept_query .= " AND da.role_name = :role_name_dept";
            $dept_params[':role_name_dept'] = $role_data['role_name'];
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
    $user_accounts_query = $users_query . " ORDER BY created_at DESC";
    $user_accounts_params = $users_params;
} elseif ($account_type_filter === 'department_accounts') {
    $user_accounts_query = $dept_query . " ORDER BY created_at DESC";
    $user_accounts_params = $dept_params;
} else {
    $user_accounts_query = "(" . $users_query . ") UNION (" . $dept_query . ") ORDER BY created_at DESC";
    $user_accounts_params = array_merge($users_params, $dept_params);
}

// Get user accounts
$all_users = [];
try {
    $user_accounts_stmt = $db->prepare($user_accounts_query);
    foreach ($user_accounts_params as $key => $value) {
        $user_accounts_stmt->bindValue($key, $value);
    }
    $user_accounts_stmt->execute();
    $all_users = $user_accounts_stmt->fetchAll();
} catch (PDOException $e) {
    $all_users = [];
}

include '../../includes/header.php';
?>

<div class="mb-6">
    <h1 class="text-2xl font-bold text-gray-900 dark:text-white">Role Management</h1>
    <p class="text-gray-600 dark:text-gray-400">Assign or update permissions for each role</p>
</div>

<!-- Roles List -->
<div class="grid grid-cols-1 gap-6">
    <?php foreach ($roles as $role): ?>
        <div class="bg-white dark:bg-gray-800 shadow rounded-lg">
            <div class="px-4 py-5 sm:p-6">
                <div class="flex items-center justify-between mb-4">
                    <div>
                        <h3 class="text-lg font-medium text-gray-900 dark:text-white">
                            <?php echo htmlspecialchars($role['display_name'] ?? getRoleDisplayName($role['role_name'])); ?>
                        </h3>
                        <p class="text-sm text-gray-500 dark:text-gray-400">
                            <?php echo htmlspecialchars($role['role_description'] ?? ''); ?>
                        </p>
                        <p class="text-xs text-gray-400 dark:text-gray-500 mt-1">
                            <?php echo $role['user_count']; ?> user(s) assigned
                        </p>
                    </div>
                    <a href="edit_role.php?id=<?php echo $role['id']; ?>" class="text-primary-600 hover:text-primary-900 dark:text-primary-400 dark:hover:text-primary-300 text-sm">
                        Edit Role
                    </a>
                </div>
                
                <?php if ($permissions_table_exists && !empty($permissions_by_category)): ?>
                    <form method="POST" class="space-y-4">
                        <input type="hidden" name="action" value="update_permissions">
                        <input type="hidden" name="role_id" value="<?php echo $role['id']; ?>">
                        
                        <div class="space-y-4">
                            <?php foreach ($permissions_by_category as $category => $permissions): ?>
                                <div class="border border-gray-200 dark:border-gray-700 rounded-lg p-4">
                                    <h4 class="text-sm font-semibold text-gray-700 dark:text-gray-300 mb-3">
                                        <?php echo htmlspecialchars($category); ?>
                                    </h4>
                                    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-3">
                                        <?php foreach ($permissions as $permission): ?>
                                            <label class="flex items-center space-x-2 cursor-pointer">
                                                <input type="checkbox" name="permissions[]" value="<?php echo $permission['id']; ?>"
                                                    <?php echo in_array($permission['id'], $role_permissions[$role['id']] ?? []) ? 'checked' : ''; ?>
                                                    class="rounded border-gray-300 text-primary-600 focus:ring-primary-500 dark:border-gray-600 dark:bg-gray-700">
                                                <span class="text-sm text-gray-700 dark:text-gray-300">
                                                    <?php echo htmlspecialchars($permission['permission_name']); ?>
                                                </span>
                                            </label>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                        
                        <div class="flex justify-end pt-4 border-t border-gray-200 dark:border-gray-700">
                            <button type="submit" class="px-4 py-2 border border-transparent rounded-md shadow-sm text-sm font-medium text-white bg-primary-600 hover:bg-primary-700">
                                Update Permissions
                            </button>
                        </div>
                    </form>
                <?php elseif (!$permissions_table_exists): ?>
                    <div class="bg-yellow-50 dark:bg-yellow-900/20 border border-yellow-200 dark:border-yellow-800 rounded-md p-4">
                        <div class="flex">
                            <div class="flex-shrink-0">
                                <svg class="h-5 w-5 text-yellow-400" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor">
                                    <path fill-rule="evenodd" d="M8.257 3.099c.765-1.36 2.722-1.36 3.486 0l5.58 9.92c.75 1.334-.213 2.98-1.742 2.98H4.42c-1.53 0-2.493-1.646-1.743-2.98l5.58-9.92zM11 13a1 1 0 11-2 0 1 1 0 012 0zm-1-8a1 1 0 00-1 1v3a1 1 0 002 0V6a1 1 0 00-1-1z" clip-rule="evenodd" />
                                </svg>
                            </div>
                            <div class="ml-3">
                                <h3 class="text-sm font-medium text-yellow-800 dark:text-yellow-200">Permissions Table Not Found</h3>
                                <div class="mt-2 text-sm text-yellow-700 dark:text-yellow-300">
                                    <p>The permissions table does not exist in the database. Please create it using the following SQL:</p>
                                    <pre class="mt-2 p-2 bg-yellow-100 dark:bg-yellow-900 rounded text-xs overflow-x-auto">CREATE TABLE IF NOT EXISTS `permissions` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `permission_name` varchar(100) NOT NULL,
  `permission_description` text DEFAULT NULL,
  `module` varchar(50) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `permission_name` (`permission_name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;</pre>
                                </div>
                            </div>
                        </div>
                    </div>
                <?php else: ?>
                    <div class="bg-blue-50 dark:bg-blue-900/20 border border-blue-200 dark:border-blue-800 rounded-md p-4">
                        <p class="text-sm text-blue-800 dark:text-blue-200">No permissions have been defined yet. Please add permissions to the database.</p>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    <?php endforeach; ?>
</div>

<?php if (empty($roles)): ?>
    <div class="bg-white dark:bg-gray-800 shadow rounded-lg">
        <div class="px-4 py-5 sm:p-6 text-center">
            <svg xmlns="http://www.w3.org/2000/svg" width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="text-gray-300 dark:text-gray-600 mx-auto mb-2">
                <path d="M20 13c0 5-3.5 7.5-7.66 8.95a1 1 0 0 1-.67-.01C7.5 20.5 4 18 4 13V6a1 1 0 0 1 1-1c2 0 4.5-1.2 6.24-2.72a1.17 1.17 0 0 1 1.52 0C14.51 3.81 17 5 19 5a1 1 0 0 1 1 1z"></path>
            </svg>
            <p class="text-gray-500 dark:text-gray-400">No roles found</p>
        </div>
    </div>
<?php endif; ?>

<!-- User Accounts Table -->
<div class="mt-8 bg-white dark:bg-gray-800 shadow rounded-lg">
    <div class="px-4 py-5 sm:px-6 border-b border-gray-200 dark:border-gray-700">
        <div class="flex items-center justify-between">
            <h3 class="text-lg leading-6 font-medium text-gray-900 dark:text-white">User Accounts</h3>
        </div>
        
        <!-- Icon-based Filter Bar -->
        <div class="mt-4 flex items-center gap-2 flex-wrap">
            <form method="GET" id="filterForm" class="flex items-center gap-2 flex-1 min-w-0">
                <!-- Search Icon/Input -->
                <div class="relative flex-1 min-w-[200px]">
                    <button type="button" id="searchToggle" class="absolute left-3 top-1/2 transform -translate-y-1/2 text-gray-400 hover:text-gray-600 dark:hover:text-gray-300 z-10">
                        <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <circle cx="11" cy="11" r="8"></circle>
                            <path d="m21 21-4.35-4.35"></path>
                        </svg>
                    </button>
                    <input type="text" name="search" id="searchInput" value="<?php echo htmlspecialchars($search); ?>" 
                           placeholder="Name, email, username..." 
                           class="hidden w-full pl-10 pr-3 py-2 border border-gray-300 dark:border-gray-600 rounded-md shadow-sm dark:bg-gray-700 dark:text-white focus:outline-none focus:ring-primary-500 focus:border-primary-500">
                </div>
                
                <!-- Role Filter Icon -->
                <div class="relative">
                    <button type="button" id="roleFilterToggle" class="p-2 text-gray-400 hover:text-gray-600 dark:hover:text-gray-300 border border-gray-300 dark:border-gray-600 rounded-md hover:bg-gray-50 dark:hover:bg-gray-700">
                        <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"></path>
                            <circle cx="9" cy="7" r="4"></circle>
                            <path d="M22 21v-2a4 4 0 0 0-3-3.87"></path>
                            <path d="M16 3.13a4 4 0 0 1 0 7.75"></path>
                        </svg>
                    </button>
                    <select name="role" id="roleFilter" class="hidden absolute top-full left-0 mt-1 w-48 border border-gray-300 dark:border-gray-600 rounded-md shadow-sm py-2 px-3 dark:bg-gray-700 dark:text-white z-20">
                        <option value="all" <?php echo $role_filter === 'all' ? 'selected' : ''; ?>>All Roles</option>
                        <?php foreach ($roles as $role): ?>
                            <option value="<?php echo $role['id']; ?>" <?php echo $role_filter == $role['id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($role['display_name'] ?? getRoleDisplayName($role['role_name'])); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <!-- Status Filter Icon -->
                <div class="relative">
                    <button type="button" id="statusFilterToggle" class="p-2 text-gray-400 hover:text-gray-600 dark:hover:text-gray-300 border border-gray-300 dark:border-gray-600 rounded-md hover:bg-gray-50 dark:hover:bg-gray-700">
                        <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <circle cx="12" cy="12" r="10"></circle>
                            <path d="M12 6v6l4 2"></path>
                        </svg>
                    </button>
                    <select name="status" id="statusFilter" class="hidden absolute top-full left-0 mt-1 w-40 border border-gray-300 dark:border-gray-600 rounded-md shadow-sm py-2 px-3 dark:bg-gray-700 dark:text-white z-20">
                        <option value="all" <?php echo $status_filter === 'all' ? 'selected' : ''; ?>>All Status</option>
                        <option value="active" <?php echo $status_filter === 'active' ? 'selected' : ''; ?>>Active</option>
                        <option value="locked" <?php echo $status_filter === 'locked' ? 'selected' : ''; ?>>Locked</option>
                        <option value="inactive" <?php echo $status_filter === 'inactive' ? 'selected' : ''; ?>>Inactive</option>
                    </select>
                </div>
                
                <!-- Account Type Filter Icon -->
                <div class="relative">
                    <button type="button" id="accountTypeFilterToggle" class="p-2 text-gray-400 hover:text-gray-600 dark:hover:text-gray-300 border border-gray-300 dark:border-gray-600 rounded-md hover:bg-gray-50 dark:hover:bg-gray-700">
                        <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <rect x="3" y="3" width="18" height="18" rx="2" ry="2"></rect>
                            <line x1="3" y1="9" x2="21" y2="9"></line>
                            <line x1="9" y1="21" x2="9" y2="9"></line>
                        </svg>
                    </button>
                    <select name="account_type" id="accountTypeFilter" class="hidden absolute top-full left-0 mt-1 w-48 border border-gray-300 dark:border-gray-600 rounded-md shadow-sm py-2 px-3 dark:bg-gray-700 dark:text-white z-20">
                        <option value="all" <?php echo $account_type_filter === 'all' ? 'selected' : ''; ?>>All Types</option>
                        <option value="users" <?php echo $account_type_filter === 'users' ? 'selected' : ''; ?>>Users</option>
                        <option value="department_accounts" <?php echo $account_type_filter === 'department_accounts' ? 'selected' : ''; ?>>Department Accounts</option>
                    </select>
                </div>
                
                <!-- Filter Button -->
                <button type="submit" class="p-2 bg-primary-600 text-white rounded-md hover:bg-primary-700 focus:outline-none focus:ring-2 focus:ring-primary-500">
                    <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <polygon points="22 3 2 3 10 12.46 10 19 14 21 14 12.46 22 3"></polygon>
                    </svg>
                </button>
                
                <!-- Clear Filters -->
                <?php if ($search || $role_filter !== 'all' || $status_filter !== 'all' || $account_type_filter !== 'all'): ?>
                    <a href="manage_roles.php" class="p-2 text-gray-400 hover:text-gray-600 dark:hover:text-gray-300 border border-gray-300 dark:border-gray-600 rounded-md hover:bg-gray-50 dark:hover:bg-gray-700">
                        <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <line x1="18" y1="6" x2="6" y2="18"></line>
                            <line x1="6" y1="6" x2="18" y2="18"></line>
                        </svg>
                    </a>
                <?php endif; ?>
            </form>
        </div>
    </div>
    
    <div class="px-4 py-5 sm:p-6">
        <?php if (count($all_users) > 0): ?>
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                    <thead class="bg-gray-50 dark:bg-gray-700">
                        <tr>
                            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">User</th>
                            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Role</th>
                            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Email</th>
                            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Type</th>
                            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Status</th>
                            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Last Login</th>
                        </tr>
                    </thead>
                    <tbody class="bg-white dark:bg-gray-800 divide-y divide-gray-200 dark:divide-gray-700">
                        <?php foreach ($all_users as $user): ?>
                            <tr>
                                <td class="px-4 py-4 whitespace-nowrap">
                                    <div class="flex items-center">
                                        <div class="flex-shrink-0 h-10 w-10">
                                            <?php
                                            $profile_picture = $user['profile_picture'] ?? null;
                                            $profile_path = null;
                                            $cache_buster = '';
                                            
                                            if (!empty($profile_picture)) {
                                                if (strpos($profile_picture, 'http') === 0) {
                                                    $profile_path = $profile_picture;
                                                } else {
                                                    $full_path = __DIR__ . '/../../' . ltrim($profile_picture, '/');
                                                    if (file_exists($full_path)) {
                                                        $profile_path = BASE_URL . '/' . ltrim($profile_picture, '/');
                                                        $cache_buster = '?t=' . filemtime($full_path);
                                                    }
                                                }
                                            }
                                            
                                            $first_initial = strtoupper(substr($user['first_name'] ?? '', 0, 1));
                                            $last_initial = strtoupper(substr($user['last_name'] ?? '', 0, 1));
                                            $initials = $first_initial . $last_initial;
                                            ?>
                                            
                                            <?php if ($profile_path): ?>
                                                <img src="<?php echo htmlspecialchars($profile_path . $cache_buster); ?>" 
                                                     alt="<?php echo htmlspecialchars(($user['first_name'] ?? '') . ' ' . ($user['last_name'] ?? '')); ?>"
                                                     class="h-10 w-10 rounded-full object-cover border-2 border-gray-200 dark:border-gray-700"
                                                     onerror="this.style.display='none'; this.nextElementSibling.style.display='flex';">
                                                <div class="h-10 w-10 rounded-full bg-primary-100 dark:bg-primary-900 flex items-center justify-center hidden">
                                                    <span class="text-primary-600 dark:text-primary-300 font-medium text-xs">
                                                        <?php echo htmlspecialchars($initials); ?>
                                                    </span>
                                                </div>
                                            <?php else: ?>
                                                <div class="h-10 w-10 rounded-full bg-primary-100 dark:bg-primary-900 flex items-center justify-center">
                                                    <span class="text-primary-600 dark:text-primary-300 font-medium text-xs">
                                                        <?php echo htmlspecialchars($initials); ?>
                                                    </span>
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                        <div class="ml-4">
                                            <div class="text-sm font-medium text-gray-900 dark:text-white">
                                                <?php echo htmlspecialchars(($user['first_name'] ?? '') . ' ' . ($user['last_name'] ?? '')); ?>
                                            </div>
                                            <div class="text-sm text-gray-500 dark:text-gray-400">
                                                @<?php echo htmlspecialchars($user['username'] ?? 'N/A'); ?>
                                            </div>
                                        </div>
                                    </div>
                                </td>
                                <td class="px-4 py-4 whitespace-nowrap">
                                    <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full bg-blue-100 text-blue-800 dark:bg-blue-900 dark:text-blue-200">
                                        <?php echo htmlspecialchars($user['role_name'] ?? 'No Role'); ?>
                                    </span>
                                </td>
                                <td class="px-4 py-4 whitespace-nowrap text-sm text-gray-700 dark:text-gray-300">
                                    <?php echo htmlspecialchars($user['email'] ?? 'N/A'); ?>
                                </td>
                                <td class="px-4 py-4 whitespace-nowrap">
                                    <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full 
                                        <?php echo $user['account_type'] === 'department_accounts' ? 'bg-purple-100 text-purple-800 dark:bg-purple-900 dark:text-purple-200' : 'bg-gray-100 text-gray-800 dark:bg-gray-700 dark:text-gray-200'; ?>">
                                        <?php echo $user['account_type'] === 'department_accounts' ? 'Dept Account' : 'User'; ?>
                                    </span>
                                </td>
                                <td class="px-4 py-4 whitespace-nowrap">
                                    <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full 
                                        <?php 
                                        $status = $user['status'] ?? 'inactive';
                                        $display_status = $status;
                                        
                                        if ($user['account_type'] === 'users' && $status === 'inactive') {
                                            $display_status = 'locked';
                                        }
                                        
                                        if ($status === 'active') {
                                            echo 'bg-green-100 text-green-800 dark:bg-green-900 dark:text-green-200';
                                        } elseif ($display_status === 'locked' || $status === 'inactive') {
                                            echo 'bg-red-100 text-red-800 dark:bg-red-900 dark:text-red-200';
                                        } else {
                                            echo 'bg-gray-100 text-gray-800 dark:bg-gray-700 dark:text-gray-200';
                                        }
                                        ?>">
                                        <?php echo ucfirst($display_status); ?>
                                    </span>
                                </td>
                                <td class="px-4 py-4 whitespace-nowrap text-sm text-gray-700 dark:text-gray-300">
                                    <?php echo $user['last_login'] ? date('M d, Y H:i', strtotime($user['last_login'])) : 'Never'; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php else: ?>
            <div class="text-center py-8">
                <svg xmlns="http://www.w3.org/2000/svg" width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="text-gray-300 dark:text-gray-600 mx-auto mb-2">
                    <path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"></path>
                    <circle cx="9" cy="7" r="4"></circle>
                    <path d="M22 21v-2a4 4 0 0 0-3-3.87"></path>
                    <path d="M16 3.13a4 4 0 0 1 0 7.75"></path>
                </svg>
                <p class="text-gray-500 dark:text-gray-400">No users found</p>
            </div>
        <?php endif; ?>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // Search toggle functionality
    const searchToggle = document.getElementById('searchToggle');
    const searchInput = document.getElementById('searchInput');
    
    if (searchToggle && searchInput) {
        // Show search if there's a value
        if (searchInput.value) {
            searchInput.classList.remove('hidden');
        }
        
        searchToggle.addEventListener('click', function() {
            searchInput.classList.toggle('hidden');
            if (!searchInput.classList.contains('hidden')) {
                searchInput.focus();
            }
        });
    }
    
    // Role filter toggle
    const roleFilterToggle = document.getElementById('roleFilterToggle');
    const roleFilter = document.getElementById('roleFilter');
    
    if (roleFilterToggle && roleFilter) {
        roleFilterToggle.addEventListener('click', function(e) {
            e.stopPropagation();
            roleFilter.classList.toggle('hidden');
            // Close other dropdowns
            statusFilter.classList.add('hidden');
            accountTypeFilter.classList.add('hidden');
        });
        
        // Close on outside click
        document.addEventListener('click', function(e) {
            if (!roleFilterToggle.contains(e.target) && !roleFilter.contains(e.target)) {
                roleFilter.classList.add('hidden');
            }
        });
    }
    
    // Status filter toggle
    const statusFilterToggle = document.getElementById('statusFilterToggle');
    const statusFilter = document.getElementById('statusFilter');
    
    if (statusFilterToggle && statusFilter) {
        statusFilterToggle.addEventListener('click', function(e) {
            e.stopPropagation();
            statusFilter.classList.toggle('hidden');
            // Close other dropdowns
            roleFilter.classList.add('hidden');
            accountTypeFilter.classList.add('hidden');
        });
        
        // Close on outside click
        document.addEventListener('click', function(e) {
            if (!statusFilterToggle.contains(e.target) && !statusFilter.contains(e.target)) {
                statusFilter.classList.add('hidden');
            }
        });
    }
    
    // Account type filter toggle
    const accountTypeFilterToggle = document.getElementById('accountTypeFilterToggle');
    const accountTypeFilter = document.getElementById('accountTypeFilter');
    
    if (accountTypeFilterToggle && accountTypeFilter) {
        accountTypeFilterToggle.addEventListener('click', function(e) {
            e.stopPropagation();
            accountTypeFilter.classList.toggle('hidden');
            // Close other dropdowns
            roleFilter.classList.add('hidden');
            statusFilter.classList.add('hidden');
        });
        
        // Close on outside click
        document.addEventListener('click', function(e) {
            if (!accountTypeFilterToggle.contains(e.target) && !accountTypeFilter.contains(e.target)) {
                accountTypeFilter.classList.add('hidden');
            }
        });
    }
    
    // Auto-submit on filter change
    if (roleFilter) {
        roleFilter.addEventListener('change', function() {
            document.getElementById('filterForm').submit();
        });
    }
    
    if (statusFilter) {
        statusFilter.addEventListener('change', function() {
            document.getElementById('filterForm').submit();
        });
    }
    
    if (accountTypeFilter) {
        accountTypeFilter.addEventListener('change', function() {
            document.getElementById('filterForm').submit();
        });
    }
});
</script>

<?php include '../../includes/footer.php'; ?>

