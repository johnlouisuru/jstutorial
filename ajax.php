<?php
require_once 'database.php';

$db = new Database();
$conn = $db->getConnection();
$studentSession = new StudentSession($conn);

header('Content-Type: application/json');

if (isset($_GET['action'])) {
    $action = $_GET['action'];
    $response = [];
    
    switch ($action) {
        case 'get_quiz_stats':
            if ($studentSession->isLoggedIn()) {
                $stats = $studentSession->getQuizStatistics();
                if ($stats) {
                    $response = $stats;
                } else {
                    $response = ['error' => 'No statistics available'];
                }
            } else {
                $response = ['error' => 'Not logged in'];
            }
            break;
            
        case 'mark_lesson_completed':
            if ($studentSession->isLoggedIn() && isset($_POST['lesson_id'])) {
                $success = $studentSession->updateProgress($_POST['lesson_id']);
                $response = ['success' => $success];
            } else {
                $response = ['success' => false, 'message' => 'Login required'];
            }
            break;
            
        case 'get_student_data':
            if ($studentSession->isLoggedIn()) {
                $response = ['success' => true, 'data' => $studentSession->getStudentData()];
            } else {
                $response = ['success' => false, 'message' => 'Not logged in'];
            }
            break;

        // In your ajax.php switch statement, add:
        case 'get_dashboard_stats':
            if ($studentSession->isLoggedIn()) {
                $stats = $studentSession->getDashboardStats();
                $goals = $studentSession->getLearningGoals();
                if ($stats) {
                    $response = ['stats' => $stats, 'goals' => $goals];
                } else {
                    $response = ['error' => 'No dashboard data available'];
                }
            } else {
                $response = ['error' => 'Not logged in'];
            }
            break;

        // Add this to your switch statement in ajax.php:
        case 'get_recent_activity':
            if ($studentSession->isLoggedIn()) {
                $recentQuery = "SELECT sqa.*, q.question, l.lesson_title, t.topic_name
                                FROM student_quiz_attempts sqa
                                JOIN quizzes q ON sqa.quiz_id = q.id
                                JOIN lessons l ON q.lesson_id = l.id
                                JOIN topics t ON l.topic_id = t.id
                                WHERE sqa.student_id = ?
                                AND l.deleted_at IS NULL
                                ORDER BY sqa.attempted_at DESC
                                LIMIT 5";
                $recentStmt = $conn->prepare($recentQuery);
                $recentStmt->execute([$studentSession->getStudentId()]);
                $activities = $recentStmt->fetchAll(PDO::FETCH_ASSOC);
                $response = ['activities' => $activities];
            } else {
                $response = ['error' => 'Not logged in'];
            }
            break;
            
        default:
            $response = ['error' => 'Invalid action'];
    }
    
    echo json_encode($response);
    exit;
}

// Add this to your ajax.php
if (isset($_GET['action']) && $_GET['action'] == 'refresh_score') {
    if (!$studentSession->isLoggedIn()) {
        echo json_encode(['success' => false]);
        exit;
    }
    
    // Get fresh score from database
    $score_query = "SELECT total_score FROM students WHERE id = ?";
    $score_stmt = $conn->prepare($score_query);
    $score_stmt->execute([$studentSession->getStudentId()]);
    $score_result = $score_stmt->fetch(PDO::FETCH_ASSOC);
    
    // Update session
    $_SESSION['student_score'] = $score_result['total_score'] ?? 0;
    
    echo json_encode(['success' => true, 'score' => $_SESSION['student_score']]);
    exit;
}
?>