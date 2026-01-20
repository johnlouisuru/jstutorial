<?php
// Debug version
?>
<!DOCTYPE html>
<html>
<head>
    <title>Debug Code Editor</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.5/codemirror.min.css">
    <style>
        body, html {
            margin: 0;
            padding: 0;
            height: 100%;
            overflow: hidden;
        }
        
        #debug {
            position: fixed;
            top: 10px;
            right: 10px;
            background: #f8f9fa;
            border: 1px solid #dee2e6;
            padding: 10px;
            z-index: 1000;
            font-size: 12px;
        }
        
        .CodeMirror {
            height: 100vh !important;
            font-size: 14px;
        }
    </style>
</head>
<body>
    <div id="debug">
        <button onclick="testEditor()">Test Editor</button>
        <div id="debugInfo"></div>
    </div>
    
    <textarea id="code">// Debug Editor
console.log("Testing...");

function debugTest() {
    return "Debug working!";
}

console.log(debugTest());</textarea>

    <script src="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.5/codemirror.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.5/mode/javascript/javascript.min.js"></script>
    
    <script>
        console.log("Starting editor initialization...");
        
        var editor = CodeMirror.fromTextArea(document.getElementById('code'), {
            lineNumbers: true,
            mode: "javascript",
            theme: "default",
            lineWrapping: true,
            autoRefresh: true,
            matchBrackets: true,
            autoCloseBrackets: true
        });
        
        console.log("Editor created:", editor);
        
        // Test functions
        function testEditor() {
            const value = editor.getValue();
            const info = document.getElementById('debugInfo');
            info.innerHTML = `
                <div>Editor exists: ${editor ? 'YES' : 'NO'}</div>
                <div>Content length: ${value.length}</div>
                <div>First 50 chars: ${value.substring(0, 50)}</div>
            `;
            
            // Try to run the code
            try {
                eval(value);
            } catch(e) {
                console.error("Execution error:", e);
            }
        }
        
        // Force refresh
        setTimeout(function() {
            console.log("Refreshing editor...");
            editor.refresh();
            editor.focus();
            testEditor();
        }, 500);

        // DEBUG: Check what's happening
console.log("=== DEBUG START ===");
console.log("Editor object:", window.editor);
console.log("Editor type:", typeof window.editor);
console.log("CodeMirror version:", CodeMirror.version);

// Check if editor is properly initialized
if (window.editor) {
    console.log("Editor has getValue method:", typeof window.editor.getValue);
    console.log("Current content:", window.editor.getValue().substring(0, 100));
    
    // Try to force refresh
    setTimeout(() => {
        console.log("Forcing refresh...");
        window.editor.refresh();
        
        // Check the textarea
        const textarea = document.getElementById('codeEditor');
        console.log("Textarea exists:", !!textarea);
        console.log("Textarea content:", textarea ? textarea.value.substring(0, 100) : "N/A");
        
        // Check CodeMirror wrapper
        const cmWrapper = document.querySelector('.CodeMirror');
        console.log("CodeMirror wrapper exists:", !!cmWrapper);
        console.log("CodeMirror wrapper HTML:", cmWrapper ? cmWrapper.outerHTML.substring(0, 200) : "N/A");
        
    }, 1000);
} else {
    console.error("Editor not initialized!");
    
    // Try to initialize it now
    setTimeout(() => {
        console.log("Attempting to initialize editor...");
        const textarea = document.getElementById('codeEditor');
        if (textarea) {
            window.editor = CodeMirror.fromTextArea(textarea, {
                lineNumbers: true,
                mode: "javascript",
                theme: "default"
            });
            console.log("Re-initialized editor:", window.editor);
        }
    }, 1000);
}

console.log("=== DEBUG END ===");
    </script>
</body>
</html>