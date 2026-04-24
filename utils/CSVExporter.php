<?php
namespace Utils;

class CSVExporter {
    
    public function exportCustomers($customers) {
        header('Content-Type: text/csv');
        header('Content-Disposition: attachment; filename="customers_' . date('Y-m-d') . '.csv"');
        
        $output = fopen('php://output', 'w');
        
        // Headers
        fputcsv($output, [
            'ID', 'Full Name', 'Telephone', 'Email', 'Address', 
            'Tier', 'Total Spent', 'Loyalty Points', 'Status', 'Created At'
        ]);
        
        // Data
        foreach ($customers as $customer) {
            fputcsv($output, [
                $customer['id'],
                $customer['full_name'],
                $customer['telephone'],
                $customer['email'],
                $customer['address'],
                $customer['customer_tier'],
                $customer['total_spent'] ?? 0,
                $customer['loyalty_points'] ?? 0,
                $customer['status'] == 1 ? 'Active' : 'Inactive',
                $customer['created_at']
            ]);
        }
        
        fclose($output);
        exit();
    }
    
    public function exportCustomersExcel($customers) {
        header('Content-Type: application/vnd.ms-excel');
        header('Content-Disposition: attachment; filename="customers_' . date('Y-m-d') . '.xls"');
        
        echo '<html><body>';
        echo '<table border="1">';
        echo '<tr>';
        echo '<th>ID</th><th>Full Name</th><th>Telephone</th><th>Email</th><th>Address</th>';
        echo '<th>Tier</th><th>Total Spent</th><th>Loyalty Points</th><th>Status</th><th>Created At</th>';
        echo '</tr>';
        
        foreach ($customers as $customer) {
            echo '<tr>';
            echo '<td>' . $customer['id'] . '</td>';
            echo '<td>' . htmlspecialchars($customer['full_name']) . '</td>';
            echo '<td>' . htmlspecialchars($customer['telephone']) . '</td>';
            echo '<td>' . htmlspecialchars($customer['email']) . '</td>';
            echo '<td>' . htmlspecialchars($customer['address']) . '</td>';
            echo '<td>' . htmlspecialchars($customer['customer_tier']) . '</td>';
            echo '<td>' . ($customer['total_spent'] ?? 0) . '</td>';
            echo '<td>' . ($customer['loyalty_points'] ?? 0) . '</td>';
            echo '<td>' . ($customer['status'] == 1 ? 'Active' : 'Inactive') . '</td>';
            echo '<td>' . $customer['created_at'] . '</td>';
            echo '</tr>';
        }
        
        echo '</table>';
        echo '</body></html>';
        exit();
    }
    
    public function exportInteractions($interactions) {
        header('Content-Type: text/csv');
        header('Content-Disposition: attachment; filename="interactions_' . date('Y-m-d') . '.csv"');
        
        $output = fopen('php://output', 'w');
        
        fputcsv($output, ['ID', 'Customer', 'Date', 'Type', 'Summary', 'Follow-up Date', 'Created By']);
        
        foreach ($interactions as $interaction) {
            fputcsv($output, [
                $interaction['id'],
                $interaction['customer_name'],
                $interaction['interaction_date'],
                $interaction['interaction_type'],
                $interaction['summary'],
                $interaction['follow_up_date'] ?? '',
                $interaction['created_by_name'] ?? ''
            ]);
        }
        
        fclose($output);
        exit();
    }
}