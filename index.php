<?php
// Test for Git Actions.
require_once 'database.php';

$db = new Database();
$conn = $db->getConnection();
$studentSession = new StudentSession($conn);

// Handle login/logout actions
if (isset($_POST['action'])) {
    $response = [];
    
    if ($_POST['action'] == 'login') {
        $response = $studentSession->login($_POST['username'], $_POST['password']);
    } elseif ($_POST['action'] == 'register') {
        $response = $studentSession->register(
            $_POST['full_name'],
            $_POST['username'],
            $_POST['email'],
            $_POST['password']
        );
    } elseif ($_POST['action'] == 'logout') {
        $response = $studentSession->logout();
    }
    
    header('Content-Type: application/json');
    echo json_encode($response);
    exit;
}

// Fetch all active topics
$query = "SELECT * FROM topics WHERE is_active = 1 AND deleted_at IS NULL ORDER BY topic_order";
$stmt = $conn->prepare($query);
$stmt->execute();
$topics = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get student data if logged in
$studentData = $studentSession->getStudentData();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="icon" type="image/x-icon" href="assets/img/URUScript.png">
    <title>Interactive JavaScript Tutorial</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --primary-color: #4361ee;
            --secondary-color: #3a0ca3;
            --accent-color: #4cc9f0;
            --light-bg: #f8f9fa;
            --dark-bg: #212529;
        }
        
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
        }
        
        .score-badge {
            background: linear-gradient(135deg, #ffd166, #ff9e00);
            color: #000;
            font-weight: bold;
            padding: 0.25rem 0.75rem;
            border-radius: 20px;
        }
        
        .toast-container {
            position: fixed;
            top: 20px;
            right: 20px;
            z-index: 1050;
        }
        
        /* Add the rest of your CSS styles here */
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: linear-gradient(135deg, #f5f7fa 0%, #c3cfe2 100%);
            min-height: 100vh;
        }
        
        .hero-section {
            background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
            color: white;
            padding: 4rem 0;
            border-radius: 0 0 2rem 2rem;
            box-shadow: 0 4px 20px rgba(0,0,0,0.1);
        }
        
        .topic-card {
            transition: all 0.3s ease;
            border: none;
            border-radius: 1rem;
            overflow: hidden;
            box-shadow: 0 5px 15px rgba(0,0,0,0.08);
            height: 100%;
        }
        
        .topic-card:hover {
            transform: translateY(-10px);
            box-shadow: 0 15px 30px rgba(0,0,0,0.15);
        }
        
        .topic-icon {
            font-size: 2.5rem;
            color: var(--primary-color);
            margin-bottom: 1rem;
        }
        
        .progress-ring {
            width: 60px;
            height: 60px;
            position: relative;
        }
        
        .progress-ring-circle {
            stroke: var(--primary-color);
            stroke-width: 4;
            fill: transparent;
            stroke-linecap: round;
            transform: rotate(-90deg);
            transform-origin: 50% 50%;
            transition: stroke-dasharray 0.5s ease;
        }
        
        .progress-ring-text {
            position: absolute;
            color: rgb(155, 82, 4);
            top: 30%;
            left: 50%;
            transform: translate(-50%, -50%);
            font-size: 0.6rem;
            font-weight: bold;
        }
    </style>
</head>
<body>
    <!-- Toast Notifications -->
    <div class="toast-container"></div>

    <!-- Navigation -->
    <nav class="navbar navbar-expand-lg navbar-light bg-white shadow-sm">
        <div class="container">
            <a class="navbar-brand fw-bold text-primary" href="#">
                <!-- <i class="fab fa-js-square me-2"></i>JS Tutorial -->
                <img src="assets/img/URUScript.png" alt="JS Tutorial Logo" width="40" height="40"> URUScript
            </a>
            <div class="d-flex align-items-center">
                <?php if ($studentSession->isLoggedIn() && $studentData): ?>
                <div class="d-flex align-items-center me-3">
                    <span class="score-badge me-2">
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
                        <ul class="dropdown-menu dropdown-menu-end">
                            <li>
                                <span class="dropdown-item-text">
                                    <small>Logged in as</small><br>
                                    <strong><?php echo htmlspecialchars($studentData['name']); ?></strong>
                                </span>
                            </li>
                            <li><hr class="dropdown-divider"></li>
                            <li><a class="dropdown-item" href="dashboard">
                                <i class="fas fa-tachometer-alt me-2"></i> Dashboard
                            </a></li>
                            <li><a class="dropdown-item" href="#" onclick="showQuizStats()">
                                <i class="fas fa-chart-bar me-2"></i> Quiz Statistics
                            </a></li>
                            <li><a class="dropdown-item" href="coding-ground">
                                <i class="fas fa-code me-2"></i> Code Arena
                            </a></li>
                            <li><a class="dropdown-item" href="#" onclick="logout()">
                                <i class="fas fa-sign-out-alt me-2"></i> Logout
                            </a></li>
                        </ul>
                    </div>
                </div>
                <?php else: ?>
                <div class="dropdown">
                    <button class="btn btn-outline-primary btn-sm dropdown-toggle" type="button" id="userDropdown" data-bs-toggle="dropdown">
                        <i class="fas fa-user me-1"></i> Guest
                    </button>
                    <ul class="dropdown-menu dropdown-menu-end">
                        <li><a class="dropdown-item" href="#" data-bs-toggle="modal" data-bs-target="#loginModal">
                            <i class="fas fa-sign-in-alt me-2"></i> Login
                        </a></li>
                        <li><a class="dropdown-item" href="#" data-bs-toggle="modal" data-bs-target="#registerModal">
                            <i class="fas fa-user-plus me-2"></i> Register
                        </a></li>
                        
                        <hr />
                        <li><a class="dropdown-item" href="teacher/teacher_login" >
                            <i class="fas fa-door-open me-2"></i> As Teacher
                        </a></li>
                    </ul>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </nav>

    <!-- Hero Section -->
    <section class="hero-section">
        <div class="container">
            <div class="row align-items-center">
                <div class="col-lg-8">
                    <h1 class="display-4 fw-bold mb-3">Zero to Hero! 🚀</h1>
                    <p class="lead mb-4">💡 “Every great developer started with nothing. On this platform, you’ll learn JavaScript from the basics to real-world projects. Stop watching—start creating.”</p>
                    <?php if ($studentSession->isLoggedIn()): ?>
                    <div class="alert alert-success d-inline-flex align-items-center" role="alert">
                        <i class="fas fa-check-circle me-2"></i>
                        <div>Maligayang Pagdating, <b><?= htmlspecialchars($studentData['name']) ?></b>! Halina at Magsimulang Matuto.</div>
                    </div>
                    <?php else: ?>
                    <div class="alert alert-info d-inline-flex align-items-center" role="alert">
                        <i class="fas fa-info-circle me-2"></i>
                        <div><a href="#" class="alert-link" data-bs-toggle="modal" data-bs-target="#registerModal">SALI NA!</a> Hindi kailangan maging IT genius — kailangan mo lang magsimula.</div>
                    </div>
                    <?php endif; ?>
                    <?php if (isset($_GET['error'])): ?>
                    <div class="alert alert-warning d-inline-flex align-items-center" role="alert">
                        <i class="fas fa-info-circle me-2"></i>
                        <div>The Topic you have clicked is currently no lesson(s) uploaded.</div>
                    </div>
                    <?php endif; ?>
                </div>
                <div class="col-lg-4 text-center">
                    <!-- <i class="fab fa-js-square display-1 opacity-75"></i> -->
                    <img src="assets/img/URUScript.png" alt="URUScript Tutorial Logo" title="URUScript Tutorial Logo" class="img-fluid" style="max-height: 150px;">
                </div>
            </div>
        </div>
    </section>

   <!-- Topics Grid -->
<section class="py-5">
    <div class="container">
        <h5 class="text-center mb-5 fw-bold">"Para maintindihan mo ang isang komplikadong bagay, intidihin mo muna ang pinaka-maliit na parte nito." - <i>John Louis Uru</i></h5>
        <div class="row">
            <?php foreach($topics as $index => $topic): 
                // Calculate progress if logged in
                $progress = 0;
                $completedLessons = 0;
                $totalLessons = 0;
                
                if ($studentSession->isLoggedIn()) {
                    $progressQuery = "SELECT 
                        COUNT(DISTINCT sp.lesson_id) as completed_lessons,
                        COUNT(DISTINCT l.id) as total_lessons
                        FROM lessons l
                        LEFT JOIN student_progress sp ON l.id = sp.lesson_id 
                            AND sp.student_id = ? 
                            AND sp.is_completed = 1
                        WHERE l.topic_id = ? 
                        AND l.is_active = 1";
                    $progressStmt = $conn->prepare($progressQuery);
                    $progressStmt->execute([$studentSession->getStudentId(), $topic['id']]);
                    $progressData = $progressStmt->fetch(PDO::FETCH_ASSOC);
                    
                    if ($progressData && $progressData['total_lessons'] > 0) {
                        $completedLessons = $progressData['completed_lessons'];
                        $totalLessons = $progressData['total_lessons'];
                        $progress = round(($completedLessons / $totalLessons) * 100);
                    }
                }
            ?>
            <div class="col-md-6 col-lg-4 mb-4">
                <div class="topic-card bg-white p-4 d-flex flex-column h-100">
                    <div class="d-flex justify-content-between align-items-start mb-3">
                        <div>
                            <div class="topic-icon">
                                <?php 
                                $icons = ['fa-code', 'fa-book', 'fa-terminal', 'fa-vial', 'fa-list', 'fa-filter', 'fa-redo'];
                                echo '<i class="fas ' . $icons[$index % count($icons)] . '"></i>';
                                ?>
                            </div>
                            <h4 class="fw-bold mt-2"><?php echo htmlspecialchars($topic['topic_name']); ?></h4>
                            <p class="text-muted mb-2"><?php echo htmlspecialchars($topic['description'] ?? 'Learn with interactive examples'); ?></p>
                        </div>
                    </div>
                    
                    <!-- Progress Bar Section -->
                    <div class="mt-auto">
                        <div class="progress-info mb-2 d-flex justify-content-between">
                            <small class="text-muted">Progress</small>
                            <small class="fw-bold <?php echo $progress == 100 ? 'text-success' : 'text-primary'; ?>">
                                <?php echo $progress; ?>%
                            </small>
                        </div>
                        
                        <!-- Progress Bar -->
                        <div class="progress" style="height: 8px; border-radius: 4px;">
                            <div class="progress-bar <?php echo $progress == 100 ? 'bg-success' : ''; ?>"
                                 role="progressbar" 
                                 style="width: <?php echo $progress; ?>%;"
                                 aria-valuenow="<?php echo $progress; ?>" 
                                 aria-valuemin="0" 
                                 aria-valuemax="100">
                            </div>
                        </div>
                        
                        <!-- Progress Text -->
                        <div class="progress-details mt-2">
                            <small class="text-muted">
                                <i class="fas fa-check-circle me-1 <?php echo $progress == 100 ? 'text-success' : 'text-primary'; ?>"></i>
                                <?php echo $completedLessons; ?> of <?php echo $totalLessons; ?> lessons completed
                            </small>
                        </div>
                    </div>
                    
                    <!-- Button -->
                    <a href="lesson?topic_id=<?php echo $topic['id']; ?>" class="btn btn-primary w-100 mt-3">
                        <?php echo $progress > 0 ? 'Revisit Lesson!' : 'Let`s Start!'; ?> 
                        <i class="fas fa-arrow-right ms-2"></i>
                    </a>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
</section>

    <!-- Features Section -->
    <section class="py-5 bg-white">
        <div class="container">
            <div class="row text-center">
                <div class="col-md-4 mb-4">
                    <div class="p-4">
                        <i class="fas fa-laptop-code fa-3x text-primary mb-3"></i>
                        <h4>Interactive Coding</h4>
                        <p class="text-muted">Gumawa at magpatakbo ng JavaScript code direkta sa iyong browser</p>
                    </div>
                </div>
                <div class="col-md-4 mb-4">
                    <div class="p-4">
                        <i class="fas fa-tasks fa-3x text-primary mb-3"></i>
                        <h4>Pagsubaybay sa Pagkatuto</h4>
                        <p class="text-muted">Subaybayan ang iyong pagkatuto sa pag-aaral at reviewhin ang mga natapos na paksa</p>
                    </div>
                </div>
                <div class="col-md-4 mb-4">
                    <div class="p-4">
                        <i class="fas fa-mobile-alt fa-3x text-primary mb-3"></i>
                        <h4>Kahit Saan!</h4>
                        <p class="text-muted">Mag-aral sa anumang device gamit ang aming responsive na application</p>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- Login Modal -->
    <div class="modal fade" id="loginModal" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header border-0">
                    <h5 class="modal-title fw-bold">Login to Continue</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <form id="loginForm">
                        <div class="mb-3">
                            <label class="form-label">Username</label>
                            <input type="text" class="form-control" id="loginUsername" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Password</label>
                            <input type="password" class="form-control" id="loginPassword" required>
                        </div>
                        <button type="submit" class="btn btn-primary w-100">
                            <span id="loginButtonText">Login</span>
                            <span id="loginLoading" class="spinner-border spinner-border-sm d-none" role="status"></span>
                        </button>
                    </form>
                    <div id="loginMessage" class="mt-3"></div>
                </div>
            </div>
        </div>
    </div>

    <!-- Register Modal -->
    <div class="modal fade" id="registerModal" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header border-0">
                    <h5 class="modal-title fw-bold">Create Account</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <form id="registerForm">
                        <div class="mb-3">
                            <label class="form-label">Full Name</label>
                            <input type="text" class="form-control" id="registerName" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Username</label>
                            <input type="text" class="form-control" id="registerUsername" required>
                            <small class="form-text text-muted">This will be your display name</small>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Email</label>
                            <input type="email" class="form-control" id="registerEmail" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Password</label>
                            <input type="password" class="form-control" id="registerPassword" required>
                            <small class="form-text text-muted">Minimum 6 characters</small>
                        </div>
                        <button type="submit" class="btn btn-primary w-100">
                            <span id="registerButtonText">Register</span>
                            <span id="registerLoading" class="spinner-border spinner-border-sm d-none" role="status"></span>
                        </button>
                    </form>
                    <div id="registerMessage" class="mt-3"></div>
                </div>
            </div>
        </div>
    </div>

    <!-- Quiz Statistics Modal -->
    <div class="modal fade" id="quizStatsModal" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header bg-info text-white">
                    <h5 class="modal-title">
                        <i class="fas fa-chart-bar me-2"></i>Your Quiz Statistics
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div id="quizStatsContent">
                        <div class="text-center">
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

    <!-- Footer -->
    <footer class="bg-dark text-white py-4 mt-5">
        <div class="container">
            <div class="row">
                <div class="col-md-6">
                    <img src="assets/img/URUScript.png" alt="URUScript Tutorial Logo" title="URUScript Tutorial Logo" class="img-fluid" style="max-height: 50px;">&nbsp <a href="https://www.github.com/johnlouisuru">URUScript Tutorial Platform</a> 
                    
                    <p class="text-white">An interactive learning platform for mastering JavaScript</p>
                </div>
                <div class="col-md-6 text-md-end">
                    <p class="text-white mb-0">&copy; 2026 URUScript Tutorial. All rights reserved.</p>
                </div>
            </div>
        </div>
    </footer>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://code.jquery.com/jquery-3.6.4.min.js"></script>
    <script>
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