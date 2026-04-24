<?php
// view_reminder.php - View Pickup Reminder Details
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
    
    // Get reminder details with joins for customer and staff
    $stmt = $conn->prepare("
        SELECT r.*, 
               u.full_name as assigned_staff_name,
               u.email as staff_email
        FROM vehicle_pickup_reminders r
        LEFT JOIN users u ON r.assigned_to = u.id
        WHERE r.id = ?
    ");
    $stmt->execute([$reminderId]);
    $reminder = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$reminder) {
        $_SESSION['error'] = "Reminder not found";
        header('Location: index.php');
        exit();
    }
    
    // Get customer details if not already in reminder
    if (empty($reminder['customer_name']) && !empty($reminder['customer_id'])) {
        $custStmt = $conn->prepare("SELECT full_name, telephone, email, address FROM customers WHERE id = ?");
        $custStmt->execute([$reminder['customer_id']]);
        $customer = $custStmt->fetch(PDO::FETCH_ASSOC);
        if ($customer) {
            $reminder['customer_name'] = $customer['full_name'];
            $reminder['customer_phone'] = $customer['telephone'];
            $reminder['customer_email'] = $customer['email'];
            $reminder['customer_address'] = $customer['address'];
        }
    }
    
} catch(PDOException $e) {
    $_SESSION['error'] = "Database error: " . $e->getMessage();
    header('Location: index.php');
    exit();
}

// Helper function to format time to 12-hour format
function formatTime12Hour($time) {
    if (empty($time)) return 'Not set';
    $timestamp = strtotime($time);
    return date('h:i A', $timestamp);
}

// Helper function to get status badge class
function getStatusBadgeClass($status) {
    switch($status) {
        case 'pending': return 'badge-pending';
        case 'scheduled': return 'badge-scheduled';
        case 'in_progress': return 'badge-in_progress';
        case 'completed': return 'badge-completed';
        case 'cancelled': return 'badge-cancelled';
        default: return 'badge-pending';
    }
}

// Helper function to get status icon
function getStatusIcon($status) {
    switch($status) {
        case 'pending': return '⏰';
        case 'scheduled': return '📅';
        case 'in_progress': return '🚚';
        case 'completed': return '✅';
        case 'cancelled': return '❌';
        default: return '📋';
    }
}

// Helper function to get pickup type icon
function getPickupTypeIcon($type) {
    switch($type) {
        case 'workshop': return '🏢';
        case 'home': return '🏠';
        case 'office': return '💼';
        default: return '📍';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>View Pickup Reminder | SAVANT MOTORS</title>
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
            --info: #3b82f6;
            --border: #e2e8f0;
            --gray: #64748b;
            --dark: #0f172a;
        }

        .container {
            max-width: 1000px;
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
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 1rem;
        }

        .card-header h1 {
            font-size: 1.5rem;
            font-weight: 700;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .reminder-number {
            background: rgba(255,255,255,0.2);
            padding: 0.3rem 0.8rem;
            border-radius: 2rem;
            font-size: 0.8rem;
        }

        .card-body {
            padding: 1.5rem;
        }

        .status-section {
            display: inline-block;
            margin-bottom: 1.5rem;
        }

        .badge {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.5rem 1rem;
            border-radius: 2rem;
            font-size: 0.85rem;
            font-weight: 600;
        }

        .badge-pending { background: #fed7aa; color: #9a3412; }
        .badge-scheduled { background: #dbeafe; color: #1e40af; }
        .badge-in_progress { background: #c7d2fe; color: #3730a3; }
        .badge-completed { background: #dcfce7; color: #166534; }
        .badge-cancelled { background: #fee2e2; color: #991b1b; }

        .info-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 1.5rem;
            margin-bottom: 1.5rem;
        }

        .info-section {
            background: var(--bg-light);
            border-radius: 1rem;
            padding: 1.25rem;
            border: 1px solid var(--border);
        }

        .section-title {
            font-size: 0.85rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 1px;
            color: var(--gray);
            margin-bottom: 1rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
            padding-bottom: 0.5rem;
            border-bottom: 2px solid var(--border);
        }

        .info-row {
            display: flex;
            padding: 0.6rem 0;
            border-bottom: 1px solid var(--border);
        }

        .info-row:last-child {
            border-bottom: none;
        }

        .info-label {
            width: 120px;
            font-weight: 600;
            color: var(--dark);
            font-size: 0.8rem;
        }

        .info-value {
            flex: 1;
            color: var(--gray);
            font-size: 0.85rem;
        }

        .info-value strong {
            color: var(--dark);
        }

        .action-buttons {
            display: flex;
            gap: 1rem;
            justify-content: flex-end;
            margin-top: 1.5rem;
            padding-top: 1.5rem;
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
            text-decoration: none;
            transition: all 0.2s;
        }

        .btn-primary {
            background: linear-gradient(135deg, var(--primary), var(--secondary));
            color: white;
        }

        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(37, 99, 235, 0.3);
        }

        .btn-secondary {
            background: #e2e8f0;
            color: var(--dark);
        }

        .btn-secondary:hover {
            background: #cbd5e1;
        }

        .btn-danger {
            background: var(--danger);
            color: white;
        }

        .btn-danger:hover {
            background: #dc2626;
        }

        .btn-success {
            background: var(--success);
            color: white;
        }

        .btn-success:hover {
            background: #059669;
        }

        .notes-box {
            background: #fef3c7;
            padding: 1rem;
            border-radius: 0.5rem;
            border-left: 3px solid var(--warning);
            margin-top: 1rem;
        }

        @media (max-width: 640px) {
            body {
                padding: 1rem;
            }
            .info-grid {
                grid-template-columns: 1fr;
            }
            .info-row {
                flex-direction: column;
            }
            .info-label {
                width: 100%;
                margin-bottom: 0.25rem;
            }
            .action-buttons {
                flex-wrap: wrap;
            }
            .btn {
                flex: 1;
                justify-content: center;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="card">
            <div class="card-header">
                <h1>
                    🚗 Pickup Reminder Details
                </h1>
                <div class="reminder-number">
                    # <?php echo htmlspecialchars($reminder['reminder_number'] ?? 'N/A'); ?>
                </div>
            </div>
            
            <div class="card-body">
                <!-- Status Badge -->
                <div class="status-section">
                    <span class="badge <?php echo getStatusBadgeClass($reminder['status'] ?? 'pending'); ?>">
                        <?php echo getStatusIcon($reminder['status'] ?? 'pending'); ?>
                        <?php echo strtoupper(str_replace('_', ' ', $reminder['status'] ?? 'PENDING')); ?>
                    </span>
                </div>

                <div class="info-grid">
                    <!-- Customer Information -->
                    <div class="info-section">
                        <div class="section-title">
                            👤 Customer Information
                        </div>
                        <div class="info-row">
                            <div class="info-label">Customer Name:</div>
                            <div class="info-value"><strong><?php echo htmlspecialchars($reminder['customer_name'] ?? 'N/A'); ?></strong></div>
                        </div>
                        <div class="info-row">
                            <div class="info-label">Phone Number:</div>
                            <div class="info-value">
                                <a href="tel:<?php echo htmlspecialchars($reminder['customer_phone'] ?? ''); ?>">
                                    <?php echo htmlspecialchars($reminder['customer_phone'] ?? 'N/A'); ?>
                                </a>
                            </div>
                        </div>
                        <div class="info-row">
                            <div class="info-label">Email Address:</div>
                            <div class="info-value">
                                <a href="mailto:<?php echo htmlspecialchars($reminder['customer_email'] ?? ''); ?>">
                                    <?php echo htmlspecialchars($reminder['customer_email'] ?? 'N/A'); ?>
                                </a>
                            </div>
                        </div>
                        <?php if (!empty($reminder['customer_address'])): ?>
                        <div class="info-row">
                            <div class="info-label">Address:</div>
                            <div class="info-value"><?php echo nl2br(htmlspecialchars($reminder['customer_address'])); ?></div>
                        </div>
                        <?php endif; ?>
                    </div>

                    <!-- Vehicle Information -->
                    <div class="info-section">
                        <div class="section-title">
                            🚙 Vehicle Information
                        </div>
                        <div class="info-row">
                            <div class="info-label">Registration:</div>
                            <div class="info-value"><strong><?php echo htmlspecialchars($reminder['vehicle_reg'] ?? 'N/A'); ?></strong></div>
                        </div>
                        <div class="info-row">
                            <div class="info-label">Make:</div>
                            <div class="info-value"><?php echo htmlspecialchars($reminder['vehicle_make'] ?? 'N/A'); ?></div>
                        </div>
                        <div class="info-row">
                            <div class="info-label">Model:</div>
                            <div class="info-value"><?php echo htmlspecialchars($reminder['vehicle_model'] ?? 'N/A'); ?></div>
                        </div>
                    </div>

                    <!-- Pickup Details -->
                    <div class="info-section">
                        <div class="section-title">
                            📍 Pickup Details
                        </div>
                        <div class="info-row">
                            <div class="info-label">Pickup Type:</div>
                            <div class="info-value">
                                <?php echo getPickupTypeIcon($reminder['pickup_type'] ?? 'workshop'); ?>
                                <?php echo ucfirst($reminder['pickup_type'] ?? 'Workshop'); ?>
                            </div>
                        </div>
                        <div class="info-row">
                            <div class="info-label">Pickup Date:</div>
                            <div class="info-value">
                                <?php echo !empty($reminder['pickup_date']) ? date('l, d F Y', strtotime($reminder['pickup_date'])) : 'N/A'; ?>
                            </div>
                        </div>
                        <div class="info-row">
                            <div class="info-label">Pickup Time:</div>
                            <div class="info-value"><?php echo formatTime12Hour($reminder['pickup_time'] ?? null); ?></div>
                        </div>
                        <?php if (!empty($reminder['pickup_address'])): ?>
                        <div class="info-row">
                            <div class="info-label">Pickup Address:</div>
                            <div class="info-value"><?php echo nl2br(htmlspecialchars($reminder['pickup_address'])); ?></div>
                        </div>
                        <?php endif; ?>
                        <?php if (!empty($reminder['pickup_location_details'])): ?>
                        <div class="info-row">
                            <div class="info-label">Location Details:</div>
                            <div class="info-value"><?php echo nl2br(htmlspecialchars($reminder['pickup_location_details'])); ?></div>
                        </div>
                        <?php endif; ?>
                    </div>

                    <!-- Reminder Settings -->
                    <div class="info-section">
                        <div class="section-title">
                            🔔 Reminder Settings
                        </div>
                        <div class="info-row">
                            <div class="info-label">Reminder Date:</div>
                            <div class="info-value">
                                <?php echo !empty($reminder['reminder_date']) ? date('l, d F Y', strtotime($reminder['reminder_date'])) : 'N/A'; ?>
                            </div>
                        </div>
                        <div class="info-row">
                            <div class="info-label">Reminder Time:</div>
                            <div class="info-value"><?php echo formatTime12Hour($reminder['reminder_time'] ?? null); ?></div>
                        </div>
                        <div class="info-row">
                            <div class="info-label">Reminder Type:</div>
                            <div class="info-value">
                                <?php 
                                $reminderType = $reminder['reminder_type'] ?? 'sms';
                                if ($reminderType == 'sms') echo '📱 SMS Only';
                                elseif ($reminderType == 'email') echo '✉️ Email Only';
                                else echo '📱✉️ Both SMS & Email';
                                ?>
                            </div>
                        </div>
                        <div class="info-row">
                            <div class="info-label">Reminder Sent:</div>
                            <div class="info-value">
                                <?php if (!empty($reminder['reminder_sent'])): ?>
                                <span style="color: var(--success);">✅ Yes</span>
                                <?php else: ?>
                                <span style="color: var(--warning);">⏳ Pending</span>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>

                    <!-- Assignment Details -->
                    <div class="info-section">
                        <div class="section-title">
                            👨‍💼 Assignment Details
                        </div>
                        <div class="info-row">
                            <div class="info-label">Assigned To:</div>
                            <div class="info-value">
                                <?php if (!empty($reminder['assigned_staff_name'])): ?>
                                <strong><?php echo htmlspecialchars($reminder['assigned_staff_name']); ?></strong>
                                <?php else: ?>
                                <span style="color: var(--warning);">Unassigned</span>
                                <?php endif; ?>
                            </div>
                        </div>
                        <?php if (!empty($reminder['staff_email'])): ?>
                        <div class="info-row">
                            <div class="info-label">Staff Email:</div>
                            <div class="info-value"><?php echo htmlspecialchars($reminder['staff_email']); ?></div>
                        </div>
                        <?php endif; ?>
                        <div class="info-row">
                            <div class="info-label">Created:</div>
                            <div class="info-value">
                                <?php echo !empty($reminder['created_at']) ? date('d M Y, h:i A', strtotime($reminder['created_at'])) : 'N/A'; ?>
                            </div>
                        </div>
                        <div class="info-row">
                            <div class="info-label">Last Updated:</div>
                            <div class="info-value">
                                <?php echo !empty($reminder['updated_at']) ? date('d M Y, h:i A', strtotime($reminder['updated_at'])) : 'N/A'; ?>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Notes Section -->
                <?php if (!empty($reminder['notes'])): ?>
                <div class="notes-box">
                    <div style="font-weight: 600; margin-bottom: 0.5rem;">📝 Notes</div>
                    <div><?php echo nl2br(htmlspecialchars($reminder['notes'])); ?></div>
                </div>
                <?php endif; ?>

                <!-- Action Buttons -->
                <div class="action-buttons">
                    <a href="index.php" class="btn btn-secondary">
                        ← Back to List
                    </a>
                    <a href="edit_reminder.php?id=<?php echo $reminder['id']; ?>" class="btn btn-primary">
                        ✏️ Edit Reminder
                    </a>
                    <?php if (empty($reminder['reminder_sent']) && in_array($reminder['status'] ?? '', ['pending', 'scheduled'])): ?>
                    <button onclick="sendReminder(<?php echo $reminder['id']; ?>)" class="btn btn-success">
                        📧 Send Reminder
                    </button>
                    <?php endif; ?>
                    <?php if (($reminder['status'] ?? '') != 'completed' && ($reminder['status'] ?? '') != 'cancelled'): ?>
                    <button onclick="updateStatus(<?php echo $reminder['id']; ?>, 'completed')" class="btn btn-success">
                        ✅ Mark Completed
                    </button>
                    <?php endif; ?>
                    <?php if (($reminder['status'] ?? '') != 'cancelled' && ($reminder['status'] ?? '') != 'completed'): ?>
                    <button onclick="updateStatus(<?php echo $reminder['id']; ?>, 'cancelled')" class="btn btn-danger">
                        ❌ Cancel
                    </button>
                    <?php endif; ?>
                </div>
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

        // Send reminder
        function sendReminder(id) {
            if (confirm('Send reminder notification to customer?')) {
                showToast('Sending reminder...', 'info');
                // Simulate AJAX - replace with actual fetch
                setTimeout(() => {
                    showToast('Reminder sent successfully!', 'success');
                    setTimeout(() => location.reload(), 1500);
                }, 1000);
            }
        }

        // Update status
        function updateStatus(id, status) {
            let message = status === 'completed' ? 'Mark this reminder as completed?' : 'Cancel this reminder?';
            if (confirm(message)) {
                showToast('Updating status...', 'info');
                // Simulate AJAX - replace with actual fetch
                setTimeout(() => {
                    showToast(`Reminder ${status === 'completed' ? 'completed' : 'cancelled'} successfully!`, 'success');
                    setTimeout(() => location.reload(), 1500);
                }, 1000);
            }
        }
    </script>
</body>
</html>