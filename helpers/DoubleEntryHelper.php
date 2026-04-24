<?php
namespace Utils;

class DoubleEntryHelper {
    
    public static function validateEntry($debitTotal, $creditTotal) {
        return abs($debitTotal - $creditTotal) < 0.01;
    }
    
    public static function calculateRunningBalance($transactions, $startingBalance = 0) {
        $balance = $startingBalance;
        foreach ($transactions as &$trans) {
            $balance += $trans['debit_amount'] - $trans['credit_amount'];
            $trans['running_balance'] = $balance;
        }
        return $transactions;
    }
    
    public static function getAccountNormalBalance($accountType) {
        $normalBalances = [
            'asset' => 'debit',
            'expense' => 'debit',
            'liability' => 'credit',
            'equity' => 'credit',
            'revenue' => 'credit'
        ];
        return $normalBalances[$accountType] ?? 'debit';
    }
    
    public static function formatAmount($amount) {
        return 'UGX ' . number_format($amount, 0);
    }
    
    public static function getAccountTypeIcon($type) {
        $icons = [
            'asset' => '💰',
            'liability' => '📝',
            'equity' => '🏛️',
            'revenue' => '📈',
            'expense' => '📉'
        ];
        return $icons[$type] ?? '📊';
    }
}