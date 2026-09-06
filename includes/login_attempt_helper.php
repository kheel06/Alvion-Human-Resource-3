<?php
/**
 * Login Attempt Security Functions
 * Tracks failed login attempts and implements account lockout
 */

/**
 * Check if an identifier (email/username) is currently locked out
 * 
 * @param PDO $db Database connection
 * @param string $identifier User identifier (email, username, employee ID)
 * @return array ['locked' => bool, 'lockout_until' => timestamp|null, 'remaining_seconds' => int]
 */
function checkLoginLockout(PDO $db, string $identifier): array {
    try {
        // Check if there's an active lockout
        $stmt = $db->prepare("
            SELECT lockout_until 
            FROM login_attempts 
            WHERE identifier = :identifier 
            AND lockout_until IS NOT NULL 
            AND lockout_until > NOW()
            ORDER BY lockout_until DESC 
            LIMIT 1
        ");
        $stmt->bindValue(':identifier', $identifier);
        $stmt->execute();
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($result && $result['lockout_until']) {
            $lockout_time = strtotime($result['lockout_until']);
            $current_time = time();
            $remaining = max(0, $lockout_time - $current_time);
            
            return [
                'locked' => true,
                'lockout_until' => $result['lockout_until'],
                'remaining_seconds' => $remaining
            ];
        }
        
        return ['locked' => false, 'lockout_until' => null, 'remaining_seconds' => 0];
    } catch (PDOException $e) {
        error_log('Login lockout check error: ' . $e->getMessage());
        return ['locked' => false, 'lockout_until' => null, 'remaining_seconds' => 0];
    }
}

/**
 * Record a failed login attempt
 * 
 * @param PDO $db Database connection
 * @param string $identifier User identifier
 * @param int $maxAttempts Maximum attempts before lockout (default: 3)
 * @param int $lockoutDuration Lockout duration in seconds (default: 30)
 * @return array ['locked_out' => bool, 'attempts_remaining' => int, 'lockout_until' => timestamp|null]
 */
function recordFailedLoginAttempt(PDO $db, string $identifier, int $maxAttempts = 3, int $lockoutDuration = 30): array {
    try {
        $ip_address = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
        $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? null;
        
        // Insert the failed attempt
        $stmt = $db->prepare("
            INSERT INTO login_attempts (identifier, ip_address, user_agent, attempt_time)
            VALUES (:identifier, :ip_address, :user_agent, NOW())
        ");
        $stmt->bindValue(':identifier', $identifier);
        $stmt->bindValue(':ip_address', $ip_address);
        $stmt->bindValue(':user_agent', $user_agent);
        $stmt->execute();
        
        // Count recent failed attempts (within last 5 minutes)
        // Count ALL attempts, including those with lockout set
        $stmt = $db->prepare("
            SELECT COUNT(*) as attempt_count
            FROM login_attempts
            WHERE identifier = :identifier
            AND attempt_time > DATE_SUB(NOW(), INTERVAL 5 MINUTE)
        ");
        $stmt->bindValue(':identifier', $identifier);
        $stmt->execute();
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        $attempt_count = (int)($result['attempt_count'] ?? 0);
        
        $attempts_remaining = max(0, $maxAttempts - $attempt_count);
        
        // If max attempts reached, set lockout
        if ($attempt_count >= $maxAttempts) {
            $lockout_until = date('Y-m-d H:i:s', time() + $lockoutDuration);
            
            $stmt = $db->prepare("
                UPDATE login_attempts
                SET lockout_until = :lockout_until
                WHERE identifier = :identifier
                AND attempt_time > DATE_SUB(NOW(), INTERVAL 5 MINUTE)
            ");
            $stmt->bindValue(':lockout_until', $lockout_until);
            $stmt->bindValue(':identifier', $identifier);
            $stmt->execute();
            
            return [
                'locked_out' => true,
                'attempts_remaining' => 0,
                'lockout_until' => $lockout_until,
                'lockout_seconds' => $lockoutDuration
            ];
        }
        
        return [
            'locked_out' => false,
            'attempts_remaining' => $attempts_remaining,
            'lockout_until' => null,
            'lockout_seconds' => 0
        ];
    } catch (PDOException $e) {
        error_log('Failed login attempt recording error: ' . $e->getMessage());
        return [
            'locked_out' => false,
            'attempts_remaining' => $maxAttempts,
            'lockout_until' => null,
            'lockout_seconds' => 0
        ];
    }
}

/**
 * Clear login attempts for a successful login
 * 
 * @param PDO $db Database connection
 * @param string $identifier User identifier
 * @return bool Success status
 */
function clearLoginAttempts(PDO $db, string $identifier): bool {
    try {
        $stmt = $db->prepare("
            DELETE FROM login_attempts
            WHERE identifier = :identifier
        ");
        $stmt->bindValue(':identifier', $identifier);
        return $stmt->execute();
    } catch (PDOException $e) {
        error_log('Clear login attempts error: ' . $e->getMessage());
        return false;
    }
}

/**
 * Clean up old login attempts (older than 24 hours)
 * Should be called periodically
 * 
 * @param PDO $db Database connection
 * @return int Number of records deleted
 */
function cleanupOldLoginAttempts(PDO $db): int {
    try {
        $stmt = $db->prepare("
            DELETE FROM login_attempts
            WHERE attempt_time < DATE_SUB(NOW(), INTERVAL 24 HOUR)
        ");
        $stmt->execute();
        return $stmt->rowCount();
    } catch (PDOException $e) {
        error_log('Cleanup old login attempts error: ' . $e->getMessage());
        return 0;
    }
}
