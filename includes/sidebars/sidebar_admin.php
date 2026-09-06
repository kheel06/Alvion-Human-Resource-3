<?php
// Ensure BASE_URL is defined
if (!defined('BASE_URL')) {
    require_once __DIR__ . '/../config/config.php';
}

$role_name = $_SESSION['role_name'] ?? $_SESSION['user_role'] ?? 'employee';
$role_id = $_SESSION['role_id'] ?? null;
$current_page = basename($_SERVER['PHP_SELF']);

// Helper function for active menu item
function isActive($page) {
    global $current_page;
    return $current_page === $page ? 'bg-gray-100 dark:bg-gray-700 text-gray-900 dark:text-white' : '';
}

// Helper function to check if current page is in a directory
function isActiveDir($dir) {
    global $current_page;
    $script_path = $_SERVER['PHP_SELF'] ?? '';
    return strpos($script_path, $dir) !== false ? 'bg-gray-100 dark:bg-gray-700 text-gray-900 dark:text-white' : '';
}
?>
<!-- Sidebar Overlay (Mobile) -->
<div id="sidebarOverlay" class="hidden fixed inset-0 bg-black bg-opacity-50 z-40 lg:hidden sidebar-transition"></div>

<aside id="sidebar" class="fixed lg:static inset-y-0 left-0 z-50 bg-white dark:bg-gray-800 shadow-xl sidebar-transition transform -translate-x-full lg:translate-x-0 transition-all duration-300">
    <div class="flex flex-col h-full">
        <!-- Logo/Branding -->
        <div class="flex items-center justify-center px-4 py-5 border-b border-gray-200 dark:border-gray-700 transition-all duration-300">
            <div class="flex items-center justify-center sidebar-logo-full w-full">
                <img src="<?php echo BASE_URL; ?>/assets/img/alvion-logo-removebg.png" alt="Alvion" class="h-8 object-contain transition-opacity duration-200">
            </div>
            <div class="flex items-center justify-center sidebar-logo-collapsed hidden w-full">
                <img src="<?php echo BASE_URL; ?>/assets/img/alvion-logo-removebg.png" alt="Alvion" class="h-8 object-contain transition-opacity duration-200">
            </div>
        </div>

        <!-- Navigation -->
        <nav class="flex-1 px-2.5 py-3 space-y-0.5 overflow-y-auto overflow-x-hidden">

            <!-- ═══════════════════════════════════════ -->
            <!-- 1) DASHBOARD                            -->
            <!-- ═══════════════════════════════════════ -->
            <a href="<?php echo BASE_URL; ?>/admin/admin-dashboard.php" class="sidebar-item flex items-center px-3 py-2 text-gray-700 dark:text-gray-300 rounded-md transition-all duration-200 <?php echo $current_page === 'admin-dashboard.php' || strpos($current_page, 'dashboard') !== false ? 'active' : ''; ?>">
                <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="w-5 h-5 mr-3 flex-shrink-0"><rect width="7" height="9" x="3" y="3" rx="1"></rect><rect width="7" height="5" x="14" y="3" rx="1"></rect><rect width="7" height="9" x="14" y="12" rx="1"></rect><rect width="7" height="5" x="3" y="16" rx="1"></rect></svg>
                <span class="sidebar-text text-sm">Dashboard</span>
            </a>

            <!-- ═══════════════════════════════════════ -->
            <!-- 2) Employee Masterlist & Workforce      -->
            <!-- ═══════════════════════════════════════ -->
            <div class="mb-3 mt-4">
                <a href="<?php echo BASE_URL; ?>/admin/modules/admin-employee_management.php?view=masterlist" class="sidebar-item flex items-center px-3 py-2 text-gray-700 dark:text-gray-300 rounded-md transition-all duration-200 <?php echo isActive('admin-employee_management.php') ? 'active' : ''; ?>">
                    <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="w-5 h-5 mr-3 flex-shrink-0"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"></path><circle cx="9" cy="7" r="4"></circle><path d="M22 21v-2a4 4 0 0 0-3-3.87"></path><path d="M16 3.13a4 4 0 0 1 0 7.75"></path></svg>
                    <span class="sidebar-text text-sm">Employee Masterlist</span>
                </a>
            </div>

            <div class="mb-3 mt-4">
                <!-- Shift & Schedule Management ▼ -->
                <button
                    type="button"
                    class="sidebar-item w-full flex items-center justify-between px-3 py-2 mt-2 text-gray-700 dark:text-gray-300 rounded-md transition-all duration-200"
                    data-collapse-target="duty-roster-submenu"
                >
                    <span class="flex items-center">
                        <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="w-5 h-5 mr-3 flex-shrink-0"><rect x="3" y="4" width="18" height="18" rx="2"></rect><path d="M16 2v4"></path><path d="M8 2v4"></path><path d="M3 10h18"></path></svg>
                        <span class="sidebar-text text-sm">Shift & Schedule Management</span>
                    </span>
                    <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="w-4 h-4 ml-2 text-gray-400 transition-transform duration-200">
                        <polyline points="6 9 12 15 18 9"></polyline>
                    </svg>
                </button>

                <div id="duty-roster-submenu" class="mt-1 space-y-1 hidden">
                    <a href="<?php echo BASE_URL; ?>/admin/modules/admin-shift_&_scheduling.php?view=roster_calendar" class="flex items-center pl-11 pr-3 py-1.5 text-xs text-gray-600 dark:text-gray-400 rounded-md hover:bg-gray-100 dark:hover:bg-gray-700 sidebar-item transition-all duration-200 <?php echo isActive('admin-shift_&_scheduling.php') && (isset($_GET['view']) && $_GET['view'] === 'roster_calendar') ? 'bg-gray-100 dark:bg-gray-700 text-gray-900 dark:text-white' : ''; ?>">
                        <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="w-3.5 h-3.5 mr-2 flex-shrink-0"><rect x="3" y="4" width="18" height="18" rx="2"></rect><path d="M16 2v4"></path><path d="M8 2v4"></path><path d="M3 10h18"></path><rect x="8" y="14" width="2" height="2"></rect><rect x="14" y="14" width="2" height="2"></rect></svg>
                        <span class="sidebar-text">Schedule Calendar</span>
                    </a>
                    <a href="<?php echo BASE_URL; ?>/admin/modules/admin-shift_&_scheduling.php?view=shift_templates" class="flex items-center pl-11 pr-3 py-1.5 text-xs text-gray-600 dark:text-gray-400 rounded-md hover:bg-gray-100 dark:hover:bg-gray-700 sidebar-item transition-all duration-200 <?php echo isActive('admin-shift_&_scheduling.php') && (isset($_GET['view']) && $_GET['view'] === 'shift_templates') ? 'bg-gray-100 dark:bg-gray-700 text-gray-900 dark:text-white' : ''; ?>">
                        <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="w-3.5 h-3.5 mr-2 flex-shrink-0"><rect x="3" y="3" width="18" height="18" rx="2"></rect><path d="M3 9h18"></path><path d="M9 21V9"></path></svg>
                        <span class="sidebar-text">Shift Templates</span>
                    </a>
                    <a href="<?php echo BASE_URL; ?>/admin/modules/admin-shift-requests.php" class="flex items-center pl-11 pr-3 py-1.5 text-xs text-gray-600 dark:text-gray-400 rounded-md hover:bg-gray-100 dark:hover:bg-gray-700 sidebar-item transition-all duration-200 <?php echo isActive('admin-shift-requests.php') ? 'bg-gray-100 dark:bg-gray-700 text-gray-900 dark:text-white' : ''; ?>">
                        <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="w-3.5 h-3.5 mr-2 flex-shrink-0"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path><polyline points="14 2 14 8 20 8"></polyline><line x1="12" y1="18" x2="12" y2="12"></line><line x1="9" y1="15" x2="15" y2="15"></line></svg>
                        <span class="sidebar-text">Shift Change Requests</span>
                    </a>
                </div>

                <!-- Leave ▼ -->
                <button
                    type="button"
                    class="sidebar-item w-full flex items-center justify-between px-3 py-2 mt-2 text-gray-700 dark:text-gray-300 rounded-md transition-all duration-200"
                    data-collapse-target="leave-submenu"
                >
                    <span class="flex items-center">
                        <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="w-5 h-5 mr-3 flex-shrink-0"><path d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"></path></svg>
                        <span class="sidebar-text text-sm">Leave</span>
                    </span>
                    <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="w-4 h-4 ml-2 text-gray-400 transition-transform duration-200">
                        <polyline points="6 9 12 15 18 9"></polyline>
                    </svg>
                </button>

                <div id="leave-submenu" class="mt-1 space-y-1 hidden">
                    <a href="<?php echo BASE_URL; ?>/admin/modules/admin-leave_management.php?view=leave_requests_queue" class="flex items-center pl-11 pr-3 py-1.5 text-xs text-gray-600 dark:text-gray-400 rounded-md hover:bg-gray-100 dark:hover:bg-gray-700 sidebar-item transition-all duration-200 <?php echo isActive('admin-leave_management.php') && (isset($_GET['view']) && $_GET['view'] === 'leave_requests_queue') ? 'bg-gray-100 dark:bg-gray-700 text-gray-900 dark:text-white' : ''; ?>">
                        <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="w-3.5 h-3.5 mr-2 flex-shrink-0"><path d="M22 12h-4l-3 9L9 3l-3 9H2"></path></svg>
                        <span class="sidebar-text">Leave Approvals</span>
                    </a>
                    <a href="<?php echo BASE_URL; ?>/admin/modules/admin-leave_management.php?view=leave_types_policies" class="flex items-center pl-11 pr-3 py-1.5 text-xs text-gray-600 dark:text-gray-400 rounded-md hover:bg-gray-100 dark:hover:bg-gray-700 sidebar-item transition-all duration-200 <?php echo isActive('admin-leave_management.php') && (isset($_GET['view']) && $_GET['view'] === 'leave_types_policies') ? 'bg-gray-100 dark:bg-gray-700 text-gray-900 dark:text-white' : ''; ?>">
                        <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="w-3.5 h-3.5 mr-2 flex-shrink-0"><path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20"></path><path d="M6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15A2.5 2.5 0 0 1 6.5 2z"></path></svg>
                        <span class="sidebar-text">Leave Types & Policies</span>
                    </a>
                    <a href="<?php echo BASE_URL; ?>/admin/modules/admin-leave_management.php?view=leave_balances" class="flex items-center pl-11 pr-3 py-1.5 text-xs text-gray-600 dark:text-gray-400 rounded-md hover:bg-gray-100 dark:hover:bg-gray-700 sidebar-item transition-all duration-200 <?php echo isActive('admin-leave_management.php') && (isset($_GET['view']) && $_GET['view'] === 'leave_balances') ? 'bg-gray-100 dark:bg-gray-700 text-gray-900 dark:text-white' : ''; ?>">
                        <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="w-3.5 h-3.5 mr-2 flex-shrink-0"><path d="M21 12V7H5a2 2 0 0 1 0-4h14v4"></path><path d="M3 5v14a2 2 0 0 0 2 2h16v-2"></path><path d="M18 12a2 2 0 0 0 0 4h4v-4Z"></path></svg>
                        <span class="sidebar-text">Leave Balances</span>
                    </a>
                </div>

                <!-- Claims & Reimbursements ▼ -->
                <button
                    type="button"
                    class="sidebar-item w-full flex items-center justify-between px-3 py-2 mt-2 text-gray-700 dark:text-gray-300 rounded-md transition-all duration-200"
                    data-collapse-target="claims-submenu"
                >
                    <span class="flex items-center">
                        <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="w-5 h-5 mr-3 flex-shrink-0"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"></path><path d="M9 12l2 2 4-4"></path></svg>
                        <span class="sidebar-text text-sm">Claims & Reimbursements</span>
                    </span>
                    <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="w-4 h-4 ml-2 text-gray-400 transition-transform duration-200">
                        <polyline points="6 9 12 15 18 9"></polyline>
                    </svg>
                </button>

                <div id="claims-submenu" class="mt-1 space-y-1 hidden">
                    <a href="<?php echo BASE_URL; ?>/admin/modules/admin-claims_&_reimbursment.php?view=claim_review_queue" class="flex items-center pl-11 pr-3 py-1.5 text-xs text-gray-600 dark:text-gray-400 rounded-md hover:bg-gray-100 dark:hover:bg-gray-700 sidebar-item transition-all duration-200 <?php echo isActive('admin-claims_&_reimbursment.php') && (isset($_GET['view']) && $_GET['view'] === 'claim_review_queue') ? 'bg-gray-100 dark:bg-gray-700 text-gray-900 dark:text-white' : ''; ?>">
                        <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="w-3.5 h-3.5 mr-2 flex-shrink-0"><path d="M9 11l3 3L22 4"></path><path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"></path></svg>
                        <span class="sidebar-text">Claims Review & Approval</span>
                    </a>
                    <a href="<?php echo BASE_URL; ?>/admin/modules/admin-claims_&_reimbursment.php?view=claim_types_limits" class="flex items-center pl-11 pr-3 py-1.5 text-xs text-gray-600 dark:text-gray-400 rounded-md hover:bg-gray-100 dark:hover:bg-gray-700 sidebar-item transition-all duration-200 <?php echo isActive('admin-claims_&_reimbursment.php') && (isset($_GET['view']) && $_GET['view'] === 'claim_types_limits') ? 'bg-gray-100 dark:bg-gray-700 text-gray-900 dark:text-white' : ''; ?>">
                        <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="w-3.5 h-3.5 mr-2 flex-shrink-0"><circle cx="12" cy="12" r="3"></circle><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1 0 2.83 2 2 0 0 1-2.83 0l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-4 0v-.09A1.65 1.65 0 0 0 8 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83 0 2 2 0 0 1 0-2.83l.06-.06A1.65 1.65 0 0 0 3.6 15a1.65 1.65 0 0 0-1.51-1H2a2 2 0 0 1 0-4h.09A1.65 1.65 0 0 0 3.6 8a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 0-2.83 2 2 0 0 1 2.83 0l.06.06A1.65 1.65 0 0 0 8 4.6a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 0 2 2 0 0 1 0 2.83l-.06.06A1.65 1.65 0 0 0 19.4 8c.26.4.4.86.4 1.33v.09A2 2 0 0 1 22 12a2 2 0 0 1-2 2h-.09c-.47 0-.93.14-1.33.4z"></path></svg>
                        <span class="sidebar-text">Reimbursement Settings</span>
                    </a>
                </div>
            </div>

            <!-- ═══════════════════════════════════════ -->
            <!-- Attendance: Daily view, Biometric, Timesheets -->
            <!-- ═══════════════════════════════════════ -->
            <div class="mb-3 mt-4">
                <a href="<?php echo BASE_URL; ?>/admin/modules/admin-attendance.php" class="sidebar-item flex items-center px-3 py-2 text-gray-700 dark:text-gray-300 rounded-md transition-all duration-200 <?php echo isActive('admin-attendance.php') ? 'active' : ''; ?>">
                    <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="w-5 h-5 mr-3 flex-shrink-0"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"></path><path d="M8 2v4"></path><path d="M16 2v4"></path><rect x="3" y="10" width="18" height="12" rx="2"></rect></svg>
                    <span class="sidebar-text text-sm">Attendance</span>
                </a>
                <a href="<?php echo BASE_URL; ?>/admin/modules/admin-biometric_log.php" class="sidebar-item flex items-center px-3 py-2 text-gray-700 dark:text-gray-300 rounded-md transition-all duration-200 <?php echo isActive('admin-biometric_log.php') ? 'active' : ''; ?>">
                    <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="w-5 h-5 mr-3 flex-shrink-0"><path d="M12 22c5.523 0 10-4.477 10-10S17.523 2 12 2 2 6.477 2 12s4.477 10 10 10z"></path><path d="m9 12 2 2 4-4"></path></svg>
                    <span class="sidebar-text text-sm">Biometric Log</span>
                </a>
                <a href="<?php echo BASE_URL; ?>/admin/modules/admin-timesheets.php" class="sidebar-item flex items-center px-3 py-2 text-gray-700 dark:text-gray-300 rounded-md transition-all duration-200 <?php echo isActive('admin-timesheets.php') ? 'active' : ''; ?>">
                    <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="w-5 h-5 mr-3 flex-shrink-0"><rect x="3" y="4" width="18" height="18" rx="2"></rect><path d="M3 10h18"></path><path d="M8 2v4"></path><path d="M16 2v4"></path></svg>
                    <span class="sidebar-text text-sm">Timesheets</span>
                </a>
            </div>

            <!-- ═══════════════════════════════════════ -->
            <!-- 3) REPORTS & ANALYTICS                  -->
            <!-- ═══════════════════════════════════════ -->
            <div class="mb-3 mt-4">
                <a href="<?php echo BASE_URL; ?>/admin/modules/admin-reports.php?view=attendance_summary" class="sidebar-item flex items-center px-3 py-2 text-gray-700 dark:text-gray-300 rounded-md transition-all duration-200 <?php echo isActive('admin-reports.php') && (isset($_GET['view']) && $_GET['view'] === 'attendance_summary') ? 'active' : ''; ?>">
                    <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="w-5 h-5 mr-3 flex-shrink-0"><line x1="12" y1="20" x2="12" y2="10"></line><line x1="18" y1="20" x2="18" y2="4"></line><line x1="6" y1="20" x2="6" y2="16"></line></svg>
                    <span class="sidebar-text text-sm">HR Reports</span>
                </a>
                <a href="<?php echo BASE_URL; ?>/admin/modules/admin-reports.php?view=export" class="sidebar-item flex items-center px-3 py-2 text-gray-700 dark:text-gray-300 rounded-md transition-all duration-200 <?php echo isActive('admin-reports.php') && (isset($_GET['view']) && $_GET['view'] === 'export') ? 'active' : ''; ?>">
                    <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="w-5 h-5 mr-3 flex-shrink-0"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path><polyline points="7 10 12 15 17 10"></polyline><line x1="12" y1="15" x2="12" y2="3"></line></svg>
                    <span class="sidebar-text text-sm">Export Center (PDF / Excel)</span>
                </a>
            </div>

            <!-- ═══════════════════════════════════════ -->
            <!-- 5) AI DASHBOARD                         -->
            <!-- ═══════════════════════════════════════ -->
            <div class="mb-3 mt-4">
                <h3 class="px-3 text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wider mb-2">AI Intelligence</h3>

                <a href="<?php echo BASE_URL; ?>/admin/modules/admin-ai_dashboard.php?view=overview" class="sidebar-item flex items-center px-3 py-2 text-gray-700 dark:text-gray-300 rounded-md transition-all duration-200 <?php echo isActive('admin-ai_dashboard.php') ? 'active' : ''; ?>">
                    <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="w-5 h-5 mr-3 flex-shrink-0"><path d="M12 8V4H8"/><rect width="16" height="12" x="4" y="8" rx="2"/><path d="M2 14h2"/><path d="M20 14h2"/><path d="M15 13v2"/><path d="M9 13v2"/></svg>
                    <span class="sidebar-text text-sm">AI Overview</span>
                </a>
            </div>

        </nav>

        <!-- Sidebar Footer - Empty for spacing -->
        <div class="p-3"></div>
    </div>
</aside>

<script>
    // Enhanced dropdown functionality for admin sidebar submodules
    document.addEventListener('DOMContentLoaded', function () {
        // Function to check if current page is in a submenu
        function isCurrentPageInSubmenu(submenuId) {
            const currentPath = window.location.pathname;
            const currentSearch = window.location.search;
            const submenu = document.getElementById(submenuId);
            
            if (!submenu) return false;
            
            const links = submenu.querySelectorAll('a');
            for (let link of links) {
                const linkPath = new URL(link.href).pathname;
                const linkSearch = new URL(link.href).search;
                
                if (linkPath === currentPath && linkSearch === currentSearch) {
                    return true;
                }
            }
            return false;
        }
        
        // Function to toggle dropdown with chevron rotation
        function toggleDropdown(button, submenu) {
            const isHidden = submenu.classList.contains('hidden');
            const chevron = button.querySelector('svg:last-child');
            
            if (isHidden) {
                submenu.classList.remove('hidden');
                if (chevron) {
                    chevron.style.transform = 'rotate(180deg)';
                }
            } else {
                submenu.classList.add('hidden');
                if (chevron) {
                    chevron.style.transform = 'rotate(0deg)';
                }
            }
        }
        
        // Initialize all dropdown buttons
        document.querySelectorAll('#sidebar [data-collapse-target]').forEach(function (button) {
            const targetId = button.getAttribute('data-collapse-target');
            const submenu = document.getElementById(targetId);
            
            if (!submenu) return;
            
            // Auto-expand if current page is in this submenu
            if (isCurrentPageInSubmenu(targetId)) {
                submenu.classList.remove('hidden');
                const chevron = button.querySelector('svg:last-child');
                if (chevron) {
                    chevron.style.transform = 'rotate(180deg)';
                }
            }
            
            // Add click handler
            button.addEventListener('click', function (e) {
                e.preventDefault();
                e.stopPropagation();
                toggleDropdown(button, submenu);
            });
        });
        
        // Add smooth transitions for chevron rotation
        const style = document.createElement('style');
        style.textContent = `
            #sidebar [data-collapse-target] svg:last-child {
                transition: transform 0.2s ease-in-out;
            }
            #sidebar .sidebar-submenu {
                transition: all 0.2s ease-in-out;
                overflow: hidden;
            }
            #sidebar .sidebar-submenu.hidden {
                max-height: 0;
                opacity: 0;
            }
            #sidebar .sidebar-submenu:not(.hidden) {
                max-height: 500px;
                opacity: 1;
            }
        `;
        document.head.appendChild(style);
        
        // Add submenu class for styling
        document.querySelectorAll('[id$="-submenu"]').forEach(function(submenu) {
            submenu.classList.add('sidebar-submenu');
        });
    });
</script>
