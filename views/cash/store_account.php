<?php
// store_account.php - Process account creation separately
session_start();

if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    header('Location: index.php');
    exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: accounts.php');
    exit();
}

try {
    $conn = new PDO("mysql:host=localhost;dbname=savant_motors_pos", "root", "");
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    $account_name = trim($_POST['account_name']);
    $account_type = $_POST['account_type'];
    $account_number = trim($_POST['account_number'] ?? '');
    $initial_balance = floatval($_POST['initial_balance'] ?? 0);
    
    $errors = [];
    
    if (empty($account_name)) {
        $errors[] = "Account name is required";
    }
    if (empty($account_type)) {
        $errors[] = "Account type is required";
    }
    
    if (empty($errors)) {
        $stmt = $conn->prepare("
            INSERT INTO cash_accounts (account_name, account_type, account_number, balance, is_active) 
            VALUES (?, ?, ?, ?, 1)
        ");
        $stmt->execute([$account_name, $account_type, $account_number, $initial_balance]);
        
        $_SESSION['success'] = "Account created successfully!";
    } else {
        $_SESSION['error'] = implode("<br>", $errors);
    }
    
    header('Location: accounts.php');
    exit();
    
} catch(PDOException $e) {
    $_SESSION['error'] = "Database error: " . $e->getMessage();
    header('Location: accounts.php');
    exit();
}
?>