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

class BinAPI {
    private $db;
    
    public function __construct() {
        $database = new Database();
        $this->db = $database->getConnection();
    }
    
    public function scanBin($barcode) {
        try {
            $stmt = $this->db->prepare("
                SELECT b.*, p.part_number, p.part_name, p.series,
                       s.stage_name, s.stage_code,
                       poi.po_id, poi.quantity_ordered, poi.quantity_completed,
                       po.po_number, c.company_name
                FROM bins b
                LEFT JOIN parts p ON b.current_part_id = p.id
                LEFT JOIN stages s ON b.current_stage_id = s.id
                LEFT JOIN po_items poi ON p.id = poi.part_id AND b.current_part_id = poi.part_id
                LEFT JOIN purchase_orders po ON poi.po_id = po.id AND po.status != 'completed'
                LEFT JOIN clients c ON po.client_id = c.id
                WHERE b.barcode = ?
                ORDER BY po.created_at DESC
                LIMIT 1
            ");
            $stmt->execute([$barcode]);
            $bin = $stmt->fetch();
            
            if (!$bin) {
                return [
                    'success' => false,
                    'message' => 'Bin not found'
                ];
            }
            
            // Get available stages for transfer
            $stagesStmt = $this->db->prepare("SELECT * FROM stages ORDER BY stage_order");
            $stagesStmt->execute();
            $stages = $stagesStmt->fetchAll();
            
            // Get rejection reasons
            $reasonsStmt = $this->db->prepare("SELECT * FROM rejection_reasons WHERE is_active = 1");
            $reasonsStmt->execute();
            $rejectionReasons = $reasonsStmt->fetchAll();
            
            return [
                'success' => true,
                'bin' => $bin,
                'stages' => $stages,
                'rejection_reasons' => $rejectionReasons
            ];
        } catch (Exception $e) {
            error_log("Scan bin error: " . $e->getMessage());
            return [
                'success' => false,
                'message' => 'Failed to scan bin: ' . $e->getMessage()
            ];
        }
    }
    
    public function getAvailableBins($stage_id = null) {
        try {
            $sql = "SELECT * FROM bins WHERE status = 'empty' ORDER BY bin_code";
            $params = [];
            
            if ($stage_id) {
                // Also include bins at the same stage
                $sql = "SELECT * FROM bins WHERE (status = 'empty' OR current_stage_id = ?) ORDER BY status, bin_code";
                $params = [$stage_id];
            }
            
            $stmt = $this->db->prepare($sql);
            $stmt->execute($params);
            $bins = $stmt->fetchAll();
            
            return [
                'success' => true,
                'bins' => $bins
            ];
        } catch (Exception $e) {
            return [
                'success' => false,
                'message' => 'Failed to get available bins: ' . $e->getMessage()
            ];
        }
    }
    
    public function createMovement($data) {
        try {
            $this->db->beginTransaction();
            
            // Validate required fields
            $required = ['bin_id', 'po_item_id', 'part_id', 'to_stage_id', 'quantity_in', 'operator_id'];
            foreach ($required as $field) {
                if (!isset($data[$field]) || empty($data[$field])) {
                    throw new Exception("Missing required field: $field");
                }
            }
            
            $binId = $data['bin_id'];
            $poItemId = $data['po_item_id'];
            $partId = $data['part_id'];
            $fromStageId = $data['from_stage_id'] ?? null;
            $toStageId = $data['to_stage_id'];
            $quantityIn = (int)$data['quantity_in'];
            $quantityRejected = (int)($data['quantity_rejected'] ?? 0);
            $quantityRework = (int)($data['quantity_rework'] ?? 0);
            $rejectionReason = $data['rejection_reason'] ?? null;
            $reworkReason = $data['rework_reason'] ?? null;
            $operatorId = $data['operator_id'];
            $notes = $data['notes'] ?? null;
            
            // Calculate quantity out (good pieces)
            $quantityOut = $quantityIn - $quantityRejected - $quantityRework;
            
            if ($quantityOut < 0) {
                throw new Exception("Invalid quantities: rejections + rework cannot exceed input quantity");
            }
            
            // Get bin capacity
            $binStmt = $this->db->prepare("SELECT max_capacity, current_quantity FROM bins WHERE id = ?");
            $binStmt->execute([$binId]);
            $bin = $binStmt->fetch();
            
            if (!$bin) {
                throw new Exception("Bin not found");
            }
            
            // Check capacity
            if ($quantityOut > $bin['max_capacity']) {
                throw new Exception("Quantity exceeds bin capacity of {$bin['max_capacity']}");
            }
            
            // Determine movement type
            $movementType = $fromStageId ? 'transfer' : 'incoming';
            if ($toStageId == 12) { // Finished Goods stage
                $movementType = 'completion';
            }
            
            // Insert bin movement
            $movementStmt = $this->db->prepare("
                INSERT INTO bin_movements (
                    bin_id, po_item_id, part_id, from_stage_id, to_stage_id,
                    quantity_in, quantity_out, quantity_rejected, quantity_rework,
                    rejection_reason, rework_reason, operator_id, movement_type, notes
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            
            $movementStmt->execute([
                $binId, $poItemId, $partId, $fromStageId, $toStageId,
                $quantityIn, $quantityOut, $quantityRejected, $quantityRework,
                $rejectionReason, $reworkReason, $operatorId, $movementType, $notes
            ]);
            
            // Update bin status
            $binUpdateStmt = $this->db->prepare("
                UPDATE bins SET 
                    current_quantity = ?,
                    current_part_id = ?,
                    current_stage_id = ?,
                    status = 'in_use',
                    last_updated_by = ?
                WHERE id = ?
            ");
            $binUpdateStmt->execute([$quantityOut, $partId, $toStageId, $operatorId, $binId]);
            
            // Update PO item completion if moving to finished goods
            if ($movementType === 'completion') {
                $poUpdateStmt = $this->db->prepare("
                    UPDATE po_items SET 
                        quantity_completed = quantity_completed + ?
                    WHERE id = ?
                ");
                $poUpdateStmt->execute([$quantityOut, $poItemId]);
                
                // Check if PO item is complete
                $poItemStmt = $this->db->prepare("SELECT quantity_ordered, quantity_completed FROM po_items WHERE id = ?");
                $poItemStmt->execute([$poItemId]);
                $poItem = $poItemStmt->fetch();
                
                if ($poItem['quantity_completed'] >= $poItem['quantity_ordered']) {
                    $poItemCompleteStmt = $this->db->prepare("UPDATE po_items SET status = 'completed' WHERE id = ?");
                    $poItemCompleteStmt->execute([$poItemId]);
                }
            }
            
            // Insert production log
            $prodLogStmt = $this->db->prepare("
                INSERT INTO production_logs (
                    po_item_id, stage_id, operator_id, quantity_processed,
                    quantity_passed, quantity_rejected, quantity_rework, notes
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?)
            ");
            
            $prodLogStmt->execute([
                $poItemId, $toStageId, $operatorId, $quantityIn,
                $quantityOut, $quantityRejected, $quantityRework, $notes
            ]);
            
            $this->db->commit();
            
            return [
                'success' => true,
                'message' => 'Movement recorded successfully',
                'movement_id' => $this->db->lastInsertId()
            ];
            
        } catch (Exception $e) {
            $this->db->rollBack();
            error_log("Create movement error: " . $e->getMessage());
            return [
                'success' => false,
                'message' => 'Failed to record movement: ' . $e->getMessage()
            ];
        }
    }
    
    public function transferToBin($data) {
        try {
            $this->db->beginTransaction();
            
            $fromBinId = $data['from_bin_id'];
            $toBinId = $data['to_bin_id'];
            $operatorId = $data['operator_id'];
            
            // Get source bin data
            $fromBinStmt = $this->db->prepare("
                SELECT current_quantity, current_part_id, current_stage_id 
                FROM bins WHERE id = ? AND status = 'in_use'
            ");
            $fromBinStmt->execute([$fromBinId]);
            $fromBin = $fromBinStmt->fetch();
            
            if (!$fromBin || $fromBin['current_quantity'] <= 0) {
                throw new Exception("Source bin is empty or not found");
            }
            
            // Get target bin capacity
            $toBinStmt = $this->db->prepare("SELECT max_capacity FROM bins WHERE id = ? AND status = 'empty'");
            $toBinStmt->execute([$toBinId]);
            $toBin = $toBinStmt->fetch();
            
            if (!$toBin) {
                throw new Exception("Target bin is not available");
            }
            
            if ($fromBin['current_quantity'] > $toBin['max_capacity']) {
                throw new Exception("Quantity exceeds target bin capacity");
            }
            
            // Update target bin
            $updateToBinStmt = $this->db->prepare("
                UPDATE bins SET 
                    current_quantity = ?,
                    current_part_id = ?,
                    current_stage_id = ?,
                    status = 'in_use',
                    last_updated_by = ?
                WHERE id = ?
            ");
            $updateToBinStmt->execute([
                $fromBin['current_quantity'],
                $fromBin['current_part_id'],
                $fromBin['current_stage_id'],
                $operatorId,
                $toBinId
            ]);
            
            // Clear source bin
            $updateFromBinStmt = $this->db->prepare("
                UPDATE bins SET 
                    current_quantity = 0,
                    current_part_id = NULL,
                    current_stage_id = NULL,
                    status = 'empty',
                    last_updated_by = ?
                WHERE id = ?
            ");
            $updateFromBinStmt->execute([$operatorId, $fromBinId]);
            
            $this->db->commit();
            
            return [
                'success' => true,
                'message' => 'Transfer completed successfully'
            ];
            
        } catch (Exception $e) {
            $this->db->rollBack();
            return [
                'success' => false,
                'message' => 'Transfer failed: ' . $e->getMessage()
            ];
        }
    }
    
    public function getBinHistory($binId) {
        try {
            $stmt = $this->db->prepare("
                SELECT bm.*, p.part_number, p.part_name,
                       fs.stage_name as from_stage_name,
                       ts.stage_name as to_stage_name,
                       u.full_name as operator_name,
                       po.po_number
                FROM bin_movements bm
                JOIN parts p ON bm.part_id = p.id
                LEFT JOIN stages fs ON bm.from_stage_id = fs.id
                JOIN stages ts ON bm.to_stage_id = ts.id
                JOIN users u ON bm.operator_id = u.id
                JOIN po_items poi ON bm.po_item_id = poi.id
                JOIN purchase_orders po ON poi.po_id = po.id
                WHERE bm.bin_id = ?
                ORDER BY bm.created_at DESC
            ");
            $stmt->execute([$binId]);
            $history = $stmt->fetchAll();
            
            return [
                'success' => true,
                'history' => $history
            ];
        } catch (Exception $e) {
            return [
                'success' => false,
                'message' => 'Failed to get bin history: ' . $e->getMessage()
            ];
        }
    }
}

// Authentication middleware
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

$binAPI = new BinAPI();
$method = $_SERVER['REQUEST_METHOD'];
$input = json_decode(file_get_contents('php://input'), true) ?? [];
$input['operator_id'] = $user['user']['id'];

switch ($method) {
    case 'GET':
        $action = $_GET['action'] ?? '';
        
        if ($action === 'scan' && isset($_GET['barcode'])) {
            $result = $binAPI->scanBin($_GET['barcode']);
        } elseif ($action === 'available') {
            $stageId = $_GET['stage_id'] ?? null;
            $result = $binAPI->getAvailableBins($stageId);
        } elseif ($action === 'history' && isset($_GET['bin_id'])) {
            $result = $binAPI->getBinHistory($_GET['bin_id']);
        } else {
            $result = ['success' => false, 'message' => 'Invalid action or missing parameters'];
        }
        break;
        
    case 'POST':
        $action = $input['action'] ?? '';
        
        if ($action === 'movement') {
            $result = $binAPI->createMovement($input);
        } elseif ($action === 'transfer') {
            $result = $binAPI->transferToBin($input);
        } else {
            $result = ['success' => false, 'message' => 'Invalid action'];
        }
        break;
        
    default:
        $result = ['success' => false, 'message' => 'Method not allowed'];
        break;
}

echo json_encode($result);
?>