<?php
require_once 'database.php';

$db = new Database();
$conn = $db->getConnection();
$teacherSession = new TeacherSession($conn);

if (!$teacherSession->isLoggedIn()) {
    header('Location: teacher_login.php');
    exit;
}

// Handle AJAX actions
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
            case 'get_lesson':
                $response = getLesson($conn, $_POST['lesson_id']);
                break;
        }
        
        header('Content-Type: application/json');
        echo json_encode($response);
        exit;
    }
}

// Fetch topics for dropdown
$topics = $conn->query("SELECT * FROM topics WHERE deleted_at IS NULL ORDER BY topic_order")->fetchAll(PDO::FETCH_ASSOC);

function saveLesson($conn, $data) {
    try {
        // Clean and prepare content
        $lesson_content = trim($data['lesson_content']);
        
        if (isset($data['lesson_id']) && $data['lesson_id']) {
            // Update existing lesson
            $query = "UPDATE lessons SET 
                     topic_id = :topic_id,
                     lesson_title = :title,
                     lesson_content = :content,
                     lesson_order = :order,
                     content_type = :type,
                     is_active = :active,
                     updated_at = NOW()
                     WHERE id = :id";
            
            $stmt = $conn->prepare($query);
            $stmt->bindParam(':id', $data['lesson_id']);
        } else {
            // Insert new lesson
            $query = "INSERT INTO lessons (topic_id, lesson_title, lesson_content, lesson_order, content_type, is_active) 
                     VALUES (:topic_id, :title, :content, :order, :type, :active)";
            
            $stmt = $conn->prepare($query);
        }
        
        $stmt->bindParam(':topic_id', $data['topic_id']);
        $stmt->bindParam(':title', $data['lesson_title']);
        $stmt->bindParam(':content', $lesson_content);
        $stmt->bindParam(':order', $data['lesson_order']);
        $stmt->bindParam(':type', $data['content_type']);
        $stmt->bindParam(':active', $data['is_active']);
        $stmt->execute();
        
        $lesson_id = isset($data['lesson_id']) ? $data['lesson_id'] : $conn->lastInsertId();
        
        return [
            'success' => true,
            'message' => 'Lesson saved successfully',
            'lesson_id' => $lesson_id
        ];
    } catch (PDOException $e) {
        return [
            'success' => false,
            'message' => 'Database error: ' . $e->getMessage()
        ];
    }
}

function saveQuiz($conn, $data) {
    try {
        $conn->beginTransaction();
        
        // Save quiz
        if (isset($data['quiz_id']) && $data['quiz_id']) {
            $quiz_query = "UPDATE quizzes SET 
                          lesson_id = :lesson_id,
                          question = :question,
                          explanation = :explanation,
                          difficulty = :difficulty
                          WHERE id = :id";
            
            $quiz_stmt = $conn->prepare($quiz_query);
            $quiz_stmt->bindParam(':id', $data['quiz_id']);
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
        
        $quiz_id = isset($data['quiz_id']) ? $data['quiz_id'] : $conn->lastInsertId();
        
        // Delete old options
        $delete_query = "DELETE FROM quiz_options WHERE quiz_id = ?";
        $delete_stmt = $conn->prepare($delete_query);
        $delete_stmt->execute([$quiz_id]);
        
        // Insert new options
        $options = json_decode($data['options'], true);
        $option_order = 0;
        
        foreach ($options as $option) {
            $option_query = "INSERT INTO quiz_options (quiz_id, option_text, is_correct, option_order) 
                            VALUES (?, ?, ?, ?)";
            $option_stmt = $conn->prepare($option_query);
            $option_stmt->execute([
                $quiz_id,
                $option['text'],
                $option['is_correct'],
                $option_order++
            ]);
        }
        
        $conn->commit();
        
        return [
            'success' => true,
            'message' => 'Quiz saved successfully',
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

function getLesson($conn, $lesson_id) {
    try {
        $query = "SELECT * FROM lessons WHERE id = ? AND deleted_at IS NULL";
        $stmt = $conn->prepare($query);
        $stmt->execute([$lesson_id]);
        $lesson = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$lesson) {
            return ['success' => false, 'message' => 'Lesson not found'];
        }
        
        // Get quiz data
        $quiz_query = "SELECT q.*, 
                      GROUP_CONCAT(
                        JSON_OBJECT(
                          'id', qo.id,
                          'text', qo.option_text,
                          'is_correct', qo.is_correct
                        )
                      ) as options_json
                      FROM quizzes q
                      LEFT JOIN quiz_options qo ON q.id = qo.quiz_id
                      WHERE q.lesson_id = ? AND q.deleted_at IS NULL
                      GROUP BY q.id";
        
        $quiz_stmt = $conn->prepare($quiz_query);
        $quiz_stmt->execute([$lesson_id]);
        $quiz = $quiz_stmt->fetch(PDO::FETCH_ASSOC);
        
        $lesson['quiz'] = $quiz;
        
        return ['success' => true, 'lesson' => $lesson];
    } catch (PDOException $e) {
        return ['success' => false, 'message' => 'Database error: ' . $e->getMessage()];
    }
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
    <!-- TinyMCE CSS -->
    <style>
        .editor-toolbar {
            background: #f8f9fa;
            border: 1px solid #dee2e6;
            border-bottom: none;
            padding: 10px;
            border-radius: 5px 5px 0 0;
            display: flex;
            gap: 5px;
            flex-wrap: wrap;
            align-items: center;
        }
        
        .editor-btn {
            background: white;
            border: 1px solid #dee2e6;
            padding: 6px 12px;
            border-radius: 4px;
            cursor: pointer;
            display: flex;
            align-items: center;
            gap: 5px;
            font-size: 14px;
            transition: all 0.2s;
        }
        
        .editor-btn:hover {
            background: #e9ecef;
            border-color: #adb5bd;
        }
        
        .code-snippet {
            background: #1e1e1e;
            color: #d4d4d4;
            border-radius: 6px;
            padding: 15px;
            margin: 15px 0;
            font-family: 'Consolas', 'Monaco', 'Courier New', monospace;
        }
        
        .code-snippet pre {
            margin: 0;
            padding: 0;
            background: transparent;
            border: none;
        }
        
        .code-snippet code {
            font-family: 'Consolas', 'Monaco', 'Courier New', monospace;
            font-size: 14px;
            line-height: 1.5;
            color: #9cdcfe;
        }
        
        .code-snippet-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 10px;
            padding-bottom: 5px;
            border-bottom: 1px solid #555;
        }
        
        .example-box {
            background: #e7f1ff;
            border-left: 4px solid #4361ee;
            padding: 15px;
            border-radius: 4px;
            margin: 15px 0;
        }
        
        .example-box-header {
            display: flex;
            align-items: center;
            gap: 10px;
            margin-bottom: 10px;
            color: #4361ee;
            font-weight: 600;
        }
        
        .tip-box {
            background: #fff3cd;
            border-left: 4px solid #ffc107;
            padding: 15px;
            border-radius: 4px;
            margin: 15px 0;
        }
        
        .tip-box-header {
            display: flex;
            align-items: center;
            gap: 10px;
            margin-bottom: 10px;
            color: #856404;
            font-weight: 600;
        }
        
        /* TinyMCE Custom Styles */
        .tox-tinymce {
            border: 1px solid #dee2e6 !important;
            border-radius: 0 0 5px 5px !important;
        }
        
        .form-section {
            background: white;
            border: 1px solid #dee2e6;
            border-radius: 8px;
            padding: 20px;
            margin-bottom: 20px;
        }
        
        .form-section h4 {
            color: #4361ee;
            border-bottom: 2px solid #f0f2f5;
            padding-bottom: 10px;
        }
        
        .quiz-option-item {
            background: #f8f9fa;
            border: 1px solid #e9ecef;
            border-radius: 6px;
            padding: 15px;
            margin-bottom: 10px;
        }
        
        .option-actions {
            display: flex;
            gap: 5px;
            margin-top: 10px;
        }
        
        .image-upload-preview {
            max-width: 200px;
            max-height: 200px;
            display: none;
            border: 2px dashed #dee2e6;
            border-radius: 4px;
            padding: 10px;
        }
    </style>
</head>
<body>
    <div class="container-fluid py-4">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <div>
                <h2><i class="fas fa-book me-2 text-primary"></i><?php echo isset($_GET['edit']) ? 'Edit' : 'Create'; ?> Lesson</h2>
                <p class="text-muted">Create interactive JavaScript lessons with quizzes</p>
            </div>
            <div>
                <button class="btn btn-outline-secondary me-2" onclick="window.history.back()">
                    <i class="fas fa-arrow-left me-1"></i> Back
                </button>
                <button class="btn btn-primary" id="saveLessonBtn" onclick="saveLesson()">
                    <i class="fas fa-save me-1"></i> Save Lesson
                </button>
            </div>
        </div>
        
        <div class="row">
            <!-- Left Column: Lesson Form -->
            <div class="col-md-8">
                <!-- Basic Information -->
                <div class="form-section">
                    <h4 class="mb-3">
                        <i class="fas fa-info-circle text-primary me-2"></i>Basic Information
                    </h4>
                    
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Topic *</label>
                            <select class="form-select" id="topicSelect" required>
                                <option value="">Select a topic</option>
                                <?php foreach ($topics as $topic): ?>
                                <option value="<?php echo $topic['id']; ?>">
                                    <?php echo htmlspecialchars($topic['topic_name']); ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Lesson Order *</label>
                            <input type="number" class="form-control" id="lessonOrder" min="1" required>
                            <small class="text-muted">Determines position within the topic</small>
                        </div>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Lesson Title *</label>
                        <input type="text" class="form-control" id="lessonTitle" placeholder="Enter lesson title" required>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Content Type</label>
                        <div class="form-check form-check-inline">
                            <input class="form-check-input" type="radio" name="contentType" id="theory" value="theory" checked>
                            <label class="form-check-label" for="theory">Theory</label>
                        </div>
                        <div class="form-check form-check-inline">
                            <input class="form-check-input" type="radio" name="contentType" id="syntax" value="syntax">
                            <label class="form-check-label" for="syntax">Syntax</label>
                        </div>
                        <div class="form-check form-check-inline">
                            <input class="form-check-input" type="radio" name="contentType" id="example" value="example">
                            <label class="form-check-label" for="example">Example</label>
                        </div>
                        <div class="form-check form-check-inline">
                            <input class="form-check-input" type="radio" name="contentType" id="exercise" value="exercise">
                            <label class="form-check-label" for="exercise">Exercise</label>
                        </div>
                    </div>
                    
                    <div class="form-check form-switch">
                        <input class="form-check-input" type="checkbox" id="lessonActive" checked>
                        <label class="form-check-label" for="lessonActive">Active Lesson</label>
                        <small class="text-muted d-block">Inactive lessons won't be visible to students</small>
                    </div>
                </div>
                
                <!-- Lesson Content Editor with TinyMCE -->
                <div class="form-section">
                    <h4 class="mb-3">
                        <i class="fas fa-edit text-primary me-2"></i>Lesson Content
                    </h4>
                    
                    <!-- TinyMCE Editor -->
                    <textarea id="lessonContent" style="min-height: 500px;">
                        <h2>Welcome to Your Lesson!</h2>
                        <p>Start writing your amazing JavaScript lesson here. You can:</p>
                        
                        <div class="example-box">
                            <div class="example-box-header">
                                <i class="fas fa-lightbulb"></i>
                                <span>Example: Add Interactive Examples</span>
                            </div>
                            <p>Use the example box to highlight important concepts.</p>
                        </div>
                        
                        <div class="code-snippet">
                            <div class="code-snippet-header">
                                <small>JavaScript Code</small>
                                <button class="btn btn-sm btn-outline-light" onclick="copyCode(this)">
                                    <i class="fas fa-copy"></i> Copy
                                </button>
                            </div>
                            <pre><code>// Your JavaScript code here
console.log('Hello, URUScript!');</code></pre>
                        </div>
                        
                        <div class="tip-box">
                            <div class="tip-box-header">
                                <i class="fas fa-info-circle"></i>
                                <span>Important Tip</span>
                            </div>
                            <p>Use tip boxes to share important notes with students.</p>
                        </div>
                    </textarea>
                    
                    <div class="mt-3">
                        <small class="text-muted">
                            <i class="fas fa-lightbulb me-1"></i>
                            Use the toolbar above to format your content. Special elements (code snippets, examples, tips) will be styled automatically.
                        </small>
                    </div>
                </div>
                
                <!-- Quiz Section -->
                <div class="form-section">
                    <h4 class="mb-3">
                        <i class="fas fa-question-circle text-primary me-2"></i>Quiz
                        <small class="text-muted">(Optional)</small>
                    </h4>
                    
                    <div class="mb-3">
                        <label class="form-label">Question</label>
                        <textarea class="form-control" id="quizQuestion" rows="3" 
                                  placeholder="Enter quiz question..."></textarea>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Explanation</label>
                        <textarea class="form-control" id="quizExplanation" rows="2" 
                                  placeholder="Explanation shown after answering..."></textarea>
                        <small class="text-muted">This helps students learn from their mistakes</small>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Difficulty</label>
                        <div class="form-check form-check-inline">
                            <input class="form-check-input" type="radio" name="difficulty" id="easy" value="easy" checked>
                            <label class="form-check-label" for="easy">Easy</label>
                        </div>
                        <div class="form-check form-check-inline">
                            <input class="form-check-input" type="radio" name="difficulty" id="medium" value="medium">
                            <label class="form-check-label" for="medium">Medium</label>
                        </div>
                        <div class="form-check form-check-inline">
                            <input class="form-check-input" type="radio" name="difficulty" id="hard" value="hard">
                            <label class="form-check-label" for="hard">Hard</label>
                        </div>
                    </div>
                    
                    <!-- Quiz Options -->
                    <div class="mb-3">
                        <label class="form-label">Answer Options *</label>
                        <div id="quizOptionsContainer">
                            <!-- Options will be added here -->
                            <div class="quiz-option-item">
                                <div class="form-check mb-2">
                                    <input class="form-check-input" type="radio" name="correctAnswer" value="0" checked>
                                    <label class="form-check-label">Correct Answer</label>
                                </div>
                                <input type="text" class="form-control option-text" placeholder="Option text...">
                                <div class="option-actions">
                                    <button class="btn btn-sm btn-outline-danger" onclick="removeOption(this)">
                                        <i class="fas fa-trash"></i> Remove
                                    </button>
                                </div>
                            </div>
                            
                            <div class="quiz-option-item">
                                <div class="form-check mb-2">
                                    <input class="form-check-input" type="radio" name="correctAnswer" value="1">
                                    <label class="form-check-label">Correct Answer</label>
                                </div>
                                <input type="text" class="form-control option-text" placeholder="Option text...">
                                <div class="option-actions">
                                    <button class="btn btn-sm btn-outline-danger" onclick="removeOption(this)">
                                        <i class="fas fa-trash"></i> Remove
                                    </button>
                                </div>
                            </div>
                        </div>
                        
                        <button type="button" class="btn btn-outline-primary btn-sm mt-2" onclick="addOption()">
                            <i class="fas fa-plus me-1"></i> Add Option
                        </button>
                        <small class="text-muted d-block mt-1">Minimum 2 options required. Select the correct answer.</small>
                    </div>
                </div>
            </div>
            
            <!-- Right Column: Preview & Tips -->
            <div class="col-md-4">
                <!-- Preview -->
                <div class="form-section">
                    <h4 class="mb-3">
                        <i class="fas fa-eye text-primary me-2"></i>Live Preview
                    </h4>
                    <div id="lessonPreview" class="border rounded p-3" style="min-height: 300px; max-height: 500px; overflow-y: auto;">
                        <p class="text-muted text-center my-5">Content preview will appear here...</p>
                    </div>
                    <div class="mt-3 text-end">
                        <button class="btn btn-sm btn-outline-secondary" onclick="updatePreview()">
                            <i class="fas fa-sync-alt me-1"></i> Refresh Preview
                        </button>
                    </div>
                </div>
                
                <!-- Tips -->
                <div class="form-section">
                    <h4 class="mb-3">
                        <i class="fas fa-lightbulb text-warning me-2"></i>Tips for Great Lessons
                    </h4>
                    <ul class="list-unstyled">
                        <li class="mb-3">
                            <i class="fas fa-check-circle text-success me-2"></i>
                            <small>Start with clear learning objectives</small>
                        </li>
                        <li class="mb-3">
                            <i class="fas fa-check-circle text-success me-2"></i>
                            <small>Use examples relevant to real-world scenarios</small>
                        </li>
                        <li class="mb-3">
                            <i class="fas fa-check-circle text-success me-2"></i>
                            <small>Include interactive code snippets</small>
                        </li>
                        <li class="mb-3">
                            <i class="fas fa-check-circle text-success me-2"></i>
                            <small>Add images/diagrams for complex concepts</small>
                        </li>
                        <li class="mb-3">
                            <i class="fas fa-check-circle text-success me-2"></i>
                            <small>End with a quiz to reinforce learning</small>
                        </li>
                        <li>
                            <i class="fas fa-check-circle text-success me-2"></i>
                            <small>Keep paragraphs short and focused</small>
                        </li>
                    </ul>
                </div>
                
                <!-- Quick Stats -->
                <div class="form-section">
                    <h4 class="mb-3">
                        <i class="fas fa-chart-bar text-info me-2"></i>Lesson Stats
                    </h4>
                    <div class="row text-center">
                        <div class="col-6 mb-3">
                            <div class="display-6 text-primary" id="wordCount">0</div>
                            <small class="text-muted">Words</small>
                        </div>
                        <div class="col-6 mb-3">
                            <div class="display-6 text-success" id="charCount">0</div>
                            <small class="text-muted">Characters</small>
                        </div>
                        <div class="col-6">
                            <div class="display-6 text-warning" id="imageCount">0</div>
                            <small class="text-muted">Images</small>
                        </div>
                        <div class="col-6">
                            <div class="display-6 text-info" id="codeBlockCount">0</div>
                            <small class="text-muted">Code Blocks</small>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <!-- TinyMCE Script -->
    <script src="https://cdn.tiny.cloud/1/no-api-key/tinymce/6/tinymce.min.js" referrerpolicy="origin"></script>
    <script src="https://code.jquery.com/jquery-3.6.4.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    
    <script>
        // Initialize TinyMCE
        tinymce.init({
            selector: '#lessonContent',
            height: 500,
            menubar: true,
            plugins: [
                'advlist', 'autolink', 'lists', 'link', 'image', 'charmap', 'preview',
                'anchor', 'searchreplace', 'visualblocks', 'code', 'fullscreen',
                'insertdatetime', 'media', 'table', 'code', 'help', 'wordcount',
                'codesample'
            ],
            toolbar: 'undo redo | blocks | ' +
                'bold italic forecolor | alignleft aligncenter ' +
                'alignright alignjustify | bullist numlist outdent indent | ' +
                'removeformat | help | code codesample',
            content_style: `
                body { 
                    font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen, Ubuntu, sans-serif;
                    font-size: 16px; 
                    line-height: 1.6;
                    color: #333;
                }
                h2, h3, h4 { 
                    color: #4361ee;
                    margin-top: 1.5em;
                    margin-bottom: 0.5em;
                }
                p { 
                    margin-bottom: 1em;
                }
                ul, ol {
                    margin-left: 20px;
                    margin-bottom: 1em;
                }
                li {
                    margin-bottom: 0.5em;
                }
                .code-snippet {
                    background: #1e1e1e;
                    color: #d4d4d4;
                    border-radius: 6px;
                    padding: 15px;
                    margin: 15px 0;
                    font-family: 'Consolas', 'Monaco', 'Courier New', monospace;
                    position: relative;
                }
                .code-snippet pre {
                    margin: 0;
                    padding: 0;
                    background: transparent;
                    border: none;
                }
                .code-snippet code {
                    font-family: 'Consolas', 'Monaco', 'Courier New', monospace;
                    font-size: 14px;
                    line-height: 1.5;
                    color: #9cdcfe;
                }
                .code-snippet-header {
                    display: flex;
                    justify-content: space-between;
                    align-items: center;
                    margin-bottom: 10px;
                    padding-bottom: 5px;
                    border-bottom: 1px solid #555;
                    color: #888;
                    font-size: 12px;
                }
                .example-box {
                    background: #e7f1ff;
                    border-left: 4px solid #4361ee;
                    padding: 15px;
                    border-radius: 4px;
                    margin: 15px 0;
                }
                .example-box-header {
                    display: flex;
                    align-items: center;
                    gap: 10px;
                    margin-bottom: 10px;
                    color: #4361ee;
                    font-weight: 600;
                }
                .tip-box {
                    background: #fff3cd;
                    border-left: 4px solid #ffc107;
                    padding: 15px;
                    border-radius: 4px;
                    margin: 15px 0;
                }
                .tip-box-header {
                    display: flex;
                    align-items: center;
                    gap: 10px;
                    margin-bottom: 10px;
                    color: #856404;
                    font-weight: 600;
                }
                img {
                    max-width: 100%;
                    height: auto;
                    border-radius: 4px;
                    margin: 10px 0;
                }
                table {
                    border-collapse: collapse;
                    width: 100%;
                    margin: 15px 0;
                }
                th, td {
                    border: 1px solid #dee2e6;
                    padding: 8px;
                    text-align: left;
                }
                th {
                    background-color: #f8f9fa;
                    font-weight: 600;
                }
            `,
            setup: function(editor) {
                // Add custom buttons
                editor.ui.registry.addButton('insertCode', {
                    text: 'Code',
                    icon: 'sourcecode',
                    onAction: function() {
                        const codeContent = `<div class="code-snippet">
    <div class="code-snippet-header">
        <small>JavaScript Code</small>
        <button class="btn btn-sm btn-outline-light" onclick="copyCode(this)">
            <i class="fas fa-copy"></i> Copy
        </button>
    </div>
    <pre><code>// Write your code here
console.log('Hello, URUScript!');</code></pre>
</div>`;
                        editor.insertContent(codeContent);
                    }
                });
                
                editor.ui.registry.addButton('insertExample', {
                    text: 'Example',
                    icon: 'lightbulb',
                    onAction: function() {
                        const exampleContent = `<div class="example-box">
    <div class="example-box-header">
        <i class="fas fa-lightbulb"></i>
        <span>Example Title</span>
    </div>
    <p>Write your example explanation here...</p>
</div>`;
                        editor.insertContent(exampleContent);
                    }
                });
                
                editor.ui.registry.addButton('insertTip', {
                    text: 'Tip',
                    icon: 'info',
                    onAction: function() {
                        const tipContent = `<div class="tip-box">
    <div class="tip-box-header">
        <i class="fas fa-info-circle"></i>
        <span>Important Tip</span>
    </div>
    <p>Write your important tip here...</p>
</div>`;
                        editor.insertContent(tipContent);
                    }
                });
                
                // Update preview on content change
                editor.on('keyup', function() {
                    updatePreview();
                    updateStats();
                });
                
                editor.on('change', function() {
                    updatePreview();
                    updateStats();
                });
            }
        });
        
        // Global variables
        let currentLessonId = <?php echo isset($_GET['edit']) ? $_GET['edit'] : 'null'; ?>;
        let currentQuizId = null;
        
        // Initialize if editing
        document.addEventListener('DOMContentLoaded', function() {
            if (currentLessonId) {
                loadLessonForEditing();
            }
            
            // Add initial event listeners
            document.getElementById('lessonContent').addEventListener('input', updateStats);
            
            // Initial preview and stats
            setTimeout(() => {
                updatePreview();
                updateStats();
            }, 1000);
        });
        
        // Load lesson for editing
        function loadLessonForEditing() {
            fetch('add-lesson.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: `action=get_lesson&lesson_id=${currentLessonId}`
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    const lesson = data.lesson;
                    
                    // Populate form fields
                    document.getElementById('topicSelect').value = lesson.topic_id;
                    document.getElementById('lessonTitle').value = lesson.lesson_title;
                    document.getElementById('lessonOrder').value = lesson.lesson_order;
                    
                    // Set content type radio
                    document.querySelector(`input[name="contentType"][value="${lesson.content_type}"]`).checked = true;
                    
                    // Set active status
                    document.getElementById('lessonActive').checked = lesson.is_active == 1;
                    
                    // Set TinyMCE content
                    tinymce.get('lessonContent').setContent(lesson.lesson_content);
                    
                    // Load quiz if exists
                    if (lesson.quiz) {
                        const quiz = lesson.quiz;
                        currentQuizId = quiz.id;
                        
                        document.getElementById('quizQuestion').value = quiz.question;
                        document.getElementById('quizExplanation').value = quiz.explanation || '';
                        document.querySelector(`input[name="difficulty"][value="${quiz.difficulty}"]`).checked = true;
                        
                        // Clear existing options
                        document.getElementById('quizOptionsContainer').innerHTML = '';
                        
                        // Parse and load options
                        try {
                            const options = JSON.parse(`[${quiz.options_json}]`);
                            options.forEach((option, index) => {
                                addOption();
                                const lastOption = document.querySelector('.quiz-option-item:last-child');
                                lastOption.querySelector('.option-text').value = option.text;
                                lastOption.querySelector('input[type="radio"]').checked = option.is_correct == 1;
                                lastOption.querySelector('input[type="radio"]').value = index;
                            });
                        } catch (e) {
                            console.error('Error parsing quiz options:', e);
                            // Add default options
                            addOption();
                            addOption();
                        }
                    }
                    
                    // Update preview
                    updatePreview();
                    updateStats();
                    
                    showToast('Lesson loaded for editing', 'success');
                }
            });
        }
        
        // Update preview
        function updatePreview() {
            const content = tinymce.get('lessonContent').getContent();
            document.getElementById('lessonPreview').innerHTML = content;
            
            // Add copy functionality to preview
            document.querySelectorAll('#lessonPreview .code-snippet .btn').forEach(btn => {
                btn.onclick = function() {
                    copyCode(this);
                };
            });
        }
        
        // Update stats
        function updateStats() {
            const content = tinymce.get('lessonContent').getContent({format: 'text'});
            const htmlContent = tinymce.get('lessonContent').getContent();
            
            // Word count
            const words = content.trim().split(/\s+/).filter(word => word.length > 0);
            document.getElementById('wordCount').textContent = words.length;
            
            // Character count
            document.getElementById('charCount').textContent = content.length;
            
            // Image count
            const imageCount = (htmlContent.match(/<img/g) || []).length;
            document.getElementById('imageCount').textContent = imageCount;
            
            // Code block count
            const codeBlockCount = (htmlContent.match(/class="code-snippet"/g) || []).length;
            document.getElementById('codeBlockCount').textContent = codeBlockCount;
        }
        
        // Quiz options management
        function addOption() {
            const container = document.getElementById('quizOptionsContainer');
            const optionCount = container.children.length;
            
            const optionHTML = `
                <div class="quiz-option-item">
                    <div class="form-check mb-2">
                        <input class="form-check-input" type="radio" name="correctAnswer" value="${optionCount}">
                        <label class="form-check-label">Correct Answer</label>
                    </div>
                    <input type="text" class="form-control option-text" placeholder="Option text...">
                    <div class="option-actions">
                        <button class="btn btn-sm btn-outline-danger" onclick="removeOption(this)">
                            <i class="fas fa-trash"></i> Remove
                        </button>
                    </div>
                </div>
            `;
            
            container.insertAdjacentHTML('beforeend', optionHTML);
        }
        
        function removeOption(button) {
            if (document.querySelectorAll('.quiz-option-item').length > 2) {
                button.closest('.quiz-option-item').remove();
                // Update radio button values
                document.querySelectorAll('.quiz-option-item').forEach((item, index) => {
                    item.querySelector('input[type="radio"]').value = index;
                });
            } else {
                showToast('Quiz must have at least 2 options', 'warning');
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
            
            // Get TinyMCE content
            const lessonContent = tinymce.get('lessonContent').getContent();
            
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
            formData.append('lesson_content', lessonContent);
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
                    
                    // Show success message with option to view
                    if (!currentLessonId) {
                        const viewBtn = confirm('Lesson saved successfully! Click OK to view it now, or Cancel to stay here.');
                        if (viewBtn) {
                            window.location.href = 'view_lesson.php?id=' + data.lesson_id;
                        }
                    }
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
        
        // Copy code function
        function copyCode(button) {
            const codeSnippet = button.closest('.code-snippet');
            const codeElement = codeSnippet.querySelector('code');
            const codeText = codeElement ? codeElement.textContent : codeSnippet.querySelector('pre').textContent;
            
            navigator.clipboard.writeText(codeText).then(() => {
                const originalHTML = button.innerHTML;
                button.innerHTML = '<i class="fas fa-check"></i> Copied!';
                button.classList.remove('btn-outline-light');
                button.classList.add('btn-success');
                
                setTimeout(() => {
                    button.innerHTML = originalHTML;
                    button.classList.remove('btn-success');
                    button.classList.add('btn-outline-light');
                }, 2000);
            }).catch(err => {
                console.error('Failed to copy: ', err);
                showToast('Failed to copy code', 'danger');
            });
        }
        
        // Toast notification
        function showToast(message, type = 'info') {
            // Create toast element
            const toast = document.createElement('div');
            toast.className = `toast align-items-center text-white bg-${type} border-0 position-fixed`;
            toast.style.cssText = 'top: 20px; right: 20px; z-index: 9999;';
            toast.innerHTML = `
                <div class="d-flex">
                    <div class="toast-body">
                        ${message}
                    </div>
                    <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button>
                </div>
            `;
            
            document.body.appendChild(toast);
            
            // Initialize and show
            const bsToast = new bootstrap.Toast(toast, { delay: 3000 });
            bsToast.show();
            
            // Remove after hide
            toast.addEventListener('hidden.bs.toast', function () {
                document.body.removeChild(toast);
            });
        }
        
        // Auto-save draft every 30 seconds
        setInterval(() => {
            if (document.getElementById('lessonTitle').value) {
                saveDraft();
            }
        }, 30000);
        
        function saveDraft() {
            const draft = {
                topicId: document.getElementById('topicSelect').value,
                lessonTitle: document.getElementById('lessonTitle').value,
                lessonOrder: document.getElementById('lessonOrder').value,
                lessonContent: tinymce.get('lessonContent').getContent(),
                contentType: document.querySelector('input[name="contentType"]:checked').value,
                isActive: document.getElementById('lessonActive').checked ? 1 : 0,
                quizQuestion: document.getElementById('quizQuestion').value,
                quizExplanation: document.getElementById('quizExplanation').value,
                difficulty: document.querySelector('input[name="difficulty"]:checked').value,
                options: []
            };
            
            document.querySelectorAll('.quiz-option-item').forEach(item => {
                const textInput = item.querySelector('.option-text');
                const isCorrect = item.querySelector('input[type="radio"]').checked;
                
                if (textInput.value.trim()) {
                    draft.options.push({
                        text: textInput.value.trim(),
                        is_correct: isCorrect ? 1 : 0
                    });
                }
            });
            
            localStorage.setItem('lesson_draft', JSON.stringify(draft));
        }
        
        function loadDraft() {
            const draft = localStorage.getItem('lesson_draft');
            if (draft) {
                const data = JSON.parse(draft);
                
                document.getElementById('topicSelect').value = data.topicId;
                document.getElementById('lessonTitle').value = data.lessonTitle;
                document.getElementById('lessonOrder').value = data.lessonOrder;
                tinymce.get('lessonContent').setContent(data.lessonContent);
                document.querySelector(`input[name="contentType"][value="${data.contentType}"]`).checked = true;
                document.getElementById('lessonActive').checked = data.isActive == 1;
                document.getElementById('quizQuestion').value = data.quizQuestion;
                document.getElementById('quizExplanation').value = data.quizExplanation;
                document.querySelector(`input[name="difficulty"][value="${data.difficulty}"]`).checked = true;
                
                // Clear existing options
                document.getElementById('quizOptionsContainer').innerHTML = '';
                
                // Load options
                data.options.forEach((option, index) => {
                    addOption();
                    const lastOption = document.querySelector('.quiz-option-item:last-child');
                    lastOption.querySelector('.option-text').value = option.text;
                    lastOption.querySelector('input[type="radio"]').checked = option.is_correct == 1;
                    lastOption.querySelector('input[type="radio"]').value = index;
                });
                
                updatePreview();
                updateStats();
                
                showToast('Draft loaded successfully', 'info');
            }
        }
        
        // Prompt to load draft on page load if exists
        document.addEventListener('DOMContentLoaded', function() {
            if (localStorage.getItem('lesson_draft') && !currentLessonId) {
                const loadDraft = confirm('You have an unsaved draft. Would you like to load it?');
                if (loadDraft) {
                    loadDraft();
                }
            }
        });
        
        // Warn before leaving if there are unsaved changes
        window.addEventListener('beforeunload', function (e) {
            if (document.getElementById('lessonTitle').value && !currentLessonId) {
                e.preventDefault();
                e.returnValue = '';
            }
        });
    </script>
</body>
</html>