<?php
require_once __DIR__ . '/../../config/config.php';
requireAuth();
checkRole(['employee']);

$page_title = 'My Attendance';

// Basic attendance data for the logged-in employee
$attendanceRows = [];
$metrics = [
    'days_logged'   => 0,
    'late_count'    => 0,
    'undertime'     => 0,
    'no_logs_count' => 0,
];

$employeeId = getCurrentEmployeeId($db ?? null);
if (!$employeeId) {
    $employeeId = $_SESSION['hr3_employee_id'] ?? $_SESSION['employee_id'] ?? $_SESSION['user_id'] ?? null;
}
if ($employeeId && !is_numeric($employeeId) && isset($db)) {
    try {
        $stmt = $db->prepare("SELECT id FROM employees WHERE employee_number = ? LIMIT 1");
        $stmt->execute([$employeeId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        $employeeId = $row ? (int) $row['id'] : null;
    } catch (PDOException $e) {
        $employeeId = null;
    }
}
if ($employeeId) {
    $employeeId = (int) $employeeId;
}
if ($employeeId && isset($db)) {
    try {
        // Use daily_attendance as the authoritative source
        $tableCheck = $db->query("SHOW TABLES LIKE 'daily_attendance'");
        if ($tableCheck && $tableCheck->rowCount() > 0) {
            $sql = "SELECT 
                        date as log_date,
                        time_in,
                        time_out,
                        status,
                        regular_hours,
                        ot_hours,
                        ROUND(regular_hours + COALESCE(ot_hours,0), 2) as total_hours,
                        ROUND(COALESCE(late_seconds,0) / 60) as late_minutes,
                        ROUND(COALESCE(undertime_seconds,0) / 60) as undertime_minutes
                    FROM daily_attendance
                    WHERE employee_id = :employee_id
                    ORDER BY date DESC
                    LIMIT 90";
            $stmt = $db->prepare($sql);
            $stmt->bindValue(':employee_id', $employeeId, PDO::PARAM_INT);
            $stmt->execute();
            $attendanceRows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

            $metrics['days_logged'] = count($attendanceRows);

            foreach ($attendanceRows as $row) {
                if ((int)($row['late_minutes'] ?? 0) > 0) {
                    $metrics['late_count']++;
                }
                $metrics['undertime'] += (int)($row['undertime_minutes'] ?? 0);
                if (!empty($row['status']) && strtolower($row['status']) === 'absent') {
                    $metrics['no_logs_count']++;
                }
            }
        }
    } catch (PDOException $e) {
        error_log('Employee attendance fetch error: ' . $e->getMessage());
        $attendanceRows = [];
    }
}

// No more sample data fallback – real data only

// Prepare table structure for the generic renderer
$tableConfig = [
    'columns' => [
        ['key' => 'log_date',         'label' => 'Date',           'class' => 'text-xs'],
        ['key' => 'time_in',          'label' => 'Time In',        'class' => 'text-xs'],
        ['key' => 'time_out',         'label' => 'Time Out',       'class' => 'text-xs'],
        ['key' => 'status',           'label' => 'Status',         'class' => 'text-xs'],
        ['key' => 'total_hours',      'label' => 'Hours Worked',   'class' => 'text-xs'],
        ['key' => 'late_minutes',     'label' => 'Late (mins)',    'class' => 'text-xs'],
        ['key' => 'undertime_minutes','label' => 'UT (mins)',      'class' => 'text-xs'],
    ],
    'rows' => $attendanceRows,
];

// Page metadata & configuration
$moduleConfig = [
    'title'       => 'Daily Attendance',
    'description' => 'View your daily time logs, hours worked, and any late or undertime records.',
    'breadcrumbs' => [
        ['label' => 'Dashboard', 'href' => BASE_URL . '/employee/employee-dashboard.php'],
        ['label' => 'My Attendance'],
    ],
    'metrics' => [
        [
            'label' => 'Days with Logs',
            'value' => $metrics['days_logged'],
            'sub'   => 'Last 90 calendar days',
            'icon'  => 'calendar-days',
            'tone'  => 'primary',
        ],
        [
            'label' => 'Late Instances',
            'value' => $metrics['late_count'],
            'sub'   => 'Marked as late in records',
            'icon'  => 'clock-alert',
            'tone'  => 'warning',
        ],
        [
            'label' => 'Total Undertime (mins)',
            'value' => $metrics['undertime'],
            'sub'   => 'Accumulated undertime',
            'icon'  => 'timer',
            'tone'  => 'danger',
        ],
        [
            'label' => 'Days without Logs',
            'value' => $metrics['no_logs_count'],
            'sub'   => 'Flagged as no logs',
            'icon'  => 'alert-circle',
            'tone'  => 'info',
        ],
    ],
    'filters' => [
        [
            'type'  => 'date-range',
            'name'  => 'date_range',
            'label' => 'Date Range',
        ],
        [
            'type'        => 'select',
            'name'        => 'status',
            'label'       => 'Status',
            'options'     => [
                ['value' => '',         'label' => 'All'],
                ['value' => 'present',  'label' => 'Present'],
                ['value' => 'late',     'label' => 'Late'],
                ['value' => 'no_log',   'label' => 'No Logs'],
            ],
        ],
        [
            'type'        => 'search',
            'name'        => 'search',
            'label'       => 'Quick Search',
            'placeholder' => 'Search by date or status',
        ],
    ],
    'table' => $tableConfig,
    'empty_state' => [
        'title'        => 'No attendance records yet',
        'message'      => 'Once you start using the timeclock, your daily logs will appear here.',
        'icon'         => 'clipboard-list',
        'action_label' => 'Open Timeclock',
        'action_href'  => BASE_URL . '/employee/modules/employee-timeclock.php',
        'action_icon'  => 'clock',
    ],
];

include __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../includes/hris_module.php';

renderHrisModule($moduleConfig);

include __DIR__ . '/../../includes/footer.php';





