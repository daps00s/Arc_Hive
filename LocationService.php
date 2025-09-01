<?php
class LocationService
{
    private $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    /**
     * Returns the full hierarchical location path for a file.
     * @param int $fileId The ID of the file
     * @return ?array Path and details, or null if file not found
     */
    public function getFullLocationPath(int $fileId): ?array
    {
        // Fetch file and its storage location
        $stmt = $this->pdo->prepare("
            SELECT f.department_id, f.sub_department_id, sl.room, sl.cabinet, sl.layer, sl.box, sl.folder
            FROM files f
            LEFT JOIN storage_locations sl ON f.location_id = sl.location_id
            WHERE f.file_id = ?
        ");
        $stmt->execute([$fileId]);
        $file = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$file) {
            return null;
        }

        // Build department hierarchy
        $departmentPath = $this->getDepartmentPath($file['department_id'], $file['sub_department_id']);

        // Construct full path
        $locationPath = array_filter([
            $departmentPath,
            $file['room'],
            $file['cabinet'],
            $file['layer'],
            $file['box'],
            $file['folder']
        ]);

        return [
            'path' => implode(' → ', $locationPath),
            'details' => [
                'department' => $departmentPath,
                'room' => $file['room'],
                'cabinet' => $file['cabinet'],
                'layer' => $file['layer'],
                'box' => $file['box'],
                'folder' => $file['folder']
            ]
        ];
    }

    /**
     * Recursively builds the department hierarchy path.
     * @param ?int $departmentId Main department ID
     * @param ?int $subDepartmentId Sub-department ID
     * @return string Department path (e.g., "College of Education → Bachelor of Elementary Education")
     */
    private function getDepartmentPath(?int $departmentId, ?int $subDepartmentId): string
    {
        $path = [];

        // Start with sub-department if provided
        if ($subDepartmentId) {
            $stmt = $this->pdo->prepare("
                SELECT department_name, parent_department_id
                FROM departments
                WHERE department_id = ?
            ");
            $stmt->execute([$subDepartmentId]);
            $subDept = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($subDept) {
                $path[] = $subDept['department_name'];
                $departmentId = $subDept['parent_department_id'] ?? $departmentId;
            }
        }

        // Recursively build parent department path
        while ($departmentId) {
            $stmt = $this->pdo->prepare("
                SELECT department_name, parent_department_id
                FROM departments
                WHERE department_id = ?
            ");
            $stmt->execute([$departmentId]);
            $dept = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($dept) {
                $path[] = $dept['department_name'];
                $departmentId = $dept['parent_department_id'];
            } else {
                break;
            }
        }

        return implode(' → ', array_reverse($path));
    }
}
