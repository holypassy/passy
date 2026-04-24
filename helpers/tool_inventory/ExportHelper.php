<?php
class ExportHelper {
    
    public static function exportToCSV($data, $filename = 'export.csv') {
        header('Content-Type: text/csv');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        
        $output = fopen('php://output', 'w');
        
        // Add UTF-8 BOM for Excel
        fwrite($output, "\xEF\xBB\xBF");
        
        if (!empty($data)) {
            fputcsv($output, array_keys($data[0]));
            foreach ($data as $row) {
                fputcsv($output, $row);
            }
        }
        
        fclose($output);
        exit();
    }
    
    public static function exportToExcel($data, $filename = 'export.xls') {
        header('Content-Type: application/vnd.ms-excel');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        
        echo '<html><head><meta charset="UTF-8"></head><body>';
        echo '<table border="1">';
        
        if (!empty($data)) {
            echo '<tr>';
            foreach (array_keys($data[0]) as $header) {
                echo '<th>' . htmlspecialchars($header) . '</th>';
            }
            echo '</tr>';
            
            foreach ($data as $row) {
                echo '<tr>';
                foreach ($row as $cell) {
                    echo '<td>' . htmlspecialchars($cell) . '</td>';
                }
                echo '</tr>';
            }
        }
        
        echo '</table></body></html>';
        exit();
    }
    
    public static function exportToPDF($html, $filename = 'export.pdf') {
        // Requires dompdf library
        // Placeholder for PDF generation
        header('Content-Type: application/pdf');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        echo $html;
        exit();
    }
}
?>