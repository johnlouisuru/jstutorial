<?php
require_once 'database.php';

$db = new Database();
$conn = $db->getConnection();
$teacherSession = new TeacherSession($conn);

if (!$teacherSession->isLoggedIn()) {
    header('Location: teacher_login.php');
    exit;
}

// Update last active time
$teacherSession->updateLastActive();

$teacherUsername = $teacherSession->getTeacherUsername();
$teacherEmail = $teacherSession->getTeacherEmail();
$avatarColor = $teacherSession->getAvatarColor();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Teacher Dashboard - JS Tutorial System</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body>
    <nav class="navbar navbar-expand-lg navbar-dark bg-primary">
        <div class="container">
            <a class="navbar-brand" href="#">
                <i class="fas fa-chalkboard-teacher me-2"></i> Teacher Dashboard
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
    
    <div class="container mt-4">
        <div class="row">
            <div class="col-md-3">
                <div class="card">
                    <div class="card-header bg-light">
                        <h5 class="mb-0">Navigation</h5>
                    </div>
                    <div class="list-group list-group-flush">
                        <a href="manage_topics" class="list-group-item list-group-item-action">
                            <i class="fas fa-folder me-2"></i> Manage Topics
                        </a>
                        <a href="manage_lessons" class="list-group-item list-group-item-action">
                            <i class="fas fa-book me-2"></i> Manage Lessons
                        </a>
                        <!-- <a href="#" class="list-group-item list-group-item-action">
                            <i class="fas fa-users me-2"></i> Student Progress
                        </a> -->
                        <a href="student_management" class="list-group-item list-group-item-action">
                            <i class="fas fa-users me-2"></i> Student Management
                        </a>
                        <a href="analytics" class="list-group-item list-group-item-action">
                            <i class="fas fa-chart-bar me-2"></i> Analytics
                        </a>
                    </div>
                </div>
            </div>
            
            <div class="col-md-9">
                <div class="card">
                    <div class="card-header bg-light">
                        <h5 class="mb-0">Welcome, <?php echo htmlspecialchars($teacherUsername); ?>!</h5>
                    </div>
                    <div class="card-body">
                        <p>Welcome to your teacher dashboard. Here you can manage your JavaScript tutorial content.</p>
                        
                        <div class="row mt-4">
                            <div class="col-md-6">
                                <div class="card border-primary">
                                    <div class="card-body">
                                        <h5><i class="fas fa-folder text-primary me-2"></i> Topics</h5>
                                        <p class="card-text">Create and organize tutorial topics</p>
                                        <a href="manage_topics" class="btn btn-outline-primary">Manage Topics</a>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="card border-success">
                                    <div class="card-body">
                                        <h5><i class="fas fa-book text-success me-2"></i> Lessons</h5>
                                        <p class="card-text">Add interactive JavaScript lessons</p>
                                        <a href="manage_lessons" class="btn btn-outline-success">Manage Lessons</a>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>