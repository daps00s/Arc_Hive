<?php
// Include PHPWord for fallback
if (file_exists('vendor/autoload.php')) {
    require 'vendor/autoload.php';
} else {
    error_log('Warning: vendor/autoload.php not found. PHPWord fallback unavailable. Run "composer require phpoffice/phpword" in C:\xampp\htdocs\GitHub\Arc_Hive-main.');
}
use PhpOffice\PhpWord\IOFactory;
use PhpOffice\PhpWord\Settings;

// Set PDF renderer to TCPDF for fallback
if (class_exists('PhpOffice\PhpWord\Settings')) {
    Settings::setPdfRendererName(Settings::PDF_RENDERER_TCPDF);
    Settings::setPdfRendererPath('vendor/tecnickcom/tcpdf');
}

// Handle file upload
$uploadMessage = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['uploadedFile'])) {
    $directory = './files';
    $file = $_FILES['uploadedFile'];
    $fileName = basename($file['name']);
    $targetPath = $directory . '/' . $fileName;
    $extension = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
    $allowedExtensions = ['txt', 'php', 'js', 'css', 'html', 'jpg', 'jpeg', 'png', 'gif', 'pdf', 'docx'];

    // Ensure files directory exists and is writable
    if (!is_dir($directory)) {
        mkdir($directory, 0777, true);
    }
    if (!is_writable($directory)) {
        $uploadMessage = "<p class='error'>Error: 'files' directory is not writable. Please set permissions (e.g., Full control for Everyone).</p>";
    } elseif (in_array($extension, $allowedExtensions) && $file['error'] === UPLOAD_ERR_OK) {
        if (move_uploaded_file($file['tmp_name'], $targetPath)) {
            $uploadMessage = "<p style='color: green;'>File uploaded successfully: $fileName</p>";
            header("Location: ?file=" . urlencode($fileName)); // Redirect to preview
            exit;
        } else {
            $uploadMessage = "<p class='error'>Error uploading file. Check server permissions or file size limits in php.ini.</p>";
        }
    } else {
        $uploadMessage = "<p class='error'>Invalid file type or upload error. Allowed types: " . implode(', ', $allowedExtensions) . ".</p>";
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>File Preview</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            margin: 0;
            padding: 20px;
            background-color: #f4f4f9;
        }
        .container {
            max-width: 1200px;
            margin: 0 auto;
        }
        .file-list {
            width: 30%;
            float: left;
            background: #fff;
            padding: 20px;
            border-radius: 8px;
            box-shadow: 0 2px 5px rgba(0,0,0,0.1);
        }
        .file-list ul {
            list-style: none;
            padding: 0;
        }
        .file-list ul li {
            padding: 10px;
            cursor: pointer;
            border-bottom: 1px solid #ddd;
        }
        .file-list ul li:hover {
            background: #e0e0e0;
        }
        .file-upload {
            margin-bottom: 20px;
        }
        .preview {
            width: 65%;
            float: right;
            background: #fff;
            padding: 20px;
            border-radius: 8px;
            box-shadow: 0 2px 5px rgba(0,0,0,0.1);
            min-height: 400px;
        }
        .preview img, .preview iframe {
            max-width: 100%;
            height: 500px; /* Fixed height for document-like view */
            border: none;
        }
        .preview pre {
            background: #f8f8f8;
            padding: 15px;
            border-radius: 5px;
            overflow-x: auto;
        }
        .error {
            color: red;
            font-weight: bold;
        }
        @media (max-width: 768px) {
            .file-list, .preview {
                width: 100%;
                float: none;
                margin-bottom: 20px;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="file-list">
            <h2>Files</h2>
            <div class="file-upload">
                <form method="post" enctype="multipart/form-data">
                    <input type="file" name="uploadedFile" id="fileInput" accept=".txt,.php,.js,.css,.html,.jpg,.jpeg,.png,.gif,.pdf,.docx">
                    <button type="submit">Upload and Preview</button>
                    <button type="button" onclick="previewUploadedFile()">Preview Client-Side</button>
                </form>
                <?php echo $uploadMessage; ?>
            </div>
            <ul id="fileList">
                <?php
                $directory = './files'; // Directory containing files
                if (is_dir($directory)) {
                    $files = scandir($directory);
                    foreach ($files as $file) {
                        if ($file !== '.' && $file !== '..') {
                            echo "<li data-file='$file'>$file</li>";
                        }
                    }
                } else {
                    echo "<li>No files found. Create a 'files' directory.</li>";
                }
                ?>
            </ul>
        </div>
        <div class="preview" id="preview">
            <h2>Preview</h2>
            <?php
            if (isset($_GET['file'])) {
                $file = $_GET['file'];
                $filePath = $directory . '/' . $file;

                // Security check to prevent directory traversal
                if (strpos($filePath, '..') !== false || !file_exists($filePath)) {
                    echo '<p class="error">Invalid file path. Ensure the file exists in the files directory.</p>';
                } else {
                    $extension = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
                    echo "<h3>$file</h3>";
                    switch ($extension) {
                        case 'txt':
                        case 'php':
                        case 'js':
                        case 'css':
                        case 'html':
                            $content = htmlspecialchars(file_get_contents($filePath));
                            echo "<pre><code>$content</code></pre>";
                            break;
                        case 'jpg':
                        case 'jpeg':
                        case 'png':
                        case 'gif':
                            echo "<img src='$filePath' alt='Image Preview'>";
                            break;
                        case 'pdf':
                            echo "<iframe src='$filePath' style='width:100%;height:500px;'></iframe>";
                            break;
                        case 'docx':
                            $tempDir = './temp';
                            if (!is_dir($tempDir)) {
                                mkdir($tempDir, 0777, true);
                            }
                            if (!is_writable($tempDir)) {
                                echo "<p class='error'>Error: 'temp' directory is not writable. Please set permissions (e.g., Full control for Everyone).</p>";
                                break;
                            }
                            $pdfPath = $tempDir . '/' . pathinfo($file, PATHINFO_FILENAME) . '.pdf';

                            // Try LibreOffice conversion
                            $libreOfficePath = '"C:\Program Files\LibreOffice\program\soffice.exe"';
                            $command = "$libreOfficePath --headless --convert-to pdf --outdir " . escapeshellarg(realpath($tempDir)) . " " . escapeshellarg(realpath($filePath)) . " 2>&1";
                            exec($command, $output, $returnVar);
                            if ($returnVar === 0 && file_exists($pdfPath)) {
                                echo "<iframe src='$pdfPath' style='width:100%;height:500px;'></iframe>";
                            } else {
                                // Fallback to PHPWord + TCPDF
                                if (class_exists('PhpOffice\PhpWord\IOFactory') && class_exists('TCPDF')) {
                                    try {
                                        $phpWord = IOFactory::load($filePath);
                                        $pdfWriter = IOFactory::createWriter($phpWord, 'PDF');
                                        $pdfWriter->save($pdfPath);
                                        if (file_exists($pdfPath)) {
                                            echo "<iframe src='$pdfPath' style='width:100%;height:500px;'></iframe>";
                                        } else {
                                            echo '<p class="error">Error generating PDF from .docx file.</p>';
                                        }
                                    } catch (Exception $e) {
                                        echo '<p class="error">Error processing .docx file with PHPWord: ' . htmlspecialchars($e->getMessage()) . '</p>';
                                    }
                                } else {
                                    echo '<p class="error">LibreOffice conversion failed, and PHPWord/TCPDF not installed. Please install LibreOffice or run "composer require phpoffice/phpword" in C:\xampp\htdocs\GitHub\Arc_Hive-main and ensure ext-gd is enabled.</p>';
                                }
                            }

                            // Clean up old PDFs (older than 1 hour)
                            foreach (glob($tempDir . '/*.pdf') as $oldPdf) {
                                if (filemtime($oldPdf) < time() - 3600) {
                                    unlink($oldPdf);
                                }
                            }
                            break;
                        default:
                            echo '<p class="error">Unsupported file type: ' . htmlspecialchars($extension) . '.</p>';
                    }
                }
            } else {
                echo '<p>Select a file to preview.</p>';
            }
            ?>
        </div>
    </div>

    <script>
        // Handle directory file clicks
        document.querySelectorAll('#fileList li').forEach(item => {
            item.addEventListener('click', function() {
                const file = this.getAttribute('data-file');
                window.location.href = `?file=${encodeURIComponent(file)}`;
            });
        });

        // Handle client-side file preview
        function previewUploadedFile() {
            const fileInput = document.getElementById('fileInput');
            const file = fileInput.files[0];
            const preview = document.getElementById('preview');

            if (!file) {
                preview.innerHTML = '<h2>Preview</h2><p class="error">No file selected.</p>';
                return;
            }

            const extension = file.name.split('.').pop().toLowerCase();
            preview.innerHTML = `<h2>Preview: ${file.name}</h2>`;

            if (['txt', 'php', 'js', 'css', 'html'].includes(extension)) {
                const reader = new FileReader();
                reader.onload = function(e) {
                    const content = e.target.result.replace(/</g, '&lt;').replace(/>/g, '&gt;');
                    preview.innerHTML += `<pre><code>${content}</code></pre>`;
                };
                reader.readAsText(file);
            } else if (['jpg', 'jpeg', 'png', 'gif'].includes(extension)) {
                const reader = new FileReader();
                reader.onload = function(e) {
                    preview.innerHTML += `<img src="${e.target.result}" alt="Image Preview">`;
                };
                reader.readAsDataURL(file);
            } else if (extension === 'pdf') {
                const reader = new FileReader();
                reader.onload = function(e) {
                    preview.innerHTML += `<iframe src="${e.target.result}" style="width:100%;height:500px;"></iframe>`;
                };
                reader.readAsDataURL(file);
            } else if (extension === 'docx') {
                preview.innerHTML += '<p class="error">.docx files cannot be previewed client-side. Please use "Upload and Preview" to save to the server and preview as PDF with original formatting.</p>';
            } else {
                preview.innerHTML += '<p class="error">Unsupported file type: ' + extension + '.</p>';
            }
        }
    </script>
</body>
</html>