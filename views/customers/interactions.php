<?php
// customers/interactions.php - Record Customer Interactions
session_start();

if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    header('Location: ../index.php');
    exit();
}

$user_full_name = $_SESSION['full_name'] ?? 'User';
$user_role = $_SESSION['role'] ?? 'user';
$user_id = $_SESSION['user_id'] ?? 1;

$customer_id = $_GET['customer_id'] ?? $_POST['customer_id'] ?? 0;
$error_message = null;
$success_message = null;

try {
    $conn = new PDO("mysql:host=localhost;dbname=savant_motors_pos", "root", "");
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    // Get customer details if customer_id is provided
    $customer = null;
    if ($customer_id) {
        $stmt = $conn->prepare("SELECT id, full_name, telephone, email FROM customers WHERE id = ?");
        $stmt->execute([$customer_id]);
        $customer = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$customer) {
            header('Location: index.php');
            exit();
        }
    }
    
    // Handle form submission
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_interaction'])) {
        
        $errors = [];
        
        if (empty($_POST['customer_id'])) {
            $errors[] = "Customer ID is required";
        }
        if (empty($_POST['interaction_date'])) {
            $errors[] = "Interaction date is required";
        }
        if (empty($_POST['interaction_type'])) {
            $errors[] = "Interaction type is required";
        }
        if (empty($_POST['summary'])) {
            $errors[] = "Summary is required";
        }
        
        if (empty($errors)) {
            try {
                $conn->beginTransaction();
                
                // Insert interaction
                $stmt = $conn->prepare("
                    INSERT INTO customer_interactions (
                        customer_id, interaction_date, interaction_type, 
                        summary, notes, follow_up_date, created_by, created_at
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, NOW())
                ");
                
                $stmt->execute([
                    $_POST['customer_id'],
                    $_POST['interaction_date'],
                    $_POST['interaction_type'],
                    $_POST['summary'],
                    $_POST['notes'] ?? null,
                    !empty($_POST['follow_up_date']) ? $_POST['follow_up_date'] : null,
                    $user_id
                ]);
                
                // Update customer's last_contact_date
                $updateStmt = $conn->prepare("
                    UPDATE customers SET 
                        last_contact_date = NOW(),
                        next_follow_up_date = ?
                    WHERE id = ?
                ");
                $updateStmt->execute([
                    !empty($_POST['follow_up_date']) ? $_POST['follow_up_date'] : null,
                    $_POST['customer_id']
                ]);
                
                $conn->commit();
                
                $_SESSION['success'] = "Interaction recorded successfully!";
                header("Location: view.php?id=" . $_POST['customer_id']);
                exit();
                
            } catch(PDOException $e) {
                $conn->rollBack();
                $error_message = "Database error: " . $e->getMessage();
            }
        } else {
            $error_message = implode("<br>", $errors);
        }
    }
    
    // Get recent interactions for this customer
    $recent_interactions = [];
    if ($customer_id) {
        $stmt = $conn->prepare("
            SELECT i.*, u.full_name as created_by_name
            FROM customer_interactions i
            LEFT JOIN users u ON i.created_by = u.id
            WHERE i.customer_id = ?
            ORDER BY i.interaction_date DESC
            LIMIT 10
        ");
        $stmt->execute([$customer_id]);
        $recent_interactions = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
} catch(PDOException $e) {
    error_log("CRM Error: " . $e->getMessage());
    $error_message = "Database error: " . $e->getMessage();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Record Interaction | <?php echo htmlspecialchars($customer['full_name'] ?? 'Customer'); ?></title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { 
            font-family: 'Inter', sans-serif; 
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            padding: 2rem;
        }

        :root {
            --primary: #2563eb;
            --primary-dark: #1e40af;
            --secondary: #7c3aed;
            --success: #10b981;
            --danger: #ef4444;
            --warning: #f59e0b;
            --info: #3b82f6;
            --dark: #0f172a;
            --gray: #64748b;
            --light: #f8fafc;
            --border: #e2e8f0;
        }

        .container {
            max-width: 1000px;
            margin: 0 auto;
        }

        /* Cards */
        .card {
            background: white;
            border-radius: 1.5rem;
            overflow: hidden;
            box-shadow: 0 20px 40px rgba(0,0,0,0.1);
            margin-bottom: 2rem;
        }

        .card-header {
            background: linear-gradient(135deg, var(--primary), var(--secondary));
            padding: 1.5rem 2rem;
            color: white;
        }

        .card-header h1 {
            font-size: 1.5rem;
            font-weight: 800;
            margin-bottom: 0.25rem;
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }

        .card-header p {
            opacity: 0.9;
            font-size: 0.85rem;
        }

        .card-body {
            padding: 2rem;
        }

        /* Customer Info Bar */
        .customer-info-bar {
            background: linear-gradient(135deg, #f8fafc, #ffffff);
            border: 1px solid var(--border);
            border-radius: 1rem;
            padding: 1rem 1.5rem;
            margin-bottom: 1.5rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 1rem;
        }

        .customer-details {
            display: flex;
            align-items: center;
            gap: 1rem;
        }

        .customer-avatar {
            width: 50px;
            height: 50px;
            background: linear-gradient(135deg, var(--primary), var(--secondary));
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: 700;
            font-size: 1.2rem;
        }

        .customer-name {
            font-size: 1.1rem;
            font-weight: 700;
            color: var(--dark);
        }

        .customer-contact {
            font-size: 0.75rem;
            color: var(--gray);
            margin-top: 0.25rem;
        }

        .customer-contact i {
            margin-right: 0.25rem;
        }

        /* Form Styles */
        .form-group {
            margin-bottom: 1.5rem;
        }

        .form-row {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 1.5rem;
            margin-bottom: 1.5rem;
        }

        label {
            display: block;
            font-size: 0.75rem;
            font-weight: 700;
            color: var(--gray);
            margin-bottom: 0.5rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        label .required {
            color: var(--danger);
            margin-left: 0.25rem;
        }

        input, select, textarea {
            width: 100%;
            padding: 0.75rem 1rem;
            border: 2px solid var(--border);
            border-radius: 0.75rem;
            font-size: 0.9rem;
            font-family: 'Inter', sans-serif;
            transition: all 0.3s;
        }

        input:focus, select:focus, textarea:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.1);
        }

        /* Interaction Type Cards */
        .interaction-types {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 1rem;
            margin-bottom: 1rem;
        }

        .type-option {
            cursor: pointer;
        }

        .type-option input {
            display: none;
        }

        .type-card {
            padding: 1rem;
            text-align: center;
            border: 2px solid var(--border);
            border-radius: 1rem;
            transition: all 0.3s;
            cursor: pointer;
        }

        .type-option input:checked + .type-card {
            border-color: var(--primary);
            background: linear-gradient(135deg, rgba(37, 99, 235, 0.05), rgba(124, 58, 237, 0.05));
            transform: translateY(-2px);
        }

        .type-card i {
            font-size: 1.5rem;
            margin-bottom: 0.5rem;
            display: block;
        }

        .type-card .type-name {
            font-weight: 600;
            font-size: 0.85rem;
        }

        /* Recent Interactions Table */
        .recent-interactions {
            margin-top: 1rem;
        }

        .interaction-item {
            display: flex;
            align-items: flex-start;
            gap: 1rem;
            padding: 1rem;
            border-bottom: 1px solid var(--border);
            transition: all 0.3s;
        }

        .interaction-item:hover {
            background: var(--light);
        }

        .interaction-icon {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1rem;
        }

        .interaction-icon.call { background: #dbeafe; color: #2563eb; }
        .interaction-icon.meeting { background: #dcfce7; color: #10b981; }
        .interaction-icon.email { background: #fef3c7; color: #f59e0b; }
        .interaction-icon.visit { background: #e0e7ff; color: #6366f1; }
        .interaction-icon.service { background: #fce7f3; color: #ec4899; }
        .interaction-icon.support { background: #ccfbf1; color: #14b8a6; }

        .interaction-content {
            flex: 1;
        }

        .interaction-title {
            font-weight: 700;
            color: var(--dark);
            margin-bottom: 0.25rem;
        }

        .interaction-meta {
            font-size: 0.7rem;
            color: var(--gray);
        }

        .interaction-notes {
            font-size: 0.8rem;
            color: var(--dark);
            margin-top: 0.5rem;
        }

        /* Buttons */
        .form-actions {
            display: flex;
            gap: 1rem;
            justify-content: flex-end;
            margin-top: 2rem;
            padding-top: 1rem;
            border-top: 1px solid var(--border);
        }

        .btn {
            padding: 0.75rem 1.5rem;
            border-radius: 0.75rem;
            font-weight: 600;
            font-size: 0.85rem;
            cursor: pointer;
            transition: all 0.3s;
            border: none;
            font-family: 'Inter', sans-serif;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
        }

        .btn-primary {
            background: linear-gradient(135deg, var(--primary), var(--secondary));
            color: white;
            box-shadow: 0 4px 12px rgba(37, 99, 235, 0.3);
        }

        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 20px rgba(37, 99, 235, 0.4);
        }

        .btn-secondary {
            background: #e2e8f0;
            color: var(--dark);
        }

        .btn-secondary:hover {
            background: #cbd5e1;
        }

        /* Alert */
        .alert {
            padding: 1rem;
            border-radius: 0.75rem;
            margin-bottom: 1.5rem;
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }

        .alert-success {
            background: #dcfce7;
            color: #166534;
            border-left: 4px solid var(--success);
        }

        .alert-error {
            background: #fee2e2;
            color: #991b1b;
            border-left: 4px solid var(--danger);
        }

        .empty-state {
            text-align: center;
            padding: 2rem;
            color: var(--gray);
        }

        /* Responsive */
        @media (max-width: 768px) {
            body {
                padding: 1rem;
            }
            
            .form-row {
                grid-template-columns: 1fr;
                gap: 1.5rem;
            }
            
            .interaction-types {
                grid-template-columns: repeat(2, 1fr);
            }
            
            .customer-info-bar {
                flex-direction: column;
                text-align: center;
            }
            
            .customer-details {
                flex-direction: column;
                text-align: center;
            }
            
            .form-actions {
                flex-direction: column;
            }
            
            .btn {
                justify-content: center;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <!-- Main Form Card -->
        <div class="card">
            <div class="card-header">
                <h1>
                    <i class="fas fa-comments"></i>
                    Record Customer Interaction
                </h1>
                <p>Log calls, meetings, emails, and other customer interactions</p>
            </div>
            <div class="card-body">
                <?php if ($error_message): ?>
                <div class="alert alert-error">
                    <i class="fas fa-exclamation-circle"></i>
                    <?php echo $error_message; ?>
                </div>
                <?php endif; ?>
                
                <?php if (isset($_SESSION['error'])): ?>
                <div class="alert alert-error">
                    <i class="fas fa-exclamation-circle"></i>
                    <?php echo $_SESSION['error']; unset($_SESSION['error']); ?>
                </div>
                <?php endif; ?>
                
                <?php if ($customer): ?>
                <!-- Customer Information Bar -->
                <div class="customer-info-bar">
                    <div class="customer-details">
                        <div class="customer-avatar">
                            <?php echo strtoupper(substr($customer['full_name'], 0, 2)); ?>
                        </div>
                        <div>
                            <div class="customer-name"><?php echo htmlspecialchars($customer['full_name']); ?></div>
                            <div class="customer-contact">
                                <i class="fas fa-phone"></i> <?php echo htmlspecialchars($customer['telephone'] ?? 'N/A'); ?>
                                <?php if ($customer['email']): ?>
                                <span style="margin: 0 0.5rem">•</span>
                                <i class="fas fa-envelope"></i> <?php echo htmlspecialchars($customer['email']); ?>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                    <a href="view.php?id=<?php echo $customer_id; ?>" class="btn btn-secondary">
                        <i class="fas fa-arrow-left"></i> Back to Profile
                    </a>
                </div>
                <?php endif; ?>
                
                <form method="POST" action="" id="interactionForm">
                    <input type="hidden" name="customer_id" value="<?php echo $customer_id; ?>">
                    
                    <!-- Interaction Date -->
                    <div class="form-row">
                        <div class="form-group">
                            <label>Interaction Date <span class="required">*</span></label>
                            <input type="date" name="interaction_date" value="<?php echo date('Y-m-d'); ?>" required>
                        </div>
                        <div class="form-group">
                            <label>Follow-up Date</label>
                            <input type="date" name="follow_up_date" placeholder="When to follow up">
                            <small style="font-size: 0.7rem; color: var(--gray);">Leave blank if no follow-up needed</small>
                        </div>
                    </div>
                    
                    <!-- Interaction Type -->
                    <div class="form-group">
                        <label>Interaction Type <span class="required">*</span></label>
                        <div class="interaction-types">
                            <label class="type-option">
                                <input type="radio" name="interaction_type" value="call" required>
                                <div class="type-card">
                                    <i class="fas fa-phone-alt"></i>
                                    <div class="type-name">Phone Call</div>
                                </div>
                            </label>
                            <label class="type-option">
                                <input type="radio" name="interaction_type" value="meeting">
                                <div class="type-card">
                                    <i class="fas fa-handshake"></i>
                                    <div class="type-name">Meeting</div>
                                </div>
                            </label>
                            <label class="type-option">
                                <input type="radio" name="interaction_type" value="email">
                                <div class="type-card">
                                    <i class="fas fa-envelope"></i>
                                    <div class="type-name">Email</div>
                                </div>
                            </label>
                            <label class="type-option">
                                <input type="radio" name="interaction_type" value="visit">
                                <div class="type-card">
                                    <i class="fas fa-building"></i>
                                    <div class="type-name">Visit</div>
                                </div>
                            </label>
                            <label class="type-option">
                                <input type="radio" name="interaction_type" value="service">
                                <div class="type-card">
                                    <i class="fas fa-wrench"></i>
                                    <div class="type-name">Service</div>
                                </div>
                            </label>
                            <label class="type-option">
                                <input type="radio" name="interaction_type" value="support">
                                <div class="type-card">
                                    <i class="fas fa-headset"></i>
                                    <div class="type-name">Support</div>
                                </div>
                            </label>
                            <label class="type-option">
                                <input type="radio" name="interaction_type" value="complaint">
                                <div class="type-card">
                                    <i class="fas fa-exclamation-triangle"></i>
                                    <div class="type-name">Complaint</div>
                                </div>
                            </label>
                            <label class="type-option">
                                <input type="radio" name="interaction_type" value="follow_up">
                                <div class="type-card">
                                    <i class="fas fa-calendar-check"></i>
                                    <div class="type-name">Follow-up</div>
                                </div>
                            </label>
                        </div>
                    </div>
                    
                    <!-- Summary -->
                    <div class="form-group">
                        <label>Summary <span class="required">*</span></label>
                        <input type="text" name="summary" required placeholder="Brief summary of the interaction">
                    </div>
                    
                    <!-- Detailed Notes -->
                    <div class="form-group">
                        <label>Detailed Notes</label>
                        <textarea name="notes" rows="4" placeholder="Enter detailed notes about the conversation, decisions made, action items, etc."></textarea>
                    </div>
                    
                    <!-- Form Actions -->
                    <div class="form-actions">
                        <?php if ($customer_id): ?>
                        <a href="view.php?id=<?php echo $customer_id; ?>" class="btn btn-secondary">
                            <i class="fas fa-times"></i> Cancel
                        </a>
                        <?php else: ?>
                        <a href="index.php" class="btn btn-secondary">
                            <i class="fas fa-times"></i> Cancel
                        </a>
                        <?php endif; ?>
                        <button type="submit" name="add_interaction" class="btn btn-primary" id="submitBtn">
                            <i class="fas fa-save"></i> Save Interaction
                        </button>
                    </div>
                </form>
            </div>
        </div>
        
        <!-- Recent Interactions Section -->
        <?php if (!empty($recent_interactions)): ?>
        <div class="card">
            <div class="card-header">
                <h2 style="font-size: 1.25rem; margin: 0;">
                    <i class="fas fa-history"></i>
                    Recent Interactions
                </h2>
            </div>
            <div class="card-body">
                <div class="recent-interactions">
                    <?php foreach ($recent_interactions as $interaction): ?>
                    <div class="interaction-item">
                        <div class="interaction-icon <?php echo $interaction['interaction_type']; ?>">
                            <i class="fas fa-<?php 
                                echo $interaction['interaction_type'] == 'call' ? 'phone-alt' : 
                                    ($interaction['interaction_type'] == 'meeting' ? 'handshake' : 
                                    ($interaction['interaction_type'] == 'email' ? 'envelope' : 
                                    ($interaction['interaction_type'] == 'visit' ? 'building' : 
                                    ($interaction['interaction_type'] == 'service' ? 'wrench' : 
                                    ($interaction['interaction_type'] == 'support' ? 'headset' : 
                                    ($interaction['interaction_type'] == 'complaint' ? 'exclamation-triangle' : 'calendar-check')))))); 
                            ?>"></i>
                        </div>
                        <div class="interaction-content">
                            <div class="interaction-title">
                                <?php echo ucfirst($interaction['interaction_type']); ?>
                                <?php if ($interaction['follow_up_date']): ?>
                                <span class="interaction-meta" style="margin-left: 0.5rem;">
                                    <i class="fas fa-calendar"></i> Follow-up: <?php echo date('d M Y', strtotime($interaction['follow_up_date'])); ?>
                                </span>
                                <?php endif; ?>
                            </div>
                            <div class="interaction-meta">
                                <i class="fas fa-calendar-alt"></i> <?php echo date('d M Y', strtotime($interaction['interaction_date'])); ?>
                                <span style="margin: 0 0.5rem">•</span>
                                <i class="fas fa-user"></i> <?php echo htmlspecialchars($interaction['created_by_name'] ?? 'System'); ?>
                            </div>
                            <div class="interaction-title" style="font-size: 0.85rem; margin-top: 0.25rem;">
                                <?php echo htmlspecialchars($interaction['summary']); ?>
                            </div>
                            <?php if ($interaction['notes']): ?>
                            <div class="interaction-notes">
                                <?php echo nl2br(htmlspecialchars(substr($interaction['notes'], 0, 100))); ?>
                                <?php if (strlen($interaction['notes']) > 100): ?>...<?php endif; ?>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
        <?php endif; ?>
    </div>
    
    <script>
        const form = document.getElementById('interactionForm');
        const submitBtn = document.getElementById('submitBtn');
        
        // Set today's date as default for follow-up if not set
        const followUpDate = document.querySelector('input[name="follow_up_date"]');
        if (followUpDate && !followUpDate.value) {
            const tomorrow = new Date();
            tomorrow.setDate(tomorrow.getDate() + 7);
            followUpDate.value = tomorrow.toISOString().split('T')[0];
        }
        
        // Form validation
        form.addEventListener('submit', function(e) {
            const interactionType = document.querySelector('input[name="interaction_type"]:checked');
            if (!interactionType) {
                e.preventDefault();
                alert('Please select an interaction type');
                return false;
            }
            
            const summary = document.querySelector('input[name="summary"]');
            if (!summary.value.trim()) {
                e.preventDefault();
                alert('Please enter a summary');
                summary.focus();
                return false;
            }
            
            // Show loading state
            if (submitBtn) {
                submitBtn.disabled = true;
                submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Saving...';
            }
        });
        
        // Auto-expand textarea
        const textarea = document.querySelector('textarea[name="notes"]');
        if (textarea) {
            textarea.addEventListener('input', function() {
                this.style.height = 'auto';
                this.style.height = (this.scrollHeight) + 'px';
            });
        }
    </script>
</body>
</html>