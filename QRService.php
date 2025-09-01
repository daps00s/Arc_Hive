<?php
require_once 'phpqrcode/qrlib.php'; // Use require_once

class QRService
{
    private $pdo;
    private $baseUrl = 'http://localhost/Arc_HIVE-main/'; // Replace with production URL

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    public function generateQRCode(int $fileId, string $outputPath): string
    {
        $fileUrl = $this->baseUrl . $fileId;
        $qrPath = $outputPath . "file_{$fileId}_qr.png";
        QRcode::png($fileUrl, $qrPath, QR_ECLEVEL_H, 4, 2); // High error correction, 4x4 pixels, 2px margin
        return $qrPath;
    }

    public function attachQRToFile(int $fileId, string $qrPath, string $filePath): void
    {
        // Placeholder for TCPDF or similar
        /*
        require_once 'tcpdf/tcpdf.php';
        $pdf = new TCPDF();
        $pdf->AddPage();
        $pdf->Image($qrPath, 10, 10, 30, 30); // Add QR at position (10,10), size 30x30
        $pdf->Output($filePath, 'F'); // Overwrite original file
        */
    }

    public function storeQRMetadata(int $fileId, string $qrPath): void
    {
        $stmt = $this->pdo->prepare("UPDATE files SET qr_path = ? WHERE file_id = ?");
        $stmt->execute([$qrPath, $fileId]);
    }
}
