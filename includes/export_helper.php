<?php
/**
 * Export Helper Functions
 * Provides Excel and PDF export functionality for reports
 */

/**
 * Export data to Excel (CSV format)
 * 
 * @param array $data Array of data rows
 * @param array $headers Column headers
 * @param string $filename Output filename
 */
function exportToExcel($data, $headers, $filename = 'report') {
    header('Content-Type: application/vnd.ms-excel');
    header('Content-Disposition: attachment; filename="' . $filename . '_' . date('Y-m-d') . '.xls"');
    header('Cache-Control: max-age=0');
    
    echo '<table border="1">';
    
    // Headers
    echo '<tr>';
    foreach ($headers as $header) {
        echo '<th style="background-color: #4F46E5; color: white; padding: 8px; font-weight: bold;">' . htmlspecialchars($header) . '</th>';
    }
    echo '</tr>';
    
    // Data rows
    foreach ($data as $row) {
        echo '<tr>';
        foreach ($row as $cell) {
            echo '<td style="padding: 5px;">' . htmlspecialchars($cell ?? '') . '</td>';
        }
        echo '</tr>';
    }
    
    echo '</table>';
    exit();
}

/**
 * Export data to CSV
 * 
 * @param array $data Array of data rows
 * @param array $headers Column headers
 * @param string $filename Output filename
 */
function exportToCSV($data, $headers, $filename = 'report') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '_' . date('Y-m-d') . '.csv"');
    header('Cache-Control: max-age=0');
    
    $output = fopen('php://output', 'w');
    
    // Add BOM for UTF-8
    fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));
    
    // Headers
    fputcsv($output, $headers);
    
    // Data rows
    foreach ($data as $row) {
        fputcsv($output, $row);
    }
    
    fclose($output);
    exit();
}

/**
 * Generate PDF export (using browser print to PDF)
 * This creates a print-friendly version that can be saved as PDF
 * 
 * @param string $html HTML content to print
 * @param string $title Document title
 */
function generatePDFView($html, $title = 'Report') {
    header('Content-Type: text/html; charset=utf-8');
    echo '<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>' . htmlspecialchars($title) . '</title>
    <style>
        @media print {
            @page { margin: 1cm; }
            body { margin: 0; }
            .no-print { display: none; }
        }
        body { font-family: Arial, sans-serif; padding: 20px; }
        table { width: 100%; border-collapse: collapse; margin: 20px 0; }
        th, td { border: 1px solid #ddd; padding: 8px; text-align: left; }
        th { background-color: #4F46E5; color: white; font-weight: bold; }
        .header { text-align: center; margin-bottom: 30px; }
        .footer { margin-top: 30px; text-align: center; font-size: 12px; color: #666; }
    </style>
</head>
<body>
    <div class="no-print" style="margin-bottom: 20px; text-align: center;">
        <button onclick="window.print()" style="padding: 10px 20px; background: #4F46E5; color: white; border: none; border-radius: 5px; cursor: pointer; font-size: 16px;">
            Print / Save as PDF
        </button>
        <button onclick="window.close()" style="padding: 10px 20px; background: #666; color: white; border: none; border-radius: 5px; cursor: pointer; font-size: 16px; margin-left: 10px;">
            Close
        </button>
    </div>
    ' . $html . '
</body>
</html>';
    exit();
}

