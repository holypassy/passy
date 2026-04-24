<?php
// sync_invoices_debtors.php
session_start();
header('Content-Type: application/json');

// Check authentication
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit();
}

try {
    $conn = new PDO("mysql:host=localhost;dbname=savant_motors_pos", "root", "");
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // Get all unpaid or partially paid invoices that are not fully settled
    $stmt = $conn->prepare("
        SELECT i.*, c.full_name, c.telephone, c.email 
        FROM invoices i
        LEFT JOIN customers c ON i.customer_id = c.id
        WHERE i.payment_status IN ('unpaid', 'partial')
        ORDER BY i.invoice_date DESC
    ");
    $stmt->execute();
    $invoices = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $synced = 0;
    $updated = 0;
    
    foreach ($invoices as $invoice) {
        $balance = $invoice['total_amount'] - $invoice['amount_paid'];
        
        // Check if debtor record already exists for this invoice
        $checkStmt = $conn->prepare("
            SELECT id, status FROM debtors 
            WHERE reference_type = 'invoice' AND reference_id = ?
        ");
        $checkStmt->execute([$invoice['id']]);
        $existing = $checkStmt->fetch(PDO::FETCH_ASSOC);
        
        if ($existing) {
            // Update existing record
            $status = $balance <= 0 ? 'settled' : ($invoice['amount_paid'] > 0 ? 'partial' : 'open');
            $updateStmt = $conn->prepare("
                UPDATE debtors 
                SET amount_owed = ?, 
                    amount_paid = ?, 
                    balance = ?, 
                    status = ?,
                    customer_name = ?
                WHERE id = ?
            ");
            $updateStmt->execute([
                $invoice['total_amount'],
                $invoice['amount_paid'],
                $balance,
                $status,
                $invoice['full_name'],
                $existing['id']
            ]);
            $updated++;
        } else {
            // Create new debtor record
            $status = $balance <= 0 ? 'settled' : ($invoice['amount_paid'] > 0 ? 'partial' : 'open');
            $insertStmt = $conn->prepare("
                INSERT INTO debtors (
                    customer_id, 
                    customer_name, 
                    reference_type, 
                    reference_id, 
                    reference_no, 
                    amount_owed, 
                    amount_paid, 
                    balance, 
                    status, 
                    created_at
                ) VALUES (?, ?, 'invoice', ?, ?, ?, ?, ?, ?, ?)
            ");
            $insertStmt->execute([
                $invoice['customer_id'],
                $invoice['full_name'],
                $invoice['id'],
                $invoice['invoice_number'],
                $invoice['total_amount'],
                $invoice['amount_paid'],
                $balance,
                $status,
                $invoice['invoice_date']
            ]);
            $synced++;
        }
    }
    
    // Also mark any invoice-linked debtors as settled if the invoice is now fully paid
    $settleStmt = $conn->prepare("
        UPDATE debtors d
        SET d.status = 'settled'
        WHERE d.reference_type = 'invoice' 
        AND d.balance <= 0
        AND d.status != 'settled'
    ");
    $settleStmt->execute();
    $settled = $settleStmt->rowCount();
    
    echo json_encode([
        'success' => true, 
        'synced' => $synced,
        'updated' => $updated,
        'settled' => $settled,
        'message' => "Added: $synced, Updated: $updated, Settled: $settled"
    ]);
    
} catch (PDOException $e) {
    echo json_encode(['success' => false, 'error' => 'Database error: ' . $e->getMessage()]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => 'Error: ' . $e->getMessage()]);
}
?>