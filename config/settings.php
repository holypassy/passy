<?php
return [
    'groups' => [
        'efris' => 'EFRIS Configuration',
        'royalty' => 'Customer Royalty',
        'discount' => 'Discount & Refund',
        'barcode' => 'Barcode & Scanner',
        'appearance' => 'Appearance & Print'
    ],
    'efris' => [
        'enabled' => false,
        'test_mode' => true,
        'url' => 'https://efris.ura.go.ug/api/v1'
    ],
    'royalty' => [
        'points_per_amount' => 1000,
        'amount_per_point' => 500,
        'tiers' => [
            'bronze' => 0,
            'silver' => 1000000,
            'gold' => 5000000,
            'platinum' => 10000000
        ]
    ],
    'discount' => [
        'max_percentage' => 20,
        'max_amount' => 500000,
        'approval_required' => true
    ],
    'barcode' => [
        'format' => 'CODE128',
        'width' => 50,
        'height' => 30
    ],
    'appearance' => [
        'header_bg' => '#1e3c72',
        'header_text' => '#ffffff',
        'print_font' => 'Times New Roman',
        'print_font_size' => 10
    ]
];