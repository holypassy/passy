<?php

function format_currency($amount) {
    return 'UGX ' . number_format($amount, 0);
}

function format_currency_short($amount) {
    if ($amount >= 1000000) {
        return 'UGX ' . round($amount / 1000000, 1) . 'M';
    } elseif ($amount >= 1000) {
        return 'UGX ' . round($amount / 1000, 1) . 'K';
    }
    return 'UGX ' . number_format($amount, 0);
}

function get_transaction_badge($type) {
    if ($type === 'income') {
        return '<span class="badge badge-success"><i class="fas fa-arrow-down"></i> Income</span>';
    }
    return '<span class="badge badge-danger"><i class="fas fa-arrow-up"></i> Expense</span>';
}

function get_amount_class($type) {
    return $type === 'income' ? 'text-success' : 'text-danger';
}