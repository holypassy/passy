<?php
// assign_tool.php – Assign tools to technicians
session_start();

if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    header('Location: index.php');
    exit();
}

$user_full_name = $_SESSION['full_name'] ?? 'User';
$user_role = $_SESSION['role'] ?? 'user';
$user_id = $_SESSION['user_id'] ?? 1;

try {
    $conn = new PDO("mysql:host=localhost;dbname=savant_motors_pos", "root", "");
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // Ensure tool_assignments table exists with all required columns
    $conn->exec("
        CREATE TABLE IF NOT EXISTS tool_assignments (
            id INT AUTO_INCREMENT PRIMARY KEY,
            tool_id INT NOT NULL,
            technician_id INT NOT NULL,
            assigned_date DATE NOT NULL,
            expected_return_date DATE NOT NULL,
            actual_return_date DATE NULL,
            status ENUM('assigned','returned') DEFAULT 'assigned',
            notes TEXT,
            created_by INT,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (tool_id) REFERENCES tools(id) ON DELETE CASCADE,
            FOREIGN KEY (technician_id) REFERENCES technicians(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    // Add created_by column if it's missing (for existing tables)
    try {
        $conn->exec("ALTER TABLE tool_assignments ADD COLUMN created_by INT AFTER notes");
    } catch (PDOException $e) {
        // Column already exists – ignore error
        if (strpos($e->getMessage(), 'Duplicate column') === false) {
            // Re-throw if it's a different error
            throw $e;
        }
    }

    // Get technicians (active, not blocked)
    $technicians = $conn->query("
        SELECT id, full_name, technician_code, specialization 
        FROM technicians 
        WHERE status = 'active' AND (is_blocked = 0 OR is_blocked IS NULL) 
        ORDER BY full_name
    ")->fetchAll(PDO::FETCH_ASSOC);

    // Get available tools (status = 'available')
    $availableTools = $conn->query("
        SELECT id, tool_code, tool_name, category 
        FROM tools 
        WHERE status = 'available' AND (is_active = 1 OR is_active IS NULL)
        ORDER BY tool_code
    ")->fetchAll(PDO::FETCH_ASSOC);

    // Get currently assigned tools (not returned)
    $currentAssignments = $conn->query("
        SELECT 
            ta.id AS assignment_id,
            ta.assigned_date,
            ta.expected_return_date,
            ta.notes,
            t.id AS tool_id,
            t.tool_code,
            t.tool_name,
            t.category,
            tech.id AS technician_id,
            tech.full_name AS technician_name,
            tech.technician_code,
            DATEDIFF(CURDATE(), ta.expected_return_date) AS days_overdue
        FROM tool_assignments ta
        JOIN tools t ON ta.tool_id = t.id
        JOIN technicians tech ON ta.technician_id = tech.id
        WHERE ta.status = 'assigned' AND ta.actual_return_date IS NULL
        ORDER BY ta.expected_return_date ASC
    ")->fetchAll(PDO::FETCH_ASSOC);

    // Handle assignment POST
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['assign_tool'])) {
        $tool_id = (int)$_POST['tool_id'];
        $technician_id = (int)$_POST['technician_id'];
        $expected_return_date = $_POST['expected_return_date'];
        $notes = trim($_POST['notes'] ?? '');

        // Validate
        if (!$tool_id || !$technician_id || !$expected_return_date) {
            $_SESSION['error'] = "Please fill all required fields.";
            header('Location: assign_tool.php');
            exit();
        }

        try {
            $conn->beginTransaction();

            // Check if tool is still available
            $toolCheck = $conn->prepare("SELECT status FROM tools WHERE id = ? FOR UPDATE");
            $toolCheck->execute([$tool_id]);
            $tool = $toolCheck->fetch();
            if (!$tool || $tool['status'] !== 'available') {
                throw new Exception("Tool is no longer available.");
            }

            // Insert assignment (created_by column now exists)
            $stmt = $conn->prepare("
                INSERT INTO tool_assignments (tool_id, technician_id, assigned_date, expected_return_date, notes, created_by)
                VALUES (?, ?, CURDATE(), ?, ?, ?)
            ");
            $stmt->execute([$tool_id, $technician_id, $expected_return_date, $notes, $user_id]);

            // Update tool status to 'taken'
            $conn->prepare("UPDATE tools SET status = 'taken' WHERE id = ?")->execute([$tool_id]);

            $conn->commit();
            $_SESSION['success'] = "Tool assigned successfully!";
        } catch (Exception $e) {
            $conn->rollBack();
            $_SESSION['error'] = "Error: " . $e->getMessage();
        }
        header('Location: assign_tool.php');
        exit();
    }

    // Handle return POST
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['return_tool'])) {
        $assignment_id = (int)$_POST['assignment_id'];

        try {
            $conn->beginTransaction();

            // Get tool_id from assignment
            $stmt = $conn->prepare("SELECT tool_id FROM tool_assignments WHERE id = ? AND status = 'assigned' FOR UPDATE");
            $stmt->execute([$assignment_id]);
            $assignment = $stmt->fetch();
            if (!$assignment) {
                throw new Exception("Assignment not found or already returned.");
            }

            // Update assignment
            $conn->prepare("
                UPDATE tool_assignments 
                SET actual_return_date = CURDATE(), status = 'returned' 
                WHERE id = ?
            ")->execute([$assignment_id]);

            // Update tool status back to available
            $conn->prepare("UPDATE tools SET status = 'available' WHERE id = ?")->execute([$assignment['tool_id']]);

            $conn->commit();
            $_SESSION['success'] = "Tool marked as returned.";
        } catch (Exception $e) {
            $conn->rollBack();
            $_SESSION['error'] = "Error: " . $e->getMessage();
        }
        header('Location: assign_tool.php');
        exit();
    }

} catch (PDOException $e) {
    $error = $e->getMessage();
    $technicians = [];
    $availableTools = [];
    $currentAssignments = [];
}

$success_message = $_SESSION['success'] ?? null;
$error_message = $_SESSION['error'] ?? null;
unset($_SESSION['success'], $_SESSION['error']);

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Assign Tools | SAVANT MOTORS</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:opsz,wght@14..32,300;14..32,400;14..32,500;14..32,600;14..32,700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: 'Inter', sans-serif;
            background: linear-gradient(135deg, #f5f7fb 0%, #eef2f9 100%);
            padding: 2rem;
        }
        .container { max-width: 1400px; margin: 0 auto; }
        .header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 2rem;
            flex-wrap: wrap;
            gap: 1rem;
        }
        h1 { font-size: 1.8rem; font-weight: 700; color: #0f172a; }
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
        .btn-primary { background: linear-gradient(135deg, #3b82f6, #1e40af); color: white; }
        .btn-secondary { background: #e2e8f0; color: #1e293b; }
        .btn-danger { background: #ef4444; color: white; }
        .btn-sm { padding: 0.3rem 0.8rem; font-size: 0.75rem; }
        .card {
            background: white;
            border-radius: 1rem;
            border: 1px solid #e2e8f0;
            margin-bottom: 2rem;
            overflow: hidden;
        }
        .card-header {
            padding: 1.2rem 1.5rem;
            background: #f8fafc;
            border-bottom: 1px solid #e2e8f0;
            font-weight: 700;
            font-size: 1.1rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        .card-body { padding: 1.5rem; }
        .form-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 1.2rem;
        }
        .form-group label {
            display: block;
            font-size: 0.75rem;
            font-weight: 600;
            text-transform: uppercase;
            color: #64748b;
            margin-bottom: 0.3rem;
        }
        .form-group input, .form-group select, .form-group textarea {
            width: 100%;
            padding: 0.6rem 0.8rem;
            border: 1.5px solid #e2e8f0;
            border-radius: 0.5rem;
            font-size: 0.85rem;
            font-family: inherit;
        }
        .form-group input:focus, .form-group select:focus {
            outline: none;
            border-color: #3b82f6;
        }
        table {
            width: 100%;
            border-collapse: collapse;
        }
        th {
            text-align: left;
            padding: 0.8rem 1rem;
            background: #f8fafc;
            font-size: 0.75rem;
            font-weight: 600;
            color: #64748b;
            border-bottom: 1px solid #e2e8f0;
        }
        td {
            padding: 0.8rem 1rem;
            border-bottom: 1px solid #e2e8f0;
            font-size: 0.85rem;
        }
        .status-badge {
            display: inline-block;
            padding: 0.2rem 0.6rem;
            border-radius: 2rem;
            font-size: 0.7rem;
            font-weight: 600;
        }
        .status-active { background: #dbeafe; color: #1e40af; }
        .status-overdue { background: #fee2e2; color: #991b1b; }
        .alert {
            padding: 0.8rem 1rem;
            border-radius: 0.5rem;
            margin-bottom: 1.5rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        .alert-success { background: #dcfce7; color: #166534; border-left: 4px solid #10b981; }
        .alert-danger { background: #fee2e2; color: #991b1b; border-left: 4px solid #ef4444; }
        .empty-state { text-align: center; padding: 3rem; color: #94a3b8; }
        .empty-state i { font-size: 3rem; margin-bottom: 1rem; opacity: 0.5; }
        .action-btn {
            background: none;
            border: none;
            cursor: pointer;
            color: #3b82f6;
            font-size: 1rem;
            margin: 0 0.2rem;
        }
        .action-btn:hover { color: #1e40af; }
        @media (max-width: 768px) {
            body { padding: 1rem; }
            .form-grid { grid-template-columns: 1fr; }
        }
    </style>
</head>
<body>
<div class="container">
    <div class="header">
        <h1><i class="fas fa-hand-holding"></i> Assign Tools to Technicians</h1>
        <div>
            <a href="../tools/index.php" class="btn btn-secondary"><i class="fas fa-arrow-left"></i> Back to Tools</a>
        </div>
    </div>

    <?php if ($success_message): ?>
    <div class="alert alert-success"><i class="fas fa-check-circle"></i> <?= htmlspecialchars($success_message) ?></div>
    <?php endif; ?>
    <?php if ($error_message): ?>
    <div class="alert alert-danger"><i class="fas fa-exclamation-triangle"></i> <?= htmlspecialchars($error_message) ?></div>
    <?php endif; ?>

    <!-- Assignment Form -->
    <div class="card">
        <div class="card-header">
            <i class="fas fa-plus-circle"></i> New Tool Assignment
        </div>
        <div class="card-body">
            <form method="POST">
                <div class="form-grid">
                    <div class="form-group">
                        <label>Select Technician *</label>
                        <select name="technician_id" required>
                            <option value="">-- Choose Technician --</option>
                            <?php foreach ($technicians as $tech): ?>
                            <option value="<?= $tech['id'] ?>">
                                <?= htmlspecialchars($tech['full_name']) ?> (<?= htmlspecialchars($tech['technician_code']) ?>)
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Select Tool *</label>
                        <select name="tool_id" required>
                            <option value="">-- Choose Tool --</option>
                            <?php foreach ($availableTools as $tool): ?>
                            <option value="<?= $tool['id'] ?>">
                                <?= htmlspecialchars($tool['tool_code']) ?> - <?= htmlspecialchars($tool['tool_name']) ?>
                                (<?= htmlspecialchars($tool['category'] ?? 'General') ?>)
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Expected Return Date *</label>
                        <input type="date" name="expected_return_date" required value="<?= date('Y-m-d', strtotime('+3 days')) ?>">
                    </div>
                    <div class="form-group">
                        <label>Notes (optional)</label>
                        <textarea name="notes" rows="2" placeholder="Any special instructions or condition notes..."></textarea>
                    </div>
                </div>
                <div style="margin-top: 1.5rem; text-align: right;">
                    <button type="submit" name="assign_tool" class="btn btn-primary"><i class="fas fa-check"></i> Assign Tool</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Currently Assigned Tools -->
    <div class="card">
        <div class="card-header">
            <i class="fas fa-clipboard-list"></i> Currently Assigned Tools (Not Returned)
        </div>
        <div class="card-body" style="padding: 0;">
            <?php if (empty($currentAssignments)): ?>
                <div class="empty-state">
                    <i class="fas fa-check-circle"></i>
                    <p>No tools currently assigned. All tools are available.</p>
                </div>
            <?php else: ?>
                <table>
                    <thead>
                        <tr>
                            <th>Tool</th>
                            <th>Technician</th>
                            <th>Assigned Date</th>
                            <th>Expected Return</th>
                            <th>Status</th>
                            <th>Notes</th>
                            <th>Action</th>
                        </thead>
                        <tbody>
                        <?php foreach ($currentAssignments as $assigned): ?>
                            <tr>
                                <td>
                                    <strong><?= htmlspecialchars($assigned['tool_code']) ?></strong><br>
                                    <small><?= htmlspecialchars($assigned['tool_name']) ?></small>
                                </td>
                                <td>
                                    <?= htmlspecialchars($assigned['technician_name']) ?><br>
                                    <small><?= htmlspecialchars($assigned['technician_code']) ?></small>
                                </td>
                                <td><?= date('d M Y', strtotime($assigned['assigned_date'])) ?></td>
                                <td>
                                    <?= date('d M Y', strtotime($assigned['expected_return_date'])) ?>
                                    <?php if ($assigned['days_overdue'] > 0): ?>
                                        <span class="status-badge status-overdue" style="display: block; margin-top: 4px;">Overdue <?= $assigned['days_overdue'] ?> days</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <span class="status-badge status-active">Assigned</span>
                                </td>
                                <td><?= nl2br(htmlspecialchars($assigned['notes'] ?? '')) ?></td>
                                <td>
                                    <form method="POST" style="display: inline;" onsubmit="return confirm('Mark this tool as returned?')">
                                        <input type="hidden" name="assignment_id" value="<?= $assigned['assignment_id'] ?>">
                                        <button type="submit" name="return_tool" class="action-btn" title="Return Tool">
                                            <i class="fas fa-undo-alt"></i> Return
                                        </button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
        </div>
    </div>
</div>
</body>
</html>