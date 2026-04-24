<?php

function getAccountTypeClass($type) {
    $classes = [
        'asset' => 'text-success',
        'liability' => 'text-warning',
        'equity' => 'text-info',
        'revenue' => 'text-primary',
        'expense' => 'text-danger'
    ];
    return $classes[$type] ?? 'text-muted';
}

function formatLedgerAmount($amount, $type = 'debit') {
    if ($amount == 0) return '-';
    $prefix = $type == 'debit' ? 'DR ' : 'CR ';
    return $prefix . 'UGX ' . number_format($amount, 0);
}

function calculateAccountBalance($debits, $credits, $normalBalance) {
    if ($normalBalance == 'debit') {
        return $debits - $credits;
    }
    return $credits - $debits;
}

function getFinancialYear($date = null) {
    $date = $date ?: date('Y-m-d');
    $year = date('Y', strtotime($date));
    $month = date('m', strtotime($date));
    
    if ($month >= 7) {
        return $year . '/' . ($year + 1);
    }
    return ($year - 1) . '/' . $year;
}