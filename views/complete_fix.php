<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "<!DOCTYPE html>
<html>
<head>
    <title>Database Fix - Savant Motors</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 20px; background: #f5f5f5; }
        .container { max-width: 800px; margin: 0 auto; background: white; padding: 20px; border-radius: 10px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); }
        h2 { color: #1e40af; }
        h3 { color: #333; margin-top: 20px; }
        .success { color: green; background: #d4edda; padding: 10px; border-radius: 5px; margin: 10px 0; }
        .error { color: red; background: #f8d7da; padding: 10px; border-radius: 5px; margin: 10px 0; }
        .info { color: blue; background: #d1ecf1; padding: 10px; border-radius: 5px; margin: 10px 0; }
        pre { background: #f4f4f4; padding: 10px; border-radius: 5px; overflow-x: auto; }
        button { background: #3b82f6; color: white; border: none; padding: 10px 20px; border-radius: 5px; cursor: pointer; font-size: 16px; }
        button:hover { background: #1e40af; }
        .nav-links { margin-top: 20px; }
        .nav-links a { display: inline-block; margin-right: 10px; color: #3b82f6; text-decoration: none; }
        .nav-links a:hover { text-decoration: underline; }
    </style>
</head>
<body>
<div class='container'>
    <h2>🔧 Savant Motors Database Fix Tool</h2>";

try {
    $conn = new PDO("mysql:host=localhost;dbname=savant_motors_pos", "root", "");
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    echo "<div class='success'>✓ Connected to database successfully</div>";
    
    // Check current services table
    echo "<h3>📊 Current Services Table Status:</h3>";
    try {
        $columns = $conn->query("SHOW COLUMNS FROM services")->fetchAll(PDO::FETCH_ASSOC);
        if ($columns) {
            echo "<p>Current columns in services table:</p>";
            echo "<ul>";
            foreach ($columns as $col) {
                echo "<li><strong>{$col['Field']}</strong> - {$col['Type']}</li>";
            }
            echo "</ul>";
        } else {
            echo "<div class='info'>Services table exists but has no columns? This is unusual.</div>";
        }
    } catch (PDOException $e) {
        echo "<div class='info'>Services table doesn't exist yet. Will create it.</div>";
    }
    
    // Ask for confirmation
    echo "<h3>⚠️ Fix Options:</h3>";
    echo "<p>Choose how to fix the database:</p>";
    echo "<form method='POST' action=''>";
    echo "<button type='submit' name='action' value='add_columns' style='background:#f59e0b;'>Option 1: Add Missing Columns (Keep Data)</button> ";
    echo "<button type='submit' name='action' value='recreate' style='background:#ef4444;'>Option 2: Recreate Table (Delete All Data)</button>";
    echo "</form>";
    
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
        if ($_POST['action'] === 'add_columns') {
            echo "<h3>🔧 Adding Missing Columns...</h3>";
            
            // Get current columns
            $existingColumns = [];
            try {
                $cols = $conn->query("SHOW COLUMNS FROM services")->fetchAll(PDO::FETCH_COLUMN);
                $existingColumns = $cols;
            } catch (PDOException $e) {
                // Table doesn't exist
                $existingColumns = [];
            }
            
            // Define all required columns
            $requiredColumns = [
                'category' => "VARCHAR(100) AFTER service_name",
                'estimated_duration' => "VARCHAR(50) AFTER standard_price",
                'track_interval' => "TINYINT(1) DEFAULT 0 AFTER estimated_duration",
                'service_interval' => "INT DEFAULT NULL AFTER track_interval",
                'interval_unit' => "ENUM('days','weeks','months','years') DEFAULT 'months' AFTER service_interval",
                'has_expiry' => "TINYINT(1) DEFAULT 0 AFTER interval_unit",
                'expiry_days' => "INT DEFAULT NULL AFTER has_expiry",
                'expiry_unit' => "ENUM('days','weeks','months','years') DEFAULT 'months' AFTER expiry_days",
                'reminder_days' => "INT DEFAULT 7 AFTER expiry_unit",
                'service_includes' => "TEXT AFTER description",
                'requires_parts' => "TINYINT(1) DEFAULT 0 AFTER service_includes"
            ];
            
            $added = 0;
            foreach ($requiredColumns as $col => $definition) {
                if (!in_array($col, $existingColumns)) {
                    try {
                        $conn->exec("ALTER TABLE services ADD COLUMN $col $definition");
                        echo "<div class='success'>✓ Added column: $col</div>";
                        $added++;
                    } catch (PDOException $e) {
                        echo "<div class='error'>✗ Error adding $col: " . $e->getMessage() . "</div>";
                    }
                } else {
                    echo "<div class='info'>○ Column already exists: $col</div>";
                }
            }
            
            if ($added > 0) {
                echo "<div class='success'>✅ Added $added columns successfully!</div>";
            } else {
                echo "<div class='info'>All columns already exist.</div>";
            }
            
        } elseif ($_POST['action'] === 'recreate') {
            echo "<h3>🔄 Recreating Services Table...</h3>";
            
            // Drop existing table
            try {
                $conn->exec("DROP TABLE IF EXISTS services");
                echo "<div class='success'>✓ Dropped existing services table</div>";
            } catch (PDOException $e) {
                echo "<div class='error'>Error dropping table: " . $e->getMessage() . "</div>";
            }
            
            // Create fresh table
            $sql = "
                CREATE TABLE services (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    service_name VARCHAR(150) NOT NULL,
                    category VARCHAR(100),
                    standard_price DECIMAL(15,2) NOT NULL DEFAULT 0,
                    estimated_duration VARCHAR(50),
                    track_interval TINYINT(1) DEFAULT 0,
                    service_interval INT DEFAULT NULL,
                    interval_unit ENUM('days','weeks','months','years') DEFAULT 'months',
                    has_expiry TINYINT(1) DEFAULT 0,
                    expiry_days INT DEFAULT NULL,
                    expiry_unit ENUM('days','weeks','months','years') DEFAULT 'months',
                    reminder_days INT DEFAULT 7,
                    description TEXT,
                    service_includes TEXT,
                    requires_parts TINYINT(1) DEFAULT 0,
                    is_active TINYINT(1) DEFAULT 1,
                    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                    updated_at DATETIME ON UPDATE CURRENT_TIMESTAMP
                )
            ";
            
            try {
                $conn->exec($sql);
                echo "<div class='success'>✓ Created new services table with all columns</div>";
                
                // Insert sample data
                $sampleData = [
                    ['Oil Change', 'Maintenance', 50000, '30 mins', 1, 3, 'months', 0, NULL, 'months', 7, 'Regular oil change service', 'Oil change, filter replacement', 0],
                    ['Brake Service', 'Safety', 75000, '45 mins', 1, 6, 'months', 0, NULL, 'months', 7, 'Complete brake inspection', 'Brake pad replacement, fluid check', 1],
                    ['Engine Tune-up', 'Performance', 150000, '2 hours', 1, 12, 'months', 0, NULL, 'months', 14, 'Full engine service', 'Spark plugs, filters, fluid check', 1],
                    ['Wheel Alignment', 'Maintenance', 60000, '1 hour', 0, NULL, 'months', 0, NULL, 'months', 7, 'Wheel alignment', 'Alignment check and adjustment', 0],
                    ['AC Service', 'Comfort', 85000, '1.5 hours', 1, 6, 'months', 0, NULL, 'months', 7, 'AC service', 'AC gas refill, filter cleaning', 0]
                ];
                
                $stmt = $conn->prepare("
                    INSERT INTO services (
                        service_name, category, standard_price, estimated_duration,
                        track_interval, service_interval, interval_unit,
                        has_expiry, expiry_days, expiry_unit, reminder_days,
                        description, service_includes, requires_parts
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ");
                
                $count = 0;
                foreach ($sampleData as $data) {
                    if ($stmt->execute($data)) {
                        $count++;
                    }
                }
                echo "<div class='success'>✓ Added $count sample services</div>";
                
            } catch (PDOException $e) {
                echo "<div class='error'>Error creating table: " . $e->getMessage() . "</div>";
            }
        }
        
        // Show final structure
        echo "<h3>📋 Final Services Table Structure:</h3>";
        try {
            $columns = $conn->query("SHOW COLUMNS FROM services")->fetchAll(PDO::FETCH_ASSOC);
            echo "<table border='1' cellpadding='8' cellspacing='0' style='border-collapse: collapse; width: 100%;'>";
            echo "<tr style='background: #f0f0f0;'><th>Column</th><th>Type</th><th>Null</th><th>Default</th></tr>";
            foreach ($columns as $col) {
                echo "<tr>";
                echo "<td><strong>{$col['Field']}</strong></td>";
                echo "<td>{$col['Type']}</td>";
                echo "<td>{$col['Null']}</td>";
                echo "<td>{$col['Default']}</td>";
                echo "</tr>";
            }
            echo "</table>";
            
            // Show sample data
            $services = $conn->query("SELECT id, service_name, standard_price FROM services LIMIT 5")->fetchAll();
            if ($services) {
                echo "<h3>📦 Sample Services Data:</h3>";
                echo "<table border='1' cellpadding='8' cellspacing='0' style='border-collapse: collapse; width: 100%;'>";
                echo "<tr style='background: #f0f0f0;'><th>ID</th><th>Service Name</th><th>Price</th></tr>";
                foreach ($services as $service) {
                    echo "<tr>";
                    echo "<td>{$service['id']}</td>";
                    echo "<td>{$service['service_name']}</td>";
                    echo "<td>UGX " . number_format($service['standard_price']) . "</td>";
                    echo "</tr>";
                }
                echo "</table>";
            }
            
        } catch (PDOException $e) {
            echo "<div class='error'>Error showing final structure: " . $e->getMessage() . "</div>";
        }
        
        echo "<div class='success' style='margin-top: 20px;'>✅ Database fix completed!</div>";
        echo "<div class='nav-links'>";
        echo "<a href='services_products.php'>→ Go to Services & Products Page</a><br>";
        echo "<a href='services_products.php?t=services'>→ View Services List</a><br>";
        echo "<a href='services_products.php?t=products'>→ View Products List</a>";
        echo "</div>";
    }
    
} catch (PDOException $e) {
    echo "<div class='error'>Database connection error: " . $e->getMessage() . "</div>";
    echo "<div class='info'>Please check your database credentials in the connection string.</div>";
}

echo "</div></body></html>";
?>