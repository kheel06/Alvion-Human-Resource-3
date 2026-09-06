<?php
/**
 * Edit Role
 * Admin can edit role details
 */
require_once '../../config/config.php';
requireAuth();
checkRole(['admin']);

$page_title = "Edit Role";

$errors = [];
$role_id = $_GET['id'] ?? null;

if (!$role_id) {
    $_SESSION['error'] = "Role ID is required.";
    header("Location: manage_roles.php");
    exit();
}

// Get role data
try {
    $role_query = "SELECT * FROM roles WHERE id = :id";
    $role_stmt = $db->prepare($role_query);
    $role_stmt->bindParam(':id', $role_id);
    $role_stmt->execute();
    $role = $role_stmt->fetch();
    
    if (!$role) {
        $_SESSION['error'] = "Role not found.";
        header("Location: manage_roles.php");
        exit();
    }
} catch (PDOException $e) {
    $_SESSION['error'] = "Error loading role: " . $e->getMessage();
    header("Location: manage_roles.php");
    exit();
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $description = trim($_POST['description'] ?? '');
    
    if (empty($errors)) {
        try {
            $update_query = "UPDATE roles SET 
                role_description = :role_description,
                updated_at = NOW()
                WHERE id = :id";
            $stmt = $db->prepare($update_query);
            $stmt->bindParam(':role_description', $description);
            $stmt->bindParam(':id', $role_id);
            $stmt->execute();
            
            $_SESSION['success'] = "Role updated successfully.";
            header("Location: manage_roles.php");
            exit();
        } catch (PDOException $e) {
            $errors[] = "Error updating role: " . $e->getMessage();
        }
    }
}

include '../../includes/header.php';
?>

<div class="mb-6">
    <div class="flex items-center justify-between">
        <div>
            <h1 class="text-2xl font-bold text-gray-900 dark:text-white">Edit Role</h1>
            <p class="text-gray-600 dark:text-gray-400">Update role information</p>
        </div>
        <a href="manage_roles.php" class="text-primary-600 hover:text-primary-900 dark:text-primary-400 dark:hover:text-primary-300">
            ← Back to Role Management
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
            <div>
                <label for="name" class="block text-sm font-medium text-gray-700 dark:text-gray-300">Role Name</label>
                <input type="text" id="name" value="<?php echo htmlspecialchars(getRoleDisplayName($role['role_name'])); ?>" readonly
                    class="mt-1 block w-full border border-gray-300 dark:border-gray-600 rounded-md shadow-sm py-2 px-3 dark:bg-gray-700 dark:text-white bg-gray-100 dark:bg-gray-800 cursor-not-allowed">
                <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">User type labels are fixed to keep access control consistent.</p>
            </div>
            
            <div>
                <label for="description" class="block text-sm font-medium text-gray-700 dark:text-gray-300">Description</label>
                <textarea name="description" id="description" rows="4"
                    class="mt-1 block w-full border border-gray-300 dark:border-gray-600 rounded-md shadow-sm py-2 px-3 dark:bg-gray-700 dark:text-white focus:ring-primary-500 focus:border-primary-500"><?php echo htmlspecialchars($_POST['description'] ?? $role['role_description'] ?? ''); ?></textarea>
            </div>
            
            <div class="flex justify-end space-x-3 pt-4 border-t border-gray-200 dark:border-gray-700">
                <a href="manage_roles.php" class="px-4 py-2 border border-gray-300 dark:border-gray-600 rounded-md shadow-sm text-sm font-medium text-gray-700 dark:text-gray-300 bg-white dark:bg-gray-700 hover:bg-gray-50 dark:hover:bg-gray-600">
                    Cancel
                </a>
                <button type="submit" class="px-4 py-2 border border-transparent rounded-md shadow-sm text-sm font-medium text-white bg-primary-600 hover:bg-primary-700">
                    Update Role
                </button>
            </div>
        </form>
    </div>
</div>

<?php include '../../includes/footer.php'; ?>

