<?php
require_once 'database.php';

$db = new Database();
$conn = $db->getConnection();
$studentSession = new StudentSession($conn);

if (!$studentSession->isLoggedIn()) {
    header('Location: index.php');
    exit;
}
?>
<!DOCTYPE html>
<html>
<head>
    <title>Fixed Code Editor</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.5/codemirror.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.5/theme/dracula.min.css">
    <style>
        body {
            margin: 0;
            padding: 0;
            height: 100vh;
        }
        
        #editor-container {
            height: calc(100vh - 56px);
            display: flex;
            flex-direction: column;
        }
        
        .toolbar {
            background: #343a40;
            padding: 10px;
            color: white;
        }
        
        .CodeMirror {
            flex: 1;
            height: auto !important;
            font-size: 14px;
        }
    </style>
</head>
<body>
    <nav class="navbar navbar-dark bg-dark">
        <div class="container-fluid">
            <span class="navbar-brand">Code Editor</span>
        </div>
    </nav>
    
    <div id="editor-container">
        <div class="toolbar">
            <button class="btn btn-sm btn-success" onclick="runCode()">Run</button>
            <button class="btn btn-sm btn-primary" onclick="saveCode()">Save</button>
        </div>
        
        <textarea id="code">// Working Code Editor
console.log("Editor is working!");

// Add your code here
function calculate(a, b) {
    return a + b;
}

console.log("5 + 3 =", calculate(5, 3));

// Arrays
const numbers = [1, 2, 3, 4, 5];
const sum = numbers.reduce((total, num) => total + num, 0);
console.log("Sum of numbers:", sum);</textarea>
    </div>

    <script src="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.5/codemirror.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.5/mode/javascript/javascript.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.5/mode/htmlmixed/htmlmixed.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.5/mode/css/css.min.js"></script>
    
    <script>
        // Simple initialization
        const editor = CodeMirror.fromTextArea(document.getElementById('code'), {
            lineNumbers: true,
            mode: "javascript",
            theme: "dracula",
            lineWrapping: true,
            matchBrackets: true,
            autoCloseBrackets: true,
            extraKeys: {"Ctrl-Enter": runCode}
        });
        
        // Force refresh
        setTimeout(() => editor.refresh(), 100);
        
        function runCode() {
            const code = editor.getValue();
            try {
                const originalLog = console.log;
                let output = '';
                console.log = function(...args) {
                    output += args.join(' ') + '\n';
                };
                
                eval(code);
                console.log = originalLog;
                
                alert('Output:\n' + (output || 'Code executed (no output)'));
            } catch(e) {
                alert('Error: ' + e.message);
            }
        }
        
        function saveCode() {
            const code = editor.getValue();
            localStorage.setItem('savedCode', code);
            alert('Code saved to localStorage');
        }
        
        // Load saved code
        const saved = localStorage.getItem('savedCode');
        if (saved) {
            editor.setValue(saved);
        }
    </script>
</body>
</html>