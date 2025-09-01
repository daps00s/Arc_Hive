<?php
// test_ocr.php
require 'db_connection.php';

// Test with a specific file
$fileId = 249; // Change to your actual file ID

echo "Testing OCR for file ID: $fileId\n";

// Check current status
$stmt = $pdo->prepare("SELECT file_status, file_path FROM files WHERE file_id = ?");
$stmt->execute([$fileId]);
$file = $stmt->fetch(PDO::FETCH_ASSOC);

echo "Current status: " . ($file['file_status'] ?? 'NOT FOUND') . "\n";
echo "File path: " . ($file['file_path'] ?? 'NOT FOUND') . "\n";

// Run OCR processor directly
$command = 'php api/ocr_processor.php ' . escapeshellarg($fileId);
echo "Executing: $command\n";

exec($command . ' 2>&1', $output, $returnCode);

echo "Return code: $returnCode\n";
echo "Output:\n" . implode("\n", $output) . "\n";

// Check status after OCR
$stmt->execute([$fileId]);
$fileAfter = $stmt->fetch(PDO::FETCH_ASSOC);
echo "Status after OCR: " . ($fileAfter['file_status'] ?? 'NOT FOUND') . "\n";
