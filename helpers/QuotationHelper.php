<?php
class QuotationHelper {
    
    public static function getStatusBadge($status) {
        $badges = [
            'draft' => '<span class="badge badge-draft"><i class="fas fa-pencil-alt"></i> Draft</span>',
            'sent' => '<span class="badge badge-sent"><i class="fas fa-paper-plane"></i> Sent</span>',
            'accepted' => '<span class="badge badge-accepted"><i class="fas fa-check-circle"></i> Accepted</span>',
            'expired' => '<span class="badge badge-expired"><i class="fas fa-clock"></i> Expired</span>',
            'rejected' => '<span class="badge badge-rejected"><i class="fas fa-times-circle"></i> Rejected</span>'
        ];
        
        return $badges[$status] ?? '<span class="badge badge-secondary">' . ucfirst($status) . '</span>';
    }
    
    public static function calculateValidity($date) {
        $validUntil = new DateTime($date);
        $today = new DateTime();
        $diff = $today->diff($validUntil);
        
        if ($validUntil < $today) {
            return ['status' => 'expired', 'message' => 'Expired', 'days' => $diff->days];
        } elseif ($diff->days <= 7) {
            return ['status' => 'expiring_soon', 'message' => "Expires in {$diff->days} days", 'days' => $diff->days];
        } else {
            return ['status' => 'valid', 'message' => "Valid for {$diff->days} more days", 'days' => $diff->days];
        }
    }
    
    public static function formatQuotationNumber($number) {
        return '<span class="quotation-number">#' . htmlspecialchars($number) . '</span>';
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
    
    public static function getQuotationTemplate($quotation, $items) {
        $template = [
            'quotation_number' => $quotation['quotation_number'],
            'date' => $quotation['quotation_date'],
            'valid_until' => $quotation['valid_until'],
            'customer' => [
                'name' => $quotation['customer_name'],
                'phone' => $quotation['telephone'],
                'email' => $quotation['email'],
                'address' => $quotation['address']
            ],
            'vehicle' => [
                'registration' => $quotation['vehicle_reg'],
                'model' => $quotation['vehicle_model'],
                'odometer' => $quotation['odometer_reading']
            ],
            'items' => $items,
            'subtotal' => $quotation['subtotal'],
            'discount' => $quotation['discount'],
            'tax' => $quotation['tax'],
            'total' => $quotation['total_amount'],
            'notes' => $quotation['notes']
        ];
        
        return $template;
    }
    
    public static function validateQuotationData($data) {
        $errors = [];
        
        if (empty($data['customer_id'])) {
            $errors[] = 'Customer is required';
        }
        
        if (empty($data['quotation_date'])) {
            $errors[] = 'Quotation date is required';
        }
        
        if (empty($data['valid_until'])) {
            $errors[] = 'Valid until date is required';
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
    
    public static function getQuickStats($quotations) {
        $stats = [
            'total' => count($quotations),
            'by_status' => [],
            'total_value' => 0,
            'avg_value' => 0
        ];
        
        foreach ($quotations as $q) {
            $status = $q['status'];
            if (!isset($stats['by_status'][$status])) {
                $stats['by_status'][$status] = 0;
            }
            $stats['by_status'][$status]++;
            $stats['total_value'] += $q['total_amount'];
        }
        
        $stats['avg_value'] = $stats['total'] > 0 ? $stats['total_value'] / $stats['total'] : 0;
        
        return $stats;
    }
}
?>