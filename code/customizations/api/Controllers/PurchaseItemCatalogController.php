<?php
class PurchaseItemCatalogController extends Controller
{
    /**
     * GET /api/purchase-catalog — ดึงทั้งหมด
     */
    public function getCatalog()
    {
        $model = new PurchaseItemCatalog();
        $includeInactive = isset($_GET['include_inactive']) && $_GET['include_inactive'] === 'true';
        Response::success('ดึงรายการสินค้าสำเร็จ', $model->getAll($includeInactive));
    }

    /**
     * GET /api/purchase-catalog/search?q=xxx — autocomplete
     */
    public function searchCatalog()
    {
        $q = trim($_GET['q'] ?? '');
        if ($q === '') {
            Response::success('ok', []);
            return;
        }
        $model = new PurchaseItemCatalog();
        Response::success('ok', $model->search($q));
    }

    /**
     * GET /api/purchase-catalog/item?id=X
     */
    public function getItem()
    {
        $id = $_GET['id'] ?? null;
        if (!$id) {
            Response::error('กรุณาระบุ ID', 400);
        }
        $model = new PurchaseItemCatalog();
        $row = $model->getById($id);
        if (!$row) {
            Response::error('ไม่พบรายการ', 404);
        }
        Response::success('ok', $row);
    }

    /**
     * POST /api/purchase-catalog
     */
    public function createItem()
    {
        $this->requireAuth(['admin', 'manager']);
        $data = $this->getRequestData();
        $model = new PurchaseItemCatalog();
        try {
            $id = $model->create($data);
            Response::success('เพิ่มรายการสำเร็จ', ['id' => $id]);
        } catch (Exception $e) {
            error_log('Catalog create failed: ' . $e->getMessage());
            Response::error('ไม่สามารถเพิ่มรายการได้', 400);
        }
    }

    /**
     * PUT /api/purchase-catalog/item
     */
    public function updateItem($id = null)
    {
        $this->requireAuth(['admin', 'manager']);
        if (!$id) {
            $data = $this->getRequestData();
            $id = $data['id'] ?? null;
        } else {
            $data = $this->getRequestData();
        }
        if (!$id) {
            Response::error('กรุณาระบุ ID', 400);
        }
        $model = new PurchaseItemCatalog();
        try {
            $model->update($id, $data);
            Response::success('แก้ไขสำเร็จ');
        } catch (Exception $e) {
            error_log('Catalog update failed: ' . $e->getMessage());
            Response::error('ไม่สามารถแก้ไขรายการได้', 400);
        }
    }
}
