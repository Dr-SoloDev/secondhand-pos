<?php
class PriceTiersController extends Controller
{
    /**
     * GET /api/price-tiers - ดึงรายการหมวดหมู่พร้อมราคาแต่ละ Tier
     */
    public function getPriceTiers()
    {
        $this->requireAuth();

        $db = Database::getInstance();
        $stmt = $db->query("SELECT id, name, price_tier1, price_tier2, price_tier3 FROM categories ORDER BY name ASC");
        $categories = $stmt->fetchAll(PDO::FETCH_ASSOC);

        Response::success('ดึงข้อมูลราคา Tier สำเร็จ', $categories);
    }

    /**
     * PUT /api/price-tiers/category - อัปเดตราคา Tier ของหมวดหมู่
     * เฉพาะ admin/manager เท่านั้น
     */
    public function updatePriceTiers()
    {
        $this->requireAuth(['admin', 'manager']);

        $data = $this->getRequestData();

        $id = $data['id'] ?? null;
        $priceTier1 = $data['price_tier1'] ?? null;
        $priceTier2 = $data['price_tier2'] ?? null;
        $priceTier3 = $data['price_tier3'] ?? null;

        if (!$id) {
            Response::error('กรุณาระบุ ID หมวดหมู่', 400);
        }

        // Validate prices are numeric and non-negative
        if ($priceTier1 === null || $priceTier2 === null || $priceTier3 === null) {
            Response::error('กรุณาระบุราคาทั้ง 3 Tier', 400);
        }

        if (!is_numeric($priceTier1) || !is_numeric($priceTier2) || !is_numeric($priceTier3)) {
            Response::error('ราคาต้องเป็นตัวเลขเท่านั้น', 400);
        }

        if ($priceTier1 < 0 || $priceTier2 < 0 || $priceTier3 < 0) {
            Response::error('ราคาต้องไม่ติดลบ', 400);
        }

        $db = Database::getInstance();

        // Check if category exists
        $stmt = $db->prepare("SELECT id, name FROM categories WHERE id = ?");
        $stmt->execute([$id]);
        $category = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$category) {
            Response::error('ไม่พบหมวดหมู่นี้', 404);
        }

        try {
            $stmt = $db->prepare("UPDATE categories SET price_tier1 = ?, price_tier2 = ?, price_tier3 = ? WHERE id = ?");
            $stmt->execute([
                floatval($priceTier1),
                floatval($priceTier2),
                floatval($priceTier3),
                $id
            ]);

            // Log activity
            Logger::logActivity(
                $this->user['user_id'],
                'update_price_tiers',
                "อัปเดตราคา Tier หมวดหมู่: {$category['name']} (Tier1: {$priceTier1}, Tier2: {$priceTier2}, Tier3: {$priceTier3})"
            );

            Response::success('อัปเดตราคา Tier สำเร็จ');
        } catch (Exception $e) {
            Response::error('เกิดข้อผิดพลาดในการอัปเดต: ' . $e->getMessage(), 500);
        }
    }
}
