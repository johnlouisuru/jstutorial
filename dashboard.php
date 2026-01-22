<?php
require_once 'database.php';

$db = new Database();
$conn = $db->getConnection();
$studentSession = new StudentSession($conn);

if (!$studentSession->isLoggedIn()) {
    header('Location: index.php');
    exit;
}

$studentData = $studentSession->getStudentData();
$stats = $studentSession->getQuizStatistics();
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
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            font-size: 0.8rem;
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
                            <li><a class="dropdown-item" href="index">
                                <i class="fas fa-tachometer-alt me-2"></i> Topics
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
                        <i class="fas fa-user me-1"></i> Panauhin
                    </button>
                    <ul class="dropdown-menu dropdown-menu-end">
                        <li><a class="dropdown-item" href="#" data-bs-toggle="modal" data-bs-target="#loginModal">
                            <i class="fas fa-sign-in-alt me-2"></i> Mag-Login
                        </a></li>
                        <li><a class="dropdown-item" href="#" data-bs-toggle="modal" data-bs-target="#registerModal">
                            <i class="fas fa-user-plus me-2"></i> Sumali
                        </a></li>
                        <hr />
                        <li><a class="dropdown-item" href="teacher/teacher_login" >
                            <i class="fas fa-sign-in-alt me-2"></i> Isang Guro
                        </a></li>
                    </ul>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </nav>
    
    <div class="container py-5">
        <div class="row">
            <div class="col-md-4">
                <!-- Student Profile Card -->
                <div class="card mb-4">
                    <div class="card-body text-center">
                        <div class="user-avatar mx-auto mb-3" style="background-color: <?php echo $studentData['avatar']; ?>; width: 100px; height: 100px; font-size: 36px;">
                            <?php echo strtoupper(substr($studentData['name'], 0, 1)); ?>
                        </div>
                        <h4><?php echo htmlspecialchars($studentData['name']); ?></h4>
                        <p class="text-muted">@<?php echo htmlspecialchars($studentData['username']); ?></p>
                        <div class="d-flex justify-content-center mb-3">
                            <div class="me-4 text-center">
                                <div class="h3 mb-0"><?php echo $studentData['score']; ?></div>
                                <small class="text-muted">Points</small>
                            </div>
                            <div class="text-center">
                                <div class="h3 mb-0"><?php echo $stats['total_attempts'] ?? 0; ?></div>
                                <small class="text-muted">Quiz Attempts</small>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Quick Stats -->
                <div class="card">
                    <div class="card-header">
                        <h5 class="mb-0">Quick Stats</h5>
                    </div>
                    <div class="card-body">
                        <div class="mb-3">
                            <label class="form-label">Accuracy Rate</label>
                            <div class="progress" style="height: 25px;">
                                <div class="progress-bar bg-success" style="width: <?php echo $stats['accuracy_rate'] ?? 0; ?>%">
                                    <?php echo $stats['accuracy_rate'] ?? 0; ?>%
                                </div>
                            </div>
                        </div>
                        <div class="row">
                            <div class="col-6">
                                <div class="text-center">
                                    <div class="h4 mb-0 text-success"><?php echo $stats['correct_attempts'] ?? 0; ?></div>
                                    <small>Correct</small>
                                </div>
                            </div>
                            <div class="col-6">
                                <div class="text-center">
                                    <div class="h4 mb-0 text-danger"><?php echo $stats['incorrect_attempts'] ?? 0; ?></div>
                                    <small>Incorrect</small>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="col-md-8">
                <!-- Recent Activity -->
                <div class="card mb-4">
                    <div class="card-header">
                        <h5 class="mb-0">Recent Quiz Activity</h5>
                    </div>
                    <div class="card-body">
                        <?php
                        $recentQuery = "SELECT sqa.*, q.question, l.lesson_title, t.topic_name
                                        FROM student_quiz_attempts sqa
                                        JOIN quizzes q ON sqa.quiz_id = q.id
                                        JOIN lessons l ON q.lesson_id = l.id
                                        JOIN topics t ON l.topic_id = t.id
                                        WHERE sqa.student_id = ?
                                        AND l.deleted_at IS NULL
                                        ORDER BY sqa.attempted_at DESC
                                        LIMIT 10";
                        $recentStmt = $conn->prepare($recentQuery);
                        $recentStmt->execute([$studentSession->getStudentId()]);
                        $recentAttempts = $recentStmt->fetchAll(PDO::FETCH_ASSOC);
                        
                        if (count($recentAttempts) > 0):
                            foreach($recentAttempts as $attempt):
                        ?>
                        <div class="d-flex align-items-center border-bottom py-3">
                            <div class="me-3">
                                <?php if ($attempt['is_correct']): ?>
                                <span class="badge bg-success">
                                    <i class="fas fa-check"></i>
                                </span>
                                <?php else: ?>
                                <span class="badge bg-danger">
                                    <i class="fas fa-times"></i>
                                </span>
                                <?php endif; ?>
                            </div>
                            <div class="flex-grow-1">
                                <div class="fw-bold"><?php echo htmlspecialchars($attempt['lesson_title']); ?></div>
                                <small class="text-muted"><?php echo htmlspecialchars($attempt['topic_name']); ?> • <?php echo date('M d, Y H:i', strtotime($attempt['attempted_at'])); ?></small>
                            </div>
                            <div class="text-end">
                                <small class="text-muted"><?php echo $attempt['time_spent']; ?>s</small>
                                <?php if ($attempt['is_correct']): ?>
                                <div class="text-success small">+10 pts</div>
                                <?php endif; ?>
                            </div>
                        </div>
                        <?php endforeach; ?>
                        <?php else: ?>
                        <div class="text-center py-4">
                            <i class="fas fa-clipboard-list fa-3x text-muted mb-3"></i>
                            <p class="text-muted">No quiz attempts yet. Start learning!</p>
                            <a href="index.php" class="btn btn-primary">Browse Topics</a>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
                
                <!-- Progress Overview -->
                <div class="card">
                    <div class="card-header">
                        <h5 class="mb-0">Learning Progress</h5>
                    </div>
                    <div class="card-body">
                        <?php
                        $progressQuery = "SELECT t.id, t.topic_name, 
                                        COUNT(DISTINCT l.id) as total_lessons,
                                        COUNT(DISTINCT sp.lesson_id) as completed_lessons
                                        FROM topics t
                                        JOIN lessons l ON t.id = l.topic_id AND l.is_active = 1
                                        LEFT JOIN student_progress sp ON l.id = sp.lesson_id 
                                            AND sp.student_id = ? 
                                            AND sp.is_completed = 1
                                        WHERE t.is_active = 1
                                        AND l.deleted_at IS NULL
                                        GROUP BY t.id
                                        ORDER BY t.topic_order";
                        $progressStmt = $conn->prepare($progressQuery);
                        $progressStmt->execute([$studentSession->getStudentId()]);
                        $progressData = $progressStmt->fetchAll(PDO::FETCH_ASSOC);
                        
                        foreach($progressData as $topic):
                            $percentage = $topic['total_lessons'] > 0 ? 
                                round(($topic['completed_lessons'] / $topic['total_lessons']) * 100) : 0;
                        ?>
                        <div class="mb-3">
                            <div class="d-flex justify-content-between mb-1">
                                <span><?php echo htmlspecialchars($topic['topic_name']); ?></span>
                                <span><?php echo $percentage; ?>%</span>
                            </div>
                            <div class="progress">
                                <div class="progress-bar" style="width: <?php echo $percentage; ?>%">
                                    <?php echo $topic['completed_lessons']; ?>/<?php echo $topic['total_lessons']; ?> lessons
                                </div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
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
                    <p class="text-white mb-0">&copy; 2024 JS Tutorial. All rights reserved.</p>
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
                                ${stats && stats.error ? stats.error : 'No quiz statistics available yet. Start learning to track your progress!'}
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