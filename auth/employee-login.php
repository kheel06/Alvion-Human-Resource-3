<?php
require_once '../config/config.php';
require_once '../includes/email_helper.php';
require_once '../includes/login_attempt_helper.php';

if (!function_exists('maskEmail')) {
    function maskEmail($email) {
        if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return $email;
        }

        [$localPart, $domainPart] = explode('@', $email, 2);

        $maskSegment = function ($segment) {
            $length = strlen($segment);
            if ($length <= 2) {
                return substr($segment, 0, 1) . str_repeat('*', max(0, $length - 1));
            }
            return substr($segment, 0, 1) . str_repeat('*', $length - 2) . substr($segment, -1);
        };

        $domainParts = explode('.', $domainPart);
        $domainName = array_shift($domainParts);
        $maskedDomainName = $maskSegment($domainName);
        $maskedDomain = $maskedDomainName . (!empty($domainParts) ? '.' . implode('.', $domainParts) : '');

        return $maskSegment($localPart) . '@' . $maskedDomain;
    }
}

function initializeLoginController(PDO $db, array $config): array {
    $defaults = [
        'context' => 'standard',
        'redirect' => 'login.php',
        'identifier_field' => 'username',
        'empty_identifier_message' => 'Please enter your credentials.',
        'invalid_credentials_message' => 'Invalid credentials.',
        'inactive_error_message' => 'Your account is inactive. Please contact an administrator.',
        'role_mapping' => [
            // Primary HR/Operations roles
            'super admin' => 1,
            'admin' => 2,
            'staff' => 3,
            'employee' => 4,
            // Legacy mappings kept for backward compatibility
            'doctor' => 5,
            'nurse' => 6,
            'receptionist' => 7,
            'appointment_coordinator' => 8,
            'billing_staff' => 9,
            'patient' => 10
        ],
        'default_role' => 'employee',
        'default_role_id' => 4,
        'fetch_user' => null,
        'source_table' => 'users',
        'source_identifier_field' => 'id',
    ];

    $config = array_merge($defaults, $config);

    if (!is_callable($config['fetch_user'])) {
        throw new InvalidArgumentException('fetch_user callback is required.');
    }

    // Only redirect already-logged-in users on GET (not POST) to avoid interfering with form submission
    if (isset($_SESSION['user_id']) && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') {
        if (isset($_GET['ref']) && $_GET['ref'] === 'forbidden') {
            // Role mismatch – destroy stale session to break any redirect loop
            $errorMsg = $_SESSION['error'] ?? 'You don\'t have permission to access that page. Please sign in with an authorized account.';
            session_unset();
            session_destroy();
            session_start();
            $_SESSION['error'] = $errorMsg;
        } else {
            // Validate that session has the required role info before redirecting
            $role_name = $_SESSION['role_name'] ?? $_SESSION['user_role'] ?? null;
            if (empty($role_name)) {
                // Stale/corrupt session – clear it and show login form
                session_unset();
                session_destroy();
                session_start();
                $_SESSION['error'] = 'Your session has expired. Please sign in again.';
            } else {
                $normalized_role = function_exists('normalizeRoleName') ? normalizeRoleName($role_name) : strtolower(trim($role_name));
                $dashboard_map = [
                    'super admin' => 'super_admin/super_admin-dashboard.php',
                    'admin'       => 'admin/admin-dashboard.php',
                    'staff'       => 'staff/staff-dashboard.php',
                    'employee'    => 'employee/employee-dashboard.php',
                    'supervisor'  => 'admin/admin-dashboard.php',
                    'unit head'   => 'admin/admin-dashboard.php',
                    'hr admin'    => 'admin/admin-dashboard.php',
                    'finance'     => 'admin/admin-dashboard.php',
                ];
                $dashboard_path = $dashboard_map[$normalized_role] ?? $dashboard_map[str_replace(' ', '_', $normalized_role)] ?? null;
                if ($dashboard_path !== null && file_exists(__DIR__ . '/../' . $dashboard_path)) {
                    header('Location: ' . rtrim(BASE_URL, '/') . '/' . $dashboard_path . '?_t=' . time(), true, 303);
                    exit();
                }
                // No matching dashboard found – clear session to prevent loop
                session_unset();
                session_destroy();
                session_start();
                $_SESSION['error'] = 'Unable to determine your dashboard. Please sign in again.';
            }
        }
    }

    if (isset($_POST['resend_otp'])) {
        handleResendOtpRequest($config['redirect']);
    }

    if (isset($_POST['verify_code'])) {
        handleOtpVerificationSubmission($db, $config['redirect']);
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && !isset($_POST['verify_code']) && !isset($_POST['resend_otp'])) {
        processPrimaryLoginAttempt($db, $config);
    }

    $resendCountdownShouldStart = isset($_SESSION['resend_cooldown_active']);
    $resendCountdownStartTime = $_SESSION['resend_cooldown_start'] ?? null;
    if ($resendCountdownShouldStart) {
        unset($_SESSION['resend_cooldown_active']);
    }

    return [
        'resendCountdownShouldStart' => $resendCountdownShouldStart,
        'resendCountdownStartTime' => $resendCountdownStartTime,
    ];
}

function processPrimaryLoginAttempt(PDO $db, array $config): void {
    // Verify reCAPTCHA
    $recaptcha_response = $_POST['g-recaptcha-response'] ?? '';
    if (function_exists('verifyRecaptcha') && !verifyRecaptcha($recaptcha_response)) {
        $_SESSION['error'] = 'Please complete the reCAPTCHA verification.';
        return;
    }
    
    $identifier = sanitizeInput($_POST[$config['identifier_field']] ?? '');
    $password = isset($_POST['password']) ? trim((string)$_POST['password']) : '';

    if (empty($identifier) || empty($password)) {
        $_SESSION['error'] = $config['empty_identifier_message'];
        return;
    }

    // Check if account is locked out
    $lockout_status = checkLoginLockout($db, $identifier);
    if ($lockout_status['locked']) {
        $remaining = $lockout_status['remaining_seconds'];
        $_SESSION['error'] = "Too many failed login attempts. Please try again in {$remaining} seconds.";
        $_SESSION['lockout_remaining'] = $remaining;
        return;
    }

    $user = call_user_func($config['fetch_user'], $db, $identifier);
    if (!$user) {
        // Record failed attempt
        $attempt_result = recordFailedLoginAttempt($db, $identifier, 3, 30);
        
        if ($attempt_result['locked_out']) {
            $_SESSION['error'] = "Too many failed login attempts. Your account has been locked for 30 seconds.";
            $_SESSION['lockout_remaining'] = 30;
        } else {
            $remaining = $attempt_result['attempts_remaining'];
            $_SESSION['error'] = $config['invalid_credentials_message'] . " ({$remaining} attempt" . ($remaining != 1 ? 's' : '') . " remaining)";
        }
        return;
    }

    $userStatus = $user['status'] ?? null;
    if ($userStatus !== null && $userStatus !== 'active') {
        $_SESSION['error'] = $config['inactive_error_message'];
        return;
    }

    if (!verifyUserPassword($password, $user['password'] ?? null)) {
        // Record failed attempt
        $attempt_result = recordFailedLoginAttempt($db, $identifier, 3, 30);
        
        if ($attempt_result['locked_out']) {
            $_SESSION['error'] = "Too many failed login attempts. Your account has been locked for 30 seconds.";
            $_SESSION['lockout_remaining'] = 30;
        } else {
            $remaining = $attempt_result['attempts_remaining'];
            $_SESSION['error'] = $config['invalid_credentials_message'] . " ({$remaining} attempt" . ($remaining != 1 ? 's' : '') . " remaining)";
        }
        return;
    }

    // Clear login attempts on successful password verification
    clearLoginAttempts($db, $identifier);

    $roleMeta = resolveUserRoleMetadata($db, $user, $config);
    $sourceIdentifierField = $config['source_identifier_field'] ?? 'id';
    $sourceIdentifierValue = $user[$sourceIdentifierField] ?? ($user['id'] ?? null);

    $_SESSION['pending_user'] = [
        'id' => $user['id'] ?? $sourceIdentifierValue,
        'username' => $user['username'],
        'first_name' => $user['first_name'],
        'last_name' => $user['last_name'],
        'email' => $user['email'] ?? '',
        'role_id' => $roleMeta['role_id'],
        'role_name' => $roleMeta['role_name'],
        'employee_id' => $user['employee_id'] ?? null,
        'login_context' => $config['context'] ?? 'standard',
        'source_table' => $config['source_table'] ?? 'users',
        'source_identifier_field' => $sourceIdentifierField,
        'source_identifier_value' => $sourceIdentifierValue,
    ];

    triggerOtpForPendingUser($user);
}

function resolveUserRoleMetadata(PDO $db, array $user, array $config): array {
    $role_id = $user['role_id'] ?? null;
    $role_name = $user['role'] ?? $config['default_role'];
    
    // Only look up role_name from roles table if the user doesn't already have one
    // The department_accounts.role_name takes priority over the roles table
    if (!empty($user['role_id'])) {
        $role_id = $user['role_id'];
        // Only use roles table name if user has no role_name of their own
        if (empty($user['role']) && empty($user['role_name'])) {
            try {
                $role_query = "SELECT role_name FROM roles WHERE id = :role_id";
                $role_stmt = $db->prepare($role_query);
                $role_stmt->bindParam(':role_id', $user['role_id']);
                $role_stmt->execute();
                $role_data = $role_stmt->fetch();
                if ($role_data) {
                    $role_name = $role_data['role_name'];
                }
            } catch (PDOException $e) {
                error_log("Roles lookup failed: " . $e->getMessage());
            }
        }
    }
    
    $normalized_role_name = function_exists('normalizeRoleName')
        ? normalizeRoleName($role_name)
        : strtolower((string)$role_name);
    
    if (!$role_id && !empty($normalized_role_name)) {
        try {
            $lookup_stmt = $db->prepare("SELECT id FROM roles WHERE role_name = :role_name LIMIT 1");
            $lookup_stmt->bindValue(':role_name', $normalized_role_name);
            $lookup_stmt->execute();
            $lookup_role = $lookup_stmt->fetch(PDO::FETCH_ASSOC);
            if ($lookup_role) {
                $role_id = (int)$lookup_role['id'];
            }
        } catch (PDOException $e) {
            error_log("Role lookup by name failed: " . $e->getMessage());
        }
    }
    
    if (!$role_id) {
        $role_id = $config['role_mapping'][$normalized_role_name] ?? $config['default_role_id'];
    }
    
    return [
        'role_id' => $role_id,
        'role_name' => $normalized_role_name ?: $config['default_role'],
    ];
}

if (!function_exists('saveOtpToDatabase')) {
    function saveOtpToDatabase(PDO $db, string $code, string $destination, string $sourceTable, int|string|null $userId = null, int|string|null $departmentAccountId = null, string $purpose = 'login'): ?int {
        try {
            $expiresAt = date('Y-m-d H:i:s', time() + (10 * 60)); // 10 minutes from now
            
            $stmt = $db->prepare("
                INSERT INTO otp_codes (code, user_id, department_account_id, source_table, destination, purpose, expires_at, attempts, created_at)
                VALUES (:code, :user_id, :department_account_id, :source_table, :destination, :purpose, :expires_at, 0, NOW())
            ");
            
            $stmt->bindValue(':code', $code, PDO::PARAM_STR);
            
            // Handle user_id - can be int or null
            if ($userId !== null) {
                $stmt->bindValue(':user_id', is_numeric($userId) ? (int)$userId : $userId, is_numeric($userId) ? PDO::PARAM_INT : PDO::PARAM_STR);
            } else {
                $stmt->bindValue(':user_id', null, PDO::PARAM_NULL);
            }
            
            // Handle department_account_id - can be int, string, or null
            if ($departmentAccountId !== null) {
                $stmt->bindValue(':department_account_id', is_numeric($departmentAccountId) ? (int)$departmentAccountId : $departmentAccountId, is_numeric($departmentAccountId) ? PDO::PARAM_INT : PDO::PARAM_STR);
            } else {
                $stmt->bindValue(':department_account_id', null, PDO::PARAM_NULL);
            }
            
            $stmt->bindValue(':source_table', $sourceTable, PDO::PARAM_STR);
            $stmt->bindValue(':destination', $destination, PDO::PARAM_STR);
            $stmt->bindValue(':purpose', $purpose, PDO::PARAM_STR);
            $stmt->bindValue(':expires_at', $expiresAt, PDO::PARAM_STR);
            
            if ($stmt->execute()) {
                return (int)$db->lastInsertId();
            }
        } catch (PDOException $e) {
            error_log("Failed to save OTP to database: " . $e->getMessage());
        }
        return null;
    }
}

if (!function_exists('markOtpAsConsumed')) {
    function markOtpAsConsumed(PDO $db, string $code, string $destination): bool {
        try {
            $stmt = $db->prepare("
                UPDATE otp_codes 
                SET consumed_at = NOW() 
                WHERE code = :code 
                AND destination = :destination 
                AND consumed_at IS NULL 
                AND expires_at > NOW()
                ORDER BY created_at DESC 
                LIMIT 1
            ");
            
            $stmt->bindValue(':code', $code, PDO::PARAM_STR);
            $stmt->bindValue(':destination', $destination, PDO::PARAM_STR);
            
            return $stmt->execute() && $stmt->rowCount() > 0;
        } catch (PDOException $e) {
            error_log("Failed to mark OTP as consumed: " . $e->getMessage());
            return false;
        }
    }
}

function triggerOtpForPendingUser(array $user): void {
    global $db;
    
    $verification_code = generateVerificationCode();
    $code_expiry = time() + (10 * 60);

    $_SESSION['verification_code'] = $verification_code;
    $_SESSION['verification_code_expiry'] = $code_expiry;
    $_SESSION['otp_last_sent'] = time();
    $_SESSION['show_verification_modal'] = true;

    $pending_user = $_SESSION['pending_user'] ?? [];
    $user_email = $pending_user['email'] ?? '';
    $user_name = trim(($user['first_name'] ?? '') . ' ' . ($user['last_name'] ?? '')) ?: ($user['username'] ?? 'User');
    $fallbackMessage = null;
    
    // Determine source table and IDs
    $sourceTable = $pending_user['source_table'] ?? 'department_accounts';
    $userId = null;
    $departmentAccountId = null;
    
    if ($sourceTable === 'users') {
        $userId = $pending_user['id'] ?? null;
    } else {
        $departmentAccountId = $pending_user['source_identifier_value'] ?? $pending_user['employee_id'] ?? null;
    }

    if (!empty($user_email)) {
        // Save OTP to database
        saveOtpToDatabase($db, $verification_code, $user_email, $sourceTable, $userId, $departmentAccountId, 'login');
        
        $email_result = sendVerificationCodeEmail($user_email, $user_name, $verification_code);
        if (!$email_result['success']) {
            error_log("Failed to send verification email: " . $email_result['message']);
            $_SESSION['error'] = "Failed to send verification code. Please contact support.";
            $_SESSION['show_verification_modal'] = true;
            $fallbackMessage = "Email delivery failed. Use this one-time code to continue.";
        } else {
            $_SESSION['verification_email'] = $user_email;
            unset($_SESSION['otp_fallback_code'], $_SESSION['otp_fallback_message']);
        }
    } else {
        $_SESSION['error'] = "No email address found for your account. Please contact support.";
        $_SESSION['show_verification_modal'] = true;
        $fallbackMessage = "No email on file. Use this one-time code to continue.";
    }

    if ($fallbackMessage !== null) {
        $_SESSION['otp_fallback_code'] = $verification_code;
        $_SESSION['otp_fallback_message'] = $fallbackMessage;
    }
}

function handleResendOtpRequest(string $redirectPath): void {
    global $db;
    
    if (!isset($_SESSION['pending_user'])) {
        $_SESSION['error'] = "Session expired. Please login again.";
        $_SESSION['show_verification_modal'] = true;
        header("Location: {$redirectPath}");
        exit();
    }

    unset($_SESSION['error']);

    $verification_code = generateVerificationCode();
    $code_expiry = time() + (10 * 60);
    $current_time = time();

    $_SESSION['verification_code'] = $verification_code;
    $_SESSION['verification_code_expiry'] = $code_expiry;
    $_SESSION['otp_last_sent'] = $current_time;
    $_SESSION['resend_cooldown_start'] = $current_time;
    $_SESSION['resend_cooldown_active'] = true;

    $pending_user = $_SESSION['pending_user'];
    $user_email = $pending_user['email'] ?? '';
    $user_name = trim(($pending_user['first_name'] ?? '') . ' ' . ($pending_user['last_name'] ?? '')) ?: ($pending_user['username'] ?? 'User');
    
    // Determine source table and IDs
    $sourceTable = $pending_user['source_table'] ?? 'department_accounts';
    $userId = null;
    $departmentAccountId = null;
    
    if ($sourceTable === 'users') {
        $userId = $pending_user['id'] ?? null;
    } else {
        $departmentAccountId = $pending_user['source_identifier_value'] ?? $pending_user['employee_id'] ?? null;
    }

    if (!empty($user_email)) {
        // Save OTP to database
        saveOtpToDatabase($db, $verification_code, $user_email, $sourceTable, $userId, $departmentAccountId, 'login');
        
        $email_result = sendVerificationCodeEmail($user_email, $user_name, $verification_code);
        if (!$email_result['success']) {
            error_log("Failed to resend verification email: " . $email_result['message']);
            $_SESSION['error'] = "Failed to resend verification code. Please try again.";
            $_SESSION['show_verification_modal'] = true;
        } else {
            $_SESSION['success_message'] = "A new verification code has been sent to your email.";
            $_SESSION['show_verification_modal'] = true;
            $_SESSION['verification_email'] = $user_email;
        }
    } else {
        $_SESSION['error'] = "No email address found for your account. Please contact support.";
        $_SESSION['show_verification_modal'] = true;
    }

    header("Location: {$redirectPath}");
    exit();
}

function handleOtpVerificationSubmission(PDO $db, string $redirectPath): void {
    $entered_code = '';
    if (isset($_POST['code1'], $_POST['code2'], $_POST['code3'], $_POST['code4'], $_POST['code5'], $_POST['code6'])) {
        $entered_code = sanitizeInput($_POST['code1'] . $_POST['code2'] . $_POST['code3'] . $_POST['code4'] . $_POST['code5'] . $_POST['code6']);
    } elseif (isset($_POST['verification_code'])) {
        $entered_code = sanitizeInput($_POST['verification_code']);
    }

    $entered_code = trim((string)$entered_code);
    $stored_code = isset($_SESSION['verification_code']) ? trim((string)$_SESSION['verification_code']) : null;
    $code_expiry = $_SESSION['verification_code_expiry'] ?? null;

    if (empty($entered_code)) {
        $_SESSION['error'] = "Please enter the verification code.";
        $_SESSION['show_verification_modal'] = true;
    } elseif (!$stored_code) {
        $_SESSION['error'] = "No verification code found. Please login again.";
        $_SESSION['show_verification_modal'] = true;
        clearPendingVerificationState();
    } elseif ($code_expiry === null || time() > $code_expiry) {
        $_SESSION['error'] = "Verification code has expired. Please login again.";
        $_SESSION['show_verification_modal'] = true;
        clearPendingVerificationState();
    } elseif ($entered_code !== $stored_code) {
        $_SESSION['error'] = "Wrong OTP. Please try again.";
        $_SESSION['show_verification_modal'] = true;
    } else {
        // Mark OTP as consumed in database
        $pending_user = $_SESSION['pending_user'] ?? [];
        $user_email = $pending_user['email'] ?? '';
        if (!empty($user_email)) {
            markOtpAsConsumed($db, $entered_code, $user_email);
        }
        
        finalizeUserLogin($db);
        return;
    }

    header("Location: {$redirectPath}");
    exit();
}

function clearPendingVerificationState(): void {
    unset($_SESSION['pending_user'], $_SESSION['verification_code'], $_SESSION['verification_code_expiry']);
}

function finalizeUserLogin(PDO $db): void {
    $pending_user = $_SESSION['pending_user'];
    $loginContext = $pending_user['login_context'] ?? 'standard';
    $_SESSION['login_context'] = $loginContext;

    $_SESSION['user_id'] = $pending_user['id'];
    $_SESSION['username'] = $pending_user['username'];
    $_SESSION['first_name'] = $pending_user['first_name'];
    $_SESSION['last_name'] = $pending_user['last_name'];
    $_SESSION['role_id'] = $pending_user['role_id'];
    $resolvedRoleName = $pending_user['role_name'] ?? 'employee';
    if (function_exists('normalizeRoleName')) {
        $resolvedRoleName = normalizeRoleName($resolvedRoleName);
    } else {
        $resolvedRoleName = strtolower((string)$resolvedRoleName);
    }
    $_SESSION['role_name'] = $resolvedRoleName;
    $_SESSION['user_role'] = $resolvedRoleName;
    if (function_exists('getRoleDisplayName')) {
        $_SESSION['role_display_name'] = getRoleDisplayName($resolvedRoleName);
    }

    if (isset($pending_user['employee_id'])) {
        $_SESSION['employee_id'] = $pending_user['employee_id'];
        // Resolve numeric employees.id for HR3 when employee_id is employee_number (e.g. EMP001)
        $srcTable = $pending_user['source_table'] ?? 'users';
        if ($srcTable === 'department_accounts' && !empty($pending_user['employee_id'])) {
            try {
                $empLookup = $db->prepare("SELECT id FROM employees WHERE employee_number = ? LIMIT 1");
                $empLookup->execute([$pending_user['employee_id']]);
                $empRow = $empLookup->fetch(PDO::FETCH_ASSOC);
                if ($empRow) {
                    $_SESSION['hr3_employee_id'] = (int) $empRow['id'];
                }
            } catch (PDOException $e) {
                // employees table may not exist
            }
        }
    }

    $sourceTable = $pending_user['source_table'] ?? 'users';
    $sourceField = $pending_user['source_identifier_field'] ?? 'id';
    $sourceValue = $pending_user['source_identifier_value'] ?? $pending_user['id'];

    updateSourceLastLogin($db, $sourceTable, $sourceField, $sourceValue);

    unset($_SESSION['pending_user'], $_SESSION['verification_code'], $_SESSION['verification_code_expiry'], $_SESSION['otp_last_sent']);

    $userFullName = trim(($pending_user['first_name'] ?? '') . ' ' . ($pending_user['last_name'] ?? '')) ?: ($pending_user['username'] ?? 'there');
    $_SESSION['success'] = "Welcome back! 🎉 " . $userFullName . "! You have successfully logged in.";
    session_regenerate_id(true);
    $role_name = $resolvedRoleName ?? 'employee';
    $normalized_role = function_exists('normalizeRoleName') ? normalizeRoleName($role_name) : strtolower(trim($role_name));
    $dashboard_map = [
        'super admin' => 'super_admin/super_admin-dashboard.php',
        'admin'       => 'admin/admin-dashboard.php',
        'staff'       => 'staff/staff-dashboard.php',
        'employee'    => 'employee/employee-dashboard.php',
        'supervisor'  => 'admin/admin-dashboard.php',
        'unit head'   => 'admin/admin-dashboard.php',
        'hr admin'    => 'admin/admin-dashboard.php',
        'finance'     => 'admin/admin-dashboard.php',
    ];
    $dashboard_path = $dashboard_map[$normalized_role] ?? $dashboard_map[str_replace(' ', '_', $normalized_role)] ?? null;
    if ($dashboard_path !== null && file_exists(__DIR__ . '/../' . $dashboard_path)) {
        header('Location: ' . rtrim(BASE_URL, '/') . '/' . $dashboard_path . '?_t=' . time(), true, 303);
    } else {
        header('Location: ' . rtrim(BASE_URL, '/') . '/employee/employee-dashboard.php?_t=' . time(), true, 303);
    }
    exit();
}

function verifyUserPassword(string $inputPassword, ?string $storedPassword): bool {
    if ($storedPassword === null || $storedPassword === '') {
        return false;
    }

    $storedPassword = trim((string)$storedPassword);
    $isPasswordHash = preg_match('/^\$(2y|2a|2b|argon2i|argon2id|argon2|P)\$/', $storedPassword) === 1;

    if ($isPasswordHash) {
        return password_verify($inputPassword, $storedPassword);
    }

    return hash_equals($storedPassword, $inputPassword);
}

function tableHasColumn(PDO $db, string $table, string $column): bool {
    static $tableColumnCache = [];
    $sanitizedTable = preg_replace('/[^A-Za-z0-9_]/', '', $table);
    $cacheKey = "{$sanitizedTable}.{$column}";

    if (array_key_exists($cacheKey, $tableColumnCache)) {
        return $tableColumnCache[$cacheKey];
    }

    try {
        $stmt = $db->prepare("SHOW COLUMNS FROM `{$sanitizedTable}` LIKE :column_name");
        $stmt->bindParam(':column_name', $column);
        $stmt->execute();
        $tableColumnCache[$cacheKey] = $stmt->rowCount() > 0;
    } catch (PDOException $e) {
        error_log("Failed to inspect {$sanitizedTable}.{$column}: " . $e->getMessage());
        $tableColumnCache[$cacheKey] = false;
    }

    return $tableColumnCache[$cacheKey];
}

function userTableHasColumn(PDO $db, string $column): bool {
    return tableHasColumn($db, 'users', $column);
}

function updateSourceLastLogin(PDO $db, string $table, string $field, $value): void {
    if ($value === null) {
        return;
    }

    $sanitizedTable = preg_replace('/[^A-Za-z0-9_]/', '', $table);
    $sanitizedField = preg_replace('/[^A-Za-z0-9_]/', '', $field);

    try {
        $query = "UPDATE `{$sanitizedTable}` SET last_login = NOW() WHERE `{$sanitizedField}` = :identifier";
        $stmt = $db->prepare($query);
        $stmt->bindParam(':identifier', $value);
        $stmt->execute();
    } catch (PDOException $e) {
        error_log("Failed to update {$sanitizedTable} last_login: " . $e->getMessage());
    }
}

$departmentTable = 'department_accounts';
$departmentTableSafe = preg_replace('/[^A-Za-z0-9_]/', '', $departmentTable);
$departmentIdentifierColumns = [];

if (tableHasColumn($db, $departmentTable, 'employee_id')) {
    $departmentIdentifierColumns[] = 'employee_id';
}
if (tableHasColumn($db, $departmentTable, 'employee_email')) {
    $departmentIdentifierColumns[] = 'employee_email';
}
if (tableHasColumn($db, $departmentTable, 'email')) {
    $departmentIdentifierColumns[] = 'email';
}
if (tableHasColumn($db, $departmentTable, 'username')) {
    $departmentIdentifierColumns[] = 'username';
}

if (empty($departmentIdentifierColumns)) {
    $departmentIdentifierColumns[] = 'id';
}

$departmentRoleMapping = [
    'admin' => 1,
    'doctor' => 2,
    'nurse' => 3,
    'staff' => 3,
    'employee' => 3,
    'receptionist' => 4,
    'appointment_coordinator' => 5,
    'billing_staff' => 6,
    'finance_staff' => 6,
    'finance staff' => 6,
    'patient' => 7,
];

$loginControllerState = initializeLoginController($db, [
    'context' => 'employee',
    'redirect' => 'employee-login.php',
    'identifier_field' => 'email',
    'empty_identifier_message' => "Please enter both email and password.",
    'invalid_credentials_message' => "Invalid email or password.",
    'role_mapping' => $departmentRoleMapping,
    'default_role' => 'employee',
    'default_role_id' => 3,
    'source_table' => $departmentTableSafe,
    'source_identifier_field' => 'employee_id',
    'fetch_user' => function(PDO $db, string $identifier) use ($departmentTableSafe, $departmentIdentifierColumns) {
        $emailColumns = array_filter($departmentIdentifierColumns, fn($col) => stripos($col, 'email') !== false);
        if (empty($emailColumns)) {
            $emailColumns = $departmentIdentifierColumns;
        }
        $conditions = array_map(fn($column) => "{$column} = :identifier", $emailColumns);
        $whereClause = implode(' OR ', $conditions);
        $query = "SELECT * FROM `{$departmentTableSafe}` WHERE {$whereClause} LIMIT 1";

        try {
            $stmt = $db->prepare($query);
            $stmt->bindParam(':identifier', $identifier);
            $stmt->execute();
            $record = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($record) {
                $record['id'] = $record['id'] ?? $record['employee_id'] ?? ($record['staff_id'] ?? null);
                $record['username'] = $record['username']
                    ?? $record['employee_email']
                    ?? $record['email']
                    ?? $record['employee_id']
                    ?? ($record['id'] ?? null);

                $record['email'] = $record['employee_email']
                    ?? $record['email']
                    ?? ($record['work_email'] ?? $record['company_email'] ?? null);

                $record['first_name'] = $record['first_name']
                    ?? $record['employee_fname']
                    ?? $record['fname']
                    ?? '';

                $record['last_name'] = $record['last_name']
                    ?? $record['employee_lname']
                    ?? $record['lname']
                    ?? '';

                if ((empty($record['first_name']) || empty($record['last_name'])) && !empty($record['full_name'])) {
                    $nameParts = preg_split('/\s+/', trim($record['full_name']), 2);
                    $record['first_name'] = $record['first_name'] ?: ($nameParts[0] ?? '');
                    if (count($nameParts) === 2) {
                        $record['last_name'] = $record['last_name'] ?: $nameParts[1];
                    }
                }

                $record['role'] = $record['role']
                    ?? $record['role_name']
                    ?? $record['account_type']
                    ?? 'employee';

                $record['role_name'] = $record['role_name'] ?? $record['role'];

                if (!isset($record['status'])) {
                    if (isset($record['is_active'])) {
                        $record['status'] = ((int)$record['is_active'] === 1) ? 'active' : 'inactive';
                    } elseif (isset($record['account_status'])) {
                        $record['status'] = $record['account_status'];
                    }
                }

                $record['employee_id'] = $record['employee_id'] ?? ($record['staff_id'] ?? $record['id'] ?? null);

                if (isset($record['password'])) {
                    $record['password'] = trim($record['password']);
                }
            }

            return $record;
        } catch (PDOException $e) {
            error_log("Department account lookup failed: " . $e->getMessage());
            return null;
        }
    },
]);

$resendCountdownShouldStart = $loginControllerState['resendCountdownShouldStart'];
$resendCountdownStartTime = $loginControllerState['resendCountdownStartTime'];
?>

<!DOCTYPE html>
<html lang="en" class="h-full">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sign In - <?php echo SITE_NAME; ?></title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    colors: {
                        brand: {
                            50:  '#EBF5FF',
                            100: '#E3F2FD',
                            200: '#BBDEFB',
                            300: '#90CAF9',
                            400: '#42A5F5',
                            500: '#1E88E5',
                            600: '#1565C0',
                            700: '#1E40AF',
                            800: '#1A237E',
                            900: '#0D1B3D',
                            950: '#0A1628',
                        }
                    },
                    fontFamily: {
                        sans: ['Inter', 'system-ui', '-apple-system', 'sans-serif'],
                    }
                }
            }
        }
    </script>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <script src="<?php echo BASE_URL; ?>/assets/js/toast.js"></script>
    <link rel="icon" type="image/png" href="<?php echo BASE_URL; ?>/assets/img/alvion-emblem-removebg.png">
    <?php if (defined('RECAPTCHA_ENABLED') && RECAPTCHA_ENABLED && defined('RECAPTCHA_SITE_KEY') && !empty(RECAPTCHA_SITE_KEY)): ?>
    <script src="https://www.google.com/recaptcha/api.js" async defer></script>
    <?php endif; ?>
    <style>
        body { font-family: 'Inter', system-ui, -apple-system, sans-serif; }
        .login-card {
            box-shadow:
                0 0 0 1px rgba(0,0,0,0.03),
                0 2px 4px rgba(0,0,0,0.03),
                0 12px 24px rgba(0,0,0,0.06),
                0 24px 48px rgba(0,0,0,0.04);
        }
        .login-input {
            transition: border-color 0.2s, box-shadow 0.2s, background-color 0.2s;
        }
        .login-input:focus {
            border-color: #1E88E5;
            box-shadow: 0 0 0 3px rgba(30,136,229,0.12);
        }
        .btn-primary {
            background: linear-gradient(135deg, #1565C0 0%, #1E88E5 100%);
            transition: all 0.2s ease;
        }
        .btn-primary:hover {
            background: linear-gradient(135deg, #1E40AF 0%, #1565C0 100%);
            box-shadow: 0 6px 20px rgba(30,136,229,0.35);
            transform: translateY(-1px);
        }
        .btn-primary:active {
            transform: translateY(0);
        }
        .btn-primary:disabled {
            opacity: 0.6;
            transform: none;
            box-shadow: none;
        }
        .bg-mesh {
            background-color: #F9FAFB;
            background-image:
                radial-gradient(at 20% 25%, rgba(30,136,229,0.05) 0, transparent 50%),
                radial-gradient(at 80% 80%, rgba(21,101,192,0.04) 0, transparent 50%),
                radial-gradient(at 50% 50%, rgba(66,165,245,0.03) 0, transparent 70%);
        }
        .auto-dismiss {
            transition: opacity 0.3s ease, transform 0.3s ease;
        }
        @keyframes float-in {
            from { opacity: 0; transform: translateY(16px); }
            to   { opacity: 1; transform: translateY(0); }
        }
        .animate-float-in {
            animation: float-in 0.5s ease-out both;
        }
        .animate-float-in-delay {
            animation: float-in 0.5s ease-out 0.1s both;
        }
    </style>
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const passwordField = document.getElementById('password');
            const togglePasswordBtn = document.getElementById('togglePassword');
            const passwordEyeIcon = document.getElementById('passwordEyeIcon');
            
            if (togglePasswordBtn && passwordField && passwordEyeIcon) {
                togglePasswordBtn.addEventListener('click', function() {
                    const type = passwordField.getAttribute('type') === 'password' ? 'text' : 'password';
                    passwordField.setAttribute('type', type);
                    
                    if (type === 'password') {
                        passwordEyeIcon.innerHTML = '<path d="M2 12s3-7 10-7 10 7 10 7-3 7-10 7-10-7-10-7Z"></path><circle cx="12" cy="12" r="3"></circle>';
                    } else {
                        passwordEyeIcon.innerHTML = '<path d="M9.88 9.88a3 3 0 1 0 4.24 4.24"></path><path d="M10.73 5.08A10.43 10.43 0 0 1 12 5c7 0 10 7 10 7a13.16 13.16 0 0 1-1.67 2.68"></path><path d="M6.61 6.61A13.526 13.526 0 0 0 2 12s3 7 10 7a9.74 9.74 0 0 0 5.39-1.61"></path><line x1="2" y1="2" x2="22" y2="22"></line>';
                    }
                });
            }
            
            <?php if (isset($_SESSION['show_verification_modal']) && $_SESSION['show_verification_modal']): ?>
                const modal = document.getElementById('verificationModal');
                if (modal) {
                    modal.classList.remove('hidden');
                    setTimeout(() => {
                        setupCodeInputs();
                    }, 100);
                }
            <?php 
                $error_message = $_SESSION['error'] ?? '';
                $is_otp_error = stripos($error_message, 'OTP') !== false || 
                                stripos($error_message, 'verification') !== false ||
                                stripos($error_message, 'code') !== false;
                
                if (!isset($_SESSION['error']) || !$is_otp_error) {
                    unset($_SESSION['show_verification_modal']);
                }
            endif; ?>
            
            let codeInputsSetup = false;
            function setupCodeInputs() {
                if (codeInputsSetup) return;
                codeInputsSetup = true;
                
                const codeInputs = document.querySelectorAll('.code-input');
                
                <?php if (isset($_SESSION['error']) && isset($_SESSION['show_verification_modal'])): ?>
                    codeInputs.forEach(input => { input.value = ''; });
                <?php endif; ?>
                
                codeInputs.forEach((input, index) => {
                    if (input.dataset.listenerAdded === 'true') return;
                    input.dataset.listenerAdded = 'true';
                    
                    input.addEventListener('input', function(e) {
                        e.target.value = e.target.value.replace(/[^0-9]/g, '').slice(0, 1);
                        const allInputs = document.querySelectorAll('.code-input');
                        if (e.target.value.length === 1 && index < allInputs.length - 1) {
                            allInputs[index + 1].focus();
                        }
                    });
                    
                    input.addEventListener('keydown', function(e) {
                        const allInputs = document.querySelectorAll('.code-input');
                        if (e.key === 'Backspace' && e.target.value === '' && index > 0) {
                            allInputs[index - 1].focus();
                        } else if (e.key === 'Enter') {
                            const fullCode = Array.from(allInputs).map(inp => inp.value).join('');
                            if (fullCode.length === allInputs.length) {
                                document.getElementById('fullCode').value = fullCode;
                                document.getElementById('verificationForm').submit();
                            }
                        }
                    });
                });
                
                if (codeInputs.length > 0) codeInputs[0].focus();
            }
            
            setupCodeInputs();

            <?php if (isset($_SESSION['error']) && !isset($_SESSION['show_verification_modal'])): ?>
                showToast('error', <?php echo json_encode($_SESSION['error']); ?>);
                <?php unset($_SESSION['error']); ?>
            <?php endif; ?>
            
            <?php if (isset($_SESSION['lockout_remaining'])): ?>
                let lockoutRemaining = <?php echo (int)$_SESSION['lockout_remaining']; ?>;
                <?php unset($_SESSION['lockout_remaining']); ?>
                
                const loginButton = document.getElementById('loginButton');
                const passwordInput = document.getElementById('password');
                const emailInput = document.getElementById('email');
                
                const lockoutAlert = document.getElementById('lockoutAlert');
                const lockoutCountdown = document.getElementById('lockoutCountdown');
                
                if (lockoutRemaining > 0) {
                    if (lockoutAlert) lockoutAlert.classList.remove('hidden');
                    if (lockoutCountdown) lockoutCountdown.textContent = lockoutRemaining;
                    
                    if (loginButton) loginButton.disabled = true;
                    if (passwordInput) passwordInput.disabled = true;
                    if (emailInput) emailInput.disabled = true;
                    
                    const countdownInterval = setInterval(() => {
                        lockoutRemaining--;
                        
                        if (lockoutRemaining > 0) {
                            if (lockoutCountdown) lockoutCountdown.textContent = lockoutRemaining;
                        } else {
                            clearInterval(countdownInterval);
                            if (lockoutAlert) lockoutAlert.classList.add('hidden');
                            if (loginButton) loginButton.disabled = false;
                            if (passwordInput) passwordInput.disabled = false;
                            if (emailInput) emailInput.disabled = false;
                            showToast('success', 'You can now try logging in again.');
                        }
                    }, 1000);
                }
            <?php endif; ?>
        });
    </script>
</head>
<body class="h-full bg-mesh">
    <div id="toast-container" class="fixed top-4 right-4 z-50 flex flex-col gap-3 pointer-events-none"></div>
    
    <div class="min-h-full flex flex-col items-center justify-center px-4 py-12 sm:px-6 lg:px-8">
        
        <div class="w-full max-w-md animate-float-in">
            <!-- Logo & Branding -->
            <div class="text-center mb-8">
                <img src="<?php echo BASE_URL; ?>/assets/img/alvion-logo-removebg.png" alt="<?php echo SITE_NAME; ?>" class="h-12 mx-auto mb-5">
                <h1 class="text-2xl font-bold text-gray-900 tracking-tight">Sign in</h1>
                <p class="mt-2 text-sm text-gray-500">Sign in to your department account to continue.</p>
            </div>

            <!-- Login Card -->
            <div class="bg-white rounded-2xl login-card px-8 py-9 animate-float-in-delay">
                
                <!-- Lockout Alert -->
                <div id="lockoutAlert" class="hidden mb-6 bg-red-50 border border-red-200 p-4 rounded-xl">
                    <div class="flex items-start gap-3">
                        <div class="flex-shrink-0 mt-0.5">
                            <svg class="h-5 w-5 text-red-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/>
                            </svg>
                        </div>
                        <div class="flex-1">
                            <p class="text-sm font-semibold text-red-800">Account temporarily locked</p>
                            <p class="mt-1 text-sm text-red-600">
                                Too many failed attempts. Try again in 
                                <span id="lockoutCountdown" class="font-bold">30</span>s
                            </p>
                        </div>
                    </div>
                </div>

                <form id="employeeLoginForm" class="space-y-5" method="POST">
                    
                    <!-- Email Field -->
                    <div>
                        <label for="email" class="block text-sm font-medium text-gray-700 mb-1.5">Email address</label>
                        <div class="relative">
                            <span class="pointer-events-none absolute inset-y-0 left-0 flex items-center pl-3.5">
                                <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" class="text-gray-400"><rect width="20" height="16" x="2" y="4" rx="2"></rect><path d="m22 7-8.97 5.7a1.94 1.94 0 0 1-2.06 0L2 7"></path></svg>
                            </span>
                            <input id="email" name="email" type="email" required autocomplete="email"
                                   class="login-input w-full pl-11 pr-4 py-3 rounded-xl border border-gray-200 bg-gray-50/50 text-gray-900 placeholder-gray-400 focus:outline-none focus:bg-white text-sm"
                                   placeholder="you@example.com" value="<?php echo isset($_POST['email']) ? htmlspecialchars($_POST['email']) : ''; ?>">
                        </div>
                    </div>

                    <!-- Password Field -->
                    <div>
                        <label for="password" class="block text-sm font-medium text-gray-700 mb-1.5">Password</label>
                        <div class="relative">
                            <span class="pointer-events-none absolute inset-y-0 left-0 flex items-center pl-3.5">
                                <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" class="text-gray-400"><rect width="18" height="11" x="3" y="11" rx="2" ry="2"></rect><path d="M7 11V7a5 5 0 0 1 10 0v4"></path></svg>
                            </span>
                            <input id="password" name="password" type="password" required autocomplete="current-password"
                                   class="login-input w-full pl-11 pr-11 py-3 rounded-xl border border-gray-200 bg-gray-50/50 text-gray-900 placeholder-gray-400 focus:outline-none focus:bg-white text-sm"
                                   placeholder="Enter your password">
                            <button type="button" id="togglePassword" class="absolute inset-y-0 right-0 flex items-center pr-3.5 text-gray-400 hover:text-brand-600 transition-colors">
                                <svg id="passwordEyeIcon" xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"><path d="M2 12s3-7 10-7 10 7 10 7-3 7-10 7-10-7-10-7Z"></path><circle cx="12" cy="12" r="3"></circle></svg>
                            </button>
                        </div>
                    </div>
                    
                    <?php if (defined('RECAPTCHA_ENABLED') && RECAPTCHA_ENABLED && defined('RECAPTCHA_SITE_KEY') && !empty(RECAPTCHA_SITE_KEY)): ?>
                    <div class="flex justify-center pt-1">
                        <div class="g-recaptcha" data-sitekey="<?php echo htmlspecialchars(RECAPTCHA_SITE_KEY); ?>" data-theme="light"></div>
                    </div>
                    <?php endif; ?>

                    <button type="submit" id="loginButton"
                            class="btn-primary w-full text-white font-semibold py-3 rounded-xl text-sm tracking-wide">
                        Sign in
                    </button>
                </form>
            </div>

            <!-- Footer -->
            <p class="mt-8 text-center text-xs text-gray-400">
                &copy; <?php echo date('Y'); ?> <?php echo SITE_NAME; ?>. All rights reserved.
            </p>
        </div>
    </div>
    
    <!-- Verification Modal -->
    <div id="verificationModal" class="hidden fixed inset-0 bg-gray-900/40 backdrop-blur-sm overflow-y-auto h-full w-full z-50 flex items-center justify-center">
        <div class="relative bg-white rounded-2xl shadow-2xl max-w-md w-full mx-4 p-8">
            <button onclick="document.getElementById('verificationModal').classList.add('hidden')" 
                    class="absolute top-4 right-4 text-gray-400 hover:text-gray-600 transition-colors p-1 rounded-lg hover:bg-gray-100">
                <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <line x1="18" y1="6" x2="6" y2="18"></line>
                    <line x1="6" y1="6" x2="18" y2="18"></line>
                </svg>
            </button>
            
            <div class="text-center">
                <div class="mx-auto flex items-center justify-center h-14 w-14 rounded-xl bg-brand-50 mb-5">
                    <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" class="text-brand-600">
                        <rect width="20" height="16" x="2" y="4" rx="2"></rect>
                        <path d="m22 7-8.97 5.7a1.94 1.94 0 0 1-2.06 0L2 7"></path>
                    </svg>
                </div>
                
                <h3 class="text-xl font-bold text-gray-900 mb-1">Check your email</h3>
                <p class="text-sm text-gray-500 mb-6">
                    We sent a 6-digit code to
                    <span class="font-medium text-brand-600">
                        <?php 
                        if (isset($_SESSION['verification_email']) && !empty($_SESSION['verification_email'])) {
                            echo htmlspecialchars(maskEmail($_SESSION['verification_email']));
                        } else {
                            echo 'your email';
                        }
                        ?>
                    </span>
                </p>
                
                <?php if (isset($_SESSION['error'])): ?>
                    <div class="mb-5 bg-red-50 border border-red-200 text-red-600 px-4 py-3 rounded-xl text-sm auto-dismiss" data-auto-dismiss="true">
                        <?php 
                        $error_msg = $_SESSION['error'];
                        echo htmlspecialchars($error_msg);
                        unset($_SESSION['error']);
                        ?>
                    </div>
                    <?php if (isset($_GET['ref']) && $_GET['ref'] === 'forbidden'): ?>
                    <p class="mb-4 text-sm text-gray-600">
                        <a href="/auth/logout.php" class="text-brand-600 hover:text-brand-700 font-medium">Sign out</a> to use a different account.
                    </p>
                    <?php endif; ?>
                <?php endif; ?>
                
                <?php if (isset($_SESSION['success_message'])): ?>
                    <div class="mb-5 bg-green-50 border border-green-200 text-green-600 px-4 py-3 rounded-xl text-sm auto-dismiss" data-auto-dismiss="true">
                        <?php echo $_SESSION['success_message']; unset($_SESSION['success_message']); ?>
                    </div>
                <?php endif; ?>

                <?php if (isset($_SESSION['otp_fallback_code'])): ?>
                    <div class="mb-5 bg-amber-50 border border-amber-200 text-amber-700 px-4 py-3 rounded-xl text-sm">
                        <p class="font-semibold mb-1">Manual verification code</p>
                        <p class="text-xs"><?php echo htmlspecialchars($_SESSION['otp_fallback_message'] ?? 'Use this verification code to continue:'); ?></p>
                        <p class="text-2xl font-bold tracking-[0.5em] mt-2"><?php echo htmlspecialchars($_SESSION['otp_fallback_code']); ?></p>
                    </div>
                    <?php unset($_SESSION['otp_fallback_code'], $_SESSION['otp_fallback_message']); ?>
                <?php endif; ?>
                
                <form method="POST" id="verificationForm" class="space-y-6">
                    <div class="flex justify-center gap-2.5">
                        <?php for ($i = 1; $i <= 6; $i++): ?>
                        <input type="text" name="code<?php echo $i; ?>" class="code-input w-12 h-13 text-center text-xl font-bold border-2 border-gray-200 rounded-xl bg-gray-50/50 focus:border-brand-500 focus:bg-white focus:outline-none focus:ring-2 focus:ring-brand-500/20 transition-all" maxlength="1" pattern="[0-9]" inputmode="numeric" required>
                        <?php endfor; ?>
                    </div>
                    <input type="hidden" name="verification_code" id="fullCode">
                    <input type="hidden" name="verify_code" value="1">
                    
                    <button type="submit" id="verifyButton" class="btn-primary w-full text-white font-semibold py-3 rounded-xl text-sm tracking-wide">
                        Verify Code
                    </button>
                </form>
                
                <div class="mt-5">
                    <p class="text-xs text-gray-400">
                        Code expires in 10 minutes.
                        <form method="POST" id="resendOtpForm" class="inline">
                            <input type="hidden" name="resend_otp" value="1">
                            <button type="submit" id="resendOtpBtn" class="text-xs text-brand-600 hover:text-brand-700 font-medium disabled:text-gray-400 disabled:cursor-not-allowed transition-colors ml-1">
                                Resend code
                            </button>
                        </form>
                    </p>
                </div>
            </div>
        </div>
    </div>
    
    <script>
        function setButtonLoading(button, text) {
            if (!button || button.dataset.loading === 'true') return;
            button.dataset.loading = 'true';
            button.disabled = true;
            button.setAttribute('aria-busy', 'true');
            button.innerHTML = `
                <span class="inline-flex items-center justify-center gap-2">
                    <svg class="animate-spin h-4 w-4 text-white" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" aria-hidden="true">
                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 0 1 8-8v4a4 4 0 0 0-4 4H4z"></path>
                    </svg>
                    <span>${text}</span>
                </span>
            `;
        }

        const verificationForm = document.getElementById('verificationForm');
        const verifyButton = document.getElementById('verifyButton');
        if (verificationForm) {
            verificationForm.addEventListener('submit', function(e) {
                const codeInputs = document.querySelectorAll('.code-input');
                const fullCode = Array.from(codeInputs).map(input => input.value || '').join('');
                
                if (fullCode.length !== 6) {
                    e.preventDefault();
                    showErrorAlert('Verification Code', 'Please enter all 6 digits of the verification code.');
                    return false;
                }
                
                document.getElementById('fullCode').value = fullCode;
                setButtonLoading(verifyButton, 'Verifying...');
            });
        }

        const loginForm = document.getElementById('employeeLoginForm');
        const loginButton = document.getElementById('loginButton');
        if (loginForm && loginButton) {
            loginForm.addEventListener('submit', function(e) {
                <?php if (defined('RECAPTCHA_ENABLED') && RECAPTCHA_ENABLED && defined('RECAPTCHA_SITE_KEY') && !empty(RECAPTCHA_SITE_KEY)): ?>
                if (typeof grecaptcha !== 'undefined') {
                    const recaptchaResponse = grecaptcha.getResponse();
                    if (!recaptchaResponse) {
                        e.preventDefault();
                        showToast('error', 'Please complete the reCAPTCHA verification.');
                        return false;
                    }
                }
                <?php endif; ?>
                setButtonLoading(loginButton, 'Signing in...');
            });
        }
        
        (function() {
            const resendBtn = document.getElementById('resendOtpBtn');
            const resendForm = document.getElementById('resendOtpForm');
            const cooldown = 60;
            let remaining = 0;
            let countdownInterval = null;
            const originalText = resendBtn ? resendBtn.textContent.trim() : '';
            
            function setButtonText(text) {
                if (!resendBtn) return;
                resendBtn.textContent = text;
            }
            
            function resetButton() {
                if (!resendBtn) return;
                resendBtn.disabled = false;
                setButtonText(originalText || 'Resend code');
            }
            
            function startCountdown(seconds) {
                if (!resendBtn) return;
                remaining = seconds;
                resendBtn.disabled = true;
                setButtonText(`Wait ${remaining}s`);
                
                if (countdownInterval) clearInterval(countdownInterval);
                
                countdownInterval = setInterval(() => {
                    remaining--;
                    if (remaining > 0) {
                        setButtonText(`Wait ${remaining}s`);
                    } else {
                        clearInterval(countdownInterval);
                        countdownInterval = null;
                        resetButton();
                    }
                }, 1000);
            }
            
            const resumeCountdown = <?php echo $resendCountdownShouldStart && $resendCountdownStartTime ? 'true' : 'false'; ?>;
            const serverStartTime = <?php echo $resendCountdownStartTime ? (int)$resendCountdownStartTime : 'null'; ?>;
            const serverNow = <?php echo time(); ?>;
            
            if (resumeCountdown && serverStartTime) {
                const elapsed = serverNow - serverStartTime;
                const remainingTime = Math.max(0, cooldown - elapsed);
                if (remainingTime > 0) {
                    startCountdown(remainingTime);
                } else {
                    resetButton();
                }
            }
            
            resendForm?.addEventListener('submit', function() {
                startCountdown(cooldown);
            });
        })();
    </script>
</body>
</html>
