<?php
// views/tools/view_tool.php - View Single Tool Details
session_start();

if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    header('Location: ../index.php');
    exit();
}

$tool_id = isset($_GET['id']) ? intval($_GET['id']) : 0;

if (!$tool_id) {
    header('Location: tools.php');
    exit();
}

try {
    $conn = new PDO("mysql:host=localhost;dbname=savant_motors_pos", "root", "");
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    // Get tool details
    $stmt = $conn->prepare("
        SELECT 
            t.*,
            COUNT(DISTINCT ta.id) as times_assigned,
            MAX(ta.assigned_date) as last_assigned_date
        FROM tools t
        LEFT JOIN tool_assignments ta ON t.id = ta.tool_id
        WHERE t.id = ?
        GROUP BY t.id
    ");
    $stmt->execute([$tool_id]);
    $tool = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$tool) {
        header('Location: tools.php');
        exit();
    }
    
    // Get current assignment if tool is taken
    $stmt = $conn->prepare("
        SELECT 
            ta.id as assignment_id,
            ta.assigned_date,
            ta.expected_return_date,
            tech.full_name as technician_name,
            tech.technician_code,
            ta.notes as assignment_notes
        FROM tool_assignments ta
        INNER JOIN technicians tech ON ta.technician_id = tech.id
        WHERE ta.tool_id = ? AND ta.actual_return_date IS NULL
        ORDER BY ta.assigned_date DESC
        LIMIT 1
    ");
    $stmt->execute([$tool_id]);
    $currentAssignment = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // Get assignment history (without return_condition and return_notes)
    $stmt = $conn->prepare("
        SELECT 
            ta.id,
            ta.assigned_date,
            ta.expected_return_date,
            ta.actual_return_date,
            tech.full_name as technician_name,
            tech.technician_code,
            ta.notes,
            CASE 
                WHEN ta.actual_return_date IS NULL THEN 'Currently Assigned'
                ELSE 'Returned'
            END as assignment_status
        FROM tool_assignments ta
        INNER JOIN technicians tech ON ta.technician_id = tech.id
        WHERE ta.tool_id = ?
        ORDER BY ta.assigned_date DESC
        LIMIT 10
    ");
    $stmt->execute([$tool_id]);
    $assignments = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
} catch(PDOException $e) {
    $error = $e->getMessage();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($tool['tool_name']); ?> | SAVANT MOTORS</title>
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
            --info: #06b6d4;
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

        /* Cards */
        .details-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 1.5rem;
            margin-bottom: 1.5rem;
        }
        .info-card {
            background: white;
            border-radius: 1rem;
            border: 1px solid var(--border);
            overflow: hidden;
        }
        .card-header {
            padding: 1rem 1.5rem;
            background: #f8fafc;
            border-bottom: 1px solid var(--border);
            font-weight: 600;
            color: var(--dark);
        }
        .card-body { padding: 1.5rem; }
        .detail-row {
            display: flex;
            margin-bottom: 0.75rem;
            padding-bottom: 0.75rem;
            border-bottom: 1px solid var(--border);
        }
        .detail-row:last-child { border-bottom: none; }
        .detail-label {
            width: 140px;
            font-size: 0.8rem;
            color: var(--gray);
        }
        .detail-value {
            flex: 1;
            font-weight: 500;
            color: var(--dark);
        }
        .status-badge {
            display: inline-block;
            padding: 0.25rem 0.75rem;
            border-radius: 2rem;
            font-size: 0.75rem;
            font-weight: 600;
        }
        .status-available { background: #dcfce7; color: #166534; }
        .status-taken { background: #fee2e2; color: #991b1b; }
        .status-in_use { background: #fed7aa; color: #9a3412; }
        .status-maintenance { background: #e2e8f0; color: #475569; }

        /* Buttons */
        .btn {
            padding: 0.5rem 1rem;
            border-radius: 0.5rem;
            font-weight: 600;
            font-size: 0.8rem;
            cursor: pointer;
            border: none;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            text-decoration: none;
        }
        .btn-primary { background: linear-gradient(135deg, var(--primary-light), var(--primary)); color: white; }
        .btn-primary:hover { background: var(--primary); }
        .btn-secondary { background: #e2e8f0; color: var(--dark); }
        .btn-secondary:hover { background: #cbd5e1; }
        .btn-danger { background: var(--danger); color: white; }
        .btn-danger:hover { background: #dc2626; }
        .btn-success { background: var(--success); color: white; }
        .btn-success:hover { background: #059669; }

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
        tr:hover { background: #f8fafc; }

        .alert {
            padding: 0.75rem 1rem;
            border-radius: 0.5rem;
            margin-bottom: 1rem;
        }
        .alert-error { background: #fee2e2; color: #991b1b; border-left: 3px solid var(--danger); }
        .alert-success { background: #dcfce7; color: #166534; border-left: 3px solid var(--success); }
        .alert-info { background: #dbeafe; color: #1e40af; border-left: 3px solid var(--primary); }

        .empty-state {
            text-align: center;
            padding: 2rem;
            color: var(--gray);
        }

        .action-buttons {
            display: flex;
            gap: 0.5rem;
            margin-top: 1rem;
        }

        @media (max-width: 768px) {
            .sidebar { left: -260px; }
            .main-content { margin-left: 0; padding: 1rem; }
            .details-grid { grid-template-columns: 1fr; }
        }
    </style>
</head>
<body>
    <div class="sidebar">
        <div class="sidebar-header">
            <h2>🔧 SAVANT MOTORS</h2>
            <p>Tool Management System</p>
        </div>
        <div class="sidebar-menu">
            <div class="sidebar-title">MAIN</div>
            <a href="../dashboard_erp.php" class="menu-item">📊 Dashboard</a>
            <a href="../job_cards.php" class="menu-item">📋 Job Cards</a>
            <a href="../technicians.php" class="menu-item">👨‍🔧 Technicians</a>
            <a href="../toolsindex.php" class="menu-item active">🔧 All Tools</a>
            <a href="../tools/taken.php" class="menu-item">📤 Tools Taken</a>
            <a href="../tool_requests/index.php" class="menu-item">📝 Tool Requests</a>
            <a href="../customers.php" class="menu-item">👥 Customers</a>
            <div style="margin-top: 2rem;">
                <a href="../logout.php" class="menu-item">🚪 Logout</a>
            </div>
        </div>
    </div>

    <div class="main-content">
        <div class="top-bar">
            <div class="page-title">
                <h1><i class="fas fa-tools"></i> <?php echo htmlspecialchars($tool['tool_name']); ?></h1>
                <p>Tool details and assignment history</p>
            </div>
            <div class="action-buttons">
                <a href="tools.php" class="btn btn-secondary">
                    <i class="fas fa-arrow-left"></i> Back to Tools
                </a>
                <a href="edit_tool.php?id=<?php echo $tool['id']; ?>" class="btn btn-primary">
                    <i class="fas fa-edit"></i> Edit Tool
                </a>
            </div>
        </div>

        <?php if (isset($error)): ?>
        <div class="alert alert-error"><?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>

        <?php if (isset($_SESSION['success'])): ?>
        <div class="alert alert-success"><?php echo $_SESSION['success']; unset($_SESSION['success']); ?></div>
        <?php endif; ?>

        <div class="details-grid">
            <!-- Tool Information Card -->
            <div class="info-card">
                <div class="card-header">
                    <i class="fas fa-info-circle"></i> Tool Information
                </div>
                <div class="card-body">
                    <div class="detail-row">
                        <div class="detail-label">Tool Code:</div>
                        <div class="detail-value"><strong><?php echo htmlspecialchars($tool['tool_code']); ?></strong></div>
                    </div>
                    <div class="detail-row">
                        <div class="detail-label">Tool Name:</div>
                        <div class="detail-value"><?php echo htmlspecialchars($tool['tool_name']); ?></div>
                    </div>
                    <div class="detail-row">
                        <div class="detail-label">Category:</div>
                        <div class="detail-value"><?php echo htmlspecialchars($tool['category'] ?? 'General'); ?></div>
                    </div>
                    <div class="detail-row">
                        <div class="detail-label">Brand:</div>
                        <div class="detail-value"><?php echo htmlspecialchars($tool['brand'] ?? 'N/A'); ?></div>
                    </div>
                    <div class="detail-row">
                        <div class="detail-label">Model:</div>
                        <div class="detail-value"><?php echo htmlspecialchars($tool['model'] ?? 'N/A'); ?></div>
                    </div>
                    <div class="detail-row">
                        <div class="detail-label">Location:</div>
                        <div class="detail-value"><?php echo htmlspecialchars($tool['location'] ?? 'Main Workshop'); ?></div>
                    </div>
                    <div class="detail-row">
                        <div class="detail-label">Status:</div>
                        <div class="detail-value">
                            <span class="status-badge status-<?php echo $tool['status']; ?>">
                                <?php echo strtoupper(str_replace('_', ' ', $tool['status'])); ?>
                            </span>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Current Assignment Card -->
            <div class="info-card">
                <div class="card-header">
                    <i class="fas fa-hand-holding"></i> Current Assignment
                </div>
                <div class="card-body">
                    <?php if ($currentAssignment): ?>
                    <div class="detail-row">
                        <div class="detail-label">Technician:</div>
                        <div class="detail-value">
                            <?php echo htmlspecialchars($currentAssignment['technician_name']); ?>
                            <br><small><?php echo htmlspecialchars($currentAssignment['technician_code']); ?></small>
                        </div>
                    </div>
                    <div class="detail-row">
                        <div class="detail-label">Assigned Date:</div>
                        <div class="detail-value"><?php echo date('d M Y, h:i A', strtotime($currentAssignment['assigned_date'])); ?></div>
                    </div>
                    <div class="detail-row">
                        <div class="detail-label">Expected Return:</div>
                        <div class="detail-value">
                            <?php echo date('d M Y, h:i A', strtotime($currentAssignment['expected_return_date'])); ?>
                            <?php if (strtotime($currentAssignment['expected_return_date']) < time()): ?>
                            <span class="status-badge" style="background: #fee2e2; color: #991b1b; margin-left: 0.5rem;">Overdue</span>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php if ($currentAssignment['assignment_notes']): ?>
                    <div class="detail-row">
                        <div class="detail-label">Notes:</div>
                        <div class="detail-value"><?php echo htmlspecialchars($currentAssignment['assignment_notes']); ?></div>
                    </div>
                    <?php endif; ?>
                    <div class="action-buttons" style="margin-top: 1rem;">
                        <button class="btn btn-success" onclick="returnTool(<?php echo $tool['id']; ?>, <?php echo $currentAssignment['assignment_id']; ?>, '<?php echo addslashes($tool['tool_name']); ?>')">
                            <i class="fas fa-undo-alt"></i> Return Tool
                        </button>
                    </div>
                    <?php else: ?>
                    <div class="empty-state">
                        <i class="fas fa-check-circle" style="font-size: 2rem; color: var(--success);"></i>
                        <p style="margin-top: 0.5rem;">Tool is currently available</p>
                        <a href="assign_tool.php?id=<?php echo $tool['id']; ?>" class="btn btn-primary" style="margin-top: 1rem;">
                            <i class="fas fa-hand-holding"></i> Assign to Technician
                        </a>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Additional Stats Card -->
        <div class="info-card" style="margin-bottom: 1.5rem;">
            <div class="card-header">
                <i class="fas fa-chart-line"></i> Usage Statistics
            </div>
            <div class="card-body">
                <div style="display: grid; grid-template-columns: repeat(3, 1fr); gap: 1rem; text-align: center;">
                    <div>
                        <div style="font-size: 1.5rem; font-weight: 700; color: var(--primary);"><?php echo $tool['times_assigned'] ?? 0; ?></div>
                        <div style="font-size: 0.7rem; color: var(--gray);">Times Assigned</div>
                    </div>
                    <div>
                        <div style="font-size: 1.5rem; font-weight: 700; color: var(--primary);">
                            <?php echo $tool['last_assigned_date'] ? date('d M Y', strtotime($tool['last_assigned_date'])) : 'Never'; ?>
                        </div>
                        <div style="font-size: 0.7rem; color: var(--gray);">Last Assigned</div>
                    </div>
                    <div>
                        <div style="font-size: 1.5rem; font-weight: 700; color: var(--primary);">
                            <?php echo $tool['status'] == 'available' ? 'Available' : 'In Use'; ?>
                        </div>
                        <div style="font-size: 0.7rem; color: var(--gray);">Current Status</div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Assignment History -->
        <div class="info-card">
            <div class="card-header">
                <i class="fas fa-history"></i> Assignment History
            </div>
            <div class="table-container" style="border-radius: 0;">
                <table>
                    <thead>
                        <tr>
                            <th>Assigned Date</th>
                            <th>Return Date</th>
                            <th>Technician</th>
                            <th>Expected Return</th>
                            <th>Notes</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($assignments)): ?>
                        <tr>
                            <td colspan="6" class="empty-state">
                                <i class="fas fa-inbox"></i>
                                <p>No assignment history found</p>
                            </td>
                        </tr>
                        <?php else: ?>
                        <?php foreach ($assignments as $assignment): ?>
                        <tr>
                            <td><?php echo date('d M Y', strtotime($assignment['assigned_date'])); ?></td>
                            <td>
                                <?php echo $assignment['actual_return_date'] ? date('d M Y', strtotime($assignment['actual_return_date'])) : '-'; ?>
                            </td>
                            <td>
                                <?php echo htmlspecialchars($assignment['technician_name']); ?>
                                <br><small><?php echo htmlspecialchars($assignment['technician_code']); ?></small>
                            </td>
                            <td><?php echo date('d M Y', strtotime($assignment['expected_return_date'])); ?></td>
                            <td>
                                <?php echo $assignment['notes'] ? htmlspecialchars(substr($assignment['notes'], 0, 50)) : '-'; ?>
                            </td>
                            <td>
                                <?php if ($assignment['assignment_status'] == 'Currently Assigned'): ?>
                                <span class="status-badge status-taken">Currently Assigned</span>
                                <?php else: ?>
                                <span class="status-badge status-available">Returned</span>
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

    <!-- Return Modal -->
    <div id="returnModal" style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); backdrop-filter: blur(4px); z-index: 2000; align-items: center; justify-content: center;">
        <div style="background: white; border-radius: 1rem; width: 90%; max-width: 400px; overflow: hidden;">
            <div style="background: linear-gradient(135deg, #10b981, #059669); padding: 1rem 1.5rem; color: white;">
                <h3 style="margin: 0;"><i class="fas fa-undo-alt"></i> Return Tool</h3>
            </div>
            <div style="padding: 1.5rem;">
                <p>Confirm return of tool:</p>
                <p><strong id="returnToolName"></strong></p>
                <div style="margin-top: 1rem;">
                    <label>Condition on Return:</label>
                    <select id="returnCondition" style="width: 100%; padding: 0.5rem; margin-top: 0.25rem; border: 1px solid #e2e8f0; border-radius: 0.5rem;">
                        <option value="Good">Good</option>
                        <option value="Fair">Fair</option>
                        <option value="Poor">Poor</option>
                        <option value="Damaged">Damaged</option>
                    </select>
                </div>
                <div style="margin-top: 1rem;">
                    <label>Notes:</label>
                    <textarea id="returnNotes" rows="2" style="width: 100%; padding: 0.5rem; border: 1px solid #e2e8f0; border-radius: 0.5rem;" placeholder="Any issues or notes..."></textarea>
                </div>
            </div>
            <div style="padding: 1rem 1.5rem; border-top: 1px solid #e2e8f0; display: flex; justify-content: flex-end; gap: 0.5rem;">
                <button class="btn btn-secondary" onclick="closeReturnModal()">Cancel</button>
                <button class="btn btn-success" id="confirmReturnBtn">Confirm Return</button>
            </div>
        </div>
    </div>

    <script>
        let currentToolId = null;
        let currentAssignmentId = null;
        
        function returnTool(toolId, assignmentId, toolName) {
            currentToolId = toolId;
            currentAssignmentId = assignmentId;
            document.getElementById('returnToolName').innerText = toolName;
            document.getElementById('returnModal').style.display = 'flex';
        }
        
        function closeReturnModal() {
            document.getElementById('returnModal').style.display = 'none';
            currentToolId = null;
            currentAssignmentId = null;
        }
        
        document.getElementById('confirmReturnBtn').addEventListener('click', function() {
            if (!currentToolId || !currentAssignmentId) return;
            
            var condition = document.getElementById('returnCondition').value;
            var notes = document.getElementById('returnNotes').value;
            
            fetch('return_tool.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ 
                    tool_id: currentToolId, 
                    assignment_id: currentAssignmentId,
                    condition: condition,
                    notes: notes
                })
            })
            .then(function(response) { return response.json(); })
            .then(function(data) {
                if (data.success) {
                    alert('Tool returned successfully!');
                    location.reload();
                } else {
                    alert('Error: ' + (data.message || 'Unknown error'));
                }
                closeReturnModal();
            })
            .catch(function(error) {
                alert('Error returning tool: ' + error);
                closeReturnModal();
            });
        });
        
        window.onclick = function(e) {
            if (e.target.id === 'returnModal') {
                closeReturnModal();
            }
        }
    </script>
</body>
</html>