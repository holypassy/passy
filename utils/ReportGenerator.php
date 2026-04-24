<?php
namespace Utils;

use TCPDF;

class ReportGenerator {
    
    public function generateCustomerSummary($data, $startDate, $endDate) {
        require_once __DIR__ . '/../vendors/TCPDF/tcpdf.php';
        
        $pdf = new TCPDF('P', 'mm', 'A4', true, 'UTF-8');
        $pdf->SetCreator('SAVANT MOTORS ERP');
        $pdf->SetAuthor('SAVANT MOTORS');
        $pdf->SetTitle('Customer Summary Report');
        $pdf->SetMargins(15, 15, 15);
        
        $pdf->AddPage();
        
        $html = "
            <h1 style='text-align:center;'>Customer Summary Report</h1>
            <p style='text-align:center;'>Period: {$startDate} to {$endDate}</p>
            <br><br>
            <table border='1' cellpadding='5'>
                <tr>
                    <td><strong>Total Customers</strong></td>
                    <td>{$data['total_customers']}</td>
                </tr>
                <tr>
                    <td><strong>Active Customers</strong></td>
                    <td>{$data['active_customers']}</td>
                </tr>
                <tr>
                    <td><strong>Platinum Tier</strong></td>
                    <td>{$data['platinum']}</td>
                </tr>
                <tr>
                    <td><strong>Gold Tier</strong></td>
                    <td>{$data['gold']}</td>
                </tr>
                <tr>
                    <td><strong>Silver Tier</strong></td>
                    <td>{$data['silver']}</td>
                </tr>
                <tr>
                    <td><strong>Bronze Tier</strong></td>
                    <td>{$data['bronze']}</td>
                </tr>
                <tr>
                    <td><strong>Average Rating</strong></td>
                    <td>{$data['avg_rating']} / 5</td>
                </tr>
                <tr>
                    <td><strong>Pending Follow-ups</strong></td>
                    <td>{$data['pending_followups']}</td>
                </tr>
            </table>
        ";
        
        $pdf->writeHTML($html, true, false, true, false, '');
        $pdf->Output('customer_summary.pdf', 'I');
    }
    
    public function generateCustomerDetailed($customers) {
        require_once __DIR__ . '/../vendors/TCPDF/tcpdf.php';
        
        $pdf = new TCPDF('L', 'mm', 'A4', true, 'UTF-8');
        $pdf->SetCreator('SAVANT MOTORS ERP');
        $pdf->SetTitle('Detailed Customer Report');
        $pdf->SetMargins(10, 10, 10);
        
        $pdf->AddPage();
        
        $html = "
            <h1 style='text-align:center;'>Detailed Customer Report</h1>
            <p style='text-align:center;'>Generated: " . date('Y-m-d H:i:s') . "</p>
            <br>
            <table border='1' cellpadding='4'>
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Name</th>
                        <th>Phone</th>
                        <th>Email</th>
                        <th>Tier</th>
                        <th>Total Spent</th>
                        <th>Points</th>
                        <th>Visits</th>
                        <th>Joined</th>
                    </tr>
                </thead>
                <tbody>
        ";
        
        foreach ($customers as $customer) {
            $html .= "
                <tr>
                    <td>{$customer['id']}</td>
                    <td>" . htmlspecialchars($customer['full_name']) . "</td>
                    <td>" . htmlspecialchars($customer['telephone']) . "</td>
                    <td>" . htmlspecialchars($customer['email']) . "</td>
                    <td>" . ucfirst($customer['customer_tier']) . "</td>
                    <td>" . number_format($customer['total_spent'] ?? 0) . "</td>
                    <td>" . ($customer['loyalty_points'] ?? 0) . "</td>
                    <td>" . ($customer['total_visits'] ?? 0) . "</td>
                    <td>" . ($customer['created_at'] ?? '') . "</td>
                </tr>
            ";
        }
        
        $html .= "
                </tbody>
            </table>
        ";
        
        $pdf->writeHTML($html, true, false, true, false, '');
        $pdf->Output('customer_detailed.pdf', 'I');
    }
}