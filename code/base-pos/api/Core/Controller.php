<?php
class Controller
{
    /**
     * @var mixed
     */
    protected $user;
    /**
     * @var mixed
     */
    protected $db;

    /**
     * @param $user
     */
    public function __construct($user = null)
    {
        $this->user = $user;
        $this->db = Database::getInstance();
    }

    /**
     * @param array $roles
     */
    protected function requireAuth($roles = [])
    {
        if (!$this->user) {
            Response::error('Authentication required', 401);
            exit;
        }

        if (!empty($roles) && !in_array($this->user['role'], $roles)) {
            Logger::logActivity(
                $this->user['user_id'] ?? null,
                'authorization_denied',
                'Role is not permitted for requested action',
                [
                    'actor' => $this->user,
                    'module' => $this->requestModule(),
                    'outcome' => 'denied',
                    'reason' => 'role_not_allowed',
                    'after' => ['request_method' => $_SERVER['REQUEST_METHOD'] ?? 'UNKNOWN'],
                ]
            );
            Response::error('You do not have permission to perform this action', 403);
            exit;
        }

        return true;
    }

    private function requestModule()
    {
        $path = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH);
        $parts = explode('/', trim((string)$path, '/'));
        $indexPosition = array_search('index.php', $parts, true);
        if ($indexPosition !== false && isset($parts[$indexPosition + 1])) return $parts[$indexPosition + 1];
        return $parts[0] ?? 'system';
    }

    protected function getRequestData()
    {
        $data = json_decode(file_get_contents('php://input'), true);
        return TextEncoding::normalize($data);
    }

    /**
     * @param $data
     * @param $fields
     */
    protected function validateRequiredFields($data, $fields)
    {
        foreach ($fields as $field) {
            if (!isset($data[$field]) || (is_string($data[$field]) && trim($data[$field]) === '')) {
                Response::error("Required field '$field' is missing or empty", 400);
                exit;
            }
        }
        return true;
    }

    protected function sanitizeInput($data, $maxLength = 1000)
    {
        if (is_array($data)) {
            foreach ($data as $key => $value) {
                $data[$key] = $this->sanitizeInput($value, $maxLength);
            }
            return $data;
        }

        if (is_string($data)) {
            $data = TextEncoding::repairMojibake($data);
            $data = strip_tags($data);
            $data = trim($data);
            if (mb_strlen($data) > $maxLength) {
                $data = mb_substr($data, 0, $maxLength);
            }
        }

        return $data;
    }

    /**
     * @param $defaultLimit
     */
    protected function getPaginationParams($defaultLimit = 20)
    {
        $page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
        $limit = isset($_GET['limit']) ? max(1, intval($_GET['limit'])) : $defaultLimit;
        $offset = ($page - 1) * $limit;

        return [
            'page' => $page,
            'limit' => $limit,
            'offset' => $offset
        ];
    }

    /**
     * @param $totalItems
     * @param $page
     * @param $limit
     */
    protected function calculatePagination($totalItems, $page, $limit)
    {
        return [
            'total' => $totalItems,
            'page' => $page,
            'limit' => $limit,
            'pages' => ceil($totalItems / $limit)
        ];
    }
}
