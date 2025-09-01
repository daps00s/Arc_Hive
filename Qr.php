<?php
// Include the QR code library
require 'phpqrcode/qrlib.php';

// Directory to store generated QR codes
$tempDir = 'qrcodes/';
if (!file_exists($tempDir)) {
    mkdir($tempDir, 0777, true);
}

// Handle form submission
$qrcode = '';
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['text'])) {
    $text = filter_input(INPUT_POST, 'text', FILTER_SANITIZE_STRING);
    if (strlen($text) > 1000) {
        $error = 'Input text is too long. Please keep it under 1000 characters.';
    } else {
        $filename = $tempDir . 'qr_' . md5($text . time()) . '.png';
        QRcode::png($text, $filename, QR_ECLEVEL_L, 10);
        $qrcode = $filename;
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>QR Code Generator</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            display: flex;
            flex-direction: column;
            align-items: center;
            background-color: #f0f0f0;
            margin: 0;
            padding: 20px;
        }
        .container {
            background: white;
            padding: 20px;
            border-radius: 8px;
            box-shadow: 0 0 10px rgba(0,0,0,0.1);
            text-align: center;
            max-width: 500px;
            width: 100%;
        }
        input[type="text"] {
            width: 100%;
            padding: 10px;
            margin: 10px 0;
            border: 1px solid #ccc;
            border-radius: 4px;
            box-sizing: border-box;
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
        .error {
            color: red;
            margin: 10px 0;
        }
        img {
            margin-top: 20px;
            max-width: 100%;
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>QR Code Generator</h1>
        <form method="post">
            <input type="text" name="text" placeholder="Enter text or URL" required>
            <button type="submit">Generate QR Code</button>
        </form>
        <?php if ($error): ?>
            <p class="error"><?php echo htmlspecialchars($error); ?></p>
        <?php endif; ?>
        <?php if ($qrcode): ?>
            <img src="<?php echo htmlspecialchars($qrcode); ?>" alt="QR Code">
        <?php endif; ?>
    </div>
</body>
</html>    