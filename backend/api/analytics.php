<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { exit(0); }
require_once '../config/database.php';
require_once '../config/auth_middleware.php';

class AnalyticsAPI { private $db; public function __construct(){ $this->db=(new Database())->getConnection(); }
  public function dashboard(){
    $out=[];
    // Stage throughput
    $stmt=$this->db->prepare("SELECT s.stage_name, SUM(bm.quantity_out) as passed, SUM(bm.quantity_rejected) as rejected FROM stages s LEFT JOIN bin_movements bm ON bm.to_stage_id=s.id AND bm.created_at>=DATE_SUB(NOW(), INTERVAL 30 DAY) GROUP BY s.id ORDER BY s.stage_order");
    $stmt->execute(); $out['stage_throughput']=$stmt->fetchAll();
    // Operator performance
    $stmt=$this->db->prepare("SELECT u.full_name, s.stage_name, SUM(pl.quantity_passed) as passed, SUM(pl.quantity_rejected) as rejected FROM production_logs pl JOIN users u ON pl.operator_id=u.id JOIN stages s ON pl.stage_id=s.id WHERE pl.created_at>=DATE_SUB(NOW(), INTERVAL 30 DAY) GROUP BY pl.operator_id, pl.stage_id ORDER BY passed DESC");
    $stmt->execute(); $out['operator_performance']=$stmt->fetchAll();
    // Rejection reasons
    $stmt=$this->db->prepare("SELECT rr.reason_description, COUNT(*) as occurrences, SUM(bm.quantity_rejected) as qty FROM bin_movements bm JOIN rejection_reasons rr ON bm.rejection_reason=rr.reason_code WHERE bm.quantity_rejected>0 AND bm.created_at>=DATE_SUB(NOW(), INTERVAL 30 DAY) GROUP BY rr.id ORDER BY qty DESC");
    $stmt->execute(); $out['rejection_reasons']=$stmt->fetchAll();
    // PO risk (incomplete vs due date)
    $stmt=$this->db->prepare("SELECT po.id, po.po_number, po.delivery_date, SUM(poi.quantity_ordered) as ordered, SUM(poi.quantity_completed) as completed FROM purchase_orders po JOIN po_items poi ON po.id=poi.po_id WHERE po.status!='completed' GROUP BY po.id ORDER BY po.delivery_date IS NULL, po.delivery_date");
    $stmt->execute(); $out['po_risk']=$stmt->fetchAll();
    return ['success'=>true]+$out;
  }
}

$token=$_SERVER['HTTP_AUTHORIZATION']??''; if(strpos($token,'Bearer ')===0){ $token=substr($token,7);} 
$auth=new AuthAPI(); $user=$auth->validateToken($token); if(!$user['success']){ http_response_code(401); echo json_encode($user); exit; }
$api=new AnalyticsAPI(); echo json_encode($api->dashboard());
?>