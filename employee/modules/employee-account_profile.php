<?php
require_once __DIR__ . '/../../config/config.php';
requireAuth();
checkRole(['employee']);

$page_title = 'Profile & Settings';
$view = $_GET['view'] ?? 'basic_info';

// Get current employee ID - resolve to numeric employees.id
$employeeId = getCurrentEmployeeId($db ?? null);
if (!$employeeId) $employeeId = $_SESSION['employee_id'] ?? $_SESSION['user_id'] ?? null;

if (!$employeeId) {
    $_SESSION['error'] = "Employee identifier not found. Please log in again.";
    header("Location: " . BASE_URL . "/auth/employee-login.php");
    exit();
}

$employee = null;
$devices = [];

if (isset($db)) {
    try {
        // Get employee data from employees table joined with units
        $stmt = $db->prepare("
            SELECT e.*, u.name as department_name, da.role_name
            FROM employees e
            LEFT JOIN units u ON e.unit_id = u.id
            LEFT JOIN department_accounts da ON da.employee_id = e.employee_number
            WHERE e.id = :emp_id
            LIMIT 1
        ");
        $stmt->bindValue(':emp_id', $employeeId, PDO::PARAM_INT);
        $stmt->execute();
        $employee = $stmt->fetch(PDO::FETCH_ASSOC);

        // If not found via numeric id, try via employee_number string
        if (!$employee) {
            $empNo = $_SESSION['employee_id'] ?? null;
            if ($empNo) {
                $stmt = $db->prepare("
                    SELECT e.*, u.name as department_name, da.role_name
                    FROM employees e
                    LEFT JOIN units u ON e.unit_id = u.id
                    LEFT JOIN department_accounts da ON da.employee_id = e.employee_number
                    WHERE e.employee_number = :emp_no
                    LIMIT 1
                ");
                $stmt->bindValue(':emp_no', $empNo);
                $stmt->execute();
                $employee = $stmt->fetch(PDO::FETCH_ASSOC);
            }
        }

        // Fallback: get basic info from department_accounts
        if (!$employee) {
            $empNo = $_SESSION['employee_id'] ?? $employeeId;
            $stmt = $db->prepare("
                SELECT employee_id as employee_number, employee_fname as first_name, employee_lname as last_name,
                       employee_email as email, role_name
                FROM department_accounts
                WHERE employee_id = :emp_id
                LIMIT 1
            ");
            $stmt->bindValue(':emp_id', $empNo);
            $stmt->execute();
            $employee = $stmt->fetch(PDO::FETCH_ASSOC);
        }

        // Get recent login sessions as "devices"
        if ($view === 'devices') {
            try {
                $stmt = $db->prepare("
                    SELECT action, ip_address, user_agent, created_at
                    FROM audit_logs
                    WHERE employee_id = :emp_id AND action IN ('login', 'time_punch', 'qr_punch', 'submit_timesheet')
                    ORDER BY created_at DESC
                    LIMIT 10
                ");
                $stmt->bindValue(':emp_id', $_SESSION['employee_id'] ?? $employeeId);
                $stmt->execute();
                $sessions = $stmt->fetchAll(PDO::FETCH_ASSOC);
                foreach ($sessions as $s) {
                    $ua = $s['user_agent'] ?? 'Unknown';
                    $deviceType = (stripos($ua, 'mobile') !== false || stripos($ua, 'android') !== false || stripos($ua, 'iphone') !== false) ? 'Mobile' : 'Desktop';
                    $browser = 'Unknown';
                    if (preg_match('/(Chrome|Firefox|Safari|Edge|Opera)[\/\s](\d+)/i', $ua, $m)) $browser = $m[1] . ' ' . $m[2];
                    $devices[] = [
                        'device_type' => $deviceType,
                        'device_name' => $deviceType,
                        'browser' => $browser,
                        'ip_address' => $s['ip_address'] ?? '-',
                        'last_active' => $s['created_at'],
                        'status' => 'active'
                    ];
                }
            } catch (PDOException $e) {}
        }
    } catch (PDOException $e) {
        error_log('Profile error: ' . $e->getMessage());
    }
}

// No sample data fallback – real data only

// Handle profile picture operations (moved after employee data is loaded)
$message = '';
$uploadError = '';

// Handle profile picture upload
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['upload_profile_pic'])) {
    if (isset($_FILES['profile_picture']) && $_FILES['profile_picture']['error'] === UPLOAD_ERR_OK) {
        $file = $_FILES['profile_picture'];
        $allowedTypes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
        $maxSize = 5 * 1024 * 1024; // 5MB
        
        // Validate file type
        if (!in_array($file['type'], $allowedTypes)) {
            $uploadError = 'Invalid file type. Only JPG, PNG, GIF, and WebP images are allowed.';
        } elseif ($file['size'] > $maxSize) {
            $uploadError = 'File size too large. Maximum size is 5MB.';
        } else {
            // Create upload directory if it doesn't exist
            $uploadDir = __DIR__ . '/../../assets/uploads/profile_pictures/';
            if (!is_dir($uploadDir)) {
                mkdir($uploadDir, 0755, true);
            }
            
            // Generate unique filename
            $ext = pathinfo($file['name'], PATHINFO_EXTENSION);
            $filename = 'profile_' . $employeeId . '_' . time() . '.' . $ext;
            $filePath = $uploadDir . $filename;
            
            // Delete old profile picture if exists
            if (!empty($employee['profile_picture']) && file_exists(__DIR__ . '/../../' . $employee['profile_picture'])) {
                unlink(__DIR__ . '/../../' . $employee['profile_picture']);
            }
            
            // Upload new file
            if (move_uploaded_file($file['tmp_name'], $filePath)) {
                // Update database if available
                if (isset($db)) {
                    try {
                        $stmt = $db->prepare("
                            UPDATE department_accounts 
                            SET profile_picture = :profile_picture, updated_at = NOW()
                            WHERE employee_id = :emp_id
                        ");
                        $stmt->bindValue(':profile_picture', 'assets/uploads/profile_pictures/' . $filename);
                        $stmt->bindValue(':emp_id', $employeeId);
                        $stmt->execute();
                        
                        // Update session data
                        $employee['profile_picture'] = 'assets/uploads/profile_pictures/' . $filename;
                        
                        $message = 'Profile picture updated successfully!';
                        $_SESSION['success'] = $message;
                    } catch (PDOException $e) {
                        error_log('Profile picture update error: ' . $e->getMessage());
                        $uploadError = 'Database error occurred. Please try again.';
                    }
                } else {
                    // Store in session if no database
                    $_SESSION['profile_picture'] = 'assets/uploads/profile_pictures/' . $filename;
                    $employee['profile_picture'] = 'assets/uploads/profile_pictures/' . $filename;
                    $message = 'Profile picture updated successfully!';
                    $_SESSION['success'] = $message;
                }
            } else {
                $uploadError = 'Failed to upload file. Please try again.';
            }
        }
    } else {
        $uploadError = 'Please select a file to upload.';
    }
    
    if ($uploadError) {
        $_SESSION['error'] = $uploadError;
    }
    header("Location: " . $_SERVER['PHP_SELF'] . "?view=basic_info");
    exit();
}

// Handle profile picture removal
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['remove_profile_pic'])) {
    if (!empty($employee['profile_picture'])) {
        // Delete file if exists
        $filePath = __DIR__ . '/../../' . $employee['profile_picture'];
        if (file_exists($filePath)) {
            unlink($filePath);
        }
        
        // Update database if available
        if (isset($db)) {
            try {
                $stmt = $db->prepare("
                    UPDATE department_accounts 
                    SET profile_picture = NULL, updated_at = NOW()
                    WHERE employee_id = :emp_id
                ");
                $stmt->bindValue(':emp_id', $employeeId);
                $stmt->execute();
                
                // Update session data
                $employee['profile_picture'] = null;
                
                $message = 'Profile picture removed successfully!';
                $_SESSION['success'] = $message;
            } catch (PDOException $e) {
                error_log('Profile picture removal error: ' . $e->getMessage());
                $uploadError = 'Database error occurred. Please try again.';
            }
        } else {
            // Remove from session if no database
            unset($_SESSION['profile_picture']);
            $employee['profile_picture'] = null;
            $message = 'Profile picture removed successfully!';
            $_SESSION['success'] = $message;
        }
    }
    
    if ($uploadError) {
        $_SESSION['error'] = $uploadError;
    }
    header("Location: " . $_SERVER['PHP_SELF'] . "?view=basic_info");
    exit();
}

include __DIR__ . '/../../includes/header.php';
?>

<!-- Profile Picture JavaScript -->
<script>
document.addEventListener('DOMContentLoaded', function() {
    const fileInput = document.querySelector('input[name="profile_picture"]');
    const uploadForm = fileInput ? fileInput.closest('form') : null;
    
    if (fileInput && uploadForm) {
        fileInput.addEventListener('change', function(e) {
            const file = e.target.files[0];
            if (file) {
                // Validate file size
                if (file.size > 5 * 1024 * 1024) {
                    alert('File size too large. Maximum size is 5MB.');
                    e.target.value = '';
                    return;
                }
                
                // Validate file type
                const allowedTypes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
                if (!allowedTypes.includes(file.type)) {
                    alert('Invalid file type. Only JPG, PNG, GIF, and WebP images are allowed.');
                    e.target.value = '';
                    return;
                }
                
                // Show preview
                const reader = new FileReader();
                reader.onload = function(e) {
                    // Find the profile picture container
                    const container = document.querySelector('.w-24.h-24.rounded-full');
                    if (container) {
                        // Replace the content with the new image
                        container.innerHTML = `<img src="${e.target.result}" alt="Profile Picture" class="w-full h-full object-cover">`;
                    }
                };
                reader.readAsDataURL(file);
            }
        });
    }
});
</script>

<div class="mb-6">
    <h1 class="text-2xl font-semibold text-gray-900 dark:text-white">Profile & Settings</h1>
    <p class="mt-1 text-sm text-gray-600 dark:text-gray-400">Personal information, preferences, and documents</p>
</div>

<!-- Success/Error Messages -->
<?php if (isset($_SESSION['success'])): ?>
    <div class="mb-4 p-4 bg-green-50 dark:bg-green-900 border border-green-200 dark:border-green-700 rounded-lg">
        <div class="flex">
            <div class="flex-shrink-0">
                <svg class="w-5 h-5 text-green-400" fill="currentColor" viewBox="0 0 20 20">
                    <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"></path>
                </svg>
            </div>
            <div class="ml-3">
                <p class="text-sm text-green-700 dark:text-green-200">
                    <?php echo htmlspecialchars($_SESSION['success']); ?>
                </p>
            </div>
        </div>
    </div>
    <?php unset($_SESSION['success']); ?>
<?php endif; ?>

<?php if (isset($_SESSION['error'])): ?>
    <div class="mb-4 p-4 bg-red-50 dark:bg-red-900 border border-red-200 dark:border-red-700 rounded-lg">
        <div class="flex">
            <div class="flex-shrink-0">
                <svg class="w-5 h-5 text-red-400" fill="currentColor" viewBox="0 0 20 20">
                    <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zM8.707 7.293a1 1 0 00-1.414 1.414L8.586 10l-1.293 1.293a1 1 0 101.414 1.414L10 11.414l1.293 1.293a1 1 0 001.414-1.414L11.414 10l1.293-1.293a1 1 0 00-1.414-1.414L10 8.586 8.707 7.293z" clip-rule="evenodd"></path>
                </svg>
            </div>
            <div class="ml-3">
                <p class="text-sm text-red-700 dark:text-red-200">
                    <?php echo htmlspecialchars($_SESSION['error']); ?>
                </p>
            </div>
        </div>
    </div>
    <?php unset($_SESSION['error']); ?>
<?php endif; ?>

<?php if ($view === 'basic_info'): ?>
    <!-- Profile Picture Section -->
    <div class="bg-white dark:bg-gray-800 rounded-xl shadow-sm border border-gray-100 dark:border-gray-700 mb-6">
        <div class="px-5 py-4 border-b border-gray-100 dark:border-gray-700">
            <h2 class="text-sm font-semibold text-gray-900 dark:text-white">Profile Picture</h2>
        </div>
        <div class="p-5">
            <div class="flex items-center space-x-6">
                <!-- Current Profile Picture -->
                <div class="flex-shrink-0">
                    <div class="w-24 h-24 rounded-full bg-gray-200 dark:bg-gray-700 overflow-hidden">
                        <?php if (!empty($employee['profile_picture']) && file_exists(__DIR__ . '/../../' . $employee['profile_picture'])): ?>
                            <img src="<?php echo BASE_URL . '/' . htmlspecialchars($employee['profile_picture']); ?>" 
                                 alt="Profile Picture" 
                                 class="w-full h-full object-cover">
                        <?php else: ?>
                            <div class="w-full h-full flex items-center justify-center">
                                <svg xmlns="http://www.w3.org/2000/svg" class="w-12 h-12 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z" />
                                </svg>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
                
                <!-- Upload Form -->
                <div class="flex-1">
                    <form method="POST" enctype="multipart/form-data" class="space-y-4">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                                Upload New Profile Picture
                            </label>
                            <div class="flex items-center space-x-4">
                                <input type="file" 
                                       name="profile_picture" 
                                       accept="image/jpeg,image/png,image/gif,image/webp"
                                       class="block w-full text-sm text-gray-500 dark:text-gray-400
                                              file:mr-4 file:py-2 file:px-4
                                              file:rounded-full file:border-0
                                              file:text-sm file:font-semibold
                                              file:bg-blue-50 file:text-blue-700
                                              dark:file:bg-blue-900 dark:file:text-blue-300
                                              hover:file:bg-blue-100
                                              dark:hover:file:bg-blue-800
                                              cursor-pointer">
                                <button type="submit" 
                                        name="upload_profile_pic"
                                        class="px-4 py-2 bg-blue-600 text-white text-sm font-medium rounded-lg hover:bg-blue-700 transition-colors">
                                    Upload
                                </button>
                            </div>
                            <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                                Allowed formats: JPG, PNG, GIF, WebP. Maximum size: 5MB
                            </p>
                        </div>
                    </form>
                    
                    <!-- Remove Profile Picture -->
                    <?php if (!empty($employee['profile_picture'])): ?>
                        <form method="POST" class="mt-4">
                            <button type="submit" 
                                    name="remove_profile_pic"
                                    onclick="return confirm('Are you sure you want to remove your profile picture?')"
                                    class="px-4 py-2 bg-red-600 text-white text-sm font-medium rounded-lg hover:bg-red-700 transition-colors">
                                Remove Profile Picture
                            </button>
                        </form>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- Personal Information -->
    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
        <!-- Basic Info (Read-only) -->
        <div class="bg-white dark:bg-gray-800 rounded-xl shadow-sm border border-gray-100 dark:border-gray-700">
            <div class="px-5 py-4 border-b border-gray-100 dark:border-gray-700">
                <h2 class="text-sm font-semibold text-gray-900 dark:text-white">Personal Information (Read-only)</h2>
            </div>
            <div class="p-5">
                <?php if ($employee): ?>
                    <div class="space-y-4">
                        <div class="grid grid-cols-2 gap-4">
                            <div>
                                <label class="block text-xs font-medium text-gray-500 dark:text-gray-400 mb-1">Employee Number</label>
                                <p class="text-sm font-semibold text-gray-900 dark:text-white">
                                    <?php echo htmlspecialchars($employee['employee_number'] ?? $employee['emp_no'] ?? 'N/A'); ?>
                                </p>
                            </div>
                            <div>
                                <label class="block text-xs font-medium text-gray-500 dark:text-gray-400 mb-1">Position</label>
                                <p class="text-sm font-semibold text-gray-900 dark:text-white">
                                    <?php echo htmlspecialchars($employee['position'] ?? 'N/A'); ?>
                                </p>
                            </div>
                        </div>
                        <div>
                            <label class="block text-xs font-medium text-gray-500 dark:text-gray-400 mb-1">Full Name</label>
                            <p class="text-sm font-semibold text-gray-900 dark:text-white">
                                <?php 
                                $firstName = $employee['first_name'] ?? $employee['employee_fname'] ?? '';
                                $lastName = $employee['last_name'] ?? $employee['employee_lname'] ?? '';
                                echo htmlspecialchars(trim($firstName . ' ' . $lastName));
                                ?>
                            </p>
                        </div>
                        <div>
                            <label class="block text-xs font-medium text-gray-500 dark:text-gray-400 mb-1">Email</label>
                            <p class="text-sm text-gray-900 dark:text-white">
                                <?php echo htmlspecialchars($employee['email'] ?? $employee['employee_email'] ?? 'N/A'); ?>
                            </p>
                        </div>
                        <div>
                            <label class="block text-xs font-medium text-gray-500 dark:text-gray-400 mb-1">Department</label>
                            <p class="text-sm text-gray-900 dark:text-white">
                                <?php echo htmlspecialchars($employee['department_name'] ?? 'N/A'); ?>
                            </p>
                        </div>
                    </div>
                <?php else: ?>
                    <p class="text-sm text-gray-500 text-center py-8">Employee information not found</p>
                <?php endif; ?>
            </div>
        </div>

        <!-- Preferences -->
        <div class="bg-white dark:bg-gray-800 rounded-xl shadow-sm border border-gray-100 dark:border-gray-700">
            <div class="px-5 py-4 border-b border-gray-100 dark:border-gray-700">
                <h2 class="text-sm font-semibold text-gray-900 dark:text-white">Preferences</h2>
            </div>
            <div class="p-5">
                <form id="preferencesForm">
                    <div class="space-y-4">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Notification Preferences</label>
                            <div class="space-y-2">
                                <label class="flex items-center">
                                    <input type="checkbox" class="rounded border-gray-300 text-primary-600 focus:ring-primary-500">
                                    <span class="ml-2 text-sm text-gray-700 dark:text-gray-300">Email notifications</span>
                                </label>
                                <label class="flex items-center">
                                    <input type="checkbox" class="rounded border-gray-300 text-primary-600 focus:ring-primary-500" checked>
                                    <span class="ml-2 text-sm text-gray-700 dark:text-gray-300">In-app notifications</span>
                                </label>
                            </div>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Theme</label>
                            <select class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg dark:bg-gray-700 dark:text-white">
                                <option value="light">Light</option>
                                <option value="dark" selected>Dark</option>
                                <option value="auto">Auto</option>
                            </select>
                        </div>
                        <button type="button" class="w-full px-4 py-2 bg-primary-600 text-white rounded-lg hover:bg-primary-700 transition-colors">
                            Save Preferences
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Documents -->
    <div class="bg-white dark:bg-gray-800 rounded-xl shadow-sm border border-gray-100 dark:border-gray-700 mt-6">
        <div class="px-5 py-4 border-b border-gray-100 dark:border-gray-700">
            <h2 class="text-sm font-semibold text-gray-900 dark:text-white">Documents</h2>
        </div>
        <div class="p-5">
            <div class="space-y-3">
                <div class="flex items-center justify-between p-3 bg-gray-50 dark:bg-gray-700/50 rounded-lg">
                    <div class="flex items-center space-x-3">
                        <svg xmlns="http://www.w3.org/2000/svg" class="w-8 h-8 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
                        </svg>
                        <div>
                            <p class="text-sm font-medium text-gray-900 dark:text-white">Employment Contract</p>
                            <p class="text-xs text-gray-500">PDF • 245 KB</p>
                        </div>
                    </div>
                    <button class="text-primary-600 hover:text-primary-700 text-sm">Download</button>
                </div>
                <p class="text-xs text-gray-500 text-center py-4">No other documents available</p>
            </div>
        </div>
    </div>

<?php elseif ($view === 'department_unit'): ?>
    <!-- Assigned Department/Unit -->
    <div class="bg-white dark:bg-gray-800 rounded-xl shadow-sm border border-gray-100 dark:border-gray-700">
        <div class="px-5 py-4 border-b border-gray-100 dark:border-gray-700">
            <h2 class="text-sm font-semibold text-gray-900 dark:text-white">Assigned Department/Unit</h2>
        </div>
        <div class="p-5">
            <?php if ($employee): ?>
                <div class="space-y-4">
                    <div>
                        <label class="block text-xs font-medium text-gray-500 dark:text-gray-400 mb-1">Department</label>
                        <p class="text-sm font-semibold text-gray-900 dark:text-white">
                            <?php echo htmlspecialchars($employee['department_name'] ?? 'Not assigned'); ?>
                        </p>
                        <?php if (isset($employee['department_code'])): ?>
                            <p class="text-xs text-gray-500 mt-1">Code: <?php echo htmlspecialchars($employee['department_code']); ?></p>
                        <?php endif; ?>
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-gray-500 dark:text-gray-400 mb-1">Position</label>
                        <p class="text-sm text-gray-900 dark:text-white">
                            <?php echo htmlspecialchars($employee['position'] ?? 'Not assigned'); ?>
                        </p>
                    </div>
                </div>
            <?php else: ?>
                <p class="text-sm text-gray-500">Department information not found</p>
            <?php endif; ?>
        </div>
    </div>

<?php elseif ($view === 'devices'): ?>
    <!-- Devices (trusted sessions) -->
    <div class="bg-white dark:bg-gray-800 rounded-xl shadow-sm border border-gray-100 dark:border-gray-700">
        <div class="px-5 py-4 border-b border-gray-100 dark:border-gray-700">
            <h2 class="text-sm font-semibold text-gray-900 dark:text-white">Devices (Trusted Sessions)</h2>
        </div>
        <div class="p-5">
            <?php if (empty($devices)): ?>
                <p class="text-sm text-gray-500">No trusted devices found</p>
                <p class="text-xs text-gray-400 mt-2">Your trusted devices and active sessions will appear here</p>
            <?php else: ?>
                <div class="space-y-3">
                    <?php foreach ($devices as $device): ?>
                        <div class="border border-gray-200 dark:border-gray-700 rounded-lg p-3">
                            <div class="flex justify-between items-center">
                                <div>
                                    <p class="text-sm font-medium text-gray-900 dark:text-white"><?php echo htmlspecialchars($device['name'] ?? 'Unknown Device'); ?></p>
                                    <p class="text-xs text-gray-500">Last seen: <?php echo date('M d, Y H:i', strtotime($device['last_seen'] ?? 'now')); ?></p>
                                </div>
                                <button class="text-xs text-red-600 hover:text-red-700">Revoke</button>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>
<?php endif; ?>

<?php include __DIR__ . '/../../includes/footer.php'; ?>

