<?php
class InvoiceHelper {
    
    public static function getStatusBadge($status) {
        $badges = [
            'draft' => '<span class="badge badge-draft"><i class="fas fa-pencil-alt"></i> Draft</span>',
            'sent' => '<span class="badge badge-sent"><i class="fas fa-paper-plane"></i> Sent</span>',
            'paid' => '<span class="badge badge-paid"><i class="fas fa-check-circle"></i> Paid</span>',
            'overdue' => '<span class="badge badge-overdue"><i class="fas fa-clock"></i> Overdue</span>',
            'cancelled' => '<span class="badge badge-cancelled"><i class="fas fa-ban"></i> Cancelled</span>'
        ];
        
        return $badges[$status] ?? '<span class="badge badge-secondary">' . ucfirst($status) . '</span>';
    }
    
    public static function getPaymentStatusBadge($status) {
        $badges = [
            'unpaid' => '<span class="badge badge-unpaid"><i class="fas fa-times-circle"></i> Unpaid</span>',
            'partial' => '<span class="badge badge-partial"><i class="fas fa-chart-line"></i> Partial</span>',
            'paid' => '<span class="badge badge-paid"><i class="fas fa-check-circle"></i> Paid</span>'
        ];
        
        return $badges[$status] ?? '<span class="badge badge-secondary">' . ucfirst($status) . '</span>';
    }
    
    public static function calculateDueStatus($dueDate, $paymentStatus) {
        if ($paymentStatus === 'paid') {
            return ['status' => 'paid', 'message' => 'Paid', 'days' => 0];
        }
        
        $today = new DateTime();
        $due = new DateTime($dueDate);
        $diff = $today->diff($due);
        
        if ($due < $today) {
            return ['status' => 'overdue', 'message' => "Overdue by {$diff->days} days", 'days' => -$diff->days];
        } elseif ($diff->days <= 7) {
            return ['status' => 'due_soon', 'message' => "Due in {$diff->days} days", 'days' => $diff->days];
        } else {
            return ['status' => 'pending', 'message' => "Due in {$diff->days} days", 'days' => $diff->days];
        }
    }
    
    public static function formatInvoiceNumber($number) {
        return '<span class="invoice-number">#' . htmlspecialchars($number) . '</span>';
    }
    
    public static function calculateTotals($items, $discount = 0, $tax = 0) {
        $subtotal = 0;
        foreach ($items as $item) {
            $subtotal += $item['quantity'] * $item['unit_price'];
        }
        
        $afterDiscount = $subtotal - $discount;
        $taxAmount = $afterDiscount * ($tax / 100);
        $total = $afterDiscount + $taxAmount;
        
        return [
            'subtotal' => $subtotal,
            'discount' => $discount,
            'tax' => $tax,
            'tax_amount' => $taxAmount,
            'total' => $total
        ];
    }
    
    public static function getInvoiceTemplate($invoice, $items) {
        $template = [
            'invoice_number' => $invoice['invoice_number'],
            'date' => $invoice['invoice_date'],
            'due_date' => $invoice['due_date'],
            'customer' => [
                'name' => $invoice['customer_name'],
                'phone' => $invoice['telephone'],
                'email' => $invoice['email'],
                'address' => $invoice['address'],
                'tax_id' => $invoice['tax_id'] ?? null
            ],
            'vehicle' => [
                'registration' => $invoice['vehicle_reg'],
                'model' => $invoice['vehicle_model'],
                'odometer' => $invoice['odometer_reading']
            ],
            'items' => $items,
            'subtotal' => $invoice['subtotal'],
            'discount' => $invoice['discount'],
            'tax' => $invoice['tax'],
            'total' => $invoice['total_amount'],
            'paid' => $invoice['amount_paid'],
            'balance' => $invoice['total_amount'] - $invoice['amount_paid'],
            'notes' => $invoice['notes'],
            'status' => $invoice['status'],
            'payment_status' => $invoice['payment_status']
        ];
        
        return $template;
    }
    
    public static function validateInvoiceData($data) {
        $errors = [];
        
        if (empty($data['customer_id'])) {
            $errors[] = 'Customer is required';
        }
        
        if (empty($data['invoice_date'])) {
            $errors[] = 'Invoice date is required';
        }
        
        if (empty($data['due_date'])) {
            $errors[] = 'Due date is required';
        }
        
        if (!empty($data['items'])) {
            $hasValidItem = false;
            foreach ($data['items'] as $item) {
                if (!empty($item['description']) && $item['unit_price'] > 0) {
                    $hasValidItem = true;
                    break;
                }
            }
            if (!$hasValidItem) {
                $errors[] = 'At least one valid item is required';
            }
        }
        
        return $errors;
    }
    
    public static function getQuickStats($invoices) {
        $stats = [
            'total' => count($invoices),
            'by_status' => [],
            'by_payment_status' => [],
            'total_amount' => 0,
            'total_paid' => 0,
            'outstanding' => 0
        ];
        
        foreach ($invoices as $inv) {
            $status = $inv['status'];
            $paymentStatus = $inv['payment_status'];
            
            if (!isset($stats['by_status'][$status])) {
                $stats['by_status'][$status] = 0;
            }
            if (!isset($stats['by_payment_status'][$paymentStatus])) {
                $stats['by_payment_status'][$paymentStatus] = 0;
            }
            
            $stats['by_status'][$status]++;
            $stats['by_payment_status'][$paymentStatus]++;
            $stats['total_amount'] += $inv['total_amount'];
            $stats['total_paid'] += $inv['amount_paid'];
        }
        
        $stats['outstanding'] = $stats['total_amount'] - $stats['total_paid'];
        
        return $stats;
    }
}
?>