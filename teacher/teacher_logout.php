<?php
require_once 'database.php';

$db = new Database();
$conn = $db->getConnection();
$teacherSession = new TeacherSession($conn);

$teacherSession->logout();

header('Location: teacher_login');
exit;
?>