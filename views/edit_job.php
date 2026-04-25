<?php
// edit_job.php - Edit an existing job card
session_start();

if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    header('Location: index.php');
    exit();
}

$user_id = $_SESSION['user_id'] ?? 1;
$user_full_name = $_SESSION['full_name'] ?? 'User';
$user_role = $_SESSION['role'] ?? 'user';

$error = '';
$success = '';

// Database connection
try {
    $conn = new PDO("mysql:host=localhost;dbname=savant_motors_pos", "root", "");
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    die("Database connection failed: " . $e->getMessage());
}

// Check if ID is provided
if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    $_SESSION['flash_error'] = 'Invalid job card ID.';
    header('Location: job_cards.php');
    exit();
}
$job_id = (int)$_GET['id'];

// Fetch the job card
$stmt = $conn->prepare("
    SELECT * FROM job_cards WHERE id = :id AND deleted_at IS NULL
");
$stmt->execute([':id' => $job_id]);
$job = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$job) {
    $_SESSION['flash_error'] = 'Job card not found.';
    header('Location: job_cards.php');
    exit();
}

// Fetch customers for dropdown
$customers = $conn->query("SELECT id, full_name, telephone FROM customers ORDER BY full_name")->fetchAll(PDO::FETCH_ASSOC);

// Fetch technicians for dropdown
$technicians = $conn->query("SELECT id, full_name FROM technicians ORDER BY full_name")->fetchAll(PDO::FETCH_ASSOC);

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Sanitize and validate inputs
    $job_number = trim($_POST['job_number'] ?? '');
    $customer_id = (int)($_POST['customer_id'] ?? 0);
    $vehicle_reg = trim($_POST['vehicle_reg'] ?? '');
    $vehicle_make = trim($_POST['vehicle_make'] ?? '');
    $vehicle_model = trim($_POST['vehicle_model'] ?? '');
    $vehicle_year = trim($_POST['vehicle_year'] ?? '');
    $odometer_reading = trim($_POST['odometer_reading'] ?? '');
    $fuel_level = trim($_POST['fuel_level'] ?? '');
    $date_received = $_POST['date_received'] ?? '';
    $date_promised = !empty($_POST['date_promised']) ? $_POST['date_promised'] : null;
    $status = $_POST['status'] ?? 'pending';
    $priority = $_POST['priority'] ?? 'normal';
    $assigned_technician_id = !empty($_POST['assigned_technician_id']) ? (int)$_POST['assigned_technician_id'] : null;
    $notes = trim($_POST['notes'] ?? '');
    $brought_by = trim($_POST['brought_by'] ?? '');
    $terms_accepted = isset($_POST['terms_accepted']) ? 1 : 0;

    // Validation
    $errors = [];
    if (empty($job_number)) {
        $errors[] = 'Job number is required.';
    }
    if ($customer_id <= 0) {
        $errors[] = 'Please select a customer.';
    }
    if (empty($date_received)) {
        $errors[] = 'Date received is required.';
    }
    if (!in_array($status, ['pending', 'in_progress', 'completed', 'cancelled'])) {
        $status = 'pending';
    }
    if (!in_array($priority, ['low', 'normal', 'urgent', 'critical'])) {
        $priority = 'normal';
    }

    // Check uniqueness of job_number (excluding current job)
    if (empty($errors)) {
        $checkStmt = $conn->prepare("SELECT id FROM job_cards WHERE job_number = :job_number AND id != :id AND deleted_at IS NULL");
        $checkStmt->execute([':job_number' => $job_number, ':id' => $job_id]);
        if ($checkStmt->fetch()) {
            $errors[] = 'Job number already exists. Please use a unique number.';
        }
    }

    if (empty($errors)) {
        try {
            $updateStmt = $conn->prepare("
                UPDATE job_cards SET
                    job_number = :job_number,
                    customer_id = :customer_id,
                    vehicle_reg = :vehicle_reg,
                    vehicle_make = :vehicle_make,
                    vehicle_model = :vehicle_model,
                    vehicle_year = :vehicle_year,
                    odometer_reading = :odometer_reading,
                    fuel_level = :fuel_level,
                    date_received = :date_received,
                    date_promised = :date_promised,
                    status = :status,
                    priority = :priority,
                    assigned_technician_id = :assigned_technician_id,
                    notes = :notes,
                    brought_by = :brought_by,
                    terms_accepted = :terms_accepted,
                    updated_at = NOW()
                WHERE id = :id
            ");
            $updateStmt->execute([
                ':job_number' => $job_number,
                ':customer_id' => $customer_id,
                ':vehicle_reg' => $vehicle_reg,
                ':vehicle_make' => $vehicle_make,
                ':vehicle_model' => $vehicle_model,
                ':vehicle_year' => $vehicle_year,
                ':odometer_reading' => $odometer_reading,
                ':fuel_level' => $fuel_level,
                ':date_received' => $date_received,
                ':date_promised' => $date_promised,
                ':status' => $status,
                ':priority' => $priority,
                ':assigned_technician_id' => $assigned_technician_id,
                ':notes' => $notes,
                ':brought_by' => $brought_by,
                ':terms_accepted' => $terms_accepted,
                ':id' => $job_id
            ]);

            $_SESSION['flash_success'] = 'Job card updated successfully.';
            header('Location: job_cards.php');
            exit();
        } catch (PDOException $e) {
            $errors[] = 'Database error: ' . $e->getMessage();
        }
    }

    // If we have errors, store them for display
    $error = implode('<br>', $errors);
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Edit Job Card - Savant Motors ERP</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: 'Inter', sans-serif;
            background: linear-gradient(135deg, #f5f7fc 0%, #eef2f9 100%);
            padding: 40px;
        }
        .container {
            max-width: 900px;
            margin: 0 auto;
            background: white;
            border-radius: 24px;
            box-shadow: 0 20px 35px rgba(0,0,0,0.1);
            overflow: hidden;
        }
        .form-header {
            background: linear-gradient(135deg, #2563eb, #1e3a8a);
            padding: 24px 30px;
            color: white;
        }
        .form-header h1 {
            font-size: 24px;
            font-weight: 700;
            margin-bottom: 5px;
        }
        .form-header p {
            opacity: 0.9;
            font-size: 14px;
        }
        .form-body {
            padding: 30px;
        }
        .form-group {
            margin-bottom: 20px;
        }
        label {
            display: block;
            font-weight: 600;
            font-size: 13px;
            margin-bottom: 8px;
            color: #0f172a;
        }
        input, select, textarea {
            width: 100%;
            padding: 12px 15px;
            border: 2px solid #e2e8f0;
            border-radius: 12px;
            font-family: inherit;
            font-size: 14px;
            transition: all 0.3s;
        }
        input:focus, select:focus, textarea:focus {
            outline: none;
            border-color: #2563eb;
            box-shadow: 0 0 0 3px rgba(37,99,235,0.1);
        }
        .row {
            display: flex;
            gap: 20px;
            flex-wrap: wrap;
        }
        .row .form-group {
            flex: 1;
            min-width: 150px;
        }
        .checkbox-group {
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .checkbox-group input {
            width: auto;
            margin: 0;
        }
        .btn {
            padding: 12px 24px;
            border: none;
            border-radius: 14px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s;
            font-family: inherit;
            font-size: 14px;
        }
        .btn-primary {
            background: linear-gradient(135deg, #2563eb, #7c3aed);
            color: white;
        }
        .btn-secondary {
            background: white;
            border: 1px solid #e2e8f0;
            color: #64748b;
        }
        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(37,99,235,0.3);
        }
        .form-actions {
            display: flex;
            gap: 15px;
            justify-content: flex-end;
            margin-top: 30px;
        }
        .alert {
            padding: 12px 20px;
            border-radius: 12px;
            margin-bottom: 20px;
            font-size: 14px;
        }
        .alert-error {
            background: #fee2e2;
            border-left: 4px solid #ef4444;
            color: #991b1b;
        }
        .alert-success {
            background: #dcfce7;
            border-left: 4px solid #10b981;
            color: #166534;
        }
        @media (max-width: 768px) {
            body { padding: 20px; }
            .form-body { padding: 20px; }
            .row { flex-direction: column; gap: 0; }
        }
    </style>
</head>
<body>
<div class="container">
    <div class="form-header">
        <h1><i class="fas fa-edit"></i> Edit Job Card</h1>
        <p>Update the details of this job card</p>
    </div>
    <div class="form-body">
        <?php if ($error): ?>
            <div class="alert alert-error"><?php echo $error; ?></div>
        <?php endif; ?>
        <?php if ($success): ?>
            <div class="alert alert-success"><?php echo $success; ?></div>
        <?php endif; ?>

        <form method="POST" action="">
            <div class="row">
                <div class="form-group">
                    <label>Job Number *</label>
                    <input type="text" name="job_number" value="<?php echo htmlspecialchars($job['job_number'] ?? ''); ?>" required>
                </div>
                <div class="form-group">
                    <label>Customer *</label>
                    <select name="customer_id" required>
                        <option value="">-- Select Customer --</option>
                        <?php foreach ($customers as $cust): ?>
                            <option value="<?php echo $cust['id']; ?>" <?php echo ($job['customer_id'] == $cust['id']) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($cust['full_name'] . ' (' . $cust['telephone'] . ')'); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <div class="row">
                <div class="form-group">
                    <label>Vehicle Registration</label>
                    <input type="text" name="vehicle_reg" value="<?php echo htmlspecialchars($job['vehicle_reg'] ?? ''); ?>">
                </div>
                <div class="form-group">
                    <label>Vehicle Make</label>
                    <input type="text" name="vehicle_make" value="<?php echo htmlspecialchars($job['vehicle_make'] ?? ''); ?>">
                </div>
                <div class="form-group">
                    <label>Vehicle Model</label>
                    <input type="text" name="vehicle_model" value="<?php echo htmlspecialchars($job['vehicle_model'] ?? ''); ?>">
                </div>
                <div class="form-group">
                    <label>Year</label>
                    <input type="text" name="vehicle_year" value="<?php echo htmlspecialchars($job['vehicle_year'] ?? ''); ?>">
                </div>
            </div>

            <div class="row">
                <div class="form-group">
                    <label>Odometer Reading</label>
                    <input type="text" name="odometer_reading" value="<?php echo htmlspecialchars($job['odometer_reading'] ?? ''); ?>">
                </div>
                <div class="form-group">
                    <label>Fuel Level</label>
                    <input type="text" name="fuel_level" value="<?php echo htmlspecialchars($job['fuel_level'] ?? ''); ?>">
                </div>
                <div class="form-group">
                    <label>Brought By</label>
                    <input type="text" name="brought_by" value="<?php echo htmlspecialchars($job['brought_by'] ?? ''); ?>">
                </div>
            </div>

            <div class="row">
                <div class="form-group">
                    <label>Date Received *</label>
                    <input type="date" name="date_received" value="<?php echo htmlspecialchars($job['date_received'] ?? ''); ?>" required>
                </div>
                <div class="form-group">
                    <label>Date Promised</label>
                    <input type="date" name="date_promised" value="<?php echo !empty($job['date_promised']) ? htmlspecialchars($job['date_promised']) : ''; ?>">
                </div>
            </div>

            <div class="row">
                <div class="form-group">
                    <label>Status</label>
                    <select name="status">
                        <option value="pending" <?php echo ($job['status'] == 'pending') ? 'selected' : ''; ?>>Pending</option>
                        <option value="in_progress" <?php echo ($job['status'] == 'in_progress') ? 'selected' : ''; ?>>In Progress</option>
                        <option value="completed" <?php echo ($job['status'] == 'completed') ? 'selected' : ''; ?>>Completed</option>
                        <option value="cancelled" <?php echo ($job['status'] == 'cancelled') ? 'selected' : ''; ?>>Cancelled</option>
                    </select>
                </div>
                <div class="form-group">
                    <label>Priority</label>
                    <select name="priority">
                        <option value="low" <?php echo ($job['priority'] == 'low') ? 'selected' : ''; ?>>Low</option>
                        <option value="normal" <?php echo ($job['priority'] == 'normal') ? 'selected' : ''; ?>>Normal</option>
                        <option value="urgent" <?php echo ($job['priority'] == 'urgent') ? 'selected' : ''; ?>>Urgent</option>
                        <option value="critical" <?php echo ($job['priority'] == 'critical') ? 'selected' : ''; ?>>Critical</option>
                    </select>
                </div>
                <div class="form-group">
                    <label>Assigned Technician</label>
                    <select name="assigned_technician_id">
                        <option value="">-- None --</option>
                        <?php foreach ($technicians as $tech): ?>
                            <option value="<?php echo $tech['id']; ?>" <?php echo ($job['assigned_technician_id'] == $tech['id']) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($tech['full_name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <div class="form-group">
                <label>Notes</label>
                <textarea name="notes" rows="4"><?php echo htmlspecialchars($job['notes'] ?? ''); ?></textarea>
            </div>

            <div class="form-group checkbox-group">
                <input type="checkbox" name="terms_accepted" value="1" <?php echo ($job['terms_accepted'] ?? 0) ? 'checked' : ''; ?>>
                <label style="margin-bottom:0;">Terms & Conditions Accepted</label>
            </div>

            <div class="form-actions">
                <a href="job_cards.php" class="btn btn-secondary"><i class="fas fa-arrow-left"></i> Cancel</a>
                <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Update Job Card</button>
            </div>
        </form>
    </div>
</div>
</body>
</html>