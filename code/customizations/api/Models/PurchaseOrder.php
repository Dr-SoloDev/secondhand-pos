<?php
class PurchaseOrder extends Model
{
    protected $table = 'purchase_orders';

    public function getPaginated($page = 1, $limit = 20, $filters = [])
    {
        $offset = ($page - 1) * $limit;
        $where = ["1=1"];
        $params = [];

        if (!empty($filters['branch_id'])) {
            $where[] = "po.branch_id = ?";
            $params[] = $filters['branch_id'];
        }
        if (!empty($filters['date_from'])) {
            $where[] = "DATE(po.created_at) >= ?";
            $params[] = $filters['date_from'];
        }
        if (!empty($filters['date_to'])) {
            $where[] = "DATE(po.created_at) <= ?";
            $params[] = $filters['date_to'];
        }
        if (!empty($filters['search'])) {
            $where[] = "(po.reference_no LIKE ? OR s.full_name LIKE ?)";
            $like = "%{$filters['search']}%";
            $params[] = $like;
            $params[] = $like;
        }
        $whereSql = implode(' AND ', $where);

        $total = $this->db->fetchColumn(
            "SELECT COUNT(*) FROM {$this->table} po
             LEFT JOIN sellers s ON po.seller_id = s.id
             WHERE {$whereSql}",
            $params
        );

        $items = $this->db->fetchAll(
            "SELECT po.*, s.full_name AS seller_name, s.id_card AS seller_id_card,
                    b.name AS branch_name, b.code AS branch_code,
                    u.full_name AS user_name,
                    (SELECT r.status FROM purchase_order_cancellation_requests r
                     WHERE r.purchase_order_id = po.id ORDER BY r.id DESC LIMIT 1) AS cancellation_request_status,
                    (SELECT r.id FROM purchase_order_cancellation_requests r
                     WHERE r.purchase_order_id = po.id ORDER BY r.id DESC LIMIT 1) AS cancellation_request_id
             FROM {$this->table} po
             LEFT JOIN sellers s ON po.seller_id = s.id
             LEFT JOIN branches b ON po.branch_id = b.id
             LEFT JOIN users u ON po.user_id = u.id
             WHERE {$whereSql}
             ORDER BY po.created_at DESC
             LIMIT {$limit} OFFSET {$offset}",
            $params
        );

        return [
            'items' => $items,
            'pagination' => [
                'total' => (int)$total,
                'page' => $page,
                'limit' => $limit,
                'pages' => (int)ceil($total / $limit),
            ],
        ];
    }

    public function getById($id)
    {
        $po = $this->db->fetch(
            "SELECT po.*, s.full_name AS seller_name, s.id_card AS seller_id_card,
                    s.phone AS seller_phone, s.address AS seller_address,
                    b.name AS branch_name, b.code AS branch_code,
                    b.phone AS branch_phone,
                    u.full_name AS user_name,
                    (SELECT r.status FROM purchase_order_cancellation_requests r
                     WHERE r.purchase_order_id = po.id ORDER BY r.id DESC LIMIT 1) AS cancellation_request_status,
                    (SELECT r.id FROM purchase_order_cancellation_requests r
                     WHERE r.purchase_order_id = po.id ORDER BY r.id DESC LIMIT 1) AS cancellation_request_id
             FROM {$this->table} po
             LEFT JOIN sellers s ON po.seller_id = s.id
             LEFT JOIN branches b ON po.branch_id = b.id
             LEFT JOIN users u ON po.user_id = u.id
             WHERE po.id = ?",
            [$id]
        );
        if (!$po) return null;
        $po['items'] = $this->db->fetchAll(
            "SELECT poi.*, ic.name AS condition_name, ic.code AS condition_code,
                     c.name AS category_name, c.requires_precious_receipt
              FROM purchase_order_items poi
              LEFT JOIN item_conditions ic ON poi.condition_id = ic.id /* DEPRECATED — legacy PO view only */
              LEFT JOIN categories c ON poi.category_id = c.id
             WHERE poi.purchase_order_id = ?
             ORDER BY poi.id ASC",
            [$id]
        );
        return $po;
    }

    public function generateReferenceNo($branch_id)
    {
        $prefix = 'PO-B' . (int)$branch_id . '-' . date('Ymd');
        $last = $this->db->fetchColumn(
            "SELECT MAX(CAST(SUBSTRING_INDEX(reference_no, '-', -1) AS UNSIGNED))
             FROM {$this->table}
             WHERE reference_no LIKE ? AND branch_id = ?",
            [$prefix . '-%', $branch_id]
        );
        $next = ($last ?? 0) + 1;
        return $prefix . '-' . str_pad($next, 3, '0', STR_PAD_LEFT);
    }

    public function cancel($id, int $actorId)
    {
        $this->db->beginTransaction();
        try {
            $this->cancelInCurrentTransaction($id, $actorId);
            $this->db->commit();
            return true;
        } catch (Exception $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    public function cancelInCurrentTransaction($id, int $actorId)
    {
            $po = $this->db->fetch(
                "SELECT id, branch_id, status, seller_id, total_amount, payment_method, source_type, source_id
                 FROM {$this->table}
                 WHERE id = ? FOR UPDATE",
                [$id]
            );
            if (!$po) {
                throw new Exception('ไม่พบใบรับซื้อ');
            }
            if ($po['status'] === 'cancelled') {
                throw new Exception('ใบรับซื้อถูกยกเลิกไปแล้ว');
            }
            if (($po['source_type'] ?? 'manual') !== 'manual') {
                throw new Exception('ใบรับซื้อที่เกิดจากการโอนสต็อกห้ามยกเลิกโดยตรง กรุณาใช้ใบโอนย้อนกลับ');
            }
            $cashSession = new CashSession();
            $cashSession->assertOpen((int)$po['branch_id']);

            $items = $this->db->fetchAll(
                "SELECT category_id,
                        item_name,
                        quantity,
                        weight_deduction,
                        consumed_qty,
                        (quantity - COALESCE(weight_deduction, 0) - COALESCE(consumed_qty, 0)) AS net_unconsumed
                 FROM purchase_order_items
                 WHERE purchase_order_id = ?
                   AND category_id IS NOT NULL
                 FOR UPDATE",
                [$id]
            );

            foreach ($items as $item) {
                if ((float)($item['consumed_qty'] ?? 0) > 0) {
                    throw new Exception('ไม่สามารถยกเลิกใบรับซื้อที่ถูกนำไปใช้ขายแล้ว');
                }
            }

            foreach ($items as $item) {
                $netQty = max(0, (float)$item['net_unconsumed']);
                if ($netQty <= 0) {
                    continue;
                }

                $currentBranchStock = (float)$this->db->fetchColumn(
                    "SELECT stock_kg
                     FROM branch_stock
                     WHERE branch_id = ?
                       AND category_id = ?
                       AND item_name = ?
                     FOR UPDATE",
                    [$po['branch_id'], $item['category_id'], $item['item_name']]
                );
                if ($currentBranchStock + 0.000001 < $netQty) {
                    throw new Exception("สต็อก {$item['item_name']} ไม่เพียงพอสำหรับยกเลิกใบรับซื้อ");
                }

                $currentCategoryStock = (float)$this->db->fetchColumn(
                    "SELECT stock_kg
                     FROM categories
                     WHERE id = ?
                     FOR UPDATE",
                    [$item['category_id']]
                );
                if ($currentCategoryStock + 0.000001 < $netQty) {
                    throw new Exception("สต็อกหมวดหมู่ ID {$item['category_id']} ไม่เพียงพอสำหรับยกเลิกใบรับซื้อ");
                }

                // ── Branch Stock: deduct per-branch per-item (ADD-001) ──
                $branchStock = new BranchStock();
                $branchStock->deduct($po['branch_id'], $item['category_id'], $item['item_name'], $netQty);

                // ── Dual-write: categories.stock_kg (backward compat) ──
                $this->db->query(
                    "UPDATE categories SET stock_kg = GREATEST(0, stock_kg - ?) WHERE id = ?",
                    [$netQty, $item['category_id']]
                );
            }

            $this->db->query(
                "UPDATE {$this->table}
                 SET status = 'cancelled', updated_at = NOW()
                 WHERE id = ?",
                [$id]
            );

            // Also revert seller stats
            $this->db->query(
                "UPDATE sellers
                 SET total_transactions = GREATEST(0, COALESCE(total_transactions, 0) - 1),
                     total_amount = GREATEST(0, COALESCE(total_amount, 0) - ?)
                 WHERE id = ?",
                [$po['total_amount'], $po['seller_id']]
            );

            if (($po['payment_method'] ?? 'cash') === 'cash'
                && $cashSession->hasMovement('purchase_payment', 'purchase_order', (int)$po['id'])) {
                $cashSession->recordMovement(
                    (int)$po['branch_id'],
                    'in',
                    'purchase_cancellation',
                    (float)$po['total_amount'],
                    'purchase_order',
                    (int)$po['id'],
                    'คืนเงินสดจากการยกเลิกใบรับซื้อ ' . $po['id'],
                    $actorId
                );
            }

            return true;
    }

    public function createWithItems($data, $items, $userId)
    {
        if (empty($items) || !is_array($items)) {
            throw new Exception('ต้องระบุรายการสินค้าอย่างน้อย 1 รายการ');
        }

        $this->db->beginTransaction();
        try {
            $cashSession = new CashSession();
            $cashSession->assertOpen((int)$data['branch_id']);
            $totalAmount = 0;
            $itemMappings = [];
            $totalItems = count($items);

            $referenceNo = $this->generateReferenceNo($data['branch_id']);

            $poId = $this->insert([
                'reference_no' => $referenceNo,
                'branch_id' => $data['branch_id'],
                'seller_id' => $data['seller_id'],
                'user_id' => $userId,
                'total_items' => $totalItems,
                'total_amount' => $totalAmount,
                'payment_method' => $data['payment_method'] ?? 'cash',
                'payment_status' => $data['payment_status'] ?? 'paid',
                'status' => $data['status'] ?? 'completed',
                'notes' => $data['notes'] ?? null,
                'vehicle_type' => $data['vehicle_type'] ?? null,
                'vehicle_plate' => $data['vehicle_plate'] ?? null,
            ]);

            foreach ($items as $item) {
                $qty = (float)($item['quantity'] ?? 1);
                $deduct = (float)($item['weight_deduction'] ?? 0);
                if (!is_finite($qty) || $qty <= 0 || !is_finite($deduct) || $deduct < 0 || $deduct >= $qty) {
                    throw new Exception('ข้อมูลน้ำหนักหรือจำนวนหักไม่ถูกต้อง');
                }
                $netQty = $qty - $deduct;
                $unitPrice = (float)($item['unit_price'] ?? 0);
                if (!is_finite($unitPrice) || $unitPrice < 0) {
                    throw new Exception('ราคาต่อหน่วยไม่ถูกต้อง');
                }
                $totalPrice = round($netQty * $unitPrice, 2);
                $totalAmount += $totalPrice;
                $categoryId = $item['category_id'] ?? null;

                $this->db->insert('purchase_order_items', [
                    'purchase_order_id' => $poId,
                    'product_id' => $item['product_id'] ?? null,
                    'item_name' => $item['item_name'],
                    'category_id' => $categoryId,
                    'condition_id' => null, // DEPRECATED — ใช้ weight_deduction แทน
                    'quantity' => $qty,
                    'weight_deduction' => $deduct,
                    'unit' => $item['unit'] ?? 'ชิ้น',
                    'unit_price' => $unitPrice,
                    'total_price' => $totalPrice,
                    'price_tier' => $item['price_tier'] ?? null,
                    'photo_path' => $item['photo_path'] ?? null,
                    'notes' => $item['notes'] ?? null,
                ]);

                $itemId = (int)$this->db->lastInsertId();
                $itemMappings[] = [
                    'id' => $itemId,
                    'client_key' => $item['client_key'] ?? null,
                ];

                // ── Branch Stock: UPSERT per-branch per-item (ADD-001) ──
                if ($categoryId) {
                    $branchStock = new BranchStock();
                    $branchStock->upsert($data['branch_id'], $categoryId, $item['item_name'], $netQty, $unitPrice);

                    // ── Dual-write: categories.stock_kg (backward compat) ──
                    $this->db->query(
                        "UPDATE categories SET stock_kg = stock_kg + ? WHERE id = ?",
                        [$netQty, $categoryId]
                    );
                }
            }

            $this->db->query(
                "UPDATE {$this->table}
                 SET total_amount = ?
                 WHERE id = ?",
                [$totalAmount, $poId]
            );

            $this->db->query(
                "UPDATE sellers
                 SET total_transactions = COALESCE(total_transactions, 0) + 1,
                     total_amount       = COALESCE(total_amount, 0) + ?,
                     last_transaction_at = NOW()
                 WHERE id = ?",
                [$totalAmount, $data['seller_id']]
            );

            if (($data['payment_method'] ?? 'cash') === 'cash' && $totalAmount > 0) {
                $cashSession->recordMovement(
                    (int)$data['branch_id'],
                    'out',
                    'purchase_payment',
                    $totalAmount,
                    'purchase_order',
                    (int)$poId,
                    "จ่ายเงินสดใบรับซื้อ {$referenceNo}",
                    (int)$userId
                );
            }

            $this->db->commit();
            return [
                'id' => $poId,
                'reference_no' => $referenceNo,
                'total_amount' => $totalAmount,
                'items' => $itemMappings,
            ];
        } catch (Exception $e) {
            $this->db->rollBack();
            throw $e;
        }
    }
}
