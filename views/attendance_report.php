<?php
session_start();

if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    header('Location: index.php');
    exit();
}

$user_full_name = $_SESSION['full_name'] ?? 'User';
$user_role = $_SESSION['role'] ?? 'user';

// Get filter parameters
$start_date = isset($_GET['start_date']) ? $_GET['start_date'] : date('Y-m-01');
$end_date = isset($_GET['end_date']) ? $_GET['end_date'] : date('Y-m-d');
$department = isset($_GET['department']) ? $_GET['department'] : 'all';
$status = isset($_GET['status']) ? $_GET['status'] : 'all';

try {
    $conn = new PDO("mysql:host=localhost;dbname=savant_motors_pos", "root", "");
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    // Get departments for filter
    $deptStmt = $conn->query("SELECT DISTINCT department FROM staff WHERE department IS NOT NULL AND department != ''");
    $departments = $deptStmt->fetchAll(PDO::FETCH_COLUMN);
    
    // Build query for attendance report
    $sql = "
        SELECT 
            sa.*,
            s.full_name,
            s.staff_code,
            s.position,
            s.department,
            TIMESTAMPDIFF(HOUR, sa.check_in_time, COALESCE(sa.check_out_time, NOW())) as hours_worked
        FROM staff_attendance sa
        LEFT JOIN staff s ON sa.staff_id = s.id
        WHERE sa.attendance_date BETWEEN :start_date AND :end_date
    ";
    
    $params = [':start_date' => $start_date, ':end_date' => $end_date];
    
    if ($department != 'all') {
        $sql .= " AND s.department = :department";
        $params[':department'] = $department;
    }
    
    $sql .= " ORDER BY sa.attendance_date DESC, s.full_name ASC";
    
    $stmt = $conn->prepare($sql);
    $stmt->execute($params);
    $attendance_records = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Get summary statistics
    $summaryStmt = $conn->prepare("
        SELECT 
            COUNT(DISTINCT sa.staff_id) as total_staff,
            COUNT(*) as total_attendance,
            COUNT(CASE WHEN sa.check_out_time IS NOT NULL THEN 1 END) as completed_days,
            AVG(TIMESTAMPDIFF(HOUR, sa.check_in_time, COALESCE(sa.check_out_time, NOW()))) as avg_hours
        FROM staff_attendance sa
        LEFT JOIN staff s ON sa.staff_id = s.id
        WHERE sa.attendance_date BETWEEN :start_date AND :end_date
        " . ($department != 'all' ? " AND s.department = :department" : "") . "
    ");
    
    $summaryParams = [':start_date' => $start_date, ':end_date' => $end_date];
    if ($department != 'all') {
        $summaryParams[':department'] = $department;
    }
    $summaryStmt->execute($summaryParams);
    $summary = $summaryStmt->fetch(PDO::FETCH_ASSOC);
    
    // Get daily breakdown
    $dailyStmt = $conn->prepare("
        SELECT 
            attendance_date,
            COUNT(*) as checkins,
            COUNT(CASE WHEN check_out_time IS NOT NULL THEN 1 END) as checkouts
        FROM staff_attendance
        WHERE attendance_date BETWEEN :start_date AND :end_date
        GROUP BY attendance_date
        ORDER BY attendance_date
    ");
    $dailyStmt->execute([':start_date' => $start_date, ':end_date' => $end_date]);
    $daily_breakdown = $dailyStmt->fetchAll(PDO::FETCH_ASSOC);
    
} catch(PDOException $e) {
    $error_message = 'Database error: ' . $e->getMessage();
    $attendance_records = [];
    $departments = [];
    $summary = ['total_staff' => 0, 'total_attendance' => 0, 'completed_days' => 0, 'avg_hours' => 0];
    $daily_breakdown = [];
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Attendance Report | Savant Motors</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: 'Calibri', 'Segoe UI', 'Inter', sans-serif;
            background: #f0f2f5;
            padding: 40px;
        }
        :root {
            --primary: #1e40af;
            --primary-light: #3b82f6;
            --success: #10b981;
            --danger: #ef4444;
            --warning: #f59e0b;
            --dark: #0f172a;
            --gray: #64748b;
            --border: #e2e8f0;
        }
        
        .report-container {
            max-width: 1400px;
            margin: 0 auto;
            background: white;
            border-radius: 28px;
            overflow: hidden;
            box-shadow: 0 20px 35px -10px rgba(0,0,0,0.1);
        }
        
        .report-header {
            background: linear-gradient(135deg, var(--primary), #1e3a8a);
            color: white;
            padding: 30px 35px;
        }
        
        .report-header h1 {
            font-size: 28px;
            font-weight: 800;
            display: flex;
            align-items: center;
            gap: 12px;
        }
        
        .filter-section {
            padding: 25px 35px;
            background: #f8fafc;
            border-bottom: 1px solid var(--border);
            display: flex;
            gap: 20px;
            flex-wrap: wrap;
            align-items: flex-end;
        }
        
        .filter-group {
            flex: 1;
            min-width: 150px;
        }
        
        .filter-group label {
            display: block;
            font-size: 11px;
            font-weight: 700;
            color: var(--gray);
            margin-bottom: 6px;
            text-transform: uppercase;
        }
        
        .filter-group input, .filter-group select {
            width: 100%;
            padding: 10px 14px;
            border: 1px solid var(--border);
            border-radius: 12px;
            font-size: 13px;
        }
        
        .btn {
            padding: 10px 24px;
            border: none;
            border-radius: 40px;
            font-size: 13px;
            font-weight: 600;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }
        
        .btn-primary {
            background: var(--primary);
            color: white;
        }
        
        .btn-secondary {
            background: white;
            border: 1px solid var(--border);
        }
        
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 20px;
            padding: 25px 35px;
            background: white;
            border-bottom: 1px solid var(--border);
        }
        
        .stat-card {
            background: #f8fafc;
            border-radius: 20px;
            padding: 20px;
            text-align: center;
        }
        
        .stat-value {
            font-size: 32px;
            font-weight: 800;
            color: var(--primary);
        }
        
        .stat-label {
            font-size: 12px;
            color: var(--gray);
            margin-top: 6px;
        }
        
        .table-container {
            padding: 25px 35px;
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
            background: #f8fafc;
        }
        
        .status-badge {
            display: inline-block;
            padding: 4px 12px;
            border-radius: 40px;
            font-size: 11px;
            font-weight: 600;
        }
        
        .status-present { background: #dcfce7; color: #166534; }
        
        .print-btn {
            margin-left: auto;
        }
        
        @media print {
            body {
                padding: 0;
                background: white;
            }
            .filter-section, .btn, .print-btn, .no-print {
                display: none !important;
            }
            .report-container {
                box-shadow: none;
            }
        }
        
        @media (max-width: 768px) {
            body { padding: 20px; }
            .stats-grid { grid-template-columns: repeat(2, 1fr); }
            .filter-section { flex-direction: column; }
        }
    </style>
</head>
<body>
    <div class="report-container">
        <div class="report-header">
            <h1><i class="fas fa-chart-bar"></i> Attendance Report</h1>
            <p style="margin-top: 10px; opacity: 0.9;">Comprehensive attendance records and analytics</p>
        </div>
        
        <div class="filter-section no-print">
            <form method="GET" action="" style="display: contents;">
                <div class="filter-group">
                    <label>Start Date</label>
                    <input type="date" name="start_date" value="<?php echo $start_date; ?>">
                </div>
                <div class="filter-group">
                    <label>End Date</label>
                    <input type="date" name="end_date" value="<?php echo $end_date; ?>">
                </div>
                <div class="filter-group">
                    <label>Department</label>
                    <select name="department">
                        <option value="all">All Departments</option>
                        <?php foreach ($departments as $dept): ?>
                            <option value="<?php echo htmlspecialchars($dept); ?>" <?php echo $department == $dept ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($dept); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="filter-group">
                    <button type="submit" class="btn btn-primary"><i class="fas fa-search"></i> Generate Report</button>
                    <button type="button" class="btn btn-secondary" onclick="window.print()"><i class="fas fa-print"></i> Print</button>
                    <button type="button" class="btn btn-secondary" onclick="window.location.href='attendance.php'"><i class="fas fa-arrow-left"></i> Back</button>
                </div>
            </form>
        </div>
        
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-value"><?php echo $summary['total_staff'] ?? 0; ?></div>
                <div class="stat-label">Staff Members</div>
            </div>
            <div class="stat-card">
                <div class="stat-value"><?php echo $summary['total_attendance'] ?? 0; ?></div>
                <div class="stat-label">Total Attendance Records</div>
            </div>
            <div class="stat-card">
                <div class="stat-value"><?php echo $summary['completed_days'] ?? 0; ?></div>
                <div class="stat-label">Completed Work Days</div>
            </div>
            <div class="stat-card">
                <div class="stat-value"><?php echo round($summary['avg_hours'] ?? 0, 1); ?></div>
                <div class="stat-label">Avg Hours/Day</div>
            </div>
        </div>
        
        <div class="table-container">
            <h3 style="margin-bottom: 20px;"><i class="fas fa-list"></i> Attendance Details</h3>
            <?php if (empty($attendance_records)): ?>
                <p style="text-align: center; padding: 40px; color: var(--gray);">
                    <i class="fas fa-info-circle"></i> No attendance records found for the selected period
                </p>
            <?php else: ?>
                <table>
                    <thead>
                        <tr>
                            <th>Date</th>
                            <th>Staff Code</th>
                            <th>Name</th>
                            <th>Department</th>
                            <th>Check In</th>
                            <th>Check Out</th>
                            <th>Hours</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($attendance_records as $record): 
                            $hours = $record['hours_worked'] ?? 0;
                            $hours_display = $hours > 0 ? floor($hours) . 'h ' . ($hours * 60 % 60) . 'm' : '--';
                        ?>
                        <tr>
                            <td><?php echo date('d M Y', strtotime($record['attendance_date'])); ?></td>
                            <td><?php echo htmlspecialchars($record['staff_code']); ?></td>
                            <td><?php echo htmlspecialchars($record['full_name']); ?></td>
                            <td><?php echo htmlspecialchars($record['department']); ?></td>
                            <td><?php echo date('h:i A', strtotime($record['check_in_time'])); ?></td>
                            <td><?php echo $record['check_out_time'] ? date('h:i A', strtotime($record['check_out_time'])) : '--'; ?></td>
                            <td><?php echo $hours_display; ?></td>
                            <td><span class="status-badge status-present">Present</span></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
    </div>
</body>
</html>