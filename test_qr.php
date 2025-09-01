<?php
require_once 'db_connection.php'; // Include the database connection
require_once 'phpqrcode/qrlib.php'; // Use require_once
require_once 'QRService.php'; // Use require_once for consistency

$qrService = new QRService($pdo); // $pdo is defined in db_connection.php
$outputPath = "C:/xampp/htdocs/Arc_Hive-main/qrcodes/";
if (!is_dir($outputPath)) mkdir($outputPath, 0777, true);
$qrPath = $qrService->generateQRCode(1, $outputPath);
echo "QR Code generated at: $qrPath";
