<?php
// Scanner.php: Web-based QR code scanner using html5-qrcode
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>QR Code Scanner</title>
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
        #reader {
            width: 100%;
            max-width: 400px;
            margin: 10px 0;
        }
        input[type="file"] {
            margin: 10px 0;
        }
        button {
            padding: 10px 20px;
            background-color: #007bff;
            color: white;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            margin: 5px;
        }
        button:hover {
            background-color: #0056b3;
        }
        .error {
            color: red;
            margin: 10px 0;
        }
        .result {
            color: green;
            margin: 10px 0;
            word-break: break-all;
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>QR Code Scanner</h1>
        <div id="reader"></div>
        <input type="file" id="qr-input-file" accept="image/*">
        <button onclick="startScanner()">Start Webcam Scan</button>
        <button onclick="stopScanner()">Stop Webcam Scan</button>
        <div id="result" class="result"></div>
        <div id="error" class="error"></div>
    </div>

    <script src="https://unpkg.com/html5-qrcode@2.3.8/html5-qrcode.min.js"></script>
    <script>
        let html5QrcodeScanner = null;

        function onScanSuccess(decodedText, decodedResult) {
            // Display the scanned result
            document.getElementById('result').innerText = `Scanned: ${decodedText}`;
            document.getElementById('error').innerText = '';
            // Optionally stop the scanner after a successful scan
            stopScanner();
        }

        function onScanFailure(error) {
            // Ignore errors during scanning (e.g., no QR code in frame)
            console.warn(`Scan error: ${error}`);
        }

        function startScanner() {
            // Clear previous results/errors
            document.getElementById('result').innerText = '';
            document.getElementById('error').innerText = '';

            // Initialize scanner
            html5QrcodeScanner = new Html5Qrcode("reader");
            const config = { fps: 10, qrbox: { width: 250, height: 250 } };

            // Start webcam scanning (prefer environment-facing camera)
            html5QrcodeScanner.start(
                { facingMode: "environment" },
                config,
                onScanSuccess,
                onScanFailure
            ).catch(err => {
                document.getElementById('error').innerText = `Error starting scanner: ${err}`;
            });
        }

        function stopScanner() {
            if (html5QrcodeScanner) {
                html5QrcodeScanner.stop().then(() => {
                    html5QrcodeScanner.clear();
                    document.getElementById('result').innerText = 'Scanner stopped.';
                    document.getElementById('error').innerText = '';
                }).catch(err => {
                    document.getElementById('error').innerText = `Error stopping scanner: ${err}`;
                });
            }
        }

        // Handle file upload
        document.getElementById('qr-input-file').addEventListener('change', (e) => {
            const file = e.target.files[0];
            if (file) {
                document.getElementById('result').innerText = '';
                document.getElementById('error').innerText = '';

                const html5Qrcode = new Html5Qrcode("reader");
                html5Qrcode.scanFile(file, true)
                    .then(decodedText => {
                        document.getElementById('result').innerText = `Scanned: ${decodedText}`;
                    })
                    .catch(err => {
                        document.getElementById('error').innerText = `Error scanning file: ${err}`;
                    });
            }
        });
    </script>
</body>
</html>