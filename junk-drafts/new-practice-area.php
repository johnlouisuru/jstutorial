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
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>JavaScript Practice Area | Interactive Learning Platform</title>
    
    <!-- Bootstrap 5 -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    
    <!-- CodeMirror -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.5/codemirror.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.5/theme/dracula.min.css">
    
    <style>
        :root {
            --primary-color: #4361ee;
            --secondary-color: #3a0ca3;
        }
        
        body {
            margin: 0;
            padding: 0;
            height: 100vh;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: #f5f7fb;
            overflow: hidden;
        }
        
        #app {
            height: 100vh;
            display: flex;
            flex-direction: column;
        }
        
        /* Navbar */
        .navbar {
            background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            height: 56px;
            flex-shrink: 0;
        }
        
        /* Main Container */
        .main-container {
            flex: 1;
            display: flex;
            overflow: hidden;
        }
        
        /* Sidebar */
        .sidebar {
            width: 280px;
            background: #2d3748;
            color: white;
            transition: transform 0.3s ease;
            overflow-y: auto;
            flex-shrink: 0;
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
            background: white;
        }
        
        /* Editor Container */
        .editor-wrapper {
            flex: 1;
            position: relative;
            overflow: hidden;
        }
        
        .editor-wrapper .CodeMirror {
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            height: 100% !important;
            font-size: 14px;
            font-family: 'Fira Code', 'Consolas', 'Courier New', monospace;
        }
        
        /* Toolbar */
        .toolbar {
            background: #343a40;
            padding: 10px 15px;
            display: flex;
            gap: 10px;
            align-items: center;
            border-bottom: 1px solid #495057;
            flex-shrink: 0;
        }
        
        /* Output Panel */
        .output-panel {
            height: 200px;
            background: #1a1a1a;
            color: white;
            border-top: 2px solid #495057;
            display: flex;
            flex-direction: column;
            flex-shrink: 0;
        }
        
        .output-header {
            background: #343a40;
            padding: 8px 15px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            border-bottom: 1px solid #495057;
        }
        
        .output-content {
            flex: 1;
            padding: 12px;
            overflow-y: auto;
            font-family: 'Consolas', 'Courier New', monospace;
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
        
        /* Status Indicators */
        .status-dot {
            width: 10px;
            height: 10px;
            border-radius: 50%;
            display: inline-block;
            margin-right: 8px;
        }
        
        .status-running { background: #48bb78; animation: pulse 1.5s infinite; }
        .status-error { background: #f56565; }
        .status-success { background: #48bb78; }
        
        @keyframes pulse {
            0% { opacity: 1; }
            50% { opacity: 0.5; }
            100% { opacity: 1; }
        }
        
        /* Mobile Responsive */
        @media (max-width: 992px) {
            .sidebar {
                position: fixed;
                left: 0;
                top: 56px;
                bottom: 0;
                transform: translateX(-100%);
                z-index: 1000;
            }
            
            .sidebar.open {
                transform: translateX(0);
            }
            
            .sidebar-toggle {
                display: flex !important;
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
        
        /* Output colors */
        .output-success { color: #68d391; }
        .output-error { color: #fc8181; }
        .output-info { color: #63b3ed; }
        
        /* Code snippet styling */
        .code-example {
            background: #2d3748;
            border-radius: 5px;
            padding: 10px;
            margin: 10px 0;
            font-family: 'Consolas', monospace;
            font-size: 12px;
            cursor: pointer;
            transition: all 0.2s;
        }
        
        .code-example:hover {
            background: #4a5568;
        }
    </style>
</head>
<body>
    <div id="app">
        <!-- Navbar -->
        <nav class="navbar navbar-dark">
            <div class="container-fluid">
                <a class="navbar-brand" href="#">
                    <i class="fab fa-js-square me-2"></i>JavaScript Practice Area
                </a>
                <button class="sidebar-toggle" id="sidebarToggle">
                    <i class="fas fa-bars"></i>
                </button>
                <div class="d-flex align-items-center">
                    <span class="text-white me-3">Welcome, <?php echo htmlspecialchars($username); ?></span>
                    <a href="dashboard.php" class="btn btn-sm btn-outline-light">
                        <i class="fas fa-arrow-left me-1"></i> Dashboard
                    </a>
                </div>
            </div>
        </nav>

        <div class="main-container">
            <!-- Sidebar -->
            <div class="sidebar" id="sidebar">
                <div class="sidebar-content">
                    <!-- Files Section -->
                    <h6 class="mb-3">
                        <i class="fas fa-folder me-2"></i>My JavaScript Files
                    </h6>
                    <div id="fileList" class="mb-4">
                        <div class="text-muted small">No saved files yet</div>
                    </div>
                    <button class="btn btn-sm btn-outline-light w-100 mb-4" onclick="createNewFile()">
                        <i class="fas fa-plus me-1"></i> New JavaScript File
                    </button>
                    
                    <!-- JavaScript Examples -->
                    <h6 class="mb-3">
                        <i class="fas fa-code me-2"></i>JavaScript Examples
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
                        <div class="sidebar-item" onclick="loadExample('dom-manipulation')">
                            <i class="fas fa-window-restore me-2"></i>DOM Manipulation
                        </div>
                    </div>
                    
                    <!-- Quick Tips -->
                    <div class="border-top pt-3 mt-3">
                        <h6 class="mb-2">
                            <i class="fas fa-lightbulb me-2"></i>Quick Tips
                        </h6>
                        <div class="small text-muted">
                            <p><i class="fas fa-keyboard me-2"></i><strong>Ctrl+Enter</strong> - Run Code</p>
                            <p><i class="fas fa-keyboard me-2"></i><strong>Ctrl+S</strong> - Save File</p>
                            <p><i class="fas fa-keyboard me-2"></i><strong>Ctrl+F</strong> - Format Code</p>
                            <p><i class="fas fa-mouse-pointer me-2"></i>Click examples to load them</p>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Main Content -->
            <div class="content-area">
                <!-- Toolbar -->
                <div class="toolbar">
                    <div class="d-flex align-items-center gap-3">
                        <span class="text-white">
                            <i class="fab fa-js me-2"></i>JavaScript
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
                        <button class="btn btn-sm btn-outline-light" onclick="formatCode()" title="Format Code (Ctrl+F)">
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

                <!-- Editor -->
                <div class="editor-wrapper">
                    <textarea id="codeEditor"></textarea>
                </div>

                <!-- Output Panel -->
                <div class="output-panel" id="outputPanel">
                    <div class="output-header">
                        <span>
                            <i class="fas fa-terminal me-2"></i> Console Output
                            <span class="status-dot" id="statusIndicator"></span>
                        </span>
                        <div>
                            <small class="text-muted me-3" id="executionTime"></small>
                            <button class="btn btn-sm btn-outline-light" onclick="toggleOutput()">
                                <i class="fas fa-chevron-up" id="outputToggleIcon"></i>
                            </button>
                        </div>
                    </div>
                    <div class="output-content" id="outputContent">
                        <div class="text-muted">JavaScript output will appear here...</div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Scripts -->
    <script src="https://code.jquery.com/jquery-3.6.4.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    
    <!-- CodeMirror -->
    <script src="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.5/codemirror.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.5/mode/javascript/javascript.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.5/addon/edit/matchbrackets.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.5/addon/edit/closebrackets.min.js"></script>
    
    <!-- Prettier -->
    <script src="https://cdnjs.cloudflare.com/ajax/libs/prettier/2.8.8/standalone.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/prettier/2.8.8/parser-babel.min.js"></script>

    <script>
        // Global variables
        let editor = null;
        let currentFile = "practice.js";
        
        // JavaScript Examples Database
        const jsExamples = {
            'hello-world': {
                title: 'Hello World & Basics',
                code: `// JavaScript Hello World
console.log("Hello, World!");

// Variables (let, const, var)
let message = "Welcome to JavaScript!";
const PI = 3.14159;
var oldWay = "Avoid using var";

console.log(message);
console.log("PI value:", PI);

// Data Types
const stringType = "I'm a string";
const numberType = 42;
const booleanType = true;
const nullType = null;
let undefinedType;
const objectType = { name: "John", age: 30 };
const arrayType = [1, 2, 3, 4, 5];

console.log("String:", stringType);
console.log("Number:", numberType);
console.log("Boolean:", booleanType);
console.log("Object:", objectType);
console.log("Array:", arrayType);`
            },
            
            'variables': {
                title: 'Variables & Data Types',
                code: `// JavaScript Variables and Data Types

// 1. Variable Declaration
let name = "Alice";
const age = 25;
var country = "USA"; // Old way, not recommended

// 2. Data Types
console.log("=== Data Types ===");

// Primitive Types
const stringVar = "Hello, JavaScript!";
const numberVar = 3.14159;
const booleanVar = true;
const nullVar = null;
let undefinedVar;
const symbolVar = Symbol('unique');

console.log("String:", stringVar);
console.log("Number:", numberVar);
console.log("Boolean:", booleanVar);
console.log("Null:", nullVar);
console.log("Undefined:", undefinedVar);

// 3. Type Conversion
console.log("\\n=== Type Conversion ===");
const strNumber = "123";
const num = Number(strNumber);
console.log("String to Number:", num, "Type:", typeof num);

const numToString = 456;
const str = String(numToString);
console.log("Number to String:", str, "Type:", typeof str);

const truthy = Boolean(1);
const falsy = Boolean(0);
console.log("Boolean conversion - 1:", truthy, "0:", falsy);

// 4. Template Literals
console.log("\\n=== Template Literals ===");
const firstName = "John";
const lastName = "Doe";
const fullName = \`\${firstName} \${lastName}\`;
const message = \`Hello, \${fullName}! You are \${age} years old.\`;
console.log(message);

// 5. Constants vs Let
console.log("\\n=== Let vs Const ===");
let counter = 0;
counter = 1; // This works
console.log("Counter (let):", counter);

const MAX_SIZE = 100;
// MAX_SIZE = 200; // This would cause an error!
console.log("MAX_SIZE (const):", MAX_SIZE);

// 6. Variable Hoisting
console.log("\\n=== Hoisting Example ===");
console.log("x is:", x); // undefined (hoisted but not initialized)
var x = 5;
console.log("x is now:", x);

// console.log(y); // Error: Cannot access 'y' before initialization
let y = 10;
console.log("y is:", y);`
            },
            
            'functions': {
                title: 'Functions',
                code: `// JavaScript Functions

console.log("=== Function Examples ===");

// 1. Function Declaration
function greet(name) {
    return \`Hello, \${name}!\`;
}
console.log("Function declaration:", greet("Alice"));

// 2. Function Expression
const add = function(a, b) {
    return a + b;
};
console.log("Function expression - Add 5 + 3:", add(5, 3));

// 3. Arrow Function (ES6+)
const multiply = (a, b) => a * b;
console.log("Arrow function - Multiply 4 * 7:", multiply(4, 7));

// 4. Default Parameters
function createUser(name, age = 18, isActive = true) {
    return {
        name: name,
        age: age,
        isActive: isActive,
        createdAt: new Date().toISOString()
    };
}
console.log("Default parameters:", createUser("Bob"));

// 5. Rest Parameters
function sumAll(...numbers) {
    return numbers.reduce((total, num) => total + num, 0);
}
console.log("Rest parameters - Sum 1-5:", sumAll(1, 2, 3, 4, 5));

// 6. Higher-Order Functions
function createMultiplier(multiplier) {
    return function(number) {
        return number * multiplier;
    };
}
const double = createMultiplier(2);
const triple = createMultiplier(3);
console.log("Higher-order - Double 8:", double(8));
console.log("Higher-order - Triple 8:", triple(8));

// 7. Immediately Invoked Function Expression (IIFE)
(function() {
    console.log("IIFE executed immediately!");
})();

// 8. Callback Functions
function processData(data, callback) {
    console.log("Processing data:", data);
    const result = data * 2;
    callback(result);
}

processData(10, function(result) {
    console.log("Callback received result:", result);
});

// 9. Recursive Function
function factorial(n) {
    if (n <= 1) return 1;
    return n * factorial(n - 1);
}
console.log("Recursive - Factorial of 5:", factorial(5));

// 10. Function as Object Property
const calculator = {
    add: (a, b) => a + b,
    subtract: (a, b) => a - b,
    multiply: (a, b) => a * b,
    divide: (a, b) => a / b
};
console.log("Object method - Calculator add:", calculator.add(10, 5));
console.log("Object method - Calculator multiply:", calculator.multiply(10, 5));`
            },
            
            'arrays': {
                title: 'Arrays',
                code: `// JavaScript Arrays

console.log("=== Array Operations ===");

// 1. Creating Arrays
const fruits = ["Apple", "Banana", "Orange"];
const numbers = [1, 2, 3, 4, 5];
const mixed = ["Hello", 42, true, null];
const empty = [];

console.log("Fruits array:", fruits);
console.log("Numbers array:", numbers);

// 2. Array Methods
console.log("\\n=== Array Methods ===");

// push/pop (end of array)
fruits.push("Mango");
console.log("After push:", fruits);
const lastFruit = fruits.pop();
console.log("Popped fruit:", lastFruit);
console.log("After pop:", fruits);

// shift/unshift (beginning of array)
fruits.unshift("Strawberry");
console.log("After unshift:", fruits);
const firstFruit = fruits.shift();
console.log("Shifted fruit:", firstFruit);
console.log("After shift:", fruits);

// 3. Iteration Methods
console.log("\\n=== Iteration Methods ===");

// forEach
console.log("forEach:");
fruits.forEach((fruit, index) => {
    console.log(\`\${index + 1}. \${fruit}\`);
});

// map
const doubled = numbers.map(num => num * 2);
console.log("map - Doubled numbers:", doubled);

// filter
const evenNumbers = numbers.filter(num => num % 2 === 0);
console.log("filter - Even numbers:", evenNumbers);

// reduce
const sum = numbers.reduce((total, num) => total + num, 0);
console.log("reduce - Sum of numbers:", sum);

// 4. Finding Elements
console.log("\\n=== Finding Elements ===");
const found = fruits.find(fruit => fruit.startsWith("B"));
console.log("find - Fruit starting with B:", found);

const hasOrange = fruits.includes("Orange");
console.log("includes - Has Orange?", hasOrange);

const orangeIndex = fruits.indexOf("Orange");
console.log("indexOf - Orange position:", orangeIndex);

// 5. Array Transformation
console.log("\\n=== Array Transformation ===");
const sliced = fruits.slice(1, 3);
console.log("slice (1-3):", sliced);

const reversed = [...fruits].reverse();
console.log("reverse:", reversed);

const sorted = [...fruits].sort();
console.log("sort:", sorted);

// 6. Multi-dimensional Arrays
console.log("\\n=== Multi-dimensional Arrays ===");
const matrix = [
    [1, 2, 3],
    [4, 5, 6],
    [7, 8, 9]
];
console.log("Matrix:");
matrix.forEach(row => console.log(row));
console.log("Element at [1][2]:", matrix[1][2]);

// 7. Spread Operator
console.log("\\n=== Spread Operator ===");
const moreFruits = [...fruits, "Grapes", "Pineapple"];
console.log("Spread operator:", moreFruits);

// 8. Array Destructuring
console.log("\\n=== Array Destructuring ===");
const [first, second, ...rest] = fruits;
console.log("First fruit:", first);
console.log("Second fruit:", second);
console.log("Rest:", rest);

// 9. Useful Array Methods
console.log("\\n=== Other Useful Methods ===");
const joined = fruits.join(", ");
console.log("join:", joined);

const someEven = numbers.some(num => num % 2 === 0);
console.log("some - Has even numbers?", someEven);

const allPositive = numbers.every(num => num > 0);
console.log("every - All positive?", allPositive);

const flatArray = [1, [2, 3], [4, [5, 6]]].flat(2);
console.log("flat:", flatArray);`
            },
            
            'objects': {
                title: 'Objects',
                code: `// JavaScript Objects

console.log("=== Object Examples ===");

// 1. Creating Objects
const person = {
    firstName: "John",
    lastName: "Doe",
    age: 30,
    isStudent: false,
    address: {
        street: "123 Main St",
        city: "New York",
        zipCode: "10001"
    },
    hobbies: ["reading", "coding", "gaming"],
    
    // Method
    getFullName: function() {
        return \`\${this.firstName} \${this.lastName}\`;
    },
    
    // ES6 Method shorthand
    greet() {
        return \`Hello, my name is \${this.getFullName()}\`;
    }
};

console.log("Person object:", person);
console.log("Full name:", person.getFullName());
console.log("Greeting:", person.greet());

// 2. Accessing Properties
console.log("\\n=== Accessing Properties ===");
console.log("Dot notation - firstName:", person.firstName);
console.log("Bracket notation - lastName:", person["lastName"]);
console.log("Nested property - city:", person.address.city);

// 3. Adding/Modifying Properties
console.log("\\n=== Modifying Objects ===");
person.email = "john.doe@example.com";
person.age = 31;
console.log("After modifications:", person);

// 4. Object Methods
console.log("\\n=== Object Methods ===");
const keys = Object.keys(person);
console.log("Keys:", keys);

const values = Object.values(person);
console.log("Values:", values);

const entries = Object.entries(person);
console.log("Entries:", entries);

// 5. Object Destructuring
console.log("\\n=== Object Destructuring ===");
const { firstName, lastName, age } = person;
console.log("Destructured - firstName:", firstName);
console.log("Destructured - lastName:", lastName);
console.log("Destructured - age:", age);

const { address: { city, zipCode } } = person;
console.log("Nested destructuring - city:", city);
console.log("Nested destructuring - zipCode:", zipCode);

// 6. Spread Operator with Objects
console.log("\\n=== Spread Operator ===");
const updatedPerson = {
    ...person,
    age: 32,
    occupation: "Developer"
};
console.log("Spread operator result:", updatedPerson);

// 7. Object Constructor
console.log("\\n=== Object Constructor ===");
function Car(make, model, year) {
    this.make = make;
    this.model = model;
    this.year = year;
    
    this.getInfo = function() {
        return \`\${this.year} \${this.make} \${this.model}\`;
    };
}

const myCar = new Car("Toyota", "Camry", 2023);
console.log("Constructor object:", myCar);
console.log("Car info:", myCar.getInfo());

// 8. Classes (ES6)
console.log("\\n=== Classes ===");
class Animal {
    constructor(name, type) {
        this.name = name;
        this.type = type;
    }
    
    speak() {
        return \`\${this.name} makes a sound\`;
    }
}

class Dog extends Animal {
    constructor(name, breed) {
        super(name, "dog");
        this.breed = breed;
    }
    
    speak() {
        return \`\${this.name} barks!\`;
    }
    
    getDescription() {
        return \`\${this.name} is a \${this.breed}\`;
    }
}

const myDog = new Dog("Buddy", "Golden Retriever");
console.log("Dog object:", myDog);
console.log("Dog speaks:", myDog.speak());
console.log("Dog description:", myDog.getDescription());

// 9. Object Prototypes
console.log("\\n=== Prototypes ===");
String.prototype.capitalize = function() {
    return this.charAt(0).toUpperCase() + this.slice(1);
};
console.log("Prototype method - 'hello'.capitalize():", "hello".capitalize());`
            },
            
            'loops': {
                title: 'Loops',
                code: `// JavaScript Loops

console.log("=== Loop Examples ===");

const numbers = [10, 20, 30, 40, 50];
const person = {
    name: "Alice",
    age: 25,
    occupation: "Developer",
    city: "San Francisco"
};

// 1. For Loop
console.log("\\n=== For Loop ===");
console.log("Counting 1 to 5:");
for (let i = 1; i <= 5; i++) {
    console.log("Count:", i);
}

console.log("\\nArray elements:");
for (let i = 0; i < numbers.length; i++) {
    console.log(\`numbers[\${i}] = \${numbers[i]}\`);
}

// 2. While Loop
console.log("\\n=== While Loop ===");
let counter = 1;
console.log("Counting with while:");
while (counter <= 3) {
    console.log("Counter:", counter);
    counter++;
}

// 3. Do-While Loop
console.log("\\n=== Do-While Loop ===");
let countdown = 3;
console.log("Countdown:");
do {
    console.log(countdown);
    countdown--;
} while (countdown > 0);

// 4. For...of Loop (Arrays)
console.log("\\n=== For...of Loop (Arrays) ===");
console.log("Array values:");
for (const number of numbers) {
    console.log("Number:", number);
}

// 5. For...in Loop (Objects)
console.log("\\n=== For...in Loop (Objects) ===");
console.log("Object properties:");
for (const key in person) {
    console.log(\`\${key}: \${person[key]}\`);
}

// 6. forEach Loop (Array method)
console.log("\\n=== forEach Loop ===");
console.log("Array forEach:");
numbers.forEach((number, index) => {
    console.log(\`Index \${index}: \${number}\`);
});

// 7. Loop Control Statements
console.log("\\n=== Loop Control ===");
console.log("Break example (stop at 30):");
for (const number of numbers) {
    if (number === 30) {
        console.log("Found 30, breaking...");
        break;
    }
    console.log("Processing:", number);
}

console.log("\\nContinue example (skip 30):");
for (const number of numbers) {
    if (number === 30) {
        console.log("Skipping 30...");
        continue;
    }
    console.log("Processing:", number);
}

// 8. Nested Loops
console.log("\\n=== Nested Loops ===");
console.log("Multiplication table (1-3):");
for (let i = 1; i <= 3; i++) {
    for (let j = 1; j <= 3; j++) {
        console.log(\`\${i} x \${j} = \${i * j}\`);
    }
}

// 9. Loop Performance Tips
console.log("\\n=== Performance Tips ===");
const largeArray = Array.from({length: 1000}, (_, i) => i + 1);

console.time("forEach");
largeArray.forEach(n => n * 2);
console.timeEnd("forEach");

console.time("for loop");
for (let i = 0; i < largeArray.length; i++) {
    largeArray[i] * 2;
}
console.timeEnd("for loop");

console.time("for loop cached");
const length = largeArray.length;
for (let i = 0; i < length; i++) {
    largeArray[i] * 2;
}
console.timeEnd("for loop cached");

// 10. Practical Example: Finding Prime Numbers
console.log("\\n=== Practical Example: Prime Numbers ===");
function isPrime(num) {
    if (num <= 1) return false;
    for (let i = 2; i <= Math.sqrt(num); i++) {
        if (num % i === 0) return false;
    }
    return true;
}

console.log("Prime numbers between 1-20:");
for (let i = 1; i <= 20; i++) {
    if (isPrime(i)) {
        console.log(i + " is prime");
    }
}`
            },
            
            'conditionals': {
                title: 'Conditionals',
                code: `// JavaScript Conditionals

console.log("=== Conditional Statements ===");

const temperature = 25;
const time = 14; // 24-hour format
const isRaining = false;
const age = 18;
const score = 85;
const user = {
    name: "John",
    isLoggedIn: true,
    role: "admin"
};

// 1. if statement
console.log("\\n=== if Statement ===");
if (temperature > 30) {
    console.log("It's hot outside!");
} else if (temperature > 20) {
    console.log("It's warm outside.");
} else {
    console.log("It's cool outside.");
}

// 2. Ternary Operator
console.log("\\n=== Ternary Operator ===");
const weatherMessage = isRaining ? "Bring an umbrella!" : "No umbrella needed.";
console.log("Weather message:", weatherMessage);

const canVote = age >= 18 ? "Can vote" : "Cannot vote";
console.log("Voting status:", canVote);

// 3. Switch Statement
console.log("\\n=== Switch Statement ===");
let dayOfWeek = 3;
let dayName;

switch (dayOfWeek) {
    case 1:
        dayName = "Monday";
        break;
    case 2:
        dayName = "Tuesday";
        break;
    case 3:
        dayName = "Wednesday";
        break;
    case 4:
        dayName = "Thursday";
        break;
    case 5:
        dayName = "Friday";
        break;
    case 6:
        dayName = "Saturday";
        break;
    case 7:
        dayName = "Sunday";
        break;
    default:
        dayName = "Invalid day";
}
console.log("Day:", dayName);

// 4. Logical Operators
console.log("\\n=== Logical Operators ===");
const hasPermission = user.isLoggedIn && user.role === "admin";
console.log("Has admin permission?", hasPermission);

const isWeekend = dayOfWeek === 6 || dayOfWeek === 7;
console.log("Is weekend?", isWeekend);

const isNotRaining = !isRaining;
console.log("Is not raining?", isNotRaining);

// 5. Nullish Coalescing (??)
console.log("\\n=== Nullish Coalescing ===");
const username = null;
const displayName = username ?? "Guest";
console.log("Display name:", displayName);

const settings = {
    theme: null,
    language: "en"
};
const theme = settings.theme ?? "light";
console.log("Theme:", theme);

// 6. Optional Chaining (?.)
console.log("\\n=== Optional Chaining ===");
const order = {
    id: 123,
    customer: {
        name: "Alice",
        address: {
            city: "New York"
        }
    }
};

console.log("Customer city:", order.customer?.address?.city);
console.log("Non-existent property:", order.payment?.method);

// 7. Multiple Conditions
console.log("\\n=== Multiple Conditions ===");
if (time >= 6 && time < 12) {
    console.log("Good morning!");
} else if (time >= 12 && time < 18) {
    console.log("Good afternoon!");
} else if (time >= 18 && time < 22) {
    console.log("Good evening!");
} else {
    console.log("Good night!");
}

// 8. Grade Calculator
console.log("\\n=== Grade Calculator ===");
let grade;
if (score >= 90) {
    grade = "A";
} else if (score >= 80) {
    grade = "B";
} else if (score >= 70) {
    grade = "C";
} else if (score >= 60) {
    grade = "D";
} else {
    grade = "F";
}
console.log(\`Score: \${score}, Grade: \${grade}\`);

// 9. Short-circuit Evaluation
console.log("\\n=== Short-circuit Evaluation ===");
const isAdult = age >= 18;
const hasID = true;

isAdult && hasID && console.log("Allowed to enter club");
!isAdult && console.log("Not allowed - underage");

// 10. Complex Condition Example
console.log("\\n=== Complex Conditions ===");
const canDrive = age >= 16 && !isRaining;
const hasCar = true;
const hasLicense = true;

if (canDrive && hasCar && hasLicense) {
    console.log("You can drive!");
} else {
    if (!canDrive) console.log("Cannot drive: age or weather issue");
    if (!hasCar) console.log("Cannot drive: no car");
    if (!hasLicense) console.log("Cannot drive: no license");
}

// 11. Truthy and Falsy Values
console.log("\\n=== Truthy/Falsy Values ===");
const values = [0, 1, "", "hello", null, undefined, [], {}, false, true];

values.forEach(value => {
    if (value) {
        console.log(\`\${JSON.stringify(value)} is truthy\`);
    } else {
        console.log(\`\${JSON.stringify(value)} is falsy\`);
    }
});`
            },
            
            'dom-manipulation': {
                title: 'DOM Manipulation',
                code: `// JavaScript DOM Manipulation Examples
// Note: These work in browser environment

console.log("=== DOM Manipulation Examples ===");
console.log("Note: These examples show DOM manipulation code.");
console.log("To see them work, you would need an HTML page.");

// 1. Selecting Elements
const domCode = \`
// === DOM Manipulation Code Examples ===

// 1. Selecting Elements
const heading = document.getElementById('main-heading');
const paragraphs = document.getElementsByClassName('text');
const buttons = document.querySelectorAll('.btn');
const container = document.querySelector('#container');

// 2. Creating Elements
const newDiv = document.createElement('div');
newDiv.className = 'alert alert-success';
newDiv.textContent = 'New element created!';

// 3. Adding to DOM
container.appendChild(newDiv);

// 4. Modifying Elements
heading.textContent = 'Updated Heading';
heading.style.color = 'blue';
heading.classList.add('highlight');

// 5. Event Listeners
buttons.forEach(button => {
    button.addEventListener('click', function() {
        console.log('Button clicked:', this.textContent);
        this.style.backgroundColor = '#4361ee';
    });
});

// 6. Form Handling
const form = document.querySelector('#myForm');
form.addEventListener('submit', function(event) {
    event.preventDefault();
    const input = this.querySelector('input[type="text"]');
    console.log('Form submitted with:', input.value);
});

// 7. Dynamic Content
const users = ['Alice', 'Bob', 'Charlie'];
const userList = document.createElement('ul');
users.forEach(user => {
    const li = document.createElement('li');
    li.textContent = user;
    li.addEventListener('click', () => {
        console.log('User clicked:', user);
    });
    userList.appendChild(li);
});

// 8. Removing Elements
const oldElement = document.querySelector('.old-element');
if (oldElement) {
    oldElement.remove();
}

// 9. Template Literals for HTML
const data = { title: 'Dynamic', content: 'This is dynamic content' };
const template = \`
    <div class="card">
        <h3>\${data.title}</h3>
        <p>\${data.content}</p>
    </div>
\`;
container.insertAdjacentHTML('beforeend', template);

// 10. Animation
const box = document.querySelector('.animated-box');
box.addEventListener('click', () => {
    box.style.transition = 'transform 0.5s';
    box.style.transform = 'scale(1.2) rotate(10deg)';
    
    setTimeout(() => {
        box.style.transform = 'scale(1) rotate(0deg)';
    }, 500);
});
\`;

console.log(domCode);
console.log("\\nTo test DOM manipulation:");
console.log("1. Create an HTML file with elements");
console.log("2. Add the above JavaScript code");
console.log("3. See the DOM manipulation in action!");`
            }
        };
        
        // Initialize when DOM is ready
        $(document).ready(function() {
            console.log("Initializing JavaScript Practice Area...");
            initializeEditor();
            loadSavedFiles();
            
            // Show welcome message
            showToast("Welcome to JavaScript Practice Area!", "info");
        });
        
        // Initialize CodeMirror editor
        function initializeEditor() {
            // Get the textarea
            const textarea = document.getElementById('codeEditor');
            
            // Set initial JavaScript content
            textarea.value = `// Welcome to JavaScript Practice Area! 🚀
// Write your JavaScript code here and click "Run"

// 1. Basic Output
console.log("Hello, ${username}!");
console.log("Let's practice JavaScript!");

// 2. Variables
let message = "JavaScript is fun!";
const PI = 3.14159;
var oldVariable = "I'm old school";

console.log("Message:", message);
console.log("PI:", PI);

// 3. Functions
function greet(name) {
    return \`Hello, \${name}!\`;
}

console.log(greet("Developer"));

// 4. Arrays
const numbers = [1, 2, 3, 4, 5];
console.log("Numbers array:", numbers);

// Map example
const doubled = numbers.map(n => n * 2);
console.log("Doubled numbers:", doubled);

// 5. Objects
const user = {
    name: "John",
    age: 30,
    isAdmin: true
};
console.log("User object:", user);
console.log("User name:", user.name);

// 6. Try changing the code below:
let counter = 0;
for (let i = 1; i <= 5; i++) {
    counter += i;
}
console.log("Sum of 1-5:", counter);

// 7. Click examples in the sidebar to load different JavaScript concepts
// 8. Press Ctrl+Enter to run your code quickly
// 9. Press Ctrl+S to save your work

// Happy coding! 🎉`;

            // Initialize CodeMirror
            editor = CodeMirror.fromTextArea(textarea, {
                lineNumbers: true,
                mode: "javascript",
                theme: "dracula",
                matchBrackets: true,
                autoCloseBrackets: true,
                lineWrapping: true,
                indentUnit: 4,
                tabSize: 4,
                extraKeys: {
                    "Ctrl-Enter": runCode,
                    "Ctrl-S": saveFile,
                    "Ctrl-F": formatCode,
                    "Ctrl-N": createNewFile
                },
                viewportMargin: Infinity
            });
            
            // Force refresh
            setTimeout(() => {
                if (editor) {
                    editor.refresh();
                    console.log("JavaScript editor ready!");
                }
            }, 200);
            
            // Set up filename handler
            $('#fileName').change(function() {
                currentFile = $(this).val() || 'practice.js';
            });
        }
        
        // Run JavaScript code
        function runCode() {
            const startTime = Date.now();
            const code = editor.getValue();
            
            if (!code.trim()) {
                showToast('Editor is empty!', 'warning');
                return;
            }
            
            // Update status
            $('#statusIndicator').removeClass().addClass('status-dot status-running');
            $('#executionTime').text('');
            $('#outputContent').html('<span class="output-info">Running JavaScript...</span>');
            
            try {
                // Capture console.log output
                let output = '';
                const originalLog = console.log;
                const originalError = console.error;
                const originalWarn = console.warn;
                const originalInfo = console.info;
                
                // Override console methods
                console.log = function(...args) {
                    output += args.map(arg => 
                        typeof arg === 'object' ? JSON.stringify(arg, null, 2) : String(arg)
                    ).join(' ') + '\\n';
                    originalLog.apply(console, args);
                };
                
                console.error = function(...args) {
                    output += '❌ ERROR: ' + args.join(' ') + '\\n';
                    originalError.apply(console, args);
                };
                
                console.warn = function(...args) {
                    output += '⚠️ WARNING: ' + args.join(' ') + '\\n';
                    originalWarn.apply(console, args);
                };
                
                console.info = function(...args) {
                    output += 'ℹ️ INFO: ' + args.join(' ') + '\\n';
                    originalInfo.apply(console, args);
                };
                
                // Execute JavaScript
                eval(code);
                
                // Restore original console methods
                console.log = originalLog;
                console.error = originalError;
                console.warn = originalWarn;
                console.info = originalInfo;
                
                // Calculate execution time
                const execTime = Date.now() - startTime;
                $('#executionTime').text(`\\${execTime}ms\\`);
                
                // Update status
                $('#statusIndicator').removeClass().addClass('status-dot status-success');
                
                if (!output.trim()) {
                    output = '✅ Code executed successfully (no console output)';
                }
                
                // Display output with formatting
                const formattedOutput = output
                    .replace(/\\n/g, '<br>')
                    .replace(/✅ /g, '<span class="output-success">✅ </span>')
                    .replace(/❌ ERROR:/g, '<span class="output-error">❌ ERROR:</span>')
                    .replace(/⚠️ WARNING:/g, '<span class="output-warning">⚠️ WARNING:</span>')
                    .replace(/ℹ️ INFO:/g, '<span class="output-info">ℹ️ INFO:</span>');
                
                $('#outputContent').html(formattedOutput);
                
                // Update last run time
                $('#lastRun').text(new Date().toLocaleTimeString());
                
                // Auto-save if it's a named file
                if (currentFile && currentFile !== 'practice.js') {
                    saveFileToStorage();
                }
                
            } catch (err) {
                const execTime = Date.now() - startTime;
                $('#executionTime').text(`\\${execTime}ms (Error)\\`);
                $('#statusIndicator').removeClass().addClass('status-dot status-error');
                $('#outputContent').html('<span class="output-error">❌ JavaScript Error: ' + err.message + '</span>');
                console.error('Execution error:', err);
            }
        }
        
        // Save file
        function saveFile() {
            if (!currentFile || currentFile === 'practice.js') {
                currentFile = prompt('Enter JavaScript filename:', 'script.js') || 'script.js';
                if (!currentFile.endsWith('.js')) {
                    currentFile += '.js';
                }
                $('#fileName').val(currentFile);
            }
            
            saveFileToStorage();
            showToast('File saved: ' + currentFile, 'success');
        }
        
        // Save file to storage
        function saveFileToStorage() {
            const content = editor.getValue();
            const fileName = $('#fileName').val() || 'script.js';
            
            // Create file object
            const fileObj = {
                name: fileName,
                content: content,
                saved: new Date().toISOString()
            };
            
            // Load existing files
            let files = JSON.parse(localStorage.getItem('jsPracticeFiles') || '[]');
            
            // Update or add file
            const index = files.findIndex(f => f.name === fileName);
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
            loadSavedFiles();
        }
        
        // Load saved files from storage
        function loadSavedFiles() {
            const files = JSON.parse(localStorage.getItem('jsPracticeFiles') || '[]');
            const fileList = $('#fileList');
            
            if (files.length === 0) {
                fileList.html('<div class="text-muted small">No saved files yet</div>');
                return;
            }
            
            let html = '';
            files.forEach((file, index) => {
                const time = new Date(file.saved).toLocaleTimeString([], {hour: '2-digit', minute:'2-digit'});
                html += `\\
                    <div class="sidebar-item" onclick="openFile(${index})">
                        <div>
                            <i class="fas fa-file-code me-2"></i>
                            ${file.name}
                        </div>
                        <small class="text-muted">${time}</small>
                    </div>
                `;
            });
            
            fileList.html(html);
        }
        
        // Open a saved file
        function openFile(index) {
            const files = JSON.parse(localStorage.getItem('jsPracticeFiles') || '[]');
            
            if (files[index]) {
                const file = files[index];
                editor.setValue(file.content);
                $('#fileName').val(file.name);
                currentFile = file.name;
                
                showToast('Opened: ' + file.name, 'info');
                
                // Close sidebar on mobile
                if ($(window).width() < 992) {
                    $('#sidebar').removeClass('open');
                }
            }
        }
        
        // Create new file
        function createNewFile() {
            const defaultName = 'script_' + Date.now() + '.js';
            
            const fileName = prompt('Enter JavaScript filename:', defaultName);
            if (fileName) {
                // Ensure .js extension
                let finalName = fileName;
                if (!finalName.endsWith('.js')) {
                    finalName += '.js';
                }
                
                // Set template
                const template = `// ${finalName}
// Created on ${new Date().toLocaleDateString()}

console.log("Hello from ${finalName}!");

// Start writing your JavaScript code here

// Example function
function exampleFunction() {
    console.log("This is an example function");
    return "Hello World";
}

// Call the function
const result = exampleFunction();
console.log("Result:", result);

// Add your own code below:\`;
                
                editor.setValue(template);
                $('#fileName').val(finalName);
                currentFile = finalName;
                
                showToast('Created new file: ' + finalName, 'success');
            }
        }
        
        // Load JavaScript example
        function loadExample(exampleId) {
            if (jsExamples[exampleId]) {
                const example = jsExamples[exampleId];
                editor.setValue(example.code);
                $('#fileName').val('example_' + exampleId + '.js');
                currentFile = $('#fileName').val();
                
                showToast('Loaded: ' + example.title, 'info');
                
                // Close sidebar on mobile
                if ($(window).width() < 992) {
                    $('#sidebar').removeClass('open');
                }
            }
        }
        
        // Format JavaScript code with Prettier
        function formatCode() {
            const code = editor.getValue();
            
            try {
                const formatted = prettier.format(code, {
                    parser: "babel",
                    plugins: prettierPlugins,
                    tabWidth: 4,
                    semi: true,
                    singleQuote: true,
                    trailingComma: 'es5'
                });
                
                editor.setValue(formatted);
                showToast('JavaScript code formatted', 'success');
            } catch (err) {
                showToast('Format error: ' + err.message, 'error');
            }
        }
        
        // Clear output
        function clearOutput() {
            $('#outputContent').html('<div class="text-muted">Output cleared - ready for next run</div>');
            $('#statusIndicator').removeClass().addClass('status-dot');
            $('#executionTime').text('');
            showToast('Output cleared', 'info');
        }
        
        // Toggle output panel
        function toggleOutput() {
            const panel = $('#outputPanel');
            const icon = $('#outputToggleIcon');
            
            if (panel.height() === 200) {
                panel.css('height', '40px');
                icon.removeClass('fa-chevron-up').addClass('fa-chevron-down');
            } else {
                panel.css('height', '200px');
                icon.removeClass('fa-chevron-down').addClass('fa-chevron-up');
            }
            
            // Refresh editor after animation
            setTimeout(() => {
                if (editor) editor.refresh();
            }, 300);
        }
        
        // Show toast notification
        function showToast(message, type = 'info') {
            // Remove existing toasts
            $('.toast-container').remove();
            
            // Create toast
            const toastId = 'toast-' + Date.now();
            const icons = {
                'info': 'info-circle',
                'success': 'check-circle',
                'error': 'exclamation-circle',
                'warning': 'exclamation-triangle'
            };
            
            const toastHtml = \`
                <div class="toast-container position-fixed top-0 end-0 p-3">
                    <div id="\${toastId}" class="toast align-items-center text-bg-\${type} border-0" role="alert">
                        <div class="d-flex">
                            <div class="toast-body">
                                <i class="fas fa-\${icons[type] || 'info-circle'} me-2"></i>
                                \${message}
                            </div>
                            <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button>
                        </div>
                    </div>
                </div>
            \\`;
            
            $('body').append(toastHtml);
            const toastElement = $('#' + toastId);
            const toast = new bootstrap.Toast(toastElement, { delay: 3000 });
            toast.show();
            
            // Remove after hiding
            toastElement.on('hidden.bs.toast', function() {
                $(this).closest('.toast-container').remove();
            });
        }
        
        // Toggle sidebar on mobile
        $('#sidebarToggle').click(function() {
            $('#sidebar').toggleClass('open');
        });
        
        // Close sidebar when clicking outside on mobile
        $(document).click(function(e) {
            if ($(window).width() < 992) {
                if (!$(e.target).closest('#sidebar, #sidebarToggle').length && $('#sidebar').hasClass('open')) {
                    $('#sidebar').removeClass('open');
                }
            }
        });
        
        // Handle window resize
        $(window).resize(function() {
            // Refresh editor on resize
            if (editor) {
                setTimeout(() => editor.refresh(), 100);
            }
            
            // Auto-close sidebar on mobile when resizing to desktop
            if ($(window).width() >= 992) {
                $('#sidebar').removeClass('open');
            }
        });
        
        // Export functions to window
        window.runCode = runCode;
        window.saveFile = saveFile;
        window.formatCode = formatCode;
        window.clearOutput = clearOutput;
        window.toggleOutput = toggleOutput;
        window.createNewFile = createNewFile;
        window.loadExample = loadExample;
        window.openFile = openFile;
    </script>
</body>
</html>