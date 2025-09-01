<?php
session_start();
require 'db_connection.php';

// Configure error handling
ini_set('display_errors', 0);
ini_set('display_startup_errors', 0);
ini_set('error_log', __DIR__ . '/logs/error_log.log');
error_reporting(E_ALL);

/**
 * Sends a JSON response with HTTP status code.
 *
 * @param bool $success
 * @param string $message
 * @param array $data
 * @param int $statusCode
 * @return void
 */
function sendJsonResponse(bool $success, string $message, array $data, int $statusCode): void
{
    header('Content-Type: application/json');
    http_response_code($statusCode);
    echo json_encode(array_merge(['success' => $success, 'message' => $message], $data));
    exit;
}

/**
 * Validates user session and retrieves user details.
 *
 * @return array{user_id: int, role: string, username: string}
 * @throws Exception
 */
function validateUserSession(): array
{
    global $pdo;
    if (!isset($_SESSION['user_id']) || !isset($_SESSION['role'])) {
        error_log("Session validation failed: user_id or role not set.");
        throw new Exception("Session validation failed.", 401);
    }
    $stmt = $pdo->prepare("SELECT username FROM users WHERE user_id = ?");
    $stmt->execute([(int)$_SESSION['user_id']]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$user || !$user['username']) {
        error_log("Username not found for user_id: {$_SESSION['user_id']}");
        throw new Exception("Username not found.", 401);
    }
    return [
        'user_id' => (int)$_SESSION['user_id'],
        'role' => (string)$_SESSION['role'],
        'username' => $user['username']
    ];
}

// Handle AJAX request to fetch extracted text for preview
if (isset($_GET['action']) && $_GET['action'] === 'get_preview' && isset($_GET['file_id'])) {
    try {
        $user = validateUserSession();
        $fileId = filter_var($_GET['file_id'], FILTER_VALIDATE_INT);
        if (!$fileId) {
            error_log("Invalid file ID provided for get_preview: {$_GET['file_id']}");
            sendJsonResponse(false, 'Invalid file ID.', [], 400);
        }

        // Get search query for highlighting
        $searchQuery = isset($_GET['q']) ? filter_var($_GET['q'], FILTER_SANITIZE_SPECIAL_CHARS) : '';

        $stmt = $pdo->prepare("
            SELECT tr.extracted_text, f.file_status, f.file_name, f.file_type, f.file_path, 
                   f.access_level, f.department_id, f.sub_department_id
            FROM text_repository tr
            JOIN files f ON tr.file_id = f.file_id
            LEFT JOIN users_department ud ON f.department_id = ud.department_id OR f.sub_department_id = ud.department_id
            WHERE tr.file_id = ? 
            AND f.file_status != 'deleted'
            AND (f.user_id = ? OR f.access_level = 'college' OR 
                 (f.access_level = 'sub_department' AND ud.user_id = ?) OR 
                 (f.access_level = 'personal' AND f.user_id = ?))
        ");
        $stmt->execute([$fileId, $user['user_id'], $user['user_id'], $user['user_id']]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$result) {
            error_log("No text_repository entry found or access denied for file_id: $fileId");
            sendJsonResponse(false, 'No text repository entry found or access denied.', [], 403);
        }

        $responseData = [
            'extracted_text' => $result['extracted_text'] ?? '',
            'file_status' => $result['file_status'],
            'file_name' => $result['file_name'],
            'file_type' => $result['file_type'],
            'file_path' => $result['file_path'],
            'search_query' => $searchQuery
        ];

        if ($result['file_status'] === 'pending_ocr') {
            sendJsonResponse(false, 'Text extraction is still in progress. Please try again later.', $responseData, 202);
        } elseif ($result['file_status'] === 'ocr_failed') {
            sendJsonResponse(false, 'Text extraction failed. Click "Retry OCR" to try again.', $responseData, 422);
        } elseif (empty($result['extracted_text'])) {
            sendJsonResponse(false, 'No text extracted for this file. It may not contain extractable content.', $responseData, 200);
        }

        error_log("Preview for file_id: $fileId, file_status: {$result['file_status']}, extracted_text length: " . strlen($result['extracted_text'] ?? ''));
        sendJsonResponse(true, 'Preview retrieved successfully.', $responseData, 200);
    } catch (Exception $e) {
        error_log("Error fetching preview for file_id {$_GET['file_id']}: " . $e->getMessage() . " | Line: " . $e->getLine());
        sendJsonResponse(false, 'Server error: Unable to retrieve preview: ' . $e->getMessage(), [], $e->getCode() ?: 500);
    }
}

// Handle AJAX request to retry OCR
if (isset($_GET['action']) && $_GET['action'] === 'retry_ocr' && isset($_GET['file_id'])) {
    try {
        $user = validateUserSession();
        $fileId = filter_var($_GET['file_id'], FILTER_VALIDATE_INT);
        if (!$fileId) {
            error_log("Invalid file ID provided for retry_ocr: {$_GET['file_id']}");
            sendJsonResponse(false, 'Invalid file ID.', [], 400);
        }
        $stmt = $pdo->prepare("
            SELECT file_status, access_level, department_id, sub_department_id
            FROM files f
            LEFT JOIN users_department ud ON f.department_id = ud.department_id OR f.sub_department_id = ud.department_id
            WHERE f.file_id = ? AND (f.file_status = 'ocr_failed' OR f.file_status = 'pending_ocr')
            AND (f.user_id = ? OR f.access_level = 'college' OR 
                 (f.access_level = 'sub_department' AND ud.user_id = ?) OR 
                 (f.access_level = 'personal' AND f.user_id = ?))
        ");
        $stmt->execute([$fileId, $user['user_id'], $user['user_id'], $user['user_id']]);
        if (!$stmt->fetch()) {
            error_log("No file found or not in ocr_failed/pending_ocr status or access denied for file_id: $fileId");
            sendJsonResponse(false, 'File not eligible for OCR retry or access denied.', [], 403);
        }
        $stmt = $pdo->prepare("UPDATE files SET file_status = 'pending_ocr' WHERE file_id = ?");
        $stmt->execute([$fileId]);
        // Trigger background OCR processing
        $logFile = __DIR__ . '/logs/ocr_processor.log';
        $command = escapeshellcmd("php " . __DIR__ . "/ocr_processor.php $fileId >> $logFile 2>&1");
        $output = [];
        $returnCode = 0;
        if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
            exec("start /B $command 2>&1", $output, $returnCode);
        } else {
            exec("$command &", $output, $returnCode);
        }
        if ($returnCode !== 0) {
            error_log("Failed to start OCR retry for file ID $fileId: " . implode("\n", $output), 3, $logFile);
            sendJsonResponse(false, 'Failed to schedule OCR retry.', [], 500);
        }
        $stmt = $pdo->prepare("
            INSERT INTO transactions (user_id, file_id, transaction_type, transaction_status, transaction_time, description)
            VALUES (?, ?, 'ocr_retry', 'scheduled', NOW(), ?)
        ");
        $stmt->execute([$user['user_id'], $fileId, "Retrying OCR processing for file ID $fileId"]);
        sendJsonResponse(true, 'OCR retry scheduled successfully.', [], 200);
    } catch (Exception $e) {
        error_log("Error retrying OCR for file_id {$_GET['file_id']}: " . $e->getMessage() . " | Line: " . $e->getLine());
        sendJsonResponse(false, 'Server error: Unable to retry OCR: ' . $e->getMessage(), [], $e->getCode() ?: 500);
    }
}

try {
    // Validate session
    $user = validateUserSession();
    $userId = $user['user_id'];

    // Fetch user departments
    $stmt = $pdo->prepare("
        SELECT d.department_id
        FROM departments d
        JOIN users_department ud ON d.department_id = ud.department_id
        WHERE ud.user_id = ?
    ");
    $stmt->execute([$userId]);
    $userDepartments = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $departmentIds = array_column($userDepartments, 'department_id');

    // Get search query
    $searchQuery = filter_input(INPUT_GET, 'q', FILTER_SANITIZE_SPECIAL_CHARS) ?? '';
    $files = [];

    if (!empty($searchQuery)) {
        // First check if text_repository has any extracted text
        $checkTextStmt = $pdo->prepare("SELECT COUNT(*) as count FROM text_repository WHERE extracted_text IS NOT NULL AND LENGTH(extracted_text) > 0");
        $checkTextStmt->execute();
        $hasExtractedText = $checkTextStmt->fetch(PDO::FETCH_ASSOC)['count'] > 0;

        if ($hasExtractedText) {
            // Build SQL query to search file contents using FULLTEXT index
            $sql = "
                SELECT f.file_id, f.file_name, f.file_type, f.upload_date, 
                       COALESCE(dt.type_name, 'Unknown') AS document_type,
                       COALESCE(f.copy_type, 'soft_copy') AS copy_type,
                       f.file_status,
                       tr.extracted_text,
                       MATCH(tr.extracted_text) AGAINST(? IN NATURAL LANGUAGE MODE) as relevance
                FROM files f
                LEFT JOIN document_types dt ON f.document_type_id = dt.document_type_id
                INNER JOIN text_repository tr ON f.file_id = tr.file_id
                LEFT JOIN users_department ud ON f.department_id = ud.department_id OR f.sub_department_id = ud.department_id
                WHERE f.file_status != 'deleted'
                AND MATCH(tr.extracted_text) AGAINST(? IN NATURAL LANGUAGE MODE)
                AND (f.user_id = ? OR f.access_level = 'college' OR 
                     (f.access_level = 'sub_department' AND ud.user_id = ?) OR 
                     (f.access_level = 'personal' AND f.user_id = ?))
                ORDER BY relevance DESC
            ";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$searchQuery, $searchQuery, $userId, $userId, $userId]);
            $files = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // If no results from FULLTEXT search, try fallback to LIKE search on file names
            if (empty($files)) {
                $sql = "
                    SELECT f.file_id, f.file_name, f.file_type, f.upload_date, 
                           COALESCE(dt.type_name, 'Unknown') AS document_type,
                           COALESCE(f.copy_type, 'soft_copy') AS copy_type,
                           f.file_status,
                           NULL as extracted_text,
                           0 as relevance
                    FROM files f
                    LEFT JOIN document_types dt ON f.document_type_id = dt.document_type_id
                    LEFT JOIN users_department ud ON f.department_id = ud.department_id OR f.sub_department_id = ud.department_id
                    WHERE f.file_status != 'deleted'
                    AND f.file_name LIKE ?
                    AND (f.user_id = ? OR f.access_level = 'college' OR 
                         (f.access_level = 'sub_department' AND ud.user_id = ?) OR 
                         (f.access_level = 'personal' AND f.user_id = ?))
                ";
                $stmt = $pdo->prepare($sql);
                $searchPattern = '%' . $searchQuery . '%';
                $stmt->execute([$searchPattern, $userId, $userId, $userId]);
                $files = $stmt->fetchAll(PDO::FETCH_ASSOC);
            }
        } else {
            // Fallback: search in file names if no extracted text is available
            $sql = "
                SELECT f.file_id, f.file_name, f.file_type, f.upload_date, 
                       COALESCE(dt.type_name, 'Unknown') AS document_type,
                       COALESCE(f.copy_type, 'soft_copy') AS copy_type,
                       f.file_status,
                       NULL as extracted_text
                FROM files f
                LEFT JOIN document_types dt ON f.document_type_id = dt.document_type_id
                LEFT JOIN users_department ud ON f.department_id = ud.department_id OR f.sub_department_id = ud.department_id
                WHERE f.file_status != 'deleted'
                AND f.file_name LIKE ?
                AND (f.user_id = ? OR f.access_level = 'college' OR 
                     (f.access_level = 'sub_department' AND ud.user_id = ?) OR 
                     (f.access_level = 'personal' AND f.user_id = ?))
            ";
            $stmt = $pdo->prepare($sql);
            $searchPattern = '%' . $searchQuery . '%';
            $stmt->execute([$searchPattern, $userId, $userId, $userId]);
            $files = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }
    }

    // HTML output
?>
    <!DOCTYPE html>
    <html lang="en">

    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>File Search</title>
        <style>
            body {
                font-family: Arial, sans-serif;
                margin: 20px;
                background-color: #f5f5f5;
            }

            .search-container {
                margin-bottom: 20px;
                padding: 15px;
                background-color: white;
                border-radius: 8px;
                box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
            }

            .search-input {
                width: 300px;
                padding: 10px;
                border: 1px solid #ddd;
                border-radius: 4px;
                font-size: 16px;
            }

            button {
                padding: 10px 15px;
                background-color: #4CAF50;
                color: white;
                border: none;
                border-radius: 4px;
                cursor: pointer;
                font-size: 16px;
            }

            button:hover {
                background-color: #45a049;
            }

            table {
                width: 100%;
                border-collapse: collapse;
                background-color: white;
                box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
                border-radius: 8px;
                overflow: hidden;
            }

            th,
            td {
                border: 1px solid #ddd;
                padding: 12px;
                text-align: left;
            }

            th {
                background-color: #f2f2f2;
                font-weight: bold;
            }

            .highlight {
                background-color: yellow;
                font-weight: bold;
            }

            .preview-container {
                margin-top: 20px;
                padding: 15px;
                background-color: white;
                border-radius: 8px;
                box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
                display: none;
            }

            .preview-header {
                display: flex;
                justify-content: space-between;
                align-items: center;
                margin-bottom: 15px;
                padding-bottom: 10px;
                border-bottom: 1px solid #eee;
            }

            .preview-content {
                max-height: 400px;
                overflow: auto;
                padding: 10px;
                border: 1px solid #eee;
                border-radius: 4px;
                background-color: #f9f9f9;
                white-space: pre-wrap;
                line-height: 1.5;
            }

            .close-btn {
                background: none;
                border: none;
                font-size: 24px;
                cursor: pointer;
                color: #999;
            }

            .close-btn:hover {
                color: #333;
            }

            .search-info {
                margin: 10px 0;
                padding: 10px;
                background-color: #e7f3ff;
                border-left: 4px solid #007bff;
                border-radius: 4px;
            }

            .search-type-badge {
                display: inline-block;
                padding: 3px 8px;
                background-color: #28a745;
                color: white;
                border-radius: 4px;
                font-size: 12px;
                margin-left: 5px;
            }

            .file-row {
                cursor: pointer;
                transition: background-color 0.2s;
            }

            .file-row:hover {
                background-color: #f0f0f0;
            }

            .file-row.active {
                background-color: #e6f7ff;
            }

            .no-text {
                color: #777;
                font-style: italic;
            }

            .text-snippet {
                margin-top: 5px;
                font-size: 14px;
                color: #555;
                max-height: 60px;
                overflow: hidden;
                text-overflow: ellipsis;
            }

            .retry-btn {
                background-color: #ff9800;
                padding: 5px 10px;
                font-size: 12px;
            }

            .retry-btn:hover {
                background-color: #e68a00;
            }
        </style>
    </head>

    <body>
        <div class="search-container">
            <input type="text" class="search-input" id="searchInput" value="<?php echo htmlspecialchars($searchQuery); ?>" placeholder="Search files...">
            <button onclick="searchFiles()">Search</button>
        </div>

        <?php if (!empty($searchQuery)): ?>
            <h2>Search Results for "<?php echo htmlspecialchars($searchQuery); ?>"</h2>

            <?php
            // Check search type
            $checkTextStmt = $pdo->prepare("SELECT COUNT(*) as count FROM text_repository WHERE extracted_text IS NOT NULL AND LENGTH(extracted_text) > 0");
            $checkTextStmt->execute();
            $hasExtractedText = $checkTextStmt->fetch(PDO::FETCH_ASSOC)['count'] > 0;

            $searchType = 'content';
            if (!$hasExtractedText) {
                $searchType = 'filename';
            } elseif (!empty($files) && isset($files[0]['extracted_text']) && $files[0]['extracted_text'] === null) {
                $searchType = 'filename';
            }
            ?>

            <?php if (!$hasExtractedText): ?>
                <div class="search-info">
                    <strong>Note:</strong> No extracted text available in database. Searching by file names only.
                    <br>To enable content search, upload files with OCR processing or run OCR on existing files.
                </div>
            <?php endif; ?>

            <?php if (empty($files)): ?>
                <p>No files found matching your query.</p>
            <?php else: ?>
                <p>Found <?php echo count($files); ?> file(s)
                    <span class="search-type-badge">
                        <?php echo $searchType === 'content' ? 'Content Search' : 'Filename Search'; ?>
                    </span>
                </p>
                <table>
                    <thead>
                        <tr>
                            <th>File Name</th>
                            <th>File Type</th>
                            <th>Document Type</th>
                            <th>Upload Date</th>
                            <th>Copy Type</th>
                            <th>File Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($files as $index => $file):
                            // Highlight search term in file name
                            $highlightedFileName = htmlspecialchars($file['file_name']);
                            if (!empty($searchQuery)) {
                                $highlightedFileName = preg_replace(
                                    "/(" . preg_quote($searchQuery, '/') . ")/i",
                                    '<span class="highlight">$1</span>',
                                    $highlightedFileName
                                );
                            }

                            // Create a text snippet if available
                            $textSnippet = '';
                            if (!empty($file['extracted_text'])) {
                                $text = htmlspecialchars($file['extracted_text']);
                                $position = stripos($text, $searchQuery);
                                if ($position !== false) {
                                    $start = max(0, $position - 50);
                                    $end = min(strlen($text), $position + strlen($searchQuery) + 100);
                                    $snippet = substr($text, $start, $end - $start);
                                    if ($start > 0) $snippet = '...' . $snippet;
                                    if ($end < strlen($text)) $snippet = $snippet . '...';

                                    // Highlight the search term in the snippet
                                    $textSnippet = preg_replace(
                                        "/(" . preg_quote($searchQuery, '/') . ")/i",
                                        '<span class="highlight">$1</span>',
                                        $snippet
                                    );
                                }
                            }
                        ?>
                            <tr class="file-row" data-file-id="<?php echo $file['file_id']; ?>" data-search-query="<?php echo htmlspecialchars($searchQuery); ?>">
                                <td>
                                    <?php echo $highlightedFileName; ?>
                                    <?php if (!empty($textSnippet)): ?>
                                        <div class="text-snippet"><?php echo $textSnippet; ?></div>
                                    <?php endif; ?>
                                </td>
                                <td><?php echo htmlspecialchars($file['file_type']); ?></td>
                                <td><?php echo htmlspecialchars($file['document_type']); ?></td>
                                <td><?php echo htmlspecialchars($file['upload_date']); ?></td>
                                <td><?php echo htmlspecialchars($file['copy_type']); ?></td>
                                <td><?php echo htmlspecialchars($file['file_status']); ?></td>
                                <td>
                                    <button class="retry-btn" data-file-id="<?php echo $file['file_id']; ?>" data-retry-enabled="<?php echo in_array($file['file_status'], ['ocr_failed', 'pending_ocr']) ? 'true' : 'false'; ?>" style="display: <?php echo in_array($file['file_status'], ['ocr_failed', 'pending_ocr']) ? 'inline-block' : 'none'; ?>;">Retry OCR</button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>

                <!-- Preview container that will show the extracted text -->
                <div class="preview-container" id="previewContainer">
                    <div class="preview-header">
                        <h3 id="previewFileName"></h3>
                        <button class="close-btn" onclick="closePreview()">&times;</button>
                    </div>
                    <div id="previewFileStatus"></div>
                    <div class="preview-content" id="previewContent"></div>
                </div>
            <?php endif; ?>
        <?php else: ?>
            <p>Enter a search query to find files.</p>
        <?php endif; ?>

        <script>
            function searchFiles() {
                const query = document.getElementById('searchInput').value;
                if (query) {
                    window.location.href = `?q=${encodeURIComponent(query)}`;
                }
            }

            document.getElementById('searchInput').addEventListener('keypress', function(e) {
                if (e.key === 'Enter') {
                    searchFiles();
                }
            });

            // Function to highlight text
            function highlightText(text, searchQuery) {
                if (!searchQuery || !text) return text;

                // Escape special regex characters
                const escapedQuery = searchQuery.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
                const regex = new RegExp(escapedQuery, 'gi');

                return text.replace(regex, match => `<span class="highlight">${match}</span>`);
            }

            // Handle file row clicks to show preview
            document.querySelectorAll('.file-row').forEach(row => {
                row.addEventListener('click', () => {
                    const fileId = row.getAttribute('data-file-id');
                    const searchQuery = row.getAttribute('data-search-query');

                    // Highlight the selected row
                    document.querySelectorAll('.file-row').forEach(r => r.classList.remove('active'));
                    row.classList.add('active');

                    // Show loading state
                    document.getElementById('previewFileName').textContent = 'Loading...';
                    document.getElementById('previewFileStatus').textContent = '';
                    document.getElementById('previewContent').innerHTML = '<div class="no-text">Loading preview...</div>';
                    document.getElementById('previewContainer').style.display = 'block';

                    // Scroll to preview
                    document.getElementById('previewContainer').scrollIntoView({
                        behavior: 'smooth'
                    });

                    // Fetch preview via AJAX
                    fetch(`?action=get_preview&file_id=${fileId}&q=${encodeURIComponent(searchQuery)}`, {
                            method: 'GET',
                            headers: {
                                'Accept': 'application/json'
                            }
                        })
                        .then(response => {
                            if (!response.ok) throw new Error(`HTTP error! Status: ${response.status}`);
                            return response.json();
                        })
                        .then(data => {
                            document.getElementById('previewFileName').textContent = data.file_name || 'Unknown';
                            document.getElementById('previewFileStatus').textContent = `Status: ${data.file_status || 'Unknown'}`;

                            if (data.success && data.extracted_text) {
                                // Escape HTML entities first
                                const escapedText = data.extracted_text.replace(/</g, '&lt;').replace(/>/g, '&gt;');

                                // Highlight the search query
                                const highlightedText = highlightText(escapedText, data.search_query || searchQuery);

                                // Replace newlines with <br> tags
                                const formattedText = highlightedText.replace(/\n/g, '<br>');

                                document.getElementById('previewContent').innerHTML = formattedText;
                            } else {
                                let message = data.message || 'No text available for this file.';
                                if (data.file_status === 'pending_ocr') {
                                    message = 'Text extraction is still in progress. Please try again later.';
                                } else if (data.file_status === 'ocr_failed') {
                                    message = 'Text extraction failed. Click "Retry OCR" to try again.';
                                } else if (!data.extracted_text) {
                                    message = 'No text available for this file. It may not contain extractable text.';
                                }
                                document.getElementById('previewContent').innerHTML = `<div class="no-text">${message}</div>`;
                            }
                        })
                        .catch(error => {
                            console.error('Error fetching preview for file_id ' + fileId + ':', error);
                            document.getElementById('previewContent').innerHTML = `<div class="no-text">Failed to load preview: ${error.message}. Please try again.</div>`;
                            document.getElementById('previewFileName').textContent = 'Error';
                            document.getElementById('previewFileStatus').textContent = 'Error';
                        });
                });
            });

            // Handle retry OCR button clicks
            document.querySelectorAll('.retry-btn').forEach(button => {
                button.addEventListener('click', (e) => {
                    e.stopPropagation(); // Prevent triggering the row click event

                    const fileId = button.getAttribute('data-file-id');
                    const retryEnabled = button.getAttribute('data-retry-enabled') === 'true';

                    if (!retryEnabled) {
                        alert('OCR retry not available for this file.');
                        return;
                    }

                    // Trigger OCR retry via AJAX
                    fetch(`?action=retry_ocr&file_id=${fileId}`, {
                            method: 'GET',
                            headers: {
                                'Accept': 'application/json'
                            }
                        })
                        .then(response => {
                            if (!response.ok) throw new Error(`HTTP error! Status: ${response.status}`);
                            return response.json();
                        })
                        .then(data => {
                            alert(data.message || 'OCR retry scheduled.');
                            button.style.display = 'none';
                            // Reload the page after a short delay to reflect the status change
                            setTimeout(() => {
                                location.reload();
                            }, 1000);
                        })
                        .catch(error => {
                            console.error('Error retrying OCR for file_id ' + fileId + ':', error);
                            alert('Failed to retry OCR: ' + error.message);
                        });
                });
            });

            function closePreview() {
                document.getElementById('previewContainer').style.display = 'none';
                document.querySelectorAll('.file-row').forEach(r => r.classList.remove('active'));
            }
        </script>
    </body>

    </html>
<?php
} catch (Exception $e) {
    error_log("Error in search page: " . $e->getMessage() . " | Line: " . $e->getLine());
    echo "<p>Error: " . htmlspecialchars($e->getMessage()) . "</p>";
}
