<?php
// fix_ocr_stuck.php
require 'db_connection.php';

// Get files stuck in pending_ocr
$stmt = $pdo->prepare("
    SELECT file_id, file_path, file_type 
    FROM files 
    WHERE file_status = 'pending_ocr' 
    AND file_type IN ('pdf', 'png', 'jpg', 'jpeg')
    LIMIT 10
");
$stmt->execute();
$files = $stmt->fetchAll(PDO::FETCH_ASSOC);

foreach ($files as $file) {
    echo "Processing file ID: " . $file['file_id'] . "\n";

    // Manually trigger OCR
    $command = 'php ocr_processor.php ' . escapeshellarg($file['file_id']);
    exec($command . ' 2>&1', $output, $returnCode);

    echo "Result: " . ($returnCode === 0 ? "Success" : "Failed") . "\n";
    if ($returnCode !== 0) {
        echo "Error: " . implode("\n", $output) . "\n";
    }
    echo "---\n";
}
