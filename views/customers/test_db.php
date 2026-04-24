<?php
// test_db.php - Debug database connection
session_start();
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

echo "<h1>Database Connection Test</h1>";

try {
    $conn = new PDO("mysql:host=localhost;dbname=savant_motors_pos", "root", "");
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    echo "<p style='color:green'>✓ Database connected successfully!</p>";
    
    // Test customers table
    $stmt = $conn->query("SELECT COUNT(*) as count FROM customers");
    $result = $stmt->fetch();
    echo "<p>✓ Customers table found. Total customers: " . $result['count'] . "</p>";
    
    // Test if id=1 exists
    $stmt = $conn->prepare("SELECT * FROM customers WHERE id = 1");
    $stmt->execute();
    $customer = $stmt->fetch();
    if ($customer) {
        echo "<p>✓ Customer with ID 1 exists: " . $customer['full_name'] . "</p>";
    } else {
        echo "<p style='color:orange'>⚠ Customer with ID 1 not found. Try a different ID.</p>";
    }
    
} catch(PDOException $e) {
    echo "<p style='color:red'>✗ Database error: " . $e->getMessage() . "</p>";
}

echo "<h2>Test URL:</h2>";
echo "<p><a href='edit.php?id=1'>Click here to test edit.php with ID=1</a></p>";
?>