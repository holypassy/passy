<?php
session_start();

// ============================================================
// GUARD: If visited directly in a browser (not an API call),
// redirect to the main app instead of dumping raw JSON.
// ============================================================
$isPost    = $_SERVER['REQUEST_METHOD'] === 'POST';
$hasAction = isset($_GET['action']);
$isAjax    = !empty($_SERVER['HTTP_X_REQUESTED_WITH']) &&
              strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';
$wantsJson = strpos($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json') !== false;

if (!$isPost && !$hasAction && !$isAjax && !$wantsJson) {
    header('Location: index.php');
    exit;
}

// All responses from here are JSON
header('Content-Type: application/json');

// Initialize session catalog if not set
if (!isset($_SESSION['catalog'])) {
    $_SESSION['catalog'] = ['services' => [], 'products' => []];
}

// ============================================================
// GET — read data
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $action = $_GET['action'] ?? 'get_all';

    if ($action === 'get_all') {
        echo json_encode($_SESSION['catalog']);
        exit;
    }
    if ($action === 'get_services') {
        echo json_encode($_SESSION['catalog']['services']);
        exit;
    }
    if ($action === 'get_products') {
        echo json_encode($_SESSION['catalog']['products']);
        exit;
    }

    http_response_code(400);
    echo json_encode(['error' => 'Unknown action: ' . htmlspecialchars($action)]);
    exit;
}

// ============================================================
// POST — write / delete data
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // ---------- SAVE SERVICE ----------
    if (isset($_POST['save_service'])) {
        $service = [
            'id'                => time() . rand(100, 999),
            'service_name'      => trim($_POST['service_name']        ?? ''),
            'category'          => trim($_POST['category']            ?? 'Minor'),
            'standard_price'    => (float)($_POST['standard_price']   ?? 0),
            'estimated_duration'=> trim($_POST['estimated_duration']  ?? ''),
            'track_interval'    => isset($_POST['track_interval'])    ? 1 : 0,
            'service_interval'  => (int)($_POST['service_interval']   ?? 6),
            'interval_unit'     => trim($_POST['interval_unit']       ?? 'months'),
            'requires_parts'    => isset($_POST['requires_parts'])    ? 1 : 0,
            'description'       => trim($_POST['description']         ?? ''),
            'created_at'        => date('Y-m-d H:i:s'),
        ];

        if (empty($service['service_name'])) {
            http_response_code(400);
            echo json_encode(['error' => 'Service name is required']);
            exit;
        }

        $_SESSION['catalog']['services'][] = $service;
        echo json_encode(['success' => true, 'service' => $service]);
        exit;
    }

    // ---------- SAVE PRODUCT ----------
    if (isset($_POST['save_product'])) {
        $opening_stock = (int)($_POST['opening_stock'] ?? $_POST['quantity'] ?? 0);
        $unit_cost     = (float)($_POST['unit_cost']     ?? 0);
        $selling_price = (float)($_POST['selling_price'] ?? 0);

        $product = [
            'id'              => time() . rand(100, 999),
            'item_code'       => trim($_POST['item_code']        ?? ''),
            'product_name'    => trim($_POST['product_name']     ?? ''),
            'category'        => trim($_POST['category']         ?? 'General'),
            'unit_of_measure' => trim($_POST['unit_of_measure']  ?? 'units'),
            'unit_cost'       => $unit_cost,
            'selling_price'   => $selling_price,
            'quantity'        => $opening_stock,
            'opening_stock'   => $opening_stock,
            'reorder_level'   => (int)($_POST['reorder_level']   ?? 5),
            'description'     => trim($_POST['description']      ?? ''),
            'created_at'      => date('Y-m-d H:i:s'),
        ];

        if (empty($product['product_name'])) {
            http_response_code(400);
            echo json_encode(['error' => 'Product name is required']);
            exit;
        }

        $_SESSION['catalog']['products'][] = $product;
        echo json_encode(['success' => true, 'product' => $product]);
        exit;
    }

    // ---------- DELETE SERVICE ----------
    if (isset($_POST['delete_service'])) {
        $id = $_POST['service_id'] ?? null;
        if (!$id) {
            http_response_code(400);
            echo json_encode(['error' => 'service_id is required']);
            exit;
        }
        $_SESSION['catalog']['services'] = array_values(
            array_filter($_SESSION['catalog']['services'], fn($s) => $s['id'] != $id)
        );
        echo json_encode(['success' => true]);
        exit;
    }

    // ---------- DELETE PRODUCT ----------
    if (isset($_POST['delete_product'])) {
        $id = $_POST['product_id'] ?? null;
        if (!$id) {
            http_response_code(400);
            echo json_encode(['error' => 'product_id is required']);
            exit;
        }
        $_SESSION['catalog']['products'] = array_values(
            array_filter($_SESSION['catalog']['products'], fn($p) => $p['id'] != $id)
        );
        echo json_encode(['success' => true]);
        exit;
    }

    http_response_code(400);
    echo json_encode(['error' => 'Unknown POST action']);
    exit;
}

// ============================================================
// Any other HTTP method
// ============================================================
http_response_code(405);
echo json_encode(['error' => 'Method not allowed']);
