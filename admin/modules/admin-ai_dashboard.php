<?php
/**
 * AI HR Dashboard - Admin Page
 * AI-powered insights, anomaly detection, analytics, and admin chat
 */
require_once __DIR__ . '/../../config/config.php';
requireAuth();
checkRole(['admin', 'super admin', 'hr_admin', 'supervisor', 'unit_head']);

$page_title = 'AI Dashboard';
$baseUrl = BASE_URL;
$employeeId = getCurrentEmployeeId($db) ?? $_SESSION['employee_id'] ?? $_SESSION['user_id'] ?? null;
$role = $_SESSION['role_name'] ?? $_SESSION['user_role'] ?? 'admin';
$userName = $_SESSION['full_name'] ?? $_SESSION['fname'] ?? 'Admin';

$view = $_GET['view'] ?? 'overview';

include __DIR__ . '/../../includes/header.php';
?>

<div class="mb-6">
    <h1 class="text-2xl font-semibold text-gray-900 dark:text-white flex items-center gap-3">
        <span class="inline-flex items-center justify-center w-10 h-10 rounded-xl bg-gradient-to-br from-violet-500 to-purple-600 text-white shadow-lg">
            <svg xmlns="http://www.w3.org/2000/svg" class="w-5 h-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 8V4H8"/><rect width="16" height="12" x="4" y="8" rx="2"/><path d="M2 14h2"/><path d="M20 14h2"/><path d="M15 13v2"/><path d="M9 13v2"/></svg>
        </span>
        AI HR Dashboard
    </h1>
    <p class="mt-1 text-sm text-gray-600 dark:text-gray-400">Intelligent insights, anomaly detection, and workforce analytics powered by AI</p>
</div>

<!-- View Tabs -->
<div class="mb-6 border-b border-gray-200 dark:border-gray-700">
    <nav class="flex space-x-6 -mb-px">
        <?php
        $tabs = [
            'overview' => ['AI Overview', '<path d="M12 8V4H8"/><rect width="16" height="12" x="4" y="8" rx="2"/><path d="M2 14h2"/><path d="M20 14h2"/><path d="M15 13v2"/><path d="M9 13v2"/>'],
            'insights' => ['Smart Insights', '<circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/>'],
            'anomalies' => ['Anomaly Detection', '<path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/>'],
            'analytics' => ['Workforce Analytics', '<line x1="12" y1="20" x2="12" y2="10"/><line x1="18" y1="20" x2="18" y2="4"/><line x1="6" y1="20" x2="6" y2="16"/>'],
            'chat' => ['AI Chat', '<path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/>'],
        ];
        foreach ($tabs as $key => $info):
            $active = $view === $key;
        ?>
        <a href="?view=<?php echo $key; ?>" class="inline-flex items-center gap-2 px-1 py-3 text-sm font-medium border-b-2 transition-colors <?php echo $active ? 'border-violet-600 text-violet-600 dark:border-violet-400 dark:text-violet-400' : 'border-transparent text-gray-500 dark:text-gray-400 hover:text-gray-700 dark:hover:text-gray-300 hover:border-gray-300'; ?>">
            <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><?php echo $info[1]; ?></svg>
            <?php echo $info[0]; ?>
        </a>
        <?php endforeach; ?>
    </nav>
</div>

<!-- ═══════════════════════════════════════ -->
<!-- OVERVIEW VIEW                           -->
<!-- ═══════════════════════════════════════ -->
<?php if ($view === 'overview'): ?>
<div class="space-y-6">
    <!-- Stats Row -->
    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4" id="overviewStats">
        <div class="bg-white dark:bg-gray-800 rounded-xl shadow-sm border border-gray-200 dark:border-gray-700 p-5">
            <div class="flex items-center gap-3">
                <div class="w-10 h-10 rounded-lg bg-green-100 dark:bg-green-900/30 flex items-center justify-center">
                    <svg xmlns="http://www.w3.org/2000/svg" class="w-5 h-5 text-green-600 dark:text-green-400" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
                </div>
                <div>
                    <p class="text-xs text-gray-500 dark:text-gray-400">AI Status</p>
                    <p class="text-lg font-bold text-green-600 dark:text-green-400"><?php echo AI_USE_LLM ? 'LLM Active' : 'Smart Mode'; ?></p>
                </div>
            </div>
        </div>
        <div class="bg-white dark:bg-gray-800 rounded-xl shadow-sm border border-gray-200 dark:border-gray-700 p-5">
            <div class="flex items-center gap-3">
                <div class="w-10 h-10 rounded-lg bg-orange-100 dark:bg-orange-900/30 flex items-center justify-center">
                    <svg xmlns="http://www.w3.org/2000/svg" class="w-5 h-5 text-orange-600 dark:text-orange-400" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>
                </div>
                <div>
                    <p class="text-xs text-gray-500 dark:text-gray-400">Active Alerts</p>
                    <p class="text-lg font-bold text-gray-900 dark:text-white" id="alertCount">--</p>
                </div>
            </div>
        </div>
        <div class="bg-white dark:bg-gray-800 rounded-xl shadow-sm border border-gray-200 dark:border-gray-700 p-5">
            <div class="flex items-center gap-3">
                <div class="w-10 h-10 rounded-lg bg-blue-100 dark:bg-blue-900/30 flex items-center justify-center">
                    <svg xmlns="http://www.w3.org/2000/svg" class="w-5 h-5 text-blue-600 dark:text-blue-400" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
                </div>
                <div>
                    <p class="text-xs text-gray-500 dark:text-gray-400">Anomalies Detected</p>
                    <p class="text-lg font-bold text-gray-900 dark:text-white" id="anomalyCount">--</p>
                </div>
            </div>
        </div>
        <div class="bg-white dark:bg-gray-800 rounded-xl shadow-sm border border-gray-200 dark:border-gray-700 p-5">
            <div class="flex items-center gap-3">
                <div class="w-10 h-10 rounded-lg bg-violet-100 dark:bg-violet-900/30 flex items-center justify-center">
                    <svg xmlns="http://www.w3.org/2000/svg" class="w-5 h-5 text-violet-600 dark:text-violet-400" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="12" y1="20" x2="12" y2="10"/><line x1="18" y1="20" x2="18" y2="4"/><line x1="6" y1="20" x2="6" y2="16"/></svg>
                </div>
                <div>
                    <p class="text-xs text-gray-500 dark:text-gray-400">Data Sources</p>
                    <p class="text-lg font-bold text-gray-900 dark:text-white">5 Modules</p>
                </div>
            </div>
        </div>
    </div>

    <!-- Top Insights + Anomalies Side by Side -->
    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
        <!-- Top Insights -->
        <div class="bg-white dark:bg-gray-800 rounded-xl shadow-sm border border-gray-200 dark:border-gray-700">
            <div class="px-5 py-4 border-b border-gray-200 dark:border-gray-700 flex items-center justify-between">
                <h3 class="font-semibold text-gray-900 dark:text-white flex items-center gap-2">
                    <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4 text-yellow-500" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M9.663 17h4.673M12 3v1m6.364 1.636l-.707.707M21 12h-1M4 12H3m3.343-5.657l-.707-.707m2.828 9.9a5 5 0 117.072 0l-.548.547A3.374 3.374 0 0014 18.469V19a2 2 0 11-4 0v-.531c0-.895-.356-1.754-.988-2.386l-.548-.547z"/></svg>
                    Smart Insights
                </h3>
                <a href="?view=insights" class="text-xs text-violet-600 dark:text-violet-400 hover:underline">View all</a>
            </div>
            <div id="overviewInsights" class="p-5 space-y-3">
                <div class="animate-pulse space-y-3">
                    <div class="h-16 bg-gray-100 dark:bg-gray-700 rounded-lg"></div>
                    <div class="h-16 bg-gray-100 dark:bg-gray-700 rounded-lg"></div>
                </div>
            </div>
        </div>

        <!-- Top Anomalies -->
        <div class="bg-white dark:bg-gray-800 rounded-xl shadow-sm border border-gray-200 dark:border-gray-700">
            <div class="px-5 py-4 border-b border-gray-200 dark:border-gray-700 flex items-center justify-between">
                <h3 class="font-semibold text-gray-900 dark:text-white flex items-center gap-2">
                    <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4 text-red-500" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>
                    Anomaly Detection
                </h3>
                <a href="?view=anomalies" class="text-xs text-violet-600 dark:text-violet-400 hover:underline">View all</a>
            </div>
            <div id="overviewAnomalies" class="p-5 space-y-3">
                <div class="animate-pulse space-y-3">
                    <div class="h-16 bg-gray-100 dark:bg-gray-700 rounded-lg"></div>
                    <div class="h-16 bg-gray-100 dark:bg-gray-700 rounded-lg"></div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- ═══════════════════════════════════════ -->
<!-- INSIGHTS VIEW                           -->
<!-- ═══════════════════════════════════════ -->
<?php elseif ($view === 'insights'): ?>
<div class="bg-white dark:bg-gray-800 rounded-xl shadow-sm border border-gray-200 dark:border-gray-700">
    <div class="px-5 py-4 border-b border-gray-200 dark:border-gray-700 flex items-center justify-between">
        <h3 class="font-semibold text-gray-900 dark:text-white">AI-Generated Insights</h3>
        <button onclick="loadInsights()" class="px-3 py-1.5 text-xs rounded-lg bg-violet-100 dark:bg-violet-900/30 text-violet-700 dark:text-violet-300 hover:bg-violet-200 dark:hover:bg-violet-800/40 transition-colors">
            Refresh
        </button>
    </div>
    <div id="insightsList" class="p-5 space-y-4">
        <div class="animate-pulse space-y-4">
            <div class="h-20 bg-gray-100 dark:bg-gray-700 rounded-lg"></div>
            <div class="h-20 bg-gray-100 dark:bg-gray-700 rounded-lg"></div>
            <div class="h-20 bg-gray-100 dark:bg-gray-700 rounded-lg"></div>
        </div>
    </div>
</div>

<!-- ═══════════════════════════════════════ -->
<!-- ANOMALIES VIEW                          -->
<!-- ═══════════════════════════════════════ -->
<?php elseif ($view === 'anomalies'): ?>
<div class="bg-white dark:bg-gray-800 rounded-xl shadow-sm border border-gray-200 dark:border-gray-700">
    <div class="px-5 py-4 border-b border-gray-200 dark:border-gray-700 flex items-center justify-between">
        <h3 class="font-semibold text-gray-900 dark:text-white">Anomaly Detection Results</h3>
        <button onclick="loadAnomalies()" class="px-3 py-1.5 text-xs rounded-lg bg-red-100 dark:bg-red-900/30 text-red-700 dark:text-red-300 hover:bg-red-200 dark:hover:bg-red-800/40 transition-colors">
            Re-scan
        </button>
    </div>
    <div id="anomaliesList" class="p-5 space-y-4">
        <div class="animate-pulse space-y-4">
            <div class="h-20 bg-gray-100 dark:bg-gray-700 rounded-lg"></div>
            <div class="h-20 bg-gray-100 dark:bg-gray-700 rounded-lg"></div>
        </div>
    </div>
</div>

<!-- ═══════════════════════════════════════ -->
<!-- ANALYTICS VIEW                          -->
<!-- ═══════════════════════════════════════ -->
<?php elseif ($view === 'analytics'): ?>
<div class="space-y-6">
    <!-- Attendance Rate Card -->
    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
        <div class="bg-white dark:bg-gray-800 rounded-xl shadow-sm border border-gray-200 dark:border-gray-700 p-5">
            <h3 class="font-semibold text-gray-900 dark:text-white mb-4 flex items-center gap-2">
                <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4 text-green-500" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
                Attendance Rate (Last 30 Days)
            </h3>
            <div id="attendanceRateChart" class="flex items-center justify-center" style="height: 220px;">
                <div class="animate-pulse w-full h-full bg-gray-100 dark:bg-gray-700 rounded-lg"></div>
            </div>
        </div>

        <!-- Leave Utilization -->
        <div class="bg-white dark:bg-gray-800 rounded-xl shadow-sm border border-gray-200 dark:border-gray-700 p-5">
            <h3 class="font-semibold text-gray-900 dark:text-white mb-4 flex items-center gap-2">
                <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4 text-blue-500" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
                Leave Utilization
            </h3>
            <div id="leaveUtilChart" class="space-y-3">
                <div class="animate-pulse space-y-3">
                    <div class="h-8 bg-gray-100 dark:bg-gray-700 rounded"></div>
                    <div class="h-8 bg-gray-100 dark:bg-gray-700 rounded"></div>
                    <div class="h-8 bg-gray-100 dark:bg-gray-700 rounded"></div>
                </div>
            </div>
        </div>
    </div>

    <!-- OT by Unit + Claims by Category -->
    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
        <div class="bg-white dark:bg-gray-800 rounded-xl shadow-sm border border-gray-200 dark:border-gray-700 p-5">
            <h3 class="font-semibold text-gray-900 dark:text-white mb-4 flex items-center gap-2">
                <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4 text-orange-500" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
                Overtime by Department (30 Days)
            </h3>
            <div id="otByUnitChart" class="space-y-3">
                <div class="animate-pulse space-y-3">
                    <div class="h-8 bg-gray-100 dark:bg-gray-700 rounded"></div>
                    <div class="h-8 bg-gray-100 dark:bg-gray-700 rounded"></div>
                </div>
            </div>
        </div>

        <div class="bg-white dark:bg-gray-800 rounded-xl shadow-sm border border-gray-200 dark:border-gray-700 p-5">
            <h3 class="font-semibold text-gray-900 dark:text-white mb-4 flex items-center gap-2">
                <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4 text-emerald-500" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="12" y1="1" x2="12" y2="23"/><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/></svg>
                Claims by Category (90 Days)
            </h3>
            <div id="claimsByCatChart" class="space-y-3">
                <div class="animate-pulse space-y-3">
                    <div class="h-8 bg-gray-100 dark:bg-gray-700 rounded"></div>
                    <div class="h-8 bg-gray-100 dark:bg-gray-700 rounded"></div>
                </div>
            </div>
        </div>
    </div>

    <!-- Attendance Trend -->
    <div class="bg-white dark:bg-gray-800 rounded-xl shadow-sm border border-gray-200 dark:border-gray-700 p-5">
        <h3 class="font-semibold text-gray-900 dark:text-white mb-4 flex items-center gap-2">
            <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4 text-violet-500" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/></svg>
            Daily Attendance Trend (14 Days)
        </h3>
        <div id="attendanceTrendChart" style="height: 200px;">
            <div class="animate-pulse w-full h-full bg-gray-100 dark:bg-gray-700 rounded-lg"></div>
        </div>
    </div>
</div>

<!-- ═══════════════════════════════════════ -->
<!-- CHAT VIEW (Admin)                       -->
<!-- ═══════════════════════════════════════ -->
<?php elseif ($view === 'chat'): ?>
<div class="grid grid-cols-1 lg:grid-cols-4 gap-6">
    <div class="lg:col-span-3">
        <div class="bg-white dark:bg-gray-800 rounded-xl shadow-sm border border-gray-200 dark:border-gray-700 flex flex-col" style="height: 65vh; min-height: 450px;">
            <div class="px-5 py-3 border-b border-gray-200 dark:border-gray-700 flex items-center gap-3">
                <div class="w-8 h-8 rounded-full bg-gradient-to-br from-violet-500 to-purple-600 flex items-center justify-center text-white">
                    <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 8V4H8"/><rect width="16" height="12" x="4" y="8" rx="2"/><path d="M2 14h2"/><path d="M20 14h2"/><path d="M15 13v2"/><path d="M9 13v2"/></svg>
                </div>
                <div>
                    <h3 class="text-sm font-semibold text-gray-900 dark:text-white">HR AI Assistant (Admin)</h3>
                    <p class="text-xs text-green-600">Online</p>
                </div>
                <div class="ml-auto">
                    <button onclick="clearAdminChat()" class="text-gray-400 hover:text-gray-600 p-1 rounded-md hover:bg-gray-100 dark:hover:bg-gray-700" title="Clear chat">
                        <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 6h18"/><path d="M19 6v14c0 1-1 2-2 2H7c-1 0-2-1-2-2V6"/><path d="M8 6V4c0-1 1-2 2-2h4c1 0 2 1 2 2v2"/></svg>
                    </button>
                </div>
            </div>
            <div id="adminChatMessages" class="flex-1 overflow-y-auto px-5 py-4 space-y-4">
                <div class="flex items-start gap-3">
                    <div class="w-8 h-8 rounded-full bg-gradient-to-br from-violet-500 to-purple-600 flex items-center justify-center text-white flex-shrink-0">
                        <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 8V4H8"/><rect width="16" height="12" x="4" y="8" rx="2"/><path d="M2 14h2"/><path d="M20 14h2"/><path d="M15 13v2"/><path d="M9 13v2"/></svg>
                    </div>
                    <div class="max-w-[80%] bg-gray-100 dark:bg-gray-700 rounded-2xl rounded-tl-md px-4 py-3">
                        <p class="text-sm text-gray-800 dark:text-gray-200">
                            Hello, <strong><?php echo htmlspecialchars($userName); ?></strong>! As your admin AI assistant, I can help with workforce insights, policy queries, and employee data analysis. What would you like to know?
                        </p>
                    </div>
                </div>
            </div>
            <div class="px-5 py-3 border-t border-gray-200 dark:border-gray-700">
                <form id="adminChatForm" class="flex gap-3">
                    <input type="text" id="adminChatInput" placeholder="Ask about workforce insights, policies, or data..." 
                        class="flex-1 px-4 py-2.5 rounded-xl border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-700 text-gray-900 dark:text-white placeholder-gray-400 focus:ring-2 focus:ring-violet-500 focus:border-transparent text-sm"
                        autocomplete="off" maxlength="2000">
                    <button type="submit" id="adminSendBtn" class="inline-flex items-center px-4 py-2.5 rounded-xl bg-gradient-to-r from-violet-600 to-purple-600 text-white font-medium text-sm hover:from-violet-700 hover:to-purple-700 transition-all shadow-sm disabled:opacity-50">
                        <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4 mr-1.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="22" y1="2" x2="11" y2="13"/><polygon points="22 2 15 22 11 13 2 9 22 2"/></svg>
                        Send
                    </button>
                </form>
            </div>
        </div>
    </div>
    <!-- Admin Quick Actions -->
    <div class="space-y-4">
        <div class="bg-white dark:bg-gray-800 rounded-xl shadow-sm border border-gray-200 dark:border-gray-700 p-4">
            <h3 class="text-sm font-semibold text-gray-900 dark:text-white mb-3">Admin Quick Queries</h3>
            <div class="space-y-2">
                <button onclick="adminQuickAsk('Show attendance summary this month')" class="w-full text-left px-3 py-2 rounded-lg text-xs text-gray-700 dark:text-gray-300 bg-gray-50 dark:bg-gray-700/50 hover:bg-violet-50 dark:hover:bg-violet-900/20 transition-colors">Attendance summary</button>
                <button onclick="adminQuickAsk('How many leave requests are pending?')" class="w-full text-left px-3 py-2 rounded-lg text-xs text-gray-700 dark:text-gray-300 bg-gray-50 dark:bg-gray-700/50 hover:bg-violet-50 dark:hover:bg-violet-900/20 transition-colors">Pending leave requests</button>
                <button onclick="adminQuickAsk('What claims are pending approval?')" class="w-full text-left px-3 py-2 rounded-lg text-xs text-gray-700 dark:text-gray-300 bg-gray-50 dark:bg-gray-700/50 hover:bg-violet-50 dark:hover:bg-violet-900/20 transition-colors">Pending claims</button>
                <button onclick="adminQuickAsk('Show policy rates and settings')" class="w-full text-left px-3 py-2 rounded-lg text-xs text-gray-700 dark:text-gray-300 bg-gray-50 dark:bg-gray-700/50 hover:bg-violet-50 dark:hover:bg-violet-900/20 transition-colors">Policy rates</button>
                <button onclick="adminQuickAsk('How many timesheets need review?')" class="w-full text-left px-3 py-2 rounded-lg text-xs text-gray-700 dark:text-gray-300 bg-gray-50 dark:bg-gray-700/50 hover:bg-violet-50 dark:hover:bg-violet-900/20 transition-colors">Timesheets for review</button>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<script>
const BASE_URL = '<?php echo $baseUrl; ?>';
const currentView = '<?php echo $view; ?>';

// ── OVERVIEW + INSIGHTS + ANOMALIES LOADING ──

async function loadAllData() {
    try {
        const res = await fetch(`${BASE_URL}/api/ai/insights.php?type=all`);
        const data = await res.json();
        if (!data.success) return;

        if (currentView === 'overview') {
            renderOverviewInsights(data.insights || []);
            renderOverviewAnomalies(data.anomalies || []);
            document.getElementById('alertCount').textContent = (data.insights || []).length;
            document.getElementById('anomalyCount').textContent = (data.anomalies || []).length;
        }
        if (currentView === 'insights') renderInsightsList(data.insights || []);
        if (currentView === 'anomalies') renderAnomaliesList(data.anomalies || []);
        if (currentView === 'analytics') renderAnalytics(data.analytics || {});
    } catch (e) {
        console.error('Failed to load AI data:', e);
    }
}

function loadInsights() {
    document.getElementById('insightsList').innerHTML = '<div class="animate-pulse space-y-4"><div class="h-20 bg-gray-100 dark:bg-gray-700 rounded-lg"></div></div>';
    loadAllData();
}
function loadAnomalies() {
    document.getElementById('anomaliesList').innerHTML = '<div class="animate-pulse space-y-4"><div class="h-20 bg-gray-100 dark:bg-gray-700 rounded-lg"></div></div>';
    loadAllData();
}

const typeColors = { warning: 'orange', info: 'blue', action: 'violet', anomaly: 'red' };
const typeIcons = {
    warning: '<path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/>',
    info: '<circle cx="12" cy="12" r="10"/><path d="M12 16v-4"/><path d="M12 8h.01"/>',
    action: '<polyline points="22 11.08 12 20.56 2 11.08 12 1.6 22 11.08"/><polyline points="22 11.08 12 20.56 2 11.08"/>',
    anomaly: '<path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/>'
};

function insightCard(item, compact = false) {
    const c = typeColors[item.type] || 'gray';
    const icon = typeIcons[item.type] || typeIcons.info;
    const pri = item.priority === 'high' ? '<span class="text-[10px] px-1.5 py-0.5 rounded-full bg-red-100 dark:bg-red-900/30 text-red-700 dark:text-red-300 font-medium">HIGH</span>' : 
                item.priority === 'medium' ? '<span class="text-[10px] px-1.5 py-0.5 rounded-full bg-yellow-100 dark:bg-yellow-900/30 text-yellow-700 dark:text-yellow-300 font-medium">MEDIUM</span>' : 
                '<span class="text-[10px] px-1.5 py-0.5 rounded-full bg-gray-100 dark:bg-gray-700 text-gray-600 dark:text-gray-400 font-medium">LOW</span>';
    
    return `<div class="flex items-start gap-3 p-3 rounded-lg border border-${c}-200 dark:border-${c}-800 bg-${c}-50/50 dark:bg-${c}-900/10">
        <div class="w-8 h-8 rounded-lg bg-${c}-100 dark:bg-${c}-900/30 flex items-center justify-center flex-shrink-0">
            <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4 text-${c}-600 dark:text-${c}-400" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">${icon}</svg>
        </div>
        <div class="flex-1 min-w-0">
            <div class="flex items-center gap-2 mb-0.5">
                <h4 class="text-sm font-semibold text-gray-900 dark:text-white">${escHtml(item.title)}</h4>
                ${pri}
            </div>
            <p class="text-xs text-gray-600 dark:text-gray-400">${escHtml(item.message)}</p>
            ${!compact && item.action ? `<p class="text-xs text-${c}-700 dark:text-${c}-300 mt-1 font-medium">${escHtml(item.action)}</p>` : ''}
        </div>
    </div>`;
}

function anomalyCard(item) {
    const sevColors = { high: 'red', medium: 'orange', low: 'yellow' };
    const c = sevColors[item.severity] || 'gray';
    return `<div class="flex items-start gap-3 p-3 rounded-lg border border-${c}-200 dark:border-${c}-800 bg-${c}-50/50 dark:bg-${c}-900/10">
        <div class="w-8 h-8 rounded-lg bg-${c}-100 dark:bg-${c}-900/30 flex items-center justify-center flex-shrink-0">
            <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4 text-${c}-600 dark:text-${c}-400" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>
        </div>
        <div class="flex-1 min-w-0">
            <div class="flex items-center gap-2 mb-0.5">
                <span class="text-xs font-mono text-gray-500 dark:text-gray-400">${escHtml(item.type)}</span>
                <span class="text-[10px] px-1.5 py-0.5 rounded-full bg-${c}-100 dark:bg-${c}-900/30 text-${c}-700 dark:text-${c}-300 font-medium uppercase">${item.severity}</span>
            </div>
            <p class="text-sm font-medium text-gray-900 dark:text-white">${escHtml(item.employee || '')}</p>
            <p class="text-xs text-gray-600 dark:text-gray-400 mt-0.5">${escHtml(item.description)}</p>
            ${item.recommendation ? `<p class="text-xs text-${c}-700 dark:text-${c}-300 mt-1 font-medium">${escHtml(item.recommendation)}</p>` : ''}
        </div>
    </div>`;
}

function renderOverviewInsights(items) {
    const el = document.getElementById('overviewInsights');
    if (!items.length) { el.innerHTML = '<p class="text-sm text-gray-500 dark:text-gray-400 text-center py-4">No active insights. Everything looks good!</p>'; return; }
    el.innerHTML = items.slice(0, 3).map(i => insightCard(i, true)).join('');
}

function renderOverviewAnomalies(items) {
    const el = document.getElementById('overviewAnomalies');
    if (!items.length) { el.innerHTML = '<p class="text-sm text-green-600 dark:text-green-400 text-center py-4">No anomalies detected. All clear!</p>'; return; }
    el.innerHTML = items.slice(0, 3).map(i => anomalyCard(i)).join('');
}

function renderInsightsList(items) {
    const el = document.getElementById('insightsList');
    if (!items.length) { el.innerHTML = '<p class="text-sm text-gray-500 text-center py-8">No active insights at this time.</p>'; return; }
    el.innerHTML = items.map(i => insightCard(i)).join('');
}

function renderAnomaliesList(items) {
    const el = document.getElementById('anomaliesList');
    if (!items.length) { el.innerHTML = '<div class="text-center py-8"><svg xmlns="http://www.w3.org/2000/svg" class="w-12 h-12 mx-auto text-green-400 mb-3" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg><p class="text-sm text-green-600 dark:text-green-400 font-medium">No anomalies detected</p><p class="text-xs text-gray-500 mt-1">The AI has scanned all modules and found no issues.</p></div>'; return; }
    el.innerHTML = items.map(i => anomalyCard(i)).join('');
}

// ── ANALYTICS RENDERING ──

function renderAnalytics(data) {
    // Attendance Rate
    const att = data.attendance_rate;
    if (att && att.total > 0) {
        const rate = ((att.present / att.total) * 100).toFixed(1);
        const el = document.getElementById('attendanceRateChart');
        el.innerHTML = `
            <div class="text-center">
                <div class="relative w-40 h-40 mx-auto mb-4">
                    <svg class="w-full h-full" viewBox="0 0 36 36">
                        <path d="M18 2.0845 a 15.9155 15.9155 0 0 1 0 31.831 a 15.9155 15.9155 0 0 1 0 -31.831" fill="none" stroke="#e5e7eb" stroke-width="3"/>
                        <path d="M18 2.0845 a 15.9155 15.9155 0 0 1 0 31.831 a 15.9155 15.9155 0 0 1 0 -31.831" fill="none" stroke="#10b981" stroke-width="3" stroke-dasharray="${rate}, 100" stroke-linecap="round"/>
                    </svg>
                    <div class="absolute inset-0 flex items-center justify-center">
                        <span class="text-2xl font-bold text-gray-900 dark:text-white">${rate}%</span>
                    </div>
                </div>
                <div class="grid grid-cols-4 gap-2 text-xs">
                    <div><span class="block font-bold text-green-600">${att.present || 0}</span>Present</div>
                    <div><span class="block font-bold text-red-600">${att.absent || 0}</span>Absent</div>
                    <div><span class="block font-bold text-yellow-600">${att.half_day || 0}</span>Half Day</div>
                    <div><span class="block font-bold text-blue-600">${att.on_leave || 0}</span>On Leave</div>
                </div>
            </div>
        `;
    }

    // Leave Utilization
    const leave = data.leave_utilization;
    if (leave && leave.length) {
        const el = document.getElementById('leaveUtilChart');
        el.innerHTML = leave.map(l => {
            const total = parseFloat(l.total_entitlement) || 1;
            const used = parseFloat(l.total_used) || 0;
            const pending = parseFloat(l.total_pending) || 0;
            const pct = ((used / total) * 100).toFixed(0);
            return `<div>
                <div class="flex justify-between text-xs mb-1">
                    <span class="font-medium text-gray-700 dark:text-gray-300">${escHtml(l.leave_type)}</span>
                    <span class="text-gray-500">${used} / ${total} days (${pct}%)</span>
                </div>
                <div class="w-full h-3 bg-gray-200 dark:bg-gray-700 rounded-full overflow-hidden">
                    <div class="h-full bg-blue-500 rounded-full transition-all" style="width: ${Math.min(pct, 100)}%"></div>
                </div>
            </div>`;
        }).join('');
    } else {
        document.getElementById('leaveUtilChart').innerHTML = '<p class="text-xs text-gray-500 text-center py-4">No leave data available.</p>';
    }

    // OT by Unit
    const ot = data.ot_by_unit;
    if (ot && ot.length) {
        const maxOT = Math.max(...ot.map(o => parseFloat(o.total_ot) || 0));
        const el = document.getElementById('otByUnitChart');
        el.innerHTML = ot.map(o => {
            const val = parseFloat(o.total_ot) || 0;
            const pct = maxOT > 0 ? ((val / maxOT) * 100).toFixed(0) : 0;
            return `<div>
                <div class="flex justify-between text-xs mb-1">
                    <span class="font-medium text-gray-700 dark:text-gray-300">${escHtml(o.unit_name || 'Unassigned')}</span>
                    <span class="text-gray-500">${val.toFixed(1)} hrs (${o.employee_count} employees)</span>
                </div>
                <div class="w-full h-3 bg-gray-200 dark:bg-gray-700 rounded-full overflow-hidden">
                    <div class="h-full bg-orange-500 rounded-full transition-all" style="width: ${pct}%"></div>
                </div>
            </div>`;
        }).join('');
    } else {
        document.getElementById('otByUnitChart').innerHTML = '<p class="text-xs text-gray-500 text-center py-4">No overtime data.</p>';
    }

    // Claims by Category
    const claims = data.claims_by_category;
    if (claims && claims.length) {
        const maxClaim = Math.max(...claims.map(c => parseFloat(c.total_amount) || 0));
        const el = document.getElementById('claimsByCatChart');
        el.innerHTML = claims.map(c => {
            const val = parseFloat(c.total_amount) || 0;
            const pct = maxClaim > 0 ? ((val / maxClaim) * 100).toFixed(0) : 0;
            return `<div>
                <div class="flex justify-between text-xs mb-1">
                    <span class="font-medium text-gray-700 dark:text-gray-300">${escHtml(c.category_name || 'Other')}</span>
                    <span class="text-gray-500">PHP ${formatNum(val)} (${c.claim_count} claims)</span>
                </div>
                <div class="w-full h-3 bg-gray-200 dark:bg-gray-700 rounded-full overflow-hidden">
                    <div class="h-full bg-emerald-500 rounded-full transition-all" style="width: ${pct}%"></div>
                </div>
            </div>`;
        }).join('');
    } else {
        document.getElementById('claimsByCatChart').innerHTML = '<p class="text-xs text-gray-500 text-center py-4">No claims data.</p>';
    }

    // Attendance Trend
    const trend = data.attendance_trend;
    if (trend && trend.length) {
        const maxTotal = Math.max(...trend.map(t => parseInt(t.total) || 0));
        const el = document.getElementById('attendanceTrendChart');
        const barWidth = Math.max(Math.floor(100 / trend.length) - 2, 3);
        el.innerHTML = `
            <div class="flex items-end justify-between gap-1 h-full px-2">
                ${trend.map(t => {
                    const total = parseInt(t.total) || 1;
                    const present = parseInt(t.present) || 0;
                    const absent = parseInt(t.absent) || 0;
                    const hPres = maxTotal > 0 ? ((present / maxTotal) * 100).toFixed(0) : 0;
                    const hAbs = maxTotal > 0 ? ((absent / maxTotal) * 100).toFixed(0) : 0;
                    const day = new Date(t.date).toLocaleDateString('en-PH', { weekday: 'short', month: 'short', day: 'numeric' });
                    return `<div class="flex flex-col items-center flex-1" title="${day}: ${present} present, ${absent} absent">
                        <div class="w-full flex flex-col gap-0.5" style="height: 160px; justify-content: flex-end;">
                            <div class="w-full bg-green-500 rounded-t" style="height: ${hPres}%;" title="Present: ${present}"></div>
                            <div class="w-full bg-red-400 rounded-b" style="height: ${hAbs}%;" title="Absent: ${absent}"></div>
                        </div>
                        <span class="text-[9px] text-gray-500 mt-1 transform -rotate-45 origin-top-left whitespace-nowrap">${new Date(t.date).toLocaleDateString('en-PH', {month:'short', day:'numeric'})}</span>
                    </div>`;
                }).join('')}
            </div>
            <div class="flex items-center gap-4 mt-3 justify-center text-xs">
                <span class="flex items-center gap-1"><span class="w-3 h-3 bg-green-500 rounded"></span> Present</span>
                <span class="flex items-center gap-1"><span class="w-3 h-3 bg-red-400 rounded"></span> Absent</span>
            </div>
        `;
    }
}

// ── ADMIN CHAT ──

let adminHistory = [];
let adminProcessing = false;

<?php if ($view === 'chat'): ?>
document.getElementById('adminChatForm').addEventListener('submit', (e) => {
    e.preventDefault();
    const msg = document.getElementById('adminChatInput').value.trim();
    if (!msg || adminProcessing) return;
    adminSendMessage(msg);
});

function adminQuickAsk(q) {
    if (adminProcessing) return;
    document.getElementById('adminChatInput').value = q;
    adminSendMessage(q);
}

async function adminSendMessage(message) {
    adminProcessing = true;
    document.getElementById('adminSendBtn').disabled = true;
    document.getElementById('adminChatInput').value = '';

    appendAdminMsg('user', message);
    adminHistory.push({ role: 'user', content: message });

    const typingId = showAdminTyping();

    try {
        const res = await fetch(`${BASE_URL}/api/ai/chat.php`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ message, history: adminHistory.slice(-10) })
        });
        const data = await res.json();
        removeEl(typingId);
        appendAdminMsg('ai', data.success ? data.message : (data.message || 'Error'), data.source);
        if (data.success) adminHistory.push({ role: 'assistant', content: data.message });
    } catch (e) {
        removeEl(typingId);
        appendAdminMsg('ai', 'Network error.', 'error');
    }

    adminProcessing = false;
    document.getElementById('adminSendBtn').disabled = false;
    document.getElementById('adminChatInput').focus();
}

function appendAdminMsg(role, content, source = '') {
    const container = document.getElementById('adminChatMessages');
    const div = document.createElement('div');
    div.className = `flex items-start gap-3 ${role === 'user' ? 'justify-end' : ''} animate-fade-in`;
    
    if (role === 'user') {
        div.innerHTML = `<div class="max-w-[80%] bg-violet-600 text-white rounded-2xl rounded-tr-md px-4 py-3"><p class="text-sm">${escHtml(content)}</p></div>
        <div class="w-8 h-8 rounded-full bg-gray-300 dark:bg-gray-600 flex items-center justify-center flex-shrink-0"><svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4 text-gray-700 dark:text-gray-300" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg></div>`;
    } else {
        div.innerHTML = `<div class="w-8 h-8 rounded-full bg-gradient-to-br from-violet-500 to-purple-600 flex items-center justify-center text-white flex-shrink-0"><svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 8V4H8"/><rect width="16" height="12" x="4" y="8" rx="2"/><path d="M2 14h2"/><path d="M20 14h2"/><path d="M15 13v2"/><path d="M9 13v2"/></svg></div>
        <div class="max-w-[80%]"><div class="bg-gray-100 dark:bg-gray-700 rounded-2xl rounded-tl-md px-4 py-3"><div class="text-sm text-gray-800 dark:text-gray-200 ai-response-content">${formatMd(content)}</div></div></div>`;
    }
    container.appendChild(div);
    container.scrollTop = container.scrollHeight;
}

function showAdminTyping() {
    const id = 'atyp-' + Date.now();
    const container = document.getElementById('adminChatMessages');
    const div = document.createElement('div');
    div.id = id;
    div.className = 'flex items-start gap-3 animate-fade-in';
    div.innerHTML = `<div class="w-8 h-8 rounded-full bg-gradient-to-br from-violet-500 to-purple-600 flex items-center justify-center text-white flex-shrink-0"><svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 8V4H8"/><rect width="16" height="12" x="4" y="8" rx="2"/><path d="M2 14h2"/><path d="M20 14h2"/><path d="M15 13v2"/><path d="M9 13v2"/></svg></div>
    <div class="bg-gray-100 dark:bg-gray-700 rounded-2xl rounded-tl-md px-4 py-3"><div class="flex space-x-1.5"><div class="w-2 h-2 bg-gray-400 rounded-full animate-bounce" style="animation-delay:0ms"></div><div class="w-2 h-2 bg-gray-400 rounded-full animate-bounce" style="animation-delay:150ms"></div><div class="w-2 h-2 bg-gray-400 rounded-full animate-bounce" style="animation-delay:300ms"></div></div></div>`;
    container.appendChild(div);
    container.scrollTop = container.scrollHeight;
    return id;
}

function clearAdminChat() { adminHistory = []; const el = document.getElementById('adminChatMessages'); const first = el.children[0]; el.innerHTML = ''; if (first) el.appendChild(first); }
<?php endif; ?>

// ── UTILITIES ──

function removeEl(id) { const el = document.getElementById(id); if (el) el.remove(); }
function escHtml(t) { const d = document.createElement('div'); d.textContent = t; return d.innerHTML; }
function formatNum(n) { return parseFloat(n).toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 }); }

function formatMd(text) {
    let html = escHtml(text);
    html = html.replace(/\*\*(.*?)\*\*/g, '<strong>$1</strong>');
    html = html.replace(/(?<!\*)\*(?!\*)(.+?)(?<!\*)\*(?!\*)/g, '<em>$1</em>');
    
    if (html.includes('|') && html.includes('---')) {
        html = html.replace(/(\|.+\|\n\|[-|\s]+\|\n(?:\|.+\|\n?)+)/g, (match) => {
            const lines = match.trim().split('\n');
            if (lines.length < 3) return match;
            const headers = lines[0].split('|').filter(c => c.trim());
            const rows = lines.slice(2).map(l => l.split('|').filter(c => c.trim()));
            let table = '<table class="text-xs w-full mt-2 mb-2 border-collapse"><thead><tr>';
            headers.forEach(h => { table += `<th class="border border-gray-300 dark:border-gray-600 px-2 py-1 bg-gray-50 dark:bg-gray-600 font-semibold">${h.trim()}</th>`; });
            table += '</tr></thead><tbody>';
            rows.forEach(r => { table += '<tr>'; r.forEach(c => { table += `<td class="border border-gray-300 dark:border-gray-600 px-2 py-1">${c.trim()}</td>`; }); table += '</tr>'; });
            table += '</tbody></table>';
            return table;
        });
    }
    
    html = html.replace(/^- (.+)$/gm, '<li class="ml-4 list-disc">$1</li>');
    html = html.replace(/(<li[^>]*>.*<\/li>\n?)+/gs, '<ul class="space-y-0.5 my-1">$&</ul>');
    html = html.replace(/^\d+\. (.+)$/gm, '<li class="ml-4 list-decimal">$1</li>');
    html = html.replace(/\n\n/g, '<br><br>');
    html = html.replace(/\n/g, '<br>');
    return html;
}

// Animation style
const st = document.createElement('style');
st.textContent = '@keyframes fadeIn{from{opacity:0;transform:translateY(8px)}to{opacity:1;transform:translateY(0)}}.animate-fade-in{animation:fadeIn .3s ease-out forwards}.ai-response-content ul{list-style-type:disc;padding-left:1rem}.ai-response-content table{font-size:.75rem}';
document.head.appendChild(st);

// Auto-load data
document.addEventListener('DOMContentLoaded', loadAllData);
</script>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
