<?php
require_once 'database.php';

$db = new Database();
$conn = $db->getConnection();
$studentSession = new StudentSession($conn);

if (!$studentSession->isLoggedIn()) {
    header('Location: index');
    exit;
}

// Handle quiz submission via AJAX
if (isset($_POST['action']) && $_POST['action'] == 'submit_quiz') {
    $response = [];
    
    if (!$studentSession->isLoggedIn()) {
        $response = ['success' => false, 'message' => 'Please login to save your quiz results'];
    } else {
        $response = $studentSession->saveQuizAttempt(
            $_POST['quiz_id'],
            $_POST['selected_option_id'],
            $_POST['is_correct'],
            $_POST['time_spent'] ?? 0
        );
    }
    
    header('Content-Type: application/json');
    echo json_encode($response);
    exit;
}

$topic_id = $_GET['topic_id'] ?? 1;
$lesson_id = $_GET['lesson_id'] ?? 1;

// Fetch topic details
$topic_query = "SELECT * FROM topics WHERE id = ? AND deleted_at IS NULL";
$topic_stmt = $conn->prepare($topic_query);
$topic_stmt->execute([$topic_id]);
$topic = $topic_stmt->fetch(PDO::FETCH_ASSOC);

if (!$topic) {
    header('Location: index');
    exit;
}

// Fetch all lessons for this topic - FIXED: changed IS NOT NULL to IS NULL
$lessons_query = "SELECT * FROM lessons WHERE topic_id = ? AND is_active = 1 AND deleted_at IS NULL ORDER BY lesson_order";
$lessons_stmt = $conn->prepare($lessons_query);
$lessons_stmt->execute([$topic_id]);
$lessons = $lessons_stmt->fetchAll(PDO::FETCH_ASSOC);

if (count($lessons) == 0) {
    // No lessons in this topic
    header('Location: index?error=no_lessons');
    exit;
}

// Fetch current lesson with topic validation - ADDED topic_id check
$lesson_query = "SELECT * FROM lessons WHERE id = ? AND topic_id = ? AND is_active = 1 AND deleted_at IS NULL";
$lesson_stmt = $conn->prepare($lesson_query);
$lesson_stmt->execute([$lesson_id, $topic_id]);
$lesson = $lesson_stmt->fetch(PDO::FETCH_ASSOC);

if(!$lesson) {
    // If lesson doesn't exist or doesn't belong to this topic, redirect to first lesson
    $first_lesson = $lessons[0];
    header("Location: lesson?topic_id=$topic_id&lesson_id={$first_lesson['id']}");
    exit;
}

// Fetch quiz for this lesson
$quiz_query = "SELECT q.* FROM quizzes q WHERE q.lesson_id = ? AND q.is_active = 1 ORDER BY RAND() LIMIT 1";
$quiz_stmt = $conn->prepare($quiz_query);
$quiz_stmt->execute([$lesson_id]);
$quiz = $quiz_stmt->fetch(PDO::FETCH_ASSOC);

if ($quiz) {
    $options_query = "SELECT * FROM quiz_options WHERE quiz_id = ? ORDER BY option_order";
    $options_stmt = $conn->prepare($options_query);
    $options_stmt->execute([$quiz['id']]);
    $quiz_options = $options_stmt->fetchAll(PDO::FETCH_ASSOC);
}

// ... rest of your code continues ...

// Check if student has already completed this lesson
$is_lesson_completed = false;
if ($studentSession->isLoggedIn()) {
    $progress = $studentSession->getLessonProgress($lesson_id);
    $is_lesson_completed = $progress && $progress['is_completed'] == 1;
}

// Check if student has already attempted this quiz
$has_attempted_quiz = false;
$previous_attempt = null;
if ($studentSession->isLoggedIn() && $quiz) {
    $attempt_query = "SELECT * FROM student_quiz_attempts 
                      WHERE student_id = ? AND quiz_id = ? 
                      ORDER BY attempted_at DESC LIMIT 1";
    $attempt_stmt = $conn->prepare($attempt_query);
    $attempt_stmt->execute([$studentSession->getStudentId(), $quiz['id']]);
    $previous_attempt = $attempt_stmt->fetch(PDO::FETCH_ASSOC);
    $has_attempted_quiz = $previous_attempt !== false;
}

// Get student data safely
$studentData = $studentSession->getStudentData();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="icon" type="image/x-icon" href="assets/img/URUScript.png">
    <title><?php echo htmlspecialchars($lesson['lesson_title'] ?? 'Lesson'); ?> - URUScript</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <!-- Add after your existing CSS links -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.5/codemirror.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.5/theme/dracula.min.css">
    <style>
        .lesson-sidebar {
            background: #f8f9fa;
            height: 100vh;
            position: sticky;
            top: 0;
            overflow-y: auto;
        }
        
        .lesson-content {
            padding: 2rem;
            max-width: 900px;
            margin: 0 auto;
        }
        
        .lesson-nav-link {
            padding: 0.75rem 1rem;
            border-left: 3px solid transparent;
            color: #495057;
            text-decoration: none;
            display: block;
            transition: all 0.2s;
        }
        
        .lesson-nav-link:hover, .lesson-nav-link.active {
            background: #e9ecef;
            border-left-color: #4361ee;
            color: #4361ee;
        }
        
        .code-runner {
            background: #1a1a1a;
            border-radius: 0.5rem;
            margin: 1.5rem 0;
        }
        
        .code-runner-header {
            background: #2d2d2d;
            padding: 0.75rem 1rem;
            color: #ccc;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .code-runner-body {
            padding: 1rem;
        }
        
        .code-runner textarea {
            width: 100%;
            min-height: 200px;
            background: #2d2d2d;
            color: #fff;
            border: none;
            padding: 1rem;
            font-family: 'Courier New', monospace;
            border-radius: 0.25rem;
        }
        
        .output-panel {
            background: #fff;
            min-height: 100px;
            border: 1px solid #dee2e6;
            border-radius: 0.25rem;
            padding: 1rem;
            margin-top: 1rem;
        }
        
        .quiz-option {
            padding: 1rem;
            border: 2px solid #dee2e6;
            border-radius: 0.5rem;
            margin-bottom: 0.75rem;
            cursor: pointer;
            transition: all 0.2s;
        }
        
        .quiz-option:hover {
            border-color: #4361ee;
            background: #f0f5ff;
        }
        
        .quiz-option.selected {
            border-color: #4361ee;
            background: #e8f4ff;
        }
        
        .quiz-option.correct {
            border-color: #28a745;
            background: #d4edda;
        }
        
        .quiz-option.incorrect {
            border-color: #dc3545;
            background: #f8d7da;
        }
        
        .lesson-status-badge {
            position: absolute;
            top: 10px;
            right: 10px;
            z-index: 1;
        }
        
        .time-tracking {
            position: fixed;
            bottom: 20px;
            right: 20px;
            background: white;
            padding: 10px 15px;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            z-index: 1000;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        
        .attempt-history {
            background: #f8f9fa;
            border-radius: 8px;
            padding: 15px;
            margin-top: 20px;
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

        .code-snippet {
    background: #2d3748;
    color: #e2e8f0;
    padding: 15px;
    border-radius: 5px;
    font-family: 'Courier New', monospace;
    margin: 15px 0;
    position: relative;
}

.code-snippet-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 10px;
    padding-bottom: 8px;
    border-bottom: 1px solid #4a5568;
}

.code-snippet-header small {
    color: #a0aec0;
    font-size: 0.85rem;
}

.code-snippet .btn-outline-light {
    border-color: #718096;
    color: #e2e8f0;
    font-size: 0.75rem;
    padding: 2px 10px;
}

.code-snippet .btn-outline-light:hover {
    background-color: #718096;
}

.code-snippet pre {
    margin: 0;
    padding: 0;
    background: transparent;
    border: none;
}

.code-snippet code {
    font-family: 'Courier New', monospace;
    color: #e2e8f0;
    font-size: 14px;
    line-height: 1.5;
}
        
        @media (max-width: 768px) {
            .lesson-sidebar {
                height: auto;
                position: static;
            }
            
            .lesson-content {
                padding: 1rem;
            }
            
            .time-tracking {
                bottom: 10px;
                right: 10px;
                font-size: 0.9rem;
                padding: 8px 12px;
            }
        }

        /* Mobile Responsiveness for Navigation Buttons */
@media (max-width: 768px) {
    /* Navigation buttons - stack vertically on mobile */
    .lesson-content .d-flex.justify-content-between {
        flex-direction: column !important;
        gap: 15px !important;
        align-items: stretch !important;
    }
    
    .lesson-content .d-flex.justify-content-between > * {
        width: 100% !important;
        text-align: center !important;
        margin: 5px 0 !important;
    }
    
    .lesson-content .d-flex.justify-content-between .btn {
        width: 100% !important;
        margin: 5px 0 !important;
        justify-content: center !important;
    }
    
    /* Adjust button sizes for mobile */
    .lesson-content .btn {
        padding: 12px 20px !important;
        font-size: 16px !important;
        border-radius: 8px !important;
        margin: 8px 0 !important;
    }
    
    /* Make completed lesson text more visible */
    .lesson-content h5.text.text-success {
        text-align: center !important;
        margin: 15px 0 !important;
        font-size: 18px !important;
        padding: 10px !important;
        background: rgba(40, 167, 69, 0.1) !important;
        border-radius: 8px !important;
        width: 100% !important;
    }
    
    /* Fix breadcrumb for mobile */
    .breadcrumb {
        font-size: 14px !important;
        overflow-x: auto !important;
        white-space: nowrap !important;
        flex-wrap: nowrap !important;
        padding-bottom: 5px !important;
    }
    
    /* Adjust lesson header */
    .lesson-content h1.display-6 {
        font-size: 24px !important;
    }
}

/* Extra small devices */
@media (max-width: 576px) {
    /* Even smaller buttons */
    .lesson-content .btn {
        padding: 10px 16px !important;
        font-size: 15px !important;
    }
    
    /* Stack buttons with icons only */
    .lesson-content .btn i {
        margin-right: 8px !important;
        font-size: 18px !important;
    }
    
    /* Hide button text on very small screens, show icons only */
    .lesson-content .btn span:not(.fas):not(.fa) {
        display: inline-block !important;
    }
    
    /* Adjust the completed lesson badge */
    .lesson-status-badge {
        position: relative !important;
        top: 0 !important;
        right: 0 !important;
        margin-bottom: 10px !important;
        display: flex !important;
        justify-content: center !important;
    }
    
    .lesson-status-badge .badge {
        font-size: 14px !important;
        padding: 8px 12px !important;
    }
}

        /* Code Runner improvements */
.CodeMirror {
    border-radius: 0.25rem;
    font-family: 'Courier New', monospace;
    font-size: 14px;
    height: 300px;
}

.code-runner-body {
    position: relative;
}

.output-panel {
    background: #fff;
    min-height: 100px;
    max-height: 300px;
    overflow-y: auto;
    border: 1px solid #dee2e6;
    border-radius: 0.25rem;
    padding: 1rem;
    margin-top: 1rem;
    font-family: 'Courier New', monospace;
    font-size: 14px;
}

.code-runner-header {
    background: #2d2d2d;
    padding: 0.75rem 1rem;
    color: #fff;
    display: flex;
    justify-content: space-between;
    align-items: center;
    border-radius: 0.5rem 0.5rem 0 0;
}

.code-runner {
    border-radius: 0.5rem;
    margin: 1.5rem 0;
    border: 1px solid #dee2e6;
    overflow: hidden;
}

#codeOutput div {
    padding: 2px 0;
    border-bottom: 1px solid #f0f0f0;
}

#codeOutput div:last-child {
    border-bottom: none;
}
    </style>
    <script>
    // ========== DEFINE FUNCTIONS FIRST ==========
    // Define functions FIRST so they're available when onclick handlers fire
    
    function runCode() {
        console.log('runCode called');
        // Get code from CodeMirror if available, otherwise from textarea
        let code;
        if (window.codeEditor && typeof window.codeEditor.getValue === 'function') {
            code = window.codeEditor.getValue();
        } else {
            code = document.getElementById('codeEditor').value;
        }
        
        const outputDiv = document.getElementById('codeOutput');
        
        try {
            outputDiv.innerHTML = '';
            
            // Store original console methods
            const originalConsole = {
                log: console.log,
                error: console.error,
                warn: console.warn,
                info: console.info
            };
            
            // Override console methods
            console.log = function(...args) {
                originalConsole.log.apply(console, args);
                displayOutput(args, 'text-primary');
            };
            
            console.error = function(...args) {
                originalConsole.error.apply(console, args);
                displayOutput(args, 'text-danger');
            };
            
            console.warn = function(...args) {
                originalConsole.warn.apply(console, args);
                displayOutput(args, 'text-warning');
            };
            
            console.info = function(...args) {
                originalConsole.info.apply(console, args);
                displayOutput(args, 'text-info');
            };
            
            function displayOutput(args, colorClass) {
                args.forEach(arg => {
                    const line = document.createElement('div');
                    line.className = colorClass;
                    
                    if (typeof arg === 'object') {
                        try {
                            line.textContent = JSON.stringify(arg, null, 2);
                        } catch {
                            line.textContent = String(arg);
                        }
                    } else {
                        line.textContent = String(arg);
                    }
                    
                    outputDiv.appendChild(line);
                });
                
                outputDiv.scrollTop = outputDiv.scrollHeight;
            }
            
            // Execute the code
            const result = eval(code);
            
            // Display return value if any
            if (result !== undefined) {
                displayOutput([`Return: ${result}`], 'text-success');
            }
            
            // Restore original console methods
            Object.assign(console, originalConsole);
            
        } catch(error) {
            outputDiv.innerHTML = `<div class="text-danger">
                <strong>Error:</strong> ${error.message}
                <br><small>at line ${error.lineNumber || 'unknown'}</small>
            </div>`;
        }
    }
    
    function resetCode() {
        if (confirm('Reset to original lesson code?')) {
            loadSampleLessonCode();
            clearOutput();
            showToast('Code reset to original', 'info');
        }
    }
    
    function clearOutput() {
        document.getElementById('codeOutput').innerHTML = '';
        showToast('Output cleared', 'info');
    }
    
    // Define other essential functions that are called from onclick
    function showToast(message, type = 'info') {
        const toastContainer = $('.toast-container');
        const toastId = 'toast-' + Date.now();
        
        const toastHtml = `
            <div id="${toastId}" class="toast align-items-center text-bg-${type} border-0" role="alert">
                <div class="d-flex">
                    <div class="toast-body">${message}</div>
                    <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button>
                </div>
            </div>
        `;
        
        toastContainer.append(toastHtml);
        const toast = new bootstrap.Toast(document.getElementById(toastId));
        toast.show();
        
        document.getElementById(toastId).addEventListener('hidden.bs.toast', function() {
            this.remove();
        });
    }
    
    // Define as empty functions for now, they'll be defined properly later
    function loadSampleLessonCode() {}
    function submitQuiz() {}
    function skipQuiz() {}
    function completeTopic() {}
    function markLessonAsCompleted() {}
    function logout() {}
    function showQuizStats() {}
    function continueAfterQuiz() {}
    function resetLessonProgress() {}
    
    // ========== GLOBAL VARIABLES ==========
    window.quizStartTime = null;
    window.timeInterval = null;
    window.codeEditor = null;
    window.selectedOption = null;
    
    // ========== EXPOSE FUNCTIONS TO WINDOW ==========
    window.runCode = runCode;
    window.resetCode = resetCode;
    window.clearOutput = clearOutput;
    window.submitQuiz = submitQuiz;
    window.skipQuiz = skipQuiz;
    window.completeTopic = completeTopic;
    window.markLessonAsCompleted = markLessonAsCompleted;
    window.logout = logout;
    window.showQuizStats = showQuizStats;
    window.continueAfterQuiz = continueAfterQuiz;
    window.resetLessonProgress = resetLessonProgress;
    window.showToast = showToast;
</script>
</head>
<body>
    <!-- Toast Container -->
    <div class="toast-container position-fixed top-0 end-0 p-3" style="z-index: 1100;"></div>

    <!-- Navigation -->
    <nav class="navbar navbar-expand-lg navbar-light bg-white shadow-sm">
        <div class="container">
            <a class="navbar-brand fw-bold text-primary" href="index">
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
    
    <div class="container-fluid">
        <div class="row">
            <!-- Sidebar -->
            <div class="col-lg-3 col-xl-2 lesson-sidebar">
                <div class="p-3 position-relative">
                    <?php if ($is_lesson_completed): ?>
                    <div class="lesson-status-badge">
                        <span class="badge bg-success">
                            <i class="fas fa-check me-1"></i> Completed
                        </span>
                    </div>
                    <?php endif; ?>
                    
                    <h5 class="fw-bold mb-3">
                        <a href="index" class="text-decoration-none text-dark">
                            <i class="fas fa-arrow-left me-2"></i> <?php echo htmlspecialchars($topic['topic_name']); ?>
                        </a>
                    </h5>
                    
                    <!-- Student Progress Summary -->
                    <?php if ($studentSession->isLoggedIn() && $studentData): ?>
                    <div class="card mb-3 bg-light border-0">
                        <div class="card-body p-3">
                            <div class="d-flex align-items-center">
                                <div class="user-avatar me-3" 
                                     style="background-color: <?php echo $studentData['avatar']; ?>;">
                                    <?php echo strtoupper(substr($studentData['name'], 0, 1)); ?>
                                </div>
                                <div>
                                    <h6 class="mb-0 fw-bold"><?php echo htmlspecialchars($studentData['username']); ?></h6>
                                    <small class="text-muted"><?php echo $studentData['score']; ?> points</small>
                                </div>
                            </div>
                        </div>
                    </div>
                    <?php endif; ?>
                    
                    <nav class="nav flex-column">
                        <?php foreach($lessons as $l): 
                            $lesson_progress = $studentSession->isLoggedIn() ? $studentSession->getLessonProgress($l['id']) : false;
                            $is_completed = $lesson_progress && $lesson_progress['is_completed'] == 1;
                        ?>
                        <a href="lesson?topic_id=<?php echo $topic_id; ?>&lesson_id=<?php echo $l['id']; ?>" 
                           class="lesson-nav-link mb-1 rounded <?php echo ($l['id'] == $lesson_id) ? 'active' : ''; ?> 
                                  position-relative">
                            <div class="d-flex justify-content-between align-items-center">
                                <span><?php echo htmlspecialchars($l['lesson_title']); ?></span>
                                <div class="d-flex align-items-center">
                                    <?php if ($is_completed): ?>
                                    <i class="fas fa-check-circle text-success me-2"></i>
                                    <?php endif; ?>
                                    <?php if($l['id'] == $lesson_id): ?>
                                    <i class="fas fa-play text-primary"></i>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </a>
                        <?php endforeach; ?>
                    </nav>
                </div>
            </div>
            
            <!-- Main Content -->
            <div class="col-lg-9 col-xl-10">
                <div class="lesson-content">
                    <!-- Lesson Header -->
                    <div class="d-flex justify-content-between align-items-start mb-4">
                        <div>
                            <nav aria-label="breadcrumb">
                                <ol class="breadcrumb">
                                    <li class="breadcrumb-item"><a href="index">Topics</a></li>
                                    <li class="breadcrumb-item"><?php echo htmlspecialchars($topic['topic_name']); ?></li>
                                    <li class="breadcrumb-item active"><?php echo htmlspecialchars($lesson['lesson_title']); ?></li>
                                </ol>
                            </nav>
                            <h1 class="display-6 fw-bold"><?php echo htmlspecialchars($lesson['lesson_title']); ?></h1>
                        </div>
                        <div class="dropdown">
                            <button class="btn btn-outline-secondary dropdown-toggle" type="button" data-bs-toggle="dropdown">
                                <i class="fas fa-cog"></i>
                            </button>
                            <ul class="dropdown-menu">
                                <?php if ($studentSession->isLoggedIn()): ?>
                                    <?php if ($is_lesson_completed): ?>
                                    <li><a class="dropdown-item" href="#" onclick="resetLessonProgress(<?php echo $lesson_id; ?>)">
                                        <i class="fas fa-redo me-2"></i>Reset Progress
                                    </a></li>
                                    <?php else: ?>
                                    <li><a class="dropdown-item" href="#" onclick="markLessonAsCompleted(<?php echo $lesson_id; ?>)">
                                        <i class="fas fa-check me-2"></i>Mark as Completed
                                    </a></li>
                                    <?php endif; ?>
                                <?php else: ?>
                                    <li><span class="dropdown-item-text text-muted">
                                        <i class="fas fa-info-circle me-2"></i>
                                        Login to track progress
                                    </span></li>
                                <?php endif; ?>
                                <!-- In the dropdown menu -->
<!-- <li><hr class="dropdown-divider"></li>
<li><a class="dropdown-item" href="#" onclick="resetCode(); return false;">
    <i class="fas fa-redo me-2"></i>Reset Code Editor
</a></li> -->
                            </ul>
                        </div>
                    </div>
                    
                    <!-- Login Reminder (if not logged in) -->
                    <?php if (!$studentSession->isLoggedIn()): ?>
                    <div class="alert alert-warning mb-4">
                        <div class="d-flex align-items-center">
                            <i class="fas fa-exclamation-triangle me-3 fa-2x"></i>
                            <div>
                                <h5 class="alert-heading mb-1">Login Required</h5>
                                <p class="mb-0">You're viewing this lesson as a guest. <a href="#" class="alert-link" data-bs-toggle="modal" data-bs-target="#loginModal">Login</a> or <a href="#" class="alert-link" data-bs-toggle="modal" data-bs-target="#registerModal">register</a> to track your progress and save quiz results!</p>
                            </div>
                        </div>
                    </div>
                    <?php endif; ?>
                    
                    <!-- Previous Attempt Info -->
                    <?php if ($has_attempted_quiz && $previous_attempt): ?>
                    <div class="attempt-history">
                        <h6><i class="fas fa-history me-2"></i>Your Previous Attempt</h6>
                        <div class="d-flex align-items-center">
                            <div class="me-3">
                                <?php if ($previous_attempt['is_correct']): ?>
                                <span class="badge bg-success">
                                    <i class="fas fa-check me-1"></i> Correct
                                </span>
                                <?php else: ?>
                                <span class="badge bg-danger">
                                    <i class="fas fa-times me-1"></i> Incorrect
                                </span>
                                <?php endif; ?>
                            </div>
                            <div class="text-muted small">
                                Attempted on <?php echo date('M d, Y H:i', strtotime($previous_attempt['attempted_at'])); ?>
                                • Time spent: <?php echo $previous_attempt['time_spent']; ?> seconds
                            </div>
                        </div>
                    </div>
                    <?php endif; ?>
                    
                    <!-- Lesson Content -->
                    <div class="lesson-body mb-5">
                        <?php echo htmlspecialchars_decode($lesson['lesson_content']); ?>
                        <hr />
                        <h5 class="text-warning">Bonus Training Ground:</h5>
                        <!-- Interactive Code Runner -->
<div class="code-runner">
    <div class="code-runner-header">
        <span><i class="fas fa-code me-2"></i>URUScript Arena</span>
        <div>
            <button class="btn btn-sm btn-outline-light me-2" onclick="resetCode()">
                <i class="fas fa-redo me-1"></i> Reset
            </button>
            <button class="btn btn-sm btn-success" onclick="runCode()">
                <i class="fas fa-play me-1"></i> Run Code
            </button>
        </div>
    </div>
    <div class="code-runner-body">
        <textarea id="codeEditor" placeholder="Write or modify the JavaScript code here...">
<?php
// Get the lesson_code_run from database, with better fallback
$default_code = '// Welcome to URUScript Arena!
// This is where you can practice JavaScript code.
console.log("Hello, JavaScript Learner!");';

// Check if lesson_code_run exists and is not empty
if (!empty($lesson['lesson_code_run'])) {
    $lesson_code = htmlspecialchars_decode($lesson['lesson_code_run']);
    
    // Add a comment if the code is too short
    if (strlen(trim($lesson_code)) < 20) {
        $lesson_code = "// Lesson code example:\n" . $lesson_code . "\n\n// Add your own code below:";
    }
} else {
    $lesson_code = $default_code;
    
    // If student is logged in, personalize the message
    if ($studentSession->isLoggedIn() && isset($studentData['name'])) {
        $lesson_code = str_replace("JavaScript Learner", $studentData['name'], $lesson_code);
    }
}
echo $lesson_code;
?>
        </textarea>
        <div class="output-panel">
            <div class="d-flex justify-content-between align-items-center mb-2">
                <h6 class="mb-0">Output:</h6>
                <button class="btn btn-sm btn-outline-secondary" onclick="clearOutput()">
                    <i class="fas fa-trash me-1"></i> Clear Output
                </button>
            </div>
            <div id="codeOutput" class="mt-2" style="min-height: 80px; font-family: 'Courier New', monospace;"></div>
        </div>
    </div>
</div>
                    </div>
                    
                    <!-- Quiz Section -->
                    <div class="quiz-section">
                        
                        
                        <?php if($quiz && $quiz_options): ?>
                        <div class="d-flex justify-content-between align-items-center mb-4">
                            <h3 class="fw-bold">
                                <i class="fas fa-question-circle text-primary me-2"></i>Quick Quiz
                            </h3>
                            <?php if ($studentSession->isLoggedIn()): ?>
                            <div class="d-flex align-items-center">
                                <span class="badge bg-info me-2">
                                    <i class="fas fa-star me-1"></i> +10 points if correct
                                </span>
                                <span class="badge bg-primary">Test Your Knowledge</span>
                            </div>
                            <?php endif; ?>
                        </div>
                        <div class="quiz-card border rounded p-4 bg-white shadow-sm">
                            <h5 class="mb-3">
                                <i class="fas fa-question me-2"></i>
                                <?php echo htmlspecialchars($quiz['question']); ?>
                                <?php if($quiz['difficulty'] == 'hard'): ?>
                                <span class="badge bg-danger ms-2">Hard</span>
                                <?php elseif($quiz['difficulty'] == 'medium'): ?>
                                <span class="badge bg-warning ms-2">Medium</span>
                                <?php else: ?>
                                <span class="badge bg-success ms-2">Easy</span>
                                <?php endif; ?>
                            </h5>
                            
                            <div class="quiz-options" id="quizOptions">
                                <?php foreach($quiz_options as $option): ?>
                                <div class="quiz-option" 
                                     data-option-id="<?php echo $option['id']; ?>"
                                     data-is-correct="<?php echo $option['is_correct']; ?>"
                                     <?php if ($has_attempted_quiz && $previous_attempt && $previous_attempt['selected_option_id'] == $option['id']): ?>
                                     data-previously-selected="true"
                                     <?php endif; ?>>
                                    <div class="form-check">
                                        <input class="form-check-input" type="radio" name="quizOption" 
                                               id="option<?php echo $option['id']; ?>"
                                               <?php if ($has_attempted_quiz && $previous_attempt && $previous_attempt['selected_option_id'] == $option['id']): ?>
                                               checked disabled
                                               <?php endif; ?>>
                                        <label class="form-check-label w-100" for="option<?php echo $option['id']; ?>">
                                            <?php echo htmlspecialchars($option['option_text']); ?>
                                        </label>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            </div>
                            
                            <div class="d-flex justify-content-between mt-4">
                                <button class="btn btn-outline-secondary" onclick="skipQuiz()" 
                                        <?php if ($has_attempted_quiz): ?>disabled<?php endif; ?>>
                                    <i class="fas fa-forward me-1"></i> Skip
                                </button>
                                
                                <?php if (!$has_attempted_quiz): ?>
                                <button class="btn btn-primary" onclick="submitQuiz()" id="submitQuizBtn">
                                    <span id="submitText">Submit Answer</span>
                                    <span id="submitLoading" class="spinner-border spinner-border-sm d-none" role="status"></span>
                                </button>
                                <?php else: ?>
                                <button class="btn btn-success" disabled>
                                    <i class="fas fa-check me-1"></i> Already Attempted
                                </button>
                                <?php endif; ?>
                            </div>
                            
                            <div id="quizFeedback" class="mt-3" style="display: none;">
                                <div class="alert" role="alert">
                                    <h6><i class="fas fa-info-circle me-2"></i>Explanation</h6>
                                    <p id="explanationText" class="mb-0"></p>
                                </div>
                            </div>
                            
                            <!-- Points Earned Display -->
                            <div id="pointsEarned" class="mt-3" style="display: none;">
                                <div class="alert alert-success">
                                    <div class="d-flex justify-content-between align-items-center">
                                        <div>
                                            <i class="fas fa-trophy me-2"></i>
                                            <strong>+10 Points Earned!</strong>
                                        </div>
                                        <div id="newScore" class="fw-bold"></div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <?php endif; ?>
                    </div>
                    
                    <!-- Navigation Buttons -->
                    <div class="d-flex justify-content-between mt-5 pt-4 border-top">
                        <?php
                        $prev_lesson = null;
                        $next_lesson = null;
                        
                        foreach($lessons as $index => $l) {
                            if($l['id'] == $lesson_id) {
                                $prev_lesson = $lessons[$index - 1] ?? null;
                                $next_lesson = $lessons[$index + 1] ?? null;
                                break;
                            }
                        }
                        ?>
                        
                        <?php if($prev_lesson): ?>
                        <a href="lesson?topic_id=<?php echo $topic_id; ?>&lesson_id=<?php echo $prev_lesson['id']; ?>" 
                           class="btn btn-outline-primary">
                            <i class="fas fa-arrow-left me-2"></i> Previous Lesson
                        </a>
                        <?php else: ?>
                        <a href="index" class="btn btn-outline-secondary">
                            <i class="fas fa-home me-2"></i> Back to Topics
                        </a>
                        <?php endif; ?>
                        
                        <?php if($next_lesson): ?>
                            <?php if ($is_lesson_completed): ?>
                                <h5 class="text text-success"><i class="fas fa-check me-2"></i> Lesson already completed!</h5>
                            <?php else: ?>
                                <a class="btn btn-warning" href="#" onclick="markLessonAsCompleted(<?php echo $lesson_id; ?>)">
                                    <i class="fas fa-check me-2"></i>Mark as Completed?
                                </a>
                            <?php endif; ?>
                        <a href="lesson?topic_id=<?php echo $topic_id; ?>&lesson_id=<?php echo $next_lesson['id']; ?>" 
                           class="btn btn-primary">
                            Next Lesson <i class="fas fa-arrow-right ms-2"></i>
                        </a>
                        <?php else: ?>
                        <button class="btn btn-success" onclick="completeTopic()">
                            Complete Topic <i class="fas fa-check ms-2"></i>
                        </button>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Quiz Result Modal -->
    <div class="modal fade" id="quizResultModal" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header" id="quizResultHeader">
                    <!-- Dynamically set based on result -->
                </div>
                <div class="modal-body text-center">
                    <div id="resultIcon" class="mb-3"></div>
                    <h4 id="resultTitle"></h4>
                    <p id="resultMessage" class="text-muted"></p>
                    <div id="pointsInfo" class="mb-3"></div>
                    <button class="btn btn-primary" data-bs-dismiss="modal" onclick="continueAfterQuiz()">
                        Continue Learning
                    </button>
                </div>
            </div>
        </div>
    </div>
    
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
    
    <!-- Time Tracking -->
    <?php if ($studentSession->isLoggedIn() && !$has_attempted_quiz && $quiz): ?>
    <div class="time-tracking" id="timeTracker">
        <i class="fas fa-clock text-primary"></i>
        <span id="timeSpent">0</span> seconds
    </div>
    <?php endif; ?>

    <!-- Quiz Statistics Modal -->
    <div class="modal  fade modal-lg" id="quizStatsModal" tabindex="-1">
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
                    <img src="assets/img/URUScript.png" alt="URUScript Tutorial Logo" title="URUScript Tutorial Logo" class="img-fluid" style="max-height: 50px;"> URUScript Tutorial Platform
                    <!-- <h5><i class="fab fa-js-square me-2"></i>JavaScript Tutorial</h5> -->
                    <p class="text-muted">An interactive learning platform for mastering JavaScript</p>
                </div>
                <div class="col-md-6 text-md-end">
                    <p class="text-muted mb-0">&copy; 2024 JS Tutorial. All rights reserved.</p>
                </div>
            </div>
        </div>
    </footer>
    <!-- Add before your existing script tags -->
    <script src="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.5/codemirror.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.5/mode/javascript/javascript.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://code.jquery.com/jquery-3.6.4.min.js"></script>

<script>
// ========== GLOBAL VARIABLES ==========
let quizStartTime = null;
let timeInterval = null;
let codeEditor = null;
let selectedOption = null;

// ========== CODE EDITOR & RUNNER FUNCTIONS ==========

function runCode() {
    // Get code from CodeMirror if available, otherwise from textarea
    let code;
    if (codeEditor && typeof codeEditor.getValue === 'function') {
        code = codeEditor.getValue();
    } else {
        code = document.getElementById('codeEditor').value;
    }
    
    const outputDiv = document.getElementById('codeOutput');
    
    try {
        outputDiv.innerHTML = '';
        
        // Store original console methods
        const originalConsole = {
            log: console.log,
            error: console.error,
            warn: console.warn,
            info: console.info
        };
        
        // Override console methods
        console.log = function(...args) {
            originalConsole.log.apply(console, args);
            displayOutput(args, 'text-primary');
        };
        
        console.error = function(...args) {
            originalConsole.error.apply(console, args);
            displayOutput(args, 'text-danger');
        };
        
        console.warn = function(...args) {
            originalConsole.warn.apply(console, args);
            displayOutput(args, 'text-warning');
        };
        
        console.info = function(...args) {
            originalConsole.info.apply(console, args);
            displayOutput(args, 'text-info');
        };
        
        function displayOutput(args, colorClass) {
            args.forEach(arg => {
                const line = document.createElement('div');
                line.className = colorClass;
                
                if (typeof arg === 'object') {
                    try {
                        line.textContent = JSON.stringify(arg, null, 2);
                    } catch {
                        line.textContent = String(arg);
                    }
                } else {
                    line.textContent = String(arg);
                }
                
                outputDiv.appendChild(line);
            });
            
            outputDiv.scrollTop = outputDiv.scrollHeight;
        }
        
        // Execute the code
        const result = eval(code);
        
        // Display return value if any
        if (result !== undefined) {
            displayOutput([`Return: ${result}`], 'text-success');
        }
        
        // Restore original console methods
        Object.assign(console, originalConsole);
        
    } catch(error) {
        outputDiv.innerHTML = `<div class="text-danger">
            <strong>Error:</strong> ${error.message}
            <br><small>at line ${error.lineNumber || 'unknown'}</small>
        </div>`;
    }
}

function resetCode() {
    if (confirm('Reset to original lesson code?')) {
        loadSampleLessonCode();
        clearOutput();
        showToast('Code reset to original', 'info');
    }
}

function clearOutput() {
    document.getElementById('codeOutput').innerHTML = '';
    showToast('Output cleared', 'info');
}

function loadSampleLessonCode() {
    const originalCode = `<?php echo addslashes($lesson_code); ?>`;
    if (codeEditor) {
        codeEditor.setValue(originalCode);
    } else {
        document.getElementById('codeEditor').value = originalCode;
    }
    showToast('Original code restored', 'info');
}

// ========== QUIZ FUNCTIONS ==========

function submitQuiz() {
    console.log('submitQuiz called');
    
    if (!selectedOption) {
        showToast('Please select an answer first!', 'warning');
        return;
    }
    
    const isCorrect = selectedOption.data('is-correct') == '1';
    const quizId = <?php echo $quiz['id'] ?? 0; ?>;
    const timeSpent = getTimeSpent();
    
    console.log('Submitting quiz:', {
        quizId: quizId,
        selectedOptionId: selectedOption.data('option-id'),
        isCorrect: isCorrect,
        timeSpent: timeSpent
    });
    
    $('#submitText').addClass('d-none');
    $('#submitLoading').removeClass('d-none');
    $('#submitQuizBtn').prop('disabled', true);
    
    if (timeInterval) {
        clearInterval(timeInterval);
    }
    
    $.ajax({
        url: 'lesson.php',
        type: 'POST',
        data: {
            action: 'submit_quiz',
            quiz_id: quizId,
            selected_option_id: selectedOption.data('option-id'),
            is_correct: isCorrect ? 1 : 0,
            time_spent: timeSpent
        },
        dataType: 'json',
        // In your submitQuiz() function AJAX success handler:
        success: function(response) {
            console.log('Quiz submission response:', response);
            
            if (response.success) {
                // Update the score badge immediately
                if (response.new_total_score !== undefined) {
                    $('.score-badge').html(`<i class="fas fa-star me-1"></i> ${response.new_total_score} pts`);
                    // Also update session in JavaScript for immediate display
                    <?php if ($studentSession->isLoggedIn()): ?>
                    // Force a small session update to keep in sync
                    $.ajax({
                        url: 'ajax.php?action=refresh_score',
                        type: 'GET',
                        async: false // Wait for this to complete
                    });
                    <?php endif; ?>
                }
                showQuizResult(isCorrect, response);
            } else {
                showToast(response.message, 'danger');
                resetQuizButton();
            }
        },
        error: function(xhr, status, error) {
            console.error('Quiz submission error:', error);
            showToast('Network error. Please try again.', 'danger');
            resetQuizButton();
        }
    });
}

function resetQuizButton() {
    $('#submitText').removeClass('d-none');
    $('#submitLoading').addClass('d-none');
    $('#submitQuizBtn').prop('disabled', false);
}

function updateTimer() {
    if (!quizStartTime) return;
    const timeSpent = Math.floor((Date.now() - quizStartTime) / 1000);
    document.getElementById('timeSpent').textContent = timeSpent;
}

function getTimeSpent() {
    if (!quizStartTime) return 0;
    return Math.floor((Date.now() - quizStartTime) / 1000);
}

function showQuizResult(isCorrect, response) {
    const modal = new bootstrap.Modal(document.getElementById('quizResultModal'));
    const header = $('#quizResultHeader');
    const icon = $('#resultIcon');
    const title = $('#resultTitle');
    const message = $('#resultMessage');
    const pointsInfo = $('#pointsInfo');
    
    if (isCorrect) {
        header.html(`
            <h5 class="modal-title text-success">
                <i class="fas fa-check-circle me-2"></i>Correct Answer!
            </h5>
            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        `).addClass('bg-success text-white').removeClass('bg-danger');
        
        icon.html('<i class="fas fa-trophy fa-4x text-success"></i>');
        title.text('Excellent Work!');
        message.text('You answered correctly and earned points.');
        
        if (response.points > 0) {
            pointsInfo.html(`
                <div class="alert alert-success">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <i class="fas fa-star me-2"></i>
                            <strong>+${response.points} Points Earned!</strong>
                        </div>
                        <div class="fw-bold">Total: ${response.new_total_score} points</div>
                    </div>
                </div>
            `);
        }
        
        $('.score-badge').html(`<i class="fas fa-star me-1"></i> ${response.new_total_score} pts`);
    } else {
        header.html(`
            <h5 class="modal-title text-danger">
                <i class="fas fa-times-circle me-2"></i>Incorrect Answer
            </h5>
            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        `).addClass('bg-danger text-white').removeClass('bg-success');
        
        icon.html('<i class="fas fa-redo fa-4x text-danger"></i>');
        title.text('Try Again!');
        message.text('Better luck next time. Review the lesson and try again.');
        pointsInfo.html('<p class="text-muted">No points earned for incorrect answers.</p>');
    }
    
    modal.show();
    showQuizFeedback(isCorrect);
}

function showQuizFeedback(isCorrect) {
    const feedbackDiv = $('#quizFeedback');
    
    if (isCorrect) {
        feedbackDiv.html(`
            <div class="alert alert-success" role="alert">
                <h6><i class="fas fa-check-circle me-2"></i>Correct!</h6>
                <p class="mb-0">Well done! You answered correctly.</p>
            </div>
        `);
        $('#pointsEarned').show();
        $('#newScore').text(`Total: <?php echo ($studentData['score'] ?? 0) + 10; ?> points`);
    } else {
        feedbackDiv.html(`
            <div class="alert alert-danger" role="alert">
                <h6><i class="fas fa-times-circle me-2"></i>Incorrect Answer</h6>
                <p class="mb-0">Try again or review the lesson material.</p>
            </div>
        `);
    }
    
    feedbackDiv.show();
    
    $('.quiz-option').each(function() {
        if ($(this).data('is-correct') == '1') {
            $(this).addClass('correct');
        }
        if ($(this).is(selectedOption) && !isCorrect) {
            $(this).addClass('incorrect');
        }
    });
    
    $('.quiz-option').css('pointer-events', 'none');
}

function skipQuiz() {
    if (confirm('Skip this quiz and continue to next lesson?')) {
        <?php if(isset($next_lesson) && $next_lesson): ?>
        window.location.href = 'lesson?topic_id=<?php echo $topic_id; ?>&lesson_id=<?php echo $next_lesson['id']; ?>';
        <?php else: ?>
        showToast('This is the last lesson in this topic!', 'info');
        <?php endif; ?>
    }
}

function completeTopic() {
    showToast('Topic completed! Congratulations on finishing all lessons.', 'success');
    setTimeout(() => {
        window.location.href = 'index';
    }, 2000);
}

function continueAfterQuiz() {
    <?php if (!$is_lesson_completed): ?>
    markLessonAsCompleted(<?php echo $lesson_id; ?>);
    <?php endif; ?>
    $('#timeTracker').hide();
}

// ========== LESSON PROGRESS FUNCTIONS ==========

function markLessonAsCompleted(lessonId) {
    $.ajax({
        url: 'ajax.php?action=mark_lesson_completed',
        type: 'POST',
        data: { lesson_id: lessonId },
        dataType: 'json',
        success: function(response) {
            if (response.success) {
                showToast('Lesson marked as completed!', 'success');
                $('.lesson-status-badge').html(`
                    <span class="badge bg-success">
                        <i class="fas fa-check me-1"></i> Completed
                    </span>
                `);
            }
        },
        error: function() {
            showToast('Failed to mark lesson as completed', 'danger');
        }
    });
}

function resetLessonProgress(lessonId) {
    if (confirm('Reset your progress for this lesson?')) {
        $.ajax({
            url: 'ajax.php?action=reset_lesson_progress',
            type: 'POST',
            data: { lesson_id: lessonId },
            dataType: 'json',
            success: function(response) {
                if (response.success) {
                    showToast('Lesson progress reset!', 'info');
                    $('.lesson-status-badge').html('');
                    location.reload();
                }
            },
            error: function() {
                showToast('Failed to reset lesson progress', 'danger');
            }
        });
    }
}

// ========== UTILITY FUNCTIONS ==========

function showToast(message, type = 'info') {
    const toastContainer = $('.toast-container');
    const toastId = 'toast-' + Date.now();
    
    const toastHtml = `
        <div id="${toastId}" class="toast align-items-center text-bg-${type} border-0" role="alert">
            <div class="d-flex">
                <div class="toast-body">${message}</div>
                <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button>
            </div>
        </div>
    `;
    
    toastContainer.append(toastHtml);
    const toast = new bootstrap.Toast(document.getElementById(toastId));
    toast.show();
    
    document.getElementById(toastId).addEventListener('hidden.bs.toast', function() {
        this.remove();
    });
}

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
                    setTimeout(() => location.reload(), 1000);
                }
            },
            error: function() {
                showToast('Error during logout. Please try again.', 'danger');
            }
        });
    }
}

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

// ========== LOGIN/REGISTRATION FUNCTIONS ==========

$('#loginForm').submit(function(e) {
    e.preventDefault();
    
    const username = $('#loginUsername').val();
    const password = $('#loginPassword').val();
    
    if (!username || !password) {
        showToast('Please fill in all fields', 'warning');
        return;
    }
    
    $('#loginButtonText').addClass('d-none');
    $('#loginLoading').removeClass('d-none');
    $('#loginMessage').html('').removeClass('alert alert-danger');
    
    $.ajax({
        url: 'index.php',
        type: 'POST',
        data: { action: 'login', username: username, password: password },
        dataType: 'json',
        success: function(response) {
            if (response.success) {
                showToast('Login successful! Welcome back ' + response.student.username + '!', 'success');
                setTimeout(() => location.reload(), 1500);
            } else {
                $('#loginMessage').html(`
                    <div class="alert alert-danger">
                        <i class="fas fa-exclamation-circle me-2"></i>${response.message}
                    </div>
                `);
                $('#loginButtonText').removeClass('d-none');
                $('#loginLoading').addClass('d-none');
            }
        },
        error: function() {
            $('#loginMessage').html(`
                <div class="alert alert-danger">
                    <i class="fas fa-exclamation-circle me-2"></i>Network error. Please try again.
                </div>
            `);
            $('#loginButtonText').removeClass('d-none');
            $('#loginLoading').addClass('d-none');
        }
    });
});

$('#registerForm').submit(function(e) {
    e.preventDefault();
    
    const full_name = $('#registerName').val();
    const username = $('#registerUsername').val();
    const email = $('#registerEmail').val();
    const password = $('#registerPassword').val();
    
    if (!full_name || !username || !email || !password) {
        showToast('Please fill in all fields', 'warning');
        return;
    }
    
    if (password.length < 6) {
        showToast('Password must be at least 6 characters', 'warning');
        return;
    }
    
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
                setTimeout(() => location.reload(), 1500);
            } else {
                $('#registerMessage').html(`
                    <div class="alert alert-danger">
                        <i class="fas fa-exclamation-circle me-2"></i>${response.message}
                    </div>
                `);
                $('#registerButtonText').removeClass('d-none');
                $('#registerLoading').addClass('d-none');
            }
        },
        error: function() {
            $('#registerMessage').html(`
                <div class="alert alert-danger">
                    <i class="fas fa-exclamation-circle me-2"></i>Network error. Please try again.
                </div>
            `);
            $('#registerButtonText').removeClass('d-none');
            $('#registerLoading').addClass('d-none');
        }
    });
});

// ========== INITIALIZATION ==========

$(document).ready(function() {
    console.log('Document ready');
    
    // Initialize CodeMirror editor
    function initCodeEditor() {
        const textarea = document.getElementById('codeEditor');
        
        if (typeof CodeMirror !== 'undefined') {
            codeEditor = CodeMirror.fromTextArea(textarea, {
                lineNumbers: true,
                mode: "javascript",
                theme: "dracula",
                indentUnit: 4,
                smartIndent: true,
                matchBrackets: true,
                autoCloseBrackets: true,
                lineWrapping: true,
                extraKeys: {
                    "Ctrl-Enter": function() { runCode(); },
                    "Ctrl-Space": "autocomplete",
                    "Ctrl-/": "toggleComment"
                }
            });
            
            codeEditor.setSize("100%", "300px");
            codeEditor.on("keydown", function(cm, event) {
                if (event.ctrlKey && event.key === "Enter") {
                    event.preventDefault();
                    runCode();
                }
            });
        } else {
            console.warn('CodeMirror not loaded, using plain textarea');
            textarea.addEventListener('keydown', function(e) {
                if (e.ctrlKey && e.key === 'Enter') {
                    e.preventDefault();
                    runCode();
                }
            });
        }
        
        // Auto-run code on page load
        // setTimeout(runCode, 1000);
    }
    
    // Initialize quiz timer if needed
    <?php if ($studentSession->isLoggedIn() && !$has_attempted_quiz && $quiz): ?>
    quizStartTime = Date.now();
    timeInterval = setInterval(updateTimer, 1000);
    <?php endif; ?>
    
    // Initialize CodeMirror
    initCodeEditor();
    
    // Quiz option selection
    $('.quiz-option').click(function() {
        if ($(this).data('previously-selected') === 'true') return;
        
        $('.quiz-option').removeClass('selected');
        $(this).addClass('selected');
        $(this).find('input[type="radio"]').prop('checked', true);
        selectedOption = $(this);
        console.log('Selected option:', selectedOption.data('option-id'));
    });
    
    // Debug: Check if submitQuiz function is available
    console.log('submitQuiz function available:', typeof window.submitQuiz === 'function');
    
    // Expose functions to global scope for onclick handlers
    window.submitQuiz = submitQuiz;
    window.skipQuiz = skipQuiz;
    window.completeTopic = completeTopic;
    window.markLessonAsCompleted = markLessonAsCompleted;
    window.logout = logout;
    window.showQuizStats = showQuizStats;
    window.continueAfterQuiz = continueAfterQuiz;
    window.resetLessonProgress = resetLessonProgress;
    window.showToast = showToast;
    window.runCode = runCode;
    window.resetCode = resetCode;
    window.clearOutput = clearOutput;
});
</script>
</body>
</html>