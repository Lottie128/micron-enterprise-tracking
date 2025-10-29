<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { exit(0); }
require_once '../config/database.php';
require_once '../config/auth_middleware.php';

class ClientsAPI { private $db; public function __construct(){ $this->db=(new Database())->getConnection(); }
  public function list(){ $s=$this->db->prepare("SELECT id, client_code, company_name, contact_person FROM clients WHERE is_active=1 ORDER BY company_name"); $s->execute(); return ['success'=>true,'clients'=>$s->fetchAll()]; }
}

$token=$_SERVER['HTTP_AUTHORIZATION']??''; if(strpos($token,'Bearer ')===0){ $token=substr($token,7);} 
$auth=new AuthAPI(); $user=$auth->validateToken($token); if(!$user['success']){ http_response_code(401); echo json_encode($user); exit; }
$api = new ClientsAPI(); echo json_encode($api->list());
?>