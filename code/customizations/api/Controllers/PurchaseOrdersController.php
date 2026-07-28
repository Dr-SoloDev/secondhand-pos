<?php
class PurchaseOrdersController extends Controller
{
    public function getPurchaseOrders()
    {
        $this->requireAuth();

        $pagination = $this->getPaginationParams();
        $filters = [
            'branch_id' => isset($_GET['branch_id']) ? intval($_GET['branch_id']) : null,
            'date_from' => isset($_GET['date_from']) ? $this->sanitizeInput($_GET['date_from']) : null,
            'date_to' => isset($_GET['date_to']) ? $this->sanitizeInput($_GET['date_to']) : null,
            'search' => isset($_GET['search']) ? $this->sanitizeInput($_GET['search']) : null,
        ];

        // Read policy: admin/super_manager may read all branches or filter by branch_id.
        // Other roles remain forced to their own branch.
        $filters['branch_id'] = $this->resolveBranchId();

        $model = new PurchaseOrder();
        $result = $model->getPaginated($pagination['page'], $pagination['limit'], $filters);
        Response::success('Purchase orders retrieved', array_merge($result, ['filters' => $filters]));
    }

    public function getPurchaseOrder($id)
    {
        $this->requireAuth();
        if (!$id) Response::error('Purchase order ID is required', 400);

        $model = new PurchaseOrder();
        $po = $model->getById($id);
        if (!$po) Response::error('Purchase order not found', 404);

        $this->assertReadBranchAccess($po['branch_id'] ?? null, 'ไม่มีสิทธิ์เข้าถึงใบรับซื้อนี้');

        Response::success('Purchase order retrieved', $po);
    }

    public function cancelPurchaseOrder($id = null)
    {
        $this->requireAuth(['admin', 'manager']);
        if (!$id) {
            $data = $this->getRequestData();
            $id = isset($data['id']) ? intval($data['id']) : 0;
        }
        if (!$id) Response::error('ต้องระบุรหัสใบรับซื้อ', 400);

        $model = new PurchaseOrder();
        $po = $model->getById($id);
        if (!$po) Response::error('ไม่พบใบรับซื้อ', 404);
        if ($po['status'] === 'cancelled') Response::error('ใบรับซื้อยกเลิกไปแล้ว', 400);

        try {
            $model->cancel($id);
            Logger::logActivity(
                $this->user['user_id'],
                'cancel_purchase_order',
                "Cancelled PO: {$po['reference_no']} (ID: {$id})"
            );
            Response::success('ยกเลิกใบรับซื้อสำเร็จ');
        } catch (Exception $e) {
            error_log('PurchaseOrder cancel failed: ' . $e->getMessage());
            Response::error('ยกเลิกใบรับซื้อไม่สำเร็จ กรุณาลองใหม่อีกครั้ง', 500);
        }
    }

    public function createPurchaseOrder()
    {
        $this->requireAuth(['admin', 'manager']);
        $data = $this->getRequestData();

        // Idempotency check — ป้องกัน PO ซ้ำ
        $idempotencyKey = $data['idempotency_key'] ?? null;
        if ($idempotencyKey) {
            $idemp = new Idempotency();
            $idemp->check($idempotencyKey, 'purchase-orders');
        }

        $this->validateRequiredFields($data, ['branch_id', 'seller_id', 'items']);

        // SECURITY: non-admin สร้างได้เฉพาะสาขาตัวเอง
        $branchId = intval($data['branch_id']);
        if (($this->user['role'] ?? '') !== 'admin') {
            $userBranch = $this->user['branch_id'] ?? null;
            if (!$userBranch || $branchId !== (int)$userBranch) {
                Response::error('ไม่มีสิทธิ์สร้างใบรับซื้อในสาขานี้', 403);
            }
        }

        if (!is_array($data['items']) || count($data['items']) === 0) {
            Response::error('ต้องระบุรายการสินค้าอย่างน้อย 1 รายการ', 400);
        }

        $cleanData = [
            'branch_id' => intval($data['branch_id']),
            'seller_id' => intval($data['seller_id']),
            'payment_method' => $data['payment_method'] ?? 'cash',
            'payment_status' => $data['payment_status'] ?? 'paid',
            'status' => $data['status'] ?? 'completed',
            'notes' => isset($data['notes']) ? trim((string)$data['notes']) : null,
            'vehicle_type' => !empty($data['vehicle_type']) ? trim((string)$data['vehicle_type']) : null,
            'vehicle_plate' => !empty($data['vehicle_plate']) ? trim((string)$data['vehicle_plate']) : null,
        ];

        $cleanItems = [];
        foreach ($data['items'] as $item) {
            if (empty($item['item_name'])) {
                Response::error('แต่ละรายการต้องมีชื่อของ', 400);
            }
            $qty = floatval($item['quantity'] ?? 1);
            if ($qty <= 0) {
                Response::error('น้ำหนัก/จำนวนต้องมากกว่า 0', 400);
                return;
            }
            $cleanItems[] = [
                'item_name' => trim((string)$item['item_name']),
                'category_id' => !empty($item['category_id']) ? intval($item['category_id']) : null,
                // DEPRECATED — condition_id ไม่ใช้แล้ว ใช้ weight_deduction แทน
                'weight_deduction' => floatval($item['weight_deduction'] ?? 0),
                'quantity' => $qty,
                'unit' => $item['unit'] ?? 'ชิ้น',
                'unit_price' => floatval($item['unit_price'] ?? 0),
                'total_price' => floatval($item['total_price'] ?? (floatval($item['quantity'] ?? 1) * floatval($item['unit_price'] ?? 0))),
                'price_tier' => !empty($item['price_tier']) ? intval($item['price_tier']) : null,
                'notes' => isset($item['notes']) ? trim((string)$item['notes']) : null,
                'client_key' => $item['client_key'] ?? null,
            ];
        }

        // QA-C2: ตรวจสอบผู้ขาย Blacklist ก่อนบันทึก PO
        $sellerModel = new Seller();
        $seller = $sellerModel->getById($cleanData['seller_id']);
        if (!$seller) {
            Response::error('ไม่พบข้อมูลผู้ขาย', 404);
            return;
        }
        if ($seller['is_blacklisted']) {
            $reason = !empty($seller['blacklist_reason']) ? " ({$seller['blacklist_reason']})" : '';
            Response::error(
                "ไม่สามารถสร้างใบรับซื้อได้ — ผู้ขายนี้ถูก Blacklist{$reason}",
                403
            );
        }

        // G2: สินค้าที่ต้องใช้ใบรับซื้อโลหะมีค่า — ตรวจว่าผู้ขายมีเลขบัตรประชาชนก่อนบันทึก
        $categoryIds = array_values(array_unique(array_filter(array_column($cleanItems, 'category_id'))));
        if (!empty($categoryIds)) {
            $placeholders = implode(',', array_fill(0, count($categoryIds), '?'));
            $preciousCategories = $this->db->fetchAll(
                "SELECT id FROM categories WHERE id IN ({$placeholders}) AND requires_precious_receipt = 1",
                $categoryIds
            );
            if (!empty($preciousCategories)) {
                // Seller model already instantiated above for blacklist check
                if (!$seller || empty($seller['id_card'])) {
                    Response::error(
                        'สินค้าประเภทโลหะมีค่า (ทองแดง/โลหะมีค่า) ต้องบันทึกเลขบัตรประชาชนของผู้ขายก่อนบันทึก PO',
                        422
                    );
                }
            }
        }

        $model = new PurchaseOrder();
        try {
            $result = $model->createWithItems($cleanData, $cleanItems, $this->user['user_id']);
            if ($idempotencyKey) {
                $idemp->save($idempotencyKey, 'purchase-orders', ['id' => $result['id'], 'reference_no' => $result['reference_no']]);
            }

            Logger::logActivity(
                $this->user['user_id'],
                'create_purchase_order',
                "Created PO: {$result['reference_no']} (฿{$result['total_amount']})"
            );
            Response::success('สร้างใบรับซื้อสำเร็จ', $result);
        } catch (Exception $e) {
            error_log('PurchaseOrder create failed: ' . $e->getMessage());
            Response::error('สร้างใบรับซื้อไม่สำเร็จ กรุณาลองใหม่อีกครั้ง', 500);
        }
    }

    /**
     * GET /api/purchase-orders/print?id=X
     * ข้อมูล PO สำหรับพิมพ์ใบรับซื้อ — includes is_precious_metal flag
     */
    public function getPurchaseOrderForPrint()
    {
        $this->requireAuth();
        $id = intval($_GET['id'] ?? 0);
        if (!$id) Response::error('กรุณาระบุรหัสใบรับซื้อ', 400);

        $model = new PurchaseOrder();
        $po = $model->getById($id);
        if (!$po) Response::error('ไม่พบใบรับซื้อ', 404);

        $this->assertReadBranchAccess($po['branch_id'] ?? null, 'ไม่มีสิทธิ์เข้าถึงใบรับซื้อนี้');

        // G2-E2: detect precious metal — requires_precious_receipt flag หรือ category name มีคำว่า ทองแดง
        $isPreciousMetal = false;
        foreach ($po['items'] as $item) {
            if (!empty($item['requires_precious_receipt'])) {
                $isPreciousMetal = true;
                break;
            }
            if (!empty($item['category_name']) && mb_strpos($item['category_name'], 'ทองแดง') !== false) {
                $isPreciousMetal = true;
                break;
            }
        }

        $po['is_precious_metal'] = $isPreciousMetal;
        Response::success('สำเร็จ', $po);
    }

    /**
     * Export daily purchase report as Excel-compatible HTML table.
     */
    public function exportDaily()
    {
        $this->requireAuth(['admin', 'manager', 'super_manager']);

        $db = Database::getInstance();

        $data = $this->getRequestData();
        if (!is_array($data)) {
            $data = [];
        }

        $exportDate = isset($data['date']) && $data['date'] !== ''
            ? trim((string)$data['date'])
            : (isset($_GET['date']) && $_GET['date'] !== '' ? trim((string)$_GET['date']) : date('Y-m-d'));

        $dateObj = DateTime::createFromFormat('!Y-m-d', $exportDate);
        if (!$dateObj || $dateObj->format('Y-m-d') !== $exportDate) {
            Response::error('รูปแบบวันที่ไม่ถูกต้อง', 400);
        }

        $branchId = $this->resolveBranchId($data);

        $bWhere = $branchId ? 'AND po.branch_id = ?' : '';
        $params = [$exportDate];
        if ($branchId) {
            $params[] = $branchId;
        }

        $orders = $db->fetchAll(
            "SELECT
                po.id,
                po.reference_no,
                po.created_at,
                po.total_items,
                po.total_amount,
                po.payment_method,
                po.payment_status,
                po.status,
                po.vehicle_type,
                po.vehicle_plate,
                s.full_name as seller_name,
                s.phone as seller_phone,
                u.full_name as cashier_name,
                b.name as branch_name
            FROM purchase_orders po
            LEFT JOIN sellers s ON po.seller_id = s.id
            LEFT JOIN users u ON po.user_id = u.id
            LEFT JOIN branches b ON po.branch_id = b.id
            WHERE DATE(po.created_at) = ? AND po.status != 'cancelled' $bWhere
            ORDER BY po.created_at ASC",
            $params
        );

        $itemsWithPo = $db->fetchAll(
            "SELECT
                po.id as purchase_order_id,
                po.reference_no,
                pi.item_name,
                pi.quantity,
                pi.weight_deduction,
                pi.unit,
                pi.unit_price,
                pi.total_price,
                pi.price_tier
            FROM purchase_order_items pi
            JOIN purchase_orders po ON pi.purchase_order_id = po.id
            WHERE DATE(po.created_at) = ? AND po.status != 'cancelled' $bWhere
            ORDER BY po.created_at ASC, po.reference_no ASC, pi.id ASC",
            $params
        );

        // Group items by PO and calculate totals
        $itemsByPo = [];
        foreach ($itemsWithPo as $item) {
            $poId = (int)$item['purchase_order_id'];
            if (!isset($itemsByPo[$poId])) {
                $itemsByPo[$poId] = [];
            }

            $quantity = floatval($item['quantity'] ?? 0);
            $deduction = floatval($item['weight_deduction'] ?? 0);
            $netQuantity = max(0, $quantity - $deduction);

            $itemsByPo[$poId][] = [
                'name' => $item['item_name'] ?? '-',
                'quantity' => $quantity,
                'deduction' => $deduction,
                'net_quantity' => $netQuantity,
                'unit' => $item['unit'] ?? '',
                'unit_price' => floatval($item['unit_price'] ?? 0),
                'subtotal' => floatval($item['total_price'] ?? 0),
            ];
        }

        $totalQuantity = 0;
        $grandTotal = 0;
        $billCount = count($orders);

        foreach ($orders as &$po) {
            $poId = (int)$po['id'];
            $po['items'] = $itemsByPo[$poId] ?? [];
            if (empty($po['items'])) {
                $grandTotal += floatval($po['total_amount'] ?? 0);
                continue;
            }
            foreach ($po['items'] as $item) {
                $totalQuantity += $item['net_quantity'];
                $grandTotal += $item['subtotal'];
            }
        }
        unset($po);

        // Generate Excel-compatible HTML
        $formattedDate = date('d/m/Y', strtotime($exportDate));
        $branchLabel = $branchId ? ('สาขา ' . $branchId) : 'ทุกสาขา';

        $html = '<html xmlns:o="urn:schemas-microsoft-com:office:office" xmlns:x="urn:schemas-microsoft-com:office:excel" xmlns="http://www.w3.org/TR/REC-html40">';
        $html .= '<head><meta charset="utf-8"><!--[if gte mso 9]><xml><x:ExcelWorkbook><x:ExcelWorksheets><x:ExcelWorksheet><x:Name>รายงานรับซื้อ</x:Name><x:WorksheetOptions><x:DisplayGridlines/></x:WorksheetOptions></x:ExcelWorksheet></x:ExcelWorksheets></x:ExcelWorkbook></xml><![endif]--></head>';
        $html .= '<body>';

        // Header
        $html .= '<div style="text-align:center;margin-bottom:20px;">';
        $html .= '<h2>รักษ์สะอาดรีไซเคิล</h2>';
        $html .= '<p>ศูนย์รับซื้อขยะเพื่อการรีไซเคิล</p>';
        $html .= '<h3>รายงานรับซื้อประจำวัน — ' . $this->escapeHtml($formattedDate) . '</h3>';
        $html .= '<p>สาขา: ' . $this->escapeHtml($branchLabel) . '</p>';
        $html .= '</div>';

        // Items table
        $html .= '<table border="1" cellpadding="4" cellspacing="0" style="border-collapse:collapse;width:100%;font-size:12px;">';
        $html .= '<thead><tr style="background-color:#d97706;color:white;">';
        $html .= '<th>#</th><th>เลขที่</th><th>เวลา</th><th>สาขา</th><th>ผู้ขาย</th><th>เบอร์โทร</th><th>รายการ</th>';
        $html .= '<th>จำนวน</th><th>หักน้ำหนัก</th><th>สุทธิ</th><th>หน่วย</th><th>ราคา/หน่วย</th><th>ยอดรวม</th>';
        $html .= '<th>วิธีจ่าย</th><th>สถานะ</th></tr></thead><tbody>';

        if (empty($orders)) {
            $html .= '<tr><td colspan="15" style="text-align:center;">ไม่มีข้อมูล</td></tr>';
        } else {
            $rowNum = 1;
            foreach ($orders as $po) {
                $items = !empty($po['items']) ? $po['items'] : [[
                    'name' => '-',
                    'quantity' => 0,
                    'deduction' => 0,
                    'net_quantity' => 0,
                    'unit' => '',
                    'unit_price' => 0,
                    'subtotal' => floatval($po['total_amount'] ?? 0),
                ]];
                $rowspan = count($items);

                foreach ($items as $index => $item) {
                    $html .= '<tr>';
                    if ($index === 0) {
                        $html .= '<td rowspan="' . $rowspan . '">' . $rowNum . '</td>';
                        $html .= '<td rowspan="' . $rowspan . '">' . $this->escapeHtml($po['reference_no'] ?? '-') . '</td>';
                        $html .= '<td rowspan="' . $rowspan . '">' . $this->escapeHtml(date('H:i', strtotime($po['created_at'] ?? $exportDate))) . '</td>';
                        $html .= '<td rowspan="' . $rowspan . '">' . $this->escapeHtml($po['branch_name'] ?? '-') . '</td>';
                        $html .= '<td rowspan="' . $rowspan . '">' . $this->escapeHtml($po['seller_name'] ?? '-') . '</td>';
                        $html .= '<td rowspan="' . $rowspan . '">' . $this->escapeHtml($po['seller_phone'] ?? '-') . '</td>';
                    }
                    $html .= '<td>' . $this->escapeHtml($item['name'] ?? '-') . '</td>';
                    $html .= '<td style="text-align:right;">' . number_format($item['quantity'], 3) . '</td>';
                    $html .= '<td style="text-align:right;">' . number_format($item['deduction'], 3) . '</td>';
                    $html .= '<td style="text-align:right;">' . number_format($item['net_quantity'], 3) . '</td>';
                    $html .= '<td>' . $this->escapeHtml($item['unit'] ?? '-') . '</td>';
                    $html .= '<td style="text-align:right;">' . number_format($item['unit_price'], 2) . '</td>';
                    $html .= '<td style="text-align:right;">' . number_format($item['subtotal'], 2) . '</td>';
                    if ($index === 0) {
                        $html .= '<td rowspan="' . $rowspan . '">' . $this->escapeHtml($this->formatPaymentMethod($po['payment_method'] ?? null)) . '</td>';
                        $html .= '<td rowspan="' . $rowspan . '">' . $this->escapeHtml($this->getStatusLabel($po['status'] ?? null)) . '</td>';
                    }
                    $html .= '</tr>';
                }
                $rowNum++;
            }
        }

        $html .= '</tbody>';

        // Summary footer
        $html .= '<tfoot><tr style="background-color:#f59e0b;font-weight:bold;">';
        $html .= '<td colspan="9" style="text-align:right;">รวมทั้งหมด</td>';
        $html .= '<td style="text-align:right;">' . number_format($totalQuantity, 3) . '</td>';
        $html .= '<td>-</td><td>-</td>';
        $html .= '<td style="text-align:right;">' . number_format($grandTotal, 2) . ' บาท</td>';
        $html .= '<td colspan="2">จำนวน ' . $billCount . ' บิล</td>';
        $html .= '</tr></tfoot></table>';

        $html .= '</body></html>';

        Response::success('สำเร็จ', [
            'html' => $html,
            'filename' => 'daily-purchase-' . str_replace('-', '', $exportDate) . '.xls',
        ]);
    }

    private function resolveBranchId(array $data = [])
    {
        $requestedBranch = null;
        $branchProvided = false;

        if (array_key_exists('branch_id', $data) && $data['branch_id'] !== '' && $data['branch_id'] !== null) {
            $branchProvided = true;
            $requestedBranch = $data['branch_id'];
        } elseif (isset($_GET['branch_id']) && $_GET['branch_id'] !== '') {
            $branchProvided = true;
            $requestedBranch = $_GET['branch_id'];
        }

        if ($branchProvided) {
            if (!is_numeric($requestedBranch) || intval($requestedBranch) < 0) {
                Response::error('branch_id ไม่ถูกต้อง', 400);
            }
            $requestedBranch = intval($requestedBranch);
            if ($requestedBranch === 0) {
                $requestedBranch = null;
            }
        }

        $role = $this->user['role'] ?? '';
        if (in_array($role, ['admin', 'super_manager'], true)) {
            return $requestedBranch;
        }

        $userBranch = intval($this->user['branch_id'] ?? 0);
        if (!$userBranch) {
            Response::error('ไม่มีสาขาที่ผูกกับผู้ใช้นี้', 403);
        }

        return $userBranch;
    }

    private function assertReadBranchAccess($branchId, $message)
    {
        $role = $this->user['role'] ?? '';
        if (in_array($role, ['admin', 'super_manager'], true)) {
            return;
        }

        $userBranch = intval($this->user['branch_id'] ?? 0);
        if (!$userBranch || (int)$branchId !== $userBranch) {
            Response::error($message, 403);
        }
    }

    private function escapeHtml($value)
    {
        if ($value === null || $value === '') {
            $value = '-';
        }
        return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    private function formatPaymentMethod($value)
    {
        return $this->getStatusLabel($value);
    }

    private function getStatusLabel($status)
    {
        $normalized = strtolower((string)($status ?? ''));
        $map = [
            'confirmed' => 'ยืนยันแล้ว',
            'completed' => 'สำเร็จ',
            'draft' => 'แบบร่าง',
            'pending' => 'รอดำเนินการ',
            'cancelled' => 'ยกเลิก',
            'active' => 'กำลังทำงาน',
            'inactive' => 'ออกแล้ว',
            'paid' => 'ชำระแล้ว',
            'cash' => 'เงินสด',
            'bank' => 'โอนธนาคาร',
            'bank_transfer' => 'โอนธนาคาร',
            'qr' => 'QR Code',
            'promptpay' => 'พร้อมเพย์',
            'card' => 'บัตร',
            'other' => 'อื่นๆ',
        ];

        return $map[$normalized] ?? ($status ?: '-');
    }
}
