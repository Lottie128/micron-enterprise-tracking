<?php
require_once 'database.php';

class AuthAPI {
    private $db;
    
    public function __construct() {
        $database = new Database();
        $this->db = $database->getConnection();
    }
    
    public function validateToken($token) {
        try {
            $stmt = $this->db->prepare("
                SELECT u.id, u.username, u.role, u.full_name, u.machine_line, u.stage_access, u.client_company
                FROM users u
                JOIN user_sessions s ON u.id = s.user_id
                WHERE s.token = ? AND s.expires_at > NOW() AND u.is_active = 1
            ");
            $stmt->execute([$token]);
            $user = $stmt->fetch();
            
            if ($user) {
                return [
                    'success' => true,
                    'user' => $user
                ];
            } else {
                return [
                    'success' => false,
                    'message' => 'Invalid or expired token'
                ];
            }
        } catch (Exception $e) {
            return [
                'success' => false,
                'message' => 'Token validation failed'
            ];
        }
    }
}
?>