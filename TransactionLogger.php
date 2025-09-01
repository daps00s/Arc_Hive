<?php
class TransactionLogger
{
    private $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    /**
     * Logs a transaction to the transactions table.
     * @param ?int $userId User ID (nullable for anonymous actions)
     * @param ?int $fileId File ID (nullable for non-file actions)
     * @param ?int $usersDepartmentId User's department ID
     * @param string $transactionType Type of transaction (upload, scan, digital_access, physical_request, relocation, login)
     * @param string $status Transaction status (completed, pending, failed)
     * @param string $description Transaction description
     */
    public function logTransaction(
        ?int $userId,
        ?int $fileId,
        ?int $usersDepartmentId,
        string $transactionType,
        string $status,
        string $description
    ): void {
        $stmt = $this->pdo->prepare("
            INSERT INTO transactions (
                user_id, file_id, users_department_id, transaction_type,
                transaction_status, transaction_time, description
            )
            VALUES (?, ?, ?, ?, ?, NOW(), ?)
        ");
        $stmt->execute([
            $userId,
            $fileId,
            $usersDepartmentId,
            $transactionType,
            $status,
            $description
        ]);
    }
}
