<?php
class VoucherHelper {
    
    public static function getStatusBadge($status) {
        $badges = [
            'draft' => '<span class="badge badge-draft"><i class="fas fa-pencil-alt"></i> Draft</span>',
            'posted' => '<span class="badge badge-posted"><i class="fas fa-check-circle"></i> Posted</span>',
            'cancelled' => '<span class="badge badge-cancelled"><i class="fas fa-ban"></i> Cancelled</span>'
        ];
        
        return $badges[$status] ?? '<span class="badge badge-secondary">' . ucfirst($status) . '</span>';
    }
    
    public static function getPaymentModeBadge($mode) {
        $badges = [
            'cash' => '<span class="badge badge-cash"><i class="fas fa-money-bill-wave"></i> Cash</span>',
            'bank' => '<span class="badge badge-bank"><i class="fas fa-university"></i> Bank Transfer</span>',
            'cheque' => '<span class="badge badge-cheque"><i class="fas fa-check"></i> Cheque</span>',
            'mobile_money' => '<span class="badge badge-mobile"><i class="fas fa-mobile-alt"></i> Mobile Money</span>',
            'card' => '<span class="badge badge-card"><i class="fas fa-credit-card"></i> Card</span>'
        ];
        
        return $badges[$mode] ?? '<span class="badge badge-secondary">' . ucfirst($mode) . '</span>';
    }
    
    public static function formatVoucherNumber($number) {
        return '<span class="voucher-number">#' . htmlspecialchars($number) . '</span>';
    }
    
    public static function getVoucherTypeName($typeCode) {
        $types = [
            'RECEIPT' => 'Receipt Voucher',
            'PAYMENT' => 'Payment Voucher',
            'SALES' => 'Sales Voucher',
            'PURCHASE' => 'Purchase Voucher',
            'CONTRA' => 'Contra Voucher',
            'JOURNAL' => 'Journal Voucher'
        ];
        
        return $types[$typeCode] ?? $typeCode;
    }
    
    public static function getVoucherTemplate($voucher, $items, $paymentDetails) {
        $template = [
            'voucher_number' => $voucher['voucher_number'],
            'date' => $voucher['voucher_date'],
            'type' => $voucher['type_name'],
            'amount' => $voucher['amount'],
            'payment_mode' => $voucher['payment_mode'],
            'reference' => $voucher['reference_no'],
            'narration' => $voucher['narration'],
            'items' => $items,
            'payment_details' => $paymentDetails,
            'created_by' => $voucher['created_by_name'],
            'status' => $voucher['status']
        ];
        
        if ($voucher['type_code'] === 'RECEIPT') {
            $template['received_from'] = $voucher['received_from'];
        } elseif ($voucher['type_code'] === 'PAYMENT') {
            $template['paid_to'] = $voucher['paid_to'];
        }
        
        return $template;
    }
    
    public static function validateVoucherData($data) {
        $errors = [];
        
        if (empty($data['voucher_type'])) {
            $errors[] = 'Voucher type is required';
        }
        
        if (empty($data['voucher_date']))
                    $errors[] = 'Voucher date is required';
        }
        
        if (empty($data['amount']) || $data['amount'] <= 0) {
            $errors[] = 'Valid amount is required';
        }
        
        if (empty($data['items']) || count($data['items']) === 0) {
            $errors[] = 'At least one item is required';
        }
        
        return $errors;
    }
    
    public static function getPaymentModeFields($mode) {
        $fields = [
            'cash' => [],
            'bank' => ['bank_name', 'reference_no'],
            'cheque' => ['bank_name', 'cheque_number'],
            'mobile_money' => ['mobile_number', 'transaction_id'],
            'card' => ['card_number', 'reference_no']
        ];
        
        return $fields[$mode] ?? [];
    }
    
    public static function calculateTotalItems($items) {
        $total = 0;
        foreach ($items as $item) {
            $total += $item['amount'];
        }
        return $total;
    }
    
    public static function getQuickStats($vouchers) {
        $stats = [
            'total' => count($vouchers),
            'by_status' => [],
            'by_type' => [],
            'total_amount' => 0
        ];
        
        foreach ($vouchers as $v) {
            $status = $v['status'];
            $type = $v['type_code'];
            
            if (!isset($stats['by_status'][$status])) {
                $stats['by_status'][$status] = 0;
            }
            if (!isset($stats['by_type'][$type])) {
                $stats['by_type'][$type] = 0;
            }
            
            $stats['by_status'][$status]++;
            $stats['by_type'][$type]++;
            $stats['total_amount'] += $v['amount'];
        }
        
        return $stats;
    }
}
?>