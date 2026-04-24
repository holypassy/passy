<?php
// views/tool_requests/view.php - View a single tool request
session_start();

if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    header('Location: index.php');
    exit();
}

$request_id = isset($_GET['id']) ? intval($_GET['id']) : 0;

if (!$request_id) {
    header('Location: index.php');
    exit();
}

try {
    $conn = new PDO("mysql:host=localhost;dbname=savant_motors_pos", "root", "");
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    // Get request details
    $stmt = $conn->prepare("
        SELECT 
            tr.*,
            t.full_name as technician_name,
            t.technician_code
        FROM tool_requests tr
        LEFT JOIN technicians t ON tr.technician_id = t.id
        WHERE tr.id = ?
    ");
    $stmt->execute([$request_id]);
    $request = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$request) {
        header('Location: index.php');
        exit();
    }
    
    // Get tools in this request
    $stmt = $conn->prepare("
        SELECT 
            tri.*,
            t.tool_code,
            t.tool_name as existing_tool_name
        FROM tool_request_items tri
        LEFT JOIN tools t ON tri.tool_id = t.id
        WHERE tri.request_id = ?
    ");
    $stmt->execute([$request_id]);
    $tools = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
} catch(PDOException $e) {
    $error = $e->getMessage();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>View Request #<?php echo htmlspecialchars($request['request_number']); ?> | SAVANT MOTORS</title>
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
        .main-content { max-width: 800px; margin: 2rem auto; padding: 0 1rem; }
        .card {
            background: white;
            border-radius: 1rem;
            border: 1px solid var(--border);
            overflow: hidden;
        }
        .card-header {
            background: linear-gradient(135deg, var(--primary-light), var(--primary));
            padding: 1rem 1.5rem;
            color: white;
        }
        .card-body { padding: 1.5rem; }
        .detail-row {
            display: flex;
            margin-bottom: 1rem;
            padding-bottom: 0.5rem;
            border-bottom: 1px solid var(--border);
        }
        .detail-label {
            width: 150px;
            font-weight: 600;
            color: var(--gray);
        }
        .detail-value { flex: 1; color: var(--dark); }
        .tools-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 1rem;
        }
        .tools-table th, .tools-table td {
            padding: 0.5rem;
            text-align: left;
            border-bottom: 1px solid var(--border);
        }
        .tools-table th {
            background: #f8fafc;
            font-weight: 600;
        }
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
        .btn-secondary { background: #e2e8f0; color: var(--dark); }
        .btn-primary { background: linear-gradient(135deg, var(--primary-light), var(--primary)); color: white; }
        .form-actions { margin-top: 1.5rem; display: flex; gap: 1rem; justify-content: flex-end; }
    </style>
</head>
<body>
    <div class="main-content">
        <div class="card">
            <div class="card-header">
                <h2>Tool Request #<?php echo htmlspecialchars($request['request_number']); ?></h2>
            </div>
            <div class="card-body">
                <div class="detail-row">
                    <div class="detail-label">Status:</div>
                    <div class="detail-value">
                        <span class="status-badge status-<?php echo $request['status']; ?>">
                            <?php echo strtoupper($request['status']); ?>
                        </span>
                    </div>
                </div>
                <div class="detail-row">
                    <div class="detail-label">Date:</div>
                    <div class="detail-value"><?php echo date('d M Y, h:i A', strtotime($request['created_at'])); ?></div>
                </div>
                <div class="detail-row">
                    <div class="detail-label">Technician:</div>
                    <div class="detail-value"><?php echo htmlspecialchars($request['technician_name']); ?> (<?php echo $request['technician_code']; ?>)</div>
                </div>
                <div class="detail-row">
                    <div class="detail-label">Vehicle Plate:</div>
                    <div class="detail-value"><?php echo htmlspecialchars($request['number_plate']); ?></div>
                </div>
                <div class="detail-row">
                    <div class="detail-label">Expected Duration:</div>
                    <div class="detail-value"><?php echo $request['expected_duration_days']; ?> days</div>
                </div>
                <div class="detail-row">
                    <div class="detail-label">Urgency:</div>
                    <div class="detail-value"><?php echo strtoupper($request['urgency']); ?></div>
                </div>
                <div class="detail-row">
                    <div class="detail-label">Reason:</div>
                    <div class="detail-value"><?php echo nl2br(htmlspecialchars($request['reason'])); ?></div>
                </div>
                <?php if ($request['instructions']): ?>
                <div class="detail-row">
                    <div class="detail-label">Instructions:</div>
                    <div class="detail-value"><?php echo nl2br(htmlspecialchars($request['instructions'])); ?></div>
                </div>
                <?php endif; ?>
                <?php if ($request['rejection_reason']): ?>
                <div class="detail-row">
                    <div class="detail-label">Rejection Reason:</div>
                    <div class="detail-value" style="color: var(--danger);"><?php echo htmlspecialchars($request['rejection_reason']); ?></div>
                </div>
                <?php endif; ?>
                
                <h3 style="margin: 1.5rem 0 0.5rem;">Tools Requested</h3>
                <table class="tools-table">
                    <thead>
                        <tr><th>Tool</th><th>Quantity</th><th>Type</th></tr>
                    </thead>
                    <tbody>
                        <?php foreach ($tools as $tool): ?>
                        <tr>
                            <td>
                                <?php 
                                if ($tool['is_new_tool']) {
                                    echo '<strong>🆕 New Tool:</strong> ' . htmlspecialchars($tool['tool_name']);
                                } else {
                                    echo htmlspecialchars($tool['tool_code'] . ' - ' . $tool['existing_tool_name']);
                                }
                                ?>
                            </td>
                            <td><?php echo $tool['quantity']; ?></td>
                            <td><?php echo $tool['is_new_tool'] ? 'New Request' : 'Existing'; ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                
                <div class="form-actions">
                    <a href="index.php" class="btn btn-secondary">← Back to Requests</a>
                    <?php if ($request['status'] === 'pending'): ?>
                    <button class="btn btn-primary" onclick="approveRequest(<?php echo $request['id']; ?>)">
                        <i class="fas fa-check"></i> Approve
                    </button>
                    <button class="btn btn-danger" onclick="rejectRequest(<?php echo $request['id']; ?>)">
                        <i class="fas fa-times"></i> Reject
                    </button>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
    <script>
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
                .catch(error => alert('Error approving request: ' + error));
            }
        }
        
        function rejectRequest(id) {
            const reason = prompt('Please provide a reason for rejection:');
            if (reason) {
                fetch(`reject.php?id=${id}`, {
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
                .catch(error => alert('Error rejecting request: ' + error));
            }
        }
    </script>
</body>
</html>