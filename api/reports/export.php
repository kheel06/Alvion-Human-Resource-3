<?php
/**
 * Export API - Generates CSV/PDF downloads for HR3 report data.
 * GET ?type=attendance|employees|leave|claims|timesheets|overtime|attendance_logs|leave_balances|audit_trail
 *     &format=csv|pdf&from=&to=&status=
 */
require_once __DIR__ . '/../../config/config.php';
requireAuth();
checkRole(['admin', 'super admin', 'supervisor', 'unit_head', 'hr_admin', 'finance']);

$type = $_GET['type'] ?? '';
$format = $_GET['format'] ?? 'csv';
$from = $_GET['from'] ?? date('Y-m-01');
$to = $_GET['to'] ?? date('Y-m-d');
$status = $_GET['status'] ?? '';

if (!isset($db)) {
    http_response_code(500);
    echo 'Database not available';
    exit;
}

$filename = "hr3_{$type}_" . date('Y-m-d_His');
$rows = [];
$headers = [];
$title = '';

try {
    $db->exec("SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci");
} catch (PDOException $e) { /* ignore if not supported */ }

try {
    switch ($type) {
        case 'attendance':
            $title = 'Daily Attendance Report';
            $headers = ['Date', 'Employee No', 'First Name', 'Last Name', 'Unit', 'Time In', 'Time Out', 'Regular Hrs', 'OT Hrs', 'Late (min)', 'Status'];
            $stmt = $db->prepare("
                SELECT da.date, e.employee_number, e.first_name, e.last_name, COALESCE(u.name,'') as unit_name,
                       COALESCE(da.time_in,'') as time_in, COALESCE(da.time_out,'') as time_out,
                       da.regular_hours, COALESCE(da.ot_hours,0) as ot_hours,
                       ROUND(COALESCE(da.late_seconds,0)/60) as late_min, da.status
                FROM daily_attendance da
                JOIN employees e ON da.employee_id = e.id
                LEFT JOIN units u ON e.unit_id = u.id
                WHERE da.date BETWEEN ? AND ?
                ORDER BY da.date DESC, e.last_name
            ");
            $stmt->execute([$from, $to]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            break;

        case 'attendance_logs':
            $title = 'Biometric Logs';
            $headers = ['Log Time', 'Employee No', 'First Name', 'Last Name', 'Type', 'Source', 'IP Address'];
            $stmt = $db->prepare("
                SELECT al.log_time, e.employee_number, e.first_name, e.last_name,
                       al.log_type, COALESCE(al.source,'') as source, COALESCE(al.ip_address,'') as ip
                FROM attendance_logs al
                JOIN employees e ON al.employee_id = e.id
                WHERE DATE(al.log_time) BETWEEN ? AND ?
                ORDER BY al.log_time DESC
            ");
            $stmt->execute([$from, $to]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            foreach ($rows as &$r) {
                $s = strtolower(trim($r['source'] ?? ''));
                if (in_array($s, ['qr', 'mobile', 'biometric'])) {
                    $r['source'] = 'Biometric';
                }
            }
            unset($r);
            break;

        case 'employees':
            $title = 'Employee Masterlist';
            $headers = ['Employee No', 'First Name', 'Last Name', 'Email', 'Unit', 'Role', 'Status', 'Hire Date'];
            $stmt = $db->prepare("
                SELECT e.employee_number, e.first_name, e.last_name, COALESCE(e.email,'') as email,
                       COALESCE(u.name,'') as unit_name, COALESCE(da.role_name,'') as role_name, e.status, COALESCE(e.hire_date,'') as hire_date
                FROM employees e
                LEFT JOIN units u ON e.unit_id = u.id
                LEFT JOIN department_accounts da ON da.employee_id COLLATE utf8mb4_unicode_ci = e.employee_number COLLATE utf8mb4_unicode_ci
                WHERE e.deleted_at IS NULL
                ORDER BY e.last_name
            ");
            $stmt->execute();
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            break;

        case 'leave':
            $title = 'Leave Requests';
            $headers = ['Employee No', 'Name', 'Leave Type', 'Start Date', 'End Date', 'Total Days', 'Reason', 'Status', 'Submitted'];
            $stmt = $db->prepare("
                SELECT e.employee_number, CONCAT(e.first_name, ' ', e.last_name) as name,
                       lr.leave_type, lr.start_date, lr.end_date, lr.total_days,
                       COALESCE(lr.reason,'') as reason, lr.status, COALESCE(lr.submitted_at,'') as submitted_at
                FROM leave_requests lr
                JOIN employees e ON lr.employee_id = e.id
                ORDER BY lr.submitted_at DESC
            ");
            $stmt->execute();
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            break;

        case 'leave_balances':
            $title = 'Leave Balances';
            $headers = ['Employee No', 'Name', 'Leave Type', 'Year', 'Entitlement', 'Used', 'Pending', 'Available'];
            $stmt = $db->prepare("
                SELECT e.employee_number, CONCAT(e.first_name, ' ', e.last_name) as name,
                       COALESCE(lt.name, lb.leave_type) as leave_type_name, lb.year,
                       lb.entitlement, lb.used, lb.pending,
                       (lb.entitlement - lb.used - lb.pending) as available
                FROM leave_balances lb
                JOIN employees e ON lb.employee_id = e.id
                LEFT JOIN leave_types lt ON lb.leave_type COLLATE utf8mb4_unicode_ci = lt.code COLLATE utf8mb4_unicode_ci
                WHERE lb.year = YEAR(CURDATE())
                ORDER BY e.last_name, lb.leave_type
            ");
            $stmt->execute();
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            break;

        case 'claims':
            $title = 'Claims & Reimbursement';
            $sql = "
                SELECT e.employee_number, CONCAT(e.first_name, ' ', e.last_name) as name,
                       COALESCE(cc.name, 'N/A') as category, c.amount, c.currency,
                       COALESCE(c.description,'') as description, c.status, COALESCE(c.submitted_at,'') as submitted_at
                FROM claims c
                JOIN employees e ON c.employee_id = e.id
                LEFT JOIN claim_categories cc ON c.category_id = cc.id
            ";
            $params = [];
            if ($status) {
                $sql .= " WHERE c.status = ?";
                $params[] = $status;
            }
            $sql .= " ORDER BY c.submitted_at DESC";
            $headers = ['Employee No', 'Name', 'Category', 'Amount', 'Currency', 'Description', 'Status', 'Submitted'];
            $stmt = $db->prepare($sql);
            $stmt->execute($params);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            break;

        case 'audit_trail':
            $title = 'Claims Audit Trail';
            $headers = ['Date', 'Employee', 'Action', 'Table', 'Record ID', 'Details', 'IP'];
            $stmt = $db->prepare("
                SELECT COALESCE(al.created_at,'') as created_at, COALESCE(al.employee_id,'') as employee_id,
                       al.action, COALESCE(al.table_name,'') as table_name,
                       COALESCE(al.record_id,'') as record_id, COALESCE(al.new_values,'') as details,
                       COALESCE(al.ip_address,'') as ip
                FROM audit_logs al
                WHERE al.table_name IN ('claims','claim_attachments')
                ORDER BY al.created_at DESC
                LIMIT 500
            ");
            $stmt->execute();
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            break;

        case 'timesheets':
            $title = 'Timesheets';
            $headers = ['Employee No', 'Name', 'Unit', 'Period Start', 'Period End', 'Total Hours', 'OT Hours', 'ND Hours', 'Status'];
            $stmt = $db->prepare("
                SELECT e.employee_number, CONCAT(e.first_name, ' ', e.last_name) as name,
                       COALESCE(u.name,'') as unit_name, t.period_start, t.period_end,
                       t.total_hours, COALESCE(t.total_ot_hours,0) as ot, COALESCE(t.total_nd_hours,0) as nd, t.status
                FROM timesheets t
                JOIN employees e ON t.employee_id = e.id
                LEFT JOIN units u ON t.unit_id = u.id
                ORDER BY t.period_start DESC, e.last_name
            ");
            $stmt->execute();
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            break;

        case 'overtime':
            $title = 'Overtime Requests';
            $headers = ['Employee No', 'Name', 'Date', 'Start Time', 'End Time', 'Hours', 'Reason', 'Status'];
            $stmt = $db->prepare("
                SELECT e.employee_number, CONCAT(e.first_name, ' ', e.last_name) as name,
                       o.request_date, o.start_time, o.end_time, o.hours,
                       COALESCE(o.reason,'') as reason, o.status
                FROM overtime_requests o
                JOIN employees e ON o.employee_id = e.id
                ORDER BY o.request_date DESC
            ");
            $stmt->execute();
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            break;

        default:
            http_response_code(400);
            echo json_encode(['error' => 'Invalid export type: ' . $type]);
            exit;
    }
} catch (PDOException $e) {
    error_log('Export error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Export query failed: ' . $e->getMessage()]);
    exit;
}

// ── PDF output (simple HTML table for print) ──
if ($format === 'pdf') {
    header('Content-Type: text/html; charset=utf-8');
    echo '<!DOCTYPE html><html><head><meta charset="utf-8"><title>' . htmlspecialchars($title ?: $type) . '</title>';
    echo '<style>
        body { font-family: Arial, sans-serif; font-size: 11px; margin: 20px; }
        h1 { font-size: 18px; margin-bottom: 4px; }
        .meta { color: #666; font-size: 10px; margin-bottom: 12px; }
        table { width: 100%; border-collapse: collapse; }
        th { background: #1f2937; color: #fff; padding: 6px 8px; text-align: left; font-size: 10px; text-transform: uppercase; }
        td { padding: 5px 8px; border-bottom: 1px solid #e5e7eb; }
        tr:nth-child(even) { background: #f9fafb; }
        @media print { body { margin: 0; } .no-print { display: none; } }
    </style></head><body>';
    echo '<h1>' . htmlspecialchars($title ?: ucfirst($type) . ' Report') . '</h1>';
    echo '<div class="meta">Generated: ' . date('F d, Y h:i A') . ' &middot; Records: ' . count($rows) . '</div>';
    echo '<button class="no-print" onclick="window.print()" style="margin-bottom:10px;padding:6px 16px;background:#2563eb;color:#fff;border:none;border-radius:4px;cursor:pointer;">Print / Save PDF</button>';
    echo '<table><thead><tr>';
    foreach ($headers as $h) echo '<th>' . htmlspecialchars($h) . '</th>';
    echo '</tr></thead><tbody>';
    if (empty($rows)) {
        echo '<tr><td colspan="' . count($headers) . '" style="text-align:center;padding:20px;color:#999;">No data found</td></tr>';
    } else {
        foreach ($rows as $row) {
            echo '<tr>';
            foreach (array_values($row) as $val) echo '<td>' . htmlspecialchars($val ?? '') . '</td>';
            echo '</tr>';
        }
    }
    echo '</tbody></table>';
    echo '<script>window.onload=function(){window.print();}</script>';
    echo '</body></html>';
    exit;
}

// ── CSV output (default) ──
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '.csv"');
header('Pragma: no-cache');
header('Expires: 0');

$output = fopen('php://output', 'w');
fwrite($output, "\xEF\xBB\xBF"); // BOM for Excel
fputcsv($output, $headers);

foreach ($rows as $row) {
    fputcsv($output, array_values($row));
}

fclose($output);
exit;
