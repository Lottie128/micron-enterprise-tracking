<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { exit(0); }
require_once '../config/database.php';
require_once '../config/auth_middleware.php';

class RejectionAPI {
  private $db; 
  public function __construct(){ $this->db=(new Database())->getConnection(); }
  public function list(){
    $stmt=$this->db->prepare("SELECT id,reason_code,reason_description,stage_id FROM rejection_reasons WHERE is_active=1 ORDER BY reason_description");
    $stmt->execute(); return ['success'=>true,'reasons'=>$stmt->fetchAll()];
  }
  public function add($data,$role){
    if($role!=='admin'){ return ['success'=>false,'message'=>'Only admin']; }
    $stmt=$this->db->prepare("INSERT INTO rejection_reasons(reason_code,reason_description,stage_id) VALUES(?,?,?)");
    $stmt->execute([$data['reason_code'],$data['reason_description'],$data['stage_id']??null]);
    return ['success'=>true,'id'=>$this->db->lastInsertId()];
  }
}

$token=$_SERVER['HTTP_AUTHORIZATION']??''; if(strpos($token,'Bearer ')===0){ $token=substr($token,7);} 
$auth=new AuthAPI(); $user=$auth->validateToken($token); if(!$user['success']){ http_response_code(401); echo json_encode($user); exit; }
$api=new RejectionAPI(); $method=$_SERVER['REQUEST_METHOD']; $input=json_decode(file_get_contents('php://input'),true)??[];
if($method==='GET'){ echo json_encode($api->list()); }
elseif($method==='POST'){ echo json_encode($api->add($input,$user['user']['role'])); }
else { echo json_encode(['success'=>false,'message'=>'Method not allowed']); }
?>