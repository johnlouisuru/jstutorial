<?php
require_once 'database.php';

$db = new Database();
$conn = $db->getConnection();
$teacherSession = new TeacherSession($conn);

if (!$teacherSession->isLoggedIn()) {
    header('Location: teacher_login');
    exit;
}

// Update last active time
$teacherSession->updateLastActive();

$teacherUsername = $teacherSession->getTeacherUsername();
$teacherEmail = $teacherSession->getTeacherEmail();
$avatarColor = $teacherSession->getAvatarColor();

// Handle AJAX actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $response = [];
    
    if (isset($_POST['action'])) {
        switch ($_POST['action']) {
            case 'save_topic':
                $response = saveTopic($conn, $_POST);
                break;
            case 'delete_topic':
                $response = deleteTopic($conn, $_POST['topic_id']);
                break;
            case 'reorder_topics':
                // $response = reorderTopics($conn, $_POST['order']);
                $order = json_decode($_POST['order'], true); // convert to array 
                $response = reorderTopics($conn, $order); 
                // echo json_encode($response);
                break;
        }
        
        header('Content-Type: application/json');
        echo json_encode($response);
        exit;
    }
}

// Fetch all topics
$query = "SELECT t.*, 
         COUNT(l.id) as lesson_count,
         SUM(CASE WHEN l.is_active = 1 THEN 1 ELSE 0 END) as active_lessons
         FROM topics t
         LEFT JOIN lessons l ON t.id = l.topic_id AND l.deleted_at IS NULL
         WHERE t.deleted_at IS NULL
         GROUP BY t.id
         ORDER BY t.topic_order";
$topics = $conn->query($query)->fetchAll(PDO::FETCH_ASSOC);

function saveTopic($conn, $data) {
    try {
        if (isset($data['topic_id']) && $data['topic_id']) {
            // Update existing topic
            $query = "UPDATE topics SET 
                     topic_name = :name,
                     description = :description,
                     topic_order = :order,
                     is_active = :active
                     WHERE id = :id";
            
            $stmt = $conn->prepare($query);
            $stmt->bindParam(':id', $data['topic_id']);
        } else {
            // Insert new topic
            $query = "INSERT INTO topics (topic_name, description, topic_order, is_active) 
                     VALUES (:name, :description, :order, :active)";
            
            $stmt = $conn->prepare($query);
        }
        
        $stmt->bindParam(':name', $data['topic_name']);
        $stmt->bindParam(':description', $data['description']);
        $stmt->bindParam(':order', $data['topic_order']);
        $stmt->bindParam(':active', $data['is_active']);
        $stmt->execute();
        
        $topic_id = isset($data['topic_id']) ? $data['topic_id'] : $conn->lastInsertId();
        
        return [
            'success' => true,
            'message' => 'Topic saved successfully',
            'topic_id' => $topic_id
        ];
    } catch (PDOException $e) {
        return [
            'success' => false,
            'message' => 'Database error: ' . $e->getMessage()
        ];
    }
}

function deleteTopic($conn, $topic_id) {
    try {
        // Check if topic has lessons
        $check_query = "SELECT COUNT(*) FROM lessons WHERE topic_id = ? AND deleted_at IS NULL";
        $check_stmt = $conn->prepare($check_query);
        $check_stmt->execute([$topic_id]);
        $lesson_count = $check_stmt->fetchColumn();
        
        if ($lesson_count > 0) {
            return [
                'success' => false,
                'message' => 'Cannot delete topic with existing lessons. Delete or move lessons first.'
            ];
        }
        
        // Soft delete the topic
        $query = "UPDATE topics SET deleted_at = NOW() WHERE id = ?";
        $stmt = $conn->prepare($query);
        $stmt->execute([$topic_id]);
        
        return [
            'success' => true,
            'message' => 'Topic deleted successfully'
        ];
    } catch (PDOException $e) {
        return [
            'success' => false,
            'message' => 'Database error: ' . $e->getMessage()
        ];
    }
}

function reorderTopics($conn, $order) {
    try {
        $conn->beginTransaction();
        
        foreach ($order as $index => $topic_id) {
            $query = "UPDATE topics SET topic_order = ? WHERE id = ?";
            $stmt = $conn->prepare($query);
            $stmt->execute([$index + 1, $topic_id]);
        }
        
        $conn->commit();
        
        return [
            'success' => true,
            'message' => 'Topics reordered successfully'
        ];
    } catch (PDOException $e) {
        $conn->rollBack();
        return [
            'success' => false,
            'message' => 'Database error: ' . $e->getMessage()
        ];
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Topics - JS Tutorial Admin</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/Sortable/1.14.0/Sortable.min.css">
    <style>
        .topic-item {
            background: white;
            border: 1px solid #dee2e6;
            border-radius: 8px;
            padding: 15px;
            margin-bottom: 10px;
            cursor: move;
            transition: all 0.2s;
        }
        
        .topic-item:hover {
            border-color: #4361ee;
            box-shadow: 0 2px 8px rgba(67, 97, 238, 0.1);
        }
        
        .topic-item.sortable-ghost {
            opacity: 0.4;
        }
        
        .topic-item.sortable-drag {
            opacity: 0.8;
            transform: rotate(2deg);
        }
        
        .lesson-count {
            font-size: 0.8rem;
            padding: 2px 8px;
            border-radius: 10px;
            background: #e9ecef;
        }
        
        .status-badge {
            font-size: 0.75rem;
            padding: 3px 8px;
            border-radius: 4px;
        }
        
        .active {
            background: #d4edda;
            color: #155724;
        }
        
        .inactive {
            background: #f8d7da;
            color: #721c24;
        }
    </style>
</head>
<body>
<nav class="navbar navbar-expand-lg navbar-dark bg-primary">
        <div class="container">
            <a class="navbar-brand" href="#">
                <i class="fas fa-chalkboard-teacher me-2"></i> Topics
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
    <div class="container-fluid py-4">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <div>
                <h2><i class="fas fa-folder me-2 text-primary"></i>Manage Topics</h2>
                <p class="text-muted">Organize your tutorial topics</p>
            </div>
            
            

            <div class="d-flex gap-2 m-1">

                <a class="btn btn-info me-2" href="manage_lessons">
                <i class="fas fa-plus me-1"></i> Add Lesson to Topic
            </a>
            <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addTopicModal">
                <i class="fas fa-plus me-1"></i> Add Topic
            </button>
                
            </div>
        </div>
        
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
                    
            <!-- <button class="btn btn-info" onclick="window.location.href='teacher_dashboard'">
                <i class="fas fa-arrow-left me-1"></i> Dashboard
            </button> 
            <hr />-->
                        <h5 class="mb-0">Topics List</h5>
                        <small class="text-muted">Drag to reorder topics</small>
                    </div>
                    <div class="card-body">
                        <div id="topicsList" class="sortable-list">
                            <?php if (count($topics) > 0): ?>
                                <?php foreach($topics as $topic): ?>
                                <div class="topic-item" data-id="<?php echo $topic['id']; ?>">
                                    <div class="d-flex justify-content-between align-items-center">
                                        <div class="d-flex align-items-center">
                                            <div class="me-3 text-primary">
                                                <i class="fas fa-grip-vertical"></i>
                                            </div>
                                            <div>
                                                <h6 class="mb-1"><?php echo htmlspecialchars($topic['topic_name']); ?></h6>
                                                <div class="d-flex gap-3 align-items-center">
                                                    <span class="badge bg-secondary">Order: <?php echo $topic['topic_order']; ?></span>
                                                    <span class="lesson-count">
                                                        <i class="fas fa-book me-1"></i>
                                                        <?php echo $topic['lesson_count']; ?> lessons
                                                    </span>
                                                    <span class="lesson-count">
                                                        <i class="fas fa-check me-1"></i>
                                                        <?php echo $topic['active_lessons']; ?> active
                                                    </span>
                                                    <?php if ($topic['description']): ?>
                                                    <small class="text-muted"><?php echo htmlspecialchars($topic['description']); ?></small>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                        </div>
                                        <div class="d-flex gap-2">
                                            <span class="status-badge <?php echo $topic['is_active'] ? 'active' : 'inactive'; ?>">
                                                <?php echo $topic['is_active'] ? 'Active' : 'Inactive'; ?>
                                            </span>
                                            <button class="btn btn-sm btn-outline-primary" 
                                                    onclick="editTopic(<?php echo $topic['id']; ?>)">
                                                <i class="fas fa-edit"></i>
                                            </button>
                                            <button class="btn btn-sm btn-outline-danger" 
                                                    onclick="deleteTopic(<?php echo $topic['id']; ?>)">
                                                <i class="fas fa-trash"></i>
                                            </button>
                                        </div>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <div class="text-center py-5">
                                    <i class="fas fa-folder-open fa-3x text-muted mb-3"></i>
                                    <h5>No Topics Found</h5>
                                    <p class="text-muted">Create your first topic to get started</p>
                                    <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addTopicModal">
                                        <i class="fas fa-plus me-1"></i> Create Topic
                                    </button>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="card-footer">
                        <button class="btn btn-success" onclick="saveOrder()">
                            <i class="fas fa-save me-1"></i> Save Order
                        </button>
                        <small class="text-muted ms-2">Remember to save after reordering</small>
                    </div>
                </div>
            </div>
            
            
        </div>
        <hr />
        <div class="row">
            <div class="col-md-6">
                <div class="card mb-4">
                    <div class="card-header bg-light">
                        <h5 class="mb-0">Quick Stats</h5>
                    </div>
                    <div class="card-body">
                        <div class="mb-3">
                            <div class="d-flex justify-content-between">
                                <span>Total Topics</span>
                                <span class="fw-bold"><?php echo count($topics); ?></span>
                            </div>
                        </div>
                        <div class="mb-3">
                            <div class="d-flex justify-content-between">
                                <span>Total Lessons</span>
                                <span class="fw-bold">
                                    <?php 
                                    $total_lessons = 0;
                                    foreach($topics as $topic) {
                                        $total_lessons += $topic['lesson_count'];
                                    }
                                    echo $total_lessons;
                                    ?>
                                </span>
                            </div>
                        </div>
                        <div class="mb-3">
                            <div class="d-flex justify-content-between">
                                <span>Active Topics</span>
                                <span class="fw-bold">
                                    <?php 
                                    $active_topics = 0;
                                    foreach($topics as $topic) {
                                        if ($topic['is_active']) $active_topics++;
                                    }
                                    echo $active_topics;
                                    ?>
                                </span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-md-6">   
                <div class="card">
                    <div class="card-header bg-light">
                        <h5 class="mb-0">Tips</h5>
                    </div>
                    <div class="card-body">
                        <ul class="list-unstyled">
                            <li class="mb-2">
                                <i class="fas fa-lightbulb text-warning me-2"></i>
                                <small>Topics determine the main sections of your tutorial</small>
                            </li>
                            <li class="mb-2">
                                <i class="fas fa-lightbulb text-warning me-2"></i>
                                <small>Use the drag handle to reorder topics</small>
                            </li>
                            <li class="mb-2">
                                <i class="fas fa-lightbulb text-warning me-2"></i>
                                <small>Inactive topics won't be visible to students</small>
                            </li>
                            <li>
                                <i class="fas fa-lightbulb text-warning me-2"></i>
                                <small>Add descriptions to help organize content</small>
                            </li>
                        </ul>
                    </div>
                </div>
            </div> 
            
        </div>
    </div>
    
    <!-- Add/Edit Topic Modal -->
    <div class="modal fade" id="addTopicModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="modalTitle">Add New Topic</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <form id="topicForm">
                        <input type="hidden" id="topicId">
                        <div class="mb-3">
                            <label class="form-label">Topic Name *</label>
                            <input type="text" class="form-control" id="topicName" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Description</label>
                            <textarea class="form-control" id="topicDescription" rows="3"></textarea>
                            <small class="text-muted">Optional: Brief description of what this topic covers</small>
                        </div>
                        <div class="row mb-3">
                            <div class="col-md-6">
                                <label class="form-label">Display Order *</label>
                                <input type="number" class="form-control" id="topicOrder" min="1" required>
                                <small class="text-muted">Determines position in the topics list</small>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Status</label>
                                <div class="form-check form-switch mt-2">
                                    <input class="form-check-input" type="checkbox" id="topicActive" checked>
                                    <label class="form-check-label" for="topicActive">Active</label>
                                </div>
                                <small class="text-muted">Inactive topics won't be shown to students</small>
                            </div>
                        </div>
                    </form>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="button" class="btn btn-primary" onclick="saveTopic()">Save Topic</button>
                </div>
            </div>
        </div>
    </div>
    
    <script src="https://code.jquery.com/jquery-3.6.4.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/Sortable/1.14.0/Sortable.min.js"></script>
    <script>
        // Initialize sortable
        let sortable = null;
        
        document.addEventListener('DOMContentLoaded', function() {
            sortable = new Sortable(document.getElementById('topicsList'), {
                animation: 150,
                ghostClass: 'sortable-ghost',
                dragClass: 'sortable-drag',
                handle: '.fa-grip-vertical'
            });
        });
        
        // Save order
        function saveOrder() {
            const order = sortable.toArray();
            
            fetch('manage_topics.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: `action=reorder_topics&order=${JSON.stringify(order)}`
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    alert('Order saved successfully!');
                    location.reload();
                } else {
                    alert('Error: ' + data.message);
                }
            });
        }
        
        // Edit topic
        function editTopic(topicId) {
            // Fetch topic data
            fetch(`get_topic.php?id=${topicId}`)
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    document.getElementById('modalTitle').textContent = 'Edit Topic';
                    document.getElementById('topicId').value = data.topic.id;
                    document.getElementById('topicName').value = data.topic.topic_name;
                    document.getElementById('topicDescription').value = data.topic.description || '';
                    document.getElementById('topicOrder').value = data.topic.topic_order;
                    document.getElementById('topicActive').checked = data.topic.is_active == 1;
                    
                    const modal = new bootstrap.Modal(document.getElementById('addTopicModal'));
                    modal.show();
                }
            });
        }
        
        // Delete topic
        function deleteTopic(topicId) {
            if (!confirm('Are you sure you want to delete this topic? This action cannot be undone.')) {
                return;
            }
            
            fetch('manage_topics.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: `action=delete_topic&topic_id=${topicId}`
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    alert('Topic deleted successfully!');
                    location.reload();
                } else {
                    alert('Error: ' + data.message);
                }
            });
        }
        
        // Save topic
        function saveTopic() {
            const formData = new FormData();
            formData.append('action', 'save_topic');
            formData.append('topic_id', document.getElementById('topicId').value);
            formData.append('topic_name', document.getElementById('topicName').value);
            formData.append('description', document.getElementById('topicDescription').value);
            formData.append('topic_order', document.getElementById('topicOrder').value);
            formData.append('is_active', document.getElementById('topicActive').checked ? 1 : 0);
            
            fetch('manage_topics.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    alert('Topic saved successfully!');
                    location.reload();
                } else {
                    alert('Error: ' + data.message);
                }
            });
        }
    </script>
</body>
</html>