<?php
class Format {
    public static function money($amount, $currency = 'UGX') {
        if ($currency === 'UGX') {
            return $currency . ' ' . number_format($amount, 0, '.', ',');
        }
        return $currency . ' ' . number_format($amount, 2);
    }
    
    public static function date($date, $format = 'd/m/Y') {
        if (!$date) return '-';
        return date($format, strtotime($date));
    }
    
    public static function datetime($datetime, $format = 'd/m/Y H:i') {
        if (!$datetime) return '-';
        return date($format, strtotime($datetime));
    }
    
    public static function number($number, $decimals = 0) {
        return number_format($number, $decimals, '.', ',');
    }
    
    public static function percentage($part, $total) {
        if ($total == 0) return '0%';
        return round(($part / $total) * 100, 1) . '%';
    }
    
    public static function truncate($text, $length = 50) {
        if (strlen($text) <= $length) return $text;
        return substr($text, 0, $length) . '...';
    }
    
    public static function slug($string) {
        $string = strtolower($string);
        $string = preg_replace('/[^a-z0-9-]/', '-', $string);
        $string = preg_replace('/-+/', '-', $string);
        return trim($string, '-');
    }
    
    public static function badge($status) {
        $badges = [
            'income' => '<span class="badge badge-success">Income</span>',
            'expense' => '<span class="badge badge-danger">Expense</span>',
            'pending' => '<span class="badge badge-warning">Pending</span>',
            'approved' => '<span class="badge badge-success">Approved</span>',
            'rejected' => '<span class="badge badge-danger">Rejected</span>',
            'cash' => '<span class="badge badge-info">Cash</span>',
            'bank' => '<span class="badge badge-primary">Bank</span>',
            'mobile_money' => '<span class="badge badge-secondary">Mobile Money</span>'
        ];
        
        return $badges[$status] ?? '<span class="badge badge-secondary">' . ucfirst($status) . '</span>';
    }
}
?>