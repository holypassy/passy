<?php
session_start();
error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "<h2>Savant Motors ERP - Diagnostic Tool</h2>";

// Test database connection
try {
    $conn = new PDO("mysql:host=localhost;dbname=savant_motors_pos", "root", "");
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    echo "<p style='color:green'>✓ Database connection successful</p>";
} catch (PDOException $e) {
    die("<p style='color:red'>✗ Database connection failed: " . $e->getMessage() . "</p>");
}

// Check if tables exist
$tables = ['services', 'inventory'];
foreach ($tables as $table) {
    $result = $conn->query("SHOW TABLES LIKE '$table'");
    if ($result->rowCount() > 0) {
        echo "<p style='color:green'>✓ Table '$table' exists</p>";
        
        // Show table structure
        $columns = $conn->query("SHOW COLUMNS FROM $table")->fetchAll(PDO::FETCH_ASSOC);
        echo "<div style='margin-left:20px'><strong>Columns in $table:</strong><br>";
        foreach ($columns as $col) {
            echo "- {$col['Field']} ({$col['Type']})<br>";
        }
        echo "</div>";
        
        // Count records
        $count = $conn->query("SELECT COUNT(*) as count FROM $table")->fetch();
        echo "<div style='margin-left:20px'>Records: {$count['count']}</div><br>";
    } else {
        echo "<p style='color:red'>✗ Table '$table' does NOT exist</p>";
    }
}

// Test session
echo "<h3>Session Data:</h3>";
echo "<pre>";
print_r($_SESSION);
echo "</pre>";

// Test if we can insert a test service
echo "<h3>Testing Insert Operations:</h3>";
try {
    $test = $conn->prepare("INSERT INTO services (service_name, standard_price) VALUES (:name, :price)");
    $test->execute([':name' => 'Test Service ' . date('Y-m-d H:i:s'), ':price' => 1000]);
    $id = $conn->lastInsertId();
    echo "<p style='color:green'>✓ Successfully inserted test service with ID: $id</p>";
    
    // Delete test record
    $conn->prepare("DELETE FROM services WHERE id = ?")->execute([$id]);
    echo "<p style='color:green'>✓ Test record cleaned up</p>";
} catch (PDOException $e) {
    echo "<p style='color:red'>✗ Insert test failed: " . $e->getMessage() . "</p>";
}

// Check if is_active column exists in inventory
$inventoryCols = $conn->query("SHOW COLUMNS FROM inventory")->fetchAll(PDO::FETCH_COLUMN);
if (!in_array('is_active', $inventoryCols)) {
    echo "<p style='color:orange'>⚠ 'is_active' column missing in inventory table. Run this SQL:</p>";
    echo "<pre>ALTER TABLE inventory ADD COLUMN is_active TINYINT(1) DEFAULT 1;</pre>";
}

echo "<h3>PHP Configuration:</h3>";
echo "Session Status: " . (session_status() == PHP_SESSION_ACTIVE ? "Active" : "Not Active") . "<br>";
echo "Upload Max Size: " . ini_get('upload_max_filesize') . "<br>";
echo "Post Max Size: " . ini_get('post_max_size') . "<br>";
echo "Display Errors: " . (ini_get('display_errors') ? "On" : "Off") . "<br>";
?>