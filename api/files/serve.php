<?php
/**
 * File serving endpoint for claim attachments
 * Usage: /api/files/serve.php?path=uploads/receipts/receipt_1.pdf
 */
session_start();
require_once __DIR__ . '/../../config/config.php';

// Check if user is logged in
if (!isset($_SESSION['user_id']) && !isset($_SESSION['employee_id']) && !isset($_SESSION['hr3_employee_id'])) {
    http_response_code(401);
    exit('Unauthorized');
}

$filePath = $_GET['path'] ?? '';

// Security: Only allow files from uploads directory
if (empty($filePath) || strpos($filePath, '..') !== false) {
    http_response_code(400);
    exit('Invalid path');
}

// Only allow specific directories
$allowedPaths = ['uploads/receipts/', 'assets/uploads/claim_receipts/'];
$isAllowed = false;
foreach ($allowedPaths as $allowed) {
    if (strpos($filePath, $allowed) === 0) {
        $isAllowed = true;
        break;
    }
}

if (!$isAllowed) {
    http_response_code(403);
    exit('Access denied');
}

$fullPath = __DIR__ . '/../../' . $filePath;

if (!file_exists($fullPath)) {
    http_response_code(404);
    exit('File not found');
}

// Get file info
$ext = strtolower(pathinfo($fullPath, PATHINFO_EXTENSION));
$mimeTypes = [
    'pdf' => 'application/pdf',
    'html' => 'text/html',
    'htm' => 'text/html',
    'jpg' => 'image/jpeg',
    'jpeg' => 'image/jpeg',
    'png' => 'image/png',
    'gif' => 'image/gif',
    'webp' => 'image/webp'
];

$contentType = $mimeTypes[$ext] ?? 'application/octet-stream';

// Set headers for inline display
header('Content-Type: ' . $contentType);
header('Content-Length: ' . filesize($fullPath));
header('Content-Disposition: inline; filename="' . basename($fullPath) . '"');
header('Cache-Control: public, max-age=3600');

// Output file
readfile($fullPath);
exit;
