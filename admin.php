<?php
session_start();
// Add authentication check here

require_once 'database.php';
$db = new Database();
$conn = $db->getConnection();
// Check if HTTP_REFERER is set and is a valid URL
if (!empty($_SERVER['HTTP_REFERER']) && filter_var($_SERVER['HTTP_REFERER'], FILTER_VALIDATE_URL)) {
    // Redirect to the previous page
    header("Location: " . $_SERVER['HTTP_REFERER']);
    exit;
} else {
    // Fallback: redirect to a default page
    header("Location: index");
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Panel - JS Tutorial</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.6/css/dataTables.bootstrap5.min.css">
    <style>
        .admin-sidebar {
            background: #2c3e50;
            min-height: 100vh;
            color: white;
        }
        
        .admin-nav-link {
            color: #ecf0f1;
            padding: 0.75rem 1rem;
            text-decoration: none;
            display: block;
            border-left: 3px solid transparent;
        }
        
        .admin-nav-link:hover, .admin-nav-link.active {
            background: #34495e;
            border-left-color: #3498db;
            color: white;
        }
    </style>
</head>
<body>
    <div class="container-fluid">
        <div class="row">
            <!-- Sidebar -->
            <div class="col-md-3 col-lg-2 admin-sidebar">
                <div class="p-3">
                    <h4 class="text-center mb-4">Admin Panel</h4>
                    <nav class="nav flex-column">
                        <a href="#" class="admin-nav-link active">
                            <i class="fas fa-tachometer-alt me-2"></i> Dashboard
                        </a>
                        <a href="#topics" class="admin-nav-link">
                            <i class="fas fa-book me-2"></i> Manage Topics
                        </a>
                        <a href="#lessons" class="admin-nav-link">
                            <i class="fas fa-file-alt me-2"></i> Manage Lessons
                        </a>
                        <a href="#quizzes" class="admin-nav-link">
                            <i class="fas fa-question-circle me-2"></i> Manage Quizzes
                        </a>
                        <a href="#students" class="admin-nav-link">
                            <i class="fas fa-users me-2"></i> Students
                        </a>
                        <a href="#reports" class="admin-nav-link">
                            <i class="fas fa-chart-bar me-2"></i> Reports
                        </a>
                    </nav>
                </div>
            </div>
            
            <!-- Main Content -->
            <div class="col-md-9 col-lg-10">
                <div class="p-4">
                    <h2 class="mb-4">Admin Dashboard</h2>
                    
                    <!-- Stats Cards -->
                    <div class="row mb-4">
                        <div class="col-md-3">
                            <div class="card bg-primary text-white">
                                <div class="card-body">
                                    <h5 class="card-title">Total Topics</h5>
                                    <?php
                                    $stmt = $conn->query("SELECT COUNT(*) FROM topics WHERE deleted_at IS NULL");
                                    echo '<h2>'.$stmt->fetchColumn().'</h2>';
                                    ?>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="card bg-success text-white">
                                <div class="card-body">
                                    <h5 class="card-title">Total Lessons</h5>
                                    <?php
                                    $stmt = $conn->query("SELECT COUNT(*) FROM lessons WHERE deleted_at IS NULL");
                                    echo '<h2>'.$stmt->fetchColumn().'</h2>';
                                    ?>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="card bg-info text-white">
                                <div class="card-body">
                                    <h5 class="card-title">Total Quizzes</h5>
                                    <?php
                                    $stmt = $conn->query("SELECT COUNT(*) FROM quizzes WHERE deleted_at IS NULL");
                                    echo '<h2>'.$stmt->fetchColumn().'</h2>';
                                    ?>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="card bg-warning text-dark">
                                <div class="card-body">
                                    <h5 class="card-title">Total Students</h5>
                                    <?php
                                    $stmt = $conn->query("SELECT COUNT(*) FROM students WHERE deleted_at IS NULL");
                                    echo '<h2>'.$stmt->fetchColumn().'</h2>';
                                    ?>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Quick Add Forms -->
                    <div class="row">
                        <div class="col-md-6">
                            <div class="card">
                                <div class="card-header">
                                    <h5>Add New Topic</h5>
                                </div>
                                <div class="card-body">
                                    <form id="addTopicForm">
                                        <div class="mb-3">
                                            <label>Topic Name</label>
                                            <input type="text" class="form-control" name="topic_name" required>
                                        </div>
                                        <div class="mb-3">
                                            <label>Description</label>
                                            <textarea class="form-control" name="description" rows="3"></textarea>
                                        </div>
                                        <button type="submit" class="btn btn-primary">Add Topic</button>
                                    </form>
                                </div>
                            </div>
                        </div>
                        
                        <div class="col-md-6">
                            <div class="card">
                                <div class="card-header">
                                    <h5>Add New Quiz</h5>
                                </div>
                                <div class="card-body">
                                    <form id="addQuizForm">
                                        <div class="mb-3">
                                            <label>Select Lesson</label>
                                            <select class="form-control" name="lesson_id" required>
                                                <?php
                                                $stmt = $conn->query("SELECT id, lesson_title FROM lessons WHERE deleted_at IS NULL");
                                                while($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                                                    echo '<option value="'.$row['id'].'">'.$row['lesson_title'].'</option>';
                                                }
                                                ?>
                                            </select>
                                        </div>
                                        <div class="mb-3">
                                            <label>Question</label>
                                            <textarea class="form-control" name="question" rows="2" required></textarea>
                                        </div>
                                        <div class="mb-3">
                                            <label>Difficulty</label>
                                            <select class="form-control" name="difficulty">
                                                <option value="easy">Easy</option>
                                                <option value="medium">Medium</option>
                                                <option value="hard">Hard</option>
                                            </select>
                                        </div>
                                        <button type="submit" class="btn btn-primary">Add Quiz</button>
                                    </form>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <script src="https://code.jquery.com/jquery-3.6.4.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.6/js/dataTables.bootstrap5.min.js"></script>
    <script>
        // Form submissions would be handled via AJAX in production
        $('#addTopicForm').submit(function(e) {
            e.preventDefault();
            alert('Topic added successfully! (Backend integration needed)');
        });
        
        $('#addQuizForm').submit(function(e) {
            e.preventDefault();
            alert('Quiz added successfully! (Backend integration needed)');
        });
    </script>
</body>
</html>