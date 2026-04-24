<?php
// purchases/receive.php — Confirm Purchase Order & Sync to Unified Inventory
session_start();
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    header('Location: ../index.php'); exit();
}

$id      = (int)($_GET['id'] ?? 0);
$error   = '';
$success = '';
$purchase = null;
$items    = [];

if (!$id) { header('Location: index.php'); exit(); }

try {
    $conn = new PDO("mysql:host=localhost;dbname=savant_motors_pos", "root", "");
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    /* ── Detect flexible column names ──────────────────────────────────── */
    $invCols     = $conn->query("SHOW COLUMNS FROM inventory")->fetchAll(PDO::FETCH_COLUMN);
    $costCol     = in_array('unit_cost',       $invCols) ? 'unit_cost'
                 :(in_array('cost_price',      $invCols) ? 'cost_price'
                 :(in_array('purchase_price',  $invCols) ? 'purchase_price' : 'buying_price'));
    $stockCol    = in_array('quantity',        $invCols) ? 'quantity'
                 :(in_array('current_stock',   $invCols) ? 'current_stock'  : 'stock');
    $nameCol     = in_array('item_name',       $invCols) ? 'item_name'
                 :(in_array('product_name',    $invCols) ? 'product_name'   : 'name');
    $skuCol      = in_array('sku',             $invCols) ? 'sku'
                 :(in_array('item_code',       $invCols) ? 'item_code'      : null);
    $sellCol     = in_array('selling_price',   $invCols) ? 'selling_price'
                 :(in_array('sale_price',      $invCols) ? 'sale_price'
                 :(in_array('price',           $invCols) ? 'price'          : null));
    $catCol      = in_array('category',        $invCols) ? 'category'
                 :(in_array('category_id',     $invCols) ? 'category_id'   : null);
    $hasUpdatedAt = in_array('updated_at',     $invCols);
    $hasActive    = in_array('is_active',      $invCols);
    $hasCreatedAt = in_array('created_at',     $invCols);

    /* ── Unified products table detection ──────────────────────────────── */
    // Try to detect a unified products/services table
    $tables = $conn->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
    $unifiedTable = null;
    foreach (['unified_products','products','products_services','items','catalogue'] as $t) {
        if (in_array($t, $tables)) { $unifiedTable = $t; break; }
    }

    /* ── Load purchase ──────────────────────────────────────────────────── */
    $purchase = $conn->prepare(
        "SELECT p.*, s.supplier_name
         FROM purchases p
         LEFT JOIN suppliers s ON s.id = p.supplier_id
         WHERE p.id = ?"
    );
    $purchase->execute([$id]);
    $purchase = $purchase->fetch(PDO::FETCH_ASSOC);

    if (!$purchase) { header('Location: index.php'); exit(); }
    if ($purchase['status'] === 'received') {
        $_SESSION['success'] = 'This purchase order has already been received.';
        header('Location: index.php'); exit();
    }
    if ($purchase['status'] === 'cancelled') {
        $error = 'Cannot receive a cancelled purchase order.';
    }

    /* ── Load purchase items ────────────────────────────────────────────── */
    $items = $conn->prepare(
        "SELECT pi.*,
                i.$nameCol AS inv_name,
                i.$costCol AS inv_cost,
                i.$stockCol AS inv_stock
                " . ($skuCol  ? ", i.$skuCol  AS inv_sku"  : "") . "
                " . ($sellCol ? ", i.$sellCol AS inv_sell" : "") . "
                " . ($catCol  ? ", i.$catCol  AS inv_cat"  : "") . "
         FROM purchase_items pi
         LEFT JOIN inventory i ON i.id = pi.inventory_id
         WHERE pi.purchase_id = ?"
    );
    $items->execute([$id]);
    $items = $items->fetchAll(PDO::FETCH_ASSOC);

    /* ── Handle POST (confirm receipt) ─────────────────────────────────── */
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && empty($error)) {
        $received_date = trim($_POST['received_date'] ?? date('Y-m-d'));
        $notes         = trim($_POST['notes'] ?? '');

        $conn->beginTransaction();
        try {
            $syncCount  = 0;
            $newCount   = 0;

            foreach ($items as $item) {
                $inv_id   = (int)$item['inventory_id'];
                $qty      = (float)$item['quantity'];
                $unitCost = (float)$item['unit_cost'];

                /* ── 1. Update stock in inventory ──────────────────────── */
                if ($inv_id) {
                    $updParts = ["$stockCol = $stockCol + :qty", "$costCol = :cost"];
                    if ($hasUpdatedAt) $updParts[] = "updated_at = NOW()";
                    $upd = $conn->prepare(
                        "UPDATE inventory SET " . implode(', ', $updParts) . " WHERE id = :id"
                    );
                    $upd->execute([':qty' => $qty, ':cost' => $unitCost, ':id' => $inv_id]);

                    /* ── 2. Sync / upsert into unified products table ─── */
                    if ($unifiedTable) {
                        // Detect unified table columns
                        $uCols = $conn->query("SHOW COLUMNS FROM $unifiedTable")->fetchAll(PDO::FETCH_COLUMN);
                        $uName  = in_array('item_name',     $uCols) ? 'item_name'
                                :(in_array('product_name',  $uCols) ? 'product_name' : 'name');
                        $uQty   = in_array('quantity',      $uCols) ? 'quantity'
                                :(in_array('stock',         $uCols) ? 'stock'        : 'current_stock');
                        $uCost  = in_array('cost_price',    $uCols) ? 'cost_price'
                                :(in_array('unit_cost',     $uCols) ? 'unit_cost'    : 'buying_price');
                        $uSell  = in_array('selling_price', $uCols) ? 'selling_price'
                                :(in_array('sale_price',    $uCols) ? 'sale_price'   : null);
                        $uSku   = in_array('sku',           $uCols) ? 'sku'
                                :(in_array('item_code',     $uCols) ? 'item_code'    : null);
                        $uCat   = in_array('category',      $uCols) ? 'category'     : null;
                        $uSrc   = in_array('source',        $uCols) ? 'source'       : null;
                        $uSrcId = in_array('inventory_id',  $uCols) ? 'inventory_id' : null;
                        $uUpd   = in_array('updated_at',    $uCols);
                        $uCrt   = in_array('created_at',    $uCols);
                        $uAct   = in_array('is_active',     $uCols);
                        $uType  = in_array('type',          $uCols) ? 'type'         : null;

                        // Check if already exists in unified table by inventory_id
                        $exists = null;
                        if ($uSrcId) {
                            $chk = $conn->prepare("SELECT id, $uQty FROM $unifiedTable WHERE inventory_id = ?");
                            $chk->execute([$inv_id]);
                            $exists = $chk->fetch(PDO::FETCH_ASSOC);
                        }
                        if (!$exists) {
                            // Try by name
                            $chk2 = $conn->prepare("SELECT id, $uQty FROM $unifiedTable WHERE $uName = ?");
                            $chk2->execute([$item['inv_name']]);
                            $exists = $chk2->fetch(PDO::FETCH_ASSOC);
                        }

                        if ($exists) {
                            // UPDATE existing unified record
                            $setParts = ["$uQty = $uQty + :qty", "$uCost = :cost"];
                            if ($uUpd) $setParts[] = "updated_at = NOW()";
                            $uStmt = $conn->prepare(
                                "UPDATE $unifiedTable SET " . implode(', ', $setParts) . " WHERE id = :uid"
                            );
                            $uStmt->execute([':qty' => $qty, ':cost' => $unitCost, ':uid' => $exists['id']]);
                        } else {
                            // INSERT new unified record
                            $cols   = [$uName, $uQty, $uCost];
                            $vals   = [':iname', ':iqty', ':icost'];
                            $bind   = [
                                ':iname' => $item['inv_name'],
                                ':iqty'  => $qty,
                                ':icost' => $unitCost,
                            ];
                            if ($uSku  && !empty($item['inv_sku']))  { $cols[] = $uSku;  $vals[] = ':isku';  $bind[':isku']  = $item['inv_sku']; }
                            if ($uSell && !empty($item['inv_sell'])) { $cols[] = $uSell; $vals[] = ':isell'; $bind[':isell'] = $item['inv_sell']; }
                            if ($uCat  && !empty($item['inv_cat']))  { $cols[] = $uCat;  $vals[] = ':icat';  $bind[':icat']  = $item['inv_cat']; }
                            if ($uSrc)   { $cols[] = $uSrc;   $vals[] = ':isrc';   $bind[':isrc']   = 'purchase'; }
                            if ($uSrcId) { $cols[] = $uSrcId; $vals[] = ':isrcid'; $bind[':isrcid'] = $inv_id; }
                            if ($uType)  { $cols[] = $uType;  $vals[] = ':itype';  $bind[':itype']  = 'product'; }
                            if ($uAct)   { $cols[] = 'is_active'; $vals[] = ':iact'; $bind[':iact'] = 1; }
                            if ($uCrt)   { $cols[] = 'created_at'; $vals[] = ':icrt'; $bind[':icrt'] = date('Y-m-d H:i:s'); }
                            if ($uUpd)   { $cols[] = 'updated_at'; $vals[] = ':iupd'; $bind[':iupd'] = date('Y-m-d H:i:s'); }

                            $ins = $conn->prepare(
                                "INSERT INTO $unifiedTable (" . implode(',', $cols) . ")
                                 VALUES (" . implode(',', $vals) . ")"
                            );
                            $ins->execute($bind);
                            $newCount++;
                        }
                        $syncCount++;
                    }
                }
            }

            /* ── 3. Mark purchase as received ──────────────────────────── */
            $conn->prepare(
                "UPDATE purchases SET status='received', received_date=?, notes=CONCAT(COALESCE(notes,''),:notes), updated_at=NOW() WHERE id=?"
            )->execute([$received_date, $notes ? "\n[Receipt note] $notes" : '', $id]);

            /* ── 4. Log to stock movements (if table exists) ───────────── */
            if (in_array('stock_movements', $tables)) {
                $smCols = $conn->query("SHOW COLUMNS FROM stock_movements")->fetchAll(PDO::FETCH_COLUMN);
                foreach ($items as $item) {
                    $smBind = [
                        'inventory_id' => (int)$item['inventory_id'],
                        'quantity'     => (float)$item['quantity'],
                        'movement_type'=> 'purchase_in',
                        'reference_id' => $id,
                        'notes'        => 'PO#' . $purchase['po_number'] . ' received',
                        'created_at'   => date('Y-m-d H:i:s'),
                    ];
                    $validBind = array_intersect_key($smBind, array_flip($smCols));
                    if (count($validBind) >= 2) {
                        $k = array_keys($validBind);
                        $conn->prepare(
                            "INSERT INTO stock_movements (" . implode(',', $k) . ")
                             VALUES (:" . implode(',:', $k) . ")"
                        )->execute(array_combine(array_map(fn($x)=>":$x",$k), array_values($validBind)));
                    }
                }
            }

            $conn->commit();

            $msg = "Purchase order <strong>{$purchase['po_number']}</strong> confirmed. "
                 . count($items) . " item(s) added to inventory";
            if ($unifiedTable) $msg .= " and synced to unified products ($syncCount updated";
            if ($newCount)     $msg .= ", $newCount new";
            if ($unifiedTable) $msg .= ")";
            $msg .= ".";

            $_SESSION['success'] = $msg;
            header('Location: index.php'); exit();

        } catch (Exception $ex) {
            $conn->rollBack();
            $error = 'Failed to confirm receipt: ' . $ex->getMessage();
        }
    }

} catch (PDOException $e) {
    $error = 'Database error: ' . $e->getMessage();
    $purchase = $purchase ?: [];
    $items    = $items    ?: [];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Receive Purchase Order | SAVANT MOTORS</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root{--primary:#1e40af;--primary-dark:#1e3a8a;--success:#10b981;--danger:#ef4444;--warning:#f59e0b;}
        body{background:#f0f2f5;font-family:'Segoe UI',Tahoma,Geneva,Verdana,sans-serif;}
        .navbar-custom{background:linear-gradient(135deg,var(--primary),var(--primary-dark));box-shadow:0 2px 10px rgba(0,0,0,.1);}
        .sidebar{position:fixed;left:0;top:56px;height:calc(100% - 56px);width:250px;
            background:white;box-shadow:2px 0 5px rgba(0,0,0,.05);overflow-y:auto;}
        .sidebar-menu{padding:20px 0;}
        .menu-item{padding:12px 24px;display:flex;align-items:center;gap:12px;color:#4a5568;
            text-decoration:none;transition:all .3s;}
        .menu-item:hover,.menu-item.active{background:#e0e7ff;color:var(--primary);border-left:3px solid var(--primary);}
        .menu-item i{width:24px;}
        .main-content{margin-left:250px;padding:20px;}
        .card{border:none;border-radius:15px;box-shadow:0 2px 10px rgba(0,0,0,.05);}
        .card-header-custom{background:linear-gradient(135deg,var(--primary),var(--primary-dark));
            color:white;border-radius:15px 15px 0 0;padding:1.25rem 1.5rem;}

        /* Item rows */
        .item-row{background:white;border:1px solid #e2e8f0;border-radius:10px;
            padding:1rem 1.25rem;margin-bottom:.75rem;transition:box-shadow .2s;}
        .item-row:hover{box-shadow:0 4px 12px rgba(0,0,0,.08);}
        .item-badge{display:inline-block;padding:3px 10px;border-radius:20px;
            font-size:.72rem;font-weight:700;background:#dbeafe;color:#1e40af;}
        .item-qty{font-size:1.4rem;font-weight:800;color:#1e40af;}
        .item-cost{font-size:.85rem;font-weight:600;color:#059669;}

        /* Sync indicator */
        .sync-pill{display:inline-flex;align-items:center;gap:.4rem;
            padding:4px 12px;border-radius:999px;font-size:.72rem;font-weight:700;
            background:#dcfce7;color:#166534;border:1px solid #bbf7d0;}
        .sync-pill i{font-size:.65rem;}

        /* Confirm strip */
        .confirm-strip{background:linear-gradient(135deg,#ecfdf5,#d1fae5);
            border:1.5px solid #6ee7b7;border-radius:12px;padding:1.25rem 1.5rem;}
        .status-badge{padding:5px 14px;border-radius:20px;font-size:.75rem;font-weight:700;}
        .status-ordered{background:#fff3e0;color:#f59e0b;}
        .status-received{background:#dcfce7;color:#10b981;}
        .status-cancelled{background:#fee2e2;color:#ef4444;}

        @media(max-width:768px){.sidebar{display:none;}.main-content{margin-left:0;}}
    </style>
</head>
<body>

<!-- Navbar -->
<nav class="navbar navbar-custom navbar-dark fixed-top">
    <div class="container-fluid">
        <a class="navbar-brand" href="#">
            <i class="fas fa-shopping-cart me-2"></i><strong>Purchase Management</strong>
        </a>
        <div class="dropdown">
            <button class="btn btn-link text-white dropdown-toggle" data-bs-toggle="dropdown">
                <i class="fas fa-user-circle"></i> <?php echo htmlspecialchars($_SESSION['full_name'] ?? 'User'); ?>
            </button>
            <ul class="dropdown-menu dropdown-menu-end">
                <li><a class="dropdown-item" href="../dashboard_erp.php"><i class="fas fa-tachometer-alt me-2"></i>Dashboard</a></li>
                <li><hr class="dropdown-divider"></li>
                <li><a class="dropdown-item" href="../logout.php"><i class="fas fa-sign-out-alt me-2"></i>Logout</a></li>
            </ul>
        </div>
    </div>
</nav>

<!-- Sidebar -->
<div class="sidebar">
    <div class="sidebar-menu">
        <a href="../dashboard_erp.php" class="menu-item"><i class="fas fa-tachometer-alt"></i> Dashboard</a>
        <a href="index.php" class="menu-item active"><i class="fas fa-shopping-cart"></i> Purchases</a>
        <a href="../suppliers.php" class="menu-item"><i class="fas fa-truck"></i> Suppliers</a>
        <a href="../unified/index.php" class="menu-item"><i class="fas fa-boxes"></i> Inventory</a>
        <hr class="my-3">
        <a href="create.php" class="menu-item"><i class="fas fa-plus-circle"></i> New Purchase Order</a>
    </div>
</div>

<!-- Main Content -->
<div class="main-content" style="margin-top:56px;">
<div class="container-fluid">

    <?php if ($error): ?>
    <div class="alert alert-danger alert-dismissible fade show">
        <i class="fas fa-exclamation-circle me-2"></i><?php echo $error; ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
    <?php endif; ?>

    <!-- Page header -->
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h2 class="fw-bold mb-1">
                <i class="fas fa-clipboard-check text-success me-2"></i>
                Confirm Receipt
            </h2>
            <p class="text-muted mb-0">Review items and confirm — stock will update automatically</p>
        </div>
        <a href="index.php" class="btn btn-outline-secondary">
            <i class="fas fa-arrow-left me-2"></i> Back to Purchases
        </a>
    </div>

    <?php if ($purchase): ?>

    <!-- PO Summary card -->
    <div class="card mb-4">
        <div class="card-header-custom">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <h5 class="mb-1 fw-bold">
                        <i class="fas fa-file-alt me-2"></i>
                        <?php echo htmlspecialchars($purchase['po_number']); ?>
                    </h5>
                    <small class="opacity-75">
                        <?php echo htmlspecialchars($purchase['supplier_name'] ?? 'Unknown Supplier'); ?>
                        &nbsp;·&nbsp;
                        <?php echo date('d M Y', strtotime($purchase['purchase_date'])); ?>
                    </small>
                </div>
                <div class="text-end">
                    <span class="status-badge status-<?php echo $purchase['status']; ?>">
                        <?php echo strtoupper($purchase['status']); ?>
                    </span>
                    <div class="mt-1" style="font-size:.85rem;opacity:.85;">
                        Total: <strong>UGX <?php echo number_format($purchase['total_amount']); ?></strong>
                    </div>
                </div>
            </div>
        </div>
        <div class="card-body">
            <div class="row g-3 text-center">
                <div class="col-4">
                    <div class="text-muted" style="font-size:.72rem;text-transform:uppercase;letter-spacing:.06em;">Items</div>
                    <div class="fw-bold fs-5"><?php echo count($items); ?></div>
                </div>
                <div class="col-4">
                    <div class="text-muted" style="font-size:.72rem;text-transform:uppercase;letter-spacing:.06em;">Total Units</div>
                    <div class="fw-bold fs-5"><?php echo number_format(array_sum(array_column($items,'quantity'))); ?></div>
                </div>
                <div class="col-4">
                    <div class="text-muted" style="font-size:.72rem;text-transform:uppercase;letter-spacing:.06em;">Will Sync To</div>
                    <div class="fw-bold fs-5" style="color:#059669;">
                        <?php echo $unifiedTable ? ucwords(str_replace('_',' ',$unifiedTable)) : 'Inventory'; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Items list -->
    <div class="card mb-4">
        <div class="card-body">
            <h6 class="fw-bold mb-3">
                <i class="fas fa-list me-2 text-primary"></i>
                Items to Receive
                <span class="sync-pill ms-2">
                    <i class="fas fa-sync-alt"></i> Will sync to unified products
                </span>
            </h6>

            <?php if (empty($items)): ?>
            <div class="text-center text-muted py-4">
                <i class="fas fa-inbox fa-2x mb-2 d-block opacity-50"></i>
                No items found on this purchase order.
            </div>
            <?php else: ?>
            <?php foreach ($items as $i => $item): ?>
            <div class="item-row">
                <div class="row align-items-center">
                    <div class="col-md-5">
                        <div class="d-flex align-items-center gap-2 mb-1">
                            <span class="item-badge">#<?php echo $i+1; ?></span>
                            <strong><?php echo htmlspecialchars($item['item_name'] ?? $item['inv_name'] ?? 'Unknown Item'); ?></strong>
                        </div>
                        <?php if (!empty($item['inv_sku'])): ?>
                        <div style="font-size:.73rem;color:#64748b;">
                            SKU: <?php echo htmlspecialchars($item['inv_sku']); ?>
                        </div>
                        <?php endif; ?>
                    </div>
                    <div class="col-md-2 text-center">
                        <div style="font-size:.65rem;color:#94a3b8;text-transform:uppercase;">Qty to Receive</div>
                        <div class="item-qty"><?php echo number_format($item['quantity'],0); ?></div>
                    </div>
                    <div class="col-md-2 text-center">
                        <div style="font-size:.65rem;color:#94a3b8;text-transform:uppercase;">Unit Cost</div>
                        <div class="item-cost">UGX <?php echo number_format($item['unit_cost']); ?></div>
                    </div>
                    <div class="col-md-2 text-center">
                        <div style="font-size:.65rem;color:#94a3b8;text-transform:uppercase;">Line Total</div>
                        <div style="font-weight:700;color:#1e40af;">UGX <?php echo number_format($item['quantity'] * $item['unit_cost']); ?></div>
                    </div>
                    <div class="col-md-1 text-center">
                        <div style="font-size:.65rem;color:#94a3b8;text-transform:uppercase;">In Stock</div>
                        <div style="font-weight:700;color:#64748b;"><?php echo number_format($item['inv_stock'] ?? 0); ?></div>
                    </div>
                </div>
                <!-- Sync arrow indicator -->
                <div class="mt-2 pt-2 border-top d-flex align-items-center gap-2" style="font-size:.73rem;color:#059669;">
                    <i class="fas fa-arrow-right"></i>
                    Stock will increase by <strong><?php echo number_format($item['quantity']); ?></strong>
                    → New total: <strong><?php echo number_format(($item['inv_stock'] ?? 0) + $item['quantity']); ?></strong>
                    <?php if ($unifiedTable): ?>
                    &nbsp;·&nbsp;<i class="fas fa-sync-alt"></i>
                    Synced to <em><?php echo htmlspecialchars($unifiedTable); ?></em>
                    <?php endif; ?>
                </div>
            </div>
            <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>

    <!-- Confirm form -->
    <?php if ($purchase['status'] === 'ordered' && empty($items) === false): ?>
    <div class="confirm-strip mb-4">
        <form method="POST" action="receive.php?id=<?php echo $id; ?>" id="confirmForm">
            <div class="row g-3 align-items-end">
                <div class="col-md-3">
                    <label class="form-label fw-semibold" style="font-size:.8rem;">
                        <i class="fas fa-calendar-check me-1"></i> Date Received
                    </label>
                    <input type="date" name="received_date" class="form-control"
                           value="<?php echo date('Y-m-d'); ?>" max="<?php echo date('Y-m-d'); ?>" required>
                </div>
                <div class="col-md-6">
                    <label class="form-label fw-semibold" style="font-size:.8rem;">
                        <i class="fas fa-sticky-note me-1"></i> Receipt Notes (optional)
                    </label>
                    <input type="text" name="notes" class="form-control"
                           placeholder="e.g. Partial delivery, items in good condition…">
                </div>
                <div class="col-md-3 d-flex gap-2">
                    <a href="index.php" class="btn btn-outline-secondary w-50">
                        Cancel
                    </a>
                    <button type="button" class="btn btn-success w-50 fw-bold" onclick="confirmReceive()">
                        <i class="fas fa-check-circle me-1"></i> Confirm
                    </button>
                </div>
            </div>

            <!-- What will happen summary -->
            <div class="mt-3 pt-3 border-top" style="font-size:.78rem;color:#065f46;">
                <strong><i class="fas fa-info-circle me-1"></i> What happens when you confirm:</strong>
                <ul class="mb-0 mt-1 ps-3">
                    <li>Purchase order status → <strong>Received</strong></li>
                    <li>Inventory stock updated for <strong><?php echo count($items); ?> item(s)</strong></li>
                    <?php if ($unifiedTable): ?>
                    <li>Items synced/updated in <strong><?php echo htmlspecialchars($unifiedTable); ?></strong> (unified products &amp; services)</li>
                    <?php endif; ?>
                    <?php if (in_array('stock_movements', $tables ?? [])): ?>
                    <li>Stock movement records created for full audit trail</li>
                    <?php endif; ?>
                </ul>
            </div>
        </form>
    </div>
    <?php elseif ($purchase['status'] !== 'ordered'): ?>
    <div class="alert alert-warning">
        <i class="fas fa-exclamation-triangle me-2"></i>
        This order cannot be received — current status is <strong><?php echo strtoupper($purchase['status']); ?></strong>.
    </div>
    <?php endif; ?>

    <?php endif; ?>

</div>
</div>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
function confirmReceive(){
    const total = <?php echo count($items); ?>;
    const po    = '<?php echo addslashes($purchase['po_number'] ?? ''); ?>';
    if(confirm(`Confirm receipt of PO ${po}?\n\n${total} item(s) will be added to inventory and synced to unified products.\n\nThis action cannot be undone.`)){
        document.getElementById('confirmForm').submit();
    }
}
</script>
</body>
</html>
