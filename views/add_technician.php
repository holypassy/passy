<?php
session_start();

if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    header('Location: index.php');
    exit();
}

$user_full_name = $_SESSION['full_name'] ?? 'User';
$user_role = $_SESSION['role'] ?? 'user';

$errors = [];
$success = false;

// Generate a new technician code (auto increment logic)
function generateTechnicianCode($conn) {
    // Format: TECH-XXXX (e.g., TECH-0001, TECH-0002, ...)
    $stmt = $conn->query("SELECT COUNT(*) as count FROM technicians");
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    $next = $row['count'] + 1;
    return 'TECH-' . str_pad($next, 4, '0', STR_PAD_LEFT);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Database connection
    try {
        $conn = new PDO("mysql:host=localhost;dbname=savant_motors_pos", "root", "");
        $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        
        // Get and sanitize inputs
        $full_name = trim($_POST['full_name'] ?? '');
        $phone = trim($_POST['phone'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $experience_years = (int)($_POST['experience_years'] ?? 0);
        $hire_date = $_POST['hire_date'] ?? '';
        $status = $_POST['status'] ?? 'active';
        
        // Validation
        if (empty($full_name)) {
            $errors[] = "Full name is required.";
        }
        if (empty($phone)) {
            $errors[] = "Phone number is required.";
        }
        if ($experience_years < 0) {
            $errors[] = "Experience years must be a positive number.";
        }
        if (!empty($email) && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors[] = "Invalid email format.";
        }
        
        // Check if phone or email already exists
        if (empty($errors)) {
            $stmt = $conn->prepare("SELECT id FROM technicians WHERE phone = ? OR (email != '' AND email = ?)");
            $stmt->execute([$phone, $email]);
            if ($stmt->fetch()) {
                $errors[] = "A technician with this phone number or email already exists.";
            }
        }
        
        // If no errors, insert
        if (empty($errors)) {
            $technician_code = generateTechnicianCode($conn);
            $is_blocked = 0; // default not blocked
            
            $sql = "INSERT INTO technicians (technician_code, full_name, phone, email, experience_years, hire_date, status, is_blocked, created_at) 
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())";
            $stmt = $conn->prepare($sql);
            $stmt->execute([
                $technician_code,
                $full_name,
                $phone,
                $email,
                $experience_years,
                $hire_date,
                $status,
                $is_blocked
            ]);
            
            $success = true;
            // Redirect after successful addition
            header('Location: technicians.php?success=added');
            exit();
        }
        
    } catch(PDOException $e) {
        $errors[] = "Database error: " . $e->getMessage();
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Add Technician | Savant Motors</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Inter', sans-serif;
            background: linear-gradient(135deg, #f0f9ff 0%, #e6f3ff 100%);
            min-height: 100vh;
        }

        :root {
            --primary: #1e40af;
            --primary-dark: #1e3a8a;
            --primary-light: #3b82f6;
            --success: #10b981;
            --danger: #ef4444;
            --warning: #f59e0b;
            --dark: #0f172a;
            --gray: #64748b;
            --light: #f8fafc;
            --border: #e2e8f0;
            --shadow: 0 1px 3px 0 rgb(0 0 0 / 0.1);
            --shadow-md: 0 4px 6px -1px rgb(0 0 0 / 0.1);
        }

        /* Navbar */
        .navbar {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(10px);
            border-bottom: 1px solid var(--border);
            padding: 1rem 2rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
            position: sticky;
            top: 0;
            z-index: 1000;
        }

        .logo-area {
            display: flex;
            align-items: center;
            gap: 20px;
        }

        .logo-icon {
            width: 40px;
            height: 40px;
            background: linear-gradient(135deg, var(--primary-light), var(--primary));
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 20px;
        }

        .logo-text {
            font-size: 20px;
            font-weight: 700;
            color: var(--dark);
        }

        .user-menu {
            display: flex;
            align-items: center;
            gap: 20px;
        }

        .user-info {
            text-align: right;
        }

        .user-name {
            font-weight: 600;
            color: var(--dark);
        }

        .user-role {
            font-size: 11px;
            color: var(--gray);
            text-transform: uppercase;
        }

        .logout-btn {
            background: #fef2f2;
            color: var(--danger);
            padding: 8px 16px;
            border-radius: 12px;
            text-decoration: none;
            font-size: 13px;
            font-weight: 500;
            display: flex;
            align-items: center;
            gap: 8px;
            transition: all 0.3s;
        }

        .logout-btn:hover {
            background: var(--danger);
            color: white;
            transform: translateY(-2px);
        }

        /* Sidebar */
        .sidebar {
            position: fixed;
            left: 0;
            top: 70px;
            width: 260px;
            height: calc(100vh - 70px);
            background: white;
            border-right: 1px solid var(--border);
            overflow-y: auto;
        }

        .sidebar-menu {
            padding: 20px 0;
        }

        .menu-item {
            padding: 12px 24px;
            display: flex;
            align-items: center;
            gap: 12px;
            color: var(--gray);
            text-decoration: none;
            transition: all 0.3s;
            border-left: 3px solid transparent;
            font-size: 14px;
            font-weight: 500;
        }

        .menu-item i {
            width: 20px;
        }

        .menu-item:hover, .menu-item.active {
            background: #f8fafc;
            border-left-color: var(--primary-light);
            color: var(--primary-light);
        }

        /* Main Content */
        .main-content {
            margin-left: 260px;
            padding: 30px;
            min-height: calc(100vh - 70px);
        }

        /* Form Container */
        .form-container {
            background: white;
            border-radius: 24px;
            border: 1px solid var(--border);
            padding: 30px;
            max-width: 700px;
            margin: 0 auto;
            box-shadow: var(--shadow-md);
        }

        .form-header {
            margin-bottom: 25px;
            padding-bottom: 20px;
            border-bottom: 1px solid var(--border);
        }

        .form-header h2 {
            font-size: 24px;
            font-weight: 700;
            color: var(--dark);
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .form-group {
            margin-bottom: 20px;
        }

        .form-group label {
            display: block;
            font-size: 13px;
            font-weight: 600;
            margin-bottom: 8px;
            color: var(--dark);
        }

        .form-group input, .form-group select {
            width: 100%;
            padding: 12px 16px;
            border: 2px solid var(--border);
            border-radius: 14px;
            font-size: 14px;
            font-family: 'Inter', sans-serif;
            transition: all 0.3s;
        }

        .form-group input:focus, .form-group select:focus {
            outline: none;
            border-color: var(--primary-light);
            box-shadow: 0 0 0 3px rgba(59,130,246,0.1);
        }

        .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
        }

        .error-messages {
            background: #fee2e2;
            border-left: 4px solid var(--danger);
            padding: 15px;
            border-radius: 12px;
            margin-bottom: 20px;
        }

        .error-messages ul {
            margin-left: 20px;
            color: #991b1b;
        }

        .form-actions {
            display: flex;
            gap: 15px;
            margin-top: 30px;
        }

        .btn {
            padding: 12px 24px;
            border: none;
            border-radius: 14px;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            font-family: 'Inter', sans-serif;
        }

        .btn-primary {
            background: linear-gradient(135deg, var(--primary-light), var(--primary));
            color: white;
        }

        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 20px rgba(59, 130, 246, 0.3);
        }

        .btn-secondary {
            background: white;
            color: var(--gray);
            border: 1px solid var(--border);
        }

        .btn-secondary:hover {
            background: var(--light);
        }

        @media (max-width: 768px) {
            .sidebar {
                left: -260px;
            }
            .main-content {
                margin-left: 0;
                padding: 20px;
            }
            .form-row {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>
<body>
    <!-- Navbar -->
    <div class="navbar">
        <div class="logo-area">
            <div class="logo-icon"><i class="fas fa-users-cog"></i></div>
            <div class="logo-text">SAVANT MOTORS</div>
        </div>
        <div class="user-menu">
            <div class="user-info">
                <div class="user-name"><?php echo htmlspecialchars($user_full_name); ?></div>
                <div class="user-role"><?php echo strtoupper(htmlspecialchars($user_role)); ?></div>
            </div>
            <a href="logout.php" class="logout-btn"><i class="fas fa-sign-out-alt"></i> Logout</a>
        </div>
    </div>

    <!-- Sidebar -->
    <div class="sidebar">
        <div class="sidebar-menu">
            <a href="dashboard_erp.php" class="menu-item"><i class="fas fa-chart-line"></i> Dashboard</a>
            <a href="job_cards.php" class="menu-item"><i class="fas fa-clipboard-list"></i> Job Cards</a>
            <a href="technicians.php" class="menu-item active"><i class="fas fa-users-cog"></i> Technicians</a>
            <a href="tools.php" class="menu-item"><i class="fas fa-tools"></i> Tool Management</a>
            <a href="tool_requests.php" class="menu-item"><i class="fas fa-clipboard-list"></i> Tool Requests</a>
            <a href="customers.php" class="menu-item"><i class="fas fa-users"></i> Customers</a>
        </div>
    </div>

    <!-- Main Content -->
    <div class="main-content">
        <div class="form-container">
            <div class="form-header">
                <h2><i class="fas fa-user-plus"></i> Add New Technician</h2>
                <p style="color: var(--gray); margin-top: 8px;">Fill in the details to register a new technician.</p>
            </div>

            <?php if (!empty($errors)): ?>
            <div class="error-messages">
                <ul>
                    <?php foreach ($errors as $error): ?>
                        <li><?php echo htmlspecialchars($error); ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
            <?php endif; ?>

            <form method="POST" action="">
                <div class="form-group">
                    <label>Full Name *</label>
                    <input type="text" name="full_name" value="<?php echo htmlspecialchars($_POST['full_name'] ?? ''); ?>" required>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label>Phone Number *</label>
                        <input type="tel" name="phone" value="<?php echo htmlspecialchars($_POST['phone'] ?? ''); ?>" required>
                    </div>
                    <div class="form-group">
                        <label>Email Address</label>
                        <input type="email" name="email" value="<?php echo htmlspecialchars($_POST['email'] ?? ''); ?>">
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label>Experience (Years)</label>
                        <input type="number" name="experience_years" value="<?php echo htmlspecialchars($_POST['experience_years'] ?? 0); ?>" min="0" step="0.5">
                    </div>
                    <div class="form-group">
                        <label>Hire Date</label>
                        <input type="date" name="hire_date" value="<?php echo htmlspecialchars($_POST['hire_date'] ?? date('Y-m-d')); ?>">
                    </div>
                </div>

                <div class="form-group">
                    <label>Status</label>
                    <select name="status">
                        <option value="active" <?php echo (isset($_POST['status']) && $_POST['status'] == 'active') ? 'selected' : ''; ?>>Active</option>
                        <option value="on_leave" <?php echo (isset($_POST['status']) && $_POST['status'] == 'on_leave') ? 'selected' : ''; ?>>On Leave</option>
                    </select>
                </div>

                <div class="form-actions">
                    <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Save Technician</button>
                    <a href="technicians.php" class="btn btn-secondary"><i class="fas fa-times"></i> Cancel</a>
                </div>
            </form>
        </div>
    </div>
</body>
</html>