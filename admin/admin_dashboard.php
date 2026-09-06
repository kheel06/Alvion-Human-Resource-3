<?php
/**
 * Redirect to canonical admin dashboard URL (hyphen).
 * Use admin-dashboard.php for the actual dashboard content.
 */
$query = $_SERVER['QUERY_STRING'] ?? '';
$dest = 'admin-dashboard.php' . ($query !== '' ? '?' . $query : '');
header('Location: ' . $dest, true, 301);
exit;
