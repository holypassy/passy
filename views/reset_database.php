<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

try {
    $conn = new PDO("mysql:host=localhost;dbname=savant_motors_pos", "root", "");
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    echo "Connected to database successfully.<br>";
    
    // Drop existing tables (be careful with this!)
    echo "Dropping existing tables...<br>";
    $conn->exec("DROP TABLE IF EXISTS services");
    $conn->exec("DROP TABLE IF EXISTS inventory");
    echo "Tables dropped.<br>";
    
    // Create services table with all columns
    echo "Creating services table...<br>";
    $conn->exec("
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
    ");
    echo "Services table created.<br>";
    
    // Create inventory table with all columns
    echo "Creating inventory table...<br>";
    $conn->exec("
        CREATE TABLE inventory (
            id INT AUTO_INCREMENT PRIMARY KEY,
            item_code VARCHAR(100) NOT NULL UNIQUE,
            product_name VARCHAR(200) NOT NULL,
            category VARCHAR(100),
            unit_of_measure VARCHAR(50) DEFAULT 'piece',
            unit_cost DECIMAL(15,2) DEFAULT 0,
            selling_price DECIMAL(15,2) NOT NULL DEFAULT 0,
            quantity INT NOT NULL DEFAULT 0,
            reorder_level INT DEFAULT 5,
            description TEXT,
            is_active TINYINT(1) DEFAULT 1,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME ON UPDATE CURRENT_TIMESTAMP
        )
    ");
    echo "Inventory table created.<br>";
    
    // Insert sample data for testing
    echo "Inserting sample data...<br>";
    
    // Sample services
    $services = [
        ['Oil Change', 'Maintenance', 50000, '30 mins', 1, 3, 'months', 0, null, 'months', 7],
        ['Brake Service', 'Safety', 75000, '45 mins', 1, 6, 'months', 0, null, 'months', 7],
        ['Engine Tune-up', 'Performance', 150000, '2 hours', 1, 12, 'months', 0, null, 'months', 14],
        ['Wheel Alignment', 'Maintenance', 60000, '1 hour', 0, null, 'months', 0, null, 'months', 7],
        ['AC Service', 'Comfort', 85000, '1.5 hours', 1, 6, 'months', 0, null, 'months', 7]
    ];
    
    $stmt = $conn->prepare("
        INSERT INTO services (
            service_name, category, standard_price, estimated_duration,
            track_interval, service_interval, interval_unit,
            has_expiry, expiry_days, expiry_unit, reminder_days
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");
    
    foreach ($services as $service) {
        $stmt->execute($service);
    }
    echo "Sample services inserted.<br>";
    
    // Sample products
    $products = [
        ['ENG-001', 'Engine Oil 5W30', 'Lubricants', 'liter', 25000, 35000, 50, 10],
        ['FIL-001', 'Oil Filter', 'Filters', 'piece', 8000, 12000, 100, 15],
        ['BRK-001', 'Brake Pads', 'Brakes', 'set', 45000, 65000, 30, 8],
        ['AIR-001', 'Air Filter', 'Filters', 'piece', 12000, 18000, 45, 10],
        ['SPK-001', 'Spark Plugs', 'Electrical', 'set', 15000, 25000, 60, 12]
    ];
    
    $stmt = $conn->prepare("
        INSERT INTO inventory (
            item_code, product_name, category, unit_of_measure,
            unit_cost, selling_price, quantity, reorder_level
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?)
    ");
    
    foreach ($products as $product) {
        $stmt->execute($product);
    }
    echo "Sample products inserted.<br>";
    
    echo "<h3 style='color:green'>Database reset completed successfully!</h3>";
    echo "<a href='services_products.php'>Go to Services & Products page</a>";
    
} catch (PDOException $e) {
    die("<h3 style='color:red'>Error: " . $e->getMessage() . "</h3>");
}
?>