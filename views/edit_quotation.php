<?php
// edit_quotation.php – Edit an existing quotation
session_start();

if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    header('Location: index.php');
    exit();
}

$user_full_name = $_SESSION['full_name'] ?? 'User';
$user_role = $_SESSION['role'] ?? 'user';
$user_id = $_SESSION['user_id'] ?? 1;

$quotation_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($quotation_id <= 0) {
    header('Location: quotations.php');
    exit();
}

try {
    $conn = new PDO("mysql:host=localhost;dbname=savant_motors_pos", "root", "");
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // Check what columns exist in quotation_items table
    $stmt = $conn->query("SHOW COLUMNS FROM quotation_items");
    $existingColumns = $stmt->fetchAll(PDO::FETCH_COLUMN);
    
    // Fetch quotation header
    $stmt = $conn->prepare("
        SELECT q.*, c.full_name as customer_name, c.telephone, c.email, c.address
        FROM quotations q
        LEFT JOIN customers c ON q.customer_id = c.id
        WHERE q.id = ?
    ");
    $stmt->execute([$quotation_id]);
    $quote = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$quote) {
        header('Location: quotations.php');
        exit();
    }

    // Fetch items
    $stmt = $conn->prepare("
        SELECT * FROM quotation_items 
        WHERE quotation_id = ? 
        ORDER BY item_number
    ");
    $stmt->execute([$quotation_id]);
    $items = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Get customers for dropdown
    $customers = $conn->query("SELECT id, full_name, telephone, email, address FROM customers WHERE deleted_at IS NULL ORDER BY full_name")->fetchAll(PDO::FETCH_ASSOC);

} catch(PDOException $e) {
    die("Database error: " . $e->getMessage());
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_quotation'])) {
    try {
        $conn->beginTransaction();

        $customer_id = $_POST['customer_id'];
        $quotation_date = $_POST['quotation_date'];
        $vehicle_reg = $_POST['vehicle_reg'] ?? null;
        $vehicle_model = $_POST['vehicle_model'] ?? null;
        $chassis_no = $_POST['chassis_no'] ?? null;
        $odo_reading = $_POST['odo_reading'] ?? null;

        // Customer type and business fields
        $customer_type = $_POST['customer_type'] ?? 'individual';
        $company_name = ($customer_type === 'business') ? trim($_POST['company_name'] ?? '') : null;
        $tin = ($customer_type === 'business') ? trim($_POST['tin'] ?? '') : null;

        // Collect items - combine item name and description into description field
        $items_data = [];
        $item_names = $_POST['item_name'] ?? [];
        $descriptions = $_POST['description'] ?? [];
        $qtys = $_POST['qty'] ?? [];
        $rates = $_POST['rate'] ?? [];

        $subtotal = 0;

        for ($i = 0; $i < count($item_names); $i++) {
            if (!empty($item_names[$i])) {
                $qty = (float)($qtys[$i] ?? 0);
                $rate = (float)($rates[$i] ?? 0);
                $item_subtotal = $qty * $rate;
                
                // Combine item name and description for the description field
                $full_description = trim($item_names[$i]);
                if (!empty($descriptions[$i])) {
                    $full_description .= " - " . trim($descriptions[$i]);
                }

                $items_data[] = [
                    'description' => $full_description,
                    'quantity' => $qty,
                    'unit_price' => $rate,
                    'total_price' => $item_subtotal
                ];
                $subtotal += $item_subtotal;
            }
        }

        // Tax handling based on customer type
        if ($customer_type === 'business') {
            $tax_rate = (float)($_POST['tax_rate'] ?? 18);
            $tax_amount = $subtotal * ($tax_rate / 100);
            $total = $subtotal + $tax_amount;
        } else {
            $tax_rate = 0;
            $tax_amount = 0;
            $total = $subtotal;
        }

        // Update quotation header
        $stmt = $conn->prepare("
            UPDATE quotations SET
                customer_id = ?,
                quotation_date = ?,
                vehicle_reg = ?,
                vehicle_model = ?,
                chassis_no = ?,
                odo_reading = ?,
                subtotal = ?,
                tax = ?,
                total_amount = ?,
                customer_type = ?,
                company_name = ?,
                tin = ?
            WHERE id = ?
        ");
        $stmt->execute([
            $customer_id,
            $quotation_date,
            $vehicle_reg,
            $vehicle_model,
            $chassis_no,
            $odo_reading,
            $subtotal,
            $tax_amount,
            $total,
            $customer_type,
            $company_name,
            $tin,
            $quotation_id
        ]);

        // Delete existing items and re-insert
        $conn->prepare("DELETE FROM quotation_items WHERE quotation_id = ?")->execute([$quotation_id]);
        
        // Insert items using only existing columns
        $itemStmt = $conn->prepare("
            INSERT INTO quotation_items 
            (quotation_id, item_number, description, quantity, unit_price, total_price) 
            VALUES (?, ?, ?, ?, ?, ?)
        ");
        
        $itemNumber = 1;
        foreach ($items_data as $item) {
            $itemStmt->execute([
                $quotation_id,
                $itemNumber++,
                $item['description'],
                $item['quantity'],
                $item['unit_price'],
                $item['total_price']
            ]);
        }

        $conn->commit();

        $_SESSION['success'] = "Quotation updated successfully!";
        header('Location: quotations.php');
        exit();

    } catch(Exception $e) {
        if (isset($conn)) $conn->rollBack();
        $error_message = "Error: " . $e->getMessage();
    }
}

$success_message = $_SESSION['success'] ?? null;
$error_message = $_SESSION['error'] ?? $error_message ?? null;
unset($_SESSION['success'], $_SESSION['error']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Edit Quotation | SAVANT MOTORS</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:opsz,wght@14..32,400;14..32,500;14..32,600;14..32,700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: 'Inter', sans-serif;
            background: linear-gradient(135deg, #e6f0fa 0%, #d4e4f5 100%);
            padding: 2rem;
            position: relative;
        }
        .watermark {
            position: fixed;
            bottom: 20px;
            right: 20px;
            opacity: 0.15;
            pointer-events: none;
            z-index: 1000;
            font-size: 48px;
            font-weight: 800;
            color: #2563eb;
            transform: rotate(-15deg);
            white-space: nowrap;
        }
        @media print {
            body { background: white; padding: 0; margin: 0; }
            .watermark { opacity: 0.1; }
            .toolbar { display: none; }
            .container { box-shadow: none; border-radius: 0; }
            input, textarea { border: none !important; background: transparent !important; }
            .btn-add-row, .btn-remove-row { display: none; }
        }
        .container {
            max-width: 1200px;
            margin: 0 auto;
            background: white;
            border-radius: 24px;
            box-shadow: 0 8px 30px rgba(0,0,0,0.1);
            overflow: hidden;
            position: relative;
            z-index: 1;
        }
        .toolbar {
            background: linear-gradient(135deg, #2563eb, #1e40af);
            padding: 1rem 2rem;
            display: flex;
            gap: 1rem;
            flex-wrap: wrap;
            align-items: center;
        }
        .toolbar button, .toolbar a {
            background: rgba(255,255,255,0.2);
            border: none;
            color: white;
            padding: 0.5rem 1.2rem;
            border-radius: 8px;
            font-weight: 600;
            cursor: pointer;
            font-size: 0.9rem;
            transition: all 0.2s;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
        }
        .toolbar button:hover, .toolbar a:hover {
            background: rgba(255,255,255,0.3);
            transform: translateY(-1px);
        }
        .quote-content {
            padding: 2rem;
        }
        .header-wrapper {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 2rem;
            padding-bottom: 1rem;
            border-bottom: 2px solid #e2e8f0;
        }
        .logo {
            flex-shrink: 0;
            width: 100px;
        }
        .logo img {
            max-width: 80px;
            height: auto;
        }
        .company {
            flex-grow: 1;
            text-align: center;
        }
        .company h1 {
            font-size: 28px;
            font-weight: 800;
            color: #0ea5e9;
        }
        .company p {
            color: #dc2626;
            font-size: 12px;
        }
        .right-text {
            width: 100px;
            text-align: right;
            font-size: 18px;
            font-weight: 800;
            color: #2563eb;
        }
        .quote-header {
            display: flex;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 1.5rem;
            margin-bottom: 2rem;
            background: #f8fafc;
            padding: 1rem;
            border-radius: 16px;
            border: 1px solid #e2e8f0;
        }
        .quote-to {
            flex: 2;
        }
        .quote-to table {
            width: 100%;
            border-collapse: collapse;
        }
        .quote-to td {
            padding: 6px 8px;
            border: 1px solid #e2e8f0;
            font-size: 14px;
        }
        .quote-to td:first-child {
            font-weight: 600;
            background: #f1f5f9;
            width: 100px;
        }
        .quote-to input, .quote-to select {
            width: 100%;
            border: none;
            background: transparent;
            font-family: inherit;
            font-size: 14px;
            padding: 4px;
            outline: none;
        }
        .quote-to input:focus {
            background: #fff;
            border-bottom: 2px solid #2563eb;
        }
        .customer-type-group {
            margin-bottom: 20px;
            display: flex;
            gap: 20px;
            background: #f8fafc;
            padding: 12px 20px;
            border-radius: 16px;
            border: 1px solid #e2e8f0;
        }
        .customer-type-group label {
            display: flex;
            align-items: center;
            gap: 8px;
            font-weight: 500;
            cursor: pointer;
        }
        .business-fields {
            display: none;
            background: #f8fafc;
            padding: 15px;
            border-radius: 16px;
            margin-top: 10px;
            margin-bottom: 20px;
            border: 1px solid #e2e8f0;
        }
        .business-fields.active {
            display: block;
        }
        .business-fields input {
            width: 100%;
            padding: 10px;
            margin-bottom: 10px;
            border: 1px solid #e2e8f0;
            border-radius: 10px;
        }
        .items-table {
            width: 100%;
            border-collapse: collapse;
            margin: 1.5rem 0;
            font-size: 13px;
        }
        .items-table th, .items-table td {
            border: 1px solid #e2e8f0;
            padding: 8px;
            vertical-align: top;
        }
        .items-table th {
            background: #2563eb;
            color: white;
            font-weight: 600;
            text-align: center;
        }
        .items-table td input, .items-table td textarea {
            width: 100%;
            border: none;
            background: transparent;
            font-family: inherit;
            font-size: 13px;
            padding: 4px;
            outline: none;
        }
        .items-table td input:focus, .items-table td textarea:focus {
            background: #fffaf0;
        }
        .amount-cell {
            text-align: right;
            font-weight: 600;
        }
        .btn-add-row {
            background: #2563eb;
            color: white;
            border: none;
            padding: 8px 16px;
            border-radius: 6px;
            cursor: pointer;
            font-size: 12px;
            margin-top: 8px;
        }
        .btn-add-row:hover {
            background: #1e40af;
        }
        .btn-remove-row {
            background: #ef4444;
            color: white;
            border: none;
            padding: 4px 8px;
            border-radius: 4px;
            cursor: pointer;
            font-size: 11px;
        }
        .btn-remove-row:hover {
            background: #dc2626;
        }
        .tax-section {
            background: #f8fafc;
            border-radius: 12px;
            padding: 20px;
            margin: 20px 0;
            border: 1px solid #e2e8f0;
        }
        .tax-grid {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 20px;
            flex-wrap: wrap;
        }
        .tax-item {
            flex: 1;
            min-width: 200px;
        }
        .tax-item label {
            font-size: 13px;
            font-weight: 600;
            color: #475569;
            margin-bottom: 5px;
            display: block;
        }
        .tax-item input {
            width: 100%;
            padding: 10px;
            border: 1px solid #e2e8f0;
            border-radius: 10px;
            font-size: 14px;
        }
        .payment-note {
            background: #fef9c3;
            border-left: 4px solid #eab308;
            padding: 1rem;
            margin: 1.5rem 0;
            border-radius: 8px;
            font-size: 14px;
            font-weight: 500;
        }
        .bank-details {
            margin-top: 2rem;
            text-align: center;
            font-size: 13px;
            color: #334155;
            border-top: 1px dashed #cbd5e1;
            padding-top: 1rem;
        }
        footer {
            text-align: center;
            padding: 1rem;
            font-size: 12px;
            color: #64748b;
            background: #f1f5f9;
        }
        .alert {
            padding: 12px 20px;
            border-radius: 12px;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .alert-success { background: #d4edda; color: #155724; border-left: 4px solid #28a745; }
        .alert-danger { background: #f8d7da; color: #721c24; border-left: 4px solid #dc3545; }
        .action-buttons {
            display: flex;
            gap: 15px;
            justify-content: flex-end;
            margin-top: 25px;
        }
        .btn {
            padding: 12px 24px;
            border-radius: 40px;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            border: none;
            transition: all 0.2s;
        }
        .btn-cancel {
            background: #64748b;
            color: white;
        }
        .btn-save {
            background: #10b981;
            color: white;
        }
        .btn:hover {
            transform: translateY(-2px);
            filter: brightness(1.05);
        }
        .totals-box {
            background: #f8fafc;
            border-radius: 12px;
            padding: 20px;
            margin: 20px 0;
            border: 1px solid #e2e8f0;
        }
        .total-row {
            display: flex;
            justify-content: space-between;
            padding: 8px 0;
        }
        .grand-total {
            font-weight: 700;
            font-size: 18px;
            border-top: 1px solid #cbd5e1;
            margin-top: 8px;
            padding-top: 12px;
            color: #2563eb;
        }
        .status-badge {
            display: inline-block;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 11px;
            font-weight: 600;
            text-transform: uppercase;
            background: #e2e8f0;
            color: #475569;
        }
        @media (max-width: 768px) {
            body { padding: 1rem; }
            .header-wrapper { flex-wrap: wrap; }
            .logo, .right-text { width: auto; }
            .tax-grid { flex-direction: column; }
            .action-buttons { flex-direction: column; }
            .quote-to table td { display: block; width: 100%; }
            .items-table { font-size: 11px; }
            .items-table th, .items-table td { padding: 4px; }
        }
    </style>
    <script>
        function toggleCustomerType() {
            var type = document.querySelector('input[name="customer_type"]:checked').value;
            var businessDiv = document.getElementById('businessFields');
            var taxSection = document.getElementById('taxSection');
            if (type === 'business') {
                businessDiv.classList.add('active');
                taxSection.style.display = 'block';
            } else {
                businessDiv.classList.remove('active');
                taxSection.style.display = 'none';
            }
            recalcTable();
        }

        function recalcTable() {
            const rows = document.querySelectorAll('#itemsBody .item-row');
            let subtotal = 0;
            rows.forEach((row, idx) => {
                const noCell = row.querySelector('.row-number');
                if (noCell) noCell.innerText = idx + 1;
                const qty = parseFloat(row.querySelector('.qty-input')?.value) || 0;
                const rate = parseFloat(row.querySelector('.rate-input')?.value) || 0;
                const amount = qty * rate;
                const amountCell = row.querySelector('.amount-cell');
                if (amountCell) amountCell.innerText = amount.toLocaleString(undefined, {minimumFractionDigits:0, maximumFractionDigits:0});
                subtotal += amount;
            });

            const customerType = document.querySelector('input[name="customer_type"]:checked').value;
            let taxAmount = 0;
            let grandTotal = subtotal;

            if (customerType === 'business') {
                const taxRate = parseFloat(document.getElementById('taxRate').value) || 0;
                taxAmount = subtotal * (taxRate / 100);
                grandTotal = subtotal + taxAmount;
                document.getElementById('taxAmountDisplay').innerText = taxAmount.toLocaleString(undefined, {minimumFractionDigits:0, maximumFractionDigits:0});
                document.getElementById('taxAmount').value = taxAmount.toFixed(0);
            } else {
                document.getElementById('taxAmountDisplay').innerText = '0';
                document.getElementById('taxAmount').value = '0';
            }

            document.getElementById('subtotal').innerText = subtotal.toLocaleString(undefined, {minimumFractionDigits:0, maximumFractionDigits:0});
            document.getElementById('grandTotal').innerText = grandTotal.toLocaleString(undefined, {minimumFractionDigits:0, maximumFractionDigits:0});
        }

        function addRow() {
            const tbody = document.getElementById('itemsBody');
            const newRow = document.createElement('tr');
            newRow.className = 'item-row';
            newRow.innerHTML = `
                <td class="row-number">-</td>
                <td><input type="text" name="item_name[]" class="item-input" placeholder="Item / Service name" style="width:100%"></td>
                <td><textarea name="description[]" rows="2" class="desc-input" placeholder="Detailed description (optional)" style="width:100%"></textarea></td>
                <td><input type="number" name="qty[]" value="1" step="0.01" class="qty-input" style="text-align:right; width:100%"></td>
                <td><input type="number" name="rate[]" value="0" step="0.01" class="rate-input" style="text-align:right; width:100%"></td>
                <td class="amount-cell">0</td>
                <td><button type="button" class="btn-remove-row" onclick="removeRow(this)">✖</button></td>
            `;
            tbody.appendChild(newRow);
            attachEventListeners(newRow);
            recalcTable();
        }

        function removeRow(btn) {
            const row = btn.closest('.item-row');
            if (row && document.querySelectorAll('#itemsBody .item-row').length > 1) {
                row.remove();
                recalcTable();
            } else {
                alert("At least one item is required.");
            }
        }

        function attachEventListeners(row) {
            const qtyInput = row.querySelector('.qty-input');
            const rateInput = row.querySelector('.rate-input');
            if (qtyInput) qtyInput.addEventListener('input', recalcTable);
            if (rateInput) rateInput.addEventListener('input', recalcTable);
        }

        function initEventListeners() {
            document.querySelectorAll('#itemsBody .item-row').forEach(row => attachEventListeners(row));
            const taxRateInput = document.getElementById('taxRate');
            if (taxRateInput) taxRateInput.addEventListener('input', recalcTable);
        }

        window.addEventListener('DOMContentLoaded', () => {
            initEventListeners();
            const typeRadios = document.querySelectorAll('input[name="customer_type"]');
            for (let radio of typeRadios) {
                if (radio.checked) {
                    toggleCustomerType();
                    break;
                }
            }
            recalcTable();
        });
    </script>
</head>
<body>
    <div class="watermark">SAVANT MOTORS</div>

    <div class="container">
        <div class="toolbar">
            <a href="quotations.php"><i class="fas fa-arrow-left"></i> Back to Quotations</a>
            <a href="view_quotation.php?id=<?php echo $quotation_id; ?>"><i class="fas fa-eye"></i> View Quotation</a>
        </div>

        <div class="quote-content">
            <div class="header-wrapper">
                <div class="logo">
                    <img src="images/logo.jpeg" alt="Savant Motors Logo" onerror="this.style.display='none'">
                </div>
                <div class="company">
                    <h1>SAVANT MOTORS</h1>
                    <p>Bugolobi, Bunyonyi Drive, Kampala, Uganda</p>
                    <p>Tel: +256 774 537 017 | +256 704 496 974 | +256 775 919 526</p>
                </div>
                <div class="right-text">EDIT QUOTATION</div>
            </div>

            <?php if ($success_message): ?>
            <div class="alert alert-success"><i class="fas fa-check-circle"></i> <?php echo htmlspecialchars($success_message); ?></div>
            <?php endif; ?>
            <?php if ($error_message): ?>
            <div class="alert alert-danger"><i class="fas fa-exclamation-triangle"></i> <?php echo htmlspecialchars($error_message); ?></div>
            <?php endif; ?>

            <form method="POST" id="quoteForm">
                <!-- Customer Type Toggle -->
                <div class="customer-type-group">
                    <label><input type="radio" name="customer_type" value="individual" <?php echo ($quote['customer_type'] ?? 'individual') === 'individual' ? 'checked' : ''; ?> onclick="toggleCustomerType()"> 👤 Individual</label>
                    <label><input type="radio" name="customer_type" value="business" <?php echo ($quote['customer_type'] ?? '') === 'business' ? 'checked' : ''; ?> onclick="toggleCustomerType()"> 🏢 Business</label>
                </div>

                <!-- Business Fields -->
                <div id="businessFields" class="business-fields <?php echo ($quote['customer_type'] ?? '') === 'business' ? 'active' : ''; ?>">
                    <input type="text" name="company_name" placeholder="Company Name" value="<?php echo htmlspecialchars($quote['company_name'] ?? ''); ?>">
                    <input type="text" name="tin" placeholder="TIN (Tax Identification Number)" value="<?php echo htmlspecialchars($quote['tin'] ?? ''); ?>">
                </div>

                <div class="quote-header">
                    <div class="quote-to">
                        <table>
                            <tr>
                                <td style="width:100px">CUSTOMER:</td>
                                <td colspan="3">
                                    <select name="customer_id" required>
                                        <option value="">Select Customer</option>
                                        <?php foreach ($customers as $cust): ?>
                                        <option value="<?php echo $cust['id']; ?>" <?php echo $cust['id'] == $quote['customer_id'] ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($cust['full_name']); ?>
                                        </option>
                                        <?php endforeach; ?>
                                    </select>
                                </td>
                                <td style="width:80px">Date:</td>
                                <td><input type="date" name="quotation_date" value="<?php echo htmlspecialchars($quote['quotation_date']); ?>"></td>
                            </tr>
                            <tr>
                                <td>REG NO:</td>
                                <td><input type="text" name="vehicle_reg" placeholder="Vehicle Registration" value="<?php echo htmlspecialchars($quote['vehicle_reg'] ?? ''); ?>"></td>
                                <td style="width:100px">VEHICLE MODEL:</td>
                                <td><input type="text" name="vehicle_model" placeholder="Model" value="<?php echo htmlspecialchars($quote['vehicle_model'] ?? ''); ?>"></td>
                                <td>Quotation #:</td>
                                <td><strong><?php echo htmlspecialchars($quote['quotation_number']); ?></strong></td>
                            </tr>
                            <tr>
                                <td>Chassis No.:</td>
                                <td><input type="text" name="chassis_no" placeholder="Chassis Number" value="<?php echo htmlspecialchars($quote['chassis_no'] ?? ''); ?>"></td>
                                <td>ODO Reading:</td>
                                <td><input type="text" name="odo_reading" placeholder="Odometer" value="<?php echo htmlspecialchars($quote['odo_reading'] ?? ''); ?>"></td>
                                <td>Status:</td>
                                <td><span class="status-badge"><?php echo htmlspecialchars($quote['status'] ?? 'draft'); ?></span></td>
                            </tr>
                        </table>
                    </div>
                </div>

                <table class="items-table">
                    <thead>
                        <tr>
                            <th style="width:40px">NO</th>
                            <th style="width:200px">ITEM / SERVICE</th>
                            <th>DESCRIPTION</th>
                            <th style="width:70px">QTY</th>
                            <th style="width:100px">RATE (UGX)</th>
                            <th style="width:120px">AMOUNT (UGX)</th>
                            <th style="width:40px"></th>
                        </tr>
                    </thead>
                    <tbody id="itemsBody">
                        <?php if (empty($items)): ?>
                        <tr class="item-row">
                            <td class="row-number">1</td>
                            <td><input type="text" name="item_name[]" class="item-input" placeholder="Item / Service name" style="width:100%"></td>
                            <td><textarea name="description[]" rows="2" class="desc-input" placeholder="Detailed description (optional)" style="width:100%"></textarea></td>
                            <td><input type="number" name="qty[]" value="1" step="0.01" class="qty-input" style="text-align:right; width:100%"></td>
                            <td><input type="number" name="rate[]" value="0" step="0.01" class="rate-input" style="text-align:right; width:100%"></td>
                            <td class="amount-cell">0</td>
                            <td><button type="button" class="btn-remove-row" onclick="removeRow(this)">✖</button></td>
                        </tr>
                        <?php else: ?>
                            <?php foreach ($items as $index => $item): ?>
                            <tr class="item-row">
                                <td class="row-number"><?php echo $index + 1; ?></td>
                                <td><input type="text" name="item_name[]" class="item-input" value="<?php echo htmlspecialchars(explode(' - ', $item['description'])[0] ?? $item['description']); ?>" placeholder="Item / Service name" style="width:100%"></td>
                                <td><textarea name="description[]" rows="2" class="desc-input" placeholder="Detailed description" style="width:100%"><?php 
                                    $parts = explode(' - ', $item['description'], 2);
                                    echo htmlspecialchars($parts[1] ?? '');
                                ?></textarea></td>
                                <td><input type="number" name="qty[]" value="<?php echo $item['quantity']; ?>" step="0.01" class="qty-input" style="text-align:right; width:100%"></td>
                                <td><input type="number" name="rate[]" value="<?php echo $item['unit_price']; ?>" step="0.01" class="rate-input" style="text-align:right; width:100%"></td>
                                <td class="amount-cell"><?php echo number_format($item['total_price'], 0); ?></td>
                                <td><button type="button" class="btn-remove-row" onclick="removeRow(this)">✖</button></td>
                            </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                    <tfoot>
                        <tr>
                            <td colspan="7" style="text-align:center; border:none;">
                                <button type="button" class="btn-add-row" onclick="addRow()">+ Add New Item</button>
                            </td>
                        </tr>
                    </tfoot>
                </table>

                <!-- Tax section -->
                <div id="taxSection" class="tax-section" style="display: <?php echo ($quote['customer_type'] ?? '') === 'business' ? 'block' : 'none'; ?>;">
                    <div class="tax-grid">
                        <div class="tax-item">
                            <label>Tax Rate (%)</label>
                            <?php
                                $tax_rate = ($quote['subtotal'] > 0) ? ($quote['tax'] / $quote['subtotal'] * 100) : 18;
                                $tax_rate = round($tax_rate, 2);
                            ?>
                            <input type="number" name="tax_rate" id="taxRate" value="<?php echo $tax_rate; ?>" step="0.1">
                        </div>
                        <div class="tax-item">
                            <label>Tax Amount (UGX)</label>
                            <input type="text" id="taxAmount" readonly style="background:#f1f5f9;" value="<?php echo number_format($quote['tax'], 0); ?>">
                        </div>
                    </div>
                </div>

                <!-- Totals -->
                <div class="totals-box">
                    <div class="total-row">
                        <span>Subtotal:</span>
                        <span id="subtotal"><?php echo number_format($quote['subtotal'], 0); ?></span>
                    </div>
                    <div class="total-row" id="taxRow">
                        <span>Tax (VAT):</span>
                        <span id="taxAmountDisplay"><?php echo number_format($quote['tax'], 0); ?></span>
                    </div>
                    <div class="total-row grand-total">
                        <span>GRAND TOTAL:</span>
                        <span id="grandTotal"><?php echo number_format($quote['total_amount'], 0); ?></span>
                    </div>
                </div>

                <div class="payment-note">
                    <strong>⚠️ Payment Terms:</strong> 70% payment is needed before commencement of any work and 30% on completion.
                </div>
                
                <div class="bank-details">
                    <p>Payments Can be Made to <strong>SAVANT MOTORS</strong><br>
                    ABSA A/C NO: 6007717553 | Mobile Money: 915573</p>
                </div>

                <div class="action-buttons">
                    <a href="quotations.php" class="btn btn-cancel"><i class="fas fa-times"></i> Cancel</a>
                    <button type="submit" name="update_quotation" class="btn btn-save"><i class="fas fa-save"></i> Update Quotation</button>
                </div>
            </form>
        </div>
        <footer>
            "Testify" – Generated by Savant Motors ERP
        </footer>
    </div>
</body>
</html>