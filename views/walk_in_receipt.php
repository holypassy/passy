<?php
// receipt.php – Walk-in Customer Receipt with Accounting Integration
session_start();

if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    header('Location: index.php');
    exit();
}

$user_id = $_SESSION['user_id'] ?? 1;
$user_full_name = $_SESSION['full_name'] ?? 'User';

$error_message = '';
$success_message = '';

try {
    $conn = new PDO("mysql:host=localhost;dbname=savant_motors_pos", "root", "");
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // Create receipts table
    $conn->exec("
        CREATE TABLE IF NOT EXISTS receipts (
            id INT AUTO_INCREMENT PRIMARY KEY,
            receipt_number VARCHAR(50) NOT NULL UNIQUE,
            customer_name VARCHAR(150) NOT NULL,
            receipt_date DATE NOT NULL,
            subtotal DECIMAL(15,2) DEFAULT 0,
            tax_rate DECIMAL(5,2) DEFAULT 0,
            tax_amount DECIMAL(15,2) DEFAULT 0,
            total_amount DECIMAL(15,2) DEFAULT 0,
            payment_method ENUM('cash','mobile_money','bank_transfer','cheque') DEFAULT 'cash',
            account_affected VARCHAR(50),
            reference_number VARCHAR(100),
            notes TEXT,
            created_by INT,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )
    ");
    
    // Create receipt items table
    $conn->exec("
        CREATE TABLE IF NOT EXISTS receipt_items (
            id INT AUTO_INCREMENT PRIMARY KEY,
            receipt_id INT NOT NULL,
            description VARCHAR(200) NOT NULL,
            quantity INT DEFAULT 1,
            unit_price DECIMAL(15,2) DEFAULT 0,
            total_price DECIMAL(15,2) DEFAULT 0,
            FOREIGN KEY (receipt_id) REFERENCES receipts(id) ON DELETE CASCADE
        )
    ");
    
    // Create accounting entries table for receipts
    $conn->exec("
        CREATE TABLE IF NOT EXISTS receipt_accounting_entries (
            id INT AUTO_INCREMENT PRIMARY KEY,
            receipt_id INT NOT NULL,
            account_type VARCHAR(50) NOT NULL,
            account_name VARCHAR(100),
            amount DECIMAL(15,2) DEFAULT 0,
            entry_type ENUM('debit','credit') DEFAULT 'debit',
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (receipt_id) REFERENCES receipts(id) ON DELETE CASCADE
        )
    ");

    // Generate next receipt number
    $last = $conn->query("SELECT receipt_number FROM receipts ORDER BY id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    if ($last) {
        $lastNum = intval(substr($last['receipt_number'], -4));
        $nextNum = str_pad($lastNum + 1, 4, '0', STR_PAD_LEFT);
        $receipt_number = 'RCP-' . date('Y') . '-' . $nextNum;
    } else {
        $receipt_number = 'RCP-' . date('Y') . '-0001';
    }

    // Get cash accounts for dropdown
    $cashAccounts = $conn->query("
        SELECT id, account_name, account_type, balance 
        FROM cash_accounts 
        WHERE is_active = 1 
        ORDER BY account_type, account_name
    ")->fetchAll(PDO::FETCH_ASSOC);

} catch(PDOException $e) {
    die("Database error: " . $e->getMessage());
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_receipt'])) {
    try {
        $conn->beginTransaction();

        $receipt_number = $_POST['receipt_number'];
        $customer_name = trim($_POST['customer_name']);
        $receipt_date = $_POST['receipt_date'];
        $payment_method = $_POST['payment_method'];
        $account_affected = $_POST['account_affected'] ?? null;
        $reference_number = trim($_POST['reference_number'] ?? '');
        $notes = trim($_POST['notes'] ?? '');

        // Collect items
        $descriptions = $_POST['description'] ?? [];
        $qtys = $_POST['qty'] ?? [];
        $prices = $_POST['price'] ?? [];

        $items = [];
        $subtotal = 0;

        for ($i = 0; $i < count($descriptions); $i++) {
            $desc = trim($descriptions[$i] ?? '');
            $qty = (float)($qtys[$i] ?? 0);
            $price = (float)($prices[$i] ?? 0);
            if ($desc !== '' && $qty > 0 && $price > 0) {
                $total = $qty * $price;
                $items[] = [
                    'description' => $desc,
                    'quantity' => $qty,
                    'unit_price' => $price,
                    'total_price' => $total
                ];
                $subtotal += $total;
            }
        }

        if ($subtotal <= 0) {
            throw new Exception("Please add at least one item with description, quantity, and price.");
        }

        $tax_rate = (float)($_POST['tax_rate'] ?? 0);
        $tax_amount = $subtotal * ($tax_rate / 100);
        $total = $subtotal + $tax_amount;

        // Insert receipt
        $stmt = $conn->prepare("
            INSERT INTO receipts (receipt_number, customer_name, receipt_date, subtotal, tax_rate, tax_amount, total_amount, payment_method, account_affected, reference_number, notes, created_by)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $receipt_number, $customer_name, $receipt_date,
            $subtotal, $tax_rate, $tax_amount, $total, $payment_method, $account_affected, $reference_number, $notes, $user_id
        ]);
        $receipt_id = $conn->lastInsertId();

        // Insert items
        $itemStmt = $conn->prepare("
            INSERT INTO receipt_items (receipt_id, description, quantity, unit_price, total_price)
            VALUES (?, ?, ?, ?, ?)
        ");
        foreach ($items as $item) {
            $itemStmt->execute([
                $receipt_id, $item['description'], $item['quantity'],
                $item['unit_price'], $item['total_price']
            ]);
        }

        // Create accounting entries
        // Debit the selected account (cash/bank/mobile/cheque)
        $accountEntryStmt = $conn->prepare("
            INSERT INTO receipt_accounting_entries (receipt_id, account_type, account_name, amount, entry_type)
            VALUES (?, ?, ?, ?, 'debit')
        ");
        $accountEntryStmt->execute([$receipt_id, $payment_method, $account_affected, $total]);

        // Credit sales/revenue account
        $revenueStmt = $conn->prepare("
            INSERT INTO receipt_accounting_entries (receipt_id, account_type, account_name, amount, entry_type)
            VALUES (?, 'sales', 'Sales Revenue', ?, 'credit')
        ");
        $revenueStmt->execute([$receipt_id, $total]);

        // Update cash/bank account balance
        $updateAccount = $conn->prepare("
            UPDATE cash_accounts SET balance = balance + ? WHERE account_name = ? AND is_active = 1
        ");
        $updateAccount->execute([$total, $account_affected]);

        // Record cash transaction
        $transactionStmt = $conn->prepare("
            INSERT INTO cash_transactions (account_id, transaction_type, amount, description, reference_number, transaction_date, status, created_by)
            SELECT id, 'income', ?, ?, ?, NOW(), 'approved', ?
            FROM cash_accounts WHERE account_name = ? AND is_active = 1
        ");
        $transactionStmt->execute([$total, "Receipt #$receipt_number - $customer_name", $reference_number, $user_id, $account_affected]);

        $conn->commit();

        // Redirect to print receipt
        header("Location: print_receipt.php?id=" . $receipt_id);
        exit();

    } catch (Exception $e) {
        $conn->rollBack();
        $error_message = "Error creating receipt: " . $e->getMessage();
    }
}

$current_date = date('Y-m-d');
$page_title = 'RECEIPT';
$page_subtitle = 'Walk-in Customer Receipt';
include 'header.php';
?>

<style>
    /* Override header background */
    .unified-header {
        background: linear-gradient(135deg, #f8fafc, #e2e8f0) !important;
        color: #1e293b !important;
    }
    .unified-header .company-details h2,
    .unified-header .company-details p {
        color: #1e293b !important;
    }
    .unified-header .header-right h3 {
        background: rgba(0,0,0,0.05) !important;
        color: #0f172a !important;
    }
    .unified-header .header-right .subtitle {
        color: #334155 !important;
    }

    /* Receipt form container */
    .receipt-form-container {
        max-width: 1000px;
        margin: 20px auto;
        background: white;
        border-radius: 24px;
        box-shadow: 0 10px 25px -5px rgba(0,0,0,0.1);
        padding: 30px;
        font-family: 'Calibri', 'Segoe UI', 'Arial', sans-serif;
    }
    .form-section {
        margin-bottom: 25px;
    }
    .form-section h3 {
        font-size: 18px;
        font-weight: 700;
        color: #1e293b;
        margin-bottom: 15px;
        padding-bottom: 8px;
        border-bottom: 2px solid #e2e8f0;
    }
    .form-grid {
        display: grid;
        grid-template-columns: repeat(2, 1fr);
        gap: 20px;
    }
    .form-group {
        margin-bottom: 15px;
    }
    .form-group label {
        display: block;
        font-size: 12px;
        font-weight: 600;
        color: #475569;
        margin-bottom: 6px;
        text-transform: uppercase;
        letter-spacing: 0.5px;
    }
    .form-group input, .form-group select, .form-group textarea {
        width: 100%;
        padding: 10px 14px;
        border: 1px solid #e2e8f0;
        border-radius: 12px;
        font-size: 14px;
        font-family: 'Calibri', 'Segoe UI', 'Arial', sans-serif;
    }
    .form-group input:focus, .form-group select:focus {
        outline: none;
        border-color: #2563eb;
        box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.1);
    }
    .items-table {
        width: 100%;
        border-collapse: collapse;
        margin: 15px 0;
    }
    .items-table th,
    .items-table td {
        border: 1px solid #e2e8f0;
        padding: 10px;
        text-align: left;
        font-size: 13px;
    }
    .items-table th {
        background: #f1f5f9;
        font-weight: 700;
        color: #334155;
    }
    .items-table input {
        width: 100%;
        padding: 6px;
        border: 1px solid #e2e8f0;
        border-radius: 8px;
        font-size: 13px;
    }
    .add-row-btn {
        background: #2563eb;
        color: white;
        border: none;
        padding: 8px 16px;
        border-radius: 40px;
        cursor: pointer;
        font-size: 12px;
        font-weight: 600;
        margin-top: 10px;
        display: inline-flex;
        align-items: center;
        gap: 8px;
    }
    .add-row-btn:hover {
        background: #1e40af;
    }
    .remove-row-btn {
        background: #ef4444;
        color: white;
        border: none;
        padding: 4px 8px;
        border-radius: 6px;
        cursor: pointer;
        font-size: 11px;
    }
    .remove-row-btn:hover {
        background: #dc2626;
    }
    .totals {
        margin-top: 20px;
        text-align: right;
        padding: 15px;
        background: #f8fafc;
        border-radius: 16px;
    }
    .totals p {
        font-size: 16px;
        margin-bottom: 8px;
    }
    .grand-total {
        font-size: 20px;
        font-weight: 800;
        color: #2563eb;
        margin-top: 10px;
        padding-top: 10px;
        border-top: 2px solid #e2e8f0;
    }
    .action-buttons {
        display: flex;
        justify-content: flex-end;
        gap: 15px;
        margin-top: 25px;
    }
    .btn {
        padding: 12px 28px;
        border-radius: 40px;
        font-size: 14px;
        font-weight: 600;
        cursor: pointer;
        border: none;
        transition: all 0.2s;
        display: inline-flex;
        align-items: center;
        gap: 8px;
    }
    .btn-primary {
        background: #2563eb;
        color: white;
    }
    .btn-primary:hover {
        background: #1e40af;
        transform: translateY(-2px);
    }
    .btn-secondary {
        background: #64748b;
        color: white;
    }
    .btn-secondary:hover {
        background: #475569;
        transform: translateY(-2px);
    }
    .alert {
        padding: 12px 20px;
        border-radius: 12px;
        margin-bottom: 20px;
        display: flex;
        align-items: center;
        gap: 10px;
    }
    .alert-success {
        background: #dcfce7;
        border-left: 4px solid #10b981;
        color: #166534;
    }
    .alert-error {
        background: #fee2e2;
        border-left: 4px solid #ef4444;
        color: #991b1b;
    }
    .info-text {
        font-size: 12px;
        color: #64748b;
        margin-top: 5px;
    }
    @media (max-width: 768px) {
        .form-grid {
            grid-template-columns: 1fr;
        }
        .receipt-form-container {
            padding: 20px;
        }
    }
</style>

<div class="receipt-form-container">
    <?php if ($error_message): ?>
    <div class="alert alert-error"><i class="fas fa-exclamation-triangle"></i> <?php echo htmlspecialchars($error_message); ?></div>
    <?php endif; ?>
    <?php if ($success_message): ?>
    <div class="alert alert-success"><i class="fas fa-check-circle"></i> <?php echo htmlspecialchars($success_message); ?></div>
    <?php endif; ?>

    <form method="POST" id="receiptForm">
        <input type="hidden" name="receipt_number" value="<?php echo htmlspecialchars($receipt_number); ?>">

        <div class="form-section">
            <h3><i class="fas fa-receipt"></i> Receipt Information</h3>
            <div class="form-grid">
                <div class="form-group">
                    <label>Receipt Number</label>
                    <input type="text" value="<?php echo htmlspecialchars($receipt_number); ?>" readonly style="background:#f1f5f9;">
                </div>
                <div class="form-group">
                    <label>Date</label>
                    <input type="date" name="receipt_date" value="<?php echo $current_date; ?>" required>
                </div>
                <div class="form-group">
                    <label>Customer Name *</label>
                    <input type="text" name="customer_name" required placeholder="Enter walk-in customer name">
                </div>
                <div class="form-group">
                    <label>Payment Method *</label>
                    <select name="payment_method" id="paymentMethod" required onchange="toggleAccountFields()">
                        <option value="cash">Cash</option>
                        <option value="mobile_money">Mobile Money</option>
                        <option value="bank_transfer">Bank Transfer</option>
                        <option value="cheque">Cheque</option>
                    </select>
                </div>
                <div class="form-group" id="accountGroup" style="display: none;">
                    <label>Select Account *</label>
                    <select name="account_affected" id="accountAffected">
                        <option value="">-- Select Account --</option>
                        <?php foreach ($cashAccounts as $account): ?>
                        <option value="<?php echo htmlspecialchars($account['account_name']); ?>">
                            <?php echo htmlspecialchars($account['account_name']); ?> (<?php echo ucfirst($account['account_type']); ?>) - Balance: UGX <?php echo number_format($account['balance'], 0); ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                    <div class="info-text"><i class="fas fa-info-circle"></i> Select which account this payment will be recorded to</div>
                </div>
                <div class="form-group" id="referenceGroup" style="display: none;">
                    <label>Reference Number</label>
                    <input type="text" name="reference_number" id="referenceNumber" placeholder="Transaction ID / Cheque No / Mobile Money Ref">
                    <div class="info-text"><i class="fas fa-info-circle"></i> Enter transaction reference for tracking</div>
                </div>
                <div class="form-group">
                    <label>Tax Rate (%)</label>
                    <input type="number" name="tax_rate" id="taxRate" value="0" step="0.1" onchange="recalcTotals()">
                </div>
            </div>
        </div>

        <div class="form-section">
            <h3><i class="fas fa-list"></i> Items / Services</h3>
            <table class="items-table" id="itemsTable">
                <thead>
                    <tr>
                        <th style="width:40%">Description</th>
                        <th style="width:15%">Quantity</th>
                        <th style="width:20%">Unit Price (UGX)</th>
                        <th style="width:20%">Total (UGX)</th>
                        <th style="width:5%"></th>
                    </thead>
                <tbody id="itemsBody">
                    <tr class="item-row">
                        <td><input type="text" name="description[]" class="desc-input" placeholder="Item description" required></td>
                        <td><input type="number" name="qty[]" class="qty-input" value="1" step="0.01" style="text-align:right"></td>
                        <td><input type="number" name="price[]" class="price-input" value="0" step="0.01" style="text-align:right" placeholder="0.00"></td>
                        <td class="amount-cell">0</td>
                        <td><button type="button" class="remove-row-btn" onclick="removeRow(this)">✖</button></td>
                    </tr>
                </tbody>
                <tfoot>
                    <tr>
                        <td colspan="5" style="text-align:right; border:none;">
                            <button type="button" class="add-row-btn" onclick="addRow()"><i class="fas fa-plus"></i> Add Item</button>
                        </td>
                    </tr>
                </tfoot>
            </table>
        </div>

        <div class="totals">
            <p>Subtotal: UGX <span id="subtotalDisplay">0</span></p>
            <p>Tax (VAT): UGX <span id="taxDisplay">0</span></p>
            <p class="grand-total">Grand Total: UGX <span id="grandTotalDisplay">0</span></p>
        </div>

        <div class="form-section">
            <h3><i class="fas fa-sticky-note"></i> Additional Notes</h3>
            <div class="form-group">
                <textarea name="notes" rows="3" placeholder="Any additional notes..."></textarea>
            </div>
        </div>

        <div class="action-buttons">
            <a href="dashboard_erp.php" class="btn btn-secondary"><i class="fas fa-times"></i> Cancel</a>
            <button type="submit" name="create_receipt" class="btn btn-primary"><i class="fas fa-save"></i> Save & Print Receipt</button>
        </div>
    </form>
</div>

<script>
    function toggleAccountFields() {
        const paymentMethod = document.getElementById('paymentMethod').value;
        const accountGroup = document.getElementById('accountGroup');
        const referenceGroup = document.getElementById('referenceGroup');
        const accountSelect = document.getElementById('accountAffected');
        
        if (paymentMethod === 'cash') {
            accountGroup.style.display = 'block';
            referenceGroup.style.display = 'none';
            accountSelect.setAttribute('required', 'required');
        } else if (paymentMethod === 'mobile_money') {
            accountGroup.style.display = 'block';
            referenceGroup.style.display = 'block';
            accountSelect.setAttribute('required', 'required');
        } else if (paymentMethod === 'bank_transfer') {
            accountGroup.style.display = 'block';
            referenceGroup.style.display = 'block';
            accountSelect.setAttribute('required', 'required');
        } else if (paymentMethod === 'cheque') {
            accountGroup.style.display = 'block';
            referenceGroup.style.display = 'block';
            accountSelect.setAttribute('required', 'required');
        } else {
            accountGroup.style.display = 'none';
            referenceGroup.style.display = 'none';
            accountSelect.removeAttribute('required');
        }
    }

    function recalcTotals() {
        const rows = document.querySelectorAll('#itemsBody .item-row');
        let subtotal = 0;
        rows.forEach((row, idx) => {
            const qty = parseFloat(row.querySelector('.qty-input')?.value) || 0;
            const price = parseFloat(row.querySelector('.price-input')?.value) || 0;
            const total = qty * price;
            const amountCell = row.querySelector('.amount-cell');
            if (amountCell) amountCell.innerText = total.toLocaleString(undefined, {minimumFractionDigits:0, maximumFractionDigits:0});
            subtotal += total;
        });

        const taxRate = parseFloat(document.getElementById('taxRate').value) || 0;
        const taxAmount = subtotal * (taxRate / 100);
        const grandTotal = subtotal + taxAmount;

        document.getElementById('subtotalDisplay').innerText = subtotal.toLocaleString(undefined, {minimumFractionDigits:0, maximumFractionDigits:0});
        document.getElementById('taxDisplay').innerText = taxAmount.toLocaleString(undefined, {minimumFractionDigits:0, maximumFractionDigits:0});
        document.getElementById('grandTotalDisplay').innerText = grandTotal.toLocaleString(undefined, {minimumFractionDigits:0, maximumFractionDigits:0});
    }

    function addRow() {
        const tbody = document.getElementById('itemsBody');
        const newRow = document.createElement('tr');
        newRow.className = 'item-row';
        newRow.innerHTML = `
            <td><input type="text" name="description[]" class="desc-input" placeholder="Item description" required></td>
            <td><input type="number" name="qty[]" class="qty-input" value="1" step="0.01" style="text-align:right"></td>
            <td><input type="number" name="price[]" class="price-input" value="0" step="0.01" style="text-align:right" placeholder="0.00"></td>
            <td class="amount-cell">0</td>
            <td><button type="button" class="remove-row-btn" onclick="removeRow(this)">✖</button></td>
        `;
        tbody.appendChild(newRow);
        attachEventListeners(newRow);
        recalcTotals();
    }

    function removeRow(btn) {
        const row = btn.closest('.item-row');
        if (row && document.querySelectorAll('#itemsBody .item-row').length > 1) {
            row.remove();
            recalcTotals();
        } else {
            alert("At least one item is required.");
        }
    }

    function attachEventListeners(row) {
        const qtyInput = row.querySelector('.qty-input');
        const priceInput = row.querySelector('.price-input');
        if (qtyInput) qtyInput.addEventListener('input', recalcTotals);
        if (priceInput) priceInput.addEventListener('input', recalcTotals);
    }

    function initEventListeners() {
        document.querySelectorAll('#itemsBody .item-row').forEach(row => attachEventListeners(row));
        document.getElementById('taxRate').addEventListener('input', recalcTotals);
        toggleAccountFields();
    }

    window.addEventListener('DOMContentLoaded', () => {
        initEventListeners();
        recalcTotals();
    });
</script>

<?php
// No closing PHP tag needed
?>