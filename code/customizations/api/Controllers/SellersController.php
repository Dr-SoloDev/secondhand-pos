<?php
class SellersController extends Controller
{
    public function getSellers()
    {
        $pagination = $this->getPaginationParams();
        $search = isset($_GET['search']) ? $this->sanitizeInput($_GET['search']) : null;
        $model = new Seller();
        $result = $model->getPaginated($pagination['page'], $pagination['limit'], $search);
        Response::success('Sellers retrieved', $result);
    }

    public function searchSellers()
    {
        $search = isset($_GET['q']) ? $this->sanitizeInput($_GET['q']) : null;
        $model = new Seller();
        $sellers = $model->getAll($search, false);
        Response::success('Sellers retrieved', array_slice($sellers, 0, 20));
    }

    public function getSeller($id)
    {
        if (!$id) Response::error('Seller ID is required', 400);
        $model = new Seller();
        $seller = $model->findById($id);
        if (!$seller) Response::error('Seller not found', 404);
        Response::success('Seller retrieved', $seller);
    }

    public function createSeller()
    {
        $data = $this->getRequestData();
        $this->validateRequiredFields($data, ['full_name']);
        $data = $this->sanitizeInput($data);

        if (!empty($data['id_card'])) {
            $idCard = preg_replace('/\D/', '', $data['id_card']);
            if (strlen($idCard) !== 13) {
                Response::error('เลขบัตรประชาชนต้องเป็นตัวเลข 13 หลัก', 400);
            }
            $data['id_card'] = $idCard;
        }

        $model = new Seller();
        try {
            $id = $model->create($data);
            Logger::logActivity($this->user['user_id'], 'create_seller', "Created seller: {$data['full_name']}");
            Response::success('Seller created', ['id' => $id]);
        } catch (Exception $e) {
            Response::error('Failed to create seller: ' . $e->getMessage());
        }
    }

    public function updateSeller($id)
    {
        if (!$id) Response::error('Seller ID is required', 400);
        $data = $this->getRequestData();
        $data = $this->sanitizeInput($data);
        $model = new Seller();
        $seller = $model->findById($id);
        if (!$seller) Response::error('Seller not found', 404);

        try {
            $allowed = ['full_name', 'phone', 'address', 'notes', 'is_blacklisted'];
            $update = array_intersect_key($data, array_flip($allowed));
            $model->update($id, $update);
            Logger::logActivity($this->user['user_id'], 'update_seller', "Updated seller ID: {$id}");
            Response::success('Seller updated');
        } catch (Exception $e) {
            Response::error('Failed to update seller: ' . $e->getMessage());
        }
    }
}
