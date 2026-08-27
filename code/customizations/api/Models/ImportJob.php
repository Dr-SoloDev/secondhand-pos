<?php
/**
 * ImportJob Model — จัดการข้อมูลการนำเข้า Excel
 */
class ImportJob extends Model
{
    protected $table = 'import_jobs';
    
    /**
     * สร้าง import job ใหม่
     */
    public function create(array $data): int
    {
        $sql = "INSERT INTO import_jobs (branch_id, user_id, filename, file_hash, import_type, status)
                VALUES (:branch_id, :user_id, :filename, :file_hash, :import_type, :status)";
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            ':branch_id' => $data['branch_id'],
            ':user_id' => $data['user_id'],
            ':filename' => $data['filename'],
            ':file_hash' => $data['file_hash'],
            ':import_type' => $data['import_type'],
            ':status' => $data['status'],
        ]);
        
        return $this->db->lastInsertId();
    }
    
    /**
     * ดึงข้อมูล import job ตาม ID
     */
    public function getById(int $id): ?array
    {
        $sql = "SELECT * FROM import_jobs WHERE id = :id";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([':id' => $id]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }
    
    /**
     * ค้นหา import job ตาม file hash
     */
    public function findByHash(string $hash): ?array
    {
        $sql = "SELECT * FROM import_jobs WHERE file_hash = :hash ORDER BY id DESC LIMIT 1";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([':hash' => $hash]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }
    
    /**
     * อัปเดตสถานะ
     */
    public function updateStatus(int $id, string $status): bool
    {
        $sql = "UPDATE import_jobs SET status = :status, updated_at = NOW() WHERE id = :id";
        $stmt = $this->db->prepare($sql);
        return $stmt->execute([':status' => $status, ':id' => $id]);
    }
    
    /**
     * อัปเดต field ใดๆ
     */
    public function updateField(int $id, string $field, $value): bool
    {
        $allowedFields = ['imported_at', 'completed_at', 'error_log', 'total_rows', 'processed_rows', 'success_rows', 'failed_rows'];
        if (!in_array($field, $allowedFields)) {
            return false;
        }
        
        $sql = "UPDATE import_jobs SET {$field} = :value, updated_at = NOW() WHERE id = :id";
        $stmt = $this->db->prepare($sql);
        return $stmt->execute([':value' => $value, ':id' => $id]);
    }
    
    /**
     * อัปเดตผลลัพธ์
     */
    public function updateResult(int $id, array $result): bool
    {
        $sql = "UPDATE import_jobs SET 
                    total_rows = :total_rows,
                    success_rows = :success_rows,
                    failed_rows = :failed_rows,
                    error_log = :error_log,
                    completed_at = NOW(),
                    updated_at = NOW()
                WHERE id = :id";
        
        $stmt = $this->db->prepare($sql);
        return $stmt->execute([
            ':total_rows' => $result['total_rows'],
            ':success_rows' => $result['success_rows'],
            ':failed_rows' => $result['failed_rows'],
            ':error_log' => json_encode($result['errors'] ?? []),
            ':id' => $id,
        ]);
    }
    
    /**
     * ดึงประวัติการนำเข้าตาม branch (พร้อม search, filter)
     */
    public function getByBranch(int $branchId, int $limit = 20, int $offset = 0, string $search = '', string $status = '', string $importType = ''): array
    {
        $sql = "SELECT ij.*, u.full_name as user_name
                FROM import_jobs ij
                LEFT JOIN users u ON ij.user_id = u.id
                WHERE ij.branch_id = :branch_id";
        
        $params = [':branch_id' => $branchId];
        
        // Search condition
        if (!empty($search)) {
            $sql .= " AND ij.filename LIKE :search";
            $params[':search'] = "%{$search}%";
        }
        
        // Status filter
        if (!empty($status)) {
            $sql .= " AND ij.status = :status";
            $params[':status'] = $status;
        }
        
        // Import type filter
        if (!empty($importType)) {
            $sql .= " AND ij.import_type = :import_type";
            $params[':import_type'] = $importType;
        }
        
        $sql .= " ORDER BY ij.created_at DESC LIMIT :limit OFFSET :offset";
        
        $stmt = $this->db->prepare($sql);
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value);
        }
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();
        
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    /**
     * นับจำนวนประวัติการนำเข้าตาม branch (พร้อม search, filter)
     */
    public function countByBranch(int $branchId, string $search = '', string $status = '', string $importType = ''): int
    {
        $sql = "SELECT COUNT(*) FROM import_jobs WHERE branch_id = :branch_id";
        $params = [':branch_id' => $branchId];
        
        if (!empty($search)) {
            $sql .= " AND filename LIKE :search";
            $params[':search'] = "%{$search}%";
        }
        
        if (!empty($status)) {
            $sql .= " AND status = :status";
            $params[':status'] = $status;
        }
        
        if (!empty($importType)) {
            $sql .= " AND import_type = :import_type";
            $params[':import_type'] = $importType;
        }
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return (int) $stmt->fetchColumn();
    }
}
