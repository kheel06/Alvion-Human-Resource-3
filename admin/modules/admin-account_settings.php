<?php
require_once __DIR__ . '/../../config/config.php';
requireAuth();
checkRole(['admin']);

$page_title = 'Admin Account Settings';

// Get employee_id from department_accounts table if not set in session
if (!isset($_SESSION['employee_id']) && isset($_SESSION['user_id'])) {
    try {
        $stmt = $db->prepare("SELECT employee_id FROM department_accounts WHERE role_id = :user_id OR employee_id = :user_id LIMIT 1");
        $stmt->bindValue(':user_id', $_SESSION['user_id'], PDO::PARAM_INT);
        $stmt->execute();
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($result && !empty($result['employee_id'])) {
            $_SESSION['employee_id'] = $result['employee_id'];
        }
    } catch (PDOException $e) {
        error_log("Error fetching employee_id for admin: " . $e->getMessage());
    }
}

// Handle basic POST (profile and security updates). This is a scaffold; extend as needed.
$errors = [];
$success = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    try {
        if ($action === 'update_profile' && isset($db)) {
            $firstName = trim($_POST['employee_fname'] ?? '');
            $lastName  = trim($_POST['employee_lname'] ?? '');

            if ($firstName === '' || $lastName === '') {
                $errors[] = 'First name and last name are required.';
            } else {
                $stmt = $db->prepare("
                    UPDATE department_accounts
                    SET employee_fname = :first_name,
                        employee_lname  = :last_name
                    WHERE employee_id = :id
                ");
                $stmt->bindValue(':first_name', $firstName);
                $stmt->bindValue(':last_name', $lastName);
                $stmt->bindValue(':id', $_SESSION['employee_id'] ?? $_SESSION['user_id'], PDO::PARAM_STR);
                $stmt->execute();

                // Update session
                $_SESSION['first_name'] = $firstName;
                $_SESSION['last_name']  = $lastName;

                $success = 'Profile updated successfully.';
            }
        }

        if ($action === 'update_password' && isset($db)) {
            $currentPassword = $_POST['current_password'] ?? '';
            $newPassword     = $_POST['new_password'] ?? '';
            $confirmPassword  = $_POST['confirm_password'] ?? '';

            if ($currentPassword === '' || $newPassword === '' || $confirmPassword === '') {
                $errors[] = 'All password fields are required.';
            } elseif ($newPassword !== $confirmPassword) {
                $errors[] = 'New password and confirmation do not match.';
            } elseif (strlen($newPassword) < 6) {
                $errors[] = 'New password must be at least 6 characters long.';
            } else {
                // Verify current password
                $stmt = $db->prepare("SELECT password FROM department_accounts WHERE employee_id = :id");
                $stmt->bindValue(':id', $_SESSION['employee_id'] ?? $_SESSION['user_id'], PDO::PARAM_STR);
                $stmt->execute();
                $user = $stmt->fetch(PDO::FETCH_ASSOC);

                if ($user && password_verify($currentPassword, $user['password'])) {
                    $hashedPassword = password_hash($newPassword, PASSWORD_DEFAULT);
                    $stmt = $db->prepare("UPDATE department_accounts SET password = :password WHERE employee_id = :id");
                    $stmt->bindValue(':password', $hashedPassword);
                    $stmt->bindValue(':id', $_SESSION['employee_id'] ?? $_SESSION['user_id'], PDO::PARAM_STR);
                    $stmt->execute();

                    $success = 'Password updated successfully.';
                } else {
                    $errors[] = 'Current password is incorrect.';
                }
            }
        }

        if ($action === 'update_email' && isset($db)) {
            $email = trim($_POST['employee_email'] ?? '');

            if ($email === '') {
                $errors[] = 'Email is required.';
            } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $errors[] = 'Please enter a valid email address.';
            } else {
                // Check if email is already taken by another user
                $stmt = $db->prepare("SELECT employee_id FROM department_accounts WHERE employee_email = :email AND employee_id != :id");
                $stmt->bindValue(':email', $email);
                $stmt->bindValue(':id', $_SESSION['employee_id'] ?? $_SESSION['user_id'], PDO::PARAM_STR);
                $stmt->execute();

                if ($stmt->rowCount() > 0) {
                    $errors[] = 'Email is already in use by another account.';
                } else {
                    $stmt = $db->prepare("UPDATE department_accounts SET employee_email = :email WHERE employee_id = :id");
                    $stmt->bindValue(':email', $email);
                    $stmt->bindValue(':id', $_SESSION['employee_id'] ?? $_SESSION['user_id'], PDO::PARAM_STR);
                    $stmt->execute();

                    $_SESSION['email'] = $email;
                    $success = 'Email updated successfully.';
                }
            }
        }

        // Handle profile picture upload
        if ($action === 'update_profile_picture' && isset($_FILES['profile_picture']) && $_FILES['profile_picture']['error'] === UPLOAD_ERR_OK) {
            $allowedTypes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
            $maxSize = 5 * 1024 * 1024; // 5MB

            if (!in_array($_FILES['profile_picture']['type'], $allowedTypes)) {
                $errors[] = 'Invalid file type. Please upload JPEG, PNG, GIF, or WebP images.';
            } elseif ($_FILES['profile_picture']['size'] > $maxSize) {
                $errors[] = 'File size too large. Maximum size is 5MB.';
            } else {
                $uploadDir = __DIR__ . '/../../assets/uploads/profile_pictures/';
                if (!is_dir($uploadDir)) {
                    mkdir($uploadDir, 0755, true);
                }

                $fileName = 'admin_profile_' . $_SESSION['user_id'] . '_' . time() . '.' . pathinfo($_FILES['profile_picture']['name'], PATHINFO_EXTENSION);
                $filePath = $uploadDir . $fileName;

                if (move_uploaded_file($_FILES['profile_picture']['tmp_name'], $filePath)) {
                    // Save relative path to database
                    $relativePath = 'assets/uploads/profile_pictures/' . $fileName;
                    $stmt = $db->prepare("UPDATE department_accounts SET profile_picture = :profile_picture WHERE employee_id = :id");
                    $stmt->bindValue(':profile_picture', $relativePath);
                    $stmt->bindValue(':id', $_SESSION['employee_id'] ?? $_SESSION['user_id'], PDO::PARAM_STR);
                    $stmt->execute();

                    $_SESSION['profile_picture'] = $relativePath;
                    $success = 'Profile picture updated successfully.';
                } else {
                    $errors[] = 'Failed to upload profile picture.';
                }
            }
        }

    } catch (PDOException $e) {
        $errors[] = 'Database error. Please try again.';
        error_log("Admin account settings error: " . $e->getMessage());
    }
}

// Get current user data
$userData = null;
if (isset($db)) {
    try {
        $stmt = $db->prepare("SELECT * FROM department_accounts WHERE employee_id = :id");
        $stmt->bindValue(':id', $_SESSION['employee_id'] ?? $_SESSION['user_id'], PDO::PARAM_STR);
        $stmt->execute();
        $userData = $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log("Error fetching admin data: " . $e->getMessage());
    }
}

include __DIR__ . '/../../includes/header.php';
?>

<div class="max-w-4xl mx-auto">
    <!-- Page Header -->
    <div class="mb-8">
        <h1 class="text-3xl font-bold text-gray-900 dark:text-white">Account Settings</h1>
        <p class="mt-2 text-gray-600 dark:text-gray-400">Manage your admin account settings and preferences</p>
    </div>

    <!-- Alerts -->
    <?php if (!empty($errors)): ?>
        <div class="mb-6 bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-800 rounded-lg p-4">
            <div class="flex">
                <div class="flex-shrink-0">
                    <svg class="h-5 w-5 text-red-400" viewBox="0 0 20 20" fill="currentColor">
                        <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zM8.707 7.293a1 1 0 00-1.414 1.414L8.586 10l-1.293 1.293a1 1 0 101.414 1.414L10 11.414l1.293 1.293a1 1 0 001.414-1.414L11.414 10l1.293-1.293a1 1 0 00-1.414-1.414L10 8.586 8.707 7.293z" clip-rule="evenodd"/>
                    </svg>
                </div>
                <div class="ml-3">
                    <h3 class="text-sm font-medium text-red-800 dark:text-red-200">Error</h3>
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

    <?php if ($success): ?>
        <div class="mb-6 bg-green-50 dark:bg-green-900/20 border border-green-200 dark:border-green-800 rounded-lg p-4">
            <div class="flex">
                <div class="flex-shrink-0">
                    <svg class="h-5 w-5 text-green-400" viewBox="0 0 20 20" fill="currentColor">
                        <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"/>
                    </svg>
                </div>
                <div class="ml-3">
                    <h3 class="text-sm font-medium text-green-800 dark:text-green-200">Success</h3>
                    <div class="mt-2 text-sm text-green-700 dark:text-green-300">
                        <?php echo htmlspecialchars($success); ?>
                    </div>
                </div>
            </div>
        </div>
    <?php endif; ?>

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
        <!-- Profile Information -->
        <div class="lg:col-span-2">
            <div class="bg-white dark:bg-gray-800 shadow rounded-lg">
                <div class="px-6 py-4 border-b border-gray-200 dark:border-gray-700">
                    <h2 class="text-lg font-medium text-gray-900 dark:text-white">Profile Information</h2>
                </div>
                <div class="px-6 py-4">
                    <form method="POST" action="">
                        <input type="hidden" name="action" value="update_profile">
                        
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                            <div>
                                <label for="employee_fname" class="block text-sm font-medium text-gray-700 dark:text-gray-300">First Name</label>
                                <input type="text" id="employee_fname" name="employee_fname" value="<?php echo htmlspecialchars($userData['employee_fname'] ?? ''); ?>" 
                                       class="mt-1 block w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-md shadow-sm focus:outline-none focus:ring-primary-500 focus:border-primary-500 dark:bg-gray-700 dark:text-white" required>
                            </div>
                            
                            <div>
                                <label for="employee_lname" class="block text-sm font-medium text-gray-700 dark:text-gray-300">Last Name</label>
                                <input type="text" id="employee_lname" name="employee_lname" value="<?php echo htmlspecialchars($userData['employee_lname'] ?? ''); ?>" 
                                       class="mt-1 block w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-md shadow-sm focus:outline-none focus:ring-primary-500 focus:border-primary-500 dark:bg-gray-700 dark:text-white" required>
                            </div>
                        </div>
                        
                        <div class="mt-6">
                            <label for="employee_email" class="block text-sm font-medium text-gray-700 dark:text-gray-300">Email Address</label>
                            <input type="email" id="employee_email" name="employee_email" value="<?php echo htmlspecialchars($userData['employee_email'] ?? ''); ?>" 
                                   class="mt-1 block w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-md shadow-sm focus:outline-none focus:ring-primary-500 focus:border-primary-500 dark:bg-gray-700 dark:text-white" required>
                        </div>
                        
                        <div class="mt-6">
                            <button type="submit" class="px-4 py-2 bg-primary-600 text-white rounded-md hover:bg-primary-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-primary-500 transition-colors">
                                Update Profile
                            </button>
                        </div>
                    </form>
                </div>
            </div>

            <!-- Change Password -->
            <div class="bg-white dark:bg-gray-800 shadow rounded-lg mt-6">
                <div class="px-6 py-4 border-b border-gray-200 dark:border-gray-700">
                    <h2 class="text-lg font-medium text-gray-900 dark:text-white">Change Password</h2>
                </div>
                <div class="px-6 py-4">
                    <form method="POST" action="">
                        <input type="hidden" name="action" value="update_password">
                        
                        <div class="space-y-4">
                            <div>
                                <label for="current_password" class="block text-sm font-medium text-gray-700 dark:text-gray-300">Current Password</label>
                                <input type="password" id="current_password" name="current_password" 
                                       class="mt-1 block w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-md shadow-sm focus:outline-none focus:ring-primary-500 focus:border-primary-500 dark:bg-gray-700 dark:text-white" required>
                            </div>
                            
                            <div>
                                <label for="new_password" class="block text-sm font-medium text-gray-700 dark:text-gray-300">New Password</label>
                                <input type="password" id="new_password" name="new_password" minlength="6"
                                       class="mt-1 block w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-md shadow-sm focus:outline-none focus:ring-primary-500 focus:border-primary-500 dark:bg-gray-700 dark:text-white" required>
                            </div>
                            
                            <div>
                                <label for="confirm_password" class="block text-sm font-medium text-gray-700 dark:text-gray-300">Confirm New Password</label>
                                <input type="password" id="confirm_password" name="confirm_password" minlength="6"
                                       class="mt-1 block w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-md shadow-sm focus:outline-none focus:ring-primary-500 focus:border-primary-500 dark:bg-gray-700 dark:text-white" required>
                            </div>
                        </div>
                        
                        <div class="mt-6">
                            <button type="submit" class="px-4 py-2 bg-primary-600 text-white rounded-md hover:bg-primary-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-primary-500 transition-colors">
                                Change Password
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <!-- Profile Picture -->
        <div class="lg:col-span-1">
            <div class="bg-white dark:bg-gray-800 shadow rounded-lg">
                <div class="px-6 py-4 border-b border-gray-200 dark:border-gray-700">
                    <h2 class="text-lg font-medium text-gray-900 dark:text-white">Profile Picture</h2>
                </div>
                <div class="px-6 py-4">
                    <div class="flex flex-col items-center">
                        <?php 
                        $profilePicture = $userData['profile_picture'] ?? '';
                        if (!empty($profilePicture) && file_exists(__DIR__ . '/../../' . $profilePicture)): ?>
                            <img src="<?php echo BASE_URL . '/' . htmlspecialchars($profilePicture); ?>" 
                                 alt="Profile Picture" 
                                 class="w-24 h-24 rounded-full object-cover border-4 border-gray-200 dark:border-gray-700">
                        <?php else: ?>
                            <div class="w-24 h-24 bg-purple-500 rounded-full flex items-center justify-center text-white text-2xl font-bold">
                                <?php echo strtoupper(substr($userData['first_name'] ?? '', 0, 1)) . strtoupper(substr($userData['last_name'] ?? '', 0, 1)); ?>
                            </div>
                        <?php endif; ?>
                        
                        <form method="POST" action="" enctype="multipart/form-data" class="mt-4 w-full">
                            <input type="hidden" name="action" value="update_profile_picture">
                            
                            <div class="mb-4">
                                <label for="profile_picture" class="block text-sm font-medium text-gray-700 dark:text-gray-300 text-center">Upload New Picture</label>
                                <input type="file" id="profile_picture" name="profile_picture" accept="image/*" 
                                       class="mt-1 block w-full text-sm text-gray-500 dark:text-gray-400 file:mr-4 file:py-2 file:px-4 file:rounded-md file:border-0 file:text-sm file:font-medium file:bg-primary-50 file:text-primary-700 hover:file:bg-primary-100 dark:file:bg-primary-900/20 dark:file:text-primary-300">
                                <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">JPEG, PNG, GIF, or WebP (max 5MB)</p>
                            </div>
                            
                            <button type="submit" class="w-full px-4 py-2 bg-primary-600 text-white rounded-md hover:bg-primary-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-primary-500 transition-colors">
                                Upload Picture
                            </button>
                        </form>
                    </div>
                </div>
            </div>

            <!-- Account Info -->
            <div class="bg-white dark:bg-gray-800 shadow rounded-lg mt-6">
                <div class="px-6 py-4 border-b border-gray-200 dark:border-gray-700">
                    <h2 class="text-lg font-medium text-gray-900 dark:text-white">Account Information</h2>
                </div>
                <div class="px-6 py-4">
                    <dl class="space-y-3">
                        <div>
                            <dt class="text-sm font-medium text-gray-500 dark:text-gray-400">User ID</dt>
                            <dd class="text-sm text-gray-900 dark:text-white"><?php echo htmlspecialchars($userData['employee_id'] ?? ''); ?></dd>
                        </div>
                        <div>
                            <dt class="text-sm font-medium text-gray-500 dark:text-gray-400">Role</dt>
                            <dd class="text-sm text-gray-900 dark:text-white">Administrator</dd>
                        </div>
                        <div>
                            <dt class="text-sm font-medium text-gray-500 dark:text-gray-400">Account Created</dt>
                            <dd class="text-sm text-gray-900 dark:text-white"><?php echo date('M j, Y', strtotime($userData['created_at'] ?? 'now')); ?></dd>
                        </div>
                    </dl>
                </div>
            </div>
        </div>
    </div>
</div>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
