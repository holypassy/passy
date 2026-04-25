<?php
session_start();

if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    header('Location: index.php');
    exit();
}

$user_full_name = $_SESSION['full_name'] ?? 'User';
$user_role = $_SESSION['role'] ?? 'user';
$user_id = $_SESSION['user_id'] ?? 1;

$staff = null;
$error_message = '';
$success_message = '';

if (!isset($_GET['id']) || empty($_GET['id'])) {
    header('Location: technicians.php');
    exit();
}

$staff_id = (int)$_GET['id'];

try {
    $conn = new PDO("mysql:host=localhost;dbname=savant_motors_pos", "root", "");
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    // Get departments for dropdown
    $stmt = $conn->query("SELECT department_name FROM departments WHERE is_active = 1 ORDER BY department_name");
    $departments = $stmt->fetchAll(PDO::FETCH_COLUMN);
    
    // Get positions from staff table (distinct positions)
    $stmt = $conn->query("SELECT DISTINCT position FROM staff WHERE position IS NOT NULL AND position != '' ORDER BY position");
    $positions = $stmt->fetchAll(PDO::FETCH_COLUMN);
    
    // Get staff details
    $stmt = $conn->prepare("
        SELECT * FROM staff 
        WHERE id = :id AND (deleted_at IS NULL OR deleted_at = '0000-00-00 00:00:00')
    ");
    $stmt->execute([':id' => $staff_id]);
    $staff = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$staff) {
        header('Location: technicians.php');
        exit();
    }
    
    // Handle form submission
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_staff'])) {
        
        // Validate required fields
        if (empty($_POST['full_name'])) {
            $error_message = 'Full name is required';
        } elseif (empty($_POST['position'])) {
            $error_message = 'Position is required';
        } elseif (empty($_POST['department'])) {
            $error_message = 'Department is required';
        } elseif (empty($_POST['phone'])) {
            $error_message = 'Phone number is required';
        } else {
            
            $full_name = trim($_POST['full_name']);
            $position = trim($_POST['position']);
            $department = trim($_POST['department']);
            $phone = trim($_POST['phone']);
            $email = !empty($_POST['email']) ? trim($_POST['email']) : null;
            $address = !empty($_POST['address']) ? trim($_POST['address']) : null;
            $hire_date = !empty($_POST['hire_date']) ? $_POST['hire_date'] : null;
            $salary = !empty($_POST['salary']) ? floatval($_POST['salary']) : null;
            $emergency_contact_name = !empty($_POST['emergency_contact_name']) ? trim($_POST['emergency_contact_name']) : null;
            $emergency_contact_phone = !empty($_POST['emergency_contact_phone']) ? trim($_POST['emergency_contact_phone']) : null;
            $status = $_POST['status'] ?? 'active';
            $is_blocked = isset($_POST['is_blocked']) ? 1 : 0;
            $blocked_reason = !empty($_POST['blocked_reason']) ? trim($_POST['blocked_reason']) : null;
            
            // Check if email already exists for another staff
            if ($email) {
                $checkStmt = $conn->prepare("SELECT id FROM staff WHERE email = :email AND id != :id AND deleted_at IS NULL");
                $checkStmt->execute([':email' => $email, ':id' => $staff_id]);
                if ($checkStmt->fetch()) {
                    $error_message = 'Email address already exists for another staff member';
                }
            }
            
            // Check if phone already exists for another staff
            if (empty($error_message)) {
                $checkStmt = $conn->prepare("SELECT id FROM staff WHERE phone = :phone AND id != :id AND deleted_at IS NULL");
                $checkStmt->execute([':phone' => $phone, ':id' => $staff_id]);
                if ($checkStmt->fetch()) {
                    $error_message = 'Phone number already exists for another staff member';
                }
            }
            
            if (empty($error_message)) {
                try {
                    // Update staff member
                    $stmt = $conn->prepare("
                        UPDATE staff SET 
                            full_name = :full_name,
                            position = :position,
                            department = :department,
                            phone = :phone,
                            email = :email,
                            address = :address,
                            hire_date = :hire_date,
                            salary = :salary,
                            emergency_contact_name = :emergency_contact_name,
                            emergency_contact_phone = :emergency_contact_phone,
                            status = :status,
                            is_blocked = :is_blocked,
                            blocked_reason = :blocked_reason,
                            updated_at = NOW()
                        WHERE id = :id
                    ");
                    
                    $stmt->execute([
                        ':full_name' => $full_name,
                        ':position' => $position,
                        ':department' => $department,
                        ':phone' => $phone,
                        ':email' => $email,
                        ':address' => $address,
                        ':hire_date' => $hire_date,
                        ':salary' => $salary,
                        ':emergency_contact_name' => $emergency_contact_name,
                        ':emergency_contact_phone' => $emergency_contact_phone,
                        ':status' => $status,
                        ':is_blocked' => $is_blocked,
                        ':blocked_reason' => $blocked_reason,
                        ':id' => $staff_id
                    ]);
                    
                    // Log the activity
                    $logStmt = $conn->prepare("
                        INSERT INTO staff_activity_log (staff_id, action, description, ip_address, user_agent)
                        VALUES (:staff_id, 'updated', :description, :ip, :ua)
                    ");
                    $logStmt->execute([
                        ':staff_id' => $staff_id,
                        ':description' => "Staff member {$full_name} was updated by {$user_full_name}",
                        ':ip' => $_SERVER['REMOTE_ADDR'] ?? null,
                        ':ua' => $_SERVER['HTTP_USER_AGENT'] ?? null
                    ]);
                    
                    $_SESSION['success'] = "Staff member updated successfully!";
                    header('Location: view_staff.php?id=' . $staff_id);
                    exit();
                    
                } catch(PDOException $e) {
                    $error_message = 'Database error: ' . $e->getMessage();
                }
            }
        }
    }
    
} catch(PDOException $e) {
    $error_message = 'Connection error: ' . $e->getMessage();
    $departments = [];
    $positions = [];
}

$success_message = $_SESSION['success'] ?? null;
unset($_SESSION['success']);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Edit Staff | <?php echo htmlspecialchars($staff['full_name'] ?? 'Staff'); ?> | Savant Motors</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Calibri', 'Segoe UI', 'Inter', sans-serif;
            background: radial-gradient(circle at 10% 30%, rgba(59,130,246,0.05), rgba(15,23,42,0.02));
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
            --shadow-sm: 0 1px 2px 0 rgb(0 0 0 / 0.05);
            --shadow-md: 0 4px 6px -1px rgb(0 0 0 / 0.1);
            --transition: all 0.2s ease;
        }

        .navbar {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(12px);
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
            background: linear-gradient(135deg, var(--dark), var(--primary-dark));
            -webkit-background-clip: text;
            background-clip: text;
            color: transparent;
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
        }

        .logout-btn {
            background: rgba(239, 68, 68, 0.1);
            color: var(--danger);
            padding: 8px 16px;
            border-radius: 40px;
            text-decoration: none;
            font-size: 13px;
            font-weight: 500;
            display: flex;
            align-items: center;
            gap: 8px;
            transition: var(--transition);
        }

        .logout-btn:hover {
            background: var(--danger);
            color: white;
        }

        .main-content {
            max-width: 1000px;
            margin: 40px auto;
            padding: 0 20px;
        }

        .page-header {
            margin-bottom: 30px;
        }

        .page-header h1 {
            font-size: 28px;
            font-weight: 800;
            background: linear-gradient(135deg, var(--dark), var(--primary-dark));
            -webkit-background-clip: text;
            background-clip: text;
            color: transparent;
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .breadcrumb {
            display: flex;
            align-items: center;
            gap: 8px;
            margin-top: 8px;
            color: var(--gray);
            font-size: 13px;
        }

        .breadcrumb a {
            color: var(--primary);
            text-decoration: none;
        }

        .breadcrumb a:hover {
            text-decoration: underline;
        }

        .form-card {
            background: white;
            border-radius: 32px;
            border: 1px solid var(--border);
            overflow: hidden;
            box-shadow: var(--shadow-md);
        }

        .form-header {
            padding: 24px 32px;
            background: linear-gradient(135deg, #f8fafc, white);
            border-bottom: 1px solid var(--border);
        }

        .form-header h2 {
            font-size: 18px;
            font-weight: 700;
            color: var(--dark);
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .form-header p {
            font-size: 13px;
            color: var(--gray);
            margin-top: 6px;
        }

        .form-body {
            padding: 32px;
        }

        .form-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 24px;
        }

        .form-group {
            margin-bottom: 0;
        }

        .form-group.full-width {
            grid-column: span 2;
        }

        .form-group label {
            display: block;
            font-size: 12px;
            font-weight: 700;
            color: var(--gray);
            margin-bottom: 8px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .form-group label .required {
            color: var(--danger);
            margin-left: 4px;
        }

        .form-group input,
        .form-group select,
        .form-group textarea {
            width: 100%;
            padding: 12px 16px;
            border: 2px solid var(--border);
            border-radius: 16px;
            font-size: 14px;
            font-family: 'Calibri', 'Segoe UI', 'Inter', sans-serif;
            transition: var(--transition);
            background: white;
        }

        .form-group input:focus,
        .form-group select:focus,
        .form-group textarea:focus {
            outline: none;
            border-color: var(--primary-light);
            box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1);
        }

        .form-group input[readonly] {
            background: var(--light);
            cursor: not-allowed;
        }

        .section-title {
            font-size: 16px;
            font-weight: 700;
            color: var(--dark);
            margin: 24px 0 20px 0;
            padding-bottom: 10px;
            border-bottom: 2px solid var(--border);
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .section-title:first-of-type {
            margin-top: 0;
        }

        .alert {
            padding: 16px 20px;
            border-radius: 20px;
            margin-bottom: 24px;
            display: flex;
            align-items: center;
            gap: 12px;
            font-size: 14px;
        }

        .alert-success {
            background: #dcfce7;
            border-left: 4px solid var(--success);
            color: #166534;
        }

        .alert-error {
            background: #fee2e2;
            border-left: 4px solid var(--danger);
            color: #991b1b;
        }

        .form-actions {
            display: flex;
            justify-content: flex-end;
            gap: 16px;
            margin-top: 32px;
            padding-top: 24px;
            border-top: 1px solid var(--border);
        }

        .btn {
            padding: 12px 28px;
            border: none;
            border-radius: 40px;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            transition: var(--transition);
            display: inline-flex;
            align-items: center;
            gap: 8px;
            font-family: 'Calibri', 'Segoe UI', 'Inter', sans-serif;
            text-decoration: none;
        }

        .btn-primary {
            background: linear-gradient(135deg, var(--primary-light), var(--primary));
            color: white;
            box-shadow: 0 4px 12px rgba(59, 130, 246, 0.3);
        }

        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 20px rgba(59, 130, 246, 0.4);
        }

        .btn-secondary {
            background: white;
            border: 1px solid var(--border);
            color: var(--gray);
        }

        .btn-secondary:hover {
            background: var(--light);
            transform: translateY(-1px);
        }

        .btn-danger {
            background: rgba(239, 68, 68, 0.1);
            color: var(--danger);
            border: 1px solid var(--danger);
        }

        .btn-danger:hover {
            background: var(--danger);
            color: white;
        }

        .info-note {
            background: #fef9e6;
            border-radius: 16px;
            padding: 12px 16px;
            margin-top: 12px;
            font-size: 12px;
            color: #b45309;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .checkbox-group {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 12px 0;
        }

        .checkbox-group input {
            width: 18px;
            height: 18px;
            cursor: pointer;
        }

        .checkbox-group label {
            margin: 0;
            cursor: pointer;
            text-transform: none;
            font-weight: 500;
        }

        @media (max-width: 768px) {
            .form-grid {
                grid-template-columns: 1fr;
            }
            .form-group.full-width {
                grid-column: span 1;
            }
            .form-body {
                padding: 20px;
            }
            .form-actions {
                flex-direction: column-reverse;
            }
            .btn {
                justify-content: center;
            }
        }
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

    <div class="main-content">
        <div class="page-header">
            <h1><i class="fas fa-user-edit"></i> Edit Staff Member</h1>
            <div class="breadcrumb">
                <a href="technicians.php">Staff Management</a>
                <i class="fas fa-chevron-right" style="font-size: 10px;"></i>
                <a href="view_staff.php?id=<?php echo $staff_id; ?>"><?php echo htmlspecialchars($staff['full_name']); ?></a>
                <i class="fas fa-chevron-right" style="font-size: 10px;"></i>
                <span>Edit</span>
            </div>
        </div>

        <div class="form-card">
            <div class="form-header">
                <h2><i class="fas fa-id-card"></i> Edit Staff Information</h2>
                <p>Update the staff member's details below. Fields marked with <span class="required" style="color: var(--danger);">*</span> are required.</p>
            </div>

            <div class="form-body">
                <?php if ($success_message): ?>
                <div class="alert alert-success">
                    <i class="fas fa-check-circle"></i>
                    <?php echo htmlspecialchars($success_message); ?>
                </div>
                <?php endif; ?>

                <?php if ($error_message): ?>
                <div class="alert alert-error">
                    <i class="fas fa-exclamation-triangle"></i>
                    <?php echo htmlspecialchars($error_message); ?>
                </div>
                <?php endif; ?>

                <form method="POST" action="" id="editStaffForm">
                    <!-- Personal Information Section -->
                    <div class="section-title">
                        <i class="fas fa-user-circle"></i> Personal Information
                    </div>

                    <div class="form-grid">
                        <div class="form-group">
                            <label>Staff Code</label>
                            <input type="text" value="<?php echo htmlspecialchars($staff['staff_code']); ?>" readonly>
                        </div>

                        <div class="form-group">
                            <label>Full Name <span class="required">*</span></label>
                            <input type="text" name="full_name" value="<?php echo htmlspecialchars($staff['full_name']); ?>" placeholder="Enter full name" required>
                        </div>

                        <div class="form-group">
                            <label>Position <span class="required">*</span></label>
                            <input type="text" name="position" value="<?php echo htmlspecialchars($staff['position']); ?>" placeholder="e.g., HR Manager, Accountant" list="positions" required>
                            <datalist id="positions">
                                <?php foreach ($positions as $pos): ?>
                                    <option value="<?php echo htmlspecialchars($pos); ?>">
                                <?php endforeach; ?>
                            </datalist>
                        </div>

                        <div class="form-group">
                            <label>Department <span class="required">*</span></label>
                            <select name="department" required>
                                <option value="">Select Department</option>
                                <?php foreach ($departments as $dept): ?>
                                    <option value="<?php echo htmlspecialchars($dept); ?>" <?php echo $staff['department'] == $dept ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($dept); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="form-group">
                            <label>Phone Number <span class="required">*</span></label>
                            <input type="tel" name="phone" value="<?php echo htmlspecialchars($staff['phone']); ?>" placeholder="e.g., +256 700 000000" required>
                        </div>

                        <div class="form-group">
                            <label>Email Address</label>
                            <input type="email" name="email" value="<?php echo htmlspecialchars($staff['email']); ?>" placeholder="staff@savantmotors.com">
                        </div>

                        <div class="form-group full-width">
                            <label>Address</label>
                            <textarea name="address" rows="2" placeholder="Enter residential address"><?php echo htmlspecialchars($staff['address']); ?></textarea>
                        </div>
                    </div>

                    <!-- Employment Information Section -->
                    <div class="section-title">
                        <i class="fas fa-briefcase"></i> Employment Information
                    </div>

                    <div class="form-grid">
                        <div class="form-group">
                            <label>Hire Date</label>
                            <input type="date" name="hire_date" value="<?php echo htmlspecialchars($staff['hire_date']); ?>">
                        </div>

                        <div class="form-group">
                            <label>Monthly Salary (UGX)</label>
                            <input type="number" name="salary" value="<?php echo htmlspecialchars($staff['salary']); ?>" placeholder="0.00" step="1000">
                        </div>

                        <div class="form-group">
                            <label>Employment Status</label>
                            <select name="status">
                                <option value="active" <?php echo $staff['status'] == 'active' ? 'selected' : ''; ?>>Active</option>
                                <option value="on_leave" <?php echo $staff['status'] == 'on_leave' ? 'selected' : ''; ?>>On Leave</option>
                                <option value="terminated" <?php echo $staff['status'] == 'terminated' ? 'selected' : ''; ?>>Terminated</option>
                                <option value="suspended" <?php echo $staff['status'] == 'suspended' ? 'selected' : ''; ?>>Suspended</option>
                            </select>
                        </div>

                        <div class="form-group full-width">
                            <div class="checkbox-group">
                                <input type="checkbox" name="is_blocked" id="is_blocked" value="1" <?php echo $staff['is_blocked'] ? 'checked' : ''; ?>>
                                <label for="is_blocked">Block Account Access</label>
                            </div>
                            
                            <div id="blocked_reason_group" style="display: <?php echo $staff['is_blocked'] ? 'block' : 'none'; ?>; margin-top: 12px;">
                                <label>Block Reason</label>
                                <textarea name="blocked_reason" rows="2" placeholder="Enter reason for blocking this staff member"><?php echo htmlspecialchars($staff['blocked_reason']); ?></textarea>
                            </div>
                        </div>
                    </div>

                    <!-- Emergency Contact Section -->
                    <div class="section-title">
                        <i class="fas fa-ambulance"></i> Emergency Contact
                    </div>

                    <div class="form-grid">
                        <div class="form-group">
                            <label>Emergency Contact Name</label>
                            <input type="text" name="emergency_contact_name" value="<?php echo htmlspecialchars($staff['emergency_contact_name']); ?>" placeholder="Full name of emergency contact">
                        </div>

                        <div class="form-group">
                            <label>Emergency Contact Phone</label>
                            <input type="tel" name="emergency_contact_phone" value="<?php echo htmlspecialchars($staff['emergency_contact_phone']); ?>" placeholder="Emergency phone number">
                        </div>
                    </div>

                    <div class="info-note" style="background: #e0f2fe; color: #0369a1; margin-top: 20px;">
                        <i class="fas fa-shield-alt"></i>
                        All staff information is confidential and will be stored securely.
                    </div>

                    <!-- Form Actions -->
                    <div class="form-actions">
                        <a href="view_staff.php?id=<?php echo $staff_id; ?>" class="btn btn-secondary">
                            <i class="fas fa-times"></i> Cancel
                        </a>
                        <button type="submit" name="update_staff" class="btn btn-primary">
                            <i class="fas fa-save"></i> Update Staff Member
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script>
        // Show/hide blocked reason
        document.getElementById('is_blocked')?.addEventListener('change', function() {
            const reasonGroup = document.getElementById('blocked_reason_group');
            reasonGroup.style.display = this.checked ? 'block' : 'none';
        });
        
        // Form validation
        document.getElementById('editStaffForm')?.addEventListener('submit', function(e) {
            const phone = document.querySelector('[name="phone"]').value;
            const phoneRegex = /^[\+\d\s\-\(\)]{10,20}$/;
            
            if (phone && !phoneRegex.test(phone)) {
                e.preventDefault();
                alert('Please enter a valid phone number (10-20 digits, can include +, -, spaces)');
                return false;
            }
            
            const email = document.querySelector('[name="email"]').value;
            if (email) {
                const emailRegex = /^[^\s@]+@([^\s@]+\.)+[^\s@]+$/;
                if (!emailRegex.test(email)) {
                    e.preventDefault();
                    alert('Please enter a valid email address');
                    return false;
                }
            }
            
            return true;
        });
        
        // Auto-format phone number
        document.querySelector('[name="phone"]')?.addEventListener('input', function(e) {
            let value = this.value.replace(/\D/g, '');
            if (value.length > 12) value = value.slice(0, 12);
            if (value.length > 0 && !value.startsWith('256') && value.length === 9) {
                value = '256' + value;
            }
            if (value.length === 12 && value.startsWith('256')) {
                value = '+' + value;
            }
            this.value = value;
        });
    </script>
</body>
</html>