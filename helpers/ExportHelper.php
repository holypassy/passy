<?php
class ExportHelper {
    
    public static function toCSV($data, $filename = 'export.csv') {
        header('Content-Type: text/csv');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Pragma: no-cache');
        header('Expires: 0');
        
        $output = fopen('php://output', 'w');
        
        // Add UTF-8 BOM for Excel compatibility
        fwrite($output, "\xEF\xBB\xBF");
        
        if (!empty($data)) {
            // Write headers
            fputcsv($output, array_keys($data[0]));
            
            // Write data
            foreach ($data as $row) {
                fputcsv($output, $row);
            }
        } else {
            fputcsv($output, ['No data available']);
        }
        
        fclose($output);
        exit();
    }
    
    public static function toExcel($data, $filename = 'export.xls') {
        header('Content-Type: application/vnd.ms-excel');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Pragma: no-cache');
        header('Expires: 0');
        
        echo '<html>';
        echo '<head>';
        echo '<meta charset="UTF-8">';
        echo '<style>';
        echo 'th { background-color: #4CAF50; color: white; padding: 8px; }';
        echo 'td { padding: 6px; border: 1px solid #ddd; }';
        echo 'table { border-collapse: collapse; width: 100%; }';
        echo '</style>';
        echo '</head>';
        echo '<body>';
        
        if (!empty($data)) {
            echo '<table>';
            echo '<thead><tr>';
            foreach (array_keys($data[0]) as $header) {
                echo '<th>' . htmlspecialchars(ucwords(str_replace('_', ' ', $header))) . '</th>';
            }
            echo '</tr></thead>';
            echo '<tbody>';
            
            foreach ($data as $row) {
                echo '<tr>';
                foreach ($row as $cell) {
                    echo '<td>' . htmlspecialchars($cell) . '</td>';
                }
                echo '</tr>';
            }
            
            echo '</tbody></table>';
        } else {
            echo '<p>No data available for the selected period.</p>';
        }
        
        echo '</body></html>';
        exit();
    }
    
    public static function toPDF($html, $filename = 'export.pdf') {
        // This is a placeholder for PDF generation
        // In production, you would use a library like Dompdf, TCPDF, or mPDF
        
        header('Content-Type: application/pdf');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        
        // Simple PDF generation (requires additional library)
        echo $html;
        exit();
    }
    
    public static function formatForExport($data, $type = 'csv') {
        if ($type === 'json') {
            return json_encode($data, JSON_PRETTY_PRINT);
        }
        
        // Default CSV format
        $output = [];
        if (!empty($data)) {
            $output[] = implode(',', array_keys($data[0]));
            foreach ($data as $row) {
                $output[] = implode(',', array_map(function($value) {
                    return '"' . str_replace('"', '""', $value) . '"';
                }, $row));
            }
        }
        
        return implode("\n", $output);
    }
}
?>
<?php
class ExportHelper {
    
    public static function exportTechniciansToCSV($technicians, $filename = 'technicians_export.csv') {
        header('Content-Type: text/csv');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        
        $output = fopen('php://output', 'w');
        fwrite($output, "\xEF\xBB\xBF"); // UTF-8 BOM
        
        // Headers
        fputcsv($output, [
            'ID', 'Technician Code', 'Full Name', 'Employee ID', 'Department', 
            'Designation', 'Experience (Years)', 'Phone', 'Alternate Phone', 
            'Email', 'Hire Date', 'Specialization', 'Status', 'Medical Certificate'
        ]);
        
        // Data
        foreach ($technicians as $tech) {
            fputcsv($output, [
                $tech['id'],
                $tech['technician_code'],
                $tech['full_name'],
                $tech['employee_id'] ?? '',
                $tech['department'] ?? '',
                $tech['designation'] ?? '',
                $tech['experience_years'] ?? 0,
                $tech['phone'] ?? '',
                $tech['alternate_phone'] ?? '',
                $tech['email'] ?? '',
                $tech['hire_date'] ?? '',
                $tech['specialization'] ?? '',
                $tech['is_blocked'] ? 'Blocked' : ($tech['status'] ?? 'Active'),
                $tech['medical_certificate'] == 1 ? 'Valid' : ($tech['medical_certificate'] == 2 ? 'Expired' : 'Missing')
            ]);
        }
        
        fclose($output);
        exit();
    }
    
    public static function exportAttendanceToCSV($attendance, $technicianName, $filename = null) {
        if (!$filename) {
            $filename = 'attendance_' . preg_replace('/[^a-zA-Z0-9]/', '_', $technicianName) . '.csv';
        }
        
        header('Content-Type: text/csv');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        
        $output = fopen('php://output', 'w');
        fwrite($output, "\xEF\xBB\xBF");
        
        fputcsv($output, ['Date', 'Check In', 'Check Out', 'Total Hours', 'Status', 'Notes']);
        
        foreach ($attendance as $record) {
            fputcsv($output, [
                $record['attendance_date'],
                $record['check_in_time'] ?? '-',
                $record['check_out_time'] ?? '-',
                $record['total_hours'] ?? 0,
                ucfirst(str_replace('_', ' ', $record['status'] ?? 'Present')),
                $record['notes'] ?? ''
            ]);
        }
        
        fclose($output);
        exit();
    }
}
?>
<?php
class ExportHelper {
    
    public static function exportTechniciansToCSV($technicians, $filename = 'technicians_export.csv') {
        header('Content-Type: text/csv');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        
        $output = fopen('php://output', 'w');
        fwrite($output, "\xEF\xBB\xBF"); // UTF-8 BOM
        
        // Headers
        fputcsv($output, [
            'ID', 'Technician Code', 'Full Name', 'Employee ID', 'Department', 
            'Designation', 'Experience (Years)', 'Phone', 'Alternate Phone', 
            'Email', 'Hire Date', 'Specialization', 'Status', 'Medical Certificate'
        ]);
        
        // Data
        foreach ($technicians as $tech) {
            fputcsv($output, [
                $tech['id'],
                $tech['technician_code'],
                $tech['full_name'],
                $tech['employee_id'] ?? '',
                $tech['department'] ?? '',
                $tech['designation'] ?? '',
                $tech['experience_years'] ?? 0,
                $tech['phone'] ?? '',
                $tech['alternate_phone'] ?? '',
                $tech['email'] ?? '',
                $tech['hire_date'] ?? '',
                $tech['specialization'] ?? '',
                $tech['is_blocked'] ? 'Blocked' : ($tech['status'] ?? 'Active'),
                $tech['medical_certificate'] == 1 ? 'Valid' : ($tech['medical_certificate'] == 2 ? 'Expired' : 'Missing')
            ]);
        }
        
        fclose($output);
        exit();
    }
    
    public static function exportAttendanceToCSV($attendance, $technicianName, $filename = null) {
        if (!$filename) {
            $filename = 'attendance_' . preg_replace('/[^a-zA-Z0-9]/', '_', $technicianName) . '.csv';
        }
        
        header('Content-Type: text/csv');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        
        $output = fopen('php://output', 'w');
        fwrite($output, "\xEF\xBB\xBF");
        
        fputcsv($output, ['Date', 'Check In', 'Check Out', 'Total Hours', 'Status', 'Notes']);
        
        foreach ($attendance as $record) {
            fputcsv($output, [
                $record['attendance_date'],
                $record['check_in_time'] ?? '-',
                $record['check_out_time'] ?? '-',
                $record['total_hours'] ?? 0,
                ucfirst(str_replace('_', ' ', $record['status'] ?? 'Present')),
                $record['notes'] ?? ''
            ]);
        }
        
        fclose($output);
        exit();
    }
}
?>
