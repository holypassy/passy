<?php
// sync_debtors.php
session_start();
header('Content-Type: application/json');

if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit();
}

if (!isset($_GET['action']) || $_GET['action'] !== 'sync_invoices') {
    echo json_encode(['success' => false, 'error' => 'Invalid action']);
    exit();
}

try {
    $conn = new PDO("mysql:host=localhost;dbname=savant_motors_pos", "root", "");
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    // Get all unpaid or partially paid invoices
    $stmt = $conn->prepare("
        SELECT i.*, c.full_name, c.telephone, c.email 
        FROM invoices i
        LEFT JOIN customers c ON i.customer_id = c.id
        WHERE i.payment_status IN ('unpaid', 'partial')
    ");
    $stmt->execute();
    $invoices = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $synced = 0;
    
    foreach ($invoices as $invoice) {
        $balance = $invoice['total_amount'] - $invoice['amount_paid'];
        
        // Check if debtor record exists
        $checkStmt = $conn->prepare("
            SELECT id FROM debtors 
            WHERE reference_type = 'invoice' AND reference_id = ?
        ");
        $checkStmt->execute([$invoice['id']]);
        
        if ($checkStmt->fetch()) {
            // Update existing
            $status = $balance <= 0 ? 'settled' : ($invoice['amount_paid'] > 0 ? 'partial' : 'open');
            $updateStmt = $conn->prepare("
                UPDATE debtors 
                SET amount_owed = ?, amount_paid = ?, balance = ?, status = ?
                WHERE reference_type = 'invoice' AND reference_id = ?
            ");
            $updateStmt->execute([
                $invoice['total_amount'],
                $invoice['amount_paid'],
                $balance,
                $status,
                $invoice['id']
            ]);
        } else {
            // Create new
            $status = $balance <= 0 ? 'settled' : ($invoice['amount_paid'] > 0 ? 'partial' : 'open');
            $insertStmt = $conn->prepare("
                INSERT INTO debtors (
                    customer_id, customer_name, reference_type, reference_id, 
                    reference_no, amount_owed, amount_paid, balance, status, created_at
                ) VALUES (?, ?, 'invoice', ?, ?, ?, ?, ?, ?, NOW())
            ");
            $insertStmt->execute([
                $invoice['customer_id'],
                $invoice['customer_name'],
                $invoice['id'],
                $invoice['invoice_number'],
                $invoice['total_amount'],
                $invoice['amount_paid'],
                $balance,
                $status
            ]);
        }
        $synced++;
    }
    
    echo json_encode(['success' => true, 'synced' => $synced]);
    
} catch (PDOException $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
?>