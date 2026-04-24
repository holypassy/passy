<?php

function get_account_types() {
    return [
        'cash' => 'Cash',
        'bank' => 'Bank Account',
        'mobile_money' => 'Mobile Money',
        'petty_cash' => 'Petty Cash'
    ];
}

function get_transaction_types() {
    return [
        'income' => 'Income',
        'expense' => 'Expense'
    ];
}

function get_category_options() {
    return [
        'Sales' => 'Sales Revenue',
        'Services' => 'Service Income',
        'Rent' => 'Rent Expense',
        'Utilities' => 'Utilities',
        'Salaries' => 'Salaries & Wages',
        'Supplies' => 'Office Supplies',
        'Maintenance' => 'Maintenance',
        'Transport' => 'Transport',
        'Marketing' => 'Marketing',
        'Taxes' => 'Taxes',
        'Insurance' => 'Insurance',
        'Other' => 'Other'
    ];
}