<?php
/**
 * Authentication Class - Fixed Memory Issue
 * 
 * Handles user authentication and session management with proper error handling
 */

class Auth {
    private $db;
    private $session_started = false;
    
    /**
     * Constructor
     */
    public function __construct() {
        try {
            $this->db = Database::getInstance();
        } catch (Exception $e) {
            error_log("Auth: Database connection failed - " . $e->getMessage());
            throw new Exception("Database connection failed");
        }
        
        // Initialize session
        $this->initializeSession();
    }
    
    /**
     * Initialize session properly
     */
    private function initializeSession() {
        // Don't start session if headers already sent
        if (headers_sent()) {
            error_log("Auth: Cannot start session - headers already sent");
            return false;
        }
        
        // Don't start if session already active
        if (session_status() !== PHP_SESSION_NONE) {
            $this->session_started = true;
            return true;
        }
        
        try {
            // Set session name if defined
            if (defined('SESSION_NAME')) {
                session_name(SESSION_NAME);
            }
            
            // Start session with basic configuration
            if (session_start()) {
                $this->session_started = true;
                
                // Regenerate session ID periodically for security
                if (!isset($_SESSION['last_regeneration'])) {
                    $_SESSION['last_regeneration'] = time();
                } elseif (time() - $_SESSION['last_regeneration'] > 300) { // 5 minutes
                    session_regenerate_id(true);
                    $_SESSION['last_regeneration'] = time();
                }
                
                return true;
            }
        } catch (Exception $e) {
            error_log("Auth: Session start failed - " . $e->getMessage());
        }
        
        return false;
    }
    
    /**
     * Get client IP address
     */
    private function getClientIP() {
        // Simple IP detection to avoid circular dependency issues
        if (isset($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            $ips = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']);
            return trim($ips[0]);
        }
        
        return $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    }
    
    /**
     * Sanitize input data
     */
    private function sanitizeInput($data) {
        if (is_array($data)) {
            return array_map([$this, 'sanitizeInput'], $data);
        }
        
        // Remove null bytes and trim
        $data = str_replace("\0", '', trim($data));
        
        // Basic sanitization
        return htmlspecialchars($data, ENT_QUOTES, 'UTF-8');
    }
    
    /**
     * Attempt to login a user
     * 
     * @param string $username
     * @param string $password
     * @return bool True if login successful
     */
    public function login($username, $password) {
        // Validate input
        if (empty($username) || empty($password)) {
            return false;
        }
        
        // Sanitize input
        $username = $this->sanitizeInput($username);
        $username = $this->db->escapeString($username);
        
        try {
            $sql = "SELECT * FROM users WHERE username = '$username'";
            $result = $this->db->query($sql);
            
            if ($result && $result->num_rows > 0) {
                $user = $result->fetch_object();
                
                if (password_verify($password, $user->password)) {
                    // Ensure session is started
                    if (!$this->session_started) {
                        $this->initializeSession();
                    }
                    
                    // Set session data
                    $_SESSION['user_id'] = $user->id;
                    $_SESSION['username'] = $user->username;
                    $_SESSION['name'] = $user->name;
                    $_SESSION['role'] = $user->role;
                    $_SESSION['language'] = $user->language ?? 'en';
                    $_SESSION['logged_in'] = true;
                    $_SESSION['login_time'] = time();
                    
                    // Update last login time
                    $now = date('Y-m-d H:i:s');
                    $ip = $this->getClientIP();
                    $escaped_ip = $this->db->escapeString($ip);
                    
                    $update_sql = "UPDATE users SET last_login = '$now', last_ip = '$escaped_ip' WHERE id = {$user->id}";
                    $this->db->query($update_sql);
                    
                    return true;
                }
            }
        } catch (Exception $e) {
            error_log("Auth: Login error - " . $e->getMessage());
        }
        
        return false;
    }
    
    /**
     * Logout the current user
     */
    public function logout() {
        // Clear session data
        if ($this->session_started) {
            $_SESSION = [];
            
            // Destroy session cookie
            if (ini_get("session.use_cookies")) {
                $params = session_get_cookie_params();
                setcookie(session_name(), '', time() - 42000,
                    $params["path"], $params["domain"],
                    $params["secure"], $params["httponly"]
                );
            }
            
            // Destroy session
            session_destroy();
            $this->session_started = false;
        }
    }
    
    /**
     * Check if a user is logged in
     * 
     * @return bool
     */
    public function isLoggedIn() {
        // Check if session is available
        if (!$this->session_started || !isset($_SESSION['logged_in'])) {
            return false;
        }
        
        // Check basic session validity
        if ($_SESSION['logged_in'] !== true || empty($_SESSION['user_id'])) {
            return false;
        }
        
        // Check session timeout
        if (isset($_SESSION['login_time'])) {
            $session_lifetime = defined('SESSION_LIFETIME') ? SESSION_LIFETIME : 3600;
            $session_duration = time() - $_SESSION['login_time'];
            
            if ($session_duration > $session_lifetime) {
                $this->logout();
                return false;
            }
        }
        
        return true;
    }
    
    /**
     * Get the current user
     * 
     * @return object|null
     */
    public function getCurrentUser() {
        if (!$this->isLoggedIn()) {
            return null;
        }
        
        try {
            $id = (int)$_SESSION['user_id'];
            $sql = "SELECT id, username, name, email, role, language, created_at, last_login, last_ip 
                    FROM users WHERE id = $id";
            $result = $this->db->query($sql);
            
            if ($result && $result->num_rows > 0) {
                return $result->fetch_object();
            }
        } catch (Exception $e) {
            error_log("Auth: Error getting current user - " . $e->getMessage());
        }
        
        return null;
    }
    
    /**
     * Update user profile
     * 
     * @param int $user_id
     * @param string $name
     * @param string $email
     * @return bool
     */
    public function updateProfile($user_id, $name, $email = null) {
        try {
            $user_id = (int)$user_id;
            $name = $this->sanitizeInput($name);
            $escaped_name = $this->db->escapeString($name);
            
            $updates = ["name = '$escaped_name'"];
            
            if ($email !== null) {
                $email = $this->sanitizeInput($email);
                $escaped_email = $this->db->escapeString($email);
                $updates[] = "email = '$escaped_email'";
            }
            
            $sql = "UPDATE users SET " . implode(', ', $updates) . " WHERE id = $user_id";
            $result = $this->db->query($sql);
            
            if ($result) {
                // Update session data
                $_SESSION['name'] = $name;
                return true;
            }
        } catch (Exception $e) {
            error_log("Auth: Error updating profile - " . $e->getMessage());
        }
        
        return false;
    }
    
    /**
     * Change a user's password
     * 
     * @param int $user_id
     * @param string $old_password
     * @param string $new_password
     * @return bool
     */
    public function changePassword($user_id, $old_password, $new_password) {
        try {
            $user_id = (int)$user_id;
            
            // Basic password validation
            if (strlen($new_password) < 8) {
                return false;
            }
            
            $sql = "SELECT * FROM users WHERE id = $user_id";
            $result = $this->db->query($sql);
            
            if ($result && $result->num_rows > 0) {
                $user = $result->fetch_object();
                
                if (password_verify($old_password, $user->password)) {
                    $new_password_hash = password_hash($new_password, PASSWORD_DEFAULT);
                    $escaped_hash = $this->db->escapeString($new_password_hash);
                    
                    $update_sql = "UPDATE users SET password = '$escaped_hash' WHERE id = $user_id";
                    $result = $this->db->query($update_sql);
                    
                    return $result ? true : false;
                }
            }
        } catch (Exception $e) {
            error_log("Auth: Error changing password - " . $e->getMessage());
        }
        
        return false;
    }
    
    /**
     * Update user language preference
     * 
     * @param int $user_id
     * @param string $language
     * @return bool
     */
    public function updateLanguage($user_id, $language) {
        if (!in_array($language, ['en', 'el'])) {
            return false;
        }
        
        try {
            $user_id = (int)$user_id;
            $escaped_language = $this->db->escapeString($language);
            
            $sql = "UPDATE users SET language = '$escaped_language' WHERE id = $user_id";
            $result = $this->db->query($sql);
            
            if ($result) {
                $_SESSION['language'] = $language;
                return true;
            }
        } catch (Exception $e) {
            error_log("Auth: Error updating language - " . $e->getMessage());
        }
        
        return false;
    }
    
    /**
     * Check if the current user requires a password reset
     * 
     * @return bool
     */
    public function requiresPasswordReset() {
        if (!$this->isLoggedIn()) {
            return false;
        }
        
        try {
            $user = $this->getCurrentUser();
            
            // Check if it's the default admin user with default password
            if ($user && $user->username === 'admin') {
                $sql = "SELECT password FROM users WHERE id = {$user->id}";
                $result = $this->db->query($sql);
                
                if ($result && $result->num_rows > 0) {
                    $user_data = $result->fetch_object();
                    
                    // Check if default password is still valid
                    if (password_verify('securepassword', $user_data->password)) {
                        return true;
                    }
                }
            }
        } catch (Exception $e) {
            error_log("Auth: Error checking password reset requirement - " . $e->getMessage());
        }
        
        return false;
    }
    
    /**
     * Require authentication for the current page
     * Redirects to login page if not logged in
     */
    public function requireAuth() {
        if (!$this->isLoggedIn()) {
            // Get current page for redirect after login
            $current_page = $_SERVER['REQUEST_URI'] ?? '';
            
            // Clean the URL
            if (strpos($current_page, '/') === 0) {
                $current_page = substr($current_page, 1);
            }
            
            // Don't save certain pages as intended destination
            $excluded_pages = ['login.php', 'index.php', 'logout.php', 'debug.php'];
            $page_name = basename($current_page);
            
            if (!in_array($page_name, $excluded_pages) && !empty($current_page)) {
                $_SESSION['intended_url'] = $current_page;
            }
            
            // Redirect to login
            if (!headers_sent()) {
                header('Location: login.php');
                exit;
            } else {
                // Fallback if headers already sent
                echo '<script>window.location.href="login.php";</script>';
                echo '<noscript><meta http-equiv="refresh" content="0;url=login.php"></noscript>';
                exit;
            }
        }
    }
    
    /**
     * Get session status for debugging
     */
    public function getSessionStatus() {
        return [
            'session_started' => $this->session_started,
            'session_id' => session_id(),
            'session_name' => session_name(),
            'logged_in' => $this->isLoggedIn(),
            'user_id' => $_SESSION['user_id'] ?? null,
            'username' => $_SESSION['username'] ?? null
        ];
    }
}