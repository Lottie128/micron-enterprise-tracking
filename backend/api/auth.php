<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

require_once '../config/database.php';

class AuthAPI {
    private $db;
    
    public function __construct() {
        $database = new Database();
        $this->db = $database->getConnection();
    }
    
    public function login($username, $password) {
        try {
            $stmt = $this->db->prepare("
                SELECT id, username, email, password_hash, role, full_name, 
                       machine_line, stage_access, client_company, is_active 
                FROM users 
                WHERE (username = ? OR email = ?) AND is_active = 1
            ");
            $stmt->execute([$username, $username]);
            $user = $stmt->fetch();
            
            if ($user && password_verify($password, $user['password_hash'])) {
                // Generate session token (simple implementation)
                $token = bin2hex(random_bytes(32));
                
                // Store session in database (you might want to create a sessions table)
                $sessionStmt = $this->db->prepare("
                    INSERT INTO user_sessions (user_id, token, expires_at) 
                    VALUES (?, ?, DATE_ADD(NOW(), INTERVAL 8 HOUR))
                    ON DUPLICATE KEY UPDATE 
                    token = VALUES(token), expires_at = VALUES(expires_at)
                ");
                
                // Create sessions table if not exists
                $this->db->exec("
                    CREATE TABLE IF NOT EXISTS user_sessions (
                        user_id INT PRIMARY KEY,
                        token VARCHAR(64) NOT NULL,
                        expires_at TIMESTAMP NOT NULL,
                        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
                    )
                ");
                
                $sessionStmt->execute([$user['id'], $token]);
                
                unset($user['password_hash']);
                $user['token'] = $token;
                
                return [
                    'success' => true,
                    'message' => 'Login successful',
                    'user' => $user
                ];
            } else {
                return [
                    'success' => false,
                    'message' => 'Invalid credentials'
                ];
            }
        } catch (Exception $e) {
            error_log("Login error: " . $e->getMessage());
            return [
                'success' => false,
                'message' => 'Login failed: ' . $e->getMessage()
            ];
        }
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

$auth = new AuthAPI();
$method = $_SERVER['REQUEST_METHOD'];
$input = json_decode(file_get_contents('php://input'), true);

switch ($method) {
    case 'POST':
        $action = $input['action'] ?? '';
        
        if ($action === 'login') {
            $username = $input['username'] ?? '';
            $password = $input['password'] ?? '';
            
            if (empty($username) || empty($password)) {
                echo json_encode([
                    'success' => false,
                    'message' => 'Username and password are required'
                ]);
                exit;
            }
            
            $result = $auth->login($username, $password);
            echo json_encode($result);
        } elseif ($action === 'validate') {
            $token = $input['token'] ?? '';
            $result = $auth->validateToken($token);
            echo json_encode($result);
        } else {
            echo json_encode([
                'success' => false,
                'message' => 'Invalid action'
            ]);
        }
        break;
        
    default:
        echo json_encode([
            'success' => false,
            'message' => 'Method not allowed'
        ]);
        break;
}
?>