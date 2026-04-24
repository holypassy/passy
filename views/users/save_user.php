<?php
// save_user.php - Simplified Working Version
session_start();

// Enable error reporting
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Set JSON header
header('Content-Type: application/json');

// Check login
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    echo json_encode(['success' => false, 'message' => 'Not logged in']);
    exit();
}

// Check admin role
if ($_SESSION['role'] !== 'admin') {
    echo json_encode(['success' => false, 'message' => 'Admin access required']);
    exit();
}

// Check POST method
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
    exit();
}

try {
    // Database connection
    $conn = new PDO("mysql:host=localhost;dbname=savant_motors_pos", "root", "");
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    // Get form data
    $user_id = isset($_POST['user_id']) ? (int)$_POST['user_id'] : 0;
    $full_name = trim($_POST['full_name'] ?? '');
    $username = trim($_POST['username'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $role = $_POST['role'] ?? 'cashier';
    $password = $_POST['password'] ?? '';
    $confirm_password = $_POST['confirm_password'] ?? '';
    
    // Basic validation
    $errors = [];
    
    if (empty($full_name)) $errors[] = "Full name is required";
    if (empty($username)) $errors[] = "Username is required";
    if (empty($email)) $errors[] = "Email is required";
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = "Invalid email format";
    
    // New user password validation
    if ($user_id == 0) {
        if (empty($password)) $errors[] = "Password is required for new users";
        if ($password !== $confirm_password) $errors[] = "Passwords do not match";
        if (strlen($password) < 8 && !empty($password)) $errors[] = "Password must be at least 8 characters";
    } else {
        // For existing user, password is optional but must match if provided
        if (!empty($password) && $password !== $confirm_password) {
            $errors[] = "Passwords do not match";
        }
        if (!empty($password) && strlen($password) < 8) {
            $errors[] = "Password must be at least 8 characters";
        }
    }
    
    // Check if username exists
    if ($user_id == 0) {
        $stmt = $conn->prepare("SELECT COUNT(*) FROM users WHERE username = ?");
        $stmt->execute([$username]);
        if ($stmt->fetchColumn() > 0) {
            $errors[] = "Username already exists";
        }
    } else {
        $stmt = $conn->prepare("SELECT COUNT(*) FROM users WHERE username = ? AND id != ?");
        $stmt->execute([$username, $user_id]);
        if ($stmt->fetchColumn() > 0) {
            $errors[] = "Username already exists";
        }
    }
    
    // Check if email exists
    if ($user_id == 0) {
        $stmt = $conn->prepare("SELECT COUNT(*) FROM users WHERE email = ?");
        $stmt->execute([$email]);
        if ($stmt->fetchColumn() > 0) {
            $errors[] = "Email already exists";
        }
    } else {
        $stmt = $conn->prepare("SELECT COUNT(*) FROM users WHERE email = ? AND id != ?");
        $stmt->execute([$email, $user_id]);
        if ($stmt->fetchColumn() > 0) {
            $errors[] = "Email already exists";
        }
    }
    
    if (!empty($errors)) {
        echo json_encode(['success' => false, 'message' => implode("\n", $errors)]);
        exit();
    }
    
    // Save user
    if ($user_id == 0) {
        // Create new user
        $hashed_password = password_hash($password, PASSWORD_DEFAULT);
        
        $stmt = $conn->prepare("
            INSERT INTO users (full_name, username, email, password, role, is_active, created_at) 
            VALUES (?, ?, ?, ?, ?, 1, NOW())
        ");
        $stmt->execute([$full_name, $username, $email, $hashed_password, $role]);
        
        $new_id = $conn->lastInsertId();
        
        echo json_encode([
            'success' => true,
            'message' => 'User created successfully',
            'user_id' => $new_id
        ]);
    } else {
        // Update existing user
        $update_fields = [];
        $params = [];
        
        $update_fields[] = "full_name = ?";
        $params[] = $full_name;
        
        $update_fields[] = "username = ?";
        $params[] = $username;
        
        $update_fields[] = "email = ?";
        $params[] = $email;
        
        $update_fields[] = "role = ?";
        $params[] = $role;
        
        if (!empty($password)) {
            $hashed_password = password_hash($password, PASSWORD_DEFAULT);
            $update_fields[] = "password = ?";
            $params[] = $hashed_password;
        }
        
        $update_fields[] = "updated_at = NOW()";
        $params[] = $user_id;
        
        $sql = "UPDATE users SET " . implode(", ", $update_fields) . " WHERE id = ?";
        $stmt = $conn->prepare($sql);
        $stmt->execute($params);
        
        echo json_encode([
            'success' => true,
            'message' => 'User updated successfully',
            'user_id' => $user_id
        ]);
    }
    
} catch(PDOException $e) {
    echo json_encode([
        'success' => false,
        'message' => 'Database error: ' . $e->getMessage()
    ]);
}
?>