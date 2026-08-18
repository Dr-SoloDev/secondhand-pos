<?php
class SaleLot extends Model
{
    protected $table = 'sale_lots';

    // ดึงรายการ Sale Lots ทั้งหมด รองรับกรองตาม branch, status และช่วงวันที่
    public function getAll($branch_id, $filters = [])
    {
        $where = [];
        $params = [];
        if ($branch_id) {
            $where[] = "sl.branch_id = ?";
            $params[] = $branch_id;
        }

        if (!empty($filters['status'])) {
            $where[] = "sl.status = ?";
            $params[] = $filters['status'];
        }
        if (!empty($filters['date_from'])) {
            $where[] = "DATE(sl.sale_date) >= ?";
            $params[] = $filters['date_from'];
        }
        if (!empty($filters['date_to'])) {
            $where[] = "DATE(sl.sale_date) <= ?";
            $params[] = $filters['date_to'];
        }
        $whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

        $items = $this->db->fetchAll(
            "SELECT sl.*,
                    b.name AS branch_name,
                    u.full_name AS created_by_name,
                    (sl.total_amount - sl.total_cost) AS profit
             FROM {$this->table} sl
             LEFT JOIN branches b ON sl.branch_id = b.id
             LEFT JOIN users u ON sl.created_by = u.id
             {$whereSql}
             ORDER BY sl.created_at DESC",
            $params
        );

        foreach ($items as &$row) {
            $row['transport_cost'] = (float)($row['transport_cost'] ?? 0);
            $expenses = json_decode($row['expenses'] ?? '[]', true) ?: [];
            $row['expenses'] = $expenses;
            $totalExpenses = array_sum(array_column($expenses, 'amount')) + $row['transport_cost'];
            $row['total_expenses'] = $totalExpenses;
            $row['net_profit'] = (float)$row['total_amount'] - (float)$row['total_cost'] - $totalExpenses;
        }

        return $items;
    }

    // ดึง Sale Lot เดียวพร้อมรายการสินค้าและข้อมูลกำไร
    public function getById($id)
    {
        $lot = $this->db->fetch(
            "SELECT sl.*,
                    b.name AS branch_name,
                    u.full_name AS created_by_name,
                    uu.full_name AS updated_by_name,
                    (sl.total_amount - sl.total_cost) AS profit
             FROM {$this->table} sl
             LEFT JOIN branches b ON sl.branch_id = b.id
             LEFT JOIN users u ON sl.created_by = u.id
             LEFT JOIN users uu ON sl.updated_by = uu.id
             WHERE sl.id = ?",
            [$id]
        );
        if (!$lot) return null;

        $lot['transport_cost'] = (float)($lot['transport_cost'] ?? 0);
        $expenses = json_decode($lot['expenses'] ?? '[]', true) ?: [];
        $lot['expenses'] = $expenses;
        $totalExpenses = array_sum(array_column($expenses, 'amount')) + $lot['transport_cost'];

        $lot['items'] = $this->db->fetchAll(
            "SELECT sli.*, c.name AS category_name
             FROM sale_lot_items sli
             LEFT JOIN categories c ON sli.category_id = c.id
             WHERE sli.sale_lot_id = ?
             ORDER BY sli.id ASC",
            [$id]
        );

        // สรุปกำไรแยกรายหมวดหมู่
        $netProfit = (float)$lot['total_amount'] - (float)$lot['total_cost'] - $totalExpenses;
        $lot['profit_breakdown'] = [
            'total_amount'   => (float)$lot['total_amount'],
            'total_cost'     => (float)$lot['total_cost'],
            'total_expenses' => $totalExpenses,
            'profit'         => (float)$lot['total_amount'] - (float)$lot['total_cost'],
            'net_profit'     => $netProfit,
            'margin_pct'     => $lot['total_amount'] > 0
                ? round((((float)$lot['total_amount'] - (float)$lot['total_cost']) / (float)$lot['total_amount']) * 100, 2)
                : 0,
            'net_margin_pct' => $lot['total_amount'] > 0
                ? round(($netProfit / (float)$lot['total_amount']) * 100, 2)
                : 0,
        ];

        return $lot;
    }

    // สร้าง Sale Lot ใหม่เป็น draft เท่านั้น ยังไม่ตัดสต็อกจนกว่าจะ confirm
    public function create($data)
    {
        // ตรวจสอบฟิลด์ที่จำเป็น
        $required = ['branch_id', 'buyer_name', 'sale_date', 'items'];
        foreach ($required as $field) {
            if (empty($data[$field])) {
                throw new Exception("ต้องระบุ {$field}");
            }
        }
        if (!is_array($data['items']) || count($data['items']) === 0) {
            throw new Exception('ต้องระบุรายการสินค้าอย่างน้อย 1 รายการ');
        }

        $transportCost = $this->requireNonNegativeAmount($data['transport_cost'] ?? 0, 'ค่าขนส่ง');
        $expenses = $this->normalizeExpenses($data['expenses'] ?? null);

        $this->db->beginTransaction();
        try {
            $branchId    = intval($data['branch_id']);
            $totalAmount = 0;
            $totalCost   = 0;

            // คำนวณยอดขายและต้นทุนประมาณการสำหรับ draft โดยไม่ล็อก/ตัดสต็อก
            $preparedItems = [];
            foreach ($data['items'] as $item) {
                $qty       = (float)($item['quantity_kg'] ?? 0);
                $unitPrice = (float)($item['unit_price'] ?? 0);
                $catId     = intval($item['category_id'] ?? 0);
                $itemName  = trim((string)($item['item_name'] ?? ''));
                $catalogId = intval($item['catalog_id'] ?? 0) ?: null;

                if (!is_finite($qty) || $qty <= 0 || empty($itemName)) {
                    throw new Exception('รายการสินค้าต้องมีชื่อสินค้าและน้ำหนักมากกว่า 0');
                }
                if (!is_finite($unitPrice) || $unitPrice < 0) {
                    throw new Exception('ราคาขายต่อหน่วยต้องไม่น้อยกว่า 0');
                }
                if ($catId <= 0) {
                    throw new Exception('แต่ละรายการต้องเลือกหมวดหมู่');
                }

                $subtotal = $qty * $unitPrice;
                try {
                    $itemCost = $this->calculateProvisionalCost($branchId, $catId, $itemName, $qty);
                } catch (Exception $e) {
                    $itemCost = 0;
                }

                $totalAmount += $subtotal;
                $totalCost   += $itemCost;

                $preparedItems[] = [
                    'catalog_id'   => $catalogId,
                    'item_name'    => $itemName,
                    'category_id'  => $catId ?: null,
                    'quantity_kg'  => $qty,
                    'unit_price'   => $unitPrice,
                    'fifo_cost'    => $itemCost,
                ];
            }

            $referenceNo = $this->generateReferenceNo($branchId);

            $lotId = $this->insert([
                'reference_no'  => $referenceNo,
                'branch_id'     => $branchId,
                'buyer_name'    => trim((string)$data['buyer_name']),
                'sale_date'     => $data['sale_date'],
                'total_amount'  => $totalAmount,
                'total_cost'    => $totalCost,
                'transport_cost' => $transportCost,
                'status'        => 'draft',
                'notes'         => isset($data['notes']) ? trim((string)$data['notes']) : null,
                'expenses'      => $expenses !== null ? json_encode($expenses) : null,
                'created_by'    => $data['created_by'] ?? null,
            ]);

            foreach ($preparedItems as $item) {
                $this->db->insert('sale_lot_items', [
                    'sale_lot_id'  => $lotId,
                    'catalog_id'   => $item['catalog_id'],
                    'item_name'    => $item['item_name'],
                    'category_id'  => $item['category_id'],
                    'quantity_kg'  => $item['quantity_kg'],
                    'unit_price'   => $item['unit_price'],
                    'fifo_cost'    => $item['fifo_cost'],
                ]);
            }

            $this->db->commit();
            return ['id' => $lotId, 'reference_no' => $referenceNo, 'total_amount' => $totalAmount, 'total_cost' => $totalCost];
        } catch (Exception $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    // อัปเดต Sale Lot ได้เฉพาะสถานะ draft เท่านั้น
    public function update($id, $data)
    {
        $lot = $this->db->fetch(
            "SELECT id, status, branch_id FROM {$this->table} WHERE id = ?",
            [$id]
        );
        if (!$lot) {
            throw new Exception('ไม่พบ Sale Lot');
        }
        if ($lot['status'] !== 'draft') {
            throw new Exception('แก้ไขได้เฉพาะ Sale Lot ที่มีสถานะ draft เท่านั้น');
        }

        $transportCost = $this->requireNonNegativeAmount($data['transport_cost'] ?? 0, 'ค่าขนส่ง');
        $expenses = $this->normalizeExpenses($data['expenses'] ?? null);

        $this->db->beginTransaction();
        try {
            $branchId    = intval($lot['branch_id']);
            $totalAmount = 0;
            $totalCost   = 0;

            // ลบรายการเดิมก่อนบันทึกใหม่
            $this->db->query("DELETE FROM sale_lot_items WHERE sale_lot_id = ?", [$id]);

            $preparedItems = [];
            foreach ($data['items'] as $item) {
                $qty       = (float)($item['quantity_kg'] ?? 0);
                $unitPrice = (float)($item['unit_price'] ?? 0);
                $catId     = intval($item['category_id'] ?? 0);
                $itemName  = trim((string)($item['item_name'] ?? ''));
                $catalogId = intval($item['catalog_id'] ?? 0) ?: null;

                if (!is_finite($qty) || $qty <= 0 || empty($itemName)) {
                    throw new Exception('รายการสินค้าต้องมีชื่อสินค้าและน้ำหนักมากกว่า 0');
                }
                if (!is_finite($unitPrice) || $unitPrice < 0) {
                    throw new Exception('ราคาขายต่อหน่วยต้องไม่น้อยกว่า 0');
                }
                if ($catId <= 0) {
                    throw new Exception('แต่ละรายการต้องเลือกหมวดหมู่');
                }

                $subtotal = $qty * $unitPrice;
                try {
                    $itemCost = $this->calculateProvisionalCost($branchId, $catId, $itemName, $qty);
                } catch (Exception $e) {
                    $itemCost = 0;
                }

                $totalAmount += $subtotal;
                $totalCost   += $itemCost;

                $preparedItems[] = [
                    'catalog_id'  => $catalogId,
                    'item_name'   => $itemName,
                    'category_id' => $catId ?: null,
                    'quantity_kg' => $qty,
                    'unit_price'  => $unitPrice,
                    'fifo_cost'   => $itemCost,
                ];
            }

            // SECURITY: บังคับ status='draft' — ห้ามให้ client flip เป็น confirmed ผ่าน update()
            // การเปลี่ยนสถานะต้องผ่าน updateStatus() ที่ตัดสต็อกถูกต้อง
            $this->db->query(
                "UPDATE {$this->table}
                 SET buyer_name = ?, sale_date = ?, total_amount = ?, total_cost = ?, transport_cost = ?, notes = ?, expenses = ?, status = 'draft', updated_at = NOW()
                 WHERE id = ?",
                [
                    trim((string)($data['buyer_name'] ?? $lot['buyer_name'] ?? '')),
                    $data['sale_date'],
                    $totalAmount,
                    $totalCost,
                    $transportCost,
                    isset($data['notes']) ? trim((string)$data['notes']) : null,
                    $expenses !== null ? json_encode($expenses) : null,
                    $id,
                ]
            );

            foreach ($preparedItems as $item) {
                $this->db->insert('sale_lot_items', [
                    'sale_lot_id' => $id,
                    'catalog_id'  => $item['catalog_id'],
                    'item_name'   => $item['item_name'],
                    'category_id' => $item['category_id'],
                    'quantity_kg' => $item['quantity_kg'],
                    'unit_price'  => $item['unit_price'],
                    'fifo_cost'   => $item['fifo_cost'],
                ]);
            }

            $this->db->commit();
            return true;
        } catch (Exception $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    // แก้ไข Sale Lot ที่ confirmed แล้ว: ไม่อนุญาต
    // ให้สร้าง Lot ใหม่แบบ draft แล้วยืนยันเมื่อมีข้อมูลบิล
    public function updateConfirmed($id, $data)
    {
        throw new Exception('แก้ไข Lot ที่ยืนยันแล้วไม่ได้ — โปรดสร้าง Lot ใหม่');
    }

    // เปลี่ยนสถานะ: draft->confirmed ตัดสต็อก, confirmed->cancelled คืนสต็อก
    public function updateStatus($id, $status)
    {
        $allowed = [
            'draft'     => ['confirmed'],
            'confirmed' => ['cancelled'],
        ];

        $this->db->beginTransaction();
        try {
            // Lock the lot row to prevent TOCTOU race
            $lot = $this->db->fetch(
                "SELECT id, status, branch_id FROM {$this->table} WHERE id = ? FOR UPDATE",
                [$id]
            );
            if (!$lot) {
                throw new Exception('ไม่พบ Sale Lot');
            }

            if (!isset($allowed[$lot['status']]) || !in_array($status, $allowed[$lot['status']])) {
                throw new Exception("ไม่สามารถเปลี่ยนสถานะจาก {$lot['status']} เป็น {$status} ได้");
            }
            if ($status === 'confirmed') {
                $this->deductStock($id);
            } elseif ($status === 'cancelled') {
                $this->restoreStock($id);
            }

            $this->db->query(
                "UPDATE {$this->table} SET status = ?, updated_at = NOW() WHERE id = ?",
                [$status, $id]
            );

            $this->db->commit();
            return true;
        } catch (Exception $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    // คำนวณ cost ใหม่สำหรับทุกรายการของ sale_lot และอัปเดต total_cost
    // ใช้ cost_method ตามการตั้งค่าของสาขา (fifo หรือ weighted)
    // จะ throw ถ้าสต็อกไม่พอ (ใช้ตอน confirm)
    private function recomputeCost($sale_lot_id)
    {
        $lot = $this->db->fetch(
            "SELECT branch_id FROM {$this->table} WHERE id = ? FOR UPDATE",
            [$sale_lot_id]
        );
        $items = $this->db->fetchAll(
            "SELECT id, category_id, item_name, quantity_kg FROM sale_lot_items WHERE sale_lot_id = ? FOR UPDATE",
            [$sale_lot_id]
        );

        $totalCost = 0.0;
        foreach ($items as $item) {
            if (empty($item['category_id'])) {
                throw new Exception('แต่ละรายการต้องเลือกหมวดหมู่');
            }
            $itemCost = $this->calculateCost(
                (int)$lot['branch_id'],
                (int)$item['category_id'],
                $item['item_name'],
                (float)$item['quantity_kg']
            );
            $this->db->query(
                "UPDATE sale_lot_items SET fifo_cost = ? WHERE id = ?",
                [$itemCost, $item['id']]
            );
            $totalCost += $itemCost;
        }

        $this->db->query(
            "UPDATE {$this->table} SET total_cost = ? WHERE id = ?",
            [$totalCost, $sale_lot_id]
        );
    }

    // ดึง cost_method ของสาขา
    private function getCostMethod($branch_id)
    {
        return $this->db->fetchColumn(
            "SELECT cost_method FROM branches WHERE id = ?",
            [$branch_id]
        ) ?: 'fifo';
    }

    // คำนวณต้นทุนตาม cost_method ของสาขา (fifo หรือ weighted)
    public function calculateCost($branch_id, $category_id, $item_name, $quantity_kg)
    {
        $method = $this->getCostMethod($branch_id);
        if ($method === 'weighted') {
            return $this->calculateWeightedAvgCost($branch_id, $category_id, $item_name, $quantity_kg);
        }
        return $this->calculateFifoCost($branch_id, $category_id, $item_name, $quantity_kg);
    }

    // ต้นทุนประมาณการสำหรับ draft: อ่านอย่างเดียว ไม่ล็อกแถวและไม่บังคับให้สต็อกพอ
    private function calculateProvisionalCost($branch_id, $category_id, $item_name, $quantity_kg)
    {
        $method = $this->getCostMethod($branch_id);
        if ($method === 'weighted') {
            $row = $this->db->fetch(
                "SELECT
                    COALESCE(SUM(poi.net_quantity - poi.consumed_qty), 0) AS total_qty,
                    COALESCE(SUM((poi.net_quantity - poi.consumed_qty) * poi.unit_price), 0) AS total_value
                 FROM purchase_order_items poi
                 INNER JOIN purchase_orders po ON poi.purchase_order_id = po.id
                 WHERE po.branch_id = ?
                   AND poi.category_id = ?
                   AND poi.item_name = ?
                   AND po.status = 'completed'
                   AND (poi.net_quantity - poi.consumed_qty) > 0",
                [$branch_id, $category_id, $item_name]
            );

            $totalQty = (float)$row['total_qty'];
            if ($totalQty <= 0 || $totalQty < (float)$quantity_kg) {
                return 0;
            }

            return ((float)$row['total_value'] / $totalQty) * (float)$quantity_kg;
        }

        $rows = $this->db->fetchAll(
            "SELECT poi.net_quantity,
                    poi.unit_price,
                    poi.consumed_qty
             FROM purchase_order_items poi
             INNER JOIN purchase_orders po ON poi.purchase_order_id = po.id
             WHERE po.branch_id = ?
               AND poi.category_id = ?
               AND poi.item_name = ?
               AND po.status = 'completed'
               AND (poi.net_quantity - poi.consumed_qty) > 0
             ORDER BY po.created_at ASC",
            [$branch_id, $category_id, $item_name]
        );

        $remaining = (float)$quantity_kg;
        $totalCost = 0.0;

        foreach ($rows as $row) {
            if ($remaining <= 0) break;

            $available = (float)$row['net_quantity'] - (float)$row['consumed_qty'];
            $take = min($available, $remaining);
            $totalCost += $take * (float)$row['unit_price'];
            $remaining -= $take;
        }

        return $remaining > 0 ? 0 : $totalCost;
    }

    // คำนวณต้นทุนแบบถัวเฉลี่ย (Weighted Average)
    public function calculateWeightedAvgCost($branch_id, $category_id, $item_name, $quantity_kg)
    {
        $row = $this->db->fetch(
            "SELECT
                COALESCE(SUM(poi.net_quantity - poi.consumed_qty), 0) AS total_qty,
                COALESCE(SUM((poi.net_quantity - poi.consumed_qty) * poi.unit_price), 0) AS total_value
             FROM purchase_order_items poi
             INNER JOIN purchase_orders po ON poi.purchase_order_id = po.id
             WHERE po.branch_id = ?
               AND poi.category_id = ?
               AND poi.item_name = ?
               AND po.status = 'completed'
               AND (poi.net_quantity - poi.consumed_qty) > 0",
            [$branch_id, $category_id, $item_name]
        );

        $totalQty   = (float)$row['total_qty'];
        $totalValue = (float)$row['total_value'];

        if ($totalQty <= 0) {
            throw new Exception("สต็อกหมวดหมู่ ID {$category_id} ไม่เพียงพอ");
        }

        $avgPrice = $totalValue / $totalQty;

        // เช็คว่ามีสต็อกพอตามปริมาณที่ต้องการ
        if ($totalQty < $quantity_kg) {
            throw new Exception("สต็อกหมวดหมู่ ID {$category_id} ไม่เพียงพอ (ขาด " . ($quantity_kg - $totalQty) . " กก.)");
        }

        return $avgPrice * $quantity_kg;
    }

    // คำนวณต้นทุน FIFO สำหรับหมวดหมู่ ปริมาณ และ item_name (ADD-001)
    public function calculateFifoCost($branch_id, $category_id, $item_name, $quantity_kg)
    {
        // ดึง purchase_order_items ที่ยังมีสต็อกเหลือ เรียงตามวันเก่าสุดก่อน (FIFO)
        $rows = $this->db->fetchAll(
            "SELECT poi.id,
                    poi.net_quantity,
                    poi.unit_price,
                    poi.consumed_qty
             FROM purchase_order_items poi
             INNER JOIN purchase_orders po ON poi.purchase_order_id = po.id
             WHERE po.branch_id = ?
               AND poi.category_id = ?
               AND poi.item_name = ?
               AND po.status = 'completed'
               AND (poi.net_quantity - poi.consumed_qty) > 0
             ORDER BY po.created_at ASC
             FOR UPDATE",
            [$branch_id, $category_id, $item_name]
        );

        $remaining = (float)$quantity_kg;
        $totalCost = 0.0;

        foreach ($rows as $row) {
            if ($remaining <= 0) break;

            $available = (float)$row['net_quantity'] - (float)$row['consumed_qty'];
            $take      = min($available, $remaining);
            $totalCost += $take * (float)$row['unit_price'];
            $remaining -= $take;
        }

        if ($remaining > 0) {
            throw new Exception("สต็อกหมวดหมู่ ID {$category_id} ไม่เพียงพอ (ขาด {$remaining} กก.)");
        }

        return $totalCost;
    }

    // ตัดสต็อกเมื่อยืนยัน Sale Lot โดยบันทึก consumed_qty ใน purchase_order_items
    // และลด stock_kg ในหมวดหมู่
    public function deductStock($sale_lot_id)
    {
        $items = $this->db->fetchAll(
            "SELECT id, category_id, item_name, quantity_kg
             FROM sale_lot_items
             WHERE sale_lot_id = ? FOR UPDATE",
            [$sale_lot_id]
        );

        $lot = $this->db->fetch(
            "SELECT branch_id FROM {$this->table} WHERE id = ? FOR UPDATE",
            [$sale_lot_id]
        );

        $costMethod = $this->getCostMethod((int)$lot['branch_id']);
        $totalLotCost = 0.0;

        foreach ($items as $item) {
            $requestedQty = (float)$item['quantity_kg'];
            $categoryId = (int)$item['category_id'];
            $itemName = $item['item_name'] ?? '';

            $existingAllocation = $this->db->fetchColumn(
                "SELECT 1 FROM sale_lot_stock_allocations
                 WHERE sale_lot_item_id = ? AND restored_at IS NULL LIMIT 1",
                [(int)$item['id']]
            );
            if ($existingAllocation) {
                throw new Exception('Sale Lot นี้ตัดสต็อกไปแล้ว');
            }

            // ── Branch Stock: deduct per-branch per-item (ADD-001) ──
            if ($categoryId && $itemName) {
                $availableBranchStock = (float)$this->db->fetchColumn(
                    "SELECT stock_kg
                     FROM branch_stock
                     WHERE branch_id = ?
                       AND category_id = ?
                       AND item_name = ?
                     FOR UPDATE",
                    [$lot['branch_id'], $categoryId, $itemName]
                );
                if ($availableBranchStock + 0.000001 < $requestedQty) {
                    $short = $requestedQty - $availableBranchStock;
                    throw new Exception("สต็อก {$itemName} ไม่เพียงพอ (ขาด {$short} กก.)");
                }
            }

            // PO items ที่ยังมีสต็อกเหลือสำหรับหมวดหมู่+item_name นี้
            $rows = $this->db->fetchAll(
                "SELECT poi.id,
                        poi.net_quantity,
                        poi.consumed_qty,
                        poi.unit_price
                 FROM purchase_order_items poi
                 INNER JOIN purchase_orders po ON poi.purchase_order_id = po.id
                 WHERE po.branch_id = ?
                   AND poi.category_id = ?
                   AND poi.item_name = ?
                   AND po.status = 'completed'
                   AND (poi.net_quantity - poi.consumed_qty) > 0
                  ORDER BY po.created_at ASC, poi.id ASC
                 FOR UPDATE",
                [$lot['branch_id'], $categoryId, $itemName]
            );

            $totalAvailable = 0.0;
            $totalAvailableValue = 0.0;
            foreach ($rows as $row) {
                $available = (float)$row['net_quantity'] - (float)$row['consumed_qty'];
                $totalAvailable += $available;
                $totalAvailableValue += $available * (float)$row['unit_price'];
            }
            if ($totalAvailable + 0.000001 < $requestedQty) {
                $short = $requestedQty - $totalAvailable;
                throw new Exception("สต็อก {$itemName} ไม่เพียงพอ (ขาด {$short} กก.)");
            }

            $weightedUnitCost = $totalAvailable > 0 ? $totalAvailableValue / $totalAvailable : 0.0;
            $remaining = $requestedQty;
            $allocatedQty = 0.0;
            $cumulativeAvailable = 0.0;
            $itemCost = 0.0;
            $lastIndex = count($rows) - 1;

            foreach ($rows as $index => $row) {
                if ($remaining <= 0.000001) break;

                $available = (float)$row['net_quantity'] - (float)$row['consumed_qty'];
                if ($costMethod === 'weighted') {
                    $cumulativeAvailable += $available;
                    $targetAllocated = $index === $lastIndex
                        ? $requestedQty
                        : round(($cumulativeAvailable / $totalAvailable) * $requestedQty, 3);
                    $take = min($available, max(0.0, $targetAllocated - $allocatedQty));
                } else {
                    $take = min($available, $remaining);
                }
                $take = round($take, 3);
                if ($take <= 0) continue;

                // Atomic update — ป้องกัน race condition
                $stmt = $this->db->query(
                    "UPDATE purchase_order_items
                     SET consumed_qty = consumed_qty + ?
                     WHERE id = ? AND consumed_qty + ? <= net_quantity",
                    [$take, $row['id'], $take]
                );

                if (!$stmt->rowCount()) {
                    throw new Exception("สต็อกถูกตัดโดยรายการอื่นแล้ว กรุณาลองใหม่");
                }

                $unitCost = $costMethod === 'weighted' ? $weightedUnitCost : (float)$row['unit_price'];
                $allocatedCost = $take * $unitCost;
                $this->db->insert('sale_lot_stock_allocations', [
                    'sale_lot_id' => (int)$sale_lot_id,
                    'sale_lot_item_id' => (int)$item['id'],
                    'purchase_order_item_id' => (int)$row['id'],
                    'quantity_kg' => $take,
                    'unit_cost' => $unitCost,
                    'allocated_cost' => $allocatedCost,
                    'cost_method' => $costMethod,
                ]);

                $remaining -= $take;
                $allocatedQty += $take;
                $itemCost += $allocatedCost;
            }

            if ($remaining > 0.000001) {
                throw new Exception("สต็อก {$itemName} ไม่เพียงพอ (ขาด {$remaining} กก.)");
            }

            $branchStock = new BranchStock();
            $branchStock->deduct($lot['branch_id'], $categoryId, $itemName, $requestedQty);
            $this->db->query(
                "UPDATE categories SET stock_kg = GREATEST(0, stock_kg - ?) WHERE id = ?",
                [$requestedQty, $categoryId]
            );
            $this->db->query(
                "UPDATE sale_lot_items SET fifo_cost = ? WHERE id = ?",
                [$itemCost, (int)$item['id']]
            );
            $totalLotCost += $itemCost;
        }

        $this->db->query(
            "UPDATE {$this->table} SET total_cost = ? WHERE id = ?",
            [$totalLotCost, $sale_lot_id]
        );
    }

    // คืนสต็อกเมื่อยกเลิก Sale Lot
    public function restoreStock($sale_lot_id)
    {
        $items = $this->db->fetchAll(
            "SELECT id, category_id, item_name, quantity_kg
             FROM sale_lot_items
             WHERE sale_lot_id = ? FOR UPDATE",
            [$sale_lot_id]
        );

        $lot = $this->db->fetch(
            "SELECT branch_id FROM {$this->table} WHERE id = ? FOR UPDATE",
            [$sale_lot_id]
        );

        foreach ($items as $item) {
            $allocations = $this->db->fetchAll(
                "SELECT a.id, a.purchase_order_item_id, a.quantity_kg, poi.consumed_qty
                 FROM sale_lot_stock_allocations a
                 INNER JOIN purchase_order_items poi ON poi.id = a.purchase_order_item_id
                 WHERE a.sale_lot_item_id = ? AND a.restored_at IS NULL
                 ORDER BY a.id ASC
                 FOR UPDATE",
                [(int)$item['id']]
            );
            $allocatedQty = array_sum(array_map(static function ($allocation) {
                return (float)$allocation['quantity_kg'];
            }, $allocations));
            $itemQty = (float)$item['quantity_kg'];
            if (!$allocations || abs($allocatedQty - $itemQty) > 0.001) {
                throw new Exception('Lot นี้ไม่มีข้อมูล allocation ที่ครบถ้วน กรุณาใช้เอกสารปรับปรุงแทนการยกเลิก');
            }
        }

        foreach ($items as $item) {
            $toRestore = (float)$item['quantity_kg'];
            $categoryId = (int)$item['category_id'];
            $itemName = $item['item_name'] ?? '';

            // ── Branch Stock: restore per-branch per-item (ADD-001) ──
            if ($categoryId && $itemName) {
                $branchStock = new BranchStock();
                $branchStock->restore($lot['branch_id'], $categoryId, $itemName, $toRestore);
            }

            // STOCK FIX: Restore category stock (dual-write backward compat)
            $this->db->query(
                "UPDATE categories SET stock_kg = stock_kg + ? WHERE id = ?",
                [$toRestore, $categoryId]
            );

            $allocations = $this->db->fetchAll(
                "SELECT a.id, a.purchase_order_item_id, a.quantity_kg
                 FROM sale_lot_stock_allocations a
                 WHERE a.sale_lot_item_id = ? AND a.restored_at IS NULL
                 ORDER BY a.id ASC FOR UPDATE",
                [(int)$item['id']]
            );
            foreach ($allocations as $allocation) {
                $quantity = (float)$allocation['quantity_kg'];
                $stmt = $this->db->query(
                     "UPDATE purchase_order_items
                      SET consumed_qty = consumed_qty - ?
                      WHERE id = ? AND consumed_qty >= ?",
                    [$quantity, (int)$allocation['purchase_order_item_id'], $quantity]
                );

                if (!$stmt->rowCount()) {
                    throw new Exception("ข้อมูล consumed_qty ไม่ตรงกัน กรุณาลองใหม่");
                }
                $this->db->query(
                    "UPDATE sale_lot_stock_allocations SET restored_at = NOW() WHERE id = ?",
                    [(int)$allocation['id']]
                );
                $toRestore -= $quantity;
            }
            if (abs($toRestore) > 0.001) {
                throw new Exception('คืนสต็อกไม่ครบตาม allocation');
            }
        }
    }

    // สร้างเลขอ้างอิง SO-{BRANCH_CODE}-YYYYMMDD-NNN เพิ่มขึ้นอัตโนมัติรายวันต่อสาขา
    // BUG-06 FIX: ใช้ MAX+1 แทน COUNT+1 เพื่อป้องกัน race condition
    // BUG-07 FIX: เพิ่ม branch code เข้าไปใน prefix เพื่อป้องกัน reference_no ซ้ำข้ามสาขา
    public function generateReferenceNo($branch_id)
    {
        $branchCode = $this->db->fetchColumn("SELECT code FROM branches WHERE id = ?", [$branch_id]) ?: 'XX';
        $prefix = 'SO-' . $branchCode . '-' . date('Ymd');
        $last   = $this->db->fetchColumn(
            "SELECT MAX(CAST(SUBSTRING_INDEX(reference_no, '-', -1) AS UNSIGNED))
             FROM {$this->table}
             WHERE reference_no LIKE ? AND branch_id = ?",
            [$prefix . '-%', $branch_id]
        );
        $next = ($last ?? 0) + 1;
        return $prefix . '-' . str_pad($next, 3, '0', STR_PAD_LEFT);
    }

    private function requireNonNegativeAmount($value, $label)
    {
        $amount = (float)$value;
        if (!is_finite($amount) || $amount < 0) {
            throw new Exception("{$label}ต้องไม่น้อยกว่า 0");
        }
        return $amount;
    }

    private function normalizeExpenses($expenses)
    {
        if ($expenses === null || $expenses === '') {
            return null;
        }
        if (!is_array($expenses)) {
            throw new Exception('รูปแบบค่าใช้จ่ายไม่ถูกต้อง');
        }

        $clean = [];
        foreach ($expenses as $expense) {
            if (!is_array($expense)) {
                throw new Exception('รูปแบบค่าใช้จ่ายไม่ถูกต้อง');
            }
            $description = trim((string)($expense['description'] ?? ''));
            if ($description === '') {
                throw new Exception('กรุณาระบุรายละเอียดค่าใช้จ่าย');
            }
            $clean[] = [
                'description' => mb_substr(strip_tags($description), 0, 255),
                'amount' => $this->requireNonNegativeAmount($expense['amount'] ?? 0, 'ค่าใช้จ่าย'),
            ];
        }
        return $clean ?: null;
    }

    // ลบ Sale Lot ได้เฉพาะสถานะ draft เท่านั้น
    public function delete($id)
    {
        $lot = $this->db->fetch(
            "SELECT id, status FROM {$this->table} WHERE id = ?",
            [$id]
        );
        if (!$lot) {
            throw new Exception('ไม่พบ Sale Lot');
        }
        if (!in_array($lot['status'], ['draft', 'cancelled'])) {
            throw new Exception('ลบได้เฉพาะ Sale Lot ที่ยังไม่ยืนยัน หรือถูกยกเลิกแล้ว');
        }

        $this->db->beginTransaction();
        try {
            $this->db->query("DELETE FROM sale_lot_items WHERE sale_lot_id = ?", [$id]);
            $this->db->query("DELETE FROM {$this->table} WHERE id = ?", [$id]);
            $this->db->commit();
            return true;
        } catch (Exception $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    // บันทึกรายรับจริงจากบิลศูนย์รับซื้อ (เฉพาะ lot ที่ confirmed แล้ว)
    public function recordRevenue($id, $data, int $userId)
    {
        $this->db->beginTransaction();
        try {
            $lot = $this->db->fetch(
                "SELECT id, reference_no, branch_id, status, actual_revenue
                 FROM {$this->table} WHERE id=? FOR UPDATE",
                [$id]
            );
            if (!$lot || $lot['status'] !== 'confirmed') {
                throw new Exception('บันทึกรายรับได้เฉพาะ Lot ที่ยืนยันแล้ว');
            }
            if ($lot['actual_revenue'] !== null) {
                throw new Exception('Lot นี้บันทึกรายรับแล้ว หากข้อมูลผิดให้ใช้เอกสารปรับปรุง');
            }
            $paymentMethod = $data['payment_method'] ?? '';
            if (!in_array($paymentMethod, ['cash', 'bank_transfer'], true)) {
                throw new Exception('วิธีรับเงินไม่ถูกต้อง');
            }
            $cashSession = new CashSession();
            if ($paymentMethod === 'cash') {
                $cashSession->assertOpen((int)$lot['branch_id']);
            }

            $this->db->query(
                "UPDATE {$this->table}
                 SET actual_revenue=?, actual_revenue_note=?, actual_revenue_date=?,
                     revenue_payment_method=?, updated_at=NOW()
                 WHERE id=?",
                [$data['actual_revenue'], $data['actual_revenue_note'], $data['actual_revenue_date'], $paymentMethod, $id]
            );
            if ($paymentMethod === 'cash' && (float)$data['actual_revenue'] > 0) {
                $cashSession->recordMovement(
                    (int)$lot['branch_id'], 'in', 'sale_lot_revenue', (float)$data['actual_revenue'],
                    'sale_lot', (int)$id, 'รับเงินสดจาก LOT ' . $lot['reference_no'], $userId
                );
            }
            $this->db->commit();
            return true;
        } catch (Exception $e) {
            $this->db->rollBack();
            throw $e;
        }
    }
}
