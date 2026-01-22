<?php
require_once 'database.php';

$db = new Database();
$conn = $db->getConnection();
$teacherSession = new TeacherSession($conn);

// Check if teacher is logged in
if (!$teacherSession->isLoggedIn()) {
    header('Location: teacher_login');
    exit;
}

// Update last active time
$teacherSession->updateLastActive();

// Set timezone
date_default_timezone_set('Asia/Manila');

// Get date range from GET or default to last 30 days
$start_date = isset($_GET['start_date']) ? $_GET['start_date'] : date('Y-m-d', strtotime('-30 days'));
$end_date = isset($_GET['end_date']) ? $_GET['end_date'] : date('Y-m-d');
$report_type = $_GET['report_type'] ?? 'overview';
$topic_id = $_GET['topic_id'] ?? 'all';
$student_id = $_GET['student_id'] ?? 'all';

// Function to get overview statistics
function getOverviewStats($conn, $start_date, $end_date) {
    $stats = [];
    
    // Overall statistics
    $query = "SELECT 
        COUNT(DISTINCT s.id) as total_students,
        COUNT(DISTINCT CASE WHEN s.last_active >= DATE_SUB(CURDATE(), INTERVAL 7 DAY) THEN s.id END) as active_last_7_days,
        COUNT(DISTINCT CASE WHEN DATE(s.created_at) BETWEEN :start_date AND :end_date THEN s.id END) as new_students,
        AVG(s.total_score) as avg_total_score,
        COUNT(DISTINCT sp.student_id) as students_with_progress,
        COUNT(DISTINCT sp.lesson_id) as total_lessons_started,
        COUNT(DISTINCT CASE WHEN sp.is_completed = 1 THEN sp.lesson_id END) as total_lessons_completed,
        COUNT(DISTINCT sqa.student_id) as students_with_quiz_attempts,
        COUNT(sqa.id) as total_quiz_attempts,
        AVG(CASE WHEN sqa.is_correct = 1 THEN 100 ELSE 0 END) as avg_quiz_score
    FROM students s
    LEFT JOIN student_progress sp ON s.id = sp.student_id AND sp.last_accessed BETWEEN :start_date_range AND :end_date_range
    LEFT JOIN student_quiz_attempts sqa ON s.id = sqa.student_id AND sqa.attempted_at BETWEEN :start_date_range AND :end_date_range
    WHERE s.deleted_at IS NULL";
    
    $stmt = $conn->prepare($query);
    $stmt->execute([
        'start_date' => $start_date,
        'end_date' => $end_date,
        'start_date_range' => $start_date . ' 00:00:00',
        'end_date_range' => $end_date . ' 23:59:59'
    ]);
    
    $stats['overall'] = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // Daily activity trend
    $daily_query = "SELECT 
        DATE(sqa.attempted_at) as date,
        COUNT(DISTINCT sqa.student_id) as active_students,
        COUNT(sqa.id) as quiz_attempts,
        AVG(CASE WHEN sqa.is_correct = 1 THEN 100 ELSE 0 END) as avg_score
    FROM student_quiz_attempts sqa
    WHERE sqa.attempted_at BETWEEN :start_date AND :end_date
    GROUP BY DATE(sqa.attempted_at)
    ORDER BY date";
    
    $stmt = $conn->prepare($daily_query);
    $stmt->execute([
        'start_date' => $start_date . ' 00:00:00',
        'end_date' => $end_date . ' 23:59:59'
    ]);
    
    $stats['daily_activity'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Topic-wise statistics
    $topic_query = "SELECT 
        t.id,
        t.topic_name,
        COUNT(DISTINCT s.id) as total_students,
        COUNT(DISTINCT sp.student_id) as students_started,
        COUNT(DISTINCT sp.lesson_id) as lessons_started,
        COUNT(DISTINCT CASE WHEN sp.is_completed = 1 THEN sp.lesson_id END) as lessons_completed,
        AVG(CASE WHEN sp.is_completed = 1 THEN 1 ELSE 0 END) * 100 as completion_rate,
        COUNT(DISTINCT sqa.student_id) as quiz_participants,
        COUNT(sqa.id) as quiz_attempts,
        AVG(CASE WHEN sqa.is_correct = 1 THEN 100 ELSE 0 END) as avg_quiz_score
    FROM topics t
    LEFT JOIN lessons l ON t.id = l.topic_id AND l.deleted_at IS NULL
    LEFT JOIN student_progress sp ON l.id = sp.lesson_id
    LEFT JOIN students s ON sp.student_id = s.id AND s.deleted_at IS NULL
    LEFT JOIN quizzes q ON l.id = q.lesson_id
    LEFT JOIN student_quiz_attempts sqa ON q.id = sqa.quiz_id
    WHERE t.deleted_at IS NULL
    GROUP BY t.id
    ORDER BY t.topic_order";
    
    $stats['topics'] = $conn->query($topic_query)->fetchAll(PDO::FETCH_ASSOC);
    
    return $stats;
}

// Function to get student progress report
function getStudentProgressReport($conn, $start_date, $end_date, $topic_id = 'all') {
    $report = [];
    
    $where_clause = "WHERE s.deleted_at IS NULL";
    $params = [
        'start_date' => $start_date . ' 00:00:00',
        'end_date' => $end_date . ' 23:59:59'
    ];
    
    if ($topic_id != 'all') {
        $where_clause .= " AND t.id = :topic_id";
        $params['topic_id'] = $topic_id;
    }
    
    $query = "SELECT 
        s.id,
        s.username,
        s.email,
        s.full_name,
        s.created_at,
        s.last_active,
        s.total_score,
        COUNT(DISTINCT sp.lesson_id) as lessons_started,
        COUNT(DISTINCT CASE WHEN sp.is_completed = 1 THEN sp.lesson_id END) as lessons_completed,
        COUNT(DISTINCT t.id) as topics_started,
        COUNT(DISTINCT sqa.quiz_id) as quizzes_attempted,
        COUNT(sqa.id) as total_quiz_attempts,
        AVG(CASE WHEN sqa.is_correct = 1 THEN 100 ELSE 0 END) as avg_quiz_score,
        SUM(sqa.time_spent) as total_time_spent,
        MAX(sqa.attempted_at) as last_quiz_attempt
    FROM students s
    LEFT JOIN student_progress sp ON s.id = sp.student_id
    LEFT JOIN lessons l ON sp.lesson_id = l.id
    LEFT JOIN topics t ON l.topic_id = t.id
    LEFT JOIN student_quiz_attempts sqa ON s.id = sqa.student_id
    $where_clause
    GROUP BY s.id
    ORDER BY s.total_score DESC, lessons_completed DESC";
    
    $stmt = $conn->prepare($query);
    $stmt->execute($params);
    $report['students'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Calculate averages
    if (count($report['students']) > 0) {
        $total_students = count($report['students']);
        $total_score = 0;
        $total_completed = 0;
        $total_attempts = 0;
        
        foreach ($report['students'] as $student) {
            $total_score += $student['total_score'];
            $total_completed += $student['lessons_completed'];
            $total_attempts += $student['total_quiz_attempts'];
        }
        
        $report['averages'] = [
            'avg_score' => round($total_score / $total_students, 2),
            'avg_lessons_completed' => round($total_completed / $total_students, 1),
            'avg_quiz_attempts' => round($total_attempts / $total_students, 1)
        ];
    }
    
    return $report;
}

// Function to get quiz analytics
function getQuizAnalytics($conn, $start_date, $end_date) {
    $analytics = [];
    
    // Quiz performance by difficulty
    $difficulty_query = "SELECT 
        q.difficulty,
        COUNT(sqa.id) as total_attempts,
        SUM(CASE WHEN sqa.is_correct = 1 THEN 1 ELSE 0 END) as correct_attempts,
        AVG(CASE WHEN sqa.is_correct = 1 THEN 100 ELSE 0 END) as avg_score,
        AVG(sqa.time_spent) as avg_time_spent,
        COUNT(DISTINCT sqa.student_id) as unique_students
    FROM student_quiz_attempts sqa
    JOIN quizzes q ON sqa.quiz_id = q.id
    WHERE sqa.attempted_at BETWEEN :start_date AND :end_date
    GROUP BY q.difficulty
    ORDER BY FIELD(q.difficulty, 'easy', 'medium', 'hard')";
    
    $stmt = $conn->prepare($difficulty_query);
    $stmt->execute([
        'start_date' => $start_date . ' 00:00:00',
        'end_date' => $end_date . ' 23:59:59'
    ]);
    $analytics['by_difficulty'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Most challenging quizzes
    $challenging_query = "SELECT 
        q.id,
        q.question,
        l.lesson_title,
        t.topic_name,
        q.difficulty,
        COUNT(sqa.id) as total_attempts,
        AVG(CASE WHEN sqa.is_correct = 1 THEN 100 ELSE 0 END) as avg_score,
        (SELECT option_text FROM quiz_options WHERE quiz_id = q.id AND is_correct = 1 LIMIT 1) as correct_answer
    FROM student_quiz_attempts sqa
    JOIN quizzes q ON sqa.quiz_id = q.id
    JOIN lessons l ON q.lesson_id = l.id
    JOIN topics t ON l.topic_id = t.id
    WHERE sqa.attempted_at BETWEEN :start_date AND :end_date
    GROUP BY q.id
    HAVING total_attempts >= 5
    ORDER BY avg_score ASC
    LIMIT 10";
    
    $stmt = $conn->prepare($challenging_query);
    $stmt->execute([
        'start_date' => $start_date . ' 00:00:00',
        'end_date' => $end_date . ' 23:59:59'
    ]);
    $analytics['challenging_quizzes'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Quiz attempts over time
    $time_query = "SELECT 
        DATE(sqa.attempted_at) as date,
        COUNT(sqa.id) as daily_attempts,
        AVG(CASE WHEN sqa.is_correct = 1 THEN 100 ELSE 0 END) as daily_avg_score,
        COUNT(DISTINCT sqa.student_id) as daily_students
    FROM student_quiz_attempts sqa
    WHERE sqa.attempted_at BETWEEN :start_date AND :end_date
    GROUP BY DATE(sqa.attempted_at)
    ORDER BY date";
    
    $stmt = $conn->prepare($time_query);
    $stmt->execute([
        'start_date' => $start_date . ' 00:00:00',
        'end_date' => $end_date . ' 23:59:59'
    ]);
    $analytics['timeline'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Student ranking by quiz performance
    $ranking_query = "SELECT 
        s.id,
        s.username,
        s.full_name,
        COUNT(sqa.id) as quiz_attempts,
        SUM(CASE WHEN sqa.is_correct = 1 THEN 1 ELSE 0 END) as correct_answers,
        AVG(CASE WHEN sqa.is_correct = 1 THEN 100 ELSE 0 END) as accuracy_rate,
        AVG(sqa.time_spent) as avg_time_per_question,
        s.total_score
    FROM students s
    JOIN student_quiz_attempts sqa ON s.id = sqa.student_id
    WHERE sqa.attempted_at BETWEEN :start_date AND :end_date
    GROUP BY s.id
    HAVING quiz_attempts >= 5
    ORDER BY accuracy_rate DESC, correct_answers DESC
    LIMIT 20";
    
    $stmt = $conn->prepare($ranking_query);
    $stmt->execute([
        'start_date' => $start_date . ' 00:00:00',
        'end_date' => $end_date . ' 23:59:59'
    ]);
    $analytics['top_performers'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    return $analytics;
}

// Function to get detailed student report
function getDetailedStudentReport($conn, $student_id, $start_date, $end_date) {
    $report = [];
    
    // Student basic info
    $student_query = "SELECT * FROM students WHERE id = ? AND deleted_at IS NULL";
    $stmt = $conn->prepare($student_query);
    $stmt->execute([$student_id]);
    $report['student'] = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$report['student']) {
        return ['error' => 'Student not found'];
    }
    
    // Progress by topic
    $progress_query = "SELECT 
        t.id,
        t.topic_name,
        COUNT(DISTINCT l.id) as total_lessons,
        COUNT(DISTINCT sp.lesson_id) as lessons_started,
        COUNT(DISTINCT CASE WHEN sp.is_completed = 1 THEN sp.lesson_id END) as lessons_completed,
        MIN(sp.last_accessed) as first_access,
        MAX(sp.last_accessed) as last_access,
        AVG(CASE WHEN sp.is_completed = 1 THEN 1 ELSE 0 END) * 100 as completion_rate
    FROM topics t
    LEFT JOIN lessons l ON t.id = l.topic_id AND l.deleted_at IS NULL
    LEFT JOIN student_progress sp ON l.id = sp.lesson_id AND sp.student_id = ?
    WHERE t.deleted_at IS NULL
    GROUP BY t.id
    ORDER BY t.topic_order";
    
    $stmt = $conn->prepare($progress_query);
    $stmt->execute([$student_id]);
    $report['topic_progress'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Quiz performance
    $quiz_query = "SELECT 
        q.id,
        q.question,
        q.difficulty,
        l.lesson_title,
        t.topic_name,
        sqa.attempted_at,
        sqa.is_correct,
        sqa.time_spent,
        (SELECT option_text FROM quiz_options WHERE quiz_id = q.id AND is_correct = 1 LIMIT 1) as correct_answer,
        (SELECT option_text FROM quiz_options WHERE id = sqa.selected_option_id) as student_answer
    FROM student_quiz_attempts sqa
    JOIN quizzes q ON sqa.quiz_id = q.id
    JOIN lessons l ON q.lesson_id = l.id
    JOIN topics t ON l.topic_id = t.id
    WHERE sqa.student_id = ? AND sqa.attempted_at BETWEEN ? AND ?
    ORDER BY sqa.attempted_at DESC";
    
    $stmt = $conn->prepare($quiz_query);
    $stmt->execute([
        $student_id,
        $start_date . ' 00:00:00',
        $end_date . ' 23:59:59'
    ]);
    $report['quiz_history'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Calculate statistics
    $total_quizzes = count($report['quiz_history']);
    $correct_quizzes = 0;
    $total_time = 0;
    
    foreach ($report['quiz_history'] as $quiz) {
        if ($quiz['is_correct']) $correct_quizzes++;
        $total_time += $quiz['time_spent'];
    }
    
    $report['quiz_stats'] = [
        'total_attempts' => $total_quizzes,
        'correct_answers' => $correct_quizzes,
        'accuracy_rate' => $total_quizzes > 0 ? round(($correct_quizzes / $total_quizzes) * 100, 1) : 0,
        'avg_time_per_question' => $total_quizzes > 0 ? round($total_time / $total_quizzes, 1) : 0
    ];
    
    // Learning timeline
    $timeline_query = "SELECT 
        DATE(sp.last_accessed) as study_date,
        COUNT(DISTINCT sp.lesson_id) as lessons_accessed,
        SUM(CASE WHEN sp.is_completed = 1 THEN 1 ELSE 0 END) as lessons_completed,
        COUNT(DISTINCT t.id) as topics_accessed
    FROM student_progress sp
    JOIN lessons l ON sp.lesson_id = l.id
    JOIN topics t ON l.topic_id = t.id
    WHERE sp.student_id = ? AND sp.last_accessed BETWEEN ? AND ?
    GROUP BY DATE(sp.last_accessed)
    ORDER BY study_date";
    
    $stmt = $conn->prepare($timeline_query);
    $stmt->execute([
        $student_id,
        $start_date . ' 00:00:00',
        $end_date . ' 23:59:59'
    ]);
    $report['learning_timeline'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    return $report;
}

// Function to get engagement metrics
function getEngagementMetrics($conn, $start_date, $end_date) {
    $metrics = [];
    
    // Daily active users (DAU)
    $dau_query = "SELECT 
        DATE(last_active) as date,
        COUNT(DISTINCT id) as daily_active_users
    FROM students 
    WHERE last_active BETWEEN ? AND ? 
    AND deleted_at IS NULL
    GROUP BY DATE(last_active)
    ORDER BY date";
    
    $stmt = $conn->prepare($dau_query);
    $stmt->execute([$start_date . ' 00:00:00', $end_date . ' 23:59:59']);
    $metrics['dau'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Session duration analysis
    $session_query = "SELECT 
        student_id,
        DATE(attempted_at) as date,
        COUNT(*) as quiz_count,
        SUM(time_spent) as total_time_spent,
        AVG(time_spent) as avg_time_per_quiz
    FROM student_quiz_attempts 
    WHERE attempted_at BETWEEN ? AND ?
    GROUP BY student_id, DATE(attempted_at)
    ORDER BY total_time_spent DESC";
    
    $stmt = $conn->prepare($session_query);
    $stmt->execute([$start_date . ' 00:00:00', $end_date . ' 23:59:59']);
    $metrics['sessions'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Retention analysis
    $retention_query = "SELECT 
        s.id,
        s.username,
        s.created_at,
        s.last_active,
        DATEDIFF(CURDATE(), s.last_active) as days_since_last_activity,
        COUNT(DISTINCT sp.lesson_id) as total_lessons_started,
        COUNT(DISTINCT CASE WHEN sp.is_completed = 1 THEN sp.lesson_id END) as lessons_completed,
        s.total_score
    FROM students s
    LEFT JOIN student_progress sp ON s.id = sp.student_id
    WHERE s.deleted_at IS NULL
    GROUP BY s.id
    HAVING days_since_last_activity <= 30
    ORDER BY days_since_last_activity DESC";
    
    $metrics['retention'] = $conn->query($retention_query)->fetchAll(PDO::FETCH_ASSOC);
    
    // Learning pace analysis
    $pace_query = "SELECT 
        s.id,
        s.username,
        COUNT(DISTINCT sp.lesson_id) as total_lessons,
        COUNT(DISTINCT CASE WHEN sp.is_completed = 1 THEN sp.lesson_id END) as completed_lessons,
        DATEDIFF(MAX(sp.last_accessed), MIN(sp.last_accessed)) as learning_duration_days,
        CASE 
            WHEN COUNT(DISTINCT sp.lesson_id) = 0 THEN 'Not Started'
            WHEN COUNT(DISTINCT CASE WHEN sp.is_completed = 1 THEN sp.lesson_id END) / COUNT(DISTINCT sp.lesson_id) >= 0.8 THEN 'Fast Learner'
            WHEN COUNT(DISTINCT CASE WHEN sp.is_completed = 1 THEN sp.lesson_id END) / COUNT(DISTINCT sp.lesson_id) >= 0.5 THEN 'Steady Pace'
            ELSE 'Slow Progress'
        END as pace_category
    FROM students s
    LEFT JOIN student_progress sp ON s.id = sp.student_id
    WHERE s.deleted_at IS NULL
    GROUP BY s.id
    HAVING total_lessons > 0";
    
    $metrics['learning_pace'] = $conn->query($pace_query)->fetchAll(PDO::FETCH_ASSOC);
    
    return $metrics;
}

// Fetch data based on report type
$report_data = [];
$page_title = 'Reports';

switch ($report_type) {
    case 'overview':
        $report_data = getOverviewStats($conn, $start_date, $end_date);
        $page_title = 'Overview Dashboard';
        break;
        
    case 'student_progress':
        $report_data = getStudentProgressReport($conn, $start_date, $end_date, $topic_id);
        $page_title = 'Student Progress Report';
        break;
        
    case 'quiz_analytics':
        $report_data = getQuizAnalytics($conn, $start_date, $end_date);
        $page_title = 'Quiz Analytics';
        break;
        
    case 'student_detail':
        if ($student_id != 'all') {
            $report_data = getDetailedStudentReport($conn, $student_id, $start_date, $end_date);
            $page_title = 'Student Detail Report - ' . ($report_data['student']['username'] ?? 'Unknown');
        }
        break;
        
    case 'engagement':
        $report_data = getEngagementMetrics($conn, $start_date, $end_date);
        $page_title = 'Engagement Metrics';
        break;
}

// Fetch topics for dropdown
$topics_query = "SELECT id, topic_name FROM topics WHERE deleted_at IS NULL ORDER BY topic_order";
$topics = $conn->query($topics_query)->fetchAll(PDO::FETCH_ASSOC);

// Fetch students for dropdown
$students_query = "SELECT id, username, full_name FROM students WHERE deleted_at IS NULL ORDER BY username";
$students = $conn->query($students_query)->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $page_title; ?> - JS Tutorial Admin</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.4/css/dataTables.bootstrap5.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        .report-card {
            background: white;
            border-radius: 10px;
            padding: 20px;
            margin-bottom: 20px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
            border: 1px solid #e9ecef;
        }
        
        .stat-number {
            font-size: 2.5rem;
            font-weight: bold;
            line-height: 1;
        }
        
        .stat-label {
            font-size: 0.9rem;
            color: #6c757d;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        
        .stat-change {
            font-size: 0.85rem;
            font-weight: 500;
        }
        
        .stat-change.up {
            color: #28a745;
        }
        
        .stat-change.down {
            color: #dc3545;
        }
        
        .nav-tabs .nav-link {
            color: #495057;
            font-weight: 500;
            border: none;
            padding: 10px 20px;
        }
        
        .nav-tabs .nav-link.active {
            color: #4361ee;
            border-bottom: 3px solid #4361ee;
            background: transparent;
        }
        
        .metric-card {
            text-align: center;
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 15px;
        }
        
        .metric-value {
            font-size: 1.8rem;
            font-weight: bold;
            margin: 10px 0;
        }
        
        .metric-label {
            font-size: 0.85rem;
            color: #6c757d;
        }
        
        .progress-thin {
            height: 6px;
            margin: 8px 0;
        }
        
        .student-avatar {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: bold;
            font-size: 16px;
        }
        
        .trend-up {
            color: #28a745;
        }
        
        .trend-down {
            color: #dc3545;
        }
        
        .trend-neutral {
            color: #6c757d;
        }
        
        .chart-container {
            position: relative;
            height: 300px;
            margin-bottom: 20px;
        }
        
        .table-hover tbody tr:hover {
            background-color: rgba(67, 97, 238, 0.05);
        }
        
        .badge-difficulty {
            font-size: 0.75rem;
            padding: 3px 8px;
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
        
        .filter-card {
            background: #f8f9fa;
            border-radius: 8px;
            padding: 20px;
            margin-bottom: 20px;
        }
        
        .export-buttons {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
        }
        
        @media print {
            .no-print {
                display: none !important;
            }
            
            .report-card {
                box-shadow: none;
                border: 1px solid #dee2e6;
                page-break-inside: avoid;
            }
            
            .chart-container {
                page-break-inside: avoid;
            }
        }
    </style>
</head>
<body>
    <!-- Navigation -->
    <nav class="navbar navbar-expand-lg navbar-dark bg-primary">
        <div class="container">
            <a class="navbar-brand" href="#">
                <i class="fas fa-chart-bar me-2"></i> Reports Dashboard
            </a>
            <div class="navbar-nav ms-auto">
                <div class="dropdown">
                    <button class="btn btn-outline-light dropdown-toggle" type="button" 
                            data-bs-toggle="dropdown">
                        <div class="d-inline-flex align-items-center">
                            <div class="rounded-circle d-flex align-items-center justify-content-center me-2" 
                                 style="width: 30px; height: 30px; background: <?php echo $teacherSession->getAvatarColor(); ?>; color: white;">
                                <?php echo strtoupper(substr($teacherSession->getTeacherUsername(), 0, 1)); ?>
                            </div>
                            <?php echo htmlspecialchars($teacherSession->getTeacherUsername()); ?>
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
                        <li><a class="dropdown-item active" href="reports">
                            <i class="fas fa-chart-bar me-2"></i> Reports
                        </a></li>
                        <li><a class="dropdown-item" href="analytics">
                            <i class="fas fa-chart-line me-2"></i> Analytics
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
    
    <div class="container-fluid mt-4">
        <!-- Header -->
        <div class="d-flex justify-content-between align-items-center mb-4">
            <div>
                <h2 class="fw-bold">
                    <i class="fas fa-chart-bar me-2 text-primary"></i><?php echo $page_title; ?>
                </h2>
                <p class="text-muted mb-0">
                    <?php echo date('F d, Y', strtotime($start_date)); ?> to <?php echo date('F d, Y', strtotime($end_date)); ?>
                </p>
            </div>
            <div class="export-buttons no-print">
                <button class="btn btn-outline-primary" onclick="printReport()">
                    <i class="fas fa-print me-1"></i> Print
                </button>
                <button class="btn btn-outline-success" onclick="exportToExcel()">
                    <i class="fas fa-file-excel me-1"></i> Export Excel
                </button>
                <button class="btn btn-outline-secondary" onclick="exportToPDF()">
                    <i class="fas fa-file-pdf me-1"></i> Export PDF
                </button>
            </div>
        </div>
        
        <!-- Report Type Navigation -->
        <div class="card mb-4 no-print">
            <div class="card-body">
                <ul class="nav nav-tabs nav-fill" id="reportTabs" role="tablist">
                    <li class="nav-item" role="presentation">
                        <a class="nav-link <?php echo $report_type == 'overview' ? 'active' : ''; ?>" 
                           href="reports?report_type=overview&start_date=<?php echo $start_date; ?>&end_date=<?php echo $end_date; ?>">
                            <i class="fas fa-tachometer-alt me-2"></i> Overview
                        </a>
                    </li>
                    <li class="nav-item" role="presentation">
                        <a class="nav-link <?php echo $report_type == 'student_progress' ? 'active' : ''; ?>" 
                           href="reports?report_type=student_progress&start_date=<?php echo $start_date; ?>&end_date=<?php echo $end_date; ?>">
                            <i class="fas fa-user-graduate me-2"></i> Student Progress
                        </a>
                    </li>
                    <li class="nav-item" role="presentation">
                        <a class="nav-link <?php echo $report_type == 'quiz_analytics' ? 'active' : ''; ?>" 
                           href="reports?report_type=quiz_analytics&start_date=<?php echo $start_date; ?>&end_date=<?php echo $end_date; ?>">
                            <i class="fas fa-question-circle me-2"></i> Quiz Analytics
                        </a>
                    </li>
                    <li class="nav-item" role="presentation">
                        <a class="nav-link <?php echo $report_type == 'engagement' ? 'active' : ''; ?>" 
                           href="reports?report_type=engagement&start_date=<?php echo $start_date; ?>&end_date=<?php echo $end_date; ?>">
                            <i class="fas fa-chart-line me-2"></i> Engagement
                        </a>
                    </li>
                </ul>
            </div>
        </div>
        
        <!-- Filters -->
        <div class="filter-card no-print">
            <form method="GET" action="" class="row g-3">
                <input type="hidden" name="report_type" value="<?php echo $report_type; ?>">
                
                <div class="col-md-3">
                    <label class="form-label">Start Date</label>
                    <input type="date" class="form-control" name="start_date" 
                           value="<?php echo $start_date; ?>" required>
                </div>
                
                <div class="col-md-3">
                    <label class="form-label">End Date</label>
                    <input type="date" class="form-control" name="end_date" 
                           value="<?php echo $end_date; ?>" required>
                </div>
                
                <?php if ($report_type == 'student_progress'): ?>
                <div class="col-md-3">
                    <label class="form-label">Topic Filter</label>
                    <select class="form-select" name="topic_id">
                        <option value="all">All Topics</option>
                        <?php foreach ($topics as $topic): ?>
                        <option value="<?php echo $topic['id']; ?>" <?php echo $topic_id == $topic['id'] ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($topic['topic_name']); ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php endif; ?>
                
                <?php if ($report_type == 'student_detail'): ?>
                <div class="col-md-3">
                    <label class="form-label">Select Student</label>
                    <select class="form-select" name="student_id">
                        <option value="all">Select Student</option>
                        <?php foreach ($students as $student): ?>
                        <option value="<?php echo $student['id']; ?>" <?php echo $student_id == $student['id'] ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($student['username']); ?>
                            <?php if ($student['full_name']): ?>
                            (<?php echo htmlspecialchars($student['full_name']); ?>)
                            <?php endif; ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php endif; ?>
                
                <div class="col-md-3">
                    <label class="form-label">&nbsp;</label>
                    <div class="d-grid">
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-filter me-1"></i> Apply Filters
                        </button>
                    </div>
                </div>
                
                <div class="col-md-3">
                    <label class="form-label">Quick Range</label>
                    <div class="btn-group w-100">
                        <button type="button" class="btn btn-outline-primary btn-sm" onclick="setDateRange(7)">7D</button>
                        <button type="button" class="btn btn-outline-primary btn-sm" onclick="setDateRange(30)">30D</button>
                        <button type="button" class="btn btn-outline-primary btn-sm" onclick="setDateRange(90)">90D</button>
                        <button type="button" class="btn btn-outline-primary btn-sm" onclick="setDateRange(365)">1Y</button>
                    </div>
                </div>
            </form>
        </div>
        
        <!-- Report Content -->
        <div class="tab-content" id="reportContent">
            <?php if ($report_type == 'overview'): ?>
            <!-- Overview Report -->
            <div class="row mb-4">
                <!-- Key Metrics -->
                <div class="col-md-3">
                    <div class="report-card">
                        <div class="stat-label">Total Students</div>
                        <div class="stat-number"><?php echo $report_data['overall']['total_students'] ?? 0; ?></div>
                        <div class="stat-change up">
                            <i class="fas fa-user-plus me-1"></i>
                            +<?php echo $report_data['overall']['new_students'] ?? 0; ?> new
                        </div>
                    </div>
                </div>
                
                <div class="col-md-3">
                    <div class="report-card">
                        <div class="stat-label">Avg. Score</div>
                        <div class="stat-number"><?php echo round($report_data['overall']['avg_total_score'] ?? 0, 1); ?></div>
                        <div class="stat-label">points per student</div>
                    </div>
                </div>
                
                <div class="col-md-3">
                    <div class="report-card">
                        <div class="stat-label">Lessons Completed</div>
                        <div class="stat-number"><?php echo $report_data['overall']['total_lessons_completed'] ?? 0; ?></div>
                        <div class="stat-label">out of <?php echo $report_data['overall']['total_lessons_started'] ?? 0; ?> started</div>
                    </div>
                </div>
                
                <div class="col-md-3">
                    <div class="report-card">
                        <div class="stat-label">Quiz Accuracy</div>
                        <div class="stat-number"><?php echo round($report_data['overall']['avg_quiz_score'] ?? 0, 1); ?>%</div>
                        <div class="stat-label">from <?php echo $report_data['overall']['total_quiz_attempts'] ?? 0; ?> attempts</div>
                    </div>
                </div>
            </div>
            
            <!-- Charts -->
            <div class="row mb-4">
                <div class="col-md-8">
                    <div class="report-card">
                        <h5 class="fw-bold mb-3">Daily Activity Trend</h5>
                        <div class="chart-container">
                            <canvas id="dailyActivityChart"></canvas>
                        </div>
                    </div>
                </div>
                
                <div class="col-md-4">
                    <div class="report-card">
                        <h5 class="fw-bold mb-3">Active Students</h5>
                        <div class="chart-container">
                            <canvas id="activeStudentsChart"></canvas>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Topic Performance -->
            <div class="report-card">
                <h5 class="fw-bold mb-3">Topic Performance Overview</h5>
                <div class="table-responsive">
                    <table class="table table-hover">
                        <thead>
                            <tr>
                                <th>Topic</th>
                                <th>Students</th>
                                <th>Completion Rate</th>
                                <th>Avg. Quiz Score</th>
                                <th>Performance</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (isset($report_data['topics'])): ?>
                            <?php foreach ($report_data['topics'] as $topic): 
                                $completion_rate = $topic['completion_rate'] ?? 0;
                                $quiz_score = $topic['avg_quiz_score'] ?? 0;
                            ?>
                            <tr>
                                <td>
                                    <strong><?php echo htmlspecialchars($topic['topic_name']); ?></strong>
                                </td>
                                <td>
                                    <?php echo $topic['students_started'] ?? 0; ?> / <?php echo $topic['total_students'] ?? 0; ?>
                                    <div class="progress progress-thin">
                                        <div class="progress-bar" style="width: <?php echo ($topic['students_started'] / max($topic['total_students'], 1)) * 100; ?>%"></div>
                                    </div>
                                </td>
                                <td>
                                    <div class="d-flex align-items-center">
                                        <div class="me-2"><?php echo round($completion_rate, 1); ?>%</div>
                                        <div class="progress progress-thin flex-grow-1">
                                            <div class="progress-bar bg-success" style="width: <?php echo $completion_rate; ?>%"></div>
                                        </div>
                                    </div>
                                </td>
                                <td>
                                    <div class="d-flex align-items-center">
                                        <div class="me-2"><?php echo round($quiz_score, 1); ?>%</div>
                                        <div class="progress progress-thin flex-grow-1">
                                            <div class="progress-bar bg-info" style="width: <?php echo $quiz_score; ?>%"></div>
                                        </div>
                                    </div>
                                </td>
                                <td>
                                    <?php if ($quiz_score >= 80): ?>
                                    <span class="badge bg-success">Excellent</span>
                                    <?php elseif ($quiz_score >= 60): ?>
                                    <span class="badge bg-warning text-dark">Good</span>
                                    <?php else: ?>
                                    <span class="badge bg-danger">Needs Review</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                            <?php else: ?>
                            <tr>
                                <td colspan="5" class="text-center py-4">
                                    <i class="fas fa-chart-bar fa-2x text-muted mb-3"></i>
                                    <p class="text-muted">No topic data available</p>
                                </td>
                            </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            
            <?php elseif ($report_type == 'student_progress'): ?>
            <!-- Student Progress Report -->
            <div class="report-card">
                <h5 class="fw-bold mb-3">Student Progress Report</h5>
                <?php if (isset($report_data['averages'])): ?>
                <div class="row mb-4">
                    <div class="col-md-4">
                        <div class="metric-card bg-light">
                            <div class="metric-value"><?php echo $report_data['averages']['avg_score']; ?></div>
                            <div class="metric-label">Average Score</div>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="metric-card bg-light">
                            <div class="metric-value"><?php echo $report_data['averages']['avg_lessons_completed']; ?></div>
                            <div class="metric-label">Avg. Lessons Completed</div>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="metric-card bg-light">
                            <div class="metric-value"><?php echo $report_data['averages']['avg_quiz_attempts']; ?></div>
                            <div class="metric-label">Avg. Quiz Attempts</div>
                        </div>
                    </div>
                </div>
                <?php endif; ?>
                
                <div class="table-responsive">
                    <table class="table table-hover" id="studentProgressTable">
                        <thead>
                            <tr>
                                <th>Student</th>
                                <th>Joined</th>
                                <th>Last Active</th>
                                <th>Total Score</th>
                                <th>Progress</th>
                                <th>Quiz Performance</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (isset($report_data['students']) && count($report_data['students']) > 0): ?>
                            <?php foreach ($report_data['students'] as $student): 
                                $completion_rate = $student['lessons_started'] > 0 
                                    ? round(($student['lessons_completed'] / $student['lessons_started']) * 100, 1) 
                                    : 0;
                            ?>
                            <tr>
                                <td>
                                    <div class="d-flex align-items-center">
                                        <div class="student-avatar me-3" 
                                             style="background: <?php echo getAvatarColor($student['username']); ?>">
                                            <?php echo strtoupper(substr($student['username'], 0, 1)); ?>
                                        </div>
                                        <div>
                                            <strong><?php echo htmlspecialchars($student['username']); ?></strong>
                                            <div class="text-muted small">
                                                <?php echo htmlspecialchars($student['email']); ?>
                                                <?php if ($student['full_name']): ?>
                                                <br><?php echo htmlspecialchars($student['full_name']); ?>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    </div>
                                </td>
                                <td>
                                    <?php echo date('M d, Y', strtotime($student['created_at'])); ?>
                                </td>
                                <td>
                                    <?php if ($student['last_active']): ?>
                                    <?php echo date('M d, Y', strtotime($student['last_active'])); ?>
                                    <div class="text-muted small">
                                        <?php 
                                        $last_active = strtotime($student['last_active']);
                                        $now = time();
                                        $diff = $now - $last_active;
                                        
                                        if ($diff < 3600) echo 'Just now';
                                        elseif ($diff < 86400) echo floor($diff / 3600) . ' hours ago';
                                        elseif ($diff < 604800) echo floor($diff / 86400) . ' days ago';
                                        else echo 'Over a week ago';
                                        ?>
                                    </div>
                                    <?php else: ?>
                                    <span class="text-muted">Never</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <span class="badge bg-warning text-dark">
                                        <i class="fas fa-star me-1"></i>
                                        <?php echo $student['total_score']; ?> pts
                                    </span>
                                </td>
                                <td>
                                    <div class="mb-1">
                                        <small class="text-muted">
                                            <?php echo $student['lessons_completed']; ?>/<?php echo $student['lessons_started']; ?> lessons
                                        </small>
                                    </div>
                                    <div class="progress progress-thin">
                                        <div class="progress-bar" style="width: <?php echo $completion_rate; ?>%"></div>
                                    </div>
                                    <small class="text-muted"><?php echo $completion_rate; ?>% complete</small>
                                </td>
                                <td>
                                    <?php if ($student['quizzes_attempted'] > 0): ?>
                                    <div class="mb-1">
                                        <strong><?php echo round($student['avg_quiz_score'], 1); ?>%</strong>
                                        <small class="text-muted"> accuracy</small>
                                    </div>
                                    <div class="text-muted small">
                                        <?php echo $student['total_quiz_attempts']; ?> attempts
                                    </div>
                                    <?php else: ?>
                                    <span class="text-muted">No attempts</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <a href="reports?report_type=student_detail&student_id=<?php echo $student['id']; ?>&start_date=<?php echo $start_date; ?>&end_date=<?php echo $end_date; ?>" 
                                       class="btn btn-sm btn-outline-primary">
                                        <i class="fas fa-chart-line"></i> Details
                                    </a>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                            <?php else: ?>
                            <tr>
                                <td colspan="7" class="text-center py-5">
                                    <i class="fas fa-users fa-3x text-muted mb-3"></i>
                                    <h5>No Student Data</h5>
                                    <p class="text-muted">No student progress data available for the selected period</p>
                                </td>
                            </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            
            <?php elseif ($report_type == 'quiz_analytics'): ?>
            <!-- Quiz Analytics Report -->
            <div class="row mb-4">
                <div class="col-md-6">
                    <div class="report-card">
                        <h5 class="fw-bold mb-3">Performance by Difficulty</h5>
                        <div class="chart-container">
                            <canvas id="difficultyChart"></canvas>
                        </div>
                    </div>
                </div>
                
                <div class="col-md-6">
                    <div class="report-card">
                        <h5 class="fw-bold mb-3">Quiz Attempts Timeline</h5>
                        <div class="chart-container">
                            <canvas id="quizTimelineChart"></canvas>
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="report-card mb-4">
                <h5 class="fw-bold mb-3">Most Challenging Quizzes</h5>
                <div class="table-responsive">
                    <table class="table table-hover">
                        <thead>
                            <tr>
                                <th>Question</th>
                                <th>Topic & Lesson</th>
                                <th>Difficulty</th>
                                <th>Attempts</th>
                                <th>Avg. Score</th>
                                <th>Correct Answer</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (isset($report_data['challenging_quizzes']) && count($report_data['challenging_quizzes']) > 0): ?>
                            <?php foreach ($report_data['challenging_quizzes'] as $quiz): ?>
                            <tr>
                                <td><?php echo htmlspecialchars(substr($quiz['question'], 0, 100)); ?><?php echo strlen($quiz['question']) > 100 ? '...' : ''; ?></td>
                                <td>
                                    <strong><?php echo htmlspecialchars($quiz['topic_name']); ?></strong><br>
                                    <small class="text-muted"><?php echo htmlspecialchars($quiz['lesson_title']); ?></small>
                                </td>
                                <td>
                                    <span class="badge badge-difficulty difficulty-<?php echo $quiz['difficulty']; ?>">
                                        <?php echo ucfirst($quiz['difficulty']); ?>
                                    </span>
                                </td>
                                <td><?php echo $quiz['total_attempts']; ?></td>
                                <td>
                                    <div class="d-flex align-items-center">
                                        <div class="me-2"><?php echo round($quiz['avg_score'], 1); ?>%</div>
                                        <div class="progress progress-thin flex-grow-1">
                                            <div class="progress-bar bg-info" style="width: <?php echo $quiz['avg_score']; ?>%"></div>
                                        </div>
                                    </div>
                                </td>
                                <td>
                                    <small><?php echo htmlspecialchars(substr($quiz['correct_answer'], 0, 50)); ?><?php echo strlen($quiz['correct_answer']) > 50 ? '...' : ''; ?></small>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                            <?php else: ?>
                            <tr>
                                <td colspan="6" class="text-center py-4">
                                    <i class="fas fa-question-circle fa-2x text-muted mb-3"></i>
                                    <p class="text-muted">No challenging quiz data available</p>
                                </td>
                            </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            
            <div class="report-card">
                <h5 class="fw-bold mb-3">Top Performing Students</h5>
                <div class="table-responsive">
                    <table class="table table-hover">
                        <thead>
                            <tr>
                                <th>Rank</th>
                                <th>Student</th>
                                <th>Accuracy Rate</th>
                                <th>Quiz Attempts</th>
                                <th>Avg. Time/Question</th>
                                <th>Total Score</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (isset($report_data['top_performers']) && count($report_data['top_performers']) > 0): ?>
                            <?php foreach ($report_data['top_performers'] as $index => $student): ?>
                            <tr>
                                <td>
                                    <span class="badge bg-primary rounded-circle" style="width: 30px; height: 30px; line-height: 30px; display: inline-block;">
                                        <?php echo $index + 1; ?>
                                    </span>
                                </td>
                                <td>
                                    <div class="d-flex align-items-center">
                                        <div class="student-avatar me-3" 
                                             style="background: <?php echo getAvatarColor($student['username']); ?>">
                                            <?php echo strtoupper(substr($student['username'], 0, 1)); ?>
                                        </div>
                                        <div>
                                            <strong><?php echo htmlspecialchars($student['username']); ?></strong>
                                            <?php if ($student['full_name']): ?>
                                            <div class="text-muted small"><?php echo htmlspecialchars($student['full_name']); ?></div>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </td>
                                <td>
                                    <div class="d-flex align-items-center">
                                        <div class="me-2"><?php echo round($student['accuracy_rate'], 1); ?>%</div>
                                        <div class="progress progress-thin flex-grow-1">
                                            <div class="progress-bar bg-success" style="width: <?php echo $student['accuracy_rate']; ?>%"></div>
                                        </div>
                                    </div>
                                </td>
                                <td>
                                    <?php echo $student['quiz_attempts']; ?> attempts<br>
                                    <small class="text-muted"><?php echo $student['correct_answers']; ?> correct</small>
                                </td>
                                <td><?php echo round($student['avg_time_per_question'], 1); ?>s</td>
                                <td>
                                    <span class="badge bg-warning text-dark">
                                        <i class="fas fa-star me-1"></i>
                                        <?php echo $student['total_score']; ?> pts
                                    </span>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                            <?php else: ?>
                            <tr>
                                <td colspan="6" class="text-center py-4">
                                    <i class="fas fa-trophy fa-2x text-muted mb-3"></i>
                                    <p class="text-muted">No top performer data available</p>
                                </td>
                            </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            
            <?php elseif ($report_type == 'student_detail' && isset($report_data['student'])): ?>
            <!-- Student Detail Report -->
            <div class="report-card mb-4">
                <div class="d-flex justify-content-between align-items-start">
                    <div class="d-flex align-items-center">
                        <div class="student-avatar me-4" style="width: 80px; height: 80px; font-size: 32px; background: <?php echo getAvatarColor($report_data['student']['username']); ?>">
                            <?php echo strtoupper(substr($report_data['student']['username'], 0, 1)); ?>
                        </div>
                        <div>
                            <h3 class="fw-bold mb-1"><?php echo htmlspecialchars($report_data['student']['full_name'] ?? $report_data['student']['username']); ?></h3>
                            <p class="text-muted mb-1"><?php echo htmlspecialchars($report_data['student']['email']); ?></p>
                            <div class="d-flex gap-3">
                                <div>
                                    <small class="text-muted">Joined</small>
                                    <div class="fw-bold"><?php echo date('M d, Y', strtotime($report_data['student']['created_at'])); ?></div>
                                </div>
                                <div>
                                    <small class="text-muted">Last Active</small>
                                    <div class="fw-bold">
                                        <?php echo $report_data['student']['last_active'] ? date('M d, Y H:i', strtotime($report_data['student']['last_active'])) : 'Never'; ?>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="text-end">
                        <div class="display-4 fw-bold text-primary"><?php echo $report_data['student']['total_score']; ?></div>
                        <div class="text-muted">Total Score</div>
                    </div>
                </div>
            </div>
            
            <!-- Quick Stats -->
            <div class="row mb-4">
                <div class="col-md-3">
                    <div class="metric-card bg-primary text-white">
                        <div class="metric-value"><?php echo $report_data['quiz_stats']['accuracy_rate']; ?>%</div>
                        <div class="metric-label">Quiz Accuracy</div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="metric-card bg-success text-white">
                        <div class="metric-value"><?php echo $report_data['quiz_stats']['total_attempts']; ?></div>
                        <div class="metric-label">Quiz Attempts</div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="metric-card bg-info text-white">
                        <div class="metric-value"><?php echo $report_data['quiz_stats']['correct_answers']; ?></div>
                        <div class="metric-label">Correct Answers</div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="metric-card bg-warning text-white">
                        <div class="metric-value"><?php echo $report_data['quiz_stats']['avg_time_per_question']; ?>s</div>
                        <div class="metric-label">Avg. Time/Question</div>
                    </div>
                </div>
            </div>
            
            <!-- Topic Progress -->
            <div class="report-card mb-4">
                <h5 class="fw-bold mb-3">Topic Progress</h5>
                <div class="table-responsive">
                    <table class="table table-hover">
                        <thead>
                            <tr>
                                <th>Topic</th>
                                <th>Lessons</th>
                                <th>Completion</th>
                                <th>First Access</th>
                                <th>Last Access</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (isset($report_data['topic_progress'])): ?>
                            <?php foreach ($report_data['topic_progress'] as $topic): ?>
                            <tr>
                                <td><strong><?php echo htmlspecialchars($topic['topic_name']); ?></strong></td>
                                <td>
                                    <?php echo $topic['lessons_completed']; ?>/<?php echo $topic['total_lessons']; ?>
                                    <div class="progress progress-thin">
                                        <div class="progress-bar" style="width: <?php echo ($topic['lessons_completed'] / max($topic['total_lessons'], 1)) * 100; ?>%"></div>
                                    </div>
                                </td>
                                <td>
                                    <span class="badge <?php echo $topic['completion_rate'] >= 80 ? 'bg-success' : ($topic['completion_rate'] >= 50 ? 'bg-warning text-dark' : 'bg-danger'); ?>">
                                        <?php echo round($topic['completion_rate'], 1); ?>%
                                    </span>
                                </td>
                                <td>
                                    <?php echo $topic['first_access'] ? date('M d, Y', strtotime($topic['first_access'])) : 'Not started'; ?>
                                </td>
                                <td>
                                    <?php echo $topic['last_access'] ? date('M d, Y', strtotime($topic['last_access'])) : 'Not started'; ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            
            <!-- Quiz History -->
            <div class="report-card">
                <h5 class="fw-bold mb-3">Quiz History</h5>
                <div class="table-responsive">
                    <table class="table table-hover">
                        <thead>
                            <tr>
                                <th>Date & Time</th>
                                <th>Question</th>
                                <th>Topic & Lesson</th>
                                <th>Difficulty</th>
                                <th>Result</th>
                                <th>Time Spent</th>
                                <th>Student Answer</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (isset($report_data['quiz_history']) && count($report_data['quiz_history']) > 0): ?>
                            <?php foreach ($report_data['quiz_history'] as $quiz): ?>
                            <tr>
                                <td><?php echo date('M d, Y H:i', strtotime($quiz['attempted_at'])); ?></td>
                                <td><?php echo htmlspecialchars(substr($quiz['question'], 0, 100)); ?><?php echo strlen($quiz['question']) > 100 ? '...' : ''; ?></td>
                                <td>
                                    <small class="text-muted"><?php echo htmlspecialchars($quiz['topic_name']); ?></small><br>
                                    <?php echo htmlspecialchars($quiz['lesson_title']); ?>
                                </td>
                                <td>
                                    <span class="badge badge-difficulty difficulty-<?php echo $quiz['difficulty']; ?>">
                                        <?php echo ucfirst($quiz['difficulty']); ?>
                                    </span>
                                </td>
                                <td>
                                    <?php if ($quiz['is_correct']): ?>
                                    <span class="badge bg-success">
                                        <i class="fas fa-check me-1"></i> Correct
                                    </span>
                                    <?php else: ?>
                                    <span class="badge bg-danger">
                                        <i class="fas fa-times me-1"></i> Incorrect
                                    </span>
                                    <?php endif; ?>
                                </td>
                                <td><?php echo $quiz['time_spent']; ?>s</td>
                                <td>
                                    <small><?php echo htmlspecialchars(substr($quiz['student_answer'], 0, 50)); ?><?php echo strlen($quiz['student_answer']) > 50 ? '...' : ''; ?></small><br>
                                    <small class="text-muted">
                                        Correct: <?php echo htmlspecialchars(substr($quiz['correct_answer'], 0, 50)); ?><?php echo strlen($quiz['correct_answer']) > 50 ? '...' : ''; ?>
                                    </small>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                            <?php else: ?>
                            <tr>
                                <td colspan="7" class="text-center py-4">
                                    <i class="fas fa-history fa-2x text-muted mb-3"></i>
                                    <p class="text-muted">No quiz history available for the selected period</p>
                                </td>
                            </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            
            <?php elseif ($report_type == 'engagement'): ?>
            <!-- Engagement Metrics -->
            <div class="row mb-4">
                <div class="col-md-8">
                    <div class="report-card">
                        <h5 class="fw-bold mb-3">Daily Active Users</h5>
                        <div class="chart-container">
                            <canvas id="dauChart"></canvas>
                        </div>
                    </div>
                </div>
                
                <div class="col-md-4">
                    <div class="report-card">
                        <h5 class="fw-bold mb-3">Learning Pace Distribution</h5>
                        <div class="chart-container">
                            <canvas id="paceChart"></canvas>
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="report-card mb-4">
                <h5 class="fw-bold mb-3">Student Retention Analysis</h5>
                <div class="table-responsive">
                    <table class="table table-hover">
                        <thead>
                            <tr>
                                <th>Student</th>
                                <th>Joined</th>
                                <th>Last Active</th>
                                <th>Days Inactive</th>
                                <th>Progress</th>
                                <th>Total Score</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (isset($report_data['retention']) && count($report_data['retention']) > 0): ?>
                            <?php foreach ($report_data['retention'] as $student): ?>
                            <tr>
                                <td>
                                    <div class="d-flex align-items-center">
                                        <div class="student-avatar me-3" 
                                             style="background: <?php echo getAvatarColor($student['username']); ?>">
                                            <?php echo strtoupper(substr($student['username'], 0, 1)); ?>
                                        </div>
                                        <div>
                                            <strong><?php echo htmlspecialchars($student['username']); ?></strong>
                                        </div>
                                    </div>
                                </td>
                                <td><?php echo date('M d, Y', strtotime($student['created_at'])); ?></td>
                                <td><?php echo date('M d, Y', strtotime($student['last_active'])); ?></td>
                                <td>
                                    <span class="badge <?php echo $student['days_since_last_activity'] > 7 ? 'bg-danger' : 'bg-success'; ?>">
                                        <?php echo $student['days_since_last_activity']; ?> days
                                    </span>
                                </td>
                                <td>
                                    <?php echo $student['lessons_completed']; ?> lessons completed
                                </td>
                                <td><?php echo $student['total_score']; ?> points</td>
                                <td>
                                    <?php if ($student['days_since_last_activity'] <= 1): ?>
                                    <span class="badge bg-success">Highly Active</span>
                                    <?php elseif ($student['days_since_last_activity'] <= 7): ?>
                                    <span class="badge bg-primary">Active</span>
                                    <?php elseif ($student['days_since_last_activity'] <= 14): ?>
                                    <span class="badge bg-warning text-dark">Inactive</span>
                                    <?php else: ?>
                                    <span class="badge bg-danger">At Risk</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                            <?php else: ?>
                            <tr>
                                <td colspan="7" class="text-center py-4">
                                    <i class="fas fa-user-clock fa-2x text-muted mb-3"></i>
                                    <p class="text-muted">No retention data available</p>
                                </td>
                            </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            
            <?php endif; ?>
        </div>
        
        <!-- Footer -->
        <div class="text-center text-muted mt-5 py-4 border-top no-print">
            <small>
                Report generated on <?php echo date('F d, Y H:i:s'); ?> by <?php echo htmlspecialchars($teacherSession->getTeacherUsername()); ?>
                <br>
                URUScript Tutorial Platform &copy; <?php echo date('Y'); ?>
            </small>
        </div>
    </div>
    
    <script src="https://code.jquery.com/jquery-3.6.4.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.4/js/jquery.dataTables.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.4/js/dataTables.bootstrap5.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>
    <script>
        // Initialize DataTables
        $(document).ready(function() {
            $('#studentProgressTable').DataTable({
                pageLength: 10,
                lengthMenu: [[10, 25, 50, -1], [10, 25, 50, 'All']],
                order: [[3, 'desc']] // Sort by total score by default
            });
        });
        
        // Set date range
        function setDateRange(days) {
            const endDate = new Date().toISOString().split('T')[0];
            const startDate = new Date();
            startDate.setDate(startDate.getDate() - days);
            const startDateStr = startDate.toISOString().split('T')[0];
            
            const url = new URL(window.location.href);
            url.searchParams.set('start_date', startDateStr);
            url.searchParams.set('end_date', endDate);
            window.location.href = url.toString();
        }
        
        // Print report
        function printReport() {
            window.print();
        }
        
        // Export to Excel
        function exportToExcel() {
            // Get current table data
            let table = document.querySelector('.table');
            if (!table) {
                alert('No table data to export');
                return;
            }
            
            let html = table.outerHTML;
            let blob = new Blob(['\ufeff', html], {
                type: 'application/vnd.ms-excel'
            });
            let url = URL.createObjectURL(blob);
            let a = document.createElement('a');
            a.href = url;
            a.download = 'report_<?php echo date('Y-m-d'); ?>.xls';
            document.body.appendChild(a);
            a.click();
            document.body.removeChild(a);
        }
        
        // Export to PDF (simplified - would need a proper library for full implementation)
        function exportToPDF() {
            alert('PDF export would require additional libraries like jsPDF. For now, use Print or Excel export.');
        }
        
        // Charts for Overview Report
        <?php if ($report_type == 'overview' && isset($report_data['daily_activity'])): ?>
        // Daily Activity Chart
        const dailyCtx = document.getElementById('dailyActivityChart').getContext('2d');
        const dailyChart = new Chart(dailyCtx, {
            type: 'line',
            data: {
                labels: <?php echo json_encode(array_column($report_data['daily_activity'], 'date')); ?>,
                datasets: [
                    {
                        label: 'Active Students',
                        data: <?php echo json_encode(array_column($report_data['daily_activity'], 'active_students')); ?>,
                        borderColor: '#4361ee',
                        backgroundColor: 'rgba(67, 97, 238, 0.1)',
                        fill: true,
                        tension: 0.4
                    },
                    {
                        label: 'Quiz Attempts',
                        data: <?php echo json_encode(array_column($report_data['daily_activity'], 'quiz_attempts')); ?>,
                        borderColor: '#4cc9f0',
                        backgroundColor: 'rgba(76, 201, 240, 0.1)',
                        fill: true,
                        tension: 0.4
                    }
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        position: 'top'
                    }
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        title: {
                            display: true,
                            text: 'Count'
                        }
                    }
                }
            }
        });
        
        // Active Students Chart
        const activeCtx = document.getElementById('activeStudentsChart').getContext('2d');
        const activeChart = new Chart(activeCtx, {
            type: 'doughnut',
            data: {
                labels: ['Active (7 days)', 'Inactive'],
                datasets: [{
                    data: [
                        <?php echo $report_data['overall']['active_last_7_days'] ?? 0; ?>,
                        <?php echo max(0, ($report_data['overall']['total_students'] ?? 0) - ($report_data['overall']['active_last_7_days'] ?? 0)); ?>
                    ],
                    backgroundColor: ['#28a745', '#dc3545'],
                    borderWidth: 1
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        position: 'bottom'
                    }
                }
            }
        });
        <?php endif; ?>
        
        <?php if ($report_type == 'quiz_analytics'): ?>
        // Difficulty Chart
        const difficultyCtx = document.getElementById('difficultyChart').getContext('2d');
        const difficultyChart = new Chart(difficultyCtx, {
            type: 'bar',
            data: {
                labels: ['Easy', 'Medium', 'Hard'],
                datasets: [
                    {
                        label: 'Average Score %',
                        data: [
                            <?php 
                            $easy = 0; $medium = 0; $hard = 0;
                            if (isset($report_data['by_difficulty'])) {
                                foreach ($report_data['by_difficulty'] as $diff) {
                                    if ($diff['difficulty'] == 'easy') $easy = $diff['avg_score'];
                                    if ($diff['difficulty'] == 'medium') $medium = $diff['avg_score'];
                                    if ($diff['difficulty'] == 'hard') $hard = $diff['avg_score'];
                                }
                            }
                            echo "$easy, $medium, $hard";
                            ?>
                        ],
                        backgroundColor: ['#28a745', '#ffc107', '#dc3545']
                    }
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                scales: {
                    y: {
                        beginAtZero: true,
                        max: 100,
                        title: {
                            display: true,
                            text: 'Score %'
                        }
                    }
                },
                plugins: {
                    legend: {
                        display: false
                    }
                }
            }
        });
        
        // Quiz Timeline Chart
        const timelineCtx = document.getElementById('quizTimelineChart').getContext('2d');
        const timelineChart = new Chart(timelineCtx, {
            type: 'line',
            data: {
                labels: <?php echo json_encode(array_column($report_data['timeline'], 'date')); ?>,
                datasets: [
                    {
                        label: 'Quiz Attempts',
                        data: <?php echo json_encode(array_column($report_data['timeline'], 'daily_attempts')); ?>,
                        borderColor: '#4361ee',
                        backgroundColor: 'rgba(67, 97, 238, 0.1)',
                        fill: true,
                        tension: 0.4
                    },
                    {
                        label: 'Avg. Score',
                        data: <?php echo json_encode(array_column($report_data['timeline'], 'daily_avg_score')); ?>,
                        borderColor: '#4cc9f0',
                        backgroundColor: 'rgba(76, 201, 240, 0.1)',
                        fill: true,
                        tension: 0.4,
                        yAxisID: 'y1'
                    }
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                scales: {
                    y: {
                        beginAtZero: true,
                        title: {
                            display: true,
                            text: 'Attempts'
                        }
                    },
                    y1: {
                        position: 'right',
                        beginAtZero: true,
                        max: 100,
                        title: {
                            display: true,
                            text: 'Score %'
                        },
                        grid: {
                            drawOnChartArea: false
                        }
                    }
                }
            }
        });
        <?php endif; ?>
        
        <?php if ($report_type == 'engagement'): ?>
        // DAU Chart
        const dauCtx = document.getElementById('dauChart').getContext('2d');
        const dauChart = new Chart(dauCtx, {
            type: 'line',
            data: {
                labels: <?php echo json_encode(array_column($report_data['dau'], 'date')); ?>,
                datasets: [{
                    label: 'Daily Active Users',
                    data: <?php echo json_encode(array_column($report_data['dau'], 'daily_active_users')); ?>,
                    borderColor: '#4361ee',
                    backgroundColor: 'rgba(67, 97, 238, 0.1)',
                    fill: true,
                    tension: 0.4
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                scales: {
                    y: {
                        beginAtZero: true,
                        title: {
                            display: true,
                            text: 'Active Users'
                        }
                    }
                }
            }
        });
        
        // Pace Chart
        const paceCtx = document.getElementById('paceChart').getContext('2d');
        const paceChart = new Chart(paceCtx, {
            type: 'pie',
            data: {
                labels: ['Fast Learner', 'Steady Pace', 'Slow Progress', 'Not Started'],
                datasets: [{
                    data: [
                        <?php
                        $fast = 0; $steady = 0; $slow = 0; $not_started = 0;
                        if (isset($report_data['learning_pace'])) {
                            foreach ($report_data['learning_pace'] as $pace) {
                                if ($pace['pace_category'] == 'Fast Learner') $fast++;
                                elseif ($pace['pace_category'] == 'Steady Pace') $steady++;
                                elseif ($pace['pace_category'] == 'Slow Progress') $slow++;
                                else $not_started++;
                            }
                        }
                        echo "$fast, $steady, $slow, $not_started";
                        ?>
                    ],
                    backgroundColor: ['#28a745', '#ffc107', '#dc3545', '#6c757d']
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        position: 'bottom'
                    }
                }
            }
        });
        <?php endif; ?>
    </script>
</body>
</html>

<?php
// Helper function to generate avatar color based on username
function getAvatarColor($username) {
    $colors = ['#4361ee', '#3a0ca3', '#7209b7', '#f72585', '#4cc9f0', '#4895ef', '#560bad', '#b5179e'];
    $index = crc32($username) % count($colors);
    return $colors[$index];
}
?>