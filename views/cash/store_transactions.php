<?php
// store_transaction.php - Save transaction and update account balance
session_start();

if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    header('Location: index.php');
    exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: index.php');
    exit();
}

try {
    $conn = new PDO("mysql:host=localhost;dbname=savant_motors_pos", "root", "");
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    // Get form data
    $transaction_date = $_POST['transaction_date'];
    $transaction_type = $_POST['transaction_type'];
    $category = $_POST['category'];
    $account_id = $_POST['account_id'];
    $amount = floatval($_POST['amount']);
    $reference_no = $_POST['reference_no'] ?? null;
    $description = $_POST['description'] ?? null;
    $created_by = $_SESSION['user_id'];
    
    // Validate
    $errors = [];
    if (empty($transaction_date)) $errors[] = "Date is required";
    if (empty($transaction_type)) $errors[] = "Transaction type is required";
    if (empty($category)) $errors[] = "Category is required";
    if (empty($account_id)) $errors[] = "Account is required";
    if ($amount <= 0) $errors[] = "Valid amount is required";
    
    if (!empty($errors)) {
        $_SESSION['error'] = implode("<br>", $errors);
        header('Location: index.php');
        exit();
    }
    
    // Start transaction
    $conn->beginTransaction();
    
    // Insert transaction
    $stmt = $conn->prepare("
        INSERT INTO cash_transactions (
            transaction_date, transaction_type, category, account_id, 
            amount, reference_no, description, created_by, created_at
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())
    ");
    $stmt->execute([
        $transaction_date, $transaction_type, $category, $account_id,
        $amount, $reference_no, $description, $created_by
    ]);
    
    // Update account balance
    $sign = $transaction_type == 'income' ? '+' : '-';
    $updateStmt = $conn->prepare("UPDATE cash_accounts SET balance = balance $sign ? WHERE id = ?");
    $updateStmt->execute([$amount, $account_id]);
    
    $conn->commit();
    
    $_SESSION['success'] = "Transaction recorded successfully!";
    header('Location: index.php');
    exit();
  

// After inserting cash transaction, also post to general ledger
    // Get the cash account from chart_of_accounts
    $stmt = $conn->prepare("SELECT id FROM chart_of_accounts WHERE account_code = '1000'");
    $stmt->execute();
    $cashAccount = $stmt->fetch();
    
    if ($cashAccount) {
        // Post to general ledger
        if ($transaction_type == 'income') {
            // Debit Cash, Credit Revenue
            $debitEntry = [
                'entry_date' => $transaction_date,
                'account_id' => $cashAccount['id'],
                'description' => $description,
                'debit_amount' => $amount,
                'credit_amount' => 0,
                'reference_type' => 'cash',
                'reference_id' => $transactionId,
                'created_by' => $user_id
            ];
            
            // Get revenue account (Sales Revenue)
            $revStmt = $conn->prepare("SELECT id FROM chart_of_accounts WHERE account_code = '4000'");
            $revStmt->execute();
            $revenueAccount = $revStmt->fetch();
            
            $creditEntry = [
                'entry_date' => $transaction_date,
                'account_id' => $revenueAccount['id'],
                'description' => $description,
                'debit_amount' => 0,
                'credit_amount' => $amount,
                'reference_type' => 'cash',
                'reference_id' => $transactionId,
                'created_by' => $user_id
            ];
        } else {
            // Credit Cash, Debit Expense
            $creditEntry = [
                'entry_date' => $transaction_date,
                'account_id' => $cashAccount['id'],
                'description' => $description,
                'debit_amount' => 0,
                'credit_amount' => $amount,
                'reference_type' => 'cash',
                'reference_id' => $transactionId,
                'created_by' => $user_id
            ];
            
            // Get expense account based on category
            $expStmt = $conn->prepare("SELECT id FROM chart_of_accounts WHERE account_name LIKE ? LIMIT 1");
            $expStmt->execute(["%{$category}%"]);
            $expenseAccount = $expStmt->fetch();
            
            if (!$expenseAccount) {
                // Use default expense account
                $expStmt = $conn->prepare("SELECT id FROM chart_of_accounts WHERE account_code = '5000'");
                $expStmt->execute();
                $expenseAccount = $expStmt->fetch();
            }
            
            $debitEntry = [
                'entry_date' => $transaction_date,
                'account_id' => $expenseAccount['id'],
                'description' => $description,
                'debit_amount' => $amount,
                'credit_amount' => 0,
                'reference_type' => 'cash',
                'reference_id' => $transactionId,
                'created_by' => $user_id
            ];
        }
        
        // Insert into general_ledger
        $ledgerStmt = $conn->prepare("
            INSERT INTO general_ledger (entry_date, account_id, description, debit_amount, credit_amount, reference_type, reference_id, created_by) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $ledgerStmt->execute([
            $debitEntry['entry_date'], $debitEntry['account_id'], $debitEntry['description'],
            $debitEntry['debit_amount'], $debitEntry['credit_amount'], $debitEntry['reference_type'],
            $debitEntry['reference_id'], $debitEntry['created_by']
        ]);
        
        $ledgerStmt->execute([
            $creditEntry['entry_date'], $creditEntry['account_id'], $creditEntry['description'],
            $creditEntry['debit_amount'], $creditEntry['credit_amount'], $creditEntry['reference_type'],
            $creditEntry['reference_id'], $creditEntry['created_by']
        ]);
    }

} catch(PDOException $e) {
    if (isset($conn)) $conn->rollBack();
    $_SESSION['error'] = "Database error: " . $e->getMessage();
    header('Location: index.php');
    exit();
}
?>