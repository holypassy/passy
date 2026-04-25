<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "<!DOCTYPE html>
<html>
<head>
    <title>Complete Database Setup - Savant Motors</title>
    <style>
        body { font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; margin: 0; padding: 20px; background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); min-height: 100vh; }
        .container { max-width: 900px; margin: 0 auto; background: white; padding: 30px; border-radius: 15px; box-shadow: 0 10px 30px rgba(0,0,0,0.2); }
        h1 { color: #333; margin-bottom: 10px; }
        h2 { color: #555; margin-top: 20px; border-bottom: 2px solid #667eea; padding-bottom: 10px; }
        .success { color: #155724; background: #d4edda; padding: 12px; border-radius: 8px; margin: 10px 0; border-left: 4px solid #28a745; }
        .error { color: #721c24; background: #f8d7da; padding: 12px; border-radius: 8px; margin: 10px 0; border-left: 4px solid #dc3545; }
        .info { color: #0c5460; background: #d1ecf1; padding: 12px; border-radius: 8px; margin: 10px 0; border-left: 4px solid #17a2b8; }
        .warning { color: #856404; background: #fff3cd; padding: 12px; border-radius: 8px; margin: 10px 0; border-left: 4px solid #ffc107; }
        pre { background: #f4f4f4; padding: 10px; border-radius: 5px; overflow-x: auto; font-size: 12px; }
        button { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; border: none; padding: 12px 24px; border-radius: 8px; cursor: pointer; font-size: 16px; margin: 10px 5px; transition: transform 0.2s; }
        button:hover { transform: translateY(-2px); }
        .nav-links { margin-top: 30px; padding-top: 20px; border-top: 1px solid #ddd; }
        .nav-links a { display: inline-block; margin-right: 15px; color: #667eea; text-decoration: none; font-weight: bold; }
        .nav-links a:hover { text-decoration: underline; }
        table { width: 100%; border-collapse: collapse; margin: 15px 0; }
        th, td { padding: 10px; text-align: left; border-bottom: 1px solid #ddd; }
        th { background: #f8f9fa; font-weight: bold; }
        tr:hover { background: #f5f5f5; }
    </style>
</head>
<body>
<div class='container'>
    <h1>🔧 Savant Motors - Complete Database Setup</h1>
    <p>This script will completely recreate your database tables with all required columns.</p>
    
    <div class='warning'>
        <strong>⚠️ Warning:</strong> This will delete all existing services and products data!
        <br>Make sure you have a backup if you need to preserve data.
    </div>
    
    <form method='POST' action=''>
        <button type='submit' name='setup' value='full'>🚀 Run Complete Database Setup</button>
        <button type='submit' name='setup' value='add_columns' style='background: #ffc107; color: #333;'>➕ Add Missing Columns Only (Keep Data)</button>
    </form>";

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['setup'])) {
    try {
        $conn = new PDO("mysql:host=localhost;dbname=savant_motors_pos", "root", "");
        $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        echo "<div class='success'>✅ Connected to database successfully</div>";
        
        if ($_POST['setup'] === 'full') {
            // FULL RESET - Drop and recreate everything
            echo "<h2>🔄 Performing Full Database Reset...</h2>";
            
            // Drop tables if they exist
            echo "<div class='info'>Dropping existing tables...</div>";
            $conn->exec("DROP TABLE IF EXISTS services");
            $conn->exec("DROP TABLE IF EXISTS inventory");
            echo "<div class='success'>✓ Tables dropped successfully</div>";
            
            // Create services table with ALL columns
            echo "<div class='info'>Creating services table with all columns...</div>";
            $sql_services = "
                CREATE TABLE services (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    service_name VARCHAR(150) NOT NULL,
                    category VARCHAR(100) DEFAULT NULL,
                    standard_price DECIMAL(15,2) NOT NULL DEFAULT 0,
                    estimated_duration VARCHAR(50) DEFAULT NULL,
                    track_interval TINYINT(1) NOT NULL DEFAULT 0,
                    service_interval INT DEFAULT NULL,
                    interval_unit ENUM('days','weeks','months','years') DEFAULT 'months',
                    has_expiry TINYINT(1) NOT NULL DEFAULT 0,
                    expiry_days INT DEFAULT NULL,
                    expiry_unit ENUM('days','weeks','months','years') DEFAULT 'months',
                    reminder_days INT NOT NULL DEFAULT 7,
                    description TEXT,
                    service_includes TEXT,
                    requires_parts TINYINT(1) NOT NULL DEFAULT 0,
                    is_active TINYINT(1) NOT NULL DEFAULT 1,
                    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    INDEX idx_service_name (service_name),
                    INDEX idx_category (category),
                    INDEX idx_is_active (is_active)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ";
            $conn->exec($sql_services);
            echo "<div class='success'>✓ Services table created with all 17 columns</div>";
            
            // Insert sample services
            echo "<div class='info'>Adding sample services...</div>";
            $sample_services = [
                ['Oil Change', 'Maintenance', 50000, '30 mins', 1, 3, 'months', 0, NULL, 'months', 7, 'Complete oil change service with filter replacement', 'Oil change, Oil filter replacement, Fluid check', 0],
                ['Brake Service', 'Safety', 75000, '45 mins', 1, 6, 'months', 0, NULL, 'months', 7, 'Comprehensive brake inspection and service', 'Brake pad replacement, Brake fluid check, Rotor inspection', 1],
                ['Engine Tune-up', 'Performance', 150000, '2 hours', 1, 12, 'months', 0, NULL, 'months', 14, 'Full engine performance optimization', 'Spark plugs replacement, Air filter, Fuel system cleaning', 1],
                ['Wheel Alignment', 'Maintenance', 60000, '1 hour', 0, NULL, 'months', 0, NULL, 'months', 7, 'Professional wheel alignment service', 'Wheel alignment, Tire rotation, Balance check', 0],
                ['AC Service', 'Comfort', 85000, '1.5 hours', 1, 6, 'months', 0, NULL, 'months', 7, 'Air conditioning system service', 'AC gas refill, Filter cleaning, System inspection', 0],
                ['Transmission Service', 'Major Service', 120000, '2.5 hours', 1, 24, 'months', 0, NULL, 'months', 14, 'Automatic transmission service', 'Transmission fluid change, Filter replacement, Inspection', 1],
                ['Coolant Flush', 'Maintenance', 45000, '1 hour', 1, 12, 'months', 0, NULL, 'months', 7, 'Engine coolant flush and replacement', 'Coolant flush, System pressure test, Additives', 0],
                ['Battery Service', 'Electrical', 25000, '30 mins', 1, 6, 'months', 0, NULL, 'months', 7, 'Battery testing and maintenance', 'Battery test, Terminal cleaning, Load test', 0],
                ['Timing Belt Replacement', 'Major Service', 250000, '4 hours', 1, 60, 'months', 0, NULL, 'months', 30, 'Timing belt replacement service', 'Timing belt, Tensioner, Water pump if needed', 1],
                ['Fuel System Cleaning', 'Performance', 95000, '1.5 hours', 1, 12, 'months', 0, NULL, 'months', 14, 'Fuel system decarbonization', 'Fuel injector cleaning, Throttle body cleaning', 0]
            ];
            
            $stmt = $conn->prepare("
                INSERT INTO services (
                    service_name, category, standard_price, estimated_duration,
                    track_interval, service_interval, interval_unit,
                    has_expiry, expiry_days, expiry_unit, reminder_days,
                    description, service_includes, requires_parts
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            
            $added = 0;
            foreach ($sample_services as $service) {
                if ($stmt->execute($service)) $added++;
            }
            echo "<div class='success'>✓ Added $added sample services</div>";
            
            // Create inventory table
            echo "<div class='info'>Creating inventory table...</div>";
            $sql_inventory = "
                CREATE TABLE inventory (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    item_code VARCHAR(100) NOT NULL UNIQUE,
                    product_name VARCHAR(200) NOT NULL,
                    category VARCHAR(100) DEFAULT NULL,
                    unit_of_measure VARCHAR(50) DEFAULT 'piece',
                    unit_cost DECIMAL(15,2) DEFAULT 0,
                    selling_price DECIMAL(15,2) NOT NULL DEFAULT 0,
                    quantity INT NOT NULL DEFAULT 0,
                    reorder_level INT DEFAULT 5,
                    description TEXT,
                    is_active TINYINT(1) NOT NULL DEFAULT 1,
                    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    INDEX idx_item_code (item_code),
                    INDEX idx_product_name (product_name),
                    INDEX idx_is_active (is_active)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ";
            $conn->exec($sql_inventory);
            echo "<div class='success'>✓ Inventory table created with all columns</div>";
            
            // Insert sample products
            echo "<div class='info'>Adding sample products...</div>";
            $sample_products = [
                ['ENG-001', 'Engine Oil 5W30', 'Lubricants', 'liter', 25000, 35000, 50, 10, 'High quality synthetic engine oil'],
                ['FIL-001', 'Oil Filter', 'Filters', 'piece', 8000, 12000, 100, 15, 'Standard oil filter for most vehicles'],
                ['BRK-001', 'Brake Pads', 'Brakes', 'set', 45000, 65000, 30, 8, 'Premium ceramic brake pads'],
                ['AIR-001', 'Air Filter', 'Filters', 'piece', 12000, 18000, 45, 10, 'High flow air filter'],
                ['SPK-001', 'Spark Plugs', 'Electrical', 'set', 15000, 25000, 60, 12, 'Iridium spark plugs set of 4'],
                ['BAT-001', 'Car Battery', 'Electrical', 'piece', 85000, 120000, 15, 5, 'Maintenance-free car battery'],
                ['TIR-001', 'Car Tires', 'Tires', 'piece', 120000, 180000, 40, 8, 'All-season radial tires'],
                ['CLN-001', 'Fuel Injector Cleaner', 'Chemicals', 'bottle', 5000, 8500, 200, 20, 'Fuel system cleaner additive'],
                ['BEL-001', 'Timing Belt', 'Engine', 'piece', 35000, 55000, 25, 5, 'Timing belt replacement kit'],
                ['FLU-001', 'Brake Fluid', 'Fluids', 'liter', 3000, 5000, 80, 15, 'DOT 4 brake fluid']
            ];
            
            $stmt = $conn->prepare("
                INSERT INTO inventory (
                    item_code, product_name, category, unit_of_measure,
                    unit_cost, selling_price, quantity, reorder_level, description
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            
            $added = 0;
            foreach ($sample_products as $product) {
                if ($stmt->execute($product)) $added++;
            }
            echo "<div class='success'>✓ Added $added sample products</div>";
            
        } elseif ($_POST['setup'] === 'add_columns') {
            // Just add missing columns
            echo "<h2>➕ Adding Missing Columns Only...</h2>";
            
            // Get current columns
            $columns = $conn->query("SHOW COLUMNS FROM services")->fetchAll(PDO::FETCH_COLUMN);
            echo "<div class='info'>Current columns: " . implode(', ', $columns) . "</div>";
            
            // All required columns
            $required = [
                'category' => "VARCHAR(100) AFTER service_name",
                'estimated_duration' => "VARCHAR(50) AFTER standard_price",
                'track_interval' => "TINYINT(1) NOT NULL DEFAULT 0 AFTER estimated_duration",
                'service_interval' => "INT DEFAULT NULL AFTER track_interval",
                'interval_unit' => "ENUM('days','weeks','months','years') DEFAULT 'months' AFTER service_interval",
                'has_expiry' => "TINYINT(1) NOT NULL DEFAULT 0 AFTER interval_unit",
                'expiry_days' => "INT DEFAULT NULL AFTER has_expiry",
                'expiry_unit' => "ENUM('days','weeks','months','years') DEFAULT 'months' AFTER expiry_days",
                'reminder_days' => "INT NOT NULL DEFAULT 7 AFTER expiry_unit",
                'description' => "TEXT AFTER reminder_days",
                'service_includes' => "TEXT AFTER description",
                'requires_parts' => "TINYINT(1) NOT NULL DEFAULT 0 AFTER service_includes"
            ];
            
            $added = 0;
            foreach ($required as $col => $def) {
                if (!in_array($col, $columns)) {
                    try {
                        $conn->exec("ALTER TABLE services ADD COLUMN $col $def");
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
                echo "<div class='success'>✅ Added $added missing columns!</div>";
            } else {
                echo "<div class='success'>✅ All columns already exist!</div>";
            }
        }
        
        // Display final table structure
        echo "<h2>📋 Final Services Table Structure</h2>";
        $columns = $conn->query("SHOW COLUMNS FROM services")->fetchAll(PDO::FETCH_ASSOC);
        echo "<table>";
        echo "<tr><th>Column</th><th>Type</th><th>Null</th><th>Default</th></tr>";
        foreach ($columns as $col) {
            echo "<tr>";
            echo "<td><strong>{$col['Field']}</strong></td>";
            echo "<td>{$col['Type']}</td>";
            echo "<td>{$col['Null']}</td>";
            echo "<td>{$col['Default']}</td>";
            echo "</tr>";
        }
        echo "</table>";
        
        // Display sample services
        echo "<h2>📦 Sample Services Data</h2>";
        $services = $conn->query("SELECT id, service_name, category, standard_price, description FROM services LIMIT 5")->fetchAll(PDO::FETCH_ASSOC);
        if ($services) {
            echo "<table>";
            echo "<tr><th>ID</th><th>Service Name</th><th>Category</th><th>Price</th><th>Description</th></tr>";
            foreach ($services as $service) {
                echo "<tr>";
                echo "<td>{$service['id']}</td>";
                echo "<td>{$service['service_name']}</td>";
                echo "<td>{$service['category']}</td>";
                echo "<td>UGX " . number_format($service['standard_price']) . "</td>";
                echo "<td>" . substr($service['description'], 0, 50) . "...</td>";
                echo "</tr>";
            }
            echo "</table>";
        } else {
            echo "<div class='info'>No services found. Add some services through the interface.</div>";
        }
        
        echo "<div class='success' style='margin-top: 20px;'>✅ Database setup completed successfully!</div>";
        
    } catch (PDOException $e) {
        echo "<div class='error'>❌ Database Error: " . $e->getMessage() . "</div>";
        echo "<div class='info'>Please check your database connection settings.</div>";
    }
}

echo "<div class='nav-links'>";
echo "<a href='services_products.php'>→ Go to Services & Products Page</a><br>";
echo "<a href='services_products.php?edit_service=new'>→ Add New Service</a><br>";
echo "<a href='services_products.php?edit_product=new'>→ Add New Product</a>";
echo "</div>";

echo "</div></body></html>";
?>