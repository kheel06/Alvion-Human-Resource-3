<?php
require_once __DIR__ . '/../../config/config.php';
requireAuth();
checkRole(['employee', 'supervisor', 'unit_head', 'hr_admin']);

$page_title = 'HR Policies & FAQs';

include __DIR__ . '/../../includes/header.php';
?>

<div class="mb-6">
    <h1 class="text-2xl font-semibold text-gray-900 dark:text-white">HR Policies & FAQs</h1>
    <p class="mt-1 text-sm text-gray-600 dark:text-gray-400">Frequently asked questions and policy overview</p>
</div>

<div class="space-y-6">
    <div class="bg-white dark:bg-gray-800 rounded-xl shadow-sm border border-gray-100 dark:border-gray-700 p-6">
        <h2 class="text-lg font-semibold text-gray-900 dark:text-white mb-3">Leave Policy</h2>
        <p class="text-gray-600 dark:text-gray-300 text-sm">Vacation Leave (VL), Sick Leave (SL), and Special Leave (SIL) are available per company policy. Check your Leave Balance before applying. Approved leave is automatically reflected in your timesheet.</p>
    </div>
    <div class="bg-white dark:bg-gray-800 rounded-xl shadow-sm border border-gray-100 dark:border-gray-700 p-6">
        <h2 class="text-lg font-semibold text-gray-900 dark:text-white mb-3">Claims & Reimbursement</h2>
        <p class="text-gray-600 dark:text-gray-300 text-sm">Submit claims with receipts for eligible expenses. Approval route depends on claim category. Payment updates will appear in My Claims Status once processed by Finance.</p>
    </div>
    <div class="bg-white dark:bg-gray-800 rounded-xl shadow-sm border border-gray-100 dark:border-gray-700 p-6">
        <h2 class="text-lg font-semibold text-gray-900 dark:text-white mb-3">Timesheet & Cut-off</h2>
        <p class="text-gray-600 dark:text-gray-300 text-sm">Timesheets are generated per cut-off period. Review your timesheet and file a dispute if there are errors. After cut-off lock, changes require HR Admin override.</p>
    </div>
    <div class="bg-white dark:bg-gray-800 rounded-xl shadow-sm border border-gray-100 dark:border-gray-700 p-6">
        <h2 class="text-lg font-semibold text-gray-900 dark:text-white mb-3">Attendance</h2>
        <p class="text-gray-600 dark:text-gray-300 text-sm">Record your time in/out via the system as per your unit's process. If you missed a punch, submit a Time Correction Request from Attendance Management.</p>
    </div>
</div>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
