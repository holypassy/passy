<?php
// customers/index.php - Customer Management Table View
session_start();

if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    header('Location: ../index.php');
    exit();
}

$user_full_name = $_SESSION['full_name'] ?? 'User';
$user_role = $_SESSION['role'] ?? 'user';

// Safe defaults in case DB fails
$error_message  = null;
$customers      = [];
$stats          = [];
$sources        = [];
$totalCustomers = 0;
$totalPages     = 1;

try {
    $conn = new PDO("mysql:host=localhost;dbname=savant_motors_pos", "root", "");
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // ── Ensure service_reminders table exists ──────────────────────────────
    $conn->exec("
        CREATE TABLE IF NOT EXISTS customer_service_reminders (
            id            INT AUTO_INCREMENT PRIMARY KEY,
            customer_id   INT NOT NULL,
            reminder_type ENUM('service_expiry','seasonal','custom') NOT NULL DEFAULT 'service_expiry',
            channel       SET('whatsapp','email','sms') NOT NULL DEFAULT 'sms',
            subject       VARCHAR(255) NOT NULL DEFAULT '',
            message       TEXT NOT NULL,
            scheduled_at  DATETIME DEFAULT NULL,
            sent_at       DATETIME DEFAULT NULL,
            status        ENUM('draft','scheduled','sent','failed') NOT NULL DEFAULT 'draft',
            created_by    INT DEFAULT NULL,
            created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_customer (customer_id),
            INDEX idx_status (status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    // ── AJAX: send / schedule reminder ────────────────────────────────────
    if (isset($_POST['ajax_action']) && $_POST['ajax_action'] === 'send_reminder') {
        header('Content-Type: application/json');
        $customerId   = (int)($_POST['customer_id'] ?? 0);
        $reminderType = trim($_POST['reminder_type'] ?? 'custom');
        $channels     = $_POST['channels'] ?? [];
        $subject      = trim($_POST['subject'] ?? '');
        $message      = trim($_POST['message'] ?? '');
        $scheduleAt   = !empty($_POST['schedule_at']) ? $_POST['schedule_at'] : null;

        if (!$customerId || empty($message)) {
            echo json_encode(['success' => false, 'error' => 'Customer and message are required']);
            exit;
        }
        if (empty($channels)) {
            echo json_encode(['success' => false, 'error' => 'Select at least one channel']);
            exit;
        }

        $channelStr = implode(',', array_map('trim', $channels));
        $status = $scheduleAt ? 'scheduled' : 'sent';
        $sentAt = $scheduleAt ? null : date('Y-m-d H:i:s');

        $stmt = $conn->prepare("
            INSERT INTO customer_service_reminders
                (customer_id, reminder_type, channel, subject, message, scheduled_at, sent_at, status, created_by)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $customerId, $reminderType, $channelStr, $subject, $message,
            $scheduleAt, $sentAt, $status, $_SESSION['user_id'] ?? 1
        ]);

        // Fetch customer contact info for the simulated send
        $cStmt = $conn->prepare("SELECT full_name, telephone, email FROM customers WHERE id = ?");
        $cStmt->execute([$customerId]);
        $cust = $cStmt->fetch(PDO::FETCH_ASSOC);

        echo json_encode([
            'success'   => true,
            'status'    => $status,
            'customer'  => $cust['full_name'] ?? 'Customer',
            'channels'  => $channels,
            'scheduled' => $scheduleAt,
        ]);
        exit;
    }

    // ── AJAX: get reminder history ─────────────────────────────────────────
    if (isset($_GET['ajax_action']) && $_GET['ajax_action'] === 'reminder_history') {
        header('Content-Type: application/json');
        $cid = (int)($_GET['customer_id'] ?? 0);
        $rows = $conn->prepare("
            SELECT r.*, c.full_name as customer_name
            FROM customer_service_reminders r
            JOIN customers c ON r.customer_id = c.id
            WHERE r.customer_id = ?
            ORDER BY r.created_at DESC LIMIT 20
        ");
        $rows->execute([$cid]);
        echo json_encode(['success' => true, 'reminders' => $rows->fetchAll(PDO::FETCH_ASSOC)]);
        exit;
    }

    // ── AJAX: bulk reminder (send to multiple customers) ──────────────────
    if (isset($_POST['ajax_action']) && $_POST['ajax_action'] === 'bulk_reminder') {
        header('Content-Type: application/json');
        $customerIds  = json_decode($_POST['customer_ids'] ?? '[]', true);
        $reminderType = trim($_POST['reminder_type'] ?? 'seasonal');
        $channels     = $_POST['channels'] ?? [];
        $subject      = trim($_POST['subject'] ?? '');
        $message      = trim($_POST['message'] ?? '');
        $scheduleAt   = !empty($_POST['schedule_at']) ? $_POST['schedule_at'] : null;

        if (empty($customerIds) || empty($message)) {
            echo json_encode(['success' => false, 'error' => 'Select customers and provide a message']);
            exit;
        }

        $channelStr = implode(',', array_map('trim', $channels));
        $status = $scheduleAt ? 'scheduled' : 'sent';
        $sentAt = $scheduleAt ? null : date('Y-m-d H:i:s');
        $userId = $_SESSION['user_id'] ?? 1;

        $stmt = $conn->prepare("
            INSERT INTO customer_service_reminders
                (customer_id, reminder_type, channel, subject, message, scheduled_at, sent_at, status, created_by)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $count = 0;
        foreach ($customerIds as $cid) {
            $stmt->execute([(int)$cid, $reminderType, $channelStr, $subject, $message, $scheduleAt, $sentAt, $status, $userId]);
            $count++;
        }
        echo json_encode(['success' => true, 'sent_count' => $count, 'status' => $status]);
        exit;
    }

    // ── AJAX: get customers whose service is expiring soon ────────────────
    if (isset($_GET['ajax_action']) && $_GET['ajax_action'] === 'expiring_services') {
        header('Content-Type: application/json');
        $days = (int)($_GET['days'] ?? 30);
        try {
            $expiring = $conn->prepare("
                SELECT DISTINCT c.id, c.full_name, c.telephone, c.email,
                    MAX(j.date_promised) as last_service_date,
                    DATEDIFF(DATE_ADD(MAX(j.date_promised), INTERVAL 6 MONTH), CURDATE()) as days_until_expiry
                FROM customers c
                JOIN job_cards j ON c.id = j.customer_id
                WHERE j.status = 'completed'
                  AND DATE_ADD(MAX(j.date_promised), INTERVAL 6 MONTH) BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL ? DAY)
                GROUP BY c.id
                ORDER BY days_until_expiry ASC
                LIMIT 50
            ");
            $expiring->execute([$days]);
            echo json_encode(['success' => true, 'customers' => $expiring->fetchAll(PDO::FETCH_ASSOC)]);
        } catch (PDOException $e) {
            echo json_encode(['success' => true, 'customers' => []]);
        }
        exit;
    }

    // ── AJAX: get single customer for view/edit modal ─────────────────────
    if (isset($_GET['ajax_action']) && $_GET['ajax_action'] === 'get_customer') {
        header('Content-Type: application/json');
        $cid = (int)($_GET['customer_id'] ?? 0);
        if (!$cid) { echo json_encode(['success' => false, 'error' => 'No ID']); exit; }
        try {
            $cStmt = $conn->prepare("SELECT * FROM customers WHERE id = ?");
            $cStmt->execute([$cid]);
            $c = $cStmt->fetch(PDO::FETCH_ASSOC);
            if (!$c) { echo json_encode(['success' => false, 'error' => 'Not found']); exit; }
            // Job history
            $jobs = $conn->prepare("
                SELECT id, job_number, vehicle_reg, status, date_received, date_promised, date_completed
                FROM job_cards WHERE customer_id = ? ORDER BY date_received DESC LIMIT 10
            ");
            $jobs->execute([$cid]);
            $c['jobs'] = $jobs->fetchAll(PDO::FETCH_ASSOC);
            echo json_encode(['success' => true, 'customer' => $c]);
        } catch (PDOException $e) {
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
        exit;
    }

    // ── AJAX: update customer from inline edit modal ───────────────────────
    if (isset($_POST['ajax_action']) && $_POST['ajax_action'] === 'update_customer') {
        header('Content-Type: application/json');
        $cid = (int)($_POST['customer_id'] ?? 0);
        if (!$cid) { echo json_encode(['success' => false, 'error' => 'No ID']); exit; }
        try {
            $allowedCols = ['full_name','telephone','email','address','customer_tier','customer_source','status','notes'];
            $sets  = [];
            $vals  = [];
            foreach ($allowedCols as $col) {
                if (isset($_POST[$col])) {
                    $sets[] = "$col = ?";
                    $vals[] = trim($_POST[$col]);
                }
            }
            if (empty($sets)) { echo json_encode(['success' => false, 'error' => 'Nothing to update']); exit; }
            $vals[] = $cid;
            $conn->prepare("UPDATE customers SET " . implode(', ', $sets) . " WHERE id = ?")->execute($vals);
            echo json_encode(['success' => true]);
        } catch (PDOException $e) {
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
        exit;
    }

    // ── AJAX: 360° full customer profile ──────────────────────────────────
    if (isset($_GET['ajax_action']) && $_GET['ajax_action'] === 'profile_360') {
        header('Content-Type: application/json');
        $cid = (int)($_GET['customer_id'] ?? 0);
        if (!$cid) { echo json_encode(['success'=>false,'error'=>'No ID']); exit; }
        try {
            // Core customer
            $c = $conn->prepare("SELECT * FROM customers WHERE id = ?");
            $c->execute([$cid]); $customer = $c->fetch(PDO::FETCH_ASSOC);
            if (!$customer) { echo json_encode(['success'=>false,'error'=>'Not found']); exit; }

            // Check which column exists in invoice_items for revenue calculation
            $checkCol = $conn->query("SHOW COLUMNS FROM invoice_items LIKE 'total_price'");
            $hasTotalPrice = $checkCol->rowCount() > 0;
            $revenueCol = $hasTotalPrice ? 'total_price' : 'price';
            
            // Also check if 'price' column exists as fallback
            if (!$hasTotalPrice) {
                $checkCol2 = $conn->query("SHOW COLUMNS FROM invoice_items LIKE 'price'");
                if ($checkCol2->rowCount() > 0) {
                    $revenueCol = 'price';
                } else {
                    $revenueCol = 'quantity'; // fallback to avoid SQL error
                }
            }

            // Check invoice table structure for revenue joins
            $invoiceColumns = $conn->query("SHOW COLUMNS FROM invoices")->fetchAll(PDO::FETCH_COLUMN);
            $hasJobCardId = in_array('job_card_id', $invoiceColumns);
            $hasQuotationId = in_array('quotation_id', $invoiceColumns);
            $hasTotalAmount = in_array('total_amount', $invoiceColumns);

            // All job cards (full service history) - revenue joined appropriately
            if ($hasJobCardId) {
                $jobs = $conn->prepare("
                    SELECT j.*, 
                        (SELECT SUM(ii.{$revenueCol}) 
                         FROM invoice_items ii 
                         JOIN invoices inv ON ii.invoice_id = inv.id 
                         WHERE inv.job_card_id = j.id) as job_revenue 
                    FROM job_cards j 
                    WHERE j.customer_id = ? 
                    ORDER BY j.date_received DESC
                ");
            } elseif ($hasQuotationId) {
                $jobs = $conn->prepare("
                    SELECT j.*, 
                        (SELECT SUM(ii.{$revenueCol}) 
                         FROM invoice_items ii 
                         JOIN invoices inv ON ii.invoice_id = inv.id 
                         JOIN quotations q ON inv.quotation_id = q.id 
                         WHERE q.job_card_id = j.id) as job_revenue 
                    FROM job_cards j 
                    WHERE j.customer_id = ? 
                    ORDER BY j.date_received DESC
                ");
            } else {
                $jobs = $conn->prepare("
                    SELECT j.*, NULL as job_revenue 
                    FROM job_cards j 
                    WHERE j.customer_id = ? 
                    ORDER BY j.date_received DESC
                ");
            }
            $jobs->execute([$cid]); 
            $customer['jobs'] = $jobs->fetchAll(PDO::FETCH_ASSOC);

            // Vehicles linked to this customer
            $existingTbls = $conn->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
            if (in_array('customer_vehicles', $existingTbls)) {
                $veh = $conn->prepare("SELECT * FROM customer_vehicles WHERE customer_id=? ORDER BY is_primary DESC, created_at DESC");
                $veh->execute([$cid]); $customer['vehicles'] = $veh->fetchAll(PDO::FETCH_ASSOC);
            } else {
                // Derive unique vehicles from job cards
                $veh = $conn->prepare("SELECT DISTINCT vehicle_reg, vehicle_model, chassis_no FROM job_cards WHERE customer_id=? AND vehicle_reg IS NOT NULL AND vehicle_reg!=''");
                $veh->execute([$cid]); $customer['vehicles'] = $veh->fetchAll(PDO::FETCH_ASSOC);
            }

            // Mileage history
            if (in_array('vehicle_mileage', $existingTbls)) {
                $mil = $conn->prepare("SELECT m.*, j.job_number FROM vehicle_mileage m LEFT JOIN job_cards j ON m.job_card_id=j.id WHERE m.customer_id=? ORDER BY m.recorded_at DESC LIMIT 20");
                $mil->execute([$cid]); $customer['mileage'] = $mil->fetchAll(PDO::FETCH_ASSOC);
            } else {
                $mil = $conn->prepare("SELECT odometer_reading as mileage, date_received as recorded_at, job_number, vehicle_reg FROM job_cards WHERE customer_id=? AND odometer_reading IS NOT NULL ORDER BY date_received DESC LIMIT 20");
                $mil->execute([$cid]); $customer['mileage'] = $mil->fetchAll(PDO::FETCH_ASSOC);
            }

            // Digital inspections
            if (in_array('vehicle_inspections', $existingTbls)) {
                $ins = $conn->prepare("SELECT * FROM vehicle_inspections WHERE customer_id=? ORDER BY inspection_date DESC LIMIT 10");
                $ins->execute([$cid]); $customer['inspections'] = $ins->fetchAll(PDO::FETCH_ASSOC);
            } else { $customer['inspections'] = []; }

            // Deferred services
            if (in_array('deferred_services', $existingTbls)) {
                $def = $conn->prepare("SELECT * FROM deferred_services WHERE customer_id=? ORDER BY deferred_date DESC");
                $def->execute([$cid]); $customer['deferred'] = $def->fetchAll(PDO::FETCH_ASSOC);
            } else { $customer['deferred'] = []; }

            // Scheduled appointments
            if (in_array('service_appointments', $existingTbls)) {
                $appt = $conn->prepare("SELECT * FROM service_appointments WHERE customer_id=? AND appointment_date >= CURDATE() ORDER BY appointment_date ASC LIMIT 10");
                $appt->execute([$cid]); $customer['appointments'] = $appt->fetchAll(PDO::FETCH_ASSOC);
            } else { $customer['appointments'] = []; }

            // Reviews / Ratings
            if (in_array('customer_feedback', $existingTbls)) {
                $rev = $conn->prepare("SELECT * FROM customer_feedback WHERE customer_id=? ORDER BY created_at DESC LIMIT 10");
                $rev->execute([$cid]); $customer['reviews'] = $rev->fetchAll(PDO::FETCH_ASSOC);
            } else { $customer['reviews'] = []; }

            // CLV & KPI calculations from job cards + invoices (FIXED)
            if ($hasJobCardId) {
                $clvStmt = $conn->prepare("
                    SELECT
                        COUNT(DISTINCT j.id) as total_visits,
                        COALESCE(SUM(inv.total_amount),0) as total_revenue,
                        COALESCE(AVG(inv.total_amount),0) as avg_spend_per_visit,
                        MIN(j.date_received) as first_visit,
                        MAX(j.date_received) as last_visit,
                        DATEDIFF(NOW(), MAX(j.date_received)) as days_since_last_visit,
                        COUNT(DISTINCT j.vehicle_reg) as vehicles_count
                    FROM job_cards j
                    LEFT JOIN invoices inv ON inv.job_card_id = j.id
                    WHERE j.customer_id = ?
                ");
            } elseif ($hasQuotationId && $hasTotalAmount) {
                $clvStmt = $conn->prepare("
                    SELECT
                        COUNT(DISTINCT j.id) as total_visits,
                        COALESCE(SUM(inv.total_amount),0) as total_revenue,
                        COALESCE(AVG(inv.total_amount),0) as avg_spend_per_visit,
                        MIN(j.date_received) as first_visit,
                        MAX(j.date_received) as last_visit,
                        DATEDIFF(NOW(), MAX(j.date_received)) as days_since_last_visit,
                        COUNT(DISTINCT j.vehicle_reg) as vehicles_count
                    FROM job_cards j
                    LEFT JOIN quotations q ON q.job_card_id = j.id
                    LEFT JOIN invoices inv ON inv.quotation_id = q.id
                    WHERE j.customer_id = ?
                ");
            } else {
                // Fallback: just use job cards without revenue
                $clvStmt = $conn->prepare("
                    SELECT
                        COUNT(DISTINCT j.id) as total_visits,
                        0 as total_revenue,
                        0 as avg_spend_per_visit,
                        MIN(j.date_received) as first_visit,
                        MAX(j.date_received) as last_visit,
                        DATEDIFF(NOW(), MAX(j.date_received)) as days_since_last_visit,
                        COUNT(DISTINCT j.vehicle_reg) as vehicles_count
                    FROM job_cards j
                    WHERE j.customer_id = ?
                ");
            }
            $clvStmt->execute([$cid]);
            $customer['clv'] = $clvStmt->fetch(PDO::FETCH_ASSOC);

            // Estimate CLV (avg spend × avg visits per year × avg relationship years)
            $firstVisit = $customer['clv']['first_visit'];
            $yearsAsCustomer = $firstVisit ? max(1, round(abs(strtotime($firstVisit) - time()) / (365.25*24*3600), 1)) : 1;
            $visitsPerYear = $yearsAsCustomer > 0 ? ($customer['clv']['total_visits'] / $yearsAsCustomer) : $customer['clv']['total_visits'];
            $customer['clv']['years_as_customer'] = $yearsAsCustomer;
            $customer['clv']['visits_per_year'] = round($visitsPerYear, 1);
            $customer['clv']['estimated_annual_value'] = round($customer['clv']['avg_spend_per_visit'] * $visitsPerYear, 0);
            $customer['clv']['estimated_3yr_clv'] = round($customer['clv']['avg_spend_per_visit'] * $visitsPerYear * 3, 0);

            echo json_encode(['success'=>true,'customer'=>$customer]);
        } catch (PDOException $e) {
            echo json_encode(['success'=>false,'error'=>$e->getMessage()]);
        }
        exit;
    }

    // ── AJAX: save appointment / scheduling ───────────────────────────────
    if (isset($_POST['ajax_action']) && $_POST['ajax_action'] === 'save_appointment') {
        header('Content-Type: application/json');
        try {
            $conn->exec("CREATE TABLE IF NOT EXISTS service_appointments (
                id INT AUTO_INCREMENT PRIMARY KEY, customer_id INT NOT NULL, vehicle_reg VARCHAR(30),
                appointment_date DATE NOT NULL, appointment_time TIME DEFAULT '09:00:00',
                service_type VARCHAR(200), technician_note TEXT, status ENUM('scheduled','confirmed','completed','cancelled') DEFAULT 'scheduled',
                created_by INT, created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_cust(customer_id), INDEX idx_date(appointment_date)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
            $stmt = $conn->prepare("INSERT INTO service_appointments (customer_id,vehicle_reg,appointment_date,appointment_time,service_type,technician_note,created_by) VALUES (?,?,?,?,?,?,?)");
            $stmt->execute([(int)$_POST['customer_id'],$_POST['vehicle_reg']??'',$_POST['appointment_date'],$_POST['appointment_time']??'09:00:00',$_POST['service_type']??'',$_POST['technician_note']??'',$_SESSION['user_id']??1]);
            echo json_encode(['success'=>true,'message'=>'Appointment scheduled!']);
        } catch (PDOException $e) { echo json_encode(['success'=>false,'error'=>$e->getMessage()]); }
        exit;
    }

    // ── AJAX: save deferred service ───────────────────────────────────────
    if (isset($_POST['ajax_action']) && $_POST['ajax_action'] === 'save_deferred') {
        header('Content-Type: application/json');
        try {
            $conn->exec("CREATE TABLE IF NOT EXISTS deferred_services (
                id INT AUTO_INCREMENT PRIMARY KEY, customer_id INT NOT NULL, vehicle_reg VARCHAR(30),
                service_description TEXT NOT NULL, deferred_date DATE NOT NULL, follow_up_date DATE,
                reason TEXT, status ENUM('pending','contacted','booked','cancelled') DEFAULT 'pending',
                created_by INT, created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_cust(customer_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
            $stmt = $conn->prepare("INSERT INTO deferred_services (customer_id,vehicle_reg,service_description,deferred_date,follow_up_date,reason,created_by) VALUES (?,?,?,?,?,?,?)");
            $stmt->execute([(int)$_POST['customer_id'],$_POST['vehicle_reg']??'',$_POST['service_description'],$_POST['deferred_date'],($_POST['follow_up_date']??null)?:null,$_POST['reason']??'',$_SESSION['user_id']??1]);
            echo json_encode(['success'=>true,'message'=>'Deferred service logged!']);
        } catch (PDOException $e) { echo json_encode(['success'=>false,'error'=>$e->getMessage()]); }
        exit;
    }

    // ── AJAX: save review ────────────────────────────────────────────────
    if (isset($_POST['ajax_action']) && $_POST['ajax_action'] === 'save_review') {
        header('Content-Type: application/json');
        try {
            $conn->exec("CREATE TABLE IF NOT EXISTS customer_feedback (
                id INT AUTO_INCREMENT PRIMARY KEY, customer_id INT NOT NULL, rating TINYINT DEFAULT 5,
                review_text TEXT, job_card_id INT DEFAULT NULL, response_text TEXT,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP, INDEX idx_cust(customer_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
            $stmt = $conn->prepare("INSERT INTO customer_feedback (customer_id,rating,review_text,job_card_id) VALUES (?,?,?,?)");
            $stmt->execute([(int)$_POST['customer_id'],(int)$_POST['rating'],$_POST['review_text']??'',($_POST['job_card_id']??null)?:null]);
            echo json_encode(['success'=>true,'message'=>'Review saved!']);
        } catch (PDOException $e) { echo json_encode(['success'=>false,'error'=>$e->getMessage()]); }
        exit;
    }

    // ════════════════════════════════════════════════════════════════════════
    //  1. PREDICTIVE MAINTENANCE — model-specific service intervals
    // ════════════════════════════════════════════════════════════════════════
    if (isset($_GET['ajax_action']) && $_GET['ajax_action'] === 'predictive_maintenance') {
        header('Content-Type: application/json');
        $cid = (int)($_GET['customer_id'] ?? 0);
        try {
            // Model-specific intervals (km and months) — expandable map
            $modelIntervals = [
                'default'   => ['oil'=>5000,  'brakes'=>20000, 'tyres'=>40000, 'filter'=>10000, 'timing_belt'=>60000, 'months_service'=>6],
                'toyota'    => ['oil'=>5000,  'brakes'=>25000, 'tyres'=>50000, 'filter'=>10000, 'timing_belt'=>80000, 'months_service'=>6],
                'mercedes'  => ['oil'=>10000, 'brakes'=>30000, 'tyres'=>40000, 'filter'=>15000, 'timing_belt'=>100000,'months_service'=>12],
                'bmw'       => ['oil'=>10000, 'brakes'=>30000, 'tyres'=>40000, 'filter'=>15000, 'timing_belt'=>100000,'months_service'=>12],
                'volkswagen'=> ['oil'=>10000, 'brakes'=>25000, 'tyres'=>45000, 'filter'=>15000, 'timing_belt'=>90000, 'months_service'=>12],
                'nissan'    => ['oil'=>5000,  'brakes'=>20000, 'tyres'=>45000, 'filter'=>10000, 'timing_belt'=>60000, 'months_service'=>6],
                'honda'     => ['oil'=>5000,  'brakes'=>25000, 'tyres'=>50000, 'filter'=>10000, 'timing_belt'=>80000, 'months_service'=>6],
                'subaru'    => ['oil'=>5000,  'brakes'=>20000, 'tyres'=>45000, 'filter'=>10000, 'timing_belt'=>60000, 'months_service'=>6],
                'land rover'=> ['oil'=>8000,  'brakes'=>25000, 'tyres'=>40000, 'filter'=>15000, 'timing_belt'=>90000, 'months_service'=>6],
            ];
            // Get last job + mileage for each vehicle
            $jobs = $conn->prepare("
                SELECT j.vehicle_reg, j.vehicle_model, j.odometer_reading,
                       j.date_received, j.job_number,
                       (SELECT GROUP_CONCAT(jc_item SEPARATOR ',') FROM job_card_items WHERE job_card_id=j.id LIMIT 1) as items
                FROM job_cards j WHERE j.customer_id=? ORDER BY j.date_received DESC
            ");
            $jobs->execute([$cid]);
            $jobRows = $jobs->fetchAll(PDO::FETCH_ASSOC);

            $predictions = [];
            $seenRegs = [];
            foreach ($jobRows as $jr) {
                $reg = strtoupper(trim($jr['vehicle_reg'] ?? ''));
                if (!$reg || in_array($reg, $seenRegs)) continue;
                $seenRegs[] = $reg;

                // Detect make from model string
                $makeKey = 'default';
                $model = strtolower($jr['vehicle_model'] ?? '');
                foreach (array_keys($modelIntervals) as $mk) {
                    if ($mk !== 'default' && strpos($model, $mk) !== false) { $makeKey = $mk; break; }
                }
                $iv = $modelIntervals[$makeKey];

                $lastMileage   = (int)($jr['odometer_reading'] ?? 0);
                $lastServiceDate = $jr['date_received'];
                $monthsSince   = $lastServiceDate ? round((time() - strtotime($lastServiceDate)) / (30.44 * 24 * 3600), 1) : null;

                $alerts = [];
                // Oil change
                if ($lastMileage > 0) {
                    $kmToOil = $iv['oil'] - ($lastMileage % $iv['oil']);
                    $urgency = $kmToOil < 500 ? 'critical' : ($kmToOil < 1500 ? 'warning' : 'ok');
                    $alerts[] = ['service'=>'Oil Change','due_km'=>$kmToOil,'interval_km'=>$iv['oil'],'urgency'=>$urgency];
                    $kmToBrake = $iv['brakes'] - ($lastMileage % $iv['brakes']);
                    $alerts[] = ['service'=>'Brake Inspection','due_km'=>$kmToBrake,'interval_km'=>$iv['brakes'],'urgency'=>$kmToBrake<2000?'warning':'ok'];
                    $kmToTyre = $iv['tyres'] - ($lastMileage % $iv['tyres']);
                    $alerts[] = ['service'=>'Tyre Rotation','due_km'=>$kmToTyre,'interval_km'=>$iv['tyres'],'urgency'=>$kmToTyre<3000?'warning':'ok'];
                    $kmToBelt = $iv['timing_belt'] - ($lastMileage % $iv['timing_belt']);
                    $alerts[] = ['service'=>'Timing Belt','due_km'=>$kmToBelt,'interval_km'=>$iv['timing_belt'],'urgency'=>$kmToBelt<5000?'critical':($kmToBelt<15000?'warning':'ok')];
                }
                if ($monthsSince !== null) {
                    $monthsToService = $iv['months_service'] - ($monthsSince % $iv['months_service']);
                    $alerts[] = ['service'=>'Periodic Service','due_months'=>round($monthsToService,1),'months_since'=>$monthsSince,'urgency'=>$monthsToService<1?'critical':($monthsToService<2?'warning':'ok')];
                }
                $predictions[] = ['vehicle_reg'=>$reg,'vehicle_model'=>$jr['vehicle_model'],'make_key'=>$makeKey,'last_mileage'=>$lastMileage,'last_service'=>$lastServiceDate,'alerts'=>$alerts];
            }
            echo json_encode(['success'=>true,'predictions'=>$predictions]);
        } catch (PDOException $e) { echo json_encode(['success'=>false,'error'=>$e->getMessage()]); }
        exit;
    }

    // ════════════════════════════════════════════════════════════════════════
    //  2. DIGITAL VEHICLE INSPECTION — save & fetch inspection checklist
    // ════════════════════════════════════════════════════════════════════════
    if (isset($_POST['ajax_action']) && $_POST['ajax_action'] === 'save_inspection') {
        header('Content-Type: application/json');
        try {
            $conn->exec("CREATE TABLE IF NOT EXISTS vehicle_inspections (
                id INT AUTO_INCREMENT PRIMARY KEY, customer_id INT NOT NULL,
                vehicle_reg VARCHAR(30), job_card_id INT DEFAULT NULL,
                inspection_date DATE NOT NULL, inspector_name VARCHAR(100),
                checklist JSON NOT NULL COMMENT 'JSON map of item=>status',
                overall_condition ENUM('excellent','good','fair','poor') DEFAULT 'good',
                notes TEXT, photo_urls TEXT COMMENT 'comma-separated URLs',
                shared_with_customer TINYINT(1) DEFAULT 0, shared_at DATETIME NULL,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_cust(customer_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
            $stmt = $conn->prepare("INSERT INTO vehicle_inspections
                (customer_id,vehicle_reg,job_card_id,inspection_date,inspector_name,checklist,overall_condition,notes,shared_with_customer)
                VALUES (?,?,?,?,?,?,?,?,?)");
            $stmt->execute([
                (int)$_POST['customer_id'], $_POST['vehicle_reg']??'',
                ($_POST['job_card_id']??null)?:null, $_POST['inspection_date']??date('Y-m-d'),
                $_POST['inspector_name']??'', $_POST['checklist']??'{}',
                $_POST['overall_condition']??'good', $_POST['notes']??'',
                (int)($_POST['shared_with_customer']??0)
            ]);
            $newId = $conn->lastInsertId();
            if ($_POST['shared_with_customer'] ?? 0) {
                $conn->prepare("UPDATE vehicle_inspections SET shared_at=NOW() WHERE id=?")->execute([$newId]);
            }
            echo json_encode(['success'=>true,'id'=>$newId,'message'=>'Inspection saved!']);
        } catch (PDOException $e) { echo json_encode(['success'=>false,'error'=>$e->getMessage()]); }
        exit;
    }

    if (isset($_GET['ajax_action']) && $_GET['ajax_action'] === 'get_inspections') {
        header('Content-Type: application/json');
        $cid = (int)($_GET['customer_id'] ?? 0);
        try {
            $rows = $conn->prepare("SELECT * FROM vehicle_inspections WHERE customer_id=? ORDER BY inspection_date DESC LIMIT 10");
            $rows->execute([$cid]);
            $insp = $rows->fetchAll(PDO::FETCH_ASSOC);
            foreach ($insp as &$i) { $i['checklist'] = json_decode($i['checklist'], true) ?? []; }
            echo json_encode(['success'=>true,'inspections'=>$insp]);
        } catch (PDOException $e) { echo json_encode(['success'=>false,'error'=>$e->getMessage()]); }
        exit;
    }

    // ════════════════════════════════════════════════════════════════════════
    //  3. FIRST 100 DAYS JOURNEY — omnichannel touchpoint sequence
    // ════════════════════════════════════════════════════════════════════════
    if (isset($_GET['ajax_action']) && $_GET['ajax_action'] === 'first_100_days') {
        header('Content-Type: application/json');
        $cid = (int)($_GET['customer_id'] ?? 0);
        try {
            $c = $conn->prepare("SELECT full_name, created_at, telephone, email FROM customers WHERE id=?");
            $c->execute([$cid]); $cust = $c->fetch(PDO::FETCH_ASSOC);
            if (!$cust) { echo json_encode(['success'=>false,'error'=>'Not found']); exit; }

            $joinDate = strtotime($cust['created_at']);
            $daysSince = (int)floor((time() - $joinDate) / 86400);

            // Touchpoint schedule — day, channel, purpose, template
            $touchpoints = [
                ['day'=>0,   'channel'=>'whatsapp','label'=>'Welcome Message',         'status_day'=>0],
                ['day'=>1,   'channel'=>'sms',     'label'=>'Thank You SMS',            'status_day'=>1],
                ['day'=>3,   'channel'=>'whatsapp','label'=>'First Service Check-in',   'status_day'=>3],
                ['day'=>7,   'channel'=>'email',   'label'=>'Week 1 Satisfaction Survey','status_day'=>7],
                ['day'=>14,  'channel'=>'whatsapp','label'=>'Service Reminder Prompt',  'status_day'=>14],
                ['day'=>30,  'channel'=>'sms',     'label'=>'1-Month Follow-Up',        'status_day'=>30],
                ['day'=>45,  'channel'=>'whatsapp','label'=>'Referral Request',         'status_day'=>45],
                ['day'=>60,  'channel'=>'email',   'label'=>'Review Request',           'status_day'=>60],
                ['day'=>75,  'channel'=>'whatsapp','label'=>'Loyalty Points Reminder',  'status_day'=>75],
                ['day'=>90,  'channel'=>'sms',     'label'=>'3-Month Service Due',      'status_day'=>90],
                ['day'=>100, 'channel'=>'whatsapp','label'=>'100 Days Milestone',       'status_day'=>100],
            ];

            // Check which have already been sent via reminders table
            $sent = $conn->prepare("SELECT subject, sent_at FROM customer_service_reminders WHERE customer_id=? AND status='sent'");
            $sent->execute([$cid]); $sentRows = $sent->fetchAll(PDO::FETCH_ASSOC);
            $sentLabels = array_column($sentRows, 'subject');

            foreach ($touchpoints as &$tp) {
                $tp['due_date']   = date('Y-m-d', $joinDate + $tp['day'] * 86400);
                $tp['status']     = $daysSince >= $tp['day']
                    ? (in_array($tp['label'], $sentLabels) ? 'sent' : 'missed')
                    : ($daysSince >= $tp['day'] - 2 ? 'due_soon' : 'upcoming');
                $tp['days_delta'] = $tp['day'] - $daysSince;
            }
            echo json_encode(['success'=>true,'customer'=>$cust,'days_since_join'=>$daysSince,'touchpoints'=>$touchpoints]);
        } catch (PDOException $e) { echo json_encode(['success'=>false,'error'=>$e->getMessage()]); }
        exit;
    }

    // ════════════════════════════════════════════════════════════════════════
    //  4. HIGH-VALUE CUSTOMER SEGMENTATION (FIXED)
    // ════════════════════════════════════════════════════════════════════════
    if (isset($_GET['ajax_action']) && $_GET['ajax_action'] === 'segmentation') {
        header('Content-Type: application/json');
        try {
            // Check invoice table structure
            $invoiceColumns = $conn->query("SHOW COLUMNS FROM invoices")->fetchAll(PDO::FETCH_COLUMN);
            $hasJobCardId = in_array('job_card_id', $invoiceColumns);
            $hasTotalAmount = in_array('total_amount', $invoiceColumns);
            
            $revenueField = ($hasJobCardId && $hasTotalAmount) ? 'COALESCE(SUM(inv.total_amount),0)' : '0';
            $joinCondition = $hasJobCardId ? 'LEFT JOIN invoices inv ON inv.job_card_id = j.id' : '';
            
            $seg = $conn->query("
                SELECT c.id, c.full_name, c.telephone, c.email, c.customer_tier,
                       COUNT(DISTINCT j.id) as visits,
                       {$revenueField} as total_revenue,
                       MAX(j.date_received) as last_visit,
                       DATEDIFF(NOW(), MAX(j.date_received)) as recency_days,
                       COUNT(DISTINCT j.vehicle_reg) as vehicles
                FROM customers c
                LEFT JOIN job_cards j ON j.customer_id = c.id
                {$joinCondition}
                WHERE c.status = 1
                GROUP BY c.id
                ORDER BY total_revenue DESC
                LIMIT 200
            ")->fetchAll(PDO::FETCH_ASSOC);

            $segments = ['champions'=>[],'loyal'=>[],'at_risk'=>[],'lost'=>[],'new'=>[],'potential'=>[]];
            foreach ($seg as $row) {
                $rev     = (float)$row['total_revenue'];
                $visits  = (int)$row['visits'];
                $recency = (int)$row['recency_days'];
                // RFM-style segmentation
                if ($rev >= 1000000 && $visits >= 5 && $recency <= 90)        $segments['champions'][] = $row;
                elseif ($visits >= 3 && $recency <= 180)                       $segments['loyal'][]     = $row;
                elseif ($visits >= 2 && $recency > 180 && $recency <= 365)    $segments['at_risk'][]   = $row;
                elseif ($recency > 365)                                         $segments['lost'][]      = $row;
                elseif ($visits <= 1 && $recency <= 30)                        $segments['new'][]       = $row;
                else                                                            $segments['potential'][] = $row;
            }
            echo json_encode(['success'=>true,'segments'=>$segments,'totals'=>array_map('count',$segments)]);
        } catch (PDOException $e) { echo json_encode(['success'=>false,'error'=>$e->getMessage()]); }
        exit;
    }

    // ════════════════════════════════════════════════════════════════════════
    //  5. AUTOMATED REVIEW GENERATION — save + optionally share link
    // ════════════════════════════════════════════════════════════════════════
    if (isset($_POST['ajax_action']) && $_POST['ajax_action'] === 'generate_review_request') {
        header('Content-Type: application/json');
        try {
            $cid      = (int)$_POST['customer_id'];
            $channel  = $_POST['channel'] ?? 'whatsapp';
            $platform = $_POST['platform'] ?? 'google';  // google|facebook|internal
            $c = $conn->prepare("SELECT full_name, telephone, email FROM customers WHERE id=?");
            $c->execute([$cid]); $cust = $c->fetch(PDO::FETCH_ASSOC);
            // Build review request message
            $reviewLinks = [
                'google'   => 'https://g.page/savant-motors/review',
                'facebook' => 'https://www.facebook.com/savantmotors/reviews',
                'internal' => $_SERVER['HTTP_HOST'].'/savant/views/customers/review.php?cid='.$cid,
            ];
            $link = $reviewLinks[$platform] ?? $reviewLinks['google'];
            $msg  = "Dear {$cust['full_name']}, thank you for choosing Savant Motors! 🚗\nWe'd love to hear your feedback. Please take a moment to leave us a review:\n{$link}\nYour opinion helps us serve you better. Thank you! 🙏";
            // Save as scheduled reminder
            $stmt = $conn->prepare("INSERT INTO customer_service_reminders (customer_id,reminder_type,channel,subject,message,status,created_by) VALUES (?,?,?,?,?,?,?)");
            $stmt->execute([$cid,'custom',$channel,'Review Request',$msg,'sent',$_SESSION['user_id']??1]);
            echo json_encode(['success'=>true,'message'=>$msg,'link'=>$link,'channel'=>$channel]);
        } catch (PDOException $e) { echo json_encode(['success'=>false,'error'=>$e->getMessage()]); }
        exit;
    }

    // ════════════════════════════════════════════════════════════════════════
    //  6. AI-POWERED LEAD & CALL CAPTURE
    // ════════════════════════════════════════════════════════════════════════
    if (isset($_POST['ajax_action']) && $_POST['ajax_action'] === 'capture_lead') {
        header('Content-Type: application/json');
        try {
            $conn->exec("CREATE TABLE IF NOT EXISTS crm_leads (
                id INT AUTO_INCREMENT PRIMARY KEY, full_name VARCHAR(150) NOT NULL,
                telephone VARCHAR(50), email VARCHAR(150), source ENUM('call','walk_in','whatsapp','website','referral','social') DEFAULT 'call',
                vehicle_interest VARCHAR(100), notes TEXT, ai_summary TEXT,
                assigned_to INT DEFAULT NULL, status ENUM('new','contacted','converted','lost') DEFAULT 'new',
                call_duration_sec INT DEFAULT 0, call_recording_url VARCHAR(255),
                created_by INT, created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_status(status)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
            $stmt = $conn->prepare("INSERT INTO crm_leads (full_name,telephone,email,source,vehicle_interest,notes,ai_summary,assigned_to,call_duration_sec,created_by) VALUES (?,?,?,?,?,?,?,?,?,?)");
            $stmt->execute([
                $_POST['full_name'], $_POST['telephone']??'', $_POST['email']??'',
                $_POST['source']??'call', $_POST['vehicle_interest']??'', $_POST['notes']??'',
                $_POST['ai_summary']??'', ($_POST['assigned_to']??null)?:null,
                (int)($_POST['call_duration_sec']??0), $_SESSION['user_id']??1
            ]);
            $newId = $conn->lastInsertId();
            echo json_encode(['success'=>true,'lead_id'=>$newId,'message'=>'Lead captured!']);
        } catch (PDOException $e) { echo json_encode(['success'=>false,'error'=>$e->getMessage()]); }
        exit;
    }

    if (isset($_GET['ajax_action']) && $_GET['ajax_action'] === 'get_leads') {
        header('Content-Type: application/json');
        try {
            $leads = $conn->query("SELECT l.*, u.full_name as assigned_name FROM crm_leads l LEFT JOIN users u ON u.id=l.assigned_to ORDER BY l.created_at DESC LIMIT 100")->fetchAll(PDO::FETCH_ASSOC);
            echo json_encode(['success'=>true,'leads'=>$leads]);
        } catch (PDOException $e) { echo json_encode(['success'=>false,'error'=>$e->getMessage()]); }
        exit;
    }

    // ════════════════════════════════════════════════════════════════════════
    //  7. REAL-TIME TWO-WAY COMMUNICATION — log & fetch interactions
    // ════════════════════════════════════════════════════════════════════════
    if (isset($_POST['ajax_action']) && $_POST['ajax_action'] === 'log_interaction') {
        header('Content-Type: application/json');
        try {
            $conn->exec("CREATE TABLE IF NOT EXISTS customer_interactions (
                id INT AUTO_INCREMENT PRIMARY KEY, customer_id INT NOT NULL,
                channel ENUM('whatsapp','sms','email','call','in_person') DEFAULT 'whatsapp',
                direction ENUM('inbound','outbound') DEFAULT 'outbound',
                message TEXT, response TEXT, status ENUM('sent','delivered','read','replied','failed') DEFAULT 'sent',
                staff_name VARCHAR(100), created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_cust(customer_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
            $stmt = $conn->prepare("INSERT INTO customer_interactions (customer_id,channel,direction,message,response,status,staff_name) VALUES (?,?,?,?,?,?,?)");
            $stmt->execute([
                (int)$_POST['customer_id'], $_POST['channel']??'whatsapp',
                $_POST['direction']??'outbound', $_POST['message']??'',
                $_POST['response']??'', $_POST['status']??'sent',
                $user_full_name
            ]);
            echo json_encode(['success'=>true,'message'=>'Interaction logged!']);
        } catch (PDOException $e) { echo json_encode(['success'=>false,'error'=>$e->getMessage()]); }
        exit;
    }

    if (isset($_GET['ajax_action']) && $_GET['ajax_action'] === 'get_interactions') {
        header('Content-Type: application/json');
        $cid = (int)($_GET['customer_id'] ?? 0);
        try {
            $rows = $conn->prepare("SELECT * FROM customer_interactions WHERE customer_id=? ORDER BY created_at DESC LIMIT 30");
            $rows->execute([$cid]);
            echo json_encode(['success'=>true,'interactions'=>$rows->fetchAll(PDO::FETCH_ASSOC)]);
        } catch (PDOException $e) { echo json_encode(['success'=>false,'error'=>$e->getMessage()]); }
        exit;
    }

    // ════════════════════════════════════════════════════════════════════════
    //  8. AUTOMATED LIFECYCLE MANAGEMENT — stage tracking & auto-actions
    // ════════════════════════════════════════════════════════════════════════
    if (isset($_POST['ajax_action']) && $_POST['ajax_action'] === 'set_lifecycle_stage') {
        header('Content-Type: application/json');
        try {
            $conn->exec("CREATE TABLE IF NOT EXISTS customer_lifecycle (
                id INT AUTO_INCREMENT PRIMARY KEY, customer_id INT NOT NULL UNIQUE,
                stage ENUM('lead','new','active','loyal','at_risk','churned','reactivated') DEFAULT 'new',
                stage_since DATE NOT NULL, next_action VARCHAR(200),
                next_action_date DATE, auto_trigger TINYINT(1) DEFAULT 1,
                notes TEXT, updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX idx_stage(stage)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
            $stmt = $conn->prepare("INSERT INTO customer_lifecycle (customer_id,stage,stage_since,next_action,next_action_date,notes)
                VALUES (?,?,?,?,?,?) ON DUPLICATE KEY UPDATE stage=VALUES(stage),stage_since=VALUES(stage_since),
                next_action=VALUES(next_action),next_action_date=VALUES(next_action_date),notes=VALUES(notes)");
            $stmt->execute([
                (int)$_POST['customer_id'], $_POST['stage']??'active',
                $_POST['stage_since']??date('Y-m-d'), $_POST['next_action']??'',
                ($_POST['next_action_date']??null)?:null, $_POST['notes']??''
            ]);
            echo json_encode(['success'=>true,'message'=>'Lifecycle stage updated!']);
        } catch (PDOException $e) { echo json_encode(['success'=>false,'error'=>$e->getMessage()]); }
        exit;
    }

    if (isset($_GET['ajax_action']) && $_GET['ajax_action'] === 'lifecycle_overview') {
        header('Content-Type: application/json');
        try {
            // Ensure table exists before querying
            $conn->exec("CREATE TABLE IF NOT EXISTS customer_lifecycle (
                id INT AUTO_INCREMENT PRIMARY KEY, customer_id INT NOT NULL UNIQUE,
                stage ENUM('lead','new','active','loyal','at_risk','churned','reactivated') DEFAULT 'new',
                stage_since DATE NOT NULL, next_action VARCHAR(200),
                next_action_date DATE, auto_trigger TINYINT(1) DEFAULT 1,
                notes TEXT, updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX idx_stage(stage)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
            // Auto-compute lifecycle stages from behaviour if no manual override exists
            $stages = $conn->query("
                SELECT
                    cl.stage,
                    COUNT(cl.id) as manual_count
                FROM customer_lifecycle cl
                GROUP BY cl.stage
            ")->fetchAll(PDO::FETCH_ASSOC);
            echo json_encode(['success'=>true,'manual_stages'=>$stages,'auto_summary'=>[]]);
        } catch (PDOException $e) { echo json_encode(['success'=>false,'error'=>$e->getMessage()]); }
        exit;
    }

    // ── AJAX: log mileage ────────────────────────────────────────────────
    if (isset($_POST['ajax_action']) && $_POST['ajax_action'] === 'log_mileage') {
        header('Content-Type: application/json');
        try {
            $conn->exec("CREATE TABLE IF NOT EXISTS vehicle_mileage (
                id INT AUTO_INCREMENT PRIMARY KEY, customer_id INT NOT NULL, vehicle_reg VARCHAR(30) NOT NULL,
                mileage INT NOT NULL, recorded_at DATE NOT NULL, job_card_id INT DEFAULT NULL, notes TEXT,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP, INDEX idx_cust(customer_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
            $stmt = $conn->prepare("INSERT INTO vehicle_mileage (customer_id,vehicle_reg,mileage,recorded_at,notes) VALUES (?,?,?,?,?)");
            $stmt->execute([(int)$_POST['customer_id'],$_POST['vehicle_reg'],(int)$_POST['mileage'],$_POST['recorded_at']??date('Y-m-d'),$_POST['notes']??'']);
            echo json_encode(['success'=>true,'message'=>'Mileage logged!']);
        } catch (PDOException $e) { echo json_encode(['success'=>false,'error'=>$e->getMessage()]); }
        exit;
    }
    
    $search = $_GET['search'] ?? '';
    $tier = $_GET['tier'] ?? '';
    $source = $_GET['source'] ?? '';
    $status = $_GET['status'] ?? 'all';
    $page = $_GET['page'] ?? 1;
    $perPage = 20;
    $offset = ($page - 1) * $perPage;
    
    // Build WHERE clause
    $where = [];
    $params = [];
    
    if ($status == 'active') {
        $where[] = "c.status = 1";
    } elseif ($status == 'inactive') {
        $where[] = "c.status = 0";
    }
    
    if ($search) {
        $where[] = "(c.full_name LIKE ? OR c.telephone LIKE ? OR c.email LIKE ? OR c.customer_source LIKE ?)";
        $searchTerm = "%$search%";
        $params[] = $searchTerm;
        $params[] = $searchTerm;
        $params[] = $searchTerm;
        $params[] = $searchTerm;
    }
    if ($tier) {
        $where[] = "c.customer_tier = ?";
        $params[] = $tier;
    }
    if ($source) {
        $where[] = "c.customer_source = ?";
        $params[] = $source;
    }
    
    $whereClause = !empty($where) ? "WHERE " . implode(" AND ", $where) : "";
    
    // Get total count
    $countSql = "SELECT COUNT(*) as total FROM customers c $whereClause";
    $countStmt = $conn->prepare($countSql);
    $countStmt->execute($params);
    $totalCustomers = $countStmt->fetch(PDO::FETCH_ASSOC)['total'];
    $totalPages = ceil($totalCustomers / $perPage);
    
    // Check which optional tables exist
    $existingTables = $conn->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
    $hasInteractions = in_array('customer_interactions', $existingTables);
    $hasFeedback     = in_array('customer_feedback', $existingTables);
    $hasLoyalty      = in_array('customer_loyalty', $existingTables);

    $interactionsSub = $hasInteractions
        ? "(SELECT COUNT(*) FROM customer_interactions WHERE customer_id = c.id)"
        : "0";
    $ratingsSub = $hasFeedback
        ? "(SELECT AVG(rating) FROM customer_feedback WHERE customer_id = c.id)"
        : "NULL";
    $loyaltyJoin = $hasLoyalty
        ? "LEFT JOIN customer_loyalty l ON c.id = l.customer_id"
        : "";
    $loyaltyPoints = $hasLoyalty ? "COALESCE(l.loyalty_points, 0)" : "0";
    $loyaltySpent  = $hasLoyalty ? "COALESCE(l.total_spent, 0)"    : "0";
    $loyaltyVisits = $hasLoyalty ? "COALESCE(l.total_visits, 0)"   : "0";

    // Get customers with all details
    $sql = "
        SELECT 
            c.*,
            u.full_name as sales_rep_name,
            $loyaltyPoints as loyalty_points,
            $loyaltySpent as total_spent,
            $loyaltyVisits as total_visits,
            $interactionsSub as total_interactions,
            (SELECT COUNT(*) FROM job_cards WHERE customer_id = c.id) as total_jobs,
            (SELECT COUNT(*) FROM job_cards WHERE customer_id = c.id AND status = 'pending') as pending_jobs,
            (SELECT COUNT(*) FROM job_cards WHERE customer_id = c.id AND status = 'completed') as completed_jobs,
            (SELECT job_number FROM job_cards WHERE id = c.source_job_id LIMIT 1) as source_job_number,
            $ratingsSub as avg_rating
        FROM customers c
        LEFT JOIN users u ON c.assigned_sales_rep = u.id
        $loyaltyJoin
        $whereClause
        ORDER BY c.created_at DESC
        LIMIT $perPage OFFSET $offset
    ";
    
    $stmt = $conn->prepare($sql);
    $stmt->execute($params);
    $customers = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Get statistics - handle potentially missing columns (FIXED)
    try {
        $statsColumns = $conn->query("SHOW COLUMNS FROM customers")->fetchAll(PDO::FETCH_COLUMN);
        $hasCustomerTier = in_array('customer_tier', $statsColumns);
        $hasCustomerSource = in_array('customer_source', $statsColumns);
        $hasNextFollowUp = in_array('next_follow_up_date', $statsColumns);
        
        $tierSql = $hasCustomerTier ? "
            SUM(CASE WHEN customer_tier = 'platinum' THEN 1 ELSE 0 END) as platinum,
            SUM(CASE WHEN customer_tier = 'gold' THEN 1 ELSE 0 END) as gold,
            SUM(CASE WHEN customer_tier = 'silver' THEN 1 ELSE 0 END) as silver,
            SUM(CASE WHEN customer_tier = 'bronze' THEN 1 ELSE 0 END) as bronze," 
            : "0 as platinum, 0 as gold, 0 as silver, 0 as bronze,";
        
        $sourceSql = $hasCustomerSource ? "
            SUM(CASE WHEN customer_source = 'Job Card' THEN 1 ELSE 0 END) as from_job_cards,
            SUM(CASE WHEN customer_source = 'Direct' THEN 1 ELSE 0 END) as direct_customers,
            SUM(CASE WHEN customer_source = 'Referral' THEN 1 ELSE 0 END) as referrals,
            SUM(CASE WHEN customer_source = 'Website' THEN 1 ELSE 0 END) as website_customers," 
            : "0 as from_job_cards, 0 as direct_customers, 0 as referrals, 0 as website_customers,";
        
        $followupSql = $hasNextFollowUp ? "SUM(CASE WHEN next_follow_up_date <= CURDATE() AND next_follow_up_date IS NOT NULL THEN 1 ELSE 0 END) as pending_followups" : "0 as pending_followups";
        
        $stats = $conn->query("
            SELECT 
                COUNT(*) as total_customers,
                SUM(CASE WHEN status = 1 THEN 1 ELSE 0 END) as active,
                SUM(CASE WHEN status = 0 THEN 1 ELSE 0 END) as inactive,
                $tierSql
                $sourceSql
                $followupSql
            FROM customers
        ")->fetch(PDO::FETCH_ASSOC);
    } catch (PDOException $e2) {
        error_log("Stats query error: " . $e2->getMessage());
        $stats = [
            'total_customers' => 0,
            'active' => 0,
            'inactive' => 0,
            'platinum' => 0,
            'gold' => 0,
            'silver' => 0,
            'bronze' => 0,
            'from_job_cards' => 0,
            'direct_customers' => 0,
            'referrals' => 0,
            'website_customers' => 0,
            'pending_followups' => 0
        ];
    }
    
    // Get unique sources for filter
    try {
        $statsColumns = $conn->query("SHOW COLUMNS FROM customers")->fetchAll(PDO::FETCH_COLUMN);
        $sources = in_array('customer_source', $statsColumns ?? []) ? $conn->query("
            SELECT DISTINCT customer_source 
            FROM customers 
            WHERE customer_source IS NOT NULL AND customer_source != ''
            ORDER BY customer_source
        ")->fetchAll(PDO::FETCH_COLUMN) : [];
    } catch (PDOException $e3) {
        $sources = [];
    }
    
} catch(PDOException $e) {
    error_log("CRM Error: " . $e->getMessage());
    $error_message = $e->getMessage();
    $customers = [];
    $stats = [];
    $sources = [];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Customer Management | SAVANT MOTORS</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:opsz,wght@14..32,300;14..32,400;14..32,500;14..32,600;14..32,700;14..32,800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: 'Helvetica Neue', Helvetica, 'Inter', -apple-system, BlinkMacSystemFont, sans-serif;
            background: #f5f7fb;
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
            --gray-light: #94a3b8;
            --border: #e2e8f0;
            --bg-light: #f8fafc;
        }

        .sidebar {
            position: fixed;
            left: 0;
            top: 0;
            width: 268px;
            height: 100%;
            background: #fff;
            border-right: 1px solid #e8edf5;
            z-index: 1000;
            overflow-y: auto;
            display: flex;
            flex-direction: column;
            box-shadow: 4px 0 24px rgba(15,23,42,0.06);
        }

        .sidebar::-webkit-scrollbar { width: 4px; }
        .sidebar::-webkit-scrollbar-thumb { background: #e2e8f0; border-radius: 4px; }

        .sidebar-brand {
            padding: 22px 20px 16px;
            display: flex;
            align-items: center;
            gap: 12px;
            border-bottom: 1px solid #f1f5f9;
        }
        .brand-logo {
            width: 42px;
            height: 42px;
            background: linear-gradient(135deg, #2563eb, #7c3aed);
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
        }
        .brand-logo i { color: white; font-size: 18px; }
        .brand-text { flex: 1; min-width: 0; }
        .brand-name {
            font-size: 14px;
            font-weight: 800;
            color: #0f172a;
            letter-spacing: -0.3px;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        .brand-sub {
            font-size: 10px;
            color: #94a3b8;
            font-weight: 500;
            margin-top: 1px;
        }

        .sidebar-user {
            margin: 12px 14px;
            background: linear-gradient(135deg, #eff6ff, #f5f3ff);
            border-radius: 12px;
            padding: 10px 12px;
            display: flex;
            align-items: center;
            gap: 10px;
            border: 1px solid #e0e7ff;
        }
        .user-avatar-sm {
            width: 34px;
            height: 34px;
            border-radius: 10px;
            background: linear-gradient(135deg, #2563eb, #7c3aed);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 13px;
            font-weight: 700;
            color: white;
            flex-shrink: 0;
        }
        .user-info-sm .user-name-sm {
            font-size: 12px;
            font-weight: 700;
            color: #0f172a;
        }
        .user-info-sm .user-role-sm {
            font-size: 10px;
            color: #64748b;
            text-transform: capitalize;
        }

        .nav-section {
            padding: 16px 14px 4px;
        }
        .nav-section-label {
            font-size: 9px;
            font-weight: 800;
            color: #cbd5e1;
            text-transform: uppercase;
            letter-spacing: 1.2px;
            padding: 0 8px;
            margin-bottom: 4px;
        }

        .menu-item {
            padding: 9px 10px;
            display: flex;
            align-items: center;
            gap: 10px;
            color: #64748b;
            text-decoration: none;
            border-radius: 10px;
            font-size: 13px;
            font-weight: 500;
            transition: all 0.15s;
            margin-bottom: 1px;
            position: relative;
        }
        .menu-item .menu-icon {
            width: 32px;
            height: 32px;
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 14px;
            flex-shrink: 0;
            background: #f8fafc;
            transition: all 0.15s;
        }
        .menu-item span { flex: 1; }
        .menu-badge {
            background: #ef4444;
            color: white;
            font-size: 9px;
            font-weight: 700;
            padding: 1px 6px;
            border-radius: 20px;
            min-width: 18px;
            text-align: center;
        }
        .menu-badge.green { background: #10b981; }
        .menu-badge.amber { background: #f59e0b; }

        .menu-item:hover {
            background: #f8fafc;
            color: #0f172a;
        }
        .menu-item:hover .menu-icon {
            background: #eff6ff;
            color: #2563eb;
        }
        .menu-item.active {
            background: linear-gradient(135deg, #eff6ff, #f5f3ff);
            color: #2563eb;
            font-weight: 600;
        }
        .menu-item.active .menu-icon {
            background: #2563eb;
            color: white;
        }
        .menu-item.active::before {
            content: '';
            position: absolute;
            left: 0;
            top: 6px;
            bottom: 6px;
            width: 3px;
            background: #2563eb;
            border-radius: 0 3px 3px 0;
        }

        .menu-item.highlight-item .menu-icon { background: #dcfce7; color: #059669; }
        .menu-item.highlight-item:hover { background: #f0fdf4; color: #059669; }
        .menu-item.highlight-item:hover .menu-icon { background: #059669; color: white; }

        .sidebar-footer {
            margin-top: auto;
            padding: 12px 14px;
            border-top: 1px solid #f1f5f9;
        }
        .sidebar-footer .menu-item {
            color: #ef4444;
        }
        .sidebar-footer .menu-item .menu-icon { background: #fee2e2; color: #ef4444; }
        .sidebar-footer .menu-item:hover { background: #fef2f2; }
        .sidebar-footer .menu-item:hover .menu-icon { background: #ef4444; color: white; }

        .main-content {
            margin-left: 268px;
            padding: 1.5rem;
            min-height: 100vh;
        }

        .top-bar {
            background: white;
            border-radius: 1rem;
            padding: 1rem 1.5rem;
            margin-bottom: 1.5rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 1rem;
            box-shadow: 0 1px 3px rgba(0,0,0,0.05);
        }

        .page-title h1 {
            font-size: 1.3rem;
            font-weight: 700;
            color: var(--dark);
        }

        .page-title p {
            font-size: 0.75rem;
            color: var(--gray);
            margin-top: 0.25rem;
        }

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
            gap: 1rem;
            margin-bottom: 1.5rem;
        }

        .stat-card {
            background: white;
            border-radius: 0.75rem;
            padding: 1rem;
            transition: all 0.3s;
            border: 1px solid var(--border);
            cursor: pointer;
        }

        .stat-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(0,0,0,0.08);
        }

        .stat-value {
            font-size: 1.5rem;
            font-weight: 700;
            color: var(--dark);
        }

        .stat-label {
            font-size: 0.7rem;
            font-weight: 600;
            color: var(--gray);
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .stat-icon {
            width: 36px;
            height: 36px;
            border-radius: 0.5rem;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1rem;
            margin-bottom: 0.5rem;
        }

        .filter-bar {
            background: white;
            border-radius: 0.75rem;
            padding: 1rem;
            margin-bottom: 1.5rem;
            display: flex;
            gap: 0.75rem;
            flex-wrap: wrap;
            align-items: flex-end;
            border: 1px solid var(--border);
        }

        .filter-group {
            flex: 1;
            min-width: 140px;
        }

        .filter-group label {
            display: block;
            font-size: 0.65rem;
            font-weight: 700;
            color: var(--gray);
            margin-bottom: 0.25rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .filter-group input,
        .filter-group select {
            width: 100%;
            padding: 0.5rem 0.75rem;
            border: 1.5px solid var(--border);
            border-radius: 0.5rem;
            font-size: 0.8rem;
            transition: all 0.2s;
        }

        .filter-group input:focus,
        .filter-group select:focus {
            outline: none;
            border-color: var(--primary);
        }

        .table-container {
            background: white;
            border-radius: 1rem;
            overflow: hidden;
            border: 1px solid var(--border);
            box-shadow: 0 1px 3px rgba(0,0,0,0.05);
        }

        .customer-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 0.8rem;
        }

        .customer-table th {
            background: var(--bg-light);
            padding: 0.9rem 1rem;
            text-align: left;
            font-weight: 600;
            color: var(--dark);
            border-bottom: 1px solid var(--border);
            font-size: 0.75rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .customer-table td {
            padding: 0.9rem 1rem;
            border-bottom: 1px solid var(--border);
            vertical-align: middle;
        }

        .customer-table tr:hover {
            background: var(--bg-light);
        }

        .badge {
            display: inline-flex;
            align-items: center;
            gap: 0.3rem;
            padding: 0.25rem 0.6rem;
            border-radius: 2rem;
            font-size: 0.7rem;
            font-weight: 600;
        }

        .badge-jobcard {
            background: #dbeafe;
            color: #1e40af;
        }

        .badge-direct {
            background: #dcfce7;
            color: #166534;
        }

        .tier-badge {
            padding: 0.2rem 0.5rem;
            border-radius: 2rem;
            font-size: 0.65rem;
            font-weight: 700;
        }

        .tier-platinum {
            background: #e5deff;
            color: #5b21b6;
        }

        .tier-gold {
            background: #fed7aa;
            color: #9a3412;
        }

        .tier-silver {
            background: #e2e8f0;
            color: #1e293b;
        }

        .tier-bronze {
            background: #fed7aa;
            color: #92400e;
        }

        .status-badge {
            padding: 0.2rem 0.5rem;
            border-radius: 2rem;
            font-size: 0.65rem;
            font-weight: 600;
        }

        .status-active {
            background: #dcfce7;
            color: #166534;
        }

        .status-inactive {
            background: #fee2e2;
            color: #991b1b;
        }

        .stars {
            display: inline-flex;
            gap: 0.1rem;
        }

        .star-filled {
            color: #f59e0b;
            font-size: 0.7rem;
        }

        .star-empty {
            color: #e2e8f0;
            font-size: 0.7rem;
        }

        .action-btns {
            display: flex;
            gap: 0.3rem;
        }

        .action-btn {
            padding: 0.3rem 0.6rem;
            border-radius: 0.4rem;
            font-size: 0.7rem;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 0.3rem;
            border: none;
            background: none;
        }

        .action-btn-view {
            background: #e0e7ff;
            color: #4338ca;
        }

        .action-btn-view:hover {
            background: #4338ca;
            color: white;
        }

        .action-btn-edit {
            background: #dbeafe;
            color: #1e40af;
        }

        .action-btn-edit:hover {
            background: #1e40af;
            color: white;
        }

        .action-btn-job {
            background: #dcfce7;
            color: #166534;
        }

        .action-btn-job:hover {
            background: #166534;
            color: white;
        }

        .action-btn-reminder {
            background: #dcfce7;
            color: #059669;
        }
        .action-btn-reminder:hover {
            background: #059669;
            color: white;
        }

        .pagination {
            display: flex;
            justify-content: center;
            gap: 0.5rem;
            padding: 1.5rem;
        }

        .page-link {
            padding: 0.4rem 0.8rem;
            border: 1px solid var(--border);
            border-radius: 0.4rem;
            text-decoration: none;
            color: var(--dark);
            font-size: 0.8rem;
            transition: all 0.2s;
        }

        .page-link:hover {
            background: var(--primary);
            color: white;
            border-color: var(--primary);
        }

        .page-link.active {
            background: var(--primary);
            color: white;
            border-color: var(--primary);
        }

        .btn-primary {
            background: linear-gradient(135deg, var(--primary), var(--secondary));
            color: white;
            border: none;
            padding: 0.5rem 1rem;
            border-radius: 0.5rem;
            font-size: 0.8rem;
            font-weight: 600;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            text-decoration: none;
            transition: all 0.2s;
        }

        .btn-primary:hover {
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(37, 99, 235, 0.3);
        }

        .empty-state {
            text-align: center;
            padding: 3rem;
            color: var(--gray);
        }

        .reminder-modal-overlay {
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(15,23,42,0.55);
            backdrop-filter: blur(4px);
            z-index: 2000;
            align-items: center;
            justify-content: center;
        }
        .reminder-modal-overlay.open { display: flex; }

        .reminder-modal {
            background: white;
            border-radius: 20px;
            width: 92%;
            max-width: 640px;
            max-height: 90vh;
            overflow-y: auto;
            box-shadow: 0 25px 60px rgba(0,0,0,0.2);
            animation: modalIn 0.25s ease;
        }
        @keyframes modalIn {
            from { opacity: 0; transform: scale(0.95) translateY(12px); }
            to   { opacity: 1; transform: scale(1) translateY(0); }
        }
        .rm-header {
            padding: 20px 24px 16px;
            border-bottom: 1px solid #f1f5f9;
            display: flex;
            align-items: center;
            gap: 12px;
        }
        .rm-header-icon {
            width: 42px;
            height: 42px;
            border-radius: 12px;
            background: linear-gradient(135deg, #10b981, #059669);
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 18px;
            flex-shrink: 0;
        }
        .rm-header h3 { font-size: 16px; font-weight: 700; color: #0f172a; }
        .rm-header p  { font-size: 12px; color: #64748b; margin-top: 2px; }
        .rm-close {
            margin-left: auto;
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            width: 32px;
            height: 32px;
            border-radius: 8px;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #64748b;
            font-size: 16px;
            flex-shrink: 0;
            transition: all 0.15s;
        }
        .rm-close:hover { background: #fee2e2; color: #ef4444; border-color: #fecaca; }

        .rm-body { padding: 20px 24px; }
        .rm-field { margin-bottom: 16px; }
        .rm-field label {
            display: block;
            font-size: 11px;
            font-weight: 700;
            color: #475569;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 6px;
        }
        .rm-field input,
        .rm-field select,
        .rm-field textarea {
            width: 100%;
            padding: 10px 12px;
            border: 1.5px solid #e2e8f0;
            border-radius: 10px;
            font-size: 13px;
            font-family: inherit;
            transition: border-color 0.15s;
            background: white;
        }
        .rm-field input:focus,
        .rm-field select:focus,
        .rm-field textarea:focus {
            outline: none;
            border-color: #2563eb;
            box-shadow: 0 0 0 3px rgba(37,99,235,0.08);
        }
        .rm-field textarea { resize: vertical; min-height: 100px; }

        .channel-pills {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
        }
        .channel-pill {
            display: flex;
            align-items: center;
            gap: 6px;
            padding: 8px 14px;
            border: 2px solid #e2e8f0;
            border-radius: 40px;
            font-size: 12px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.15s;
            user-select: none;
            color: #64748b;
        }
        .channel-pill input[type="checkbox"] { display: none; }
        .channel-pill.whatsapp:has(input:checked)  { border-color: #25D366; background: #f0fdf4; color: #059669; }
        .channel-pill.email:has(input:checked)     { border-color: #2563eb; background: #eff6ff; color: #1d4ed8; }
        .channel-pill.sms:has(input:checked)       { border-color: #7c3aed; background: #f5f3ff; color: #6d28d9; }
        .channel-pill:hover { border-color: #94a3b8; background: #f8fafc; }

        .template-chips {
            display: flex;
            gap: 6px;
            flex-wrap: wrap;
            margin-bottom: 8px;
        }
        .tpl-chip {
            padding: 4px 10px;
            background: #f1f5f9;
            border: 1px solid #e2e8f0;
            border-radius: 20px;
            font-size: 11px;
            font-weight: 600;
            cursor: pointer;
            color: #475569;
            transition: all 0.15s;
        }
        .tpl-chip:hover { background: #dbeafe; color: #1d4ed8; border-color: #93c5fd; }

        .rm-footer {
            padding: 16px 24px;
            border-top: 1px solid #f1f5f9;
            display: flex;
            gap: 10px;
            justify-content: flex-end;
        }
        .btn-rm-cancel {
            padding: 9px 20px;
            border-radius: 40px;
            border: 1.5px solid #e2e8f0;
            background: white;
            color: #64748b;
            font-size: 13px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.15s;
        }
        .btn-rm-cancel:hover { background: #f8fafc; }
        .btn-rm-send {
            padding: 9px 22px;
            border-radius: 40px;
            border: none;
            background: linear-gradient(135deg, #10b981, #059669);
            color: white;
            font-size: 13px;
            font-weight: 700;
            cursor: pointer;
            display: flex;
            align-items: center;
            gap: 8px;
            transition: all 0.15s;
        }
        .btn-rm-send:hover { transform: translateY(-1px); box-shadow: 0 4px 12px rgba(16,185,129,0.35); }
        .btn-rm-send:disabled { opacity: 0.6; transform: none; cursor: not-allowed; }
        .btn-rm-schedule {
            padding: 9px 22px;
            border-radius: 40px;
            border: 1.5px solid #2563eb;
            background: white;
            color: #2563eb;
            font-size: 13px;
            font-weight: 700;
            cursor: pointer;
            display: flex;
            align-items: center;
            gap: 8px;
            transition: all 0.15s;
        }
        .btn-rm-schedule:hover { background: #eff6ff; }

        .bulk-reminder-bar {
            display: none;
            background: linear-gradient(135deg, #0f172a, #1e293b);
            color: white;
            border-radius: 12px;
            padding: 12px 20px;
            margin-bottom: 16px;
            align-items: center;
            gap: 14px;
            flex-wrap: wrap;
        }
        .bulk-reminder-bar.show { display: flex; }
        .bulk-count-badge {
            background: #2563eb;
            color: white;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 700;
        }
        .bulk-reminder-bar button {
            padding: 7px 16px;
            border-radius: 20px;
            border: none;
            font-size: 12px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.15s;
        }
        .btn-bulk-send { background: #10b981; color: white; }
        .btn-bulk-send:hover { background: #059669; }
        .btn-bulk-clear { background: rgba(255,255,255,0.15); color: white; }
        .btn-bulk-clear:hover { background: rgba(255,255,255,0.25); }

        /* ═══ INTELLIGENCE HUB ═══════════════════════════════════════════════ */
        .intel-hub {
            background: white; border-radius: 16px;
            border: 1px solid #e2e8f0; margin-bottom: 1.5rem;
            overflow: hidden; box-shadow: 0 2px 8px rgba(15,23,42,.06);
        }
        .intel-hub-header {
            background: linear-gradient(135deg, #1e40af, #7c3aed);
            padding: 1rem 1.5rem; display: flex; align-items: center;
            justify-content: space-between; flex-wrap: wrap; gap: .5rem;
        }
        .intel-hub-header h2 { color: white; font-size: 1rem; font-weight: 700; display:flex; align-items:center; gap:.5rem; }
        .intel-hub-header p  { color: rgba(255,255,255,.75); font-size: .72rem; margin-top:2px; }
        .intel-tabs { display:flex; border-bottom: 1px solid #e2e8f0; overflow-x:auto; background:#f8fafc; }
        .intel-tab {
            padding: .65rem 1.1rem; font-size: .72rem; font-weight: 600; color: #64748b;
            cursor: pointer; white-space: nowrap; border-bottom: 2px solid transparent;
            transition: all .18s; display:flex; align-items:center; gap:.35rem;
            background: none; border-top:none; border-left:none; border-right:none;
            font-family: inherit;
        }
        .intel-tab:hover  { color: #2563eb; background: #eff6ff; }
        .intel-tab.active { color: #2563eb; border-bottom-color: #2563eb; background: white; }
        .intel-pane { display:none; padding: 1.25rem 1.5rem; }
        .intel-pane.active { display:block; }

        /* Predictive cards */
        .pred-vehicle { border:1px solid #e2e8f0; border-radius:12px; padding:1rem; margin-bottom:.75rem; }
        .pred-vehicle-title { font-weight:700; color:#0f172a; font-size:.9rem; margin-bottom:.6rem; }
        .pred-alert {
            display:flex; align-items:center; gap:.6rem; padding:.45rem .75rem;
            border-radius:8px; font-size:.78rem; font-weight:600; margin:.25rem 0;
        }
        .pred-alert.critical { background:#fee2e2; color:#991b1b; }
        .pred-alert.warning  { background:#fef9c3; color:#854d0e; }
        .pred-alert.ok       { background:#dcfce7; color:#166534; }

        /* Inspection checklist */
        .insp-grid { display:grid; grid-template-columns:repeat(auto-fill,minmax(190px,1fr)); gap:.5rem; margin:.75rem 0; }
        .insp-item { display:flex; align-items:center; gap:.5rem; padding:.45rem .7rem; border:1px solid #e2e8f0; border-radius:8px; font-size:.78rem; font-weight:600; cursor:pointer; transition:all .15s; }
        .insp-item:hover { border-color:#2563eb; }
        .insp-item.pass   { background:#dcfce7; border-color:#86efac; color:#166534; }
        .insp-item.warn   { background:#fef9c3; border-color:#fde047; color:#854d0e; }
        .insp-item.fail   { background:#fee2e2; border-color:#fca5a5; color:#991b1b; }

        /* 100 days journey */
        .journey-line { position:relative; padding-left:28px; }
        .journey-line::before { content:''; position:absolute; left:10px; top:0; bottom:0; width:2px; background:#e2e8f0; }
        .journey-tp {
            position:relative; margin-bottom:.6rem; padding:.5rem .85rem;
            border-radius:10px; font-size:.78rem; font-weight:600;
            border: 1px solid #e2e8f0;
        }
        .journey-tp::before { content:''; position:absolute; left:-22px; top:50%; transform:translateY(-50%); width:12px; height:12px; border-radius:50%; border:2px solid white; }
        .journey-tp.sent     { background:#dcfce7; color:#166534; }
        .journey-tp.sent::before { background:#10b981; }
        .journey-tp.missed   { background:#fee2e2; color:#991b1b; }
        .journey-tp.missed::before { background:#ef4444; }
        .journey-tp.due_soon { background:#fef9c3; color:#854d0e; }
        .journey-tp.due_soon::before { background:#f59e0b; }
        .journey-tp.upcoming { background:#f8fafc; color:#64748b; }
        .journey-tp.upcoming::before { background:#e2e8f0; }

        /* Segmentation */
        .seg-grid { display:grid; grid-template-columns:repeat(auto-fill,minmax(160px,1fr)); gap:.75rem; margin:.75rem 0; }
        .seg-card {
            border-radius:12px; padding:1rem; text-align:center; cursor:pointer; transition:transform .15s;
            border: 1px solid transparent;
        }
        .seg-card:hover { transform:translateY(-2px); }
        .seg-card .seg-count { font-size:1.8rem; font-weight:800; }
        .seg-card .seg-label { font-size:.72rem; font-weight:700; margin-top:4px; }
        .seg-card.champions  { background:#ede9fe; color:#5b21b6; border-color:#c4b5fd; }
        .seg-card.loyal      { background:#dcfce7; color:#166534; border-color:#86efac; }
        .seg-card.at_risk    { background:#fef9c3; color:#854d0e; border-color:#fde047; }
        .seg-card.lost       { background:#fee2e2; color:#991b1b; border-color:#fca5a5; }
        .seg-card.new_cust   { background:#dbeafe; color:#1e40af; border-color:#93c5fd; }
        .seg-card.potential  { background:#f0fdf4; color:#166534; border-color:#bbf7d0; }

        /* Conversation thread */
        .convo-thread { max-height:280px; overflow-y:auto; display:flex; flex-direction:column; gap:.5rem; padding:.5rem 0; }
        .convo-msg {
            max-width:80%; padding:.55rem .9rem; border-radius:12px;
            font-size:.8rem; font-weight:500; line-height:1.4;
        }
        .convo-msg.out { align-self:flex-end; background:#dbeafe; color:#1e40af; border-radius:12px 12px 2px 12px; }
        .convo-msg.in  { align-self:flex-start; background:#f1f5f9; color:#0f172a; border-radius:12px 12px 12px 2px; }
        .convo-meta { font-size:.62rem; color:#94a3b8; margin-top:2px; }

        /* Lifecycle stages */
        .lc-stages { display:flex; gap:.35rem; flex-wrap:wrap; margin:.75rem 0; }
        .lc-badge {
            padding:.4rem 1rem; border-radius:2rem; font-size:.72rem; font-weight:700; cursor:pointer; border:2px solid transparent; transition:all .15s;
        }
        .lc-badge.lead       { background:#f1f5f9; color:#64748b; }
        .lc-badge.new        { background:#dbeafe; color:#1e40af; }
        .lc-badge.active     { background:#dcfce7; color:#166534; }
        .lc-badge.loyal      { background:#ede9fe; color:#5b21b6; }
        .lc-badge.at_risk    { background:#fef9c3; color:#854d0e; }
        .lc-badge.churned    { background:#fee2e2; color:#991b1b; }
        .lc-badge.reactivated{ background:#d1fae5; color:#065f46; }
        .lc-badge.selected   { border-color:currentColor; }

        /* Lead capture form */
        .lead-form { display:grid; grid-template-columns:1fr 1fr; gap:.75rem; }
        @media(max-width:600px){ .lead-form { grid-template-columns:1fr; } }

        /* Shared mini form styling */
        .intel-form-group { display:flex; flex-direction:column; gap:.25rem; }
        .intel-form-group label { font-size:.68rem; font-weight:700; text-transform:uppercase; color:#64748b; }
        .intel-form-group input,
        .intel-form-group select,
        .intel-form-group textarea {
            border:1px solid #e2e8f0; border-radius:8px; padding:.45rem .7rem;
            font-size:.83rem; font-family:inherit; outline:none; transition:border .15s;
        }
        .intel-form-group input:focus,
        .intel-form-group select:focus,
        .intel-form-group textarea:focus { border-color:#2563eb; }
        .intel-btn {
            padding:.5rem 1.1rem; border-radius:8px; font-size:.78rem; font-weight:700;
            border:none; cursor:pointer; font-family:inherit; display:inline-flex; align-items:center; gap:.35rem; transition:all .15s;
        }
        .intel-btn-primary { background:#2563eb; color:white; }
        .intel-btn-primary:hover { background:#1e40af; }
        .intel-btn-success { background:#10b981; color:white; }
        .intel-btn-success:hover { background:#059669; }
        .intel-btn-secondary { background:#f1f5f9; color:#0f172a; }
        .intel-btn-secondary:hover { background:#e2e8f0; }

        .rm-toast {
            position: fixed;
            bottom: 24px;
            right: 24px;
            background: #0f172a;
            color: white;
            padding: 12px 18px;
            border-radius: 12px;
            font-size: 13px;
            font-weight: 500;
            z-index: 9999;
            display: flex;
            align-items: center;
            gap: 10px;
            box-shadow: 0 8px 24px rgba(0,0,0,0.2);
            animation: toastIn 0.25s ease;
        }
        @keyframes toastIn {
            from { opacity: 0; transform: translateY(12px); }
        }
        .rm-toast.success { border-left: 4px solid #10b981; }
        .rm-toast.error   { border-left: 4px solid #ef4444; }

        @media (max-width: 768px) {
            .sidebar { left: -268px; transition: left 0.3s; }
            .sidebar.show { left: 0; }
            .main-content { margin-left: 0; }
        }
    </style>
</head>
<body>
    <div class="sidebar" id="sidebar">
        <div class="sidebar-brand">
            <div class="brand-logo"><i class="fas fa-charging-station"></i></div>
            <div class="brand-text">
                <div class="brand-name">SAVANT MOTORS</div>
                <div class="brand-sub">Enterprise Resource Planning</div>
            </div>
        </div>

        <div class="sidebar-user">
            <div class="user-avatar-sm"><?php echo strtoupper(substr($user_full_name, 0, 2)); ?></div>
            <div class="user-info-sm">
                <div class="user-name-sm"><?php echo htmlspecialchars($user_full_name); ?></div>
                <div class="user-role-sm"><?php echo htmlspecialchars($user_role); ?></div>
            </div>
        </div>

        <div class="nav-section">
            <div class="nav-section-label">Main</div>
            <a href="../dashboard_erp.php" class="menu-item">
                <div class="menu-icon"><i class="fas fa-chart-pie"></i></div>
                <span>Dashboard</span>
            </a>
            <a href="index.php" class="menu-item active">
                <div class="menu-icon"><i class="fas fa-users"></i></div>
                <span>Customers</span>
                <span class="menu-badge green"><?php echo number_format($stats['total_customers'] ?? 0); ?></span>
            </a>
            <a href="../job_cards/index.php" class="menu-item">
                <div class="menu-icon"><i class="fas fa-clipboard-list"></i></div>
                <span>Job Cards</span>
            </a>
            <a href="../quotations.php" class="menu-item">
                <div class="menu-icon"><i class="fas fa-file-invoice"></i></div>
                <span>Quotations</span>
            </a>
            <a href="../invoices.php" class="menu-item">
                <div class="menu-icon"><i class="fas fa-file-invoice-dollar"></i></div>
                <span>Invoices</span>
            </a>
        </div>

        <div class="nav-section">
            <div class="nav-section-label">CRM</div>
            <a href="#" class="menu-item highlight-item" onclick="openBulkReminderModal(); return false;">
                <div class="menu-icon"><i class="fas fa-bell"></i></div>
                <span>Service Reminders</span>
                <?php if (($stats['pending_followups'] ?? 0) > 0): ?>
                <span class="menu-badge amber"><?php echo $stats['pending_followups']; ?></span>
                <?php endif; ?>
            </a>
            <a href="create.php" class="menu-item">
                <div class="menu-icon"><i class="fas fa-user-plus"></i></div>
                <span>Add Customer</span>
            </a>
            <a href="../reminders/index.php" class="menu-item">
                <div class="menu-icon"><i class="fas fa-calendar-check"></i></div>
                <span>Pickup Reminders</span>
            </a>
            <a href="niche_identifier.php" class="menu-item">
                <div class="menu-icon"><i class="fas fa-crosshairs"></i></div>
                <span>Niche Identifier</span>
            </a>
        </div>

        <div class="nav-section">
            <div class="nav-section-label">Operations</div>
            <a href="../purchases/index.php" class="menu-item">
                <div class="menu-icon"><i class="fas fa-shopping-cart"></i></div>
                <span>Purchases</span>
            </a>
            <a href="../suppliers.php" class="menu-item">
                <div class="menu-icon"><i class="fas fa-truck"></i></div>
                <span>Suppliers</span>
            </a>
            <a href="../inventory.php" class="menu-item">
                <div class="menu-icon"><i class="fas fa-boxes"></i></div>
                <span>Inventory</span>
            </a>
            <a href="../attendance.php" class="menu-item">
                <div class="menu-icon"><i class="fas fa-user-clock"></i></div>
                <span>Attendance</span>
            </a>
        </div>

        <div class="sidebar-footer">
            <a href="../logout.php" class="menu-item">
                <div class="menu-icon"><i class="fas fa-sign-out-alt"></i></div>
                <span>Logout</span>
            </a>
        </div>
    </div>

    <div class="main-content">
        <div class="top-bar">
            <div class="page-title">
                <h1><i class="fas fa-users" style="color: var(--primary);"></i> Customer Management</h1>
                <p>Manage all customers including those from job cards</p>
            </div>
            <div style="display:flex;gap:8px;flex-wrap:wrap;">
                <button class="btn-primary" style="background:linear-gradient(135deg,#10b981,#059669);" onclick="openBulkReminderModal()">
                    <i class="fas fa-bell"></i> Send Reminder
                </button>
                <a href="create.php" class="btn-primary">
                    <i class="fas fa-plus-circle"></i> Add Customer
                </a>
            </div>
        </div>

        <?php if ($error_message): ?>
        <div style="background:#fee2e2;color:#991b1b;border-left:4px solid #ef4444;padding:12px 18px;border-radius:10px;margin-bottom:1rem;font-size:13px;">
            <i class="fas fa-exclamation-triangle"></i> <strong>Database Error:</strong> <?php echo htmlspecialchars($error_message); ?>
        </div>
        <?php endif; ?>

        <div class="bulk-reminder-bar" id="bulkBar">
            <span class="bulk-count-badge" id="bulkCount">0 selected</span>
            <span style="font-size:13px;opacity:0.8;">customers selected for bulk reminder</span>
            <button class="btn-bulk-send" onclick="openBulkReminderModal()"><i class="fas fa-paper-plane"></i> Send Bulk Reminder</button>
            <button class="btn-bulk-clear" onclick="clearSelection()"><i class="fas fa-times"></i> Clear</button>
        </div>

        <div class="stats-grid">
            <div class="stat-card" onclick="window.location.href='index.php'">
                <div class="stat-icon" style="background: #dbeafe; color: var(--primary);"><i class="fas fa-users"></i></div>
                <div class="stat-value"><?php echo number_format($stats['total_customers'] ?? 0); ?></div>
                <div class="stat-label">Total Customers</div>
            </div>
            <div class="stat-card" onclick="window.location.href='index.php?source=Job Card'">
                <div class="stat-icon" style="background: #dbeafe; color: #1e40af;"><i class="fas fa-clipboard-list"></i></div>
                <div class="stat-value"><?php echo number_format($stats['from_job_cards'] ?? 0); ?></div>
                <div class="stat-label">From Job Cards</div>
            </div>
            <div class="stat-card" onclick="window.location.href='index.php?status=active'">
                <div class="stat-icon" style="background: #dcfce7; color: var(--success);"><i class="fas fa-user-check"></i></div>
                <div class="stat-value"><?php echo number_format($stats['active'] ?? 0); ?></div>
                <div class="stat-label">Active</div>
            </div>
            <div class="stat-card" onclick="window.location.href='index.php?tier=platinum'">
                <div class="stat-icon" style="background: #fed7aa; color: var(--warning);"><i class="fas fa-crown"></i></div>
                <div class="stat-value"><?php echo number_format(($stats['platinum'] ?? 0) + ($stats['gold'] ?? 0)); ?></div>
                <div class="stat-label">VIP Customers</div>
            </div>
        </div>

        <div class="filter-bar">
            <div class="filter-group">
                <label><i class="fas fa-search"></i> Search</label>
                <input type="text" id="searchInput" placeholder="Name, phone, email..." value="<?php echo htmlspecialchars($search); ?>">
            </div>
            <div class="filter-group">
                <label><i class="fas fa-trophy"></i> Tier</label>
                <select id="tierFilter">
                    <option value="">All Tiers</option>
                    <option value="platinum" <?php echo $tier == 'platinum' ? 'selected' : ''; ?>>Platinum</option>
                    <option value="gold" <?php echo $tier == 'gold' ? 'selected' : ''; ?>>Gold</option>
                    <option value="silver" <?php echo $tier == 'silver' ? 'selected' : ''; ?>>Silver</option>
                    <option value="bronze" <?php echo $tier == 'bronze' ? 'selected' : ''; ?>>Bronze</option>
                </select>
            </div>
            <div class="filter-group">
                <label><i class="fas fa-chart-line"></i> Source</label>
                <select id="sourceFilter">
                    <option value="">All Sources</option>
                    <option value="Job Card" <?php echo $source == 'Job Card' ? 'selected' : ''; ?>>📋 Job Card</option>
                    <option value="Direct" <?php echo $source == 'Direct' ? 'selected' : ''; ?>>👤 Direct</option>
                    <option value="Referral" <?php echo $source == 'Referral' ? 'selected' : ''; ?>>🤝 Referral</option>
                    <option value="Website" <?php echo $source == 'Website' ? 'selected' : ''; ?>>🌐 Website</option>
                    <?php foreach ($sources as $src): ?>
                        <?php if (!in_array($src, ['Job Card', 'Direct', 'Referral', 'Website'])): ?>
                        <option value="<?php echo $src; ?>" <?php echo $source == $src ? 'selected' : ''; ?>><?php echo $src; ?></option>
                        <?php endif; ?>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="filter-group">
                <label><i class="fas fa-flag"></i> Status</label>
                <select id="statusFilter">
                    <option value="all" <?php echo $status == 'all' ? 'selected' : ''; ?>>All</option>
                    <option value="active" <?php echo $status == 'active' ? 'selected' : ''; ?>>Active</option>
                    <option value="inactive" <?php echo $status == 'inactive' ? 'selected' : ''; ?>>Inactive</option>
                </select>
            </div>
            <div class="filter-group">
                <button class="btn-primary" onclick="applyFilters()" style="width: 100%; justify-content: center;">
                    <i class="fas fa-search"></i> Filter
                </button>
            </div>
        </div>

        <!-- ════════════════════════════════════════════════════════════════
             SAVANT MOTORS — INTELLIGENCE HUB
             8 AI-powered CRM modules in a single tabbed panel
        ═══════════════════════════════════════════════════════════════ -->
        <div class="intel-hub" id="intelHub">
            <div class="intel-hub-header">
                <div>
                    <h2>🧠 Intelligence Hub</h2>
                    <p>Predictive Maintenance · Digital Inspections · 100-Day Journey · Segmentation · Reviews · Lead Capture · Comms · Lifecycle</p>
                </div>
                <button class="intel-btn intel-btn-secondary" onclick="document.getElementById('intelHub').style.display='none'" style="font-size:.7rem;padding:.3rem .75rem;">
                    <i class="fas fa-compress-alt"></i> Minimise
                </button>
            </div>

            <!-- Tab Strip -->
            <div class="intel-tabs">
                <button class="intel-tab active" onclick="switchIntelTab('predictive',this)"><i class="fas fa-brain"></i> Predictive Maint.</button>
                <button class="intel-tab" onclick="switchIntelTab('inspection',this)"><i class="fas fa-clipboard-check"></i> Digital Inspection</button>
                <button class="intel-tab" onclick="switchIntelTab('journey',this)"><i class="fas fa-route"></i> 100 Days Journey</button>
                <button class="intel-tab" onclick="switchIntelTab('segmentation',this)"><i class="fas fa-layer-group"></i> Segmentation</button>
                <button class="intel-tab" onclick="switchIntelTab('reviews',this)"><i class="fas fa-star"></i> Auto Reviews</button>
                <button class="intel-tab" onclick="switchIntelTab('leads',this)"><i class="fas fa-funnel-dollar"></i> Lead Capture</button>
                <button class="intel-tab" onclick="switchIntelTab('comms',this)"><i class="fas fa-comments"></i> Two-Way Comms</button>
                <button class="intel-tab" onclick="switchIntelTab('lifecycle',this)"><i class="fas fa-recycle"></i> Lifecycle</button>
            </div>

            <!-- ① PREDICTIVE MAINTENANCE -->
            <div class="intel-pane active" id="pane-predictive">
                <p style="font-size:.78rem;color:#64748b;margin-bottom:.75rem;">Select a customer to see model-specific service predictions based on mileage and service history.</p>
                <div style="display:flex;gap:.5rem;flex-wrap:wrap;align-items:center;margin-bottom:.75rem;">
                    <select id="predCustomerSel" style="flex:1;min-width:200px;border:1px solid #e2e8f0;border-radius:8px;padding:.45rem .7rem;font-size:.83rem;font-family:inherit;">
                        <option value="">— Pick a customer —</option>
                        <?php foreach($customers as $ccc): ?>
                        <option value="<?=$ccc['id']?>"><?=htmlspecialchars($ccc['full_name'])?></option>
                        <?php endforeach; ?>
                    </select>
                    <button class="intel-btn intel-btn-primary" onclick="loadPredictive()"><i class="fas fa-search"></i> Analyse</button>
                </div>
                <div id="predResults"></div>
            </div>

            <!-- ② DIGITAL VEHICLE INSPECTION -->
            <div class="intel-pane" id="pane-inspection">
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:1rem;">
                    <div>
                        <h4 style="font-size:.82rem;font-weight:700;color:#0f172a;margin-bottom:.75rem;"><i class="fas fa-clipboard-check" style="color:#2563eb;"></i> New Inspection</h4>
                        <div style="display:flex;flex-direction:column;gap:.5rem;">
                            <div class="intel-form-group">
                                <label>Customer</label>
                                <select id="inspCustomer">
                                    <option value="">— Select —</option>
                                    <?php foreach($customers as $ccc): ?>
                                    <option value="<?=$ccc['id']?>"><?=htmlspecialchars($ccc['full_name'])?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="intel-form-group">
                                <label>Vehicle Reg</label>
                                <input type="text" id="inspReg" placeholder="e.g. UAA 123B">
                            </div>
                            <div class="intel-form-group">
                                <label>Overall Condition</label>
                                <select id="inspCondition">
                                    <option value="excellent">✅ Excellent</option>
                                    <option value="good" selected>👍 Good</option>
                                    <option value="fair">⚠️ Fair</option>
                                    <option value="poor">❌ Poor</option>
                                </select>
                            </div>
                            <div style="margin:.5rem 0 .25rem;">
                                <label style="font-size:.68rem;font-weight:700;text-transform:uppercase;color:#64748b;">Checklist — click to cycle: ✅ Pass / ⚠️ Note / ❌ Fail</label>
                            </div>
                            <div class="insp-grid" id="inspChecklist">
                                <?php $checkItems = ['Engine Oil','Coolant','Brake Fluid','Power Steering','Windscreen Washer','Air Filter','Brakes (Front)','Brakes (Rear)','Tyres (Front)','Tyres (Rear)','Battery','Lights','Wipers','AC System','Suspension','Exhaust','Seatbelts','Horn']; ?>
                                <?php foreach($checkItems as $ci): ?>
                                <div class="insp-item ok" data-item="<?=htmlspecialchars($ci)?>" data-status="pass" onclick="cycleInspItem(this)">
                                    <span class="insp-icon">✅</span>
                                    <span style="font-size:.72rem;"><?=htmlspecialchars($ci)?></span>
                                </div>
                                <?php endforeach; ?>
                            </div>
                            <div class="intel-form-group">
                                <label>Technician Notes</label>
                                <textarea id="inspNotes" rows="2" placeholder="Additional observations…"></textarea>
                            </div>
                            <div style="display:flex;align-items:center;gap:.5rem;">
                                <input type="checkbox" id="inspShare" style="width:16px;height:16px;">
                                <label for="inspShare" style="font-size:.8rem;font-weight:600;cursor:pointer;">Share report with customer via WhatsApp</label>
                            </div>
                            <button class="intel-btn intel-btn-primary" onclick="saveInspection()"><i class="fas fa-save"></i> Save Inspection</button>
                        </div>
                    </div>
                    <div>
                        <h4 style="font-size:.82rem;font-weight:700;color:#0f172a;margin-bottom:.75rem;"><i class="fas fa-history" style="color:#7c3aed;"></i> Past Inspections</h4>
                        <div id="inspHistory" style="font-size:.8rem;color:#64748b;">Select a customer and load history below.</div>
                        <button class="intel-btn intel-btn-secondary" style="margin-top:.5rem;" onclick="loadInspHistory()"><i class="fas fa-sync"></i> Load History</button>
                    </div>
                </div>
            </div>

            <!-- ③ FIRST 100 DAYS JOURNEY -->
            <div class="intel-pane" id="pane-journey">
                <p style="font-size:.78rem;color:#64748b;margin-bottom:.75rem;">Omnichannel touchpoint sequence for new customers — track which messages have been sent and what's coming up.</p>
                <div style="display:flex;gap:.5rem;flex-wrap:wrap;align-items:center;margin-bottom:.75rem;">
                    <select id="journeyCustomerSel" style="flex:1;min-width:200px;border:1px solid #e2e8f0;border-radius:8px;padding:.45rem .7rem;font-size:.83rem;font-family:inherit;">
                        <option value="">— Pick a customer —</option>
                        <?php foreach($customers as $ccc): ?>
                        <option value="<?=$ccc['id']?>"><?=htmlspecialchars($ccc['full_name'])?></option>
                        <?php endforeach; ?>
                    </select>
                    <button class="intel-btn intel-btn-primary" onclick="load100Days()"><i class="fas fa-route"></i> Load Journey</button>
                </div>
                <div id="journeyResults"></div>
            </div>

            <!-- ④ SEGMENTATION -->
            <div class="intel-pane" id="pane-segmentation">
                <p style="font-size:.78rem;color:#64748b;margin-bottom:.75rem;">RFM-based customer segmentation — Recency, Frequency, Monetary value. Click a segment to see customers.</p>
                <button class="intel-btn intel-btn-primary" onclick="loadSegmentation()" style="margin-bottom:.75rem;"><i class="fas fa-layer-group"></i> Run Segmentation</button>
                <div id="segResults"></div>
                <div id="segDetail" style="margin-top:1rem;"></div>
            </div>

            <!-- ⑤ AUTOMATED REVIEW GENERATION -->
            <div class="intel-pane" id="pane-reviews">
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:1rem;">
                    <div>
                        <h4 style="font-size:.82rem;font-weight:700;color:#0f172a;margin-bottom:.75rem;"><i class="fas fa-star" style="color:#f59e0b;"></i> Generate Review Request</h4>
                        <div style="display:flex;flex-direction:column;gap:.5rem;">
                            <div class="intel-form-group">
                                <label>Customer</label>
                                <select id="reviewCustomer">
                                    <option value="">— Select —</option>
                                    <?php foreach($customers as $ccc): ?>
                                    <option value="<?=$ccc['id']?>"><?=htmlspecialchars($ccc['full_name'])?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="intel-form-group">
                                <label>Platform</label>
                                <select id="reviewPlatform">
                                    <option value="google">🔍 Google</option>
                                    <option value="facebook">👍 Facebook</option>
                                    <option value="internal">⭐ Internal (Savant)</option>
                                </select>
                            </div>
                            <div class="intel-form-group">
                                <label>Send via</label>
                                <select id="reviewChannel">
                                    <option value="whatsapp">💬 WhatsApp</option>
                                    <option value="sms">📱 SMS</option>
                                    <option value="email">📧 Email</option>
                                </select>
                            </div>
                            <button class="intel-btn intel-btn-success" onclick="generateReview()"><i class="fas fa-paper-plane"></i> Generate & Send Request</button>
                        </div>
                    </div>
                    <div id="reviewPreview" style="background:#f8fafc;border-radius:12px;padding:1rem;font-size:.8rem;color:#475569;">
                        <div style="font-size:.68rem;font-weight:700;text-transform:uppercase;color:#94a3b8;margin-bottom:.5rem;">Message Preview</div>
                        <p style="color:#94a3b8;">Select a customer and platform to preview the message.</p>
                    </div>
                </div>
            </div>

            <!-- ⑥ AI LEAD & CALL CAPTURE -->
            <div class="intel-pane" id="pane-leads">
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:1rem;">
                    <div>
                        <h4 style="font-size:.82rem;font-weight:700;color:#0f172a;margin-bottom:.75rem;"><i class="fas fa-funnel-dollar" style="color:#2563eb;"></i> Capture New Lead</h4>
                        <div class="lead-form">
                            <div class="intel-form-group">
                                <label>Full Name *</label>
                                <input type="text" id="leadName" placeholder="Caller / visitor name">
                            </div>
                            <div class="intel-form-group">
                                <label>Phone</label>
                                <input type="text" id="leadPhone" placeholder="+256 7xx xxxxxx">
                            </div>
                            <div class="intel-form-group">
                                <label>Source</label>
                                <select id="leadSource">
                                    <option value="call">📞 Incoming Call</option>
                                    <option value="walk_in">🚶 Walk-In</option>
                                    <option value="whatsapp">💬 WhatsApp</option>
                                    <option value="website">🌐 Website</option>
                                    <option value="referral">🤝 Referral</option>
                                    <option value="social">📲 Social Media</option>
                                </select>
                            </div>
                            <div class="intel-form-group">
                                <label>Vehicle Interest</label>
                                <input type="text" id="leadVehicle" placeholder="e.g. Toyota RAV4 service">
                            </div>
                            <div class="intel-form-group" style="grid-column:1/-1;">
                                <label>Notes / Summary</label>
                                <textarea id="leadNotes" rows="2" placeholder="What did the customer say? Any key details…"></textarea>
                            </div>
                            <div class="intel-form-group" style="grid-column:1/-1;">
                                <label>AI Summary (auto or manual)</label>
                                <textarea id="leadAiSummary" rows="2" placeholder="AI-generated or manual summary of the interaction…"></textarea>
                            </div>
                        </div>
                        <button class="intel-btn intel-btn-primary" style="margin-top:.75rem;" onclick="captureLead()"><i class="fas fa-user-plus"></i> Save Lead</button>
                    </div>
                    <div>
                        <h4 style="font-size:.82rem;font-weight:700;color:#0f172a;margin-bottom:.75rem;"><i class="fas fa-list-ul" style="color:#7c3aed;"></i> Recent Leads</h4>
                        <button class="intel-btn intel-btn-secondary" onclick="loadLeads()" style="margin-bottom:.5rem;"><i class="fas fa-sync"></i> Refresh</button>
                        <div id="leadsTable" style="font-size:.78rem;color:#64748b;"></div>
                    </div>
                </div>
            </div>

            <!-- ⑦ TWO-WAY COMMUNICATION -->
            <div class="intel-pane" id="pane-comms">
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:1rem;">
                    <div>
                        <h4 style="font-size:.82rem;font-weight:700;color:#0f172a;margin-bottom:.5rem;"><i class="fas fa-comments" style="color:#2563eb;"></i> Conversation</h4>
                        <div style="display:flex;gap:.5rem;margin-bottom:.5rem;flex-wrap:wrap;">
                            <select id="commsCustomerSel" style="flex:1;border:1px solid #e2e8f0;border-radius:8px;padding:.4rem .6rem;font-size:.8rem;font-family:inherit;" onchange="loadInteractions()">
                                <option value="">— Select customer —</option>
                                <?php foreach($customers as $ccc): ?>
                                <option value="<?=$ccc['id']?>"><?=htmlspecialchars($ccc['full_name'])?></option>
                                <?php endforeach; ?>
                            </select>
                            <select id="commsChannel" style="border:1px solid #e2e8f0;border-radius:8px;padding:.4rem .6rem;font-size:.8rem;font-family:inherit;">
                                <option value="whatsapp">💬 WhatsApp</option>
                                <option value="sms">📱 SMS</option>
                                <option value="email">📧 Email</option>
                                <option value="call">📞 Call</option>
                                <option value="in_person">🤝 In Person</option>
                            </select>
                        </div>
                        <div class="convo-thread" id="convoThread">
                            <div style="text-align:center;color:#94a3b8;font-size:.78rem;padding:1rem;">Select a customer to load conversation history</div>
                        </div>
                        <div style="display:flex;gap:.4rem;margin-top:.5rem;">
                            <input type="text" id="commsMsg" placeholder="Type a message…" style="flex:1;border:1px solid #e2e8f0;border-radius:8px;padding:.4rem .7rem;font-size:.8rem;font-family:inherit;">
                            <button class="intel-btn intel-btn-primary" onclick="sendInteraction('outbound')"><i class="fas fa-paper-plane"></i></button>
                        </div>
                        <button class="intel-btn intel-btn-secondary" style="margin-top:.3rem;font-size:.72rem;" onclick="sendInteraction('inbound')"><i class="fas fa-inbox"></i> Log Inbound Reply</button>
                    </div>
                    <div>
                        <h4 style="font-size:.82rem;font-weight:700;color:#0f172a;margin-bottom:.5rem;"><i class="fas fa-broadcast-tower" style="color:#10b981;"></i> Bulk Broadcast</h4>
                        <p style="font-size:.75rem;color:#64748b;margin-bottom:.75rem;">Select customers in the table below, then use the bulk reminder tool to send a message to all selected.</p>
                        <div style="background:#f0fdf4;border:1px solid #86efac;border-radius:10px;padding:.75rem 1rem;font-size:.78rem;color:#166534;font-weight:600;">
                            <i class="fas fa-info-circle"></i> Use the checkboxes in the customer table → Bulk Action → Send Reminder to broadcast to multiple customers at once.
                        </div>
                    </div>
                </div>
            </div>

            <!-- ⑧ LIFECYCLE MANAGEMENT -->
            <div class="intel-pane" id="pane-lifecycle">
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:1rem;">
                    <div>
                        <h4 style="font-size:.82rem;font-weight:700;color:#0f172a;margin-bottom:.75rem;"><i class="fas fa-recycle" style="color:#7c3aed;"></i> Set Lifecycle Stage</h4>
                        <div style="display:flex;flex-direction:column;gap:.5rem;">
                            <div class="intel-form-group">
                                <label>Customer</label>
                                <select id="lcCustomer">
                                    <option value="">— Select —</option>
                                    <?php foreach($customers as $ccc): ?>
                                    <option value="<?=$ccc['id']?>"><?=htmlspecialchars($ccc['full_name'])?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="intel-form-group">
                                <label>Stage</label>
                                <div class="lc-stages" id="lcStages">
                                    <?php foreach(['lead','new','active','loyal','at_risk','churned','reactivated'] as $st): ?>
                                    <button class="lc-badge <?=$st?>" onclick="selectLcStage(this,'<?=$st?>')"><?=ucfirst(str_replace('_',' ',$st))?></button>
                                    <?php endforeach; ?>
                                </div>
                                <input type="hidden" id="lcStageVal" value="active">
                            </div>
                            <div class="intel-form-group">
                                <label>Next Recommended Action</label>
                                <input type="text" id="lcNextAction" placeholder="e.g. Call for service reminder">
                            </div>
                            <div class="intel-form-group">
                                <label>Action Due Date</label>
                                <input type="date" id="lcNextDate">
                            </div>
                            <div class="intel-form-group">
                                <label>Notes</label>
                                <textarea id="lcNotes" rows="2" placeholder="Why this stage?"></textarea>
                            </div>
                            <button class="intel-btn intel-btn-primary" onclick="saveLifecycle()"><i class="fas fa-save"></i> Save Stage</button>
                        </div>
                    </div>
                    <div>
                        <h4 style="font-size:.82rem;font-weight:700;color:#0f172a;margin-bottom:.75rem;"><i class="fas fa-chart-pie" style="color:#f59e0b;"></i> Portfolio Overview</h4>
                        <button class="intel-btn intel-btn-secondary" onclick="loadLifecycleOverview()" style="margin-bottom:.5rem;"><i class="fas fa-sync"></i> Load Overview</button>
                        <div id="lcOverview" style="font-size:.78rem;color:#64748b;"></div>
                    </div>
                </div>
            </div>
        </div>
        <!-- ═══ END INTELLIGENCE HUB ════════════════════════════════════════ -->

        <div class="table-container">
            <table class="customer-table">
                <thead>
                    <tr>
                        <th><input type="checkbox" id="selectAll" onclick="toggleSelectAll(this)" style="cursor:pointer;width:14px;height:14px;"></th>
                        <th>ID</th>
                        <th>Customer Name</th>
                        <th>Contact</th>
                        <th>Source</th>
                        <th>Tier</th>
                        <th>Jobs</th>
                        <th>Total Spent</th>
                        <th>Points</th>
                        <th>Rating</th>
                        <th>Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($customers)): ?>
                    <tr>
                        <td colspan="12" class="empty-state">
                            <i class="fas fa-users-slash" style="font-size: 2rem; opacity: 0.5; margin-bottom: 0.5rem; display: block;"></i>
                            <p>No customers found</p>
                            <?php if ($source == 'Job Card'): ?>
                            <p style="font-size: 0.75rem; margin-top: 0.25rem;">Create a job card to add customers automatically</p>
                            <a href="../job_cards/create.php" class="btn-primary" style="margin-top: 1rem; display: inline-flex;">
                                <i class="fas fa-clipboard-list"></i> Create Job Card
                            </a>
                            <?php else: ?>
                            <a href="create.php" class="btn-primary" style="margin-top: 1rem; display: inline-flex;">
                                <i class="fas fa-plus-circle"></i> Add Customer
                            </a>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php else: ?>
                    <?php foreach ($customers as $customer): ?>
                    <tr>
                        <td><input type="checkbox" class="row-check" value="<?php echo $customer['id']; ?>" onclick="updateBulkBar()" style="cursor:pointer;width:14px;height:14px;"></td>
                        <td>#<?php echo $customer['id']; ?></td>
                        <td>
                            <strong><?php echo htmlspecialchars($customer['full_name']); ?></strong>
                            <?php if ($customer['customer_source'] == 'Job Card' && $customer['source_job_number']): ?>
                            <div style="font-size: 0.65rem; color: var(--gray); margin-top: 0.2rem;">
                                <i class="fas fa-clipboard-list"></i> Job: <?php echo htmlspecialchars($customer['source_job_number']); ?>
                            </div>
                            <?php endif; ?>
                        </td>
                        <td>
                            <div><i class="fas fa-phone" style="width: 14px; font-size: 0.7rem;"></i> <?php echo htmlspecialchars($customer['telephone'] ?? 'N/A'); ?></div>
                            <div style="font-size: 0.7rem; color: var(--gray); margin-top: 0.2rem;">
                                <i class="fas fa-envelope"></i> <?php echo htmlspecialchars($customer['email'] ?? 'N/A'); ?>
                            </div>
                        </td>
                        <td>
                            <span class="badge <?php echo $customer['customer_source'] == 'Job Card' ? 'badge-jobcard' : 'badge-direct'; ?>">
                                <i class="fas fa-<?php echo $customer['customer_source'] == 'Job Card' ? 'clipboard-list' : 'user-plus'; ?>"></i>
                                <?php echo htmlspecialchars($customer['customer_source'] ?? 'Direct'); ?>
                            </span>
                        </td>
                        <td>
                            <span class="tier-badge tier-<?php echo $customer['customer_tier']; ?>">
                                <i class="fas fa-<?php echo $customer['customer_tier'] == 'platinum' ? 'crown' : ($customer['customer_tier'] == 'gold' ? 'medal' : 'star'); ?>"></i>
                                <?php echo ucfirst($customer['customer_tier']); ?>
                            </span>
                        </td>
                        <td>
                            <?php if (($customer['total_jobs'] ?? 0) > 0): ?>
                            <div><strong><?php echo $customer['total_jobs']; ?></strong> total</div>
                            <div style="font-size: 0.65rem;">
                                <span style="color: var(--warning);"><?php echo $customer['pending_jobs'] ?? 0; ?> pending</span>
                                <span style="color: var(--success); margin-left: 0.3rem;"><?php echo $customer['completed_jobs'] ?? 0; ?> done</span>
                            </div>
                            <?php else: ?>
                            <span style="color: var(--gray);">No jobs</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <strong>UGX <?php echo number_format(($customer['total_spent'] ?? 0) / 1000000, 1); ?>M</strong>
                        </td>
                        <td>
                            <strong><?php echo number_format($customer['loyalty_points'] ?? 0); ?></strong>
                        </td>
                        <td>
                            <?php if ($customer['avg_rating']): ?>
                            <div class="stars">
                                <?php for($i = 1; $i <= 5; $i++): ?>
                                    <i class="fas fa-star <?php echo $i <= round($customer['avg_rating']) ? 'star-filled' : 'star-empty'; ?>"></i>
                                <?php endfor; ?>
                            </div>
                            <div style="font-size: 0.65rem; color: var(--gray);"><?php echo number_format($customer['avg_rating'], 1); ?> / 5</div>
                            <?php else: ?>
                            <span style="color: var(--gray);">No ratings</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <span class="status-badge <?php echo $customer['status'] == 1 ? 'status-active' : 'status-inactive'; ?>">
                                <?php echo $customer['status'] == 1 ? 'Active' : 'Inactive'; ?>
                            </span>
                        </td>
                        <td>
                            <div class="action-btns">
                                <button onclick="open360Profile(<?php echo $customer['id']; ?>,'<?php echo addslashes(htmlspecialchars($customer['full_name'])); ?>')" class="action-btn" style="background:#fef3c7;color:#92400e;" title="360° Profile">
                                    <i class="fas fa-id-card-alt"></i> 360°
                                </button>
                                <button onclick="openViewModal(<?php echo $customer['id']; ?>)" class="action-btn action-btn-view" title="Quick View">
                                    <i class="fas fa-eye"></i>
                                </button>
                                <button onclick="openEditModal(<?php echo $customer['id']; ?>)" class="action-btn action-btn-edit" title="Edit Customer">
                                    <i class="fas fa-edit"></i>
                                </button>
                                <a href="../job_cards/create.php?customer_id=<?php echo $customer['id']; ?>" class="action-btn action-btn-job" title="Create Job">
                                    <i class="fas fa-clipboard-list"></i>
                                </a>
                                <button onclick="openReminderModal(<?php echo $customer['id']; ?>,'<?php echo addslashes(htmlspecialchars($customer['full_name'])); ?>','<?php echo addslashes($customer['telephone'] ?? ''); ?>','<?php echo addslashes($customer['email'] ?? ''); ?>')" class="action-btn action-btn-reminder" title="Send Reminder">
                                    <i class="fas fa-bell"></i>
                                </button>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>

            <?php if ($totalPages > 1): ?>
            <div class="pagination">
                <?php if ($page > 1): ?>
                <a href="?page=<?php echo $page - 1; ?>&search=<?php echo urlencode($search); ?>&tier=<?php echo urlencode($tier); ?>&source=<?php echo urlencode($source); ?>&status=<?php echo $status; ?>" class="page-link">
                    <i class="fas fa-chevron-left"></i> Prev
                </a>
                <?php endif; ?>
                
                <?php for($i = 1; $i <= min(5, $totalPages); $i++): ?>
                <a href="?page=<?php echo $i; ?>&search=<?php echo urlencode($search); ?>&tier=<?php echo urlencode($tier); ?>&source=<?php echo urlencode($source); ?>&status=<?php echo $status; ?>" 
                   class="page-link <?php echo $page == $i ? 'active' : ''; ?>">
                    <?php echo $i; ?>
                </a>
                <?php endfor; ?>
                
                <?php if ($totalPages > 5): ?>
                <span class="page-link">...</span>
                <a href="?page=<?php echo $totalPages; ?>&search=<?php echo urlencode($search); ?>&tier=<?php echo urlencode($tier); ?>&source=<?php echo urlencode($source); ?>&status=<?php echo $status; ?>" class="page-link">
                    <?php echo $totalPages; ?>
                </a>
                <?php endif; ?>
                
                <?php if ($page < $totalPages): ?>
                <a href="?page=<?php echo $page + 1; ?>&search=<?php echo urlencode($search); ?>&tier=<?php echo urlencode($tier); ?>&source=<?php echo urlencode($source); ?>&status=<?php echo $status; ?>" class="page-link">
                    Next <i class="fas fa-chevron-right"></i>
                </a>
                <?php endif; ?>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- SERVICE REMINDER MODAL -->
    <div class="reminder-modal-overlay" id="reminderOverlay" onclick="if(event.target===this) closeReminderModal()">
        <div class="reminder-modal" id="reminderModal">
            <div class="rm-header">
                <div class="rm-header-icon"><i class="fas fa-bell"></i></div>
                <div>
                    <h3 id="rmTitle">Send Service Reminder</h3>
                    <p id="rmSubtitle">Notify customer via WhatsApp, Email or SMS</p>
                </div>
                <button class="rm-close" onclick="closeReminderModal()"><i class="fas fa-times"></i></button>
            </div>
            <div class="rm-body">
                <input type="hidden" id="rmCustomerId">
                <input type="hidden" id="rmIsBulk" value="0">

                <div id="rmCustomerStrip" style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:10px;padding:10px 14px;margin-bottom:16px;display:none;">
                    <div style="font-size:11px;color:#64748b;margin-bottom:2px;">SENDING TO</div>
                    <div id="rmCustomerInfo" style="font-size:13px;font-weight:600;color:#0f172a;"></div>
                </div>

                <div id="rmBulkStrip" style="background:#eff6ff;border:1px solid #bfdbfe;border-radius:10px;padding:10px 14px;margin-bottom:16px;display:none;">
                    <div style="font-size:11px;color:#1d4ed8;margin-bottom:2px;">BULK SEND TO</div>
                    <div id="rmBulkInfo" style="font-size:13px;font-weight:700;color:#1e40af;"></div>
                </div>

                <div class="rm-field">
                    <label><i class="fas fa-tag"></i> Reminder Type</label>
                    <select id="rmType" onchange="loadTemplate()">
                        <option value="service_expiry">🔧 Service Expiry Reminder</option>
                        <option value="seasonal">🎉 Seasonal Greeting</option>
                        <option value="custom">✉️ Custom Message</option>
                    </select>
                </div>

                <div class="rm-field">
                    <label><i class="fas fa-share-alt"></i> Send Via</label>
                    <div class="channel-pills">
                        <label class="channel-pill whatsapp">
                            <input type="checkbox" name="channels" value="whatsapp" id="chWhatsapp" checked>
                            <i class="fab fa-whatsapp" style="color:#25D366;font-size:15px;"></i> WhatsApp
                        </label>
                        <label class="channel-pill email">
                            <input type="checkbox" name="channels" value="email" id="chEmail">
                            <i class="fas fa-envelope" style="color:#2563eb;font-size:13px;"></i> Email
                        </label>
                        <label class="channel-pill sms">
                            <input type="checkbox" name="channels" value="sms" id="chSms">
                            <i class="fas fa-sms" style="color:#7c3aed;font-size:13px;"></i> SMS
                        </label>
                    </div>
                </div>

                <div class="rm-field">
                    <label><i class="fas fa-heading"></i> Subject / Title</label>
                    <input type="text" id="rmSubject" placeholder="e.g. Your service is due soon!">
                </div>

                <div class="rm-field">
                    <label><i class="fas fa-magic"></i> Quick Templates</label>
                    <div class="template-chips">
                        <span class="tpl-chip" onclick="applyTemplate('service_due')">Service Due</span>
                        <span class="tpl-chip" onclick="applyTemplate('oil_change')">Oil Change</span>
                        <span class="tpl-chip" onclick="applyTemplate('christmas')">Christmas</span>
                        <span class="tpl-chip" onclick="applyTemplate('new_year')">New Year</span>
                        <span class="tpl-chip" onclick="applyTemplate('eid')">Eid Greetings</span>
                        <span class="tpl-chip" onclick="applyTemplate('promo')">Promotion</span>
                    </div>
                </div>

                <div class="rm-field">
                    <label><i class="fas fa-comment-alt"></i> Message <span style="font-weight:400;color:#94a3b8;">(use {name} for customer name)</span></label>
                    <textarea id="rmMessage" placeholder="Type your message here…"></textarea>
                    <div style="text-align:right;font-size:11px;color:#94a3b8;margin-top:4px;" id="rmCharCount">0 characters</div>
                </div>

                <div class="rm-field">
                    <label><i class="fas fa-clock"></i> Schedule for Later <span style="font-weight:400;color:#94a3b8;">(leave blank to send now)</span></label>
                    <input type="datetime-local" id="rmScheduleAt">
                </div>
            </div>
            <div class="rm-footer">
                <button class="btn-rm-cancel" onclick="closeReminderModal()">Cancel</button>
                <button class="btn-rm-schedule" onclick="submitReminder('schedule')">
                    <i class="fas fa-clock"></i> Schedule
                </button>
                <button class="btn-rm-send" id="btnSend" onclick="submitReminder('send')">
                    <i class="fas fa-paper-plane"></i> Send Now
                </button>
            </div>
        </div>
    </div>

    <!-- VIEW CUSTOMER MODAL -->
    <div id="viewModalOverlay" onclick="if(event.target===this)closeViewModal()" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);backdrop-filter:blur(4px);z-index:3000;align-items:center;justify-content:center;">
        <div style="background:white;border-radius:1.25rem;width:90%;max-width:680px;max-height:88vh;overflow-y:auto;box-shadow:0 25px 60px rgba(0,0,0,.2);">
            <div id="viewModalHeader" style="background:linear-gradient(135deg,#2563eb,#7c3aed);color:white;padding:1.25rem 1.5rem;border-radius:1.25rem 1.25rem 0 0;display:flex;justify-content:space-between;align-items:center;">
                <div>
                    <div style="font-size:1.1rem;font-weight:800;" id="viewModalName">Loading…</div>
                    <div style="font-size:.75rem;opacity:.85;margin-top:.15rem;" id="viewModalSub"></div>
                </div>
                <button onclick="closeViewModal()" style="background:rgba(255,255,255,.2);border:none;color:white;width:32px;height:32px;border-radius:50%;font-size:1.1rem;cursor:pointer;display:flex;align-items:center;justify-content:center;">&times;</button>
            </div>
            <div id="viewModalBody" style="padding:1.5rem;">
                <div style="text-align:center;padding:2rem;color:#94a3b8;"><i class="fas fa-spinner fa-spin" style="font-size:1.5rem;"></i><p style="margin-top:.5rem;">Loading customer…</p></div>
            </div>
            <div style="padding:1rem 1.5rem;border-top:1px solid #e2e8f0;display:flex;gap:.75rem;justify-content:flex-end;">
                <button onclick="closeViewModal()" style="padding:.55rem 1.1rem;border-radius:.5rem;border:1.5px solid #e2e8f0;background:white;color:#475569;font-weight:600;font-size:.85rem;cursor:pointer;">Close</button>
                <button id="viewToEditBtn" onclick="" style="padding:.55rem 1.1rem;border-radius:.5rem;border:none;background:linear-gradient(135deg,#2563eb,#7c3aed);color:white;font-weight:600;font-size:.85rem;cursor:pointer;display:flex;align-items:center;gap:.4rem;"><i class="fas fa-edit"></i> Edit Customer</button>
            </div>
        </div>
    </div>

    <!-- EDIT CUSTOMER MODAL -->
    <div id="editModalOverlay" onclick="if(event.target===this)closeEditModal()" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);backdrop-filter:blur(4px);z-index:3100;align-items:center;justify-content:center;">
        <div style="background:white;border-radius:1.25rem;width:90%;max-width:620px;max-height:90vh;overflow-y:auto;box-shadow:0 25px 60px rgba(0,0,0,.2);">
            <div style="background:linear-gradient(135deg,#059669,#0284c7);color:white;padding:1.25rem 1.5rem;border-radius:1.25rem 1.25rem 0 0;display:flex;justify-content:space-between;align-items:center;">
                <div>
                    <div style="font-size:1rem;font-weight:800;">✏️ Edit Customer</div>
                    <div style="font-size:.75rem;opacity:.85;margin-top:.1rem;" id="editModalSub">Update customer details</div>
                </div>
                <button onclick="closeEditModal()" style="background:rgba(255,255,255,.2);border:none;color:white;width:32px;height:32px;border-radius:50%;font-size:1.1rem;cursor:pointer;display:flex;align-items:center;justify-content:center;">&times;</button>
            </div>
            <form id="editForm" onsubmit="submitEdit(event)" style="padding:1.5rem;">
                <input type="hidden" id="editCustomerId" name="customer_id">
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:1rem;margin-bottom:1rem;">
                    <div>
                        <label style="display:block;font-size:.68rem;font-weight:700;color:#64748b;text-transform:uppercase;margin-bottom:.3rem;">Full Name *</label>
                        <input type="text" name="full_name" id="editFullName" required style="width:100%;padding:.55rem .8rem;border:1.5px solid #e2e8f0;border-radius:.6rem;font-size:.85rem;font-family:inherit;">
                    </div>
                    <div>
                        <label style="display:block;font-size:.68rem;font-weight:700;color:#64748b;text-transform:uppercase;margin-bottom:.3rem;">Phone</label>
                        <input type="text" name="telephone" id="editTelephone" style="width:100%;padding:.55rem .8rem;border:1.5px solid #e2e8f0;border-radius:.6rem;font-size:.85rem;font-family:inherit;">
                    </div>
                    <div>
                        <label style="display:block;font-size:.68rem;font-weight:700;color:#64748b;text-transform:uppercase;margin-bottom:.3rem;">Email</label>
                        <input type="email" name="email" id="editEmail" style="width:100%;padding:.55rem .8rem;border:1.5px solid #e2e8f0;border-radius:.6rem;font-size:.85rem;font-family:inherit;">
                    </div>
                    <div>
                        <label style="display:block;font-size:.68rem;font-weight:700;color:#64748b;text-transform:uppercase;margin-bottom:.3rem;">Status</label>
                        <select name="status" id="editStatus" style="width:100%;padding:.55rem .8rem;border:1.5px solid #e2e8f0;border-radius:.6rem;font-size:.85rem;font-family:inherit;">
                            <option value="1">Active</option>
                            <option value="0">Inactive</option>
                        </select>
                    </div>
                    <div>
                        <label style="display:block;font-size:.68rem;font-weight:700;color:#64748b;text-transform:uppercase;margin-bottom:.3rem;">Tier</label>
                        <select name="customer_tier" id="editTier" style="width:100%;padding:.55rem .8rem;border:1.5px solid #e2e8f0;border-radius:.6rem;font-size:.85rem;font-family:inherit;">
                            <option value="">None</option>
                            <option value="bronze">Bronze</option>
                            <option value="silver">Silver</option>
                            <option value="gold">Gold</option>
                            <option value="platinum">Platinum</option>
                        </select>
                    </div>
                    <div>
                        <label style="display:block;font-size:.68rem;font-weight:700;color:#64748b;text-transform:uppercase;margin-bottom:.3rem;">Source</label>
                        <input type="text" name="customer_source" id="editSource" style="width:100%;padding:.55rem .8rem;border:1.5px solid #e2e8f0;border-radius:.6rem;font-size:.85rem;font-family:inherit;">
                    </div>
                </div>
                <div style="margin-bottom:1rem;">
                    <label style="display:block;font-size:.68rem;font-weight:700;color:#64748b;text-transform:uppercase;margin-bottom:.3rem;">Address</label>
                    <input type="text" name="address" id="editAddress" style="width:100%;padding:.55rem .8rem;border:1.5px solid #e2e8f0;border-radius:.6rem;font-size:.85rem;font-family:inherit;">
                </div>
                <div style="margin-bottom:1.25rem;">
                    <label style="display:block;font-size:.68rem;font-weight:700;color:#64748b;text-transform:uppercase;margin-bottom:.3rem;">Notes</label>
                    <textarea name="notes" id="editNotes" rows="3" style="width:100%;padding:.55rem .8rem;border:1.5px solid #e2e8f0;border-radius:.6rem;font-size:.85rem;font-family:inherit;resize:vertical;"></textarea>
                </div>
                <div style="display:flex;gap:.75rem;justify-content:flex-end;padding-top:1rem;border-top:1px solid #e2e8f0;">
                    <button type="button" onclick="closeEditModal()" style="padding:.6rem 1.2rem;border-radius:.6rem;border:1.5px solid #e2e8f0;background:white;color:#475569;font-weight:600;font-size:.85rem;cursor:pointer;">Cancel</button>
                    <button type="submit" id="editSaveBtn" style="padding:.6rem 1.2rem;border-radius:.6rem;border:none;background:linear-gradient(135deg,#059669,#0284c7);color:white;font-weight:700;font-size:.85rem;cursor:pointer;display:flex;align-items:center;gap:.4rem;">
                        <i class="fas fa-save"></i> Save Changes
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- 360° CUSTOMER PROFILE MODAL -->
    <div id="modal360Overlay" onclick="if(event.target===this)close360()" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.6);backdrop-filter:blur(5px);z-index:4000;align-items:flex-start;justify-content:center;overflow-y:auto;padding:20px;">
      <div style="background:white;border-radius:1.5rem;width:98%;max-width:960px;margin:auto;box-shadow:0 30px 80px rgba(0,0,0,.3);overflow:hidden;">
        <div id="m360Header" style="background:linear-gradient(135deg,#1e40af,#7c3aed,#0891b2);color:white;padding:1.5rem 2rem;display:flex;align-items:center;gap:1rem;">
          <div style="width:54px;height:54px;border-radius:50%;background:rgba(255,255,255,.2);display:flex;align-items:center;justify-content:center;font-size:1.4rem;font-weight:800;flex-shrink:0;" id="m360Avatar">?</div>
          <div style="flex:1;">
            <div style="font-size:1.25rem;font-weight:800;" id="m360Name">Loading…</div>
            <div style="font-size:.8rem;opacity:.8;margin-top:.2rem;" id="m360Sub"></div>
          </div>
          <div style="display:flex;gap:.5rem;flex-wrap:wrap;">
            <button onclick="open360Tab('profile')" class="m360-tab-btn active" id="tab-profile">👤 Profile</button>
            <button onclick="open360Tab('vehicles')" class="m360-tab-btn" id="tab-vehicles">🚗 Vehicles</button>
            <button onclick="open360Tab('history')" class="m360-tab-btn" id="tab-history">📋 History</button>
            <button onclick="open360Tab('kpi')" class="m360-tab-btn" id="tab-kpi">📊 CLV / KPIs</button>
            <button onclick="open360Tab('reviews')" class="m360-tab-btn" id="tab-reviews">⭐ Reviews</button>
            <button onclick="open360Tab('schedule')" class="m360-tab-btn" id="tab-schedule">📅 Schedule</button>
            <button onclick="open360Tab('deferred')" class="m360-tab-btn" id="tab-deferred">⏳ Deferred</button>
          </div>
          <button onclick="close360()" style="background:rgba(255,255,255,.2);border:none;color:white;width:36px;height:36px;border-radius:50%;font-size:1.2rem;cursor:pointer;display:flex;align-items:center;justify-content:center;flex-shrink:0;">&times;</button>
        </div>

        <div id="m360Loading" style="text-align:center;padding:3rem;color:#94a3b8;"><i class="fas fa-spinner fa-spin" style="font-size:2rem;"></i><p style="margin-top:.75rem;">Loading 360° profile…</p></div>

        <div id="m360Body" style="display:none;">
          <div id="m360-profile" class="m360-panel" style="padding:1.5rem 2rem;">
            <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:1rem;margin-bottom:1.5rem;">
              <div class="p360-field"><div class="p360-lbl">📞 Phone</div><div class="p360-val" id="p360-phone"></div></div>
              <div class="p360-field"><div class="p360-lbl">✉️ Email</div><div class="p360-val" id="p360-email"></div></div>
              <div class="p360-field"><div class="p360-lbl">📍 Address</div><div class="p360-val" id="p360-address"></div></div>
              <div class="p360-field"><div class="p360-lbl">🏷️ Tier</div><div class="p360-val" id="p360-tier"></div></div>
              <div class="p360-field"><div class="p360-lbl">📥 Source</div><div class="p360-val" id="p360-source"></div></div>
              <div class="p360-field"><div class="p360-lbl">📅 Member Since</div><div class="p360-val" id="p360-since"></div></div>
              <div class="p360-field"><div class="p360-lbl">✅ Status</div><div class="p360-val" id="p360-status"></div></div>
            </div>
            <div id="p360-notes-wrap" style="background:#f8fafc;border-radius:.75rem;padding:1rem;font-size:.85rem;color:#475569;display:none;">
              <div style="font-size:.65rem;font-weight:700;text-transform:uppercase;color:#94a3b8;margin-bottom:.4rem;">Notes</div>
              <div id="p360-notes"></div>
            </div>
          </div>

          <div id="m360-vehicles" class="m360-panel" style="padding:1.5rem 2rem;display:none;">
            <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:1rem;">
              <div style="font-size:.9rem;font-weight:700;color:#0f172a;">Linked Vehicles</div>
            </div>
            <div id="p360-vehicles-list"></div>
            <div style="margin-top:1.5rem;">
              <div style="font-size:.85rem;font-weight:700;color:#0f172a;margin-bottom:.75rem;display:flex;align-items:center;gap:.5rem;"><i class="fas fa-tachometer-alt" style="color:#7c3aed;"></i> Mileage Tracking</div>
              <div id="p360-mileage-list" style="max-height:220px;overflow-y:auto;"></div>
              <details style="margin-top:1rem;border:1px solid #e2e8f0;border-radius:.75rem;overflow:hidden;">
                <summary style="padding:.65rem 1rem;font-size:.8rem;font-weight:700;cursor:pointer;background:#f8fafc;color:#374151;">➕ Log New Mileage Reading</summary>
                <div style="padding:1rem;display:grid;grid-template-columns:1fr 1fr 1fr;gap:.75rem;">
                  <div><label class="fm-lbl">Vehicle Reg</label><input id="mil-reg" type="text" class="fm-inp" placeholder="e.g. UAA 123B"></div>
                  <div><label class="fm-lbl">Mileage (km)</label><input id="mil-km" type="number" class="fm-inp" placeholder="e.g. 45000"></div>
                  <div><label class="fm-lbl">Date</label><input id="mil-date" type="date" class="fm-inp" value="<?php echo date('Y-m-d'); ?>"></div>
                  <div style="grid-column:1/-1;"><label class="fm-lbl">Notes</label><input id="mil-notes" type="text" class="fm-inp" placeholder="Optional notes"></div>
                  <div style="grid-column:1/-1;text-align:right;"><button onclick="submitMileage()" class="btn360-save"><i class="fas fa-save"></i> Save Mileage</button></div>
                </div>
              </details>
            </div>
          </div>

          <div id="m360-history" class="m360-panel" style="padding:1.5rem 2rem;display:none;">
            <div style="font-size:.85rem;font-weight:700;color:#0f172a;margin-bottom:1rem;"><i class="fas fa-history" style="color:#2563eb;"></i> Complete Service History</div>
            <div id="p360-history-list" style="max-height:480px;overflow-y:auto;"></div>
            <details style="margin-top:1rem;border:1px solid #e2e8f0;border-radius:.75rem;overflow:hidden;">
              <summary style="padding:.65rem 1rem;font-size:.8rem;font-weight:700;cursor:pointer;background:#f8fafc;color:#374151;">🔍 Digital Vehicle Inspection Checklist</summary>
              <div style="padding:1rem;">
                <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(200px,1fr));gap:.5rem;margin-bottom:1rem;" id="inspectionChecklist">
                  <?php $inspItems = ['Engine Oil','Brake Fluid','Coolant','Tyres (Front)','Tyres (Rear)','Brakes','Battery','Lights','Windscreen','Air Filter','Wipers','Suspension','Exhaust','AC System','Gearbox Oil'];
                  foreach ($inspItems as $it): ?>
                  <label style="display:flex;align-items:center;gap:.5rem;font-size:.82rem;background:#f8fafc;padding:.4rem .75rem;border-radius:.5rem;border:1px solid #e2e8f0;cursor:pointer;">
                    <select name="insp_<?php echo strtolower(str_replace([' ','(',')'],['-','',''], $it)); ?>" style="border:none;background:transparent;font-size:.78rem;color:#374151;">
                      <option value="ok">✅ OK</option>
                      <option value="attention">⚠️ Needs Attention</option>
                      <option value="critical">❌ Critical</option>
                      <option value="na">— N/A</option>
                    </select>
                    <?php echo $it; ?>
                  </label>
                  <?php endforeach; ?>
                </div>
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:.75rem;margin-bottom:.75rem;">
                  <div><label class="fm-lbl">Vehicle Reg</label><input id="insp-reg" type="text" class="fm-inp" placeholder="e.g. UAA 123B"></div>
                  <div><label class="fm-lbl">Inspection Date</label><input id="insp-date" type="date" class="fm-inp" value="<?php echo date('Y-m-d'); ?>"></div>
                  <div style="grid-column:1/-1;"><label class="fm-lbl">Inspector Notes</label><textarea id="insp-notes" class="fm-inp" rows="2" placeholder="Overall findings…" style="resize:vertical;"></textarea></div>
                </div>
                <div style="text-align:right;"><button onclick="submitInspection()" class="btn360-save"><i class="fas fa-clipboard-check"></i> Save Inspection</button></div>
              </div>
            </details>
          </div>

          <div id="m360-kpi" class="m360-panel" style="padding:1.5rem 2rem;display:none;">
            <div style="font-size:.85rem;font-weight:700;color:#0f172a;margin-bottom:1rem;"><i class="fas fa-chart-bar" style="color:#10b981;"></i> Customer Lifetime Value & KPIs</div>
            <div id="p360-kpi-grid" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:1rem;margin-bottom:1.5rem;"></div>
            <div style="background:#f8fafc;border-radius:1rem;padding:1.25rem;border:1px solid #e2e8f0;">
              <div style="font-size:.75rem;font-weight:700;text-transform:uppercase;color:#64748b;margin-bottom:.75rem;">CLV Projection</div>
              <div id="p360-clv-projection"></div>
            </div>
          </div>

          <div id="m360-reviews" class="m360-panel" style="padding:1.5rem 2rem;display:none;">
            <div style="font-size:.85rem;font-weight:700;color:#0f172a;margin-bottom:1rem;"><i class="fas fa-star" style="color:#f59e0b;"></i> Reviews & Ratings</div>
            <div id="p360-reviews-list" style="max-height:320px;overflow-y:auto;margin-bottom:1rem;"></div>
            <details style="border:1px solid #e2e8f0;border-radius:.75rem;overflow:hidden;">
              <summary style="padding:.65rem 1rem;font-size:.8rem;font-weight:700;cursor:pointer;background:#f8fafc;color:#374151;">➕ Add / Record Review</summary>
              <div style="padding:1rem;display:grid;grid-template-columns:1fr 1fr;gap:.75rem;">
                <div><label class="fm-lbl">Rating</label>
                  <select id="rev-rating" class="fm-inp">
                    <option value="5">⭐⭐⭐⭐⭐ Excellent (5)</option>
                    <option value="4">⭐⭐⭐⭐ Good (4)</option>
                    <option value="3">⭐⭐⭐ Average (3)</option>
                    <option value="2">⭐⭐ Poor (2)</option>
                    <option value="1">⭐ Terrible (1)</option>
                  </select>
                </div>
                <div><label class="fm-lbl">Job Card (optional)</label><input id="rev-job" type="text" class="fm-inp" placeholder="Job card ID"></div>
                <div style="grid-column:1/-1;"><label class="fm-lbl">Review / Feedback</label><textarea id="rev-text" class="fm-inp" rows="3" style="resize:vertical;" placeholder="Customer's feedback…"></textarea></div>
                <div style="grid-column:1/-1;text-align:right;"><button onclick="submitReview()" class="btn360-save"><i class="fas fa-star"></i> Save Review</button></div>
              </div>
            </details>
          </div>

          <div id="m360-schedule" class="m360-panel" style="padding:1.5rem 2rem;display:none;">
            <div style="font-size:.85rem;font-weight:700;color:#0f172a;margin-bottom:1rem;"><i class="fas fa-calendar-alt" style="color:#2563eb;"></i> Upcoming Appointments</div>
            <div id="p360-appt-list" style="max-height:240px;overflow-y:auto;margin-bottom:1rem;"></div>
            <div style="background:#eff6ff;border-radius:.75rem;padding:1.25rem;border:1px solid #bfdbfe;">
              <div style="font-size:.8rem;font-weight:700;color:#1e40af;margin-bottom:.75rem;"><i class="fas fa-plus-circle"></i> Schedule New Appointment</div>
              <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:.75rem;margin-bottom:.75rem;">
                <div><label class="fm-lbl">Vehicle Reg</label><input id="appt-reg" type="text" class="fm-inp" placeholder="e.g. UAA 123B"></div>
                <div><label class="fm-lbl">Date</label><input id="appt-date" type="date" class="fm-inp"></div>
                <div><label class="fm-lbl">Time</label><input id="appt-time" type="time" class="fm-inp" value="09:00"></div>
                <div style="grid-column:1/-1;"><label class="fm-lbl">Service Type</label><input id="appt-service" type="text" class="fm-inp" placeholder="e.g. Engine Service, Oil Change, Full Inspection…"></div>
                <div style="grid-column:1/-1;"><label class="fm-lbl">Notes for Technician</label><textarea id="appt-notes" class="fm-inp" rows="2" style="resize:vertical;"></textarea></div>
              </div>
              <div style="text-align:right;"><button onclick="submitAppointment()" class="btn360-save"><i class="fas fa-calendar-check"></i> Book Appointment</button></div>
            </div>
          </div>

          <div id="m360-deferred" class="m360-panel" style="padding:1.5rem 2rem;display:none;">
            <div style="font-size:.85rem;font-weight:700;color:#0f172a;margin-bottom:1rem;"><i class="fas fa-clock" style="color:#f59e0b;"></i> Deferred Service Follow-ups</div>
            <div id="p360-deferred-list" style="max-height:240px;overflow-y:auto;margin-bottom:1rem;"></div>
            <div style="background:#fffbeb;border-radius:.75rem;padding:1.25rem;border:1px solid #fde68a;">
              <div style="font-size:.8rem;font-weight:700;color:#92400e;margin-bottom:.75rem;"><i class="fas fa-plus-circle"></i> Log Deferred Service</div>
              <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:.75rem;margin-bottom:.75rem;">
                <div><label class="fm-lbl">Vehicle Reg</label><input id="def-reg" type="text" class="fm-inp" placeholder="e.g. UAA 123B"></div>
                <div><label class="fm-lbl">Deferred Date</label><input id="def-date" type="date" class="fm-inp" value="<?php echo date('Y-m-d'); ?>"></div>
                <div><label class="fm-lbl">Follow-up Date</label><input id="def-followup" type="date" class="fm-inp"></div>
                <div style="grid-column:1/-1;"><label class="fm-lbl">Service Description</label><input id="def-desc" type="text" class="fm-inp" placeholder="e.g. Brake pad replacement declined due to budget…"></div>
                <div style="grid-column:1/-1;"><label class="fm-lbl">Reason</label><textarea id="def-reason" class="fm-inp" rows="2" style="resize:vertical;" placeholder="Why was service deferred?"></textarea></div>
              </div>
              <div style="text-align:right;"><button onclick="submitDeferred()" class="btn360-save"><i class="fas fa-save"></i> Log Deferred Service</button></div>
            </div>
          </div>
        </div>
      </div>
    </div>

    <style>
    .m360-tab-btn { padding:6px 14px;border-radius:20px;border:1.5px solid rgba(255,255,255,.35);background:rgba(255,255,255,.12);color:white;font-size:.72rem;font-weight:700;cursor:pointer;transition:all .15s;white-space:nowrap; }
    .m360-tab-btn.active,.m360-tab-btn:hover { background:white;color:#1e40af;border-color:white; }
    .p360-field { background:#f8fafc;border-radius:.75rem;padding:.75rem 1rem;border:1px solid #f1f5f9; }
    .p360-lbl { font-size:.6rem;font-weight:700;text-transform:uppercase;color:#94a3b8;margin-bottom:.25rem;letter-spacing:.5px; }
    .p360-val { font-size:.88rem;font-weight:600;color:#0f172a; }
    .fm-lbl { display:block;font-size:.65rem;font-weight:700;text-transform:uppercase;color:#64748b;margin-bottom:.3rem;letter-spacing:.5px; }
    .fm-inp { width:100%;padding:.5rem .75rem;border:1.5px solid #e2e8f0;border-radius:.5rem;font-size:.82rem;font-family:inherit;transition:border-color .15s; }
    .fm-inp:focus { outline:none;border-color:#2563eb;box-shadow:0 0 0 3px rgba(37,99,235,.08); }
    .btn360-save { padding:.55rem 1.2rem;border-radius:40px;border:none;background:linear-gradient(135deg,#2563eb,#7c3aed);color:white;font-size:.8rem;font-weight:700;cursor:pointer;display:inline-flex;align-items:center;gap:.4rem; }
    .btn360-save:hover { transform:translateY(-1px);box-shadow:0 4px 12px rgba(37,99,235,.3); }
    .kpi-card { background:white;border-radius:.75rem;padding:1rem;border:1px solid #e2e8f0;text-align:center; }
    .kpi-card .kv { font-size:1.4rem;font-weight:800;color:#0f172a; }
    .kpi-card .kl { font-size:.65rem;font-weight:700;text-transform:uppercase;color:#64748b;margin-top:.25rem;letter-spacing:.5px; }
    .hist-row { border:1px solid #e2e8f0;border-radius:.75rem;padding:.75rem 1rem;margin-bottom:.5rem;display:grid;grid-template-columns:auto 1fr auto;gap:.75rem;align-items:center; }
    .hist-row:hover { background:#f8fafc; }
    .rev-row { border:1px solid #e2e8f0;border-radius:.75rem;padding:.75rem 1rem;margin-bottom:.5rem; }
    .appt-row { border:1px solid #bfdbfe;border-radius:.75rem;padding:.75rem 1rem;margin-bottom:.5rem;background:#eff6ff; }
    .def-row { border:1px solid #fde68a;border-radius:.75rem;padding:.75rem 1rem;margin-bottom:.5rem;background:#fffbeb; }
    .veh-card { border:1px solid #e2e8f0;border-radius:.75rem;padding:1rem;display:flex;align-items:center;gap:1rem;margin-bottom:.5rem; }
    .veh-card:hover { background:#f8fafc; }
    .mil-row { display:flex;justify-content:space-between;align-items:center;padding:.5rem .75rem;border-bottom:1px solid #f1f5f9;font-size:.82rem; }
    </style>

    <script>
    let _360customerId = null;
    let _360data = null;

    async function open360Profile(customerId, name) {
        _360customerId = customerId;
        _360data = null;
        const overlay = document.getElementById('modal360Overlay');
        overlay.style.display = 'flex';
        document.getElementById('m360Name').textContent = name || 'Loading…';
        document.getElementById('m360Sub').textContent = '';
        document.getElementById('m360Avatar').textContent = (name||'?').charAt(0).toUpperCase();
        document.getElementById('m360Loading').style.display = 'block';
        document.getElementById('m360Body').style.display = 'none';
        open360Tab('profile');

        try {
            const resp = await fetch(`index.php?ajax_action=profile_360&customer_id=${customerId}`);
            const data = await resp.json();
            if (!data.success) throw new Error(data.error||'Failed to load profile');
            _360data = data.customer;
            render360(data.customer);
        } catch(e) {
            document.getElementById('m360Loading').innerHTML = `<div style="color:#ef4444;text-align:center;padding:2rem;"><i class="fas fa-exclamation-circle" style="font-size:2rem;"></i><p style="margin-top:.5rem;">${e.message}</p></div>`;
        }
    }

    function close360() {
        document.getElementById('modal360Overlay').style.display = 'none';
        _360customerId = null; _360data = null;
    }

    function open360Tab(tab) {
        document.querySelectorAll('.m360-tab-btn').forEach(b => b.classList.remove('active'));
        document.querySelectorAll('.m360-panel').forEach(p => p.style.display = 'none');
        const btn = document.getElementById('tab-' + tab);
        if (btn) btn.classList.add('active');
        const panel = document.getElementById('m360-' + tab);
        if (panel) panel.style.display = 'block';
    }

    function fmt(n) {
        const num = parseFloat(n)||0;
        if (num >= 1000000) return 'UGX ' + (num/1000000).toFixed(1) + 'M';
        if (num >= 1000) return 'UGX ' + (num/1000).toFixed(0) + 'K';
        return 'UGX ' + num.toLocaleString();
    }
    function fmtDate(d) {
        if (!d) return '—';
        return new Date(d).toLocaleDateString('en-GB',{day:'2-digit',month:'short',year:'numeric'});
    }
    function stars(r) {
        let s = '';
        for (let i=1;i<=5;i++) s += `<i class="fas fa-star" style="color:${i<=Math.round(r)?'#f59e0b':'#e2e8f0'};font-size:.75rem;"></i>`;
        return s;
    }
    const tierColors = { platinum:'#7c3aed', gold:'#d97706', silver:'#64748b', bronze:'#b45309' };

    function render360(c) {
        document.getElementById('m360Loading').style.display = 'none';
        document.getElementById('m360Body').style.display = 'block';
        document.getElementById('m360Name').textContent = c.full_name || 'Unknown';
        const tier = (c.customer_tier||'').toLowerCase();
        document.getElementById('m360Sub').textContent = (c.telephone||'') + (c.email?' · '+c.email:'') + (tier?' · '+tier.charAt(0).toUpperCase()+tier.slice(1):'');
        document.getElementById('m360Avatar').textContent = (c.full_name||'?').charAt(0).toUpperCase();

        document.getElementById('p360-phone').textContent  = c.telephone || '—';
        document.getElementById('p360-email').textContent  = c.email || '—';
        document.getElementById('p360-address').textContent = c.address || '—';
        document.getElementById('p360-source').textContent = c.customer_source || '—';
        document.getElementById('p360-since').textContent  = fmtDate(c.created_at);
        document.getElementById('p360-tier').innerHTML = tier
            ? `<span style="background:${tierColors[tier]||'#64748b'}22;color:${tierColors[tier]||'#64748b'};padding:.2rem .6rem;border-radius:2rem;font-size:.75rem;font-weight:700;">${tier.charAt(0).toUpperCase()+tier.slice(1)}</span>` : '—';
        document.getElementById('p360-status').innerHTML = c.status==1
            ? '<span style="color:#059669;font-weight:700;">● Active</span>'
            : '<span style="color:#dc2626;font-weight:700;">● Inactive</span>';
        if (c.notes) {
            document.getElementById('p360-notes').textContent = c.notes;
            document.getElementById('p360-notes-wrap').style.display = 'block';
        }

        const vl = document.getElementById('p360-vehicles-list');
        vl.innerHTML = (c.vehicles||[]).length
            ? c.vehicles.map(v => `
                <div class="veh-card">
                  <div style="width:44px;height:44px;border-radius:.75rem;background:#dbeafe;display:flex;align-items:center;justify-content:center;font-size:1.3rem;">🚗</div>
                  <div style="flex:1;">
                    <div style="font-weight:800;font-size:.95rem;color:#0f172a;">${v.vehicle_reg||v.reg||'—'}</div>
                    <div style="font-size:.8rem;color:#64748b;">${v.vehicle_model||v.model||''} ${v.chassis_no?'· Chassis: '+v.chassis_no:''}</div>
                    ${v.is_primary==1?'<span style="background:#dbeafe;color:#1e40af;font-size:.65rem;font-weight:700;padding:.1rem .45rem;border-radius:20px;">Primary</span>':''}
                  </div>
                  <button onclick="document.getElementById('mil-reg').value='${(v.vehicle_reg||v.reg||'').replace(/'/g,'')}';" style="font-size:.7rem;background:#f0fdf4;color:#059669;border:1px solid #bbf7d0;border-radius:6px;padding:.3rem .6rem;cursor:pointer;">Log Mileage</button>
                </div>`).join('')
            : '<div style="text-align:center;padding:1.5rem;color:#94a3b8;font-size:.85rem;">No vehicles linked. Vehicles are detected automatically from job cards.</div>';

        const ml = document.getElementById('p360-mileage-list');
        ml.innerHTML = (c.mileage||[]).length
            ? '<div style="border:1px solid #e2e8f0;border-radius:.75rem;overflow:hidden;">'
              + c.mileage.map((m,i) => `<div class="mil-row" style="${i%2?'background:#f8fafc':''}"><span style="font-weight:700;">${m.vehicle_reg||''}</span><span style="color:#7c3aed;font-weight:800;">${parseInt(m.mileage||0).toLocaleString()} km</span><span style="color:#64748b;font-size:.75rem;">${fmtDate(m.recorded_at)}</span></div>`).join('')
              + '</div>'
            : '<p style="text-align:center;color:#94a3b8;font-size:.82rem;padding:1rem;">No mileage records yet.</p>';

        const hl = document.getElementById('p360-history-list');
        hl.innerHTML = (c.jobs||[]).length
            ? c.jobs.map(j => {
                const statusColor = j.status==='completed'?'#059669':j.status==='in_progress'?'#2563eb':'#f59e0b';
                const statusBg = j.status==='completed'?'#dcfce7':j.status==='in_progress'?'#dbeafe':'#fef9c3';
                return `<div class="hist-row">
                  <div style="width:40px;height:40px;border-radius:.5rem;background:${statusBg};display:flex;align-items:center;justify-content:center;font-size:.7rem;font-weight:800;color:${statusColor};">${(j.status||'').charAt(0).toUpperCase()}</div>
                  <div>
                    <div style="font-weight:700;font-size:.85rem;color:#0f172a;">${j.job_number||'#'+j.id} — ${j.vehicle_reg||'—'}</div>
                    <div style="font-size:.75rem;color:#64748b;">${fmtDate(j.date_received)} ${j.date_completed?'→ Completed '+fmtDate(j.date_completed):''}</div>
                    ${j.complaint?`<div style="font-size:.72rem;color:#475569;margin-top:.15rem;">${j.complaint.substring(0,80)}${j.complaint.length>80?'…':''}</div>`:''}
                  </div>
                  <div style="text-align:right;">
                    ${j.job_revenue?`<div style="font-weight:800;color:#059669;font-size:.9rem;">${fmt(j.job_revenue)}</div>`:''}
                    <span style="background:${statusBg};color:${statusColor};padding:.15rem .5rem;border-radius:20px;font-size:.65rem;font-weight:700;">${(j.status||'—').replace('_',' ').toUpperCase()}</span>
                  </div>
                </div>`;
              }).join('')
            : '<p style="text-align:center;color:#94a3b8;padding:2rem;font-size:.85rem;">No service history found.</p>';

        const clv = c.clv || {};
        const kpiGrid = document.getElementById('p360-kpi-grid');
        const kpis = [
            { lbl:'Total Visits', val: clv.total_visits||0, icon:'🏁', color:'#2563eb' },
            { lbl:'Total Revenue', val: fmt(clv.total_revenue||0), icon:'💰', color:'#059669' },
            { lbl:'Avg Spend / Visit', val: fmt(clv.avg_spend_per_visit||0), icon:'📊', color:'#7c3aed' },
            { lbl:'Vehicles Serviced', val: clv.vehicles_count||0, icon:'🚗', color:'#0891b2' },
            { lbl:'First Visit', val: fmtDate(clv.first_visit), icon:'📅', color:'#64748b' },
            { lbl:'Last Visit', val: fmtDate(clv.last_visit), icon:'🕐', color:'#64748b' },
            { lbl:'Days Since Visit', val: clv.days_since_last_visit||'—', icon:'⏱️', color: (clv.days_since_last_visit||0)>90?'#dc2626':'#059669' },
            { lbl:'Visits / Year', val: clv.visits_per_year||0, icon:'📆', color:'#f59e0b' },
        ];
        kpiGrid.innerHTML = kpis.map(k => `
            <div class="kpi-card">
              <div style="font-size:1.5rem;">${k.icon}</div>
              <div class="kv" style="color:${k.color};">${k.val}</div>
              <div class="kl">${k.lbl}</div>
            </div>`).join('');

        document.getElementById('p360-clv-projection').innerHTML = `
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:1rem;">
              <div style="background:linear-gradient(135deg,#eff6ff,#f5f3ff);border-radius:.75rem;padding:1rem;text-align:center;">
                <div style="font-size:1.6rem;font-weight:800;color:#2563eb;">${fmt(clv.estimated_annual_value||0)}</div>
                <div style="font-size:.7rem;font-weight:700;text-transform:uppercase;color:#64748b;margin-top:.3rem;">Estimated Annual Value</div>
              </div>
              <div style="background:linear-gradient(135deg,#f0fdf4,#ecfdf5);border-radius:.75rem;padding:1rem;text-align:center;">
                <div style="font-size:1.6rem;font-weight:800;color:#059669;">${fmt(clv.estimated_3yr_clv||0)}</div>
                <div style="font-size:.7rem;font-weight:700;text-transform:uppercase;color:#64748b;margin-top:.3rem;">3-Year CLV Projection</div>
              </div>
            </div>
            <div style="margin-top:.75rem;padding:.75rem 1rem;background:white;border-radius:.75rem;border:1px solid #e2e8f0;font-size:.8rem;color:#475569;">
              <strong>Customer for ${clv.years_as_customer||1} year(s)</strong> · Based on ${clv.total_visits||0} visits at avg ${fmt(clv.avg_spend_per_visit||0)} / visit
            </div>`;

        const rl = document.getElementById('p360-reviews-list');
        rl.innerHTML = (c.reviews||[]).length
            ? c.reviews.map(r => `
                <div class="rev-row">
                  <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:.4rem;">
                    <div>${stars(r.rating)}</div>
                    <div style="font-size:.7rem;color:#94a3b8;">${fmtDate(r.created_at)}</div>
                  </div>
                  ${r.review_text?`<div style="font-size:.83rem;color:#374151;">${r.review_text}</div>`:''}
                  ${r.response_text?`<div style="margin-top:.4rem;padding:.4rem .7rem;background:#eff6ff;border-left:3px solid #2563eb;border-radius:0 .5rem .5rem 0;font-size:.75rem;color:#1e40af;"><strong>Response:</strong> ${r.response_text}</div>`:''}
                </div>`).join('')
            : '<p style="text-align:center;color:#94a3b8;padding:1rem;font-size:.82rem;">No reviews yet.</p>';

        const al = document.getElementById('p360-appt-list');
        al.innerHTML = (c.appointments||[]).length
            ? c.appointments.map(a => `
                <div class="appt-row">
                  <div style="display:flex;justify-content:space-between;align-items:center;">
                    <div><strong style="font-size:.9rem;">${fmtDate(a.appointment_date)} ${a.appointment_time||''}</strong><span style="margin-left:.5rem;font-size:.7rem;background:#dbeafe;color:#1e40af;padding:.15rem .5rem;border-radius:20px;font-weight:700;">${(a.status||'scheduled').toUpperCase()}</span></div>
                    <div style="font-size:.75rem;color:#1e40af;">${a.vehicle_reg||''}</div>
                  </div>
                  <div style="font-size:.8rem;color:#1e40af;margin-top:.25rem;">${a.service_type||''}</div>
                  ${a.technician_note?`<div style="font-size:.72rem;color:#64748b;margin-top:.2rem;">${a.technician_note}</div>`:''}
                </div>`).join('')
            : '<p style="text-align:center;color:#94a3b8;padding:1rem;font-size:.82rem;">No upcoming appointments.</p>';

        const dl = document.getElementById('p360-deferred-list');
        dl.innerHTML = (c.deferred||[]).length
            ? c.deferred.map(d => `
                <div class="def-row">
                  <div style="display:flex;justify-content:space-between;align-items:center;">
                    <div style="font-weight:700;font-size:.85rem;color:#92400e;">${d.service_description||''}</div>
                    <span style="font-size:.65rem;background:#fde68a;color:#92400e;padding:.15rem .5rem;border-radius:20px;font-weight:700;">${(d.status||'pending').toUpperCase()}</span>
                  </div>
                  <div style="font-size:.75rem;color:#92400e;margin-top:.25rem;">
                    ${d.vehicle_reg?'🚗 '+d.vehicle_reg+' · ':''}Deferred: ${fmtDate(d.deferred_date)}
                    ${d.follow_up_date?' · Follow-up: '+fmtDate(d.follow_up_date):''}
                  </div>
                  ${d.reason?`<div style="font-size:.72rem;color:#78350f;margin-top:.2rem;">Reason: ${d.reason}</div>`:''}
                </div>`).join('')
            : '<p style="text-align:center;color:#94a3b8;padding:1rem;font-size:.82rem;">No deferred services logged.</p>';
    }

    async function post360(payload, successMsg) {
        try {
            const body = new URLSearchParams(payload);
            const resp = await fetch('index.php', { method:'POST', body });
            const data = await resp.json();
            if (data.success) showToast('✅ ' + (data.message || successMsg), 'success');
            else showToast('❌ ' + (data.error || 'Failed'), 'error');
        } catch(e) { showToast('Network error: ' + e.message, 'error'); }
    }

    function submitAppointment() {
        const cid = _360customerId;
        if (!document.getElementById('appt-date').value) { showToast('Please select an appointment date', 'error'); return; }
        post360({ ajax_action:'save_appointment', customer_id:cid, vehicle_reg:document.getElementById('appt-reg').value, appointment_date:document.getElementById('appt-date').value, appointment_time:document.getElementById('appt-time').value, service_type:document.getElementById('appt-service').value, technician_note:document.getElementById('appt-notes').value }, 'Appointment booked!');
    }

    function submitDeferred() {
        const cid = _360customerId;
        if (!document.getElementById('def-desc').value) { showToast('Please enter a service description', 'error'); return; }
        post360({ ajax_action:'save_deferred', customer_id:cid, vehicle_reg:document.getElementById('def-reg').value, service_description:document.getElementById('def-desc').value, deferred_date:document.getElementById('def-date').value, follow_up_date:document.getElementById('def-followup').value, reason:document.getElementById('def-reason').value }, 'Deferred service logged!');
    }

    function submitReview() {
        const cid = _360customerId;
        post360({ ajax_action:'save_review', customer_id:cid, rating:document.getElementById('rev-rating').value, review_text:document.getElementById('rev-text').value, job_card_id:document.getElementById('rev-job').value }, 'Review saved!');
    }

    function submitMileage() {
        const cid = _360customerId;
        if (!document.getElementById('mil-km').value) { showToast('Please enter mileage reading', 'error'); return; }
        post360({ ajax_action:'log_mileage', customer_id:cid, vehicle_reg:document.getElementById('mil-reg').value, mileage:document.getElementById('mil-km').value, recorded_at:document.getElementById('mil-date').value, notes:document.getElementById('mil-notes').value }, 'Mileage logged!');
    }

    function submitInspection() {
        const cid = _360customerId;
        const checks = {};
        document.querySelectorAll('#inspectionChecklist select').forEach(s => { checks[s.name] = s.value; });
        const summary = Object.entries(checks).filter(([k,v])=>v!='ok').map(([k,v])=>`${k}=${v}`).join(', ');
        const notes = document.getElementById('insp-notes').value + (summary ? '\nIssues: ' + summary : '');
        post360({ ajax_action:'save_deferred', customer_id:cid, vehicle_reg:document.getElementById('insp-reg').value, service_description:'Digital Inspection', deferred_date:document.getElementById('insp-date').value, reason:notes }, 'Inspection saved!');
    }

    // ─────────────────────────────────────────────────────────────────
    // FILTER / SEARCH
    // ─────────────────────────────────────────────────────────────────
    function applyFilters() {
        const search = document.getElementById('searchInput').value;
        const tier   = document.getElementById('tierFilter').value;
        const source = document.getElementById('sourceFilter').value;
        const status = document.getElementById('statusFilter').value;
        let url = 'index.php?';
        if (search) url += `search=${encodeURIComponent(search)}&`;
        if (tier)   url += `tier=${encodeURIComponent(tier)}&`;
        if (source) url += `source=${encodeURIComponent(source)}&`;
        if (status && status !== 'all') url += `status=${encodeURIComponent(status)}&`;
        window.location.href = url;
    }
    document.getElementById('searchInput')?.addEventListener('keypress', e => { if (e.key === 'Enter') applyFilters(); });

    // ─────────────────────────────────────────────────────────────────
    // BULK SELECTION
    // ─────────────────────────────────────────────────────────────────
    function toggleSelectAll(master) {
        document.querySelectorAll('.row-check').forEach(cb => cb.checked = master.checked);
        updateBulkBar();
    }
    function updateBulkBar() {
        const selected = [...document.querySelectorAll('.row-check:checked')];
        const bar = document.getElementById('bulkBar');
        document.getElementById('bulkCount').textContent = `${selected.length} selected`;
        bar.classList.toggle('show', selected.length > 0);
    }
    function clearSelection() {
        document.querySelectorAll('.row-check, #selectAll').forEach(cb => cb.checked = false);
        updateBulkBar();
    }

    // ─────────────────────────────────────────────────────────────────
    // MESSAGE TEMPLATES
    // ─────────────────────────────────────────────────────────────────
    const templates = {
        service_due: {
            subject: 'Your vehicle service is due!',
            message: `Dear {name},\n\nWe hope you're enjoying your drive! 🚗\n\nThis is a friendly reminder from Savant Motors that your vehicle's service is due. Regular maintenance keeps your car running smoothly and safely.\n\nCall us today to book your appointment.\n📞 Savant Motors | Quality Service You Can Trust`
        },
        oil_change: {
            subject: 'Time for an Oil Change!',
            message: `Hi {name},\n\nYour vehicle is due for an oil change! Fresh oil protects your engine and improves fuel efficiency.\n\nBook your appointment today and we'll have you back on the road quickly.\n\n📍 Savant Motors Workshop\n📞 Contact us now to schedule.`
        },
        christmas: {
            subject: '🎄 Merry Christmas from Savant Motors!',
            message: `Dear {name},\n\n🎄 Wishing you a joyful Christmas and a wonderful holiday season!\n\nThank you for trusting Savant Motors with your vehicle care throughout the year. We look forward to serving you in the new year.\n\n🎁 Special festive service offers available – ask us today!\n\nWarm regards,\nThe Savant Motors Team`
        },
        new_year: {
            subject: '🎉 Happy New Year from Savant Motors!',
            message: `Dear {name},\n\n🎉 Happy New Year! Wishing you a prosperous and successful year ahead.\n\nStart the new year right – book a full vehicle check-up and ensure your car is ready for everything the year brings!\n\nBest wishes,\nSavant Motors Team`
        },
        eid: {
            subject: '🌙 Eid Mubarak from Savant Motors!',
            message: `Dear {name},\n\n🌙 Eid Mubarak! May this blessed occasion bring you joy, peace, and prosperity.\n\nFrom all of us at Savant Motors, we thank you for your continued trust and loyalty.\n\nEid Greetings,\nSavant Motors Team`
        },
        promo: {
            subject: '🔧 Special Offer – Savant Motors',
            message: `Dear {name},\n\n🔧 We have an exciting special offer just for you!\n\nBring your vehicle in this month and enjoy discounted servicing rates for our valued customers.\n\nDon't miss out – limited slots available!\n\n📞 Call now to book your appointment.\nSavant Motors | Quality Service You Can Trust`
        }
    };

    function applyTemplate(key) {
        const tpl = templates[key];
        if (!tpl) return;
        document.getElementById('rmSubject').value = tpl.subject;
        document.getElementById('rmMessage').value = tpl.message;
        updateCharCount();
    }

    function loadTemplate() {
        const type = document.getElementById('rmType').value;
        if (type === 'service_expiry') applyTemplate('service_due');
        else if (type === 'seasonal') applyTemplate('christmas');
    }

    function updateCharCount() {
        const len = document.getElementById('rmMessage').value.length;
        document.getElementById('rmCharCount').textContent = `${len} characters`;
    }
    document.getElementById('rmMessage')?.addEventListener('input', updateCharCount);

    // ─────────────────────────────────────────────────────────────────
    // OPEN / CLOSE MODAL (single customer)
    // ─────────────────────────────────────────────────────────────────
    function openReminderModal(customerId, name, phone, email) {
        document.getElementById('rmCustomerId').value = customerId;
        document.getElementById('rmIsBulk').value    = '0';
        document.getElementById('rmTitle').textContent    = 'Send Reminder';
        document.getElementById('rmSubtitle').textContent = 'Notify customer via WhatsApp, Email or SMS';
        document.getElementById('rmCustomerStrip').style.display = 'block';
        document.getElementById('rmBulkStrip').style.display     = 'none';
        document.getElementById('rmCustomerInfo').innerHTML =
            `<strong>${name}</strong>${phone ? ' &nbsp;📞 ' + phone : ''}${email ? ' &nbsp;✉️ ' + email : ''}`;
        loadTemplate();
        document.getElementById('reminderOverlay').classList.add('open');
    }

    function openBulkReminderModal() {
        const selected = [...document.querySelectorAll('.row-check:checked')].map(cb => cb.value);
        document.getElementById('rmIsBulk').value = '1';
        document.getElementById('rmTitle').textContent    = 'Send Bulk Reminder';
        document.getElementById('rmSubtitle').textContent = 'Send message to multiple customers at once';
        document.getElementById('rmCustomerStrip').style.display = 'none';
        document.getElementById('rmBulkStrip').style.display     = 'block';
        if (selected.length > 0) {
            document.getElementById('rmBulkInfo').textContent = `${selected.length} customer(s) selected`;
        } else {
            document.getElementById('rmBulkInfo').textContent = 'All customers in current view';
        }
        loadTemplate();
        document.getElementById('reminderOverlay').classList.add('open');
    }

    function closeReminderModal() {
        document.getElementById('reminderOverlay').classList.remove('open');
    }

    // ─────────────────────────────────────────────────────────────────
    // SUBMIT REMINDER
    // ─────────────────────────────────────────────────────────────────
    async function submitReminder(mode) {
        const isBulk   = document.getElementById('rmIsBulk').value === '1';
        const message  = document.getElementById('rmMessage').value.trim();
        const subject  = document.getElementById('rmSubject').value.trim();
        const type     = document.getElementById('rmType').value;
        const schedule = mode === 'schedule' ? document.getElementById('rmScheduleAt').value : '';
        const channels = [...document.querySelectorAll('input[name="channels"]:checked')].map(c => c.value);

        if (!message) { showToast('Please enter a message.', 'error'); return; }
        if (!channels.length) { showToast('Select at least one channel.', 'error'); return; }
        if (mode === 'schedule' && !schedule) { showToast('Please pick a date/time to schedule.', 'error'); return; }

        const btn = document.getElementById('btnSend');
        btn.disabled = true;
        btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Sending…';

        try {
            let body;
            if (isBulk) {
                const selected = [...document.querySelectorAll('.row-check:checked')].map(cb => cb.value);
                body = new URLSearchParams({
                    ajax_action: 'bulk_reminder',
                    customer_ids: JSON.stringify(selected),
                    reminder_type: type,
                    subject,
                    message,
                    schedule_at: schedule
                });
                channels.forEach(c => body.append('channels[]', c));
            } else {
                body = new URLSearchParams({
                    ajax_action: 'send_reminder',
                    customer_id: document.getElementById('rmCustomerId').value,
                    reminder_type: type,
                    subject,
                    message,
                    schedule_at: schedule
                });
                channels.forEach(c => body.append('channels[]', c));
            }

            const resp = await fetch('index.php', { method: 'POST', body });
            const data = await resp.json();

            if (data.success) {
                const channelLabels = { whatsapp:'WhatsApp', email:'Email', sms:'SMS' };
                const chStr = channels.map(c => channelLabels[c] || c).join(', ');
                if (data.status === 'scheduled') {
                    showToast(`✅ Reminder scheduled via ${chStr}`, 'success');
                } else if (isBulk) {
                    showToast(`✅ Reminder sent to ${data.sent_count} customer(s) via ${chStr}`, 'success');
                    clearSelection();
                } else {
                    showToast(`✅ Reminder sent to ${data.customer} via ${chStr}`, 'success');
                }
                closeReminderModal();
            } else {
                showToast('❌ ' + (data.error || 'Failed to send'), 'error');
            }
        } catch(e) {
            showToast('Network error: ' + e.message, 'error');
        } finally {
            btn.disabled = false;
            btn.innerHTML = '<i class="fas fa-paper-plane"></i> Send Now';
        }
    }

    // ─────────────────────────────────────────────────────────────────
    // TOAST
    // ─────────────────────────────────────────────────────────────────
    function showToast(msg, type = 'success') {
        const t = document.createElement('div');
        t.className = `rm-toast ${type}`;
        t.innerHTML = `<i class="fas fa-${type === 'success' ? 'check-circle' : 'exclamation-circle'}"></i> ${msg}`;
        document.body.appendChild(t);
        setTimeout(() => t.style.opacity = '0', 3000);
        setTimeout(() => t.remove(), 3400);
    }

    // ── VIEW MODAL ────────────────────────────────────────────────────────────
    let _currentViewId = null;

    async function openViewModal(customerId) {
        _currentViewId = customerId;
        const overlay = document.getElementById('viewModalOverlay');
        overlay.style.display = 'flex';
        document.getElementById('viewModalName').textContent = 'Loading…';
        document.getElementById('viewModalSub').textContent  = '';
        document.getElementById('viewModalBody').innerHTML   = '<div style="text-align:center;padding:2rem;color:#94a3b8;"><i class="fas fa-spinner fa-spin" style="font-size:1.5rem;"></i><p style="margin-top:.5rem;">Loading customer…</p></div>';

        try {
            const resp = await fetch(`index.php?ajax_action=get_customer&customer_id=${customerId}`);
            const data = await resp.json();
            if (!data.success) throw new Error(data.error || 'Failed');
            const c = data.customer;

            document.getElementById('viewModalName').textContent = c.full_name || 'Unknown';
            document.getElementById('viewModalSub').textContent  = (c.telephone || '') + (c.email ? '  ·  ' + c.email : '');
            document.getElementById('viewToEditBtn').onclick = () => { closeViewModal(); openEditModal(customerId); };

            const tierColors = { platinum:'#7c3aed', gold:'#d97706', silver:'#64748b', bronze:'#b45309' };
            const tier = (c.customer_tier || '').toLowerCase();

            const jobs = (c.jobs || []).map(j => `
                <tr style="font-size:.8rem;">
                    <td style="padding:.4rem .6rem;">${j.job_number || '—'}</td>
                    <td style="padding:.4rem .6rem;">${j.vehicle_reg || '—'}</td>
                    <td style="padding:.4rem .6rem;">${j.date_received ? new Date(j.date_received).toLocaleDateString('en-GB',{day:'2-digit',month:'short',year:'numeric'}) : '—'}</td>
                    <td style="padding:.4rem .6rem;"><span style="padding:.15rem .5rem;border-radius:2rem;font-size:.65rem;font-weight:700;background:${j.status==='completed'?'#dcfce7':j.status==='pending'?'#fef9c3':'#dbeafe'};color:${j.status==='completed'?'#166534':j.status==='pending'?'#854d0e':'#1e40af'};">${j.status||'—'}</span></td>
                </tr>`).join('');

            document.getElementById('viewModalBody').innerHTML = `
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:.75rem 1.25rem;margin-bottom:1.25rem;">
                    ${field('👤 Full Name',     c.full_name)}
                    ${field('📞 Phone',          c.telephone)}
                    ${field('✉️ Email',          c.email)}
                    ${field('📍 Address',        c.address)}
                    ${field('🏷️ Tier',           tier ? `<span style="background:${tierColors[tier]||'#64748b'}22;color:${tierColors[tier]||'#64748b'};padding:.15rem .5rem;border-radius:2rem;font-size:.75rem;font-weight:700;">${tier.charAt(0).toUpperCase()+tier.slice(1)}</span>` : '—', true)}
                    ${field('📥 Source',         c.customer_source || '—')}
                    ${field('📅 Joined',         c.created_at ? new Date(c.created_at).toLocaleDateString('en-GB',{day:'2-digit',month:'short',year:'numeric'}) : '—')}
                    ${field('✅ Status',          c.status == 1 ? '<span style="color:#059669;font-weight:700;">Active</span>' : '<span style="color:#dc2626;font-weight:700;">Inactive</span>', true)}
                </div>
                ${c.notes ? `<div style="background:#f8fafc;border-radius:.6rem;padding:.75rem 1rem;margin-bottom:1.25rem;font-size:.83rem;color:#475569;"><strong style="font-size:.68rem;text-transform:uppercase;color:#94a3b8;display:block;margin-bottom:.3rem;">Notes</strong>${escHtml(c.notes)}</div>` : ''}
                ${jobs ? `
                <div style="font-size:.68rem;font-weight:700;text-transform:uppercase;color:#94a3b8;margin-bottom:.5rem;">Job History (last 10)</div>
                <div style="border:1px solid #e2e8f0;border-radius:.6rem;overflow:hidden;">
                <table style="width:100%;border-collapse:collapse;">
                    <thead><tr style="background:#f8fafc;">
                        <th style="padding:.4rem .6rem;text-align:left;font-size:.68rem;color:#64748b;">Job #</th>
                        <th style="padding:.4rem .6rem;text-align:left;font-size:.68rem;color:#64748b;">Vehicle</th>
                        <th style="padding:.4rem .6rem;text-align:left;font-size:.68rem;color:#64748b;">Date</th>
                        <th style="padding:.4rem .6rem;text-align:left;font-size:.68rem;color:#64748b;">Status</th>
                    </tr></thead>
                    <tbody>${jobs}</tbody>
                </table></div>` : '<p style="color:#94a3b8;font-size:.83rem;">No job history found.</p>'}
            `;
        } catch(e) {
            document.getElementById('viewModalBody').innerHTML = `<div style="text-align:center;padding:2rem;color:#ef4444;"><i class="fas fa-exclamation-triangle" style="font-size:1.5rem;display:block;margin-bottom:.5rem;"></i>${e.message}</div>`;
        }
    }

    function field(label, value, isHtml = false) {
        return `<div>
            <div style="font-size:.65rem;font-weight:700;text-transform:uppercase;color:#94a3b8;margin-bottom:.2rem;">${label}</div>
            <div style="font-size:.88rem;color:#0f172a;font-weight:500;">${isHtml ? (value||'—') : escHtml(value||'—')}</div>
        </div>`;
    }
    function escHtml(s) {
        return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
    }
    function closeViewModal() {
        document.getElementById('viewModalOverlay').style.display = 'none';
    }

    // ── EDIT MODAL ────────────────────────────────────────────────────────────
    async function openEditModal(customerId) {
        document.getElementById('editModalOverlay').style.display = 'flex';
        document.getElementById('editModalSub').textContent = 'Loading…';

        try {
            const resp = await fetch(`index.php?ajax_action=get_customer&customer_id=${customerId}`);
            const data = await resp.json();
            if (!data.success) throw new Error(data.error || 'Failed to load');
            const c = data.customer;

            document.getElementById('editCustomerId').value  = c.id;
            document.getElementById('editFullName').value    = c.full_name   || '';
            document.getElementById('editTelephone').value   = c.telephone   || '';
            document.getElementById('editEmail').value       = c.email       || '';
            document.getElementById('editAddress').value     = c.address     || '';
            document.getElementById('editStatus').value      = c.status ?? '1';
            document.getElementById('editTier').value        = c.customer_tier    || '';
            document.getElementById('editSource').value      = c.customer_source  || '';
            document.getElementById('editNotes').value       = c.notes       || '';
            document.getElementById('editModalSub').textContent = c.full_name || 'Customer';
        } catch(e) {
            showToast('Failed to load customer: ' + e.message, 'error');
            closeEditModal();
        }
    }

    function closeEditModal() {
        document.getElementById('editModalOverlay').style.display = 'none';
    }

    async function submitEdit(e) {
        e.preventDefault();
        const btn = document.getElementById('editSaveBtn');
        btn.disabled = true;
        btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Saving…';

        try {
            const form = document.getElementById('editForm');
            const body = new FormData(form);
            body.append('ajax_action', 'update_customer');

            const resp = await fetch('index.php', { method: 'POST', body });
            const data = await resp.json();

            if (data.success) {
                showToast('✅ Customer updated successfully!', 'success');
                closeEditModal();
                setTimeout(() => location.reload(), 1200);
            } else {
                showToast('❌ ' + (data.error || 'Update failed'), 'error');
            }
        } catch(err) {
            showToast('Network error: ' + err.message, 'error');
        } finally {
            btn.disabled = false;
            btn.innerHTML = '<i class="fas fa-save"></i> Save Changes';
        }
    }

    // ═══════════════════════════════════════════════════════════════════════
    //  INTELLIGENCE HUB — JavaScript
    // ═══════════════════════════════════════════════════════════════════════

    function switchIntelTab(name, btn) {
        document.querySelectorAll('.intel-tab').forEach(t => t.classList.remove('active'));
        document.querySelectorAll('.intel-pane').forEach(p => p.classList.remove('active'));
        btn.classList.add('active');
        document.getElementById('pane-' + name).classList.add('active');
    }

    // ① PREDICTIVE MAINTENANCE
    async function loadPredictive() {
        const cid = document.getElementById('predCustomerSel').value;
        if (!cid) return showToast('Please select a customer', 'error');
        document.getElementById('predResults').innerHTML = '<div style="color:#94a3b8;font-size:.8rem;"><i class="fas fa-spinner fa-spin"></i> Analysing…</div>';
        const r = await fetch(`index.php?ajax_action=predictive_maintenance&customer_id=${cid}`);
        const d = await r.json();
        if (!d.success || !d.predictions.length) {
            document.getElementById('predResults').innerHTML = '<p style="color:#94a3b8;font-size:.8rem;">No vehicle/mileage data found for this customer.</p>';
            return;
        }
        const urgencyIcon = {'critical':'🔴','warning':'🟡','ok':'🟢'};
        document.getElementById('predResults').innerHTML = d.predictions.map(p => `
            <div class="pred-vehicle">
                <div class="pred-vehicle-title">🚗 ${p.vehicle_reg} — ${p.vehicle_model||'Unknown Model'} <span style="font-size:.7rem;font-weight:500;color:#64748b;">(${p.make_key})</span></div>
                <div style="font-size:.72rem;color:#94a3b8;margin-bottom:.4rem;">Last service: ${p.last_service||'—'} · Last mileage: ${p.last_mileage ? p.last_mileage.toLocaleString()+' km' : '—'}</div>
                ${p.alerts.map(a => `
                    <div class="pred-alert ${a.urgency}">
                        ${urgencyIcon[a.urgency]} <strong>${a.service}</strong>
                        ${a.due_km !== undefined ? `— ${a.due_km.toLocaleString()} km remaining (every ${a.interval_km.toLocaleString()} km)` : ''}
                        ${a.due_months !== undefined ? `— ${a.due_months} month(s) until service (${a.months_since} months since last)` : ''}
                    </div>`).join('')}
            </div>`).join('');
    }

    // ② DIGITAL INSPECTION
    function cycleInspItem(el) {
        const states = ['pass','warn','fail'];
        const icons  = {'pass':'✅','warn':'⚠️','fail':'❌'};
        const classes= {'pass':'pass','warn':'warn','fail':'fail'};
        let cur = el.dataset.status;
        let next = states[(states.indexOf(cur)+1) % 3];
        el.dataset.status = next;
        el.className = 'insp-item ' + classes[next];
        el.querySelector('.insp-icon').textContent = icons[next];
    }

    async function saveInspection() {
        const cid = document.getElementById('inspCustomer').value;
        if (!cid) return showToast('Select a customer', 'error');
        const items = {};
        document.querySelectorAll('#inspChecklist .insp-item').forEach(el => {
            items[el.dataset.item] = el.dataset.status;
        });
        const fd = new FormData();
        fd.append('ajax_action','save_inspection');
        fd.append('customer_id', cid);
        fd.append('vehicle_reg', document.getElementById('inspReg').value);
        fd.append('inspection_date', new Date().toISOString().slice(0,10));
        fd.append('overall_condition', document.getElementById('inspCondition').value);
        fd.append('checklist', JSON.stringify(items));
        fd.append('notes', document.getElementById('inspNotes').value);
        fd.append('shared_with_customer', document.getElementById('inspShare').checked ? '1' : '0');
        const r = await fetch('index.php', {method:'POST',body:fd});
        const d = await r.json();
        d.success ? showToast('✅ Inspection saved!') : showToast('❌ ' + d.error, 'error');
    }

    async function loadInspHistory() {
        const cid = document.getElementById('inspCustomer').value;
        if (!cid) return showToast('Select a customer first', 'error');
        const r = await fetch(`index.php?ajax_action=get_inspections&customer_id=${cid}`);
        const d = await r.json();
        const el = document.getElementById('inspHistory');
        if (!d.success || !d.inspections.length) { el.innerHTML = '<p style="color:#94a3b8;">No past inspections found.</p>'; return; }
        const condColor = {excellent:'#10b981',good:'#3b82f6',fair:'#f59e0b',poor:'#ef4444'};
        el.innerHTML = d.inspections.map(i => {
            const fails = Object.entries(i.checklist).filter(([,v])=>v==='fail').map(([k])=>k).join(', ');
            const warns = Object.entries(i.checklist).filter(([,v])=>v==='warn').map(([k])=>k).join(', ');
            return `<div style="border:1px solid #e2e8f0;border-radius:10px;padding:.7rem;margin-bottom:.5rem;">
                <div style="display:flex;justify-content:space-between;margin-bottom:.35rem;">
                    <strong style="font-size:.82rem;">${i.vehicle_reg||'—'} · ${i.inspection_date}</strong>
                    <span style="font-size:.7rem;font-weight:700;color:${condColor[i.overall_condition]||'#64748b'};">${(i.overall_condition||'').toUpperCase()}</span>
                </div>
                ${fails ? `<div style="font-size:.72rem;color:#991b1b;"><b>❌ Fail:</b> ${fails}</div>` : ''}
                ${warns ? `<div style="font-size:.72rem;color:#854d0e;"><b>⚠️ Note:</b> ${warns}</div>` : ''}
                ${i.shared_with_customer ? '<div style="font-size:.68rem;color:#059669;margin-top:.2rem;">✅ Shared with customer</div>' : ''}
            </div>`;
        }).join('');
    }

    // ③ 100 DAYS JOURNEY
    async function load100Days() {
        const cid = document.getElementById('journeyCustomerSel').value;
        if (!cid) return showToast('Select a customer', 'error');
        const r = await fetch(`index.php?ajax_action=first_100_days&customer_id=${cid}`);
        const d = await r.json();
        if (!d.success) return showToast(d.error, 'error');
        const chanIcon = {whatsapp:'💬',sms:'📱',email:'📧'};
        const statusLabel = {sent:'✅ Sent',missed:'❌ Missed',due_soon:'⚡ Due Soon',upcoming:'⏳ Upcoming'};
        const el = document.getElementById('journeyResults');
        el.innerHTML = `
            <div style="font-size:.8rem;font-weight:600;color:#0f172a;margin-bottom:.75rem;">
                ${d.customer.full_name} — Day <strong>${d.days_since_join}</strong> of their journey
                ${d.days_since_join >= 100 ? '🎉 Completed!' : `(${100 - d.days_since_join} days left)`}
            </div>
            <div class="journey-line">
                ${d.touchpoints.map(tp => `
                    <div class="journey-tp ${tp.status}">
                        <span style="font-size:.7rem;color:#94a3b8;">Day ${tp.day}</span>
                        ${chanIcon[tp.channel]||'📣'} <strong>${tp.label}</strong>
                        <span style="float:right;font-size:.7rem;">${statusLabel[tp.status]||tp.status}</span>
                        ${tp.status === 'due_soon' || tp.status === 'missed' ?
                            `<button class="intel-btn intel-btn-primary" style="margin-top:.3rem;font-size:.68rem;padding:.25rem .6rem;" onclick="quickSendTouchpoint(${cid},'${tp.label}','${tp.channel}')"><i class="fas fa-paper-plane"></i> Send Now</button>` : ''}
                    </div>`).join('')}
            </div>`;
    }

    async function quickSendTouchpoint(cid, label, channel) {
        const fd = new FormData();
        fd.append('ajax_action','send_reminder'); fd.append('customer_id',cid);
        fd.append('reminder_type','custom'); fd.append('channels[]',channel);
        fd.append('subject',label); fd.append('message', `Dear Customer, this is your "${label}" touchpoint from Savant Motors. Thank you for choosing us! 🚗`);
        const r = await fetch('index.php',{method:'POST',body:fd});
        const d = await r.json();
        d.success ? (showToast('✅ Sent: '+label), load100Days()) : showToast('❌ '+d.error,'error');
    }

    // ④ SEGMENTATION
    async function loadSegmentation() {
        const r = await fetch('index.php?ajax_action=segmentation');
        const d = await r.json();
        if (!d.success) return showToast(d.error,'error');
        const seg = d.segments;
        const meta = {
            champions:  {label:'🏆 Champions',    cls:'champions',  desc:'High value, recent, frequent'},
            loyal:      {label:'💛 Loyal',          cls:'loyal',      desc:'Regular, consistent visitors'},
            at_risk:    {label:'⚠️ At Risk',        cls:'at_risk',    desc:'Were active, now fading'},
            lost:       {label:'💔 Lost',            cls:'lost',       desc:'No visit in 12+ months'},
            new:        {label:'🆕 New',             cls:'new_cust',   desc:'Joined in last 30 days'},
            potential:  {label:'⭐ Potential',      cls:'potential',  desc:'Low visits, good signs'},
        };
        document.getElementById('segResults').innerHTML = `
            <div class="seg-grid">
                ${Object.entries(meta).map(([k,m]) => `
                    <div class="seg-card ${m.cls}" onclick="showSegDetail('${k}',${JSON.stringify(seg[k]||[]).replace(/"/g,'&quot;')})">
                        <div class="seg-count">${(seg[k]||[]).length}</div>
                        <div class="seg-label">${m.label}</div>
                        <div style="font-size:.65rem;margin-top:3px;opacity:.75;">${m.desc}</div>
                    </div>`).join('')}
            </div>`;
    }

    function showSegDetail(segName, customers) {
        const el = document.getElementById('segDetail');
        if (!customers.length) { el.innerHTML = '<p style="color:#94a3b8;font-size:.8rem;">No customers in this segment.</p>'; return; }
        el.innerHTML = `
            <h4 style="font-size:.82rem;font-weight:700;margin-bottom:.5rem;">${segName.toUpperCase()} — ${customers.length} customer(s)</h4>
            <div style="overflow-x:auto;"><table style="width:100%;border-collapse:collapse;font-size:.78rem;">
                <thead><tr style="background:#f8fafc;">
                    <th style="padding:.4rem .6rem;text-align:left;color:#64748b;font-weight:600;border-bottom:1px solid #e2e8f0;">Name</th>
                    <th style="padding:.4rem .6rem;text-align:left;color:#64748b;font-weight:600;border-bottom:1px solid #e2e8f0;">Phone</th>
                    <th style="padding:.4rem .6rem;text-align:right;color:#64748b;font-weight:600;border-bottom:1px solid #e2e8f0;">Revenue</th>
                    <th style="padding:.4rem .6rem;text-align:right;color:#64748b;font-weight:600;border-bottom:1px solid #e2e8f0;">Visits</th>
                    <th style="padding:.4rem .6rem;text-align:right;color:#64748b;font-weight:600;border-bottom:1px solid #e2e8f0;">Last Visit</th>
                    <th style="padding:.4rem .6rem;border-bottom:1px solid #e2e8f0;"></th>
                </tr></thead>
                <tbody>${customers.slice(0,20).map(c => `
                    <tr style="border-bottom:1px solid #f1f5f9;">
                        <td style="padding:.4rem .6rem;font-weight:600;">${c.full_name}</td>
                        <td style="padding:.4rem .6rem;color:#64748b;">${c.telephone||'—'}</td>
                        <td style="padding:.4rem .6rem;text-align:right;font-weight:700;">UGX ${parseInt(c.total_revenue||0).toLocaleString()}</td>
                        <td style="padding:.4rem .6rem;text-align:right;">${c.visits}</td>
                        <td style="padding:.4rem .6rem;text-align:right;color:#64748b;">${c.last_visit||'—'}</td>
                        <td style="padding:.4rem .6rem;">
                            <button class="intel-btn intel-btn-primary" style="font-size:.65rem;padding:.2rem .5rem;" onclick="openViewModal(${c.id})">View</button>
                        </td>
                    </tr>`).join('')}
                </tbody>
            </table></div>
            ${customers.length > 20 ? `<p style="font-size:.72rem;color:#94a3b8;margin-top:.4rem;">Showing 20 of ${customers.length}</p>` : ''}`;
    }

    // ⑤ REVIEW GENERATION
    async function generateReview() {
        const cid = document.getElementById('reviewCustomer').value;
        if (!cid) return showToast('Select a customer', 'error');
        const fd = new FormData();
        fd.append('ajax_action','generate_review_request'); fd.append('customer_id',cid);
        fd.append('platform', document.getElementById('reviewPlatform').value);
        fd.append('channel',  document.getElementById('reviewChannel').value);
        const r = await fetch('index.php',{method:'POST',body:fd});
        const d = await r.json();
        if (d.success) {
            showToast('✅ Review request sent!');
            document.getElementById('reviewPreview').innerHTML = `
                <div style="font-size:.68rem;font-weight:700;text-transform:uppercase;color:#94a3b8;margin-bottom:.5rem;">Message Sent via ${d.channel}</div>
                <div style="white-space:pre-wrap;font-size:.8rem;color:#0f172a;line-height:1.55;">${d.message}</div>
                <a href="${d.link}" target="_blank" style="display:inline-block;margin-top:.5rem;font-size:.72rem;color:#2563eb;">🔗 ${d.link}</a>`;
        } else { showToast('❌ '+d.error,'error'); }
    }

    // ⑥ LEAD CAPTURE
    async function captureLead() {
        const name = document.getElementById('leadName').value.trim();
        if (!name) return showToast('Enter lead name', 'error');
        const fd = new FormData();
        fd.append('ajax_action','capture_lead');
        fd.append('full_name', name);
        fd.append('telephone', document.getElementById('leadPhone').value);
        fd.append('source',    document.getElementById('leadSource').value);
        fd.append('vehicle_interest', document.getElementById('leadVehicle').value);
        fd.append('notes',     document.getElementById('leadNotes').value);
        fd.append('ai_summary',document.getElementById('leadAiSummary').value);
        const r = await fetch('index.php',{method:'POST',body:fd});
        const d = await r.json();
        if (d.success) {
            showToast('✅ Lead #'+d.lead_id+' captured!');
            document.getElementById('leadName').value = document.getElementById('leadPhone').value =
            document.getElementById('leadVehicle').value = document.getElementById('leadNotes').value =
            document.getElementById('leadAiSummary').value = '';
            loadLeads();
        } else { showToast('❌ '+d.error,'error'); }
    }

    async function loadLeads() {
        const r = await fetch('index.php?ajax_action=get_leads');
        const d = await r.json();
        const el = document.getElementById('leadsTable');
        if (!d.success || !d.leads.length) { el.innerHTML = '<p style="color:#94a3b8;">No leads yet.</p>'; return; }
        const srcIcon = {call:'📞',walk_in:'🚶',whatsapp:'💬',website:'🌐',referral:'🤝',social:'📲'};
        const stColor = {new:'#2563eb',contacted:'#f59e0b',converted:'#10b981',lost:'#ef4444'};
        el.innerHTML = `<div style="overflow-x:auto;max-height:240px;overflow-y:auto;">
            <table style="width:100%;border-collapse:collapse;font-size:.75rem;">
                <thead><tr style="background:#f8fafc;position:sticky;top:0;">
                    <th style="padding:.35rem .5rem;text-align:left;color:#64748b;border-bottom:1px solid #e2e8f0;">Name</th>
                    <th style="padding:.35rem .5rem;text-align:left;color:#64748b;border-bottom:1px solid #e2e8f0;">Phone</th>
                    <th style="padding:.35rem .5rem;border-bottom:1px solid #e2e8f0;">Source</th>
                    <th style="padding:.35rem .5rem;border-bottom:1px solid #e2e8f0;">Status</th>
                    <th style="padding:.35rem .5rem;text-align:left;color:#64748b;border-bottom:1px solid #e2e8f0;">Interest</th>
                </tr></thead>
                <tbody>${d.leads.map(l => `
                    <tr style="border-bottom:1px solid #f1f5f9;">
                        <td style="padding:.35rem .5rem;font-weight:600;">${l.full_name}</td>
                        <td style="padding:.35rem .5rem;color:#64748b;">${l.telephone||'—'}</td>
                        <td style="padding:.35rem .5rem;text-align:center;">${srcIcon[l.source]||'❓'}</td>
                        <td style="padding:.35rem .5rem;text-align:center;"><span style="font-size:.65rem;font-weight:700;color:${stColor[l.status]||'#64748b'}">${l.status.toUpperCase()}</span></td>
                        <td style="padding:.35rem .5rem;color:#64748b;">${l.vehicle_interest||'—'}</td>
                    </tr>`).join('')}
                </tbody>
            </table></div>`;
    }

    // ⑦ TWO-WAY COMMS
    async function loadInteractions() {
        const cid = document.getElementById('commsCustomerSel').value;
        if (!cid) return;
        const r = await fetch(`index.php?ajax_action=get_interactions&customer_id=${cid}`);
        const d = await r.json();
        const el = document.getElementById('convoThread');
        if (!d.success || !d.interactions.length) { el.innerHTML = '<div style="text-align:center;color:#94a3b8;font-size:.78rem;padding:1rem;">No messages yet</div>'; return; }
        const chanIcon = {whatsapp:'💬',sms:'📱',email:'📧',call:'📞',in_person:'🤝'};
        el.innerHTML = [...d.interactions].reverse().map(i => `
            <div style="align-self:${i.direction==='outbound'?'flex-end':'flex-start'};">
                <div class="convo-msg ${i.direction==='outbound'?'out':'in'}">
                    ${chanIcon[i.channel]||''} ${i.message||'(no message)'}
                </div>
                <div class="convo-meta" style="text-align:${i.direction==='outbound'?'right':'left'};">
                    ${i.staff_name||''} · ${new Date(i.created_at).toLocaleString('en-GB',{day:'2-digit',month:'short',hour:'2-digit',minute:'2-digit'})}
                    · <span style="color:${i.status==='delivered'?'#10b981':i.status==='failed'?'#ef4444':'#94a3b8'}">${i.status}</span>
                </div>
            </div>`).join('');
        el.scrollTop = el.scrollHeight;
    }

    async function sendInteraction(direction) {
        const cid = document.getElementById('commsCustomerSel').value;
        const msg = document.getElementById('commsMsg').value.trim();
        if (!cid) return showToast('Select a customer', 'error');
        if (!msg) return showToast('Type a message', 'error');
        const fd = new FormData();
        fd.append('ajax_action','log_interaction'); fd.append('customer_id',cid);
        fd.append('channel', document.getElementById('commsChannel').value);
        fd.append('direction', direction); fd.append('message', msg); fd.append('status','sent');
        const r = await fetch('index.php',{method:'POST',body:fd});
        const d = await r.json();
        if (d.success) { document.getElementById('commsMsg').value=''; showToast('✅ '+(direction==='outbound'?'Sent':'Logged')); loadInteractions(); }
        else showToast('❌ '+d.error,'error');
    }

    // ⑧ LIFECYCLE
    function selectLcStage(btn, stage) {
        document.querySelectorAll('#lcStages .lc-badge').forEach(b => b.classList.remove('selected'));
        btn.classList.add('selected');
        document.getElementById('lcStageVal').value = stage;
    }

    async function saveLifecycle() {
        const cid = document.getElementById('lcCustomer').value;
        if (!cid) return showToast('Select a customer', 'error');
        const fd = new FormData();
        fd.append('ajax_action','set_lifecycle_stage'); fd.append('customer_id',cid);
        fd.append('stage', document.getElementById('lcStageVal').value);
        fd.append('stage_since', new Date().toISOString().slice(0,10));
        fd.append('next_action', document.getElementById('lcNextAction').value);
        fd.append('next_action_date', document.getElementById('lcNextDate').value);
        fd.append('notes', document.getElementById('lcNotes').value);
        const r = await fetch('index.php',{method:'POST',body:fd});
        const d = await r.json();
        d.success ? showToast('✅ Lifecycle stage saved!') : showToast('❌ '+d.error,'error');
    }

    async function loadLifecycleOverview() {
        const r = await fetch('index.php?ajax_action=lifecycle_overview');
        const d = await r.json();
        const el = document.getElementById('lcOverview');
        if (!d.success) { el.innerHTML = '<p style="color:#ef4444;">'+d.error+'</p>'; return; }
        const stageColor = {lead:'#94a3b8',new:'#3b82f6',active:'#10b981',loyal:'#7c3aed',at_risk:'#f59e0b',churned:'#ef4444',reactivated:'#059669'};
        if (!d.manual_stages.length) { el.innerHTML = '<p style="color:#94a3b8;">No manual stages set yet. Assign stages above to track them here.</p>'; return; }
        el.innerHTML = `
            <div style="display:flex;flex-wrap:wrap;gap:.4rem;margin-bottom:.5rem;">
                ${d.manual_stages.map(s => `
                    <div style="background:${stageColor[s.stage]||'#94a3b8'}22;border:1px solid ${stageColor[s.stage]||'#94a3b8'};border-radius:10px;padding:.5rem .9rem;text-align:center;">
                        <div style="font-size:1.3rem;font-weight:800;color:${stageColor[s.stage]||'#64748b'}">${s.manual_count}</div>
                        <div style="font-size:.68rem;font-weight:700;color:${stageColor[s.stage]||'#64748b'}">${s.stage.charAt(0).toUpperCase()+s.stage.slice(1)}</div>
                    </div>`).join('')}
            </div>
            <p style="font-size:.72rem;color:#94a3b8;">Based on manually assigned lifecycle stages. Auto-segments available in the Segmentation tab.</p>`;
    }

    // Close modals on Escape key
    document.addEventListener('keydown', e => {
        if (e.key === 'Escape') { closeViewModal(); closeEditModal(); close360(); }
    });
    </script>

</body>
</html>