<?php
// edit_account.php - Edit existing account
session_start();

if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    header('Location: index.php');
    exit();
}

$account_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($account_id <= 0) {
    header('Location: accounts.php');
    exit();
}

try {
    $conn = new PDO("mysql:host=localhost;dbname=savant_motors_pos", "root", "");
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    // Get account details
    $stmt = $conn->prepare("SELECT * FROM cash_accounts WHERE id = ?");
    $stmt->execute([$account_id]);
    $account = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$account) {
        header('Location: accounts.php');
        exit();
    }
    
    // Handle update
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_account'])) {
        $account_name = trim($_POST['account_name']);
        $account_type = $_POST['account_type'];
        $account_number = trim($_POST['account_number'] ?? '');
        
        $stmt = $conn->prepare("
            UPDATE cash_accounts 
            SET account_name = ?, account_type = ?, account_number = ?
            WHERE id = ?
        ");
        $stmt->execute([$account_name, $account_type, $account_number, $account_id]);
        
        $_SESSION['success'] = "Account updated successfully!";
        header('Location: accounts.php');
        exit();
    }
    
} catch(PDOException $e) {
    $error = $e->getMessage();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Edit Account | SAVANT MOTORS</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:opsz,wght@14..32,300;14..32,400;14..32,500;14..32,600;14..32,700;14..32,800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Inter', sans-serif; background: #f0f2f5; padding: 2rem; }
        .container { max-width: 500px; margin: 0 auto; background: white; border-radius: 1rem; padding: 2rem; }
        h1 { font-size: 1.5rem; margin-bottom: 1.5rem; color: #1e40af; }
        .form-group { margin-bottom: 1rem; }
        label { display: block; font-size: 0.75rem; font-weight: 700; margin-bottom: 0.25rem; color: #64748b; text-transform: uppercase; }
        input, select { width: 100%; padding: 0.6rem 0.75rem; border: 1.5px solid #e2e8f0; border-radius: 0.5rem; font-size: 0.85rem; }
        .btn { padding: 0.6rem 1.2rem; border-radius: 0.5rem; font-weight: 600; cursor: pointer; border: none; display: inline-flex; align-items: center; gap: 0.5rem; }
        .btn-primary { background: #3b82f6; color: white; }
        .btn-secondary { background: #e2e8f0; color: #0f172a; }
        .form-actions { display: flex; gap: 1rem; justify-content: flex-end; margin-top: 1.5rem; }
    </style>
</head>
<body>
    <div class="container">
        <h1><i class="fas fa-edit"></i> Edit Account</h1>
        <form method="POST">
            <div class="form-group">
                <label>Account Name *</label>
                <input type="text" name="account_name" value="<?php echo htmlspecialchars($account['account_name']); ?>" required>
            </div>
            <div class="form-group">
                <label>Account Type *</label>
                <select name="account_type" required>
                    <option value="cash" <?php echo $account['account_type'] == 'cash' ? 'selected' : ''; ?>>Cash</option>
                    <option value="bank" <?php echo $account['account_type'] == 'bank' ? 'selected' : ''; ?>>Bank Account</option>
                    <option value="mobile_money" <?php echo $account['account_type'] == 'mobile_money' ? 'selected' : ''; ?>>Mobile Money</option>
                    <option value="petty_cash" <?php echo $account['account_type'] == 'petty_cash' ? 'selected' : ''; ?>>Petty Cash</option>
                </select>
            </div>
            <div class="form-group">
                <label>Account Number</label>
                <input type="text" name="account_number" value="<?php echo htmlspecialchars($account['account_number'] ?? ''); ?>" placeholder="Optional">
            </div>
            <div class="form-group">
                <label>Current Balance</label>
                <input type="text" value="UGX <?php echo number_format($account['balance']); ?>" readonly disabled style="background: #f1f5f9;">
            </div>
            <div class="form-actions">
                <a href="accounts.php" class="btn btn-secondary">Cancel</a>
                <button type="submit" name="update_account" class="btn btn-primary">Save Changes</button>
            </div>
        </form>
    </div>
</body>
</html>