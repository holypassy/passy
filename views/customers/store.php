<?php
// customers/store.php - Process customer creation
session_start();

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
    
    // Validate required fields
    $errors = [];
    
    if (empty($_POST['full_name'])) {
        $errors[] = "Full name is required";
    }
    if (empty($_POST['telephone'])) {
        $errors[] = "Telephone number is required";
    }
    if (!empty($_POST['email']) && !filter_var($_POST['email'], FILTER_VALIDATE_EMAIL)) {
        $errors[] = "Invalid email address";
    }
    
    if (!empty($errors)) {
        $_SESSION['error'] = implode("<br>", $errors);
        header('Location: create.php');
        exit();
    }
    
    // Begin transaction
    $conn->beginTransaction();

    // Generate reminder number
    $reminderNumber = 'PKP-' . date('Ymd') . '-' . str_pad(rand(1, 9999), 4, '0', STR_PAD_LEFT);
    
    // Insert customer
    $stmt = $conn->prepare("
        INSERT INTO customers (
            full_name, telephone, email, address, tax_id, 
            credit_limit, preferred_contact, preferred_language, 
            customer_source, notes, assigned_sales_rep, customer_tier, 
            status, created_at
        ) VALUES (
            ?, ?, ?, ?, ?, 
            ?, ?, ?, 
            ?, ?, ?, ?, 
            1, NOW()
        )
    ");
    
    $stmt->execute([
        $_POST['full_name'],
        $_POST['telephone'] ?? null,
        $_POST['email'] ?? null,
        $_POST['address'] ?? null,
        $_POST['tax_id'] ?? null,
        $_POST['credit_limit'] ?? 0,
        $_POST['preferred_contact'] ?? 'phone',
        $_POST['preferred_language'] ?? 'English',
        $_POST['customer_source'] ?? null,
        $_POST['notes'] ?? null,
        !empty($_POST['assigned_sales_rep']) ? $_POST['assigned_sales_rep'] : null,
        $_POST['customer_tier'] ?? 'bronze'
    ]);
    
    $customer_id = $conn->lastInsertId();
    
    // Initialize loyalty record
    $loyaltyStmt = $conn->prepare("
        INSERT INTO customer_loyalty (customer_id, joined_date, loyalty_points, total_spent, total_visits) 
        VALUES (?, CURDATE(), 500, 0, 0)
    ");
    $loyaltyStmt->execute([$customer_id]);
    
    // Commit transaction
    $conn->commit();
    
    $_SESSION['success'] = "Customer created successfully!";
    header("Location: view.php?id=$customer_id");
    exit();

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
    $conn->rollBack();
    error_log("Error creating customer: " . $e->getMessage());
    $_SESSION['error'] = "Database error: " . $e->getMessage();
    header('Location: create.php');
    exit();
}
?>