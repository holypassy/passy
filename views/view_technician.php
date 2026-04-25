<?php
// view_technician.php – View a single technician
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

try {
    $conn = new PDO("mysql:host=localhost;dbname=savant_motors_pos", "root", "");
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // Fetch technician details
    $stmt = $conn->prepare("
        SELECT t.*,
               (SELECT COUNT(*) FROM tool_assignments WHERE technician_id = t.id AND actual_return_date IS NULL) as current_tools,
               (SELECT COUNT(*) FROM tool_assignments WHERE technician_id = t.id) as total_tools,
               (SELECT COUNT(*) FROM tool_assignments WHERE technician_id = t.id AND is_overdue = TRUE) as overdue_tools
        FROM technicians t
        WHERE t.id = ?
    ");
    $stmt->execute([$technician_id]);
    $technician = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$technician) {
        header('Location: technicians.php');
        exit();
    }

    // Fetch currently assigned tools (detailed)
    $stmt = $conn->prepare("
        SELECT ta.*, t.tool_name, t.tool_code, ta.assigned_date,
               DATEDIFF(NOW(), ta.assigned_date) as days_assigned,
               CASE WHEN ta.expected_return_date < CURDATE() THEN 1 ELSE 0 END as is_overdue
        FROM tool_assignments ta
        JOIN tools t ON ta.tool_id = t.id
        WHERE ta.technician_id = ? AND ta.actual_return_date IS NULL
        ORDER BY ta.assigned_date DESC
    ");
    $stmt->execute([$technician_id]);
    $assigned_tools = $stmt->fetchAll(PDO::FETCH_ASSOC);

} catch(PDOException $e) {
    die("Database error: " . $e->getMessage());
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>View Technician | Savant Motors</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
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
        .card { background: white; border-radius: 24px; border: 1px solid var(--border); padding: 30px; margin-bottom: 30px; }
        .detail-row { display: flex; padding: 12px 0; border-bottom: 1px solid var(--border); }
        .detail-label { width: 150px; font-weight: 600; color: var(--gray); }
        .detail-value { flex: 1; color: var(--dark); }
        .status-badge { display: inline-block; padding: 4px 12px; border-radius: 20px; font-size: 12px; font-weight: 600; }
        .status-active { background: #dcfce7; color: #166534; }
        .status-blocked { background: #fee2e2; color: #991b1b; }
        .status-on_leave { background: #fed7aa; color: #9a3412; }
        .stats-row { display: flex; gap: 20px; margin: 20px 0; padding: 15px; background: var(--light); border-radius: 16px; }
        .stat-item { flex: 1; text-align: center; }
        .stat-number { font-size: 28px; font-weight: 800; color: var(--primary-light); }
        .stat-label { font-size: 12px; color: var(--gray); }
        .tools-table { width: 100%; border-collapse: collapse; margin-top: 20px; }
        .tools-table th { background: #f1f5f9; padding: 12px; text-align: left; font-size: 12px; font-weight: 700; color: var(--gray); border-bottom: 2px solid var(--border); }
        .tools-table td { padding: 10px 12px; border-bottom: 1px solid var(--border); font-size: 13px; }
        .overdue { color: var(--danger); font-weight: 600; }
        @media (max-width: 768px) { .sidebar { left: -260px; } .main-content { margin-left: 0; padding: 20px; } .detail-row { flex-direction: column; gap: 5px; } .detail-label { width: 100%; } }
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
                <h1><i class="fas fa-user"></i> Technician Details</h1>
            </div>
            <a href="technicians.php" class="btn btn-secondary"><i class="fas fa-arrow-left"></i> Back to List</a>
        </div>

        <div class="card">
            <?php 
                $statusClass = $technician['is_blocked'] ? 'blocked' : ($technician['status'] == 'on_leave' ? 'on_leave' : 'active');
                $statusText = $technician['is_blocked'] ? 'Blocked' : ($technician['status'] == 'on_leave' ? 'On Leave' : 'Active');
            ?>
            <div class="detail-row">
                <div class="detail-label">Technician Code:</div>
                <div class="detail-value"><?php echo htmlspecialchars($technician['technician_code']); ?></div>
            </div>
            <div class="detail-row">
                <div class="detail-label">Full Name:</div>
                <div class="detail-value"><?php echo htmlspecialchars($technician['full_name']); ?></div>
            </div>
            <div class="detail-row">
                <div class="detail-label">Phone:</div>
                <div class="detail-value"><?php echo htmlspecialchars($technician['phone']); ?></div>
            </div>
            <div class="detail-row">
                <div class="detail-label">Email:</div>
                <div class="detail-value"><?php echo htmlspecialchars($technician['email'] ?: 'N/A'); ?></div>
            </div>
            <div class="detail-row">
                <div class="detail-label">Experience (Years):</div>
                <div class="detail-value"><?php echo $technician['experience_years']; ?></div>
            </div>
            <div class="detail-row">
                <div class="detail-label">Hire Date:</div>
                <div class="detail-value"><?php echo $technician['hire_date'] ? date('d M Y', strtotime($technician['hire_date'])) : 'N/A'; ?></div>
            </div>
            <div class="detail-row">
                <div class="detail-label">Status:</div>
                <div class="detail-value"><span class="status-badge status-<?php echo $statusClass; ?>"><?php echo $statusText; ?></span></div>
            </div>

            <div class="stats-row">
                <div class="stat-item"><div class="stat-number"><?php echo $technician['current_tools']; ?></div><div class="stat-label">Currently Assigned</div></div>
                <div class="stat-item"><div class="stat-number"><?php echo $technician['total_tools']; ?></div><div class="stat-label">Total Assigned</div></div>
                <div class="stat-item"><div class="stat-number <?php echo $technician['overdue_tools'] > 0 ? 'overdue' : ''; ?>"><?php echo $technician['overdue_tools']; ?></div><div class="stat-label">Overdue</div></div>
            </div>

            <?php if (!empty($assigned_tools)): ?>
                <h3 style="margin: 20px 0 10px;">Currently Assigned Tools</h3>
                <table class="tools-table">
                    <thead>
                        能
                            <th>Tool Code</th>
                            <th>Tool Name</th>
                            <th>Assigned Date</th>
                            <th>Quantity</th>
                            <th>Days</th>
                            <th>Status</th>
                        </thead>
                    <tbody>
                        <?php foreach ($assigned_tools as $tool): ?>
                        <tr>
                            <td><?php echo htmlspecialchars($tool['tool_code']); ?></td>
                            <td><?php echo htmlspecialchars($tool['tool_name']); ?></td>
                            <td><?php echo date('d/m/Y', strtotime($tool['assigned_date'])); ?></td>
                            <td><?php echo $tool['assigned_quantity']; ?></td>
                            <td><?php echo $tool['days_assigned']; ?></td>
                            <td class="<?php echo $tool['is_overdue'] ? 'overdue' : ''; ?>"><?php echo $tool['is_overdue'] ? 'Overdue' : 'Active'; ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php else: ?>
                <p style="margin-top: 20px; color: var(--gray);">No tools currently assigned.</p>
            <?php endif; ?>

            <div class="action-buttons" style="margin-top: 30px; display: flex; gap: 15px; justify-content: flex-end;">
                <a href="edit_technician.php?id=<?php echo $technician['id']; ?>" class="btn btn-primary"><i class="fas fa-edit"></i> Edit</a>
                <a href="technicians.php" class="btn btn-secondary">Close</a>
            </div>
        </div>
    </div>
</body>
</html>