#!/usr/bin/env php
<?php
// create_users.php - Run this script to create initial users
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../models/User.php';

echo "========================================\n";
echo "SAVANT MOTORS UGANDA - User Creation\n";
echo "========================================\n\n";

$users = [
    [
        'username' => 'admin',
        'password' => 'Admin@123',
        'email' => 'admin@savantmotors.com',
        'full_name' => 'System Administrator',
        'role' => 'admin',
        'is_active' => 1
    ],
    [
        'username' => 'manager',
        'password' => 'Manager@123',
        'email' => 'manager@savantmotors.com',
        'full_name' => 'Operations Manager',
        'role' => 'manager',
        'is_active' => 1
    ],
    [
        'username' => 'cashier',
        'password' => 'Cashier@123',
        'email' => 'cashier@savantmotors.com',
        'full_name' => 'Head Cashier',
        'role' => 'cashier',
        'is_active' => 1
    ],
    [
        'username' => 'technician',
        'password' => 'Tech@123',
        'email' => 'tech@savantmotors.com',
        'full_name' => 'Senior Technician',
        'role' => 'technician',
        'is_active' => 1
    ]
];

$userModel = new User();
$created = 0;
$failed = 0;

foreach ($users as $user) {
    echo "Creating user: {$user['username']}... ";
    
    $result = $userModel->create($user);
    
    if ($result['success']) {
        echo "✓ SUCCESS (ID: {$result['user_id']})\n";
        $created++;
    } else {
        echo "✗ FAILED: {$result['message']}\n";
        $failed++;
    }
}

echo "\n========================================\n";
echo "Summary: $created created, $failed failed\n";
echo "========================================\n\n";

echo "Default Credentials:\n";
echo "-------------------\n";
foreach ($users as $user) {
    echo "Username: {$user['username']} | Password: {$user['password']}\n";
}
echo "\n";
?>