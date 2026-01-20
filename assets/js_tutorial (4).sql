-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: Jan 20, 2026 at 12:01 PM
-- Server version: 10.4.32-MariaDB
-- PHP Version: 8.2.12

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `js_tutorial`
--

-- --------------------------------------------------------

--
-- Table structure for table `allowed_teachers`
--

CREATE TABLE `allowed_teachers` (
  `id` int(11) NOT NULL,
  `email` text NOT NULL,
  `is_allowed` int(11) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `allowed_teachers`
--

INSERT INTO `allowed_teachers` (`id`, `email`, `is_allowed`) VALUES
(1, 'jlouisuru@gmail.com', 1),
(2, 'allan@gmail.com', 1);

-- --------------------------------------------------------

--
-- Table structure for table `lessons`
--

CREATE TABLE `lessons` (
  `id` int(11) NOT NULL,
  `topic_id` int(11) NOT NULL,
  `lesson_title` varchar(200) NOT NULL,
  `lesson_content` longtext NOT NULL,
  `lesson_order` int(11) DEFAULT 0,
  `content_type` enum('theory','syntax','example','exercise') DEFAULT 'theory',
  `is_active` tinyint(1) DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `deleted_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `lessons`
--

INSERT INTO `lessons` (`id`, `topic_id`, `lesson_title`, `lesson_content`, `lesson_order`, `content_type`, `is_active`, `created_at`, `deleted_at`) VALUES
(1, 1, 'What you should already know', '<p>\r\n                </p><div class=\"alert alert-info\">\r\n                    <div class=\"d-flex align-items-start\">\r\n                        <i class=\"fas fa-lightbulb me-3 mt-1 fa-lg\"><br></i>\r\n                        <div>\r\n                            <h5 class=\"alert-heading\">What you should already know</h5>\r\n                            <p class=\"mb-0\">This guide assumes you have the following basic background:</p></div></div></div><ul><li>A general understanding of the Internet and the World Wide Web (WWW).\r\n</li><li>Good working knowledge of HyperText Markup Language (HTML).\r\n</li><li>Some programming experience. If you are new to programming, try one of the tutorials linked on the main page about JavaScript.</li>\r\n                </ul><div>\r\n                <div class=\"alert alert-info\">\r\n                    <div class=\"d-flex align-items-start\">\r\n                        <i class=\"fas fa-lightbulb me-3 mt-1 fa-lg\"></i>\r\n                        <div>\r\n                            <h5 class=\"alert-heading\">Where to find JavaScript information</h5>\r\n                            <p class=\"mb-0\">The JavaScript documentation on MDN includes the following:</p>\r\n                        </div>\r\n                    </div>\r\n                </div>\r\n            `</div><div>\r\n                <ul>\r\n                    <li>Dynamic scripting with JavaScript provides structured JavaScript guides for beginners and introduces basic concepts of programming and the Internet.\r\n</li><li>JavaScript Guide (this guide) provides an overview about the JavaScript language and its objects.\r\n</li><li>JavaScript Reference provides detailed reference material for JavaScript.</li>\r\n                </ul>\r\n            </div><h4 class=\"fw-bold mb-2\">What is JavaScript?</h4>JavaScript is a cross-platform, object-oriented scripting language used to make webpages interactive (e.g., having complex animations, clickable buttons, popup menus, etc.).&nbsp;<div><br></div><div>There are also more advanced server side versions of JavaScript such as Node.js, which allow you to add more functionality to a website than downloading files (such as realtime collaboration between multiple computers). Inside a host environment (for example, a web browser), JavaScript can be connected to the objects of its environment to provide programmatic control over them.<p>\r\n</p><p>\r\n</p><p>\r\n</p><p>\r\n</p><p>\r\n</p><p>\r\n</p><p>\r\n</p><p>\r\n</p><p>\r\n</p><p>\r\n</p></div>', 1, 'theory', 1, '2026-01-20 07:55:12', NULL),
(2, 2, 'Basics', '<p>JavaScript borrows most of its syntax from Java, C, and C++, but it has also been influenced by Awk, Perl, and Python.\r\n</p><p>JavaScript is case-sensitive and uses the Unicode character set. For example, the word Früh (which means \"early\" in German) could be used as a variable name.</p><p><div class=\"code-snippet\"><div class=\"code-snippet-header\"><small>JavaScript Code</small>\r\n                <button class=\"btn btn-sm btn-outline-light copy-code-btn\">\r\n                    <i class=\"fas fa-copy\"></i> Copy\r\n                </button>\r\n            </div>\r\n            <pre><code>//Pwede mo i-edit ito;</code></pre><pre><code>const greet = \"Hello World!\"; </code></pre><pre><code>console.log(greet);</code></pre>\r\n        </div>\r\n    </p><p>But, the variable greet is not the same as Greet because JavaScript is case sensitive.</p><p>In JavaScript, instructions are called statements and are separated by semicolons (;).</p><p>A semicolon is not necessary after a statement if it is written on its own line.&nbsp;</p><p>But if more than one statement on a line is desired, then they must be separated by semicolons.</p><p>\r\n                <div class=\"alert alert-info\">\r\n                    <div class=\"d-flex align-items-start\">\r\n                        <i class=\"fas fa-lightbulb me-3 mt-1 fa-lg\"></i>\r\n                        <div>\r\n                            <h5 class=\"alert-heading\">Note:</h5>\r\n                            <p class=\"mb-0\">ECMAScript also has rules for automatic insertion of semicolons (ASI) to end statements. (For more information, see the detailed reference about JavaScript\'s lexical grammar.)</p>\r\n                        </div>\r\n                    </div>\r\n                </div>\r\n            It is considered best practice, however, to always write a semicolon after a statement, even when it is not strictly needed. This practice reduces the chances of bugs getting into the code.</p><p>The source text of JavaScript script gets scanned from left to right, and is converted into a sequence of input elements which are tokens, control characters, line terminators, comments, or whitespace. (Spaces, tabs, and newline characters are considered whitespace.)</p><p><h4 class=\"fw-bold mb-2\">Comments</h4></p><p>The syntax of comments is the same as in C++ and in many other languages:</p><p><div class=\"code-snippet\"><div class=\"code-snippet-header\"><small>JavaScript Code</small>\r\n                <button class=\"btn btn-sm btn-outline-light copy-code-btn\">\r\n                    <i class=\"fas fa-copy\"></i> Copy\r\n                </button>\r\n            </div>\r\n            <pre><code>// This is a one-line Comment </code></pre><pre><code>/* this is a longer,\r\n</code></pre><pre><code> * multi-line comment\r\n</code></pre><pre><code> */</code></pre></div></p><p>\r\n                <div class=\"alert alert-info\">\r\n                    <div class=\"d-flex align-items-start\">\r\n                        <i class=\"fas fa-lightbulb me-3 mt-1 fa-lg\"></i>\r\n                        <div>\r\n                            <h5 class=\"alert-heading\">Important!</h5>\r\n                            <p class=\"mb-0\">You might also see a third type of comment syntax at the start of some JavaScript files, which looks something like this: #!/usr/bin/env node.\r\n</p><p class=\"mb-0\">\r\n</p>\r\n                        </div>\r\n                    </div>\r\n                </div>\r\n            </p><p>This is called hashbang comment syntax, and is a special comment used to specify the path to a particular JavaScript engine that should execute the script. See Hashbang comments for more details.</p><p>Declarations\r\n</p><p>JavaScript has three kinds of variable declarations.</p><p><b>var\r\n</b></p><p>Declares a variable, optionally initializing it to a value.</p><p><b>let\r\n</b></p><p>Declares a block-scoped, local variable, optionally initializing it to a value.</p><p><b>const\r\n</b></p><p>Declares a block-scoped, read-only named constant.</p><p>\r\n                <div class=\"alert alert-info\">\r\n                    <div class=\"d-flex align-items-start\">\r\n                        <i class=\"fas fa-lightbulb me-3 mt-1 fa-lg\"></i>\r\n                        <div>\r\n                            <h5 class=\"alert-heading\">Variables</h5>\r\n                            <p class=\"mb-0\">You use variables as symbolic names for values in your application. The names of variables, called identifiers, conform to certain rules.</p>\r\n                        </div>\r\n                    </div>\r\n                </div>\r\n            </p><p>A JavaScript identifier usually starts with a letter, underscore (_), or dollar sign ($). Subsequent characters can also be digits (0 – 9). Because JavaScript is case sensitive, letters include the characters A through Z (uppercase) as well as a through z (lowercase).</p><p>You can use most Unicode letters such as å and ü in identifiers. (For more details, see the lexical grammar reference.) You can also use Unicode escape sequences to represent characters in identifiers.</p><p>Some examples of legal names are <b>Number_hits</b>, <b>temp99</b>, <b>$credit</b>, and <b>_name</b>.</p><p><b>Declaring variables\r\n</b></p><p>You can declare a variable in two ways:\r\n</p><p>With the keyword var. For example, var x = 42. This syntax can be used to declare both local and global variables, depending on the execution context.\r\n</p><p>With the keyword const or let. For example, let y = 13. This syntax can be used to declare a block-scope local variable. (See Variable scope below.)</p><p>\r\n</p><p>\r\n</p>', 1, 'syntax', 1, '2026-01-20 08:19:46', NULL);

-- --------------------------------------------------------

--
-- Table structure for table `quizzes`
--

CREATE TABLE `quizzes` (
  `id` int(11) NOT NULL,
  `lesson_id` int(11) NOT NULL,
  `question` text NOT NULL,
  `explanation` text DEFAULT NULL,
  `difficulty` enum('easy','medium','hard') DEFAULT 'easy',
  `is_active` tinyint(1) DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `deleted_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `quizzes`
--

INSERT INTO `quizzes` (`id`, `lesson_id`, `question`, `explanation`, `difficulty`, `is_active`, `created_at`, `deleted_at`) VALUES
(1, 1, 'JavaScript is a scripting language used to make webpages interactive.', 'JavaScript is a cross-platform, object-oriented scripting language used to make webpages interactive', 'easy', 1, '2026-01-20 07:55:12', NULL),
(2, 2, 'In JavaScript, the variable greet and Greet is identical.', 'JavaScript is Case-Sensitive', 'easy', 1, '2026-01-20 08:19:46', NULL);

-- --------------------------------------------------------

--
-- Table structure for table `quiz_options`
--

CREATE TABLE `quiz_options` (
  `id` int(11) NOT NULL,
  `quiz_id` int(11) NOT NULL,
  `option_text` text NOT NULL,
  `is_correct` tinyint(1) DEFAULT 0,
  `option_order` int(11) DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `quiz_options`
--

INSERT INTO `quiz_options` (`id`, `quiz_id`, `option_text`, `is_correct`, `option_order`, `created_at`) VALUES
(4, 1, 'False', 0, 0, '2026-01-20 08:07:35'),
(5, 1, 'True', 1, 1, '2026-01-20 08:07:35'),
(6, 1, 'I don\'t know', 0, 2, '2026-01-20 08:07:35'),
(7, 2, 'False', 1, 0, '2026-01-20 08:19:46'),
(8, 2, 'True', 0, 1, '2026-01-20 08:19:46');

-- --------------------------------------------------------

--
-- Table structure for table `students`
--

CREATE TABLE `students` (
  `id` int(11) NOT NULL,
  `username` varchar(50) NOT NULL,
  `email` varchar(100) NOT NULL,
  `password` varchar(255) NOT NULL,
  `full_name` varchar(100) DEFAULT NULL,
  `avatar_color` varchar(7) DEFAULT '#007bff',
  `total_score` int(11) DEFAULT 0,
  `last_active` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `is_active` int(11) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `deleted_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `students`
--

INSERT INTO `students` (`id`, `username`, `email`, `password`, `full_name`, `avatar_color`, `total_score`, `last_active`, `is_active`, `created_at`, `deleted_at`) VALUES
(1, 'testuser', 'test@example.com', 'ecd71870d1963316a97e3ac3408c9835ad8cf0f3c1bc703527c30265534f75ae', 'Test User', '#007bff', NULL, '2026-01-20 07:44:37', 1, '2026-01-17 10:32:30', NULL),
(3, 'calipot', 'calipot@gmail.com', '8d969eef6ecad3c29a3a629280e686cf0c3f5d5a86aff3ca12020c923adc6c92', 'Calipot', '#3432a9', NULL, '2026-01-20 07:44:40', 1, '2026-01-18 23:39:50', NULL);

-- --------------------------------------------------------

--
-- Table structure for table `student_progress`
--

CREATE TABLE `student_progress` (
  `id` int(11) NOT NULL,
  `student_id` int(11) NOT NULL,
  `lesson_id` int(11) NOT NULL,
  `is_completed` tinyint(1) DEFAULT 0,
  `completed_at` timestamp NULL DEFAULT NULL,
  `last_accessed` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `student_progress`
--

INSERT INTO `student_progress` (`id`, `student_id`, `lesson_id`, `is_completed`, `completed_at`, `last_accessed`) VALUES
(1, 3, 2, 1, '2026-01-20 08:20:01', '2026-01-20 08:20:01');

-- --------------------------------------------------------

--
-- Table structure for table `student_quiz_attempts`
--

CREATE TABLE `student_quiz_attempts` (
  `id` int(11) NOT NULL,
  `student_id` int(11) NOT NULL,
  `quiz_id` int(11) NOT NULL,
  `selected_option_id` int(11) DEFAULT NULL,
  `is_correct` tinyint(1) DEFAULT NULL,
  `time_spent` int(11) DEFAULT 0,
  `attempted_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `student_quiz_attempts`
--

INSERT INTO `student_quiz_attempts` (`id`, `student_id`, `quiz_id`, `selected_option_id`, `is_correct`, `time_spent`, `attempted_at`) VALUES
(1, 3, 2, 8, 0, 6, '2026-01-20 08:20:00');

-- --------------------------------------------------------

--
-- Table structure for table `teachers`
--

CREATE TABLE `teachers` (
  `id` int(11) NOT NULL,
  `username` varchar(50) NOT NULL,
  `email` varchar(100) NOT NULL,
  `password` varchar(255) NOT NULL,
  `avatar_color` varchar(7) DEFAULT '#4361ee',
  `last_active` datetime DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `deleted_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `teachers`
--

INSERT INTO `teachers` (`id`, `username`, `email`, `password`, `avatar_color`, `last_active`, `created_at`, `deleted_at`) VALUES
(1, 'johnlouisuru', 'jlouisuru@gmail.com', '$2y$10$qIagYFSpC8TssfSceyE6F.7qkXf6zDoeZvvTfdtUCmeDhGhQYrZSm', '#560bad', '2026-01-20 18:59:28', '2026-01-19 01:56:11', NULL);

-- --------------------------------------------------------

--
-- Table structure for table `topics`
--

CREATE TABLE `topics` (
  `id` int(11) NOT NULL,
  `topic_name` varchar(100) NOT NULL,
  `topic_order` int(11) DEFAULT 0,
  `description` text DEFAULT NULL,
  `is_active` tinyint(1) DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `deleted_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `topics`
--

INSERT INTO `topics` (`id`, `topic_name`, `topic_order`, `description`, `is_active`, `created_at`, `deleted_at`) VALUES
(1, 'Intro to JavaScript', 1, 'This chapter introduces JavaScript and discusses some of its fundamental concepts.', 1, '2026-01-20 07:46:52', NULL),
(2, 'Grammar and Types', 2, 'This chapter discusses JavaScript\'s basic grammar, variable declarations, data types and literals.', 1, '2026-01-20 07:47:17', NULL),
(3, 'Control flow and error handling', 3, 'This chapter will discuss how JavaScript works at the back of your device.', 1, '2026-01-20 07:48:15', NULL);

--
-- Indexes for dumped tables
--

--
-- Indexes for table `allowed_teachers`
--
ALTER TABLE `allowed_teachers`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `lessons`
--
ALTER TABLE `lessons`
  ADD PRIMARY KEY (`id`),
  ADD KEY `topic_id` (`topic_id`);

--
-- Indexes for table `quizzes`
--
ALTER TABLE `quizzes`
  ADD PRIMARY KEY (`id`),
  ADD KEY `lesson_id` (`lesson_id`);

--
-- Indexes for table `quiz_options`
--
ALTER TABLE `quiz_options`
  ADD PRIMARY KEY (`id`),
  ADD KEY `quiz_id` (`quiz_id`);

--
-- Indexes for table `students`
--
ALTER TABLE `students`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `username` (`username`),
  ADD UNIQUE KEY `email` (`email`);

--
-- Indexes for table `student_progress`
--
ALTER TABLE `student_progress`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_progress` (`student_id`,`lesson_id`),
  ADD KEY `lesson_id` (`lesson_id`),
  ADD KEY `idx_student_progress` (`student_id`,`lesson_id`);

--
-- Indexes for table `student_quiz_attempts`
--
ALTER TABLE `student_quiz_attempts`
  ADD PRIMARY KEY (`id`),
  ADD KEY `quiz_id` (`quiz_id`),
  ADD KEY `selected_option_id` (`selected_option_id`),
  ADD KEY `idx_student_quiz_attempts` (`student_id`,`quiz_id`);

--
-- Indexes for table `teachers`
--
ALTER TABLE `teachers`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `username` (`username`),
  ADD UNIQUE KEY `email` (`email`),
  ADD KEY `idx_username` (`username`),
  ADD KEY `idx_email` (`email`),
  ADD KEY `idx_active` (`deleted_at`);

--
-- Indexes for table `topics`
--
ALTER TABLE `topics`
  ADD PRIMARY KEY (`id`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `allowed_teachers`
--
ALTER TABLE `allowed_teachers`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT for table `lessons`
--
ALTER TABLE `lessons`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT for table `quizzes`
--
ALTER TABLE `quizzes`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT for table `quiz_options`
--
ALTER TABLE `quiz_options`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=9;

--
-- AUTO_INCREMENT for table `students`
--
ALTER TABLE `students`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT for table `student_progress`
--
ALTER TABLE `student_progress`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `student_quiz_attempts`
--
ALTER TABLE `student_quiz_attempts`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `teachers`
--
ALTER TABLE `teachers`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `topics`
--
ALTER TABLE `topics`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `lessons`
--
ALTER TABLE `lessons`
  ADD CONSTRAINT `lessons_ibfk_1` FOREIGN KEY (`topic_id`) REFERENCES `topics` (`id`);

--
-- Constraints for table `quizzes`
--
ALTER TABLE `quizzes`
  ADD CONSTRAINT `quizzes_ibfk_1` FOREIGN KEY (`lesson_id`) REFERENCES `lessons` (`id`);

--
-- Constraints for table `quiz_options`
--
ALTER TABLE `quiz_options`
  ADD CONSTRAINT `quiz_options_ibfk_1` FOREIGN KEY (`quiz_id`) REFERENCES `quizzes` (`id`);

--
-- Constraints for table `student_progress`
--
ALTER TABLE `student_progress`
  ADD CONSTRAINT `student_progress_ibfk_1` FOREIGN KEY (`student_id`) REFERENCES `students` (`id`),
  ADD CONSTRAINT `student_progress_ibfk_2` FOREIGN KEY (`lesson_id`) REFERENCES `lessons` (`id`);

--
-- Constraints for table `student_quiz_attempts`
--
ALTER TABLE `student_quiz_attempts`
  ADD CONSTRAINT `student_quiz_attempts_ibfk_1` FOREIGN KEY (`student_id`) REFERENCES `students` (`id`),
  ADD CONSTRAINT `student_quiz_attempts_ibfk_2` FOREIGN KEY (`quiz_id`) REFERENCES `quizzes` (`id`),
  ADD CONSTRAINT `student_quiz_attempts_ibfk_3` FOREIGN KEY (`selected_option_id`) REFERENCES `quiz_options` (`id`);
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
