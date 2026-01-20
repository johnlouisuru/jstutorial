<?php
require_once 'database.php';

$db = new Database();
$conn = $db->getConnection();
$studentSession = new StudentSession($conn);

if (!$studentSession->isLoggedIn()) {
    header('Location: index.php');
    exit;
}

$username = $studentSession->getStudentData()['username'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <title>JavaScript Practice Area | Interactive Learning Platform</title>
    
    <!-- Bootstrap 5 -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    
    <style>
        :root {
            --primary-color: #4361ee;
            --secondary-color: #3a0ca3;
        }
        
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: #f5f7fb;
            overflow-x: hidden;
        }
        
        /* Navbar */
        .navbar {
            background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        
        /* Main Container */
        .main-container {
            display: flex;
            min-height: calc(100vh - 56px);
        }
        
        /* Sidebar */
        .sidebar {
            width: 280px;
            background: #2d3748;
            color: white;
            transition: transform 0.3s ease;
            overflow-y: auto;
            border-right: 1px solid #4a5568;
        }
        
        .sidebar-content {
            padding: 20px;
        }
        
        /* Main Content */
        .content-area {
            flex: 1;
            display: flex;
            flex-direction: column;
            overflow: hidden;
        }
        
        /* Toolbar */
        .toolbar {
            background: #343a40;
            padding: 10px 15px;
            display: flex;
            gap: 10px;
            align-items: center;
            border-bottom: 1px solid #495057;
        }
        
        /* Editor Container */
        .editor-container {
            flex: 1;
            height: 40vh; /* 40% of viewport height */ 
            min-height: 200px; /* safety minimum */
            max-height: 350px;
            border: 1px solid #dee2e6;
            border-radius: 6px;
            overflow: hidden;
            background: white;
        }
        
        /* Fallback Editor */
        #fallbackEditor {
            width: 100%;
            height: 100px;
            border: 0;
            padding: 15px;
            box-sizing: border-box;
            font-family: 'Consolas', 'Monaco', monospace;
            font-size: 14px;
            line-height: 1.5;
            resize: none;
            background: #1e1e1e;
            color: #d4d4d4;
        }
        
        /* Output Panel */
        .output-panel {
            height: 200px;
            background: #0b0b0b;
            color: #d0ffb3;
            border: 1px solid #333;
            border-radius: 6px;
            display: flex;
            flex-direction: column;
            margin-top: 15px;
        }
        
        .output-header {
            background: #1a1a1a;
            padding: 8px 15px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            border-bottom: 1px solid #333;
        }
        
        .output-content {
            flex: 1;
            padding: 12px;
            overflow-y: auto;
            font-family: 'Consolas', 'Monaco', monospace;
            font-size: 13px;
            white-space: pre-wrap;
        }
        
        /* Sidebar Items */
        .sidebar-item {
            padding: 10px 15px;
            margin: 5px 0;
            background: #4a5568;
            border-radius: 5px;
            cursor: pointer;
            transition: all 0.2s;
        }
        
        .sidebar-item:hover {
            background: #5a6578;
            transform: translateX(5px);
        }
        
        .sidebar-item.active {
            background: var(--primary-color);
        }
        
        /* Status Indicators */
        .status-badge {
            transition: all 0.3s;
        }
        
        .status-dot {
            width: 10px;
            height: 10px;
            border-radius: 50%;
            display: inline-block;
            margin-right: 8px;
        }
        
        .status-running { 
            background: #48bb78; 
            animation: pulse 1.5s infinite; 
        }
        
        .status-error { background: #f56565; }
        .status-success { background: #48bb78; }
        
        @keyframes pulse {
            0% { opacity: 1; }
            50% { opacity: 0.5; }
            100% { opacity: 1; }
        }
        
        /* Output colors */
        .output-success { color: #68d391; }
        .output-error { color: #fc8181; }
        .output-warning { color: #f6e05e; }
        .output-info { color: #63b3ed; }
        
        /* Mobile Responsive */
        @media (max-width: 992px) {
            .sidebar {
                position: fixed;
                left: 0;
                top: 56px;
                bottom: 0;
                transform: translateX(-100%);
                z-index: 1000;
                width: 100%;
                max-width: 320px;
            }
            
            .sidebar.open {
                transform: translateX(0);
            }
            
            .sidebar-toggle {
                display: flex !important;
            }
            
            .editor-container {
                height: 50px;
            }
            
            .output-panel {
                height: 150px;
            }
        }
        
        .sidebar-toggle {
            display: none;
            position: fixed;
            left: 15px;
            top: 70px;
            z-index: 1001;
            background: var(--primary-color);
            color: white;
            border: none;
            border-radius: 50%;
            width: 40px;
            height: 40px;
            align-items: center;
            justify-content: center;
            box-shadow: 0 2px 10px rgba(0,0,0,0.2);
            cursor: pointer;
        }
        
        /* Custom Scrollbar */
        ::-webkit-scrollbar {
            width: 8px;
        }
        
        ::-webkit-scrollbar-track {
            background: #2d3748;
        }
        
        ::-webkit-scrollbar-thumb {
            background: #4a5568;
            border-radius: 4px;
        }
        
        ::-webkit-scrollbar-thumb:hover {
            background: #5a6578;
        }
        
        /* Monaco Editor Theming */
        .monaco-editor .margin {
            background-color: #1e1e1e !important;
        }
        
        /* File list styling */
        .file-time {
            font-size: 11px;
            opacity: 0.7;
        }
        
        /* Tips box */
        .tips-box {
            background: rgba(255, 255, 255, 0.05);
            border-radius: 5px;
            padding: 10px;
            margin-top: 10px;
        }
    </style>
</head>
<body>
    <!-- Navbar -->
    <nav class="navbar navbar-dark">
        <div class="container-fluid">
            <a class="navbar-brand" href="#">
                <i class="fab fa-js-square me-2"></i>URUScript Practice Area
            </a>
            <button class="sidebar-toggle" id="sidebarToggle">
                <i class="fas fa-bars"></i>
            </button>
            <div class="d-flex align-items-center">
                <span class="text-white me-3 d-none d-md-inline">Welcome, <?php echo htmlspecialchars($username); ?></span>
                <a href="dashboard.php" class="btn btn-sm btn-outline-light">
                    <i class="fas fa-arrow-left me-1"></i> Dashboard
                </a>
            </div>
        </div>
    </nav>

    <!-- Main Container -->
    <div class="main-container">
        <!-- Sidebar -->
        <div class="sidebar" id="sidebar">
            <div class="sidebar-content">
                <!-- Files Section -->
                <h6 class="mb-3">
                    <i class="fas fa-folder me-2"></i>My URUScript Files
                </h6>
                <div id="fileList" class="mb-4">
                    <div class="text-muted small">No saved files yet</div>
                </div>
                <button class="btn btn-sm btn-outline-light w-100 mb-4" onclick="createNewFile()">
                    <i class="fas fa-plus me-1"></i> New File
                </button>
                
                <!-- JavaScript Examples -->
                <h6 class="mb-3">
                    <i class="fas fa-code me-2"></i>URUScript Examples
                </h6>
                <div id="exampleList" class="mb-4">
                    <div class="sidebar-item" onclick="loadExample('hello-world')">
                        <i class="fas fa-play-circle me-2"></i>Hello World
                    </div>
                    <div class="sidebar-item" onclick="loadExample('variables')">
                        <i class="fas fa-cube me-2"></i>Variables & Types
                    </div>
                    <div class="sidebar-item" onclick="loadExample('functions')">
                        <i class="fas fa-cogs me-2"></i>Functions
                    </div>
                    <div class="sidebar-item" onclick="loadExample('arrays')">
                        <i class="fas fa-list me-2"></i>Arrays
                    </div>
                    <div class="sidebar-item" onclick="loadExample('objects')">
                        <i class="fas fa-object-group me-2"></i>Objects
                    </div>
                    <div class="sidebar-item" onclick="loadExample('loops')">
                        <i class="fas fa-redo me-2"></i>Loops
                    </div>
                    <div class="sidebar-item" onclick="loadExample('conditionals')">
                        <i class="fas fa-code-branch me-2"></i>Conditionals
                    </div>
                </div>
                
                <!-- Quick Tips -->
                <div class="tips-box">
                    <h6 class="mb-2">
                        <i class="fas fa-lightbulb me-2"></i>Quick Tips
                    </h6>
                    <div class="small">
                        <p><i class="fas fa-keyboard me-2"></i><strong>Ctrl+Enter</strong> - Run Code</p>
                        <p><i class="fas fa-keyboard me-2"></i><strong>Ctrl+S</strong> - Save File</p>
                        <p><i class="fas fa-keyboard me-2"></i><strong>Ctrl+F</strong> - Format</p>
                        <p><i class="fas fa-mouse-pointer me-2"></i>Click examples to load</p>
                    </div>
                </div>
            </div>
        </div>

        <!-- Main Content -->
        <div class="content-area p-3">
            <!-- Toolbar -->
            <div class="toolbar rounded-top">
                <div class="d-flex align-items-center gap-3">
                    <span class="text-white">
                        <i class="fab fa-js me-2"></i>URUScript Editor
                    </span>
                    
                    <div class="input-group input-group-sm" style="width: 200px;">
                        <span class="input-group-text bg-dark text-white border-dark">
                            <i class="fas fa-file-code"></i>
                        </span>
                        <input type="text" id="fileName" class="form-control bg-dark text-white border-dark" 
                               placeholder="filename.js" value="practice.js">
                    </div>
                </div>
                
                <div class="d-flex gap-2 ms-auto">
                    <button class="btn btn-sm btn-outline-light" onclick="saveFile()" title="Save (Ctrl+S)">
                        <i class="fas fa-save me-1"></i> Save
                    </button>
                    <button class="btn btn-sm btn-outline-light" onclick="formatCode()" title="Format Code">
                        <i class="fas fa-magic me-1"></i> Format
                    </button>
                    <button class="btn btn-sm btn-success" onclick="runCode()" title="Run (Ctrl+Enter)">
                        <i class="fas fa-play me-1"></i> Run Code
                    </button>
                    <button class="btn btn-sm btn-outline-light" onclick="clearOutput()" title="Clear Output">
                        <i class="fas fa-broom me-1"></i> Clear
                    </button>
                </div>
            </div>
            
            <!-- Status -->
            <div class="d-flex align-items-center justify-content-between my-2">
                <small class="text-muted" id="statusText">Loading Monaco Editor...</small>
                <div>
                    <span id="statusBadge" class="badge bg-secondary">Loading...</span>
                    <span id="executionTime" class="ms-2 text-muted"></span>
                </div>
            </div>

            <!-- Editor Container -->
            <div class="editor-container" style="height: 100px;">
                <!-- Monaco Editor will mount here; fallback textarea will be inserted if needed -->
            </div>

            <!-- Output Panel -->
            <div class="output-panel">
                <div class="output-header">
                    <span>
                        <i class="fas fa-terminal me-2"></i> Console Output
                        <span class="status-dot" id="statusIndicator"></span>
                    </span>
                    <button class="btn btn-sm btn-outline-light" onclick="toggleOutput()">
                        <i class="fas fa-chevron-up" id="outputToggleIcon"></i>
                    </button>
                </div>
                <div class="output-content" id="consoleOutput">
URUScript output will appear here...
                </div>
            </div>
            
            <!-- Stats -->
            <div class="mt-3 small text-muted d-flex justify-content-between">
                <div>
                    <i class="fas fa-history me-1"></i>
                    Last run: <span id="lastRun">Never</span>
                </div>
                <div>
                    <i class="fas fa-folder me-1"></i>
                    Files saved: <span id="fileCount">0</span>
                </div>
            </div>
        </div>
    </div>

    <!-- JavaScript Examples Database -->
    <!-- Main Application Script -->
<script>
// Make sure jsExamples is available globally


window.jsExamples = {
    'hello-world': {
        'title': 'Hello World',
        'code': "console.log('Hello World!');\nconsole.log('Welcome to John Louis Uru`s JS Platform');"
    },
    'variables': {
        'title': 'Variables',
        'code': "let name = 'John';\nconst age = 25;\nconsole.log('Name:', name);\nconsole.log('Age:', age);"
    },
    'functions': {
        'title': 'Functions',
        'code': "function greet(name) {\n    return 'Hello, ' + name;\n}\nconsole.log(greet('Alice'));"
    },
    'arrays': {
        'title': 'Arrays',
        'code': "let numbers = [1, 2, 3, 4, 5];\nconsole.log('Array:', numbers);\nconsole.log('First item:', numbers[0]);"
    },
    'objects': {
        'title': 'Objects',
        'code': "let person = {\n    name: 'John',\n    age: 30,\n    city: 'New York'\n};\nconsole.log('Person:', person);\nconsole.log('Name:', person.name);"
    },
    'loops': {
        'title': 'Loops',
        'code': "for (let i = 1; i <= 5; i++) {\n    console.log('Count:', i);\n}\nlet fruits = ['Apple', 'Banana', 'Orange'];\nfor (let fruit of fruits) {\n    console.log('Fruit:', fruit);\n}"
    },
    'conditionals': {
        'title': 'Conditionals',
        'code': "let age = 18;\nif (age >= 18) {\n    console.log('Adult');\n} else {\n    console.log('Minor');\n}\nlet score = 85;\nif (score >= 90) {\n    console.log('A');\n} else if (score >= 80) {\n    console.log('B');\n} else {\n    console.log('C or below');\n}"
    }
};
(function() {
    const editorContainer = document.querySelector('.editor-container');
    const statusText = document.getElementById('statusText');
    const statusBadge = document.getElementById('statusBadge');
    const consoleOutput = document.getElementById('consoleOutput');
    const statusIndicator = document.getElementById('statusIndicator');
    const fileNameInput = document.getElementById('fileName');
    const lastRunElement = document.getElementById('lastRun');
    const fileCountElement = document.getElementById('fileCount');
    const executionTimeElement = document.getElementById('executionTime');

    let editor = null;
    let usingMonaco = false;
    let currentFile = "practice.js";

    // Helper: Write to console output
    function appendConsole(msg, color) {
        const d = document.createElement('div');
        if (color) d.style.color = color;
        d.textContent = msg;
        consoleOutput.appendChild(d);
        consoleOutput.scrollTop = consoleOutput.scrollHeight;
    }

    function clearConsole() {
        consoleOutput.innerHTML = '<div class="text-muted">Console cleared</div>';
        executionTimeElement.textContent = '';
        statusIndicator.className = 'status-dot';
    }

    // Update file list from localStorage
    function updateFileList() {
        const files = JSON.parse(localStorage.getItem('jsPracticeFiles') || '[]');
        const fileList = document.getElementById('fileList');
        
        fileCountElement.textContent = files.length;
        
        if (files.length === 0) {
            fileList.innerHTML = '<div class="text-muted small">No saved files yet</div>';
            return;
        }
        
        let html = '';
        files.forEach((file, index) => {
            const time = new Date(file.saved).toLocaleTimeString([], {hour: '2-digit', minute:'2-digit'});
            const date = new Date(file.saved).toLocaleDateString();
            html += '<div class="sidebar-item" onclick="openFile(' + index + ')">' +
                    '<div>' +
                    '<i class="fas fa-file-code me-2"></i>' +
                    file.name +
                    '</div>' +
                    '<div class="file-time">' + time + ' - ' + date + '</div>' +
                    '</div>';
        });
        
        fileList.innerHTML = html;
    }

    // Open a saved file
    window.openFile = function(index) {
        const files = JSON.parse(localStorage.getItem('jsPracticeFiles') || '[]');
        if (files[index]) {
            const file = files[index];
            if (editor && typeof editor.setValue === 'function') {
                editor.setValue(file.content);
            } else if (editor && editor.getValue) {
                editor.setValue(file.content);
            }
            fileNameInput.value = file.name;
            currentFile = file.name;
            
            showToast('Opened: ' + file.name, 'info');
            
            // Close sidebar on mobile
            if (window.innerWidth < 992) {
                document.getElementById('sidebar').classList.remove('open');
            }
        }
    };

    // Save current file
    window.saveFile = function() {
        if (!currentFile || currentFile === 'practice.js') {
            currentFile = prompt('Enter JavaScript filename:', 'script.js') || 'script.js';
            if (!currentFile.endsWith('.js')) {
                currentFile += '.js';
            }
            fileNameInput.value = currentFile;
        }
        
        const content = editor && typeof editor.getValue === 'function' ? editor.getValue() : '';
        
        if (!content.trim()) {
            showToast('Cannot save empty file', 'warning');
            return;
        }
        
        // Create file object
        const fileObj = {
            name: currentFile,
            content: content,
            saved: new Date().toISOString()
        };
        
        // Load existing files
        let files = JSON.parse(localStorage.getItem('jsPracticeFiles') || '[]');
        
        // Update or add file
        const index = files.findIndex(f => f.name === currentFile);
        if (index >= 0) {
            files[index] = fileObj;
        } else {
            files.unshift(fileObj);
        }
        
        // Keep only last 20 files
        files = files.slice(0, 20);
        
        // Save to localStorage
        localStorage.setItem('jsPracticeFiles', JSON.stringify(files));
        
        // Update UI
        updateFileList();
        showToast('File saved: ' + currentFile, 'success');
    };

    // Create new file
    window.createNewFile = function() {
        const defaultName = 'script_' + new Date().getTime() + '.js';
        const fileName = prompt('Enter JavaScript filename:', defaultName);
        
        if (fileName) {
            let finalName = fileName;
            if (!finalName.endsWith('.js')) {
                finalName += '.js';
            }
            
            // Set template
            const template = '// ' + finalName + '\n' +
                            '// Created on ' + new Date().toLocaleDateString() + '\n\n' +
                            'console.log("Hello from ' + finalName + '!");\n\n' +
                            '// Your JavaScript code goes here\n\n' +
                            'function example() {\n' +
                            '    return "Hello World";\n' +
                            '}\n\n' +
                            'console.log(example());';
            
            if (editor && typeof editor.setValue === 'function') {
                editor.setValue(template);
            } else if (editor && editor.getValue) {
                editor.setValue(template);
            }
            
            fileNameInput.value = finalName;
            currentFile = finalName;
            
            showToast('Created: ' + finalName, 'success');
        }
    };

    // Load JavaScript example
    window.loadExample = function(exampleId) {
        if (window.jsExamples && window.jsExamples[exampleId]) {
            const example = window.jsExamples[exampleId];
            if (editor && typeof editor.setValue === 'function') {
                editor.setValue(example.code);
            } else if (editor && editor.getValue) {
                editor.setValue(example.code);
            }
            
            const exampleName = 'example_' + exampleId + '.js';
            fileNameInput.value = exampleName;
            currentFile = exampleName;
            
            showToast('Loaded: ' + example.title, 'info');
            
            // Close sidebar on mobile
            if (window.innerWidth < 992) {
                document.getElementById('sidebar').classList.remove('open');
            }
        }
    };

    // Fallback editor shim: create a textarea
    function setupFallbackEditor() {
        statusText.textContent = 'Fallback Editor';
        statusBadge.className = 'badge bg-warning';
        statusBadge.textContent = 'Fallback Mode';
        
        editorContainer.innerHTML = '';
        const ta = document.createElement('textarea');
        ta.id = 'fallbackEditor';
        ta.placeholder = '// Monaco failed to load. Type JavaScript here and click Run.\n// Try these examples:\n// console.log("Hello from fallback");\n// const numbers = [1, 2, 3];\n// console.log(numbers.map(n => n * 2));';
        
        // Escape the PHP variable properly for JavaScript
        const username = '<?php echo addslashes($username); ?>';
        ta.value = '// Welcome to JavaScript Practice Area\n' +
                  'console.log("Hello, ' + username + '!");\n\n' +
                  '// Try changing this code\n' +
                  'const numbers = [1, 2, 3, 4, 5];\n' +
                  'const sum = numbers.reduce((a, b) => a + b, 0);\n' +
                  'console.log("Sum of numbers:", sum);\n\n' +
                  '// Click examples in sidebar to load different concepts';
        
        editorContainer.appendChild(ta);

        // Shim object with Monaco-like API
        editor = {
            getValue: function() { return ta.value; },
            setValue: function(v) { ta.value = v; }
        };
        usingMonaco = false;
    }

    // If Monaco loads, create the editor instance
    function createMonacoEditor(monaco) {
        try {
            editorContainer.innerHTML = '';
            
            // Escape the PHP variable properly
            const username = '<?php echo addslashes($username); ?>';
            const initialCode = '// Welcome to JavaScript Practice Area! 🚀\n' +
                              'console.log("Hello, ' + username + '!");\n\n' +
                              '// Try changing this code\n' +
                              'const numbers = [1, 2, 3, 4, 5];\n' +
                              'const sum = numbers.reduce((a, b) => a + b, 0);\n' +
                              'console.log("Sum of numbers:", sum);\n\n' +
                              '// Click examples in sidebar to load different concepts\n' +
                              '// Press Ctrl+Enter to run code quickly\n' +
                              '// Press Ctrl+S to save your work';
            
            editor = monaco.editor.create(editorContainer, {
                value: initialCode,
                language: 'javascript',
                theme: 'vs-dark',
                automaticLayout: true,
                fontSize: 14,
                minimap: { enabled: false },
                scrollBeyondLastLine: false,
                wordWrap: 'on'
            });
            
            usingMonaco = true;
            statusText.textContent = 'Monaco Editor Ready';
            statusBadge.className = 'badge bg-success';
            statusBadge.textContent = 'Monaco Active';
            
            // Add keyboard shortcuts
            editor.addCommand(monaco.KeyMod.CtrlCmd | monaco.KeyCode.Enter, function() {
                runCode();
            });
            
            editor.addCommand(monaco.KeyMod.CtrlCmd | monaco.KeyCode.KeyS, function() {
                saveFile();
            });
            
        } catch (err) {
            console.warn('Monaco create failed:', err);
            setupFallbackEditor();
        }
    }

    // Try to load Monaco AMD loader dynamically with timeout
    function loadMonacoWithTimeout() {
        const monacoVersion = '0.45.0';
        const loaderUrl = 'https://cdn.jsdelivr.net/npm/monaco-editor@' + monacoVersion + '/min/vs/loader.js';
        let timedOut = false;
        const timeoutMs = 8000;

        const script = document.createElement('script');
        script.src = loaderUrl;
        script.onload = function() {
            if (timedOut) return;
            try {
                require.config({ 
                    paths: { 
                        vs: 'https://cdn.jsdelivr.net/npm/monaco-editor@' + monacoVersion + '/min/vs'
                    } 
                });
                
                require(['vs/editor/editor.main'], function() {
                    if (window.monaco) {
                        createMonacoEditor(window.monaco);
                    } else {
                        console.warn('window.monaco not found');
                        setupFallbackEditor();
                    }
                }, function(err) {
                    console.error('Monaco require error:', err);
                    setupFallbackEditor();
                });
            } catch (e) {
                console.error('Monaco config error:', e);
                setupFallbackEditor();
            }
        };
        
        script.onerror = function() {
            console.error('Failed to load Monaco loader');
            if (!timedOut) setupFallbackEditor();
        };
        
        document.head.appendChild(script);

        // Timeout fallback
        setTimeout(function() {
            timedOut = true;
            if (!editor) {
                console.warn('Monaco load timed out');
                setupFallbackEditor();
            }
        }, timeoutMs);
    }

    // Run JavaScript code
    window.runCode = function() {
        const startTime = performance.now();
        clearConsole();
        
        // Update status
        statusIndicator.className = 'status-dot status-running';
        
        // Get code from editor
        let userCode = '';
        if (editor && typeof editor.getValue === 'function') {
            userCode = editor.getValue();
        } else if (editor && editor.getValue) {
            userCode = editor.getValue();
        }
        
        if (!userCode || !userCode.trim()) {
            appendConsole('⚠ Nothing to run (editor empty).', 'orange');
            statusIndicator.className = 'status-dot status-error';
            return;
        }
        
        // Intercept console methods
        const originalLog = console.log;
        const originalError = console.error;
        const originalWarn = console.warn;
        const originalInfo = console.info;
        
        console.log = function(...args) {
            appendConsole(args.map(function(arg) {
                return typeof arg === 'object' ? JSON.stringify(arg, null, 2) : String(arg);
            }).join(' '));
            originalLog.apply(console, args);
        };
        
        console.error = function(...args) {
            appendConsole('❌ ERROR: ' + args.join(' '), '#fc8181');
            originalError.apply(console, args);
        };
        
        console.warn = function(...args) {
            appendConsole('⚠ WARNING: ' + args.join(' '), '#f6e05e');
            originalWarn.apply(console, args);
        };
        
        console.info = function(...args) {
            appendConsole('ℹ INFO: ' + args.join(' '), '#63b3ed');
            originalInfo.apply(console, args);
        };
        
        try {
            // Execute the code
            new Function(userCode)();
            
            // Calculate and display execution time
            const execTime = performance.now() - startTime;
            executionTimeElement.textContent = execTime.toFixed(2) + 'ms';
            
            // Update status
            statusIndicator.className = 'status-dot status-success';
            
            // Update last run time
            const now = new Date();
            lastRunElement.textContent = now.toLocaleTimeString([], {hour: '2-digit', minute:'2-digit', second:'2-digit'});
            
            // Auto-save if it's a named file
            if (currentFile && currentFile !== 'practice.js') {
                setTimeout(saveFile, 100); // Save after a short delay
            }
            
        } catch (err) {
            const execTime = performance.now() - startTime;
            executionTimeElement.textContent = execTime.toFixed(2) + 'ms (Error)';
            statusIndicator.className = 'status-dot status-error';
            appendConsole('❌ JavaScript Error: ' + err.message, '#fc8181');
            console.error('Execution error:', err);
        } finally {
            // Restore original console methods
            console.log = originalLog;
            console.error = originalError;
            console.warn = originalWarn;
            console.info = originalInfo;
        }
    };

    // Format code
    window.formatCode = function() {
        if (usingMonaco) {
            editor.getAction('editor.action.formatDocument').run();
            showToast('Code formatted', 'success');
        } else {
            // Simple formatting for fallback editor
            const ta = document.getElementById('fallbackEditor');
            if (ta) {
                showToast('Formatting not available in fallback mode', 'info');
            }
        }
    };

    // Clear output
    window.clearOutput = clearConsole;

    // Toggle output panel
    window.toggleOutput = function() {
        const panel = document.querySelector('.output-panel');
        const icon = document.getElementById('outputToggleIcon');
        
        if (panel.style.height === '40px' || panel.classList.contains('collapsed')) {
            panel.style.height = '200px';
            panel.classList.remove('collapsed');
            icon.className = 'fas fa-chevron-up';
        } else {
            panel.style.height = '40px';
            panel.classList.add('collapsed');
            icon.className = 'fas fa-chevron-down';
        }
    };

    // Show toast notification
    function showToast(message, type) {
        // Remove existing toasts
        const existing = document.querySelector('.toast-container');
        if (existing) existing.remove();
        
        const icons = {
            'info': 'info-circle',
            'success': 'check-circle',
            'error': 'exclamation-circle',
            'warning': 'exclamation-triangle'
        };
        
        const icon = icons[type] || 'info-circle';
        const toastId = 'toast-' + Date.now();
        const toastHtml = '<div class="toast-container position-fixed top-0 end-0 p-3">' +
                         '<div id="' + toastId + '" class="toast align-items-center text-bg-' + type + ' border-0" role="alert">' +
                         '<div class="d-flex">' +
                         '<div class="toast-body">' +
                         '<i class="fas fa-' + icon + ' me-2"></i>' +
                         message +
                         '</div>' +
                         '<button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button>' +
                         '</div>' +
                         '</div>' +
                         '</div>';
        
        document.body.insertAdjacentHTML('beforeend', toastHtml);
        const toastElement = document.getElementById(toastId);
        const toast = new bootstrap.Toast(toastElement, { delay: 3000 });
        toast.show();
        
        // Remove after hiding
        toastElement.addEventListener('hidden.bs.toast', function() {
            this.closest('.toast-container').remove();
        });
    }

    // Toggle sidebar on mobile
    document.getElementById('sidebarToggle').addEventListener('click', function() {
        document.getElementById('sidebar').classList.toggle('open');
    });

    // Close sidebar when clicking outside on mobile
    document.addEventListener('click', function(e) {
        if (window.innerWidth < 992) {
            const sidebar = document.getElementById('sidebar');
            const toggleBtn = document.getElementById('sidebarToggle');
            if (!sidebar.contains(e.target) && !toggleBtn.contains(e.target) && sidebar.classList.contains('open')) {
                sidebar.classList.remove('open');
            }
        }
    });

    // Handle window resize
    window.addEventListener('resize', function() {
        // Refresh Monaco editor if it exists
        if (editor && editor.layout) {
            setTimeout(function() { editor.layout(); }, 100);
        }
        
        // Auto-close sidebar on mobile when resizing to desktop
        if (window.innerWidth >= 992) {
            document.getElementById('sidebar').classList.remove('open');
        }
    });

    // Initialize
    function init() {
        // Start loading Monaco
        statusText.textContent = 'Loading Monaco Editor...';
        statusBadge.className = 'badge bg-info';
        loadMonacoWithTimeout();
        
        // Load saved files
        updateFileList();
        
        // Set default filename
        fileNameInput.value = currentFile;
        
        // Set up filename change handler
        fileNameInput.addEventListener('change', function() {
            currentFile = this.value || 'practice.js';
            if (!currentFile.endsWith('.js')) {
                currentFile += '.js';
                this.value = currentFile;
            }
        });
        
        // Show file protocol warning if needed
        if (location.protocol === 'file:') {
            const warning = document.createElement('div');
            warning.className = 'alert alert-warning mt-3';
            warning.innerHTML = '<i class="fas fa-exclamation-triangle me-2"></i>' +
                               '<strong>Note:</strong> Opening this page as <code>file://</code> may prevent Monaco Editor from loading. ' +
                               'For best results, run a local HTTP server (e.g., <code>php -S localhost:8000</code> in your project folder).';
            document.querySelector('.content-area').prepend(warning);
        }
    }

    // Start the application
    init();

// Add event listeners to sidebar examples using data attributes
document.querySelectorAll('#exampleList .sidebar-item[data-example]').forEach(item => {
    item.addEventListener('click', function() {
        const exampleId = this.getAttribute('data-example');
        console.log("Loading example from data attribute:", exampleId);
        loadExample(exampleId);
    });
});

    // Export functions to global scope for onclick handlers
    window.runCode = runCode;
    window.saveFile = saveFile;
    window.formatCode = formatCode;
    window.clearOutput = clearOutput;
    window.toggleOutput = toggleOutput;
    window.createNewFile = createNewFile;
    window.loadExample = loadExample;
    window.openFile = openFile;
})();
</script>

    <!-- Bootstrap JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>