<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, GET, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

require_once '../config/database.php';
require_once '../config/auth_middleware.php';

class PartsAPI {
    private $db;
    
    public function __construct() {
        $database = new Database();
        $this->db = $database->getConnection();
    }
    
    public function getAllParts($search = null, $series = null) {
        try {
            $sql = "SELECT * FROM parts WHERE is_active = 1";
            $params = [];
            
            if ($search) {
                $sql .= " AND (part_number LIKE ? OR part_name LIKE ? OR description LIKE ?)";
                $searchTerm = "%$search%";
                $params[] = $searchTerm;
                $params[] = $searchTerm;
                $params[] = $searchTerm;
            }
            
            if ($series) {
                $sql .= " AND series = ?";
                $params[] = $series;
            }
            
            $sql .= " ORDER BY part_number";
            
            $stmt = $this->db->prepare($sql);
            $stmt->execute($params);
            $parts = $stmt->fetchAll();
            
            return [
                'success' => true,
                'parts' => $parts
            ];
        } catch (Exception $e) {
            return [
                'success' => false,
                'message' => 'Failed to get parts: ' . $e->getMessage()
            ];
        }
    }
    
    public function getSeriesList() {
        try {
            $stmt = $this->db->prepare("SELECT DISTINCT series FROM parts WHERE is_active = 1 ORDER BY series");
            $stmt->execute();
            $series = $stmt->fetchAll(PDO::FETCH_COLUMN);
            
            return [
                'success' => true,
                'series' => $series
            ];
        } catch (Exception $e) {
            return [
                'success' => false,
                'message' => 'Failed to get series: ' . $e->getMessage()
            ];
        }
    }
    
    public function addPart($data, $userRole) {
        if ($userRole !== 'admin') {
            return [
                'success' => false,
                'message' => 'Only admins can add parts'
            ];
        }
        
        try {
            $stmt = $this->db->prepare("
                INSERT INTO parts (part_number, part_name, series, material, description, weight_grams)
                VALUES (?, ?, ?, ?, ?, ?)
            ");
            
            $stmt->execute([
                $data['part_number'],
                $data['part_name'],
                $data['series'],
                $data['material'] ?? null,
                $data['description'] ?? null,
                $data['weight_grams'] ?? null
            ]);
            
            return [
                'success' => true,
                'message' => 'Part added successfully',
                'part_id' => $this->db->lastInsertId()
            ];
        } catch (Exception $e) {
            if ($e->getCode() == 23000) { // Duplicate entry
                return [
                    'success' => false,
                    'message' => 'Part number already exists'
                ];
            }
            return [
                'success' => false,
                'message' => 'Failed to add part: ' . $e->getMessage()
            ];
        }
    }
    
    public function updatePart($partId, $data, $userRole) {
        if ($userRole !== 'admin') {
            return [
                'success' => false,
                'message' => 'Only admins can update parts'
            ];
        }
        
        try {
            $stmt = $this->db->prepare("
                UPDATE parts SET 
                    part_name = ?, series = ?, material = ?, 
                    description = ?, weight_grams = ?
                WHERE id = ?
            ");
            
            $stmt->execute([
                $data['part_name'],
                $data['series'],
                $data['material'] ?? null,
                $data['description'] ?? null,
                $data['weight_grams'] ?? null,
                $partId
            ]);
            
            return [
                'success' => true,
                'message' => 'Part updated successfully'
            ];
        } catch (Exception $e) {
            return [
                'success' => false,
                'message' => 'Failed to update part: ' . $e->getMessage()
            ];
        }
    }
    
    public function getPartProduction($partId, $dateFrom = null, $dateTo = null) {
        try {
            $sql = "
                SELECT bm.*, s.stage_name, u.full_name as operator_name,
                       po.po_number, c.company_name
                FROM bin_movements bm
                JOIN stages s ON bm.to_stage_id = s.id
                JOIN users u ON bm.operator_id = u.id
                JOIN po_items poi ON bm.po_item_id = poi.id
                JOIN purchase_orders po ON poi.po_id = po.id
                JOIN clients c ON po.client_id = c.id
                WHERE bm.part_id = ?
            ";
            
            $params = [$partId];
            
            if ($dateFrom) {
                $sql .= " AND DATE(bm.created_at) >= ?";
                $params[] = $dateFrom;
            }
            
            if ($dateTo) {
                $sql .= " AND DATE(bm.created_at) <= ?";
                $params[] = $dateTo;
            }
            
            $sql .= " ORDER BY bm.created_at DESC";
            
            $stmt = $this->db->prepare($sql);
            $stmt->execute($params);
            $production = $stmt->fetchAll();
            
            return [
                'success' => true,
                'production_history' => $production
            ];
        } catch (Exception $e) {
            return [
                'success' => false,
                'message' => 'Failed to get part production: ' . $e->getMessage()
            ];
        }
    }
}

// Authentication
$token = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
if (strpos($token, 'Bearer ') === 0) {
    $token = substr($token, 7);
}

if (empty($token)) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Authentication required']);
    exit;
}

$auth = new AuthAPI();
$user = $auth->validateToken($token);
if (!$user['success']) {
    http_response_code(401);
    echo json_encode($user);
    exit;
}

$partsAPI = new PartsAPI();
$method = $_SERVER['REQUEST_METHOD'];
$input = json_decode(file_get_contents('php://input'), true) ?? [];

switch ($method) {
    case 'GET':
        $action = $_GET['action'] ?? 'list';
        
        if ($action === 'list') {
            $search = $_GET['search'] ?? null;
            $series = $_GET['series'] ?? null;
            $result = $partsAPI->getAllParts($search, $series);
        } elseif ($action === 'series') {
            $result = $partsAPI->getSeriesList();
        } elseif ($action === 'production' && isset($_GET['part_id'])) {
            $dateFrom = $_GET['date_from'] ?? null;
            $dateTo = $_GET['date_to'] ?? null;
            $result = $partsAPI->getPartProduction($_GET['part_id'], $dateFrom, $dateTo);
        } else {
            $result = ['success' => false, 'message' => 'Invalid action'];
        }
        break;
        
    case 'POST':
        $result = $partsAPI->addPart($input, $user['user']['role']);
        break;
        
    case 'PUT':
        if (isset($input['part_id'])) {
            $result = $partsAPI->updatePart($input['part_id'], $input, $user['user']['role']);
        } else {
            $result = ['success' => false, 'message' => 'Part ID required'];
        }
        break;
        
    default:
        $result = ['success' => false, 'message' => 'Method not allowed'];
        break;
}

echo json_encode($result);
?>