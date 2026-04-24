<?php
namespace Utils;

require_once __DIR__ . '/../vendors/TCPDF/tcpdf.php';

class PDFGenerator {
    private $pdf;
    
    public function __construct() {
        $this->pdf = new \TCPDF(PDF_PAGE_ORIENTATION, PDF_UNIT, PDF_PAGE_FORMAT, true, 'UTF-8', false);
        $this->pdf->SetCreator('SAVANT MOTORS ERP');
        $this->pdf->SetAuthor('SAVANT MOTORS UGANDA');
        $this->pdf->SetTitle('Purchase Order');
        $this->pdf->SetMargins(15, 15, 15);
        $this->pdf->SetAutoPageBreak(TRUE, 15);
    }
    
    public function generatePurchaseOrder($purchase) {
        $this->pdf->AddPage();
        
        // Header
        $html = '
        <table style="width:100%; border-bottom:2px solid #1e3a6f; margin-bottom:20px;">
            <tr>
                <td style="width:60%;">
                    <h1 style="color:#1e3a6f;">SAVANT MOTORS UGANDA</h1>
                    <p>Kampala, Uganda<br>Tel: +256 XXX XXX XXX<br>Email: info@savantmotors.com</p>
                </td>
                <td style="text-align:right;">
                    <h2>PURCHASE ORDER</h2>
                    <p><strong>PO Number:</strong> ' . $purchase['po_number'] . '<br>
                    <strong>Date:</strong> ' . date('d/m/Y', strtotime($purchase['purchase_date'])) . '<br>
                    <strong>Status:</strong> ' . strtoupper($purchase['status']) . '</p>
                </td>
            </tr>
        </table>
        
        <table style="width:100%; margin-bottom:20px;">
            <tr>
                <td style="width:50%; background:#f8fafc; padding:10px;">
                    <h3>Supplier Information</h3>
                    <p><strong>' . $purchase['supplier_name'] . '</strong><br>
                    ' . $purchase['address'] . '<br>
                    Tel: ' . $purchase['telephone'] . '<br>
                    Email: ' . $purchase['email'] . '</p>
                </td>
                <td style="width:50%; background:#f8fafc; padding:10px;">
                    <h3>Delivery Information</h3>
                    <p><strong>Expected Delivery:</strong> ' . ($purchase['expected_delivery'] ? date('d/m/Y', strtotime($purchase['expected_delivery'])) : 'Not specified') . '<br>
                    <strong>Payment Terms:</strong> ' . ($purchase['payment_terms'] ?? 'Standard') . '<br>
                    <strong>Supplier Invoice:</strong> ' . ($purchase['supplier_invoice'] ?? 'N/A') . '</p>
                </td>
            </tr>
        </table>
        
        <table style="width:100%; border-collapse:collapse; margin-bottom:20px;">
            <thead>
                <tr>
                    <th style="background:#1e3a6f; color:white; padding:8px;">#</th>
                    <th style="background:#1e3a6f; color:white; padding:8px;">Item Code</th>
                    <th style="background:#1e3a6f; color:white; padding:8px;">Description</th>
                    <th style="background:#1e3a6f; color:white; padding:8px; text-align:center;">Qty</th>
                    <th style="background:#1e3a6f; color:white; padding:8px; text-align:right;">Unit Price</th>
                    <th style="background:#1e3a6f; color:white; padding:8px; text-align:right;">Discount</th>
                    <th style="background:#1e3a6f; color:white; padding:8px; text-align:right;">Total</th>
                </tr>
            </thead>
            <tbody>';
        
        $counter = 1;
        foreach ($purchase['items'] as $item) {
            $html .= '
                <tr>
                    <td style="border-bottom:1px solid #ddd; padding:8px;">' . $counter++ . '</td>
                    <td style="border-bottom:1px solid #ddd; padding:8px;">' . $item['item_code'] . '</td>
                    <td style="border-bottom:1px solid #ddd; padding:8px;">' . $item['product_name'] . '</td>
                    <td style="border-bottom:1px solid #ddd; padding:8px; text-align:center;">' . $item['quantity'] . ' ' . $item['unit_of_measure'] . '</td>
                    <td style="border-bottom:1px solid #ddd; padding:8px; text-align:right;">UGX ' . number_format($item['unit_price']) . '</td>
                    <td style="border-bottom:1px solid #ddd; padding:8px; text-align:right;">UGX ' . number_format($item['discount']) . '</td>
                    <td style="border-bottom:1px solid #ddd; padding:8px; text-align:right;">UGX ' . number_format($item['total']) . '</td>
                </tr>';
        }
        
        $html .= '
            </tbody>
        </table>
        
        <table style="width:100%; margin-top:20px;">
            <tr>
                <td style="width:60%;">
                    <p><strong>Notes:</strong><br>' . ($purchase['notes'] ?? 'No notes') . '</p>
                </td>
                <td style="width:40%; text-align:right;">
                    <p><strong>Subtotal:</strong> UGX ' . number_format($purchase['subtotal']) . '<br>
                    <strong>Discount:</strong> UGX ' . number_format($purchase['discount_total']) . '<br>
                    <strong>Tax (18%):</strong> UGX ' . number_format($purchase['tax_total']) . '<br>
                    <strong>Shipping:</strong> UGX ' . number_format($purchase['shipping_cost']) . '<br>
                    <strong style="font-size:14px;">Grand Total:</strong> <strong style="font-size:16px; color:#1e3a6f;">UGX ' . number_format($purchase['total_amount']) . '</strong></p>
                </td>
            </tr>
        </table>
        
        <div style="margin-top:50px; text-align:center; font-size:10px; color:#666;">
            <p>This is a computer-generated document. No signature is required.</p>
            <p>Generated on: ' . date('Y-m-d H:i:s') . '</p>
        </div>';
        
        $this->pdf->writeHTML($html, true, false, true, false, '');
        $this->pdf->Output('Purchase_Order_' . $purchase['po_number'] . '.pdf', 'I');
    }
    
    public function generatePurchaseReport($data, $filters) {
        $this->pdf->AddPage();
        
        $html = '
        <h1 style="text-align:center; color:#1e3a6f;">Purchase Report</h1>
        <p style="text-align:center;">Period: ' . ($filters['date_from'] ?? 'All') . ' to ' . ($filters['date_to'] ?? 'All') . '</p>
        
        <table style="width:100%; border-collapse:collapse; margin-top:20px;">
            <thead>
                <tr>
                    <th style="background:#1e3a6f; color:white; padding:8px;">PO Number</th>
                    <th style="background:#1e3a6f; color:white; padding:8px;">Date</th>
                    <th style="background:#1e3a6f; color:white; padding:8px;">Supplier</th>
                    <th style="background:#1e3a6f; color:white; padding:8px; text-align:right;">Amount</th>
                    <th style="background:#1e3a6f; color:white; padding:8px;">Status</th>
                </tr>
            </thead>
            <tbody>';
        
        foreach ($data as $purchase) {
            $html .= '
                <tr>
                    <td style="border-bottom:1px solid #ddd; padding:8px;">' . $purchase['po_number'] . '</td>
                    <td style="border-bottom:1px solid #ddd; padding:8px;">' . date('d/m/Y', strtotime($purchase['purchase_date'])) . '</td>
                    <td style="border-bottom:1px solid #ddd; padding:8px;">' . $purchase['supplier_name'] . '</td>
                    <td style="border-bottom:1px solid #ddd; padding:8px; text-align:right;">UGX ' . number_format($purchase['total_amount']) . '</td>
                    <td style="border-bottom:1px solid #ddd; padding:8px;">' . strtoupper($purchase['status']) . '</td>
                </tr>';
        }
        
        $html .= '
            </tbody>
        </table>';
        
        $this->pdf->writeHTML($html, true, false, true, false, '');
        $this->pdf->Output('Purchase_Report.pdf', 'I');
    }
}