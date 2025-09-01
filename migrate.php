<?php
require 'db_connection.php';

try {
    $migrations = [
        // Add qr_path to files table
        "ALTER TABLE files ADD qr_path VARCHAR(512) DEFAULT NULL COMMENT 'Path to static QR code image'",
        // Add transaction_type to transactions table
        "ALTER TABLE transactions ADD transaction_type ENUM('upload', 'scan', 'digital_access', 'physical_request', 'relocation', 'login') NOT NULL DEFAULT 'upload'"
    ];

    foreach ($migrations as $sql) {
        try {
            $pdo->exec($sql);
            echo "Applied migration: $sql\n";
        } catch (PDOException $e) {
            if ($e->getCode() == '42S21') { // Duplicate column error
                echo "Column already exists, skipping: $sql\n";
            } else {
                throw $e;
            }
        }
    }

    // Update existing transaction_type values for login attempts
    $pdo->exec("
        UPDATE transactions
        SET transaction_type = 'login'
        WHERE transaction_id IN (157, 158)
    ");
    echo "Updated existing transactions with transaction_type = 'login'\n";

    echo "Migration completed successfully.\n";
} catch (Exception $e) {
    error_log("Migration failed: " . $e->getMessage());
    echo "Migration failed: " . $e->getMessage() . "\n";
}
