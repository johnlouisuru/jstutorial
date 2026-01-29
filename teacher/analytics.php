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

// Set timezone for date calculations
date_default_timezone_set('Asia/Manila');

// Get date range from GET or default to last 30 days
$start_date = isset($_GET['start_date']) ? $_GET['start_date'] : date('Y-m-d', strtotime('-30 days'));
$end_date = isset($_GET['end_date']) ? $_GET['end_date'] : date('Y-m-d');

// Analytics Functions
function getOverallStats($conn) {
    try {
        $stats = [];
        
        // Student Statistics
        $student_query = "SELECT 
            COUNT(*) as total_students,
            SUM(CASE WHEN is_active = 1 THEN 1 ELSE 0 END) as active_students,
            SUM(CASE WHEN DATE(created_at) = CURDATE() THEN 1 ELSE 0 END) as new_today,
            AVG(total_score) as avg_score
        FROM students WHERE deleted_at IS NULL";
        
        $student_stats = $conn->query($student_query)->fetch(PDO::FETCH_ASSOC);
        
        // Progress Statistics
        $progress_query = "SELECT 
            COUNT(DISTINCT sp.student_id) as students_with_progress,
            COUNT(DISTINCT sp.lesson_id) as lessons_started,
            SUM(CASE WHEN sp.is_completed = 1 THEN 1 ELSE 0 END) as lessons_completed,
            AVG(CASE WHEN sp.is_completed = 1 THEN 1 ELSE 0 END) * 100 as completion_rate
        FROM student_progress sp
        JOIN students s ON sp.student_id = s.id AND s.deleted_at IS NULL";
        
        $progress_stats = $conn->query($progress_query)->fetch(PDO::FETCH_ASSOC);
        
        // Quiz Statistics
        $quiz_query = "SELECT 
            COUNT(*) as total_attempts,
            AVG(CASE WHEN is_correct = 1 THEN 100 ELSE 0 END) as avg_quiz_score,
            COUNT(DISTINCT student_id) as students_attempted
        FROM student_quiz_attempts sqa
        JOIN students s ON sqa.student_id = s.id AND s.deleted_at IS NULL";
        
        $quiz_stats = $conn->query($quiz_query)->fetch(PDO::FETCH_ASSOC);
        
        // Time Statistics
        $time_query = "SELECT 
            AVG(time_spent) as avg_time_per_quiz,
            SUM(time_spent) as total_time_spent
        FROM student_quiz_attempts sqa
        JOIN students s ON sqa.student_id = s.id AND s.deleted_at IS NULL";
        
        $time_stats = $conn->query($time_query)->fetch(PDO::FETCH_ASSOC);
        
        return [
            'students' => $student_stats,
            'progress' => $progress_stats,
            'quizzes' => $quiz_stats,
            'time' => $time_stats
        ];
        
    } catch (PDOException $e) {
        return ['error' => 'Database error: ' . $e->getMessage()];
    }
}

function getPredictiveInsights($conn) {
    try {
        $insights = [];
        
        // 1. Predict completion time for average student
        $completion_query = "SELECT 
            AVG(DATEDIFF(sp.completed_at, sp.last_accessed)) as avg_days_to_complete
        FROM student_progress sp
        WHERE sp.is_completed = 1 AND sp.completed_at IS NOT NULL";
        
        $completion_data = $conn->query($completion_query)->fetch(PDO::FETCH_ASSOC);
        $avg_days = $completion_data['avg_days_to_complete'] ?? 3;
        
        $insights['completion_prediction'] = [
            'avg_days_per_lesson' => round($avg_days, 1),
            'predicted_total_days' => round($avg_days * 10, 1), // Assuming 10 lessons
            'message' => "Average student completes a lesson in " . round($avg_days, 1) . " days"
        ];
        
        // 2. Predict student success rate based on early performance
        $success_query = "SELECT 
            s.id,
            s.username,
            AVG(CASE WHEN sqa.is_correct = 1 THEN 1 ELSE 0 END) as success_rate,
            COUNT(sqa.id) as attempt_count
        FROM students s
        LEFT JOIN student_quiz_attempts sqa ON s.id = sqa.student_id
        WHERE s.deleted_at IS NULL
        GROUP BY s.id
        HAVING attempt_count >= 3";
        
        $success_data = $conn->query($success_query)->fetchAll(PDO::FETCH_ASSOC);
        
        $high_performers = 0;
        $medium_performers = 0;
        $low_performers = 0;
        
        foreach ($success_data as $student) {
            $rate = $student['success_rate'] * 100;
            if ($rate >= 80) $high_performers++;
            elseif ($rate >= 50) $medium_performers++;
            else $low_performers++;
        }
        
        $total_with_data = count($success_data);
        if ($total_with_data > 0) {
            $insights['performance_prediction'] = [
                'high_performers' => $high_performers,
                'medium_performers' => $medium_performers,
                'low_performers' => $low_performers,
                'high_percent' => round(($high_performers / $total_with_data) * 100, 1),
                'message' => "Based on early quiz performance, " . 
                           round(($high_performers / $total_with_data) * 100, 1) . 
                           "% of students are predicted to excel"
            ];
        }
        
        // 3. Predict lesson difficulty based on quiz scores
        $lesson_difficulty_query = "SELECT 
            l.id,
            l.lesson_title,
            t.topic_name,
            COUNT(sqa.id) as total_attempts,
            AVG(CASE WHEN sqa.is_correct = 1 THEN 100 ELSE 0 END) as avg_score,
            STDDEV(CASE WHEN sqa.is_correct = 1 THEN 100 ELSE 0 END) as score_stddev
        FROM lessons l
        JOIN topics t ON l.topic_id = t.id
        LEFT JOIN quizzes q ON l.id = q.lesson_id
        LEFT JOIN student_quiz_attempts sqa ON q.id = sqa.quiz_id
        WHERE l.deleted_at IS NULL
        GROUP BY l.id
        HAVING total_attempts >= 5
        ORDER BY avg_score ASC
        LIMIT 5";
        
        $difficult_lessons = $conn->query($lesson_difficulty_query)->fetchAll(PDO::FETCH_ASSOC);
        
        $insights['difficult_lessons'] = $difficult_lessons;
        
        // 4. Predict engagement trends
        $engagement_query = "SELECT 
            DATE(sqa.attempted_at) as attempt_date,
            COUNT(DISTINCT sqa.student_id) as active_students,
            COUNT(sqa.id) as daily_attempts
        FROM student_quiz_attempts sqa
        WHERE sqa.attempted_at >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)
        GROUP BY DATE(sqa.attempted_at)
        ORDER BY attempt_date";
        
        $engagement_data = $conn->query($engagement_query)->fetchAll(PDO::FETCH_ASSOC);
        
        if (count($engagement_data) >= 2) {
            $last_day = end($engagement_data);
            $first_day = reset($engagement_data);
            $growth_rate = ($last_day['daily_attempts'] - $first_day['daily_attempts']) / 
                          $first_day['daily_attempts'] * 100;
            
            $insights['engagement_trend'] = [
                'growth_rate' => round($growth_rate, 1),
                'trend' => $growth_rate > 0 ? 'increasing' : 'decreasing',
                'message' => "Daily engagement is " . 
                           ($growth_rate > 0 ? 'increasing' : 'decreasing') . 
                           " by " . abs(round($growth_rate, 1)) . "% over the last week"
            ];
        }
        
        // 5. Predict optimal lesson order based on completion rates
        $lesson_order_query = "SELECT 
            l.lesson_title,
            t.topic_name,
            COUNT(DISTINCT sp.student_id) as total_students,
            AVG(CASE WHEN sp.is_completed = 1 THEN 1 ELSE 0 END) * 100 as completion_rate,
            AVG(s.total_score) as avg_student_score
        FROM lessons l
        JOIN topics t ON l.topic_id = t.id
        LEFT JOIN student_progress sp ON l.id = sp.lesson_id
        LEFT JOIN students s ON sp.student_id = s.id
        WHERE l.deleted_at IS NULL
        GROUP BY l.id
        ORDER BY completion_rate DESC, avg_student_score DESC";
        
        $optimal_order = $conn->query($lesson_order_query)->fetchAll(PDO::FETCH_ASSOC);
        
        $insights['optimal_lesson_order'] = $optimal_order;
        
        // 6. Predict student retention
        $retention_query = "SELECT 
            DATEDIFF(CURDATE(), s.last_active) as days_since_last_activity,
            COUNT(*) as student_count
        FROM students s
        WHERE s.deleted_at IS NULL AND s.last_active IS NOT NULL
        GROUP BY DATEDIFF(CURDATE(), s.last_active)
        HAVING days_since_last_activity BETWEEN 1 AND 30";
        
        $retention_data = $conn->query($retention_query)->fetchAll(PDO::FETCH_ASSOC);
        
        $at_risk_students = 0;
        foreach ($retention_data as $data) {
            if ($data['days_since_last_activity'] > 7) {
                $at_risk_students += $data['student_count'];
            }
        }
        
        $total_active_students_query = "SELECT COUNT(*) as total FROM students WHERE deleted_at IS NULL AND last_active >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)";
        $total_active = $conn->query($total_active_students_query)->fetch(PDO::FETCH_ASSOC)['total'];
        
        if ($total_active > 0) {
            $retention_rate = (($total_active - $at_risk_students) / $total_active) * 100;
            $insights['retention_prediction'] = [
                'at_risk_students' => $at_risk_students,
                'retention_rate' => round($retention_rate, 1),
                'message' => round($retention_rate, 1) . "% of active students are likely to continue"
            ];
        }
        
        return $insights;
        
    } catch (PDOException $e) {
        return ['error' => 'Database error: ' . $e->getMessage()];
    }
}

function getTimeSeriesData($conn, $start_date, $end_date) {
    try {
        $data = [];
        
        // Daily student registrations
        $registration_query = "SELECT 
            DATE(created_at) as date,
            COUNT(*) as new_students
        FROM students 
        WHERE created_at BETWEEN :start_date AND :end_date 
        AND deleted_at IS NULL
        GROUP BY DATE(created_at)
        ORDER BY date";
        
        $stmt = $conn->prepare($registration_query);
        $stmt->execute(['start_date' => $start_date . ' 00:00:00', 'end_date' => $end_date . ' 23:59:59']);
        $data['registrations'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Daily quiz attempts
        $quiz_query = "SELECT 
            DATE(attempted_at) as date,
            COUNT(*) as quiz_attempts,
            AVG(CASE WHEN is_correct = 1 THEN 100 ELSE 0 END) as avg_score
        FROM student_quiz_attempts 
        WHERE attempted_at BETWEEN :start_date AND :end_date
        GROUP BY DATE(attempted_at)
        ORDER BY date";
        
        $stmt = $conn->prepare($quiz_query);
        $stmt->execute(['start_date' => $start_date . ' 00:00:00', 'end_date' => $end_date . ' 23:59:59']);
        $data['quiz_activity'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Daily lesson completions
        $completion_query = "SELECT 
            DATE(completed_at) as date,
            COUNT(*) as lessons_completed
        FROM student_progress 
        WHERE completed_at BETWEEN :start_date AND :end_date 
        AND is_completed = 1
        GROUP BY DATE(completed_at)
        ORDER BY date";
        
        $stmt = $conn->prepare($completion_query);
        $stmt->execute(['start_date' => $start_date . ' 00:00:00', 'end_date' => $end_date . ' 23:59:59']);
        $data['completions'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Daily active students
        $active_query = "SELECT 
            DATE(last_active) as date,
            COUNT(DISTINCT id) as active_students
        FROM students 
        WHERE last_active BETWEEN :start_date AND :end_date 
        AND deleted_at IS NULL
        GROUP BY DATE(last_active)
        ORDER BY date";
        
        $stmt = $conn->prepare($active_query);
        $stmt->execute(['start_date' => $start_date . ' 00:00:00', 'end_date' => $end_date . ' 23:59:59']);
        $data['active_students'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        return $data;
        
    } catch (PDOException $e) {
        return ['error' => 'Database error: ' . $e->getMessage()];
    }
}

function getTopicAnalytics($conn) {
    try {
        $query = "SELECT 
            t.id,
            t.topic_name,
            COUNT(DISTINCT l.id) as total_lessons,
            COUNT(DISTINCT sp.student_id) as students_started,
            AVG(CASE WHEN sp.is_completed = 1 THEN 1 ELSE 0 END) * 100 as completion_rate,
            AVG(sqa.is_correct) * 100 as avg_quiz_score,
            AVG(s.total_score) as avg_student_score
        FROM topics t
        LEFT JOIN lessons l ON t.id = l.topic_id AND l.deleted_at IS NULL
        LEFT JOIN student_progress sp ON l.id = sp.lesson_id
        LEFT JOIN quizzes q ON l.id = q.lesson_id
        LEFT JOIN student_quiz_attempts sqa ON q.id = sqa.quiz_id
        LEFT JOIN students s ON sp.student_id = s.id AND s.deleted_at IS NULL
        WHERE t.deleted_at IS NULL
        GROUP BY t.id
        ORDER BY t.topic_order";
        
        return $conn->query($query)->fetchAll(PDO::FETCH_ASSOC);
        
    } catch (PDOException $e) {
        return ['error' => 'Database error: ' . $e->getMessage()];
    }
}

function getStudentSegmentAnalysis($conn) {
    try {
        $segments = [];
        
        // Segment by performance
        $performance_query = "SELECT 
            CASE 
                WHEN s.total_score >= 80 THEN 'High Achievers'
                WHEN s.total_score >= 50 THEN 'Average Performers'
                ELSE 'Needs Improvement'
            END as segment,
            COUNT(*) as student_count,
            AVG(s.total_score) as avg_score,
            AVG(CASE WHEN sp.is_completed = 1 THEN 1 ELSE 0 END) * 100 as completion_rate,
            AVG(CASE WHEN sqa.is_correct = 1 THEN 100 ELSE 0 END) as avg_quiz_score
        FROM students s
        LEFT JOIN student_progress sp ON s.id = sp.student_id
        LEFT JOIN student_quiz_attempts sqa ON s.id = sqa.student_id
        WHERE s.deleted_at IS NULL
        GROUP BY segment
        ORDER BY avg_score DESC";
        
        $segments['performance'] = $conn->query($performance_query)->fetchAll(PDO::FETCH_ASSOC);
        
        // Segment by engagement
        $engagement_query = "SELECT 
            CASE 
                WHEN DATEDIFF(CURDATE(), s.last_active) <= 1 THEN 'Highly Engaged'
                WHEN DATEDIFF(CURDATE(), s.last_active) <= 7 THEN 'Moderately Engaged'
                ELSE 'Low Engagement'
            END as segment,
            COUNT(*) as student_count,
            AVG(DATEDIFF(CURDATE(), s.last_active)) as avg_days_inactive,
            AVG(s.total_score) as avg_score
        FROM students s
        WHERE s.deleted_at IS NULL AND s.last_active IS NOT NULL
        GROUP BY segment
        ORDER BY avg_days_inactive";
        
        $segments['engagement'] = $conn->query($engagement_query)->fetchAll(PDO::FETCH_ASSOC);
        
        // Segment by progress pace
        $pace_query = "SELECT 
            s.id,
            s.username,
            COUNT(DISTINCT sp.lesson_id) as lessons_started,
            COUNT(DISTINCT CASE WHEN sp.is_completed = 1 THEN sp.lesson_id END) as lessons_completed,
            DATEDIFF(MAX(sp.last_accessed), MIN(sp.last_accessed)) as learning_duration,
            CASE 
                WHEN COUNT(DISTINCT sp.lesson_id) = 0 THEN 'Not Started'
                WHEN COUNT(DISTINCT CASE WHEN sp.is_completed = 1 THEN sp.lesson_id END) / COUNT(DISTINCT sp.lesson_id) >= 0.8 THEN 'Fast Learner'
                WHEN COUNT(DISTINCT CASE WHEN sp.is_completed = 1 THEN sp.lesson_id END) / COUNT(DISTINCT sp.lesson_id) >= 0.5 THEN 'Steady Pace'
                ELSE 'Slow Progress'
            END as pace_segment
        FROM students s
        LEFT JOIN student_progress sp ON s.id = sp.student_id
        WHERE s.deleted_at IS NULL
        GROUP BY s.id
        HAVING lessons_started > 0";
        
        $pace_data = $conn->query($pace_query)->fetchAll(PDO::FETCH_ASSOC);
        
        // Count segments
        $pace_counts = ['Fast Learner' => 0, 'Steady Pace' => 0, 'Slow Progress' => 0, 'Not Started' => 0];
        foreach ($pace_data as $student) {
            if (isset($student['pace_segment'])) {
                $pace_counts[$student['pace_segment']]++;
            }
        }
        
        $segments['pace'] = $pace_counts;
        
        return $segments;
        
    } catch (PDOException $e) {
        return ['error' => 'Database error: ' . $e->getMessage()];
    }
}

// Get all analytics data
$overall_stats = getOverallStats($conn);
$predictive_insights = getPredictiveInsights($conn);
$time_series_data = getTimeSeriesData($conn, $start_date, $end_date);
$topic_analytics = getTopicAnalytics($conn);
$student_segments = getStudentSegmentAnalysis($conn);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Analytics Dashboard - JS Tutorial System</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/chartjs-adapter-date-fns"></script>
    <style>
        .stat-card {
            border-radius: 10px;
            padding: 20px;
            margin-bottom: 20px;
            transition: transform 0.3s;
        }
        
        .stat-card:hover {
            transform: translateY(-5px);
        }
        
        .stat-card i {
            font-size: 2.5rem;
            opacity: 0.8;
        }
        
        .stat-number {
            font-size: 2rem;
            font-weight: bold;
            margin: 10px 0;
        }
        
        .stat-label {
            font-size: 0.9rem;
            color: #6c757d;
            text-transform: uppercase;
            letter-spacing: 1px;
        }
        
        .insight-card {
            border-left: 4px solid;
            padding: 15px;
            margin-bottom: 15px;
            background: #f8f9fa;
        }
        
        .insight-high {
            border-left-color: #28a745;
        }
        
        .insight-medium {
            border-left-color: #ffc107;
        }
        
        .insight-low {
            border-left-color: #dc3545;
        }
        
        .prediction-badge {
            font-size: 0.75rem;
            padding: 3px 8px;
            border-radius: 12px;
        }
        
        .chart-container {
            position: relative;
            height: 300px;
            margin-bottom: 30px;
        }
        
        .segment-indicator {
            width: 12px;
            height: 12px;
            border-radius: 50%;
            display: inline-block;
            margin-right: 5px;
        }
        
        .date-filter {
            background: #f8f9fa;
            border-radius: 8px;
            padding: 15px;
            margin-bottom: 20px;
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
        
        .topic-progress-bar {
            height: 8px;
            border-radius: 4px;
            background: #e9ecef;
            margin: 5px 0;
            overflow: hidden;
        }
        
        .topic-progress-fill {
            height: 100%;
            background: linear-gradient(90deg, #4361ee, #3a0ca3);
            border-radius: 4px;
        }
    </style>
</head>
<body>
    <!-- Navigation -->
    <nav class="navbar navbar-expand-lg navbar-dark bg-primary">
        <div class="container">
            <a class="navbar-brand" href="#">
                <img src="../assets/img/URUScript.png" alt="JS Tutorial Logo" width="40" height="40"> Analytics Dashboard
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
                        <li><a class="dropdown-item active" href="analytics">
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
    
    <div class="container-fluid mt-4">
        <!-- Date Range Filter -->
        <div class="date-filter">
            <form method="GET" action="" class="row g-3">
                <div class="col-md-3">
                    <label class="form-label">Start Date</label>
                    <input type="date" class="form-control" name="start_date" 
                           value="<?php echo htmlspecialchars($start_date); ?>">
                </div>
                <div class="col-md-3">
                    <label class="form-label">End Date</label>
                    <input type="date" class="form-control" name="end_date" 
                           value="<?php echo htmlspecialchars($end_date); ?>">
                </div>
                <div class="col-md-2">
                    <label class="form-label">&nbsp;</label>
                    <button type="submit" class="btn btn-primary w-100">
                        <i class="fas fa-filter me-1"></i> Apply Filter
                    </button>
                </div>
                <div class="col-md-2">
                    <label class="form-label">&nbsp;</label>
                    <a href="analytics" class="btn btn-outline-secondary w-100">
                        <i class="fas fa-redo me-1"></i> Reset
                    </a>
                </div>
                <div class="col-md-2">
                    <label class="form-label">Quick Select</label>
                    <div class="btn-group w-100">
                        <button type="button" class="btn btn-outline-primary btn-sm" onclick="setDateRange(7)">7D</button>
                        <button type="button" class="btn btn-outline-primary btn-sm" onclick="setDateRange(30)">30D</button>
                        <button type="button" class="btn btn-outline-primary btn-sm" onclick="setDateRange(90)">90D</button>
                    </div>
                </div>
            </form>
        </div>
        
        <!-- Predictive Insights Section -->
        <div class="row mb-4">
            <div class="col-12">
                <div class="card">
                    <div class="card-header bg-primary text-white">
                        <h5 class="mb-0">
                            <i class="fas fa-crystal-ball me-2"></i> Predictive Insights & AI Recommendations
                        </h5>
                    </div>
                    <div class="card-body">
                        <div class="row">
                            <?php if (isset($predictive_insights['completion_prediction'])): ?>
                            <div class="col-md-4">
                                <div class="insight-card insight-medium">
                                    <h6><i class="fas fa-clock me-2"></i> Learning Pace Prediction</h6>
                                    <p class="mb-1">Average: <?php echo $predictive_insights['completion_prediction']['avg_days_per_lesson']; ?> days per lesson</p>
                                    <p class="mb-0"><small><?php echo $predictive_insights['completion_prediction']['message']; ?></small></p>
                                    <span class="prediction-badge bg-warning text-dark">
                                        <i class="fas fa-chart-line me-1"></i> Predicted
                                    </span>
                                </div>
                            </div>
                            <?php endif; ?>
                            
                            <?php if (isset($predictive_insights['performance_prediction'])): ?>
                            <div class="col-md-4">
                                <div class="insight-card insight-high">
                                    <h6><i class="fas fa-trophy me-2"></i> Performance Prediction</h6>
                                    <p class="mb-1">High Performers: <?php echo $predictive_insights['performance_prediction']['high_percent']; ?>%</p>
                                    <p class="mb-0"><small><?php echo $predictive_insights['performance_prediction']['message']; ?></small></p>
                                    <span class="prediction-badge bg-success">
                                        <i class="fas fa-brain me-1"></i> AI Analysis
                                    </span>
                                </div>
                            </div>
                            <?php endif; ?>
                            
                            <?php if (isset($predictive_insights['engagement_trend'])): ?>
                            <div class="col-md-4">
                                <div class="insight-card <?php echo $predictive_insights['engagement_trend']['growth_rate'] > 0 ? 'insight-high' : 'insight-low'; ?>">
                                    <h6><i class="fas fa-chart-line me-2"></i> Engagement Trend</h6>
                                    <p class="mb-1">
                                        <i class="fas fa-arrow-<?php echo $predictive_insights['engagement_trend']['growth_rate'] > 0 ? 'up' : 'down'; ?> me-1"></i>
                                        <?php echo abs($predictive_insights['engagement_trend']['growth_rate']); ?>% 
                                        <?php echo $predictive_insights['engagement_trend']['growth_rate'] > 0 ? 'Increase' : 'Decrease'; ?>
                                    </p>
                                    <p class="mb-0"><small><?php echo $predictive_insights['engagement_trend']['message']; ?></small></p>
                                    <span class="prediction-badge bg-info">
                                        <i class="fas fa-trend-up me-1"></i> Trend Analysis
                                    </span>
                                </div>
                            </div>
                            <?php endif; ?>
                            
                            <?php if (isset($predictive_insights['retention_prediction'])): ?>
                            <div class="col-md-4 mt-3">
                                <div class="insight-card <?php echo $predictive_insights['retention_prediction']['retention_rate'] > 70 ? 'insight-high' : 
                                                         ($predictive_insights['retention_prediction']['retention_rate'] > 50 ? 'insight-medium' : 'insight-low'); ?>">
                                    <h6><i class="fas fa-user-check me-2"></i> Student Retention Prediction</h6>
                                    <p class="mb-1">Retention Rate: <?php echo $predictive_insights['retention_prediction']['retention_rate']; ?>%</p>
                                    <p class="mb-1">At Risk: <?php echo $predictive_insights['retention_prediction']['at_risk_students']; ?> students</p>
                                    <p class="mb-0"><small><?php echo $predictive_insights['retention_prediction']['message']; ?></small></p>
                                    <span class="prediction-badge bg-primary">
                                        <i class="fas fa-shield-alt me-1"></i> Retention
                                    </span>
                                </div>
                            </div>
                            <?php endif; ?>
                            
                            <?php if (isset($predictive_insights['difficult_lessons']) && count($predictive_insights['difficult_lessons']) > 0): ?>
                            <div class="col-md-4 mt-3">
                                <div class="insight-card insight-low">
                                    <h6><i class="fas fa-exclamation-triangle me-2"></i> Difficult Lessons Identified</h6>
                                    <p class="mb-1">Top challenging lessons:</p>
                                    <ul class="list-unstyled mb-0">
                                        <?php foreach(array_slice($predictive_insights['difficult_lessons'], 0, 3) as $lesson): ?>
                                        <li class="small">
                                            <i class="fas fa-book me-1"></i>
                                            <?php echo htmlspecialchars($lesson['lesson_title']); ?>
                                            (<?php echo round($lesson['avg_score'], 1); ?>% avg)
                                        </li>
                                        <?php endforeach; ?>
                                    </ul>
                                    <span class="prediction-badge bg-danger">
                                        <i class="fas fa-exclamation me-1"></i> Attention Needed
                                    </span>
                                </div>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Overall Statistics -->
        <div class="row mb-4">
            <div class="col-md-3">
                <div class="stat-card bg-light border">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <div class="stat-label">Total Students</div>
                            <div class="stat-number"><?php echo $overall_stats['students']['total_students'] ?? 0; ?></div>
                            <small class="text-success">
                                <i class="fas fa-user-plus me-1"></i>
                                +<?php echo $overall_stats['students']['new_today'] ?? 0; ?> today
                            </small>
                        </div>
                        <i class="fas fa-users text-primary"></i>
                    </div>
                </div>
            </div>
            
            <div class="col-md-3">
                <div class="stat-card bg-light border">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <div class="stat-label">Avg Score</div>
                            <div class="stat-number"><?php echo round($overall_stats['students']['avg_score'] ?? 0, 1); ?></div>
                            <small class="text-primary">
                                <i class="fas fa-chart-line me-1"></i>
                                Overall performance
                            </small>
                        </div>
                        <i class="fas fa-star text-warning"></i>
                    </div>
                </div>
            </div>
            
            <div class="col-md-3">
                <div class="stat-card bg-light border">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <div class="stat-label">Completion Rate</div>
                            <div class="stat-number"><?php echo round($overall_stats['progress']['completion_rate'] ?? 0, 1); ?>%</div>
                            <small class="text-info">
                                <i class="fas fa-check-circle me-1"></i>
                                <?php echo $overall_stats['progress']['lessons_completed'] ?? 0; ?> completed
                            </small>
                        </div>
                        <i class="fas fa-tasks text-success"></i>
                    </div>
                </div>
            </div>
            
            <div class="col-md-3">
                <div class="stat-card bg-light border">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <div class="stat-label">Avg Quiz Score</div>
                            <div class="stat-number"><?php echo round($overall_stats['quizzes']['avg_quiz_score'] ?? 0, 1); ?>%</div>
                            <small class="text-info">
                                <i class="fas fa-question-circle me-1"></i>
                                <?php echo $overall_stats['quizzes']['total_attempts'] ?? 0; ?> attempts
                            </small>
                        </div>
                        <i class="fas fa-brain text-info"></i>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Charts Section -->
        <div class="row mb-4">
            <div class="col-md-6">
                <div class="card">
                    <div class="card-header">
                        <h5 class="mb-0">Student Activity Over Time</h5>
                    </div>
                    <div class="card-body">
                        <div class="chart-container">
                            <canvas id="activityChart"></canvas>
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="col-md-6">
                <div class="card">
                    <div class="card-header">
                        <h5 class="mb-0">Quiz Performance Trends</h5>
                    </div>
                    <div class="card-body">
                        <div class="chart-container">
                            <canvas id="quizChart"></canvas>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Topic Analytics -->
        <div class="row mb-4">
            <div class="col-12">
                <div class="card">
                    <div class="card-header">
                        <h5 class="mb-0">Topic Performance Analysis</h5>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-hover">
                                <thead>
                                    <tr>
                                        <th>Topic</th>
                                        <th>Lessons</th>
                                        <th>Students Started</th>
                                        <th>Completion Rate</th>
                                        <th>Avg Quiz Score</th>
                                        <th>Difficulty Level</th>
                                        <th>Recommendation</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($topic_analytics as $topic): 
                                        $completion_rate = $topic['completion_rate'] ?? 0;
                                        $quiz_score = $topic['avg_quiz_score'] ?? 0;
                                        
                                        // Determine difficulty level
                                        if ($quiz_score >= 80) {
                                            $difficulty = 'Easy';
                                            $difficulty_class = 'text-success';
                                        } elseif ($quiz_score >= 60) {
                                            $difficulty = 'Medium';
                                            $difficulty_class = 'text-warning';
                                        } else {
                                            $difficulty = 'Hard';
                                            $difficulty_class = 'text-danger';
                                        }
                                        
                                        // Generate recommendation
                                        if ($completion_rate < 50 && $quiz_score < 60) {
                                            $recommendation = 'Consider revising content';
                                            $rec_class = 'text-danger';
                                        } elseif ($completion_rate < 70) {
                                            $recommendation = 'Add more examples';
                                            $rec_class = 'text-warning';
                                        } elseif ($quiz_score < 70) {
                                            $recommendation = 'Review quiz questions';
                                            $rec_class = 'text-warning';
                                        } else {
                                            $recommendation = 'Performing well';
                                            $rec_class = 'text-success';
                                        }
                                    ?>
                                    <tr>
                                        <td><strong><?php echo htmlspecialchars($topic['topic_name']); ?></strong></td>
                                        <td><?php echo $topic['total_lessons'] ?? 0; ?></td>
                                        <td><?php echo $topic['students_started'] ?? 0; ?></td>
                                        <td>
                                            <div class="d-flex align-items-center">
                                                <span class="me-2"><?php echo round($completion_rate, 1); ?>%</span>
                                                <div class="topic-progress-bar" style="width: 100px;">
                                                    <div class="topic-progress-fill" style="width: <?php echo $completion_rate; ?>%"></div>
                                                </div>
                                            </div>
                                        </td>
                                        <td><?php echo round($quiz_score, 1); ?>%</td>
                                        <td><span class="<?php echo $difficulty_class; ?>"><?php echo $difficulty; ?></span></td>
                                        <td><span class="<?php echo $rec_class; ?>"><?php echo $recommendation; ?></span></td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Student Segmentation -->
        <div class="row mb-4">
            <div class="col-md-4">
                <div class="card">
                    <div class="card-header">
                        <h5 class="mb-0">Performance Segmentation</h5>
                    </div>
                    <div class="card-body">
                        <div class="chart-container">
                            <canvas id="performanceSegmentsChart"></canvas>
                        </div>
                        <div class="mt-3">
                            <?php if (isset($student_segments['performance'])): ?>
                            <?php foreach ($student_segments['performance'] as $segment): ?>
                            <div class="d-flex justify-content-between align-items-center mb-2">
                                <div>
                                    <span class="segment-indicator" 
                                          style="background: <?php echo $segment['segment'] == 'High Achievers' ? '#28a745' : 
                                                               ($segment['segment'] == 'Average Performers' ? '#ffc107' : '#dc3545'); ?>"></span>
                                    <?php echo $segment['segment']; ?>
                                </div>
                                <div>
                                    <strong><?php echo $segment['student_count']; ?></strong>
                                    <small class="text-muted"> students</small>
                                </div>
                            </div>
                            <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="col-md-4">
                <div class="card">
                    <div class="card-header">
                        <h5 class="mb-0">Engagement Segmentation</h5>
                    </div>
                    <div class="card-body">
                        <div class="chart-container">
                            <canvas id="engagementSegmentsChart"></canvas>
                        </div>
                        <div class="mt-3">
                            <?php if (isset($student_segments['engagement'])): ?>
                            <?php foreach ($student_segments['engagement'] as $segment): ?>
                            <div class="d-flex justify-content-between align-items-center mb-2">
                                <div>
                                    <span class="segment-indicator" 
                                          style="background: <?php echo $segment['segment'] == 'Highly Engaged' ? '#28a745' : 
                                                               ($segment['segment'] == 'Moderately Engaged' ? '#ffc107' : '#dc3545'); ?>"></span>
                                    <?php echo $segment['segment']; ?>
                                </div>
                                <div>
                                    <strong><?php echo $segment['student_count']; ?></strong>
                                    <small class="text-muted"> students</small>
                                </div>
                            </div>
                            <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="col-md-4">
                <div class="card">
                    <div class="card-header">
                        <h5 class="mb-0">Learning Pace Analysis</h5>
                    </div>
                    <div class="card-body">
                        <div class="chart-container">
                            <canvas id="paceSegmentsChart"></canvas>
                        </div>
                        <div class="mt-3">
                            <?php if (isset($student_segments['pace'])): ?>
                            <?php foreach ($student_segments['pace'] as $segment => $count): ?>
                            <div class="d-flex justify-content-between align-items-center mb-2">
                                <div>
                                    <span class="segment-indicator" 
                                          style="background: <?php echo $segment == 'Fast Learner' ? '#28a745' : 
                                                               ($segment == 'Steady Pace' ? '#ffc107' : 
                                                               ($segment == 'Slow Progress' ? '#dc3545' : '#6c757d')); ?>"></span>
                                    <?php echo $segment; ?>
                                </div>
                                <div>
                                    <strong><?php echo $count; ?></strong>
                                    <small class="text-muted"> students</small>
                                </div>
                            </div>
                            <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Optimal Lesson Order Recommendations -->
        <?php if (isset($predictive_insights['optimal_lesson_order'])): ?>
        <div class="row">
            <div class="col-12">
                <div class="card">
                    <div class="card-header bg-info text-white">
                        <h5 class="mb-0">
                            <i class="fas fa-sort-amount-down me-2"></i> AI-Powered Lesson Order Recommendations
                        </h5>
                    </div>
                    <div class="card-body">
                        <p class="text-muted">Based on student completion rates and scores, here's the optimal order for maximum learning effectiveness:</p>
                        <div class="table-responsive">
                            <table class="table table-hover">
                                <thead>
                                    <tr>
                                        <th width="50">Rank</th>
                                        <th>Lesson</th>
                                        <th>Topic</th>
                                        <th>Completion Rate</th>
                                        <th>Avg Student Score</th>
                                        <th>Recommended Action</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($predictive_insights['optimal_lesson_order'] as $index => $lesson): 
                                        $completion_rate = $lesson['completion_rate'] ?? 0;
                                        $avg_score = $lesson['avg_student_score'] ?? 0;
                                        
                                        // Determine action
                                        if ($completion_rate >= 80 && $avg_score >= 80) {
                                            $action = 'Keep as is - Excellent performance';
                                            $action_class = 'text-success';
                                        } elseif ($completion_rate < 50) {
                                            $action = 'Consider moving earlier or revising';
                                            $action_class = 'text-danger';
                                        } elseif ($avg_score < 60) {
                                            $action = 'Review difficulty level';
                                            $action_class = 'text-warning';
                                        } else {
                                            $action = 'Good position';
                                            $action_class = 'text-info';
                                        }
                                    ?>
                                    <tr>
                                        <td>
                                            <span class="badge bg-primary rounded-circle" style="width: 30px; height: 30px; line-height: 30px; display: inline-block;">
                                                <?php echo $index + 1; ?>
                                            </span>
                                        </td>
                                        <td><strong><?php echo htmlspecialchars($lesson['lesson_title']); ?></strong></td>
                                        <td><?php echo htmlspecialchars($lesson['topic_name']); ?></td>
                                        <td>
                                            <div class="d-flex align-items-center">
                                                <span class="me-2"><?php echo round($completion_rate, 1); ?>%</span>
                                                <div class="topic-progress-bar" style="width: 100px;">
                                                    <div class="topic-progress-fill" style="width: <?php echo $completion_rate; ?>%"></div>
                                                </div>
                                            </div>
                                        </td>
                                        <td><?php echo round($avg_score, 1); ?></td>
                                        <td><span class="<?php echo $action_class; ?>"><?php echo $action; ?></span></td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <?php endif; ?>
    </div>
    
    <script src="https://code.jquery.com/jquery-3.6.4.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Date range quick select
        function setDateRange(days) {
            const endDate = new Date().toISOString().split('T')[0];
            const startDate = new Date();
            startDate.setDate(startDate.getDate() - days);
            const startDateStr = startDate.toISOString().split('T')[0];
            
            $('input[name="start_date"]').val(startDateStr);
            $('input[name="end_date"]').val(endDate);
            $('form').submit();
        }
        
        // Prepare chart data
        const activityData = {
            dates: <?php echo json_encode(array_column($time_series_data['active_students'] ?? [], 'date')); ?>,
            activeStudents: <?php echo json_encode(array_column($time_series_data['active_students'] ?? [], 'active_students')); ?>,
            newStudents: <?php echo json_encode(array_column($time_series_data['registrations'] ?? [], 'new_students')); ?>,
            completions: <?php echo json_encode(array_column($time_series_data['completions'] ?? [], 'lessons_completed')); ?>
        };
        
        const quizData = {
            dates: <?php echo json_encode(array_column($time_series_data['quiz_activity'] ?? [], 'date')); ?>,
            attempts: <?php echo json_encode(array_column($time_series_data['quiz_activity'] ?? [], 'quiz_attempts')); ?>,
            scores: <?php echo json_encode(array_column($time_series_data['quiz_activity'] ?? [], 'avg_score')); ?>
        };
        
        // Student Activity Chart
        const activityCtx = document.getElementById('activityChart').getContext('2d');
        const activityChart = new Chart(activityCtx, {
            type: 'line',
            data: {
                labels: activityData.dates,
                datasets: [
                    {
                        label: 'Active Students',
                        data: activityData.activeStudents,
                        borderColor: '#4361ee',
                        backgroundColor: 'rgba(67, 97, 238, 0.1)',
                        fill: true,
                        tension: 0.4
                    },
                    {
                        label: 'New Students',
                        data: activityData.newStudents,
                        borderColor: '#4cc9f0',
                        backgroundColor: 'rgba(76, 201, 240, 0.1)',
                        fill: true,
                        tension: 0.4
                    },
                    {
                        label: 'Lessons Completed',
                        data: activityData.completions,
                        borderColor: '#28a745',
                        backgroundColor: 'rgba(40, 167, 69, 0.1)',
                        fill: true,
                        tension: 0.4
                    }
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                interaction: {
                    mode: 'index',
                    intersect: false
                },
                scales: {
                    x: {
                        display: true,
                        title: {
                            display: true,
                            text: 'Date'
                        }
                    },
                    y: {
                        display: true,
                        title: {
                            display: true,
                            text: 'Count'
                        },
                        beginAtZero: true
                    }
                },
                plugins: {
                    legend: {
                        position: 'top'
                    },
                    tooltip: {
                        mode: 'index',
                        intersect: false
                    }
                }
            }
        });
        
        // Quiz Performance Chart
        const quizCtx = document.getElementById('quizChart').getContext('2d');
        const quizChart = new Chart(quizCtx, {
            type: 'bar',
            data: {
                labels: quizData.dates,
                datasets: [
                    {
                        label: 'Quiz Attempts',
                        data: quizData.attempts,
                        backgroundColor: 'rgba(255, 193, 7, 0.6)',
                        borderColor: 'rgba(255, 193, 7, 1)',
                        borderWidth: 1
                    },
                    {
                        label: 'Average Score %',
                        data: quizData.scores,
                        type: 'line',
                        borderColor: '#dc3545',
                        backgroundColor: 'rgba(220, 53, 69, 0.1)',
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
                    x: {
                        display: true,
                        title: {
                            display: true,
                            text: 'Date'
                        }
                    },
                    y: {
                        display: true,
                        title: {
                            display: true,
                            text: 'Attempts'
                        },
                        beginAtZero: true
                    },
                    y1: {
                        type: 'linear',
                        display: true,
                        position: 'right',
                        title: {
                            display: true,
                            text: 'Score %'
                        },
                        min: 0,
                        max: 100,
                        grid: {
                            drawOnChartArea: false
                        }
                    }
                },
                plugins: {
                    legend: {
                        position: 'top'
                    }
                }
            }
        });
        
        // Performance Segments Chart
        <?php if (isset($student_segments['performance'])): ?>
        const performanceSegmentsCtx = document.getElementById('performanceSegmentsChart').getContext('2d');
        const performanceSegmentsChart = new Chart(performanceSegmentsCtx, {
            type: 'doughnut',
            data: {
                labels: <?php echo json_encode(array_column($student_segments['performance'], 'segment')); ?>,
                datasets: [{
                    data: <?php echo json_encode(array_column($student_segments['performance'], 'student_count')); ?>,
                    backgroundColor: [
                        'rgba(40, 167, 69, 0.8)',
                        'rgba(255, 193, 7, 0.8)',
                        'rgba(220, 53, 69, 0.8)'
                    ],
                    borderColor: [
                        'rgb(40, 167, 69)',
                        'rgb(255, 193, 7)',
                        'rgb(220, 53, 69)'
                    ],
                    borderWidth: 1
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        position: 'bottom'
                    },
                    tooltip: {
                        callbacks: {
                            label: function(context) {
                                const label = context.label || '';
                                const value = context.raw || 0;
                                const total = context.dataset.data.reduce((a, b) => a + b, 0);
                                const percentage = Math.round((value / total) * 100);
                                return `${label}: ${value} students (${percentage}%)`;
                            }
                        }
                    }
                }
            }
        });
        <?php endif; ?>
        
        // Engagement Segments Chart
        <?php if (isset($student_segments['engagement'])): ?>
        const engagementSegmentsCtx = document.getElementById('engagementSegmentsChart').getContext('2d');
        const engagementSegmentsChart = new Chart(engagementSegmentsCtx, {
            type: 'pie',
            data: {
                labels: <?php echo json_encode(array_column($student_segments['engagement'], 'segment')); ?>,
                datasets: [{
                    data: <?php echo json_encode(array_column($student_segments['engagement'], 'student_count')); ?>,
                    backgroundColor: [
                        'rgba(40, 167, 69, 0.8)',
                        'rgba(255, 193, 7, 0.8)',
                        'rgba(220, 53, 69, 0.8)'
                    ],
                    borderColor: [
                        'rgb(40, 167, 69)',
                        'rgb(255, 193, 7)',
                        'rgb(220, 53, 69)'
                    ],
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
        
        // Pace Segments Chart
        <?php if (isset($student_segments['pace'])): ?>
        const paceSegmentsCtx = document.getElementById('paceSegmentsChart').getContext('2d');
        const paceSegmentsChart = new Chart(paceSegmentsCtx, {
            type: 'polarArea',
            data: {
                labels: <?php echo json_encode(array_keys($student_segments['pace'])); ?>,
                datasets: [{
                    data: <?php echo json_encode(array_values($student_segments['pace'])); ?>,
                    backgroundColor: [
                        'rgba(40, 167, 69, 0.8)',
                        'rgba(255, 193, 7, 0.8)',
                        'rgba(220, 53, 69, 0.8)',
                        'rgba(108, 117, 125, 0.8)'
                    ],
                    borderColor: [
                        'rgb(40, 167, 69)',
                        'rgb(255, 193, 7)',
                        'rgb(220, 53, 69)',
                        'rgb(108, 117, 125)'
                    ],
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
        
        // Auto-refresh analytics every 5 minutes
        setInterval(() => {
            console.log('Refreshing analytics data...');
            // You can implement AJAX refresh here if needed
        }, 300000);
    </script>
</body>
</html>