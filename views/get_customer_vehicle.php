<?php
// get_customer_vehicle.php
// Returns the most recent job card vehicle details for a given customer.
// Called by new_quotation.php via fetch().

session_start();
header('Content-Type: application/json');

// Must be logged in
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Not authenticated']);
    exit();
}

$customer_id = isset($_GET['customer_id']) ? (int)$_GET['customer_id'] : 0;

if ($customer_id <= 0) {
    echo json_encode(['success' => false, 'message' => 'Invalid customer ID']);
    exit();
}

try {
    $conn = new PDO("mysql:host=localhost;dbname=savant_motors_pos", "root", "");
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // Detect which odometer column name is used in job_cards
    $cols = $conn->query("SHOW COLUMNS FROM job_cards")->fetchAll(PDO::FETCH_COLUMN);

    $odoCol = null;
    foreach (['odometer_reading', 'odo_reading', 'odometer', 'odo'] as $c) {
        if (in_array($c, $cols)) { $odoCol = $c; break; }
    }

    // Build SELECT — only include odo column if it exists
    $odoSelect = $odoCol ? ", jc.{$odoCol} as odometer_reading" : ", NULL as odometer_reading";

    $stmt = $conn->prepare("
        SELECT
            jc.vehicle_reg,
            jc.vehicle_model,
            jc.chassis_no
            {$odoSelect}
        FROM job_cards jc
        WHERE jc.customer_id = ?
          AND (jc.deleted_at IS NULL OR jc.deleted_at = '0000-00-00 00:00:00')
        ORDER BY jc.created_at DESC
        LIMIT 1
    ");
    $stmt->execute([$customer_id]);
    $vehicle = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($vehicle) {
        // Normalise: strip empty strings to null so JS can check truthiness cleanly
        foreach ($vehicle as $k => $v) {
            if ($v === '') $vehicle[$k] = null;
        }
        echo json_encode([
            'success' => true,
            'vehicle' => $vehicle
        ]);
    } else {
        echo json_encode([
            'success' => false,
            'message' => 'No job card found for this customer. Please enter vehicle details manually.'
        ]);
    }

} catch (PDOException $e) {
    error_log("get_customer_vehicle.php error: " . $e->getMessage());
    echo json_encode([
        'success' => false,
        'message' => 'Database error: ' . $e->getMessage()
    ]);
}
