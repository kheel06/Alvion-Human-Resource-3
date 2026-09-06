<?php
/**
 * Dynamic QR Token Service
 * Generates and validates time-limited tokens for QR attendance
 * Anti-fraud: signature, expiry, nonce, duplicate scan window
 */

function qrGenerateToken(PDO $db, int $locationId): array {
    $rotationSec = (int) (getHr3Setting($db, 'qr_rotation_seconds', 60) ?: 60);
    $now = time();
    $validFrom = $now;
    $validTo = $now + $rotationSec;
    $nonce = bin2hex(random_bytes(16));
    $payload = json_encode([
        'location_id' => $locationId,
        'valid_from' => $validFrom,
        'valid_to' => $validTo,
        'nonce' => $nonce,
    ]);
    $secret = defined('HR3_QR_SECRET') ? HR3_QR_SECRET : 'hr3-qr-default';
    $signature = hash_hmac('sha256', $payload, $secret);
    return [
        'payload' => $payload,
        'signature' => $signature,
        'valid_from' => $validFrom,
        'valid_to' => $validTo,
        'nonce' => $nonce,
    ];
}

function qrValidateToken(PDO $db, string $tokenJson, int $locationId): array {
    $dupWindow = (int) (getHr3Setting($db, 'duplicate_scan_window_seconds', 30) ?: 30);
    $result = ['valid' => false, 'error' => ''];

    $data = json_decode($tokenJson, true);
    if (!$data || !isset($data['payload'], $data['signature'], $data['location_id'], $data['valid_from'], $data['valid_to'], $data['nonce'])) {
        $result['error'] = 'Invalid token format';
        return $result;
    }

    if ((int) $data['location_id'] !== $locationId) {
        $result['error'] = 'Location mismatch';
        return $result;
    }

    $payloadStr = is_string($data['payload']) ? $data['payload'] : json_encode($data['payload']);
    $secret = defined('HR3_QR_SECRET') ? HR3_QR_SECRET : 'hr3-qr-default';
    $expectedSig = hash_hmac('sha256', $payloadStr, $secret);
    if (!hash_equals($expectedSig, $data['signature'] ?? '')) {
        $result['error'] = 'Invalid signature';
        return $result;
    }

    $now = time();
    if ($now < (int) ($data['valid_from'] ?? 0)) {
        $result['error'] = 'Token not yet valid';
        return $result;
    }
    if ($now > (int) ($data['valid_to'] ?? 0)) {
        $result['error'] = 'Token expired';
        return $result;
    }

    $result['valid'] = true;
    $result['nonce'] = $data['nonce'] ?? '';
    $result['dup_window'] = $dupWindow;
    return $result;
}
