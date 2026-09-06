<?php
/**
 * HR3 PH Labor Policy Helpers
 * Configurable rules engine for Philippines labor law compliance
 * Asia/Manila timezone
 */

if (!function_exists('getHr3Setting')) {
    function getHr3Setting(PDO $db, string $key, $default = null) {
        static $cache = [];
        if (isset($cache[$key])) {
            return $cache[$key];
        }
        try {
            $stmt = $db->prepare("SELECT setting_value FROM hr3_settings WHERE setting_key = ? LIMIT 1");
            $stmt->execute([$key]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            $val = $row['setting_value'] ?? $default;
            $cache[$key] = $val;
            return $val;
        } catch (PDOException $e) {
            return $default;
        }
    }
}

function getStandardWorkHours(PDO $db): float {
    return (float) (getHr3Setting($db, 'standard_work_hours', 8) ?: 8);
}

function getMealBreakMins(PDO $db): int {
    return (int) (getHr3Setting($db, 'meal_break_mins', 60) ?: 60);
}

function getOtPremiumRegular(PDO $db): float {
    return (float) (getHr3Setting($db, 'ot_premium_regular', 1.25) ?: 1.25);
}

function getOtPremiumRest(PDO $db): float {
    return (float) (getHr3Setting($db, 'ot_premium_rest', 1.30) ?: 1.30);
}

function getNdRate(PDO $db): float {
    return (float) (getHr3Setting($db, 'nd_rate', 0.10) ?: 0.10);
}

function getNdStartHour(PDO $db): int {
    return (int) (getHr3Setting($db, 'nd_start_hour', 22) ?: 22);
}

function getNdEndHour(PDO $db): int {
    return (int) (getHr3Setting($db, 'nd_end_hour', 6) ?: 6);
}

function getRestDayPremium(PDO $db): float {
    return (float) (getHr3Setting($db, 'rest_day_premium', 1.30) ?: 1.30);
}

function getHolidayMultiplier(PDO $db, string $type = 'regular', bool $onRestDay = false): float {
    $key = $onRestDay ? "holiday_{$type}_rest_day_worked" : "holiday_{$type}_worked";
    $defaults = [
        'holiday_regular_worked' => 2.00,
        'holiday_regular_rest_day_worked' => 2.60,
        'holiday_special_worked' => 1.30,
        'holiday_special_rest_day_worked' => 1.50,
    ];
    return (float) (getHr3Setting($db, $key, $defaults[$key] ?? 2.00) ?: ($defaults[$key] ?? 2.00));
}

/**
 * Check if date is a PH holiday
 */
function isHoliday(PDO $db, string $date): ?array {
    try {
        $stmt = $db->prepare("SELECT * FROM holiday_calendar WHERE date = ? LIMIT 1");
        $stmt->execute([$date]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    } catch (PDOException $e) {
        return null;
    }
}

/**
 * Check if day is rest day (Saturday=6, Sunday=7)
 */
function isRestDay(string $date): bool {
    $dow = (int) date('N', strtotime($date));
    return $dow >= 6;
}

/**
 * Compute hours in night differential window (22:00-06:00)
 */
function ndHoursBetween(string $start, string $end): float {
    $t1 = strtotime($start);
    $t2 = strtotime($end);
    if ($t2 <= $t1) return 0;
    $ndStart = strtotime(date('Y-m-d', $t1) . ' 22:00:00');
    $ndEnd = strtotime(date('Y-m-d', $t1) . ' 06:00:00') + 86400; // next day 06:00
    $ndStart2 = $ndStart + 86400;
    $ndEnd2 = $ndEnd + 86400;
    $hours = 0;
    $segments = [
        [$ndStart, $ndEnd],
        [$ndStart2, $ndEnd2],
    ];
    foreach ($segments as [$s, $e]) {
        $overlapStart = max($t1, $s);
        $overlapEnd = min($t2, $e);
        if ($overlapEnd > $overlapStart) {
            $hours += ($overlapEnd - $overlapStart) / 3600;
        }
    }
    return round($hours, 4);
}

/**
 * Compute daily attendance metrics per PH policy
 * @param PDO $db
 * @param int $employeeId
 * @param string $date Y-m-d
 * @param string|null $timeIn
 * @param string|null $timeOut
 * @param int $breakMinutes
 * @param bool $isRestDay
 * @param string|null $expectedStart From shift
 * @param string|null $expectedEnd From shift
 * @return array [regular_hours, ot_hours, nd_hours, late_seconds, undertime_seconds, holiday_premium_hours, rest_day_hours]
 */
function computeDailyAttendance(
    PDO $db,
    int $employeeId,
    string $date,
    ?string $timeIn,
    ?string $timeOut,
    int $breakMinutes = 0,
    bool $isRestDay = false,
    ?string $expectedStart = null,
    ?string $expectedEnd = null
): array {
    $standardHours = getStandardWorkHours($db);
    $mealMins = getMealBreakMins($db);
    $result = [
        'regular_hours' => 0,
        'ot_hours' => 0,
        'nd_hours' => 0,
        'late_seconds' => 0,
        'undertime_seconds' => 0,
        'holiday_premium_hours' => 0,
        'rest_day_hours' => 0,
        'total_work_seconds' => 0,
    ];

    if (!$timeIn || !$timeOut) {
        return $result;
    }

    $holiday = isHoliday($db, $date);
    $fullStart = $date . ' ' . $timeIn;
    $fullEnd = $date . ' ' . $timeOut;
    if (strtotime($fullEnd) <= strtotime($fullStart)) {
        $fullEnd = date('Y-m-d', strtotime($date . ' +1 day')) . ' ' . $timeOut;
    }

    $workSeconds = strtotime($fullEnd) - strtotime($fullStart) - ($breakMinutes * 60);
    if ($workSeconds < 0) $workSeconds = 0;
    $result['total_work_seconds'] = $workSeconds;
    $workHours = $workSeconds / 3600;

    $expectedStart = $expectedStart ?? '08:00:00';
    $expectedEnd = $expectedEnd ?? '17:00:00';
    $expStartTs = strtotime($date . ' ' . $expectedStart);
    $expEndTs = strtotime($date . ' ' . $expectedEnd);
    if ($expEndTs <= $expStartTs) {
        $expEndTs += 86400;
    }
    $actualStartTs = strtotime($fullStart);
    $actualEndTs = strtotime($fullEnd);

    if ($actualStartTs > $expStartTs) {
        $result['late_seconds'] = $actualStartTs - $expStartTs;
    }
    if ($actualEndTs < $expEndTs) {
        $undertime = $expEndTs - $actualEndTs;
        $maxUndertime = $workSeconds;
        $result['undertime_seconds'] = min($undertime, $maxUndertime);
    }

    $regularCap = $standardHours * 3600;
    $result['regular_hours'] = min($workSeconds, $regularCap) / 3600;
    $result['ot_hours'] = max(0, ($workSeconds - $regularCap) / 3600);

    $ndStart = getNdStartHour($db);
    $ndEnd = getNdEndHour($db);
    $result['nd_hours'] = ndHoursBetween($fullStart, $fullEnd);

    if ($holiday) {
        $mult = getHolidayMultiplier($db, $holiday['type'] ?? 'regular', $isRestDay);
        $result['holiday_premium_hours'] = $workHours * ($mult - 1);
    } elseif ($isRestDay) {
        $prem = getRestDayPremium($db);
        $result['rest_day_hours'] = $workHours * ($prem - 1);
    }

    return $result;
}
