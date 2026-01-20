<?php
require_once 'database.php';

// Set content type to JSON
header('Content-Type: application/json');

$db = new Database();
$conn = $db->getConnection();
$teacherSession = new TeacherSession($conn);

if (!$teacherSession->isLoggedIn()) {
    header('Location: teacher_login');
    exit;
}

// Check if topic ID is provided
if (!isset($_GET['id']) || empty($_GET['id'])) {
    echo json_encode([
        'success' => false,
        'message' => 'Topic ID is required'
    ]);
    exit;
}

$topic_id = intval($_GET['id']);

try {
    // Fetch topic data
    $query = "SELECT * FROM topics WHERE id = ? AND deleted_at IS NULL";
    $stmt = $conn->prepare($query);
    $stmt->execute([$topic_id]);
    $topic = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$topic) {
        echo json_encode([
            'success' => false,
            'message' => 'Topic not found or has been deleted'
        ]);
        exit;
    }
    
    // Fetch lesson count for this topic
    $lesson_query = "SELECT COUNT(*) as lesson_count FROM lessons 
                    WHERE topic_id = ? AND deleted_at IS NULL";
    $lesson_stmt = $conn->prepare($lesson_query);
    $lesson_stmt->execute([$topic_id]);
    $lesson_count = $lesson_stmt->fetch(PDO::FETCH_ASSOC)['lesson_count'];
    
    // Add lesson count to topic data
    $topic['lesson_count'] = $lesson_count;
    
    echo json_encode([
        'success' => true,
        'topic' => $topic
    ]);
    
} catch (PDOException $e) {
    echo json_encode([
        'success' => false,
        'message' => 'Database error: ' . $e->getMessage()
    ]);
}
?>