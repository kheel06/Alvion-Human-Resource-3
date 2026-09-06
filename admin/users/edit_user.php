<?php
/**
 * Edit User Account
 * Admin can edit user accounts
 */
require_once '../../config/config.php';
requireAuth();
checkRole(['admin']);

$page_title = "Edit User Account";

$errors = [];
$user_id = $_GET['id'] ?? null;
$available_roles = getSystemRoles($db);
$allowed_role_ids = array_column($available_roles, 'id');

if (!$user_id) {
    $_SESSION['error'] = "User ID is required.";
    header("Location: manage_users.php");
    exit();
}

// Get user data
try {
    $user_query = "SELECT u.*, r.role_name 
                   FROM users u 
                   LEFT JOIN roles r ON u.role_id = r.id 
                   WHERE u.id = :id";
    $user_stmt = $db->prepare($user_query);
    $user_stmt->bindParam(':id', $user_id);
    $user_stmt->execute();
    $user = $user_stmt->fetch();
    
    if (!$user) {
        $_SESSION['error'] = "User not found.";
        header("Location: manage_users.php");
        exit();
    }
} catch (PDOException $e) {
    $_SESSION['error'] = "Error loading user: " . $e->getMessage();
    header("Location: manage_users.php");
    exit();
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $first_name = trim($_POST['first_name'] ?? '');
    $last_name = trim($_POST['last_name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    $role_id = $_POST['role_id'] ?? null;
    $status = $_POST['status'] ?? 'active';
    
    // Validation
    if (empty($first_name)) {
        $errors[] = "First name is required.";
    }
    if (empty($last_name)) {
        $errors[] = "Last name is required.";
    }
    if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = "Valid email is required.";
    }
    if (empty($username)) {
        $errors[] = "Username is required.";
    }
    if (!empty($password) && strlen($password) < 8) {
        $errors[] = "Password must be at least 8 characters long.";
    }
    if (empty($role_id) || !in_array((int)$role_id, $allowed_role_ids, true)) {
        $errors[] = "Role is required and must be one of the allowed user types.";
    }
    
    // Check if email/username already exists (excluding current user)
    if (empty($errors)) {
        try {
            $check_stmt = $db->prepare("SELECT id FROM users WHERE (email = :email OR username = :username) AND id != :id");
            $check_stmt->bindParam(':email', $email);
            $check_stmt->bindParam(':username', $username);
            $check_stmt->bindParam(':id', $user_id);
            $check_stmt->execute();
            if ($check_stmt->fetch()) {
                $errors[] = "Email or username already exists.";
            }
        } catch (PDOException $e) {
            $errors[] = "Error checking existing user: " . $e->getMessage();
        }
    }
    
    // Update user if no errors
    if (empty($errors)) {
        try {
            if (!empty($password)) {
                $hashed_password = password_hash($password, PASSWORD_DEFAULT);
                $update_query = "UPDATE users SET 
                    first_name = :first_name, 
                    last_name = :last_name, 
                    email = :email, 
                    username = :username, 
                    password = :password, 
                    role_id = :role_id, 
                    status = :status,
                    updated_at = NOW()
                    WHERE id = :id";
                $stmt = $db->prepare($update_query);
                $stmt->bindParam(':password', $hashed_password);
            } else {
                $update_query = "UPDATE users SET 
                    first_name = :first_name, 
                    last_name = :last_name, 
                    email = :email, 
                    username = :username, 
                    role_id = :role_id, 
                    status = :status,
                    updated_at = NOW()
                    WHERE id = :id";
                $stmt = $db->prepare($update_query);
            }
            
            $stmt->bindParam(':first_name', $first_name);
            $stmt->bindParam(':last_name', $last_name);
            $stmt->bindParam(':email', $email);
            $stmt->bindParam(':username', $username);
            $stmt->bindParam(':role_id', $role_id);
            $stmt->bindParam(':status', $status);
            $stmt->bindParam(':id', $user_id);
            $stmt->execute();
            
            $_SESSION['success'] = "User account updated successfully.";
            header("Location: manage_users.php");
            exit();
        } catch (PDOException $e) {
            $errors[] = "Error updating user: " . $e->getMessage();
        }
    }
}

include '../../includes/header.php';
?>

<div class="mb-6">
    <div class="flex items-center justify-between">
        <div>
            <h1 class="text-2xl font-bold text-gray-900 dark:text-white">Edit User Account</h1>
            <p class="text-gray-600 dark:text-gray-400">Update user information and permissions</p>
        </div>
        <a href="manage_users.php" class="text-primary-600 hover:text-primary-900 dark:text-primary-400 dark:hover:text-primary-300">
            ← Back to User Management
        </a>
    </div>
</div>

<div class="bg-white dark:bg-gray-800 shadow rounded-lg">
    <div class="px-4 py-5 sm:p-6">
        <?php if (!empty($errors)): ?>
            <div class="mb-4 bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-800 rounded-md p-4">
                <div class="flex">
                    <div class="flex-shrink-0">
                        <svg class="h-5 w-5 text-red-400" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor">
                            <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zM8.707 7.293a1 1 0 00-1.414 1.414L8.586 10l-1.293 1.293a1 1 0 101.414 1.414L10 11.414l1.293 1.293a1 1 0 001.414-1.414L11.414 10l1.293-1.293a1 1 0 00-1.414-1.414L10 8.586 8.707 7.293z" clip-rule="evenodd" />
                        </svg>
                    </div>
                    <div class="ml-3">
                        <h3 class="text-sm font-medium text-red-800 dark:text-red-200">Please correct the following errors:</h3>
                        <div class="mt-2 text-sm text-red-700 dark:text-red-300">
                            <ul class="list-disc list-inside space-y-1">
                                <?php foreach ($errors as $error): ?>
                                    <li><?php echo htmlspecialchars($error); ?></li>
                                <?php endforeach; ?>
                            </ul>
                        </div>
                    </div>
                </div>
            </div>
        <?php endif; ?>
        
        <form method="POST" class="space-y-6">
            <div class="grid grid-cols-1 gap-6 sm:grid-cols-2">
                <div>
                    <label for="first_name" class="block text-sm font-medium text-gray-700 dark:text-gray-300">First Name *</label>
                    <input type="text" name="first_name" id="first_name" required
                        value="<?php echo htmlspecialchars($_POST['first_name'] ?? $user['first_name']); ?>"
                        class="mt-1 block w-full border border-gray-300 dark:border-gray-600 rounded-md shadow-sm py-2 px-3 dark:bg-gray-700 dark:text-white focus:ring-primary-500 focus:border-primary-500">
                </div>
                
                <div>
                    <label for="last_name" class="block text-sm font-medium text-gray-700 dark:text-gray-300">Last Name *</label>
                    <input type="text" name="last_name" id="last_name" required
                        value="<?php echo htmlspecialchars($_POST['last_name'] ?? $user['last_name']); ?>"
                        class="mt-1 block w-full border border-gray-300 dark:border-gray-600 rounded-md shadow-sm py-2 px-3 dark:bg-gray-700 dark:text-white focus:ring-primary-500 focus:border-primary-500">
                </div>
                
                <div>
                    <label for="email" class="block text-sm font-medium text-gray-700 dark:text-gray-300">Email *</label>
                    <input type="email" name="email" id="email" required
                        value="<?php echo htmlspecialchars($_POST['email'] ?? $user['email']); ?>"
                        class="mt-1 block w-full border border-gray-300 dark:border-gray-600 rounded-md shadow-sm py-2 px-3 dark:bg-gray-700 dark:text-white focus:ring-primary-500 focus:border-primary-500">
                </div>
                
                <div>
                    <label for="username" class="block text-sm font-medium text-gray-700 dark:text-gray-300">Username *</label>
                    <input type="text" name="username" id="username" required
                        value="<?php echo htmlspecialchars($_POST['username'] ?? $user['username']); ?>"
                        class="mt-1 block w-full border border-gray-300 dark:border-gray-600 rounded-md shadow-sm py-2 px-3 dark:bg-gray-700 dark:text-white focus:ring-primary-500 focus:border-primary-500">
                </div>
                
                <div>
                    <label for="password" class="block text-sm font-medium text-gray-700 dark:text-gray-300">New Password</label>
                    <input type="password" name="password" id="password" minlength="8"
                        class="mt-1 block w-full border border-gray-300 dark:border-gray-600 rounded-md shadow-sm py-2 px-3 dark:bg-gray-700 dark:text-white focus:ring-primary-500 focus:border-primary-500">
                    <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">Leave blank to keep current password</p>
                </div>
                
                <div>
                    <label for="role_id" class="block text-sm font-medium text-gray-700 dark:text-gray-300">Role *</label>
                    <select name="role_id" id="role_id" required
                        class="mt-1 block w-full border border-gray-300 dark:border-gray-600 rounded-md shadow-sm py-2 px-3 dark:bg-gray-700 dark:text-white focus:ring-primary-500 focus:border-primary-500">
                        <option value="">Select a role</option>
                        <?php foreach ($available_roles as $role): ?>
                            <option value="<?php echo $role['id']; ?>" <?php echo ((int)($_POST['role_id'] ?? $user['role_id']) == $role['id']) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($role['display_name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div>
                    <label for="status" class="block text-sm font-medium text-gray-700 dark:text-gray-300">Status</label>
                    <select name="status" id="status"
                        class="mt-1 block w-full border border-gray-300 dark:border-gray-600 rounded-md shadow-sm py-2 px-3 dark:bg-gray-700 dark:text-white focus:ring-primary-500 focus:border-primary-500">
                        <option value="active" <?php echo (($_POST['status'] ?? $user['status']) === 'active') ? 'selected' : ''; ?>>Active</option>
                        <option value="inactive" <?php echo (($_POST['status'] ?? $user['status']) === 'inactive') ? 'selected' : ''; ?>>Inactive</option>
                        <option value="locked" <?php echo (($_POST['status'] ?? $user['status']) === 'locked') ? 'selected' : ''; ?>>Locked</option>
                    </select>
                </div>
            </div>
            
            <div class="flex justify-end space-x-3 pt-4 border-t border-gray-200 dark:border-gray-700">
                <a href="manage_users.php" class="px-4 py-2 border border-gray-300 dark:border-gray-600 rounded-md shadow-sm text-sm font-medium text-gray-700 dark:text-gray-300 bg-white dark:bg-gray-700 hover:bg-gray-50 dark:hover:bg-gray-600">
                    Cancel
                </a>
                <button type="submit" class="px-4 py-2 border border-transparent rounded-md shadow-sm text-sm font-medium text-white bg-primary-600 hover:bg-primary-700">
                    Update User Account
                </button>
            </div>
        </form>
    </div>
</div>

<?php include '../../includes/footer.php'; ?>

