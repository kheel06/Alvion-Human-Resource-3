<?php
require_once __DIR__ . '/../../config/config.php';
requireAuth();

$page_title = 'User Profile';

// Get current user data
$userData = null;
if (isset($db)) {
    try {
        $stmt = $db->prepare("SELECT * FROM users WHERE id = :id");
        $stmt->bindValue(':id', $_SESSION['user_id'], PDO::PARAM_INT);
        $stmt->execute();
        $userData = $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log("Error fetching user data: " . $e->getMessage());
    }
}

include __DIR__ . '/../../includes/header.php';
?>

<div class="max-w-4xl mx-auto">
    <!-- Page Header -->
    <div class="mb-8">
        <h1 class="text-3xl font-bold text-gray-900 dark:text-white">User Profile</h1>
        <p class="mt-2 text-gray-600 dark:text-gray-400">View your account information</p>
    </div>

    <!-- Profile Information -->
    <div class="bg-white dark:bg-gray-800 shadow rounded-lg">
        <div class="px-6 py-4 border-b border-gray-200 dark:border-gray-700">
            <h2 class="text-lg font-medium text-gray-900 dark:text-white">Account Information</h2>
        </div>
        <div class="px-6 py-4">
            <?php if ($userData): ?>
                <dl class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <div>
                        <dt class="text-sm font-medium text-gray-500 dark:text-gray-400">Name</dt>
                        <dd class="mt-1 text-sm text-gray-900 dark:text-white">
                            <?php echo htmlspecialchars($userData['first_name'] . ' ' . $userData['last_name']); ?>
                        </dd>
                    </div>
                    <div>
                        <dt class="text-sm font-medium text-gray-500 dark:text-gray-400">Email</dt>
                        <dd class="mt-1 text-sm text-gray-900 dark:text-white">
                            <?php echo htmlspecialchars($userData['email'] ?? 'N/A'); ?>
                        </dd>
                    </div>
                    <div>
                        <dt class="text-sm font-medium text-gray-500 dark:text-gray-400">Role</dt>
                        <dd class="mt-1 text-sm text-gray-900 dark:text-white">
                            <?php echo htmlspecialchars(ucfirst($userData['role_name'] ?? $_SESSION['user_role'] ?? 'User')); ?>
                        </dd>
                    </div>
                    <div>
                        <dt class="text-sm font-medium text-gray-500 dark:text-gray-400">User ID</dt>
                        <dd class="mt-1 text-sm text-gray-900 dark:text-white">
                            <?php echo htmlspecialchars($userData['id']); ?>
                        </dd>
                    </div>
                    <div>
                        <dt class="text-sm font-medium text-gray-500 dark:text-gray-400">Account Created</dt>
                        <dd class="mt-1 text-sm text-gray-900 dark:text-white">
                            <?php echo date('M j, Y', strtotime($userData['created_at'] ?? 'now')); ?>
                        </dd>
                    </div>
                    <div>
                        <dt class="text-sm font-medium text-gray-500 dark:text-gray-400">Last Updated</dt>
                        <dd class="mt-1 text-sm text-gray-900 dark:text-white">
                            <?php echo date('M j, Y', strtotime($userData['updated_at'] ?? $userData['created_at'] ?? 'now')); ?>
                        </dd>
                </dl>
            <?php else: ?>
                <p class="text-gray-500 dark:text-gray-400">Unable to load user information.</p>
            <?php endif; ?>
        </div>
    </div>

    <!-- Navigation -->
    <div class="mt-6">
        <a href="<?php echo BASE_URL; ?>/index.php" class="inline-flex items-center px-4 py-2 border border-gray-300 dark:border-gray-600 rounded-md shadow-sm text-sm font-medium text-gray-700 dark:text-gray-300 bg-white dark:bg-gray-800 hover:bg-gray-50 dark:hover:bg-gray-700">
            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="mr-2">
                <path d="m15 18-6-6 6-6"/>
                <path d="M21 12H9"/>
            </svg>
            Back to Dashboard
        </a>
    </div>
</div>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
