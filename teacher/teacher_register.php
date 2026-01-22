<?php
require_once 'database.php';

$db = new Database();
$conn = $db->getConnection();
$teacherSession = new TeacherSession($conn);

// Redirect if already logged in
if ($teacherSession->isLoggedIn()) {
    header('Location: teacher_dashboard');
    exit;
}

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username']);
    $email = trim($_POST['email']);
    $password = $_POST['password'];
    $confirm_password = $_POST['confirm_password'];
    
    // Validation
    if (empty($username) || empty($email) || empty($password)) {
        $error = 'All fields are required';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Invalid email address';
    } elseif (strlen($password) < 6) {
        $error = 'Password must be at least 6 characters long';
    } elseif ($password !== $confirm_password) {
        $error = 'Passwords do not match';
    } else {
    // Check if teacher email is allowed.
        $is_allowed = $teacherSession->isAllowed($email);
        if($is_allowed === false) {
            $error = 'Registration with this email is not allowed. Please contact admin.';
        } else {
            // Register the teacher
            $result = $teacherSession->register($username, $email, $password);
            
            if ($result['success']) {
                header('Location: teacher_dashboard');
                exit;
            } else {
                $error = $result['message'];
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Teacher Registration - JS Tutorial System</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        .register-container {
            max-width: 500px;
            margin: 80px auto;
            padding: 40px;
            background: white;
            border-radius: 12px;
            box-shadow: 0 5px 20px rgba(0,0,0,0.1);
        }
        
        .register-header {
            text-align: center;
            margin-bottom: 30px;
        }
        
        .register-header i {
            font-size: 3rem;
            color: #4361ee;
            margin-bottom: 15px;
        }
        
        .password-strength {
            height: 4px;
            margin-top: 5px;
            border-radius: 2px;
            transition: all 0.3s;
        }
        
        .strength-weak { background: #dc3545; width: 25%; }
        .strength-fair { background: #ffc107; width: 50%; }
        .strength-good { background: #28a745; width: 75%; }
        .strength-strong { background: #20c997; width: 100%; }
    </style>
</head>
<body style="background: linear-gradient(135deg, #4361ee 0%, #3a0ca3 100%); min-height: 100vh;">
    <div class="container">
        <div class="register-container">
            <div class="register-header">
                <i class="fas fa-chalkboard-teacher"></i>
                <h2>Teacher Registration</h2>
                <p class="text-muted">Create your account to manage tutorials</p>
            </div>
            
            <?php if ($error): ?>
                <div class="alert alert-danger alert-dismissible fade show" role="alert">
                    <?php echo htmlspecialchars($error); ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>
            
            <?php if ($success): ?>
                <div class="alert alert-success alert-dismissible fade show" role="alert">
                    <?php echo htmlspecialchars($success); ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>
            
            <form method="POST" action="" id="registerForm">
                <div class="mb-3">
                    <label class="form-label">Username *</label>
                    <div class="input-group">
                        <span class="input-group-text"><i class="fas fa-user"></i></span>
                        <input type="text" class="form-control" name="username" 
                               value="<?php echo isset($_POST['username']) ? htmlspecialchars($_POST['username']) : ''; ?>"
                               required placeholder="Choose a username">
                    </div>
                    <small class="text-muted">This will be your display name</small>
                </div>
                
                <div class="mb-3">
                    <label class="form-label">Email Address *</label>
                    <div class="input-group">
                        <span class="input-group-text"><i class="fas fa-envelope"></i></span>
                        <input type="email" class="form-control" name="email" 
                               value="<?php echo isset($_POST['email']) ? htmlspecialchars($_POST['email']) : ''; ?>"
                               required placeholder="Your email address">
                    </div>
                </div>
                
                <div class="mb-3">
                    <label class="form-label">Password *</label>
                    <div class="input-group">
                        <span class="input-group-text"><i class="fas fa-lock"></i></span>
                        <input type="password" class="form-control" name="password" 
                               id="password" required placeholder="At least 6 characters">
                        <button class="btn btn-outline-secondary" type="button" id="togglePassword">
                            <i class="fas fa-eye"></i>
                        </button>
                    </div>
                    <div class="password-strength" id="passwordStrength"></div>
                    <small class="text-muted" id="passwordHint"></small>
                </div>
                
                <div class="mb-4">
                    <label class="form-label">Confirm Password *</label>
                    <div class="input-group">
                        <span class="input-group-text"><i class="fas fa-lock"></i></span>
                        <input type="password" class="form-control" name="confirm_password" 
                               id="confirmPassword" required placeholder="Re-enter your password">
                        <button class="btn btn-outline-secondary" type="button" id="toggleConfirmPassword">
                            <i class="fas fa-eye"></i>
                        </button>
                    </div>
                    <small class="text-muted" id="passwordMatch"></small>
                </div>
                
                <div class="d-grid gap-2">
                    <button type="submit" class="btn btn-primary btn-lg">
                        <i class="fas fa-user-plus me-2"></i> Register Account
                    </button>
                    
                    <div class="text-center mt-3">
                        <p class="mb-0">Already have an account? 
                            <a href="teacher_login" class="text-decoration-none">Login here</a>
                        </p>
                    </div>
                    <div class="text-center mt-3">
                        <p class="mb-0">Student Portal? 
                            <a href="../" class="text-decoration-none">Login as Student</a>
                        </p>
                    </div>
                </div>
            </form>
            
            <hr class="my-4">
            
            <div class="text-center">
                <small class="text-muted">
                    By registering, you agree to our 
                    <a href="#" class="text-decoration-none">Terms of Service</a> 
                    and 
                    <a href="#" class="text-decoration-none">Privacy Policy</a>
                </small>
            </div>
        </div>
    </div>
    
    <script src="https://code.jquery.com/jquery-3.6.4.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Password visibility toggle
        document.getElementById('togglePassword').addEventListener('click', function() {
            const passwordInput = document.getElementById('password');
            const icon = this.querySelector('i');
            
            if (passwordInput.type === 'password') {
                passwordInput.type = 'text';
                icon.classList.remove('fa-eye');
                icon.classList.add('fa-eye-slash');
            } else {
                passwordInput.type = 'password';
                icon.classList.remove('fa-eye-slash');
                icon.classList.add('fa-eye');
            }
        });
        
        document.getElementById('toggleConfirmPassword').addEventListener('click', function() {
            const passwordInput = document.getElementById('confirmPassword');
            const icon = this.querySelector('i');
            
            if (passwordInput.type === 'password') {
                passwordInput.type = 'text';
                icon.classList.remove('fa-eye');
                icon.classList.add('fa-eye-slash');
            } else {
                passwordInput.type = 'password';
                icon.classList.remove('fa-eye-slash');
                icon.classList.add('fa-eye');
            }
        });
        
        // Password strength checker
        document.getElementById('password').addEventListener('input', function() {
            const password = this.value;
            const strengthBar = document.getElementById('passwordStrength');
            const hint = document.getElementById('passwordHint');
            const confirmPassword = document.getElementById('confirmPassword');
            
            let strength = 0;
            let hintText = '';
            
            // Check password length
            if (password.length >= 6) strength++;
            if (password.length >= 8) strength++;
            
            // Check for mixed case
            if (/[a-z]/.test(password) && /[A-Z]/.test(password)) strength++;
            
            // Check for numbers
            if (/\d/.test(password)) strength++;
            
            // Check for special characters
            if (/[^A-Za-z0-9]/.test(password)) strength++;
            
            // Update strength bar
            strengthBar.className = 'password-strength';
            if (password.length === 0) {
                strengthBar.className = 'password-strength';
                hintText = 'Enter a password';
            } else if (strength < 2) {
                strengthBar.className = 'password-strength strength-weak';
                hintText = 'Weak - try adding more characters or numbers';
            } else if (strength < 3) {
                strengthBar.className = 'password-strength strength-fair';
                hintText = 'Fair - could be stronger';
            } else if (strength < 4) {
                strengthBar.className = 'password-strength strength-good';
                hintText = 'Good password';
            } else {
                strengthBar.className = 'password-strength strength-strong';
                hintText = 'Strong password!';
            }
            
            hint.textContent = hintText;
            
            // Check password match
            if (confirmPassword.value) {
                checkPasswordMatch();
            }
        });
        
        // Password match checker
        document.getElementById('confirmPassword').addEventListener('input', checkPasswordMatch);
        
        function checkPasswordMatch() {
            const password = document.getElementById('password').value;
            const confirmPassword = document.getElementById('confirmPassword').value;
            const matchText = document.getElementById('passwordMatch');
            
            if (confirmPassword.length === 0) {
                matchText.textContent = '';
                matchText.className = 'text-muted';
            } else if (password === confirmPassword) {
                matchText.textContent = '✓ Passwords match';
                matchText.className = 'text-success';
            } else {
                matchText.textContent = '✗ Passwords do not match';
                matchText.className = 'text-danger';
            }
        }
        
        // Form validation
        document.getElementById('registerForm').addEventListener('submit', function(e) {
            const password = document.getElementById('password').value;
            const confirmPassword = document.getElementById('confirmPassword').value;
            
            if (password !== confirmPassword) {
                e.preventDefault();
                alert('Please make sure passwords match before submitting.');
                document.getElementById('confirmPassword').focus();
            }
        });
    </script>
</body>
</html>