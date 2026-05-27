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
             WHERE {$whereSql}",
            $params
        );

        $items = $this->db->fetchAll(
            "SELECT po.*, s.full_name AS seller_name, s.id_card AS seller_id_card,
                    b.name AS branch_name, b.code AS branch_code,
                    u.full_name AS user_name
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
                    u.full_name AS user_name
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
                    c.name AS category_name
             FROM purchase_order_items poi
             LEFT JOIN item_conditions ic ON poi.condition_id = ic.id
             LEFT JOIN categories c ON poi.category_id = c.id
             WHERE poi.purchase_order_id = ?
             ORDER BY poi.id ASC",
            [$id]
        );
        return $po;
    }

    public function generateReferenceNo()
    {
        $prefix = 'PO' . date('Ymd');
        $last = $this->db->fetchColumn(
            "SELECT MAX(CAST(SUBSTRING_INDEX(reference_no, '-', -1) AS UNSIGNED))
             FROM {$this->table}
             WHERE reference_no LIKE ?",
            [$prefix . '-%']
        );
        $next = ($last ?? 0) + 1;
        return $prefix . '-' . str_pad($next, 3, '0', STR_PAD_LEFT);
    }

    public function updateStatus($id, $status)
    {
        $this->db->query(
            "UPDATE {$this->table} SET status = ?, updated_at = NOW() WHERE id = ?",
            [$status, $id]
        );
        return true;
    }

    public function createWithItems($data, $items, $userId)
    {
        if (empty($items) || !is_array($items)) {
            throw new Exception('ต้องระบุรายการสินค้าอย่างน้อย 1 รายการ');
        }

        $this->db->beginTransaction();
        try {
            $totalAmount = 0;
            foreach ($items as $item) {
                $totalAmount += (float)($item['total_price'] ?? 0);
            }
            $totalItems = count($items);

            $referenceNo = $this->generateReferenceNo();

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
            ]);

            foreach ($items as $item) {
                $qty = (float)($item['quantity'] ?? 1);
                $deduct = (float)($item['weight_deduction'] ?? 0);
                $netQty = max(0, $qty - $deduct);
                $unitPrice = (float)($item['unit_price'] ?? 0);
                $totalPrice = (float)($item['total_price'] ?? ($netQty * $unitPrice));

                $this->db->insert('purchase_order_items', [
                    'purchase_order_id' => $poId,
                    'product_id' => $item['product_id'] ?? null,
                    'item_name' => $item['item_name'],
                    'category_id' => $item['category_id'] ?? null,
                    'condition_id' => $item['condition_id'] ?? null,
                    'quantity' => $qty,
                    'weight_deduction' => $deduct,
                    'unit' => $item['unit'] ?? 'ชิ้น',
                    'unit_price' => $unitPrice,
                    'total_price' => $totalPrice,
                    'price_tier' => $item['price_tier'] ?? null,
                    'photo_path' => $item['photo_path'] ?? null,
                    'notes' => $item['notes'] ?? null,
                ]);
            }

            $this->db->query(
                "UPDATE sellers
                 SET total_transactions = COALESCE(total_transactions, 0) + 1,
                     total_amount       = COALESCE(total_amount, 0) + ?,
                     last_transaction_at = NOW()
                 WHERE id = ?",
                [$totalAmount, $data['seller_id']]
            );

            $this->db->commit();
            return ['id' => $poId, 'reference_no' => $referenceNo, 'total_amount' => $totalAmount];
        } catch (Exception $e) {
            $this->db->rollBack();
            throw $e;
        }
    }
}
