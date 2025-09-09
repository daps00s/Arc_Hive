<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>File Preview</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            max-width: 800px;
            margin: 0 auto;
            padding: 20px;
            background-color: #f4f4f4;
        }
        .container {
            background: white;
            padding: 20px;
            border-radius: 8px;
            box-shadow: 0 0 10px rgba(0,0,0,0.1);
        }
        h1 {
            text-align: center;
            color: #333;
        }
        .upload-form {
            margin-bottom: 20px;
            text-align: center;
        }
        input[type="file"] {
            padding: 10px;
            margin: 10px 0;
        }
        button {
            padding: 10px 20px;
            background-color: #007bff;
            color: white;
            border: none;
            border-radius: 4px;
            cursor: pointer;
        }
        button:hover {
            background-color: #0056b3;
        }
        #preview {
            margin-top: 20px;
            border: 1px solid #ddd;
            padding: 10px;
            border-radius: 4px;
            min-height: 200px;
            text-align: center;
        }
        #preview img {
            max-width: 100%;
            max-height: 400px;
            object-fit: contain;
        }
        #preview pre {
            text-align: left;
            background: #f8f8f8;
            padding: 10px;
            border-radius: 4px;
            max-height: 400px;
            overflow-y: auto;
        }
        .error {
            color: red;
            text-align: center;
        }
        .success {
            color: green;
            text-align: center;
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>File Preview</h1>
        <form class="upload-form" method="POST" enctype="multipart/form-data">
            <input type="file" id="fileInput" name="file" accept="image/*,text/plain,application/pdf" required>
            <button type="submit" name="submit">Upload and Preview</button>
        </form>
        <div id="preview">
            <?php
            if (isset($_POST['submit']) && isset($_FILES['file'])) {
                $allowedTypes = ['image/jpeg', 'image/png', 'image/gif', 'text/plain', 'application/pdf'];
                $fileType = $_FILES['file']['type'];
                $fileName = $_FILES['file']['name'];
                $fileTmpPath = $_FILES['file']['tmp_name'];
                $fileError = $_FILES['file']['error'];
                $uploadDir = 'uploads/';
                $uploadPath = $uploadDir . basename($fileName);

                // Create uploads directory if it doesn't exist
                if (!is_dir($uploadDir)) {
                    mkdir($uploadDir, 0755, true);
                }

                // Validate file
                if ($fileError === UPLOAD_ERR_OK) {
                    if (in_array($fileType, $allowedTypes)) {
                        // Move uploaded file to uploads directory
                        if (move_uploaded_file($fileTmpPath, $uploadPath)) {
                            echo "<p class='success'>File uploaded successfully!</p>";

                            // Display preview based on file type
                            if (in_array($fileType, ['image/jpeg', 'image/png', 'image/gif'])) {
                                echo "<img src='$uploadPath' alt='Preview'>";
                            } elseif ($fileType === 'text/plain') {
                                $content = htmlspecialchars(file_get_contents($uploadPath));
                                echo "<pre>$content</pre>";
                            } elseif ($fileType === 'application/pdf') {
                                // For PDF, we'll use an embed tag
                                echo "<embed src='$uploadPath' width='100%' height='400px' type='application/pdf'>";
                            }
                        } else {
                            echo "<p class='error'>Failed to move uploaded file.</p>";
                        }
                    } else {
                        echo "<p class='error'>Unsupported file type. Please upload an image, text, or PDF file.</p>";
                    }
                } else {
                    echo "<p class='error'>Error uploading file: " . $fileError . "</p>";
                }
            }
            ?>
        </div>
    </div>

    <script>
        // Client-side preview for images and text files
        document.getElementById('fileInput').addEventListener('change', function(event) {
            const file = event.target.files[0];
            const preview = document.getElementById('preview');
            
            // Clear previous preview
            preview.innerHTML = '';

            if (file) {
                const fileType = file.type;
                const reader = new FileReader();

                if (fileType.startsWith('image/')) {
                    reader.onload = function(e) {
                        const img = document.createElement('img');
                        img.src = e.target.result;
                        img.alt = 'Preview';
                        preview.appendChild(img);
                    };
                    reader.readAsDataURL(file);
                } else if (fileType === 'text/plain') {
                    reader.onload = function(e) {
                        const pre = document.createElement('pre');
                        pre.textContent = e.target.result;
                        preview.appendChild(pre);
                    };
                    reader.readAsText(file);
                } else if (fileType === 'application/pdf') {
                    const p = document.createElement('p');
                    p.textContent = 'PDF preview will be shown after upload.';
                    preview.appendChild(p);
                } else {
                    const p = document.createElement('p');
                    p.className = 'error';
                    p.textContent = 'Please select an image, text, or PDF file.';
                    preview.appendChild(p);
                }
            }
        });
    </script>
</body>
</html>