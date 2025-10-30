<?php
// Auth API - moved to root level for easier debugging
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

// Simple database connection without config files
class SimpleDatabase {
    private $connection;
    
    public function connect() {
        // Load .env manually
        $envFile = __DIR__ . '/.env';
        $env = [];
        if (file_exists($envFile)) {
            $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            foreach ($lines as $line) {
                if (strpos($line, '=') !== false && strpos($line, '#') !== 0) {
                    list($key, $value) = explode('=', $line, 2);
                    $env[trim($key)] = trim($value);
                }
            }
        }
        
        $host = $env['DB_HOST'] ?? 'localhost';
        $username = $env['DB_USERNAME'] ?? 'root';
        $password = $env['DB_PASSWORD'] ?? '';
        $database = $env['DB_NAME'] ?? 'micron_tracking';
        
        try {
            $this->connection = new PDO(
                "mysql:host={$host};dbname={$database};charset=utf8mb4",
                $username,
                $password,
                [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
                ]
            );
            return $this->connection;
        } catch (PDOException $e) {
            throw new Exception("Database connection failed: " . $e->getMessage());
        }
    }
}

class SimpleAuth {
    private $db;
    
    public function __construct() {
        $database = new SimpleDatabase();
        $this->db = $database->connect();
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
                $token = bin2hex(random_bytes(32));
                
                // Create sessions table if not exists
                $this->db->exec("
                    CREATE TABLE IF NOT EXISTS user_sessions (
                        user_id INT PRIMARY KEY,
                        token VARCHAR(64) NOT NULL,
                        expires_at TIMESTAMP NOT NULL,
                        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
                    )
                ");
                
                $sessionStmt = $this->db->prepare("
                    INSERT INTO user_sessions (user_id, token, expires_at) 
                    VALUES (?, ?, DATE_ADD(NOW(), INTERVAL 8 HOUR))
                    ON DUPLICATE KEY UPDATE 
                    token = VALUES(token), expires_at = VALUES(expires_at)
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
                'message' => 'Token validation failed: ' . $e->getMessage()
            ];
        }
    }
}

try {
    $auth = new SimpleAuth();
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
                'message' => 'Method not allowed. Use POST with action=login'
            ]);
            break;
    }
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => 'Server error: ' . $e->getMessage()
    ]);
}
?>