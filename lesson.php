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
    <title><?php echo htmlspecialchars($lesson['lesson_title'] ?? 'Lesson'); ?> - JS Tutorial</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
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
    </style>
</head>
<body>
    <!-- Toast Container -->
    <div class="toast-container position-fixed top-0 end-0 p-3" style="z-index: 1100;"></div>

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
                        
                        <!-- Interactive Code Runner -->
                        <!-- <div class="code-runner">
                            <div class="code-runner-header">
                                <span><i class="fas fa-code me-2"></i>Try it Yourself</span>
                                <button class="btn btn-sm btn-success" onclick="runCode()">
                                    <i class="fas fa-play me-1"></i> Run Code
                                </button>
                            </div>
                            <div class="code-runner-body">
                                <textarea id="codeEditor" placeholder="Write your JavaScript code here...">
// URU-Example: Try changing this code

let x = "Hello World";
console.log(x);
                                    </textarea>
                                <div class="output-panel">
                                    <h6>Output:</h6>
                                    <div id="codeOutput" class="mt-2"></div>
                                </div>
                            </div>
                        </div> -->
                    </div>
                    
                    <!-- Quiz Section -->
                    <div class="quiz-section">
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
                        
                        <?php if($quiz && $quiz_options): ?>
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
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://code.jquery.com/jquery-3.6.4.min.js"></script>
    <script>
        // Quiz timer
        let quizStartTime = null;
        let timeInterval = null;
        
        // Start timer when page loads
        <?php if ($studentSession->isLoggedIn() && !$has_attempted_quiz && $quiz): ?>
        document.addEventListener('DOMContentLoaded', function() {
            quizStartTime = Date.now();
            timeInterval = setInterval(updateTimer, 1000);
        });
        <?php endif; ?>
        
        function updateTimer() {
            if (!quizStartTime) return;
            
            const timeSpent = Math.floor((Date.now() - quizStartTime) / 1000);
            document.getElementById('timeSpent').textContent = timeSpent;
        }
        
        function getTimeSpent() {
            if (!quizStartTime) return 0;
            return Math.floor((Date.now() - quizStartTime) / 1000);
        }
        
        // Quiz Handling
        let selectedOption = null;
        
        $('.quiz-option').click(function() {
            if ($(this).data('previously-selected') === 'true') return;
            
            $('.quiz-option').removeClass('selected');
            $(this).addClass('selected');
            $(this).find('input[type="radio"]').prop('checked', true);
            selectedOption = $(this);
        });
        
        function submitQuiz() {
            if (!selectedOption) {
                showToast('Please select an answer first!', 'warning');
                return;
            }
            
            const isCorrect = selectedOption.data('is-correct') == '1';
            const quizId = <?php echo $quiz['id'] ?? 0; ?>;
            const timeSpent = getTimeSpent();
            
            // Show loading state
            $('#submitText').addClass('d-none');
            $('#submitLoading').removeClass('d-none');
            $('#submitQuizBtn').prop('disabled', true);
            
            // Stop timer
            if (timeInterval) {
                clearInterval(timeInterval);
            }
            
            // Submit via AJAX
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
                success: function(response) {
                    if (response.success) {
                        showQuizResult(isCorrect, response);
                        
                    } else {
                        showToast(response.message, 'danger');
                        $('#submitText').removeClass('d-none');
                        $('#submitLoading').addClass('d-none');
                        $('#submitQuizBtn').prop('disabled', false);
                    }
                },
                error: function() {
                    showToast('Network error. Please try again.', 'danger');
                    $('#submitText').removeClass('d-none');
                    $('#submitLoading').addClass('d-none');
                    $('#submitQuizBtn').prop('disabled', false);
                }
            });
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
                `);
                header.addClass('bg-success text-white').removeClass('bg-danger');
                
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
                
                // Update student score in UI
                $('.score-badge').html(`<i class="fas fa-star me-1"></i> ${response.new_total_score} pts`);
                
            } else {
                header.html(`
                    <h5 class="modal-title text-danger">
                        <i class="fas fa-times-circle me-2"></i>Incorrect Answer
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                `);
                header.addClass('bg-danger text-white').removeClass('bg-success');
                
                icon.html('<i class="fas fa-redo fa-4x text-danger"></i>');
                title.text('Try Again!');
                message.text('Better luck next time. Review the lesson and try again.');
                pointsInfo.html('<p class="text-muted">No points earned for incorrect answers.</p>');
            }
            
            modal.show();
            
            // Show feedback in quiz section
            showQuizFeedback(isCorrect);
        }
        
        function showQuizFeedback(isCorrect) {
            const feedbackDiv = $('#quizFeedback');
            const explanationText = $('#explanationText');
            
            if (isCorrect) {
                feedbackDiv.html(`
                    <div class="alert alert-success" role="alert">
                        <h6><i class="fas fa-check-circle me-2"></i>Correct!</h6>
                        <p class="mb-0">Well done! You answered correctly.</p>
                    </div>
                `);
                
                // Show points earned
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
            
            // Mark correct/incorrect options
            $('.quiz-option').each(function() {
                if ($(this).data('is-correct') == '1') {
                    $(this).addClass('correct');
                }
                if ($(this).is(selectedOption) && !isCorrect) {
                    $(this).addClass('incorrect');
                }
            });
            
            // Disable further selection
            $('.quiz-option').css('pointer-events', 'none');
        }
        
        function continueAfterQuiz() {
            // Mark lesson as completed automatically if correct
            <?php if (!$is_lesson_completed): ?>
            markLessonAsCompleted(<?php echo $lesson_id; ?>);
            <?php endif; ?>
            
            // Hide time tracker
            $('#timeTracker').hide();
        }
        
        function markLessonAsCompleted(lessonId) {
            $.ajax({
                url: 'ajax.php?action=mark_lesson_completed',
                type: 'POST',
                data: { lesson_id: lessonId },
                dataType: 'json',
                success: function(response) {
                    if (response.success) {
                        showToast('Lesson marked as completed!', 'success');
                        // Update UI
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
        
        // Toast notification function
        function showToast(message, type = 'info') {
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
        
        // Login form handling (same as index.php)
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
        
        // Registration form handling (same as index.php)
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
        
        // Code Runner function
        function runCode() {
            const code = document.getElementById('codeEditor').value;
            const outputDiv = document.getElementById('codeOutput');
            
            try {
                // Clear previous output
                outputDiv.innerHTML = '';
                
                // Override console.log to display in output div
                const originalConsoleLog = console.log;
                console.log = function(...args) {
                    originalConsoleLog.apply(console, args);
                    args.forEach(arg => {
                        const line = document.createElement('div');
                        line.textContent = typeof arg === 'object' ? JSON.stringify(arg, null, 2) : String(arg);
                        outputDiv.appendChild(line);
                    });
                };
                
                // Execute the code
                eval(code);
                
                // Restore original console.log
                console.log = originalConsoleLog;
                
            } catch(error) {
                outputDiv.innerHTML = `<div class="text-danger"><strong>Error:</strong> ${error.message}</div>`;
            }
        }

        // Copy code function for student view
function copyCode(button) {
    const codeSnippet = button.closest('.code-snippet');
    const codeElement = codeSnippet.querySelector('code');
    const text = codeElement.textContent;
    
    navigator.clipboard.writeText(text).then(() => {
        const originalText = button.innerHTML;
        button.innerHTML = '<i class="fas fa-check"></i> Copied!';
        button.classList.remove('btn-outline-light');
        button.classList.add('btn-success');
        
        setTimeout(() => {
            button.innerHTML = originalText;
            button.classList.remove('btn-success');
            button.classList.add('btn-outline-light');
        }, 2000);
    });
}

// Initialize copy buttons on page load
document.addEventListener('DOMContentLoaded', function() {
    document.querySelectorAll('.code-snippet .btn').forEach(btn => {
        if (btn.textContent.includes('Copy') || btn.querySelector('.fa-copy')) {
            btn.onclick = function() {
                copyCode(this);
            };
        }
    });
});
        
        // Auto-run example code on page load
        document.addEventListener('DOMContentLoaded', runCode);

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
    </script>
</body>
</html>