<?php
class Database {
    private $host = "localhost";
    private $db_name = "js_tutorial";
    private $username = "root";
    private $password = "";
    private $conn;

    // For Live Server
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
                
                // Set session variables
                $_SESSION['student_id'] = $student['id'];
                $_SESSION['student_username'] = $student['username'];
                $_SESSION['student_name'] = $student['full_name'] ?? $student['username'];
                $_SESSION['student_email'] = $student['email'];
                $_SESSION['student_avatar'] = $student['avatar_color'] ?? '#007bff';
                $_SESSION['student_score'] = $student['total_score'];
                
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
            // Update session score
            $_SESSION['student_score'] += $points;
            
            // Update database
            $query = "UPDATE students SET total_score = total_score + :points WHERE id = :student_id";
            $stmt = $this->conn->prepare($query);
            $stmt->bindParam(':points', $points, PDO::PARAM_INT);
            $stmt->bindParam(':student_id', $_SESSION['student_id'], PDO::PARAM_INT);
            
            return $stmt->execute();
        } catch(PDOException $e) {
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
    
    public function getQuizStatistics() {
        try {
            $student_id = $this->getStudentId();
            
            if (!$student_id) {
                return false;
            }
            
            // Get quiz statistics
            $quizStatsQuery = "SELECT 
                COUNT(*) as total_attempts,
                SUM(CASE WHEN is_correct = 1 THEN 1 ELSE 0 END) as correct_attempts,
                SUM(CASE WHEN is_correct = 0 THEN 1 ELSE 0 END) as incorrect_attempts,
                AVG(time_spent) as avg_time_spent
                FROM student_quiz_attempts 
                WHERE student_id = ?";
            $quizStmt = $this->conn->prepare($quizStatsQuery);
            $quizStmt->execute([$student_id]);
            $quizStats = $quizStmt->fetch(PDO::FETCH_ASSOC);
            
            // Calculate accuracy rate
            $accuracy_rate = 0;
            if ($quizStats['total_attempts'] > 0) {
                $accuracy_rate = round(($quizStats['correct_attempts'] / $quizStats['total_attempts']) * 100, 2);
            }
            
            // NEW: Get lessons finished statistics
            $lessonsStatsQuery = "SELECT 
                COUNT(*) as total_finished_lessons,
                (SELECT COUNT(*) FROM lessons WHERE is_active = 1 AND deleted_at IS NULL) as total_active_lessons
                FROM student_progress sp
                JOIN lessons l ON sp.lesson_id = l.id
                WHERE sp.student_id = ? 
                AND sp.is_completed = 1
                AND l.is_active = 1
                AND l.deleted_at IS NULL";
            $lessonsStmt = $this->conn->prepare($lessonsStatsQuery);
            $lessonsStmt->execute([$student_id]);
            $lessonsStats = $lessonsStmt->fetch(PDO::FETCH_ASSOC);
            
            // Calculate lessons completion rate
            $lessons_completion_rate = 0;
            if ($lessonsStats['total_active_lessons'] > 0) {
                $lessons_completion_rate = round(($lessonsStats['total_finished_lessons'] / $lessonsStats['total_active_lessons']) * 100, 2);
            }
            
            // Get topic-wise progress
            $topicsProgressQuery = "SELECT 
                t.id,
                t.topic_name,
                COUNT(l.id) as total_lessons,
                COUNT(sp.lesson_id) as completed_lessons
                FROM topics t
                LEFT JOIN lessons l ON t.id = l.topic_id 
                    AND l.is_active = 1 
                    AND l.deleted_at IS NULL
                LEFT JOIN student_progress sp ON l.id = sp.lesson_id 
                    AND sp.student_id = ? 
                    AND sp.is_completed = 1
                WHERE t.is_active = 1
                AND t.deleted_at IS NULL
                GROUP BY t.id, t.topic_name
                ORDER BY t.topic_order";
            
            $topicsStmt = $this->conn->prepare($topicsProgressQuery);
            $topicsStmt->execute([$student_id]);
            $topicsProgress = $topicsStmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Combine all stats
            $stats = array_merge($quizStats, $lessonsStats, [
                'accuracy_rate' => $accuracy_rate,
                'lessons_completion_rate' => $lessons_completion_rate,
                'topics_progress' => $topicsProgress
            ]);
            
            return $stats;
            
        } catch (Exception $e) {
            error_log("Error getting quiz stats: " . $e->getMessage());
            return false;
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

// Also update getPredictiveAnalytics() to handle empty results better:
public function getPredictiveAnalytics() {
    try {
        $student_id = $this->getStudentId();
        
        if (!$student_id) {
            return [
                'learning_streak' => 0,
                'active_days_this_week' => 0,
                'estimated_completion_minutes' => 0,
                'goal_progress' => 0,
                'weakest_topic' => null,
                'strongest_topic' => null,
                'weekly_activity' => [],
                'remaining_lessons' => 0
            ];
        }
        
        // Get learning streak
        $streakQuery = "SELECT 
            IFNULL(DATEDIFF(CURDATE(), MAX(last_accessed)), 999) as days_since_last_activity,
            (SELECT COUNT(DISTINCT DATE(last_accessed)) 
             FROM student_progress 
             WHERE student_id = ? 
             AND last_accessed >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)) as active_days_this_week
            FROM student_progress 
            WHERE student_id = ?";
        $streakStmt = $this->conn->prepare($streakQuery);
        $streakStmt->execute([$student_id, $student_id]);
        $streakData = $streakStmt->fetch(PDO::FETCH_ASSOC);
        
        // Calculate learning streak (consecutive days)
        $streak = 0;
        if ($streakData && $streakData['days_since_last_activity'] == 0) {
            $streakQuery2 = "WITH RECURSIVE dates AS (
                SELECT CURDATE() as date
                UNION ALL
                SELECT DATE_SUB(date, INTERVAL 1 DAY)
                FROM dates
                WHERE date > DATE_SUB(CURDATE(), INTERVAL 30 DAY)
            )
            SELECT COUNT(*) as streak
            FROM dates d
            WHERE NOT EXISTS (
                SELECT 1 FROM student_progress sp
                WHERE sp.student_id = ?
                AND DATE(sp.last_accessed) = d.date
                LIMIT 1
            )
            ORDER BY d.date DESC
            LIMIT 1";
            
            $streakStmt2 = $this->conn->prepare($streakQuery2);
            $streakStmt2->execute([$student_id]);
            $streakResult = $streakStmt2->fetch(PDO::FETCH_ASSOC);
            $streak = $streakResult ? (30 - $streakResult['streak']) : 1;
        }
        
        // Get estimated completion time
        $completionQuery = "SELECT 
            COUNT(*) as total_lessons,
            SUM(CASE WHEN sp.is_completed = 1 THEN 1 ELSE 0 END) as completed_lessons,
            AVG(sqa.time_spent) as avg_quiz_time
            FROM lessons l
            LEFT JOIN student_progress sp ON l.id = sp.lesson_id AND sp.student_id = ?
            LEFT JOIN student_quiz_attempts sqa ON sqa.student_id = ?
            WHERE l.is_active = 1 AND l.deleted_at IS NULL";
        
        $completionStmt = $this->conn->prepare($completionQuery);
        $completionStmt->execute([$student_id, $student_id]);
        $completionData = $completionStmt->fetch(PDO::FETCH_ASSOC);
        
        // Calculate estimated completion
        $remaining = isset($completionData['total_lessons']) ? 
            ($completionData['total_lessons'] - ($completionData['completed_lessons'] ?? 0)) : 0;
        
        $avgTimePerLesson = isset($completionData['avg_quiz_time']) && $completionData['avg_quiz_time'] > 0 ? 
            $completionData['avg_quiz_time'] * 2 : 300; // Default 5 minutes per lesson
        
        $estimatedMinutes = $remaining > 0 ? round(($remaining * $avgTimePerLesson) / 60) : 0;
        
        // Calculate daily goal progress
        $goalProgress = isset($streakData['active_days_this_week']) ? 
            ($streakData['active_days_this_week'] / 7 * 100) : 0;
        
        // Get topic difficulty analysis
        $difficultyQuery = "SELECT 
            t.topic_name,
            COUNT(DISTINCT sqa.quiz_id) as total_quizzes,
            SUM(CASE WHEN sqa.is_correct = 1 THEN 1 ELSE 0 END) as correct_quizzes,
            AVG(sqa.time_spent) as avg_time
            FROM topics t
            JOIN lessons l ON t.id = l.topic_id
            JOIN quizzes q ON l.id = q.lesson_id
            LEFT JOIN student_quiz_attempts sqa ON q.id = sqa.quiz_id AND sqa.student_id = ?
            WHERE t.is_active = 1
            GROUP BY t.id
            HAVING total_quizzes > 0
            ORDER BY (correct_quizzes/total_quizzes) ASC";
        
        $difficultyStmt = $this->conn->prepare($difficultyQuery);
        $difficultyStmt->execute([$student_id]);
        $difficultyData = $difficultyStmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Identify weakest and strongest topics
        $weakestTopic = null;
        $strongestTopic = null;
        if (!empty($difficultyData)) {
            $weakestTopic = $difficultyData[0];
            $strongestTopic = end($difficultyData);
        }
        
        // Get weekly activity
        $weeklyQuery = "SELECT 
            DAYNAME(sp.last_accessed) as day_name,
            COUNT(DISTINCT sp.lesson_id) as lessons_completed,
            COUNT(DISTINCT sqa.quiz_id) as quizzes_attempted
            FROM student_progress sp
            LEFT JOIN student_quiz_attempts sqa ON DATE(sqa.attempted_at) = DATE(sp.last_accessed) AND sqa.student_id = ?
            WHERE sp.student_id = ?
            AND sp.last_accessed >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)
            GROUP BY DATE(sp.last_accessed)
            ORDER BY sp.last_accessed DESC
            LIMIT 7";
        
        $weeklyStmt = $this->conn->prepare($weeklyQuery);
        $weeklyStmt->execute([$student_id, $student_id]);
        $weeklyActivity = $weeklyStmt->fetchAll(PDO::FETCH_ASSOC);
        
        return [
            'learning_streak' => $streak,
            'active_days_this_week' => $streakData['active_days_this_week'] ?? 0,
            'estimated_completion_minutes' => $estimatedMinutes,
            'goal_progress' => round($goalProgress),
            'weakest_topic' => $weakestTopic,
            'strongest_topic' => $strongestTopic,
            'weekly_activity' => $weeklyActivity,
            'remaining_lessons' => $remaining
        ];
        
    } catch (Exception $e) {
        error_log("Error getting predictive analytics: " . $e->getMessage());
        return [
            'learning_streak' => 0,
            'active_days_this_week' => 0,
            'estimated_completion_minutes' => 0,
            'goal_progress' => 0,
            'weakest_topic' => null,
            'strongest_topic' => null,
            'weekly_activity' => [],
            'remaining_lessons' => 0
        ];
    }
}

// In your StudentSession class, update the getDashboardStats() method:
public function getDashboardStats() {
    $stats = $this->getQuizStatistics();
    $analytics = $this->getPredictiveAnalytics();
    
    // Handle empty arrays properly
    if (!$stats) $stats = [];
    if (!$analytics) $analytics = [];
    
    // Merge arrays, ensuring all keys exist
    $mergedStats = array_merge([
        'accuracy_rate' => 0,
        'correct_attempts' => 0,
        'total_attempts' => 0,
        'incorrect_attempts' => 0,
        'avg_time_spent' => 0,
        'total_finished_lessons' => 0,
        'total_active_lessons' => 0,
        'lessons_completion_rate' => 0,
        'topics_progress' => [],
        'learning_streak' => 0,
        'active_days_this_week' => 0,
        'estimated_completion_minutes' => 0,
        'goal_progress' => 0,
        'weakest_topic' => null,
        'strongest_topic' => null,
        'weekly_activity' => [],
        'remaining_lessons' => 0
    ], $stats, $analytics);
    
    return $mergedStats;
}

public function getLearningGoals() {
    $student_id = $this->getStudentId();
    
    // Get recommended next lessons based on progress
    $recommendationQuery = "SELECT 
        l.id, l.lesson_title, l.content_type, t.topic_name, t.id AS topics_id,
        (SELECT COUNT(*) FROM quizzes q WHERE q.lesson_id = l.id) as quiz_count,
        (SELECT COUNT(*) FROM student_quiz_attempts sqa 
         JOIN quizzes q ON sqa.quiz_id = q.id 
         WHERE q.lesson_id = l.id AND sqa.student_id = ? AND sqa.is_correct = 1) as correct_quizzes
        FROM lessons l
        JOIN topics t ON l.topic_id = t.id
        WHERE l.is_active = 1 
        AND l.deleted_at IS NULL
        AND l.id NOT IN (
            SELECT lesson_id FROM student_progress 
            WHERE student_id = ? AND is_completed = 1
        )
        ORDER BY t.topic_order, l.lesson_order
        LIMIT 5";
    
    $stmt = $this->conn->prepare($recommendationQuery);
    $stmt->execute([$student_id, $student_id]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}
}
?>