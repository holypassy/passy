<?php
// edit_technician.php – Edit technician details
session_start();

if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    header('Location: index.php');
    exit();
}

$user_full_name = $_SESSION['full_name'] ?? 'User';
$user_role = $_SESSION['role'] ?? 'user';

$technician_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($technician_id <= 0) {
    header('Location: technicians.php');
    exit();
}

$error_message = '';
$success_message = '';

try {
    $conn = new PDO("mysql:host=localhost;dbname=savant_motors_pos", "root", "");
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // Fetch technician details
    $stmt = $conn->prepare("SELECT * FROM technicians WHERE id = ?");
    $stmt->execute([$technician_id]);
    $technician = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$technician) {
        header('Location: technicians.php');
        exit();
    }

    // Process form submission
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_technician'])) {
        $full_name = trim($_POST['full_name']);
        $phone = trim($_POST['phone']);
        $email = trim($_POST['email']) ?: null;
        $experience_years = (int)$_POST['experience_years'];
        $hire_date = $_POST['hire_date'];
        $status = $_POST['status'];
        $is_blocked = isset($_POST['is_blocked']) ? 1 : 0;

        // Validation
        if (empty($full_name) || empty($phone)) {
            $error_message = "Full name and phone are required.";
        } else {
            $stmt = $conn->prepare("
                UPDATE technicians SET
                    full_name = ?,
                    phone = ?,
                    email = ?,
                    experience_years = ?,
                    hire_date = ?,
                    status = ?,
                    is_blocked = ?
                WHERE id = ?
            ");
            $stmt->execute([$full_name, $phone, $email, $experience_years, $hire_date, $status, $is_blocked, $technician_id]);

            $_SESSION['success'] = "Technician updated successfully!";
            header('Location: technicians.php');
            exit();
        }
    }

} catch(PDOException $e) {
    $error_message = "Database error: " . $e->getMessage();
}

$success_message = $_SESSION['success'] ?? null;
unset($_SESSION['success']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Edit Technician | Savant Motors</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        /* Same styles as add_technician.php (reuse from previous) */
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Inter', sans-serif; background: linear-gradient(135deg, #f0f9ff 0%, #e6f3ff 100%); min-height: 100vh; }
        :root { --primary: #1e40af; --primary-dark: #1e3a8a; --primary-light: #3b82f6; --success: #10b981; --danger: #ef4444; --warning: #f59e0b; --info: #3b82f6; --dark: #0f172a; --gray: #64748b; --light: #f8fafc; --border: #e2e8f0; --shadow: 0 1px 3px 0 rgb(0 0 0 / 0.1); --shadow-md: 0 4px 6px -1px rgb(0 0 0 / 0.1); --shadow-lg: 0 10px 15px -3px rgb(0 0 0 / 0.1); }
        .navbar { background: rgba(255,255,255,0.95); backdrop-filter: blur(10px); border-bottom: 1px solid var(--border); padding: 1rem 2rem; display: flex; justify-content: space-between; align-items: center; position: sticky; top: 0; z-index: 1000; }
        .logo-area { display: flex; align-items: center; gap: 20px; }
        .logo-icon { width: 40px; height: 40px; background: linear-gradient(135deg, var(--primary-light), var(--primary)); border-radius: 12px; display: flex; align-items: center; justify-content: center; color: white; font-size: 20px; }
        .logo-text { font-size: 20px; font-weight: 700; color: var(--dark); }
        .user-menu { display: flex; align-items: center; gap: 20px; }
        .user-info { text-align: right; }
        .user-name { font-weight: 600; color: var(--dark); }
        .user-role { font-size: 11px; color: var(--gray); text-transform: uppercase; }
        .logout-btn { background: #fef2f2; color: var(--danger); padding: 8px 16px; border-radius: 12px; text-decoration: none; font-size: 13px; font-weight: 500; display: flex; align-items: center; gap: 8px; transition: all 0.3s; }
        .logout-btn:hover { background: var(--danger); color: white; transform: translateY(-2px); }
        .sidebar { position: fixed; left: 0; top: 70px; width: 260px; height: calc(100vh - 70px); background: white; border-right: 1px solid var(--border); overflow-y: auto; }
        .sidebar-menu { padding: 20px 0; }
        .menu-item { padding: 12px 24px; display: flex; align-items: center; gap: 12px; color: var(--gray); text-decoration: none; transition: all 0.3s; border-left: 3px solid transparent; font-size: 14px; font-weight: 500; }
        .menu-item i { width: 20px; }
        .menu-item:hover, .menu-item.active { background: #f8fafc; border-left-color: var(--primary-light); color: var(--primary-light); }
        .main-content { margin-left: 260px; padding: 30px; min-height: calc(100vh - 70px); }
        .page-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 30px; flex-wrap: wrap; gap: 20px; }
        .page-title h1 { font-size: 28px; font-weight: 800; color: var(--dark); display: flex; align-items: center; gap: 12px; }
        .page-title h1 i { color: var(--primary-light); }
        .btn { padding: 12px 24px; border: none; border-radius: 14px; font-size: 13px; font-weight: 600; cursor: pointer; transition: all 0.3s; display: inline-flex; align-items: center; gap: 8px; font-family: 'Inter', sans-serif; text-decoration: none; }
        .btn-primary { background: linear-gradient(135deg, var(--primary-light), var(--primary)); color: white; }
        .btn-primary:hover { transform: translateY(-2px); box-shadow: 0 8px 20px rgba(59,130,246,0.3); }
        .btn-secondary { background: white; color: var(--gray); border: 1px solid var(--border); }
        .btn-secondary:hover { background: #f8fafc; border-color: var(--primary-light); color: var(--primary-light); }
        .form-container { background: white; border-radius: 24px; border: 1px solid var(--border); padding: 30px; max-width: 700px; margin: 0 auto; }
        .form-group { margin-bottom: 20px; }
        .form-group label { display: block; font-size: 13px; font-weight: 700; color: var(--gray); margin-bottom: 8px; text-transform: uppercase; }
        .form-group input, .form-group select { width: 100%; padding: 12px 16px; border: 2px solid var(--border); border-radius: 14px; font-size: 14px; transition: all 0.3s; }
        .form-group input:focus, .form-group select:focus { border-color: var(--primary-light); outline: none; box-shadow: 0 0 0 3px rgba(59,130,246,0.1); }
        .checkbox-group { display: flex; align-items: center; gap: 10px; margin-top: 5px; }
        .checkbox-group input { width: auto; margin-right: 5px; }
        .alert { padding: 12px 20px; border-radius: 12px; margin-bottom: 20px; display: flex; align-items: center; gap: 10px; }
        .alert-success { background: #dcfce7; border-left: 4px solid var(--success); color: #166534; }
        .alert-error { background: #fee2e2; border-left: 4px solid var(--danger); color: #991b1b; }
        .form-actions { display: flex; gap: 15px; justify-content: flex-end; margin-top: 30px; padding-top: 20px; border-top: 1px solid var(--border); }
        @media (max-width: 768px) { .sidebar { left: -260px; } .main-content { margin-left: 0; padding: 20px; } }
    </style>
</head>
<body>
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

    <div class="main-content">
        <div class="page-header">
            <div class="page-title">
                <h1><i class="fas fa-edit"></i> Edit Technician</h1>
            </div>
            <a href="technicians.php" class="btn btn-secondary"><i class="fas fa-arrow-left"></i> Back to List</a>
        </div>

        <?php if ($success_message): ?>
        <div class="alert alert-success"><i class="fas fa-check-circle"></i> <?php echo htmlspecialchars($success_message); ?></div>
        <?php endif; ?>
        <?php if ($error_message): ?>
        <div class="alert alert-error"><i class="fas fa-exclamation-triangle"></i> <?php echo htmlspecialchars($error_message); ?></div>
        <?php endif; ?>

        <div class="form-container">
            <form method="POST">
                <div class="form-group">
                    <label>Technician Code</label>
                    <input type="text" value="<?php echo htmlspecialchars($technician['technician_code']); ?>" readonly style="background:#f1f5f9;">
                </div>
                <div class="form-group">
                    <label>Full Name *</label>
                    <input type="text" name="full_name" value="<?php echo htmlspecialchars($technician['full_name']); ?>" required>
                </div>
                <div class="form-group">
                    <label>Phone *</label>
                    <input type="text" name="phone" value="<?php echo htmlspecialchars($technician['phone']); ?>" required>
                </div>
                <div class="form-group">
                    <label>Email</label>
                    <input type="email" name="email" value="<?php echo htmlspecialchars($technician['email']); ?>">
                </div>
                <div class="form-group">
                    <label>Experience (Years)</label>
                    <input type="number" name="experience_years" value="<?php echo $technician['experience_years']; ?>" min="0" step="0.5">
                </div>
                <div class="form-group">
                    <label>Hire Date</label>
                    <input type="date" name="hire_date" value="<?php echo $technician['hire_date']; ?>">
                </div>
                <div class="form-group">
                    <label>Status</label>
                    <select name="status">
                        <option value="active" <?php echo $technician['status'] == 'active' ? 'selected' : ''; ?>>Active</option>
                        <option value="on_leave" <?php echo $technician['status'] == 'on_leave' ? 'selected' : ''; ?>>On Leave</option>
                    </select>
                </div>
                <div class="form-group checkbox-group">
                    <input type="checkbox" name="is_blocked" id="is_blocked" value="1" <?php echo $technician['is_blocked'] ? 'checked' : ''; ?>>
                    <label for="is_blocked">Blocked (cannot be assigned tools)</label>
                </div>

                <div class="form-actions">
                    <button type="submit" name="update_technician" class="btn btn-primary"><i class="fas fa-save"></i> Update Technician</button>
                    <a href="technicians.php" class="btn btn-secondary">Cancel</a>
                </div>
            </form>
        </div>
    </div>
</body>
</html>