<?php
return [
    'categories' => [
        'General' => 'General Access',
        'Jobs' => 'Job Card Management',
        'Sales' => 'Sales & Quotations',
        'Finance' => 'Financial Operations',
        'Reports' => 'Reports & Analytics',
        'Admin' => 'Administration',
        'Inventory' => 'Inventory Management',
        'Tools' => 'Tool Management',
        'CRM' => 'Customer Management',
        'HR' => 'Human Resources',
        'Procurement' => 'Procurement & Purchasing',
        'Custom' => 'Custom Permissions'
    ],
    
    'default_roles' => [
        'admin' => [
            'view_dashboard', 'create_job_card', 'edit_job_card', 'delete_job_card', 'view_job_cards',
            'create_quotation', 'edit_quotation', 'delete_quotation', 'approve_quotation',
            'create_invoice', 'edit_invoice', 'delete_invoice', 'record_payment', 'void_transaction',
            'view_reports', 'export_data', 'manage_users', 'manage_roles', 'manage_permissions',
            'manage_inventory', 'view_inventory', 'adjust_stock', 'manage_tools', 'assign_tools',
            'view_tool_requests', 'approve_tool_requests', 'manage_customers', 'view_customers',
            'manage_technicians', 'view_attendance', 'record_attendance', 'edit_prices',
            'approve_discounts', 'manage_suppliers', 'create_purchase_order', 'approve_purchase_order'
        ],
        'manager' => [
            'view_dashboard', 'create_job_card', 'edit_job_card', 'view_job_cards',
            'create_quotation', 'edit_quotation', 'approve_quotation',
            'create_invoice', 'edit_invoice', 'record_payment',
            'view_reports', 'export_data',
            'view_inventory', 'view_tool_requests',
            'view_customers', 'view_attendance', 'edit_prices', 'approve_discounts'
        ],
        'cashier' => [
            'view_dashboard', 'view_job_cards', 'create_invoice', 'record_payment',
            'view_customers', 'view_inventory'
        ],
        'technician' => [
            'view_dashboard', 'view_job_cards', 'edit_job_card', 'view_inventory',
            'view_tool_requests', 'view_customers'
        ],
        'receptionist' => [
            'view_dashboard', 'create_job_card', 'view_job_cards', 'view_customers',
            'create_quotation', 'view_inventory'
        ]
    ]
];