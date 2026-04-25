<?php
// quotations.php – List quotations with status update
session_start();

if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    header('Location: index.php');
    exit();
}

$user_full_name = $_SESSION['full_name'] ?? 'User';
$user_role = $_SESSION['role'] ?? 'user';
$user_id = $_SESSION['user_id'] ?? 1;

$quotations = [];
$error_message = null;

try {
    $conn = new PDO("mysql:host=localhost;dbname=savant_motors_pos", "root", "");
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // === AUTO-UPDATE JOB CARD TO pending WHEN A QUOTATION IS JUST CREATED FOR IT ===
    // new_quotation.php should redirect here with ?job_card_updated=<job_card_id> after saving
    if (isset($_GET['job_card_updated']) && (int)$_GET['job_card_updated'] > 0) {
        try {
            $jcId = (int)$_GET['job_card_updated'];
            $conn->prepare("
                UPDATE job_cards SET status = 'pending'
                WHERE id = ? AND deleted_at IS NULL
            ")->execute([$jcId]);
        } catch (PDOException $e) {
            error_log("Job card auto-pending error: " . $e->getMessage());
        }
    }
    
    $stmt = $conn->query("
        SELECT q.*, c.full_name as customer_name, c.telephone, c.email
        FROM quotations q
        LEFT JOIN customers c ON q.customer_id = c.id
        ORDER BY q.created_at DESC
    ");
    $quotations = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
} catch(PDOException $e) {
    $error_message = "Database error: " . $e->getMessage();
    // Mock data for testing if the query fails
    $quotations = [
        [
            'id' => 1,
            'quotation_number' => 'QUO-2024-001',
            'customer_name' => 'John Doe',
            'quotation_date' => '2024-01-15',
            'total_amount' => 1250000,
            'status' => 'sent'
        ],
        [
            'id' => 2,
            'quotation_number' => 'QUO-2024-002',
            'customer_name' => 'Jane Smith',
            'quotation_date' => '2024-01-20',
            'total_amount' => 875000,
            'status' => 'approved'
        ],
        [
            'id' => 3,
            'quotation_number' => 'QUO-2024-003',
            'customer_name' => 'Mike Johnson',
            'quotation_date' => '2024-01-22',
            'total_amount' => 2100000,
            'status' => 'draft'
        ]
    ];
}

// Handle delete request
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_quotation'])) {
    try {
        if (isset($conn)) {
            $stmt = $conn->prepare("DELETE FROM quotations WHERE id = ?");
            $stmt->execute([$_POST['quotation_id']]);
            $_SESSION['success'] = "Quotation deleted successfully!";
        } else {
            $_SESSION['error'] = "Database connection not available";
        }
        header('Location: quotations.php');
        exit();
    } catch(PDOException $e) {
        $_SESSION['error'] = "Error deleting quotation: " . $e->getMessage();
        header('Location: quotations.php');
        exit();
    }
}

// Handle status update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_status'])) {
    try {
        if (isset($conn)) {
            $new_status = $_POST['status'];
            $quotation_id = (int)$_POST['quotation_id'];

            $stmt = $conn->prepare("UPDATE quotations SET status = ? WHERE id = ?");
            $stmt->execute([$new_status, $quotation_id]);

            // === AUTO-UPDATE LINKED JOB CARD STATUS ===
            // When quotation is approved or invoiced → job card becomes in_progress
            if (in_array($new_status, ['approved', 'invoiced'])) {
                $conn->prepare("
                    UPDATE job_cards jc
                    INNER JOIN quotations q ON q.job_card_id = jc.id
                    SET jc.status = 'in_progress'
                    WHERE q.id = ? AND jc.deleted_at IS NULL
                ")->execute([$quotation_id]);
            }

            $_SESSION['success'] = "Status updated successfully!";
        } else {
            $_SESSION['error'] = "Database connection not available";
        }
        header('Location: quotations.php');
        exit();
    } catch(PDOException $e) {
        $_SESSION['error'] = "Error updating status: " . $e->getMessage();
        header('Location: quotations.php');
        exit();
    }
}

// Handle conversion to invoice (redirect to invoices.php with conversion)
if (isset($_GET['convert_quotation'])) {
    $quotation_id = (int)$_GET['convert_quotation'];

    // === AUTO-UPDATE LINKED JOB CARD TO in_progress WHEN CONVERTING ===
    if (isset($conn)) {
        try {
            $conn->prepare("
                UPDATE job_cards jc
                INNER JOIN quotations q ON q.job_card_id = jc.id
                SET jc.status = 'in_progress'
                WHERE q.id = ? AND jc.deleted_at IS NULL
            ")->execute([$quotation_id]);
        } catch (PDOException $e) {
            // Non-fatal: log and continue
            error_log("Job card status sync error (convert): " . $e->getMessage());
        }
    }

    header("Location: invoices.php?convert_quotation=" . $quotation_id);
    exit();
}

$success_message = $_SESSION['success'] ?? null;
$error_message = $_SESSION['error'] ?? $error_message ?? null;
unset($_SESSION['success'], $_SESSION['error']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <title>Quotations | SAVANT MOTORS</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        /* ---------- RESET & GLOBAL ---------- */
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Calibri', 'Segoe UI', 'Candara', 'Helvetica Neue', sans-serif;
            background: linear-gradient(145deg, #f0f4fc 0%, #e2eaf5 100%);
            padding: 2rem;
            position: relative;
            min-height: 100vh;
        }

        /* modern watermark */
        .watermark {
            position: fixed;
            bottom: 30px;
            right: 30px;
            opacity: 0.08;
            pointer-events: none;
            z-index: 1000;
            font-size: 64px;
            font-weight: 800;
            color: #0b2b5c;
            letter-spacing: 4px;
            transform: rotate(-12deg);
            white-space: nowrap;
            font-family: inherit;
        }

        @media print {
            body {
                background: white;
                padding: 0;
                margin: 0;
            }
            .watermark {
                opacity: 0.05;
                print-color-adjust: exact;
                -webkit-print-color-adjust: exact;
            }
            .toolbar, .btn-actions, .quick-action-card {
                display: none;
            }
            .container {
                box-shadow: none;
                border-radius: 0;
                margin: 0;
            }
        }

        /* main container – glassmorphic white card */
        .container {
            max-width: 1400px;
            margin: 0 auto;
            background: rgba(255, 255, 255, 0.96);
            backdrop-filter: blur(0px);
            border-radius: 32px;
            box-shadow: 0 20px 40px -12px rgba(0, 0, 0, 0.12), 0 4px 12px rgba(0, 0, 0, 0.05);
            overflow: hidden;
            transition: all 0.2s ease;
        }

        /* toolbar – modern, minimal, elevated */
        .toolbar {
            background: #ffffff;
            padding: 0.9rem 2rem;
            display: flex;
            gap: 1rem;
            flex-wrap: wrap;
            align-items: center;
            border-bottom: 1px solid #eef2f8;
            box-shadow: 0 1px 2px rgba(0,0,0,0.02);
        }

        .toolbar button, .toolbar a {
            background: #f3f6fc;
            border: none;
            color: #1a4c8c;
            padding: 0.5rem 1.2rem;
            border-radius: 40px;
            font-weight: 600;
            font-size: 0.85rem;
            cursor: pointer;
            transition: all 0.2s;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            font-family: inherit;
            letter-spacing: 0.3px;
        }

        .toolbar button:hover, .toolbar a:hover {
            background: #e6edf8;
            transform: translateY(-1px);
            color: #0a3a6e;
        }

        .toolbar .print-btn {
            background: #1f5e3a;
            color: white;
        }
        .toolbar .print-btn:hover {
            background: #154d2e;
        }

        /* main content area */
        .quote-content {
            padding: 2rem 2rem 1.5rem 2rem;
        }

        /* header wrapper with logo, company name, and address */
        .header-wrapper {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 1.5rem;
            flex-wrap: wrap;
            gap: 1rem;
        }
        .logo {
            flex-shrink: 0;
            width: 110px;
        }
        .logo img {
            max-width: 100px;
            height: auto;
            filter: drop-shadow(0 2px 4px rgba(0,0,0,0.05));
        }
        .company-info {
            flex-grow: 1;
            text-align: center;
        }
        .company-info h1 {
            font-size: 2rem;
            font-weight: 700;
            color: #0b2b5c;
            letter-spacing: -0.3px;
            background: linear-gradient(135deg, #1e4a7a, #0f3460);
            background-clip: text;
            -webkit-background-clip: text;
            color: transparent;
            text-shadow: none;
            margin-bottom: 0.5rem;
        }
        .company-address {
            font-size: 0.85rem;
            color: #4a627a;
            line-height: 1.4;
            margin-top: 0.25rem;
        }
        .company-contact {
            font-size: 0.8rem;
            color: #5e6f8d;
            margin-top: 0.25rem;
        }
        .company-contact i {
            margin-right: 4px;
            color: #1f6eae;
        }

        /* subtle spacer for symmetry */
        .spacer {
            width: 100px;
            visibility: hidden;
        }

        /* modern alert banners */
        .alert {
            padding: 1rem 1.5rem;
            border-radius: 24px;
            margin-bottom: 1.8rem;
            display: flex;
            align-items: center;
            gap: 12px;
            font-weight: 500;
            backdrop-filter: blur(2px);
        }
        .alert-success {
            background: #e3f7ec;
            color: #146b3a;
            border-left: 5px solid #2b8c4a;
        }
        .alert-danger {
            background: #ffe8e8;
            color: #b13e3e;
            border-left: 5px solid #e45c5c;
        }

        /* section header + new quotation button */
        .section-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin: 1.5rem 0 1.2rem 0;
            flex-wrap: wrap;
            gap: 1rem;
        }
        .section-title {
            font-size: 1.55rem;
            font-weight: 700;
            color: #102a4c;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .quick-action-card {
            background: linear-gradient(105deg, #ffffff 0%, #fbfdff 100%);
            border-radius: 56px;
            padding: 0.55rem 1.4rem 0.55rem 1rem;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 12px;
            font-weight: 600;
            color: #1a6eb0;
            transition: all 0.25s;
            border: 1px solid #dce5f0;
            box-shadow: 0 1px 2px rgba(0,0,0,0.02);
        }
        .quick-action-card:hover {
            transform: translateY(-3px);
            box-shadow: 0 12px 22px -12px rgba(26, 110, 176, 0.25);
            border-color: #bdd4f0;
            background: white;
        }
        .quick-action-icon {
            width: 38px;
            height: 38px;
            background: linear-gradient(145deg, #1e6fae, #135a8f);
            border-radius: 40px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
        }
        .quick-action-icon i {
            font-size: 1.2rem;
            color: white;
        }

        /* modern table wrapper */
        .table-wrapper {
            overflow-x: auto;
            border-radius: 24px;
            margin-top: 0.5rem;
        }
        .quotations-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 0.9rem;
        }
        .quotations-table th {
            text-align: left;
            padding: 1rem 1rem;
            background: #f9fbfe;
            font-weight: 700;
            color: #2c4b74;
            font-size: 0.8rem;
            letter-spacing: 0.5px;
            text-transform: uppercase;
            border-bottom: 1px solid #e2edf7;
        }
        .quotations-table td {
            padding: 1rem 1rem;
            border-bottom: 1px solid #ecf3fa;
            vertical-align: middle;
            color: #1f2f44;
            font-weight: 500;
        }
        .quotations-table tr:hover td {
            background-color: #fafcff;
        }

        /* status badges – fresh and modern */
        .status-badge {
            display: inline-block;
            padding: 0.25rem 0.9rem;
            border-radius: 40px;
            font-size: 0.7rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.3px;
            background: #f0f4fa;
            color: #2c4b74;
        }
        .status-badge.draft { background: #eef2f6; color: #5e6f8d; }
        .status-badge.sent { background: #e1edfc; color: #1a5d9c; }
        .status-badge.approved { background: #dff0e3; color: #1f7840; }
        .status-badge.rejected { background: #ffe3e3; color: #bc4747; }
        .status-badge.invoiced { background: #ede7fc; color: #634d9e; }

        /* action button group */
        .action-buttons {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
        }
        .btn-icon {
            background: #f2f5f9;
            color: #2c5a87;
            border: none;
            padding: 6px 12px;
            border-radius: 32px;
            cursor: pointer;
            font-size: 0.75rem;
            font-weight: 600;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            text-decoration: none;
            transition: all 0.2s;
        }
        .btn-icon:hover {
            background: #e5ecf5;
            transform: translateY(-1px);
        }
        .btn-primary-icon {
            background: #eef4ff;
            color: #1f6392;
        }
        .btn-primary-icon:hover {
            background: #dceaff;
        }
        .btn-danger-icon {
            background: #ffe9e9;
            color: #c23b3b;
        }
        .btn-danger-icon:hover {
            background: #ffdddd;
        }

        /* status update inline form */
        .status-update {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            flex-wrap: wrap;
        }
        .status-select {
            padding: 0.4rem 1rem;
            border-radius: 40px;
            border: 1px solid #cfddee;
            background: white;
            font-size: 0.75rem;
            font-weight: 500;
            color: #1e3a5f;
            font-family: inherit;
            cursor: pointer;
            transition: 0.2s;
        }
        .status-select:focus {
            outline: none;
            border-color: #4f9fcf;
            box-shadow: 0 0 0 2px rgba(79, 159, 207, 0.2);
        }
        .update-btn {
            background: #2f7d5c;
            color: white;
            border: none;
            padding: 0.4rem 1rem;
            border-radius: 32px;
            cursor: pointer;
            font-size: 0.7rem;
            font-weight: 600;
            transition: all 0.2s;
        }
        .update-btn:hover {
            background: #236a4c;
            transform: scale(0.98);
        }

        /* empty state */
        .empty-state {
            text-align: center;
            padding: 4rem 2rem;
            color: #7b8ba3;
        }
        .empty-state i {
            font-size: 4rem;
            margin-bottom: 1rem;
            opacity: 0.5;
            color: #8aa0bc;
        }
        .empty-state h3 {
            font-size: 1.3rem;
            font-weight: 600;
            margin-bottom: 0.5rem;
        }

        /* footer */
        footer {
            text-align: center;
            padding: 1rem 2rem;
            font-size: 0.7rem;
            color: #7890aa;
            background: #fbfdff;
            border-top: 1px solid #eef2f8;
            letter-spacing: 0.3px;
        }

        /* responsive */
        @media (max-width: 780px) {
            body {
                padding: 1rem;
            }
            .quote-content {
                padding: 1.2rem;
            }
            .section-title {
                font-size: 1.3rem;
            }
            .quotations-table th, .quotations-table td {
                padding: 0.75rem 0.8rem;
            }
            .action-buttons {
                flex-direction: column;
                gap: 6px;
            }
            .status-update {
                flex-direction: column;
                align-items: flex-start;
            }
            .company-info h1 {
                font-size: 1.5rem;
            }
            .company-address {
                font-size: 0.7rem;
            }
        }
    </style>
</head>
<body>
    <div class="watermark">SAVANT MOTORS</div>

    <div class="container">
        <div class="toolbar">
            <button class="print-btn" onclick="window.print()"><i class="fas fa-print"></i> Print</button>
            <a href="dashboard_erp.php"><i class="fas fa-chart-line"></i> Dashboard</a>
        </div>

        <div class="quote-content">
            <div class="header-wrapper">
                <div class="logo">
                </div>
                <div class="company-info">
                    <h1>SAVANT MOTORS UGANDA</h1>
                    <div class="company-address">
                        <i class="fas fa-map-marker-alt"></i> Bugolobi, Bunyonyi Drive, Kampala, Uganda
                    </div>
                    <div class="company-contact">
                        <i class="fas fa-phone-alt"></i> +256 774 537 017 &nbsp;|&nbsp; 
                        <i class="fas fa-phone-alt"></i> +256 704 496 974 &nbsp;|&nbsp;
                        <i class="fas fa-envelope"></i> rogersm2008@gmail.com
                    </div>
                </div>
                <div class="spacer"></div>
            </div>
            
            <?php if ($success_message): ?>
            <div class="alert alert-success"><i class="fas fa-check-circle"></i> <?php echo htmlspecialchars($success_message); ?></div>
            <?php endif; ?>
            <?php if ($error_message): ?>
            <div class="alert alert-danger"><i class="fas fa-exclamation-triangle"></i> <?php echo htmlspecialchars($error_message); ?></div>
            <?php endif; ?>

            <div class="section-header">
                <div class="section-title"><i class="fas fa-file-invoice" style="color: #1f6eae;"></i> All Quotations</div>
                <a href="new_quotation.php" class="quick-action-card">
                    <div class="quick-action-icon"><i class="fas fa-plus-circle"></i></div>
                    <span>New Quotation</span>
                </a>
            </div>

            <?php if (empty($quotations)): ?>
                <div class="empty-state">
                    <i class="fas fa-file-invoice"></i>
                    <h3>No Quotations Found</h3>
                    <p>Click "New Quotation" to create your first quotation.</p>
                </div>
            <?php else: ?>
                <div class="table-wrapper">
                    <table class="quotations-table">
                        <thead>
                            <tr>
                                <th>Quote No.</th>
                                <th>Customer</th>
                                <th>Date</th>
                                <th>Total (UGX)</th>
                                <th>Status</th>
                                <th>Actions</th>
                            </thead>
                        <tbody>
                            <?php foreach ($quotations as $quote): ?>
                             <tr>
                                <td><strong><?php echo htmlspecialchars($quote['quotation_number']); ?></strong></td>
                                <td><?php echo htmlspecialchars($quote['customer_name']); ?></td>
                                <td><?php echo date('d/m/Y', strtotime($quote['quotation_date'])); ?></td>
                                <td><?php echo number_format($quote['total_amount'], 0); ?></td>
                                <td>
                                    <form method="POST" class="status-update" style="display:inline-flex; gap:5px;">
                                        <input type="hidden" name="quotation_id" value="<?php echo $quote['id']; ?>">
                                        <select name="status" class="status-select">
                                            <option value="draft" <?php echo $quote['status'] == 'draft' ? 'selected' : ''; ?>>Draft</option>
                                            <option value="sent" <?php echo $quote['status'] == 'sent' ? 'selected' : ''; ?>>Sent</option>
                                            <option value="approved" <?php echo $quote['status'] == 'approved' ? 'selected' : ''; ?>>Approved</option>
                                            <option value="rejected" <?php echo $quote['status'] == 'rejected' ? 'selected' : ''; ?>>Rejected</option>
                                            <option value="invoiced" <?php echo $quote['status'] == 'invoiced' ? 'selected' : ''; ?>>Invoiced</option>
                                        </select>
                                        <button type="submit" name="update_status" class="update-btn">Update</button>
                                    </form>
                                </td>
                                <td class="action-buttons">
                                    <a href="view_quotation.php?id=<?php echo $quote['id']; ?>" class="btn-icon">
                                        <i class="fas fa-eye"></i> View
                                    </a>
                                    <a href="print_quotation.php?id=<?php echo $quote['id']; ?>" class="btn-icon" target="_blank">
                                        <i class="fas fa-print"></i> Print
                                    </a>
                                    <a href="edit_quotation.php?id=<?php echo $quote['id']; ?>" class="btn-icon">
                                        <i class="fas fa-edit"></i> Edit
                                    </a>
                                    <?php if ($quote['status'] === 'approved' && $quote['status'] != 'invoiced'): ?>
                                        <a href="quotations.php?convert_quotation=<?php echo $quote['id']; ?>" class="btn-icon btn-primary-icon">
                                            <i class="fas fa-exchange-alt"></i> Convert
                                        </a>
                                    <?php endif; ?>
                                    <form method="POST" style="display:inline;">
                                        <input type="hidden" name="quotation_id" value="<?php echo $quote['id']; ?>">
                                        <button type="submit" name="delete_quotation" class="btn-icon btn-danger-icon" onclick="return confirm('Delete this quotation? This action cannot be undone.')">
                                            <i class="fas fa-trash"></i> Delete
                                        </button>
                                    </form>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
        <footer>
            "Testify" – Generated by Savant Motors ERP
        </footer>
    </div>
</body>
</html>