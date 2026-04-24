<?php
// customers/edit.php - Simplified Working Version
session_start();
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Check login
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    header('Location: ../index.php');
    exit();
}

// Get customer ID
$customer_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($customer_id <= 0) {
    $_SESSION['error'] = "Invalid customer ID";
    header('Location: index.php');
    exit();
}

$error_message = '';
$success_message = '';

try {
    $conn = new PDO("mysql:host=localhost;dbname=savant_motors_pos", "root", "");
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    // Fetch customer
    $stmt = $conn->prepare("SELECT * FROM customers WHERE id = ?");
    $stmt->execute([$customer_id]);
    $customer = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$customer) {
        $_SESSION['error'] = "Customer not found";
        header('Location: index.php');
        exit();
    }
    
    // Update customer
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $stmt = $conn->prepare("
            UPDATE customers SET 
                full_name = ?,
                telephone = ?,
                email = ?,
                address = ?,
                updated_at = NOW()
            WHERE id = ?
        ");
        
        $stmt->execute([
            $_POST['full_name'] ?? '',
            $_POST['telephone'] ?? null,
            $_POST['email'] ?? null,
            $_POST['address'] ?? null,
            $customer_id
        ]);
        
        $success_message = "Customer updated successfully!";
        
        // Refresh customer data
        $stmt = $conn->prepare("SELECT * FROM customers WHERE id = ?");
        $stmt->execute([$customer_id]);
        $customer = $stmt->fetch(PDO::FETCH_ASSOC);
    }
    
} catch(PDOException $e) {
    $error_message = "Database error: " . $e->getMessage();
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Edit Customer | SAVANT MOTORS</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: Arial, sans-serif;
            background: #f0f2f5;
            padding: 40px;
        }
        .container {
            max-width: 600px;
            margin: 0 auto;
            background: white;
            border-radius: 16px;
            padding: 30px;
            box-shadow: 0 4px 12px rgba(0,0,0,0.1);
        }
        h1 {
            color: #1e40af;
            margin-bottom: 20px;
            font-size: 24px;
        }
        .form-group {
            margin-bottom: 15px;
        }
        label {
            display: block;
            font-weight: 600;
            margin-bottom: 5px;
            color: #333;
        }
        input, textarea {
            width: 100%;
            padding: 10px;
            border: 1px solid #ddd;
            border-radius: 8px;
            font-size: 14px;
        }
        input:focus, textarea:focus {
            outline: none;
            border-color: #2563eb;
        }
        button {
            background: #2563eb;
            color: white;
            border: none;
            padding: 12px 24px;
            border-radius: 8px;
            cursor: pointer;
            font-size: 16px;
            font-weight: 600;
        }
        button:hover {
            background: #1e40af;
        }
        .btn-cancel {
            background: #64748b;
            margin-left: 10px;
            text-decoration: none;
            display: inline-block;
        }
        .btn-cancel:hover {
            background: #475569;
        }
        .alert-success {
            background: #dcfce7;
            color: #166534;
            padding: 12px;
            border-radius: 8px;
            margin-bottom: 20px;
            border-left: 4px solid #10b981;
        }
        .alert-error {
            background: #fee2e2;
            color: #991b1b;
            padding: 12px;
            border-radius: 8px;
            margin-bottom: 20px;
            border-left: 4px solid #ef4444;
        }
        .form-actions {
            margin-top: 20px;
            display: flex;
            gap: 10px;
        }
        a {
            color: white;
            text-decoration: none;
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>✏️ Edit Customer</h1>
        
        <?php if ($success_message): ?>
            <div class="alert-success">✅ <?php echo htmlspecialchars($success_message); ?></div>
        <?php endif; ?>
        
        <?php if ($error_message): ?>
            <div class="alert-error">❌ <?php echo htmlspecialchars($error_message); ?></div>
        <?php endif; ?>
        
        <form method="POST">
            <div class="form-group">
                <label>Full Name *</label>
                <input type="text" name="full_name" value="<?php echo htmlspecialchars($customer['full_name'] ?? ''); ?>" required>
            </div>
            
            <div class="form-group">
                <label>Telephone</label>
                <input type="tel" name="telephone" value="<?php echo htmlspecialchars($customer['telephone'] ?? ''); ?>">
            </div>
            
            <div class="form-group">
                <label>Email</label>
                <input type="email" name="email" value="<?php echo htmlspecialchars($customer['email'] ?? ''); ?>">
            </div>
            
            <div class="form-group">
                <label>Address</label>
                <textarea name="address" rows="3"><?php echo htmlspecialchars($customer['address'] ?? ''); ?></textarea>
            </div>
            
            <div class="form-actions">
                <button type="submit">💾 Save Changes</button>
                <a href="view.php?id=<?php echo $customer_id; ?>" class="btn-cancel" style="background: #64748b; padding: 12px 24px; border-radius: 8px;">❌ Cancel</a>
            </div>
        </form>
    </div>
</body>
</html>