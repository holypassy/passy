<?php
// edit_reminder.php - Edit Pickup Reminder
session_start();

if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    header('Location: ../index.php');
    exit();
}

// Check if ID is provided
if (!isset($_GET['id']) || empty($_GET['id'])) {
    $_SESSION['error'] = "Invalid reminder ID";
    header('Location: index.php');
    exit();
}

$reminderId = intval($_GET['id']);

// Database connection
try {
    $conn = new PDO("mysql:host=localhost;dbname=savant_motors_pos", "root", "");
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    // Get reminder details
    $stmt = $conn->prepare("SELECT * FROM vehicle_pickup_reminders WHERE id = ?");
    $stmt->execute([$reminderId]);
    $reminder = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$reminder) {
        $_SESSION['error'] = "Reminder not found";
        header('Location: index.php');
        exit();
    }
    
    // Get all customers — detect status column type to handle int (1) vs string ('active')
    $customers = [];
    try {
        $colInfo = $conn->query("SHOW COLUMNS FROM customers LIKE 'status'")->fetch(PDO::FETCH_ASSOC);
        $statusType = $colInfo['Type'] ?? '';

        if (empty($colInfo)) {
            $customers = $conn->query("
                SELECT id, full_name, telephone, email, address
                FROM customers
                ORDER BY full_name
            ")->fetchAll(PDO::FETCH_ASSOC);
        } elseif (stripos($statusType, 'int') !== false || stripos($statusType, 'tinyint') !== false) {
            $customers = $conn->query("
                SELECT id, full_name, telephone, email, address
                FROM customers
                WHERE status = 1 OR status IS NULL
                ORDER BY full_name
            ")->fetchAll(PDO::FETCH_ASSOC);
        } else {
            $customers = $conn->query("
                SELECT id, full_name, telephone, email, address
                FROM customers
                WHERE status NOT IN ('inactive','blocked','deleted','suspended') OR status IS NULL
                ORDER BY full_name
            ")->fetchAll(PDO::FETCH_ASSOC);
        }
    } catch(PDOException $e) {
        // If anything fails, fetch all customers without status filter
        try {
            $customers = $conn->query("
                SELECT id, full_name, telephone, email, address
                FROM customers ORDER BY full_name
            ")->fetchAll(PDO::FETCH_ASSOC);
        } catch(PDOException $e2) {
            $customers = [];
        }
    }

    // Get staff — try users table first, then staff table, detect status type
    $staff = [];
    $staffTables = [
        ['table' => 'users', 'role_col' => 'role'],
        ['table' => 'staff', 'role_col' => "'staff' as role"],
    ];
    foreach ($staffTables as $t) {
        try {
            $colInfo2 = $conn->query("SHOW COLUMNS FROM {$t['table']} LIKE 'status'")->fetch(PDO::FETCH_ASSOC);
            $sType = $colInfo2['Type'] ?? '';
            if (empty($colInfo2)) {
                $where = '';
            } elseif (stripos($sType, 'int') !== false || stripos($sType, 'tinyint') !== false) {
                $where = 'WHERE status = 1 OR status IS NULL';
            } else {
                $where = "WHERE status NOT IN ('inactive','blocked','deleted','suspended') OR status IS NULL";
            }
            $staff = $conn->query("
                SELECT id, full_name, email, {$t['role_col']}
                FROM {$t['table']} $where
                ORDER BY full_name
            ")->fetchAll(PDO::FETCH_ASSOC);
            if (!empty($staff)) break; // stop at first table that returns results
        } catch(PDOException $e) {
            continue;
        }
    }
    
} catch(PDOException $e) {
    $_SESSION['error'] = "Database error: " . $e->getMessage();
    header('Location: index.php');
    exit();
}

// Helper function to convert 24-hour time to 12-hour components
function convertTo12HourComponents($time24) {
    if (empty($time24)) return ['hour' => '', 'minute' => '', 'ampm' => 'AM'];
    
    $timestamp = strtotime($time24);
    return [
        'hour' => date('h', $timestamp),
        'minute' => date('i', $timestamp),
        'ampm' => date('A', $timestamp)
    ];
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_reminder'])) {
    
    // Function to convert 12-hour time to 24-hour for storage
    function convertTo24Hour($hour, $minute, $ampm) {
        if (empty($hour) || empty($minute)) return null;
        
        $hour = intval($hour);
        $minute = intval($minute);
        
        if ($ampm == 'PM' && $hour != 12) {
            $hour += 12;
        } elseif ($ampm == 'AM' && $hour == 12) {
            $hour = 0;
        }
        
        return sprintf("%02d:%02d:00", $hour, $minute);
    }
    
    $pickupTime = null;
    if (!empty($_POST['pickup_time_hour']) && !empty($_POST['pickup_time_minute'])) {
        $pickupTime = convertTo24Hour($_POST['pickup_time_hour'], $_POST['pickup_time_minute'], $_POST['pickup_ampm']);
    }
    
    $reminderTime = null;
    if (!empty($_POST['reminder_time_hour']) && !empty($_POST['reminder_time_minute'])) {
        $reminderTime = convertTo24Hour($_POST['reminder_time_hour'], $_POST['reminder_time_minute'], $_POST['reminder_ampm']);
    }
    
    $errors = [];
    
    if (empty($_POST['customer_id'])) {
        $errors[] = "Please select a customer";
    }
    if (empty($_POST['vehicle_reg'])) {
        $errors[] = "Please enter vehicle registration";
    }
    if (empty($_POST['pickup_date'])) {
        $errors[] = "Please select pickup date";
    }
    if ($_POST['pickup_type'] != 'workshop' && empty($_POST['pickup_address'])) {
        $errors[] = "Please enter pickup address for home/office pickup";
    }
    
    if (empty($errors)) {
        try {
            // Get customer details
            $customerStmt = $conn->prepare("SELECT full_name, telephone, email FROM customers WHERE id = ?");
            $customerStmt->execute([$_POST['customer_id']]);
            $customer = $customerStmt->fetch(PDO::FETCH_ASSOC);
            
            $updateStmt = $conn->prepare("
                UPDATE vehicle_pickup_reminders SET
                    customer_id = ?,
                    customer_name = ?,
                    customer_phone = ?,
                    customer_email = ?,
                    vehicle_reg = ?,
                    vehicle_make = ?,
                    vehicle_model = ?,
                    pickup_type = ?,
                    pickup_address = ?,
                    pickup_location_details = ?,
                    pickup_date = ?,
                    pickup_time = ?,
                    reminder_date = ?,
                    reminder_time = ?,
                    reminder_type = ?,
                    notes = ?,
                    assigned_to = ?,
                    status = ?,
                    updated_at = NOW()
                WHERE id = ?
            ");
            
            $updateStmt->execute([
                $_POST['customer_id'],
                $customer['full_name'] ?? null,
                $customer['telephone'] ?? null,
                $customer['email'] ?? null,
                $_POST['vehicle_reg'],
                $_POST['vehicle_make'] ?? null,
                $_POST['vehicle_model'] ?? null,
                $_POST['pickup_type'],
                $_POST['pickup_address'] ?? null,
                $_POST['pickup_location_details'] ?? null,
                $_POST['pickup_date'],
                $pickupTime,
                $_POST['reminder_date'],
                $reminderTime,
                $_POST['reminder_type'] ?? 'sms',
                $_POST['notes'] ?? null,
                !empty($_POST['assigned_to']) ? $_POST['assigned_to'] : null,
                $_POST['status'],
                $reminderId
            ]);
            
            $_SESSION['success'] = "Pickup reminder updated successfully!";
            header("Location: view_reminder.php?id=" . $reminderId);
            exit();
            
        } catch(PDOException $e) {
            $error_message = "Database error: " . $e->getMessage();
        }
    } else {
        $error_message = implode("<br>", $errors);
    }
}

// Get current time components for display
$pickupTimeComponents = convertTo12HourComponents($reminder['pickup_time'] ?? '');
$reminderTimeComponents = convertTo12HourComponents($reminder['reminder_time'] ?? '');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Edit Pickup Reminder | SAVANT MOTORS</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif;
            background: linear-gradient(135deg, #f5f7fb 0%, #eef2f9 100%);
            min-height: 100vh;
            padding: 2rem;
        }

        :root {
            --primary: #2563eb;
            --primary-dark: #1e40af;
            --secondary: #7c3aed;
            --success: #10b981;
            --danger: #ef4444;
            --warning: #f59e0b;
            --border: #e2e8f0;
            --gray: #64748b;
            --dark: #0f172a;
        }

        .container {
            max-width: 900px;
            margin: 0 auto;
        }

        .card {
            background: white;
            border-radius: 1.5rem;
            overflow: hidden;
            box-shadow: 0 20px 40px rgba(0,0,0,0.1);
        }

        .card-header {
            background: linear-gradient(135deg, var(--primary), var(--secondary));
            padding: 1.5rem;
            color: white;
        }

        .card-header h1 {
            font-size: 1.5rem;
            font-weight: 700;
            display: flex;
            align-items: center;
            gap: 0.5rem;
            margin-bottom: 0.25rem;
        }

        .card-header p {
            font-size: 0.8rem;
            opacity: 0.9;
        }

        .reminder-number {
            background: rgba(255,255,255,0.2);
            display: inline-block;
            padding: 0.25rem 0.75rem;
            border-radius: 2rem;
            font-size: 0.7rem;
            margin-top: 0.5rem;
        }

        .card-body {
            padding: 1.5rem;
        }

        .form-section {
            margin-bottom: 1.5rem;
            padding-bottom: 1rem;
            border-bottom: 1px solid var(--border);
        }

        .form-section:last-child {
            border-bottom: none;
            margin-bottom: 0;
            padding-bottom: 0;
        }

        .section-title {
            font-size: 1rem;
            font-weight: 700;
            color: var(--dark);
            margin-bottom: 1rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .section-title i {
            color: var(--primary);
        }

        .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 1rem;
            margin-bottom: 1rem;
        }

        .form-group {
            margin-bottom: 1rem;
        }
        
        label {
            display: block;
            font-size: 0.7rem;
            font-weight: 700;
            color: var(--gray);
            margin-bottom: 0.25rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .required {
            color: var(--danger);
            margin-left: 0.25rem;
        }

        input, select, textarea {
            width: 100%;
            padding: 0.6rem 0.75rem;
            border: 1.5px solid var(--border);
            border-radius: 0.5rem;
            font-size: 0.85rem;
            font-family: inherit;
            transition: all 0.2s;
            background: white;
        }

        input:focus, select:focus, textarea:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.1);
        }

        .info-box {
            background: #eff6ff;
            padding: 0.75rem;
            border-radius: 0.5rem;
            margin-top: 0.5rem;
            font-size: 0.75rem;
            color: var(--primary);
            border-left: 3px solid var(--primary);
        }

        .alert-error {
            background: #fee2e2;
            color: #991b1b;
            padding: 0.75rem 1rem;
            border-radius: 0.5rem;
            margin-bottom: 1rem;
            border-left: 3px solid var(--danger);
        }

        .form-actions {
            display: flex;
            gap: 1rem;
            justify-content: flex-end;
            margin-top: 1.5rem;
            padding-top: 1rem;
            border-top: 1px solid var(--border);
        }

        .btn {
            padding: 0.6rem 1.2rem;
            border-radius: 0.5rem;
            font-weight: 600;
            font-size: 0.85rem;
            cursor: pointer;
            border: none;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            transition: all 0.2s;
            font-family: inherit;
            text-decoration: none;
        }

        .btn-primary {
            background: linear-gradient(135deg, var(--primary), var(--secondary));
            color: white;
        }

        .btn-primary:hover {
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(37, 99, 235, 0.3);
        }

        .btn-secondary {
            background: #e2e8f0;
            color: var(--dark);
        }

        .btn-secondary:hover {
            background: #cbd5e1;
        }

        @media (max-width: 640px) {
            body {
                padding: 1rem;
            }
            .form-row {
                grid-template-columns: 1fr;
                gap: 0.75rem;
            }
            .form-actions {
                flex-direction: column;
            }
            .btn {
                justify-content: center;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="card">
            <div class="card-header">
                <h1>✏️ Edit Pickup Reminder</h1>
                <p>Update the pickup reminder details</p>
                <div class="reminder-number">
                    Reminder #: <?php echo htmlspecialchars($reminder['reminder_number'] ?? 'N/A'); ?>
                </div>
            </div>
            
            <div class="card-body">
                <?php if (isset($error_message)): ?>
                <div class="alert-error">
                    ❌ <?php echo htmlspecialchars($error_message); ?>
                </div>
                <?php endif; ?>
                
                <form method="POST" action="" id="reminderForm">
                    <!-- Customer Information -->
                    <div class="form-section">
                        <div class="section-title">
                            👤 Customer Information
                        </div>
                        
                        <div class="form-group">
                            <label>Select Customer <span class="required">*</span></label>
                            <select name="customer_id" id="customerSelect" required>
                                <option value="">-- Select Customer --</option>
                                <?php foreach ($customers as $cust): ?>
                                <option value="<?php echo $cust['id']; ?>" 
                                        data-phone="<?php echo htmlspecialchars($cust['telephone'] ?? ''); ?>"
                                        data-email="<?php echo htmlspecialchars($cust['email'] ?? ''); ?>"
                                        data-address="<?php echo htmlspecialchars($cust['address'] ?? ''); ?>"
                                        data-whatsapp="<?php echo htmlspecialchars($cust['telephone'] ?? ''); ?>"
                                        <?php echo ($cust['id'] == ($reminder['customer_id'] ?? '')) ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($cust['full_name']); ?> - <?php echo htmlspecialchars($cust['telephone']); ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div id="customerDetails" class="info-box" <?php echo empty($reminder['customer_id']) ? 'style="display: none;"' : ''; ?>>
                            📞 <span id="customerPhone"><?php echo htmlspecialchars($reminder['customer_phone'] ?? ''); ?></span> &nbsp;|&nbsp;
                            ✉️ <span id="customerEmail"><?php echo htmlspecialchars($reminder['customer_email'] ?? ''); ?></span> &nbsp;|&nbsp;
                            💬 <span id="customerWhatsapp"><?php echo htmlspecialchars($reminder['customer_phone'] ?? 'N/A'); ?></span>
                        </div>
                    </div>
                    
                    <!-- Vehicle Information -->
                    <div class="form-section">
                        <div class="section-title">
                            🚙 Vehicle Information
                        </div>
                        
                        <div class="form-group">
                            <label>Vehicle Registration <span class="required">*</span></label>
                            <input type="text" name="vehicle_reg" required placeholder="e.g., UBA 123A" value="<?php echo htmlspecialchars($reminder['vehicle_reg'] ?? ''); ?>">
                        </div>
                        
                        <div class="form-row">
                            <div class="form-group">
                                <label>Make</label>
                                <input type="text" name="vehicle_make" placeholder="e.g., Toyota" value="<?php echo htmlspecialchars($reminder['vehicle_make'] ?? ''); ?>">
                            </div>
                            <div class="form-group">
                                <label>Model</label>
                                <input type="text" name="vehicle_model" placeholder="e.g., Corolla" value="<?php echo htmlspecialchars($reminder['vehicle_model'] ?? ''); ?>">
                            </div>
                        </div>
                    </div>
                    
                    <!-- Pickup Details -->
                    <div class="form-section">
                        <div class="section-title">
                            📍 Pickup Details
                        </div>
                        
                        <div class="form-group">
                            <label>Pickup Type <span class="required">*</span></label>
                            <select name="pickup_type" id="pickupTypeSelect" required onchange="toggleAddressFields()">
                                <option value="workshop" <?php echo ($reminder['pickup_type'] ?? '') == 'workshop' ? 'selected' : ''; ?>>🏢 Workshop Pickup</option>
                                <option value="home" <?php echo ($reminder['pickup_type'] ?? '') == 'home' ? 'selected' : ''; ?>>🏠 Home Pickup</option>
                                <option value="office" <?php echo ($reminder['pickup_type'] ?? '') == 'office' ? 'selected' : ''; ?>>💼 Office Pickup</option>
                            </select>
                        </div>
                        
                        <div id="addressFields" <?php echo ($reminder['pickup_type'] ?? '') == 'workshop' ? 'style="display: none;"' : ''; ?>>
                            <div class="form-group">
                                <label>Pickup Address <span class="required">*</span></label>
                                <textarea name="pickup_address" id="pickupAddress" rows="2" placeholder="Enter full address for pickup"><?php echo htmlspecialchars($reminder['pickup_address'] ?? ''); ?></textarea>
                            </div>
                            <div class="form-group">
                                <label>Location Details (Landmarks)</label>
                                <textarea name="pickup_location_details" rows="2" placeholder="Gate color, nearby landmark, special instructions..."><?php echo htmlspecialchars($reminder['pickup_location_details'] ?? ''); ?></textarea>
                            </div>
                        </div>
                        
                        <div class="form-row">
                            <div class="form-group">
                                <label>Pickup Date <span class="required">*</span></label>
                                <input type="date" name="pickup_date" required value="<?php echo htmlspecialchars($reminder['pickup_date'] ?? ''); ?>">
                            </div>
                            <div class="form-group">
                                <label>Pickup Time (12-hour)</label>
                                <div style="display: flex; gap: 5px;">
                                    <select name="pickup_time_hour" style="width: 33%;">
                                        <option value="">--</option>
                                        <?php for($i = 1; $i <= 12; $i++): ?>
                                        <option value="<?php echo $i; ?>" <?php echo ($pickupTimeComponents['hour'] == $i) ? 'selected' : ''; ?>>
                                            <?php echo sprintf("%02d", $i); ?>
                                        </option>
                                        <?php endfor; ?>
                                    </select>
                                    <select name="pickup_time_minute" style="width: 33%;">
                                        <option value="">--</option>
                                        <option value="00" <?php echo ($pickupTimeComponents['minute'] == '00') ? 'selected' : ''; ?>>00</option>
                                        <option value="15" <?php echo ($pickupTimeComponents['minute'] == '15') ? 'selected' : ''; ?>>15</option>
                                        <option value="30" <?php echo ($pickupTimeComponents['minute'] == '30') ? 'selected' : ''; ?>>30</option>
                                        <option value="45" <?php echo ($pickupTimeComponents['minute'] == '45') ? 'selected' : ''; ?>>45</option>
                                    </select>
                                    <select name="pickup_ampm" style="width: 34%;">
                                        <option value="AM" <?php echo ($pickupTimeComponents['ampm'] == 'AM') ? 'selected' : ''; ?>>AM</option>
                                        <option value="PM" <?php echo ($pickupTimeComponents['ampm'] == 'PM') ? 'selected' : ''; ?>>PM</option>
                                    </select>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Reminder Settings -->
                    <div class="form-section">
                        <div class="section-title">
                            🔔 Reminder Settings
                        </div>
                        
                        <div class="form-row">
                            <div class="form-group">
                                <label>Reminder Date <span class="required">*</span></label>
                                <input type="date" name="reminder_date" required value="<?php echo htmlspecialchars($reminder['reminder_date'] ?? ''); ?>">
                            </div>
                            <div class="form-group">
                                <label>Reminder Time (12-hour)</label>
                                <div style="display: flex; gap: 5px;">
                                    <select name="reminder_time_hour" style="width: 33%;">
                                        <option value="">--</option>
                                        <?php for($i = 1; $i <= 12; $i++): ?>
                                        <option value="<?php echo $i; ?>" <?php echo ($reminderTimeComponents['hour'] == $i) ? 'selected' : ''; ?>>
                                            <?php echo sprintf("%02d", $i); ?>
                                        </option>
                                        <?php endfor; ?>
                                    </select>
                                    <select name="reminder_time_minute" style="width: 33%;">
                                        <option value="">--</option>
                                        <option value="00" <?php echo ($reminderTimeComponents['minute'] == '00') ? 'selected' : ''; ?>>00</option>
                                        <option value="15" <?php echo ($reminderTimeComponents['minute'] == '15') ? 'selected' : ''; ?>>15</option>
                                        <option value="30" <?php echo ($reminderTimeComponents['minute'] == '30') ? 'selected' : ''; ?>>30</option>
                                        <option value="45" <?php echo ($reminderTimeComponents['minute'] == '45') ? 'selected' : ''; ?>>45</option>
                                    </select>
                                    <select name="reminder_ampm" style="width: 34%;">
                                        <option value="AM" <?php echo ($reminderTimeComponents['ampm'] == 'AM') ? 'selected' : ''; ?>>AM</option>
                                        <option value="PM" <?php echo ($reminderTimeComponents['ampm'] == 'PM') ? 'selected' : ''; ?>>PM</option>
                                    </select>
                                </div>
                            </div>
                        </div>
                        
                        <div class="form-group">
                            <label>Reminder Type</label>
                            <select name="reminder_type">
                                <option value="sms" <?php echo ($reminder['reminder_type'] ?? 'sms') == 'sms' ? 'selected' : ''; ?>>📱 SMS Only</option>
                                <option value="email" <?php echo ($reminder['reminder_type'] ?? '') == 'email' ? 'selected' : ''; ?>>✉️ Email Only</option>
                                <option value="whatsapp" <?php echo ($reminder['reminder_type'] ?? '') == 'whatsapp' ? 'selected' : ''; ?>>💬 WhatsApp Only</option>
                                <option value="sms_whatsapp" <?php echo ($reminder['reminder_type'] ?? '') == 'sms_whatsapp' ? 'selected' : ''; ?>>📱💬 SMS & WhatsApp</option>
                                <option value="both" <?php echo ($reminder['reminder_type'] ?? '') == 'both' ? 'selected' : ''; ?>>📱✉️ SMS & Email</option>
                                <option value="all" <?php echo ($reminder['reminder_type'] ?? '') == 'all' ? 'selected' : ''; ?>>🔔 All (SMS, Email & WhatsApp)</option>
                            </select>
                        </div>
                        
                        <div class="form-group">
                            <label>Status</label>
                            <select name="status">
                                <option value="pending" <?php echo ($reminder['status'] ?? '') == 'pending' ? 'selected' : ''; ?>>⏰ Pending</option>
                                <option value="scheduled" <?php echo ($reminder['status'] ?? '') == 'scheduled' ? 'selected' : ''; ?>>📅 Scheduled</option>
                                <option value="in_progress" <?php echo ($reminder['status'] ?? '') == 'in_progress' ? 'selected' : ''; ?>>🚚 In Progress</option>
                                <option value="completed" <?php echo ($reminder['status'] ?? '') == 'completed' ? 'selected' : ''; ?>>✅ Completed</option>
                                <option value="cancelled" <?php echo ($reminder['status'] ?? '') == 'cancelled' ? 'selected' : ''; ?>>❌ Cancelled</option>
                            </select>
                        </div>
                        
                        <div class="form-group">
                            <label>Assign To (Staff)</label>
                            <select name="assigned_to">
                                <option value="">-- Unassigned --</option>
                                <?php if (!empty($staff)): ?>
                                    <?php foreach ($staff as $s): ?>
                                    <option value="<?php echo $s['id']; ?>" 
                                            data-role="<?php echo htmlspecialchars($s['role'] ?? ''); ?>"
                                            data-email="<?php echo htmlspecialchars($s['email'] ?? ''); ?>"
                                            <?php echo ($s['id'] == ($reminder['assigned_to'] ?? '')) ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($s['full_name']); ?>
                                        <?php if (!empty($s['role'])): ?> — <?php echo htmlspecialchars(ucfirst($s['role'])); ?><?php endif; ?>
                                    </option>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <option disabled>No staff members found</option>
                                <?php endif; ?>
                            </select>
                            <?php if (!empty($reminder['assigned_to']) && !empty($staff)): ?>
                                <?php 
                                $assignedStaff = array_filter($staff, fn($s) => $s['id'] == $reminder['assigned_to']);
                                $assignedStaff = array_values($assignedStaff);
                                if (!empty($assignedStaff)): ?>
                                <div class="info-box" style="margin-top: 0.4rem;">
                                    👤 Assigned to: <strong><?php echo htmlspecialchars($assignedStaff[0]['full_name']); ?></strong>
                                    <?php if (!empty($assignedStaff[0]['email'])): ?>
                                     — ✉️ <?php echo htmlspecialchars($assignedStaff[0]['email']); ?>
                                    <?php endif; ?>
                                </div>
                                <?php endif; ?>
                            <?php endif; ?>
                        </div>
                    </div>
                    
                    <!-- Additional Notes -->
                    <div class="form-section">
                        <div class="section-title">
                            📝 Additional Notes
                        </div>
                        
                        <div class="form-group">
                            <textarea name="notes" rows="3" placeholder="Any special instructions or notes..."><?php echo htmlspecialchars($reminder['notes'] ?? ''); ?></textarea>
                        </div>
                    </div>
                    
                    <!-- Form Actions -->
                    <div class="form-actions">
                        <a href="view_reminder.php?id=<?php echo $reminderId; ?>" class="btn btn-secondary">
                            ← Cancel
                        </a>
                        <button type="submit" name="update_reminder" class="btn-primary">
                            💾 Update Reminder
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script>
        // Toast notification
        function showToast(message, type = 'success') {
            const toast = document.createElement('div');
            toast.style.cssText = `
                position: fixed;
                top: 20px;
                right: 20px;
                padding: 12px 20px;
                border-radius: 8px;
                color: white;
                font-size: 14px;
                z-index: 9999;
                animation: slideIn 0.3s ease;
                background: ${type === 'success' ? '#10b981' : (type === 'error' ? '#ef4444' : '#3b82f6')};
            `;
            toast.textContent = message;
            document.body.appendChild(toast);
            setTimeout(() => toast.remove(), 3000);
        }

        // Add animation style
        const style = document.createElement('style');
        style.textContent = `
            @keyframes slideIn {
                from { transform: translateX(100%); opacity: 0; }
                to { transform: translateX(0); opacity: 1; }
            }
        `;
        document.head.appendChild(style);

        // Display session messages
        <?php if (isset($_SESSION['success'])): ?>
        showToast('<?php echo addslashes($_SESSION['success']); ?>', 'success');
        <?php unset($_SESSION['success']); ?>
        <?php endif; ?>
        
        <?php if (isset($_SESSION['error'])): ?>
        showToast('<?php echo addslashes($_SESSION['error']); ?>', 'error');
        <?php unset($_SESSION['error']); ?>
        <?php endif; ?>

        // Toggle address fields
        function toggleAddressFields() {
            const type = document.getElementById('pickupTypeSelect').value;
            const addressFields = document.getElementById('addressFields');
            if (type === 'workshop') {
                addressFields.style.display = 'none';
            } else {
                addressFields.style.display = 'block';
            }
        }
        
        // Customer selection
        const customerSelect = document.getElementById('customerSelect');
        const customerDetails = document.getElementById('customerDetails');
        const customerPhoneSpan = document.getElementById('customerPhone');
        const customerEmailSpan = document.getElementById('customerEmail');
        const customerWhatsappSpan = document.getElementById('customerWhatsapp');
        const pickupAddress = document.getElementById('pickupAddress');
        
        function updateCustomerDetails(selectEl) {
            const selected = selectEl.options[selectEl.selectedIndex];
            const phone = selected.getAttribute('data-phone');
            const email = selected.getAttribute('data-email');
            const whatsapp = selected.getAttribute('data-whatsapp');
            const address = selected.getAttribute('data-address');
            
            if (selectEl.value && customerDetails) {
                customerDetails.style.display = 'block';
                customerPhoneSpan.textContent = phone || 'N/A';
                customerEmailSpan.textContent = email || 'N/A';
                customerWhatsappSpan.textContent = whatsapp || 'N/A';
                
                const pickupType = document.getElementById('pickupTypeSelect');
                if (pickupType && pickupType.value !== 'workshop' && address && address !== 'null' && pickupAddress && !pickupAddress.value) {
                    pickupAddress.value = address;
                }
            } else if (customerDetails && !selectEl.value) {
                customerDetails.style.display = 'none';
            }
        }

        if (customerSelect) {
            customerSelect.addEventListener('change', function() {
                updateCustomerDetails(this);
            });
            // Auto-populate on page load if a customer is already selected
            if (customerSelect.value) {
                updateCustomerDetails(customerSelect);
            }
        }
        
        // Initialize
        toggleAddressFields();

        // Update assigned staff info box on change
        const assignedToSelect = document.querySelector('select[name="assigned_to"]');
        if (assignedToSelect) {
            assignedToSelect.addEventListener('change', function() {
                const existingBox = this.parentElement.querySelector('.staff-info-box');
                if (existingBox) existingBox.remove();
                if (this.value) {
                    const selected = this.options[this.selectedIndex];
                    const email = selected.getAttribute('data-email');
                    const role = selected.getAttribute('data-role');
                    if (email || role) {
                        const box = document.createElement('div');
                        box.className = 'info-box staff-info-box';
                        box.style.marginTop = '0.4rem';
                        box.innerHTML = `👤 <strong>${selected.text.trim()}</strong>${email ? ' — ✉️ ' + email : ''}`;
                        this.parentElement.appendChild(box);
                    }
                }
            });
        }
    </script>
</body>
</html>