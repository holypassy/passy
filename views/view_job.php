<?php
// view_job.php - View a single job card (read-only)
session_start();

if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    header('Location: index.php');
    exit();
}

if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    $_SESSION['flash_error'] = 'Invalid job card ID.';
    header('Location: job_cards.php');
    exit();
}
$job_id = (int)$_GET['id'];

try {
    $conn = new PDO("mysql:host=localhost;dbname=savant_motors_pos", "root", "");
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $stmt = $conn->prepare("
        SELECT 
            jc.*,
            c.full_name as customer_name,
            c.telephone as customer_phone,
            c.email as customer_email,
            tech.full_name as technician_name
        FROM job_cards jc
        LEFT JOIN customers c ON jc.customer_id = c.id
        LEFT JOIN technicians tech ON jc.assigned_technician_id = tech.id
        WHERE jc.id = :id AND jc.deleted_at IS NULL
    ");
    $stmt->execute([':id' => $job_id]);
    $job = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$job) {
        $_SESSION['flash_error'] = 'Job card not found.';
        header('Location: job_cards.php');
        exit();
    }
} catch (PDOException $e) {
    die("Database error: " . $e->getMessage());
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>View Job Card #<?php echo htmlspecialchars($job['job_number']); ?> - Savant Motors ERP</title>
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
            max-width: 1000px;
            margin: 0 auto;
            background: white;
            border-radius: 24px;
            box-shadow: 0 20px 35px rgba(0,0,0,0.1);
            overflow: hidden;
        }
        .header {
            background: linear-gradient(135deg, #2563eb, #1e3a8a);
            padding: 24px 30px;
            color: white;
        }
        .header h1 {
            font-size: 24px;
            font-weight: 700;
            margin-bottom: 5px;
        }
        .header p {
            opacity: 0.9;
            font-size: 14px;
        }
        .content {
            padding: 30px;
        }
        .section {
            margin-bottom: 30px;
        }
        .section-title {
            font-size: 18px;
            font-weight: 700;
            color: #0f172a;
            border-left: 4px solid #2563eb;
            padding-left: 12px;
            margin-bottom: 20px;
        }
        .info-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 20px;
        }
        .info-item {
            background: #f8fafc;
            padding: 12px 16px;
            border-radius: 12px;
        }
        .info-label {
            font-size: 11px;
            font-weight: 700;
            text-transform: uppercase;
            color: #64748b;
            margin-bottom: 5px;
        }
        .info-value {
            font-size: 16px;
            font-weight: 600;
            color: #0f172a;
        }
        .status-badge {
            display: inline-block;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 700;
        }
        .status-pending { background: #fed7aa; color: #9a3412; }
        .status-in_progress { background: #dbeafe; color: #1e40af; }
        .status-completed { background: #dcfce7; color: #166534; }
        .status-cancelled { background: #fee2e2; color: #991b1b; }
        .priority-badge {
            display: inline-block;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 700;
        }
        .priority-low { background: #e2e8f0; color: #334155; }
        .priority-normal { background: #dbeafe; color: #1e40af; }
        .priority-urgent { background: #fed7aa; color: #9a3412; }
        .priority-critical { background: #fee2e2; color: #991b1b; }
        .btn-group {
            display: flex;
            gap: 15px;
            margin-top: 30px;
            justify-content: flex-end;
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
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 8px;
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
        .btn-secondary:hover {
            background: #f1f5f9;
        }
        @media (max-width: 768px) {
            body { padding: 20px; }
            .content { padding: 20px; }
        }
    </style>
</head>
<body>
<div class="container">
    <div class="header">
        <h1><i class="fas fa-clipboard-list"></i> Job Card #<?php echo htmlspecialchars($job['job_number']); ?></h1>
        <p>View complete job card details</p>
    </div>
    <div class="content">
        <!-- Job Status & Priority -->
        <div class="section">
            <div class="section-title">Job Overview</div>
            <div class="info-grid">
                <div class="info-item">
                    <div class="info-label">Status</div>
                    <div class="info-value">
                        <span class="status-badge status-<?php echo $job['status']; ?>">
                            <?php echo strtoupper(str_replace('_', ' ', $job['status'])); ?>
                        </span>
                    </div>
                </div>
                <div class="info-item">
                    <div class="info-label">Priority</div>
                    <div class="info-value">
                        <span class="priority-badge priority-<?php echo $job['priority']; ?>">
                            <?php echo ucfirst($job['priority']); ?>
                        </span>
                    </div>
                </div>
                <div class="info-item">
                    <div class="info-label">Assigned Technician</div>
                    <div class="info-value"><?php echo htmlspecialchars($job['technician_name'] ?? 'Not assigned'); ?></div>
                </div>
            </div>
        </div>

        <!-- Customer Details -->
        <div class="section">
            <div class="section-title">Customer Information</div>
            <div class="info-grid">
                <div class="info-item">
                    <div class="info-label">Full Name</div>
                    <div class="info-value"><?php echo htmlspecialchars($job['customer_name'] ?? 'N/A'); ?></div>
                </div>
                <div class="info-item">
                    <div class="info-label">Phone</div>
                    <div class="info-value"><?php echo htmlspecialchars($job['customer_phone'] ?? 'N/A'); ?></div>
                </div>
                <div class="info-item">
                    <div class="info-label">Email</div>
                    <div class="info-value"><?php echo htmlspecialchars($job['customer_email'] ?? 'N/A'); ?></div>
                </div>
            </div>
        </div>

        <!-- Vehicle Details -->
        <div class="section">
            <div class="section-title">Vehicle Information</div>
            <div class="info-grid">
                <div class="info-item">
                    <div class="info-label">Registration</div>
                    <div class="info-value"><?php echo htmlspecialchars($job['vehicle_reg'] ?? 'N/A'); ?></div>
                </div>
                <div class="info-item">
                    <div class="info-label">Make</div>
                    <div class="info-value"><?php echo htmlspecialchars($job['vehicle_make'] ?? 'N/A'); ?></div>
                </div>
                <div class="info-item">
                    <div class="info-label">Model</div>
                    <div class="info-value"><?php echo htmlspecialchars($job['vehicle_model'] ?? 'N/A'); ?></div>
                </div>
                <div class="info-item">
                    <div class="info-label">Year</div>
                    <div class="info-value"><?php echo htmlspecialchars($job['vehicle_year'] ?? 'N/A'); ?></div>
                </div>
                <div class="info-item">
                    <div class="info-label">Odometer</div>
                    <div class="info-value"><?php echo htmlspecialchars($job['odometer_reading'] ?? 'N/A'); ?></div>
                </div>
                <div class="info-item">
                    <div class="info-label">Fuel Level</div>
                    <div class="info-value"><?php echo htmlspecialchars($job['fuel_level'] ?? 'N/A'); ?></div>
                </div>
            </div>
        </div>

        <!-- Dates -->
        <div class="section">
            <div class="section-title">Important Dates</div>
            <div class="info-grid">
                <div class="info-item">
                    <div class="info-label">Date Received</div>
                    <div class="info-value"><?php echo date('d/m/Y', strtotime($job['date_received'])); ?></div>
                </div>
                <div class="info-item">
                    <div class="info-label">Date Promised</div>
                    <div class="info-value"><?php echo $job['date_promised'] ? date('d/m/Y', strtotime($job['date_promised'])) : 'Not set'; ?></div>
                </div>
                <div class="info-item">
                    <div class="info-label">Date Completed</div>
                    <div class="info-value"><?php echo $job['date_completed'] ? date('d/m/Y', strtotime($job['date_completed'])) : 'Not completed'; ?></div>
                </div>
            </div>
        </div>

        <!-- Additional Info -->
        <div class="section">
            <div class="section-title">Additional Information</div>
            <div class="info-grid">
                <div class="info-item">
                    <div class="info-label">Brought By</div>
                    <div class="info-value"><?php echo htmlspecialchars($job['brought_by'] ?? 'N/A'); ?></div>
                </div>
                <div class="info-item">
                    <div class="info-label">Terms Accepted</div>
                    <div class="info-value"><?php echo ($job['terms_accepted'] ?? 0) ? 'Yes' : 'No'; ?></div>
                </div>
                <div class="info-item">
                    <div class="info-label">Created By</div>
                    <div class="info-value"><?php echo htmlspecialchars($job['created_by'] ?? 'N/A'); ?></div>
                </div>
            </div>
        </div>

        <!-- Notes -->
        <?php if (!empty($job['notes'])): ?>
        <div class="section">
            <div class="section-title">Notes</div>
            <div class="info-item">
                <div class="info-value"><?php echo nl2br(htmlspecialchars($job['notes'])); ?></div>
            </div>
        </div>
        <?php endif; ?>

        <!-- Work Items (if any) -->
        <?php if (!empty($job['work_items'])): ?>
        <div class="section">
            <div class="section-title">Work Items</div>
            <div class="info-item">
                <div class="info-value"><?php echo nl2br(htmlspecialchars($job['work_items'])); ?></div>
            </div>
        </div>
        <?php endif; ?>

        <!-- Inspection Data (if any) -->
        <?php if (!empty($job['inspection_data'])): ?>
        <div class="section">
            <div class="section-title">Inspection Data</div>
            <div class="info-item">
                <div class="info-value"><?php echo nl2br(htmlspecialchars($job['inspection_data'])); ?></div>
            </div>
        </div>
        <?php endif; ?>

        <!-- Actions -->
        <div class="btn-group">
            <a href="job_cards.php" class="btn btn-secondary"><i class="fas fa-arrow-left"></i> Back to List</a>
            <a href="edit_job.php?id=<?php echo $job_id; ?>" class="btn btn-secondary"><i class="fas fa-edit"></i> Edit</a>
            <a href="print_job.php?id=<?php echo $job_id; ?>" target="_blank" class="btn btn-primary"><i class="fas fa-print"></i> Print</a>
        </div>
    </div>
</div>
</body>
</html>