<?php
require_once __DIR__ . '/../../config/config.php';
requireAuth();
checkRole(['employee', 'supervisor', 'unit_head']);

$page_title = 'Attendance Rules';
$settings = [];
if (isset($db)) {
    try {
        $stmt = $db->query("SELECT setting_key, setting_value FROM hr3_settings ORDER BY setting_key");
        while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $settings[$r['setting_key']] = $r['setting_value'];
        }
    } catch (PDOException $e) {}
}

include __DIR__ . '/../../includes/header.php';
?>

<div class="mb-6">
    <h1 class="text-2xl font-semibold text-gray-900 dark:text-white">Attendance Rules</h1>
    <p class="mt-1 text-sm text-gray-600 dark:text-gray-400">Philippines labor policy summary (configurable by HR)</p>
</div>

<div class="bg-white dark:bg-gray-800 rounded-xl shadow-sm border border-gray-100 dark:border-gray-700 p-6">
    <div class="prose dark:prose-invert max-w-none">
        <h2 class="text-lg font-semibold text-gray-900 dark:text-white mb-4">Standard Work Hours</h2>
        <ul class="list-disc pl-6 space-y-1 text-gray-600 dark:text-gray-300">
            <li>Standard work hours: <?php echo $settings['standard_work_hours'] ?? '8'; ?> hours/day</li>
            <li>Meal break: <?php echo $settings['meal_break_mins'] ?? '60'; ?> minutes (unpaid unless configured)</li>
        </ul>

        <h2 class="text-lg font-semibold text-gray-900 dark:text-white mt-6 mb-4">Overtime (OT)</h2>
        <ul class="list-disc pl-6 space-y-1 text-gray-600 dark:text-gray-300">
            <li>OT applies beyond <?php echo $settings['standard_work_hours'] ?? '8'; ?> hours</li>
            <li>Regular day OT premium: <?php echo $settings['ot_premium_regular'] ?? '1.25'; ?>x</li>
            <li>Rest day / Holiday OT: <?php echo $settings['ot_premium_rest'] ?? '1.30'; ?>x on top of day multiplier</li>
        </ul>

        <h2 class="text-lg font-semibold text-gray-900 dark:text-white mt-6 mb-4">Night Differential (ND)</h2>
        <ul class="list-disc pl-6 space-y-1 text-gray-600 dark:text-gray-300">
            <li>+<?php echo (floatval($settings['nd_rate'] ?? 0.10) * 100); ?>% per hour between 22:00 and 06:00</li>
        </ul>

        <h2 class="text-lg font-semibold text-gray-900 dark:text-white mt-6 mb-4">Rest Day & Holiday Premiums</h2>
        <ul class="list-disc pl-6 space-y-1 text-gray-600 dark:text-gray-300">
            <li>Rest day premium: <?php echo $settings['rest_day_premium'] ?? '1.30'; ?>x</li>
            <li>Regular holiday worked: <?php echo $settings['holiday_regular_worked'] ?? '2.00'; ?>x</li>
            <li>Regular holiday on rest day: <?php echo $settings['holiday_regular_rest_day_worked'] ?? '2.60'; ?>x</li>
            <li>Special non-working day: <?php echo $settings['holiday_special_worked'] ?? '1.30'; ?>x</li>
            <li>Special non-working day on rest day: <?php echo $settings['holiday_special_rest_day_worked'] ?? '1.50'; ?>x</li>
        </ul>

        <h2 class="text-lg font-semibold text-gray-900 dark:text-white mt-6 mb-4">Other Rules</h2>
        <ul class="list-disc pl-6 space-y-1 text-gray-600 dark:text-gray-300">
            <li>Undertime cannot be offset by overtime across different days</li>
        </ul>
    </div>
</div>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
