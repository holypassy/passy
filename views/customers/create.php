<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);
session_start();

if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    header('Location: ../index.php');
    exit();
}

$user_id = $_SESSION['user_id'] ?? 1;
$error = '';

try {
    $conn = new PDO("mysql:host=localhost;dbname=savant_motors_pos", "root", "");
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // Ensure all required columns exist
    $columns = $conn->query("SHOW COLUMNS FROM customers")->fetchAll(PDO::FETCH_COLUMN);
    $missing = [];
    if (!in_array('customer_source', $columns)) $missing[] = "ADD COLUMN customer_source VARCHAR(50) DEFAULT 'Direct'";
    if (!in_array('customer_tier', $columns)) $missing[] = "ADD COLUMN customer_tier VARCHAR(20) DEFAULT 'bronze'";
    if (!in_array('created_by', $columns)) $missing[] = "ADD COLUMN created_by INT";
    if (!in_array('created_at', $columns)) $missing[] = "ADD COLUMN created_at DATETIME DEFAULT CURRENT_TIMESTAMP";
    if (!empty($missing)) {
        $conn->exec("ALTER TABLE customers " . implode(', ', $missing));
    }
} catch (PDOException $e) {
    $error = "DB setup error: " . $e->getMessage();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_customer'])) {
    $full_name = trim($_POST['full_name'] ?? '');
    $telephone = trim($_POST['telephone'] ?? '');
    $email     = trim($_POST['email'] ?? '');
    $source    = $_POST['customer_source'] ?? 'Direct';
    $tier      = $_POST['customer_tier'] ?? 'bronze';
    $status    = isset($_POST['status']) ? 1 : 0;

    if (empty($full_name) || empty($telephone)) {
        $error = "Full name and telephone are required.";
    } else {
        try {
            $conn = new PDO("mysql:host=localhost;dbname=savant_motors_pos", "root", "");
            $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

            $stmt = $conn->prepare("
                INSERT INTO customers 
                (full_name, telephone, email, customer_source, customer_tier, status, created_by, created_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, NOW())
            ");
            $stmt->execute([$full_name, $telephone, $email, $source, $tier, $status, $user_id]);

            $_SESSION['success'] = "Customer added successfully.";
            header('Location: index.php');
            exit();
        } catch (PDOException $e) {
            $error = "Database error: " . $e->getMessage();
        }
    }
}
?>
<!DOCTYPE html>
<html>
<head>
    <title>Add Customer | SAVANT MOTORS</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        body { font-family: 'Inter', sans-serif; background: #f5f7fb; padding: 2rem; }
        .container { max-width: 600px; margin: 0 auto; background: white; border-radius: 1rem; padding: 2rem; box-shadow: 0 4px 12px rgba(0,0,0,0.05); }
        h1 { font-size: 1.5rem; margin-bottom: 1.5rem; }
        .form-group { margin-bottom: 1rem; }
        label { display: block; font-weight: 600; margin-bottom: 0.3rem; font-size: 0.8rem; }
        input, select { width: 100%; padding: 0.6rem; border: 1px solid #e2e8f0; border-radius: 0.5rem; }
        button { background: #2563eb; color: white; padding: 0.6rem 1.2rem; border: none; border-radius: 0.5rem; cursor: pointer; }
        .error { background: #fee2e2; color: #991b1b; padding: 0.6rem; border-radius: 0.5rem; margin-bottom: 1rem; }
        .back-link { display: inline-block; margin-top: 1rem; color: #64748b; text-decoration: none; }
    </style>
</head>
<body>
<div class="container">
    <h1><i class="fas fa-user-plus"></i> Add New Customer</h1>
    <?php if ($error): ?>
        <div class="error"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>
    <form method="POST">
        <div class="form-group">
            <label>Full Name *</label>
            <input type="text" name="full_name" required>
        </div>
        <div class="form-group">
            <label>Telephone *</label>
            <input type="text" name="telephone" required>
        </div>
        <div class="form-group">
            <label>Email</label>
            <input type="email" name="email">
        </div>
        <div class="form-group">
            <label>Customer Source</label>
            <select name="customer_source">
                <option value="Direct">Direct</option>
                <option value="Referral">Referral</option>
                <option value="Website">Website</option>
                <option value="Job Card">Job Card</option>
            </select>
        </div>
        <div class="form-group">
            <label>Tier</label>
            <select name="customer_tier">
                <option value="bronze">Bronze</option>
                <option value="silver">Silver</option>
                <option value="gold">Gold</option>
                <option value="platinum">Platinum</option>
            </select>
        </div>
        <div class="form-group">
            <label><input type="checkbox" name="status" checked> Active</label>
        </div>
        <button type="submit" name="create_customer"><i class="fas fa-save"></i> Save Customer</button>
        <a href="index.php" class="back-link"><i class="fas fa-arrow-left"></i> Back to Customers</a>
    </form>
</div>
</body>
</html>