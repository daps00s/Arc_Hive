<?php
require __DIR__ . '/../db_connection.php';
require __DIR__ . '/../log_activity.php';
require __DIR__ . '/../vendor/autoload.php';

use Smalot\PdfParser\Parser as PdfParser;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpWord\IOFactory as WordIOFactory;
use PhpOffice\PhpWord\Element\Text as TextElement;
use PhpOffice\PhpWord\Element\TextRun;

// Ensure log directory exists
$logDir = __DIR__ . '/logs';
if (!file_exists($logDir) && !mkdir($logDir, 0755, true)) {
    die("Failed to create log directory: $logDir\n");
}

// Enhanced logging function
function ocr_log($message, $level = 'INFO')
{
    $logFile = __DIR__ . '/logs/ocr_processor.log';
    $timestamp = date('Y-m-d H:i:s');
    $message = "[$timestamp] [$level] $message\n";
    file_put_contents($logFile, $message, FILE_APPEND | LOCK_EX);
    echo $message;
}

/**
 * Normalize file type to match database expectations
 */
function normalizeFileType($fileType)
{
    $mapping = [
        'image/png' => 'png',
        'image/jpeg' => 'jpeg',
        'image/jpg' => 'jpeg',
        'application/pdf' => 'pdf',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
        'text/plain' => 'txt',
        'text/csv' => 'csv',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => 'xlsx'
    ];

    return $mapping[strtolower($fileType)] ?? strtolower(pathinfo($fileType, PATHINFO_EXTENSION));
}

/**
 * Extracts text from a file based on its type, handling multi-page PDFs.
 */
function extractTextFromFile(string $filePath, string $fileType, int $fileId, PDO $pdo): ?array
{
    try {
        $normalizedType = normalizeFileType($fileType);
        ocr_log("Starting text extraction for file ID $fileId, type: $normalizedType ($fileType), path: $filePath");

        // Validate file path
        $filePath = realpath($filePath);
        if (!$filePath || !file_exists($filePath) || !is_readable($filePath)) {
            throw new Exception("File not found or not readable: $filePath");
        }

        $fullText = '';
        $pages = [];

        switch ($normalizedType) {
            case 'pdf':
                ocr_log("Processing PDF file: $filePath");
                $parser = new PdfParser();
                $pdf = $parser->parseFile($filePath);
                $pdfText = $pdf->getText();

                if ($pdfText && trim($pdfText) !== '') {
                    ocr_log("PDF contains text, extracting directly");
                    $fullText = $pdfText;
                    $pdfPages = $pdf->getPages();
                    foreach ($pdfPages as $pageNum => $page) {
                        $pageText = $page->getText();
                        if ($pageText && trim($pageText) !== '') {
                            $pages[$pageNum + 1] = $pageText;
                        }
                    }
                } else {
                    ocr_log("PDF appears to be scanned, using Tesseract OCR");
                    // Fallback to Tesseract for scanned PDFs
                    $tesseractPath = strtoupper(substr(PHP_OS, 0, 3)) === 'WIN'
                        ? '"' . __DIR__ . '\tesseract\tesseract.exe' . '"'
                        : 'tesseract';

                    if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN' && !file_exists(__DIR__ . '\tesseract\tesseract.exe')) {
                        throw new Exception("Tesseract executable not found at " . __DIR__ . '\tesseract\tesseract.exe');
                    }

                    // Verify Tesseract is executable
                    $testCommand = strtoupper(substr(PHP_OS, 0, 3)) === 'WIN'
                        ? "$tesseractPath --version"
                        : "tesseract --version";
                    exec($testCommand . ' 2>&1', $testOutput, $testReturnCode);
                    if ($testReturnCode !== 0) {
                        throw new Exception("Tesseract is not executable: " . implode("\n", $testOutput));
                    }

                    $tempDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . uniqid('ocr_pdf_');
                    if (!mkdir($tempDir, 0755, true)) {
                        throw new Exception("Failed to create temporary directory: $tempDir");
                    }

                    // Convert PDF to images using pdftoppm
                    $command = "pdftoppm -png " . escapeshellarg($filePath) . " " . escapeshellarg($tempDir . '/page');
                    exec($command . ' 2>&1', $output, $returnCode);

                    if ($returnCode !== 0) {
                        throw new Exception("pdftoppm failed with code $returnCode: " . implode("\n", $output));
                    }

                    $imageFiles = glob($tempDir . '/page-*.png');
                    ocr_log("Converted PDF to " . count($imageFiles) . " images");

                    foreach ($imageFiles as $index => $imageFile) {
                        $outputFile = sys_get_temp_dir() . DIRECTORY_SEPARATOR . uniqid('ocr_') . '.txt';
                        $command = escapeshellcmd(
                            "$tesseractPath " .
                                escapeshellarg($imageFile) . " " .
                                escapeshellarg(str_replace('.txt', '', $outputFile)) . " -l eng"
                        );

                        // Set a timeout for Tesseract
                        $timeoutCommand = strtoupper(substr(PHP_OS, 0, 3)) === 'WIN'
                            ? "timeout /t 30 /nobreak >nul & $command"
                            : "timeout 30 $command";

                        exec($timeoutCommand . ' 2>&1', $output, $returnCode);

                        if ($returnCode !== 0) {
                            ocr_log("Tesseract OCR failed for page " . ($index + 1) . ": " . implode("\n", $output), 'ERROR');
                            continue;
                        }

                        if (!file_exists($outputFile)) {
                            ocr_log("Tesseract output file not created: $outputFile", 'ERROR');
                            continue;
                        }

                        $pageText = file_get_contents($outputFile);
                        if ($pageText !== false && trim($pageText) !== '') {
                            $pages[$index + 1] = $pageText;
                            $fullText .= $pageText . "\n";
                            ocr_log("Extracted text from page " . ($index + 1) . " (" . strlen($pageText) . " chars)");
                        } else {
                            ocr_log("No text extracted from $imageFile", 'WARNING');
                        }
                        @unlink($outputFile);
                    }

                    // Clean up temporary directory
                    array_map('unlink', glob($tempDir . '/*'));
                    rmdir($tempDir);
                }
                break;

            case 'png':
            case 'jpeg':
                ocr_log("Processing image file: $filePath");
                $tesseractPath = strtoupper(substr(PHP_OS, 0, 3)) === 'WIN'
                    ? '"' . __DIR__ . '\tesseract\tesseract.exe' . '"'
                    : 'tesseract';

                if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN' && !file_exists(__DIR__ . '\tesseract\tesseract.exe')) {
                    throw new Exception("Tesseract executable not found at " . __DIR__ . '\tesseract\tesseract.exe');
                }

                // Verify Tesseract is executable
                $testCommand = strtoupper(substr(PHP_OS, 0, 3)) === 'WIN'
                    ? "$tesseractPath --version"
                    : "tesseract --version";
                exec($testCommand . ' 2>&1', $testOutput, $testReturnCode);
                if ($testReturnCode !== 0) {
                    throw new Exception("Tesseract is not executable: " . implode("\n", $testOutput));
                }

                $outputFile = sys_get_temp_dir() . DIRECTORY_SEPARATOR . uniqid('ocr_') . '.txt';
                $command = escapeshellcmd(
                    "$tesseractPath " .
                        escapeshellarg($filePath) . " " .
                        escapeshellarg(str_replace('.txt', '', $outputFile)) . " -l eng"
                );

                // Set a timeout for Tesseract
                $timeoutCommand = strtoupper(substr(PHP_OS, 0, 3)) === 'WIN'
                    ? "timeout /t 30 /nobreak >nul & $command"
                    : "timeout 30 $command";

                exec($timeoutCommand . ' 2>&1', $output, $returnCode);

                if ($returnCode !== 0) {
                    throw new Exception("Tesseract OCR failed with code $returnCode: " . implode("\n", $output));
                }

                if (!file_exists($outputFile)) {
                    throw new Exception("Tesseract output file not created: $outputFile");
                }

                $fullText = file_get_contents($outputFile);
                if ($fullText === false) {
                    throw new Exception("Failed to read Tesseract output file: $outputFile");
                }
                $pages[1] = $fullText;
                @unlink($outputFile);
                ocr_log("Extracted text from image (" . strlen($fullText) . " chars)");
                break;

            case 'txt':
                ocr_log("Processing text file: $filePath");
                $fullText = file_get_contents($filePath);
                if ($fullText === false) {
                    throw new Exception("Failed to read text file: $filePath");
                }
                $pages[1] = $fullText;
                ocr_log("Extracted text from file (" . strlen($fullText) . " chars)");
                break;

            case 'csv':
            case 'xlsx':
                ocr_log("Processing spreadsheet file: $filePath");
                $spreadsheet = IOFactory::load($filePath);
                $sheet = $spreadsheet->getActiveSheet();
                foreach ($sheet->getRowIterator() as $rowIndex => $row) {
                    $rowText = '';
                    foreach ($row->getCellIterator() as $cell) {
                        $value = $cell->getValue();
                        $rowText .= is_null($value) ? '' : $value . ' ';
                    }
                    $rowText = trim($rowText);
                    if ($rowText !== '') {
                        $pages[$rowIndex] = $rowText;
                        $fullText .= $rowText . "\n";
                    }
                }
                ocr_log("Extracted text from spreadsheet (" . strlen($fullText) . " chars)");
                break;

            case 'docx':
                ocr_log("Processing Word document: $filePath");
                $phpWord = WordIOFactory::load($filePath, 'Word2007');
                $sectionIndex = 1;
                foreach ($phpWord->getSections() as $section) {
                    $sectionText = '';
                    foreach ($section->getElements() as $element) {
                        if ($element instanceof TextElement) {
                            $sectionText .= $element->getText() . ' ';
                        } elseif ($element instanceof TextRun) {
                            foreach ($element->getElements() as $subElement) {
                                if ($subElement instanceof TextElement) {
                                    $sectionText .= $subElement->getText() . ' ';
                                }
                            }
                        }
                    }
                    $sectionText = trim($sectionText);
                    if ($sectionText !== '') {
                        $pages[$sectionIndex] = $sectionText;
                        $fullText .= $sectionText . "\n";
                        $sectionIndex++;
                    }
                }
                ocr_log("Extracted text from Word document (" . strlen($fullText) . " chars)");
                break;

            default:
                throw new Exception("Unsupported file type: $fileType (normalized: $normalizedType)");
        }

        // Store page-wise text in file_pages table
        if (!empty($pages)) {
            try {
                $stmt = $pdo->prepare("
                    INSERT INTO file_pages (file_id, page_number, extracted_text, page_status)
                    VALUES (?, ?, ?, 'completed')
                    ON DUPLICATE KEY UPDATE extracted_text = ?, page_status = 'completed'
                ");
                foreach ($pages as $pageNum => $pageText) {
                    if (trim($pageText) !== '') {
                        $stmt->execute([$fileId, $pageNum, $pageText, $pageText]);
                    }
                }
                ocr_log("Inserted " . count($pages) . " pages into file_pages table");
            } catch (Exception $e) {
                ocr_log("Failed to insert file pages for file ID $fileId: " . $e->getMessage(), 'ERROR');
                throw $e;
            }
        }

        return [
            'full_text' => $fullText ?: null,
            'pages' => $pages
        ];
    } catch (Exception $e) {
        ocr_log("OCR error for file ID $fileId: " . $e->getMessage(), 'ERROR');
        return null;
    }
}

try {
    global $pdo;

    ocr_log("Starting OCR processor");

    // Allow command-line argument for specific file_id
    $specificFileId = isset($argv[1]) ? filter_var($argv[1], FILTER_VALIDATE_INT) : null;

    if ($specificFileId) {
        ocr_log("Processing specific file ID: $specificFileId");

        // First verify the file exists and get its current file_type, file_name, and user_id
        $stmt = $pdo->prepare("SELECT file_id, file_name, file_path, file_type, file_status, user_id FROM files WHERE file_id = ?");
        $stmt->execute([$specificFileId]);
        $file = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$file) {
            ocr_log("File ID $specificFileId not found in database", 'ERROR');
            exit(1);
        }

        ocr_log("File found: " . $file['file_path'] . ", type: " . $file['file_type'] . ", status: " . $file['file_status']);

        // Process this specific file
        $files = [$file];
    } else {
        // Process up to 10 files at a time - only files that should be OCR'd
        // Check for both 'pending_ocr' and 'pending' statuses to handle any inconsistencies
        $query = "
            SELECT file_id, file_path, file_type, file_name, user_id
            FROM files 
            WHERE file_status IN ('pending_ocr', 'pending')
            AND file_type IN ('application/pdf', 'image/png', 'image/jpeg', 'image/jpg')
            LIMIT 10
        ";

        $stmt = $pdo->prepare($query);
        $stmt->execute();
        $files = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if (empty($files)) {
            ocr_log("No pending OCR files found");
            exit(0);
        }

        ocr_log("Found " . count($files) . " files to process");
    }

    foreach ($files as $file) {
        $fileId = $file['file_id'];
        $filePath = $file['file_path'];
        $fileType = $file['file_type'];
        $fileName = $file['file_name'];
        $userId = $file['user_id'];
        $normalizedType = normalizeFileType($fileType);

        ocr_log("Processing file ID: $fileId, path: $filePath, type: $normalizedType");

        // Start transaction for each file
        $pdo->beginTransaction();

        try {
            // Check retry attempts
            $stmt = $pdo->prepare("
                SELECT COUNT(*) as attempts 
                FROM transactions 
                WHERE file_id = ? AND transaction_type IN ('ocr_process', 'ocr_retry') 
                AND transaction_status = 'failed'
            ");
            $stmt->execute([$fileId]);
            $attempts = $stmt->fetch(PDO::FETCH_ASSOC)['attempts'];

            if ($attempts >= 3) {
                ocr_log("Max OCR retries (3) reached for file ID $fileId", 'WARNING');

                // Use the correct status based on file type
                $status = in_array($normalizedType, ['pdf', 'png', 'jpeg', 'docx']) ? 'ocr_failed' : 'completed';
                $stmt = $pdo->prepare("UPDATE files SET file_status = ? WHERE file_id = ?");
                $stmt->execute([$status, $fileId]);

                $stmt = $pdo->prepare("
                    INSERT INTO transactions (user_id, file_id, transaction_type, transaction_status, transaction_time, description)
                    VALUES (?, ?, 'ocr_process', 'failed', NOW(), ?)
                ");
                $stmt->execute([null, $fileId, "Max OCR retries reached for file ID $fileId"]);
                $pdo->commit();
                continue;
            }

            // Check file accessibility
            $absoluteFilePath = realpath($filePath);
            if (!$absoluteFilePath || !is_readable($absoluteFilePath)) {
                ocr_log("File not accessible for file ID $fileId: $filePath", 'ERROR');

                // Use the correct status based on file type
                $status = in_array($normalizedType, ['pdf', 'png', 'jpeg', 'docx']) ? 'ocr_failed' : 'completed';
                $stmt = $pdo->prepare("UPDATE files SET file_status = ? WHERE file_id = ?");
                $stmt->execute([$status, $fileId]);

                $stmt = $pdo->prepare("
                    INSERT INTO transactions (user_id, file_id, transaction_type, transaction_status, transaction_time, description)
                    VALUES (?, ?, 'ocr_process', 'failed', NOW(), ?)
                ");
                $stmt->execute([null, $fileId, "File not accessible for file ID $fileId: $filePath"]);
                $pdo->commit();
                continue;
            }

            // Ensure text_repository entry exists
            $stmt = $pdo->prepare("
                INSERT IGNORE INTO text_repository (file_id, extracted_text, ocr_attempts, ocr_status)
                VALUES (?, '', 0, 'processing')
            ");
            $stmt->execute([$fileId]);

            // Update OCR attempts
            $stmt = $pdo->prepare("
                UPDATE text_repository 
                SET ocr_attempts = ocr_attempts + 1, ocr_status = 'processing', last_processed = NOW()
                WHERE file_id = ?
            ");
            $stmt->execute([$fileId]);

            // Extract text with timeout
            $startTime = microtime(true);
            $result = extractTextFromFile($absoluteFilePath, $fileType, $fileId, $pdo);
            $executionTime = microtime(true) - $startTime;

            if (is_null($result) || empty($result['full_text'])) {
                $errorMsg = is_null($result) ? 'OCR failed to produce output' : 'Extracted text is empty';
                ocr_log("No text extracted for file ID $fileId: $errorMsg (execution time: {$executionTime}s)", 'ERROR');

                // Use the correct status based on file type
                $status = in_array($normalizedType, ['pdf', 'png', 'jpeg', 'docx']) ? 'ocr_failed' : 'completed';
                $stmt = $pdo->prepare("UPDATE files SET file_status = ? WHERE file_id = ?");
                $stmt->execute([$status, $fileId]);

                $stmt = $pdo->prepare("
                    UPDATE text_repository SET ocr_status = 'failed', error_message = ? WHERE file_id = ?
                ");
                $stmt->execute([$errorMsg, $fileId]);

                $stmt = $pdo->prepare("
                    INSERT INTO transactions (user_id, file_id, transaction_type, transaction_status, transaction_time, description)
                    VALUES (?, ?, 'ocr_process', 'failed', NOW(), ?)
                ");
                $stmt->execute([null, $fileId, "OCR failed for file ID $fileId: $errorMsg"]);
                $pdo->commit();
                continue;
            }

            // Store full text in text_repository
            $stmt = $pdo->prepare("
                UPDATE text_repository 
                SET extracted_text = ?, ocr_attempts = ?, ocr_status = 'completed', last_processed = NOW()
                WHERE file_id = ?
            ");
            $stmt->execute([$result['full_text'], $attempts + 1, $fileId]);

            // Update file status - use correct status based on file type
            $status = 'completed'; // All successful OCR processes should result in 'completed' status
            $stmt = $pdo->prepare("UPDATE files SET file_status = ? WHERE file_id = ?");
            $stmt->execute([$status, $fileId]);

            // Log existing OCR process transaction
            $stmt = $pdo->prepare("
                INSERT INTO transactions (user_id, file_id, transaction_type, transaction_status, transaction_time, description)
                VALUES (?, ?, 'ocr_process', 'completed', NOW(), ?)
            ");
            $stmt->execute([null, $fileId, "OCR processed for file ID $fileId with " . count($result['pages']) . " pages (execution time: {$executionTime}s)"]);

            // Log new notification transaction
            if (function_exists('logActivity')) {
                logActivity($userId, "OCR successful:$fileName", $fileId, null, null, 'notification');
            } else {
                ocr_log("logActivity function not defined for file ID $fileId", 'ERROR');
                // Fallback to direct insert if logActivity is not available
                $stmt = $pdo->prepare("
                    INSERT INTO transactions (user_id, file_id, transaction_type, transaction_status, transaction_time, description)
                    VALUES (?, ?, 'notification', 'completed', NOW(), ?)
                ");
                $stmt->execute([$userId, $fileId, "OCR successful:$fileName"]);
            }

            $pdo->commit();
            ocr_log("OCR completed for file ID $fileId (pages: " . count($result['pages']) . ", execution time: {$executionTime}s)");
        } catch (Exception $e) {
            $pdo->rollBack();
            ocr_log("OCR processing error for file ID $fileId: " . $e->getMessage() . " | Trace: " . $e->getTraceAsString(), 'ERROR');

            // Use the correct status based on file type
            $status = in_array($normalizedType, ['pdf', 'png', 'jpeg', 'docx']) ? 'ocr_failed' : 'completed';
            $stmt = $pdo->prepare("UPDATE files SET file_status = ? WHERE file_id = ?");
            $stmt->execute([$status, $fileId]);

            $stmt = $pdo->prepare("
                UPDATE text_repository SET ocr_status = 'failed', error_message = ? WHERE file_id = ?
            ");
            $stmt->execute([$e->getMessage(), $fileId]);

            $stmt = $pdo->prepare("
                INSERT INTO transactions (user_id, file_id, transaction_type, transaction_status, transaction_time, description)
                VALUES (?, ?, 'ocr_process', 'failed', NOW(), ?)
            ");
            $stmt->execute([null, $fileId, "OCR failed for file ID $fileId: {$e->getMessage()}"]);
            $pdo->commit();
        }
    }

    ocr_log("OCR processor finished");
} catch (Exception $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    ocr_log("OCR processing error: " . $e->getMessage() . " | Trace: " . $e->getTraceAsString(), 'ERROR');
    exit(1);
}
