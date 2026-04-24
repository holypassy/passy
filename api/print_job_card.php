<?php
session_start();
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../models/JobCard.php';
require_once __DIR__ . '/../models/JobCardItem.php';
require_once __DIR__ . '/../helpers/Format.php';

if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    header('Location: index.php');
    exit();
}

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if (!$id) {
    die('Invalid job card ID');
}

$jobCardModel = new JobCard();
$jobItemModel = new JobCardItem();

$jobCard = $jobCardModel->findById($id);
if (!$jobCard) {
    die('Job card not found');
}

$items = $jobItemModel->getByJobId($id);
$total = $jobItemModel->getTotalByJobId($id);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Job Card #<?php echo htmlspecialchars($jobCard['job_number']); ?> - Savant Motors</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        @media print {
            .no-print { display: none; }
            body { padding: 0; margin: 0; }
            .print-container { margin: 0; padding: 20px; }
        }
        
        body {
            font-family: 'Inter', sans-serif;
            background: #f5f7fc;
            padding: 20px;
        }
        
        .print-container {
            max-width: 1100px;
            margin: 0 auto;
            background: white;
            border-radius: 16px;
            box-shadow: 0 20px 40px rgba(0,0,0,0.1);
            overflow: hidden;
        }
        
        .header {
            background: linear-gradient(135deg, #2563eb, #7c3aed);
            color: white;
            padding: 30px;
            text-align: center;
        }
        
        .header h1 {
            font-size: 28px;
            margin-bottom: 5px;
        }
        
        .header p {
            opacity: 0.9;
        }
        
        .content {
            padding: 30px;
        }
        
        .job-info {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 20px;
            margin-bottom: 30px;
            padding-bottom: 20px;
            border-bottom: 2px solid #e2e8f0;
        }
        
        .info-group {
            margin-bottom: 15px;
        }
        
        .info-label {
            font-size: 12px;
            font-weight: 600;
            color: #64748b;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        
        .info-value {
            font-size: 16px;
            font-weight: 600;
            color: #0f172a;
            margin-top: 5px;
        }
        
        .status-badge {
            display: inline-block;
            padding: 6px 16px;
            border-radius: 30px;
            font-size: 12px;
            font-weight: 700;
        }
        
        .status-pending { background: #fed7aa; color: #9a3412; }
        .status-in_progress { background: #dbeafe; color: #1e40af; }
        .status-completed { background: #dcfce7; color: #166534; }
        .status-cancelled { background: #fee2e2; color: #991b1b; }
        
        table {
            width: 100%;
            border-collapse: collapse;
            margin: 20px 0;
        }
        
        th {
            background: #f8fafc;
            padding: 12px;
            text-align: left;
            font-size: 12px;
            font-weight: 700;
            color: #64748b;
            border-bottom: 2px solid #e2e8f0;
        }
        
        td {
            padding: 12px;
            border-bottom: 1px solid #e2e8f0;
        }
        
        .total-row {
            background: #f8fafc;
            font-weight: 700;
        }
        
        .footer {
            background: #f8fafc;
            padding: 20px 30px;
            text-align: center;
            font-size: 12px;
            color: #64748b;
            border-top: 1px solid #e2e8f0;
        }
        
        .signature-line {
            margin-top: 30px;
            padding-top: 20px;
            border-top: 1px dashed #e2e8f0;
            display: flex;
            justify-content: space-between;
        }
        
        .btn-print {
            position: fixed;
            bottom: 20px;
            right: 20px;
            background: linear-gradient(135deg, #2563eb, #7c3aed);
            color: white;
            border: none;
            padding: 12px 24px;
            border-radius: 12px;
            cursor: pointer;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 8px;
            box-shadow: 0 10px 20px rgba(0,0,0,0.2);
        }
        
        @media print {
            .btn-print { display: none; }
        }
    </style>
</head>
<body>
    <div class="print-container">
        <div class="header">
            <h1>SAVANT MOTORS UGANDA</h1>
            <p>Job Card / Work Order</p>
        </div>
        
        <div class="content">
            <div class="job-info">
                <div>
                    <div class="info-group">
                        <div class="info-label">Job Number</div>
                        <div class="info-value">#<?php echo htmlspecialchars($jobCard['job_number']); ?></div>
                    </div>
                    <div class="info-group">
                        <div class="info-label">Status</div>
                        <div class="info-value">
                            <span class="status-badge status-<?php echo $jobCard['status']; ?>">
                                <?php echo strtoupper(str_replace('_', ' ', $jobCard['status'])); ?>
                            </span>
                        </div>
                    </div>
                </div>
                <div>
                    <div class="info-group">
                        <div class="info-label">Date Received</div>
                        <div class="info-value"><?php echo date('d/m/Y', strtotime($jobCard['date_received'])); ?></div>
                    </div>
                    <?php if ($jobCard['date_promised']): ?>
                    <div class="info-group">
                        <div class="info-label">Date Promised</div>
                        <div class="info-value"><?php echo date('d/m/Y', strtotime($jobCard['date_promised'])); ?></div>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
            
            <div class="job-info">
                <div>
                    <div class="info-group">
                        <div class="info-label">Customer</div>
                        <div class="info-value"><?php echo htmlspecialchars($jobCard['customer_full_name']); ?></div>
                    </div>
                    <div class="info-group">
                        <div class="info-label">Contact</div>
                        <div class="info-value"><?php echo htmlspecialchars($jobCard['customer_phone'] ?? 'N/A'); ?></div>
                    </div>
                </div>
                <div>
                    <div class="info-group">
                        <div class="info-label">Vehicle</div>
                        <div class="info-value">
                            <?php echo htmlspecialchars($jobCard['vehicle_reg'] ?? 'N/A'); ?>
                            <?php if ($jobCard['vehicle_make']): ?> - <?php echo htmlspecialchars($jobCard['vehicle_make']); ?><?php endif; ?>
                            <?php if ($jobCard['vehicle_model']): ?> <?php echo htmlspecialchars($jobCard['vehicle_model']); ?><?php endif; ?>
                        </div>
                    </div>
                    <?php if ($jobCard['odometer_reading']): ?>
                    <div class="info-group">
                        <div class="info-label">Odometer</div>
                        <div class="info-value"><?php echo htmlspecialchars($jobCard['odometer_reading']); ?> km</div>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
            
            <h3>Job Items / Services</h3>
            <table>
                <thead>
                    <tr>
                        <th>Description</th>
                        <th>Quantity</th>
                        <th>Unit Price (UGX)</th>
                        <th>Total (UGX)</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($items)): ?>
                    <tr>
                        <td colspan="4" style="text-align: center;">No items added yet</td>
                    </tr>
                    <?php else: ?>
                        <?php foreach ($items as $item): ?>
                        <tr>
                            <td><?php echo htmlspecialchars($item['description']); ?></td>
                            <td><?php echo $item['quantity']; ?></td>
                            <td><?php echo number_format($item['unit_price']); ?></td>
                            <td><?php echo number_format($item['total']); ?></td>
                        </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
                <tfoot>
                    <tr class="total-row">
                        <td colspan="3" style="text-align: right;"><strong>TOTAL</strong></td>
                        <td><strong>UGX <?php echo number_format($total); ?></strong></td>
                    </tr>
                </tfoot>
            </table>
            
            <?php if ($jobCard['notes']): ?>
            <div class="info-group">
                <div class="info-label">Notes / Instructions</div>
                <div class="info-value"><?php echo nl2br(htmlspecialchars($jobCard['notes'])); ?></div>
            </div>
            <?php endif; ?>
            
            <div class="signature-line">
                <div>
                    <div>_________________________</div>
                    <div style="font-size: 11px; color: #64748b;">Customer Signature</div>
                </div>
                <div>
                    <div>_________________________</div>
                    <div style="font-size: 11px; color: #64748b;">Technician Signature</div>
                </div>
            </div>
        </div>
        
        <div class="footer">
            <p>SAVANT MOTORS UGANDA - Quality Service You Can Trust</p>
            <p>Tel: +256 123 456 789 | Email: service@savantmotors.com | Kampala, Uganda</p>
            <p>Printed on: <?php echo date('d/m/Y H:i:s'); ?></p>
        </div>
    </div>
    
    <button class="btn-print no-print" onclick="window.print()">
        <i class="fas fa-print"></i> Print Job Card
    </button>
</body>
</html>