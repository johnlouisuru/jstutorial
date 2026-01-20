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
            
        default:
            $response = ['error' => 'Invalid action'];
    }
    
    echo json_encode($response);
    exit;
}
?>