<?php
/**
 * Shared Header for ERP Pages
 * Expects $page_title (string) and optionally $page_subtitle (string)
 * Must be included after session_start() but before any HTML output.
 */
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <!-- Common fonts and icons -->
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        /* Unified header and watermark styles – shared across all pages */
        .watermark {
            position: absolute;
            top: 20px;
            right: 20px;
            opacity: 0.2;
            z-index: 1000;
            pointer-events: none;
            width: 15cm;
            height: auto;
            text-align: right;
        }
        .watermark-logo {
            max-width: 100%;
            height: auto;
            border: none;
        }

        .unified-header {
            background: transparent;   /* No background – completely transparent */
            border-radius: 20px;
            padding: 20px 30px;
            margin-bottom: 20px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            box-shadow: none;
        }
        .header-left {
            display: flex;
            align-items: center;
            gap: 20px;
        }
        .logo-img {
            width: 10cm;
            height: auto;
            border-radius: 12px;
            background: rgb(255, 255, 255);
            padding: 5px;
        }
        .company-details h2 {
            font-size: 28px;
            font-weight: 800;
            letter-spacing: -0.5px;
            color: #0ea5e9;           /* Blue company name */
            margin: 0;
        }
        .company-details p {
            font-size: 14px;           /* Increased from 12px to 14px */
            margin-top: 4px;
            color: #dc2626;            /* Changed from #0ea5e9 to red */
        }
        .header-right {
            text-align: right;
        }
        .header-right h3 {
            font-size: 28px;
            font-weight: 800;
            background: transparent;    /* No background */
            padding: 6px 0;
            border-radius: 0;
            display: inline-block;
            margin: 0;
            color: #1e293b;            /* Dark text for page title */
        }
        .header-right .subtitle {
            font-size: 14px;
            margin-top: 8px;
            color: #334155;
        }

        @media print {
            .watermark {
                position: fixed;
                top: 20px;
                right: 20px;
                opacity: 0.2;
                width: 12cm;
            }
            .unified-header {
                background: transparent !important;
                padding: 8px 12px !important;
                margin: 0 0 10px 0 !important;
                border-radius: 0 !important;
            }
            .logo-img {
                max-height: 100px !important;
                width: auto !important;
            }
            .company-details h2 {
                font-size: 16pt !important;
                font-weight: 800 !important;
                color: #0ea5e9 !important;
            }
            .company-details p {
                font-size: 9pt !important;    /* Increased from 7pt to 9pt */
                color: #dc2626 !important;     /* Red in print as well */
            }
            .header-right h3 {
                font-size: 14pt !important;
                padding: 2px 0 !important;
                color: #1e293b !important;
            }
            .header-right .subtitle {
                font-size: 9pt !important;
                color: #334155 !important;
            }
        }
    </style>
</head>
<body>
    <!-- Fixed watermark top right -->
    <div class="watermark">
        <img src="images/watermark.jpeg" alt="Watermark" class="watermark-logo" 
             onerror="this.onerror=null; this.parentElement.innerHTML='<i class=\'fas fa-car\' style=\'font-size:48px; color:rgba(0,0,0,0.1);\'></i>';">
    </div>

    <!-- Unified header -->
    <div class="unified-header">
        <div class="header-left">
            <img class="logo-img" src="/savant/views/images/logo.jpeg" alt="Savant Motors Logo" 
                 onerror="this.onerror=null; this.src='data:image/svg+xml,%3Csvg xmlns=\'http://www.w3.org/2000/svg\' viewBox=\'0 0 100 100\'%3E%3Crect width=\'100\' height=\'100\' fill=\'%232c3e50\'/%3E%3Ctext x=\'50\' y=\'55\' font-size=\'40\' text-anchor=\'middle\' fill=\'%23fbbf24\' font-family=\'monospace\'%3ES%3C/text%3E%3C/svg%3E';">
            <div class="company-details">
                <h2>SAVANT MOTORS</h2>
                <p>Bugolobi, Bunyonyi Drive, Kampala, Uganda<br>Tel: +256 774 537 017 | +256 704 496 974 | +256 775 919 526<br>Email: rogersm2008@gmail.com</p>
            </div>
        </div>
        <div class="header-right">
            <h3><?php echo htmlspecialchars($page_title); ?></h3>
            <?php if (!empty($page_subtitle)): ?>
                <div class="subtitle"><?php echo htmlspecialchars($page_subtitle); ?></div>
            <?php endif; ?>
        </div>
    </div>