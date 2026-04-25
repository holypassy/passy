<?php
// print_job.php — Renders the job card exactly as new_job.php looks, using saved DB data
session_start();

if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    header('Location: index.php');
    exit();
}

if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    die('Invalid job card ID.');
}
$job_id = (int)$_GET['id'];

try {
    $conn = new PDO("mysql:host=localhost;dbname=savant_motors_pos", "root", "");
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $stmt = $conn->prepare("
        SELECT
            jc.*,
            c.full_name  AS customer_name,
            c.telephone  AS customer_phone,
            c.email      AS customer_email,
            c.address    AS customer_address
        FROM job_cards jc
        LEFT JOIN customers c ON jc.customer_id = c.id
        WHERE jc.id = :id AND (jc.deleted_at IS NULL OR jc.deleted_at = '0000-00-00 00:00:00')
    ");
    $stmt->execute([':id' => $job_id]);
    $job = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$job) {
        die('Job card not found.');
    }

} catch (PDOException $e) {
    die("Database error: " . $e->getMessage());
}

// Decode stored JSON
$inspection_data = [];
if (!empty($job['inspection_data'])) {
    $decoded = json_decode($job['inspection_data'], true);
    if (is_array($decoded)) $inspection_data = $decoded;
}

$work_items = [];
if (!empty($job['work_items'])) {
    $decoded = json_decode($job['work_items'], true);
    if (is_array($decoded)) $work_items = $decoded;
}

// Helper: get inspection value
function iv($data, $key) {
    return htmlspecialchars($data[$key] ?? '');
}

$current_date = !empty($job['date_received'])
    ? date('d-m-Y', strtotime($job['date_received']))
    : date('d-m-Y');

// Set page title and subtitle for the shared header
$page_title = "Job Card #" . htmlspecialchars($job['job_number']);
$page_subtitle = "Vehicle Repair Order";

// Include the shared header (contains DOCTYPE, head, body start, watermark, and unified header)
include 'header.php';
?>

<!-- Job Card Main Content -->
<div class="job-card" style="max-width:1200px; margin:0 auto 2rem auto; background:white; border-radius:24px; box-shadow:0 8px 30px rgba(0,0,0,0.1); overflow:hidden;">
    <!-- Toolbar (screen only) -->
    <div class="toolbar no-print" style="background:linear-gradient(135deg, #2563eb, #1e3a8a); padding:1rem 2rem; display:flex; gap:1rem; flex-wrap:wrap; align-items:center;">
        <button class="print-btn" onclick="window.print()" style="background:#3b82f6; border:none; color:white; padding:0.5rem 1.2rem; border-radius:8px; font-weight:600; cursor:pointer; display:inline-flex; align-items:center; gap:0.5rem;"><i class="fas fa-print"></i> Print Job Card</button>
        <a href="job_cards.php" style="background:#2c3e50; border:none; color:white; padding:0.5rem 1.2rem; border-radius:8px; font-weight:600; text-decoration:none; display:inline-flex; align-items:center; gap:0.5rem;"><i class="fas fa-list"></i> Back to List</a>
    </div>

    <div class="quote-content" style="padding:2rem; position:relative;">
        <!-- Fuel warning -->
        <div class="fuel-warning" style="background:#fef9e6; border-left:5px solid #f59e0b; padding:12px 20px; margin-bottom:20px; border-radius:16px; display:flex; align-items:center; gap:12px;">
            <i class="fas fa-gas-pump"></i>
            <span>Please make sure that your Vehicle has a minimum of a quarter tank of Fuel, otherwise it will affect the smooth running of repairs.</span>
        </div>

        <!-- CUSTOMER INFORMATION -->
        <div class="customer-info-section" style="background:#f8fafc; border-radius:16px; border:1px solid #3b82f6; padding:1rem; margin-bottom:20px;">
            <div class="section-title-modern" style="font-size:16px; font-weight:700; color:#1e293b; margin-bottom:16px; padding-bottom:8px; border-bottom:2px solid #e2e8f0; display:flex; align-items:center; gap:8px;">
                <i class="fas fa-user"></i> CUSTOMER INFORMATION
            </div>

            <!-- Customer details in table form -->
            <table class="customer-info-table" style="width:100%; border-collapse:collapse; margin-bottom:16px;">
                <tr>
                    <td style="padding:9px 12px; border:1px solid #e2e8f0; font-weight:600; background:#f1f5f9; width:160px; font-size:13px;">Name of Vehicle Owner</td>
                    <td style="padding:9px 12px; border:1px solid #e2e8f0; font-size:13px;"><strong><?php echo htmlspecialchars($job['customer_name'] ?? 'N/A'); ?></strong></td>
                </tr>
                <tr>
                    <td style="padding:9px 12px; border:1px solid #e2e8f0; font-weight:600; background:#f1f5f9; font-size:13px;">Telephone</td>
                    <td style="padding:9px 12px; border:1px solid #e2e8f0; font-size:13px;"><?php echo htmlspecialchars($job['customer_phone'] ?? '—'); ?></td>
                </tr>
                <tr>
                    <td style="padding:9px 12px; border:1px solid #e2e8f0; font-weight:600; background:#f1f5f9; font-size:13px;">Email</td>
                    <td style="padding:9px 12px; border:1px solid #e2e8f0; font-size:13px;"><?php echo htmlspecialchars($job['customer_email'] ?? '—'); ?></td>
                </tr>
                <tr>
                    <td style="padding:9px 12px; border:1px solid #e2e8f0; font-weight:600; background:#f1f5f9; font-size:13px;">Address</td>
                    <td style="padding:9px 12px; border:1px solid #e2e8f0; font-size:13px;"><?php echo htmlspecialchars($job['customer_address'] ?? '—'); ?></td>
                </tr>
            </table>

            <!-- Date -->
            <div class="info-row" style="display:flex; margin-bottom:12px; align-items:center;">
                <div class="info-label" style="width:140px; font-weight:600; color:#475569;">Date:</div>
                <div class="info-value" style="flex:1;"><span class="print-val" style="padding:8px 12px; border:1px solid #e2e8f0; border-radius:10px; background:#f9fafb; display:inline-block;"><?php echo $current_date; ?></span></div>
            </div>

            <!-- Vehicle details -->
            <div class="two-columns" style="display:grid; grid-template-columns:1fr 1fr; gap:20px; margin-bottom:15px;">
                <div class="info-row" style="display:flex; margin-bottom:12px; align-items:center;">
                    <div class="info-label" style="width:140px; font-weight:600; color:#475569;">Model:</div>
                    <div class="info-value" style="flex:1;"><span class="print-val" style="padding:8px 12px; border:1px solid #e2e8f0; border-radius:10px; background:#f9fafb; display:inline-block;"><?php echo htmlspecialchars($job['vehicle_model'] ?? ''); ?></span></div>
                </div>
                <div class="info-row" style="display:flex; margin-bottom:12px; align-items:center;">
                    <div class="info-label" style="width:140px; font-weight:600; color:#475569;">Chassis No.:</div>
                    <div class="info-value" style="flex:1;"><span class="print-val" style="padding:8px 12px; border:1px solid #e2e8f0; border-radius:10px; background:#f9fafb; display:inline-block;"><?php echo htmlspecialchars($job['chassis_no'] ?? ''); ?></span></div>
                </div>
            </div>
            <div class="two-columns" style="display:grid; grid-template-columns:1fr 1fr; gap:20px; margin-bottom:15px;">
                <div class="info-row" style="display:flex; margin-bottom:12px; align-items:center;">
                    <div class="info-label" style="width:140px; font-weight:600; color:#475569;">Reg. No.:</div>
                    <div class="info-value" style="flex:1;"><span class="print-val" style="padding:8px 12px; border:1px solid #e2e8f0; border-radius:10px; background:#f9fafb; display:inline-block;"><?php echo htmlspecialchars($job['vehicle_reg'] ?? ''); ?></span></div>
                </div>
                <div class="info-row" style="display:flex; margin-bottom:12px; align-items:center;">
                    <div class="info-label" style="width:140px; font-weight:600; color:#475569;">ODO Reading:</div>
                    <div class="info-value" style="flex:1;"><span class="print-val" style="padding:8px 12px; border:1px solid #e2e8f0; border-radius:10px; background:#f9fafb; display:inline-block;"><?php echo htmlspecialchars($job['odometer_reading'] ?? ''); ?></span></div>
                </div>
            </div>
            <div class="info-row" style="display:flex; margin-bottom:12px; align-items:center;">
                <div class="info-label" style="width:140px; font-weight:600; color:#475569;">Received by:</div>
                <div class="info-value" style="flex:1;"><span class="print-val" style="padding:8px 12px; border:1px solid #e2e8f0; border-radius:10px; background:#f9fafb; display:inline-block;"><?php echo htmlspecialchars($job['received_by'] ?? ''); ?></span></div>
            </div>
        </div>

        <!-- WORK TO BE DONE -->
        <div class="work-table" style="margin-bottom:20px;">
            <div class="section-title-modern" style="font-size:16px; font-weight:700; color:#1e293b; margin-bottom:16px; padding-bottom:8px; border-bottom:2px solid #e2e8f0; display:flex; align-items:center; gap:8px;">
                <i class="fas fa-tools"></i> WORK TO BE DONE
            </div>
            <table class="items-table" style="width:100%; border-collapse:collapse; margin:1rem 0; font-size:13px;">
                <thead>
                    <tr>
                        <th style="border:1px solid #e2e8f0; padding:10px; background:#3b82f6; color:white; width:50px;">No.</th>
                        <th style="border:1px solid #e2e8f0; padding:10px; background:#3b82f6; color:white;">Description / Customer Complaint</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (!empty($work_items)): ?>
                        <?php foreach ($work_items as $idx => $wi): ?>
                        <tr>
                            <td style="border:1px solid #e2e8f0; padding:10px; text-align:center;"><?php echo $idx + 1; ?></td>
                            <td style="border:1px solid #e2e8f0; padding:10px;"><?php echo nl2br(htmlspecialchars($wi['description'] ?? '')); ?></td>
                        </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr><td style="border:1px solid #e2e8f0; padding:10px; text-align:center;">1</td><td style="border:1px solid #e2e8f0; padding:10px;">&nbsp;</td></tr>
                        <tr><td style="border:1px solid #e2e8f0; padding:10px; text-align:center;">2</td><td style="border:1px solid #e2e8f0; padding:10px;">&nbsp;</td></tr>
                        <tr><td style="border:1px solid #e2e8f0; padding:10px; text-align:center;">3</td><td style="border:1px solid #e2e8f0; padding:10px;">&nbsp;</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <!-- SECURITY NOTICE — only shown when notes are filled in -->
        <?php if (!empty(trim($job['notes'] ?? ''))): ?>
        <div class="notes-section" style="margin-bottom:20px;">
            <div class="notes-box" style="background:#fff7ed; border-left:4px solid #f59e0b; border-radius:12px; padding:12px 16px; display:flex; align-items:flex-start; gap:10px;">
                <i class="fas fa-shield-alt" style="margin-top:2px; flex-shrink:0;"></i>
                <div>
                    <div style="font-weight:700; font-size:12px; text-transform:uppercase; color:#92400e; margin-bottom:4px;">Notes</div>
                    <div style="font-size:13px;"><?php echo nl2br(htmlspecialchars($job['notes'])); ?></div>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- INSPECTION SECTIONS -->
        <?php
        function renderConditionSelect($value) {
            $val = htmlspecialchars($value ?? '');
            $cls = '';
            switch (strtolower($val)) {
                case 'good':      $cls = 'val-good'; break;
                case 'scratched': $cls = 'val-scratched'; break;
                case 'dented':    $cls = 'val-dented'; break;
                case 'cracked':   $cls = 'val-cracked'; break;
                case 'missing':   $cls = 'val-missing'; break;
                case 'on':        $cls = 'val-on'; break;
                case 'off':       $cls = 'val-off'; break;
                case 'present':   $cls = 'val-present'; break;
            }
            echo '<span class="condition-select ' . $cls . '" style="padding:3px 8px; border-radius:20px; border:1px solid #cbd5e1; font-size:12px; background:white; font-weight:600;">' . ($val ?: '—') . '</span>';
        }
        ?>

        <!-- Basic Inspection -->
        <div class="inspection-section" style="margin-bottom:24px; background:white; border:1px solid #e2e8f0; border-radius:20px; padding:16px;">
            <div class="inspection-title" style="font-weight:700; margin-bottom:16px; background:#f8fafc; padding:10px 16px; border-radius:12px; display:flex; justify-content:space-between;">
                <div class="inspection-title-left" style="display:flex; align-items:center; gap:8px;"><i class="fas fa-clipboard-check"></i> VEHICLE INCOMING INSPECTION (Basic Items)</div>
            </div>
            <div class="checklist-grid" style="display:flex; flex-wrap:wrap; gap:16px 24px; margin-bottom:16px;">
                <div class="checklist-item" style="display:flex; align-items:center; gap:8px; background:#f1f5f9; padding:6px 14px; border-radius:40px;"><label>Wheel Spanner</label><?php renderConditionSelect($inspection_data['wheel_spanner'] ?? ''); ?></div>
                <div class="checklist-item" style="display:flex; align-items:center; gap:8px; background:#f1f5f9; padding:6px 14px; border-radius:40px;"><label>Car Jack</label><?php renderConditionSelect($inspection_data['car_jack'] ?? ''); ?></div>
                <div class="checklist-item" style="display:flex; align-items:center; gap:8px; background:#f1f5f9; padding:6px 14px; border-radius:40px;"><label>Special Nut</label><?php renderConditionSelect($inspection_data['special_nut'] ?? ''); ?></div>
                <div class="checklist-item" style="display:flex; align-items:center; gap:8px; background:#f1f5f9; padding:6px 14px; border-radius:40px;"><label>Reflector</label><?php renderConditionSelect($inspection_data['reflector'] ?? ''); ?></div>
                <div class="checklist-item" style="display:flex; align-items:center; gap:8px; background:#f1f5f9; padding:6px 14px; border-radius:40px;"><label>Engine Check Light</label><?php renderConditionSelect($inspection_data['engine_check_light'] ?? ''); ?></div>
                <div class="checklist-item" style="display:flex; align-items:center; gap:8px; background:#f1f5f9; padding:6px 14px; border-radius:40px;"><label>Radio</label><?php renderConditionSelect($inspection_data['radio'] ?? ''); ?></div>
                <div class="checklist-item" style="display:flex; align-items:center; gap:8px; background:#f1f5f9; padding:6px 14px; border-radius:40px;"><label>AC</label><?php renderConditionSelect($inspection_data['ac'] ?? ''); ?></div>
                <div class="checklist-item" style="display:flex; align-items:center; gap:8px; background:#f1f5f9; padding:6px 14px; border-radius:40px;"><label>Fuel Level Indicator</label><?php renderConditionSelect($inspection_data['fuel_level_indicator'] ?? ''); ?></div>
            </div>
            <?php if (!empty($inspection_data['basic_notes'])): ?>
            <div class="inspection-notes"><div class="print-notes" style="width:100%; padding:10px 14px; border:1px solid #e2e8f0; border-radius:16px; background:#fefce8;"><?php echo htmlspecialchars($inspection_data['basic_notes']); ?></div></div>
            <?php endif; ?>
        </div>

        <!-- Front Inspection -->
        <div class="inspection-section" style="margin-bottom:24px; background:white; border:1px solid #e2e8f0; border-radius:20px; padding:16px;">
            <div class="inspection-title" style="font-weight:700; margin-bottom:16px; background:#f8fafc; padding:10px 16px; border-radius:12px;"><div class="inspection-title-left" style="display:flex; align-items:center; gap:8px;"><i class="fas fa-car"></i> FRONT INSPECTION</div></div>
            <div class="checklist-grid" style="display:flex; flex-wrap:wrap; gap:16px 24px; margin-bottom:16px;">
                <div class="checklist-item" style="display:flex; align-items:center; gap:8px; background:#f1f5f9; padding:6px 14px; border-radius:40px;"><label>Front Bumper</label><?php renderConditionSelect($inspection_data['front_bumper'] ?? ''); ?></div>
                <div class="checklist-item" style="display:flex; align-items:center; gap:8px; background:#f1f5f9; padding:6px 14px; border-radius:40px;"><label>Front Grill</label><?php renderConditionSelect($inspection_data['front_grill'] ?? ''); ?></div>
                <div class="checklist-item" style="display:flex; align-items:center; gap:8px; background:#f1f5f9; padding:6px 14px; border-radius:40px;"><label>Headlights</label><?php renderConditionSelect($inspection_data['headlights'] ?? ''); ?></div>
                <div class="checklist-item" style="display:flex; align-items:center; gap:8px; background:#f1f5f9; padding:6px 14px; border-radius:40px;"><label>Fog Lights</label><?php renderConditionSelect($inspection_data['fog_lights'] ?? ''); ?></div>
                <div class="checklist-item" style="display:flex; align-items:center; gap:8px; background:#f1f5f9; padding:6px 14px; border-radius:40px;"><label>Windshield</label><?php renderConditionSelect($inspection_data['windshield'] ?? ''); ?></div>
                <div class="checklist-item" style="display:flex; align-items:center; gap:8px; background:#f1f5f9; padding:6px 14px; border-radius:40px;"><label>Windshield Wipers</label><?php renderConditionSelect($inspection_data['windshield_wipers'] ?? ''); ?></div>
            </div>
            <?php if (!empty($inspection_data['front_notes'])): ?>
            <div class="inspection-notes"><div class="print-notes" style="width:100%; padding:10px 14px; border:1px solid #e2e8f0; border-radius:16px; background:#fefce8;"><?php echo htmlspecialchars($inspection_data['front_notes']); ?></div></div>
            <?php endif; ?>
        </div>

        <!-- Rear Inspection -->
        <div class="inspection-section" style="margin-bottom:24px; background:white; border:1px solid #e2e8f0; border-radius:20px; padding:16px;">
            <div class="inspection-title" style="font-weight:700; margin-bottom:16px; background:#f8fafc; padding:10px 16px; border-radius:12px;"><div class="inspection-title-left" style="display:flex; align-items:center; gap:8px;"><i class="fas fa-car-rear"></i> REAR INSPECTION</div></div>
            <div class="checklist-grid" style="display:flex; flex-wrap:wrap; gap:16px 24px; margin-bottom:16px;">
                <div class="checklist-item" style="display:flex; align-items:center; gap:8px; background:#f1f5f9; padding:6px 14px; border-radius:40px;"><label>Rear Bumper</label><?php renderConditionSelect($inspection_data['rear_bumper'] ?? ''); ?></div>
                <div class="checklist-item" style="display:flex; align-items:center; gap:8px; background:#f1f5f9; padding:6px 14px; border-radius:40px;"><label>Tail Lights</label><?php renderConditionSelect($inspection_data['tail_lights'] ?? ''); ?></div>
                <div class="checklist-item" style="display:flex; align-items:center; gap:8px; background:#f1f5f9; padding:6px 14px; border-radius:40px;"><label>Rear Fog Lights</label><?php renderConditionSelect($inspection_data['rear_fog_lights'] ?? ''); ?></div>
                <div class="checklist-item" style="display:flex; align-items:center; gap:8px; background:#f1f5f9; padding:6px 14px; border-radius:40px;"><label>Rear Windshield</label><?php renderConditionSelect($inspection_data['rear_windshield'] ?? ''); ?></div>
                <div class="checklist-item" style="display:flex; align-items:center; gap:8px; background:#f1f5f9; padding:6px 14px; border-radius:40px;"><label>Rear Wiper</label><?php renderConditionSelect($inspection_data['rear_wiper'] ?? ''); ?></div>
                <div class="checklist-item" style="display:flex; align-items:center; gap:8px; background:#f1f5f9; padding:6px 14px; border-radius:40px;"><label>Boot Lid / Trunk</label><?php renderConditionSelect($inspection_data['boot_lid'] ?? ''); ?></div>
            </div>
            <?php if (!empty($inspection_data['rear_notes'])): ?>
            <div class="inspection-notes"><div class="print-notes" style="width:100%; padding:10px 14px; border:1px solid #e2e8f0; border-radius:16px; background:#fefce8;"><?php echo htmlspecialchars($inspection_data['rear_notes']); ?></div></div>
            <?php endif; ?>
        </div>

        <!-- Left Side Inspection -->
        <div class="inspection-section" style="margin-bottom:24px; background:white; border:1px solid #e2e8f0; border-radius:20px; padding:16px;">
            <div class="inspection-title" style="font-weight:700; margin-bottom:16px; background:#f8fafc; padding:10px 16px; border-radius:12px;"><div class="inspection-title-left" style="display:flex; align-items:center; gap:8px;"><i class="fas fa-arrow-left"></i> LEFT SIDE INSPECTION</div></div>
            <div class="checklist-grid" style="display:flex; flex-wrap:wrap; gap:16px 24px; margin-bottom:16px;">
                <div class="checklist-item" style="display:flex; align-items:center; gap:8px; background:#f1f5f9; padding:6px 14px; border-radius:40px;"><label>Front Door</label><?php renderConditionSelect($inspection_data['left_front_door'] ?? ''); ?></div>
                <div class="checklist-item" style="display:flex; align-items:center; gap:8px; background:#f1f5f9; padding:6px 14px; border-radius:40px;"><label>Rear Door</label><?php renderConditionSelect($inspection_data['left_rear_door'] ?? ''); ?></div>
                <div class="checklist-item" style="display:flex; align-items:center; gap:8px; background:#f1f5f9; padding:6px 14px; border-radius:40px;"><label>Side Mirror</label><?php renderConditionSelect($inspection_data['left_mirror'] ?? ''); ?></div>
                <div class="checklist-item" style="display:flex; align-items:center; gap:8px; background:#f1f5f9; padding:6px 14px; border-radius:40px;"><label>Side Molding</label><?php renderConditionSelect($inspection_data['left_side_molding'] ?? ''); ?></div>
                <div class="checklist-item" style="display:flex; align-items:center; gap:8px; background:#f1f5f9; padding:6px 14px; border-radius:40px;"><label>Side Glass</label><?php renderConditionSelect($inspection_data['left_side_glass'] ?? ''); ?></div>
            </div>
            <?php if (!empty($inspection_data['left_notes'])): ?>
            <div class="inspection-notes"><div class="print-notes" style="width:100%; padding:10px 14px; border:1px solid #e2e8f0; border-radius:16px; background:#fefce8;"><?php echo htmlspecialchars($inspection_data['left_notes']); ?></div></div>
            <?php endif; ?>
        </div>

        <!-- Right Side Inspection -->
        <div class="inspection-section" style="margin-bottom:24px; background:white; border:1px solid #e2e8f0; border-radius:20px; padding:16px;">
            <div class="inspection-title" style="font-weight:700; margin-bottom:16px; background:#f8fafc; padding:10px 16px; border-radius:12px;"><div class="inspection-title-left" style="display:flex; align-items:center; gap:8px;"><i class="fas fa-arrow-right"></i> RIGHT SIDE INSPECTION</div></div>
            <div class="checklist-grid" style="display:flex; flex-wrap:wrap; gap:16px 24px; margin-bottom:16px;">
                <div class="checklist-item" style="display:flex; align-items:center; gap:8px; background:#f1f5f9; padding:6px 14px; border-radius:40px;"><label>Front Door</label><?php renderConditionSelect($inspection_data['right_front_door'] ?? ''); ?></div>
                <div class="checklist-item" style="display:flex; align-items:center; gap:8px; background:#f1f5f9; padding:6px 14px; border-radius:40px;"><label>Rear Door</label><?php renderConditionSelect($inspection_data['right_rear_door'] ?? ''); ?></div>
                <div class="checklist-item" style="display:flex; align-items:center; gap:8px; background:#f1f5f9; padding:6px 14px; border-radius:40px;"><label>Side Mirror</label><?php renderConditionSelect($inspection_data['right_mirror'] ?? ''); ?></div>
                <div class="checklist-item" style="display:flex; align-items:center; gap:8px; background:#f1f5f9; padding:6px 14px; border-radius:40px;"><label>Side Molding</label><?php renderConditionSelect($inspection_data['right_side_molding'] ?? ''); ?></div>
                <div class="checklist-item" style="display:flex; align-items:center; gap:8px; background:#f1f5f9; padding:6px 14px; border-radius:40px;"><label>Side Glass</label><?php renderConditionSelect($inspection_data['right_side_glass'] ?? ''); ?></div>
            </div>
            <?php if (!empty($inspection_data['right_notes'])): ?>
            <div class="inspection-notes"><div class="print-notes" style="width:100%; padding:10px 14px; border:1px solid #e2e8f0; border-radius:16px; background:#fefce8;"><?php echo htmlspecialchars($inspection_data['right_notes']); ?></div></div>
            <?php endif; ?>
        </div>

        <!-- Top View Inspection -->
        <div class="inspection-section" style="margin-bottom:24px; background:white; border:1px solid #e2e8f0; border-radius:20px; padding:16px;">
            <div class="inspection-title" style="font-weight:700; margin-bottom:16px; background:#f8fafc; padding:10px 16px; border-radius:12px;"><div class="inspection-title-left" style="display:flex; align-items:center; gap:8px;"><i class="fas fa-arrow-up"></i> TOP VIEW INSPECTION</div></div>
            <div class="checklist-grid" style="display:flex; flex-wrap:wrap; gap:16px 24px; margin-bottom:16px;">
                <div class="checklist-item" style="display:flex; align-items:center; gap:8px; background:#f1f5f9; padding:6px 14px; border-radius:40px;"><label>Roof Condition</label><?php renderConditionSelect($inspection_data['roof_condition'] ?? ''); ?></div>
                <div class="checklist-item" style="display:flex; align-items:center; gap:8px; background:#f1f5f9; padding:6px 14px; border-radius:40px;"><label>Sunroof / Moonroof</label><?php renderConditionSelect($inspection_data['sunroof'] ?? ''); ?></div>
                <div class="checklist-item" style="display:flex; align-items:center; gap:8px; background:#f1f5f9; padding:6px 14px; border-radius:40px;"><label>Roof Rails</label><?php renderConditionSelect($inspection_data['roof_rails'] ?? ''); ?></div>
                <div class="checklist-item" style="display:flex; align-items:center; gap:8px; background:#f1f5f9; padding:6px 14px; border-radius:40px;"><label>Aerial / Antenna</label><?php renderConditionSelect($inspection_data['aerial_antenna'] ?? ''); ?></div>
            </div>
            <?php if (!empty($inspection_data['top_notes'])): ?>
            <div class="inspection-notes"><div class="print-notes" style="width:100%; padding:10px 14px; border:1px solid #e2e8f0; border-radius:16px; background:#fefce8;"><?php echo htmlspecialchars($inspection_data['top_notes']); ?></div></div>
            <?php endif; ?>
        </div>

        <!-- Fuel Level -->
        <div class="inspection-section" style="margin-bottom:24px; background:white; border:1px solid #e2e8f0; border-radius:20px; padding:16px;">
            <div class="inspection-title" style="font-weight:700; margin-bottom:16px; background:#f8fafc; padding:10px 16px; border-radius:12px;"><div class="inspection-title-left" style="display:flex; align-items:center; gap:8px;"><i class="fas fa-gas-pump"></i> FUEL LEVEL</div></div>
            <div style="padding:8px 0;">
                <span class="condition-select" style="padding:6px 14px; border-radius:40px; border:1px solid #cbd5e1; font-weight:600;">
                    ⛽ <?php echo htmlspecialchars($inspection_data['fuel_level'] ?? $job['fuel_level'] ?? 'Not recorded'); ?>
                </span>
            </div>
        </div>

        <!-- TERMS AND CONDITIONS -->
        <div class="terms-section" style="background:#eff6ff; border-left:4px solid #2563eb; padding:1rem; margin:1.5rem 0; border-radius:8px;">
            <div class="terms-header" style="font-size:16px; font-weight:700; margin-bottom:12px; display:flex; align-items:center; gap:8px;"><i class="fas fa-file-contract"></i> TERMS AND CONDITIONS</div>
            <div class="terms-grid" style="display:grid; grid-template-columns:repeat(2,1fr); gap:12px 25px;">
                <div class="term-item" style="display:flex; gap:10px; font-size:12px; padding:6px 0; border-bottom:1px dashed #e2e8f0;"><div class="term-number" style="font-weight:800; color:#f59e0b; min-width:24px;">1.</div><div class="term-text"><strong>UNSPECIFIED WORK:</strong> Only repairs set out overleaf will be carried out. Any other defects discovered will be drawn to your attention.</div></div>
                <div class="term-item" style="display:flex; gap:10px; font-size:12px; padding:6px 0; border-bottom:1px dashed #e2e8f0;"><div class="term-number" style="font-weight:800; color:#f59e0b; min-width:24px;">2.</div><div class="term-text"><strong>REPAIR ESTIMATES:</strong> All estimates are based on prevailing labour rate and parts/material prices at the time repairs are carried out.</div></div>
                <div class="term-item" style="display:flex; gap:10px; font-size:12px; padding:6px 0; border-bottom:1px dashed #e2e8f0;"><div class="term-number" style="font-weight:800; color:#f59e0b; min-width:24px;">3.</div><div class="term-text"><strong>STORAGE CHARGES:</strong> Storage charges of UGX 10,000 per day apply from 3 days after completion notification.</div></div>
                <div class="term-item" style="display:flex; gap:10px; font-size:12px; padding:6px 0; border-bottom:1px dashed #e2e8f0;"><div class="term-number" style="font-weight:800; color:#f59e0b; min-width:24px;">4.</div><div class="term-text"><strong>UNCOLLECTED GOODS:</strong> All items accepted fall under the UNCOLLECTED GOODS ACT (1952).</div></div>
                <div class="term-item" style="display:flex; gap:10px; font-size:12px; padding:6px 0; border-bottom:1px dashed #e2e8f0;"><div class="term-number" style="font-weight:800; color:#f59e0b; min-width:24px;">5.</div><div class="term-text"><strong>GUARANTEE:</strong> The Company accepts no liability for fault or defective workmanship once the vehicle has been taken away.</div></div>
                <div class="term-item" style="display:flex; gap:10px; font-size:12px; padding:6px 0; border-bottom:1px dashed #e2e8f0;"><div class="term-number" style="font-weight:800; color:#f59e0b; min-width:24px;">6.</div><div class="term-text"><strong>UNSERVICEABLE PARTS:</strong> Unserviceable parts will be disposed of unless claimed within hours of completion.</div></div>
                <div class="term-item" style="display:flex; gap:10px; font-size:12px; padding:6px 0; border-bottom:1px dashed #e2e8f0;"><div class="term-number" style="font-weight:800; color:#f59e0b; min-width:24px;">7.</div><div class="term-text"><strong>QUERIES:</strong> Queries on invoices will not be entertained if not received within 24 hours after invoice issue date.</div></div>
                <div class="term-item" style="display:flex; gap:10px; font-size:12px; padding:6px 0; border-bottom:1px dashed #e2e8f0;"><div class="term-number" style="font-weight:800; color:#f59e0b; min-width:24px;">8.</div><div class="term-text"><strong>PENALTY FOR LATE PAYMENT:</strong> A penalty of 5% per month applies on outstanding amounts after thirty days from invoice date.</div></div>
                <div class="term-item" style="display:flex; gap:10px; font-size:12px; padding:6px 0; border-bottom:1px dashed #e2e8f0;"><div class="term-number" style="font-weight:800; color:#f59e0b; min-width:24px;">9.</div><div class="term-text"><strong>PICKUP/DELIVERY:</strong> Please note that the workshop cannot assume responsibility for any accidents or incidents that may occur during the pickup or delivery process.</div></div>
            </div>
            <div class="authorization-text" style="margin-top:15px; padding:12px; border-top:1px solid #e2e8f0; font-size:12px; font-weight:500; text-align:center; background:white; border-radius:12px;">
                <i class="fas fa-check-circle"></i> I authorize the above repair work and confirm the vehicle incoming inspection &amp; security check are agreed to your terms and conditions.
            </div>
        </div>

        <!-- SIGNATURE SECTION -->
        <div class="signature-section" style="padding:20px 0; background:white; display:flex; justify-content:space-between; gap:30px; border-top:1px solid #e2e8f0;">
            <div class="signature-box" style="flex:1; text-align:center;">
                <div class="signature-line" style="border-top:1px solid #4b5563; margin-top:20px; padding-top:8px; font-size:11px;">_________________________</div>
                <div>Brought by (Print Name): <span class="signature-val" style="border:none; border-bottom:1px solid #cbd5e1; background:transparent; text-align:center; display:inline-block; width:80%; padding:6px;"><?php echo htmlspecialchars($job['brought_by'] ?? ''); ?></span></div>
            </div>
            <div class="signature-box" style="flex:1; text-align:center;">
                <div class="signature-line" style="border-top:1px solid #4b5563; margin-top:20px; padding-top:8px; font-size:11px;">_________________________</div>
                <div>Signed:</div>
            </div>
        </div>

        <!-- Footer -->
        <div class="footer" style="text-align:center; padding:1rem; font-size:12px; color:white; background:#1e3a8a; margin-top:20px; border-radius:0 0 24px 24px;">
            <i class="fas fa-charging-station"></i> Savant Motors - Quality Service You Can Trust | Since 2018
        </div>

        <!-- Screen-only print bar -->
        <div class="print-bar no-print" style="background:#f8fafc; border-top:1px solid #e2e8f0; padding:16px 24px; display:flex; justify-content:flex-end; gap:12px;">
            <a href="job_cards.php" class="btn btn-secondary" style="background:#64748b; color:white; padding:10px 24px; border-radius:40px; text-decoration:none; display:inline-flex; align-items:center; gap:8px;"><i class="fas fa-arrow-left"></i> Back to List</a>
            <button class="btn btn-primary" onclick="window.print()" style="background:#2563eb; color:white; padding:10px 24px; border-radius:40px; border:none; display:inline-flex; align-items:center; gap:8px; cursor:pointer;"><i class="fas fa-print"></i> Print Job Card</button>
        </div>
    </div>
</div>

<!-- Additional print styling to ensure header and job card work well together + force content to fit on 2 pages -->
<style media="print">
    /* Existing print adjustments (preserved) */
    body {
        padding: 0 !important;
        margin: 0 !important;
    }
    .job-card {
        margin-top: 5px !important;
        box-shadow: none !important;
    }
    .toolbar, .print-bar {
        display: none !important;
    }
    /* Watermark only on first page */
    .watermark {
        position: absolute !important;
        top: 20px !important;
        right: 20px !important;
        width: 15cm;
        opacity: 0.07;
    }

    /* ===== ADDED: Force print to fit on exactly 2 pages ===== */
    /* Reduce overall margins and spacing */
    @page {
        margin: 0.3in 0.2in !important;
        size: A4;
    }
    body {
        font-size: 9.5pt !important;
        line-height: 1.3 !important;
    }
    /* Compress header area */
    .unified-header {
        padding: 8px 15px !important;
        margin-bottom: 5px !important;
    }
    .logo-img {
        max-height: 50px !important;
        width: auto !important;
    }
    .company-details h2 {
        font-size: 12pt !important;
    }
    .company-details p {
        font-size: 7pt !important;
    }
    .header-right h3 {
        font-size: 12pt !important;
    }
    /* Reduce padding inside job card */
    .quote-content {
        padding: 0.8rem !important;
    }
    .customer-info-section, .inspection-section {
        padding: 0.6rem !important;
        margin-bottom: 10px !important;
    }
    .customer-info-table td {
        padding: 5px 8px !important;
        font-size: 8.5pt !important;
    }
    .customer-info-table td:first-child {
        width: 130px !important;
    }
    .section-title-modern {
        font-size: 11pt !important;
        margin-bottom: 8px !important;
        padding-bottom: 3px !important;
    }
    .info-row {
        margin-bottom: 4px !important;
    }
    .info-label {
        width: 110px !important;
        font-size: 8pt !important;
    }
    .print-val, .info-value span {
        padding: 4px 6px !important;
        font-size: 8pt !important;
    }
    .items-table th, .items-table td {
        padding: 4px 6px !important;
        font-size: 8pt !important;
    }
    .checklist-item {
        padding: 3px 8px !important;
        font-size: 7.5pt !important;
    }
    .condition-select {
        font-size: 7.5pt !important;
        padding: 2px 5px !important;
    }
    .terms-section {
        margin: 8px 0 !important;
        padding: 0.5rem !important;
    }
    .terms-header {
        font-size: 10pt !important;
        margin-bottom: 5px !important;
    }
    .term-item {
        font-size: 6.5pt !important;
        padding: 2px 0 !important;
    }
    .signature-section {
        padding: 8px 0 !important;
    }
    .footer {
        padding: 0.4rem !important;
        font-size: 7pt !important;
        margin-top: 8px !important;
    }
    /* Prevent large empty spaces and allow breaking inside sections if needed */
    .inspection-section, .terms-section, .signature-section {
        page-break-inside: avoid;
    }
    /* Ensure the work table doesn't break awkwardly */
    .work-table {
        page-break-inside: avoid;
    }
    /* Small reduction for fuel warning */
    .fuel-warning {
        padding: 6px 12px !important;
        margin-bottom: 8px !important;
        font-size: 8pt !important;
    }
    /* Allow the whole job card to break gracefully across pages, but keep sections together */
    .job-card {
        page-break-inside: auto;
    }
</style>

</body>
</html>