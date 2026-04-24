<?php
return [
    'accounts' => [
        // Assets (1xxx)
        ['code' => '1000', 'name' => 'Cash and Cash Equivalents', 'type' => 'asset', 'normal' => 'debit'],
        ['code' => '1100', 'name' => 'Accounts Receivable', 'type' => 'asset', 'normal' => 'debit'],
        ['code' => '1200', 'name' => 'Inventory', 'type' => 'asset', 'normal' => 'debit'],
        ['code' => '1300', 'name' => 'Prepaid Expenses', 'type' => 'asset', 'normal' => 'debit'],
        ['code' => '1400', 'name' => 'Fixed Assets', 'type' => 'asset', 'normal' => 'debit'],
        ['code' => '1500', 'name' => 'Accumulated Depreciation', 'type' => 'asset', 'normal' => 'credit'],
        
        // Liabilities (2xxx)
        ['code' => '2000', 'name' => 'Accounts Payable', 'type' => 'liability', 'normal' => 'credit'],
        ['code' => '2100', 'name' => 'Accrued Expenses', 'type' => 'liability', 'normal' => 'credit'],
        ['code' => '2200', 'name' => 'Loans Payable', 'type' => 'liability', 'normal' => 'credit'],
        
        // Equity (3xxx)
        ['code' => '3000', 'name' => "Owner's Equity", 'type' => 'equity', 'normal' => 'credit'],
        ['code' => '3100', 'name' => 'Retained Earnings', 'type' => 'equity', 'normal' => 'credit'],
        
        // Revenue (4xxx)
        ['code' => '4000', 'name' => 'Sales Revenue', 'type' => 'revenue', 'normal' => 'credit'],
        ['code' => '4100', 'name' => 'Service Revenue', 'type' => 'revenue', 'normal' => 'credit'],
        ['code' => '4200', 'name' => 'Interest Income', 'type' => 'revenue', 'normal' => 'credit'],
        
        // Expenses (5xxx)
        ['code' => '5000', 'name' => 'Cost of Goods Sold', 'type' => 'expense', 'normal' => 'debit'],
        ['code' => '5100', 'name' => 'Salaries Expense', 'type' => 'expense', 'normal' => 'debit'],
        ['code' => '5200', 'name' => 'Rent Expense', 'type' => 'expense', 'normal' => 'debit'],
        ['code' => '5300', 'name' => 'Utilities Expense', 'type' => 'expense', 'normal' => 'debit'],
        ['code' => '5400', 'name' => 'Supplies Expense', 'type' => 'expense', 'normal' => 'debit'],
        ['code' => '5500', 'name' => 'Maintenance Expense', 'type' => 'expense', 'normal' => 'debit'],
    ]
];