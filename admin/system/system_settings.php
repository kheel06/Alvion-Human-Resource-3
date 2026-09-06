<?php
/**
 * System Settings
 * Admin can configure hospital settings and manage backups
 */
require_once '../../config/config.php';
requireAuth();
checkRole(['admin']);

$page_title = "System Settings";

$errors = [];
$success = false;

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action'])) {
        $action = $_POST['action'];
        
        if ($action === 'update_settings') {
            try {
                // Update system settings
                $settings = [
                    'hospital_name' => $_POST['hospital_name'] ?? '',
                    'hospital_address' => $_POST['hospital_address'] ?? '',
                    'hospital_phone' => $_POST['hospital_phone'] ?? '',
                    'hospital_email' => $_POST['hospital_email'] ?? '',
                    'timezone' => $_POST['timezone'] ?? 'Asia/Manila',
                    'date_format' => $_POST['date_format'] ?? 'Y-m-d',
                    'time_format' => $_POST['time_format'] ?? 'H:i',
                    'currency' => $_POST['currency'] ?? 'PHP',
                    'session_timeout' => $_POST['session_timeout'] ?? '30',
                    'max_login_attempts' => $_POST['max_login_attempts'] ?? '5',
                    'backup_frequency' => $_POST['backup_frequency'] ?? 'daily',
                    'auto_backup_enabled' => isset($_POST['auto_backup_enabled']) ? '1' : '0',
                ];
                
                foreach ($settings as $key => $value) {
                    $stmt = $db->prepare("INSERT INTO system_settings (setting_key, setting_value, updated_at) 
                                          VALUES (:key, :value, NOW())
                                          ON DUPLICATE KEY UPDATE setting_value = :value, updated_at = NOW()");
                    $stmt->bindParam(':key', $key);
                    $stmt->bindParam(':value', $value);
                    $stmt->execute();
                }
                
                $_SESSION['success'] = "System settings updated successfully.";
                header("Location: system_settings.php");
                exit();
            } catch (PDOException $e) {
                $errors[] = "Error updating settings: " . $e->getMessage();
            }
        } elseif ($action === 'create_backup') {
            try {
                // Create database backup
                $backup_file = 'backup_' . date('Y-m-d_His') . '.sql';
                $backup_path = '../../backups/' . $backup_file;
                
                // Ensure backups directory exists
                if (!is_dir('../../backups')) {
                    mkdir('../../backups', 0755, true);
                }
                
                // Get database connection details from config
                $db_host = DB_HOST;
                $db_name = DB_NAME;
                $db_user = DB_USER;
                $db_pass = DB_PASS;
                
                // Create backup using mysqldump (if available)
                $command = "mysqldump -h $db_host -u $db_user -p$db_pass $db_name > $backup_path 2>&1";
                exec($command, $output, $return_var);
                
                if ($return_var === 0 && file_exists($backup_path)) {
                    // Log backup creation
                    $stmt = $db->prepare("INSERT INTO backup_logs (backup_file, backup_path, created_by, created_at) 
                                          VALUES (:file, :path, :user_id, NOW())");
                    $stmt->bindParam(':file', $backup_file);
                    $stmt->bindParam(':path', $backup_path);
                    $stmt->bindParam(':user_id', $_SESSION['user_id']);
                    $stmt->execute();
                    
                    $_SESSION['success'] = "Backup created successfully: " . $backup_file;
                } else {
                    $errors[] = "Failed to create backup. Please check server configuration.";
                }
            } catch (PDOException $e) {
                $errors[] = "Error creating backup: " . $e->getMessage();
            }
        } elseif ($action === 'restore_backup' && isset($_POST['backup_file'])) {
            $backup_file = $_POST['backup_file'];
            $backup_path = '../../backups/' . $backup_file;
            
            if (file_exists($backup_path)) {
                try {
                    // Restore database from backup
                    $db_host = DB_HOST;
                    $db_name = DB_NAME;
                    $db_user = DB_USER;
                    $db_pass = DB_PASS;
                    
                    $command = "mysql -h $db_host -u $db_user -p$db_pass $db_name < $backup_path 2>&1";
                    exec($command, $output, $return_var);
                    
                    if ($return_var === 0) {
                        $_SESSION['success'] = "Backup restored successfully: " . $backup_file;
                    } else {
                        $errors[] = "Failed to restore backup.";
                    }
                } catch (PDOException $e) {
                    $errors[] = "Error restoring backup: " . $e->getMessage();
                }
            } else {
                $errors[] = "Backup file not found.";
            }
        }
    }
}

// Get current settings
$current_settings = [];
try {
    $settings_query = "SELECT setting_key, setting_value FROM system_settings";
    $settings_stmt = $db->prepare($settings_query);
    $settings_stmt->execute();
    $settings_data = $settings_stmt->fetchAll();
    
    foreach ($settings_data as $setting) {
        $current_settings[$setting['setting_key']] = $setting['setting_value'];
    }
} catch (PDOException $e) {
    // Settings table might not exist, use defaults
}

// Get backup logs
$backups = [];
try {
    $backup_query = "SELECT bl.*, u.first_name, u.last_name 
                     FROM backup_logs bl 
                     LEFT JOIN users u ON bl.created_by = u.id 
                     ORDER BY bl.created_at DESC LIMIT 20";
    $backup_stmt = $db->prepare($backup_query);
    $backup_stmt->execute();
    $backups = $backup_stmt->fetchAll();
} catch (PDOException $e) {
    // Backup logs table might not exist
}

// Get available backup files
$backup_files = [];
$backup_dir = '../../backups/';
if (is_dir($backup_dir)) {
    $files = scandir($backup_dir);
    foreach ($files as $file) {
        if (pathinfo($file, PATHINFO_EXTENSION) === 'sql') {
            $backup_files[] = $file;
        }
    }
    rsort($backup_files); // Most recent first
}

include '../../includes/header.php';
?>

<div class="mb-6">
    <h1 class="text-2xl font-bold text-gray-900 dark:text-white">System Settings</h1>
    <p class="text-gray-600 dark:text-gray-400">Configure hospital settings and manage backups</p>
</div>

<?php if (!empty($errors)): ?>
    <div class="mb-4 bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-800 rounded-md p-4">
        <div class="flex">
            <div class="flex-shrink-0">
                <svg class="h-5 w-5 text-red-400" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor">
                    <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zM8.707 7.293a1 1 0 00-1.414 1.414L8.586 10l-1.293 1.293a1 1 0 101.414 1.414L10 11.414l1.293 1.293a1 1 0 001.414-1.414L11.414 10l1.293-1.293a1 1 0 00-1.414-1.414L10 8.586 8.707 7.293z" clip-rule="evenodd" />
                </svg>
            </div>
            <div class="ml-3">
                <h3 class="text-sm font-medium text-red-800 dark:text-red-200">Errors occurred:</h3>
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

<div class="grid grid-cols-1 gap-6 lg:grid-cols-2">
    <!-- Hospital Settings -->
    <div class="bg-white dark:bg-gray-800 shadow rounded-lg">
        <div class="px-4 py-5 sm:p-6 border-b border-gray-200 dark:border-gray-700">
            <h3 class="text-lg font-medium text-gray-900 dark:text-white">Hospital Settings</h3>
        </div>
        <div class="px-4 py-5 sm:p-6">
            <form method="POST" class="space-y-4">
                <input type="hidden" name="action" value="update_settings">
                
                <div>
                    <label for="hospital_name" class="block text-sm font-medium text-gray-700 dark:text-gray-300">Hospital Name</label>
                    <input type="text" name="hospital_name" id="hospital_name"
                        value="<?php echo htmlspecialchars($current_settings['hospital_name'] ?? 'Alvion Hospital'); ?>"
                        class="mt-1 block w-full border border-gray-300 dark:border-gray-600 rounded-md shadow-sm py-2 px-3 dark:bg-gray-700 dark:text-white">
                </div>
                
                <div>
                    <label for="hospital_address" class="block text-sm font-medium text-gray-700 dark:text-gray-300">Hospital Address</label>
                    <textarea name="hospital_address" id="hospital_address" rows="3"
                        class="mt-1 block w-full border border-gray-300 dark:border-gray-600 rounded-md shadow-sm py-2 px-3 dark:bg-gray-700 dark:text-white"><?php echo htmlspecialchars($current_settings['hospital_address'] ?? ''); ?></textarea>
                </div>
                
                <div>
                    <label for="hospital_phone" class="block text-sm font-medium text-gray-700 dark:text-gray-300">Phone Number</label>
                    <input type="text" name="hospital_phone" id="hospital_phone"
                        value="<?php echo htmlspecialchars($current_settings['hospital_phone'] ?? ''); ?>"
                        class="mt-1 block w-full border border-gray-300 dark:border-gray-600 rounded-md shadow-sm py-2 px-3 dark:bg-gray-700 dark:text-white">
                </div>
                
                <div>
                    <label for="hospital_email" class="block text-sm font-medium text-gray-700 dark:text-gray-300">Email</label>
                    <input type="email" name="hospital_email" id="hospital_email"
                        value="<?php echo htmlspecialchars($current_settings['hospital_email'] ?? ''); ?>"
                        class="mt-1 block w-full border border-gray-300 dark:border-gray-600 rounded-md shadow-sm py-2 px-3 dark:bg-gray-700 dark:text-white">
                </div>
                
                <div>
                    <label for="timezone" class="block text-sm font-medium text-gray-700 dark:text-gray-300">Timezone</label>
                    <select name="timezone" id="timezone" class="mt-1 block w-full border border-gray-300 dark:border-gray-600 rounded-md shadow-sm py-2 px-3 dark:bg-gray-700 dark:text-white">
                        <option value="Asia/Manila" <?php echo ($current_settings['timezone'] ?? 'Asia/Manila') === 'Asia/Manila' ? 'selected' : ''; ?>>Asia/Manila (PHT)</option>
                        <option value="UTC" <?php echo ($current_settings['timezone'] ?? '') === 'UTC' ? 'selected' : ''; ?>>UTC</option>
                    </select>
                </div>
                
                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <label for="date_format" class="block text-sm font-medium text-gray-700 dark:text-gray-300">Date Format</label>
                        <select name="date_format" id="date_format" class="mt-1 block w-full border border-gray-300 dark:border-gray-600 rounded-md shadow-sm py-2 px-3 dark:bg-gray-700 dark:text-white">
                            <option value="Y-m-d" <?php echo ($current_settings['date_format'] ?? 'Y-m-d') === 'Y-m-d' ? 'selected' : ''; ?>>YYYY-MM-DD</option>
                            <option value="m/d/Y" <?php echo ($current_settings['date_format'] ?? '') === 'm/d/Y' ? 'selected' : ''; ?>>MM/DD/YYYY</option>
                            <option value="d/m/Y" <?php echo ($current_settings['date_format'] ?? '') === 'd/m/Y' ? 'selected' : ''; ?>>DD/MM/YYYY</option>
                        </select>
                    </div>
                    <div>
                        <label for="time_format" class="block text-sm font-medium text-gray-700 dark:text-gray-300">Time Format</label>
                        <select name="time_format" id="time_format" class="mt-1 block w-full border border-gray-300 dark:border-gray-600 rounded-md shadow-sm py-2 px-3 dark:bg-gray-700 dark:text-white">
                            <option value="H:i" <?php echo ($current_settings['time_format'] ?? 'H:i') === 'H:i' ? 'selected' : ''; ?>>24-hour</option>
                            <option value="h:i A" <?php echo ($current_settings['time_format'] ?? '') === 'h:i A' ? 'selected' : ''; ?>>12-hour</option>
                        </select>
                    </div>
                </div>
                
                <div>
                    <label for="currency" class="block text-sm font-medium text-gray-700 dark:text-gray-300">Currency</label>
                    <select name="currency" id="currency" class="mt-1 block w-full border border-gray-300 dark:border-gray-600 rounded-md shadow-sm py-2 px-3 dark:bg-gray-700 dark:text-white">
                        <option value="PHP" <?php echo ($current_settings['currency'] ?? 'PHP') === 'PHP' ? 'selected' : ''; ?>>PHP (₱)</option>
                        <option value="USD" <?php echo ($current_settings['currency'] ?? '') === 'USD' ? 'selected' : ''; ?>>USD ($)</option>
                    </select>
                </div>
                
                <div class="pt-4 border-t border-gray-200 dark:border-gray-700">
                    <button type="submit" class="w-full px-4 py-2 border border-transparent rounded-md shadow-sm text-sm font-medium text-white bg-primary-600 hover:bg-primary-700">
                        Save Settings
                    </button>
                </div>
            </form>
        </div>
    </div>
    
    <!-- Security & Backup Settings -->
    <div class="bg-white dark:bg-gray-800 shadow rounded-lg">
        <div class="px-4 py-5 sm:p-6 border-b border-gray-200 dark:border-gray-700">
            <h3 class="text-lg font-medium text-gray-900 dark:text-white">Security & Backup</h3>
        </div>
        <div class="px-4 py-5 sm:p-6 space-y-6">
            <!-- Security Settings -->
            <form method="POST" class="space-y-4">
                <input type="hidden" name="action" value="update_settings">
                
                <div>
                    <label for="session_timeout" class="block text-sm font-medium text-gray-700 dark:text-gray-300">Session Timeout (minutes)</label>
                    <input type="number" name="session_timeout" id="session_timeout" min="5" max="480"
                        value="<?php echo htmlspecialchars($current_settings['session_timeout'] ?? '30'); ?>"
                        class="mt-1 block w-full border border-gray-300 dark:border-gray-600 rounded-md shadow-sm py-2 px-3 dark:bg-gray-700 dark:text-white">
                </div>
                
                <div>
                    <label for="max_login_attempts" class="block text-sm font-medium text-gray-700 dark:text-gray-300">Max Login Attempts</label>
                    <input type="number" name="max_login_attempts" id="max_login_attempts" min="3" max="10"
                        value="<?php echo htmlspecialchars($current_settings['max_login_attempts'] ?? '5'); ?>"
                        class="mt-1 block w-full border border-gray-300 dark:border-gray-600 rounded-md shadow-sm py-2 px-3 dark:bg-gray-700 dark:text-white">
                </div>
                
                <div>
                    <label for="backup_frequency" class="block text-sm font-medium text-gray-700 dark:text-gray-300">Backup Frequency</label>
                    <select name="backup_frequency" id="backup_frequency" class="mt-1 block w-full border border-gray-300 dark:border-gray-600 rounded-md shadow-sm py-2 px-3 dark:bg-gray-700 dark:text-white">
                        <option value="daily" <?php echo ($current_settings['backup_frequency'] ?? 'daily') === 'daily' ? 'selected' : ''; ?>>Daily</option>
                        <option value="weekly" <?php echo ($current_settings['backup_frequency'] ?? '') === 'weekly' ? 'selected' : ''; ?>>Weekly</option>
                        <option value="monthly" <?php echo ($current_settings['backup_frequency'] ?? '') === 'monthly' ? 'selected' : ''; ?>>Monthly</option>
                    </select>
                </div>
                
                <div class="flex items-center">
                    <input type="checkbox" name="auto_backup_enabled" id="auto_backup_enabled" value="1"
                        <?php echo ($current_settings['auto_backup_enabled'] ?? '0') === '1' ? 'checked' : ''; ?>
                        class="rounded border-gray-300 text-primary-600 focus:ring-primary-500 dark:border-gray-600 dark:bg-gray-700">
                    <label for="auto_backup_enabled" class="ml-2 block text-sm text-gray-700 dark:text-gray-300">
                        Enable Automatic Backups
                    </label>
                </div>
                
                <div class="pt-4 border-t border-gray-200 dark:border-gray-700">
                    <button type="submit" class="w-full px-4 py-2 border border-transparent rounded-md shadow-sm text-sm font-medium text-white bg-primary-600 hover:bg-primary-700">
                        Save Security Settings
                    </button>
                </div>
            </form>
            
            <!-- Manual Backup -->
            <div class="pt-4 border-t border-gray-200 dark:border-gray-700">
                <h4 class="text-sm font-medium text-gray-700 dark:text-gray-300 mb-3">Manual Backup</h4>
                <form method="POST" class="backup-form" onsubmit="event.preventDefault(); showConfirmAlert('Create Backup', 'This will create a database backup. Continue?').then(confirmed => { if(confirmed) this.submit(); }); return false;">
                    <input type="hidden" name="action" value="create_backup">
                    <button type="submit" class="w-full px-4 py-2 border border-transparent rounded-md shadow-sm text-sm font-medium text-white bg-green-600 hover:bg-green-700">
                        Create Backup Now
                    </button>
                </form>
            </div>
            
            <!-- Restore Backup -->
            <?php if (!empty($backup_files)): ?>
                <div class="pt-4 border-t border-gray-200 dark:border-gray-700">
                    <h4 class="text-sm font-medium text-gray-700 dark:text-gray-300 mb-3">Restore Backup</h4>
                    <form method="POST" class="restore-form" onsubmit="event.preventDefault(); showConfirmAlert('Restore Database', 'WARNING: This will overwrite current database. Are you sure?', {okText: 'Restore', cancelText: 'Cancel'}).then(confirmed => { if(confirmed) this.submit(); }); return false;">
                        <input type="hidden" name="action" value="restore_backup">
                        <select name="backup_file" class="mb-3 block w-full border border-gray-300 dark:border-gray-600 rounded-md shadow-sm py-2 px-3 dark:bg-gray-700 dark:text-white">
                            <?php foreach ($backup_files as $file): ?>
                                <option value="<?php echo htmlspecialchars($file); ?>"><?php echo htmlspecialchars($file); ?></option>
                            <?php endforeach; ?>
                        </select>
                        <button type="submit" class="w-full px-4 py-2 border border-transparent rounded-md shadow-sm text-sm font-medium text-white bg-red-600 hover:bg-red-700">
                            Restore Backup
                        </button>
                    </form>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Backup History -->
<?php if (!empty($backups)): ?>
    <div class="mt-6 bg-white dark:bg-gray-800 shadow rounded-lg">
        <div class="px-4 py-5 sm:p-6 border-b border-gray-200 dark:border-gray-700">
            <h3 class="text-lg font-medium text-gray-900 dark:text-white">Backup History</h3>
        </div>
        <div class="px-4 py-5 sm:p-6">
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                    <thead class="bg-gray-50 dark:bg-gray-700">
                        <tr>
                            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase">Backup File</th>
                            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase">Created By</th>
                            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase">Date</th>
                        </tr>
                    </thead>
                    <tbody class="bg-white dark:bg-gray-800 divide-y divide-gray-200 dark:divide-gray-700">
                        <?php foreach ($backups as $backup): ?>
                            <tr>
                                <td class="px-4 py-4 whitespace-nowrap text-sm text-gray-700 dark:text-gray-300">
                                    <?php echo htmlspecialchars($backup['backup_file']); ?>
                                </td>
                                <td class="px-4 py-4 whitespace-nowrap text-sm text-gray-700 dark:text-gray-300">
                                    <?php echo htmlspecialchars(($backup['first_name'] ?? '') . ' ' . ($backup['last_name'] ?? '')); ?>
                                </td>
                                <td class="px-4 py-4 whitespace-nowrap text-sm text-gray-700 dark:text-gray-300">
                                    <?php echo date('M d, Y H:i', strtotime($backup['created_at'])); ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
<?php endif; ?>

<?php include '../../includes/footer.php'; ?>

