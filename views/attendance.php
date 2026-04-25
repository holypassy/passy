<?php
session_start();

if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    header('Location: index.php');
    exit();
}

$user_full_name = $_SESSION['full_name'] ?? 'User';
$user_role = $_SESSION['role'] ?? 'user';
$user_id = $_SESSION['user_id'] ?? 1;

$error_message = '';
$success_message = '';

try {
    $conn = new PDO("mysql:host=localhost;dbname=savant_motors_pos", "root", "");
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    // Function to get or create a staff record for a technician
    function getOrCreateStaffForTechnician($conn, $tech_id) {
        // Check if a staff record already exists for this technician
        $stmt = $conn->prepare("SELECT id FROM staff WHERE source_type = 'technician' AND source_id = :tech_id");
        $stmt->execute([':tech_id' => $tech_id]);
        $existing = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($existing) {
            return $existing['id'];
        }
        
        // Fetch technician details
        $techStmt = $conn->prepare("SELECT full_name, technician_code, specialization, status, is_blocked FROM technicians WHERE id = :id");
        $techStmt->execute([':id' => $tech_id]);
        $tech = $techStmt->fetch(PDO::FETCH_ASSOC);
        if (!$tech) {
            return false;
        }
        
        // Insert a new staff record linked to this technician
        $insert = $conn->prepare("
            INSERT INTO staff (full_name, staff_code, position, department, status, is_blocked, source_type, source_id, created_at) 
            VALUES (:full_name, :staff_code, :position, 'Technician', :status, :is_blocked, 'technician', :tech_id, NOW())
        ");
        $insert->execute([
            ':full_name' => $tech['full_name'],
            ':staff_code' => $tech['technician_code'],
            ':position' => $tech['specialization'],
            ':status' => $tech['status'],
            ':is_blocked' => $tech['is_blocked'],
            ':tech_id' => $tech_id
        ]);
        
        return $conn->lastInsertId();
    }
    
    // Handle Check-in/Check-out AJAX requests
    if (isset($_POST['ajax_action'])) {
        header('Content-Type: application/json');
        
        try {
            if ($_POST['ajax_action'] == 'check_in') {
                $type = $_POST['type'] ?? 'staff'; // 'staff' or 'technician'
                $id = (int)$_POST['id'];
                
                $staff_id = null;
                if ($type == 'staff') {
                    // Validate staff exists and is active
                    $staffCheck = $conn->prepare("SELECT id FROM staff WHERE id = :id AND status = 'active' AND (deleted_at IS NULL OR deleted_at = '0000-00-00 00:00:00')");
                    $staffCheck->execute([':id' => $id]);
                    if (!$staffCheck->fetch()) {
                        echo json_encode(['success' => false, 'error' => 'Invalid staff member']);
                        exit();
                    }
                    $staff_id = $id;
                } else if ($type == 'technician') {
                    // Get or create staff record for this technician
                    $staff_id = getOrCreateStaffForTechnician($conn, $id);
                    if (!$staff_id) {
                        echo json_encode(['success' => false, 'error' => 'Technician not found']);
                        exit();
                    }
                } else {
                    echo json_encode(['success' => false, 'error' => 'Invalid type']);
                    exit();
                }
                
                // Check if already checked in today
                $checkStmt = $conn->prepare("
                    SELECT id FROM staff_attendance 
                    WHERE staff_id = :staff_id AND attendance_date = CURDATE()
                ");
                $checkStmt->execute([':staff_id' => $staff_id]);
                
                if ($checkStmt->fetch()) {
                    echo json_encode(['success' => false, 'error' => 'Already checked in today']);
                    exit();
                }
                
                // Record check-in
                $stmt = $conn->prepare("
                    INSERT INTO staff_attendance (staff_id, attendance_date, check_in_time, status, created_at)
                    VALUES (:staff_id, CURDATE(), NOW(), 'present', NOW())
                ");
                $result = $stmt->execute([':staff_id' => $staff_id]);
                
                if ($result) {
                    echo json_encode(['success' => true, 'message' => 'Check-in successful!']);
                } else {
                    echo json_encode(['success' => false, 'error' => 'Failed to record check-in']);
                }
                exit();
            }
            
            if ($_POST['ajax_action'] == 'check_out') {
                $attendance_id = (int)$_POST['attendance_id'];
                
                $stmt = $conn->prepare("
                    UPDATE staff_attendance 
                    SET check_out_time = NOW(),
                        updated_at = NOW()
                    WHERE id = :id AND check_out_time IS NULL
                ");
                $result = $stmt->execute([':id' => $attendance_id]);
                
                if ($result && $stmt->rowCount() > 0) {
                    echo json_encode(['success' => true, 'message' => 'Check-out successful!']);
                } else {
                    echo json_encode(['success' => false, 'error' => 'Failed to record check-out']);
                }
                exit();
            }

            // ── AI Agent: gather attendance context and call Claude ───────────
            if ($_POST['ajax_action'] == 'ai_agent') {
                header('Content-Type: application/json');
                $user_question = trim($_POST['question'] ?? '');
                if (!$user_question) {
                    echo json_encode(['success' => false, 'error' => 'No question provided']);
                    exit();
                }

                // Gather rich attendance data for context
                try {
                    // Late arrivals in last 30 days (check-in after 08:30)
                    $lateStmt = $conn->query("
                        SELECT s.full_name, s.department, s.position,
                               sa.attendance_date, TIME(sa.check_in_time) as check_in_time,
                               TIMESTAMPDIFF(MINUTE, CONCAT(DATE(sa.attendance_date),' 08:30:00'), sa.check_in_time) as minutes_late,
                               DAYNAME(sa.attendance_date) as day_of_week
                        FROM staff_attendance sa
                        JOIN staff s ON sa.staff_id = s.id
                        WHERE sa.attendance_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
                          AND TIME(sa.check_in_time) > '08:30:00'
                        ORDER BY minutes_late DESC
                        LIMIT 50
                    ");
                    $late_records = $lateStmt->fetchAll(PDO::FETCH_ASSOC);

                    // Most frequently late employees
                    $freqLateStmt = $conn->query("
                        SELECT s.full_name, s.department, s.position,
                               COUNT(*) as late_count,
                               AVG(TIMESTAMPDIFF(MINUTE, CONCAT(DATE(sa.attendance_date),' 08:30:00'), sa.check_in_time)) as avg_minutes_late,
                               MAX(TIMESTAMPDIFF(MINUTE, CONCAT(DATE(sa.attendance_date),' 08:30:00'), sa.check_in_time)) as max_minutes_late,
                               GROUP_CONCAT(DISTINCT DAYNAME(sa.attendance_date) ORDER BY FIELD(DAYNAME(sa.attendance_date),'Monday','Tuesday','Wednesday','Thursday','Friday') SEPARATOR ', ') as late_days
                        FROM staff_attendance sa
                        JOIN staff s ON sa.staff_id = s.id
                        WHERE sa.attendance_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
                          AND TIME(sa.check_in_time) > '08:30:00'
                        GROUP BY sa.staff_id, s.full_name, s.department, s.position
                        ORDER BY late_count DESC
                    ");
                    $frequent_late = $freqLateStmt->fetchAll(PDO::FETCH_ASSOC);

                    // Day-of-week lateness pattern
                    $dayPatternStmt = $conn->query("
                        SELECT DAYNAME(attendance_date) as day_name,
                               DAYOFWEEK(attendance_date) as day_num,
                               COUNT(*) as late_count,
                               AVG(TIMESTAMPDIFF(MINUTE, CONCAT(DATE(attendance_date),' 08:30:00'), check_in_time)) as avg_late_mins
                        FROM staff_attendance
                        WHERE attendance_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
                          AND TIME(check_in_time) > '08:30:00'
                        GROUP BY DAYOFWEEK(attendance_date), DAYNAME(attendance_date)
                        ORDER BY day_num
                    ");
                    $day_patterns = $dayPatternStmt->fetchAll(PDO::FETCH_ASSOC);

                    // Overall summary
                    $summStmt = $conn->query("
                        SELECT
                            COUNT(*) as total_checkins,
                            SUM(CASE WHEN TIME(check_in_time) > '08:30:00' THEN 1 ELSE 0 END) as total_late,
                            AVG(CASE WHEN TIME(check_in_time) > '08:30:00' 
                                THEN TIMESTAMPDIFF(MINUTE, CONCAT(DATE(attendance_date),' 08:30:00'), check_in_time)
                                ELSE NULL END) as avg_late_mins
                        FROM staff_attendance
                        WHERE attendance_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
                    ");
                    $summary = $summStmt->fetch(PDO::FETCH_ASSOC);

                    // Today's late arrivals
                    $todayLateStmt = $conn->query("
                        SELECT s.full_name, s.department,
                               TIME(sa.check_in_time) as check_in_time,
                               TIMESTAMPDIFF(MINUTE, CONCAT(CURDATE(),' 08:30:00'), sa.check_in_time) as minutes_late
                        FROM staff_attendance sa
                        JOIN staff s ON sa.staff_id = s.id
                        WHERE sa.attendance_date = CURDATE()
                          AND TIME(sa.check_in_time) > '08:30:00'
                        ORDER BY minutes_late DESC
                    ");
                    $today_late = $todayLateStmt->fetchAll(PDO::FETCH_ASSOC);

                } catch (PDOException $de) {
                    echo json_encode(['success' => false, 'error' => 'Data error: ' . $de->getMessage()]);
                    exit();
                }

                // Build context for Claude
                $context = "You are an attendance analysis AI agent for SAVANT MOTORS, a vehicle repair workshop. ";
                $context .= "You help managers understand why employees are arriving late and identify patterns. ";
                $context .= "Be specific, actionable, and empathetic. Today's date: " . date('l, F j, Y') . ".\n\n";

                $context .= "=== ATTENDANCE DATA (LAST 30 DAYS) ===\n\n";
                $context .= "OVERALL SUMMARY:\n";
                $context .= "- Total check-ins: " . ($summary['total_checkins'] ?? 0) . "\n";
                $context .= "- Total late arrivals (after 08:30): " . ($summary['total_late'] ?? 0) . "\n";
                $latePct = $summary['total_checkins'] > 0 ? round(($summary['total_late'] / $summary['total_checkins']) * 100, 1) : 0;
                $context .= "- Late arrival rate: {$latePct}%\n";
                $context .= "- Average lateness: " . round($summary['avg_late_mins'] ?? 0) . " minutes\n\n";

                if (!empty($today_late)) {
                    $context .= "TODAY'S LATE ARRIVALS:\n";
                    foreach ($today_late as $r) {
                        $context .= "- {$r['full_name']} ({$r['department']}): arrived at {$r['check_in_time']}, {$r['minutes_late']} min late\n";
                    }
                    $context .= "\n";
                } else {
                    $context .= "TODAY'S LATE ARRIVALS: None so far today.\n\n";
                }

                if (!empty($frequent_late)) {
                    $context .= "MOST FREQUENTLY LATE EMPLOYEES (last 30 days):\n";
                    foreach ($frequent_late as $r) {
                        $context .= "- {$r['full_name']} ({$r['department']}, {$r['position']}): late {$r['late_count']} times, avg {$r['avg_minutes_late']} min late, worst {$r['max_minutes_late']} min. Most common late days: {$r['late_days']}\n";
                    }
                    $context .= "\n";
                }

                if (!empty($day_patterns)) {
                    $context .= "LATENESS BY DAY OF WEEK:\n";
                    foreach ($day_patterns as $r) {
                        $context .= "- {$r['day_name']}: {$r['late_count']} late arrivals, avg " . round($r['avg_late_mins']) . " min late\n";
                    }
                    $context .= "\n";
                }

                if (!empty($late_records)) {
                    $context .= "RECENT LATE ARRIVAL LOG (last 30 days, worst first):\n";
                    foreach (array_slice($late_records, 0, 20) as $r) {
                        $context .= "- {$r['full_name']} | {$r['day_of_week']} {$r['attendance_date']} | arrived {$r['check_in_time']} | {$r['minutes_late']} min late\n";
                    }
                    $context .= "\n";
                }

                $context .= "=== END OF DATA ===\n\n";
                $context .= "Manager's question: " . $user_question;

                // Pass previous conversation if provided
                $conversation_history = json_decode($_POST['history'] ?? '[]', true);
                $messages = [];
                foreach ($conversation_history as $h) {
                    $messages[] = ['role' => $h['role'], 'content' => $h['content']];
                }
                $messages[] = ['role' => 'user', 'content' => $context];

                // Return the context for the JS to call Claude directly
                echo json_encode([
                    'success' => true,
                    'context' => $context,
                    'messages' => $messages,
                    'data_summary' => [
                        'total_late' => $summary['total_late'] ?? 0,
                        'late_rate' => $latePct,
                        'avg_late_mins' => round($summary['avg_late_mins'] ?? 0),
                        'today_late_count' => count($today_late),
                        'worst_offenders' => array_slice($frequent_late, 0, 3),
                    ]
                ]);
                exit();
            }
        } catch (PDOException $e) {
            echo json_encode(['success' => false, 'error' => 'Database error: ' . $e->getMessage()]);
            exit();
        }
    }
    
    // Get all staff members (for dropdown and attendance)
    $staffStmt = $conn->query("
        SELECT id, full_name, staff_code, position, department, status, is_blocked, source_type
        FROM staff 
        WHERE (deleted_at IS NULL OR deleted_at = '0000-00-00 00:00:00')
        ORDER BY department, full_name
    ");
    $staff_members = $staffStmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Get all technicians (for dropdown only)
    $techStmt = $conn->query("
        SELECT id, full_name, technician_code as staff_code, specialization as position, 
               'Technician' as department, status, is_blocked
        FROM technicians 
        WHERE (deleted_at IS NULL OR deleted_at = '0000-00-00 00:00:00')
        ORDER BY full_name
    ");
    $technicians = $techStmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Merge both lists for dropdown display
    $all_personnel = [];
    foreach ($staff_members as $staff) {
        $all_personnel[] = [
            'type' => 'staff',
            'id' => $staff['id'],
            'full_name' => $staff['full_name'],
            'code' => $staff['staff_code'],
            'dept' => $staff['department'],
            'status' => $staff['status'],
            'is_blocked' => $staff['is_blocked']
        ];
    }
    foreach ($technicians as $tech) {
        $all_personnel[] = [
            'type' => 'technician',
            'id' => $tech['id'],
            'full_name' => $tech['full_name'],
            'code' => $tech['staff_code'],
            'dept' => $tech['department'],
            'status' => $tech['status'],
            'is_blocked' => $tech['is_blocked']
        ];
    }
    
    // Get today's attendance
    $attendanceStmt = $conn->prepare("
        SELECT 
            sa.*,
            s.full_name,
            s.staff_code,
            s.position,
            s.department
        FROM staff_attendance sa
        LEFT JOIN staff s ON sa.staff_id = s.id
        WHERE sa.attendance_date = CURDATE()
        ORDER BY sa.check_in_time DESC
    ");
    $attendanceStmt->execute();
    $today_attendance = $attendanceStmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Get attendance summary for current month
    $monthStart = date('Y-m-01');
    $monthEnd = date('Y-m-t');
    
    $summaryStmt = $conn->prepare("
        SELECT 
            COUNT(DISTINCT staff_id) as total_staff_present,
            COUNT(*) as total_checkins,
            AVG(TIMESTAMPDIFF(HOUR, check_in_time, COALESCE(check_out_time, NOW()))) as avg_hours
        FROM staff_attendance
        WHERE attendance_date BETWEEN :start AND :end
    ");
    $summaryStmt->execute([':start' => $monthStart, ':end' => $monthEnd]);
    $month_summary = $summaryStmt->fetch(PDO::FETCH_ASSOC);
    
    // Get today's statistics
    $today_stats = [
        'checked_in' => count($today_attendance),
        'checked_out' => count(array_filter($today_attendance, function($a) { return !is_null($a['check_out_time']); })),
        'still_working' => count(array_filter($today_attendance, function($a) { return is_null($a['check_out_time']); }))
    ];
    
    // Get weekly attendance data for chart
    $weeklyStmt = $conn->prepare("
        SELECT 
            DAYNAME(attendance_date) as day_name,
            COUNT(*) as checkins,
            COUNT(CASE WHEN check_out_time IS NOT NULL THEN 1 END) as checkouts
        FROM staff_attendance
        WHERE attendance_date >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)
        GROUP BY attendance_date
        ORDER BY attendance_date
    ");
    $weeklyStmt->execute();
    $weekly_data = $weeklyStmt->fetchAll(PDO::FETCH_ASSOC);
    
} catch(PDOException $e) {
    $error_message = 'Database error: ' . $e->getMessage();
    $all_personnel = [];
    $staff_members = [];
    $technicians = [];
    $today_attendance = [];
    $month_summary = ['total_staff_present' => 0, 'total_checkins' => 0, 'avg_hours' => 0];
    $today_stats = ['checked_in' => 0, 'checked_out' => 0, 'still_working' => 0];
    $weekly_data = [];
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Attendance Management | Savant Motors</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        /* ... (keep your existing CSS) ... */
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Calibri', 'Segoe UI', 'Inter', sans-serif; background: radial-gradient(circle at 10% 30%, rgba(59,130,246,0.05), rgba(15,23,42,0.02)); min-height: 100vh; }
        :root { --primary: #1e40af; --primary-dark: #1e3a8a; --primary-light: #3b82f6; --success: #10b981; --danger: #ef4444; --warning: #f59e0b; --info: #3b82f6; --dark: #0f172a; --gray: #64748b; --light: #f8fafc; --border: #e2e8f0; --shadow-sm: 0 1px 2px 0 rgb(0 0 0 / 0.05); --shadow-md: 0 4px 6px -1px rgb(0 0 0 / 0.1); --shadow-lg: 0 10px 15px -3px rgb(0 0 0 / 0.1); --transition: all 0.2s ease; }
        .navbar { background: rgba(255,255,255,0.95); backdrop-filter: blur(12px); border-bottom: 1px solid var(--border); padding: 1rem 2rem; display: flex; justify-content: space-between; align-items: center; position: sticky; top: 0; z-index: 1000; }
        .logo-area { display: flex; align-items: center; gap: 20px; }
        .logo-icon { width: 40px; height: 40px; background: linear-gradient(135deg, var(--primary-light), var(--primary)); border-radius: 12px; display: flex; align-items: center; justify-content: center; color: white; font-size: 20px; }
        .logo-text { font-size: 20px; font-weight: 700; background: linear-gradient(135deg, var(--dark), var(--primary-dark)); -webkit-background-clip: text; background-clip: text; color: transparent; }
        .user-menu { display: flex; align-items: center; gap: 20px; }
        .user-info { text-align: right; }
        .user-name { font-weight: 600; color: var(--dark); }
        .user-role { font-size: 11px; color: var(--gray); }
        .logout-btn { background: rgba(239,68,68,0.1); color: var(--danger); padding: 8px 16px; border-radius: 40px; text-decoration: none; font-size: 13px; font-weight: 500; display: flex; align-items: center; gap: 8px; transition: var(--transition); }
        .logout-btn:hover { background: var(--danger); color: white; }
        .sidebar { position: fixed; left: 0; top: 70px; width: 260px; height: calc(100vh - 70px); background: rgba(255,255,255,0.9); backdrop-filter: blur(8px); border-right: 1px solid var(--border); overflow-y: auto; }
        .sidebar-menu { padding: 24px 12px; }
        .menu-item { padding: 12px 20px; display: flex; align-items: center; gap: 12px; color: var(--gray); text-decoration: none; transition: var(--transition); border-radius: 16px; font-size: 14px; font-weight: 500; margin-bottom: 6px; }
        .menu-item i { width: 20px; font-size: 1.1rem; }
        .menu-item:hover, .menu-item.active { background: rgba(59,130,246,0.1); color: var(--primary-light); }
        .main-content { margin-left: 260px; padding: 30px 40px; min-height: calc(100vh - 70px); }
        .page-header { margin-bottom: 30px; }
        .page-header h1 { font-size: 28px; font-weight: 800; background: linear-gradient(135deg, var(--dark), var(--primary-dark)); -webkit-background-clip: text; background-clip: text; color: transparent; display: flex; align-items: center; gap: 12px; }
        .date-badge { display: inline-flex; align-items: center; gap: 10px; padding: 8px 20px; background: white; border-radius: 40px; font-size: 14px; font-weight: 600; color: var(--dark); margin-top: 10px; border: 1px solid var(--border); }
        .stats-grid { display: grid; grid-template-columns: repeat(4,1fr); gap: 20px; margin-bottom: 30px; }
        .stat-card { background: white; border-radius: 28px; padding: 20px; display: flex; align-items: center; gap: 15px; transition: var(--transition); border: 1px solid var(--border); box-shadow: var(--shadow-sm); }
        .stat-card:hover { transform: translateY(-3px); box-shadow: var(--shadow-md); }
        .stat-icon { width: 54px; height: 54px; border-radius: 18px; display: flex; align-items: center; justify-content: center; font-size: 24px; }
        .stat-icon.blue { background: #dbeafe; color: var(--primary); }
        .stat-icon.green { background: #dcfce7; color: var(--success); }
        .stat-icon.orange { background: #fed7aa; color: var(--warning); }
        .stat-icon.purple { background: #e9d5ff; color: #9333ea; }
        .stat-info h3 { font-size: 12px; color: var(--gray); margin-bottom: 4px; text-transform: uppercase; }
        .stat-info .value { font-size: 28px; font-weight: 800; color: var(--dark); }
        .chart-container { background: white; border-radius: 28px; padding: 24px; margin-bottom: 30px; border: 1px solid var(--border); }
        .chart-container h3 { font-size: 16px; font-weight: 700; margin-bottom: 20px; color: var(--dark); }
        canvas { max-height: 300px; }
        .attendance-table { background: white; border-radius: 28px; border: 1px solid var(--border); overflow: hidden; }
        .table-header { padding: 20px 24px; background: var(--light); border-bottom: 1px solid var(--border); display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 15px; }
        .table-header h3 { font-size: 18px; font-weight: 700; color: var(--dark); }
        .filter-group { display: flex; gap: 10px; }
        .filter-group input, .filter-group select { padding: 8px 16px; border: 1px solid var(--border); border-radius: 40px; font-size: 13px; }
        .btn { padding: 8px 20px; border: none; border-radius: 40px; font-size: 13px; font-weight: 600; cursor: pointer; transition: var(--transition); display: inline-flex; align-items: center; gap: 8px; }
        .btn-primary { background: linear-gradient(135deg, var(--primary-light), var(--primary)); color: white; }
        .btn-primary:hover { transform: translateY(-2px); box-shadow: 0 4px 12px rgba(59,130,246,0.3); }
        .btn-success { background: var(--success); color: white; }
        .btn-success:hover { background: #059669; transform: translateY(-2px); }
        .btn-warning { background: var(--warning); color: white; }
        .btn-warning:hover { background: #d97706; }
        table { width: 100%; border-collapse: collapse; }
        th, td { padding: 14px 16px; text-align: left; border-bottom: 1px solid var(--border); }
        th { font-size: 12px; font-weight: 700; color: var(--gray); text-transform: uppercase; background: white; }
        td { font-size: 13px; }
        .status-badge { display: inline-block; padding: 4px 12px; border-radius: 40px; font-size: 11px; font-weight: 600; }
        .status-present { background: #dcfce7; color: #166534; }
        .status-absent { background: #fee2e2; color: #991b1b; }
        .status-late { background: #fed7aa; color: #9a3412; }
        .modal { display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); backdrop-filter: blur(4px); z-index: 2000; align-items: center; justify-content: center; }
        .modal.active { display: flex; }
        .modal-content { background: white; border-radius: 32px; width: 90%; max-width: 450px; overflow: hidden; }
        .modal-header { padding: 20px 24px; background: linear-gradient(135deg, var(--primary-dark), var(--primary)); color: white; display: flex; justify-content: space-between; align-items: center; }
        .modal-header h3 { font-size: 18px; font-weight: 600; }
        .close-modal { background: none; border: none; color: white; font-size: 24px; cursor: pointer; }
        .modal-body { padding: 24px; }
        .modal-footer { padding: 16px 24px; border-top: 1px solid var(--border); display: flex; justify-content: flex-end; gap: 12px; }
        .form-group { margin-bottom: 20px; }
        .form-group label { display: block; font-size: 12px; font-weight: 600; color: var(--gray); margin-bottom: 8px; }
        .form-group select, .form-group textarea { width: 100%; padding: 12px; border: 2px solid var(--border); border-radius: 16px; font-size: 14px; }
        .alert { padding: 12px 16px; border-radius: 16px; margin-bottom: 20px; display: flex; align-items: center; gap: 10px; }
        .alert-success { background: #dcfce7; color: #166534; }
        .alert-error { background: #fee2e2; color: #991b1b; }
        .alert-info { background: #dbeafe; color: #1e40af; }

        /* ── AI Agent Panel ────────────────────────────────────────────── */
        .ai-agent-panel {
            background: white;
            border-radius: 28px;
            border: 1px solid var(--border);
            margin-bottom: 30px;
            overflow: hidden;
            box-shadow: var(--shadow-sm);
        }
        .ai-agent-header {
            background: linear-gradient(135deg, #0f172a, #1e3a8a);
            padding: 18px 24px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            flex-wrap: wrap;
        }
        .ai-agent-header-left {
            display: flex; align-items: center; gap: 14px;
        }
        .ai-icon-wrap {
            width: 44px; height: 44px;
            background: linear-gradient(135deg, #6366f1, #8b5cf6);
            border-radius: 14px;
            display: flex; align-items: center; justify-content: center;
            font-size: 20px; color: white;
            box-shadow: 0 4px 12px rgba(99,102,241,.4);
            flex-shrink: 0;
        }
        .ai-agent-header h3 {
            color: white; font-size: 16px; font-weight: 700; line-height: 1.2;
        }
        .ai-agent-header p {
            color: rgba(255,255,255,.65); font-size: 12px; margin-top: 2px;
        }
        .ai-toggle-btn {
            background: rgba(255,255,255,.12);
            border: 1px solid rgba(255,255,255,.2);
            color: white; padding: 7px 16px; border-radius: 20px;
            font-size: 12px; font-weight: 600; cursor: pointer;
            transition: all .2s;
            display: flex; align-items: center; gap: 6px;
            white-space: nowrap;
        }
        .ai-toggle-btn:hover { background: rgba(255,255,255,.22); }

        .ai-agent-body { display: none; }
        .ai-agent-body.open { display: block; }

        /* Quick stats inside agent */
        .ai-quick-stats {
            display: grid; grid-template-columns: repeat(auto-fit, minmax(150px,1fr));
            gap: 12px; padding: 18px 24px;
            background: #f8fafc; border-bottom: 1px solid var(--border);
        }
        .ai-qs-card {
            background: white; border-radius: 14px; padding: 14px 16px;
            border: 1px solid var(--border); text-align: center;
        }
        .ai-qs-card .qv { font-size: 1.5rem; font-weight: 800; color: var(--dark); }
        .ai-qs-card .ql { font-size: 10px; font-weight: 600; text-transform: uppercase; letter-spacing: .5px; color: var(--gray); margin-top: 3px; }
        .ai-qs-card.warn .qv { color: var(--warning); }
        .ai-qs-card.danger .qv { color: var(--danger); }

        /* Chat area */
        .ai-chat-wrap { padding: 0 24px 24px; }
        .ai-messages {
            min-height: 80px; max-height: 420px; overflow-y: auto;
            padding: 16px 0; display: flex; flex-direction: column; gap: 14px;
        }
        .ai-msg {
            display: flex; gap: 10px; align-items: flex-start;
        }
        .ai-msg.user { flex-direction: row-reverse; }
        .ai-avatar {
            width: 32px; height: 32px; border-radius: 50%; flex-shrink: 0;
            display: flex; align-items: center; justify-content: center;
            font-size: 13px; font-weight: 700;
        }
        .ai-avatar.agent { background: linear-gradient(135deg,#6366f1,#8b5cf6); color: white; }
        .ai-avatar.user  { background: linear-gradient(135deg,#0ea5e9,#0284c7); color: white; }
        .ai-bubble {
            max-width: 80%; padding: 12px 16px; border-radius: 18px;
            font-size: 13.5px; line-height: 1.6; white-space: pre-wrap; word-break: break-word;
        }
        .ai-bubble.agent {
            background: #f8fafc; border: 1px solid var(--border); color: var(--dark);
            border-bottom-left-radius: 4px;
        }
        .ai-bubble.user {
            background: linear-gradient(135deg,#1e40af,#1d4ed8); color: white;
            border-bottom-right-radius: 4px;
        }
        .ai-bubble strong { font-weight: 700; }
        .ai-bubble.typing { color: var(--gray); font-style: italic; }

        /* Input row */
        .ai-input-row {
            display: flex; gap: 10px; margin-top: 8px; align-items: flex-end;
        }
        .ai-input-wrap { flex: 1; position: relative; }
        .ai-input-wrap textarea {
            width: 100%; padding: 12px 16px; border: 2px solid var(--border);
            border-radius: 16px; font-size: 13px; font-family: inherit;
            resize: none; min-height: 50px; max-height: 120px; line-height: 1.5;
            transition: border-color .15s;
        }
        .ai-input-wrap textarea:focus { outline: none; border-color: #6366f1; }
        .ai-send-btn {
            width: 46px; height: 46px; border-radius: 50%;
            background: linear-gradient(135deg,#6366f1,#8b5cf6);
            border: none; color: white; cursor: pointer;
            display: flex; align-items: center; justify-content: center;
            font-size: 16px; transition: all .2s; flex-shrink: 0;
        }
        .ai-send-btn:hover { transform: scale(1.08); box-shadow: 0 4px 12px rgba(99,102,241,.4); }
        .ai-send-btn:disabled { opacity: .5; cursor: not-allowed; transform: none; }

        /* Quick prompts */
        .ai-quick-prompts {
            display: flex; flex-wrap: wrap; gap: 6px; padding: 14px 0 0;
        }
        .ai-qp {
            padding: 5px 12px; background: #eff6ff; border: 1px solid #bfdbfe;
            border-radius: 20px; font-size: 11px; font-weight: 600; color: #1e40af;
            cursor: pointer; transition: all .15s;
        }
        .ai-qp:hover { background: #1e40af; color: white; border-color: #1e40af; }

        /* Divider between sections */
        .ai-divider {
            height: 1px; background: var(--border); margin: 0 24px;
        }

        @media (max-width: 768px) {
            .sidebar { left: -260px; }
            .main-content { margin-left: 0; padding: 20px; }
            .stats-grid { grid-template-columns: repeat(2,1fr); }
            .table-header { flex-direction: column; align-items: stretch; }
            .ai-quick-stats { grid-template-columns: 1fr 1fr; }
        }
    </style>
</head>
<body>
    <div class="navbar">
        <div class="logo-area">
            <div class="logo-icon"><i class="fas fa-clock"></i></div>
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
            <a href="technicians.php" class="menu-item"><i class="fas fa-users-cog"></i> Staff & Technicians</a>
            <a href="attendance.php" class="menu-item active"><i class="fas fa-clock"></i> Attendance</a>
            <a href="tools/index.php" class="menu-item"><i class="fas fa-tools"></i> Tool Management</a>
            <a href="tool_requests/index.php" class="menu-item"><i class="fas fa-clipboard-list"></i> Tool Requests</a>
            <a href="customers/index.php" class="menu-item"><i class="fas fa-users"></i> Customers</a>
        </div>
    </div>

    <div class="main-content">
        <div class="page-header">
            <h1><i class="fas fa-clock"></i> Attendance Management</h1>
            <div class="date-badge">
                <i class="fas fa-calendar-alt"></i>
                <?php echo date('l, F j, Y'); ?>
            </div>
        </div>

        <?php if ($success_message): ?>
        <div class="alert alert-success">
            <i class="fas fa-check-circle"></i> <?php echo htmlspecialchars($success_message); ?>
        </div>
        <?php endif; ?>

        <?php if ($error_message): ?>
        <div class="alert alert-error">
            <i class="fas fa-exclamation-triangle"></i> <?php echo htmlspecialchars($error_message); ?>
        </div>
        <?php endif; ?>

        <!-- ════════════════════════════════════════════════════════
             AI ATTENDANCE AGENT
        ════════════════════════════════════════════════════════ -->
        <div class="ai-agent-panel">
            <div class="ai-agent-header">
                <div class="ai-agent-header-left">
                    <div class="ai-icon-wrap"><i class="fas fa-robot"></i></div>
                    <div>
                        <h3>Attendance Intelligence Agent</h3>
                        <p>AI-powered analysis · Identifies lateness patterns · Recommends actions</p>
                    </div>
                </div>
                <button class="ai-toggle-btn" id="aiToggleBtn" onclick="toggleAgent()">
                    <i class="fas fa-chevron-down" id="aiToggleIcon"></i> Open Agent
                </button>
            </div>

            <div class="ai-agent-body" id="aiAgentBody">

                <!-- Quick stats from DB -->
                <div class="ai-quick-stats" id="aiQuickStats">
                    <div class="ai-qs-card warn">
                        <div class="qv" id="qsLateToday">—</div>
                        <div class="ql">Late Today</div>
                    </div>
                    <div class="ai-qs-card danger">
                        <div class="qv" id="qsLateRate">—</div>
                        <div class="ql">Late Rate (30d)</div>
                    </div>
                    <div class="ai-qs-card">
                        <div class="qv" id="qsAvgLate">—</div>
                        <div class="ql">Avg Late (mins)</div>
                    </div>
                    <div class="ai-qs-card danger">
                        <div class="qv" id="qsTopOffender">—</div>
                        <div class="ql">Most Late (30d)</div>
                    </div>
                </div>

                <div class="ai-divider"></div>

                <!-- Chat -->
                <div class="ai-chat-wrap">
                    <div class="ai-messages" id="aiMessages">
                        <div class="ai-msg">
                            <div class="ai-avatar agent"><i class="fas fa-robot"></i></div>
                            <div class="ai-bubble agent">👋 Hello! I'm your Attendance Intelligence Agent for <strong>Savant Motors</strong>.

I have access to your last <strong>30 days of attendance records</strong> — check-in times, late arrivals, department patterns, and day-of-week trends.

Ask me anything about lateness, and I'll give you a specific analysis with actionable recommendations. Try one of the quick prompts below, or type your own question.</div>
                        </div>
                    </div>

                    <!-- Quick prompts -->
                    <div class="ai-quick-prompts">
                        <span class="ai-qp" onclick="sendPrompt('Who are the most frequently late employees this month and by how much?')">🕐 Who's most often late?</span>
                        <span class="ai-qp" onclick="sendPrompt('Which day of the week has the worst lateness pattern?')">📅 Worst day pattern?</span>
                        <span class="ai-qp" onclick="sendPrompt('Give me possible reasons why these specific employees might be arriving late, based on their patterns.')">🔍 Why are they late?</span>
                        <span class="ai-qp" onclick="sendPrompt('What departments have the most lateness issues?')">🏢 Department breakdown?</span>
                        <span class="ai-qp" onclick="sendPrompt('What disciplinary or support actions would you recommend for our late employees?')">⚡ Recommend actions</span>
                        <span class="ai-qp" onclick="sendPrompt('Show me today late arrivals and give me a brief analysis.')">📊 Today\'s late report</span>
                        <span class="ai-qp" onclick="sendPrompt('Is there a pattern suggesting a transport, shift time, or motivation issue?')">🚌 Transport issues?</span>
                    </div>

                    <!-- Input -->
                    <div class="ai-input-row" style="margin-top:14px;">
                        <div class="ai-input-wrap">
                            <textarea id="aiInput" placeholder="Ask about attendance patterns, late employees, root causes…" rows="2" onkeydown="handleKey(event)"></textarea>
                        </div>
                        <button class="ai-send-btn" id="aiSendBtn" onclick="sendAgentMessage()" title="Send">
                            <i class="fas fa-paper-plane"></i>
                        </button>
                    </div>
                    <div style="font-size:11px;color:var(--gray);margin-top:6px;">Press <kbd style="background:#f1f5f9;border:1px solid #e2e8f0;padding:1px 5px;border-radius:4px;">Enter</kbd> to send · <kbd style="background:#f1f5f9;border:1px solid #e2e8f0;padding:1px 5px;border-radius:4px;">Shift+Enter</kbd> for new line</div>
                </div>
            </div>
        </div>

        <!-- Statistics Cards -->
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-icon blue"><i class="fas fa-user-check"></i></div>
                <div class="stat-info">
                    <h3>Checked In Today</h3>
                    <div class="value"><?php echo $today_stats['checked_in']; ?></div>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-icon green"><i class="fas fa-sign-out-alt"></i></div>
                <div class="stat-info">
                    <h3>Checked Out</h3>
                    <div class="value"><?php echo $today_stats['checked_out']; ?></div>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-icon orange"><i class="fas fa-briefcase"></i></div>
                <div class="stat-info">
                    <h3>Still Working</h3>
                    <div class="value"><?php echo $today_stats['still_working']; ?></div>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-icon purple"><i class="fas fa-calendar-month"></i></div>
                <div class="stat-info">
                    <h3>Monthly Check-ins</h3>
                    <div class="value"><?php echo $month_summary['total_checkins']; ?></div>
                </div>
            </div>
        </div>

        <!-- Weekly Chart -->
        <div class="chart-container">
            <h3><i class="fas fa-chart-line"></i> Weekly Attendance Overview</h3>
            <canvas id="attendanceChart"></canvas>
        </div>

        <!-- Today's Attendance Table -->
        <div class="attendance-table">
            <div class="table-header">
                <h3><i class="fas fa-user-clock"></i> Today's Attendance</h3>
                <div class="filter-group">
                    <input type="text" id="searchInput" placeholder="Search staff..." style="width: 200px;">
                    <button class="btn btn-primary" onclick="openCheckInModal()">
                        <i class="fas fa-sign-in-alt"></i> Manual Check-in
                    </button>
                    <button class="btn btn-primary" onclick="window.location.href='attendance_report.php'">
                        <i class="fas fa-file-alt"></i> View Report
                    </button>
                </div>
            </div>
            <div style="overflow-x: auto;">
                <table id="attendanceTable">
                    <thead>
                        <tr>
                            <th>Staff Code</th>
                            <th>Name</th>
                            <th>Department</th>
                            <th>Check In</th>
                            <th>Check Out</th>
                            <th>Duration</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($today_attendance)): ?>
                        <tr>
                            <td colspan="8" style="text-align: center; padding: 40px;">
                                <i class="fas fa-info-circle"></i> No check-ins recorded for today
                            </td>
                        </tr>
                        <?php else: ?>
                            <?php foreach ($today_attendance as $record): 
                                $duration = '';
                                if ($record['check_in_time'] && $record['check_out_time']) {
                                    $check_in = new DateTime($record['check_in_time']);
                                    $check_out = new DateTime($record['check_out_time']);
                                    $diff = $check_in->diff($check_out);
                                    $duration = $diff->format('%H:%I:%S');
                                } elseif ($record['check_in_time']) {
                                    $duration = 'Still working';
                                }
                            ?>
                            <tr data-name="<?php echo strtolower($record['full_name']); ?>">
                                <td><?php echo htmlspecialchars($record['staff_code']); ?></td>
                                <td><?php echo htmlspecialchars($record['full_name']); ?></td>
                                <td><?php echo htmlspecialchars($record['department']); ?></td>
                                <td><?php echo date('h:i A', strtotime($record['check_in_time'])); ?></td>
                                <td><?php echo $record['check_out_time'] ? date('h:i A', strtotime($record['check_out_time'])) : '--'; ?></td>
                                <td><?php echo $duration; ?></td>
                                <td>
                                    <span class="status-badge status-present">Present</span>
                                </td>
                                <td>
                                    <?php if (!$record['check_out_time']): ?>
                                    <button class="btn btn-warning" onclick="openCheckOutModal(<?php echo $record['id']; ?>, '<?php echo htmlspecialchars($record['full_name']); ?>')" style="padding: 4px 12px; font-size: 11px;">
                                        <i class="fas fa-sign-out-alt"></i> Check Out
                                    </button>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- Check-in Modal -->
    <div id="checkInModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3><i class="fas fa-sign-in-alt"></i> Manual Check-in</h3>
                <button class="close-modal" onclick="closeModal('checkInModal')">&times;</button>
            </div>
            <div class="modal-body">
                <?php if (empty($all_personnel)): ?>
                    <div class="alert alert-info">
                        <i class="fas fa-info-circle"></i> No staff or technicians found. 
                        <a href="technicians.php">Add staff or technicians</a> first.
                    </div>
                <?php else: ?>
                    <div class="form-group">
                        <label>Select Person</label>
                        <select id="checkin_person">
                            <option value="">Select staff or technician</option>
                            <?php foreach ($all_personnel as $person): 
                                if ($person['status'] == 'active' && !$person['is_blocked']):
                            ?>
                                <option value="<?php echo $person['type'] . '_' . $person['id']; ?>">
                                    <?php echo htmlspecialchars($person['full_name']); ?> 
                                    (<?php echo htmlspecialchars($person['code']); ?>) - 
                                    <?php echo htmlspecialchars($person['dept']); ?>
                                    <?php if ($person['type'] == 'technician'): ?> [Tech]<?php endif; ?>
                                </option>
                            <?php endif; endforeach; ?>
                        </select>
                    </div>
                <?php endif; ?>
            </div>
            <div class="modal-footer">
                <button class="btn" onclick="closeModal('checkInModal')">Cancel</button>
                <?php if (!empty($all_personnel)): ?>
                    <button class="btn btn-primary" onclick="submitCheckIn()">Check In</button>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Check-out Modal -->
    <div id="checkOutModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3><i class="fas fa-sign-out-alt"></i> Check Out - <span id="checkout_staff_name"></span></h3>
                <button class="close-modal" onclick="closeModal('checkOutModal')">&times;</button>
            </div>
            <div class="modal-body">
                <!-- No notes field anymore -->
            </div>
            <div class="modal-footer">
                <button class="btn" onclick="closeModal('checkOutModal')">Cancel</button>
                <button class="btn btn-warning" onclick="submitCheckOut()">Check Out</button>
            </div>
        </div>
    </div>

    <script>
        let currentAttendanceId = null;
        
        // Search functionality
        document.getElementById('searchInput')?.addEventListener('keyup', function() {
            const searchTerm = this.value.toLowerCase();
            const rows = document.querySelectorAll('#attendanceTable tbody tr');
            rows.forEach(row => {
                const name = row.dataset.name || '';
                if (name.includes(searchTerm) || searchTerm === '') {
                    row.style.display = '';
                } else {
                    row.style.display = 'none';
                }
            });
        });
        
        function openCheckInModal() {
            const modal = document.getElementById('checkInModal');
            modal.classList.add('active');
            const personSelect = document.getElementById('checkin_person');
            if (personSelect) personSelect.value = '';
        }
        
        function openCheckOutModal(id, name) {
            currentAttendanceId = id;
            document.getElementById('checkout_staff_name').textContent = name;
            document.getElementById('checkOutModal').classList.add('active');
        }
        
        function closeModal(modalId) {
            document.getElementById(modalId).classList.remove('active');
        }
        
        function submitCheckIn() {
            const value = document.getElementById('checkin_person').value;
            if (!value) {
                alert('Please select a staff member or technician');
                return;
            }
            const [type, id] = value.split('_');
            
            fetch('attendance.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: new URLSearchParams({
                    'ajax_action': 'check_in',
                    'type': type,
                    'id': id
                })
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    alert('Check-in successful!');
                    location.reload();
                } else {
                    alert('Error: ' + data.error);
                }
            })
            .catch(error => {
                console.error('Error:', error);
                alert('An error occurred');
            });
        }
        
        function submitCheckOut() {
            fetch('attendance.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: new URLSearchParams({
                    'ajax_action': 'check_out',
                    'attendance_id': currentAttendanceId
                })
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    alert('Check-out successful!');
                    location.reload();
                } else {
                    alert('Error: ' + data.error);
                }
            })
            .catch(error => {
                console.error('Error:', error);
                alert('An error occurred');
            });
        }
        
        // ════════════════════════════════════════════════════════
        // AI ATTENDANCE AGENT
        // ════════════════════════════════════════════════════════
        let aiConversationHistory = [];
        let aiIsOpen = false;
        let aiDataLoaded = false;
        let aiDataSummary = null;

        function toggleAgent() {
            aiIsOpen = !aiIsOpen;
            const body = document.getElementById('aiAgentBody');
            const btn  = document.getElementById('aiToggleBtn');
            const icon = document.getElementById('aiToggleIcon');
            body.classList.toggle('open', aiIsOpen);
            btn.innerHTML  = aiIsOpen
                ? '<i class="fas fa-chevron-up"></i> Close Agent'
                : '<i class="fas fa-chevron-down"></i> Open Agent';
            if (aiIsOpen && !aiDataLoaded) {
                loadAiQuickStats();
            }
        }

        async function loadAiQuickStats() {
            // Fire a dummy first question to get the data summary
            try {
                const fd = new FormData();
                fd.append('ajax_action', 'ai_agent');
                fd.append('question', 'Give me a quick overview of lateness today.');
                fd.append('history', '[]');
                const resp = await fetch('attendance.php', { method: 'POST', body: fd });
                const data = await resp.json();
                if (data.success && data.data_summary) {
                    aiDataSummary = data.data_summary;
                    const ds = data.data_summary;
                    document.getElementById('qsLateToday').textContent   = ds.today_late_count ?? '—';
                    document.getElementById('qsLateRate').textContent     = (ds.late_rate ?? 0) + '%';
                    document.getElementById('qsAvgLate').textContent      = (ds.avg_late_mins ?? 0) + ' min';
                    if (ds.worst_offenders && ds.worst_offenders.length > 0) {
                        const top = ds.worst_offenders[0];
                        document.getElementById('qsTopOffender').textContent = top.late_count + '× ' + top.full_name.split(' ')[0];
                    } else {
                        document.getElementById('qsTopOffender').textContent = 'None';
                    }
                    aiDataLoaded = true;
                }
            } catch(e) { /* silently fail stats load */ }
        }

        function appendMessage(role, content, isTyping = false) {
            const box = document.getElementById('aiMessages');
            const wrap = document.createElement('div');
            wrap.className = 'ai-msg' + (role === 'user' ? ' user' : '');
            const av = document.createElement('div');
            av.className = 'ai-avatar ' + (role === 'user' ? 'user' : 'agent');
            av.innerHTML = role === 'user'
                ? '<i class="fas fa-user"></i>'
                : '<i class="fas fa-robot"></i>';
            const bubble = document.createElement('div');
            bubble.className = 'ai-bubble ' + (role === 'user' ? 'user' : 'agent') + (isTyping ? ' typing' : '');
            bubble.id = isTyping ? 'typingBubble' : '';
            bubble.textContent = isTyping ? '⏳ Thinking…' : content;
            wrap.appendChild(av);
            wrap.appendChild(bubble);
            box.appendChild(wrap);
            box.scrollTop = box.scrollHeight;
            return bubble;
        }

        function removeTypingIndicator() {
            const t = document.getElementById('typingBubble');
            if (t) t.parentElement.remove();
        }

        async function sendAgentMessage() {
            const inp = document.getElementById('aiInput');
            const question = inp.value.trim();
            if (!question) return;
            inp.value = '';
            inp.style.height = '';

            appendMessage('user', question);
            aiConversationHistory.push({ role: 'user', content: question });

            const sendBtn = document.getElementById('aiSendBtn');
            sendBtn.disabled = true;
            appendMessage('agent', '', true);

            try {
                // Step 1: Get context from PHP
                const fd = new FormData();
                fd.append('ajax_action', 'ai_agent');
                fd.append('question', question);
                fd.append('history', JSON.stringify(aiConversationHistory.slice(-6)));
                const ctxResp = await fetch('attendance.php', { method: 'POST', body: fd });
                const ctxData = await ctxResp.json();

                if (!ctxData.success) throw new Error(ctxData.error || 'Failed to get context');

                // Update quick stats if first load
                if (!aiDataLoaded && ctxData.data_summary) {
                    aiDataSummary = ctxData.data_summary;
                    const ds = ctxData.data_summary;
                    document.getElementById('qsLateToday').textContent   = ds.today_late_count ?? '—';
                    document.getElementById('qsLateRate').textContent     = (ds.late_rate ?? 0) + '%';
                    document.getElementById('qsAvgLate').textContent      = (ds.avg_late_mins ?? 0) + ' min';
                    if (ds.worst_offenders && ds.worst_offenders.length > 0) {
                        const top = ds.worst_offenders[0];
                        document.getElementById('qsTopOffender').textContent = top.late_count + '× ' + top.full_name.split(' ')[0];
                    }
                    aiDataLoaded = true;
                }

                // Step 2: Call Claude API directly from browser
                const claudeResp = await fetch('https://api.anthropic.com/v1/messages', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        model: 'claude-sonnet-4-20250514',
                        max_tokens: 1000,
                        system: "You are an Attendance Intelligence Agent for SAVANT MOTORS vehicle repair workshop. You analyse attendance data and help managers understand WHY employees are late. Be specific, empathetic and actionable. Use bullet points and clear structure. When you identify patterns, suggest root causes (transport, motivation, shift timing, personal issues, etc.) and concrete HR recommendations. Keep responses concise — max 300 words.",
                        messages: ctxData.messages
                    })
                });

                const claudeData = await claudeResp.json();
                removeTypingIndicator();

                if (!claudeResp.ok) {
                    const errMsg = claudeData.error?.message || 'Claude API error';
                    appendMessage('agent', '❌ API Error: ' + errMsg + '\n\nMake sure you are accessing this page through the Claude.ai interface which provides the API key automatically.');
                    return;
                }

                const reply = claudeData.content?.[0]?.text || 'No response received.';
                appendMessage('agent', reply);
                aiConversationHistory.push({ role: 'assistant', content: reply });

            } catch(e) {
                removeTypingIndicator();
                appendMessage('agent', '❌ Error: ' + e.message + '\n\nThis AI agent requires the Anthropic API. Make sure you have a valid connection.');
            } finally {
                sendBtn.disabled = false;
                inp.focus();
            }
        }

        function sendPrompt(text) {
            document.getElementById('aiInput').value = text;
            sendAgentMessage();
        }

        function handleKey(e) {
            if (e.key === 'Enter' && !e.shiftKey) {
                e.preventDefault();
                sendAgentMessage();
            }
        }

        // Auto-expand textarea
        document.getElementById('aiInput')?.addEventListener('input', function() {
            this.style.height = 'auto';
            this.style.height = Math.min(this.scrollHeight, 120) + 'px';
        });

        // Close modal when clicking outside
        window.onclick = function(event) {
            if (event.target.classList.contains('modal')) {
                event.target.classList.remove('active');
            }
        }
        
        // Weekly Attendance Chart
        const weeklyData = <?php echo json_encode($weekly_data); ?>;
        const ctx = document.getElementById('attendanceChart')?.getContext('2d');
        
        if (ctx && weeklyData.length > 0) {
            new Chart(ctx, {
                type: 'line',
                data: {
                    labels: weeklyData.map(item => item.day_name),
                    datasets: [
                        {
                            label: 'Check-ins',
                            data: weeklyData.map(item => item.checkins),
                            borderColor: '#3b82f6',
                            backgroundColor: 'rgba(59, 130, 246, 0.1)',
                            tension: 0.4,
                            fill: true
                        },
                        {
                            label: 'Check-outs',
                            data: weeklyData.map(item => item.checkouts),
                            borderColor: '#10b981',
                            backgroundColor: 'rgba(16, 185, 129, 0.1)',
                            tension: 0.4,
                            fill: true
                        }
                    ]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: true,
                    plugins: {
                        legend: {
                            position: 'top',
                        }
                    },
                    scales: {
                        y: {
                            beginAtZero: true,
                            ticks: {
                                stepSize: 1
                            }
                        }
                    }
                }
            });
        }
    </script>
</body>
</html>