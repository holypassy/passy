<?php
namespace Utils;

class CurrencyFormatter {
    public static function format($amount, $currency = 'UGX') {
        return $currency . ' ' . number_format($amount, 0);
    }
    
    public static function formatWithSymbol($amount) {
        return 'UGX ' . number_format($amount, 0);
    }
    
    public static function formatShort($amount) {
        if ($amount >= 1000000) {
            return 'UGX ' . round($amount / 1000000, 1) . 'M';
        } elseif ($amount >= 1000) {
            return 'UGX ' . round($amount / 1000, 1) . 'K';
        }
        return 'UGX ' . number_format($amount, 0);
    }
}