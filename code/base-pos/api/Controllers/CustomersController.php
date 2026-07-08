<?php
class CustomersController extends Controller
{
    public function getCustomers()
    {
        $this->requireAuth();
        // Get pagination params
        $pagination = $this->getPaginationParams();

        // Get search term
        $search = isset($_GET['search']) ? $this->sanitizeInput($_GET['search']) : null;

        // Get customers
        $customerModel = new Customer();
        $result = $customerModel->getCustomersWithPagination(
            $pagination['page'],
            $pagination['limit'],
            $search
        );

        Response::success('Customers retrieved', $result);
    }

    public function createCustomer()
    {
        $this->requireAuth();
        // Get and validate request data
        $data = $this->getRequestData();
        $this->validateRequiredFields($data, ['name']);

        // Sanitize input
        $data = $this->sanitizeInput($data);

        // Create customer
        $customerModel = new Customer();

        try {
            $customerId = $customerModel->insert([
                'name' => $data['name'],
                'email' => $data['email'] ?? null,
                'phone' => $data['phone'] ?? null,
                'address' => $data['address'] ?? null
            ]);

            // Log activity
            Logger::logActivity(
                $this->user['user_id'],
                'create_customer',
                "Created customer: {$data['name']}"
            );

            Response::success('Customer created', ['id' => $customerId]);
        } catch (Exception $e) {
            error_log('Customer create failed: ' . $e->getMessage());
            Response::error('Failed to create customer', 500);
        }
    }

    /**
     * @param $id
     */
    public function getCustomer($id)
    {
        $this->requireAuth();
        if (!$id) {
            Response::error('Customer ID is required', 400);
        }

        $customerModel = new Customer();
        $customer = $customerModel->findById($id);

        if (!$customer) {
            Response::error('Customer not found', 404);
        }

        Response::success('Customer retrieved', $customer);
    }

    /**
     * @param $id
     */
    public function updateCustomer($id)
    {
        $this->requireAuth(['admin', 'manager']);
        if (!$id) {
            Response::error('Customer ID is required', 400);
        }

        // Get and validate request data
        $data = $this->getRequestData();
        $this->validateRequiredFields($data, ['name']);

        // Sanitize input
        $data = $this->sanitizeInput($data);

        // Update customer
        $customerModel = new Customer();

        // Check if customer exists
        $customer = $customerModel->findById($id);
        if (!$customer) {
            Response::error('Customer not found', 404);
        }

        try {
            $customerModel->update($id, [
                'name' => $data['name'],
                'email' => $data['email'] ?? $customer['email'],
                'phone' => $data['phone'] ?? $customer['phone'],
                'address' => $data['address'] ?? $customer['address']
            ]);

            // Log activity
            Logger::logActivity(
                $this->user['user_id'],
                'update_customer',
                "Updated customer ID: {$id}"
            );

            Response::success('Customer updated');
        } catch (Exception $e) {
            error_log('Customer update failed: ' . $e->getMessage());
            Response::error('Failed to update customer', 500);
        }
    }

    /**
     * @param $id
     */
    public function deleteCustomer($id)
    {
        // Check permissions
        $this->requireAuth(['admin', 'manager']);

        if (!$id) {
            Response::error('Customer ID is required', 400);
        }

        // Check if it's the walk-in customer (ID 1)
        if ($id == 1) {
            Response::error('Cannot delete the walk-in customer', 400);
        }

        // Check if customer has sales
        $saleModel = new Sale();
        $salesCount = $saleModel->countByCustomerId($id);

        if ($salesCount > 0) {
            Response::error('Cannot delete customer with associated sales', 400);
        }

        // Delete customer
        $customerModel = new Customer();

        try {
            $customerModel->delete($id);

            // Log activity
            Logger::logActivity(
                $this->user['user_id'],
                'delete_customer',
                "Deleted customer ID: {$id}"
            );

            Response::success('Customer deleted');
        } catch (Exception $e) {
            error_log('Customer delete failed: ' . $e->getMessage());
            Response::error('Failed to delete customer', 500);
        }
    }
}
