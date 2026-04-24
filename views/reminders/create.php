<?php
// create.php - Handle form submission with 12-hour time format
session_start();

if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    header('Location: ../index.php');
    exit();
}

// Enable error reporting
error_reporting(E_ALL);
ini_set('display_errors', 1);

try {
    $conn = new PDO("mysql:host=localhost;dbname=savant_motors_pos", "root", "");
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    // Function to convert 12-hour time to 24-hour for storage
    function convertTo24Hour($hour, $minute, $ampm) {
        if (empty($hour) || empty($minute)) return null;
        
        $hour = intval($hour);
        $minute = intval($minute);
        
        if ($ampm == 'PM' && $hour != 12) {
            $hour += 12;
        } elseif ($ampm == 'AM' && $hour == 12) {
            $hour = 0;
        }
        
        return sprintf("%02d:%02d:00", $hour, $minute);
    }
    
    // Generate reminder number
    $reminderNumber = 'PKP-' . date('Ymd') . '-' . str_pad(rand(1, 9999), 4, '0', STR_PAD_LEFT);
    
    // Handle form submission
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_reminder'])) {
        
        // Convert times from 12-hour to 24-hour format
        $pickupTime = null;
        if (!empty($_POST['pickup_time_hour']) && !empty($_POST['pickup_time_minute'])) {
            $pickupTime = convertTo24Hour($_POST['pickup_time_hour'], $_POST['pickup_time_minute'], $_POST['pickup_ampm']);
        }
        
        $reminderTime = null;
        if (!empty($_POST['reminder_time_hour']) && !empty($_POST['reminder_time_minute'])) {
            $reminderTime = convertTo24Hour($_POST['reminder_time_hour'], $_POST['reminder_time_minute'], $_POST['reminder_ampm']);
        }
        
        $errors = [];
        
        if (empty($_POST['customer_id'])) {
            $errors[] = "Please select a customer";
        }
        if (empty($_POST['vehicle_reg'])) {
            $errors[] = "Please enter vehicle registration";
        }
        if (empty($_POST['pickup_date'])) {
            $errors[] = "Please select pickup date";
        }
        if (empty($_POST['pickup_type'])) {
            $errors[] = "Please select pickup type";
        }
        if ($_POST['pickup_type'] != 'workshop' && empty($_POST['pickup_address'])) {
            $errors[] = "Please enter pickup address for home/office pickup";
        }
        
        if (empty($errors)) {
            try {
                // Create table if not exists
                $conn->exec("
                    CREATE TABLE IF NOT EXISTS vehicle_pickup_reminders (
                        id INT AUTO_INCREMENT PRIMARY KEY,
                        reminder_number VARCHAR(50) UNIQUE,
                        customer_id INT,
                        customer_name VARCHAR(255),
                        customer_phone VARCHAR(50),
                        customer_email VARCHAR(255),
                        vehicle_reg VARCHAR(20),
                        vehicle_make VARCHAR(50),
                        vehicle_model VARCHAR(50),
                        pickup_type VARCHAR(20),
                        pickup_address TEXT,
                        pickup_location_details TEXT,
                        pickup_date DATE,
                        pickup_time TIME,
                        reminder_date DATE,
                        reminder_time TIME,
                        reminder_type VARCHAR(20) DEFAULT 'sms',
                        reminder_sent BOOLEAN DEFAULT 0,
                        notes TEXT,
                        assigned_to INT,
                        created_by INT,
                        status VARCHAR(20) DEFAULT 'scheduled',
                        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
                    )
                ");
                
                // Get customer details
                $customerStmt = $conn->prepare("SELECT full_name, telephone, email FROM customers WHERE id = ?");
                $customerStmt->execute([$_POST['customer_id']]);
                $customer = $customerStmt->fetch(PDO::FETCH_ASSOC);
                
                $stmt = $conn->prepare("
                    INSERT INTO vehicle_pickup_reminders (
                        reminder_number, customer_id, customer_name, customer_phone, customer_email,
                        vehicle_reg, vehicle_make, vehicle_model, pickup_type, pickup_address, 
                        pickup_location_details, pickup_date, pickup_time, reminder_date, reminder_time,
                        reminder_type, notes, assigned_to, created_by, status
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'scheduled')
                ");
                
                $stmt->execute([
                    $reminderNumber,
                    $_POST['customer_id'],
                    $customer['full_name'] ?? null,
                    $customer['telephone'] ?? null,
                    $customer['email'] ?? null,
                    $_POST['vehicle_reg'],
                    $_POST['vehicle_make'] ?? null,
                    $_POST['vehicle_model'] ?? null,
                    $_POST['pickup_type'],
                    $_POST['pickup_address'] ?? null,
                    $_POST['pickup_location_details'] ?? null,
                    $_POST['pickup_date'],
                    $pickupTime,
                    $_POST['reminder_date'],
                    $reminderTime,
                    $_POST['reminder_type'] ?? 'sms',
                    $_POST['notes'] ?? null,
                    !empty($_POST['assigned_to']) ? $_POST['assigned_to'] : null,
                    $_SESSION['user_id'] ?? 1
                ]);
                
                $_SESSION['success'] = "Pickup reminder created successfully! Reminder #: " . $reminderNumber;
                header("Location: index.php");
                exit();
                
            } catch(PDOException $e) {
                $_SESSION['error'] = "Database error: " . $e->getMessage();
                header("Location: index.php");
                exit();
            }
        } else {
            $_SESSION['error'] = implode("<br>", $errors);
            header("Location: index.php");
            exit();
        }
    }
    
} catch(PDOException $e) {
    $_SESSION['error'] = "Database connection error: " . $e->getMessage();
    header("Location: index.php");
    exit();
}
?>