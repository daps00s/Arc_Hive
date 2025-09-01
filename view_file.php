<?php
session_start();
require 'db_connection.php';

$fileId = filter_var($_GET['file_id'], FILTER_SANITIZE_NUMBER_INT);

try {
    $stmt = $pdo->prepare("SELECT file_name FROM files WHERE file_id = ? AND user_id = ? AND file_status != 'deleted'");
    $stmt->execute([$fileId, $_SESSION['user_id']]);
    $file = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($file) {
        header('Location: Uploads/' . $file['file_name']);
    } else {
        header('Location: dashboard.php?error=access_denied');
    }
} catch (Exception $e) {
    error_log("View file error: " . $e->getMessage());
    header('Location: dashboard.php?error=access_denied');
}
exit;
