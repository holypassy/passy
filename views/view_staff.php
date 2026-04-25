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
    
    // Get staff details
    $stmt = $conn->prepare("
        SELECT 
            s.*,
            DATEDIFF(CURDATE(), s.hire_date) AS days_employed,
            (SELECT COUNT(*) FROM staff_attendance sa WHERE sa.staff_id = s.id AND sa.attendance_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)) AS attendance_last_30_days,
            (SELECT COUNT(*) FROM staff_leave_requests slr WHERE slr.staff_id = s.id AND slr.status = 'pending') AS pending_leave_requests,
            (SELECT COUNT(*) FROM staff_leave_requests slr WHERE slr.staff_id = s.id AND slr.status = 'approved' AND slr.start_date <= CURDATE() AND slr.end_date >= CURDATE()) AS on_leave_today
        FROM staff s
        WHERE s.id = :id AND (s.deleted_at IS NULL OR s.deleted_at = '0000-00-00 00:00:00')
    ");
    $stmt->execute([':id' => $staff_id]);
    $staff = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$staff) {
        header('Location: technicians.php');
        exit();
    }
    
    // Get staff activity log
    $stmt = $conn->prepare("
        SELECT * FROM staff_activity_log 
        WHERE staff_id = :id 
        ORDER BY created_at DESC 
        LIMIT 10
    ");
    $stmt->execute([':id' => $staff_id]);
    $activities = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Get recent leave requests
    $stmt = $conn->prepare("
        SELECT * FROM staff_leave_requests 
        WHERE staff_id = :id 
        ORDER BY created_at DESC 
        LIMIT 5
    ");
    $stmt->execute([':id' => $staff_id]);
    $leave_requests = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Get recent attendance
    $stmt = $conn->prepare("
        SELECT * FROM staff_attendance 
        WHERE staff_id = :id 
        ORDER BY attendance_date DESC 
        LIMIT 10
    ");
    $stmt->execute([':id' => $staff_id]);
    $attendance = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Get performance reviews
    $stmt = $conn->prepare("
        SELECT * FROM staff_performance_reviews 
        WHERE staff_id = :id 
        ORDER BY review_date DESC 
        LIMIT 3
    ");
    $stmt->execute([':id' => $staff_id]);
    $reviews = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
} catch(PDOException $e) {
    $error_message = 'Database error: ' . $e->getMessage();
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>View Staff | <?php echo htmlspecialchars($staff['full_name'] ?? 'Staff'); ?> | Savant Motors</title>
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
            --info: #3b82f6;
            --dark: #0f172a;
            --gray: #64748b;
            --light: #f8fafc;
            --border: #e2e8f0;
            --shadow-sm: 0 1px 2px 0 rgb(0 0 0 / 0.05);
            --shadow-md: 0 4px 6px -1px rgb(0 0 0 / 0.1);
            --shadow-lg: 0 10px 15px -3px rgb(0 0 0 / 0.1);
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
            max-width: 1200px;
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

        .action-buttons {
            display: flex;
            gap: 12px;
            margin-bottom: 30px;
        }

        .btn {
            padding: 10px 24px;
            border: none;
            border-radius: 40px;
            font-size: 13px;
            font-weight: 600;
            cursor: pointer;
            transition: var(--transition);
            display: inline-flex;
            align-items: center;
            gap: 8px;
            text-decoration: none;
        }

        .btn-primary {
            background: linear-gradient(135deg, var(--primary-light), var(--primary));
            color: white;
        }

        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(59, 130, 246, 0.3);
        }

        .btn-secondary {
            background: white;
            border: 1px solid var(--border);
            color: var(--gray);
        }

        .btn-secondary:hover {
            background: var(--light);
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

        .profile-header {
            background: white;
            border-radius: 32px;
            border: 1px solid var(--border);
            overflow: hidden;
            margin-bottom: 30px;
        }

        .profile-cover {
            height: 120px;
            background: linear-gradient(135deg, var(--primary-light), var(--primary));
        }

        .profile-info {
            padding: 0 32px 32px 32px;
            position: relative;
            display: flex;
            gap: 30px;
            flex-wrap: wrap;
        }

        .profile-avatar {
            margin-top: -40px;
        }

        .avatar-large {
            width: 120px;
            height: 120px;
            background: linear-gradient(135deg, var(--primary-light), var(--primary));
            border-radius: 60px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 48px;
            font-weight: 700;
            color: white;
            border: 4px solid white;
            box-shadow: var(--shadow-md);
        }

        .profile-details {
            flex: 1;
            padding-top: 20px;
        }

        .profile-name {
            font-size: 28px;
            font-weight: 800;
            color: var(--dark);
            margin-bottom: 8px;
        }

        .profile-meta {
            display: flex;
            gap: 20px;
            flex-wrap: wrap;
            margin-bottom: 16px;
        }

        .meta-badge {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 6px 14px;
            background: var(--light);
            border-radius: 40px;
            font-size: 13px;
            color: var(--gray);
        }

        .status-badge {
            padding: 6px 14px;
            border-radius: 40px;
            font-size: 12px;
            font-weight: 700;
        }

        .status-active { background: #dcfce7; color: #166534; }
        .status-blocked { background: #fee2e2; color: #991b1b; }
        .status-on_leave { background: #fed7aa; color: #9a3412; }
        .status-terminated { background: #f1f5f9; color: #475569; }

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 20px;
            margin-bottom: 30px;
        }

        .stat-card {
            background: white;
            border-radius: 24px;
            padding: 20px;
            border: 1px solid var(--border);
            transition: var(--transition);
        }

        .stat-card:hover {
            transform: translateY(-2px);
            box-shadow: var(--shadow-md);
        }

        .stat-icon {
            width: 48px;
            height: 48px;
            border-radius: 16px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 24px;
            margin-bottom: 12px;
        }

        .stat-icon.blue { background: #dbeafe; color: var(--primary); }
        .stat-icon.green { background: #dcfce7; color: var(--success); }
        .stat-icon.orange { background: #fed7aa; color: var(--warning); }
        .stat-icon.purple { background: #e9d5ff; color: #9333ea; }

        .stat-value {
            font-size: 28px;
            font-weight: 800;
            color: var(--dark);
        }

        .stat-label {
            font-size: 12px;
            color: var(--gray);
            margin-top: 4px;
        }

        .info-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 30px;
            margin-bottom: 30px;
        }

        .info-card {
            background: white;
            border-radius: 24px;
            border: 1px solid var(--border);
            overflow: hidden;
        }

        .card-header {
            padding: 20px 24px;
            background: var(--light);
            border-bottom: 1px solid var(--border);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .card-header h3 {
            font-size: 16px;
            font-weight: 700;
            color: var(--dark);
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .card-body {
            padding: 24px;
        }

        .detail-row {
            display: flex;
            justify-content: space-between;
            padding: 12px 0;
            border-bottom: 1px dashed var(--border);
        }

        .detail-row:last-child {
            border-bottom: none;
        }

        .detail-label {
            font-size: 13px;
            color: var(--gray);
            font-weight: 500;
        }

        .detail-value {
            font-weight: 600;
            color: var(--dark);
        }

        .activity-item {
            padding: 12px 0;
            border-bottom: 1px solid var(--border);
            display: flex;
            gap: 12px;
        }

        .activity-icon {
            width: 32px;
            height: 32px;
            background: var(--light);
            border-radius: 40px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: var(--primary);
        }

        .activity-content {
            flex: 1;
        }

        .activity-title {
            font-weight: 600;
            font-size: 13px;
            color: var(--dark);
        }

        .activity-date {
            font-size: 11px;
            color: var(--gray);
            margin-top: 4px;
        }

        .table-wrapper {
            overflow-x: auto;
        }

        table {
            width: 100%;
            border-collapse: collapse;
        }

        th, td {
            padding: 12px;
            text-align: left;
            border-bottom: 1px solid var(--border);
        }

        th {
            font-size: 12px;
            font-weight: 700;
            color: var(--gray);
            text-transform: uppercase;
            background: var(--light);
        }

        td {
            font-size: 13px;
        }

        .leave-status {
            display: inline-block;
            padding: 4px 12px;
            border-radius: 40px;
            font-size: 11px;
            font-weight: 600;
        }

        .leave-pending { background: #fef3c7; color: #d97706; }
        .leave-approved { background: #dcfce7; color: #166534; }
        .leave-rejected { background: #fee2e2; color: #dc2626; }

        @media (max-width: 768px) {
            .stats-grid {
                grid-template-columns: repeat(2, 1fr);
            }
            .info-grid {
                grid-template-columns: 1fr;
            }
            .profile-info {
                flex-direction: column;
                align-items: center;
                text-align: center;
            }
            .profile-meta {
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
            <h1><i class="fas fa-id-card"></i> Staff Profile</h1>
            <div class="breadcrumb">
                <a href="technicians.php">Staff Management</a>
                <i class="fas fa-chevron-right" style="font-size: 10px;"></i>
                <span>View Staff</span>
            </div>
        </div>

        <div class="action-buttons">
            <a href="edit_staff.php?id=<?php echo $staff_id; ?>" class="btn btn-primary">
                <i class="fas fa-edit"></i> Edit Staff
            </a>
            <a href="technicians.php" class="btn btn-secondary">
                <i class="fas fa-arrow-left"></i> Back to List
            </a>
        </div>

        <?php if ($error_message): ?>
        <div class="alert alert-error" style="background: #fee2e2; padding: 16px; border-radius: 16px; margin-bottom: 20px; color: #991b1b;">
            <i class="fas fa-exclamation-triangle"></i> <?php echo htmlspecialchars($error_message); ?>
        </div>
        <?php endif; ?>

        <?php if ($staff): 
            $initials = '';
            $nameParts = explode(' ', $staff['full_name']);
            foreach ($nameParts as $part) $initials .= strtoupper(substr($part, 0, 1));
            $initials = substr($initials, 0, 2);
            $statusClass = $staff['is_blocked'] ? 'blocked' : ($staff['status'] == 'on_leave' ? 'on_leave' : ($staff['status'] == 'terminated' ? 'terminated' : 'active'));
        ?>
        <!-- Profile Header -->
        <div class="profile-header">
            <div class="profile-cover"></div>
            <div class="profile-info">
                <div class="profile-avatar">
                    <div class="avatar-large"><?php echo $initials; ?></div>
                </div>
                <div class="profile-details">
                    <div class="profile-name"><?php echo htmlspecialchars($staff['full_name']); ?></div>
                    <div class="profile-meta">
                        <span class="meta-badge"><i class="fas fa-id-badge"></i> <?php echo htmlspecialchars($staff['staff_code']); ?></span>
                        <span class="meta-badge"><i class="fas fa-briefcase"></i> <?php echo htmlspecialchars($staff['position']); ?></span>
                        <span class="meta-badge"><i class="fas fa-building"></i> <?php echo htmlspecialchars($staff['department']); ?></span>
                        <span class="status-badge status-<?php echo $statusClass; ?>">
                            <?php echo $staff['is_blocked'] ? 'BLOCKED' : strtoupper(str_replace('_', ' ', $staff['status'])); ?>
                        </span>
                        <?php if ($staff['on_leave_today'] > 0): ?>
                        <span class="status-badge status-on_leave"><i class="fas fa-umbrella-beach"></i> On Leave Today</span>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>

        <!-- Statistics -->
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-icon blue"><i class="fas fa-calendar-alt"></i></div>
                <div class="stat-value"><?php echo floor($staff['days_employed'] / 365); ?> yrs</div>
                <div class="stat-label">Years Employed</div>
            </div>
            <div class="stat-card">
                <div class="stat-icon green"><i class="fas fa-check-circle"></i></div>
                <div class="stat-value"><?php echo $staff['attendance_last_30_days']; ?>/30</div>
                <div class="stat-label">Days Present (30 days)</div>
            </div>
            <div class="stat-card">
                <div class="stat-icon orange"><i class="fas fa-clock"></i></div>
                <div class="stat-value"><?php echo $staff['pending_leave_requests']; ?></div>
                <div class="stat-label">Pending Leave Requests</div>
            </div>
            <div class="stat-card">
                <div class="stat-icon purple"><i class="fas fa-star"></i></div>
                <div class="stat-value"><?php echo count($reviews); ?></div>
                <div class="stat-label">Performance Reviews</div>
            </div>
        </div>

        <!-- Information Grid -->
        <div class="info-grid">
            <!-- Personal Information -->
            <div class="info-card">
                <div class="card-header">
                    <h3><i class="fas fa-user-circle"></i> Personal Information</h3>
                </div>
                <div class="card-body">
                    <div class="detail-row">
                        <span class="detail-label">Full Name</span>
                        <span class="detail-value"><?php echo htmlspecialchars($staff['full_name']); ?></span>
                    </div>
                    <div class="detail-row">
                        <span class="detail-label">Phone Number</span>
                        <span class="detail-value"><?php echo htmlspecialchars($staff['phone']); ?></span>
                    </div>
                    <div class="detail-row">
                        <span class="detail-label">Email Address</span>
                        <span class="detail-value"><?php echo htmlspecialchars($staff['email'] ?: 'N/A'); ?></span>
                    </div>
                    <div class="detail-row">
                        <span class="detail-label">Address</span>
                        <span class="detail-value"><?php echo nl2br(htmlspecialchars($staff['address'] ?: 'N/A')); ?></span>
                    </div>
                </div>
            </div>

            <!-- Employment Information -->
            <div class="info-card">
                <div class="card-header">
                    <h3><i class="fas fa-briefcase"></i> Employment Information</h3>
                </div>
                <div class="card-body">
                    <div class="detail-row">
                        <span class="detail-label">Staff Code</span>
                        <span class="detail-value"><?php echo htmlspecialchars($staff['staff_code']); ?></span>
                    </div>
                    <div class="detail-row">
                        <span class="detail-label">Position</span>
                        <span class="detail-value"><?php echo htmlspecialchars($staff['position']); ?></span>
                    </div>
                    <div class="detail-row">
                        <span class="detail-label">Department</span>
                        <span class="detail-value"><?php echo htmlspecialchars($staff['department']); ?></span>
                    </div>
                    <div class="detail-row">
                        <span class="detail-label">Hire Date</span>
                        <span class="detail-value"><?php echo date('d M Y', strtotime($staff['hire_date'])); ?></span>
                    </div>
                    <div class="detail-row">
                        <span class="detail-label">Monthly Salary</span>
                        <span class="detail-value"><?php echo $staff['salary'] ? 'UGX ' . number_format($staff['salary']) : 'N/A'; ?></span>
                    </div>
                </div>
            </div>

            <!-- Emergency Contact -->
            <div class="info-card">
                <div class="card-header">
                    <h3><i class="fas fa-ambulance"></i> Emergency Contact</h3>
                </div>
                <div class="card-body">
                    <div class="detail-row">
                        <span class="detail-label">Contact Name</span>
                        <span class="detail-value"><?php echo htmlspecialchars($staff['emergency_contact_name'] ?: 'N/A'); ?></span>
                    </div>
                    <div class="detail-row">
                        <span class="detail-label">Contact Phone</span>
                        <span class="detail-value"><?php echo htmlspecialchars($staff['emergency_contact_phone'] ?: 'N/A'); ?></span>
                    </div>
                </div>
            </div>

            <!-- Recent Activity -->
            <div class="info-card">
                <div class="card-header">
                    <h3><i class="fas fa-history"></i> Recent Activity</h3>
                </div>
                <div class="card-body">
                    <?php if (empty($activities)): ?>
                        <p style="color: var(--gray); text-align: center;">No recent activity</p>
                    <?php else: ?>
                        <?php foreach ($activities as $activity): ?>
                        <div class="activity-item">
                            <div class="activity-icon">
                                <i class="fas fa-user-edit"></i>
                            </div>
                            <div class="activity-content">
                                <div class="activity-title"><?php echo htmlspecialchars($activity['description']); ?></div>
                                <div class="activity-date"><?php echo date('d M Y, h:i A', strtotime($activity['created_at'])); ?></div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Leave Requests -->
        <div class="info-card" style="margin-bottom: 30px;">
            <div class="card-header">
                <h3><i class="fas fa-calendar-alt"></i> Leave Requests</h3>
                <a href="#" style="font-size: 12px; color: var(--primary);">View All</a>
            </div>
            <div class="card-body">
                <?php if (empty($leave_requests)): ?>
                    <p style="color: var(--gray; text-align: center;">No leave requests found</p>
                <?php else: ?>
                    <div class="table-wrapper">
                        <table>
                            <thead>
                                 <tr>
                                    <th>Type</th>
                                    <th>Start Date</th>
                                    <th>End Date</th>
                                    <th>Days</th>
                                    <th>Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($leave_requests as $leave): ?>
                                <tr>
                                    <td><?php echo ucfirst(htmlspecialchars($leave['leave_type'])); ?></td>
                                    <td><?php echo date('d M Y', strtotime($leave['start_date'])); ?></td>
                                    <td><?php echo date('d M Y', strtotime($leave['end_date'])); ?></td>
                                    <td><?php echo $leave['total_days']; ?></td>
                                    <td>
                                        <span class="leave-status leave-<?php echo $leave['status']; ?>">
                                            <?php echo ucfirst($leave['status']); ?>
                                        </span>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Recent Attendance -->
        <div class="info-card">
            <div class="card-header">
                <h3><i class="fas fa-clock"></i> Recent Attendance</h3>
                <a href="#" style="font-size: 12px; color: var(--primary);">View All</a>
            </div>
            <div class="card-body">
                <?php if (empty($attendance)): ?>
                    <p style="color: var(--gray; text-align: center;">No attendance records found</p>
                <?php else: ?>
                    <div class="table-wrapper">
                        <table>
                            <thead>
                                 <tr>
                                    <th>Date</th>
                                    <th>Check In</th>
                                    <th>Check Out</th>
                                    <th>Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($attendance as $record): ?>
                                <tr>
                                    <td><?php echo date('d M Y', strtotime($record['attendance_date'])); ?></td>
                                    <td><?php echo $record['check_in_time'] ?: '--'; ?></td>
                                    <td><?php echo $record['check_out_time'] ?: '--'; ?></td>
                                    <td>
                                        <span class="status-badge status-<?php echo $record['status']; ?>">
                                            <?php echo ucfirst(str_replace('_', ' ', $record['status'])); ?>
                                        </span>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>
    </div>
</body>
</html>