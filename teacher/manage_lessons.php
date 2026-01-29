<?php
require_once 'database.php';

$db = new Database();
$conn = $db->getConnection();
$teacherSession = new TeacherSession($conn);

if (!$teacherSession->isLoggedIn()) {
    header('Location: teacher_login');
    exit;
}

// Update last active time
$teacherSession->updateLastActive();

$teacherUsername = $teacherSession->getTeacherUsername();
$teacherEmail = $teacherSession->getTeacherEmail();
$avatarColor = $teacherSession->getAvatarColor();

// Handle bulk actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $response = [];
    
    if (isset($_POST['action'])) {
        switch ($_POST['action']) {
            case 'get_lesson_stats':
                $response = getLessonStats($conn);
                break;
            case 'bulk_update':
                $response = bulkUpdateLessons($conn, $_POST);
                break;
            case 'export_lessons':
                $response = exportLessons($conn, $_POST);
                break;
            case 'import_lessons':
                // $response = importLessons($conn, $_POST);
                break;
        }
        
        header('Content-Type: application/json');
        echo json_encode($response);
        exit;
    }
}

// Fetch all topics with their lessons
$topics_query = "SELECT t.id, t.topic_name, t.topic_order,
                COUNT(l.id) as lesson_count,
                SUM(CASE WHEN l.is_active = 1 THEN 1 ELSE 0 END) as active_lessons,
                SUM(CASE WHEN l.is_active = 0 THEN 1 ELSE 0 END) as inactive_lessons,
                MIN(l.created_at) as first_lesson_date,
                MAX(l.created_at) as last_lesson_date
                FROM topics t
                LEFT JOIN lessons l ON t.id = l.topic_id AND l.deleted_at IS NULL
                WHERE t.is_active = 1 AND t.deleted_at IS NULL
                GROUP BY t.id
                ORDER BY t.topic_order";
$topics = $conn->query($topics_query)->fetchAll(PDO::FETCH_ASSOC);

// Get overall statistics
$stats_query = "SELECT 
                COUNT(*) as total_lessons,
                SUM(CASE WHEN is_active = 1 THEN 1 ELSE 0 END) as active_lessons,
                SUM(CASE WHEN is_active = 0 THEN 1 ELSE 0 END) as inactive_lessons,
                AVG(LENGTH(lesson_content)) as avg_content_length,
                COUNT(DISTINCT topic_id) as topics_with_lessons
                FROM lessons 
                WHERE deleted_at IS NULL";
$stats = $conn->query($stats_query)->fetch(PDO::FETCH_ASSOC);

// Functions
function getLessonStats($conn) {
    try {
        // Weekly lesson creation stats
        $weekly_stats = "SELECT 
                        DATE_FORMAT(created_at, '%Y-%m-%d') as date,
                        COUNT(*) as lessons_created
                        FROM lessons 
                        WHERE created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
                        AND deleted_at IS NULL
                        GROUP BY DATE_FORMAT(created_at, '%Y-%m-%d')
                        ORDER BY date";
        
        $weekly = $conn->query($weekly_stats)->fetchAll(PDO::FETCH_ASSOC);
        
        // Content type distribution
        $type_stats = "SELECT 
                      content_type,
                      COUNT(*) as count,
                      ROUND(COUNT(*) * 100.0 / (SELECT COUNT(*) FROM lessons WHERE deleted_at IS NULL), 1) as percentage
                      FROM lessons 
                      WHERE deleted_at IS NULL
                      GROUP BY content_type
                      ORDER BY count DESC";
        
        $types = $conn->query($type_stats)->fetchAll(PDO::FETCH_ASSOC);
        
        // Quiz coverage
        $quiz_stats = "SELECT 
                      COUNT(DISTINCT l.id) as total_lessons,
                      COUNT(DISTINCT q.lesson_id) as lessons_with_quizzes,
                      ROUND(COUNT(DISTINCT q.lesson_id) * 100.0 / COUNT(DISTINCT l.id), 1) as quiz_coverage
                      FROM lessons l
                      LEFT JOIN quizzes q ON l.id = q.lesson_id AND q.deleted_at IS NULL
                      WHERE l.deleted_at IS NULL";
        
        $quizzes = $conn->query($quiz_stats)->fetch(PDO::FETCH_ASSOC);
        
        return [
            'success' => true,
            'weekly_stats' => $weekly,
            'type_stats' => $types,
            'quiz_stats' => $quizzes
        ];
    } catch (PDOException $e) {
        return ['success' => false, 'message' => 'Database error: ' . $e->getMessage()];
    }
}

function bulkUpdateLessons($conn, $data) {
    try {
        if (!isset($data['lesson_ids']) || empty($data['lesson_ids'])) {
            return ['success' => false, 'message' => 'No lessons selected'];
        }
        
        $lesson_ids = json_decode($data['lesson_ids']);
        $placeholders = str_repeat('?,', count($lesson_ids) - 1) . '?';
        
        switch ($data['operation']) {
            case 'activate':
                $query = "UPDATE lessons SET is_active = 1 WHERE id IN ($placeholders)";
                break;
            case 'deactivate':
                $query = "UPDATE lessons SET is_active = 0 WHERE id IN ($placeholders)";
                break;
            case 'delete':
                $query = "UPDATE lessons SET deleted_at = NOW() WHERE id IN ($placeholders)";
                break;
            case 'move_topic':
                if (!isset($data['target_topic_id'])) {
                    return ['success' => false, 'message' => 'Target topic not specified'];
                }
                $query = "UPDATE lessons SET topic_id = ? WHERE id IN ($placeholders)";
                array_unshift($lesson_ids, $data['target_topic_id']);
                break;
            case 'update_order':
                // This would need individual updates for each lesson
                return ['success' => false, 'message' => 'Order update requires individual processing'];
            default:
                return ['success' => false, 'message' => 'Invalid operation'];
        }
        
        $stmt = $conn->prepare($query);
        $stmt->execute($lesson_ids);
        
        return [
            'success' => true,
            'message' => 'Operation completed successfully',
            'affected_rows' => $stmt->rowCount()
        ];
    } catch (PDOException $e) {
        return ['success' => false, 'message' => 'Database error: ' . $e->getMessage()];
    }
}

function exportLessons($conn, $data) {
    try {
        $query = "SELECT l.*, t.topic_name,
                 (SELECT GROUP_CONCAT(CONCAT(q.question, '||', q.difficulty, '||', q.explanation)) 
                  FROM quizzes q WHERE q.lesson_id = l.id) as quiz_data
                 FROM lessons l
                 JOIN topics t ON l.topic_id = t.id
                 WHERE l.deleted_at IS NULL";
        
        if (isset($data['topic_id']) && $data['topic_id']) {
            $query .= " AND l.topic_id = :topic_id";
        }
        
        if (isset($data['status']) && $data['status'] !== 'all') {
            $query .= " AND l.is_active = :status";
        }
        
        $query .= " ORDER BY t.topic_order, l.lesson_order";
        
        $stmt = $conn->prepare($query);
        
        if (isset($data['topic_id']) && $data['topic_id']) {
            $stmt->bindParam(':topic_id', $data['topic_id']);
        }
        
        if (isset($data['status']) && $data['status'] !== 'all') {
            $stmt->bindParam(':status', $data['status']);
        }
        
        $stmt->execute();
        $lessons = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Format data for export
        $export_data = [];
        foreach ($lessons as $lesson) {
            $export_data[] = [
                'topic' => $lesson['topic_name'],
                'title' => $lesson['lesson_title'],
                'content' => $lesson['lesson_content'],
                'order' => $lesson['lesson_order'],
                'type' => $lesson['content_type'],
                'active' => $lesson['is_active'] ? 'Yes' : 'No',
                'created' => $lesson['created_at'],
                'quiz' => $lesson['quiz_data']
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
    <title>Manage Lessons - JS Tutorial Admin</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.6/css/dataTables.bootstrap5.min.css">
    <link rel="stylesheet" href="https://cdn.datatables.net/buttons/2.4.1/css/buttons.bootstrap5.min.css">
    <style>
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
        
        .topic-card {
            background: white;
            border-radius: 10px;
            overflow: hidden;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
            margin-bottom: 20px;
            transition: all 0.3s;
        }
        
        .topic-card:hover {
            box-shadow: 0 5px 20px rgba(0,0,0,0.1);
        }
        
        .topic-header {
            background: linear-gradient(135deg, #4361ee, #3a0ca3);
            color: white;
            padding: 15px 20px;
        }
        
        .lesson-item {
            padding: 15px 20px;
            border-bottom: 1px solid #eee;
            transition: background 0.2s;
        }
        
        .lesson-item:hover {
            background: #f8f9fa;
        }
        
        .lesson-item:last-child {
            border-bottom: none;
        }
        
        .lesson-status-badge {
            font-size: 0.75rem;
            padding: 3px 8px;
            border-radius: 4px;
        }
        
        .status-active {
            background: #d4edda;
            color: #155724;
        }
        
        .status-inactive {
            background: #f8d7da;
            color: #721c24;
        }
        
        .action-buttons {
            display: flex;
            gap: 5px;
            opacity: 0;
            transition: opacity 0.2s;
        }
        
        .lesson-item:hover .action-buttons {
            opacity: 1;
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
        
        .filter-bar {
            background: white;
            padding: 15px 20px;
            border-radius: 10px;
            margin-bottom: 20px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
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
            max-width: 300px;
        }
        
        .chart-container {
            background: white;
            border-radius: 10px;
            padding: 20px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
            margin-bottom: 20px;
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

<nav class="navbar navbar-expand-lg navbar-dark bg-primary">
        <div class="container">
            <a class="navbar-brand" href="#">
                <img src="../assets/img/URUScript.png" alt="JS Tutorial Logo" width="40" height="40"> Lessons
            </a>
            <div class="navbar-nav ms-auto">
                <div class="dropdown">
                    <button class="btn btn-outline-light dropdown-toggle" type="button" 
                            data-bs-toggle="dropdown">
                        <div class="d-inline-flex align-items-center">
                            <div class="rounded-circle d-flex align-items-center justify-content-center me-2" 
                                 style="width: 30px; height: 30px; background: <?php echo $avatarColor; ?>; color: white;">
                                <?php echo strtoupper(substr($teacherUsername, 0, 1)); ?>
                            </div>
                            <?php echo htmlspecialchars($teacherUsername); ?>
                        </div>
                    </button>
                    <ul class="dropdown-menu dropdown-menu-end">
                        <li><a class="dropdown-item" href="teacher_dashboard">
                            <i class="fas fa-tachometer-alt me-2"></i> Dashboard
                        </a></li>
                        <li><a class="dropdown-item" href="manage_topics">
                            <i class="fas fa-folder me-2"></i> Manage Topics
                        </a></li>
                        <li><a class="dropdown-item" href="student_management">
                            <i class="fas fa-users me-2"></i> Student Management
                        </a></li>
                        <li><a class="dropdown-item" href="analytics">
                            <i class="fas fa-chart-bar me-2"></i> Analytics
                        </a></li>
                        <li><hr class="dropdown-divider"></li>
                        <li><a class="dropdown-item text-danger" href="teacher_logout">
                            <i class="fas fa-sign-out-alt me-2"></i> Logout
                        </a></li>
                    </ul>
                </div>
            </div>
        </div>
    </nav>
    <div class="admin-container">
        <!-- Header -->
        <div class="d-flex justify-content-between align-items-center mb-4">
            <div>
                <h2 class="mb-1">
                    <i class="fas fa-book me-2 text-primary"></i>Manage Lessons
                </h2>
                <p class="text-muted mb-0">View, edit, and manage all tutorial lessons</p>
            </div>
            <div class="d-flex gap-2">
            
            <button class="btn btn-info" onclick="window.location.href='teacher_dashboard'">
                <i class="fas fa-arrow-left me-1"></i> Dashboard
            </button>
                <a href="add-lesson" class="btn btn-primary">
                    <i class="fas fa-plus me-1"></i> Add New Lesson
                </a>
                <button class="btn btn-outline-primary" onclick="showStatsModal()">
                    <i class="fas fa-chart-bar me-1"></i> View Stats
                </button>
                
            </div>
        </div>
        
        <!-- Quick Actions -->
        <div class="quick-actions">
            <a href="add-lesson" class="quick-action-btn">
                <i class="fas fa-plus-circle text-primary"></i>
                <span class="fw-bold mt-2">Create New Lesson</span>
                <small class="text-muted mt-1">Start from scratch</small>
            </a>
            <a href="manage_topics" class="quick-action-btn">
                <i class="fas fa-folder text-success"></i>
                <span class="fw-bold mt-2">Manage Topics</span>
                <small class="text-muted mt-1">Organize lesson categories</small>
            </a>
            <a href="manage_quizzes" class="quick-action-btn">
                <i class="fas fa-question-circle text-warning"></i>
                <span class="fw-bold mt-2">Manage Quizzes</span>
                <small class="text-muted mt-1">Review quiz questions</small>
            </a>
            <button class="quick-action-btn" onclick="showExportModal()">
                <i class="fas fa-file-export text-info"></i>
                <span class="fw-bold mt-2">Export Lessons</span>
                <small class="text-muted mt-1">Download lesson data</small>
            </button>
        </div>
        
        <!-- Statistics Cards -->
        <div class="row mb-4">
            <div class="col-md-3">
                <div class="stats-card">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <h3 class="mb-0"><?php echo $stats['total_lessons'] ?? 0; ?></h3>
                            <small class="text-muted">Total Lessons</small>
                        </div>
                        <div class="stats-icon bg-primary bg-opacity-10 text-primary">
                            <i class="fas fa-book"></i>
                        </div>
                    </div>
                    <div class="mt-3">
                        <small class="text-muted">
                            <i class="fas fa-check text-success me-1"></i>
                            <?php echo $stats['active_lessons'] ?? 0; ?> active
                        </small>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="stats-card">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <h3 class="mb-0"><?php echo $stats['topics_with_lessons'] ?? 0; ?></h3>
                            <small class="text-muted">Active Topics</small>
                        </div>
                        <div class="stats-icon bg-success bg-opacity-10 text-success">
                            <i class="fas fa-folder"></i>
                        </div>
                    </div>
                    <div class="mt-3">
                        <small class="text-muted">
                            <i class="fas fa-layer-group me-1"></i>
                            <?php echo count($topics); ?> total topics
                        </small>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="stats-card">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <h3 class="mb-0">
                                <?php 
                                $avg_length = $stats['avg_content_length'] ?? 0;
                                echo number_format($avg_length / 1000, 1) . 'k';
                                ?>
                            </h3>
                            <small class="text-muted">Avg. Content Length</small>
                        </div>
                        <div class="stats-icon bg-warning bg-opacity-10 text-warning">
                            <i class="fas fa-ruler-combined"></i>
                        </div>
                    </div>
                    <div class="mt-3">
                        <small class="text-muted">
                            <i class="fas fa-file-alt me-1"></i>
                            Characters per lesson
                        </small>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="stats-card">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <h3 class="mb-0">
                                <?php 
                                $quiz_coverage = 0;
                                if ($stats['total_lessons'] > 0) {
                                    // Calculate quiz coverage (you might want to fetch this from DB)
                                    $quiz_query = "SELECT COUNT(DISTINCT lesson_id) as with_quizzes FROM quizzes WHERE deleted_at IS NULL";
                                    $quiz_result = $conn->query($quiz_query)->fetch(PDO::FETCH_ASSOC);
                                    $quiz_coverage = round(($quiz_result['with_quizzes'] / $stats['total_lessons']) * 100);
                                }
                                echo ($quiz_coverage == null) ? 0 . '%' : $quiz_coverage . '%';
                                ?>
                            </h3>
                            <small class="text-muted">Quiz Coverage</small>
                        </div>
                        <div class="stats-icon bg-info bg-opacity-10 text-info">
                            <i class="fas fa-question-circle"></i>
                        </div>
                    </div>
                    <div class="mt-3">
                        <small class="text-muted">
                            <i class="fas fa-check-circle me-1"></i>
                            Lessons with quizzes
                        </small>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Filter Bar -->
        <div class="filter-bar">
            <div class="row align-items-center">
                <div class="col-md-6">
                    <div class="input-group search-box">
                        <span class="input-group-text">
                            <i class="fas fa-search"></i>
                        </span>
                        <input type="text" class="form-control" id="searchInput" placeholder="Search lessons...">
                        <button class="btn btn-outline-secondary" type="button" onclick="clearSearch()">
                            <i class="fas fa-times"></i>
                        </button>
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="d-flex justify-content-end gap-2">
                        <select class="form-select" style="max-width: 200px;" onchange="filterByStatus(this.value)">
                            <option value="all">All Status</option>
                            <option value="1">Active Only</option>
                            <option value="0">Inactive Only</option>
                        </select>
                        <select class="form-select" style="max-width: 200px;" onchange="filterByTopic(this.value)">
                            <option value="all">All Topics</option>
                            <?php foreach($topics as $topic): ?>
                            <option value="<?php echo $topic['id']; ?>"><?php echo htmlspecialchars($topic['topic_name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                        <button class="btn btn-outline-secondary" onclick="refreshList()">
                            <i class="fas fa-sync-alt"></i>
                        </button>
                    </div>
                </div>
            </div>
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
                
                <div class="btn-group me-2">
                    <button type="button" class="btn btn-sm btn-outline-success" onclick="bulkAction('activate')">
                        <i class="fas fa-check me-1"></i> Activate
                    </button>
                    <button type="button" class="btn btn-sm btn-outline-warning" onclick="bulkAction('deactivate')">
                        <i class="fas fa-ban me-1"></i> Deactivate
                    </button>
                </div>
                
                <div class="dropdown me-2">
                    <button class="btn btn-sm btn-outline-secondary dropdown-toggle" type="button" data-bs-toggle="dropdown">
                        <i class="fas fa-exchange-alt me-1"></i> Move To
                    </button>
                    <ul class="dropdown-menu">
                        <?php foreach($topics as $topic): ?>
                        <li><a class="dropdown-item" href="#" onclick="moveToTopic(<?php echo $topic['id']; ?>)">
                            <?php echo htmlspecialchars($topic['topic_name']); ?>
                        </a></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
                
                <button type="button" class="btn btn-sm btn-outline-danger" onclick="bulkAction('delete')">
                    <i class="fas fa-trash me-1"></i> Delete
                </button>
                
                <button type="button" class="btn btn-sm btn-outline-secondary ms-auto" onclick="clearSelection()">
                    <i class="fas fa-times me-1"></i> Clear Selection
                </button>
            </div>
        </div>
        
        <!-- Topics and Lessons -->
        <?php if (count($topics) > 0): ?>
            <?php foreach($topics as $topic): 
                // Fetch detailed lessons for this topic
                $lessons_query = "SELECT l.*, 
                                 (SELECT COUNT(*) FROM quizzes q WHERE q.lesson_id = l.id AND q.deleted_at IS NULL) as quiz_count,
                                 (SELECT COUNT(*) FROM student_progress sp WHERE sp.lesson_id = l.id) as completed_count
                                 FROM lessons l 
                                 WHERE l.topic_id = ? AND l.deleted_at IS NULL 
                                 ORDER BY l.lesson_order";
                $lessons_stmt = $conn->prepare($lessons_query);
                $lessons_stmt->execute([$topic['id']]);
                $lessons = $lessons_stmt->fetchAll(PDO::FETCH_ASSOC);
            ?>
            <div class="topic-card" data-topic-id="<?php echo $topic['id']; ?>">
                <div class="topic-header">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <h5 class="mb-1">
                                <i class="fas fa-book me-2"></i>
                                <?php echo htmlspecialchars($topic['topic_name']); ?>
                            </h5>
                            <small class="opacity-75">
                                <?php echo $topic['lesson_count']; ?> lessons • 
                                <?php echo $topic['active_lessons']; ?> active •
                                Order: <?php echo $topic['topic_order']; ?>
                            </small>
                        </div>
                        <div class="d-flex gap-2">
                            <button class="btn btn-sm btn-light" onclick="expandTopic(<?php echo $topic['id']; ?>)">
                                <i class="fas fa-chevron-down"></i>
                            </button>
                            <a href="add-lesson?topic_id=<?php echo $topic['id']; ?>" class="btn btn-sm btn-light">
                                <i class="fas fa-plus"></i> Add Lesson
                            </a>
                        </div>
                    </div>
                </div>
                
                <div class="topic-content" id="topicContent<?php echo $topic['id']; ?>" style="display: none;">
                    <?php if (count($lessons) > 0): ?>
                        <?php foreach($lessons as $lesson): 
                            $content_preview = strip_tags($lesson['lesson_content']);
                            $content_preview = strlen($content_preview) > 150 ? substr($content_preview, 0, 150) . '...' : $content_preview;
                        ?>
                        <div class="lesson-item" data-lesson-id="<?php echo $lesson['id']; ?>" 
                             data-status="<?php echo $lesson['is_active']; ?>">
                            <div class="d-flex justify-content-between align-items-center">
                                <div class="d-flex align-items-center">
                                    <div class="form-check me-3">
                                        <input type="checkbox" class="form-check-input lesson-checkbox" 
                                               value="<?php echo $lesson['id']; ?>"
                                               onchange="updateSelection()">
                                    </div>
                                    <div class="me-3 text-center" style="min-width: 40px;">
                                        <span class="badge bg-secondary"><?php echo $lesson['lesson_order']; ?></span>
                                    </div>
                                    <div>
                                        <div class="fw-bold mb-1">
                                            <?php echo htmlspecialchars($lesson['lesson_title']); ?>
                                            <?php if ($lesson['quiz_count'] > 0): ?>
                                            <span class="badge bg-success ms-2">
                                                <i class="fas fa-question"></i> Quiz
                                            </span>
                                            <?php endif; ?>
                                        </div>
                                        <div class="text-muted small mb-1"><?php echo $content_preview; ?></div>
                                        <div class="d-flex gap-3">
                                            <span class="badge bg-info">
                                                <i class="fas fa-tag me-1"></i><?php echo ucfirst($lesson['content_type']); ?>
                                            </span>
                                            <?php if ($lesson['is_active'] == 1): ?>
                                            <span class="lesson-status-badge status-active">
                                                <i class="fas fa-check me-1"></i> Active
                                            </span>
                                            <?php else: ?>
                                            <span class="lesson-status-badge status-inactive">
                                                <i class="fas fa-times me-1"></i> Inactive
                                            </span>
                                            <?php endif; ?>
                                            <span class="text-muted small">
                                                <i class="fas fa-users me-1"></i>
                                                <?php echo $lesson['completed_count']; ?> completions
                                            </span>
                                            <span class="text-muted small">
                                                <i class="fas fa-calendar me-1"></i>
                                                <?php echo date('M d, Y', strtotime($lesson['created_at'])); ?>
                                            </span>
                                        </div>
                                    </div>
                                </div>
                                
                                <div class="action-buttons">
                                    <a href="add-lesson?edit=<?php echo $lesson['id']; ?>" 
                                       class="btn btn-sm btn-outline-primary" title="Edit">
                                        <i class="fas fa-edit"></i>
                                    </a>
                                    <a href="lesson?lesson_id=<?php echo $lesson['id']; ?>" 
                                       target="_blank" class="btn btn-sm btn-outline-info" title="Preview">
                                        <i class="fas fa-eye"></i>
                                    </a>
                                    <button class="btn btn-sm btn-outline-success" 
                                            onclick="toggleLessonStatus(<?php echo $lesson['id']; ?>, <?php echo $lesson['is_active']; ?>)" 
                                            title="<?php echo $lesson['is_active'] ? 'Deactivate' : 'Activate'; ?>">
                                        <i class="fas fa-power-off"></i>
                                    </button>
                                    <button class="btn btn-sm btn-outline-danger" 
                                            onclick="deleteLesson(<?php echo $lesson['id']; ?>)" title="Delete">
                                        <i class="fas fa-trash"></i>
                                    </button>
                                </div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <div class="empty-state">
                            <i class="fas fa-book-open"></i>
                            <p class="mb-2">No lessons found for this topic</p>
                            <a href="add-lesson?topic_id=<?php echo $topic['id']; ?>" class="btn btn-sm btn-primary">
                                <i class="fas fa-plus me-1"></i> Create First Lesson
                            </a>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
            <?php endforeach; ?>
        <?php else: ?>
            <div class="empty-state">
                <i class="fas fa-book-open fa-3x mb-3"></i>
                <h4>No Topics Found</h4>
                <p class="text-muted mb-3">You haven't created any topics yet</p>
                <a href="manage_topics" class="btn btn-primary">
                    <i class="fas fa-plus me-1"></i> Create Your First Topic
                </a>
            </div>
        <?php endif; ?>
    </div>
    
    <!-- Modals -->
    <!-- Stats Modal -->
    <div class="modal fade" id="statsModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">
                        <i class="fas fa-chart-bar me-2"></i>Lesson Statistics
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
                        <i class="fas fa-file-export me-2"></i>Export Lessons
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
                        <label class="form-label">Filter by Status</label>
                        <select class="form-select" id="exportStatus">
                            <option value="all">All Status</option>
                            <option value="1">Active Only</option>
                            <option value="0">Inactive Only</option>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Include Content</label>
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" id="includeContent" checked>
                            <label class="form-check-label" for="includeContent">
                                Include lesson content
                            </label>
                        </div>
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" id="includeQuizzes" checked>
                            <label class="form-check-label" for="includeQuizzes">
                                Include quiz questions
                            </label>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="button" class="btn btn-primary" onclick="exportLessons()">
                        <i class="fas fa-download me-1"></i> Export
                    </button>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Toast Container -->
    <div class="toast-container position-fixed top-0 end-0 p-3"></div>
    
    <script src="https://code.jquery.com/jquery-3.6.4.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script>
        let selectedLessons = new Set();
        let currentFilters = {
            status: 'all',
            topic: 'all',
            search: ''
        };
        
        // Initialize when page loads
        document.addEventListener('DOMContentLoaded', function() {
            // Expand first topic by default
            if (document.querySelector('.topic-card')) {
                const firstTopic = document.querySelector('.topic-card');
                expandTopic(firstTopic.dataset.topicId);
            }
        });
        
        // Expand/collapse topic
        function expandTopic(topicId) {
            const content = document.getElementById('topicContent' + topicId);
            const button = document.querySelector(`[onclick="expandTopic(${topicId})"] i`);
            
            if (content.style.display === 'none') {
                content.style.display = 'block';
                button.classList.remove('fa-chevron-down');
                button.classList.add('fa-chevron-up');
            } else {
                content.style.display = 'none';
                button.classList.remove('fa-chevron-up');
                button.classList.add('fa-chevron-down');
            }
        }
        
        // Update selection
        function updateSelection() {
            selectedLessons.clear();
            
            document.querySelectorAll('.lesson-checkbox:checked').forEach(checkbox => {
                selectedLessons.add(checkbox.value);
            });
            
            const count = selectedLessons.size;
            document.getElementById('selectedCount').textContent = count;
            
            if (count > 0) {
                document.getElementById('bulkActionsBar').style.display = 'flex';
                document.getElementById('selectAll').checked = count === document.querySelectorAll('.lesson-checkbox').length;
            } else {
                document.getElementById('bulkActionsBar').style.display = 'none';
            }
        }
        
        // Toggle select all
        function toggleSelectAll(checked) {
            document.querySelectorAll('.lesson-checkbox').forEach(checkbox => {
                checkbox.checked = checked;
            });
            updateSelection();
        }
        
        // Clear selection
        function clearSelection() {
            selectedLessons.clear();
            document.querySelectorAll('.lesson-checkbox').forEach(checkbox => {
                checkbox.checked = false;
            });
            document.getElementById('selectAll').checked = false;
            updateSelection();
        }
        
        // Bulk action
        function bulkAction(action) {
            if (selectedLessons.size === 0) {
                showToast('Please select at least one lesson', 'warning');
                return;
            }
            
            let confirmMessage = '';
            let confirmButton = 'Confirm';
            
            switch (action) {
                case 'activate':
                    confirmMessage = `Activate ${selectedLessons.size} selected lesson(s)?`;
                    break;
                case 'deactivate':
                    confirmMessage = `Deactivate ${selectedLessons.size} selected lesson(s)?`;
                    break;
                case 'delete':
                    confirmMessage = `Delete ${selectedLessons.size} selected lesson(s)? This action cannot be undone.`;
                    confirmButton = 'Delete';
                    break;
            }
            
            if (!confirm(confirmMessage)) return;
            
            const lessonIds = Array.from(selectedLessons);
            
            fetch('manage_lessons.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: `action=bulk_update&operation=${action}&lesson_ids=${JSON.stringify(lessonIds)}`
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
        
        // Move lessons to topic
        function moveToTopic(topicId) {
            if (selectedLessons.size === 0) {
                showToast('Please select at least one lesson', 'warning');
                return;
            }
            
            const topicName = document.querySelector(`[onclick="moveToTopic(${topicId})"]`).textContent.trim();
            
            if (!confirm(`Move ${selectedLessons.size} selected lesson(s) to "${topicName}"?`)) return;
            
            const lessonIds = Array.from(selectedLessons);
            
            fetch('manage_lessons.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: `action=bulk_update&operation=move_topic&target_topic_id=${topicId}&lesson_ids=${JSON.stringify(lessonIds)}`
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
        
        // Toggle single lesson status
        function toggleLessonStatus(lessonId, currentStatus) {
            const newStatus = currentStatus ? 0 : 1;
            const action = newStatus ? 'activate' : 'deactivate';
            
            fetch('manage_lessons.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: `action=bulk_update&operation=${action}&lesson_ids=[${lessonId}]`
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    showToast('Lesson status updated', 'success');
                    setTimeout(() => {
                        location.reload();
                    }, 500);
                } else {
                    showToast(data.message, 'danger');
                }
            })
            .catch(error => {
                showToast('Network error: ' + error.message, 'danger');
            });
        }
        
        // Delete single lesson
        function deleteLesson(lessonId) {
            if (!confirm('Are you sure you want to delete this lesson? This action cannot be undone.')) {
                return;
            }
            
            fetch('manage_lessons.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: `action=bulk_update&operation=delete&lesson_ids=[${lessonId}]`
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    showToast('Lesson deleted successfully', 'success');
                    setTimeout(() => {
                        location.reload();
                    }, 500);
                } else {
                    showToast(data.message, 'danger');
                }
            })
            .catch(error => {
                showToast('Network error: ' + error.message, 'danger');
            });
        }
        
        // Filter by status
        function filterByStatus(status) {
            currentFilters.status = status;
            applyFilters();
        }
        
        // Filter by topic
        function filterByTopic(topicId) {
            currentFilters.topic = topicId;
            applyFilters();
        }
        
        // Search lessons
        function searchLessons() {
            const searchTerm = document.getElementById('searchInput').value.toLowerCase();
            currentFilters.search = searchTerm;
            applyFilters();
        }
        
        // Clear search
        function clearSearch() {
            document.getElementById('searchInput').value = '';
            currentFilters.search = '';
            applyFilters();
        }
        
        // Apply all filters
        function applyFilters() {
            const topics = document.querySelectorAll('.topic-card');
            
            topics.forEach(topic => {
                const topicId = topic.dataset.topicId;
                const lessons = topic.querySelectorAll('.lesson-item');
                let topicVisible = false;
                
                // Filter by topic
                if (currentFilters.topic !== 'all' && currentFilters.topic !== topicId) {
                    topic.style.display = 'none';
                    return;
                }
                
                lessons.forEach(lesson => {
                    const lessonId = lesson.dataset.lessonId;
                    const status = lesson.dataset.status;
                    const title = lesson.querySelector('.fw-bold').textContent.toLowerCase();
                    const content = lesson.querySelector('.text-muted').textContent.toLowerCase();
                    
                    let visible = true;
                    
                    // Filter by status
                    if (currentFilters.status !== 'all' && status !== currentFilters.status) {
                        visible = false;
                    }
                    
                    // Filter by search
                    if (currentFilters.search && 
                        !title.includes(currentFilters.search) && 
                        !content.includes(currentFilters.search)) {
                        visible = false;
                    }
                    
                    if (visible) {
                        lesson.style.display = 'flex';
                        topicVisible = true;
                    } else {
                        lesson.style.display = 'none';
                    }
                });
                
                topic.style.display = topicVisible ? 'block' : 'none';
            });
        }
        
        // Refresh list
        function refreshList() {
            location.reload();
        }
        
        // Show stats modal
        function showStatsModal() {
            const modal = new bootstrap.Modal(document.getElementById('statsModal'));
            modal.show();
            
            // Load statistics
            fetch('manage_lessons.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: 'action=get_lesson_stats'
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
                                    <div class="display-4 fw-bold">${data.quiz_stats.quiz_coverage == null ? 0 : data.quiz_stats.quiz_coverage}%</div>
                                    <small class="text-muted">of lessons have quizzes</small>
                                </div>
                                <div class="flex-grow-1">
                                    <div class="progress" style="height: 30px;">
                                        <div class="progress-bar bg-success" style="width: ${data.quiz_stats.quiz_coverage == null ? 0 : data.quiz_stats.quiz_coverage}%">
                                            ${data.quiz_stats.lessons_with_quizzes} with quizzes
                                        </div>
                                        <div class="progress-bar bg-light text-dark" style="width: ${100 - (data.quiz_stats.quiz_coverage == null ? 0 : data.quiz_stats.quiz_coverage)}%">
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
        
        // Export lessons
        function exportLessons() {
            const format = document.getElementById('exportFormat').value;
            const topic = document.getElementById('exportTopic').value;
            const status = document.getElementById('exportStatus').value;
            
            fetch('manage_lessons.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: `action=export_lessons&format=${format}&topic_id=${topic}&status=${status}`
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
                            filename = `lessons_export_${Date.now()}.json`;
                            break;
                        case 'csv':
                            content = convertToCSV(data.data);
                            mimeType = 'text/csv';
                            filename = `lessons_export_${Date.now()}.csv`;
                            break;
                        case 'html':
                            content = convertToHTML(data.data);
                            mimeType = 'text/html';
                            filename = `lessons_export_${Date.now()}.html`;
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
                    
                    showToast(`Exported ${data.count} lessons successfully`, 'success');
                    
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
        
        // Convert to CSV
        function convertToCSV(data) {
            const headers = ['Topic', 'Title', 'Type', 'Order', 'Status', 'Created', 'Quiz'];
            const rows = data.map(item => [
                `"${item.topic}"`,
                `"${item.title}"`,
                `"${item.type}"`,
                item.order,
                `"${item.active}"`,
                `"${item.created}"`,
                `"${item.quiz || 'No quiz'}"`
            ]);
            
            return [headers.join(','), ...rows.map(row => row.join(','))].join('\n');
        }
        
        // Convert to HTML
        function convertToHTML(data) {
            let html = `
                <!DOCTYPE html>
                <html>
                <head>
                    <title>Lessons Export</title>
                    <style>
                        body { font-family: Arial, sans-serif; padding: 20px; }
                        table { width: 100%; border-collapse: collapse; margin-top: 20px; }
                        th, td { border: 1px solid #ddd; padding: 8px; text-align: left; }
                        th { background-color: #f2f2f2; }
                        .badge { padding: 2px 6px; border-radius: 3px; font-size: 12px; }
                        .active { background: #d4edda; color: #155724; }
                        .inactive { background: #f8d7da; color: #721c24; }
                    </style>
                </head>
                <body>
                    <h1>Lessons Export</h1>
                    <p>Generated on ${new Date().toLocaleString()}</p>
                    <p>Total Lessons: ${data.length}</p>
                    <table>
                        <thead>
                            <tr>
                                <th>Topic</th>
                                <th>Title</th>
                                <th>Type</th>
                                <th>Order</th>
                                <th>Status</th>
                                <th>Created</th>
                                <th>Quiz</th>
                            </tr>
                        </thead>
                        <tbody>
            `;
            
            data.forEach(item => {
                html += `
                    <tr>
                        <td>${item.topic}</td>
                        <td>${item.title}</td>
                        <td>${item.type}</td>
                        <td>${item.order}</td>
                        <td><span class="badge ${item.active === 'Yes' ? 'active' : 'inactive'}">${item.active}</span></td>
                        <td>${item.created}</td>
                        <td>${item.quiz ? 'Yes' : 'No'}</td>
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
        
        // Initialize search with debounce
        let searchTimeout;
        document.getElementById('searchInput').addEventListener('input', function() {
            clearTimeout(searchTimeout);
            searchTimeout = setTimeout(searchLessons, 300);
        });
    </script>
</body>
</html>