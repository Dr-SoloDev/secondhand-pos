<?php
/**
 * ImportController — จัดการการนำเข้าข้อมูลจาก Excel
 * 
 * รองรับ:
 * - purchase_items: นำเข้ารายการรับซื้อ
 * - sale_lots: นำเข้ารายการขาย Lot
 * - sellers: นำเข้าข้อมูลผู้ขาย
 */
class ImportController extends Controller
{
    /**
     * POST /import/upload — อัปโหลดไฟล์ Excel
     */
    public function upload()
    {
        $this->requireAuth(['admin', 'manager', 'super_manager']);
        
        // ตรวจสอบไฟล์
        if (!isset($_FILES['excel_file'])) {
            Response::error('ไม่พบไฟล์ที่อัปโหลด', 400);
        }
        
        $file = $_FILES['excel_file'];
        $importType = $this->sanitizeInput($_POST['import_type'] ?? 'purchase_items');
        $branchId = $this->resolveBranchId();
        
        // ตรวจสอบประเภทไฟล์
        $allowedTypes = ['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'application/vnd.ms-excel'];
        $allowedExtensions = ['xlsx', 'xls'];
        $fileExtension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        
        if (!in_array($file['type'], $allowedTypes) && !in_array($fileExtension, $allowedExtensions)) {
            Response::error('อนุญาตเฉพาะไฟล์ .xlsx หรือ .xls เท่านั้น', 400);
        }
        
        // ตรวจสอบขนาดไฟล์ (สูงสุด 10MB)
        if ($file['size'] > 10 * 1024 * 1024) {
            Response::error('ไฟล์มีขนาดใหญ่เกินไป (สูงสุด 10MB)', 400);
        }
        
        // คำนวณ file hash
        $fileHash = hash_file('sha256', $file['tmp_name']);
        
        // ตรวจสอบ duplicate
        $model = new ImportJob();
        $existing = $model->findByHash($fileHash);
        if ($existing) {
            Response::error('ไฟล์นี้ถูกนำเข้าไปแล้ว (เมื่อ ' . $existing['created_at'] . ')', 409);
        }
        
        // บันทึก import job
        $jobData = [
            'branch_id' => $branchId,
            'user_id' => $this->getUserId(),
            'filename' => $file['name'],
            'file_hash' => $fileHash,
            'import_type' => $importType,
            'status' => 'pending',
        ];
        
        $jobId = $model->create($jobData);
        
        // เก็บไฟล์ชั่วคราว
        $uploadDir = sys_get_temp_dir() . '/import_jobs';
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0755, true);
        }
        
        $tempPath = $uploadDir . '/' . $jobId . '_' . $file['name'];
        if (!move_uploaded_file($file['tmp_name'], $tempPath)) {
            $model->updateStatus($jobId, 'failed');
            Response::error('ไม่สามารถเก็บไฟล์ได้', 500);
        }
        
        Response::success('อัปโหลดไฟล์สำเร็จ', [
            'job_id' => $jobId,
            'filename' => $file['name'],
            'import_type' => $importType,
        ]);
    }
    
    /**
     * POST /import/process — ประมวลผลไฟล์ Excel
     */
    public function process()
    {
        $this->requireAuth(['admin', 'manager', 'super_manager']);
        
        $data = $this->getRequestData();
        $jobId = intval($data['job_id'] ?? 0);
        
        if (!$jobId) {
            Response::error('ต้องระบุ job_id', 400);
        }
        
        $model = new ImportJob();
        $job = $model->getById($jobId);
        
        if (!$job) {
            Response::error('ไม่พบ job ที่ระบุ', 404);
        }
        
        if ($job['status'] !== 'pending') {
            Response::error('job นี้ถูกประมวลผลไปแล้ว', 400);
        }
        
        // อัปเดตสถานะเป็น processing
        $model->updateStatus($jobId, 'processing');
        $model->updateField($jobId, 'imported_at', date('Y-m-d H:i:s'));
        
        // หาไฟล์
        $uploadDir = sys_get_temp_dir() . '/import_jobs';
        $tempPath = $uploadDir . '/' . $jobId . '_' . $job['filename'];
        
        if (!file_exists($tempPath)) {
            $model->updateStatus($jobId, 'failed');
            Response::error('ไม่พบไฟล์', 404);
        }
        
        try {
            // ประมวลผลตามประเภท
            $result = match($job['import_type']) {
                'purchase_items' => $this->processPurchaseItems($tempPath, $job),
                'sale_lots' => $this->processSaleLots($tempPath, $job),
                'sellers' => $this->processSellers($tempPath, $job),
                default => throw new Exception('ประเภทการนำเข้าไม่ถูกต้อง'),
            };
            
            // อัปเดตผลลัพธ์
            $model->updateResult($jobId, $result);
            $model->updateStatus($jobId, 'completed');
            
            // ลบไฟล์ชั่วคราว
            @unlink($tempPath);
            
            Response::success('นำเข้าข้อมูลสำเร็จ', $result);
            
        } catch (Exception $e) {
            $model->updateStatus($jobId, 'failed');
            $model->updateField($jobId, 'error_log', json_encode(['error' => $e->getMessage()]));
            Response::error('นำเข้าข้อมูลไม่สำเร็จ: ' . $e->getMessage(), 500);
        }
    }
    
    /**
     * GET /import/history — ดูประวัติการนำเข้า (พร้อม pagination, search, filter)
     */
    public function history()
    {
        $this->requireAuth(['admin', 'manager', 'super_manager']);
        
        $branchId = $this->resolveBranchId();
        
        // Pagination params
        $page = max(1, intval($_GET['page'] ?? 1));
        $limit = min(100, max(10, intval($_GET['limit'] ?? 20)));
        $offset = ($page - 1) * $limit;
        
        // Search & Filter params
        $search = $this->sanitizeInput($_GET['search'] ?? '');
        $status = $this->sanitizeInput($_GET['status'] ?? '');
        $importType = $this->sanitizeInput($_GET['import_type'] ?? '');
        
        $model = new ImportJob();
        $jobs = $model->getByBranch($branchId, $limit, $offset, $search, $status, $importType);
        $total = $model->countByBranch($branchId, $search, $status, $importType);
        $totalPages = ceil($total / $limit);
        
        Response::success('ดึงประวัติการนำเข้าสำเร็จ', [
            'items' => $jobs,
            'pagination' => [
                'page' => $page,
                'limit' => $limit,
                'total' => $total,
                'total_pages' => $totalPages,
            ],
        ]);
    }
    
    /**
     * POST /import/validate — ตรวจสอบไฟล์ก่อนนำเข้า
     */
    public function validate()
    {
        $this->requireAuth(['admin', 'manager', 'super_manager']);
        
        $data = $this->getRequestData();
        $jobId = intval($data['job_id'] ?? 0);
        
        if (!$jobId) {
            Response::error('ต้องระบุ job_id', 400);
        }
        
        $model = new ImportJob();
        $job = $model->getById($jobId);
        
        if (!$job) {
            Response::error('ไม่พบ job ที่ระบุ', 404);
        }
        
        // หาไฟล์
        $uploadDir = sys_get_temp_dir() . '/import_jobs';
        $tempPath = $uploadDir . '/' . $jobId . '_' . $job['filename'];
        
        if (!file_exists($tempPath)) {
            Response::error('ไม่พบไฟล์', 404);
        }
        
        // ตรวจสอบไฟล์
        $validation = $this->validateExcelFile($tempPath, $job['import_type']);
        
        Response::success('ตรวจสอบไฟล์สำเร็จ', $validation);
    }
    
    /**
     * ประมวลผล purchase_items
     */
    private function processPurchaseItems(string $filePath, array $job): array
    {
        $reader = $this->getExcelReader($filePath);
        $sheet = $reader->getActiveSheet();
        $rows = $sheet->toArray();
        
        // ข้าม header row
        $headers = array_shift($rows);
        
        // จับคู่คอลัมน์แบบยืดหยุ่น
        $columnMap = $this->mapColumns($headers, [
            'date' => ['date', 'วันที่', 'purchase_date', 'วันเดือนปี'],
            'item' => ['item', 'product', 'ชื่อสินค้า', 'รายการ', 'item_name'],
            'category' => ['category', 'หมวดหมู่', 'หมวด', 'category_name'],
            'quantity' => ['weight', 'kg', 'quantity', 'จำนวน', 'น้ำหนัก'],
            'price' => ['price', 'ราคา', 'unit_price', 'ราคาต่อหน่วย'],
            'total' => ['total', 'ยอด', 'amount', 'ยอดรวม'],
            'unit' => ['unit', 'หน่วย'],
        ]);
        
        $successCount = 0;
        $failedCount = 0;
        $errors = [];
        
        foreach ($rows as $index => $row) {
            try {
                // ตรวจสอบข้อมูลจำเป็น
                $date = $row[$columnMap['date']] ?? null;
                $item = $row[$columnMap['item']] ?? null;
                
                if (empty($date) || empty($item)) {
                    throw new Exception('แถว ' . ($index + 2) . ': ขาดข้อมูลวันที่หรือสินค้า');
                }
                
                // สร้าง purchase order
                $poData = [
                    'branch_id' => $job['branch_id'],
                    'seller_id' => null, // ไม่มีใน Excel
                    'reference_no' => 'IMP-' . date('Ymd') . '-' . str_pad($index + 1, 4, '0', STR_PAD_LEFT),
                    'status' => 'completed',
                    'payment_method' => 'cash',
                    'total_amount' => floatval($row[$columnMap['total']] ?? 0),
                    'notes' => 'นำเข้าจาก Excel: ' . $job['filename'],
                    'import_job_id' => $job['id'],
                ];
                
                // บันทึก purchase order
                $poModel = new PurchaseOrder();
                $poId = $poModel->create($poData);
                
                // บันทึก purchase order items
                $itemData = [
                    'purchase_order_id' => $poId,
                    'item_name' => $item,
                    'category_name' => $row[$columnMap['category']] ?? '',
                    'quantity' => floatval($row[$columnMap['quantity']] ?? 0),
                    'unit_price' => floatval($row[$columnMap['price']] ?? 0),
                    'total_price' => floatval($row[$columnMap['total']] ?? 0),
                    'unit' => $row[$columnMap['unit']] ?? 'กก.',
                    'purchase_date' => $this->parseDate($date),
                ];
                
                $itemModel = new PurchaseOrderItem();
                $itemModel->create($itemData);
                
                $successCount++;
                
            } catch (Exception $e) {
                $failedCount++;
                $errors[] = [
                    'row' => $index + 2,
                    'message' => $e->getMessage(),
                ];
            }
        }
        
        return [
            'total_rows' => count($rows),
            'success_rows' => $successCount,
            'failed_rows' => $failedCount,
            'errors' => $errors,
        ];
    }
    
    /**
     * ประมวลผล sale_lots
     * 
     * Excel columns:
     * - buyer_name / ผู้ซื้อ (required)
     * - sale_date / วันที่ขาย (required)
     * - item_name / ชื่อสินค้า (required)
     * - quantity_kg / น้ำหนัก (required)
     * - unit_price / ราคา/กก. (required)
     * - notes / หมายเหตุ (optional)
     */
    private function processSaleLots(string $filePath, array $job): array
    {
        $reader = $this->getExcelReader($filePath);
        $sheet = $reader->getActiveSheet();
        $rows = $sheet->toArray();
        
        // ข้าม header row
        $headers = array_shift($rows);
        
        // จับคู่คอลัมน์แบบยืดหยุ่น
        $columnMap = $this->mapColumns($headers, [
            'buyer' => ['buyer', 'buyer_name', 'ผู้ซื้อ', 'customer'],
            'sale_date' => ['sale_date', 'date', 'วันที่ขาย', 'วันที่'],
            'item' => ['item', 'item_name', 'product', 'ชื่อสินค้า', 'สินค้า'],
            'quantity' => ['quantity', 'quantity_kg', 'kg', 'น้ำหนัก', 'จำนวน'],
            'price' => ['price', 'unit_price', 'ราคา', 'ราคา/กก.', 'ราคาต่อหน่วย'],
            'notes' => ['notes', 'note', 'หมายเหตุ'],
        ]);
        
        $successCount = 0;
        $failedCount = 0;
        $errors = [];
        
        // จัดกลุ่มแถวตามวันที่ขาย + ผู้ซื้อ (สร้าง Lot ต่อ 1 บิล)
        $groupedLots = [];
        foreach ($rows as $index => $row) {
            $buyer = trim((string)($row[$columnMap['buyer']] ?? ''));
            $saleDate = $this->parseDate($row[$columnMap['sale_date']] ?? '');
            $item = trim((string)($row[$columnMap['item']] ?? ''));
            $qty = floatval($row[$columnMap['quantity']] ?? 0);
            $price = floatval($row[$columnMap['price']] ?? 0);
            $notes = trim((string)($row[$columnMap['notes']] ?? ''));
            
            if (empty($buyer) || empty($item) || $qty <= 0) {
                $errors[] = [
                    'row' => $index + 2,
                    'message' => 'ขาดข้อมูลจำเป็น (ผู้ซื้อ, สินค้า, น้ำหนัก)',
                ];
                $failedCount++;
                continue;
            }
            
            $groupKey = $saleDate . '|' . $buyer;
            if (!isset($groupedLots[$groupKey])) {
                $groupedLots[$groupKey] = [
                    'buyer_name' => $buyer,
                    'sale_date' => $saleDate,
                    'notes' => $notes,
                    'items' => [],
                ];
            }
            
            $groupedLots[$groupKey]['items'][] = [
                'item_name' => $item,
                'quantity_kg' => $qty,
                'unit_price' => $price,
                'subtotal' => $qty * $price,
            ];
        }
        
        // สร้าง Sale Lot แต่ละกลุ่ม
        foreach ($groupedLots as $groupKey => $group) {
            try {
                $totalAmount = array_sum(array_column($group['items'], 'subtotal'));
                
                $lotData = [
                    'branch_id' => $job['branch_id'],
                    'buyer_name' => $group['buyer_name'],
                    'sale_date' => $group['sale_date'],
                    'total_amount' => $totalAmount,
                    'status' => 'completed',
                    'notes' => $group['notes'] ?: 'นำเข้าจาก Excel: ' . $job['filename'],
                    'created_by' => $job['user_id'],
                ];
                
                $lotModel = new SaleLot();
                $lotId = $lotModel->create($lotData);
                
                // สร้าง Lot Items
                foreach ($group['items'] as $item) {
                    $itemData = [
                        'sale_lot_id' => $lotId,
                        'item_name' => $item['item_name'],
                        'quantity_kg' => $item['quantity_kg'],
                        'unit_price' => $item['unit_price'],
                        'subtotal' => $item['subtotal'],
                    ];
                    
                    $lotItemModel = new SaleLotItem();
                    $lotItemModel->create($itemData);
                }
                
                $successCount++;
                
            } catch (Exception $e) {
                $failedCount++;
                $errors[] = [
                    'row' => $groupKey,
                    'message' => $e->getMessage(),
                ];
            }
        }
        
        return [
            'total_rows' => count($rows),
            'success_rows' => $successCount,
            'failed_rows' => $failedCount,
            'errors' => $errors,
        ];
    }
    
    /**
     * ประมวลผล sellers
     * 
     * Excel columns:
     * - full_name / ชื่อ-นามสกุล (required)
     * - phone / เบอร์โทร (optional)
     * - id_card / เลขบัตรประชาชน (optional)
     * - address / ที่อยู่ (optional)
     * - vehicle_plate / ทะเบียนรถ (optional)
     * - vehicle_type / ประเภทรถ (optional)
     * - notes / หมายเหตุ (optional)
     */
    private function processSellers(string $filePath, array $job): array
    {
        $reader = $this->getExcelReader($filePath);
        $sheet = $reader->getActiveSheet();
        $rows = $sheet->toArray();
        
        // ข้าม header row
        $headers = array_shift($rows);
        
        // จับคู่คอลัมน์แบบยืดหยุ่น
        $columnMap = $this->mapColumns($headers, [
            'name' => ['name', 'full_name', 'ชื่อ', 'ชื่อ-นามสกุล', 'ชื่อผู้ขาย'],
            'phone' => ['phone', 'tel', 'เบอร์โทร', 'โทรศัพท์', 'เบอร์'],
            'id_card' => ['id_card', 'national_id', 'บัตรประชาชน', 'เลขบัตร', 'id'],
            'address' => ['address', 'ที่อยู่'],
            'vehicle_plate' => ['vehicle_plate', 'plate', 'ทะเบียนรถ', 'ทะเบียน'],
            'vehicle_type' => ['vehicle_type', 'type', 'ประเภทรถ', 'ประเภทยานพาหนะ'],
            'notes' => ['notes', 'note', 'หมายเหตุ'],
        ]);
        
        $successCount = 0;
        $failedCount = 0;
        $errors = [];
        
        foreach ($rows as $index => $row) {
            try {
                $fullName = trim((string)($row[$columnMap['name']] ?? ''));
                
                if (empty($fullName)) {
                    throw new Exception('แถว ' . ($index + 2) . ': ขาดชื่อ-นามสกุล');
                }
                
                $phone = trim((string)($row[$columnMap['phone']] ?? ''));
                $idCard = trim((string)($row[$columnMap['id_card']] ?? ''));
                
                // ตรวจสอบ duplicate (ชื่อ + เบอร์โทร)
                $sellerModel = new Seller();
                $existing = $sellerModel->findByNameOrPhone($fullName, $phone ?: null);
                if ($existing) {
                    $errors[] = [
                        'row' => $index + 2,
                        'message' => "ข้าม — ผู้ขาย '{$fullName}' มีอยู่แล้ว (ID: {$existing['id']})",
                    ];
                    $failedCount++;
                    continue;
                }
                
                $sellerData = [
                    'full_name' => $fullName,
                    'phone' => $phone ?: null,
                    'id_card' => $idCard ?: null,
                    'address' => trim((string)($row[$columnMap['address']] ?? '')) ?: null,
                    'vehicle_plate' => trim((string)($row[$columnMap['vehicle_plate']] ?? '')) ?: null,
                    'vehicle_type' => trim((string)($row[$columnMap['vehicle_type']] ?? '')) ?: null,
                    'notes' => trim((string)($row[$columnMap['notes']] ?? '')) ?: null,
                    'tier_level' => 1,
                    'status' => 'active',
                ];
                
                $sellerModel->create($sellerData);
                $successCount++;
                
            } catch (Exception $e) {
                $failedCount++;
                $errors[] = [
                    'row' => $index + 2,
                    'message' => $e->getMessage(),
                ];
            }
        }
        
        return [
            'total_rows' => count($rows),
            'success_rows' => $successCount,
            'failed_rows' => $failedCount,
            'errors' => $errors,
        ];
    }
    
    /**
     * จับคู่คอลัมน์แบบยืดหยุ่น
     */
    private function mapColumns(array $headers, array $mapping): array
    {
        $result = [];
        foreach ($mapping as $key => $aliases) {
            $result[$key] = null;
            foreach ($aliases as $alias) {
                foreach ($headers as $index => $header) {
                    if (strtolower(trim($header)) === strtolower($alias)) {
                        $result[$key] = $index;
                        break 2;
                    }
                }
            }
        }
        return $result;
    }
    
    /**
     * แปลงวันที่
     */
    private function parseDate($date): string
    {
        if (empty($date)) return date('Y-m-d');
        
        // ลอง parse ด้วย DateTime
        try {
            $dt = new DateTime($date);
            return $dt->format('Y-m-d');
        } catch (Exception $e) {
            // ถ้า parse ไม่ได้ ลอง format อื่น
            if (preg_match('/(\d{1,2})[\/\-](\d{1,2})[\/\-](\d{4})/', $date, $matches)) {
                return "{$matches[3]}-{$matches[2]}-{$matches[1]}";
            }
            return date('Y-m-d');
        }
    }
    
    /**
     * ตรวจสอบไฟล์ Excel
     */
    private function validateExcelFile(string $filePath, string $importType): array
    {
        try {
            $reader = $this->getExcelReader($filePath);
            $sheet = $reader->getActiveSheet();
            $rows = $sheet->toArray();
            
            $headers = $row[0] ?? [];
            $totalRows = count($rows) - 1; // ลบ header
            
            return [
                'valid' => true,
                'total_rows' => $totalRows,
                'headers' => $headers,
                'sample_data' => array_slice($rows, 1, 3),
            ];
        } catch (Exception $e) {
            return [
                'valid' => false,
                'error' => $e->getMessage(),
            ];
        }
    }
    
    /**
     * ดึง Excel Reader (ใช้ PhpSpreadsheet หรือทำเอง)
     */
    private function getExcelReader(string $filePath)
    {
        // ถ้ามี PhpSpreadsheet ใช้ PhpSpreadsheet
        if (class_exists('PhpOffice\PhpSpreadsheet\IOFactory')) {
            return \PhpOffice\PhpSpreadsheet\IOFactory::load($filePath);
        }
        
        // ถ้าไม่มี ใช้ simplexls (lightweight)
        // TODO: เพิ่ม simplexls library
        throw new Exception('ต้องติดตั้ง PhpSpreadsheet หรือ simplexls');
    }
}
