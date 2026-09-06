<?php
require_once __DIR__ . '/../../config/config.php';
requireAuth();
checkRole(['employee', 'supervisor', 'unit_head']);

$page_title = 'Support Ticket';
$message = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['subject'], $_POST['message'])) {
    $subject = trim($_POST['subject'] ?? '');
    $msg = trim($_POST['message'] ?? '');
    if (strlen($subject) >= 5 && strlen($msg) >= 20) {
        $message = 'Thank you. Your support ticket has been submitted. We will respond via email.';
        $_SESSION['success'] = $message;
    } else {
        $message = 'Subject (min 5 chars) and message (min 20 chars) are required.';
    }
}

include __DIR__ . '/../../includes/header.php';
?>

<div class="mb-6">
    <h1 class="text-2xl font-semibold text-gray-900 dark:text-white">Support Ticket</h1>
    <p class="mt-1 text-sm text-gray-600 dark:text-gray-400">Submit a support request for HR system issues</p>
</div>

<div class="bg-white dark:bg-gray-800 rounded-xl shadow-sm border border-gray-100 dark:border-gray-700 p-6 max-w-2xl">
    <?php if ($message): ?>
        <div class="mb-4 p-3 rounded-lg <?php echo strpos($message, 'Thank you') !== false ? 'bg-green-100 dark:bg-green-900/30 text-green-800 dark:text-green-200' : 'bg-amber-100 dark:bg-amber-900/30 text-amber-800 dark:text-amber-200'; ?>">
            <?php echo htmlspecialchars($message); ?>
        </div>
    <?php endif; ?>
    <form method="POST" class="space-y-4">
        <div>
            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Subject</label>
            <input type="text" name="subject" required minlength="5" class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-700 text-gray-900 dark:text-white" placeholder="Brief description">
        </div>
        <div>
            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Message</label>
            <textarea name="message" required minlength="20" rows="5" class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-700 text-gray-900 dark:text-white" placeholder="Describe your issue in detail"></textarea>
        </div>
        <button type="submit" class="px-4 py-2 bg-primary-600 text-white rounded-lg hover:bg-primary-700">Submit Ticket</button>
    </form>
</div>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
