<?php
// Ensure BASE_URL is defined
if (!defined('BASE_URL')) {
    require_once __DIR__ . '/../config/config.php';
}

$current_page = basename($_SERVER['PHP_SELF']);

// Helper function for active menu item
function employeeIsActive($page)
{
    global $current_page;
    return $current_page === $page ? 'bg-gray-100 dark:bg-gray-700 text-gray-900 dark:text-white' : '';
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

        <nav class="flex-1 px-2.5 py-3 space-y-0.5 overflow-y-auto overflow-x-hidden">

            <!-- ═══════════════════════════════════════ -->
            <!-- 1) DASHBOARD                            -->
            <!-- ═══════════════════════════════════════ -->
            <a href="<?php echo BASE_URL; ?>/employee/employee-dashboard.php" class="sidebar-item flex items-center px-3 py-2 text-gray-700 dark:text-gray-300 rounded-md transition-all duration-200 <?php echo $current_page === 'employee-dashboard.php' || strpos($current_page, 'dashboard') !== false ? 'active' : ''; ?>">
                <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="w-5 h-5 mr-3 flex-shrink-0"><rect width="7" height="9" x="3" y="3" rx="1"></rect><rect width="7" height="5" x="14" y="3" rx="1"></rect><rect width="7" height="9" x="14" y="12" rx="1"></rect><rect width="7" height="5" x="3" y="16" rx="1"></rect></svg>
                <span class="sidebar-text text-sm">Dashboard</span>
            </a>

            <!-- ═══════════════════════════════════════ -->
            <!-- 2) WORKFORCE & TIME MANAGEMENT          -->
            <!-- ═══════════════════════════════════════ -->
            <div class="mb-3 mt-4">
            <!-- Attendance Management ▼ -->
            <button
                type="button"
                class="sidebar-item w-full flex items-center justify-between px-3 py-2 text-gray-700 dark:text-gray-300 rounded-md transition-all duration-200"
                data-collapse-target="attendance-submenu"
            >
                <span class="flex items-center">
                    <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="w-5 h-5 mr-3 flex-shrink-0"><path d="M12 11c1.1 0 2-.9 2-2s-.9-2-2-2-2 .9-2 2 .9 2 2 2z"></path><path d="M12 21c-2.5-2.5-5-5-5-8 0-2.76 2.24-5 5-5s5 2.24 5 5c0 3-2.5 5.5-5 8z"></path><path d="M2 12h4"></path><path d="M18 12h4"></path><path d="M12 2v4"></path><path d="M12 18v4"></path></svg>
                    <span class="sidebar-text text-sm">Attendance Management</span>
                </span>
                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="w-4 h-4 ml-2 text-gray-400 transition-transform duration-200">
                    <polyline points="6 9 12 15 18 9"></polyline>
                </svg>
            </button>

            <div id="attendance-submenu" class="sidebar-submenu mt-1 space-y-1 hidden">
                <a href="<?php echo BASE_URL; ?>/employee/modules/employee-timeclock.php?view=attendance_log" class="flex items-center pl-11 pr-3 py-1.5 text-xs text-gray-600 dark:text-gray-400 rounded-md sidebar-item transition-all duration-200 <?php echo employeeIsActive('employee-timeclock.php') && (isset($_GET['view']) && $_GET['view'] === 'attendance_log') ? 'bg-gray-100 dark:bg-gray-700 text-gray-900 dark:text-white' : ''; ?>">
                    <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="w-3.5 h-3.5 mr-2 flex-shrink-0"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path><polyline points="14 2 14 8 20 8"></polyline><line x1="16" y1="13" x2="8" y2="13"></line><line x1="16" y1="17" x2="8" y2="17"></line></svg>
                    <span class="sidebar-text">My Attendance Logs</span>
                </a>
                <a href="<?php echo BASE_URL; ?>/employee/modules/employee-timeclock.php?view=correction_request" class="flex items-center pl-11 pr-3 py-1.5 text-xs text-gray-600 dark:text-gray-400 rounded-md sidebar-item transition-all duration-200 <?php echo employeeIsActive('employee-timeclock.php') && (isset($_GET['view']) && $_GET['view'] === 'correction_request') ? 'bg-gray-100 dark:bg-gray-700 text-gray-900 dark:text-white' : ''; ?>">
                    <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="w-3.5 h-3.5 mr-2 flex-shrink-0"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"></path><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"></path></svg>
                    <span class="sidebar-text">Missed Log / Time Correction Request</span>
                </a>
                <a href="<?php echo BASE_URL; ?>/employee/modules/employee-attendance_rules.php" class="flex items-center pl-11 pr-3 py-1.5 text-xs text-gray-600 dark:text-gray-400 rounded-md sidebar-item transition-all duration-200 <?php echo employeeIsActive('employee-attendance_rules.php') ? 'bg-gray-100 dark:bg-gray-700 text-gray-900 dark:text-white' : ''; ?>">
                    <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="w-3.5 h-3.5 mr-2 flex-shrink-0"><path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20"></path><path d="M6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15A2.5 2.5 0 0 1 6.5 2z"></path></svg>
                    <span class="sidebar-text">Attendance Rules</span>
                </a>
            </div>

            <!-- Shift & Schedule Management ▼ -->
            <button
                type="button"
                class="sidebar-item w-full flex items-center justify-between px-3 py-2 text-gray-700 dark:text-gray-300 rounded-md transition-all duration-200"
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

            <div id="duty-roster-submenu" class="sidebar-submenu mt-1 space-y-1 hidden">
                <a href="<?php echo BASE_URL; ?>/employee/modules/employee-my_schedules.php?view=weekly_view" class="flex items-center pl-11 pr-3 py-1.5 text-xs text-gray-600 dark:text-gray-400 rounded-md sidebar-item transition-all duration-200 <?php echo employeeIsActive('employee-my_schedules.php') && (isset($_GET['view']) && $_GET['view'] === 'weekly_view') ? 'bg-gray-100 dark:bg-gray-700 text-gray-900 dark:text-white' : ''; ?>">
                    <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="w-3.5 h-3.5 mr-2 flex-shrink-0"><rect x="3" y="4" width="18" height="18" rx="2"></rect><path d="M16 2v4"></path><path d="M8 2v4"></path><path d="M3 10h18"></path></svg>
                    <span class="sidebar-text">My Schedule</span>
                </a>
                <a href="<?php echo BASE_URL; ?>/employee/modules/employee-my_schedules.php?view=shift_details" class="flex items-center pl-11 pr-3 py-1.5 text-xs text-gray-600 dark:text-gray-400 rounded-md sidebar-item transition-all duration-200 <?php echo employeeIsActive('employee-my_schedules.php') && (isset($_GET['view']) && $_GET['view'] === 'shift_details') ? 'bg-gray-100 dark:bg-gray-700 text-gray-900 dark:text-white' : ''; ?>">
                    <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="w-3.5 h-3.5 mr-2 flex-shrink-0"><rect x="3" y="3" width="18" height="18" rx="2"></rect><path d="M3 9h18"></path><path d="M9 21V9"></path></svg>
                    <span class="sidebar-text">Shift Details</span>
                </a>
                <a href="<?php echo BASE_URL; ?>/employee/modules/employee-my_schedules.php?view=swap_cover" class="flex items-center pl-11 pr-3 py-1.5 text-xs text-gray-600 dark:text-gray-400 rounded-md sidebar-item transition-all duration-200 <?php echo employeeIsActive('employee-my_schedules.php') && (isset($_GET['view']) && $_GET['view'] === 'swap_cover') ? 'bg-gray-100 dark:bg-gray-700 text-gray-900 dark:text-white' : ''; ?>">
                    <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="w-3.5 h-3.5 mr-2 flex-shrink-0"><path d="M1 4v6h6"></path><path d="M23 20v-6h-6"></path><path d="M20.49 9A9 9 0 0 0 5.64 5.64L1 10m22 4l-4.64 4.36A9 9 0 0 1 3.51 15"></path></svg>
                    <span class="sidebar-text">Shift Swap Request</span>
                </a>
                <a href="<?php echo BASE_URL; ?>/employee/modules/employee-shift-request.php" class="flex items-center pl-11 pr-3 py-1.5 text-xs text-gray-600 dark:text-gray-400 rounded-md sidebar-item transition-all duration-200 <?php echo employeeIsActive('employee-shift-request.php') ? 'bg-gray-100 dark:bg-gray-700 text-gray-900 dark:text-white' : ''; ?>">
                    <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="w-3.5 h-3.5 mr-2 flex-shrink-0"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path><polyline points="14 2 14 8 20 8"></polyline><line x1="12" y1="18" x2="12" y2="12"></line><line x1="9" y1="15" x2="15" y2="15"></line></svg>
                    <span class="sidebar-text">Shift Change Request</span>
                </a>
                <a href="<?php echo BASE_URL; ?>/employee/modules/employee-overtime_requests.php" class="flex items-center pl-11 pr-3 py-1.5 text-xs text-gray-600 dark:text-gray-400 rounded-md sidebar-item transition-all duration-200 <?php echo employeeIsActive('employee-overtime_requests.php') ? 'bg-gray-100 dark:bg-gray-700 text-gray-900 dark:text-white' : ''; ?>">
                    <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="w-3.5 h-3.5 mr-2 flex-shrink-0"><circle cx="12" cy="12" r="10"></circle><polyline points="12 6 12 12 16 14"></polyline></svg>
                    <span class="sidebar-text">Overtime Request</span>
                </a>
            </div>

            <!-- Timesheets ▼ -->
            <button
                type="button"
                class="sidebar-item w-full flex items-center justify-between px-3 py-2 text-gray-700 dark:text-gray-300 rounded-md transition-all duration-200"
                data-collapse-target="timesheets-submenu"
            >
                <span class="flex items-center">
                    <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="w-5 h-5 mr-3 flex-shrink-0"><rect x="3" y="4" width="18" height="18" rx="2"></rect><path d="M3 10h18"></path><path d="M8 2v4"></path><path d="M16 2v4"></path></svg>
                    <span class="sidebar-text text-sm">Timesheets</span>
                </span>
                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="w-4 h-4 ml-2 text-gray-400 transition-transform duration-200">
                    <polyline points="6 9 12 15 18 9"></polyline>
                </svg>
            </button>

            <div id="timesheets-submenu" class="sidebar-submenu mt-1 space-y-1 hidden">
                <a href="<?php echo BASE_URL; ?>/employee/modules/employee-timesheets.php?view=current_cutoff" class="flex items-center pl-11 pr-3 py-1.5 text-xs text-gray-600 dark:text-gray-400 rounded-md sidebar-item transition-all duration-200 <?php echo employeeIsActive('employee-timesheets.php') && (isset($_GET['view']) && $_GET['view'] === 'current_cutoff') ? 'bg-gray-100 dark:bg-gray-700 text-gray-900 dark:text-white' : ''; ?>">
                    <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="w-3.5 h-3.5 mr-2 flex-shrink-0"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path><polyline points="14 2 14 8 20 8"></polyline><line x1="16" y1="13" x2="8" y2="13"></line><line x1="16" y1="17" x2="8" y2="17"></line></svg>
                    <span class="sidebar-text">My Timesheet (Current Cut-off)</span>
                </a>
                <a href="<?php echo BASE_URL; ?>/employee/modules/employee-timesheets.php?view=history" class="flex items-center pl-11 pr-3 py-1.5 text-xs text-gray-600 dark:text-gray-400 rounded-md sidebar-item transition-all duration-200 <?php echo employeeIsActive('employee-timesheets.php') && (isset($_GET['view']) && $_GET['view'] === 'history') ? 'bg-gray-100 dark:bg-gray-700 text-gray-900 dark:text-white' : ''; ?>">
                    <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="w-3.5 h-3.5 mr-2 flex-shrink-0"><circle cx="12" cy="12" r="10"></circle><polyline points="12 6 12 12 16 14"></polyline></svg>
                    <span class="sidebar-text">Timesheet History</span>
                </a>
                <a href="<?php echo BASE_URL; ?>/employee/modules/employee-timesheets.php?view=dispute" class="flex items-center pl-11 pr-3 py-1.5 text-xs text-gray-600 dark:text-gray-400 rounded-md sidebar-item transition-all duration-200 <?php echo employeeIsActive('employee-timesheets.php') && (isset($_GET['view']) && $_GET['view'] === 'dispute') ? 'bg-gray-100 dark:bg-gray-700 text-gray-900 dark:text-white' : ''; ?>">
                    <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="w-3.5 h-3.5 mr-2 flex-shrink-0"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"></path><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"></path></svg>
                    <span class="sidebar-text">Dispute Timesheet</span>
                </a>
                <a href="<?php echo BASE_URL; ?>/employee/modules/employee-timesheets.php?view=download" class="flex items-center pl-11 pr-3 py-1.5 text-xs text-gray-600 dark:text-gray-400 rounded-md sidebar-item transition-all duration-200 <?php echo employeeIsActive('employee-timesheets.php') && (isset($_GET['view']) && $_GET['view'] === 'download') ? 'bg-gray-100 dark:bg-gray-700 text-gray-900 dark:text-white' : ''; ?>">
                    <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="w-3.5 h-3.5 mr-2 flex-shrink-0"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path><polyline points="7 10 12 15 17 10"></polyline><line x1="12" y1="15" x2="12" y2="3"></line></svg>
                    <span class="sidebar-text">Download Timesheet (PDF/CSV)</span>
                </a>
            </div>

            <!-- Leave ▼ -->
            <button
                type="button"
                class="sidebar-item w-full flex items-center justify-between px-3 py-2 text-gray-700 dark:text-gray-300 rounded-md transition-all duration-200"
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

            <div id="leave-submenu" class="sidebar-submenu mt-1 space-y-1 hidden">
                <a href="<?php echo BASE_URL; ?>/employee/modules/employee-leave_management.php?view=apply_leave" class="flex items-center pl-11 pr-3 py-1.5 text-xs text-gray-600 dark:text-gray-400 rounded-md sidebar-item transition-all duration-200 <?php echo employeeIsActive('employee-leave_management.php') && (isset($_GET['view']) && $_GET['view'] === 'apply_leave') ? 'bg-gray-100 dark:bg-gray-700 text-gray-900 dark:text-white' : ''; ?>">
                    <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="w-3.5 h-3.5 mr-2 flex-shrink-0"><path d="M12 5v14"></path><path d="M5 12h14"></path></svg>
                    <span class="sidebar-text">Apply Leave</span>
                </a>
                <a href="<?php echo BASE_URL; ?>/employee/modules/employee-leave_management.php?view=leave_balances" class="flex items-center pl-11 pr-3 py-1.5 text-xs text-gray-600 dark:text-gray-400 rounded-md sidebar-item transition-all duration-200 <?php echo employeeIsActive('employee-leave_management.php') && (isset($_GET['view']) && $_GET['view'] === 'leave_balances') ? 'bg-gray-100 dark:bg-gray-700 text-gray-900 dark:text-white' : ''; ?>">
                    <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="w-3.5 h-3.5 mr-2 flex-shrink-0"><path d="M21 12V7H5a2 2 0 0 1 0-4h14v4"></path><path d="M3 5v14a2 2 0 0 0 2 2h16v-2"></path><path d="M18 12a2 2 0 0 0 0 4h4v-4Z"></path></svg>
                    <span class="sidebar-text">Leave Balance</span>
                </a>
                <a href="<?php echo BASE_URL; ?>/employee/modules/employee-leave_management.php?view=leave_status_history" class="flex items-center pl-11 pr-3 py-1.5 text-xs text-gray-600 dark:text-gray-400 rounded-md sidebar-item transition-all duration-200 <?php echo employeeIsActive('employee-leave_management.php') && (isset($_GET['view']) && $_GET['view'] === 'leave_status_history') ? 'bg-gray-100 dark:bg-gray-700 text-gray-900 dark:text-white' : ''; ?>">
                    <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="w-3.5 h-3.5 mr-2 flex-shrink-0"><circle cx="12" cy="12" r="10"></circle><polyline points="12 6 12 12 16 14"></polyline></svg>
                    <span class="sidebar-text">Leave Status/History</span>
                </a>
                <a href="<?php echo BASE_URL; ?>/employee/modules/employee-leave_management.php?view=upload_docs" class="flex items-center pl-11 pr-3 py-1.5 text-xs text-gray-600 dark:text-gray-400 rounded-md sidebar-item transition-all duration-200 <?php echo employeeIsActive('employee-leave_management.php') && (isset($_GET['view']) && $_GET['view'] === 'upload_docs') ? 'bg-gray-100 dark:bg-gray-700 text-gray-900 dark:text-white' : ''; ?>">
                    <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="w-3.5 h-3.5 mr-2 flex-shrink-0"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path><polyline points="17 8 12 3 7 8"></polyline><path d="M12 3v12"></path></svg>
                    <span class="sidebar-text">Upload Supporting Documents</span>
                </a>
            </div>

            <!-- Claims & Reimbursements ▼ -->
            <button
                type="button"
                class="sidebar-item w-full flex items-center justify-between px-3 py-2 text-gray-700 dark:text-gray-300 rounded-md transition-all duration-200"
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

            <div id="claims-submenu" class="sidebar-submenu mt-1 space-y-1 hidden">
                <a href="<?php echo BASE_URL; ?>/employee/modules/employee-claims.php?view=file_claim" class="flex items-center pl-11 pr-3 py-1.5 text-xs text-gray-600 dark:text-gray-400 rounded-md sidebar-item transition-all duration-200 <?php echo employeeIsActive('employee-claims.php') && (isset($_GET['view']) && $_GET['view'] === 'file_claim') ? 'bg-gray-100 dark:bg-gray-700 text-gray-900 dark:text-white' : ''; ?>">
                    <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="w-3.5 h-3.5 mr-2 flex-shrink-0"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path><polyline points="14 2 14 8 20 8"></polyline><path d="M12 18v-6"></path><path d="M9 15h6"></path></svg>
                    <span class="sidebar-text">New Claim</span>
                </a>
                <a href="<?php echo BASE_URL; ?>/employee/modules/employee-claims.php?view=claim_status_history" class="flex items-center pl-11 pr-3 py-1.5 text-xs text-gray-600 dark:text-gray-400 rounded-md sidebar-item transition-all duration-200 <?php echo employeeIsActive('employee-claims.php') && (isset($_GET['view']) && $_GET['view'] === 'claim_status_history') ? 'bg-gray-100 dark:bg-gray-700 text-gray-900 dark:text-white' : ''; ?>">
                    <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="w-3.5 h-3.5 mr-2 flex-shrink-0"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path><polyline points="14 2 14 8 20 8"></polyline><line x1="16" y1="13" x2="8" y2="13"></line><line x1="16" y1="17" x2="8" y2="17"></line></svg>
                    <span class="sidebar-text">My Claims Status</span>
                </a>
                <a href="<?php echo BASE_URL; ?>/employee/modules/employee-claims.php?view=claim_history" class="flex items-center pl-11 pr-3 py-1.5 text-xs text-gray-600 dark:text-gray-400 rounded-md sidebar-item transition-all duration-200 <?php echo employeeIsActive('employee-claims.php') && (isset($_GET['view']) && $_GET['view'] === 'claim_history') ? 'bg-gray-100 dark:bg-gray-700 text-gray-900 dark:text-white' : ''; ?>">
                    <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="w-3.5 h-3.5 mr-2 flex-shrink-0"><circle cx="12" cy="12" r="10"></circle><polyline points="12 6 12 12 16 14"></polyline></svg>
                    <span class="sidebar-text">Claim History</span>
                </a>
                <a href="<?php echo BASE_URL; ?>/employee/modules/employee-claims.php?view=payment_updates" class="flex items-center pl-11 pr-3 py-1.5 text-xs text-gray-600 dark:text-gray-400 rounded-md sidebar-item transition-all duration-200 <?php echo employeeIsActive('employee-claims.php') && (isset($_GET['view']) && $_GET['view'] === 'payment_updates') ? 'bg-gray-100 dark:bg-gray-700 text-gray-900 dark:text-white' : ''; ?>">
                    <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="w-3.5 h-3.5 mr-2 flex-shrink-0"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"></path><path d="M9 12l2 2 4-4"></path></svg>
                    <span class="sidebar-text">Payment Updates</span>
                </a>
            </div>

            </div>

            <!-- ═══════════════════════════════════════ -->
            <!-- 3) PROFILE & HELP                       -->
            <!-- ═══════════════════════════════════════ -->
            <div class="mb-3 mt-4">
                <h3 class="px-3 text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wider mb-2">Profile & Help</h3>
                <a href="<?php echo BASE_URL; ?>/employee/modules/employee-account_profile.php" class="sidebar-item flex items-center px-3 py-2 text-gray-700 dark:text-gray-300 rounded-md transition-all duration-200 <?php echo employeeIsActive('employee-account_profile.php') ? 'active' : ''; ?>">
                    <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="w-5 h-5 mr-3 flex-shrink-0"><path d="M19 21v-2a4 4 0 0 0-4-4H9a4 4 0 0 0-4 4v2"></path><circle cx="12" cy="7" r="4"></circle></svg>
                    <span class="sidebar-text text-sm">My Profile</span>
                </a>
                <a href="<?php echo BASE_URL; ?>/employee/modules/employee-hr_policies.php" class="sidebar-item flex items-center px-3 py-2 text-gray-700 dark:text-gray-300 rounded-md transition-all duration-200 <?php echo employeeIsActive('employee-hr_policies.php') ? 'active' : ''; ?>">
                    <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="w-5 h-5 mr-3 flex-shrink-0"><path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20"></path><path d="M6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15A2.5 2.5 0 0 1 6.5 2z"></path></svg>
                    <span class="sidebar-text text-sm">HR Policies & FAQs</span>
                </a>
                <a href="<?php echo BASE_URL; ?>/employee/modules/employee-support.php" class="sidebar-item flex items-center px-3 py-2 text-gray-700 dark:text-gray-300 rounded-md transition-all duration-200 <?php echo employeeIsActive('employee-support.php') ? 'active' : ''; ?>">
                    <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="w-5 h-5 mr-3 flex-shrink-0"><circle cx="12" cy="12" r="10"></circle><path d="M9.09 9a3 3 0 0 1 5.83 1c0 2-3 3-3 3"></path><path d="M12 17h.01"></path></svg>
                    <span class="sidebar-text text-sm">Support Ticket</span>
                </a>
            </div>

            <!-- ═══════════════════════════════════════ -->
            <!-- 4) REPORTS & ANALYTICS                  -->
            <!-- ═══════════════════════════════════════ -->
            <div class="mb-3 mt-4">
                <a href="<?php echo BASE_URL; ?>/employee/modules/employee-reports.php" class="sidebar-item flex items-center px-3 py-2 text-gray-700 dark:text-gray-300 rounded-md transition-all duration-200 <?php echo employeeIsActive('employee-reports.php') ? 'active' : ''; ?>">
                    <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="w-5 h-5 mr-3 flex-shrink-0"><line x1="12" y1="20" x2="12" y2="10"></line><line x1="18" y1="20" x2="18" y2="4"></line><line x1="6" y1="20" x2="6" y2="16"></line></svg>
                    <span class="sidebar-text text-sm">My Reports</span>
                </a>
            </div>

            <!-- ═══════════════════════════════════════ -->
            <!-- 5) AI ASSISTANT                         -->
            <!-- ═══════════════════════════════════════ -->
            <div class="mb-3 mt-4">
                <h3 class="px-3 text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wider mb-2">AI Assistant</h3>

                <a href="<?php echo BASE_URL; ?>/employee/modules/employee-ai_assistant.php" class="sidebar-item flex items-center px-3 py-2 text-gray-700 dark:text-gray-300 rounded-md transition-all duration-200 <?php echo employeeIsActive('employee-ai_assistant.php') ? 'active' : ''; ?>">
                    <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="w-5 h-5 mr-3 flex-shrink-0"><path d="M12 8V4H8"/><rect width="16" height="12" x="4" y="8" rx="2"/><path d="M2 14h2"/><path d="M20 14h2"/><path d="M15 13v2"/><path d="M9 13v2"/></svg>
                    <span class="sidebar-text text-sm">AI HR Assistant</span>
                </a>
            </div>

        </nav>

        <!-- Sidebar Footer - Empty for spacing -->
        <div class="p-3"></div>
    </div>
</aside>

<script>
    // Enhanced dropdown functionality for employee sidebar submodules
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
