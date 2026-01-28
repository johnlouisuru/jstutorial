<?php
require_once 'database.php';

$db = new Database();
$conn = $db->getConnection();
$studentSession = new StudentSession($conn);

if (!$studentSession->isLoggedIn()) {
    header('Location: index');
    exit;
}

$studentData = $studentSession->getStudentData();
$stats = $studentSession->getDashboardStats();
$goals = $studentSession->getLearningGoals();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="icon" type="image/x-icon" href="assets/img/URUScript.png">
    <title>Learning Dashboard - URUScript</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        :root {
            --primary-color: #4361ee;
            --secondary-color: #3a0ca3;
            --accent-color: #4cc9f0;
            --success-color: #06d6a0;
            --warning-color: #ffd166;
            --danger-color: #ef476f;
            --light-bg: #f8f9fa;
            --dark-bg: #1a1d29;
            --card-shadow: 0 4px 6px rgba(0, 0, 0, 0.07);
            --hover-shadow: 0 10px 25px rgba(0, 0, 0, 0.15);
        }
        
        * {
            font-family: 'Inter', sans-serif;
        }
        
        body {
            background: linear-gradient(135deg, #f8fafc 0%, #e2e8f0 100%);
            min-height: 100vh;
        }
        
        .dashboard-container {
            max-width: 1400px;
            margin: 0 auto;
            padding: 1rem;
        }
        
        /* Modern Cards - Added padding */
        .modern-card {
            background: white;
            border-radius: 16px;
            border: none;
            box-shadow: var(--card-shadow);
            transition: all 0.3s ease;
            overflow: hidden;
            padding: 1.5rem; /* Added padding to all cards */
            margin-bottom: 1.5rem; /* Added spacing between cards */
        }
        
        .modern-card .card-body {
            padding: 0; /* Remove padding from inner card-body since parent has padding */
        }
        
        .modern-card:hover {
            transform: translateY(-5px);
            box-shadow: var(--hover-shadow);
        }
        
        .modern-card.gradient {
            background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
            color: white;
        }
        
        .modern-card.gradient-2 {
            background: linear-gradient(135deg, var(--success-color), var(--accent-color));
            color: white;
        }
        
        /* Stats Cards */
        .stat-card {
            padding: 1.5rem;
            text-align: center;
            position: relative;
        }
        
        .stat-card .icon-wrapper {
            width: 60px;
            height: 60px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 1rem;
            font-size: 1.5rem;
        }
        
        .stat-card .value {
            font-size: 2.5rem;
            font-weight: 700;
            line-height: 1;
            margin-bottom: 0.5rem;
        }
        
        .stat-card .label {
            font-size: 0.9rem;
            color: #6c757d;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        
        /* Progress Indicators */
        .progress-circle {
            width: 120px;
            height: 120px;
            position: relative;
        }
        
        .progress-circle svg {
            transform: rotate(-90deg);
        }
        
        .progress-circle-bg {
            fill: none;
            stroke: #e9ecef;
            stroke-width: 6;
        }
        
        .progress-circle-fill {
            fill: none;
            stroke: var(--primary-color);
            stroke-width: 6;
            stroke-linecap: round;
            transition: stroke-dasharray 1s ease;
        }
        
        .progress-circle-text {
            position: absolute;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            font-size: 1.5rem;
            font-weight: 700;
        }
        
        /* Navigation */
        .navbar-brand img {
            transition: transform 0.3s ease;
        }
        
        .navbar-brand:hover img {
            transform: scale(1.1);
        }
        
        /* User Avatar */
        .user-avatar {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: bold;
            font-size: 18px;
            transition: transform 0.3s ease;
        }
        
        .user-avatar:hover {
            transform: scale(1.1);
        }
        
        /* Score Badge */
        .score-badge {
            background: linear-gradient(135deg, #ffd166, #ff9e00);
            color: #000;
            font-weight: bold;
            padding: 0.4rem 1rem;
            border-radius: 20px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        
        /* Learning Streak */
        .streak-container {
            position: relative;
            padding: 1.5rem; /* Increased padding */
            border-radius: 12px;
            background: linear-gradient(135deg, #ffd166, #ff9e00);
            color: #000;
        }
        
        .streak-count {
            font-size: 2.5rem;
            font-weight: 700;
            line-height: 1;
        }
        
        /* Topic Cards - Added padding */
        .topic-progress-card {
            padding: 1.25rem;
            margin-bottom: 1rem;
            border-left: 4px solid var(--primary-color);
            background: #f8f9fa;
            border-radius: 8px;
        }
        
        .topic-progress-card.complete {
            border-left-color: var(--success-color);
        }
        
        /* Chart Container */
        .chart-container {
            position: relative;
            height: 300px;
            width: 100%;
            margin-top: 1rem;
        }
        
        /* Chart Filter Buttons */
        .chart-filter-buttons .btn {
            padding: 0.25rem 0.75rem;
            font-size: 0.875rem;
        }
        
        .chart-filter-buttons .btn.active {
            background-color: var(--primary-color);
            color: white;
        }
        
        /* Achievement Badges */
        .achievement-badge {
            width: 80px;
            height: 80px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto;
            font-size: 2rem;
            background: linear-gradient(135deg, #ffd166, #ff9e00);
            color: white;
        }
        
        /* Loading Animation */
        .loading-wave {
            display: flex;
            justify-content: center;
            align-items: center;
            height: 200px;
        }
        
        .loading-wave div {
            width: 4px;
            height: 20px;
            background: var(--primary-color);
            margin: 0 3px;
            border-radius: 10px;
            animation: wave 1.2s linear infinite;
        }
        
        .loading-wave div:nth-child(2) {
            animation-delay: 0.1s;
        }
        
        .loading-wave div:nth-child(3) {
            animation-delay: 0.2s;
        }
        
        .loading-wave div:nth-child(4) {
            animation-delay: 0.3s;
        }
        
        @keyframes wave {
            0%, 60%, 100% {
                transform: scaleY(1);
            }
            30% {
                transform: scaleY(2);
            }
        }
        
        /* Responsive Design */
        @media (max-width: 768px) {
            .stat-card .value {
                font-size: 2rem;
            }
            
            .progress-circle {
                width: 100px;
                height: 100px;
            }
            
            .dashboard-container {
                padding: 0.5rem;
            }
            
            .modern-card {
                padding: 1rem;
            }
        }
        
        /* Add padding to containers */
        .container {
            padding-left: 15px;
            padding-right: 15px;
        }
        
        .row {
            margin-left: -10px;
            margin-right: -10px;
        }
        
        .col-lg-3, .col-lg-4, .col-lg-8, .col-md-6, .col-12, .col-6 {
            padding-left: 10px;
            padding-right: 10px;
        }
        
        /* Fix for the chart buttons */
        .btn-group {
            gap: 0.25rem;
        }
        
        .btn-group .btn {
            border-radius: 20px !important;
        }
        
        /* Ensure all content has proper spacing */
        h1, h2, h3, h4, h5, h6 {
            margin-bottom: 1rem;
        }
        
        p {
            margin-bottom: 1rem;
        }
    </style>
</head>
<body>
    <!-- Navigation -->
    <nav class="navbar navbar-expand-lg navbar-light bg-white shadow-sm py-3">
        <div class="container">
            <a class="navbar-brand fw-bold text-primary d-flex align-items-center" href="index">
                <img src="assets/img/URUScript.png" alt="URUScript Logo" width="40" height="40" class="me-2">
                <span>URUScript</span>
            </a>
            
            <div class="d-flex align-items-center">
                <?php if ($studentSession->isLoggedIn() && $studentData): ?>
                <div class="d-flex align-items-center me-3">
                    <span class="score-badge me-3">
                        <i class="fas fa-star me-1"></i> <?php echo $studentData['score']; ?> pts
                    </span>
                    <div class="dropdown">
                        <button class="btn btn-outline-primary btn-sm dropdown-toggle d-flex align-items-center" 
                                type="button" id="userDropdown" data-bs-toggle="dropdown">
                            <div class="user-avatar me-2" style="background-color: <?php echo $studentData['avatar']; ?>;">
                                <?php echo strtoupper(substr($studentData['name'], 0, 1)); ?>
                            </div>
                            <span><?php echo htmlspecialchars($studentData['username']); ?></span>
                        </button>
                        <ul class="dropdown-menu dropdown-menu-end shadow">
                            <li>
                                <span class="dropdown-item-text">
                                    <small>Logged in as</small><br>
                                    <strong><?php echo htmlspecialchars($studentData['name']); ?></strong>
                                </span>
                            </li>
                            <li><hr class="dropdown-divider"></li>
                            <li><a class="dropdown-item" href="index">
                                <i class="fas fa-compass me-2"></i> Explore Topics
                            </a></li>
                            <li><a class="dropdown-item" href="coding-ground">
                                <i class="fas fa-code me-2"></i> Code Arena
                            </a></li>
                            <li><a class="dropdown-item" href="#" onclick="showQuizStats()">
                                <i class="fas fa-chart-line me-2"></i> Advanced Analytics
                            </a></li>
                            <li><hr class="dropdown-divider"></li>
                            <li><a class="dropdown-item" href="#" onclick="logout()">
                                <i class="fas fa-sign-out-alt me-2"></i> Logout
                            </a></li>
                        </ul>
                    </div>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </nav>

   <!-- Main Dashboard -->
    <div class="dashboard-container py-4">
        <div class="container-fluid"> <!-- Changed to container-fluid for better padding -->
            <!-- Welcome Header -->
            <div class="row mb-4">
                <div class="col-12">
                    <div class="modern-card gradient">
                        <!-- Removed p-4 since modern-card now has padding -->
                        <div class="row align-items-center">
                            <div class="col-md-8">
                                <h1 class="display-6 fw-bold mb-2">Welcome back, <?php echo htmlspecialchars($studentData['name']); ?>! 👋</h1>
                                <p class="mb-0 opacity-75">Track your learning journey and unlock new achievements.</p>
                            </div>
                            <div class="col-md-4 text-md-end">
                                <div class="d-inline-block">
                                    <div class="h3 mb-0">Day <?php echo isset($stats['learning_streak']) ? $stats['learning_streak'] : 0; ?></div>
                                    <small class="opacity-75">Learning Streak</small>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Stats Overview -->
            <div class="row mb-4">
                <div class="col-lg-3 col-md-6 mb-4">
                    <div class="modern-card stat-card">
                        <div class="icon-wrapper" style="background: rgba(67, 97, 238, 0.1); color: var(--primary-color);">
                            <i class="fas fa-chart-line"></i>
                        </div>
                        <div class="value text-primary"><?php echo $stats['accuracy_rate'] ?? 0; ?>%</div>
                        <div class="label">Quiz Accuracy</div>
                        <small class="text-muted"><?php echo $stats['correct_attempts'] ?? 0; ?>/<?php echo $stats['total_attempts'] ?? 0; ?> correct</small>
                    </div>
                </div>
                
                <div class="col-lg-3 col-md-6 mb-4">
                    <div class="modern-card stat-card">
                        <div class="icon-wrapper" style="background: rgba(6, 214, 160, 0.1); color: var(--success-color);">
                            <i class="fas fa-book"></i>
                        </div>
                        <div class="value text-success"><?php echo $stats['total_finished_lessons'] ?? 0; ?></div>
                        <div class="label">Lessons Completed</div>
                        <small class="text-muted"><?php echo $stats['lessons_completion_rate'] ?? 0; ?>% of all lessons</small>
                    </div>
                </div>
                
                <div class="col-lg-3 col-md-6 mb-4">
                    <div class="modern-card stat-card">
                        <div class="icon-wrapper" style="background: rgba(76, 201, 240, 0.1); color: var(--accent-color);">
                            <i class="fas fa-clock"></i>
                        </div>
                        <div class="value text-info"><?php echo round($stats['avg_time_spent'] ?? 0); ?>s</div>
                        <div class="label">Avg Time/Question</div>
                        <small class="text-muted">Total attempts: <?php echo $stats['total_attempts'] ?? 0; ?></small>
                    </div>
                </div>
                
                <div class="col-lg-3 col-md-6 mb-4">
                    <div class="modern-card gradient-2 stat-card">
                        <div class="icon-wrapper bg-white opacity-25">
                            <i class="fas fa-trophy"></i>
                        </div>
                        <div class="value"><?php echo $studentData['score']; ?></div>
                        <div class="label opacity-75">Total Points</div>
                        <small class="opacity-75">Earn more by completing lessons</small>
                    </div>
                </div>
            </div>

            <!-- Main Content Area -->
            <div class="row">
                <!-- Left Column: Progress & Analytics -->
                <div class="col-lg-8 mb-4">
                    <!-- Learning Progress Chart -->
<div class="modern-card mb-4">
    <div class="card-body">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h5 class="card-title mb-0">
                <i class="fas fa-chart-bar me-2 text-primary"></i>Learning Progress
            </h5>
            <div class="btn-group chart-filter-buttons" role="group">
                <button type="button" class="btn btn-sm btn-outline-primary active" 
                        data-range="week" onclick="updateChart('week')">
                    Week
                </button>
                <button type="button" class="btn btn-sm btn-outline-primary" 
                        data-range="month" onclick="updateChart('month')">
                    Month
                </button>
                <button type="button" class="btn btn-sm btn-outline-primary" 
                        data-range="all" onclick="updateChart('all')">
                    All Time
                </button>
            </div>
        </div>
        <div class="chart-container">
            <canvas id="progressChart"></canvas>
        </div>
    </div>
</div>

                    <!-- Topic Progress -->
                    <div class="modern-card mb-4">
                        <div class="card-body">
                            <h5 class="card-title mb-4">
                                <i class="fas fa-layer-group me-2 text-primary"></i>Topic Progress
                            </h5>
                            <div id="topicProgressContainer">
                                <!-- Topics will be loaded via AJAX -->
                                <div class="loading-wave">
                                    <div></div><div></div><div></div><div></div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Predictive Insights - FIXED VERSION -->
            <div class="modern-card">
                <div class="card-body">
                    <h5 class="card-title mb-4">
                        <i class="fas fa-lightbulb me-2 text-warning"></i>Predictive Insights
                    </h5>
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <div class="modern-card bg-light p-3 h-100">
                                <div class="d-flex align-items-center mb-2">
                                    <i class="fas fa-bolt text-warning me-2"></i>
                                    <h6 class="mb-0">Estimated Completion</h6>
                                </div>
                                <?php if (isset($stats['estimated_completion_minutes']) && $stats['estimated_completion_minutes'] > 0): ?>
                                <p class="mb-2">Based on your current pace, you'll complete all lessons in approximately:</p>
                                <div class="h4 text-primary">
                                    <?php 
                                    $hours = floor($stats['estimated_completion_minutes'] / 60);
                                    $minutes = $stats['estimated_completion_minutes'] % 60;
                                    echo $hours > 0 ? "{$hours}h {$minutes}m" : "{$minutes}m";
                                    ?>
                                </div>
                                <small class="text-muted"><?php echo isset($stats['remaining_lessons']) ? $stats['remaining_lessons'] : 0; ?> lessons remaining</small>
                                <?php else: ?>
                                <p class="mb-0">Complete more lessons to get an accurate estimate!</p>
                                <?php endif; ?>
                            </div>
                        </div>
                        
                        <div class="col-md-6 mb-3">
                            <div class="modern-card bg-light p-3 h-100">
                                <div class="d-flex align-items-center mb-2">
                                    <i class="fas fa-crosshairs text-danger me-2"></i>
                                    <h6 class="mb-0">Focus Area</h6>
                                </div>
                                <?php if (isset($stats['weakest_topic']) && $stats['weakest_topic']): ?>
                                <p class="mb-2">You might want to review:</p>
                                <div class="h5 text-danger"><?php echo htmlspecialchars($stats['weakest_topic']['topic_name']); ?></div>
                                <small class="text-muted">
                                    <?php 
                                    $weakAccuracy = isset($stats['weakest_topic']['total_quizzes']) && $stats['weakest_topic']['total_quizzes'] > 0 ? 
                                        round(($stats['weakest_topic']['correct_quizzes'] / $stats['weakest_topic']['total_quizzes']) * 100) : 0;
                                    echo "{$weakAccuracy}% accuracy in this topic";
                                    ?>
                                </small>
                                <?php else: ?>
                                <p class="mb-0">Take more quizzes to identify focus areas.</p>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
                </div>

                <!-- Right Column: Quick Stats & Recommendations -->
                <div class="col-lg-4 mb-4">
                    <!-- Learning Streak -->
                    <div class="modern-card mb-4 streak-container">
                        <div class="d-flex align-items-center">
                            <div class="me-3">
                                <i class="fas fa-fire fa-2x"></i>
                            </div>
                            <div>
                                <div class="streak-count"><?php echo $stats['learning_streak'] ?? 0; ?></div>
                                <div class="fw-bold">Day Streak</div>
                                <small>Active <?php echo $stats['active_days_this_week'] ?? 0; ?> days this week</small>
                            </div>
                        </div>
                        <div class="mt-3">
                            <div class="d-flex justify-content-between mb-1">
                                <small>Weekly Goal</small>
                                <small><?php echo $stats['goal_progress'] ?? 0; ?>%</small>
                            </div>
                            <div class="progress" style="height: 6px;">
                                <div class="progress-bar bg-dark" style="width: <?php echo $stats['goal_progress'] ?? 0; ?>%"></div>
                            </div>
                        </div>
                    </div>

                    <!-- Recommended Next Steps -->
                    <div class="modern-card mb-4">
                        <div class="card-body">
                            <h5 class="card-title mb-3">
                                <i class="fas fa-rocket me-2 text-success"></i>Recommended Next Steps
                            </h5>
                            <div id="recommendationsContainer">
                                <?php if (!empty($goals)): ?>
                                    <?php foreach($goals as $goal): ?>
                                    <div class="d-flex align-items-center mb-3 p-2 border rounded">
                                        <div class="me-3">
                                            <i class="fas fa-book text-primary"></i>
                                        </div>
                                        <div class="flex-grow-1">
                                            <div class="fw-bold"><?php echo htmlspecialchars($goal['lesson_title']); ?></div>
                                            <small class="text-muted"><?php echo htmlspecialchars($goal['topic_name']); ?></small>
                                        </div>
                                        <a href="lesson?topic_id=<?php echo $goal['topics_id'] ?? ''; ?>&lesson_id=<?php echo $goal['id']; ?>" 
                                           class="btn btn-sm btn-primary">
                                            Start
                                        </a>
                                    </div>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <p class="text-muted text-center py-3">All lessons completed! 🎉</p>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>

                    <!-- Quick Stats -->
                    <div class="modern-card">
                        <div class="card-body">
                            <h5 class="card-title mb-3">
                                <i class="fas fa-chart-pie me-2 text-info"></i>Performance Breakdown
                            </h5>
                            <div class="mb-3">
                                <div class="d-flex justify-content-between mb-1">
                                    <small>Quiz Accuracy</small>
                                    <small><?php echo $stats['accuracy_rate'] ?? 0; ?>%</small>
                                </div>
                                <div class="progress" style="height: 8px;">
                                    <div class="progress-bar bg-success" style="width: <?php echo $stats['accuracy_rate'] ?? 0; ?>%"></div>
                                </div>
                            </div>
                            <div class="row">
                                <div class="col-6 text-center">
                                    <div class="h4 text-success mb-1"><?php echo $stats['correct_attempts'] ?? 0; ?></div>
                                    <small class="text-muted">Correct</small>
                                </div>
                                <div class="col-6 text-center">
                                    <div class="h4 text-danger mb-1"><?php echo $stats['incorrect_attempts'] ?? 0; ?></div>
                                    <small class="text-muted">Incorrect</small>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Achievement Badge -->
                    <?php if (($stats['lessons_completion_rate'] ?? 0) >= 100): ?>
                    <div class="modern-card mt-4 border-success">
                        <div class="card-body text-center">
                            <div class="achievement-badge mb-3">
                                <i class="fas fa-trophy"></i>
                            </div>
                            <h5 class="text-success">Master Achieved! 🎉</h5>
                            <p class="text-muted mb-0">You've completed all available lessons!</p>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Recent Activity -->
            <div class="row mb-4">
                <div class="col-12">
                    <div class="modern-card">
                        <div class="card-body">
                            <h5 class="card-title mb-4">
                                <i class="fas fa-history me-2 text-primary"></i>Recent Activity
                            </h5>
                            <div id="recentActivityContainer">
                                <!-- Activity will be loaded via AJAX -->
                                <div class="loading-wave">
                                    <div></div><div></div><div></div><div></div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Statistics Modal -->
    <div class="modal fade modal-lg" id="quizStatsModal" tabindex="-1">
        <div class="modal-dialog modal-lg modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header bg-primary text-white">
                    <h5 class="modal-title">
                        <i class="fas fa-chart-line me-2"></i>Advanced Analytics
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div id="quizStatsContent">
                        <div class="text-center py-5">
                            <div class="spinner-border text-primary" role="status">
                                <span class="visually-hidden">Loading...</span>
                            </div>
                            <p class="mt-3">Loading advanced analytics...</p>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Footer -->
    <footer class="bg-dark text-white py-4 mt-5">
        <div class="container">
            <div class="row align-items-center">
                <div class="col-md-6">
                    <img src="assets/img/URUScript.png" alt="URUScript Logo" class="img-fluid me-2" style="max-height: 40px;">
                    <span class="align-middle">URUScript Learning Platform</span>
                    <p class="text-white-50 mb-0 mt-2">Interactive JavaScript learning with analytics</p>
                </div>
                <div class="col-md-6 text-md-end">
                    <p class="text-white-50 mb-0">&copy; <?php echo date('Y'); ?> URUScript. All rights reserved.</p>
                </div>
            </div>
        </div>
    </footer>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://code.jquery.com/jquery-3.6.4.min.js"></script>
    <script>
        let progressChart;
        let currentChartRange = 'week';
        
        // Load dashboard data
        $(document).ready(function() {
            loadTopicProgress();
            loadRecentActivity();
            initializeProgressChart();
            
            // Set up chart button click handlers
            $('.chart-filter-buttons .btn').on('click', function() {
                // Remove active class from all buttons
                $('.chart-filter-buttons .btn').removeClass('active');
                // Add active class to clicked button
                $(this).addClass('active');
                
                // Get the range from data-range attribute or onclick parameter
                const range = $(this).data('range') || $(this).attr('onclick')?.replace("updateChart('", "").replace("')", "");
                
                if (range) {
                    updateChart(range);
                }
            });
            
            // Update dashboard every 30 seconds
            setInterval(() => {
                loadTopicProgress();
                loadRecentActivity();
            }, 30000);
        });
        
        // Load topic progress via AJAX
    function loadTopicProgress() {
        $.ajax({
            url: 'ajax.php?action=get_quiz_stats',
            type: 'GET',
            dataType: 'json',
            success: function(response) {
                if (response && response.topics_progress) {
                    let html = '';
                    response.topics_progress.forEach(topic => {
                        const progress = topic.total_lessons > 0 ? 
                            Math.round((topic.completed_lessons / topic.total_lessons) * 100) : 0;
                        const isComplete = progress === 100;
                        
                        html += `
                        <div class="topic-progress-card ${isComplete ? 'complete' : ''}">
                            <div class="d-flex justify-content-between align-items-center mb-2">
                                <h6 class="mb-0 fw-bold">${topic.topic_name}</h6>
                                <span class="badge ${isComplete ? 'bg-success' : 'bg-primary'}">
                                    ${progress}%
                                </span>
                            </div>
                            <div class="progress mb-2" style="height: 8px;">
                                <div class="progress-bar ${isComplete ? 'bg-success' : ''}" 
                                     style="width: ${progress}%"></div>
                            </div>
                            <div class="d-flex justify-content-between">
                                <small class="text-muted">
                                    ${topic.completed_lessons}/${topic.total_lessons} lessons
                                </small>
                                <small class="${isComplete ? 'text-success' : 'text-primary'}">
                                    ${isComplete ? '✓ Complete' : 'In Progress'}
                                </small>
                            </div>
                        </div>
                        `;
                    });
                    $('#topicProgressContainer').html(html);
                }
            }
        });
    }
    
    // Load recent activity
    function loadRecentActivity() {
        $.ajax({
            url: 'ajax.php?action=get_recent_activity',
            type: 'GET',
            dataType: 'json',
            success: function(response) {
                if (response && response.activities) {
                    let html = '';
                    if (response.activities.length > 0) {
                        response.activities.forEach(activity => {
                            const timeAgo = getTimeAgo(activity.attempted_at);
                            html += `
                            <div class="d-flex align-items-center border-bottom py-3">
                                <div class="me-3">
                                    <span class="badge ${activity.is_correct ? 'bg-success' : 'bg-danger'} p-2">
                                        <i class="fas ${activity.is_correct ? 'fa-check' : 'fa-times'}"></i>
                                    </span>
                                </div>
                                <div class="flex-grow-1">
                                    <div class="fw-bold">${activity.lesson_title}</div>
                                    <small class="text-muted">${activity.topic_name} • ${timeAgo}</small>
                                </div>
                                <div class="text-end">
                                    <small class="text-muted">${activity.time_spent}s</small>
                                    ${activity.is_correct ? '<div class="text-success small">+10 pts</div>' : ''}
                                </div>
                            </div>
                            `;
                        });
                    } else {
                        html = `
                        <div class="text-center py-5">
                            <i class="fas fa-clipboard-list fa-3x text-muted mb-3"></i>
                            <p class="text-muted">No recent activity</p>
                            <a href="index" class="btn btn-primary">Start Learning</a>
                        </div>
                        `;
                    }
                    $('#recentActivityContainer').html(html);
                }
            },
            error: function() {
                $('#recentActivityContainer').html('<p class="text-muted text-center">Failed to load activity</p>');
            }
        });
    }
        
        // Initialize progress chart
    function initializeProgressChart() {
        const ctx = document.getElementById('progressChart').getContext('2d');
        
        // Sample data for different ranges
        const chartData = {
            week: {
                labels: ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'],
                lessons: [3, 5, 2, 6, 4, 7, 3],
                accuracy: [65, 70, 80, 75, 85, 90, 88]
            },
            month: {
                labels: ['Week 1', 'Week 2', 'Week 3', 'Week 4'],
                lessons: [15, 18, 22, 20],
                accuracy: [68, 72, 78, 85]
            },
            all: {
                labels: ['Month 1', 'Month 2', 'Month 3'],
                lessons: [45, 60, 75],
                accuracy: [65, 75, 82]
            }
        };
        
        const data = chartData[currentChartRange] || chartData.week;
        
        if (progressChart) {
            progressChart.destroy();
        }
        
        progressChart = new Chart(ctx, {
            type: 'line',
            data: {
                labels: data.labels,
                datasets: [{
                    label: 'Lessons Completed',
                    data: data.lessons,
                    borderColor: '#4361ee',
                    backgroundColor: 'rgba(67, 97, 238, 0.1)',
                    borderWidth: 2,
                    fill: true,
                    tension: 0.4,
                    pointBackgroundColor: '#4361ee',
                    pointBorderColor: '#ffffff',
                    pointBorderWidth: 2,
                    pointRadius: 6,
                    pointHoverRadius: 8
                }, {
                    label: 'Quiz Accuracy %',
                    data: data.accuracy,
                    borderColor: '#06d6a0',
                    backgroundColor: 'rgba(6, 214, 160, 0.1)',
                    borderWidth: 2,
                    fill: true,
                    tension: 0.4,
                    pointBackgroundColor: '#06d6a0',
                    pointBorderColor: '#ffffff',
                    pointBorderWidth: 2,
                    pointRadius: 6,
                    pointHoverRadius: 8
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        position: 'top',
                        labels: {
                            padding: 20,
                            usePointStyle: true,
                        }
                    },
                    tooltip: {
                        mode: 'index',
                        intersect: false,
                        backgroundColor: 'rgba(0, 0, 0, 0.7)',
                        padding: 10,
                        titleFont: {
                            size: 12
                        },
                        bodyFont: {
                            size: 12
                        }
                    }
                },
                interaction: {
                    intersect: false,
                    mode: 'index'
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        max: 100,
                        grid: {
                            drawBorder: false,
                            color: 'rgba(0, 0, 0, 0.05)'
                        },
                        ticks: {
                            padding: 10
                        }
                    },
                    x: {
                        grid: {
                            display: false
                        },
                        ticks: {
                            padding: 10
                        }
                    }
                },
                elements: {
                    line: {
                        tension: 0.4
                    }
                }
            }
        });
    }
        
        // Update chart data
    function updateChart(range) {
        currentChartRange = range;
        initializeProgressChart();
        
        // Show loading state
        const chartContainer = document.getElementById('progressChart').parentElement;
        chartContainer.innerHTML = '<div class="loading-wave"><div></div><div></div><div></div><div></div></div>';
        
        // Simulate API call delay
        setTimeout(() => {
            initializeProgressChart();
        }, 500);
    }
        
        // Time ago utility
    function getTimeAgo(dateString) {
        const date = new Date(dateString);
        const now = new Date();
        const seconds = Math.floor((now - date) / 1000);
        
        if (seconds < 60) return 'just now';
        const minutes = Math.floor(seconds / 60);
        if (minutes < 60) return `${minutes}m ago`;
        const hours = Math.floor(minutes / 60);
        if (hours < 24) return `${hours}h ago`;
        const days = Math.floor(hours / 24);
        if (days < 7) return `${days}d ago`;
        return date.toLocaleDateString();
    }
        // Toast notification function
        function showToast(message, type = 'success') {
            const toastContainer = $('.toast-container');
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
            
            toastContainer.append(toastHtml);
            const toast = new bootstrap.Toast(document.getElementById(toastId));
            toast.show();
            
            // Remove toast after it hides
            document.getElementById(toastId).addEventListener('hidden.bs.toast', function () {
                this.remove();
            });
        }
        
        // Login form handling with AJAX
        $('#loginForm').submit(function(e) {
            e.preventDefault();
            
            const username = $('#loginUsername').val();
            const password = $('#loginPassword').val();
            
            // Basic validation
            if (!username || !password) {
                showToast('Please fill in all fields', 'warning');
                return;
            }
            
            // Show loading state
            $('#loginButtonText').addClass('d-none');
            $('#loginLoading').removeClass('d-none');
            $('#loginMessage').html('').removeClass('alert alert-danger');
            
            $.ajax({
                url: 'index.php',
                type: 'POST',
                data: {
                    action: 'login',
                    username: username,
                    password: password
                },
                dataType: 'json',
                success: function(response) {
                    if (response.success) {
                        showToast('Login successful! Welcome back ' + response.student.username + '!', 'success');
                        setTimeout(() => {
                            location.reload();
                        }, 1500);
                    } else {
                        $('#loginMessage').html(`
                            <div class="alert alert-danger">
                                <i class="fas fa-exclamation-circle me-2"></i>
                                ${response.message}
                            </div>
                        `);
                        $('#loginButtonText').removeClass('d-none');
                        $('#loginLoading').addClass('d-none');
                    }
                },
                error: function() {
                    $('#loginMessage').html(`
                        <div class="alert alert-danger">
                            <i class="fas fa-exclamation-circle me-2"></i>
                            Network error. Please try again.
                        </div>
                    `);
                    $('#loginButtonText').removeClass('d-none');
                    $('#loginLoading').addClass('d-none');
                }
            });
        });
        
        // Registration form handling with AJAX
        $('#registerForm').submit(function(e) {
            e.preventDefault();
            
            const full_name = $('#registerName').val();
            const username = $('#registerUsername').val();
            const email = $('#registerEmail').val();
            const password = $('#registerPassword').val();
            
            // Basic validation
            if (!full_name || !username || !email || !password) {
                showToast('Please fill in all fields', 'warning');
                return;
            }
            
            if (password.length < 6) {
                showToast('Password must be at least 6 characters', 'warning');
                return;
            }
            
            // Show loading state
            $('#registerButtonText').addClass('d-none');
            $('#registerLoading').removeClass('d-none');
            $('#registerMessage').html('').removeClass('alert alert-danger alert-success');
            
            $.ajax({
                url: 'index.php',
                type: 'POST',
                data: {
                    action: 'register',
                    full_name: full_name,
                    username: username,
                    email: email,
                    password: password
                },
                dataType: 'json',
                success: function(response) {
                    if (response.success) {
                        showToast('Registration successful! Welcome to JS Tutorial!', 'success');
                        setTimeout(() => {
                            location.reload();
                        }, 1500);
                    } else {
                        $('#registerMessage').html(`
                            <div class="alert alert-danger">
                                <i class="fas fa-exclamation-circle me-2"></i>
                                ${response.message}
                            </div>
                        `);
                        $('#registerButtonText').removeClass('d-none');
                        $('#registerLoading').addClass('d-none');
                    }
                },
                error: function() {
                    $('#registerMessage').html(`
                        <div class="alert alert-danger">
                            <i class="fas fa-exclamation-circle me-2"></i>
                            Network error. Please try again.
                        </div>
                    `);
                    $('#registerButtonText').removeClass('d-none');
                    $('#registerLoading').addClass('d-none');
                }
            });
        });
        
                 // Show quiz statistics
       // Update the showQuizStats() function
function showQuizStats() {
    const modal = new bootstrap.Modal(document.getElementById('quizStatsModal'));
    modal.show();
    
    // Load statistics via AJAX
    $.ajax({
        url: 'ajax.php?action=get_quiz_stats',
        type: 'GET',
        dataType: 'json',
        success: function(stats) {
            if (stats && !stats.error) {
                // Calculate remaining lessons
                const remainingLessons = stats.total_active_lessons - stats.total_finished_lessons;
                
                // Check if all topics are completed
                let allTopicsCompleted = true;
                if (stats.topics_progress && stats.topics_progress.length > 0) {
                    stats.topics_progress.forEach(topic => {
                        if (topic.total_lessons > 0 && topic.completed_lessons !== topic.total_lessons) {
                            allTopicsCompleted = false;
                        }
                    });
                }
                
                const statsHtml = `
                    <!-- Stats Overview Cards -->
                    <div class="row text-center mb-4">
                        <!-- Quiz Accuracy -->
                        <div class="col-md-4 mb-3">
                            <div class="card border-primary h-100">
                                <div class="card-body">
                                    <i class="fas fa-chart-line fa-2x text-primary mb-3"></i>
                                    <div class="display-6 fw-bold text-primary">${stats.accuracy_rate || 0}%</div>
                                    <small class="text-muted">Quiz Accuracy</small>
                                    <div class="mt-2">
                                        <small class="text-muted">${stats.correct_attempts || 0}/${stats.total_attempts || 0} correct</small>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Lessons Completed -->
                        <div class="col-md-4 mb-3">
                            <div class="card border-success h-100">
                                <div class="card-body">
                                    <i class="fas fa-book fa-2x text-success mb-3"></i>
                                    <div class="display-6 fw-bold text-success">${stats.total_finished_lessons || 0}</div>
                                    <small class="text-muted">Lessons Completed</small>
                                    <div class="mt-2">
                                        <small class="text-muted">${stats.lessons_completion_rate || 0}% of all lessons</small>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Avg Time Per Question -->
                        <div class="col-md-4 mb-3">
                            <div class="card border-info h-100">
                                <div class="card-body">
                                    <i class="fas fa-clock fa-2x text-info mb-3"></i>
                                    <div class="display-6 fw-bold text-info">${Math.round(stats.avg_time_spent || 0)}s</div>
                                    <small class="text-muted">Avg. Time/Question</small>
                                    <div class="mt-2">
                                        <small class="text-muted">Total attempts: ${stats.total_attempts || 0}</small>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Lessons Progress Section -->
                    <div class="mb-4">
                        <div class="d-flex justify-content-between align-items-center mb-3">
                            <h6 class="mb-0">
                                <i class="fas fa-graduation-cap me-2"></i>Overall Learning Progress
                            </h6>
                            <span class="badge ${stats.lessons_completion_rate >= 100 ? 'bg-success' : 'bg-primary'}">
                                ${stats.lessons_completion_rate || 0}%
                            </span>
                        </div>
                        
                        <!-- Main Progress Bar -->
                        <div class="progress mb-3" style="height: 20px;">
                            <div class="progress-bar ${stats.lessons_completion_rate >= 100 ? 'bg-success' : 'bg-primary'} 
                                 ${stats.lessons_completion_rate < 100 ? 'progress-bar-striped progress-bar-animated' : ''}" 
                                 style="width: ${stats.lessons_completion_rate || 0}%">
                                <span class="fw-bold">${stats.lessons_completion_rate || 0}%</span>
                            </div>
                        </div>
                        
                        <!-- Lessons Counter -->
                        <div class="row text-center">
                            <div class="col-4">
                                <div class="h5 fw-bold text-success">${stats.total_finished_lessons || 0}</div>
                                <small class="text-muted">Completed</small>
                            </div>
                            <div class="col-4">
                                <div class="h5 fw-bold ${remainingLessons > 0 ? 'text-warning' : 'text-success'}">
                                    ${remainingLessons >= 0 ? remainingLessons : 0}
                                </div>
                                <small class="text-muted">Remaining</small>
                            </div>
                            <div class="col-4">
                                <div class="h5 fw-bold text-primary">${stats.total_active_lessons || 0}</div>
                                <small class="text-muted">Total Lessons</small>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Quiz Performance Breakdown -->
                    <div class="mb-4">
                        <h6 class="mb-3">
                            <i class="fas fa-chart-pie me-2"></i>Quiz Performance Breakdown
                        </h6>
                        <div class="mb-3">
                            <div class="d-flex justify-content-between mb-1">
                                <span>${stats.total_attempts || 0} Total Attempts</span>
                                <span>${stats.accuracy_rate || 0}% Accuracy</span>
                            </div>
                            <div class="progress" style="height: 25px;">
                                <div class="progress-bar bg-success" style="width: ${stats.accuracy_rate || 0}%">
                                    ${stats.correct_attempts || 0} Correct
                                </div>
                                <div class="progress-bar bg-danger" style="width: ${100 - (stats.accuracy_rate || 0)}%">
                                    ${stats.incorrect_attempts || 0} Incorrect
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Topic-wise Progress -->
                    ${stats.topics_progress && stats.topics_progress.length > 0 ? `
                    <div class="mb-4">
                        <div class="d-flex justify-content-between align-items-center mb-3">
                            <h6 class="mb-0">
                                <i class="fas fa-layer-group me-2"></i>Topic-wise Progress
                            </h6>
                            <span class="badge bg-primary">${stats.topics_progress.length} Topics</span>
                        </div>
                        
                        <div class="accordion" id="topicsAccordion">
                            ${stats.topics_progress.map((topic, index) => {
                                const topicProgress = topic.total_lessons > 0 ? 
                                    Math.round((topic.completed_lessons / topic.total_lessons) * 100) : 0;
                                const isComplete = topicProgress === 100;
                                
                                return `
                                <div class="accordion-item">
                                    <h2 class="accordion-header" id="heading${index}">
                                        <button class="accordion-button ${index > 0 ? 'collapsed' : ''}" 
                                                type="button" data-bs-toggle="collapse" 
                                                data-bs-target="#collapse${index}" 
                                                aria-expanded="${index === 0 ? 'true' : 'false'}" 
                                                aria-controls="collapse${index}">
                                            <div class="d-flex w-100 align-items-center">
                                                <div class="me-3">
                                                    <i class="fas ${isComplete ? 'fa-check-circle text-success' : 'fa-book text-primary'}"></i>
                                                </div>
                                                <div class="flex-grow-1">
                                                    <strong>${topic.topic_name}</strong>
                                                    <div class="progress mt-1" style="height: 6px;">
                                                        <div class="progress-bar ${isComplete ? 'bg-success' : ''}" 
                                                             style="width: ${topicProgress}%"></div>
                                                    </div>
                                                </div>
                                                <div class="ms-2">
                                                    <span class="badge ${isComplete ? 'bg-success' : 'bg-primary'}">
                                                        ${topic.completed_lessons}/${topic.total_lessons}
                                                    </span>
                                                </div>
                                            </div>
                                        </button>
                                    </h2>
                                    <div id="collapse${index}" 
                                         class="accordion-collapse collapse ${index === 0 ? 'show' : ''}" 
                                         aria-labelledby="heading${index}" 
                                         data-bs-parent="#topicsAccordion">
                                        <div class="accordion-body">
                                            <div class="row">
                                                <div class="col-6">
                                                    <small class="text-muted">Progress:</small>
                                                    <div class="h5 ${isComplete ? 'text-success' : 'text-primary'}">
                                                        ${topicProgress}%
                                                    </div>
                                                </div>
                                                <div class="col-6">
                                                    <small class="text-muted">Status:</small>
                                                    <div>
                                                        <span class="badge ${isComplete ? 'bg-success' : 'bg-warning'}">
                                                            ${isComplete ? 'Complete' : 'In Progress'}
                                                        </span>
                                                    </div>
                                                </div>
                                            </div>
                                            ${!isComplete ? `
                                            <div class="mt-2">
                                                <small class="text-muted">
                                                    ${topic.total_lessons - topic.completed_lessons} lesson(s) remaining
                                                </small>
                                            </div>
                                            ` : ''}
                                        </div>
                                    </div>
                                </div>
                                `;
                            }).join('')}
                        </div>
                    </div>
                    ` : ''}
                    
                    <!-- Achievement Badge -->
                    ${stats.lessons_completion_rate >= 100 ? `
                    <div class="alert alert-success text-center">
                        <i class="fas fa-trophy fa-2x me-2"></i>
                        <strong>Congratulations! You've completed all available lessons!</strong>
                        <div class="mt-2">
                            <small>Keep up the great work! Consider reviewing completed topics to reinforce your learning.</small>
                        </div>
                    </div>
                    ` : allTopicsCompleted ? `
                    <div class="alert alert-info text-center">
                        <i class="fas fa-star fa-2x me-2"></i>
                        <strong>Great job! You've completed all topics!</strong>
                        <div class="mt-2">
                            <small>You've finished all available lessons in every topic. Excellent progress!</small>
                        </div>
                    </div>
                    ` : ''}
                `;
                $('#quizStatsContent').html(statsHtml);
            } else {
                $('#quizStatsContent').html(`
                    <div class="alert alert-info">
                        <i class="fas fa-info-circle me-2"></i>
                        ${stats && stats.error ? stats.error : 'No statistics available yet. Start learning to track your progress!'}
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
        // Logout function
        function logout() {
            if (confirm('Are you sure you want to logout?')) {
                $.ajax({
                    url: 'index.php',
                    type: 'POST',
                    data: { action: 'logout' },
                    dataType: 'json',
                    success: function(response) {
                        if (response.success) {
                            showToast('Logged out successfully!', 'info');
                            setTimeout(() => {
                                location.reload();
                            }, 1000);
                        }
                    },
                    error: function() {
                        showToast('Error during logout. Please try again.', 'danger');
                    }
                });
            }
        }
        

        
        // Auto-close modals on success
        $(document).ajaxSuccess(function(event, xhr, settings) {
            if (settings.url === '/' && settings.type === 'POST') {
                const data = JSON.parse(xhr.responseText);
                if (data.success) {
                    if (settings.data.includes('action=login') || settings.data.includes('action=register')) {
                        setTimeout(() => {
                            $('#loginModal').modal('hide');
                            $('#registerModal').modal('hide');
                        }, 1000);
                    }
                }
            }
        });
    </script>
</body>
</html>