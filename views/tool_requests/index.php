<?php
// views/tool_requests/index.php - Main Tool Requests View
session_start();

if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    header('Location: index.php');
    exit();
}

// Database connection
try {
    $conn = new PDO("mysql:host=localhost;dbname=savant_motors_pos", "root", "");
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    // Get current user ID
    $user_id = $_SESSION['user_id'] ?? null;
    if (!$user_id) {
        // Try to get user ID from username in session
        $username = $_SESSION['username'] ?? null;
        if ($username) {
            $stmt = $conn->prepare("SELECT id FROM users WHERE username = ? OR email = ? LIMIT 1");
            $stmt->execute([$username, $username]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($user) {
                $user_id = $user['id'];
                $_SESSION['user_id'] = $user_id;
            }
        }
    }
    
    // Get statistics for all requests
    $statsQuery = $conn->query("
        SELECT 
            COUNT(*) as total,
            SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending,
            SUM(CASE WHEN status = 'approved' THEN 1 ELSE 0 END) as approved,
            SUM(CASE WHEN status = 'fulfilled' THEN 1 ELSE 0 END) as fulfilled
        FROM tool_requests
    ");
    $stats = $statsQuery->fetch(PDO::FETCH_ASSOC);
    
    // Get pending requests (for admin/manager view)
    // Check if user is admin or manager (you may need to adjust this based on your role system)
    $stmt = $conn->prepare("
        SELECT 
            tr.*,
            t.full_name as technician_name,
            GROUP_CONCAT(
                CASE 
                    WHEN tri.is_new_tool = 1 THEN CONCAT(tri.tool_name, ' (New) x', tri.quantity)
                    ELSE CONCAT(tr2.tool_name, ' x', tri.quantity)
                END 
                SEPARATOR '\n'
            ) as tools_summary
        FROM tool_requests tr
        LEFT JOIN technicians t ON tr.technician_id = t.id
        LEFT JOIN tool_request_items tri ON tr.id = tri.request_id
        LEFT JOIN tools tr2 ON tri.tool_id = tr2.id
        WHERE tr.status = 'pending'
        GROUP BY tr.id
        ORDER BY 
            CASE tr.urgency
                WHEN 'emergency' THEN 1
                WHEN 'high' THEN 2
                WHEN 'medium' THEN 3
                WHEN 'low' THEN 4
            END,
            tr.created_at ASC
    ");
    $stmt->execute();
    $pendingRequests = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Get my requests (all requests, but you can filter by technician if needed)
    // For now, showing all requests sorted by most recent
    $stmt = $conn->prepare("
        SELECT 
            tr.*,
            t.full_name as technician_name,
            GROUP_CONCAT(
                CASE 
                    WHEN tri.is_new_tool = 1 THEN CONCAT(tri.tool_name, ' (New) x', tri.quantity)
                    ELSE CONCAT(tr2.tool_name, ' x', tri.quantity)
                END 
                SEPARATOR '\n'
            ) as tools_summary
        FROM tool_requests tr
        LEFT JOIN technicians t ON tr.technician_id = t.id
        LEFT JOIN tool_request_items tri ON tr.id = tri.request_id
        LEFT JOIN tools tr2 ON tri.tool_id = tr2.id
        GROUP BY tr.id
        ORDER BY tr.created_at DESC
        LIMIT 50
    ");
    $stmt->execute();
    $myRequests = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
} catch(PDOException $e) {
    $error = "Database error: " . $e->getMessage();
    $stats = ['total' => 0, 'pending' => 0, 'approved' => 0, 'fulfilled' => 0];
    $pendingRequests = [];
    $myRequests = [];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Tool Requests | SAVANT MOTORS</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:opsz,wght@14..32,300;14..32,400;14..32,500;14..32,600;14..32,700;14..32,800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: 'Inter', sans-serif;
            background: #f0f2f5;
            min-height: 100vh;
        }
        :root {
            --primary: #1e40af;
            --primary-light: #3b82f6;
            --success: #10b981;
            --danger: #ef4444;
            --warning: #f59e0b;
            --border: #e2e8f0;
            --gray: #64748b;
            --dark: #0f172a;
        }

        /* Sidebar */
        .sidebar {
            position: fixed;
            left: 0;
            top: 0;
            width: 260px;
            height: 100%;
            background: linear-gradient(180deg, #e0f2fe 0%, #bae6fd 100%);
            color: #0c4a6e;
            z-index: 1000;
            overflow-y: auto;
        }
        .sidebar-header { padding: 1.5rem; border-bottom: 1px solid rgba(0,0,0,0.08); }
        .sidebar-header h2 { font-size: 1.2rem; font-weight: 700; color: #0369a1; }
        .sidebar-header p { font-size: 0.7rem; opacity: 0.7; margin-top: 0.25rem; color: #0284c7; }
        .sidebar-menu { padding: 1rem 0; }
        .sidebar-title { padding: 0.5rem 1.5rem; font-size: 0.7rem; text-transform: uppercase; letter-spacing: 1px; color: #0369a1; font-weight: 600; }
        .menu-item {
            padding: 0.7rem 1.5rem;
            display: flex;
            align-items: center;
            gap: 0.75rem;
            color: #0c4a6e;
            text-decoration: none;
            transition: all 0.2s;
            border-left: 3px solid transparent;
            font-size: 0.85rem;
            font-weight: 500;
        }
        .menu-item i { width: 20px; }
        .menu-item:hover, .menu-item.active { background: rgba(14, 165, 233, 0.2); color: #0284c7; border-left-color: #0284c7; }

        /* Main Content */
        .main-content { margin-left: 260px; padding: 1.5rem; min-height: 100vh; }

        /* Top Bar */
        .top-bar {
            background: white;
            border-radius: 1rem;
            padding: 1rem 1.5rem;
            margin-bottom: 1.5rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 1rem;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
            border: 1px solid var(--border);
        }
        .page-title h1 { font-size: 1.3rem; font-weight: 700; color: var(--dark); display: flex; align-items: center; gap: 0.5rem; }
        .page-title p { font-size: 0.75rem; color: var(--gray); margin-top: 0.25rem; }

        /* Stats Cards */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 1rem;
            margin-bottom: 1.5rem;
        }
        .stat-card {
            background: white;
            border-radius: 1rem;
            padding: 1rem;
            border: 1px solid var(--border);
            text-align: center;
        }
        .stat-value { font-size: 1.8rem; font-weight: 700; color: var(--dark); }
        .stat-label { font-size: 0.7rem; color: var(--gray); text-transform: uppercase; margin-top: 0.25rem; }

        /* Buttons */
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
        }
        .btn-primary { background: linear-gradient(135deg, var(--primary-light), var(--primary)); color: white; }
        .btn-secondary { background: #e2e8f0; color: var(--dark); }
        .btn-success { background: var(--success); color: white; }
        .btn-danger { background: var(--danger); color: white; }

        /* Request Cards */
        .requests-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(380px, 1fr));
            gap: 1.5rem;
            margin-bottom: 2rem;
        }
        .request-card {
            background: white;
            border-radius: 1rem;
            border: 1px solid var(--border);
            overflow: hidden;
            transition: all 0.2s;
        }
        .request-card:hover {
            transform: translateY(-3px);
            box-shadow: 0 10px 25px rgba(0,0,0,0.1);
        }
        .card-header {
            padding: 1rem 1.25rem;
            background: var(--bg-light);
            border-bottom: 1px solid var(--border);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .request-number {
            font-weight: 700;
            color: var(--primary);
        }
        .urgency-badge {
            padding: 0.2rem 0.6rem;
            border-radius: 2rem;
            font-size: 0.7rem;
            font-weight: 600;
        }
        .urgency-emergency { background: #fee2e2; color: #991b1b; }
        .urgency-high { background: #fed7aa; color: #9a3412; }
        .urgency-medium { background: #dbeafe; color: #1e40af; }
        .urgency-low { background: #dcfce7; color: #166534; }
        .card-body { padding: 1rem 1.25rem; }
        .detail-row {
            display: flex;
            justify-content: space-between;
            margin-bottom: 0.5rem;
            font-size: 0.8rem;
        }
        .detail-label { color: var(--gray); }
        .detail-value { font-weight: 500; color: var(--dark); }
        .tools-list {
            background: #f8fafc;
            padding: 0.5rem;
            border-radius: 0.5rem;
            margin: 0.5rem 0;
            font-size: 0.75rem;
        }
        .card-footer {
            padding: 0.75rem 1.25rem;
            background: #f8fafc;
            border-top: 1px solid var(--border);
            display: flex;
            gap: 0.5rem;
        }
        .action-btn {
            flex: 1;
            padding: 0.4rem;
            border-radius: 0.4rem;
            font-size: 0.75rem;
            font-weight: 600;
            cursor: pointer;
            border: none;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 0.3rem;
        }

        /* Table */
        .table-container {
            background: white;
            border-radius: 1rem;
            overflow-x: auto;
            border: 1px solid var(--border);
        }
        table { width: 100%; border-collapse: collapse; }
        th {
            background: #f8fafc;
            padding: 0.8rem 1rem;
            text-align: left;
            font-weight: 600;
            font-size: 0.75rem;
            color: var(--gray);
            border-bottom: 1px solid var(--border);
        }
        td { padding: 0.8rem 1rem; border-bottom: 1px solid var(--border); font-size: 0.85rem; }
        .status-badge {
            padding: 0.2rem 0.6rem;
            border-radius: 2rem;
            font-size: 0.7rem;
            font-weight: 600;
        }
        .status-pending { background: #fed7aa; color: #9a3412; }
        .status-approved { background: #dbeafe; color: #1e40af; }
        .status-fulfilled { background: #dcfce7; color: #166534; }
        .status-rejected { background: #fee2e2; color: #991b1b; }
        .status-cancelled { background: #e2e8f0; color: #475569; }

        .section-title {
            font-size: 1.1rem;
            font-weight: 700;
            color: var(--dark);
            margin: 1.5rem 0 1rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .empty-state {
            text-align: center;
            padding: 3rem;
            color: var(--gray);
        }
        .empty-state i { font-size: 3rem; margin-bottom: 1rem; opacity: 0.5; }

        .alert {
            padding: 0.75rem 1rem;
            border-radius: 0.5rem;
            margin-bottom: 1rem;
        }
        .alert-error { background: #fee2e2; color: #991b1b; border-left: 3px solid var(--danger); }
        .alert-success { background: #dcfce7; color: #166534; border-left: 3px solid var(--success); }

        @media (max-width: 768px) {
            .sidebar { left: -260px; }
            .main-content { margin-left: 0; padding: 1rem; }
            .stats-grid { grid-template-columns: repeat(2, 1fr); }
            .requests-grid { grid-template-columns: 1fr; }
        }
    </style>
</head>
<body>
    <div class="sidebar">
        <div class="sidebar-header">
            <h2>🔧 SAVANT MOTORS</h2>
            <p>Tool Request System</p>
        </div>
        <div class="sidebar-menu">
            <div class="sidebar-title">MAIN</div>
            <a href="../dashboard_erp.php" class="menu-item">📊 Dashboard</a>
            <a href="../tools/index.php" class="menu-item">🔧 Tools</a>
            <a href="../technicians.php" class="menu-item">👨‍🔧 Technicians</a>
            <a href="../tool_requests/index.php" class="menu-item active">📝 Tool Requests</a>
            <div style="margin-top: 2rem;">
                <a href="../logout.php" class="menu-item">🚪 Logout</a>
            </div>
        </div>
    </div>

    <div class="main-content">
        <div class="top-bar">
            <div class="page-title">
                <h1><i class="fas fa-clipboard-list"></i> Tool Requests</h1>
                <p>Request and manage workshop tools</p>
            </div>
            <a href="create.php" class="btn btn-primary">
                <i class="fas fa-plus-circle"></i> New Request
            </a>
        </div>

        <?php if (isset($error)): ?>
        <div class="alert alert-error">❌ <?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>

        <?php if (isset($_SESSION['success'])): ?>
        <div class="alert alert-success">✅ <?php echo $_SESSION['success']; unset($_SESSION['success']); ?></div>
        <?php endif; ?>

        <!-- Statistics -->
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-value"><?php echo $stats['total'] ?? 0; ?></div>
                <div class="stat-label">Total</div>
            </div>
            <div class="stat-card">
                <div class="stat-value"><?php echo $stats['pending'] ?? 0; ?></div>
                <div class="stat-label">Pending</div>
            </div>
            <div class="stat-card">
                <div class="stat-value"><?php echo $stats['approved'] ?? 0; ?></div>
                <div class="stat-label">Approved</div>
            </div>
            <div class="stat-card">
                <div class="stat-value"><?php echo $stats['fulfilled'] ?? 0; ?></div>
                <div class="stat-label">Fulfilled</div>
            </div>
        </div>

        <!-- Pending Requests (Admin/Manager View) -->
        <?php if (!empty($pendingRequests)): ?>
        <div class="section-title">
            <i class="fas fa-hourglass-half"></i> Pending Requests (<?php echo count($pendingRequests); ?>)
        </div>
        <div class="requests-grid">
            <?php foreach ($pendingRequests as $request): ?>
            <div class="request-card">
                <div class="card-header">
                    <span class="request-number">#<?php echo htmlspecialchars($request['request_number']); ?></span>
                    <span class="urgency-badge urgency-<?php echo $request['urgency']; ?>">
                        <?php echo strtoupper($request['urgency']); ?>
                    </span>
                </div>
                <div class="card-body">
                    <div class="detail-row">
                        <span class="detail-label">Technician:</span>
                        <span class="detail-value"><?php echo htmlspecialchars($request['technician_name'] ?? 'N/A'); ?></span>
                    </div>
                    <div class="detail-row">
                        <span class="detail-label">Vehicle Plate:</span>
                        <span class="detail-value"><?php echo htmlspecialchars($request['number_plate']); ?></span>
                    </div>
                    <div class="detail-row">
                        <span class="detail-label">Duration:</span>
                        <span class="detail-value"><?php echo $request['expected_duration_days']; ?> days</span>
                    </div>
                    <div class="tools-list">
                        <strong>Tools Requested:</strong><br>
                        <?php echo nl2br(htmlspecialchars($request['tools_summary'] ?? 'No tools listed')); ?>
                    </div>
                    <div class="detail-row">
                        <span class="detail-label">Reason:</span>
                        <span class="detail-value"><?php echo htmlspecialchars(substr($request['reason'] ?? '', 0, 50)); ?>...</span>
                    </div>
                </div>
                <div class="card-footer">
                    <button class="action-btn btn-success" onclick="approveRequest(<?php echo $request['id']; ?>)">
                        <i class="fas fa-check"></i> Approve
                    </button>
                    <button class="action-btn btn-danger" onclick="rejectRequest(<?php echo $request['id']; ?>)">
                        <i class="fas fa-times"></i> Reject
                    </button>
                    <button class="action-btn btn-secondary" onclick="viewRequest(<?php echo $request['id']; ?>)">
                        <i class="fas fa-eye"></i> View
                    </button>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <!-- All Requests -->
        <div class="section-title">
            <i class="fas fa-history"></i> All Requests
        </div>
        <div class="table-container">
            <table>
                <thead>
                    <tr>
                        <th>Request #</th>
                        <th>Date</th>
                        <th>Technician</th>
                        <th>Tools</th>
                        <th>Plate</th>
                        <th>Urgency</th>
                        <th>Status</th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($myRequests)): ?>
                    <tr>
                        <td colspan="8" class="empty-state">
                            <i class="fas fa-inbox"></i>
                            <p>No requests found</p>
                            <a href="create.php" class="btn btn-primary" style="margin-top: 1rem;">Create First Request</a>
                        </td>
                    </tr>
                    <?php else: ?>
                    <?php foreach ($myRequests as $req): ?>
                    <tr>
                        <td><strong><?php echo htmlspecialchars($req['request_number'] ?? 'N/A'); ?></strong></td>
                        <td><?php echo date('d M Y', strtotime($req['created_at'])); ?></td>
                        <td><?php echo htmlspecialchars($req['technician_name'] ?? 'N/A'); ?></td>
                        <td><?php echo htmlspecialchars(substr($req['tools_summary'] ?? '', 0, 30)); ?>...</td>
                        <td><?php echo htmlspecialchars($req['number_plate']); ?></td>
                        <td>
                            <span class="urgency-badge urgency-<?php echo $req['urgency']; ?>">
                                <?php echo strtoupper($req['urgency']); ?>
                            </span>
                        </td>
                        <td>
                            <span class="status-badge status-<?php echo $req['status']; ?>">
                                <?php echo strtoupper($req['status']); ?>
                            </span>
                        </td>
                        <td>
                            <button class="action-btn btn-secondary" onclick="viewRequest(<?php echo $req['id']; ?>)">
                                <i class="fas fa-eye"></i> View
                            </button>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Rejection Modal -->
    <div id="rejectModal" style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); backdrop-filter: blur(4px); z-index: 2000; align-items: center; justify-content: center;">
        <div style="background: white; border-radius: 1rem; width: 90%; max-width: 400px;">
            <div style="padding: 1rem 1.5rem; background: var(--danger); color: white; border-radius: 1rem 1rem 0 0;">
                <h3><i class="fas fa-ban"></i> Reject Request</h3>
            </div>
            <div style="padding: 1.5rem;">
                <input type="hidden" id="rejectRequestId">
                <div class="form-group">
                    <label>Reason for rejection:</label>
                    <textarea id="rejectReason" rows="3" style="width: 100%; padding: 0.5rem; border: 1px solid var(--border); border-radius: 0.5rem;"></textarea>
                </div>
            </div>
            <div style="padding: 1rem 1.5rem; border-top: 1px solid var(--border); display: flex; justify-content: flex-end; gap: 0.5rem;">
                <button class="btn btn-secondary" onclick="closeRejectModal()">Cancel</button>
                <button class="btn btn-danger" onclick="confirmReject()">Confirm Rejection</button>
            </div>
        </div>
    </div>

    <script>
        let currentRequestId = null;
        
        function approveRequest(id) {
            if (confirm('Approve this tool request?')) {
                fetch(`approve.php?id=${id}`, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' }
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        alert('Request approved!');
                        location.reload();
                    } else {
                        alert('Error: ' + data.message);
                    }
                })
                .catch(error => alert('Error approving request'));
            }
        }
        
        function rejectRequest(id) {
            currentRequestId = id;
            document.getElementById('rejectModal').style.display = 'flex';
        }
        
        function closeRejectModal() {
            document.getElementById('rejectModal').style.display = 'none';
            document.getElementById('rejectReason').value = '';
            currentRequestId = null;
        }
        
        function confirmReject() {
            const reason = document.getElementById('rejectReason').value;
            if (!reason) {
                alert('Please provide a reason for rejection');
                return;
            }
            
            fetch(`reject.php?id=${currentRequestId}`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ reason: reason })
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    alert('Request rejected');
                    location.reload();
                } else {
                    alert('Error: ' + data.message);
                }
            })
            .catch(error => alert('Error rejecting request'));
        }
        
        function viewRequest(id) {
            window.location.href = `view.php?id=${id}`;
        }
        
        window.onclick = function(e) {
            if (e.target.id === 'rejectModal') {
                closeRejectModal();
            }
        }
    </script>
</body>
</html>