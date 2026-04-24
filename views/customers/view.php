<?php
// customers/view.php - View Customer Details
session_start();

if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    header('Location: index.php');
    exit();
}

$user_full_name = $_SESSION['full_name'] ?? 'User';
$user_role = $_SESSION['role'] ?? 'user';

// Check if customer ID is provided
if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    $_SESSION['error'] = "Invalid customer ID";
    header('Location: index.php');
    exit();
}

$customer_id = (int)$_GET['id'];

try {
    $conn = new PDO("mysql:host=localhost;dbname=savant_motors_pos", "root", "");
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    // Fetch customer details with all related data
    $stmt = $conn->prepare("
        SELECT 
            c.*,
            u.full_name as sales_rep_name,
            COALESCE(l.loyalty_points, 0) as loyalty_points,
            COALESCE(l.total_spent, 0) as total_spent,
            COALESCE(l.total_visits, 0) as total_visits,
            l.last_visit_date,
            (SELECT COUNT(*) FROM customer_interactions WHERE customer_id = c.id) as total_interactions,
            (SELECT COUNT(*) FROM job_cards WHERE customer_id = c.id) as total_jobs,
            (SELECT COUNT(*) FROM job_cards WHERE customer_id = c.id AND status = 'pending') as pending_jobs,
            (SELECT COUNT(*) FROM job_cards WHERE customer_id = c.id AND status = 'completed') as completed_jobs,
            (SELECT AVG(rating) FROM customer_feedback WHERE customer_id = c.id) as avg_rating
        FROM customers c
        LEFT JOIN users u ON c.assigned_sales_rep = u.id
        LEFT JOIN customer_loyalty l ON c.id = l.customer_id
        WHERE c.id = :id
    ");
    $stmt->execute([':id' => $customer_id]);
    $customer = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // Check if customer exists
    if (!$customer) {
        $_SESSION['error'] = "Customer not found";
        header('Location: index.php');
        exit();
    }
    
    // Fetch customer interactions
    $interactionStmt = $conn->prepare("
        SELECT 
            ci.*,
            u.full_name as performed_by_name
        FROM customer_interactions ci
        LEFT JOIN users u ON ci.performed_by = u.id
        WHERE ci.customer_id = :id
        ORDER BY ci.interaction_date DESC
        LIMIT 10
    ");
    $interactionStmt->execute([':id' => $customer_id]);
    $interactions = $interactionStmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Fetch customer job cards
    $jobStmt = $conn->prepare("
        SELECT 
            jc.*,
            (SELECT AVG(rating) FROM customer_feedback WHERE job_card_id = jc.id) as job_rating
        FROM job_cards jc
        WHERE jc.customer_id = :id
        ORDER BY jc.created_at DESC
        LIMIT 10
    ");
    $jobStmt->execute([':id' => $customer_id]);
    $jobCards = $jobStmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Fetch customer feedback
    $feedbackStmt = $conn->prepare("
        SELECT 
            cf.*,
            jc.job_number
        FROM customer_feedback cf
        LEFT JOIN job_cards jc ON cf.job_card_id = jc.id
        WHERE cf.customer_id = :id
        ORDER BY cf.feedback_date DESC
        LIMIT 5
    ");
    $feedbackStmt->execute([':id' => $customer_id]);
    $feedbacks = $feedbackStmt->fetchAll(PDO::FETCH_ASSOC);
    
} catch(PDOException $e) {
    error_log("View Customer Error: " . $e->getMessage());
    $_SESSION['error'] = "Database error: " . $e->getMessage();
    header('Location: index.php');
    exit();
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>View Customer | SAVANT MOTORS</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:opsz,wght@14..32,300;14..32,400;14..32,500;14..32,600;14..32,700;14..32,800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: 'Inter', sans-serif;
            background: #f5f7fb;
            padding: 2rem;
        }
        :root {
            --primary: #2563eb;
            --primary-dark: #1e40af;
            --success: #10b981;
            --danger: #ef4444;
            --warning: #f59e0b;
            --border: #e2e8f0;
            --gray: #64748b;
            --dark: #0f172a;
        }

        .container {
            max-width: 1200px;
            margin: 0 auto;
        }

        .header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 2rem;
            flex-wrap: wrap;
            gap: 1rem;
        }

        .header h1 {
            font-size: 1.8rem;
            font-weight: 700;
            color: var(--dark);
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .btn {
            padding: 0.5rem 1rem;
            border-radius: 0.5rem;
            font-weight: 600;
            font-size: 0.85rem;
            cursor: pointer;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            border: none;
            transition: all 0.2s;
        }

        .btn-primary {
            background: linear-gradient(135deg, var(--primary), var(--primary-dark));
            color: white;
        }

        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(37, 99, 235, 0.3);
        }

        .btn-secondary {
            background: white;
            border: 1px solid var(--border);
            color: var(--gray);
        }

        .btn-secondary:hover {
            background: #f8fafc;
        }

        .card {
            background: white;
            border-radius: 1rem;
            border: 1px solid var(--border);
            overflow: hidden;
            margin-bottom: 1.5rem;
        }

        .card-header {
            padding: 1rem 1.5rem;
            background: #f8fafc;
            border-bottom: 1px solid var(--border);
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 0.5rem;
        }

        .card-header h2 {
            font-size: 1.1rem;
            font-weight: 700;
            color: var(--dark);
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .card-body {
            padding: 1.5rem;
        }

        .info-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
            gap: 1rem;
        }

        .info-item {
            margin-bottom: 0.75rem;
        }

        .info-label {
            font-size: 0.7rem;
            font-weight: 700;
            color: var(--gray);
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 0.25rem;
        }

        .info-value {
            font-size: 0.9rem;
            font-weight: 500;
            color: var(--dark);
        }

        .tier-badge {
            display: inline-block;
            padding: 0.25rem 0.75rem;
            border-radius: 2rem;
            font-size: 0.75rem;
            font-weight: 700;
        }

        .tier-platinum { background: #e5deff; color: #5b21b6; }
        .tier-gold { background: #fed7aa; color: #9a3412; }
        .tier-silver { background: #e2e8f0; color: #1e293b; }
        .tier-bronze { background: #fed7aa; color: #92400e; }

        .status-badge {
            display: inline-block;
            padding: 0.25rem 0.75rem;
            border-radius: 2rem;
            font-size: 0.7rem;
            font-weight: 600;
        }

        .status-active { background: #dcfce7; color: #166534; }
        .status-inactive { background: #fee2e2; color: #991b1b; }

        table {
            width: 100%;
            border-collapse: collapse;
        }

        th, td {
            padding: 0.75rem;
            text-align: left;
            border-bottom: 1px solid var(--border);
        }

        th {
            font-size: 0.7rem;
            font-weight: 700;
            color: var(--gray);
            text-transform: uppercase;
            background: #f8fafc;
        }

        .stars {
            display: inline-flex;
            gap: 0.1rem;
        }

        .star-filled { color: #f59e0b; }
        .star-empty { color: #e2e8f0; }

        .empty-state {
            text-align: center;
            padding: 3rem;
            color: var(--gray);
        }

        .empty-state i {
            font-size: 3rem;
            margin-bottom: 1rem;
            opacity: 0.5;
        }

        @media (max-width: 768px) {
            body { padding: 1rem; }
            .info-grid { grid-template-columns: 1fr; }
            .card-header { flex-direction: column; align-items: flex-start; }
            table { display: block; overflow-x: auto; }
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>
                <i class="fas fa-user-circle" style="color: var(--primary);"></i>
                Customer Details
            </h1>
            <div style="display: flex; gap: 0.5rem;">
                <a href="edit.php?id=<?php echo $customer['id']; ?>" class="btn btn-primary">
                    <i class="fas fa-edit"></i> Edit Customer
                </a>
                <a href="index.php" class="btn btn-secondary">
                    <i class="fas fa-arrow-left"></i> Back to List
                </a>
            </div>
        </div>

        <!-- Basic Information -->
        <div class="card">
            <div class="card-header">
                <h2><i class="fas fa-info-circle"></i> Basic Information</h2>
                <span class="status-badge <?php echo ($customer['status'] ?? 0) == 1 ? 'status-active' : 'status-inactive'; ?>">
                    <?php echo ($customer['status'] ?? 0) == 1 ? 'Active' : 'Inactive'; ?>
                </span>
            </div>
            <div class="card-body">
                <div class="info-grid">
                    <div class="info-item">
                        <div class="info-label">Customer ID</div>
                        <div class="info-value">#<?php echo $customer['id']; ?></div>
                    </div>
                    <div class="info-item">
                        <div class="info-label">Full Name</div>
                        <div class="info-value"><strong><?php echo htmlspecialchars($customer['full_name'] ?? 'N/A'); ?></strong></div>
                    </div>
                    <div class="info-item">
                        <div class="info-label">Telephone</div>
                        <div class="info-value"><?php echo htmlspecialchars($customer['telephone'] ?? 'N/A'); ?></div>
                    </div>
                    <div class="info-item">
                        <div class="info-label">Email</div>
                        <div class="info-value"><?php echo htmlspecialchars($customer['email'] ?? 'N/A'); ?></div>
                    </div>
                    <div class="info-item">
                        <div class="info-label">Address</div>
                        <div class="info-value"><?php echo nl2br(htmlspecialchars($customer['address'] ?? 'N/A')); ?></div>
                    </div>
                    <div class="info-item">
                        <div class="info-label">Customer Tier</div>
                        <div class="info-value">
                            <span class="tier-badge tier-<?php echo $customer['customer_tier'] ?? 'bronze'; ?>">
                                <i class="fas fa-<?php echo ($customer['customer_tier'] ?? 'bronze') == 'platinum' ? 'crown' : 'star'; ?>"></i>
                                <?php echo ucfirst($customer['customer_tier'] ?? 'Bronze'); ?>
                            </span>
                        </div>
                    </div>
                    <div class="info-item">
                        <div class="info-label">Customer Source</div>
                        <div class="info-value"><?php echo htmlspecialchars($customer['customer_source'] ?? 'Direct'); ?></div>
                    </div>
                    <div class="info-item">
                        <div class="info-label">Sales Representative</div>
                        <div class="info-value"><?php echo htmlspecialchars($customer['sales_rep_name'] ?? 'Unassigned'); ?></div>
                    </div>
                    <div class="info-item">
                        <div class="info-label">Member Since</div>
                        <div class="info-value"><?php echo isset($customer['created_at']) ? date('d M Y', strtotime($customer['created_at'])) : 'N/A'; ?></div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Loyalty Statistics -->
        <div class="card">
            <div class="card-header">
                <h2><i class="fas fa-chart-line"></i> Loyalty Statistics</h2>
            </div>
            <div class="card-body">
                <div class="info-grid">
                    <div class="info-item">
                        <div class="info-label">Loyalty Points</div>
                        <div class="info-value"><strong><?php echo number_format($customer['loyalty_points'] ?? 0); ?></strong> points</div>
                    </div>
                    <div class="info-item">
                        <div class="info-label">Total Spent</div>
                        <div class="info-value">UGX <?php echo number_format($customer['total_spent'] ?? 0, 2); ?></div>
                    </div>
                    <div class="info-item">
                        <div class="info-label">Total Visits</div>
                        <div class="info-value"><?php echo number_format($customer['total_visits'] ?? 0); ?> visits</div>
                    </div>
                    <div class="info-item">
                        <div class="info-label">Last Visit</div>
                        <div class="info-value"><?php echo isset($customer['last_visit_date']) ? date('d M Y', strtotime($customer['last_visit_date'])) : 'N/A'; ?></div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Job Cards Summary -->
        <div class="card">
            <div class="card-header">
                <h2><i class="fas fa-clipboard-list"></i> Job Cards Summary</h2>
                <a href="../job_cards/create.php?customer_id=<?php echo $customer['id']; ?>" class="btn btn-primary" style="padding: 0.25rem 0.75rem;">
                    <i class="fas fa-plus"></i> New Job
                </a>
            </div>
            <div class="card-body">
                <div class="info-grid" style="grid-template-columns: repeat(4, 1fr);">
                    <div class="info-item">
                        <div class="info-label">Total Jobs</div>
                        <div class="info-value"><strong><?php echo number_format($customer['total_jobs'] ?? 0); ?></strong></div>
                    </div>
                    <div class="info-item">
                        <div class="info-label">Completed</div>
                        <div class="info-value" style="color: var(--success);"><?php echo number_format($customer['completed_jobs'] ?? 0); ?></div>
                    </div>
                    <div class="info-item">
                        <div class="info-label">Pending</div>
                        <div class="info-value" style="color: var(--warning);"><?php echo number_format($customer['pending_jobs'] ?? 0); ?></div>
                    </div>
                    <div class="info-item">
                        <div class="info-label">Avg Rating</div>
                        <div class="info-value">
                            <div class="stars">
                                <?php $avgRating = round($customer['avg_rating'] ?? 0); ?>
                                <?php for($i = 1; $i <= 5; $i++): ?>
                                    <i class="fas fa-star <?php echo $i <= $avgRating ? 'star-filled' : 'star-empty'; ?>"></i>
                                <?php endfor; ?>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Recent Interactions -->
        <div class="card">
            <div class="card-header">
                <h2><i class="fas fa-comments"></i> Recent Interactions</h2>
                <a href="add_interaction.php?id=<?php echo $customer['id']; ?>" class="btn btn-primary" style="padding: 0.25rem 0.75rem;">
                    <i class="fas fa-plus"></i> Add Interaction
                </a>
            </div>
            <div class="card-body">
                <?php if (empty($interactions)): ?>
                    <div class="empty-state">
                        <i class="fas fa-comments"></i>
                        <p>No interactions recorded yet</p>
                    </div>
                <?php else: ?>
                    <div style="overflow-x: auto;">
                        <table>
                            <thead>
                                <tr>
                                    <th>Date</th>
                                    <th>Type</th>
                                    <th>Summary</th>
                                    <th>Performed By</th>
                                    <th>Follow Up</th>
                                </thead>
                                <tbody>
                                    <?php foreach ($interactions as $interaction): ?>
                                    <tr>
                                        <td><?php echo date('d M Y', strtotime($interaction['interaction_date'])); ?></td>
                                        <td><?php echo ucfirst($interaction['interaction_type']); ?></td>
                                        <td><?php echo htmlspecialchars(substr($interaction['summary'] ?? '', 0, 50)); ?>...</td>
                                        <td><?php echo htmlspecialchars($interaction['performed_by_name'] ?? 'N/A'); ?></td>
                                        <td><?php echo isset($interaction['follow_up_date']) ? date('d M Y', strtotime($interaction['follow_up_date'])) : 'N/A'; ?></td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Recent Feedback -->
        <div class="card">
            <div class="card-header">
                <h2><i class="fas fa-star"></i> Customer Feedback</h2>
            </div>
            <div class="card-body">
                <?php if (empty($feedbacks)): ?>
                    <div class="empty-state">
                        <i class="fas fa-star"></i>
                        <p>No feedback received yet</p>
                    </div>
                <?php else: ?>
                    <?php foreach ($feedbacks as $feedback): ?>
                        <div style="padding: 0.75rem; border-bottom: 1px solid var(--border);">
                            <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 0.5rem;">
                                <div>
                                    <strong>Job #<?php echo htmlspecialchars($feedback['job_number'] ?? 'N/A'); ?></strong>
                                    <span style="margin-left: 0.5rem;">
                                        <?php for($i = 1; $i <= 5; $i++): ?>
                                            <i class="fas fa-star <?php echo $i <= round($feedback['rating'] ?? 0) ? 'star-filled' : 'star-empty'; ?>" style="font-size: 0.7rem;"></i>
                                        <?php endfor; ?>
                                    </span>
                                </div>
                                <span style="font-size: 0.7rem; color: var(--gray);"><?php echo isset($feedback['feedback_date']) ? date('d M Y', strtotime($feedback['feedback_date'])) : 'N/A'; ?></span>
                            </div>
                            <p style="margin-top: 0.5rem; font-size: 0.85rem;"><?php echo htmlspecialchars($feedback['feedback_text'] ?? ''); ?></p>
                            <?php if ($feedback['recommend'] ?? false): ?>
                                <span style="font-size: 0.7rem; color: var(--success);"><i class="fas fa-thumbs-up"></i> Would recommend</span>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>
</body>
</html>