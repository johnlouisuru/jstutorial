<?php
class Database {
    private $host = "localhost";
    private $db_name = "js_tutorial";
    private $username = "root";
    private $password = "";
    private $conn;

    // private $host = "localhost";
    // private $db_name = "bwzavjig_jstutee";
    // private $username = "bwzavjig_jstutee";
    // private $password = "iQHo@R@rncq&W(HE";
    // private $conn;
    
    public function getConnection() {
        $this->conn = null;
        
        try {
            $this->conn = new PDO(
                "mysql:host=" . $this->host . ";dbname=" . $this->db_name,
                $this->username,
                $this->password
            );
            $this->conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $this->conn->exec("set names utf8");
        } catch(PDOException $e) {
            echo "Connection error: " . $e->getMessage();
        }
        
        return $this->conn;
    }
}

// Student Session Management Class
class StudentSession {
    private $conn;
    
    public function __construct($db) {
        $this->conn = $db;
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        $this->initializeSession();
    }
    
    private function initializeSession() {
        // Initialize session arrays if they don't exist
        if (!isset($_SESSION['student_id'])) {
            $_SESSION['student_id'] = null;
            $_SESSION['student_username'] = null;
            $_SESSION['student_name'] = null;
            $_SESSION['student_email'] = null;
            $_SESSION['student_avatar'] = null;
            $_SESSION['student_score'] = 0;
        }
    }
    
    public function login($username, $password) {
    try {
        $query = "SELECT id, username, email, full_name, avatar_color, total_score 
                  FROM students 
                  WHERE username = :username 
                  AND password = SHA2(:password, 256) 
                  AND deleted_at IS NULL";
        
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':username', $username);
        $stmt->bindParam(':password', $password);
        $stmt->execute();
        
        if ($stmt->rowCount() > 0) {
            $student = $stmt->fetch(PDO::FETCH_ASSOC);
            
            // IMPORTANT: Get fresh score from database
            $score_query = "SELECT total_score FROM students WHERE id = ?";
            $score_stmt = $this->conn->prepare($score_query);
            $score_stmt->execute([$student['id']]);
            $score_result = $score_stmt->fetch(PDO::FETCH_ASSOC);
            $actual_score = $score_result['total_score'] ?? 0;
            
            // Set session variables with actual database score
            $_SESSION['student_id'] = $student['id'];
            $_SESSION['student_username'] = $student['username'];
            $_SESSION['student_name'] = $student['full_name'] ?? $student['username'];
            $_SESSION['student_email'] = $student['email'];
            $_SESSION['student_avatar'] = $student['avatar_color'] ?? '#007bff';
            $_SESSION['student_score'] = $actual_score; // Use actual database value
            
            // Update last active timestamp
            $this->updateLastActive($student['id']);
            
            return ['success' => true, 'student' => $student];
        } else {
            return ['success' => false, 'message' => 'Invalid credentials'];
        }
    } catch(PDOException $e) {
        return ['success' => false, 'message' => 'Database error: ' . $e->getMessage()];
    }
}
    
    public function register($full_name, $username, $email, $password) {
        try {
            // Check if username or email already exists
            $checkQuery = "SELECT id FROM students WHERE (username = :username OR email = :email) AND deleted_at IS NULL";
            $checkStmt = $this->conn->prepare($checkQuery);
            $checkStmt->bindParam(':username', $username);
            $checkStmt->bindParam(':email', $email);
            $checkStmt->execute();
            
            if ($checkStmt->rowCount() > 0) {
                return ['success' => false, 'message' => 'Username or email already exists'];
            }
            
            // Validate password length
            if (strlen($password) < 6) {
                return ['success' => false, 'message' => 'Password must be at least 6 characters'];
            }
            
            // Generate random avatar color
            $avatar_color = '#' . substr(md5($email), 0, 6);
            
            $query = "INSERT INTO students (full_name, username, email, password, avatar_color) 
                      VALUES (:full_name, :username, :email, SHA2(:password, 256), :avatar_color)";
            
            $stmt = $this->conn->prepare($query);
            $stmt->bindParam(':full_name', $full_name);
            $stmt->bindParam(':username', $username);
            $stmt->bindParam(':email', $email);
            $stmt->bindParam(':password', $password);
            $stmt->bindParam(':avatar_color', $avatar_color);
            
            if ($stmt->execute()) {
                $student_id = $this->conn->lastInsertId();
                
                // Set session variables
                $_SESSION['student_id'] = $student_id;
                $_SESSION['student_username'] = $username;
                $_SESSION['student_name'] = $full_name;
                $_SESSION['student_email'] = $email;
                $_SESSION['student_avatar'] = $avatar_color;
                $_SESSION['student_score'] = 0;
                
                return ['success' => true, 'student_id' => $student_id];
            } else {
                return ['success' => false, 'message' => 'Registration failed'];
            }
        } catch(PDOException $e) {
            return ['success' => false, 'message' => 'Database error: ' . $e->getMessage()];
        }
    }
    
    public function logout() {
        // Update last active before logging out
        if ($this->isLoggedIn()) {
            $this->updateLastActive($_SESSION['student_id']);
        }
        
        // Clear all session variables
        $_SESSION = array();
        
        // Destroy the session
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_destroy();
        }
        
        return ['success' => true];
    }
    
    public function isLoggedIn() {
        return isset($_SESSION['student_id']) && $_SESSION['student_id'] !== null;
    }
    
    public function getStudentId() {
        return $_SESSION['student_id'] ?? null;
    }
    
    public function getStudentData() {
        if ($this->isLoggedIn()) {
            return [
                'id' => $_SESSION['student_id'] ?? null,
                'username' => $_SESSION['student_username'] ?? '',
                'name' => $_SESSION['student_name'] ?? '',
                'email' => $_SESSION['student_email'] ?? '',
                'avatar' => $_SESSION['student_avatar'] ?? '#007bff',
                'score' => $_SESSION['student_score'] ?? 0
            ];
        }
        return null;
    }
    
    public function updateStudentScore($points) {
    if (!$this->isLoggedIn()) return false;
    
    try {
        // First update database
        $query = "UPDATE students SET total_score = total_score + :points WHERE id = :student_id";
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':points', $points, PDO::PARAM_INT);
        $stmt->bindParam(':student_id', $_SESSION['student_id'], PDO::PARAM_INT);
        $success = $stmt->execute();
        
        if ($success) {
            // Then get the fresh value from database
            $score_query = "SELECT total_score FROM students WHERE id = ?";
            $score_stmt = $this->conn->prepare($score_query);
            $score_stmt->execute([$_SESSION['student_id']]);
            $score_result = $score_stmt->fetch(PDO::FETCH_ASSOC);
            
            // Update session with actual database value
            $_SESSION['student_score'] = $score_result['total_score'] ?? 0;
            
            return true;
        }
        return false;
    } catch(PDOException $e) {
        error_log("Error updating student score: " . $e->getMessage());
        return false;
    }
}
    
    private function updateLastActive($student_id) {
        try {
            $query = "UPDATE students SET last_active = NOW() WHERE id = :student_id";
            $stmt = $this->conn->prepare($query);
            $stmt->bindParam(':student_id', $student_id, PDO::PARAM_INT);
            $stmt->execute();
        } catch(PDOException $e) {
            // Silent fail for last active update
        }
    }
    
    public function saveQuizAttempt($quiz_id, $selected_option_id, $is_correct, $time_spent = 0) {
        if (!$this->isLoggedIn()) {
            return ['success' => false, 'message' => 'Please login to save your quiz results'];
        }
        
        try {
            // Calculate points (10 for correct, 0 for incorrect)
            $points = $is_correct ? 10 : 0;
            
            // Check if already attempted this quiz
            $checkQuery = "SELECT id FROM student_quiz_attempts 
                           WHERE student_id = :student_id AND quiz_id = :quiz_id 
                           ORDER BY attempted_at DESC LIMIT 1";
            $checkStmt = $this->conn->prepare($checkQuery);
            $checkStmt->bindParam(':student_id', $_SESSION['student_id'], PDO::PARAM_INT);
            $checkStmt->bindParam(':quiz_id', $quiz_id, PDO::PARAM_INT);
            $checkStmt->execute();
            
            if ($checkStmt->rowCount() > 0) {
                return ['success' => false, 'message' => 'You have already attempted this quiz'];
            }
            
            // Save quiz attempt
            $query = "INSERT INTO student_quiz_attempts 
                      (student_id, quiz_id, selected_option_id, is_correct, time_spent) 
                      VALUES (:student_id, :quiz_id, :selected_option_id, :is_correct, :time_spent)";
            
            $stmt = $this->conn->prepare($query);
            $stmt->bindParam(':student_id', $_SESSION['student_id'], PDO::PARAM_INT);
            $stmt->bindParam(':quiz_id', $quiz_id, PDO::PARAM_INT);
            $stmt->bindParam(':selected_option_id', $selected_option_id, PDO::PARAM_INT);
            $stmt->bindParam(':is_correct', $is_correct, PDO::PARAM_BOOL);
            $stmt->bindParam(':time_spent', $time_spent, PDO::PARAM_INT);
            
            if ($stmt->execute()) {
                // Update student score if correct
                if ($is_correct) {
                    $this->updateStudentScore($points);
                }
                
                return [
                    'success' => true, 
                    'attempt_id' => $this->conn->lastInsertId(),
                    'points' => $points,
                    'new_total_score' => $_SESSION['student_score'] ?? 0
                ];
            } else {
                return ['success' => false, 'message' => 'Failed to save quiz attempt'];
            }
        } catch(PDOException $e) {
            return ['success' => false, 'message' => 'Database error: ' . $e->getMessage()];
        }
    }
    
    public function getQuizStatistics($student_id = null) {
        $student_id = $student_id ?? $this->getStudentId();
        
        if (!$student_id) {
            return null;
        }
        
        try {
            $query = "SELECT 
                        COUNT(*) as total_attempts,
                        SUM(CASE WHEN is_correct = 1 THEN 1 ELSE 0 END) as correct_attempts,
                        SUM(CASE WHEN is_correct = 0 THEN 1 ELSE 0 END) as incorrect_attempts,
                        CASE 
                            WHEN COUNT(*) > 0 THEN 
                                ROUND(SUM(CASE WHEN is_correct = 1 THEN 1 ELSE 0 END) * 100.0 / COUNT(*), 1)
                            ELSE 0
                        END as accuracy_rate,
                        AVG(time_spent) as avg_time_spent
                      FROM student_quiz_attempts 
                      WHERE student_id = :student_id";
            
            $stmt = $this->conn->prepare($query);
            $stmt->bindParam(':student_id', $student_id, PDO::PARAM_INT);
            $stmt->execute();
            
            return $stmt->fetch(PDO::FETCH_ASSOC);
        } catch(PDOException $e) {
            return null;
        }
    }
    
    public function updateProgress($lesson_id) {
        if (!$this->isLoggedIn()) return false;
        
        try {
            $query = "INSERT INTO student_progress (student_id, lesson_id, is_completed, completed_at) 
                      VALUES (:student_id, :lesson_id, 1, NOW())
                      ON DUPLICATE KEY UPDATE 
                      is_completed = 1, 
                      completed_at = NOW(),
                      last_accessed = NOW()";
            
            $stmt = $this->conn->prepare($query);
            $stmt->bindParam(':student_id', $_SESSION['student_id'], PDO::PARAM_INT);
            $stmt->bindParam(':lesson_id', $lesson_id, PDO::PARAM_INT);
            
            return $stmt->execute();
        } catch(PDOException $e) {
            return false;
        }
    }
    
    public function getLessonProgress($lesson_id) {
        if (!$this->isLoggedIn()) return false;
        
        try {
            $query = "SELECT * FROM student_progress 
                      WHERE student_id = :student_id AND lesson_id = :lesson_id";
            
            $stmt = $this->conn->prepare($query);
            $stmt->bindParam(':student_id', $_SESSION['student_id'], PDO::PARAM_INT);
            $stmt->bindParam(':lesson_id', $lesson_id, PDO::PARAM_INT);
            $stmt->execute();
            
            return $stmt->fetch(PDO::FETCH_ASSOC);
        } catch(PDOException $e) {
            return false;
        }
    }
}

// ... existing database code ...

class TeacherSession {
    private $conn;
    
    public function __construct($connection) {
        $this->conn = $connection;
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
    }
    
    public function login($username, $password) {
        try {
            $query = "SELECT * FROM teachers WHERE (username = :username OR email = :username) AND deleted_at IS NULL";
            $stmt = $this->conn->prepare($query);
            $stmt->bindParam(':username', $username);
            $stmt->execute();
            
            $teacher = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($teacher && password_verify($password, $teacher['password'])) {
                // Update last active
                $update_query = "UPDATE teachers SET last_active = NOW() WHERE id = :id";
                $update_stmt = $this->conn->prepare($update_query);
                $update_stmt->bindParam(':id', $teacher['id']);
                $update_stmt->execute();
                
                // Set session variables
                $_SESSION['teacher_id'] = $teacher['id'];
                $_SESSION['teacher_username'] = $teacher['username'];
                $_SESSION['teacher_email'] = $teacher['email'];
                $_SESSION['teacher_avatar_color'] = $teacher['avatar_color'];
                $_SESSION['teacher_logged_in'] = true;
                
                return ['success' => true, 'message' => 'Login successful'];
            }
            
            return ['success' => false, 'message' => 'Invalid username or password'];
            
        } catch (PDOException $e) {
            return ['success' => false, 'message' => 'Database error: ' . $e->getMessage()];
        }
    }
    
    public function register($username, $email, $password) {
        try {
            // Check if username or email already exists
            $check_query = "SELECT id FROM teachers WHERE (username = :username OR email = :email) AND deleted_at IS NULL";
            $check_stmt = $this->conn->prepare($check_query);
            $check_stmt->bindParam(':username', $username);
            $check_stmt->bindParam(':email', $email);
            $check_stmt->execute();
            
            if ($check_stmt->rowCount() > 0) {
                return ['success' => false, 'message' => 'Username or email already exists'];
            }
            
            // Generate random avatar color
            $colors = ['#4361ee', '#3a0ca3', '#7209b7', '#f72585', '#4cc9f0', '#4895ef', '#560bad', '#b5179e'];
            $avatar_color = $colors[array_rand($colors)];
            
            // Hash password and insert
            $hashed_password = password_hash($password, PASSWORD_DEFAULT);
            
            $query = "INSERT INTO teachers (username, email, password, avatar_color) 
                     VALUES (:username, :email, :password, :avatar_color)";
            $stmt = $this->conn->prepare($query);
            $stmt->bindParam(':username', $username);
            $stmt->bindParam(':email', $email);
            $stmt->bindParam(':password', $hashed_password);
            $stmt->bindParam(':avatar_color', $avatar_color);
            $stmt->execute();
            
            // Auto-login after registration
            $teacher_id = $this->conn->lastInsertId();
            $_SESSION['teacher_id'] = $teacher_id;
            $_SESSION['teacher_username'] = $username;
            $_SESSION['teacher_email'] = $email;
            $_SESSION['teacher_avatar_color'] = $avatar_color;
            $_SESSION['teacher_logged_in'] = true;
            
            return ['success' => true, 'message' => 'Registration successful'];
            
        } catch (PDOException $e) {
            return ['success' => false, 'message' => 'Database error: ' . $e->getMessage()];
        }
    }
    
    public function isAllowed($email) {
        // Check if email allowed to register as teacher
            $check_query = "SELECT id FROM allowed_teachers WHERE (email = :email) AND is_allowed = 1";
            $check_stmt = $this->conn->prepare($check_query);
            $check_stmt->bindParam(':email', $email);
            $check_stmt->execute();
            $isCount = $check_stmt->rowCount() > 0 ? true : false;
            return $isCount;
    }
    public function isLoggedIn() {
        return isset($_SESSION['teacher_logged_in']) && $_SESSION['teacher_logged_in'] === true;
    }
    
    public function getTeacherId() {
        return $_SESSION['teacher_id'] ?? null;
    }
    
    public function getTeacherUsername() {
        return $_SESSION['teacher_username'] ?? null;
    }
    
    public function getTeacherEmail() {
        return $_SESSION['teacher_email'] ?? null;
    }
    
    public function getAvatarColor() {
        return $_SESSION['teacher_avatar_color'] ?? '#4361ee';
    }
    
    public function logout() {
        // Clear all session variables
        $_SESSION = array();
        
        // Destroy the session
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_destroy();
        }
    }
    
    public function updateLastActive() {
        if ($this->isLoggedIn()) {
            $query = "UPDATE teachers SET last_active = NOW() WHERE id = :id";
            $stmt = $this->conn->prepare($query);
            $stmt->bindParam(':id', $_SESSION['teacher_id']);
            $stmt->execute();
        }
    }
}
?>