<?php
/**
 * HR3 AI Service
 * Provides intelligent HR assistance using LLM (OpenAI-compatible) with rule-based fallback.
 * 
 * Features:
 * - Natural language chat for HR queries
 * - Anomaly detection across attendance, claims, leave
 * - Predictive insights and trend analysis
 * - Auto-classification of claims
 * - Smart scheduling suggestions
 */

require_once __DIR__ . '/../config/ai_config.php';

class HrAiService
{
    private ?PDO $db;
    private ?int $employeeId;
    private ?string $role;

    public function __construct(?PDO $db, ?int $employeeId = null, ?string $role = null)
    {
        $this->db = $db;
        $this->employeeId = $employeeId;
        $this->role = $role ? strtolower(trim($role)) : 'employee';
    }

    // ─── CHAT ─────────────────────────────────────────────────────────

    /**
     * Process a chat message and return AI response
     */
    public function chat(string $message, array $history = []): array
    {
        $message = trim($message);
        if (empty($message)) {
            return ['success' => false, 'message' => 'Empty message'];
        }

        // Gather contextual data based on the query intent
        $context = $this->gatherContext($message);

        if (AI_USE_LLM) {
            return $this->chatWithLLM($message, $context, $history);
        }

        return $this->chatRuleBased($message, $context);
    }

    /**
     * Chat using OpenAI-compatible LLM API
     */
    private function chatWithLLM(string $message, array $context, array $history): array
    {
        $messages = [
            ['role' => 'system', 'content' => AI_SYSTEM_PROMPT]
        ];

        // Add context as a system message
        if (!empty($context)) {
            $contextStr = "Current employee/system context:\n" . json_encode($context, JSON_PRETTY_PRINT);
            $messages[] = ['role' => 'system', 'content' => $contextStr];
        }

        // Add conversation history (last 10 messages)
        foreach (array_slice($history, -10) as $h) {
            $messages[] = ['role' => $h['role'] ?? 'user', 'content' => $h['content'] ?? ''];
        }

        $messages[] = ['role' => 'user', 'content' => $message];

        $payload = [
            'model' => AI_MODEL,
            'messages' => $messages,
            'max_tokens' => AI_MAX_TOKENS,
            'temperature' => AI_TEMPERATURE,
        ];

        $ch = curl_init(AI_API_URL);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . AI_API_KEY,
            ],
            CURLOPT_POSTFIELDS => json_encode($payload),
            CURLOPT_TIMEOUT => 30,
            CURLOPT_SSL_VERIFYPEER => false,
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($error || $httpCode !== 200) {
            error_log("AI LLM error (HTTP {$httpCode}): {$error} | Response: " . substr($response, 0, 500));
            // Fallback to rule-based
            return $this->chatRuleBased($message, $context);
        }

        $data = json_decode($response, true);
        $reply = $data['choices'][0]['message']['content'] ?? null;

        if (!$reply) {
            return $this->chatRuleBased($message, $context);
        }

        return [
            'success' => true,
            'message' => $reply,
            'source' => 'ai',
            'model' => AI_MODEL,
        ];
    }

    /**
     * Rule-based chat fallback (works without API key)
     */
    private function chatRuleBased(string $message, array $context): array
    {
        $msg = strtolower($message);
        $reply = '';

        // ── Attendance queries
        if ($this->matchesIntent($msg, ['attendance', 'time in', 'time out', 'clock', 'punch', 'present', 'absent'])) {
            $reply = $this->handleAttendanceQuery($msg, $context);
        }
        // ── Leave queries
        elseif ($this->matchesIntent($msg, ['leave', 'vacation', 'sick leave', 'balance', 'day off', 'maternity', 'paternity'])) {
            $reply = $this->handleLeaveQuery($msg, $context);
        }
        // ── Claims queries
        elseif ($this->matchesIntent($msg, ['claim', 'reimbursement', 'expense', 'receipt', 'reimburse', 'disbursement'])) {
            $reply = $this->handleClaimsQuery($msg, $context);
        }
        // ── Schedule queries
        elseif ($this->matchesIntent($msg, ['schedule', 'shift', 'roster', 'duty', 'swap', 'overtime'])) {
            $reply = $this->handleScheduleQuery($msg, $context);
        }
        // ── Timesheet queries
        elseif ($this->matchesIntent($msg, ['timesheet', 'hours worked', 'pay period', 'cutoff', 'payroll'])) {
            $reply = $this->handleTimesheetQuery($msg, $context);
        }
        // ── Policy queries
        elseif ($this->matchesIntent($msg, ['policy', 'rule', 'regulation', 'labor law', 'entitlement', 'premium', 'night diff'])) {
            $reply = $this->handlePolicyQuery($msg, $context);
        }
        // ── Greeting
        elseif ($this->matchesIntent($msg, ['hello', 'hi', 'hey', 'good morning', 'good afternoon', 'good evening', 'kumusta'])) {
            $hour = (int) date('H');
            $greeting = $hour < 12 ? 'Good morning' : ($hour < 18 ? 'Good afternoon' : 'Good evening');
            $name = $context['employee']['name'] ?? 'there';
            $reply = "{$greeting}, {$name}! I'm your AI HR Assistant. I can help you with:\n\n";
            $reply .= "- **Attendance** - Check your attendance records, status, and exceptions\n";
            $reply .= "- **Leave** - View balances, request status, and PH leave policies\n";
            $reply .= "- **Claims** - Track your expense claims and reimbursements\n";
            $reply .= "- **Schedule** - View your shifts, rosters, and swap requests\n";
            $reply .= "- **Timesheets** - Check hours worked and submission status\n";
            $reply .= "- **Policies** - Learn about HR policies and labor regulations\n\n";
            $reply .= "What would you like to know?";
        }
        // ── Help
        elseif ($this->matchesIntent($msg, ['help', 'what can you do', 'capabilities', 'features'])) {
            $reply = "I'm an AI-powered HR Assistant that can help you with:\n\n";
            $reply .= "1. **Attendance** - \"How many days was I present this month?\", \"Show my attendance today\"\n";
            $reply .= "2. **Leave** - \"What's my leave balance?\", \"How many VL days do I have left?\"\n";
            $reply .= "3. **Claims** - \"What's the status of my claims?\", \"How much can I claim for meals?\"\n";
            $reply .= "4. **Schedule** - \"What's my shift today?\", \"Show my schedule this week\"\n";
            $reply .= "5. **Timesheets** - \"How many hours did I work this period?\", \"Is my timesheet submitted?\"\n";
            $reply .= "6. **Policies** - \"What's the OT premium rate?\", \"How many SL days am I entitled to?\"\n\n";
            $reply .= "Just ask me anything in plain language!";
        }
        // ── Default
        else {
            $reply = "I understand you're asking about: \"{$message}\"\n\n";
            $reply .= "I can help with **attendance**, **leave**, **claims**, **schedules**, **timesheets**, and **HR policies**. Could you rephrase your question or try one of these:\n\n";
            $reply .= "- \"What's my leave balance?\"\n";
            $reply .= "- \"Show my attendance this week\"\n";
            $reply .= "- \"What's the status of my claims?\"\n";
            $reply .= "- \"What shift am I assigned to?\"\n";
            $reply .= "- \"How many hours have I worked this period?\"";
        }

        return [
            'success' => true,
            'message' => $reply,
            'source' => 'rule_based',
        ];
    }

    // ─── INTENT-SPECIFIC HANDLERS ─────────────────────────────────────

    private function handleAttendanceQuery(string $msg, array $context): string
    {
        $att = $context['attendance'] ?? [];

        if (str_contains($msg, 'today') || str_contains($msg, 'status')) {
            $today = $att['today'] ?? null;
            if ($today) {
                $reply = "**Today's Attendance** (" . date('l, M d, Y') . ")\n\n";
                $reply .= "- Status: **" . strtoupper($today['status'] ?? 'N/A') . "**\n";
                if (!empty($today['time_in'])) $reply .= "- Time In: **{$today['time_in']}**\n";
                if (!empty($today['time_out'])) $reply .= "- Time Out: **{$today['time_out']}**\n";
                if (!empty($today['regular_hours'])) $reply .= "- Regular Hours: **{$today['regular_hours']}**\n";
                if (!empty($today['ot_hours']) && $today['ot_hours'] > 0) $reply .= "- Overtime: **{$today['ot_hours']} hrs**\n";
                return $reply;
            }
            return "No attendance record found for today. Have you clocked in via QR scan?";
        }

        if (str_contains($msg, 'this month') || str_contains($msg, 'month') || str_contains($msg, 'summary')) {
            $summary = $att['monthly_summary'] ?? null;
            if ($summary) {
                $reply = "**Attendance Summary - " . date('F Y') . "**\n\n";
                $reply .= "- Days Present: **{$summary['present_days']}**\n";
                $reply .= "- Days Absent: **{$summary['absent_days']}**\n";
                $reply .= "- Total Regular Hours: **" . number_format($summary['total_regular'], 1) . "**\n";
                $reply .= "- Total Overtime: **" . number_format($summary['total_ot'], 1) . " hrs**\n";
                if ($summary['total_late'] > 0) $reply .= "- Late Instances: **{$summary['total_late']}**\n";
                return $reply;
            }
        }

        if (str_contains($msg, 'exception') || str_contains($msg, 'missing') || str_contains($msg, 'anomal')) {
            $exceptions = $att['exceptions'] ?? [];
            if (!empty($exceptions)) {
                $reply = "**Attendance Exceptions** (pending):\n\n";
                foreach (array_slice($exceptions, 0, 5) as $e) {
                    $reply .= "- {$e['log_date']}: **{$e['exception_type']}** - {$e['details']}\n";
                }
                return $reply;
            }
            return "No pending attendance exceptions found. Your records look clean!";
        }

        // General attendance info
        $reply = "**Your Attendance Overview**\n\n";
        if (!empty($att['monthly_summary'])) {
            $s = $att['monthly_summary'];
            $reply .= "This month: **{$s['present_days']} days present**, {$s['total_regular']} regular hours\n";
        }
        if (!empty($att['today'])) {
            $reply .= "Today: **" . strtoupper($att['today']['status'] ?? 'N/A') . "**\n";
        }
        $reply .= "\nYou can ask me more specifically about today's status, monthly summary, or exceptions.";
        return $reply;
    }

    private function handleLeaveQuery(string $msg, array $context): string
    {
        $leave = $context['leave'] ?? [];

        if (str_contains($msg, 'balance') || str_contains($msg, 'remaining') || str_contains($msg, 'how many') || str_contains($msg, 'left')) {
            $balances = $leave['balances'] ?? [];
            if (!empty($balances)) {
                $reply = "**Your Leave Balances (" . date('Y') . ")**\n\n";
                foreach ($balances as $b) {
                    $avail = $b['entitlement'] - $b['used'] - $b['pending'];
                    $reply .= "- **{$b['leave_type']}**: {$avail} days available";
                    $reply .= " (Entitled: {$b['entitlement']}, Used: {$b['used']}, Pending: {$b['pending']})\n";
                }
                return $reply;
            }
            return "No leave balances found for the current year. Please contact HR to set up your entitlements.";
        }

        if (str_contains($msg, 'request') || str_contains($msg, 'pending') || str_contains($msg, 'status') || str_contains($msg, 'application')) {
            $requests = $leave['requests'] ?? [];
            if (!empty($requests)) {
                $reply = "**Your Leave Requests**\n\n";
                foreach (array_slice($requests, 0, 5) as $r) {
                    $reply .= "- **{$r['leave_type']}** ({$r['start_date']} to {$r['end_date']}): ";
                    $reply .= strtoupper($r['status']) . " ({$r['total_days']} days)\n";
                }
                return $reply;
            }
            return "You don't have any recent leave requests.";
        }

        // General leave info
        $reply = "**Leave Information**\n\n";
        $reply .= "Under PH labor law, employees are entitled to:\n";
        $reply .= "- **Service Incentive Leave (SIL)**: 5 days after 1 year of service\n";
        $reply .= "- **Vacation Leave (VL)**: As per company policy (typically 15 days)\n";
        $reply .= "- **Sick Leave (SL)**: As per company policy (typically 15 days)\n";
        $reply .= "- **Maternity Leave (ML)**: 105 days (RA 11210)\n";
        $reply .= "- **Paternity Leave (PL)**: 7 days (RA 8187)\n";
        $reply .= "- **Solo Parent Leave (SPL)**: 7 days (RA 8972)\n\n";
        $reply .= "Ask me about your **leave balance** or **request status** for personalized info.";
        return $reply;
    }

    private function handleClaimsQuery(string $msg, array $context): string
    {
        $claims = $context['claims'] ?? [];

        if (str_contains($msg, 'status') || str_contains($msg, 'pending') || str_contains($msg, 'my claim')) {
            $list = $claims['my_claims'] ?? [];
            if (!empty($list)) {
                $reply = "**Your Claims**\n\n";
                foreach (array_slice($list, 0, 5) as $c) {
                    $reply .= "- **{$c['category_name']}** - PHP " . number_format($c['amount'], 2);
                    $reply .= " | Status: **" . strtoupper($c['status']) . "**\n";
                }
                return $reply;
            }
            return "You don't have any claims on record. You can file a new claim from the Claims & Reimbursement page.";
        }

        if (str_contains($msg, 'category') || str_contains($msg, 'type') || str_contains($msg, 'limit') || str_contains($msg, 'how much')) {
            $cats = $claims['categories'] ?? [];
            if (!empty($cats)) {
                $reply = "**Claim Categories & Limits**\n\n";
                foreach ($cats as $c) {
                    $max = $c['max_amount'] ? 'PHP ' . number_format($c['max_amount'], 2) : 'No limit';
                    $reply .= "- **{$c['name']}** ({$c['code']}): Max {$max}";
                    $reply .= $c['requires_receipt'] ? " | Receipt required\n" : "\n";
                }
                return $reply;
            }
        }

        $reply = "**Claims & Reimbursement Info**\n\n";
        $reply .= "You can file claims for approved expense categories. Each category has a maximum claimable amount.\n\n";
        $reply .= "Ask me about:\n";
        $reply .= "- Your **claim status** and history\n";
        $reply .= "- Available **categories and limits**\n";
        $reply .= "- **How to file** a new claim";
        return $reply;
    }

    private function handleScheduleQuery(string $msg, array $context): string
    {
        $sched = $context['schedule'] ?? [];

        if (str_contains($msg, 'today') || str_contains($msg, 'shift today') || str_contains($msg, 'my shift')) {
            $today = $sched['today_shift'] ?? null;
            if ($today) {
                $reply = "**Today's Shift** (" . date('l, M d') . ")\n\n";
                $reply .= "- Shift: **{$today['shift_name']}**\n";
                $reply .= "- Time: **{$today['start_time']} - {$today['end_time']}**\n";
                if (!empty($today['break_minutes'])) $reply .= "- Break: {$today['break_minutes']} minutes\n";
                return $reply;
            }
            return "No shift assigned for today. You may be on a rest day or the roster hasn't been published yet.";
        }

        if (str_contains($msg, 'week') || str_contains($msg, 'this week') || str_contains($msg, 'schedule')) {
            $week = $sched['week_schedule'] ?? [];
            if (!empty($week)) {
                $reply = "**This Week's Schedule**\n\n";
                foreach ($week as $day) {
                    $dayName = date('D M d', strtotime($day['date']));
                    $reply .= "- **{$dayName}**: {$day['shift_name']} ({$day['start_time']} - {$day['end_time']})\n";
                }
                return $reply;
            }
            return "No schedule found for this week. The roster may not have been published yet.";
        }

        $reply = "**Schedule Information**\n\n";
        $reply .= "I can help you check your shift assignments. Ask me about:\n";
        $reply .= "- \"What's my **shift today**?\"\n";
        $reply .= "- \"Show my **schedule this week**\"\n";
        $reply .= "- \"Do I have any **swap requests**?\"";
        return $reply;
    }

    private function handleTimesheetQuery(string $msg, array $context): string
    {
        $ts = $context['timesheets'] ?? [];

        if (str_contains($msg, 'current') || str_contains($msg, 'this period') || str_contains($msg, 'hours')) {
            $current = $ts['current'] ?? null;
            if ($current) {
                $reply = "**Current Timesheet**\n\n";
                $reply .= "- Period: **{$current['period_start']}** to **{$current['period_end']}**\n";
                $reply .= "- Status: **" . strtoupper($current['status']) . "**\n";
                $reply .= "- Total Hours: **" . number_format($current['total_hours'], 1) . "**\n";
                $reply .= "- Overtime: **" . number_format($current['total_ot_hours'], 1) . " hrs**\n";
                $reply .= "- Night Diff: **" . number_format($current['total_nd_hours'], 1) . " hrs**\n";
                return $reply;
            }
            return "No current timesheet found. Timesheets may need to be generated by HR admin.";
        }

        $reply = "**Timesheet Information**\n\n";
        $reply .= "Ask me about your **current period hours**, **timesheet status**, or **recent timesheets**.";
        return $reply;
    }

    private function handlePolicyQuery(string $msg, array $context): string
    {
        $settings = $context['settings'] ?? [];

        $reply = "**HR Policy Summary**\n\n";
        $reply .= "| Policy | Value |\n|--------|-------|\n";
        $reply .= "| Standard Work Hours | " . ($settings['standard_work_hours'] ?? '8') . " hrs/day |\n";
        $reply .= "| Meal Break | " . ($settings['meal_break_mins'] ?? '60') . " minutes |\n";
        $reply .= "| OT Premium (Regular) | " . ($settings['ot_premium_regular'] ?? '1.25') . "x |\n";
        $reply .= "| Night Differential | " . (($settings['nd_rate'] ?? '0.10') * 100) . "% (10PM-6AM) |\n";
        $reply .= "| Rest Day Premium | " . ($settings['rest_day_premium'] ?? '1.30') . "x |\n";
        $reply .= "| Holiday Premium (Regular) | " . ($settings['holiday_premium_regular'] ?? '2.00') . "x |\n";
        $reply .= "| Holiday Premium (Special) | " . ($settings['holiday_premium_special'] ?? '1.30') . "x |\n\n";
        $reply .= "These rates follow Philippine labor law standards (Labor Code of the Philippines).";
        return $reply;
    }

    // ─── INSIGHTS & ANOMALY DETECTION ─────────────────────────────────

    /**
     * Get AI-powered insights for the dashboard
     */
    public function getInsights(): array
    {
        if (!$this->db) return [];

        $insights = [];

        // 1. Attendance anomalies
        try {
            $stmt = $this->db->query("
                SELECT COUNT(*) as cnt FROM attendance_exceptions 
                WHERE status = 'pending' AND created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
            ");
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (($row['cnt'] ?? 0) > 0) {
                $insights[] = [
                    'type' => 'warning',
                    'category' => 'attendance',
                    'title' => 'Unresolved Attendance Exceptions',
                    'message' => "{$row['cnt']} attendance exception(s) pending resolution in the last 7 days.",
                    'action' => 'Review exceptions in Attendance Management',
                    'priority' => 'high',
                ];
            }
        } catch (PDOException $e) {}

        // 2. Leave pattern analysis
        try {
            $stmt = $this->db->query("
                SELECT leave_type, COUNT(*) as cnt, SUM(total_days) as total_days
                FROM leave_requests 
                WHERE status IN ('pending', 'approved') 
                AND start_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 14 DAY)
                GROUP BY leave_type
            ");
            $upcoming = $stmt->fetchAll(PDO::FETCH_ASSOC);
            if (!empty($upcoming)) {
                $totalUpcoming = array_sum(array_column($upcoming, 'cnt'));
                $details = implode(', ', array_map(fn($u) => "{$u['cnt']} {$u['leave_type']}", $upcoming));
                $insights[] = [
                    'type' => 'info',
                    'category' => 'leave',
                    'title' => 'Upcoming Leave Forecast',
                    'message' => "{$totalUpcoming} leave request(s) in the next 14 days ({$details}).",
                    'action' => 'Plan staffing coverage accordingly',
                    'priority' => 'medium',
                ];
            }
        } catch (PDOException $e) {}

        // 3. Pending claims value
        try {
            $stmt = $this->db->query("
                SELECT COUNT(*) as cnt, COALESCE(SUM(amount), 0) as total 
                FROM claims WHERE status IN ('submitted', 'endorsed')
            ");
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (($row['cnt'] ?? 0) > 0) {
                $insights[] = [
                    'type' => 'action',
                    'category' => 'claims',
                    'title' => 'Pending Claims for Review',
                    'message' => "{$row['cnt']} claim(s) totaling PHP " . number_format($row['total'], 2) . " awaiting approval.",
                    'action' => 'Review in Claims & Reimbursement',
                    'priority' => 'medium',
                ];
            }
        } catch (PDOException $e) {}

        // 4. Overtime trend detection
        try {
            $stmt = $this->db->query("
                SELECT e.first_name, e.last_name, e.employee_number, 
                       SUM(da.ot_hours) as total_ot
                FROM daily_attendance da
                JOIN employees e ON da.employee_id = e.id
                WHERE da.date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
                GROUP BY da.employee_id, e.first_name, e.last_name, e.employee_number
                HAVING total_ot > 40
                ORDER BY total_ot DESC
                LIMIT 5
            ");
            $highOT = $stmt->fetchAll(PDO::FETCH_ASSOC);
            if (!empty($highOT)) {
                $names = implode(', ', array_map(fn($e) => "{$e['first_name']} {$e['last_name']} (" . number_format($e['total_ot'], 1) . "h)", $highOT));
                $insights[] = [
                    'type' => 'warning',
                    'category' => 'overtime',
                    'title' => 'High Overtime Alert',
                    'message' => count($highOT) . " employee(s) exceeded 40 OT hours this month: {$names}",
                    'action' => 'Review workload distribution',
                    'priority' => 'high',
                ];
            }
        } catch (PDOException $e) {}

        // 5. Timesheets pending approval
        try {
            $stmt = $this->db->query("
                SELECT COUNT(*) as cnt FROM timesheets 
                WHERE status IN ('submitted', 'endorsed')
            ");
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (($row['cnt'] ?? 0) > 0) {
                $insights[] = [
                    'type' => 'action',
                    'category' => 'timesheets',
                    'title' => 'Timesheets Awaiting Approval',
                    'message' => "{$row['cnt']} timesheet(s) submitted and waiting for review.",
                    'action' => 'Review in Timesheet Management',
                    'priority' => 'medium',
                ];
            }
        } catch (PDOException $e) {}

        // 6. Monday absence pattern detection
        try {
            $stmt = $this->db->query("
                SELECT e.employee_number, e.first_name, e.last_name,
                       COUNT(*) as monday_absences
                FROM daily_attendance da
                JOIN employees e ON da.employee_id = e.id
                WHERE DAYOFWEEK(da.date) = 2 
                AND da.status = 'absent'
                AND da.date >= DATE_SUB(CURDATE(), INTERVAL 90 DAY)
                GROUP BY da.employee_id
                HAVING monday_absences >= 3
            ");
            $mondayPattern = $stmt->fetchAll(PDO::FETCH_ASSOC);
            if (!empty($mondayPattern)) {
                $insights[] = [
                    'type' => 'anomaly',
                    'category' => 'attendance',
                    'title' => 'Monday Absence Pattern Detected',
                    'message' => count($mondayPattern) . " employee(s) show a pattern of frequent Monday absences (3+ in 90 days).",
                    'action' => 'Investigate potential attendance issues',
                    'priority' => 'low',
                ];
            }
        } catch (PDOException $e) {}

        // 7. Staffing gap detection
        try {
            $stmt = $this->db->query("
                SELECT lr.start_date, COUNT(*) as leaves_on_day
                FROM leave_requests lr
                WHERE lr.status = 'approved'
                AND lr.start_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 7 DAY)
                GROUP BY lr.start_date
                HAVING leaves_on_day >= 3
            ");
            $staffGaps = $stmt->fetchAll(PDO::FETCH_ASSOC);
            if (!empty($staffGaps)) {
                foreach ($staffGaps as $gap) {
                    $insights[] = [
                        'type' => 'warning',
                        'category' => 'scheduling',
                        'title' => 'Potential Staffing Gap',
                        'message' => "{$gap['leaves_on_day']} employees on approved leave on " . date('M d (l)', strtotime($gap['start_date'])) . ".",
                        'action' => 'Ensure adequate shift coverage',
                        'priority' => 'high',
                    ];
                }
            }
        } catch (PDOException $e) {}

        // Sort by priority
        usort($insights, function ($a, $b) {
            $order = ['high' => 0, 'medium' => 1, 'low' => 2];
            return ($order[$a['priority']] ?? 3) - ($order[$b['priority']] ?? 3);
        });

        return $insights;
    }

    /**
     * Detect anomalies across all HR domains
     */
    public function detectAnomalies(): array
    {
        if (!$this->db) return [];

        $anomalies = [];

        // 1. Rapid successive punches (possible buddy punching)
        try {
            $stmt = $this->db->query("
                SELECT al1.employee_id, e.first_name, e.last_name, e.employee_number,
                       COUNT(*) as rapid_count
                FROM attendance_logs al1
                JOIN attendance_logs al2 ON al1.employee_id = al2.employee_id 
                    AND al1.id != al2.id
                    AND ABS(TIMESTAMPDIFF(SECOND, al1.log_time, al2.log_time)) < 30
                JOIN employees e ON al1.employee_id = e.id
                WHERE al1.log_time >= DATE_SUB(NOW(), INTERVAL 7 DAY)
                GROUP BY al1.employee_id
                HAVING rapid_count >= 2
            ");
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $anomalies[] = [
                    'type' => 'rapid_punch',
                    'severity' => 'high',
                    'employee' => "{$r['employee_number']} - {$r['first_name']} {$r['last_name']}",
                    'description' => "{$r['rapid_count']} rapid successive punches detected (< 30s apart) in the last 7 days.",
                    'recommendation' => 'Verify authenticity of attendance records.',
                ];
            }
        } catch (PDOException $e) {}

        // 2. Unusually high claim amounts
        try {
            $stmt = $this->db->query("
                SELECT c.id, c.amount, c.description, cc.name as category_name, cc.max_amount,
                       e.first_name, e.last_name, e.employee_number
                FROM claims c
                JOIN employees e ON c.employee_id = e.id
                LEFT JOIN claim_categories cc ON c.category_id = cc.id
                WHERE c.status IN ('submitted', 'endorsed')
                AND cc.max_amount IS NOT NULL
                AND c.amount > (cc.max_amount * 0.9)
            ");
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $anomalies[] = [
                    'type' => 'high_claim',
                    'severity' => 'medium',
                    'employee' => "{$r['employee_number']} - {$r['first_name']} {$r['last_name']}",
                    'description' => "Claim for {$r['category_name']}: PHP " . number_format($r['amount'], 2) . " (near max limit of PHP " . number_format($r['max_amount'], 2) . ")",
                    'recommendation' => 'Verify receipt and claim details.',
                ];
            }
        } catch (PDOException $e) {}

        // 3. Employees with no attendance but no leave
        try {
            $stmt = $this->db->query("
                SELECT e.employee_number, e.first_name, e.last_name,
                       (SELECT MAX(da.date) FROM daily_attendance da WHERE da.employee_id = e.id) as last_attendance
                FROM employees e
                WHERE e.status = 'active'
                AND NOT EXISTS (
                    SELECT 1 FROM daily_attendance da 
                    WHERE da.employee_id = e.id 
                    AND da.date >= DATE_SUB(CURDATE(), INTERVAL 3 DAY)
                )
                AND NOT EXISTS (
                    SELECT 1 FROM leave_requests lr 
                    WHERE lr.employee_id = e.id 
                    AND lr.status = 'approved'
                    AND CURDATE() BETWEEN lr.start_date AND lr.end_date
                )
                LIMIT 10
            ");
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
                if ($r['last_attendance']) {
                    $anomalies[] = [
                        'type' => 'missing_attendance',
                        'severity' => 'medium',
                        'employee' => "{$r['employee_number']} - {$r['first_name']} {$r['last_name']}",
                        'description' => "No attendance in last 3 days. Last recorded: {$r['last_attendance']}. No approved leave found.",
                        'recommendation' => 'Follow up with employee or supervisor.',
                    ];
                }
            }
        } catch (PDOException $e) {}

        return $anomalies;
    }

    /**
     * Get workforce analytics data
     */
    public function getAnalytics(): array
    {
        if (!$this->db) return [];

        $analytics = [];

        // Attendance rate (last 30 days)
        try {
            $stmt = $this->db->query("
                SELECT 
                    COUNT(CASE WHEN status = 'present' THEN 1 END) as present,
                    COUNT(CASE WHEN status = 'absent' THEN 1 END) as absent,
                    COUNT(CASE WHEN status = 'half_day' THEN 1 END) as half_day,
                    COUNT(CASE WHEN status = 'leave' THEN 1 END) as on_leave,
                    COUNT(*) as total
                FROM daily_attendance 
                WHERE date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
            ");
            $analytics['attendance_rate'] = $stmt->fetch(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {}

        // OT distribution by unit
        try {
            $stmt = $this->db->query("
                SELECT u.name as unit_name, 
                       SUM(da.ot_hours) as total_ot,
                       COUNT(DISTINCT da.employee_id) as employee_count
                FROM daily_attendance da
                JOIN employees e ON da.employee_id = e.id
                LEFT JOIN units u ON e.unit_id = u.id
                WHERE da.date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
                GROUP BY e.unit_id
                ORDER BY total_ot DESC
            ");
            $analytics['ot_by_unit'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {}

        // Leave utilization
        try {
            $stmt = $this->db->query("
                SELECT leave_type,
                       SUM(entitlement) as total_entitlement,
                       SUM(used) as total_used,
                       SUM(pending) as total_pending
                FROM leave_balances
                WHERE year = YEAR(CURDATE())
                GROUP BY leave_type
            ");
            $analytics['leave_utilization'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {}

        // Claims by category
        try {
            $stmt = $this->db->query("
                SELECT cc.name as category_name,
                       COUNT(*) as claim_count,
                       SUM(c.amount) as total_amount,
                       AVG(c.amount) as avg_amount
                FROM claims c
                LEFT JOIN claim_categories cc ON c.category_id = cc.id
                WHERE c.submitted_at >= DATE_SUB(NOW(), INTERVAL 90 DAY)
                GROUP BY c.category_id
                ORDER BY total_amount DESC
            ");
            $analytics['claims_by_category'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {}

        // Daily attendance trend (last 14 days)
        try {
            $stmt = $this->db->query("
                SELECT date, 
                       COUNT(CASE WHEN status = 'present' THEN 1 END) as present,
                       COUNT(CASE WHEN status = 'absent' THEN 1 END) as absent,
                       COUNT(*) as total
                FROM daily_attendance
                WHERE date >= DATE_SUB(CURDATE(), INTERVAL 14 DAY)
                GROUP BY date
                ORDER BY date
            ");
            $analytics['attendance_trend'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {}

        return $analytics;
    }

    // ─── CONTEXT GATHERING ────────────────────────────────────────────

    /**
     * Gather relevant data context based on message intent
     */
    private function gatherContext(string $message): array
    {
        $msg = strtolower($message);
        $context = [];

        // Always get employee info if available
        if ($this->employeeId && $this->db) {
            try {
                $stmt = $this->db->prepare("SELECT first_name, last_name, employee_number, email, unit_id FROM employees WHERE id = ?");
                $stmt->execute([$this->employeeId]);
                $emp = $stmt->fetch(PDO::FETCH_ASSOC);
                if ($emp) {
                    $context['employee'] = [
                        'name' => $emp['first_name'] . ' ' . $emp['last_name'],
                        'employee_number' => $emp['employee_number'],
                        'unit_id' => $emp['unit_id'],
                    ];
                }
            } catch (PDOException $e) {}
        }

        // Attendance context
        if ($this->matchesIntent($msg, ['attendance', 'time in', 'time out', 'clock', 'present', 'absent', 'punch', 'exception', 'anomal']) || str_contains($msg, 'today')) {
            $context['attendance'] = $this->getAttendanceContext();
        }

        // Leave context
        if ($this->matchesIntent($msg, ['leave', 'vacation', 'sick', 'balance', 'day off', 'maternity', 'paternity', 'remaining'])) {
            $context['leave'] = $this->getLeaveContext();
        }

        // Claims context
        if ($this->matchesIntent($msg, ['claim', 'reimbursement', 'expense', 'receipt', 'reimburse', 'category', 'limit'])) {
            $context['claims'] = $this->getClaimsContext();
        }

        // Schedule context
        if ($this->matchesIntent($msg, ['schedule', 'shift', 'roster', 'duty', 'swap', 'overtime'])) {
            $context['schedule'] = $this->getScheduleContext();
        }

        // Timesheet context
        if ($this->matchesIntent($msg, ['timesheet', 'hours', 'pay period', 'cutoff', 'payroll'])) {
            $context['timesheets'] = $this->getTimesheetContext();
        }

        // Policy / settings context
        if ($this->matchesIntent($msg, ['policy', 'rule', 'regulation', 'premium', 'rate', 'setting', 'night diff', 'labor'])) {
            $context['settings'] = $this->getSettingsContext();
        }

        return $context;
    }

    private function getAttendanceContext(): array
    {
        if (!$this->db || !$this->employeeId) return [];
        $data = [];

        try {
            // Today's attendance
            $stmt = $this->db->prepare("SELECT * FROM daily_attendance WHERE employee_id = ? AND date = CURDATE()");
            $stmt->execute([$this->employeeId]);
            $data['today'] = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;

            // Monthly summary
            $stmt = $this->db->prepare("
                SELECT COUNT(CASE WHEN status = 'present' THEN 1 END) as present_days,
                       COUNT(CASE WHEN status = 'absent' THEN 1 END) as absent_days,
                       SUM(regular_hours) as total_regular,
                       SUM(ot_hours) as total_ot,
                       COUNT(CASE WHEN late_seconds > 0 THEN 1 END) as total_late
                FROM daily_attendance 
                WHERE employee_id = ? AND MONTH(date) = MONTH(CURDATE()) AND YEAR(date) = YEAR(CURDATE())
            ");
            $stmt->execute([$this->employeeId]);
            $data['monthly_summary'] = $stmt->fetch(PDO::FETCH_ASSOC);

            // Exceptions
            $stmt = $this->db->prepare("SELECT * FROM attendance_exceptions WHERE employee_id = ? AND status = 'pending' ORDER BY log_date DESC LIMIT 5");
            $stmt->execute([$this->employeeId]);
            $data['exceptions'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {}

        return $data;
    }

    private function getLeaveContext(): array
    {
        if (!$this->db || !$this->employeeId) return [];
        $data = [];

        try {
            $stmt = $this->db->prepare("SELECT leave_type, entitlement, used, pending FROM leave_balances WHERE employee_id = ? AND year = YEAR(CURDATE())");
            $stmt->execute([$this->employeeId]);
            $data['balances'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $stmt = $this->db->prepare("SELECT leave_type, start_date, end_date, total_days, status, reason FROM leave_requests WHERE employee_id = ? ORDER BY created_at DESC LIMIT 5");
            $stmt->execute([$this->employeeId]);
            $data['requests'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {}

        return $data;
    }

    private function getClaimsContext(): array
    {
        if (!$this->db || !$this->employeeId) return [];
        $data = [];

        try {
            $stmt = $this->db->prepare("
                SELECT c.amount, c.description, c.status, cc.name as category_name
                FROM claims c LEFT JOIN claim_categories cc ON c.category_id = cc.id
                WHERE c.employee_id = ? ORDER BY c.created_at DESC LIMIT 5
            ");
            $stmt->execute([$this->employeeId]);
            $data['my_claims'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $data['categories'] = $this->db->query("SELECT code, name, max_amount, requires_receipt FROM claim_categories ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {}

        return $data;
    }

    private function getScheduleContext(): array
    {
        if (!$this->db || !$this->employeeId) return [];
        $data = [];

        try {
            // Today's shift
            $stmt = $this->db->prepare("
                SELECT ra.assignment_date, st.name as shift_name, st.start_time, st.end_time, st.break_minutes
                FROM roster_assignments ra
                JOIN rosters r ON ra.roster_id = r.id
                JOIN shift_templates st ON ra.shift_template_id = st.id
                WHERE ra.employee_id = ? AND ra.assignment_date = CURDATE() AND r.status = 'published'
                LIMIT 1
            ");
            $stmt->execute([$this->employeeId]);
            $data['today_shift'] = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;

            // Week schedule
            $stmt = $this->db->prepare("
                SELECT ra.assignment_date as date, st.name as shift_name, st.start_time, st.end_time
                FROM roster_assignments ra
                JOIN rosters r ON ra.roster_id = r.id
                JOIN shift_templates st ON ra.shift_template_id = st.id
                WHERE ra.employee_id = ? 
                AND ra.assignment_date BETWEEN DATE(DATE_SUB(CURDATE(), INTERVAL WEEKDAY(CURDATE()) DAY)) AND DATE(DATE_ADD(CURDATE(), INTERVAL (6 - WEEKDAY(CURDATE())) DAY))
                AND r.status = 'published'
                ORDER BY ra.assignment_date
            ");
            $stmt->execute([$this->employeeId]);
            $data['week_schedule'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {}

        return $data;
    }

    private function getTimesheetContext(): array
    {
        if (!$this->db || !$this->employeeId) return [];
        $data = [];

        try {
            $stmt = $this->db->prepare("
                SELECT period_start, period_end, total_hours, total_ot_hours, total_nd_hours, status
                FROM timesheets WHERE employee_id = ? ORDER BY period_start DESC LIMIT 1
            ");
            $stmt->execute([$this->employeeId]);
            $data['current'] = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
        } catch (PDOException $e) {}

        return $data;
    }

    private function getSettingsContext(): array
    {
        if (!$this->db) return [];

        try {
            $stmt = $this->db->query("SELECT setting_key, setting_value FROM hr3_settings");
            $settings = [];
            while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $settings[$r['setting_key']] = $r['setting_value'];
            }
            return $settings;
        } catch (PDOException $e) {
            return [];
        }
    }

    // ─── UTILITY ──────────────────────────────────────────────────────

    private function matchesIntent(string $msg, array $keywords): bool
    {
        foreach ($keywords as $kw) {
            if (str_contains($msg, $kw)) return true;
        }
        return false;
    }
}
