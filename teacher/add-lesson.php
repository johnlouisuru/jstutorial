<?php
require_once 'database.php';

$db = new Database();
$conn = $db->getConnection();
$teacherSession = new TeacherSession($conn);

if (!$teacherSession->isLoggedIn()) {
    header('Location: teacher_login');
    exit;
}

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $response = [];
    
    if (isset($_POST['action'])) {
        switch ($_POST['action']) {
            case 'save_lesson':
                $response = saveLesson($conn, $_POST);
                break;
            case 'save_quiz':
                $response = saveQuiz($conn, $_POST);
                break;
            case 'upload_image':
                $response = uploadImage();
                break;
            case 'get_topic_lessons':
                $response = getTopicLessons($conn, $_POST['topic_id']);
                break;
            case 'get_lesson_data':
                $response = getLessonData($conn, $_POST['lesson_id']);
                break;
            case 'delete_lesson':
                $response = deleteLesson($conn, $_POST['lesson_id']);
                break;
        }
        
        header('Content-Type: application/json');
        echo json_encode($response);
        exit;
    }
}

// Fetch all topics for dropdown
$topics_query = "SELECT id, topic_name FROM topics WHERE is_active = 1 AND deleted_at IS NULL ORDER BY topic_order";
$topics = $conn->query($topics_query)->fetchAll(PDO::FETCH_ASSOC);

// Function to save lesson
function saveLesson($conn, $data) {
    try {
        $conn->beginTransaction();
        
        if (isset($data['lesson_id']) && $data['lesson_id']) {
            // Update existing lesson
            $query = "UPDATE lessons SET 
                      topic_id = :topic_id,
                      lesson_title = :lesson_title,
                      lesson_content = :lesson_content,
                      lesson_order = :lesson_order,
                      content_type = :content_type,
                      is_active = :is_active
                      WHERE id = :lesson_id";
            
            $stmt = $conn->prepare($query);
            $stmt->bindParam(':lesson_id', $data['lesson_id']);
        } else {
            // Insert new lesson
            $query = "INSERT INTO lessons (topic_id, lesson_title, lesson_content, lesson_order, content_type, is_active) 
                      VALUES (:topic_id, :lesson_title, :lesson_content, :lesson_order, :content_type, :is_active)";
            
            $stmt = $conn->prepare($query);
        }
        
        $stmt->bindParam(':topic_id', $data['topic_id']);
        $stmt->bindParam(':lesson_title', $data['lesson_title']);
        $stmt->bindParam(':lesson_content', $data['lesson_content']);
        $stmt->bindParam(':lesson_order', $data['lesson_order']);
        $stmt->bindParam(':content_type', $data['content_type']);
        $stmt->bindParam(':is_active', $data['is_active']);
        
        $stmt->execute();
        
        $lesson_id = isset($data['lesson_id']) && $data['lesson_id'] ? $data['lesson_id'] : $conn->lastInsertId();
        
        $conn->commit();
        
        return [
            'success' => true,
            'message' => 'Lesson saved successfully!',
            'lesson_id' => $lesson_id
        ];
    } catch (PDOException $e) {
        $conn->rollBack();
        return [
            'success' => false,
            'message' => 'Database error: ' . $e->getMessage()
        ];
    }
}

// Function to save quiz
function saveQuiz($conn, $data) {
    try {
        $conn->beginTransaction();
        
        // Save quiz question
        if (isset($data['quiz_id']) && $data['quiz_id']) {
            $quiz_query = "UPDATE quizzes SET 
                          lesson_id = :lesson_id,
                          question = :question,
                          explanation = :explanation,
                          difficulty = :difficulty
                          WHERE id = :quiz_id";
            
            $quiz_stmt = $conn->prepare($quiz_query);
            $quiz_stmt->bindParam(':quiz_id', $data['quiz_id']);
        } else {
            $quiz_query = "INSERT INTO quizzes (lesson_id, question, explanation, difficulty) 
                          VALUES (:lesson_id, :question, :explanation, :difficulty)";
            
            $quiz_stmt = $conn->prepare($quiz_query);
        }
        
        $quiz_stmt->bindParam(':lesson_id', $data['lesson_id']);
        $quiz_stmt->bindParam(':question', $data['question']);
        $quiz_stmt->bindParam(':explanation', $data['explanation']);
        $quiz_stmt->bindParam(':difficulty', $data['difficulty']);
        $quiz_stmt->execute();
        
        $quiz_id = isset($data['quiz_id']) && $data['quiz_id'] ? $data['quiz_id'] : $conn->lastInsertId();
        
        // Delete existing options if updating
        if (isset($data['quiz_id']) && $data['quiz_id']) {
            $delete_query = "DELETE FROM quiz_options WHERE quiz_id = :quiz_id";
            $delete_stmt = $conn->prepare($delete_query);
            $delete_stmt->bindParam(':quiz_id', $quiz_id);
            $delete_stmt->execute();
        }
        
        // Save quiz options
        $options = json_decode($data['options'], true);
        
        foreach ($options as $index => $option) {
            $option_query = "INSERT INTO quiz_options (quiz_id, option_text, is_correct, option_order) 
                            VALUES (:quiz_id, :option_text, :is_correct, :option_order)";
            
            $option_stmt = $conn->prepare($option_query);
            $option_stmt->bindParam(':quiz_id', $quiz_id);
            $option_stmt->bindParam(':option_text', $option['text']);
            $option_stmt->bindParam(':is_correct', $option['is_correct']);
            $option_stmt->bindParam(':option_order', $index);
            $option_stmt->execute();
        }
        
        $conn->commit();
        
        return [
            'success' => true,
            'message' => 'Quiz saved successfully!',
            'quiz_id' => $quiz_id
        ];
    } catch (PDOException $e) {
        $conn->rollBack();
        return [
            'success' => false,
            'message' => 'Database error: ' . $e->getMessage()
        ];
    }
}

// Function to get topic lessons
function getTopicLessons($conn, $topic_id) {
    try {
        $query = "SELECT id, lesson_title, lesson_order, is_active 
                  FROM lessons 
                  WHERE topic_id = :topic_id 
                  AND deleted_at IS NULL 
                  ORDER BY lesson_order";
        
        $stmt = $conn->prepare($query);
        $stmt->bindParam(':topic_id', $topic_id);
        $stmt->execute();
        
        $lessons = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        return [
            'success' => true,
            'lessons' => $lessons
        ];
    } catch (PDOException $e) {
        return [
            'success' => false,
            'message' => 'Database error: ' . $e->getMessage()
        ];
    }
}

// Function to get lesson data
function getLessonData($conn, $lesson_id) {
    try {
        // Get lesson data
        $lesson_query = "SELECT * FROM lessons WHERE id = :lesson_id";
        $lesson_stmt = $conn->prepare($lesson_query);
        $lesson_stmt->bindParam(':lesson_id', $lesson_id);
        $lesson_stmt->execute();
        $lesson = $lesson_stmt->fetch(PDO::FETCH_ASSOC);
        
        // Get quiz data if exists
        $quiz_query = "SELECT * FROM quizzes WHERE lesson_id = :lesson_id LIMIT 1";
        $quiz_stmt = $conn->prepare($quiz_query);
        $quiz_stmt->bindParam(':lesson_id', $lesson_id);
        $quiz_stmt->execute();
        $quiz = $quiz_stmt->fetch(PDO::FETCH_ASSOC);
        
        $options = [];
        if ($quiz) {
            $options_query = "SELECT * FROM quiz_options WHERE quiz_id = :quiz_id ORDER BY option_order";
            $options_stmt = $conn->prepare($options_query);
            $options_stmt->bindParam(':quiz_id', $quiz['id']);
            $options_stmt->execute();
            $options = $options_stmt->fetchAll(PDO::FETCH_ASSOC);
        }
        
        return [
            'success' => true,
            'lesson' => $lesson,
            'quiz' => $quiz,
            'options' => $options
        ];
    } catch (PDOException $e) {
        return [
            'success' => false,
            'message' => 'Database error: ' . $e->getMessage()
        ];
    }
}

// Function to delete lesson
function deleteLesson($conn, $lesson_id) {
    try {
        // Soft delete - set deleted_at timestamp
        $query = "UPDATE lessons SET deleted_at = NOW() WHERE id = :lesson_id";
        $stmt = $conn->prepare($query);
        $stmt->bindParam(':lesson_id', $lesson_id);
        $stmt->execute();
        
        return [
            'success' => true,
            'message' => 'Lesson deleted successfully!'
        ];
    } catch (PDOException $e) {
        return [
            'success' => false,
            'message' => 'Database error: ' . $e->getMessage()
        ];
    }
}

// Function to upload image
function uploadImage() {
    if (!isset($_FILES['image']) || $_FILES['image']['error'] !== UPLOAD_ERR_OK) {
        return ['success' => false, 'message' => 'No file uploaded or upload error'];
    }
    
    $allowed_types = ['image/jpeg', 'image/png', 'image/gif', 'image/svg+xml'];
    $max_size = 5 * 1024 * 1024; // 5MB
    
    $file_type = $_FILES['image']['type'];
    $file_size = $_FILES['image']['size'];
    
    if (!in_array($file_type, $allowed_types)) {
        return ['success' => false, 'message' => 'Invalid file type. Allowed: JPEG, PNG, GIF, SVG'];
    }
    
    if ($file_size > $max_size) {
        return ['success' => false, 'message' => 'File too large. Maximum size: 5MB'];
    }
    
    // Create uploads directory if it doesn't exist
    $upload_dir = 'uploads/lessons/';
    if (!file_exists($upload_dir)) {
        mkdir($upload_dir, 0777, true);
    }
    
    // Generate unique filename
    $file_ext = pathinfo($_FILES['image']['name'], PATHINFO_EXTENSION);
    $filename = uniqid('lesson_', true) . '.' . $file_ext;
    $filepath = $upload_dir . $filename;
    
    if (move_uploaded_file($_FILES['image']['tmp_name'], $filepath)) {
        return [
            'success' => true,
            'url' => $filepath,
            'filename' => $filename
        ];
    }
    
    return ['success' => false, 'message' => 'Failed to upload file'];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Create Lesson - JS Tutorial Admin</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <!-- TinyMCE Editor -->
    <script src="https://cdn.tiny.cloud/1/YOUR_API_KEY/tinymce/6/tinymce.min.js" referrerpolicy="origin"></script>
    <style>
        :root {
            --sidebar-width: 280px;
            --primary-color: #4361ee;
            --secondary-color: #3a0ca3;
            --success-color: #2ecc71;
            --warning-color: #f39c12;
            --danger-color: #e74c3c;
        }
        
        .admin-layout {
            display: flex;
            min-height: 100vh;
            background: #f8f9fa;
        }
        
        .admin-sidebar {
            width: var(--sidebar-width);
            background: linear-gradient(180deg, var(--primary-color), var(--secondary-color));
            color: white;
            position: fixed;
            height: 100vh;
            overflow-y: auto;
            box-shadow: 2px 0 10px rgba(0,0,0,0.1);
            z-index: 100;
        }
        
        .admin-content {
            flex: 1;
            margin-left: var(--sidebar-width);
            padding: 20px;
            max-width: calc(100vw - var(--sidebar-width));
            overflow-x: hidden;
        }
        
        .sidebar-header {
            padding: 20px;
            border-bottom: 1px solid rgba(255,255,255,0.1);
        }
        
        .sidebar-nav {
            padding: 20px 0;
        }
        
        .nav-item {
            margin-bottom: 5px;
        }
        
        .nav-link {
            color: rgba(255,255,255,0.8);
            padding: 12px 20px;
            display: flex;
            align-items: center;
            text-decoration: none;
            transition: all 0.3s;
        }
        
        .nav-link:hover, .nav-link.active {
            color: white;
            background: rgba(255,255,255,0.1);
            border-left: 4px solid #4cc9f0;
        }
        
        .nav-link i {
            width: 24px;
            margin-right: 10px;
            text-align: center;
        }
        
        .content-header {
            background: white;
            padding: 20px;
            border-radius: 10px;
            margin-bottom: 20px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
        }
        
        .content-card {
            background: white;
            padding: 25px;
            border-radius: 10px;
            margin-bottom: 20px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
        }
        
        .form-section {
            margin-bottom: 30px;
            padding-bottom: 20px;
            border-bottom: 1px solid #eee;
        }
        
        .form-section:last-child {
            border-bottom: none;
            margin-bottom: 0;
            padding-bottom: 0;
        }
        
        .editor-toolbar {
            background: #f8f9fa;
            padding: 10px;
            border: 1px solid #dee2e6;
            border-bottom: none;
            border-radius: 5px 5px 0 0;
            display: flex;
            gap: 5px;
            flex-wrap: wrap;
        }
        
        .editor-btn {
            padding: 6px 12px;
            background: white;
            border: 1px solid #dee2e6;
            border-radius: 4px;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 5px;
            transition: all 0.2s;
        }
        
        .editor-btn:hover {
            background: #e9ecef;
            border-color: #ced4da;
        }
        
        .editor-btn.active {
            background: var(--primary-color);
            color: white;
            border-color: var(--primary-color);
        }
        
        .editor-content {
            min-height: 300px;
            border: 1px solid #dee2e6;
            border-radius: 0 0 5px 5px;
            padding: 15px;
            background: white;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            line-height: 1.6;
        }
        
        .quiz-option-item {
            background: #f8f9fa;
            border: 1px solid #dee2e6;
            border-radius: 5px;
            padding: 15px;
            margin-bottom: 10px;
            transition: all 0.2s;
        }
        
        .quiz-option-item:hover {
            border-color: #adb5bd;
            background: #e9ecef;
        }
        
        .quiz-option-item.correct {
            border-color: var(--success-color);
            background: rgba(46, 204, 113, 0.1);
        }
        
        .lesson-preview {
            background: #f8f9fa;
            border: 1px solid #dee2e6;
            border-radius: 5px;
            padding: 20px;
            margin-top: 20px;
            max-height: 400px;
            overflow-y: auto;
        }
        
        .drag-handle {
            cursor: move;
            color: #6c757d;
            padding: 0 10px;
        }
        
        .drag-handle:hover {
            color: var(--primary-color);
        }
        
        .lesson-list-item {
            background: white;
            border: 1px solid #dee2e6;
            border-radius: 5px;
            padding: 12px 15px;
            margin-bottom: 8px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            transition: all 0.2s;
        }
        
        .lesson-list-item:hover {
            border-color: #adb5bd;
            box-shadow: 0 2px 5px rgba(0,0,0,0.05);
        }
        
        .lesson-status-badge {
            font-size: 0.75rem;
            padding: 3px 8px;
        }
        
        .code-snippet {
            background: #2d3748;
            color: #e2e8f0;
            padding: 15px;
            border-radius: 5px;
            font-family: 'Courier New', monospace;
            margin: 10px 0;
            overflow-x: auto;
        }
        
        .image-upload-preview {
            max-width: 200px;
            max-height: 200px;
            border: 2px dashed #dee2e6;
            border-radius: 5px;
            padding: 10px;
            margin: 10px 0;
            display: none;
        }
        
        @media (max-width: 768px) {
            .admin-layout {
                flex-direction: column;
            }
            
            .admin-sidebar {
                width: 100%;
                height: auto;
                position: static;
            }
            
            .admin-content {
                margin-left: 0;
                max-width: 100%;
            }
            
            .sidebar-nav {
                display: flex;
                overflow-x: auto;
                padding: 10px;
            }
            
            .nav-item {
                flex-shrink: 0;
                margin-bottom: 0;
                margin-right: 10px;
            }
            
            .nav-link {
                padding: 8px 12px;
                font-size: 0.9rem;
            }
        }
    </style>
</head>
<body>
    <div class="admin-layout">
        <!-- Sidebar -->
        <div class="admin-sidebar">
            <div class="sidebar-header">
                <h4 class="mb-0">
                    <i class="fab fa-js-square me-2"></i>JS Tutorial Admin
                </h4>
                <small class="text-light opacity-75">Content Management System</small>
            </div>
            
            <div class="sidebar-nav">
                <div class="nav-item">
                    <a href="add-lesson" class="nav-link active">
                        <i class="fas fa-plus-circle"></i>
                        <span>Create Lesson</span>
                    </a>
                </div>
                <div class="nav-item">
                    <a href="manage_lessons" class="nav-link">
                        <i class="fas fa-list"></i>
                        <span>Manage Lessons</span>
                    </a>
                </div>
                <div class="nav-item">
                    <a href="manage_quizzes" class="nav-link">
                        <i class="fas fa-question-circle"></i>
                        <span>Manage Quizzes</span>
                    </a>
                </div>
                <div class="nav-item">
                    <a href="manage_topics" class="nav-link">
                        <i class="fas fa-book"></i>
                        <span>Manage Topics</span>
                    </a>
                </div>
                <div class="nav-item">
                    <a href="analytics" class="nav-link">
                        <i class="fas fa-chart-bar"></i>
                        <span>Reports</span>
                    </a>
                </div>
                <div class="nav-item">
                    <a href="teacher_dashboard" class="nav-link">
                        <i class="fas fa-home"></i>
                        <span>Back to Site</span>
                    </a>
                </div>
            </div>
        </div>
        
        <!-- Main Content -->
        <div class="admin-content">
            <div class="content-header">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <h2 class="mb-1">Create New Lesson</h2>
                        <p class="text-muted mb-0">Add interactive lessons with quizzes for JavaScript tutorial</p>
                    </div>
                    <div class="d-flex gap-2">
                        <button class="btn btn-outline-primary" onclick="previewLesson()">
                            <i class="fas fa-eye me-1"></i> Preview
                        </button>
                        <button class="btn btn-primary" onclick="saveLesson()" id="saveLessonBtn">
                            <i class="fas fa-save me-1"></i> Save Lesson
                        </button>
                    </div>
                </div>
            </div>
            
            <div class="row">
                <!-- Left Column: Lesson Form -->
                <div class="col-lg-8">
                    <div class="content-card">
                        <!-- Topic Selection -->
                        <div class="form-section">
                            <h4 class="mb-3">
                                <i class="fas fa-book text-primary me-2"></i>Topic & Lesson Details
                            </h4>
                            
                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label class="form-label fw-bold">Select Topic *</label>
                                    <select class="form-select" id="topicSelect" onchange="loadTopicLessons()" required>
                                        <option value="">Choose a topic...</option>
                                        <?php foreach($topics as $topic): ?>
                                        <option value="<?php echo $topic['id']; ?>">
                                            <?php echo htmlspecialchars($topic['topic_name']); ?>
                                        </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label class="form-label fw-bold">Lesson Order *</label>
                                    <input type="number" class="form-control" id="lessonOrder" min="1" value="1" required>
                                    <small class="form-text text-muted">Determines the display order within the topic</small>
                                </div>
                            </div>
                            
                            <div class="mb-3">
                                <label class="form-label fw-bold">Lesson Title *</label>
                                <input type="text" class="form-control" id="lessonTitle" placeholder="Enter lesson title" required>
                            </div>
                            
                            <div class="mb-3">
                                <label class="form-label fw-bold">Content Type</label>
                                <div class="btn-group w-100" role="group">
                                    <input type="radio" class="btn-check" name="contentType" id="typeTheory" value="theory" checked>
                                    <label class="btn btn-outline-primary" for="typeTheory">
                                        <i class="fas fa-book me-1"></i> Theory
                                    </label>
                                    
                                    <input type="radio" class="btn-check" name="contentType" id="typeSyntax" value="syntax">
                                    <label class="btn btn-outline-primary" for="typeSyntax">
                                        <i class="fas fa-code me-1"></i> Syntax
                                    </label>
                                    
                                    <input type="radio" class="btn-check" name="contentType" id="typeExample" value="example">
                                    <label class="btn btn-outline-primary" for="typeExample">
                                        <i class="fas fa-laptop-code me-1"></i> Example
                                    </label>
                                    
                                    <input type="radio" class="btn-check" name="contentType" id="typeExercise" value="exercise">
                                    <label class="btn btn-outline-primary" for="typeExercise">
                                        <i class="fas fa-dumbbell me-1"></i> Exercise
                                    </label>
                                </div>
                            </div>
                            
                            <div class="form-check form-switch mb-3">
                                <input class="form-check-input" type="checkbox" id="lessonActive" checked>
                                <label class="form-check-label fw-bold" for="lessonActive">Active Lesson</label>
                                <small class="form-text text-muted d-block">Inactive lessons won't be visible to students</small>
                            </div>
                        </div>
                        
                        <!-- Lesson Content Editor -->
                        <div class="form-section">
                            <h4 class="mb-3">
                                <i class="fas fa-edit text-primary me-2"></i>Lesson Content
                            </h4>
                            
                            <!-- Editor Toolbar -->
                            <div class="editor-toolbar">
                                <button type="button" class="editor-btn" onclick="formatText('bold')">
                                    <i class="fas fa-bold"></i>
                                </button>
                                <button type="button" class="editor-btn" onclick="formatText('italic')">
                                    <i class="fas fa-italic"></i>
                                </button>
                                <button type="button" class="editor-btn" onclick="formatText('underline')">
                                    <i class="fas fa-underline"></i>
                                </button>
                                <div class="vr"></div>
                                <button type="button" class="editor-btn" onclick="insertHeading()">
                                    <i class="fas fa-heading"></i> H2
                                </button>
                                <button type="button" class="editor-btn" onclick="insertSubheading()">
                                    <i class="fas fa-heading fa-sm"></i> H3
                                </button>
                                <div class="vr"></div>
                                <button type="button" class="editor-btn" onclick="insertCodeBlock()">
                                    <i class="fas fa-code"></i> Code
                                </button>
                                <button type="button" class="editor-btn" onclick="insertLink()">
                                    <i class="fas fa-link"></i> Link
                                </button>
                                <button type="button" class="editor-btn" onclick="insertList()">
                                    <i class="fas fa-list"></i> List
                                </button>
                                <div class="vr"></div>
                                <button type="button" class="editor-btn" onclick="uploadImage()">
                                    <i class="fas fa-image"></i> Image
                                </button>
                                <button type="button" class="editor-btn" onclick="insertExample()">
                                    <i class="fas fa-laptop-code"></i> Example
                                </button>
                                <button type="button" class="editor-btn" onclick="clearFormatting()">
                                    <i class="fas fa-eraser"></i> Clear
                                </button>
                            </div>
                            
                            <!-- Content Editor -->
                            <div class="editor-content" contenteditable="true" id="lessonContent" 
                                 oninput="updateContent()" onpaste="handlePaste(event)">
                                <p>Start writing your lesson content here...</p>
                                <p>You can add:</p>
                                <ul>
                                    <li>Explanations and theory</li>
                                    <li>Code examples</li>
                                    <li>Images and diagrams</li>
                                    <li>Interactive examples</li>
                                </ul>
                            </div>
                            
                            <!-- Hidden textarea for form submission -->
                            <textarea class="d-none" id="lessonContentText"></textarea>
                            
                            <!-- Image Upload Modal -->
                            <div class="modal fade" id="imageUploadModal">
                                <div class="modal-dialog">
                                    <div class="modal-content">
                                        <div class="modal-header">
                                            <h5 class="modal-title">Upload Image</h5>
                                            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                        </div>
                                        <div class="modal-body">
                                            <div class="mb-3">
                                                <label class="form-label">Choose Image</label>
                                                <input type="file" class="form-control" id="imageFile" accept="image/*">
                                            </div>
                                            <div class="mb-3">
                                                <label class="form-label">Alt Text</label>
                                                <input type="text" class="form-control" id="imageAlt" placeholder="Description of image">
                                            </div>
                                            <div class="text-center">
                                                <img id="imagePreview" class="image-upload-preview img-fluid">
                                            </div>
                                        </div>
                                        <div class="modal-footer">
                                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                                            <button type="button" class="btn btn-primary" onclick="insertImage()">Insert</button>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Quiz Section -->
                        <div class="form-section">
                            <h4 class="mb-3">
                                <i class="fas fa-question-circle text-primary me-2"></i>Quiz Questions
                                <span class="badge bg-secondary ms-2">Optional</span>
                            </h4>
                            
                            <div class="mb-3">
                                <label class="form-label fw-bold">Quiz Question</label>
                                <textarea class="form-control" id="quizQuestion" rows="3" 
                                          placeholder="Enter quiz question here..."></textarea>
                            </div>
                            
                            <div class="mb-3">
                                <label class="form-label fw-bold">Difficulty Level</label>
                                <div class="btn-group w-100" role="group">
                                    <input type="radio" class="btn-check" name="difficulty" id="diffEasy" value="easy" checked>
                                    <label class="btn btn-outline-success" for="diffEasy">
                                        <i class="fas fa-smile"></i> Easy
                                    </label>
                                    
                                    <input type="radio" class="btn-check" name="difficulty" id="diffMedium" value="medium">
                                    <label class="btn btn-outline-warning" for="diffMedium">
                                        <i class="fas fa-meh"></i> Medium
                                    </label>
                                    
                                    <input type="radio" class="btn-check" name="difficulty" id="diffHard" value="hard">
                                    <label class="btn btn-outline-danger" for="diffHard">
                                        <i class="fas fa-frown"></i> Hard
                                    </label>
                                </div>
                            </div>
                            
                            <div class="mb-3">
                                <label class="form-label fw-bold">Explanation (shown after answer)</label>
                                <textarea class="form-control" id="quizExplanation" rows="2" 
                                          placeholder="Explain why this answer is correct..."></textarea>
                            </div>
                            
                            <div class="mb-4">
                                <label class="form-label fw-bold">Answer Options *</label>
                                <small class="form-text text-muted d-block mb-2">Drag to reorder options. Select the correct answer.</small>
                                
                                <div id="quizOptionsContainer">
                                    <!-- Options will be added here -->
                                </div>
                                
                                <button type="button" class="btn btn-outline-primary w-100 mt-2" onclick="addQuizOption()">
                                    <i class="fas fa-plus me-1"></i> Add Answer Option
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Right Column: Preview & Lesson List -->
                <div class="col-lg-4">
                    <!-- Lesson List -->
                    <div class="content-card mb-4">
                        <h4 class="mb-3">
                            <i class="fas fa-list text-primary me-2"></i>Topic Lessons
                        </h4>
                        
                        <div id="lessonsList">
                            <div class="text-center py-4">
                                <i class="fas fa-book-open fa-2x text-muted mb-3"></i>
                                <p class="text-muted">Select a topic to view its lessons</p>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Quick Actions -->
                    <div class="content-card mb-4">
                        <h4 class="mb-3">
                            <i class="fas fa-bolt text-warning me-2"></i>Quick Actions
                        </h4>
                        
                        <div class="d-grid gap-2">
                            <button class="btn btn-outline-primary" onclick="loadSampleLesson()">
                                <i class="fas fa-magic me-1"></i> Load Sample Lesson
                            </button>
                            <button class="btn btn-outline-success" onclick="duplicateLesson()">
                                <i class="fas fa-copy me-1"></i> Duplicate Current
                            </button>
                            <button class="btn btn-outline-danger" onclick="clearForm()">
                                <i class="fas fa-trash me-1"></i> Clear Form
                            </button>
                        </div>
                    </div>
                    
                    <!-- Lesson Preview -->
                    <div class="content-card">
                        <h4 class="mb-3">
                            <i class="fas fa-eye text-info me-2"></i>Quick Preview
                        </h4>
                        
                        <div class="lesson-preview" id="lessonPreview">
                            <div class="text-center py-4">
                                <i class="fas fa-file-alt fa-2x text-muted mb-3"></i>
                                <p class="text-muted">Content preview will appear here</p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Toast Container -->
    <div class="toast-container position-fixed top-0 end-0 p-3" style="z-index: 9999;"></div>
    
    <!-- Scripts -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://code.jquery.com/jquery-3.6.4.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sortablejs@1.14.0/Sortable.min.js"></script>
    <script>
        // Initialize variables
        let currentLessonId = null;
        let currentQuizId = null;
        let quizOptionCount = 0;
        
        // Initialize Sortable for quiz options
        let sortableOptions = null;
        
        // Initialize when page loads
        document.addEventListener('DOMContentLoaded', function() {
            // Initialize quiz options container
            addQuizOption();
            addQuizOption();
            
            // Make quiz options sortable
            setTimeout(() => {
                sortableOptions = new Sortable(document.getElementById('quizOptionsContainer'), {
                    animation: 150,
                    handle: '.drag-handle',
                    ghostClass: 'bg-light'
                });
            }, 100);
            
            // Load saved data from localStorage if exists
            loadDraft();
        });
        
        // Update content for form submission
        function updateContent() {
            const content = document.getElementById('lessonContent').innerHTML;
            document.getElementById('lessonContentText').value = content;
            
            // Update preview
            updatePreview();
        }
        
        // Update preview
        function updatePreview() {
            const content = document.getElementById('lessonContent').innerHTML;
            document.getElementById('lessonPreview').innerHTML = content;
        }
        
        // Format text in editor
        function formatText(command) {
            document.execCommand(command, false, null);
            updateContent();
        }
        
        // Insert heading
        function insertHeading() {
            const selection = window.getSelection();
            if (selection.rangeCount > 0) {
                const range = selection.getRangeAt(0);
                const heading = document.createElement('h3');
                heading.className = 'fw-bold mb-3';
                heading.textContent = 'Section Heading';
                range.insertNode(heading);
                updateContent();
            }
        }
        
        // Insert subheading
        function insertSubheading() {
            const selection = window.getSelection();
            if (selection.rangeCount > 0) {
                const range = selection.getRangeAt(0);
                const subheading = document.createElement('h4');
                subheading.className = 'fw-bold mb-2';
                subheading.textContent = 'Subheading';
                range.insertNode(subheading);
                updateContent();
            }
        }
        
        // Insert code block
function insertCodeBlock() {
    const codeBlock = `
        <div class="code-snippet">
            <div class="code-snippet-header">
                <small>JavaScript Code</small>
                <button class="btn btn-sm btn-outline-light copy-code-btn">
                    <i class="fas fa-copy"></i> Copy
                </button>
            </div>
            <pre><code>// Write your code here
console.log('Hello, World!');</code></pre>
        </div>
    `;
    
    insertHTML(codeBlock);
}
        
        // Insert example
        function insertExample() {
            const example = `
                <div class="alert alert-info">
                    <div class="d-flex align-items-start">
                        <i class="fas fa-lightbulb me-3 mt-1 fa-lg"></i>
                        <div>
                            <h5 class="alert-heading">Example</h5>
                            <p class="mb-0">This is an example section. Explain concepts with practical examples.</p>
                        </div>
                    </div>
                </div>
            `;
            
            insertHTML(example);
        }
        
        // Insert link
        function insertLink() {
            const url = prompt('Enter URL:', 'https://');
            if (url) {
                const text = prompt('Enter link text:', 'Learn More');
                const link = `<a href="${url}" target="_blank" class="text-primary">${text}</a>`;
                document.execCommand('insertHTML', false, link);
                updateContent();
            }
        }
        
        // Insert list
        function insertList() {
            const list = `
                <ul>
                    <li>List item 1</li>
                    <li>List item 2</li>
                    <li>List item 3</li>
                </ul>
            `;
            
            insertHTML(list);
        }
        
        // Clear formatting
        function clearFormatting() {
            document.execCommand('removeFormat', false, null);
            updateContent();
        }
        
        // Upload image
        function uploadImage() {
            const modal = new bootstrap.Modal(document.getElementById('imageUploadModal'));
            modal.show();
            
            // Preview image
            document.getElementById('imageFile').addEventListener('change', function(e) {
                const file = e.target.files[0];
                if (file) {
                    const reader = new FileReader();
                    reader.onload = function(e) {
                        const preview = document.getElementById('imagePreview');
                        preview.src = e.target.result;
                        preview.style.display = 'block';
                    };
                    reader.readAsDataURL(file);
                }
            });
        }
        
        // Insert image
        function insertImage() {
            const fileInput = document.getElementById('imageFile');
            const altText = document.getElementById('imageAlt').value || 'Image';
            
            if (fileInput.files.length === 0) {
                alert('Please select an image first');
                return;
            }
            
            const formData = new FormData();
            formData.append('action', 'upload_image');
            formData.append('image', fileInput.files[0]);
            
            fetch('add-lesson.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    const img = `<img src="${data.url}" alt="${altText}" class="img-fluid rounded mb-3" style="max-width: 100%;">`;
                    insertHTML(img);
                    
                    // Reset modal
                    document.getElementById('imageFile').value = '';
                    document.getElementById('imageAlt').value = '';
                    document.getElementById('imagePreview').style.display = 'none';
                    
                    const modal = bootstrap.Modal.getInstance(document.getElementById('imageUploadModal'));
                    modal.hide();
                } else {
                    alert('Error: ' + data.message);
                }
            });
        }
        
        // Insert HTML content
        function insertHTML(html) {
            const editor = document.getElementById('lessonContent');
            const selection = window.getSelection();
            
            if (selection.rangeCount > 0) {
                const range = selection.getRangeAt(0);
                range.deleteContents();
                
                const tempDiv = document.createElement('div');
                tempDiv.innerHTML = html;
                const fragment = document.createDocumentFragment();
                
                while (tempDiv.firstChild) {
                    fragment.appendChild(tempDiv.firstChild);
                }
                
                range.insertNode(fragment);
                updateContent();
            }
        }
        
        // Handle paste event
        function handlePaste(event) {
            event.preventDefault();
            
            // Get pasted text
            const text = (event.clipboardData || window.clipboardData).getData('text');
            
            // Insert as plain text
            document.execCommand('insertText', false, text);
            updateContent();
        }
        
        // Add quiz option
        function addQuizOption(text = '', isCorrect = false) {
            quizOptionCount++;
            const optionId = `option${quizOptionCount}`;
            
            const optionHtml = `
                <div class="quiz-option-item ${isCorrect ? 'correct' : ''}" id="${optionId}">
                    <div class="d-flex align-items-start">
                        <div class="drag-handle">
                            <i class="fas fa-grip-vertical"></i>
                        </div>
                        <div class="flex-grow-1">
                            <div class="input-group mb-2">
                                <span class="input-group-text">
                                    <input class="form-check-input mt-0" type="radio" 
                                           name="correctOption" ${isCorrect ? 'checked' : ''}
                                           onchange="markCorrect('${optionId}')">
                                </span>
                                <input type="text" class="form-control option-text" 
                                       placeholder="Enter answer option" value="${text}"
                                       oninput="updateOptionText('${optionId}', this.value)">
                            </div>
                        </div>
                        <div class="ms-2">
                            <button type="button" class="btn btn-sm btn-outline-danger" 
                                    onclick="removeQuizOption('${optionId}')">
                                <i class="fas fa-times"></i>
                            </button>
                        </div>
                    </div>
                </div>
            `;
            
            document.getElementById('quizOptionsContainer').insertAdjacentHTML('beforeend', optionHtml);
        }
        
        // Mark option as correct
        function markCorrect(optionId) {
            // Unmark all options
            document.querySelectorAll('.quiz-option-item').forEach(item => {
                item.classList.remove('correct');
            });
            
            // Mark selected option
            const option = document.getElementById(optionId);
            option.classList.add('correct');
        }
        
        // Update option text
        function updateOptionText(optionId, text) {
            // Text is updated automatically via input event
        }
        
        // Remove quiz option
        function removeQuizOption(optionId) {
            const option = document.getElementById(optionId);
            if (option && document.querySelectorAll('.quiz-option-item').length > 1) {
                option.remove();
            } else {
                showToast('You need at least one answer option', 'warning');
            }
        }
        
        // Load topic lessons
        function loadTopicLessons() {
            const topicId = document.getElementById('topicSelect').value;
            
            if (!topicId) {
                document.getElementById('lessonsList').innerHTML = `
                    <div class="text-center py-4">
                        <i class="fas fa-book-open fa-2x text-muted mb-3"></i>
                        <p class="text-muted">Select a topic to view its lessons</p>
                    </div>
                `;
                return;
            }
            
            fetch('add-lesson.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: `action=get_topic_lessons&topic_id=${topicId}`
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    let html = '';
                    
                    if (data.lessons.length > 0) {
                        data.lessons.forEach(lesson => {
                            html += `
                                <div class="lesson-list-item">
                                    <div>
                                        <div class="fw-bold">${lesson.lesson_title}</div>
                                        <div class="text-muted small">Order: ${lesson.lesson_order}</div>
                                    </div>
                                    <div class="d-flex gap-1">
                                        <button class="btn btn-sm btn-outline-primary" 
                                                onclick="editLesson(${lesson.id})">
                                            <i class="fas fa-edit"></i>
                                        </button>
                                        <button class="btn btn-sm btn-outline-danger" 
                                                onclick="deleteLesson(${lesson.id})">
                                            <i class="fas fa-trash"></i>
                                        </button>
                                    </div>
                                </div>
                            `;
                        });
                    } else {
                        html = `
                            <div class="text-center py-4">
                                <i class="fas fa-book fa-2x text-muted mb-3"></i>
                                <p class="text-muted">No lessons found for this topic</p>
                                <small class="text-muted">Create the first lesson!</small>
                            </div>
                        `;
                    }
                    
                    document.getElementById('lessonsList').innerHTML = html;
                } else {
                    showToast('Error loading lessons: ' + data.message, 'danger');
                }
            });
        }
        
        // Edit lesson
        function editLesson(lessonId) {
            fetch('add-lesson.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: `action=get_lesson_data&lesson_id=${lessonId}`
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    currentLessonId = lessonId;
                    
                    // Fill lesson form
                    document.getElementById('topicSelect').value = data.lesson.topic_id;
                    document.getElementById('lessonOrder').value = data.lesson.lesson_order;
                    document.getElementById('lessonTitle').value = data.lesson.lesson_title;
                    
                    // Set content type
                    document.querySelector(`input[name="contentType"][value="${data.lesson.content_type}"]`).checked = true;
                    
                    // Set active status
                    document.getElementById('lessonActive').checked = data.lesson.is_active == 1;
                    
                    // Set lesson content
                    document.getElementById('lessonContent').innerHTML = data.lesson.lesson_content;
                    updateContent();
                    
                    // Load quiz data if exists
                    if (data.quiz) {
                        currentQuizId = data.quiz.id;
                        document.getElementById('quizQuestion').value = data.quiz.question;
                        document.getElementById('quizExplanation').value = data.quiz.explanation || '';
                        document.querySelector(`input[name="difficulty"][value="${data.quiz.difficulty}"]`).checked = true;
                        
                        // Clear and add quiz options
                        document.getElementById('quizOptionsContainer').innerHTML = '';
                        data.options.forEach((option, index) => {
                            addQuizOption(option.option_text, option.is_correct == 1);
                        });
                    } else {
                        currentQuizId = null;
                        document.getElementById('quizQuestion').value = '';
                        document.getElementById('quizExplanation').value = '';
                    }
                    
                    showToast('Lesson loaded for editing', 'success');
                    
                    // Load lessons for this topic
                    loadTopicLessons();
                } else {
                    showToast('Error loading lesson: ' + data.message, 'danger');
                }
            });
        }
        
        // Delete lesson
        function deleteLesson(lessonId) {
            if (confirm('Are you sure you want to delete this lesson? This action cannot be undone.')) {
                fetch('add-lesson.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded',
                    },
                    body: `action=delete_lesson&lesson_id=${lessonId}`
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        showToast('Lesson deleted successfully', 'success');
                        loadTopicLessons();
                        
                        // If deleting current lesson, clear form
                        if (currentLessonId == lessonId) {
                            clearForm();
                        }
                    } else {
                        showToast('Error deleting lesson: ' + data.message, 'danger');
                    }
                });
            }
        }
        
        // Save lesson
        function saveLesson() {
            // Validate required fields
            const topicId = document.getElementById('topicSelect').value;
            const lessonTitle = document.getElementById('lessonTitle').value;
            const lessonOrder = document.getElementById('lessonOrder').value;
            
            if (!topicId || !lessonTitle || !lessonOrder) {
                showToast('Please fill all required fields (Topic, Title, Order)', 'warning');
                return;
            }
            
            // Get quiz options
            const options = [];
            let hasCorrectAnswer = false;
            
            document.querySelectorAll('.quiz-option-item').forEach(item => {
                const textInput = item.querySelector('.option-text');
                const isCorrect = item.querySelector('input[type="radio"]').checked;
                
                if (textInput.value.trim()) {
                    options.push({
                        text: textInput.value.trim(),
                        is_correct: isCorrect ? 1 : 0
                    });
                    
                    if (isCorrect) hasCorrectAnswer = true;
                }
            });
            
            // Validate quiz if question is provided
            const quizQuestion = document.getElementById('quizQuestion').value.trim();
            if (quizQuestion) {
                if (options.length < 2) {
                    showToast('Quiz must have at least 2 answer options', 'warning');
                    return;
                }
                
                if (!hasCorrectAnswer) {
                    showToast('Please select the correct answer for the quiz', 'warning');
                    return;
                }
            }
            
            // Prepare data
            const formData = new FormData();
            formData.append('action', 'save_lesson');
            formData.append('lesson_id', currentLessonId || '');
            formData.append('topic_id', topicId);
            formData.append('lesson_title', lessonTitle);
            formData.append('lesson_content', document.getElementById('lessonContentText').value);
            formData.append('lesson_order', lessonOrder);
            formData.append('content_type', document.querySelector('input[name="contentType"]:checked').value);
            formData.append('is_active', document.getElementById('lessonActive').checked ? 1 : 0);
            
            // Show loading state
            const saveBtn = document.getElementById('saveLessonBtn');
            const originalHtml = saveBtn.innerHTML;
            saveBtn.innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i> Saving...';
            saveBtn.disabled = true;
            
            // Save lesson
            fetch('add-lesson.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    currentLessonId = data.lesson_id;
                    
                    // Save quiz if question exists
                    if (quizQuestion) {
                        const quizData = new FormData();
                        quizData.append('action', 'save_quiz');
                        quizData.append('quiz_id', currentQuizId || '');
                        quizData.append('lesson_id', data.lesson_id);
                        quizData.append('question', quizQuestion);
                        quizData.append('explanation', document.getElementById('quizExplanation').value);
                        quizData.append('difficulty', document.querySelector('input[name="difficulty"]:checked').value);
                        quizData.append('options', JSON.stringify(options));
                        
                        return fetch('add-lesson.php', {
                            method: 'POST',
                            body: quizData
                        });
                    }
                    
                    return Promise.resolve({ success: true });
                } else {
                    throw new Error(data.message);
                }
            })
            .then(response => response ? response.json() : { success: true })
            .then(data => {
                if (data.success) {
                    if (data.quiz_id) {
                        currentQuizId = data.quiz_id;
                    }
                    
                    showToast('Lesson saved successfully!', 'success');
                    
                    // Update lessons list
                    loadTopicLessons();
                    
                    // Save draft to localStorage
                    saveDraft();
                } else {
                    throw new Error(data.message);
                }
            })
            .catch(error => {
                showToast('Error saving lesson: ' + error.message, 'danger');
            })
            .finally(() => {
                // Restore button state
                saveBtn.innerHTML = originalHtml;
                saveBtn.disabled = false;
            });
        }
        
        // Preview lesson
        function previewLesson() {
            const content = document.getElementById('lessonContentText').value;
            
            if (!content.trim()) {
                showToast('No content to preview', 'warning');
                return;
            }
            
            // Open preview in new tab
            const previewWindow = window.open('', '_blank');
            previewWindow.document.write(`
                <!DOCTYPE html>
                <html>
                <head>
                    <title>Lesson Preview</title>
                    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
                    <style>
                        body { padding: 20px; font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; }
                        .code-snippet { background: #2d3748; color: #e2e8f0; padding: 15px; border-radius: 5px; margin: 10px 0; }
                    </style>
                </head>
                <body>
                    <div class="container">
                        <h1 class="mb-4">${document.getElementById('lessonTitle').value || 'Lesson Preview'}</h1>
                        <div>${content}</div>
                    </div>
                </body>
                </html>
            `);
            previewWindow.document.close();
        }
        
        // Load sample lesson
        function loadSampleLesson() {
            if (!confirm('Load sample lesson? This will replace current content.')) return;
            
            document.getElementById('lessonTitle').value = 'JavaScript Variables and Data Types';
            
            const sampleContent = `
                <h3 class="fw-bold mb-3">Introduction to Variables</h3>
                
                <p>In JavaScript, variables are containers for storing data values. You can declare variables using three different keywords:</p>
                
                <ul>
                    <li><code>var</code> - Function scoped (older way)</li>
                    <li><code>let</code> - Block scoped (modern way)</li>
                    <li><code>const</code> - Block scoped, cannot be reassigned</li>
                </ul>
                
                <div class="alert alert-info">
                    <div class="d-flex align-items-start">
                        <i class="fas fa-lightbulb me-3 mt-1 fa-lg"></i>
                        <div>
                            <h5 class="alert-heading">Best Practice</h5>
                            <p class="mb-0">Use <code>const</code> by default, <code>let</code> when you need to reassign, and avoid <code>var</code> in modern JavaScript.</p>
                        </div>
                    </div>
                </div>
                
                <h4 class="fw-bold mb-2">Data Types</h4>
                
                <p>JavaScript has several primitive data types:</p>
                
                <div class="code-snippet">
                    <div class="d-flex justify-content-between align-items-center mb-2">
                        <small class="text-muted">JavaScript Data Types</small>
                        <button class="btn btn-sm btn-outline-light" onclick="copyCode(this)">
                            <i class="fas fa-copy"></i> Copy
                        </button>
                    </div>
                    <pre class="mb-0"><code>// String
let name = "John Doe";

// Number
let age = 25;
let price = 99.99;

// Boolean
let isStudent = true;

// Undefined
let notAssigned;

// Null
let emptyValue = null;

// Symbol (ES6)
let sym = Symbol('id');

// BigInt (ES2020)
let bigNumber = 9007199254740991n;</code></pre>
                </div>
                
                <div class="alert alert-warning">
                    <div class="d-flex align-items-start">
                        <i class="fas fa-exclamation-triangle me-3 mt-1 fa-lg"></i>
                        <div>
                            <h5 class="alert-heading">Important Note</h5>
                            <p class="mb-0">JavaScript is dynamically typed, meaning you don't need to specify the data type when declaring a variable.</p>
                        </div>
                    </div>
                </div>
            `;
            
            document.getElementById('lessonContent').innerHTML = sampleContent;
            updateContent();
            
            // Set sample quiz
            document.getElementById('quizQuestion').value = 'Which keyword is used to declare a variable that cannot be reassigned?';
            document.getElementById('quizExplanation').value = 'The const keyword creates a constant reference to a value. The variable identifier cannot be reassigned, but the value itself can be mutable (like objects or arrays).';
            
            // Clear and add sample quiz options
            document.getElementById('quizOptionsContainer').innerHTML = '';
            addQuizOption('var', false);
            addQuizOption('let', false);
            addQuizOption('const', true);
            addQuizOption('static', false);
            
            // Mark the correct answer
            setTimeout(() => {
                markCorrect('option2');
            }, 100);
            
            showToast('Sample lesson loaded', 'success');
        }
        
        // Duplicate current lesson
        function duplicateLesson() {
            if (!currentLessonId) {
                showToast('No lesson to duplicate', 'warning');
                return;
            }
            
            document.getElementById('lessonTitle').value += ' (Copy)';
            document.getElementById('lessonOrder').value = parseInt(document.getElementById('lessonOrder').value) + 1;
            currentLessonId = null;
            currentQuizId = null;
            
            showToast('Lesson duplicated - ready to save as new', 'info');
        }
        
        // Clear form
        function clearForm() {
            if (!confirm('Clear all form data? This cannot be undone.')) return;
            
            document.getElementById('lessonTitle').value = '';
            document.getElementById('lessonOrder').value = 1;
            document.getElementById('lessonContent').innerHTML = '<p>Start writing your lesson content here...</p>';
            document.getElementById('quizQuestion').value = '';
            document.getElementById('quizExplanation').value = '';
            document.getElementById('quizOptionsContainer').innerHTML = '';
            
            currentLessonId = null;
            currentQuizId = null;
            
            // Add default options
            addQuizOption();
            addQuizOption();
            
            updateContent();
            showToast('Form cleared', 'info');
        }
        
        // Copy code function for preview
        function copyCode(button) {
            const codeElement = button.closest('.code-snippet').querySelector('code');
            const text = codeElement.textContent;
            
            navigator.clipboard.writeText(text).then(() => {
                const originalText = button.innerHTML;
                button.innerHTML = '<i class="fas fa-check"></i> Copied!';
                button.classList.add('btn-success');
                
                setTimeout(() => {
                    button.innerHTML = originalText;
                    button.classList.remove('btn-success');
                }, 2000);
            });
        }
        
        // Save draft to localStorage
        function saveDraft() {
            const draft = {
                topic_id: document.getElementById('topicSelect').value,
                lesson_title: document.getElementById('lessonTitle').value,
                lesson_content: document.getElementById('lessonContentText').value,
                lesson_order: document.getElementById('lessonOrder').value,
                content_type: document.querySelector('input[name="contentType"]:checked').value,
                is_active: document.getElementById('lessonActive').checked,
                quiz_question: document.getElementById('quizQuestion').value,
                quiz_explanation: document.getElementById('quizExplanation').value,
                difficulty: document.querySelector('input[name="difficulty"]:checked').value,
                timestamp: new Date().getTime()
            };
            
            localStorage.setItem('lesson_draft', JSON.stringify(draft));
        }
        
        // Load draft from localStorage
        function loadDraft() {
            const draft = localStorage.getItem('lesson_draft');
            
            if (draft) {
                if (confirm('You have a saved draft. Would you like to load it?')) {
                    const data = JSON.parse(draft);
                    
                    document.getElementById('topicSelect').value = data.topic_id || '';
                    document.getElementById('lessonTitle').value = data.lesson_title || '';
                    document.getElementById('lessonContent').innerHTML = data.lesson_content || '';
                    document.getElementById('lessonOrder').value = data.lesson_order || 1;
                    
                    if (data.content_type) {
                        document.querySelector(`input[name="contentType"][value="${data.content_type}"]`).checked = true;
                    }
                    
                    document.getElementById('lessonActive').checked = data.is_active || false;
                    document.getElementById('quizQuestion').value = data.quiz_question || '';
                    document.getElementById('quizExplanation').value = data.quiz_explanation || '';
                    
                    if (data.difficulty) {
                        document.querySelector(`input[name="difficulty"][value="${data.difficulty}"]`).checked = true;
                    }
                    
                    updateContent();
                    showToast('Draft loaded', 'success');
                }
            }
        }
        
        // Clear draft
        function clearDraft() {
            localStorage.removeItem('lesson_draft');
        }
        
        // Toast notification
        function showToast(message, type = 'info') {
            const toastContainer = document.querySelector('.toast-container');
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
            
            toastContainer.insertAdjacentHTML('beforeend', toastHtml);
            const toast = new bootstrap.Toast(document.getElementById(toastId));
            toast.show();
            
            // Remove toast after it hides
            document.getElementById(toastId).addEventListener('hidden.bs.toast', function () {
                this.remove();
            });
        }
        
        // Auto-save draft every 30 seconds
        setInterval(() => {
            if (document.getElementById('lessonTitle').value || document.getElementById('lessonContentText').value) {
                saveDraft();
            }
        }, 30000);
        
        // Warn before leaving if there's unsaved content
        window.addEventListener('beforeunload', function (e) {
            if (document.getElementById('lessonTitle').value || document.getElementById('lessonContentText').value) {
                e.preventDefault();
                e.returnValue = '';
            }
        });

        // Global copy function for code snippets
window.copyCode = function(button) {
    const codeSnippet = button.closest('.code-snippet');
    const codeElement = codeSnippet.querySelector('code');
    const text = codeElement.textContent;
    
    navigator.clipboard.writeText(text).then(() => {
        const originalText = button.innerHTML;
        button.innerHTML = '<i class="fas fa-check"></i> Copied!';
        button.classList.add('btn-success');
        
        setTimeout(() => {
            button.innerHTML = originalText;
            button.classList.remove('btn-success');
        }, 2000);
    });
};

// Initialize copy buttons after content is loaded
function initCopyButtons() {
    document.querySelectorAll('.copy-code-btn').forEach(btn => {
        btn.onclick = function() {
            copyCode(this);
        };
    });
}

// Call this after inserting content or loading
setTimeout(initCopyButtons, 100);
    </script>
</body>
</html>