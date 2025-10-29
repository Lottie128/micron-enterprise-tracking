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

class PurchaseOrderAPI {
    private $db;
    
    public function __construct() {
        $database = new Database();
        $this->db = $database->getConnection();
    }
    
    public function createPO($data, $userId) {
        try {
            $this->db->beginTransaction();
            
            // Generate PO number
            $poNumber = $this->generatePONumber($data['client_id'], $data['po_date']);
            
            // Insert PO
            $poStmt = $this->db->prepare("
                INSERT INTO purchase_orders (
                    po_number, client_id, po_date, delivery_date, notes, created_by
                ) VALUES (?, ?, ?, ?, ?, ?)
            ");
            
            $poStmt->execute([
                $poNumber,
                $data['client_id'],
                $data['po_date'],
                $data['delivery_date'] ?? null,
                $data['notes'] ?? null,
                $userId
            ]);
            
            $poId = $this->db->lastInsertId();
            
            // Insert PO items
            if (isset($data['items']) && is_array($data['items'])) {
                $itemStmt = $this->db->prepare("
                    INSERT INTO po_items (
                        po_id, part_id, quantity_ordered, unit_price, total_price
                    ) VALUES (?, ?, ?, ?, ?)
                ");
                
                $totalValue = 0;
                foreach ($data['items'] as $item) {
                    $totalPrice = $item['quantity'] * $item['unit_price'];
                    $totalValue += $totalPrice;
                    
                    $itemStmt->execute([
                        $poId,
                        $item['part_id'],
                        $item['quantity'],
                        $item['unit_price'],
                        $totalPrice
                    ]);
                }
                
                // Update PO total value
                $updateTotalStmt = $this->db->prepare("UPDATE purchase_orders SET total_value = ? WHERE id = ?");
                $updateTotalStmt->execute([$totalValue, $poId]);
            }
            
            $this->db->commit();
            
            return [
                'success' => true,
                'message' => 'Purchase order created successfully',
                'po_id' => $poId,
                'po_number' => $poNumber
            ];
            
        } catch (Exception $e) {
            $this->db->rollBack();
            error_log("Create PO error: " . $e->getMessage());
            return [
                'success' => false,
                'message' => 'Failed to create PO: ' . $e->getMessage()
            ];
        }
    }
    
    private function generatePONumber($clientId, $poDate) {
        // Get client code
        $clientStmt = $this->db->prepare("SELECT client_code FROM clients WHERE id = ?");
        $clientStmt->execute([$clientId]);
        $client = $clientStmt->fetch();
        
        $clientCode = $client ? $client['client_code'] : 'GEN';
        $dateCode = date('Ymd', strtotime($poDate));
        
        // Get next sequence number for the day
        $seqStmt = $this->db->prepare("
            SELECT COUNT(*) + 1 as next_seq 
            FROM purchase_orders 
            WHERE DATE(po_date) = ? AND client_id = ?
        ");
        $seqStmt->execute([$poDate, $clientId]);
        $seq = $seqStmt->fetch()['next_seq'];
        
        return sprintf('%s-%s-%03d', $clientCode, $dateCode, $seq);
    }
    
    public function getPOs($userId, $userRole, $clientId = null) {
        try {
            $sql = "
                SELECT po.*, c.company_name, c.client_code,
                       u.full_name as created_by_name,
                       COUNT(poi.id) as total_items,
                       SUM(poi.quantity_ordered) as total_quantity,
                       SUM(poi.quantity_completed) as completed_quantity
                FROM purchase_orders po
                JOIN clients c ON po.client_id = c.id
                JOIN users u ON po.created_by = u.id
                LEFT JOIN po_items poi ON po.id = poi.po_id
            ";
            
            $params = [];
            $whereConditions = [];
            
            // Role-based filtering
            if ($userRole === 'client') {
                $whereConditions[] = "po.client_id = ?";
                $params[] = $clientId;
            } elseif ($userRole === 'po_creator') {
                $whereConditions[] = "po.created_by = ?";
                $params[] = $userId;
            }
            // Admin and operators see all POs
            
            if (!empty($whereConditions)) {
                $sql .= " WHERE " . implode(" AND ", $whereConditions);
            }
            
            $sql .= " GROUP BY po.id ORDER BY po.created_at DESC";
            
            $stmt = $this->db->prepare($sql);
            $stmt->execute($params);
            $pos = $stmt->fetchAll();
            
            return [
                'success' => true,
                'purchase_orders' => $pos
            ];
        } catch (Exception $e) {
            return [
                'success' => false,
                'message' => 'Failed to get POs: ' . $e->getMessage()
            ];
        }
    }
    
    public function getPODetails($poId, $userId, $userRole, $clientId = null) {
        try {
            // Check access rights
            $accessSql = "SELECT po.*, c.company_name FROM purchase_orders po JOIN clients c ON po.client_id = c.id WHERE po.id = ?";
            $accessParams = [$poId];
            
            if ($userRole === 'client') {
                $accessSql .= " AND po.client_id = ?";
                $accessParams[] = $clientId;
            } elseif ($userRole === 'po_creator') {
                $accessSql .= " AND po.created_by = ?";
                $accessParams[] = $userId;
            }
            
            $accessStmt = $this->db->prepare($accessSql);
            $accessStmt->execute($accessParams);
            $po = $accessStmt->fetch();
            
            if (!$po) {
                return [
                    'success' => false,
                    'message' => 'PO not found or access denied'
                ];
            }
            
            // Get PO items with progress
            $itemsStmt = $this->db->prepare("
                SELECT poi.*, p.part_number, p.part_name, p.series,
                       (poi.quantity_completed / poi.quantity_ordered * 100) as completion_percentage
                FROM po_items poi
                JOIN parts p ON poi.part_id = p.id
                WHERE poi.po_id = ?
                ORDER BY p.part_number
            ");
            $itemsStmt->execute([$poId]);
            $items = $itemsStmt->fetchAll();
            
            // Get stage-wise progress for each item
            foreach ($items as &$item) {
                $progressStmt = $this->db->prepare("
                    SELECT s.stage_name, s.stage_code,
                           COALESCE(SUM(bm.quantity_out), 0) as completed_at_stage
                    FROM stages s
                    LEFT JOIN bin_movements bm ON s.id = bm.to_stage_id 
                        AND bm.po_item_id = ?
                    ORDER BY s.stage_order
                ");
                $progressStmt->execute([$item['id']]);
                $item['stage_progress'] = $progressStmt->fetchAll();
            }
            
            return [
                'success' => true,
                'purchase_order' => $po,
                'items' => $items
            ];
        } catch (Exception $e) {
            return [
                'success' => false,
                'message' => 'Failed to get PO details: ' . $e->getMessage()
            ];
        }
    }
    
    public function updatePOStatus($poId, $status, $userId, $userRole) {
        try {
            // Check if user can update this PO
            $checkStmt = $this->db->prepare("SELECT created_by FROM purchase_orders WHERE id = ?");
            $checkStmt->execute([$poId]);
            $po = $checkStmt->fetch();
            
            if (!$po) {
                return ['success' => false, 'message' => 'PO not found'];
            }
            
            if ($userRole !== 'admin' && $po['created_by'] != $userId) {
                return ['success' => false, 'message' => 'Access denied'];
            }
            
            $updateStmt = $this->db->prepare("UPDATE purchase_orders SET status = ? WHERE id = ?");
            $updateStmt->execute([$status, $poId]);
            
            return [
                'success' => true,
                'message' => 'PO status updated successfully'
            ];
        } catch (Exception $e) {
            return [
                'success' => false,
                'message' => 'Failed to update PO status: ' . $e->getMessage()
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

$poAPI = new PurchaseOrderAPI();
$method = $_SERVER['REQUEST_METHOD'];
$input = json_decode(file_get_contents('php://input'), true) ?? [];

$userId = $user['user']['id'];
$userRole = $user['user']['role'];
$clientId = null;

// Get client ID for client users
if ($userRole === 'client') {
    $clientStmt = $poAPI->db->prepare("SELECT id FROM clients WHERE company_name = ? OR contact_person LIKE ?");
    $clientStmt->execute([$user['user']['client_company'], '%' . $user['user']['full_name'] . '%']);
    $client = $clientStmt->fetch();
    $clientId = $client ? $client['id'] : null;
}

switch ($method) {
    case 'GET':
        if (isset($_GET['po_id'])) {
            $result = $poAPI->getPODetails($_GET['po_id'], $userId, $userRole, $clientId);
        } else {
            $result = $poAPI->getPOs($userId, $userRole, $clientId);
        }
        break;
        
    case 'POST':
        $action = $input['action'] ?? 'create';
        
        if ($action === 'create') {
            if ($userRole === 'operator') {
                $result = ['success' => false, 'message' => 'Operators cannot create POs'];
            } else {
                $result = $poAPI->createPO($input, $userId);
            }
        } else {
            $result = ['success' => false, 'message' => 'Invalid action'];
        }
        break;
        
    case 'PUT':
        if (isset($input['po_id']) && isset($input['status'])) {
            $result = $poAPI->updatePOStatus($input['po_id'], $input['status'], $userId, $userRole);
        } else {
            $result = ['success' => false, 'message' => 'Missing required parameters'];
        }
        break;
        
    default:
        $result = ['success' => false, 'message' => 'Method not allowed'];
        break;
}

echo json_encode($result);
?>