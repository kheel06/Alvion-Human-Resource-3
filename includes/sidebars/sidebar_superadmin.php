<?php
// Ensure BASE_URL is defined
if (!defined('BASE_URL')) {
    require_once __DIR__ . '/../config/config.php';
}

$current_page = basename($_SERVER['PHP_SELF']);

function superAdminIsActive($page)
{
    global $current_page;
    return $current_page === $page ? 'bg-gray-100 dark:bg-gray-700 text-gray-900 dark:text-white' : 'text-gray-700 dark:text-gray-300';
}
?>

<!-- Sidebar Overlay (Mobile) -->
<div id="sidebarOverlay" class="hidden fixed inset-0 bg-black bg-opacity-50 z-40 lg:hidden sidebar-transition"></div>

<aside id="sidebar" class="fixed lg:static inset-y-0 left-0 z-50 bg-white dark:bg-gray-800 shadow-xl sidebar-transition transform -translate-x-full lg:translate-x-0 transition-all duration-300">
    <div class="flex flex-col h-full">
        <!-- Logo -->
        <div class="flex items-center justify-center px-4 py-5 border-b border-gray-200 dark:border-gray-700">
            <div class="flex items-center justify-center sidebar-logo-full w-full">
                <img src="<?php echo BASE_URL; ?>/assets/img/alvion-logo-removebg.png" alt="Alvion" class="h-9 object-contain">
            </div>
            <div class="flex items-center justify-center sidebar-logo-collapsed hidden w-full">
                <img src="<?php echo BASE_URL; ?>/assets/img/alvion-logo-removebg.png" alt="Alvion" class="h-9 object-contain">
            </div>
        </div>

        <nav class="flex-1 px-2.5 py-4 space-y-1 overflow-y-auto">
            <!-- Dashboard -->
            <a href="<?php echo BASE_URL; ?>/super_admin/super_admin-dashboard.php" class="flex items-center px-3 py-2 rounded-md hover:bg-gray-100 dark:hover:bg-gray-700 sidebar-item transition-all duration-200 <?php echo $current_page === 'super_admin-dashboard.php' ? 'bg-gray-100 dark:bg-gray-700 text-gray-900 dark:text-white' : 'text-gray-700 dark:text-gray-300'; ?>">
                <svg xmlns="http://www.w3.org/2000/svg" class="w-5 h-5 mr-3" fill="none" viewBox="0 0 24 24" stroke="currentColor"><rect width="7" height="9" x="3" y="3" rx="1"/><rect width="7" height="5" x="14" y="3" rx="1"/><rect width="7" height="9" x="14" y="12" rx="1"/><rect width="7" height="5" x="3" y="16" rx="1"/></svg>
                Dashboard
            </a>

            <div class="mt-5">
                <p class="px-3 text-[11px] font-semibold uppercase tracking-widest text-gray-500 dark:text-gray-400">Global Controls</p>

                <a href="<?php echo BASE_URL; ?>/super_admin/modules/super_admin-user_&_roles_managment.php" class="flex items-center px-3 py-2 rounded-md hover:bg-gray-100 dark:hover:bg-gray-700 sidebar-item transition-all duration-200 <?php echo superAdminIsActive('super_admin-user_&_roles_managment.php'); ?>">
                    <svg xmlns="http://www.w3.org/2000/svg" class="w-5 h-5 mr-3" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path d="M16 21v-2a4 4 0 00-4-4H6a4 4 0 00-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 00-3-3.87"/><path d="M16 3.13a4 4 0 010 7.75"/></svg>
                    User & Roles
                </a>

                <a href="<?php echo BASE_URL; ?>/super_admin/modules/super_admin-hospital_departments.php" class="flex items-center px-3 py-2 rounded-md hover:bg-gray-100 dark:hover:bg-gray-700 sidebar-item transition-all duration-200 <?php echo superAdminIsActive('super_admin-hospital_departments.php'); ?>">
                    <svg xmlns="http://www.w3.org/2000/svg" class="w-5 h-5 mr-3" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path d="M3 21V7l9-4 9 4v14"/><path d="M13 13h4v8H7v-6h6z"/></svg>
                    Hospital Departments
                </a>

                <a href="<?php echo BASE_URL; ?>/super_admin/modules/super_admin-system_configuration.php" class="flex items-center px-3 py-2 rounded-md hover:bg-gray-100 dark:hover:bg-gray-700 sidebar-item transition-all duration-200 <?php echo superAdminIsActive('super_admin-system_configuration.php'); ?>">
                    <svg xmlns="http://www.w3.org/2000/svg" class="w-5 h-5 mr-3" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path d="M12.22 2h-.44a2 2 0 00-2 2v.18a2 2 0 01-1 1.73l-.43.25a2 2 0 01-2 0l-.15-.08a2 2 0 00-2.73.73l-.22.38a2 2 0 00.73 2.73l.15.1a2 2 0 011 1.72v.51a2 2 0 01-1 1.74l-.15.09a2 2 0 00-.73 2.73l.22.38a2 2 0 002.73.73l.15-.08a2 2 0 012 0l.43.25a2 2 0 011 1.73V20a2 2 0 002 2h.44a2 2 0 002-2v-.18a2 2 0 011-1.73l.43-.25a2 2 0 012 0l.15.08a2 2 0 002.73-.73l.22-.39a2 2 0 00-.73-2.73l-.15-.08a2 2 0 01-1-1.74v-.5a2 2 0 011-1.74l.15-.09a2 2 0 00.73-2.73l-.22-.38a2 2 0 00-2.73-.73l-.15.08a2 2 0 01-2 0l-.43-.25a2 2 0 01-1-1.73V4a2 2 0 00-2-2z"/><circle cx="12" cy="12" r="3"/></svg>
                    System Configuration
                </a>
            </div>

            <div class="mt-6">
                <p class="px-3 text-[11px] font-semibold uppercase tracking-widest text-gray-500 dark:text-gray-400">HR Operations</p>

                <a href="<?php echo BASE_URL; ?>/super_admin/modules/super_admin-attendance_management(global).php" class="flex items-center px-3 py-2 rounded-md hover:bg-gray-100 dark:hover:bg-gray-700 sidebar-item transition-all duration-200 <?php echo superAdminIsActive('super_admin-attendance_management(global).php'); ?>">
                    <svg xmlns="http://www.w3.org/2000/svg" class="w-5 h-5 mr-3" fill="none" viewBox="0 0 24 24" stroke="currentColor"><rect x="3" y="4" width="18" height="18" rx="2"/><path d="M16 2v4"/><path d="M8 2v4"/><path d="M3 10h18"/></svg>
                    Attendance Management (Global)
                </a>

                <a href="<?php echo BASE_URL; ?>/super_admin/modules/super_admin-shift_&_scheduling.php" class="flex items-center px-3 py-2 rounded-md hover:bg-gray-100 dark:hover:bg-gray-700 sidebar-item transition-all duration-200 <?php echo superAdminIsActive('super_admin-shift_&_scheduling.php'); ?>">
                    <svg xmlns="http://www.w3.org/2000/svg" class="w-5 h-5 mr-3" fill="none" viewBox="0 0 24 24" stroke="currentColor"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
                    Shift & Scheduling
                </a>

                <a href="<?php echo BASE_URL; ?>/super_admin/modules/super_admin-timesheets.php" class="flex items-center px-3 py-2 rounded-md hover:bg-gray-100 dark:hover:bg-gray-700 sidebar-item transition-all duration-200 <?php echo superAdminIsActive('super_admin-timesheets.php'); ?>">
                    <svg xmlns="http://www.w3.org/2000/svg" class="w-5 h-5 mr-3" fill="none" viewBox="0 0 24 24" stroke="currentColor"><rect x="3" y="4" width="18" height="18" rx="2"/><path d="M3 10h18"/><path d="M8 2v4"/><path d="M16 2v4"/></svg>
                    Timesheets
                </a>

                <a href="<?php echo BASE_URL; ?>/super_admin/modules/super_admin-leaves_&_overtime.php" class="flex items-center px-3 py-2 rounded-md hover:bg-gray-100 dark:hover:bg-gray-700 sidebar-item transition-all duration-200 <?php echo superAdminIsActive('super_admin-leaves_&_overtime.php'); ?>">
                    <svg xmlns="http://www.w3.org/2000/svg" class="w-5 h-5 mr-3" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path d="M3 3h18"/><path d="M8 3v18"/><path d="M3 8h10"/><path d="M3 13h7"/><path d="M3 18h4"/></svg>
                    Leaves & Overtime
                </a>

                <a href="<?php echo BASE_URL; ?>/super_admin/modules/super_admin-claims_&_reimbursements.php" class="flex items-center px-3 py-2 rounded-md hover:bg-gray-100 dark:hover:bg-gray-700 sidebar-item transition-all duration-200 <?php echo superAdminIsActive('super_admin-claims_&_reimbursements.php'); ?>">
                    <svg xmlns="http://www.w3.org/2000/svg" class="w-5 h-5 mr-3" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/><path d="M9 12l2 2 4-4"/></svg>
                    Claims & Reimbursements
                </a>

                <a href="<?php echo BASE_URL; ?>/super_admin/modules/super_admin-reporting.php" class="flex items-center px-3 py-2 rounded-md hover:bg-gray-100 dark:hover:bg-gray-700 sidebar-item transition-all duration-200 <?php echo superAdminIsActive('super_admin-reporting.php'); ?>">
                    <svg xmlns="http://www.w3.org/2000/svg" class="w-5 h-5 mr-3" fill="none" viewBox="0 0 24 24" stroke="currentColor"><line x1="12" y1="20" x2="12" y2="10"/><line x1="18" y1="20" x2="18" y2="4"/><line x1="6" y1="20" x2="6" y2="16"/></svg>
                    Reporting & Analytics
                </a>
            </div>

            <div class="mt-6">
                <p class="px-3 text-[11px] font-semibold uppercase tracking-widest text-gray-500 dark:text-gray-400">Security</p>

                <a href="<?php echo BASE_URL; ?>/super_admin/modules/super_admin-audit_logs.php" class="flex items-center px-3 py-2 rounded-md hover:bg-gray-100 dark:hover:bg-gray-700 sidebar-item transition-all duration-200 <?php echo superAdminIsActive('super_admin-audit_logs.php'); ?>">
                    <svg xmlns="http://www.w3.org/2000/svg" class="w-5 h-5 mr-3" fill="none" viewBox="0 0 24 24" stroke="currentColor"><rect width="8" height="4" x="8" y="2" rx="1"/><path d="M16 4h2a2 2 0 012 2v14a2 2 0 01-2 2H6a2 2 0 01-2-2V6a2 2 0 012-2h2"/><path d="M12 11h4"/><path d="M12 16h4"/><path d="M8 11h.01"/><path d="M8 16h.01"/></svg>
                    Audit Logs
                </a>

                <a href="<?php echo BASE_URL; ?>/super_admin/modules/super_admin-account_settings.php" class="flex items-center px-3 py-2 rounded-md hover:bg-gray-100 dark:hover:bg-gray-700 sidebar-item transition-all duration-200 <?php echo superAdminIsActive('super_admin-account_settings.php'); ?>">
                    <svg xmlns="http://www.w3.org/2000/svg" class="w-5 h-5 mr-3" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path d="M12 1v4"/><path d="M12 19v4"/><path d="M4.22 4.22 6.34 6.34"/><path d="M17.66 17.66l2.12 2.12"/><path d="M1 12h4"/><path d="M19 12h4"/><path d="M4.22 19.78 6.34 17.66"/><path d="M17.66 6.34l2.12-2.12"/><circle cx="12" cy="12" r="3"/></svg>
                    Account Settings
                </a>
            </div>
        </nav>

        <div class="p-3"></div>
    </div>
</aside>

