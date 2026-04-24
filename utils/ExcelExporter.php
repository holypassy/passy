<?php
namespace Utils;

class ExcelExporter {
    public function exportPurchases($purchases) {
        header('Content-Type: application/vnd.ms-excel');
        header('Content-Disposition: attachment; filename="purchases_' . date('Y-m-d') . '.xls"');
        
        echo '<html><body>';
        echo '<table border="1">';
        echo '<tr>';
        echo '<th>PO Number</th>';
        echo '<th>Date</th>';
        echo '<th>Supplier</th>';
        echo '<th>Total Amount</th>';
        echo '<th>Status</th>';
        echo '<th>Items Count</th>';
        echo '</tr>';
        
        foreach ($purchases as $purchase) {
            echo '<tr>';
            echo '<td>' . $purchase['po_number'] . '</td>';
            echo '<td>' . $purchase['purchase_date'] . '</td>';
            echo '<td>' . $purchase['supplier_name'] . '</td>';
            echo '<td>' . $purchase['total_amount'] . '</td>';
            echo '<td>' . $purchase['status'] . '</td>';
            echo '<td>' . ($purchase['item_count'] ?? 0) . '</td>';
            echo '</tr>';
        }
        
        echo '</table>';
        echo '</body></html>';
    }
    
    public function exportSuppliers($suppliers) {
        header('Content-Type: application/vnd.ms-excel');
        header('Content-Disposition: attachment; filename="suppliers_' . date('Y-m-d') . '.xls"');
        
        echo '<html><body>';
        echo '<table border="1">';
        echo '<tr>';
        echo '<th>Supplier Name</th>';
        echo '<th>Contact Person</th>';
        echo '<th>Telephone</th>';
        echo '<th>Email</th>';
        echo '<th>Address</th>';
        echo '<th>Payment Terms</th>';
        echo '<th>Total Spent</th>';
        echo '</tr>';
        
        foreach ($suppliers as $supplier) {
            echo '<tr>';
            echo '<td>' . $supplier['supplier_name'] . '</td>';
            echo '<td>' . ($supplier['contact_person'] ?? '') . '</td>';
            echo '<td>' . $supplier['telephone'] . '</td>';
            echo '<td>' . $supplier['email'] . '</td>';
            echo '<td>' . $supplier['address'] . '</td>';
            echo '<td>' . ($supplier['payment_terms'] ?? '') . '</td>';
            echo '<td>' . ($supplier['total_spent'] ?? 0) . '</td>';
            echo '</tr>';
        }
        
        echo '</table>';
        echo '</body></html>';
    }
}