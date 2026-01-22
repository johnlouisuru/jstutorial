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

// Handle AJAX actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $response = [];
    
    if (isset($_POST['action'])) {
        switch ($_POST['action']) {
            case 'get_student':
                $response = getStudent($conn, $_POST['student_id']);
                break;
            case 'update_student':
                $response = updateStudent($conn, $_POST);
                break;
            case 'delete_student':
                $response = deleteStudent($conn, $_POST['student_id']);
                break;
            case 'reset_password':
                $response = resetStudentPassword($conn, $_POST['student_id']);
                break;
            case 'get_student_progress':
                $response = getStudentProgress($conn, $_POST['student_id']);
                break;
            case 'bulk_import':
                $response = bulkImportStudents($conn, $_POST['students']);
                break;
        }
        
        header('Content-Type: application/json');
        echo json_encode($response);
        exit;
    }
}

// Fetch all students with their progress statistics
$query = "SELECT 
    s.id,
    s.username,
    s.email,
    s.full_name,
    s.created_at,
    s.last_active,
    s.is_active,
    s.avatar_color,
    s.total_score,
    COUNT(DISTINCT sp.lesson_id) as completed_lessons,
    (SELECT COUNT(*) FROM lessons WHERE deleted_at IS NULL AND is_active = 1) as total_lessons,
    MAX(sp.completed_at) as last_completed,
    (SELECT COUNT(*) FROM student_quiz_attempts WHERE student_id = s.id) as quiz_attempts,
    (SELECT AVG(CASE WHEN is_correct = 1 THEN 100 ELSE 0 END) FROM student_quiz_attempts WHERE student_id = s.id) as avg_quiz_score
FROM students s
LEFT JOIN student_progress sp ON s.id = sp.student_id AND sp.is_completed = 1
WHERE s.deleted_at IS NULL
GROUP BY s.id
ORDER BY s.created_at DESC";

$students = $conn->query($query)->fetchAll(PDO::FETCH_ASSOC);

// Get total statistics
$stats_query = "SELECT 
    COUNT(*) as total_students,
    SUM(CASE WHEN is_active = 1 THEN 1 ELSE 0 END) as active_students,
    COUNT(DISTINCT DATE(created_at)) as signups_today,
    AVG(TIMESTAMPDIFF(HOUR, created_at, last_active) <= 24) * 100 as daily_active_percent
FROM students 
WHERE deleted_at IS NULL";

$stats = $conn->query($stats_query)->fetch(PDO::FETCH_ASSOC);

// Functions for AJAX actions
function getStudent($conn, $student_id) {
    try {
        $query = "SELECT * FROM students WHERE id = ? AND deleted_at IS NULL";
        $stmt = $conn->prepare($query);
        $stmt->execute([$student_id]);
        $student = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$student) {
            return ['success' => false, 'message' => 'Student not found'];
        }
        
        return ['success' => true, 'student' => $student];
    } catch (PDOException $e) {
        return ['success' => false, 'message' => 'Database error: ' . $e->getMessage()];
    }
}

function updateStudent($conn, $data) {
    try {
        $query = "UPDATE students SET 
                 username = :username,
                 email = :email,
                 full_name = :full_name,
                 is_active = :is_active
                 WHERE id = :id AND deleted_at IS NULL";
        
        $stmt = $conn->prepare($query);
        $stmt->bindParam(':username', $data['username']);
        $stmt->bindParam(':email', $data['email']);
        $stmt->bindParam(':full_name', $data['full_name']);
        $stmt->bindParam(':is_active', $data['is_active']);
        $stmt->bindParam(':id', $data['student_id']);
        $stmt->execute();
        
        return ['success' => true, 'message' => 'Student updated successfully'];
    } catch (PDOException $e) {
        return ['success' => false, 'message' => 'Database error: ' . $e->getMessage()];
    }
}

function deleteStudent($conn, $student_id) {
    try {
        // Soft delete the student
        $query = "UPDATE students SET deleted_at = NOW() WHERE id = ?";
        $stmt = $conn->prepare($query);
        $stmt->execute([$student_id]);
        
        return ['success' => true, 'message' => 'Student deleted successfully'];
    } catch (PDOException $e) {
        return ['success' => false, 'message' => 'Database error: ' . $e->getMessage()];
    }
}

function resetStudentPassword($conn, $student_id) {
    try {
        // Generate a random password
        $new_password = bin2hex(random_bytes(4)); // 8 character password
        $hashed_password = password_hash($new_password, PASSWORD_DEFAULT);
        
        $query = "UPDATE students SET password = ? WHERE id = ? AND deleted_at IS NULL";
        $stmt = $conn->prepare($query);
        $stmt->execute([$hashed_password, $student_id]);
        
        return [
            'success' => true, 
            'message' => 'Password reset successfully',
            'new_password' => $new_password
        ];
    } catch (PDOException $e) {
        return ['success' => false, 'message' => 'Database error: ' . $e->getMessage()];
    }
}

function getStudentProgress($conn, $student_id) {
    try {
        $query = "SELECT 
            t.topic_name,
            l.lesson_title,
            l.lesson_order,
            sp.is_completed as completed,
            sp.completed_at,
            sp.last_accessed,
            q.id as quiz_id,
            q.question,
            sqa.is_correct as quiz_correct,
            sqa.time_spent as quiz_time_spent,
            sqa.attempted_at as quiz_attempted_at
        FROM student_progress sp
        JOIN lessons l ON sp.lesson_id = l.id AND l.deleted_at IS NULL
        JOIN topics t ON l.topic_id = t.id AND t.deleted_at IS NULL
        LEFT JOIN quizzes q ON q.lesson_id = l.id AND q.deleted_at IS NULL
        LEFT JOIN student_quiz_attempts sqa ON sqa.quiz_id = q.id AND sqa.student_id = sp.student_id
        WHERE sp.student_id = ?
        ORDER BY t.topic_order, l.lesson_order";
        
        $stmt = $conn->prepare($query);
        $stmt->execute([$student_id]);
        $progress = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Calculate totals
        $total_lessons = 0;
        $completed_lessons = 0;
        $quiz_correct = 0;
        $quiz_total = 0;
        $total_time = 0;
        $topics = [];
        
        foreach ($progress as $item) {
            if (!isset($topics[$item['topic_name']])) {
                $topics[$item['topic_name']] = [
                    'total' => 0,
                    'completed' => 0,
                    'quizzes' => 0,
                    'correct_quizzes' => 0
                ];
            }
            
            // Count each lesson only once (deduplicate by lesson_id)
            static $processed_lessons = [];
            if (!in_array($item['lesson_title'], $processed_lessons)) {
                $topics[$item['topic_name']]['total']++;
                $total_lessons++;
                $processed_lessons[] = $item['lesson_title'];
                
                if ($item['completed']) {
                    $topics[$item['topic_name']]['completed']++;
                    $completed_lessons++;
                }
            }
            
            // Count quizzes
            if ($item['quiz_id'] && $item['quiz_correct'] !== null) {
                $topics[$item['topic_name']]['quizzes']++;
                $quiz_total++;
                
                if ($item['quiz_correct']) {
                    $topics[$item['topic_name']]['correct_quizzes']++;
                    $quiz_correct++;
                }
                
                if ($item['quiz_time_spent']) {
                    $total_time += $item['quiz_time_spent'];
                }
            }
        }
        
        // Clear static variable for next call
        unset($processed_lessons);
        
        return [
            'success' => true,
            'progress' => $progress,
            'summary' => [
                'total_lessons' => $total_lessons,
                'completed_lessons' => $completed_lessons,
                'completion_rate' => $total_lessons > 0 ? round(($completed_lessons / $total_lessons) * 100, 1) : 0,
                'total_time_seconds' => $total_time,
                'total_time_minutes' => round($total_time / 60, 1),
                'quiz_score' => $quiz_total > 0 ? round(($quiz_correct / $quiz_total) * 100, 1) : 0,
                'quiz_attempts' => $quiz_total,
                'topics' => $topics
            ]
        ];
    } catch (PDOException $e) {
        return ['success' => false, 'message' => 'Database error: ' . $e->getMessage()];
    }
}

function bulkImportStudents($conn, $students_data) {
    try {
        $students = json_decode($students_data, true);
        $results = [
            'success' => 0,
            'failed' => 0,
            'errors' => []
        ];
        
        $conn->beginTransaction();
        
        foreach ($students as $index => $student) {
            // Validate required fields
            if (empty($student['username']) || empty($student['email'])) {
                $results['failed']++;
                $results['errors'][] = "Row " . ($index + 1) . ": Missing username or email";
                continue;
            }
            
            // Check if student already exists
            $check_query = "SELECT id FROM students WHERE (username = ? OR email = ?) AND deleted_at IS NULL";
            $check_stmt = $conn->prepare($check_query);
            $check_stmt->execute([$student['username'], $student['email']]);
            
            if ($check_stmt->rowCount() > 0) {
                $results['failed']++;
                $results['errors'][] = "Row " . ($index + 1) . ": Student already exists";
                continue;
            }
            
            // Generate random password and avatar color
            $temp_password = bin2hex(random_bytes(4));
            $hashed_password = password_hash($temp_password, PASSWORD_DEFAULT);
            
            $colors = ['#4361ee', '#3a0ca3', '#7209b7', '#f72585', '#4cc9f0', '#4895ef', '#560bad', '#b5179e'];
            $avatar_color = $colors[array_rand($colors)];
            
            // Insert student
            $insert_query = "INSERT INTO students (username, email, password, avatar_color, is_active) 
                           VALUES (?, ?, ?, ?, 1)";
            $insert_stmt = $conn->prepare($insert_query);
            $insert_stmt->execute([
                $student['username'],
                $student['email'],
                $hashed_password,
                $avatar_color
            ]);
            
            $results['success']++;
        }
        
        $conn->commit();
        
        return [
            'success' => true,
            'message' => "Import completed: {$results['success']} successful, {$results['failed']} failed",
            'results' => $results
        ];
    } catch (PDOException $e) {
        $conn->rollBack();
        return ['success' => false, 'message' => 'Database error: ' . $e->getMessage()];
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Student Management - JS Tutorial System</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.4/css/dataTables.bootstrap5.min.css">
    <style>
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
        
        .progress-card {
            border-left: 4px solid #4361ee;
        }
        
        .active-status {
            display: inline-block;
            width: 10px;
            height: 10px;
            border-radius: 50%;
            margin-right: 5px;
        }
        
        .status-active { background: #28a745; }
        .status-inactive { background: #dc3545; }
        
        .table-hover tbody tr:hover {
            background-color: rgba(67, 97, 238, 0.05);
        }
        
        .completion-badge {
            font-size: 0.75rem;
            padding: 3px 8px;
            border-radius: 10px;
        }
        
        .completion-high { background: #d4edda; color: #155724; }
        .completion-medium { background: #fff3cd; color: #856404; }
        .completion-low { background: #f8d7da; color: #721c24; }
        
        .nav-tabs .nav-link.active {
            border-bottom: 3px solid #4361ee;
            font-weight: 600;
        }
        
        .total-score {
            font-size: 0.85rem;
            padding: 2px 6px;
            border-radius: 4px;
            background: #e9ecef;
            color: #495057;
        }
    </style>
</head>
<body>
    <!-- Navigation from teacher_dashboard.php -->
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
                        <li><a class="dropdown-item" href="#">
                            <i class="fas fa-user me-2"></i> Profile
                        </a></li>
                        <li><a class="dropdown-item" href="#">
                            <i class="fas fa-cog me-2"></i> Settings
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
        <div class="row">
            <!-- Sidebar -->
            <div class="col-md-3">
                <div class="card">
                    <div class="card-header bg-light">
                        <h5 class="mb-0">Navigation</h5>
                    </div>
                    <div class="list-group list-group-flush">
                        <a href="teacher_dashboard" class="list-group-item list-group-item-action">
                            <i class="fas fa-tachometer-alt me-2"></i> Dashboard
                        </a>
                        <a href="manage_topics" class="list-group-item list-group-item-action">
                            <i class="fas fa-folder me-2"></i> Manage Topics
                        </a>
                        <a href="manage_lessons" class="list-group-item list-group-item-action">
                            <i class="fas fa-book me-2"></i> Manage Lessons
                        </a>
                        <a href="student_management" class="list-group-item list-group-item-action active">
                            <i class="fas fa-users me-2"></i> Student Management
                        </a>
                        <a href="analytics" class="list-group-item list-group-item-action">
                            <i class="fas fa-chart-bar me-2"></i> Analytics
                        </a>
                    </div>
                </div>
                
                <!-- Quick Stats -->
                <div class="card mt-4">
                    <div class="card-header bg-light">
                        <h6 class="mb-0">Quick Stats</h6>
                    </div>
                    <div class="card-body">
                        <div class="mb-3">
                            <small class="text-muted">Total Students</small>
                            <h4><?php echo $stats['total_students']; ?></h4>
                        </div>
                        <div class="mb-3">
                            <small class="text-muted">Active Students</small>
                            <h4><?php echo $stats['active_students']; ?></h4>
                        </div>
                        <div class="mb-3">
                            <small class="text-muted">Daily Active</small>
                            <h4><?php echo round($stats['daily_active_percent'], 1); ?>%</h4>
                        </div>
                        <div>
                            <small class="text-muted">Today's Signups</small>
                            <h4><?php echo $stats['signups_today']; ?></h4>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Main Content -->
            <div class="col-md-9">
                <!-- Header -->
                <div class="d-flex justify-content-between align-items-center mb-4">
                    <div>
                        <h2><i class="fas fa-users me-2 text-primary"></i>Student Management</h2>
                        <p class="text-muted">Manage student accounts and track progress</p>
                    </div>
                    <div>
                        <button class="btn btn-primary me-2" data-bs-toggle="modal" data-bs-target="#importModal">
                            <i class="fas fa-file-import me-1"></i> Import
                        </button>
                        <button class="btn btn-success" onclick="exportStudents()">
                            <i class="fas fa-file-export me-1"></i> Export
                        </button>
                    </div>
                </div>
                
                <!-- Filters -->
                <div class="card mb-4">
                    <div class="card-body">
                        <div class="row g-3">
                            <div class="col-md-4">
                                <input type="text" class="form-control" id="searchInput" 
                                       placeholder="Search students...">
                            </div>
                            <div class="col-md-3">
                                <select class="form-select" id="statusFilter">
                                    <option value="">All Status</option>
                                    <option value="active">Active</option>
                                    <option value="inactive">Inactive</option>
                                </select>
                            </div>
                            <div class="col-md-3">
                                <select class="form-select" id="progressFilter">
                                    <option value="">All Progress</option>
                                    <option value="high">High (>70%)</option>
                                    <option value="medium">Medium (30-70%)</option>
                                    <option value="low">Low (<30%)</option>
                                </select>
                            </div>
                            <div class="col-md-2">
                                <button class="btn btn-outline-secondary w-100" onclick="resetFilters()">
                                    <i class="fas fa-redo"></i> Reset
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Students Table -->
                <div class="card">
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-hover" id="studentsTable">
                                <thead>
                                    <tr>
                                        <th>Student</th>
                                        <th>Progress</th>
                                        <th>Total Score</th>
                                        <th>Last Active</th>
                                        <th>Quizzes</th>
                                        <th>Status</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (count($students) > 0): ?>
                                        <?php foreach ($students as $student): 
                                            $completion_rate = $student['total_lessons'] > 0 
                                                ? round(($student['completed_lessons'] / $student['total_lessons']) * 100, 1) 
                                                : 0;
                                            
                                            $completion_class = '';
                                            if ($completion_rate >= 70) $completion_class = 'completion-high';
                                            elseif ($completion_rate >= 30) $completion_class = 'completion-medium';
                                            else $completion_class = 'completion-low';
                                            
                                            $full_name = $student['full_name'] ?: 'Not set';
                                        ?>
                                        <tr data-student-id="<?php echo $student['id']; ?>"
                                            data-status="<?php echo $student['is_active'] ? 'active' : 'inactive'; ?>"
                                            data-progress="<?php echo $completion_rate >= 70 ? 'high' : ($completion_rate >= 30 ? 'medium' : 'low'); ?>">
                                            <td>
                                                <div class="d-flex align-items-center">
                                                    <div class="student-avatar me-3" 
                                                         style="background: <?php echo $student['avatar_color']; ?>">
                                                        <?php echo strtoupper(substr($student['username'], 0, 1)); ?>
                                                    </div>
                                                    <div>
                                                        <strong><?php echo htmlspecialchars($student['username']); ?></strong>
                                                        <div class="text-muted small"><?php echo htmlspecialchars($student['email']); ?></div>
                                                        <div class="text-muted small">
                                                            <?php echo htmlspecialchars($full_name); ?> • 
                                                            Joined: <?php echo date('M d, Y', strtotime($student['created_at'])); ?>
                                                        </div>
                                                    </div>
                                                </div>
                                            </td>
                                            <td>
                                                <div class="mb-1">
                                                    <span class="badge <?php echo $completion_class; ?>">
                                                        <?php echo $completion_rate; ?>% Complete
                                                    </span>
                                                </div>
                                                <div class="progress" style="height: 6px;">
                                                    <div class="progress-bar" 
                                                         role="progressbar" 
                                                         style="width: <?php echo $completion_rate; ?>%"
                                                         aria-valuenow="<?php echo $completion_rate; ?>" 
                                                         aria-valuemin="0" 
                                                         aria-valuemax="100">
                                                    </div>
                                                </div>
                                                <small class="text-muted">
                                                    <?php echo $student['completed_lessons']; ?> of <?php echo $student['total_lessons']; ?> lessons
                                                </small>
                                            </td>
                                            <td>
                                                <span class="total-score">
                                                    <i class="fas fa-star text-warning me-1"></i>
                                                    <?php echo $student['total_score']; ?> pts
                                                </span>
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
                                                <?php if ($student['quiz_attempts'] > 0): ?>
                                                    <div class="mb-1">
                                                        <strong><?php echo $student['quiz_attempts']; ?> attempts</strong>
                                                    </div>
                                                    <div class="text-muted small">
                                                        Avg Score: <?php echo round($student['avg_quiz_score'], 1); ?>%
                                                    </div>
                                                <?php else: ?>
                                                    <span class="text-muted">No attempts</span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <span class="active-status status-<?php echo $student['is_active'] ? 'active' : 'inactive'; ?>"></span>
                                                <?php echo $student['is_active'] ? 'Active' : 'Inactive'; ?>
                                            </td>
                                            <td>
                                                <div class="btn-group btn-group-sm">
                                                    <button class="btn btn-outline-primary" 
                                                            onclick="viewProgress(<?php echo $student['id']; ?>)"
                                                            title="View Progress">
                                                        <i class="fas fa-chart-line"></i>
                                                    </button>
                                                    <button class="btn btn-outline-secondary" 
                                                            onclick="editStudent(<?php echo $student['id']; ?>)"
                                                            title="Edit">
                                                        <i class="fas fa-edit"></i>
                                                    </button>
                                                    <button class="btn btn-outline-warning" 
                                                            onclick="resetStudentPassword(<?php echo $student['id']; ?>)"
                                                            title="Reset Password">
                                                        <i class="fas fa-key"></i>
                                                    </button>
                                                    <button class="btn btn-outline-danger" 
                                                            onclick="deleteStudent(<?php echo $student['id']; ?>)"
                                                            title="Delete">
                                                        <i class="fas fa-trash"></i>
                                                    </button>
                                                </div>
                                            </td>
                                        </tr>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <tr>
                                            <td colspan="7" class="text-center py-5">
                                                <i class="fas fa-users fa-3x text-muted mb-3"></i>
                                                <h5>No Students Found</h5>
                                                <p class="text-muted">Students will appear here once they register</p>
                                            </td>
                                        </tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Student Progress Modal -->
    <div class="modal fade" id="progressModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Student Progress Details</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div id="progressContent">
                        <div class="text-center py-4">
                            <div class="spinner-border text-primary" role="status">
                                <span class="visually-hidden">Loading...</span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Edit Student Modal -->
    <div class="modal fade" id="editStudentModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Edit Student</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <form id="editStudentForm">
                        <input type="hidden" id="editStudentId">
                        <div class="mb-3">
                            <label class="form-label">Username</label>
                            <input type="text" class="form-control" id="editUsername" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Email</label>
                            <input type="email" class="form-control" id="editEmail" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Full Name</label>
                            <input type="text" class="form-control" id="editFullName">
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Status</label>
                            <div class="form-check">
                                <input class="form-check-input" type="radio" name="editStatus" id="editActive" value="1">
                                <label class="form-check-label" for="editActive">Active</label>
                            </div>
                            <div class="form-check">
                                <input class="form-check-input" type="radio" name="editStatus" id="editInactive" value="0">
                                <label class="form-check-label" for="editInactive">Inactive</label>
                            </div>
                        </div>
                    </form>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="button" class="btn btn-primary" onclick="saveStudent()">Save Changes</button>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Import Students Modal -->
    <div class="modal fade" id="importModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Import Students</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="alert alert-info">
                        <i class="fas fa-info-circle me-2"></i>
                        Upload a CSV file with columns: username, email
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Upload CSV File</label>
                        <input type="file" class="form-control" id="csvFile" accept=".csv">
                        <small class="text-muted">File should contain username and email columns</small>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Or paste CSV data</label>
                        <textarea class="form-control" id="csvData" rows="5" 
                                  placeholder="username,email
john_doe,john@example.com
jane_smith,jane@example.com"></textarea>
                    </div>
                    
                    <div id="importPreview" class="d-none">
                        <h6>Preview:</h6>
                        <div class="table-responsive">
                            <table class="table table-sm" id="previewTable">
                                <thead>
                                    <tr>
                                        <th>Username</th>
                                        <th>Email</th>
                                    </tr>
                                </thead>
                                <tbody></tbody>
                            </table>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="button" class="btn btn-primary" onclick="importStudents()">Import Students</button>
                </div>
            </div>
        </div>
    </div>
    
    <script src="https://code.jquery.com/jquery-3.6.4.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.4/js/jquery.dataTables.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.4/js/dataTables.bootstrap5.min.js"></script>
    <script>
        // Initialize DataTable
        $(document).ready(function() {
            $('#studentsTable').DataTable({
                pageLength: 10,
                lengthMenu: [[10, 25, 50, -1], [10, 25, 50, 'All']],
                order: [[0, 'asc']]
            });
        });
        
        // Filter functions
        function filterTable() {
            const searchTerm = $('#searchInput').val().toLowerCase();
            const statusFilter = $('#statusFilter').val();
            const progressFilter = $('#progressFilter').val();
            
            $('#studentsTable tbody tr').each(function() {
                const studentName = $(this).find('strong').text().toLowerCase();
                const studentEmail = $(this).find('.text-muted.small').first().text().toLowerCase();
                const studentFullName = $(this).find('.text-muted.small').eq(1).text().toLowerCase();
                const status = $(this).data('status');
                const progress = $(this).data('progress');
                
                let showRow = true;
                
                // Search filter
                if (searchTerm && 
                    !studentName.includes(searchTerm) && 
                    !studentEmail.includes(searchTerm) &&
                    !studentFullName.includes(searchTerm)) {
                    showRow = false;
                }
                
                // Status filter
                if (statusFilter && status !== statusFilter) {
                    showRow = false;
                }
                
                // Progress filter
                if (progressFilter && progress !== progressFilter) {
                    showRow = false;
                }
                
                if (showRow) {
                    $(this).show();
                } else {
                    $(this).hide();
                }
            });
        }
        
        function resetFilters() {
            $('#searchInput').val('');
            $('#statusFilter').val('');
            $('#progressFilter').val('');
            $('#studentsTable tbody tr').show();
        }
        
        // Event listeners for filters
        $('#searchInput').on('keyup', filterTable);
        $('#statusFilter, #progressFilter').on('change', filterTable);
        
        // View student progress
        function viewProgress(studentId) {
            $('#progressContent').html(`
                <div class="text-center py-4">
                    <div class="spinner-border text-primary" role="status">
                        <span class="visually-hidden">Loading...</span>
                    </div>
                </div>
            `);
            
            const modal = new bootstrap.Modal(document.getElementById('progressModal'));
            modal.show();
            
            fetch('student_management.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: `action=get_student_progress&student_id=${studentId}`
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    const progress = data.progress;
                    const summary = data.summary;
                    
                    let html = `
                        <div class="mb-4">
                            <div class="row">
                                <div class="col-md-3">
                                    <div class="card bg-light">
                                        <div class="card-body text-center">
                                            <h3>${summary.completed_lessons}/${summary.total_lessons}</h3>
                                            <small>Lessons Completed</small>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-md-3">
                                    <div class="card bg-light">
                                        <div class="card-body text-center">
                                            <h3>${summary.completion_rate}%</h3>
                                            <small>Completion Rate</small>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-md-3">
                                    <div class="card bg-light">
                                        <div class="card-body text-center">
                                            <h3>${summary.total_time_minutes}</h3>
                                            <small>Minutes Spent</small>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-md-3">
                                    <div class="card bg-light">
                                        <div class="card-body text-center">
                                            <h3>${summary.quiz_score}%</h3>
                                            <small>Quiz Score</small>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <h5>Topic Progress</h5>
                    `;
                    
                    // Group by topic
                    const topics = {};
                    progress.forEach(item => {
                        if (!topics[item.topic_name]) {
                            topics[item.topic_name] = [];
                        }
                        topics[item.topic_name].push(item);
                    });
                    
                    for (const [topicName, lessons] of Object.entries(topics)) {
                        const completed = lessons.filter(l => l.completed).length;
                        const total = lessons.length;
                        const percentage = Math.round((completed / total) * 100);
                        
                        html += `
                            <div class="card mb-3">
                                <div class="card-header bg-light">
                                    <strong>${topicName}</strong>
                                    <span class="badge bg-primary float-end">${completed}/${total} lessons</span>
                                </div>
                                <div class="card-body">
                                    <div class="progress mb-3" style="height: 10px;">
                                        <div class="progress-bar bg-success" style="width: ${percentage}%"></div>
                                    </div>
                                    <div class="table-responsive">
                                        <table class="table table-sm">
                                            <thead>
                                                <tr>
                                                    <th>Lesson</th>
                                                    <th>Status</th>
                                                    <th>Last Accessed</th>
                                                    <th>Quiz Result</th>
                                                    <th>Quiz Time</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                        `;
                        
                        lessons.forEach(lesson => {
                            html += `
                                <tr>
                                    <td>${lesson.lesson_title}</td>
                                    <td>
                                        ${lesson.completed 
                                            ? '<span class="badge bg-success">Completed</span>' 
                                            : '<span class="badge bg-secondary">Not Started</span>'}
                                    </td>
                                    <td>${lesson.last_accessed ? new Date(lesson.last_accessed).toLocaleDateString() : '-'}</td>
                                    <td>
                                        ${lesson.quiz_correct !== null 
                                            ? (lesson.quiz_correct 
                                                ? '<span class="badge bg-success">Correct</span>' 
                                                : '<span class="badge bg-danger">Incorrect</span>')
                                            : '-'}
                                    </td>
                                    <td>${lesson.quiz_time_spent ? Math.round(lesson.quiz_time_spent) + ' sec' : '-'}</td>
                                </tr>
                            `;
                        });
                        
                        html += `
                                            </tbody>
                                        </table>
                                    </div>
                                </div>
                            </div>
                        `;
                    }
                    
                    $('#progressContent').html(html);
                } else {
                    $('#progressContent').html(`
                        <div class="alert alert-danger">
                            <i class="fas fa-exclamation-circle me-2"></i>
                            ${data.message}
                        </div>
                    `);
                }
            });
        }
        
        // Edit student
        function editStudent(studentId) {
            fetch('student_management.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: `action=get_student&student_id=${studentId}`
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    const student = data.student;
                    
                    $('#editStudentId').val(student.id);
                    $('#editUsername').val(student.username);
                    $('#editEmail').val(student.email);
                    $('#editFullName').val(student.full_name || '');
                    
                    if (student.is_active == 1) {
                        $('#editActive').prop('checked', true);
                    } else {
                        $('#editInactive').prop('checked', true);
                    }
                    
                    const modal = new bootstrap.Modal(document.getElementById('editStudentModal'));
                    modal.show();
                } else {
                    alert('Error: ' + data.message);
                }
            });
        }
        
        // Save student changes
        function saveStudent() {
            const formData = new FormData();
            formData.append('action', 'update_student');
            formData.append('student_id', $('#editStudentId').val());
            formData.append('username', $('#editUsername').val());
            formData.append('email', $('#editEmail').val());
            formData.append('full_name', $('#editFullName').val());
            formData.append('is_active', $('input[name="editStatus"]:checked').val());
            
            fetch('student_management.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    alert('Student updated successfully!');
                    location.reload();
                } else {
                    alert('Error: ' + data.message);
                }
            });
        }
        
        // Reset student password
        function resetStudentPassword(studentId) {
            if (!confirm('Are you sure you want to reset this student\'s password? They will receive a new temporary password.')) {
                return;
            }
            
            fetch('student_management.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: `action=reset_password&student_id=${studentId}`
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    alert(`Password reset successful! New password: ${data.new_password}\n\nCopy this password and share it with the student securely.`);
                } else {
                    alert('Error: ' + data.message);
                }
            });
        }
        
        // Delete student
        function deleteStudent(studentId) {
            if (!confirm('Are you sure you want to delete this student? This will remove their account and all progress data.')) {
                return;
            }
            
            fetch('student_management.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: `action=delete_student&student_id=${studentId}`
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    alert('Student deleted successfully!');
                    location.reload();
                } else {
                    alert('Error: ' + data.message);
                }
            });
        }
        
        // Import students
        function importStudents() {
            const csvData = $('#csvData').val();
            const fileInput = $('#csvFile')[0];
            
            let students = [];
            
            if (csvData.trim()) {
                // Parse CSV from textarea
                const lines = csvData.split('\n');
                lines.forEach((line, index) => {
                    if (index === 0 && line.toLowerCase().includes('username')) {
                        return; // Skip header
                    }
                    
                    const parts = line.split(',');
                    if (parts.length >= 2) {
                        students.push({
                            username: parts[0].trim(),
                            email: parts[1].trim()
                        });
                    }
                });
            } else if (fileInput.files.length > 0) {
                const file = fileInput.files[0];
                const reader = new FileReader();
                
                reader.onload = function(e) {
                    const content = e.target.result;
                    const lines = content.split('\n');
                    
                    lines.forEach((line, index) => {
                        if (index === 0 && line.toLowerCase().includes('username')) {
                            return; // Skip header
                        }
                        
                        const parts = line.split(',');
                        if (parts.length >= 2) {
                            students.push({
                                username: parts[0].trim(),
                                email: parts[1].trim()
                            });
                        }
                    });
                    
                    performImport(students);
                };
                
                reader.readAsText(file);
                return;
            } else {
                alert('Please provide CSV data or upload a file.');
                return;
            }
            
            performImport(students);
        }
        
        function performImport(students) {
            if (students.length === 0) {
                alert('No valid student data found.');
                return;
            }
            
            fetch('student_management.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: `action=bulk_import&students=${JSON.stringify(students)}`
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    alert(data.message);
                    if (data.results.failed > 0) {
                        alert('Errors:\n' + data.results.errors.join('\n'));
                    }
                    location.reload();
                } else {
                    alert('Error: ' + data.message);
                }
            });
        }
        
        // Export students
        function exportStudents() {
            // Get table data
            const rows = [];
            $('#studentsTable tbody tr').each(function() {
                const username = $(this).find('strong').text();
                const email = $(this).find('.text-muted.small').first().text();
                const fullName = $(this).find('.text-muted.small').eq(1).text().split('•')[0].trim();
                const progress = $(this).find('.badge').first().text().trim();
                const totalScore = $(this).find('.total-score').text().trim();
                const status = $(this).find('td:nth-child(6)').text().trim();
                
                rows.push([username, email, fullName, progress, totalScore, status]);
            });
            
            // Create CSV content
            const headers = ['Username', 'Email', 'Full Name', 'Progress', 'Total Score', 'Status'];
            const csvContent = [
                headers.join(','),
                ...rows.map(row => row.join(','))
            ].join('\n');
            
            // Create download link
            const blob = new Blob([csvContent], { type: 'text/csv' });
            const url = window.URL.createObjectURL(blob);
            const a = document.createElement('a');
            a.href = url;
            a.download = `students_${new Date().toISOString().split('T')[0]}.csv`;
            document.body.appendChild(a);
            a.click();
            document.body.removeChild(a);
            window.URL.revokeObjectURL(url);
        }
    </script>
</body>
</html>