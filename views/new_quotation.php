 <?php
ini_set('error_log', 'php_errors.log');
error_reporting(E_ALL);
ini_set('display_errors', 1);
session_start();

if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    header('Location: index.php');
    exit();
}

$user_full_name = $_SESSION['full_name'] ?? 'User';
$user_role = $_SESSION['role'] ?? 'user';
$user_id = $_SESSION['user_id'] ?? 1;

$error_message = '';
$success_message = '';
$info_message = '';

try {
    $conn = new PDO("mysql:host=localhost;dbname=savant_motors_pos", "root", "");
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    function getTableColumns($conn, $table) {
        $stmt = $conn->query("SHOW COLUMNS FROM $table");
        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    }

    // Ensure quotations has the new columns (if needed)
    $quotationColumns = getTableColumns($conn, 'quotations');
    $newCols = [
        'customer_type' => "ENUM('individual','business') DEFAULT 'individual'",
        'company_name' => 'VARCHAR(150) DEFAULT NULL',
        'tin' => 'VARCHAR(50) DEFAULT NULL'
    ];
    foreach ($newCols as $col => $def) {
        if (!in_array($col, $quotationColumns)) {
            $conn->exec("ALTER TABLE quotations ADD COLUMN $col $def");
        }
    }

    // Get customers for dropdown
    $customers = $conn->query("SELECT id, full_name, telephone, email, address FROM customers WHERE deleted_at IS NULL ORDER BY full_name")->fetchAll(PDO::FETCH_ASSOC);

    // Generate next quotation number
    $last = $conn->query("SELECT quotation_number FROM quotations ORDER BY id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    if ($last) {
        $lastNum = intval(substr($last['quotation_number'], -4));
        $nextNum = str_pad($lastNum + 1, 4, '0', STR_PAD_LEFT);
        $quotation_number = 'QTN-' . date('Y') . '-' . $nextNum;
    } else {
        $quotation_number = 'QTN-' . date('Y') . '-0001';
    }

} catch(PDOException $e) {
    error_log("new_quotation.php DB init error: " . $e->getMessage());
    $customers = [];
    $quotation_number = 'QTN-' . date('Y') . '-0001';
    $error_message = "Database initialization error: " . $e->getMessage();
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    file_put_contents('debug_quotation.txt', date('Y-m-d H:i:s') . "\n" . print_r($_POST, true) . "\n\n", FILE_APPEND);

    try {
        $conn->beginTransaction();

        $quotation_number = $_POST['quotation_number'];
        $customer_id = (int)$_POST['customer_id'];
        $quotation_date = $_POST['quotation_date'];
        $vehicle_reg = $_POST['vehicle_reg'] ?? null;
        $vehicle_model = $_POST['vehicle_model'] ?? null;
        $chassis_no = $_POST['chassis_no'] ?? null;
        $odo_reading = $_POST['odo_reading'] ?? null;
        $notes = null;

        $customer_type = $_POST['customer_type'] ?? 'individual';
        $company_name = ($customer_type === 'business') ? trim($_POST['company_name'] ?? '') : null;
        $tin = ($customer_type === 'business') ? trim($_POST['tin'] ?? '') : null;

        // --- Retrieve customer name from the $customers array ---
        $customerName = '';
        foreach ($customers as $cust) {
            if ($cust['id'] == $customer_id) {
                $customerName = $cust['full_name'];
                break;
            }
        }

        // Collect items – only rows with a name, positive quantity, and positive rate
        $items = [];
        $item_names = $_POST['item'] ?? [];
        $descriptions = $_POST['desc'] ?? [];
        $ums = $_POST['um'] ?? [];
        $qtys = $_POST['qty'] ?? [];
        $rates = $_POST['rate'] ?? [];

        $subtotal = 0;

        for ($i = 0; $i < count($item_names); $i++) {
            $name = trim($item_names[$i] ?? '');
            $qty = (float)($qtys[$i] ?? 0);
            $rate = (float)($rates[$i] ?? 0);

            if ($name !== '' && $qty > 0 && $rate > 0) {
                $item_subtotal = $qty * $rate;
                $items[] = [
                    'item' => $name,
                    'desc' => trim($descriptions[$i] ?? ''),
                    'um' => trim($ums[$i] ?? ''),
                    'qty' => $qty,
                    'rate' => $rate,
                    'total' => $item_subtotal
                ];
                $subtotal += $item_subtotal;
            }
        }

        if ($subtotal <= 0) {
            throw new Exception("Please add at least one item with a name, positive quantity, and positive rate.");
        }

        // Tax handling
        if ($customer_type === 'business') {
            $tax_rate = (float)($_POST['tax_rate'] ?? 18);
            $tax_amount = $subtotal * ($tax_rate / 100);
            $total = $subtotal + $tax_amount;
        } else {
            $tax_rate = 0;
            $tax_amount = 0;
            $total = $subtotal;
        }

        // ----- Build quotation data array (without customer_name – column does not exist) -----
        $quotationData = [
            'quotation_number' => $quotation_number,
            'customer_id' => $customer_id,
            'quotation_date' => $quotation_date,
            'vehicle_reg' => $vehicle_reg,
            'vehicle_model' => $vehicle_model,
            'chassis_no' => $chassis_no,
            'odo_reading' => $odo_reading,
            'subtotal' => $subtotal,
            'tax' => $tax_amount,
            'total_amount' => $total,
            'notes' => $notes,
            'created_by' => $user_id,
            'status' => 'draft',
            'customer_type' => $customer_type,
            'company_name' => $company_name,
            'tin' => $tin
        ];

        // Optional columns (if they exist in the table)
        $optionalCols = ['valid_until', 'discount'];
        foreach ($optionalCols as $col) {
            if (in_array($col, $quotationColumns)) {
                $quotationData[$col] = ($col === 'valid_until') ? null : 0;
            }
        }

        // If vat_amount or service_fee columns exist, provide values
        if (in_array('vat_amount', $quotationColumns)) {
            $quotationData['vat_amount'] = $tax_amount;
        }
        if (in_array('service_fee', $quotationColumns)) {
            $quotationData['service_fee'] = 0;
        }

        // Build INSERT query
        $fields = array_keys($quotationData);
        $placeholders = implode(', ', array_fill(0, count($fields), '?'));
        $fieldsList = implode(', ', $fields);
        $sql = "INSERT INTO quotations ($fieldsList) VALUES ($placeholders)";

        error_log("INSERT SQL: " . $sql);
        error_log("INSERT VALUES: " . print_r(array_values($quotationData), true));

        $stmt = $conn->prepare($sql);
        $stmt->execute(array_values($quotationData));
        $quotation_id = $conn->lastInsertId();

        // ----- Insert items into quotation_items -----
        $itemColumns = getTableColumns($conn, 'quotation_items');
        $itemMapping = [
            'quotation_id' => $quotation_id,
            'item_number' => null,
            'description' => null,
            'quantity' => null,
            'unit_price' => null,
            'total' => null
        ];
        $extraItemFields = ['item', 'um', 'notes'];
        foreach ($extraItemFields as $f) {
            if (in_array($f, $itemColumns)) {
                $itemMapping[$f] = null;
            }
        }

        $usedFields = array_intersect(array_keys($itemMapping), $itemColumns);
        if (empty($usedFields)) {
            throw new Exception("No common columns found in quotation_items table for insertion.");
        }

        $itemPlaceholders = implode(', ', array_fill(0, count($usedFields), '?'));
        $itemFieldsList = implode(', ', $usedFields);
        $itemSql = "INSERT INTO quotation_items ($itemFieldsList) VALUES ($itemPlaceholders)";
        $itemStmt = $conn->prepare($itemSql);

        $itemNumber = 1;
        foreach ($items as $item) {
            $rowData = [];
            foreach ($usedFields as $field) {
                switch ($field) {
                    case 'quotation_id':
                        $rowData[] = $quotation_id;
                        break;
                    case 'item_number':
                        $rowData[] = $itemNumber++;
                        break;
                    case 'description':
                        $rowData[] = $item['desc'] ?: $item['item'];
                        break;
                    case 'quantity':
                        $rowData[] = $item['qty'];
                        break;
                    case 'unit_price':
                        $rowData[] = $item['rate'];
                        break;
                    case 'total':
                        $rowData[] = $item['total'];
                        break;
                    case 'item':
                        $rowData[] = $item['item'];
                        break;
                    case 'um':
                        $rowData[] = $item['um'];
                        break;
                    case 'notes':
                        $rowData[] = null;
                        break;
                    default:
                        $rowData[] = null;
                }
            }
            $itemStmt->execute($rowData);
        }

        $conn->commit();
        error_log("Commit successful for quotation ID: $quotation_id, total: $total");

        // Redirect based on action
        $action = $_POST['action'] ?? '';
        if ($action === 'save_and_print') {
            header("Location: print_quotation.php?id=" . $quotation_id);
            exit();
        } else {
            $_SESSION['success'] = "Quotation #$quotation_number created successfully!";
            header('Location: quotations.php');
            exit();
        }

    } catch (Exception $e) {
        if (isset($conn)) $conn->rollBack();
        $error_message = "Error: " . $e->getMessage();
        error_log("Quotation save error: " . $e->getMessage());
    }
}

// Retrieve session messages
$success_message = $_SESSION['success'] ?? null;
$error_message = $_SESSION['error'] ?? $error_message ?? null;
unset($_SESSION['success'], $_SESSION['error']);
if (isset($_SESSION['info'])) {
    $info_message = $_SESSION['info'];
    unset($_SESSION['info']);
}

// Set page title and subtitle for header
$page_title = 'QUOTATION';
$page_subtitle = $quotation_number;
include 'header.php';
?>

<style>
    /* Override header background to remove dark blue */
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

    /* Existing page styles */
    * {
        margin: 0;
        padding: 0;
        box-sizing: border-box;
    }
    body {
        font-family: 'Inter', sans-serif;
        background: linear-gradient(135deg, #e6f0ff 0%, #cce4ff 100%);
        padding: 2rem;
        position: relative;
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
        background: linear-gradient(135deg, #2563eb, #1e3a8a);
        padding: 1rem 2rem;
        display: flex;
        gap: 1rem;
        flex-wrap: wrap;
        align-items: center;
    }
    .toolbar button, .toolbar a {
        background: #2c3e50;
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
        background: #1e2b38;
        transform: translateY(-1px);
    }
    .toolbar .print-btn {
        background: #3b82f6;
        color: white;
    }
    .quote-content {
        padding: 2rem;
        position: relative;
    }
    .customer-type-group {
        margin-bottom: 20px;
        display: flex;
        gap: 20px;
        background: #f8fafc;
        padding: 12px 20px;
        border-radius: 16px;
        border: 1px solid #3b82f6;
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
        border: 1px solid #3b82f6;
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
    .quote-header {
        display: flex;
        justify-content: space-between;
        flex-wrap: wrap;
        gap: 1.5rem;
        margin-bottom: 2rem;
        background: #f8fafc;
        padding: 1rem;
        border-radius: 16px;
        border: 1px solid #3b82f6;
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
        border-bottom: 2px solid #3b82f6;
    }
    .auto-load-btn {
        background: #10b981;
        color: white;
        border: none;
        padding: 6px 12px;
        border-radius: 6px;
        cursor: pointer;
        font-size: 11px;
        font-weight: 600;
        margin-left: 10px;
    }
    .auto-load-btn:hover {
        background: #059669;
    }
    .vehicle-details {
        margin-top: 15px;
        padding: 15px;
        background: #f1f5f9;
        border-radius: 12px;
        display: none;
        border-left: 4px solid #10b981;
    }
    .vehicle-details.active {
        display: block;
    }
    .vehicle-details p {
        margin: 5px 0;
        font-size: 13px;
    }
    .vehicle-details strong {
        color: #1e40af;
    }
    .chassis-odo {
        flex: 1;
        background: #f1f5f9;
        padding: 1rem;
        border-radius: 12px;
    }
    .chassis-odo div {
        margin-bottom: 12px;
    }
    .chassis-odo label {
        font-weight: 600;
        display: inline-block;
        width: 70px;
    }
    .chassis-odo input {
        border: none;
        border-bottom: 1px solid #cbd5e1;
        background: transparent;
        padding: 4px;
        width: calc(100% - 80px);
        font-family: inherit;
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
        background: #3b82f6;
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
        background: #eff6ff;
    }
    .amount-cell {
        text-align: right;
        font-weight: 600;
    }
    .btn-add-row {
        background: #2563eb;
        color: white;
        border: none;
        padding: 6px 12px;
        border-radius: 6px;
        cursor: pointer;
        font-size: 12px;
        margin-top: 8px;
        font-weight: 600;
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
    .payment-note {
        background: #eff6ff;
        border-left: 4px solid #2563eb;
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
        border-top: 1px dashed #3b82f6;
        padding-top: 1rem;
    }
    footer {
        text-align: center;
        padding: 1rem;
        font-size: 12px;
        color: white;
        background: #1e3a8a;
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
        background: #d4edda;
        color: #155724;
        border-left: 4px solid #28a745;
    }
    .alert-danger {
        background: #f8d7da;
        color: #721c24;
        border-left: 4px solid #dc3545;
    }
    .alert-info {
        background: #d1ecf1;
        color: #0c5460;
        border-left: 4px solid #17a2b8;
    }
    .action-buttons {
        display: flex;
        gap: 15px;
        justify-content: flex-end;
        margin-top: 25px;
        flex-wrap: wrap;
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
    .btn-save-print {
        background: #2563eb;
        color: white;
    }
    .btn:hover {
        transform: translateY(-2px);
        filter: brightness(1.05);
    }
    .tax-section {
        background: #f8fafc;
        border-radius: 12px;
        padding: 20px;
        margin: 20px 0;
        border: 1px solid #3b82f6;
        display: none;
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
    .totals-box {
        background: #f8fafc;
        border-radius: 12px;
        padding: 20px;
        margin: 20px 0;
        border: 1px solid #3b82f6;
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
    .loading-spinner {
        display: inline-block;
        width: 16px;
        height: 16px;
        border: 2px solid #fff;
        border-radius: 50%;
        border-top-color: transparent;
        animation: spin 0.6s linear infinite;
        margin-left: 8px;
    }
    @keyframes spin {
        to { transform: rotate(360deg); }
    }
    @media (max-width: 768px) {
        .header-wrapper {
            flex-wrap: wrap;
        }
        .logo, .right-text {
            width: auto;
        }
        .right-text {
            margin-top: 10px;
        }
        .tax-grid {
            flex-direction: column;
        }
        .action-buttons {
            flex-direction: column;
        }
    }
</style>

<div class="container">
    <div class="toolbar">
        <button class="print-btn" onclick="window.print()"><i class="fas fa-print"></i> Print (Current Form)</button>
        <a href="quotations.php" class="reset-btn" style="background:#2c3e50;"><i class="fas fa-list"></i> Back to List</a>
    </div>

    <div class="quote-content">
        <?php if ($info_message): ?>
        <div class="alert alert-info"><i class="fas fa-info-circle"></i> <?php echo htmlspecialchars($info_message); ?></div>
        <?php endif; ?>
        <?php if ($success_message): ?>
        <div class="alert alert-success"><i class="fas fa-check-circle"></i> <?php echo htmlspecialchars($success_message); ?></div>
        <?php endif; ?>
        <?php if ($error_message): ?>
        <div class="alert alert-danger"><i class="fas fa-exclamation-triangle"></i> <?php echo htmlspecialchars($error_message); ?></div>
        <?php endif; ?>

        <form method="POST" id="quoteForm">
            <input type="hidden" name="quotation_number" value="<?php echo htmlspecialchars($quotation_number); ?>">

            <div class="customer-type-group">
                <label><input type="radio" name="customer_type" value="individual" checked onclick="toggleCustomerType()"> Individual</label>
                <label><input type="radio" name="customer_type" value="business" onclick="toggleCustomerType()"> Business</label>
            </div>

            <div id="businessFields" class="business-fields">
                <input type="text" name="company_name" placeholder="Company Name">
                <input type="text" name="tin" placeholder="TIN (Tax Identification Number)">
            </div>

            <div class="quote-header">
                <div class="quote-to">
                    <table>
                        <tr>
                            <td>CUSTOMER:</td>
                            <td>
                                <select name="customer_id" id="customerSelect" required>
                                    <option value="">Select Customer</option>
                                    <?php foreach ($customers as $cust): ?>
                                    <option value="<?php echo $cust['id']; ?>">
                                        <?php echo htmlspecialchars($cust['full_name']); ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                            </td>
                            <td>Date:</td>
                            <td><input type="date" name="quotation_date" value="<?php echo date('Y-m-d'); ?>"></td>
                        </tr>
                        <tr>
                            <td>REG NO:</td>
                            <td colspan="3">
                                <input type="text" name="vehicle_reg" id="vehicleReg" placeholder="Vehicle Registration">
                                <button type="button" class="auto-load-btn" onclick="loadVehicleFromJobCard(this)">
                                    <i class="fas fa-sync-alt"></i> Auto-load from Job Card
                                </button>
                            </td>
                        </tr>
                        <tr>
                            <td>VEHICLE MODEL:</td>
                            <td><input type="text" name="vehicle_model" id="vehicleModel" placeholder="Model"></td>
                            <td>Chassis No.:</td>
                            <td><input type="text" name="chassis_no" id="chassisNo" placeholder="Chassis Number"></td>
                        </tr>
                        <tr>
                            <td>ODO Reading:</td>
                            <td><input type="text" name="odo_reading" id="odoReading" placeholder="Odometer"></td>
                            <td></td>
                            <td></td>
                        </tr>
                    </table>
                    
                    <!-- Vehicle Details Display Section -->
                    <div id="vehicleDetails" class="vehicle-details">
                        <p><strong><i class="fas fa-truck"></i> Last Job Card Vehicle Info:</strong></p>
                        <p><strong>Reg No:</strong> <span id="displayReg">-</span></p>
                        <p><strong>Model:</strong> <span id="displayModel">-</span></p>
                        <p><strong>Chassis No:</strong> <span id="displayChassis">-</span></p>
                        <p><strong>ODO Reading:</strong> <span id="displayOdo">-</span> km</p>
                    </div>
                </div>
            </div>

            <table class="items-table" id="itemsTable">
                <thead>
                    <tr>
                        <th style="width:40px">NO</th>
                        <th style="width:150px">ITEM</th>
                        <th>DESCRIPTION</th>
                        <th style="width:60px">U/M</th>
                        <th style="width:70px">QTY</th>
                        <th style="width:100px">RATE (UGX)</th>
                        <th style="width:120px">AMOUNT (UGX)</th>
                        <th style="width:40px"></th>
                    </tr>
                </thead>
                <tbody id="itemsBody">
                    <tr class="item-row">
                        <td class="row-number">1</td>
                        <td><input type="text" name="item[]" class="item-input" placeholder="Item name (required)"></td>
                        <td><textarea name="desc[]" rows="1" class="desc-input" placeholder="Description"></textarea></td>
                        <td><input type="text" name="um[]" class="um-input" placeholder="Unit"></td>
                        <td><input type="number" name="qty[]" value="1" step="0.01" class="qty-input" style="text-align:right"></td>
                        <td><input type="number" name="rate[]" value="0" step="0.01" class="rate-input" style="text-align:right" placeholder="Enter rate"></td>
                        <td class="amount-cell">0</td>
                        <td><button type="button" class="btn-remove-row" onclick="removeRow(this)">✖</button></td>
                    </tr>
                </tbody>
                <tfoot>
                    <tr>
                        <td colspan="7" style="text-align:right; border:none;">
                            <button type="button" class="btn-add-row" onclick="addRow()">+ Add New Item</button>
                        </td>
                        <td style="border:none;"></td>
                    </tr>
                </tfoot>
            </table>

            <div id="taxSection" class="tax-section" style="display: none;">
                <div class="tax-grid">
                    <div class="tax-item">
                        <label>Tax Rate (%)</label>
                        <input type="number" name="tax_rate" id="taxRate" value="18" step="0.1" onchange="recalcTable()">
                    </div>
                    <div class="tax-item">
                        <label>Tax Amount (UGX)</label>
                        <input type="text" id="taxAmount" readonly style="background:#f1f5f9;">
                    </div>
                </div>
            </div>

            <div class="totals-box">
                <div class="total-row"><span>Subtotal:</span><span id="subtotal">0</span></div>
                <div class="total-row" id="taxRow"><span>Tax (VAT):</span><span id="taxAmountDisplay">0</span></div>
                <div class="total-row grand-total"><span>GRAND TOTAL:</span><span id="grandTotal">0</span></div>
            </div>

            <div class="payment-note"><strong>⚠️ Payment Terms:</strong> 70% payment is needed before commencement of any work and 30% on completion.</div>
            <div class="bank-details"><p>Payments Can be Made to <strong>SAVANT MOTORS</strong><br>ABSA A/C NO: 6007717553  |  Mobile Money: 915573</p></div>

            <div class="action-buttons">
                <a href="quotations.php" class="btn btn-cancel"><i class="fas fa-times"></i> Cancel</a>
                <button type="submit" name="action" value="save_and_print" class="btn btn-save-print"><i class="fas fa-print"></i> Save & Print</button>
            </div>
        </form>
    </div>
    <footer>
        "Testify" – Generated by Savant Motors ERP
    </footer>
</div>

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
            const qty = parseFloat(row.querySelector('input[name*="qty"]')?.value) || 0;
            const rate = parseFloat(row.querySelector('input[name*="rate"]')?.value) || 0;
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
            <td><input type="text" name="item[]" class="item-input" placeholder="Item name (required)"></td>
            <td><textarea name="desc[]" rows="1" class="desc-input" placeholder="Description"></textarea></td>
            <td><input type="text" name="um[]" class="um-input" placeholder="Unit"></td>
            <td><input type="number" name="qty[]" value="1" step="0.01" class="qty-input" style="text-align:right"></td>
            <td><input type="number" name="rate[]" value="0" step="0.01" class="rate-input" style="text-align:right" placeholder="Enter rate"></td>
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
        document.getElementById('taxRate').addEventListener('input', recalcTable);
    }

    function validateForm() {
        const rows = document.querySelectorAll('#itemsBody .item-row');
        let valid = false;
        for (let row of rows) {
            const itemName = row.querySelector('input[name="item[]"]')?.value.trim();
            const qty = parseFloat(row.querySelector('input[name*="qty"]')?.value) || 0;
            const rate = parseFloat(row.querySelector('input[name*="rate"]')?.value) || 0;
            if (itemName && qty > 0 && rate > 0) {
                valid = true;
                break;
            }
        }
        if (!valid) {
            alert("Please add at least one item with a name, positive quantity, and positive rate.");
            return false;
        }
        
        // Check if customer is selected
        const customerSelect = document.getElementById('customerSelect');
        if (!customerSelect.value) {
            alert("Please select a customer.");
            return false;
        }
        
        return true;
    }

    // Function to load vehicle details from the latest job card
    async function loadVehicleFromJobCard(btn) {
        const customerId = document.getElementById('customerSelect').value;
        
        if (!customerId) {
            showToast('Please select a customer first.', 'warning');
            return;
        }

        // Accept btn passed directly from onclick, fall back to event
        const button = btn || (typeof event !== 'undefined' && event?.target?.closest('.auto-load-btn'));
        if (!button) return;

        const originalText = button.innerHTML;
        button.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Loading...';
        button.disabled = true;
        
        try {
            const response = await fetch(`get_customer_vehicle.php?customer_id=${encodeURIComponent(customerId)}`);

            if (!response.ok) {
                throw new Error(`Server returned ${response.status}. Check that get_customer_vehicle.php exists.`);
            }

            // Guard against non-JSON response (e.g. PHP fatal/notice output)
            const text = await response.text();
            let data;
            try {
                data = JSON.parse(text);
            } catch(parseErr) {
                console.error('Non-JSON response from get_customer_vehicle.php:', text.slice(0, 300));
                throw new Error('Server returned an invalid response. Check get_customer_vehicle.php for PHP errors.');
            }
                
            if (data.success && data.vehicle) {
                const v = data.vehicle;
                // Auto-fill form fields (only overwrite if value returned)
                if (v.vehicle_reg)       document.getElementById('vehicleReg').value   = v.vehicle_reg;
                if (v.vehicle_model)     document.getElementById('vehicleModel').value  = v.vehicle_model;
                if (v.chassis_no)        document.getElementById('chassisNo').value     = v.chassis_no;
                if (v.odometer_reading)  document.getElementById('odoReading').value    = v.odometer_reading;

                // Update the vehicle details display panel
                document.getElementById('displayReg').textContent     = v.vehicle_reg       || '-';
                document.getElementById('displayModel').textContent    = v.vehicle_model     || '-';
                document.getElementById('displayChassis').textContent  = v.chassis_no        || '-';
                document.getElementById('displayOdo').textContent      = v.odometer_reading  || '-';
                document.getElementById('vehicleDetails').classList.add('active');

                showToast('Vehicle details loaded from job card!', 'success');
            } else {
                document.getElementById('vehicleDetails').classList.remove('active');
                showToast(data.message || 'No job card found for this customer. Please enter vehicle details manually.', 'warning');
            }
        } catch (error) {
            console.error('loadVehicleFromJobCard error:', error);
            showToast('Could not load vehicle details: ' + error.message, 'error');
        } finally {
            button.innerHTML = originalText;
            button.disabled = false;
        }
    }

    function showToast(message, type) {
        const toast = document.createElement('div');
        toast.innerHTML = message;
        toast.style.cssText = `
            position: fixed;
            bottom: 20px;
            right: 20px;
            background: ${type === 'success' ? '#10b981' : (type === 'warning' ? '#f59e0b' : '#ef4444')};
            color: white;
            padding: 12px 20px;
            border-radius: 8px;
            z-index: 9999;
            box-shadow: 0 4px 12px rgba(0,0,0,0.15);
            font-size: 14px;
            font-weight: 500;
        `;
        document.body.appendChild(toast);
        setTimeout(() => toast.remove(), 3000);
    }

    window.addEventListener('DOMContentLoaded', () => {
        initEventListeners();
        toggleCustomerType();
        recalcTable();
        document.getElementById('quoteForm').onsubmit = validateForm;
    });
</script>