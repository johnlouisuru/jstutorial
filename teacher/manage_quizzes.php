<?php
require_once 'database.php';

$db = new Database();
$conn = $db->getConnection();
$teacherSession = new TeacherSession($conn);

if (!$teacherSession->isLoggedIn()) {
    header('Location: teacher_login');
    exit;
}

// Handle AJAX actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $response = [];
    
    if (isset($_POST['action'])) {
        switch ($_POST['action']) {
            case 'get_quiz_details':
                $response = getQuizDetails($conn, $_POST['quiz_id']);
                break;
            case 'update_quiz':
                $response = updateQuiz($conn, $_POST);
                break;
            case 'delete_quiz':
                $response = deleteQuiz($conn, $_POST['quiz_id']);
                break;
            case 'bulk_delete':
                $response = bulkDeleteQuizzes($conn, $_POST['quiz_ids']);
                break;
            case 'get_quiz_stats':
                $response = getQuizStats($conn);
                break;
            case 'get_quiz_analytics':
                $response = getQuizAnalytics($conn, $_POST);
                break;
            case 'export_quizzes':
                $response = exportQuizzes($conn, $_POST);
                break;
        }
        
        header('Content-Type: application/json');
        echo json_encode($response);
        exit;
    }
}

// Get filter parameters with proper type casting and default values
$topic_id = isset($_GET['topic_id']) && $_GET['topic_id'] !== '' && $_GET['topic_id'] !== 'all' 
    ? (int)$_GET['topic_id'] 
    : 'all';
$difficulty = isset($_GET['difficulty']) && $_GET['difficulty'] !== '' ? $_GET['difficulty'] : 'all';
$has_explanation = isset($_GET['has_explanation']) && $_GET['has_explanation'] !== '' ? $_GET['has_explanation'] : 'all';
$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$per_page = 20;

// Ensure page is at least 1
if ($page < 1) $page = 1;

// Build query with filters
$query = "SELECT q.*, l.lesson_title, t.topic_name, 
         (SELECT COUNT(*) FROM quiz_options o WHERE o.quiz_id = q.id) as option_count,
         (SELECT COUNT(*) FROM student_quiz_attempts sqa WHERE sqa.quiz_id = q.id) as attempt_count,
         (SELECT COUNT(*) FROM student_quiz_attempts sqa WHERE sqa.quiz_id = q.id AND sqa.is_correct = 1) as correct_count
         FROM quizzes q
         JOIN lessons l ON q.lesson_id = l.id
         JOIN topics t ON l.topic_id = t.id
         WHERE q.deleted_at IS NULL AND l.deleted_at IS NULL AND t.deleted_at IS NULL";

$count_query = "SELECT COUNT(*) as total FROM quizzes q
                JOIN lessons l ON q.lesson_id = l.id
                JOIN topics t ON l.topic_id = t.id
                WHERE q.deleted_at IS NULL AND l.deleted_at IS NULL AND t.deleted_at IS NULL";

$params = [];
$param_types = [];
$conditions = [];

// Apply filters
if ($topic_id !== 'all') {
    $conditions[] = "t.id = ?";
    $params[] = $topic_id;
    $param_types[] = PDO::PARAM_INT;
}

if ($difficulty !== 'all') {
    $conditions[] = "q.difficulty = ?";
    $params[] = $difficulty;
    $param_types[] = PDO::PARAM_STR;
}

if ($has_explanation !== 'all') {
    if ($has_explanation === 'yes') {
        $conditions[] = "(q.explanation IS NOT NULL AND q.explanation != '')";
    } else {
        $conditions[] = "(q.explanation IS NULL OR q.explanation = '')";
    }
    // No parameters to bind for this condition
}

if ($search !== '') {
    $conditions[] = "(q.question LIKE ? OR l.lesson_title LIKE ? OR t.topic_name LIKE ?)";
    $searchTerm = "%$search%";
    $params[] = $searchTerm;
    $params[] = $searchTerm;
    $params[] = $searchTerm;
    $param_types[] = PDO::PARAM_STR;
    $param_types[] = PDO::PARAM_STR;
    $param_types[] = PDO::PARAM_STR;
}

// Add conditions to queries
if (!empty($conditions)) {
    $query .= " AND " . implode(" AND ", $conditions);
    $count_query .= " AND " . implode(" AND ", $conditions);
}

// Get total count for pagination
try {
    $stmt = $conn->prepare($count_query);
    if (!empty($params)) {
        foreach ($params as $index => $value) {
            $stmt->bindValue($index + 1, $value, $param_types[$index] ?? PDO::PARAM_STR);
        }
    }
    $stmt->execute();
    $count_result = $stmt->fetch(PDO::FETCH_ASSOC);
    $total_count = (int)($count_result['total'] ?? 0);
} catch (PDOException $e) {
    error_log("Count query error: " . $e->getMessage());
    $total_count = 0;
}

$total_pages = ceil($total_count / $per_page);

// Ensure page doesn't exceed total pages
if ($page > $total_pages && $total_pages > 0) {
    $page = $total_pages;
}

// Calculate offset
$offset = ($page - 1) * $per_page;

// Add ordering and pagination - use question mark placeholders for all parameters
$query .= " ORDER BY q.created_at DESC LIMIT ? OFFSET ?";

// Add LIMIT and OFFSET parameters
$params[] = $per_page;
$param_types[] = PDO::PARAM_INT;
$params[] = $offset;
$param_types[] = PDO::PARAM_INT;

// Prepare and execute the main query
try {
    $stmt = $conn->prepare($query);
    
    // Bind all parameters
    foreach ($params as $index => $value) {
        $stmt->bindValue($index + 1, $value, $param_types[$index] ?? PDO::PARAM_STR);
    }
    
    $stmt->execute();
    $quizzes = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Main query error: " . $e->getMessage());
    $quizzes = [];
}

// Fetch topics for filter dropdown
try {
    $topics_query = "SELECT id, topic_name FROM topics WHERE is_active = 1 AND deleted_at IS NULL ORDER BY topic_order";
    $topics = $conn->query($topics_query)->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Topics query error: " . $e->getMessage());
    $topics = [];
}

// Get overall quiz statistics
try {
    $stats_query = "SELECT 
                    COUNT(*) as total_quizzes,
                    SUM(CASE WHEN difficulty = 'easy' THEN 1 ELSE 0 END) as easy_count,
                    SUM(CASE WHEN difficulty = 'medium' THEN 1 ELSE 0 END) as medium_count,
                    SUM(CASE WHEN difficulty = 'hard' THEN 1 ELSE 0 END) as hard_count,
                    COUNT(DISTINCT lesson_id) as lessons_with_quizzes,
                    AVG((SELECT COUNT(*) FROM quiz_options o WHERE o.quiz_id = q.id)) as avg_options,
                    (SELECT COUNT(*) FROM student_quiz_attempts) as total_attempts,
                    (SELECT COUNT(*) FROM student_quiz_attempts WHERE is_correct = 1) as correct_attempts
                    FROM quizzes q
                    WHERE q.deleted_at IS NULL";
    $stats = $conn->query($stats_query)->fetch(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Stats query error: " . $e->getMessage());
    $stats = [
        'total_quizzes' => 0,
        'easy_count' => 0,
        'medium_count' => 0,
        'hard_count' => 0,
        'lessons_with_quizzes' => 0,
        'avg_options' => 0,
        'total_attempts' => 0,
        'correct_attempts' => 0
    ];
}

// Calculate accuracy rate
if ($stats && $stats['total_attempts'] > 0) {
    $stats['accuracy_rate'] = round(($stats['correct_attempts'] / $stats['total_attempts']) * 100, 1);
} else {
    $stats['accuracy_rate'] = 0;
}

// Functions (same as before, but let me include the essential ones)
function getQuizDetails($conn, $quiz_id) {
    try {
        $quiz_id = (int)$quiz_id;
        
        // Get quiz details
        $quiz_query = "SELECT q.*, l.lesson_title, t.topic_name, l.topic_id 
                      FROM quizzes q
                      JOIN lessons l ON q.lesson_id = l.id
                      JOIN topics t ON l.topic_id = t.id
                      WHERE q.id = ?";
        $quiz_stmt = $conn->prepare($quiz_query);
        $quiz_stmt->execute([$quiz_id]);
        $quiz = $quiz_stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$quiz) {
            return ['success' => false, 'message' => 'Quiz not found'];
        }
        
        // Get quiz options
        $options_query = "SELECT * FROM quiz_options WHERE quiz_id = ? ORDER BY option_order";
        $options_stmt = $conn->prepare($options_query);
        $options_stmt->execute([$quiz_id]);
        $options = $options_stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Get quiz attempt statistics
        $attempt_query = "SELECT 
                         COUNT(*) as total_attempts,
                         SUM(CASE WHEN is_correct = 1 THEN 1 ELSE 0 END) as correct_attempts,
                         SUM(CASE WHEN is_correct = 0 THEN 1 ELSE 0 END) as incorrect_attempts,
                         AVG(time_spent) as avg_time_spent,
                         MIN(time_spent) as min_time_spent,
                         MAX(time_spent) as max_time_spent
                         FROM student_quiz_attempts 
                         WHERE quiz_id = ?";
        $attempt_stmt = $conn->prepare($attempt_query);
        $attempt_stmt->execute([$quiz_id]);
        $attempt_stats = $attempt_stmt->fetch(PDO::FETCH_ASSOC);
        
        // Calculate accuracy
        if ($attempt_stats['total_attempts'] > 0) {
            $attempt_stats['accuracy_rate'] = round(($attempt_stats['correct_attempts'] / $attempt_stats['total_attempts']) * 100, 1);
        } else {
            $attempt_stats['accuracy_rate'] = 0;
        }
        
        return [
            'success' => true,
            'quiz' => $quiz,
            'options' => $options,
            'attempt_stats' => $attempt_stats
        ];
    } catch (PDOException $e) {
        return ['success' => false, 'message' => 'Database error: ' . $e->getMessage()];
    }
}

function updateQuiz($conn, $data) {
    try {
        $conn->beginTransaction();
        
        // Update quiz
        $quiz_query = "UPDATE quizzes SET 
                      question = :question,
                      explanation = :explanation,
                      difficulty = :difficulty,
                      is_active = :is_active
                      WHERE id = :quiz_id";
        
        $quiz_stmt = $conn->prepare($quiz_query);
        $quiz_stmt->bindParam(':quiz_id', $data['quiz_id']);
        $quiz_stmt->bindParam(':question', $data['question']);
        $quiz_stmt->bindParam(':explanation', $data['explanation']);
        $quiz_stmt->bindParam(':difficulty', $data['difficulty']);
        $quiz_stmt->bindParam(':is_active', $data['is_active']);
        $quiz_stmt->execute();
        
        // Delete existing options
        $delete_query = "DELETE FROM quiz_options WHERE quiz_id = :quiz_id";
        $delete_stmt = $conn->prepare($delete_query);
        $delete_stmt->bindParam(':quiz_id', $data['quiz_id']);
        $delete_stmt->execute();
        
        // Insert new options
        $options = json_decode($data['options'], true);
        foreach ($options as $index => $option) {
            $option_query = "INSERT INTO quiz_options (quiz_id, option_text, is_correct, option_order) 
                            VALUES (:quiz_id, :option_text, :is_correct, :option_order)";
            
            $option_stmt = $conn->prepare($option_query);
            $option_stmt->bindParam(':quiz_id', $data['quiz_id']);
            $option_stmt->bindParam(':option_text', $option['text']);
            $option_stmt->bindParam(':is_correct', $option['is_correct']);
            $option_stmt->bindParam(':option_order', $index);
            $option_stmt->execute();
        }
        
        $conn->commit();
        
        return ['success' => true, 'message' => 'Quiz updated successfully'];
    } catch (PDOException $e) {
        $conn->rollBack();
        return ['success' => false, 'message' => 'Database error: ' . $e->getMessage()];
    }
}

function deleteQuiz($conn, $quiz_id) {
    try {
        // Soft delete
        $query = "UPDATE quizzes SET deleted_at = NOW() WHERE id = ?";
        $stmt = $conn->prepare($query);
        $stmt->execute([$quiz_id]);
        
        return ['success' => true, 'message' => 'Quiz deleted successfully'];
    } catch (PDOException $e) {
        return ['success' => false, 'message' => 'Database error: ' . $e->getMessage()];
    }
}

function bulkDeleteQuizzes($conn, $quiz_ids) {
    try {
        // Convert JSON string to array and validate
        $ids = json_decode($quiz_ids, true);
        if (!is_array($ids) || empty($ids)) {
            return ['success' => false, 'message' => 'Invalid quiz IDs'];
        }
        
        // Prepare placeholders
        $placeholders = str_repeat('?,', count($ids) - 1) . '?';
        $query = "UPDATE quizzes SET deleted_at = NOW() WHERE id IN ($placeholders)";
        
        $stmt = $conn->prepare($query);
        $stmt->execute($ids);
        
        return ['success' => true, 'message' => count($ids) . ' quizzes deleted successfully'];
    } catch (PDOException $e) {
        return ['success' => false, 'message' => 'Database error: ' . $e->getMessage()];
    }
}

function getQuizStats($conn) {
    try {
        // Daily quiz attempts
        $daily_query = "SELECT 
                       DATE(attempted_at) as date,
                       COUNT(*) as attempts,
                       SUM(CASE WHEN is_correct = 1 THEN 1 ELSE 0 END) as correct,
                       AVG(time_spent) as avg_time
                       FROM student_quiz_attempts 
                       WHERE attempted_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
                       GROUP BY DATE(attempted_at)
                       ORDER BY date";
        
        $daily = $conn->query($daily_query)->fetchAll(PDO::FETCH_ASSOC);
        
        // Most difficult quizzes
        $difficult_query = "SELECT 
                           q.id, q.question, l.lesson_title,
                           COUNT(sqa.id) as attempts,
                           SUM(CASE WHEN sqa.is_correct = 1 THEN 1 ELSE 0 END) as correct,
                           ROUND(SUM(CASE WHEN sqa.is_correct = 1 THEN 1 ELSE 0 END) * 100.0 / COUNT(sqa.id), 1) as accuracy
                           FROM quizzes q
                           JOIN student_quiz_attempts sqa ON q.id = sqa.quiz_id
                           JOIN lessons l ON q.lesson_id = l.id
                           GROUP BY q.id
                           HAVING attempts >= 5
                           ORDER BY accuracy ASC
                           LIMIT 10";
        
        $difficult = $conn->query($difficult_query)->fetchAll(PDO::FETCH_ASSOC);
        
        // Topic-wise performance
        $topic_query = "SELECT 
                       t.topic_name,
                       COUNT(sqa.id) as attempts,
                       SUM(CASE WHEN sqa.is_correct = 1 THEN 1 ELSE 0 END) as correct,
                       ROUND(SUM(CASE WHEN sqa.is_correct = 1 THEN 1 ELSE 0 END) * 100.0 / COUNT(sqa.id), 1) as accuracy,
                       AVG(sqa.time_spent) as avg_time
                       FROM student_quiz_attempts sqa
                       JOIN quizzes q ON sqa.quiz_id = q.id
                       JOIN lessons l ON q.lesson_id = l.id
                       JOIN topics t ON l.topic_id = t.id
                       GROUP BY t.id
                       ORDER BY attempts DESC";
        
        $topics = $conn->query($topic_query)->fetchAll(PDO::FETCH_ASSOC);
        
        return [
            'success' => true,
            'daily_stats' => $daily,
            'difficult_quizzes' => $difficult,
            'topic_performance' => $topics
        ];
    } catch (PDOException $e) {
        return ['success' => false, 'message' => 'Database error: ' . $e->getMessage()];
    }
}

function getQuizAnalytics($conn, $data) {
    try {
        $start_date = $data['start_date'] ?? date('Y-m-d', strtotime('-30 days'));
        $end_date = $data['end_date'] ?? date('Y-m-d');
        $topic_id = $data['topic_id'] ?? 'all';
        
        $query = "SELECT 
                 q.difficulty,
                 COUNT(sqa.id) as attempts,
                 SUM(CASE WHEN sqa.is_correct = 1 THEN 1 ELSE 0 END) as correct,
                 ROUND(SUM(CASE WHEN sqa.is_correct = 1 THEN 1 ELSE 0 END) * 100.0 / COUNT(sqa.id), 1) as accuracy,
                 AVG(sqa.time_spent) as avg_time,
                 MIN(sqa.time_spent) as min_time,
                 MAX(sqa.time_spent) as max_time
                 FROM student_quiz_attempts sqa
                 JOIN quizzes q ON sqa.quiz_id = q.id
                 JOIN lessons l ON q.lesson_id = l.id
                 WHERE sqa.attempted_at BETWEEN ? AND ?";
        
        $params = [$start_date . ' 00:00:00', $end_date . ' 23:59:59'];
        
        if ($topic_id !== 'all') {
            $query .= " AND l.topic_id = ?";
            $params[] = $topic_id;
        }
        
        $query .= " GROUP BY q.difficulty";
        
        $stmt = $conn->prepare($query);
        $stmt->execute($params);
        $analytics = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        return ['success' => true, 'analytics' => $analytics];
    } catch (PDOException $e) {
        return ['success' => false, 'message' => 'Database error: ' . $e->getMessage()];
    }
}

function exportQuizzes($conn, $data) {
    try {
        $format = $data['format'] ?? 'json';
        $topic_id = $data['topic_id'] ?? 'all';
        $difficulty = $data['difficulty'] ?? 'all';
        
        $query = "SELECT q.*, l.lesson_title, t.topic_name,
                 (SELECT GROUP_CONCAT(CONCAT(o.option_text, '::', o.is_correct) SEPARATOR '||') 
                  FROM quiz_options o WHERE o.quiz_id = q.id ORDER BY o.option_order) as options
                 FROM quizzes q
                 JOIN lessons l ON q.lesson_id = l.id
                 JOIN topics t ON l.topic_id = t.id
                 WHERE q.deleted_at IS NULL";
        
        $params = [];
        
        if ($topic_id !== 'all') {
            $query .= " AND t.id = ?";
            $params[] = $topic_id;
        }
        
        if ($difficulty !== 'all') {
            $query .= " AND q.difficulty = ?";
            $params[] = $difficulty;
        }
        
        $query .= " ORDER BY t.topic_order, l.lesson_order";
        
        $stmt = $conn->prepare($query);
        $stmt->execute($params);
        $quizzes = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Format data
        $export_data = [];
        foreach ($quizzes as $quiz) {
            $options = [];
            if ($quiz['options']) {
                $option_parts = explode('||', $quiz['options']);
                foreach ($option_parts as $part) {
                    list($text, $is_correct) = explode('::', $part);
                    $options[] = [
                        'text' => $text,
                        'is_correct' => $is_correct
                    ];
                }
            }
            
            $export_data[] = [
                'question' => $quiz['question'],
                'explanation' => $quiz['explanation'],
                'difficulty' => $quiz['difficulty'],
                'topic' => $quiz['topic_name'],
                'lesson' => $quiz['lesson_title'],
                'options' => $options,
                'created' => $quiz['created_at']
            ];
        }
        
        return [
            'success' => true,
            'data' => $export_data,
            'count' => count($export_data)
        ];
    } catch (PDOException $e) {
        return ['success' => false, 'message' => 'Database error: ' . $e->getMessage()];
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Quizzes - JS Tutorial Admin</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.6/css/dataTables.bootstrap5.min.css">
    <link rel="stylesheet" href="https://cdn.datatables.net/buttons/2.4.1/css/buttons.bootstrap5.min.css">
    <style>
        /* Keep all the CSS styles from the previous version */
        .admin-container {
            background: #f8f9fa;
            min-height: 100vh;
            padding: 20px;
        }
        
        .stats-card {
            background: white;
            border-radius: 10px;
            padding: 20px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
            margin-bottom: 20px;
            transition: transform 0.2s;
        }
        
        .stats-card:hover {
            transform: translateY(-2px);
        }
        
        .stats-icon {
            width: 60px;
            height: 60px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 24px;
        }
        
        .quiz-card {
            background: white;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
            margin-bottom: 20px;
            transition: all 0.3s;
            border-left: 4px solid transparent;
        }
        
        .quiz-card:hover {
            box-shadow: 0 5px 20px rgba(0,0,0,0.1);
            transform: translateY(-2px);
        }
        
        .quiz-card.easy { border-left-color: #2ecc71; }
        .quiz-card.medium { border-left-color: #f39c12; }
        .quiz-card.hard { border-left-color: #e74c3c; }
        
        .quiz-header {
            padding: 15px 20px;
            border-bottom: 1px solid #eee;
            cursor: pointer;
        }
        
        .quiz-content {
            padding: 20px;
            display: none;
        }
        
        .quiz-option {
            padding: 10px 15px;
            margin-bottom: 8px;
            border-radius: 6px;
            background: #f8f9fa;
            border: 1px solid #e9ecef;
            transition: all 0.2s;
        }
        
        .quiz-option.correct {
            background: #d4edda;
            border-color: #c3e6cb;
            color: #155724;
        }
        
        .quiz-option:hover {
            background: #e9ecef;
        }
        
        .difficulty-badge {
            font-size: 0.75rem;
            padding: 3px 8px;
            border-radius: 4px;
            font-weight: 600;
        }
        
        .difficulty-easy {
            background: #d4edda;
            color: #155724;
        }
        
        .difficulty-medium {
            background: #fff3cd;
            color: #856404;
        }
        
        .difficulty-hard {
            background: #f8d7da;
            color: #721c24;
        }
        
        .accuracy-bar {
            height: 6px;
            background: #e9ecef;
            border-radius: 3px;
            overflow: hidden;
            margin-top: 5px;
        }
        
        .accuracy-fill {
            height: 100%;
            background: linear-gradient(90deg, #2ecc71, #27ae60);
            transition: width 0.5s ease;
        }
        
        .filter-bar {
            background: white;
            padding: 20px;
            border-radius: 10px;
            margin-bottom: 20px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
        }
        
        .bulk-actions-bar {
            background: white;
            padding: 15px 20px;
            border-radius: 10px;
            margin-bottom: 20px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
            display: none;
            align-items: center;
            gap: 15px;
        }
        
        .action-buttons {
            display: flex;
            gap: 5px;
            opacity: 0;
            transition: opacity 0.2s;
        }
        
        .quiz-card:hover .action-buttons {
            opacity: 1;
        }
        
        .pagination-container {
            background: white;
            padding: 15px 20px;
            border-radius: 10px;
            margin-top: 20px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
        }
        
        .chart-container {
            background: white;
            border-radius: 10px;
            padding: 20px;
            margin-bottom: 20px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
        }
        
        .analytics-card {
            background: white;
            border-radius: 10px;
            padding: 15px;
            margin-bottom: 15px;
            box-shadow: 0 2px 5px rgba(0,0,0,0.05);
        }
        
        .empty-state {
            text-align: center;
            padding: 40px 20px;
            color: #6c757d;
        }
        
        .empty-state i {
            font-size: 48px;
            margin-bottom: 15px;
            opacity: 0.5;
        }
        
        .search-box {
            position: relative;
        }
        
        .search-box i {
            position: absolute;
            left: 12px;
            top: 50%;
            transform: translateY(-50%);
            color: #6c757d;
        }
        
        .search-box input {
            padding-left: 40px;
        }
        
        .quick-actions {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
            margin-bottom: 20px;
        }
        
        .quick-action-btn {
            background: white;
            border: 2px solid #e9ecef;
            border-radius: 10px;
            padding: 20px;
            text-align: center;
            text-decoration: none;
            color: #495057;
            transition: all 0.2s;
            display: flex;
            flex-direction: column;
            align-items: center;
            cursor: pointer;
        }
        
        .quick-action-btn:hover {
            border-color: #4361ee;
            color: #4361ee;
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(67, 97, 238, 0.1);
        }
        
        .quick-action-btn i {
            font-size: 32px;
            margin-bottom: 10px;
        }
    </style>
</head>
<body>
    <div class="admin-container">
        <!-- Header -->
        <div class="d-flex justify-content-between align-items-center mb-4">
            <div>
                <h2 class="mb-1">
                    <i class="fas fa-question-circle me-2 text-primary"></i>Manage Quizzes
                </h2>
                <p class="text-muted mb-0">View, edit, and analyze all quiz questions</p>
            </div>
            <div class="d-flex gap-2">
            <button class="btn btn-info" onclick="window.location.href='teacher_dashboard'">
                <i class="fas fa-arrow-left me-1"></i> Dashboard
            </button>
                <button class="btn btn-primary" onclick="showAddQuizModal()">
                    <i class="fas fa-plus me-1"></i> Add Quiz
                </button>
                <button class="btn btn-outline-info" onclick="showAnalyticsModal()">
                    <i class="fas fa-chart-line me-1"></i> View Analytics
                </button>
            </div>
        </div>
        
        <!-- Quick Actions -->
        <div class="quick-actions">
            <a href="add-lesson" class="quick-action-btn">
                <i class="fas fa-plus-circle text-primary"></i>
                <span class="fw-bold mt-2">Create New Quiz</span>
                <small class="text-muted mt-1">Add to existing lesson</small>
            </a>
            <button class="quick-action-btn" onclick="showAnalyticsModal()">
                <i class="fas fa-chart-line text-success"></i>
                <span class="fw-bold mt-2">Performance Analytics</span>
                <small class="text-muted mt-1">View detailed insights</small>
            </button>
            <button class="quick-action-btn" onclick="showExportModal()">
                <i class="fas fa-file-export text-warning"></i>
                <span class="fw-bold mt-2">Export Quizzes</span>
                <small class="text-muted mt-1">Download quiz data</small>
            </button>
        </div>
        
        <!-- Statistics Cards -->
        <div class="row mb-4">
            <div class="col-md-3">
                <div class="stats-card">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <h3 class="mb-0"><?php echo $stats['total_quizzes'] ?? 0; ?></h3>
                            <small class="text-muted">Total Quizzes</small>
                        </div>
                        <div class="stats-icon bg-primary bg-opacity-10 text-primary">
                            <i class="fas fa-question-circle"></i>
                        </div>
                    </div>
                    <div class="mt-3">
                        <div class="d-flex justify-content-between small">
                            <span class="text-success">
                                <i class="fas fa-smile me-1"></i>
                                <?php echo $stats['easy_count'] ?? 0; ?> Easy
                            </span>
                            <span class="text-warning">
                                <i class="fas fa-meh me-1"></i>
                                <?php echo $stats['medium_count'] ?? 0; ?> Medium
                            </span>
                            <span class="text-danger">
                                <i class="fas fa-frown me-1"></i>
                                <?php echo $stats['hard_count'] ?? 0; ?> Hard
                            </span>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="stats-card">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <h3 class="mb-0"><?php echo $stats['accuracy_rate'] ?? 0; ?>%</h3>
                            <small class="text-muted">Overall Accuracy</small>
                        </div>
                        <div class="stats-icon bg-success bg-opacity-10 text-success">
                            <i class="fas fa-bullseye"></i>
                        </div>
                    </div>
                    <div class="mt-3">
                        <small class="text-muted">
                            <i class="fas fa-check-circle me-1"></i>
                            <?php echo $stats['correct_attempts'] ?? 0; ?> correct of <?php echo $stats['total_attempts'] ?? 0; ?> attempts
                        </small>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="stats-card">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <h3 class="mb-0"><?php echo $stats['lessons_with_quizzes'] ?? 0; ?></h3>
                            <small class="text-muted">Lessons with Quizzes</small>
                        </div>
                        <div class="stats-icon bg-warning bg-opacity-10 text-warning">
                            <i class="fas fa-book"></i>
                        </div>
                    </div>
                    <div class="mt-3">
                        <small class="text-muted">
                            <i class="fas fa-percentage me-1"></i>
                            <?php 
                            $lesson_query = "SELECT COUNT(*) as total_lessons FROM lessons WHERE deleted_at IS NULL";
                            $lesson_result = $conn->query($lesson_query);
                            $lesson_count = $lesson_result ? $lesson_result->fetch(PDO::FETCH_ASSOC)['total_lessons'] : 0;
                            $coverage = $lesson_count > 0 ? round(($stats['lessons_with_quizzes'] / $lesson_count) * 100) : 0;
                            echo $coverage; ?>% coverage
                        </small>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="stats-card">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <h3 class="mb-0"><?php echo isset($stats['avg_options']) ? round($stats['avg_options'], 1) : 0; ?></h3>
                            <small class="text-muted">Avg. Options/Quiz</small>
                        </div>
                        <div class="stats-icon bg-info bg-opacity-10 text-info">
                            <i class="fas fa-list-ol"></i>
                        </div>
                    </div>
                    <div class="mt-3">
                        <small class="text-muted">
                            <i class="fas fa-ruler me-1"></i>
                            Average number of answer choices
                        </small>
                    </div>
                </div>
            </div>
        </div>
        
       <!-- Filter Bar -->
<div class="filter-bar">
    <form id="filterForm" method="GET" class="row g-3 align-items-end">
        <input type="hidden" name="page" value="1"> <!-- Reset to page 1 on filter -->
        
        <div class="col-md-3">
            <label class="form-label">Topic</label>
            <select class="form-select" name="topic_id" id="topicFilter">
                <option value="all">All Topics</option>
                <?php foreach($topics as $topic): ?>
                <option value="<?php echo $topic['id']; ?>" 
                    <?php echo $topic_id == $topic['id'] ? 'selected' : ''; ?>>
                    <?php echo htmlspecialchars($topic['topic_name']); ?>
                </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-2">
            <label class="form-label">Difficulty</label>
            <select class="form-select" name="difficulty" id="difficultyFilter">
                <option value="all" <?php echo $difficulty == 'all' ? 'selected' : ''; ?>>All Levels</option>
                <option value="easy" <?php echo $difficulty == 'easy' ? 'selected' : ''; ?>>Easy</option>
                <option value="medium" <?php echo $difficulty == 'medium' ? 'selected' : ''; ?>>Medium</option>
                <option value="hard" <?php echo $difficulty == 'hard' ? 'selected' : ''; ?>>Hard</option>
            </select>
        </div>
        <div class="col-md-2">
            <label class="form-label">Explanation</label>
            <select class="form-select" name="has_explanation" id="explanationFilter">
                <option value="all" <?php echo $has_explanation == 'all' ? 'selected' : ''; ?>>All</option>
                <option value="yes" <?php echo $has_explanation == 'yes' ? 'selected' : ''; ?>>With Explanation</option>
                <option value="no" <?php echo $has_explanation == 'no' ? 'selected' : ''; ?>>Without Explanation</option>
            </select>
        </div>
        <div class="col-md-3">
            <label class="form-label">Search</label>
            <div class="search-box">
                <i class="fas fa-search"></i>
                <input type="text" class="form-control" name="search" id="searchFilter" 
                       placeholder="Search questions..." 
                       value="<?php echo htmlspecialchars($search); ?>">
            </div>
        </div>
        <div class="col-md-2">
            <button type="submit" class="btn btn-primary w-100">
                <i class="fas fa-filter me-1"></i> Filter
            </button>
            <?php if ($topic_id !== 'all' || $difficulty !== 'all' || $has_explanation !== 'all' || $search !== ''): ?>
            <a href="manage_quizzes" class="btn btn-outline-secondary w-100 mt-2">
                <i class="fas fa-times me-1"></i> Clear
            </a>
            <?php endif; ?>
        </div>
    </form>
</div>
        
        <!-- Bulk Actions Bar -->
        <div class="bulk-actions-bar" id="bulkActionsBar">
            <div class="d-flex align-items-center">
                <div class="form-check me-3">
                    <input class="form-check-input" type="checkbox" id="selectAll" onchange="toggleSelectAll(this.checked)">
                    <label class="form-check-label fw-bold" for="selectAll">
                        <span id="selectedCount">0</span> selected
                    </label>
                </div>
                
                <button type="button" class="btn btn-sm btn-outline-danger" onclick="bulkDelete()">
                    <i class="fas fa-trash me-1"></i> Delete Selected
                </button>
                
                <button type="button" class="btn btn-sm btn-outline-secondary ms-auto" onclick="clearSelection()">
                    <i class="fas fa-times me-1"></i> Clear Selection
                </button>
            </div>
        </div>
        
        <!-- Quizzes List -->
        <div id="quizzesContainer">
            <?php if (count($quizzes) > 0): ?>
                <?php foreach($quizzes as $quiz): 
                    $accuracy = $quiz['attempt_count'] > 0 ? round(($quiz['correct_count'] / $quiz['attempt_count']) * 100) : 0;
                ?>
                <div class="quiz-card <?php echo $quiz['difficulty']; ?>" data-quiz-id="<?php echo $quiz['id']; ?>">
                    <div class="quiz-header" onclick="toggleQuizDetails(<?php echo $quiz['id']; ?>)">
                        <div class="d-flex justify-content-between align-items-start">
                            <div class="flex-grow-1">
                                <div class="d-flex align-items-center mb-2">
                                    <div class="form-check me-3">
                                        <input type="checkbox" class="form-check-input quiz-checkbox" 
                                               value="<?php echo $quiz['id']; ?>" onchange="updateSelection()">
                                    </div>
                                    <span class="difficulty-badge difficulty-<?php echo $quiz['difficulty']; ?> me-2">
                                        <?php echo ucfirst($quiz['difficulty']); ?>
                                    </span>
                                    <small class="text-muted me-3">
                                        <i class="fas fa-book me-1"></i><?php echo htmlspecialchars($quiz['topic_name']); ?>
                                    </small>
                                    <small class="text-muted">
                                        <i class="fas fa-file-alt me-1"></i><?php echo htmlspecialchars($quiz['lesson_title']); ?>
                                    </small>
                                </div>
                                <h6 class="mb-2 fw-bold"><?php echo htmlspecialchars($quiz['question']); ?></h6>
                                <div class="d-flex justify-content-between align-items-center">
                                    <div class="d-flex gap-3">
                                        <small class="text-muted">
                                            <i class="fas fa-list me-1"></i>
                                            <?php echo $quiz['option_count']; ?> options
                                        </small>
                                        <small class="text-muted">
                                            <i class="fas fa-users me-1"></i>
                                            <?php echo $quiz['attempt_count']; ?> attempts
                                        </small>
                                        <small class="text-muted">
                                            <i class="fas fa-check-circle me-1"></i>
                                            <?php echo $accuracy; ?>% accuracy
                                        </small>
                                        <?php if ($quiz['explanation']): ?>
                                        <small class="text-success">
                                            <i class="fas fa-comment me-1"></i>
                                            Has explanation
                                        </small>
                                        <?php endif; ?>
                                    </div>
                                    <div class="action-buttons">
                                        <button class="btn btn-sm btn-outline-primary" 
                                                onclick="editQuiz(<?php echo $quiz['id']; ?>, event)">
                                            <i class="fas fa-edit"></i>
                                        </button>
                                        <button class="btn btn-sm btn-outline-danger" 
                                                onclick="deleteQuiz(<?php echo $quiz['id']; ?>, event)">
                                            <i class="fas fa-trash"></i>
                                        </button>
                                    </div>
                                </div>
                            </div>
                            <div class="ms-3">
                                <i class="fas fa-chevron-down"></i>
                            </div>
                        </div>
                    </div>
                    
                    <div class="quiz-content" id="quizContent<?php echo $quiz['id']; ?>">
                        <!-- Content will be loaded dynamically -->
                    </div>
                </div>
                <?php endforeach; ?>
            <?php else: ?>
                <div class="empty-state">
                    <i class="fas fa-question-circle fa-3x mb-3"></i>
                    <h4>No Quizzes Found</h4>
                    <p class="text-muted mb-3">
                        <?php if ($topic_id !== 'all' || $difficulty !== 'all' || $search): ?>
                            Try changing your filters or search terms
                        <?php else: ?>
                            No quizzes have been created yet
                        <?php endif; ?>
                    </p>
                    <div class="d-flex justify-content-center gap-2">
                        <button class="btn btn-primary" onclick="showAddQuizModal()">
                            <i class="fas fa-plus me-1"></i> Create First Quiz
                        </button>
                        <?php if ($topic_id !== 'all' || $difficulty !== 'all' || $search): ?>
                        <a href="manage_quizzes" class="btn btn-outline-secondary">
                            <i class="fas fa-times me-1"></i> Clear Filters
                        </a>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endif; ?>
        </div>
        
        <!-- Pagination -->
        <?php if ($total_pages > 1): ?>
        <div class="pagination-container">
            <nav>
                <ul class="pagination justify-content-center mb-0">
                    <li class="page-item <?php echo $page == 1 ? 'disabled' : ''; ?>">
                        <a class="page-link" href="?<?php echo buildQueryString(['page' => max(1, $page - 1)]); ?>">
                            <i class="fas fa-chevron-left"></i>
                        </a>
                    </li>
                    
                    <?php 
                    // Show page numbers
                    $start_page = max(1, $page - 2);
                    $end_page = min($total_pages, $page + 2);
                    
                    if ($start_page > 1) {
                        echo '<li class="page-item"><a class="page-link" href="?' . buildQueryString(['page' => 1]) . '">1</a></li>';
                        if ($start_page > 2) {
                            echo '<li class="page-item disabled"><span class="page-link">...</span></li>';
                        }
                    }
                    
                    for ($i = $start_page; $i <= $end_page; $i++): ?>
                        <li class="page-item <?php echo $i == $page ? 'active' : ''; ?>">
                            <a class="page-link" href="?<?php echo buildQueryString(['page' => $i]); ?>">
                                <?php echo $i; ?>
                            </a>
                        </li>
                    <?php endfor; ?>
                    
                    if ($end_page < $total_pages) {
                        if ($end_page < $total_pages - 1) {
                            echo '<li class="page-item disabled"><span class="page-link">...</span></li>';
                        }
                        echo '<li class="page-item"><a class="page-link" href="?' . buildQueryString(['page' => $total_pages]) . '">' . $total_pages . '</a></li>';
                    }
                    ?>
                    
                    <li class="page-item <?php echo $page == $total_pages ? 'disabled' : ''; ?>">
                        <a class="page-link" href="?<?php echo buildQueryString(['page' => min($total_pages, $page + 1)]); ?>">
                            <i class="fas fa-chevron-right"></i>
                        </a>
                    </li>
                </ul>
            </nav>
            <div class="text-center mt-2">
                <small class="text-muted">
                    Showing <?php echo min((($page - 1) * $per_page) + 1, $total_count); ?> - 
                    <?php echo min($page * $per_page, $total_count); ?> of <?php echo $total_count; ?> quizzes
                </small>
            </div>
        </div>
        <?php endif; ?>
    </div>
    
    <!-- Modals -->
    <!-- Edit Quiz Modal -->
    <div class="modal fade" id="editQuizModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">
                        <i class="fas fa-edit me-2"></i>Edit Quiz
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div id="editQuizContent">
                        <!-- Content loaded dynamically -->
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Analytics Modal -->
<div class="modal fade" id="analyticsModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">
                    <i class="fas fa-chart-line me-2"></i>Quiz Analytics
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div id="analyticsContent">
                    <!-- Analytics content will be loaded here -->
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>
    
    <!-- Stats Modal -->
    <div class="modal fade" id="statsModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">
                        <i class="fas fa-chart-bar me-2"></i>Quiz Statistics
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div id="statsContent">
                        <div class="text-center py-4">
                            <div class="spinner-border text-primary" role="status">
                                <span class="visually-hidden">Loading...</span>
                            </div>
                            <p class="mt-2">Loading statistics...</p>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Export Modal -->
    <div class="modal fade" id="exportModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">
                        <i class="fas fa-file-export me-2"></i>Export Quizzes
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">Export Format</label>
                        <select class="form-select" id="exportFormat">
                            <option value="json">JSON Format</option>
                            <option value="csv">CSV Format</option>
                            <option value="html">HTML Report</option>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Filter by Topic</label>
                        <select class="form-select" id="exportTopic">
                            <option value="all">All Topics</option>
                            <?php foreach($topics as $topic): ?>
                            <option value="<?php echo $topic['id']; ?>"><?php echo htmlspecialchars($topic['topic_name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Filter by Difficulty</label>
                        <select class="form-select" id="exportDifficulty">
                            <option value="all">All Difficulties</option>
                            <option value="easy">Easy Only</option>
                            <option value="medium">Medium Only</option>
                            <option value="hard">Hard Only</option>
                        </select>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="button" class="btn btn-primary" onclick="exportQuizzes()">
                        <i class="fas fa-download me-1"></i> Export
                    </button>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Add Quiz Modal -->
    <div class="modal fade" id="addQuizModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">
                        <i class="fas fa-plus me-2"></i>Add New Quiz
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="alert alert-info">
                        <i class="fas fa-info-circle me-2"></i>
                        To add a quiz, please go to the specific lesson or use the "Create New Quiz" button above.
                    </div>
                    <div class="text-center py-4">
                        <a href="add-lesson" class="btn btn-primary">
                            <i class="fas fa-plus-circle me-1"></i> Go to Lesson Creator
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Toast Container -->
    <div class="toast-container position-fixed top-0 end-0 p-3"></div>
    
    <script src="https://code.jquery.com/jquery-3.6.4.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script>
        let selectedQuizzes = new Set();
        
        // Toggle quiz details
        function toggleQuizDetails(quizId) {
            const content = document.getElementById('quizContent' + quizId);
            const chevron = event.currentTarget.querySelector('.fa-chevron-down');
            
            if (content.style.display === 'none' || !content.style.display) {
                // Load quiz details
                loadQuizDetails(quizId, content);
                chevron.classList.remove('fa-chevron-down');
                chevron.classList.add('fa-chevron-up');
            } else {
                content.style.display = 'none';
                chevron.classList.remove('fa-chevron-up');
                chevron.classList.add('fa-chevron-down');
            }
        }
        
        // Load quiz details
        function loadQuizDetails(quizId, contentElement) {
            contentElement.innerHTML = `
                <div class="text-center py-4">
                    <div class="spinner-border text-primary" role="status">
                        <span class="visually-hidden">Loading...</span>
                    </div>
                    <p class="mt-2">Loading quiz details...</p>
                </div>
            `;
            
            fetch('manage_quizzes.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: `action=get_quiz_details&quiz_id=${quizId}`
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    renderQuizDetails(data, contentElement);
                    contentElement.style.display = 'block';
                } else {
                    contentElement.innerHTML = `
                        <div class="alert alert-danger">
                            <i class="fas fa-exclamation-circle me-2"></i>
                            ${data.message}
                        </div>
                    `;
                }
            })
            .catch(error => {
                contentElement.innerHTML = `
                    <div class="alert alert-danger">
                        <i class="fas fa-exclamation-circle me-2"></i>
                        Failed to load quiz details: ${error.message}
                    </div>
                `;
            });
        }
        
        // Render quiz details
        function renderQuizDetails(data, element) {
            const quiz = data.quiz;
            const options = data.options;
            const stats = data.attempt_stats;
            
            let html = `
                <div class="row">
                    <div class="col-md-8">
                        <div class="mb-4">
                            <h6>Question</h6>
                            <p class="mb-0">${quiz.question}</p>
                        </div>
                        
                        <div class="mb-4">
                            <h6>Answer Options</h6>
                            <div id="quizOptions">
            `;
            
            options.forEach((option, index) => {
                const optionClass = option.is_correct == 1 ? 'quiz-option correct' : 'quiz-option';
                const optionLetter = String.fromCharCode(65 + index); // A, B, C, D...
                
                html += `
                    <div class="${optionClass}">
                        <div class="d-flex align-items-center">
                            <div class="me-3 fw-bold">${optionLetter}.</div>
                            <div class="flex-grow-1">${option.option_text}</div>
                            ${option.is_correct == 1 ? '<span class="badge bg-success ms-2">Correct</span>' : ''}
                        </div>
                    </div>
                `;
            });
            
            html += `
                            </div>
                        </div>
                        
                        ${quiz.explanation ? `
                        <div class="mb-4">
                            <h6>Explanation</h6>
                            <div class="alert alert-info">
                                <i class="fas fa-info-circle me-2"></i>
                                ${quiz.explanation}
                            </div>
                        </div>
                        ` : ''}
                    </div>
                    
                    <div class="col-md-4">
                        <div class="analytics-card">
                            <h6>Quiz Information</h6>
                            <div class="mb-2">
                                <small class="text-muted">Topic:</small>
                                <div class="fw-bold">${quiz.topic_name}</div>
                            </div>
                            <div class="mb-2">
                                <small class="text-muted">Lesson:</small>
                                <div class="fw-bold">${quiz.lesson_title}</div>
                            </div>
                            <div class="mb-2">
                                <small class="text-muted">Difficulty:</small>
                                <span class="badge ${getDifficultyClass(quiz.difficulty)}">
                                    ${quiz.difficulty.charAt(0).toUpperCase() + quiz.difficulty.slice(1)}
                                </span>
                            </div>
                            <div class="mb-2">
                                <small class="text-muted">Created:</small>
                                <div>${new Date(quiz.created_at).toLocaleDateString()}</div>
                            </div>
                            <div class="mb-2">
                                <small class="text-muted">Status:</small>
                                <span class="badge ${quiz.is_active == 1 ? 'bg-success' : 'bg-secondary'}">
                                    ${quiz.is_active == 1 ? 'Active' : 'Inactive'}
                                </span>
                            </div>
                        </div>
                        
                        <div class="analytics-card">
                            <h6>Performance Statistics</h6>
                            <div class="mb-2">
                                <small class="text-muted">Total Attempts:</small>
                                <div class="fw-bold">${stats.total_attempts || 0}</div>
                            </div>
                            <div class="mb-2">
                                <small class="text-muted">Correct Answers:</small>
                                <div class="fw-bold">${stats.correct_attempts || 0}</div>
                            </div>
                            <div class="mb-2">
                                <small class="text-muted">Accuracy Rate:</small>
                                <div class="fw-bold">${stats.accuracy_rate || 0}%</div>
                                <div class="accuracy-bar">
                                    <div class="accuracy-fill" style="width: ${stats.accuracy_rate || 0}%"></div>
                                </div>
                            </div>
                            <div class="mb-2">
                                <small class="text-muted">Avg. Time:</small>
                                <div class="fw-bold">${Math.round(stats.avg_time_spent || 0)}s</div>
                            </div>
                            <div class="mb-2">
                                <small class="text-muted">Time Range:</small>
                                <div>${stats.min_time_spent || 0}s - ${stats.max_time_spent || 0}s</div>
                            </div>
                        </div>
                    </div>
                </div>
                
                <div class="mt-4 pt-3 border-top">
                    <div class="d-flex justify-content-between">
                        <div>
                            <button class="btn btn-sm btn-outline-primary" onclick="editQuiz(${quiz.id})">
                                <i class="fas fa-edit me-1"></i> Edit Quiz
                            </button>
                            <button class="btn btn-sm btn-outline-danger" onclick="deleteQuiz(${quiz.id})">
                                <i class="fas fa-trash me-1"></i> Delete Quiz
                            </button>
                        </div>
                        <a href="lesson?lesson_id=${quiz.lesson_id}" target="_blank" class="btn btn-sm btn-outline-info">
                            <i class="fas fa-external-link-alt me-1"></i> View Lesson
                        </a>
                    </div>
                </div>
            `;
            
            element.innerHTML = html;
        }
        
        // Get difficulty CSS class
        function getDifficultyClass(difficulty) {
            switch(difficulty) {
                case 'easy': return 'bg-success';
                case 'medium': return 'bg-warning';
                case 'hard': return 'bg-danger';
                default: return 'bg-secondary';
            }
        }
        
        // Update selection
        function updateSelection() {
            selectedQuizzes.clear();
            
            document.querySelectorAll('.quiz-checkbox:checked').forEach(checkbox => {
                selectedQuizzes.add(checkbox.value);
            });
            
            const count = selectedQuizzes.size;
            document.getElementById('selectedCount').textContent = count;
            
            if (count > 0) {
                document.getElementById('bulkActionsBar').style.display = 'flex';
                document.getElementById('selectAll').checked = count === document.querySelectorAll('.quiz-checkbox').length;
            } else {
                document.getElementById('bulkActionsBar').style.display = 'none';
            }
        }
        
        // Toggle select all
        function toggleSelectAll(checked) {
            document.querySelectorAll('.quiz-checkbox').forEach(checkbox => {
                checkbox.checked = checked;
            });
            updateSelection();
        }
        
        // Clear selection
        function clearSelection() {
            selectedQuizzes.clear();
            document.querySelectorAll('.quiz-checkbox').forEach(checkbox => {
                checkbox.checked = false;
            });
            document.getElementById('selectAll').checked = false;
            updateSelection();
        }
        
        // Bulk delete
        function bulkDelete() {
            if (selectedQuizzes.size === 0) {
                showToast('Please select at least one quiz', 'warning');
                return;
            }
            
            if (!confirm(`Delete ${selectedQuizzes.size} selected quiz(zes)? This action cannot be undone.`)) {
                return;
            }
            
            const quizIds = Array.from(selectedQuizzes);
            
            fetch('manage_quizzes.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: `action=bulk_delete&quiz_ids=${JSON.stringify(quizIds)}`
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    showToast(data.message, 'success');
                    setTimeout(() => {
                        location.reload();
                    }, 1000);
                } else {
                    showToast(data.message, 'danger');
                }
            })
            .catch(error => {
                showToast('Network error: ' + error.message, 'danger');
            });
        }
        
        // Edit quiz
        function editQuiz(quizId, event = null) {
            if (event) {
                event.stopPropagation();
            }
            
            const modal = new bootstrap.Modal(document.getElementById('editQuizModal'));
            const content = document.getElementById('editQuizContent');
            
            content.innerHTML = `
                <div class="text-center py-4">
                    <div class="spinner-border text-primary" role="status">
                        <span class="visually-hidden">Loading...</span>
                    </div>
                    <p class="mt-2">Loading quiz editor...</p>
                </div>
            `;
            
            modal.show();
            
            // Load quiz data
            fetch('manage_quizzes.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: `action=get_quiz_details&quiz_id=${quizId}`
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    renderQuizEditor(data, content);
                } else {
                    content.innerHTML = `
                        <div class="alert alert-danger">
                            <i class="fas fa-exclamation-circle me-2"></i>
                            ${data.message}
                        </div>
                    `;
                }
            })
            .catch(error => {
                content.innerHTML = `
                    <div class="alert alert-danger">
                        <i class="fas fa-exclamation-circle me-2"></i>
                        Failed to load quiz: ${error.message}
                    </div>
                `;
            });
        }
        
        // Render quiz editor
        function renderQuizEditor(data, element) {
            const quiz = data.quiz;
            const options = data.options;
            
            let html = `
                <form id="editQuizForm">
                    <input type="hidden" id="quizId" value="${quiz.id}">
                    
                    <div class="mb-3">
                        <label class="form-label fw-bold">Question</label>
                        <textarea class="form-control" id="quizQuestion" rows="3" required>${quiz.question}</textarea>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label fw-bold">Explanation</label>
                        <textarea class="form-control" id="quizExplanation" rows="2" 
                                  placeholder="Explain why the correct answer is right...">${quiz.explanation || ''}</textarea>
                    </div>
                    
                    <div class="row mb-3">
                        <div class="col-md-6">
                            <label class="form-label fw-bold">Difficulty</label>
                            <select class="form-select" id="quizDifficulty">
                                <option value="easy" ${quiz.difficulty === 'easy' ? 'selected' : ''}>Easy</option>
                                <option value="medium" ${quiz.difficulty === 'medium' ? 'selected' : ''}>Medium</option>
                                <option value="hard" ${quiz.difficulty === 'hard' ? 'selected' : ''}>Hard</option>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-bold">Status</label>
                            <div class="form-check form-switch mt-2">
                                <input class="form-check-input" type="checkbox" id="quizActive" ${quiz.is_active == 1 ? 'checked' : ''}>
                                <label class="form-check-label" for="quizActive">Active</label>
                            </div>
                        </div>
                    </div>
                    
                    <div class="mb-4">
                        <label class="form-label fw-bold">Answer Options</label>
                        <small class="text-muted d-block mb-2">Select the correct answer</small>
                        
                        <div id="quizOptionsEditor">
            `;
            
            options.forEach((option, index) => {
                html += `
                    <div class="quiz-option-item mb-2 p-3 border rounded">
                        <div class="d-flex align-items-center">
                            <div class="form-check me-3">
                                <input class="form-check-input" type="radio" name="correctOption" 
                                       value="${index}" ${option.is_correct == 1 ? 'checked' : ''}>
                            </div>
                            <input type="text" class="form-control option-text" 
                                   value="${option.option_text}" placeholder="Enter option text...">
                            <button type="button" class="btn btn-sm btn-outline-danger ms-2" 
                                    onclick="removeOption(this)" ${options.length <= 2 ? 'disabled' : ''}>
                                <i class="fas fa-times"></i>
                            </button>
                        </div>
                    </div>
                `;
            });
            
            html += `
                        </div>
                        
                        <button type="button" class="btn btn-outline-primary w-100 mt-2" onclick="addOption()">
                            <i class="fas fa-plus me-1"></i> Add Option
                        </button>
                    </div>
                    
                    <div class="d-flex justify-content-between">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="button" class="btn btn-primary" onclick="saveQuiz()">
                            <i class="fas fa-save me-1"></i> Save Changes
                        </button>
                    </div>
                </form>
            `;
            
            element.innerHTML = html;
        }
        
        // Add option to editor
        function addOption() {
            const container = document.getElementById('quizOptionsEditor');
            const index = container.children.length;
            
            const html = `
                <div class="quiz-option-item mb-2 p-3 border rounded">
                    <div class="d-flex align-items-center">
                        <div class="form-check me-3">
                            <input class="form-check-input" type="radio" name="correctOption" value="${index}">
                        </div>
                        <input type="text" class="form-control option-text" placeholder="Enter option text...">
                        <button type="button" class="btn btn-sm btn-outline-danger ms-2" onclick="removeOption(this)">
                            <i class="fas fa-times"></i>
                        </button>
                    </div>
                </div>
            `;
            
            container.insertAdjacentHTML('beforeend', html);
        }
        
        // Remove option from editor
        function removeOption(button) {
            const container = document.getElementById('quizOptionsEditor');
            if (container.children.length > 2) {
                button.closest('.quiz-option-item').remove();
                
                // Re-index radio buttons
                document.querySelectorAll('input[name="correctOption"]').forEach((radio, index) => {
                    radio.value = index;
                });
            }
        }
        
        // Save quiz
        function saveQuiz() {
            const quizId = document.getElementById('quizId').value;
            const question = document.getElementById('quizQuestion').value;
            const explanation = document.getElementById('quizExplanation').value;
            const difficulty = document.getElementById('quizDifficulty').value;
            const isActive = document.getElementById('quizActive').checked ? 1 : 0;
            
            // Get options
            const options = [];
            document.querySelectorAll('.quiz-option-item').forEach((item, index) => {
                const textInput = item.querySelector('.option-text');
                const isCorrect = item.querySelector('input[type="radio"]').checked;
                
                if (textInput.value.trim()) {
                    options.push({
                        text: textInput.value.trim(),
                        is_correct: isCorrect ? 1 : 0
                    });
                }
            });
            
            // Validation
            if (!question.trim()) {
                showToast('Please enter a question', 'warning');
                return;
            }
            
            if (options.length < 2) {
                showToast('Please add at least 2 answer options', 'warning');
                return;
            }
            
            const hasCorrect = options.some(option => option.is_correct == 1);
            if (!hasCorrect) {
                showToast('Please select the correct answer', 'warning');
                return;
            }
            
            const formData = new FormData();
            formData.append('action', 'update_quiz');
            formData.append('quiz_id', quizId);
            formData.append('question', question);
            formData.append('explanation', explanation);
            formData.append('difficulty', difficulty);
            formData.append('is_active', isActive);
            formData.append('options', JSON.stringify(options));
            
            fetch('manage_quizzes.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    showToast('Quiz updated successfully', 'success');
                    
                    const modal = bootstrap.Modal.getInstance(document.getElementById('editQuizModal'));
                    modal.hide();
                    
                    setTimeout(() => {
                        location.reload();
                    }, 1000);
                } else {
                    showToast('Error: ' + data.message, 'danger');
                }
            })
            .catch(error => {
                showToast('Network error: ' + error.message, 'danger');
            });
        }
        
        // Delete single quiz
        function deleteQuiz(quizId, event = null) {
            if (event) {
                event.stopPropagation();
            }
            
            if (!confirm('Are you sure you want to delete this quiz? This action cannot be undone.')) {
                return;
            }
            
            fetch('manage_quizzes.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: `action=delete_quiz&quiz_id=${quizId}`
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    showToast('Quiz deleted successfully', 'success');
                    setTimeout(() => {
                        location.reload();
                    }, 1000);
                } else {
                    showToast(data.message, 'danger');
                }
            })
            .catch(error => {
                showToast('Network error: ' + error.message, 'danger');
            });
        }
        
        // Show analytics modal
function showAnalyticsModal() {
    const modal = new bootstrap.Modal(document.getElementById('analyticsModal'));
    const content = document.getElementById('analyticsContent');
    
    content.innerHTML = `
        <div class="text-center py-4">
            <div class="spinner-border text-primary" role="status">
                <span class="visually-hidden">Loading...</span>
            </div>
            <p class="mt-2">Loading analytics...</p>
        </div>
    `;
    
    modal.show();
    
    // Load analytics data - default to last 30 days
    const endDate = new Date();
    const startDate = new Date();
    startDate.setDate(startDate.getDate() - 30);
    
    const formData = new FormData();
    formData.append('action', 'get_quiz_analytics');
    formData.append('start_date', startDate.toISOString().split('T')[0]);
    formData.append('end_date', endDate.toISOString().split('T')[0]);
    formData.append('topic_id', 'all');
    
    fetch('manage_quizzes.php', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            renderAnalytics(data.analytics, content);
        } else {
            content.innerHTML = `
                <div class="alert alert-danger">
                    <i class="fas fa-exclamation-circle me-2"></i>
                    ${data.message}
                </div>
            `;
        }
    })
    .catch(error => {
        content.innerHTML = `
            <div class="alert alert-danger">
                <i class="fas fa-exclamation-circle me-2"></i>
                Failed to load analytics: ${error.message}
            </div>
        `;
    });
}
        
        // Render analytics
function renderAnalytics(analytics, element) {
    // Clear any existing content first
    element.innerHTML = '';
    
    // Check if analytics data exists and is an array
    if (!analytics || !Array.isArray(analytics) || analytics.length === 0) {
        element.innerHTML = `
            <div class="alert alert-info">
                <i class="fas fa-info-circle me-2"></i>
                No analytics data available for the selected period.
            </div>
        `;
        return;
    }
    
    // Create a default structure for each difficulty if not present
    const difficulties = ['easy', 'medium', 'hard'];
    const processedData = difficulties.map(difficulty => {
        const item = analytics.find(a => a.difficulty === difficulty);
        return item || {
            difficulty: difficulty,
            attempts: 0,
            correct: 0,
            accuracy: 0,
            avg_time: 0,
            min_time: 0,
            max_time: 0
        };
    });
    
    // Create chart container first
    let html = `
        <div class="row mb-4">
            <div class="col-md-12">
                <div class="chart-container" style="position: relative; height: 300px;">
                    <h6>Performance by Difficulty Level</h6>
                    <div style="height: 250px;">
                        <canvas id="analyticsChart"></canvas>
                    </div>
                </div>
            </div>
        </div>
        
        <div class="row">
    `;
    
    processedData.forEach((item, index) => {
        const difficulty = item.difficulty || 'unknown';
        const accuracy = parseFloat(item.accuracy) || 0;
        const attempts = parseInt(item.attempts) || 0;
        const correct = parseInt(item.correct) || 0;
        const avgTime = parseFloat(item.avg_time) || 0;
        
        html += `
            <div class="col-md-4 mb-3">
                <div class="analytics-card p-3 border rounded">
                    <div class="d-flex justify-content-between align-items-center mb-2">
                        <span class="badge ${getDifficultyClass(difficulty)} fs-6">
                            ${difficulty.charAt(0).toUpperCase() + difficulty.slice(1)}
                        </span>
                        <span class="fw-bold fs-5">${accuracy.toFixed(1)}%</span>
                    </div>
                    <div class="row mb-2">
                        <div class="col-6">
                            <small class="text-muted">Attempts:</small>
                            <div class="fw-bold">${attempts}</div>
                        </div>
                        <div class="col-6">
                            <small class="text-muted">Correct:</small>
                            <div class="fw-bold">${correct}</div>
                        </div>
                    </div>
                    <div class="mb-2">
                        <small class="text-muted">Avg. Time:</small>
                        <div class="fw-bold">${Math.round(avgTime)}s</div>
                    </div>
                    <div class="accuracy-bar mt-2" style="height: 6px; background: #e9ecef; border-radius: 3px; overflow: hidden;">
                        <div class="accuracy-fill" style="width: ${Math.min(accuracy, 100)}%; height: 100%; 
                             background: ${getDifficultyColor(difficulty)}; transition: width 0.5s ease;"></div>
                    </div>
                </div>
            </div>
        `;
    });
    
    html += `
        </div>
        
        <div class="row mt-4">
            <div class="col-md-12">
                <div class="card">
                    <div class="card-header">
                        <h6 class="mb-0">Analytics Summary</h6>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-sm">
                                <thead>
                                    <tr>
                                        <th>Difficulty</th>
                                        <th>Attempts</th>
                                        <th>Correct</th>
                                        <th>Accuracy</th>
                                        <th>Avg Time</th>
                                        <th>Min Time</th>
                                        <th>Max Time</th>
                                    </tr>
                                </thead>
                                <tbody>
    `;
    
    processedData.forEach(item => {
        html += `
            <tr>
                <td><span class="badge ${getDifficultyClass(item.difficulty)}">${item.difficulty}</span></td>
                <td>${item.attempts}</td>
                <td>${item.correct}</td>
                <td>${parseFloat(item.accuracy).toFixed(1)}%</td>
                <td>${Math.round(item.avg_time)}s</td>
                <td>${Math.round(item.min_time || 0)}s</td>
                <td>${Math.round(item.max_time || 0)}s</td>
            </tr>
        `;
    });
    
    html += `
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    `;
    
    element.innerHTML = html;
    
    // Render chart after HTML is loaded
    setTimeout(() => {
        renderAnalyticsChart(processedData);
    }, 50);
}

// Add this helper function
function getDifficultyColor(difficulty) {
    switch(difficulty) {
        case 'easy': return '#28a745';
        case 'medium': return '#ffc107';
        case 'hard': return '#dc3545';
        default: return '#6c757d';
    }
}

document.addEventListener('DOMContentLoaded', function() {
    // Auto-submit filters when dropdowns change
    const autoSubmitFilters = ['topicFilter', 'difficultyFilter', 'explanationFilter'];
    
    autoSubmitFilters.forEach(filterId => {
        const element = document.getElementById(filterId);
        if (element) {
            element.addEventListener('change', function() {
                document.getElementById('filterForm').submit();
            });
        }
    });
    
    // Debounced search
    let searchTimeout;
    const searchInput = document.getElementById('searchFilter');
    if (searchInput) {
        searchInput.addEventListener('input', function() {
            clearTimeout(searchTimeout);
            searchTimeout = setTimeout(() => {
                document.getElementById('filterForm').submit();
            }, 500);
        });
    }
    
    // Preserve filters in pagination links
    document.querySelectorAll('.pagination a').forEach(link => {
        const url = new URL(link.href);
        const currentParams = new URLSearchParams(window.location.search);
        
        // Preserve filter parameters
        ['topic_id', 'difficulty', 'has_explanation', 'search'].forEach(param => {
            if (currentParams.has(param) && !url.searchParams.has(param)) {
                url.searchParams.set(param, currentParams.get(param));
            }
        });
        
        link.href = url.toString();
    });
});
        
        // Render analytics chart
function renderAnalyticsChart(analytics) {
    const ctx = document.getElementById('analyticsChart');
    if (!ctx) return;
    
    const labels = analytics.map(item => 
        item.difficulty ? item.difficulty.charAt(0).toUpperCase() + item.difficulty.slice(1) : 'Unknown'
    );
    const accuracyData = analytics.map(item => parseFloat(item.accuracy) || 0);
    const attemptsData = analytics.map(item => parseInt(item.attempts) || 0);
    
    new Chart(ctx, {
        type: 'bar',
        data: {
            labels: labels,
            datasets: [
                {
                    label: 'Accuracy (%)',
                    data: accuracyData,
                    backgroundColor: analytics.map(item => getDifficultyColor(item.difficulty)),
                    borderColor: analytics.map(item => getDifficultyColor(item.difficulty)),
                    borderWidth: 1,
                    yAxisID: 'y'
                },
                {
                    label: 'Attempts',
                    data: attemptsData,
                    backgroundColor: 'rgba(52, 152, 219, 0.2)',
                    borderColor: 'rgba(52, 152, 219, 1)',
                    borderWidth: 2,
                    type: 'line',
                    yAxisID: 'y1',
                    tension: 0.4
                }
            ]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            interaction: {
                mode: 'index',
                intersect: false,
            },
            plugins: {
                legend: {
                    position: 'top',
                },
                tooltip: {
                    callbacks: {
                        label: function(context) {
                            let label = context.dataset.label || '';
                            if (label) {
                                label += ': ';
                            }
                            if (context.datasetIndex === 0) {
                                label += context.parsed.y.toFixed(1) + '%';
                            } else {
                                label += context.parsed.y;
                            }
                            return label;
                        }
                    }
                }
            },
            scales: {
                y: {
                    type: 'linear',
                    display: true,
                    position: 'left',
                    title: {
                        display: true,
                        text: 'Accuracy (%)'
                    },
                    min: 0,
                    max: 100,
                    grid: {
                        drawBorder: false
                    }
                },
                y1: {
                    type: 'linear',
                    display: true,
                    position: 'right',
                    title: {
                        display: true,
                        text: 'Attempts'
                    },
                    grid: {
                        drawOnChartArea: false,
                    },
                    min: 0
                }
            }
        }
    });
}


        
        // Show stats modal
        function showStatsModal() {
            const modal = new bootstrap.Modal(document.getElementById('statsModal'));
            modal.show();
            
            fetch('manage_quizzes.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: 'action=get_quiz_stats'
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    renderStats(data);
                } else {
                    document.getElementById('statsContent').innerHTML = `
                        <div class="alert alert-danger">
                            <i class="fas fa-exclamation-circle me-2"></i>
                            ${data.message}
                        </div>
                    `;
                }
            })
            .catch(error => {
                document.getElementById('statsContent').innerHTML = `
                    <div class="alert alert-danger">
                        <i class="fas fa-exclamation-circle me-2"></i>
                        Failed to load statistics: ${error.message}
                    </div>
                `;
            });
        }
        
         // Render statistics
        function renderStats(data) {
            let html = `
                <div class="row">
                    <div class="col-md-6">
                        <div class="chart-container">
                            <h6>Content Type Distribution</h6>
                            <canvas id="typeChart" height="200"></canvas>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="chart-container">
                            <h6>Recent Activity (30 days)</h6>
                            <canvas id="activityChart" height="200"></canvas>
                        </div>
                    </div>
                </div>
                
                <div class="row mt-3">
                    <div class="col-md-12">
                        <div class="chart-container">
                            <h6>Quiz Coverage</h6>
                            <div class="d-flex align-items-center">
                                <div class="me-4">
                                    <div class="display-4 fw-bold">${data.quiz_stats.quiz_coverage}%</div>
                                    <small class="text-muted">of lessons have quizzes</small>
                                </div>
                                <div class="flex-grow-1">
                                    <div class="progress" style="height: 30px;">
                                        <div class="progress-bar bg-success" style="width: ${data.quiz_stats.quiz_coverage}%">
                                            ${data.quiz_stats.lessons_with_quizzes} with quizzes
                                        </div>
                                        <div class="progress-bar bg-light text-dark" style="width: ${100 - data.quiz_stats.quiz_coverage}%">
                                            ${data.quiz_stats.total_lessons - data.quiz_stats.lessons_with_quizzes} without quizzes
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            `;
            
            document.getElementById('statsContent').innerHTML = html;
            
            // Render charts
            renderTypeChart(data.type_stats);
            renderActivityChart(data.weekly_stats);
        }

        // Render type chart
        function renderTypeChart(typeStats) {
            const ctx = document.getElementById('typeChart').getContext('2d');
            const labels = typeStats.map(item => item.content_type);
            const data = typeStats.map(item => item.count);
            const colors = ['#4361ee', '#3a0ca3', '#4cc9f0', '#7209b7', '#f72585'];
            
            new Chart(ctx, {
                type: 'doughnut',
                data: {
                    labels: labels,
                    datasets: [{
                        data: data,
                        backgroundColor: colors,
                        borderWidth: 1
                    }]
                },
                options: {
                    responsive: true,
                    plugins: {
                        legend: {
                            position: 'bottom'
                        }
                    }
                }
            });
        }
        
        // Render activity chart
        function renderActivityChart(weeklyStats) {
            const ctx = document.getElementById('activityChart').getContext('2d');
            const labels = weeklyStats.map(item => item.date);
            const data = weeklyStats.map(item => item.lessons_created);
            
            new Chart(ctx, {
                type: 'line',
                data: {
                    labels: labels,
                    datasets: [{
                        label: 'Lessons Created',
                        data: data,
                        borderColor: '#4361ee',
                        backgroundColor: 'rgba(67, 97, 238, 0.1)',
                        borderWidth: 2,
                        fill: true,
                        tension: 0.4
                    }]
                },
                options: {
                    responsive: true,
                    scales: {
                        y: {
                            beginAtZero: true,
                            ticks: {
                                stepSize: 1
                            }
                        }
                    }
                }
            });
        }
        
        // Show export modal
        function showExportModal() {
            const modal = new bootstrap.Modal(document.getElementById('exportModal'));
            modal.show();
        }
        
        // Export quizzes
        function exportQuizzes() {
            const format = document.getElementById('exportFormat').value;
            const topic = document.getElementById('exportTopic').value;
            const difficulty = document.getElementById('exportDifficulty').value;
            
            fetch('manage_quizzes.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: `action=export_quizzes&format=${format}&topic_id=${topic}&difficulty=${difficulty}`
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    // Create and download file
                    let content, mimeType, filename;
                    
                    switch (format) {
                        case 'json':
                            content = JSON.stringify(data.data, null, 2);
                            mimeType = 'application/json';
                            filename = `quizzes_export_${Date.now()}.json`;
                            break;
                        case 'csv':
                            content = convertToCSV(data.data);
                            mimeType = 'text/csv';
                            filename = `quizzes_export_${Date.now()}.csv`;
                            break;
                        case 'html':
                            content = convertToHTML(data.data);
                            mimeType = 'text/html';
                            filename = `quizzes_export_${Date.now()}.html`;
                            break;
                    }
                    
                    const blob = new Blob([content], { type: mimeType });
                    const url = URL.createObjectURL(blob);
                    const a = document.createElement('a');
                    a.href = url;
                    a.download = filename;
                    document.body.appendChild(a);
                    a.click();
                    document.body.removeChild(a);
                    URL.revokeObjectURL(url);
                    
                    showToast(`Exported ${data.count} quizzes successfully`, 'success');
                    
                    const modal = bootstrap.Modal.getInstance(document.getElementById('exportModal'));
                    modal.hide();
                } else {
                    showToast('Export failed: ' + data.message, 'danger');
                }
            })
            .catch(error => {
                showToast('Export error: ' + error.message, 'danger');
            });
        }
         // Show quiz statistics
        function showQuizStats() {
            const modal = new bootstrap.Modal(document.getElementById('quizStatsModal'));
            modal.show();
            
            // Load statistics via AJAX
            $.ajax({
                url: 'ajax.php?action=get_quiz_stats',
                type: 'GET',
                dataType: 'json',
                success: function(stats) {
                    if (stats) {
                        const statsHtml = `
                            <div class="row text-center mb-4">
                                <div class="col-6">
                                    <div class="display-6 fw-bold text-primary">${stats.correct_attempts || 0}</div>
                                    <small class="text-muted">Correct Answers</small>
                                </div>
                                <div class="col-6">
                                    <div class="display-6 fw-bold text-success">${stats.accuracy_rate || 0}%</div>
                                    <small class="text-muted">Accuracy Rate</small>
                                </div>
                            </div>
                            <div class="mb-3">
                                <label class="form-label">Total Quiz Attempts</label>
                                <div class="progress" style="height: 25px;">
                                    <div class="progress-bar bg-success" style="width: ${stats.accuracy_rate || 0}%">
                                        ${stats.correct_attempts || 0} correct
                                    </div>
                                    <div class="progress-bar bg-danger" style="width: ${100 - (stats.accuracy_rate || 0)}%">
                                        ${stats.incorrect_attempts || 0} incorrect
                                    </div>
                                </div>
                            </div>
                            <div class="row">
                                <div class="col-6">
                                    <div class="card bg-light">
                                        <div class="card-body text-center">
                                            <div class="h4 mb-0">${stats.total_attempts || 0}</div>
                                            <small>Total Attempts</small>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-6">
                                    <div class="card bg-light">
                                        <div class="card-body text-center">
                                            <div class="h4 mb-0">${Math.round(stats.avg_time_spent || 0)}s</div>
                                            <small>Avg. Time/Question</small>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        `;
                        $('#quizStatsContent').html(statsHtml);
                    } else {
                        $('#quizStatsContent').html(`
                            <div class="alert alert-info">
                                <i class="fas fa-info-circle me-2"></i>
                                No quiz statistics available yet. Start learning to track your progress!
                            </div>
                        `);
                    }
                },
                error: function() {
                    $('#quizStatsContent').html(`
                        <div class="alert alert-danger">
                            <i class="fas fa-exclamation-circle me-2"></i>
                            Failed to load statistics. Please try again.
                        </div>
                    `);
                }
            });
        }

        // Show import modal
        function showImportModal() {
            const modal = new bootstrap.Modal(document.getElementById('importModal'));
            modal.show();
        }
        
        // Import quizzes
        function importQuizzes() {
            const fileInput = document.getElementById('importFile');
            const format = document.getElementById('importFormat').value;
            const topicId = document.getElementById('importTopic').value;
            
            if (!fileInput.files.length) {
                showToast('Please select a file to import', 'warning');
                return;
            }
            
            const file = fileInput.files[0];
            const reader = new FileReader();
            
            reader.onload = function(e) {
                try {
                    let quizzes;
                    
                    if (format === 'json') {
                        quizzes = JSON.parse(e.target.result);
                    } else if (format === 'csv') {
                        quizzes = parseCSV(e.target.result);
                    }
                    
                    if (!Array.isArray(quizzes) || quizzes.length === 0) {
                        showToast('Invalid file format or empty data', 'danger');
                        return;
                    }
                    
                    // Process import
                    showToast(`Found ${quizzes.length} quizzes to import`, 'info');
                    
                    // Here you would typically send this to the server
                    // For now, just show a message
                    showToast('Import functionality will be implemented', 'info');
                    
                } catch (error) {
                    showToast('Error parsing file: ' + error.message, 'danger');
                }
            };
            
            reader.readAsText(file);
        }
        
        // Parse CSV
        function parseCSV(csvText) {
            const lines = csvText.split('\n');
            const headers = lines[0].split(',');
            const quizzes = [];
            
            for (let i = 1; i < lines.length; i++) {
                if (!lines[i].trim()) continue;
                
                const values = lines[i].split(',');
                const quiz = {};
                
                headers.forEach((header, index) => {
                    quiz[header.trim()] = values[index] ? values[index].trim() : '';
                });
                
                quizzes.push(quiz);
            }
            
            return quizzes;
        }
        
        // Convert to CSV
        function convertToCSV(data) {
            const headers = ['Question', 'Explanation', 'Difficulty', 'Topic', 'Lesson', 'Options', 'Created'];
            const rows = data.map(item => [
                `"${item.question}"`,
                `"${item.explanation || ''}"`,
                `"${item.difficulty}"`,
                `"${item.topic}"`,
                `"${item.lesson}"`,
                `"${JSON.stringify(item.options)}"`,
                `"${item.created}"`
            ]);
            
            return [headers.join(','), ...rows.map(row => row.join(','))].join('\n');
        }
        
        // Convert to HTML
        function convertToHTML(data) {
            let html = `
                <!DOCTYPE html>
                <html>
                <head>
                    <title>Quizzes Export</title>
                    <style>
                        body { font-family: Arial, sans-serif; padding: 20px; }
                        table { width: 100%; border-collapse: collapse; margin-top: 20px; }
                        th, td { border: 1px solid #ddd; padding: 8px; text-align: left; }
                        th { background-color: #f2f2f2; }
                        .badge { padding: 2px 6px; border-radius: 3px; font-size: 12px; }
                        .easy { background: #d4edda; color: #155724; }
                        .medium { background: #fff3cd; color: #856404; }
                        .hard { background: #f8d7da; color: #721c24; }
                    </style>
                </head>
                <body>
                    <h1>Quizzes Export</h1>
                    <p>Generated on ${new Date().toLocaleString()}</p>
                    <p>Total Quizzes: ${data.length}</p>
                    <table>
                        <thead>
                            <tr>
                                <th>Question</th>
                                <th>Difficulty</th>
                                <th>Topic</th>
                                <th>Lesson</th>
                                <th>Options</th>
                                <th>Created</th>
                            </tr>
                        </thead>
                        <tbody>
            `;
            
            data.forEach(item => {
                html += `
                    <tr>
                        <td>${item.question}</td>
                        <td><span class="badge ${item.difficulty}">${item.difficulty}</span></td>
                        <td>${item.topic}</td>
                        <td>${item.lesson}</td>
                        <td>${item.options.length} options</td>
                        <td>${item.created}</td>
                    </tr>
                `;
            });
            
            html += `
                        </tbody>
                    </table>
                </body>
                </html>
            `;
            
            return html;
        }
        
        // Show add quiz modal
        function showAddQuizModal() {
            const modal = new bootstrap.Modal(document.getElementById('addQuizModal'));
            modal.show();
        }
        
        // Toast notification
        function showToast(message, type = 'info') {
            const toastContainer = document.querySelector('.toast-container');
            const toastId = 'toast-' + Date.now();
            
            const toastHtml = `
                <div id="${toastId}" class="toast align-items-center text-bg-${type} border-0" role="alert">
                    <div class="d-flex">
                        <div class="toast-body">
                            ${message}
                        </div>
                        <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button>
                    </div>
                </div>
            `;
            
            toastContainer.insertAdjacentHTML('beforeend', toastHtml);
            const toast = new bootstrap.Toast(document.getElementById(toastId));
            toast.show();
            
            setTimeout(() => {
                document.getElementById(toastId).remove();
            }, 5000);
        }
        
        // Helper function to build query string
        function buildQueryString(params) {
            const currentParams = new URLSearchParams(window.location.search);
            Object.keys(params).forEach(key => {
                currentParams.set(key, params[key]);
            });
            return currentParams.toString();
        }
    </script>
</body>
</html>

<?php
// Helper function to build query string for pagination
function buildQueryString($newParams = []) {
    $params = $_GET;
    foreach ($newParams as $key => $value) {
        $params[$key] = $value;
    }
    return http_build_query($params);
}
?>