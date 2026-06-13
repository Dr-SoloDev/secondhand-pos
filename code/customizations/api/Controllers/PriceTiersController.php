<?php
class PriceTiersController extends Controller
{
    public function getPriceTiers()
    {
        $this->requireAuth();
        $db = Database::getInstance();
        $stmt = $db->query(
            "SELECT c.id, c.code, c.name, c.category_id, c.default_unit, c.default_price,
                    c.tier_prices,
                    cat.name AS category_name
             FROM purchase_item_catalog c
             LEFT JOIN categories cat ON cat.id = c.category_id
             WHERE c.is_active = 1
             ORDER BY cat.name ASC, c.code ASC"
        );
        $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($items as &$item) {
            $item['tier_prices'] = json_decode($item['tier_prices'] ?? '[]', true) ?: [];
        }
        Response::success('ok', $items);
    }

    public function createCatalogItem()
    {
        $this->requireAuth(['admin', 'manager']);
        $data = $this->getRequestData();
        try {
            $model = new PurchaseItemCatalog();
            $id = $model->create($data);
            Logger::logActivity($this->user['user_id'], 'create_catalog_item', "เพิ่มรายการ catalog ID:{$id}");
            Response::success('เพิ่มรายการสำเร็จ', ['id' => $id]);
        } catch (Exception $e) {
            Response::error($e->getMessage(), 400);
        }
    }

    public function updatePriceTiers()
    {
        $this->requireAuth(['admin', 'manager']);
        $data = $this->getRequestData();
        $id = $data['id'] ?? null;
        $tiers = $data['tiers'] ?? null;

        if (!$id) {
            Response::error('กรุณาระบุ ID รายการ', 400);
        }
        if (!is_array($tiers) || count($tiers) === 0) {
            Response::error('กรุณาระบุราคาอย่างน้อย 1 ระดับ', 400);
        }

        $db = Database::getInstance();
        $stmt = $db->prepare("SELECT id, name FROM purchase_item_catalog WHERE id = ?");
        $stmt->execute([$id]);
        $item = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$item) {
            Response::error('ไม่พบรายการสินค้า', 404);
        }

        // M4/M5: whitelist + sanitize tiers ก่อน json_encode (กัน stored XSS + bloat)
        $cleanTiers = [];
        foreach ($tiers as $i => $t) {
            if (empty($t['label'])) {
                Response::error("กรุณาระบุชื่อ ระดับ ที่ {$i}", 400);
            }
            if (!isset($t['price']) || !is_numeric($t['price'])) {
                Response::error("ราคา ระดับ ที่ {$i} ({$t['label']}) ไม่ถูกต้อง", 400);
            }
            if ((float)$t['price'] < 0) {
                Response::error("ราคา ระดับ ที่ {$i} ({$t['label']}) ต้องไม่ติดลบ", 400);
            }
            $label = trim((string)$t['label']);
            if (mb_strlen($label) > 50) {
                $label = mb_substr($label, 0, 50);
            }
            $cleanTiers[] = [
                'label' => $label,
                'price' => (float)$t['price'],
            ];
        }

        try {
            $stmt = $db->prepare("UPDATE purchase_item_catalog SET tier_prices = ? WHERE id = ?");
            $stmt->execute([
                json_encode($cleanTiers, JSON_UNESCAPED_UNICODE),
                $id
            ]);
            $tierLabels = array_map(fn($t) => "{$t['label']}: {$t['price']}", $cleanTiers);
            Logger::logActivity(
                $this->user['user_id'],
                'update_price_tiers',
                "อัปเดตราคา ระดับ สินค้า: {$item['name']} (" . implode(', ', $tierLabels) . ")"
            );
            Response::success('บันทึกราคา ระดับ สำเร็จ');
        } catch (Exception $e) {
            error_log('PriceTier update failed: ' . $e->getMessage());
            Response::error('เกิดข้อผิดพลาดในการอัปเดต', 500);
        }
    }
}
