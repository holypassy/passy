<?php
// app/controllers/PickupReminderController.php
session_start();

class PickupReminderController {
    
    public function create() {
        // Check authentication
        if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
            header('Location: ../index.php');
            exit();
        }
        
        try {
            $conn = new PDO("mysql:host=localhost;dbname=savant_motors_pos", "root", "");
            $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            
            // Get customers for dropdown - THIS IS THE KEY FIX
            $customers = $conn->query("
                SELECT id, full_name, telephone, email, address 
                FROM customers 
                WHERE status = 1 
                ORDER BY full_name
            ")->fetchAll(PDO::FETCH_ASSOC);
            
            // Get staff for assignment
            $staff = $conn->query("
                SELECT id, full_name 
                FROM users 
                WHERE is_active = 1 
                ORDER BY full_name
            ")->fetchAll(PDO::FETCH_ASSOC);
            
            // Generate reminder number
            $reminderNumber = 'PKP-' . date('Ymd') . '-' . str_pad(rand(1, 9999), 4, '0', STR_PAD_LEFT);
            
        } catch(PDOException $e) {
            error_log("Error: " . $e->getMessage());
            $customers = [];
            $staff = [];
            $reminderNumber = 'PKP-' . date('Ymd') . '-' . rand(1000, 9999);
            $error_message = "Database error: " . $e->getMessage();
        }
        
        // Load the view and pass variables
        require_once __DIR__ . '/../views/reminders/create.php';
    }
    
    public function store() {
        // Check authentication
        if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
            header('Location: ../index.php');
            exit();
        }
        
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            header('Location: create.php');
            exit();
        }
        
        try {
            $conn = new PDO("mysql:host=localhost;dbname=savant_motors_pos", "root", "");
            $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            
            // Generate reminder number
            $reminderNumber = 'PKP-' . date('Ymd') . '-' . str_pad(rand(1, 9999), 4, '0', STR_PAD_LEFT);
            
            // Prepare and execute insert
            $stmt = $conn->prepare("
                INSERT INTO vehicle_pickup_reminders (
                    reminder_number, customer_id, vehicle_reg, vehicle_make, vehicle_model,
                    pickup_type, pickup_address, pickup_location_details, pickup_date, pickup_time,
                    reminder_date, reminder_time, reminder_type, notes, assigned_to, created_by, status
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'scheduled')
            ");
            
            $stmt->execute([
                $reminderNumber,
                $_POST['customer_id'],
                $_POST['vehicle_reg'],
                $_POST['vehicle_make'] ?? null,
                $_POST['vehicle_model'] ?? null,
                $_POST['pickup_type'],
                $_POST['pickup_address'] ?? null,
                $_POST['pickup_location_details'] ?? null,
                $_POST['pickup_date'],
                $_POST['pickup_time'] ?? null,
                $_POST['reminder_date'],
                $_POST['reminder_time'] ?? null,
                $_POST['reminder_type'] ?? 'sms',
                $_POST['notes'] ?? null,
                !empty($_POST['assigned_to']) ? $_POST['assigned_to'] : null,
                $_SESSION['user_id'] ?? 1
            ]);
            
            $_SESSION['success'] = "Pickup reminder created successfully!";
            header("Location: index.php");
            exit();
            
        } catch(PDOException $e) {
            error_log("Error creating reminder: " . $e->getMessage());
            $_SESSION['error'] = "Error creating reminder: " . $e->getMessage();
            header("Location: create.php");
            exit();
        }
    }
}