<?php
// Suppress all output before JSON responses
error_reporting(E_ERROR | E_PARSE);
ini_set('display_errors', 0);
ini_set('max_execution_time', 120); // Increase execution time to 2 minutes

// Initialize SQLite Database for projects and conversations
function initializeDatabase() {
    $db = new SQLite3('idea_creator.db');
    
    // Create projects table
    $db->exec('
        CREATE TABLE IF NOT EXISTS projects (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT NOT NULL,
            description TEXT,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            status TEXT DEFAULT "development"
        )
    ');
    
    // Create project files table
    $db->exec('
        CREATE TABLE IF NOT EXISTS project_files (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            project_id INTEGER,
            filename TEXT NOT NULL,
            content TEXT NOT NULL,
            file_type TEXT NOT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (project_id) REFERENCES projects (id)
        )
    ');
    
    // Create conversations table for chat history
    $db->exec('
        CREATE TABLE IF NOT EXISTS conversations (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            project_id INTEGER,
            speaker TEXT NOT NULL,
            message TEXT NOT NULL,
            timestamp DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (project_id) REFERENCES projects (id)
        )
    ');
    
    return $db;
}

// Initialize database
$db = initializeDatabase();

// Claude API function
function callClaudeAPI($message, $systemPrompt, $apiKey) {
    if (empty($apiKey)) {
        return ['error' => 'API key not provided'];
    }
    
    $messages = [['role' => 'user', 'content' => $message]];

    $data = [
        'model' => 'claude-sonnet-4-20250514',
        'max_tokens' => 8000,
        'messages' => $messages,
        'system' => $systemPrompt
    ];

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => 'https://api.anthropic.com/v1/messages',
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($data),
        CURLOPT_HTTPHEADER => [
            'x-api-key: ' . $apiKey,
            'anthropic-version: 2023-06-01',
            'content-type: application/json'
        ],
        CURLOPT_TIMEOUT => 90, // Increase timeout to 90 seconds
        CURLOPT_CONNECTTIMEOUT => 30,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 3
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    // Handle curl errors
    if ($curlError) {
        return ['error' => 'Connection error: ' . $curlError];
    }

    // Handle HTTP errors
    if ($httpCode !== 200) {
        $errorMsg = 'API call failed with HTTP code: ' . $httpCode;
        if ($response) {
            $errorData = json_decode($response, true);
            if (isset($errorData['error']['message'])) {
                $errorMsg .= ' - ' . $errorData['error']['message'];
            }
        }
        return ['error' => $errorMsg];
    }

    // Parse response
    $result = json_decode($response, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        return ['error' => 'Invalid JSON response from API'];
    }

    if (!isset($result['content'][0]['text'])) {
        return ['error' => 'No content in API response'];
    }

    return ['success' => true, 'content' => $result['content'][0]['text']];
}

// Handle AJAX requests
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    // Clean any existing output
    if (ob_get_level()) {
        ob_clean();
    }
    
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-cache, must-revalidate');
    
    try {
        switch ($_POST['action']) {
        case 'create_project':
            $name = $_POST['name'] ?? 'Untitled Project';
            $description = $_POST['description'] ?? '';
            
            $stmt = $db->prepare('INSERT INTO projects (name, description) VALUES (?, ?)');
            $stmt->bindValue(1, $name, SQLITE3_TEXT);
            $stmt->bindValue(2, $description, SQLITE3_TEXT);
            $stmt->execute();
            
            $projectId = $db->lastInsertRowID();
            echo json_encode(['success' => true, 'project_id' => $projectId]);
            break;
            
        case 'chat_with_claude':
            $projectId = $_POST['project_id'];
            $message = $_POST['message'];
            $apiKey = $_POST['api_key'];
            $stage = $_POST['stage'] ?? 'ideation';
            
            // Save user message
            $stmt = $db->prepare('INSERT INTO conversations (project_id, speaker, message) VALUES (?, ?, ?)');
            $stmt->bindValue(1, $projectId, SQLITE3_INTEGER);
            $stmt->bindValue(2, 'User', SQLITE3_TEXT);
            $stmt->bindValue(3, $message, SQLITE3_TEXT);
            $stmt->execute();
            
            // Get conversation history
            $stmt = $db->prepare('SELECT speaker, message FROM conversations WHERE project_id = ? ORDER BY timestamp DESC LIMIT 10');
            $stmt->bindValue(1, $projectId, SQLITE3_INTEGER);
            $result = $stmt->execute();
            
            $history = [];
            while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
                $history[] = $row['speaker'] . ': ' . $row['message'];
            }
            $conversationHistory = implode("\n", array_reverse($history));
            
            // Create system prompt based on stage
            $systemPrompts = [
                'ideation' => "You are an expert idea developer and creative consultant. Help users refine and enhance their ideas for landing pages. Ask clarifying questions about target audience, style preferences, key features, and visual direction. Be conversational and encouraging. Focus on understanding their vision fully before moving to the build stage.",
                
                'building' => "You are an expert web developer specializing in creating stunning landing pages for ideas and concepts. Create beautiful, modern HTML/CSS landing pages that showcase the user's idea effectively. Use modern design principles, responsive layouts, and engaging visuals. The landing page should tell the story of the idea and make it compelling to potential users or investors.",
                
                'refinement' => "You are helping refine and improve an existing landing page concept. Suggest improvements, alternative layouts, color schemes, or content enhancements based on the user's feedback."
            ];
            
            $systemPrompt = $systemPrompts[$stage] ?? $systemPrompts['ideation'];
            
            // Add conversation context
            $fullMessage = "Previous conversation:\n" . $conversationHistory . "\n\nCurrent message: " . $message;
            
            $response = callClaudeAPI($fullMessage, $systemPrompt, $apiKey);
            
            if (isset($response['success'])) {
                // Save Claude's response
                $stmt = $db->prepare('INSERT INTO conversations (project_id, speaker, message) VALUES (?, ?, ?)');
                $stmt->bindValue(1, $projectId, SQLITE3_INTEGER);
                $stmt->bindValue(2, 'Claude', SQLITE3_TEXT);
                $stmt->bindValue(3, $response['content'], SQLITE3_TEXT);
                $stmt->execute();
                
                echo json_encode(['success' => true, 'response' => $response['content']]);
            } else {
                echo json_encode(['error' => $response['error']]);
            }
            break;
            
        case 'build_project':
            $projectId = $_POST['project_id'];
            $apiKey = $_POST['api_key'];
            
            // Get conversation history
            $stmt = $db->prepare('SELECT speaker, message FROM conversations WHERE project_id = ? ORDER BY timestamp');
            $stmt->bindValue(1, $projectId, SQLITE3_INTEGER);
            $result = $stmt->execute();
            
            $conversation = [];
            while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
                $conversation[] = $row['speaker'] . ': ' . $row['message'];
            }
            $fullConversation = implode("\n", $conversation);
            
            // System prompt for building
            $buildPrompt = "Based on the following conversation about a user's idea, create a complete, modern landing page. 

CONVERSATION:
$fullConversation

REQUIREMENTS:
1. Create an HTML file with embedded CSS (no external files)
2. Make it responsive and mobile-friendly
3. Use modern design principles with attractive colors and typography
4. Include sections like: Hero, Features/Benefits, How it Works, Call to Action
5. Make it visually compelling and professional
6. Use CSS animations and modern effects
7. The landing page should effectively communicate the idea's value proposition

IMPORTANT: Respond with ONLY the complete HTML code, starting with <!DOCTYPE html> and ending with </html>. No explanations or markdown formatting.";

            $response = callClaudeAPI('Please build the landing page now.', $buildPrompt, $apiKey);
            
            if (isset($response['success'])) {
                $htmlContent = $response['content'];
                
                // Clean up the response to ensure it's just HTML
                $htmlContent = preg_replace('/```html\s*/', '', $htmlContent);
                $htmlContent = preg_replace('/```\s*$/', '', $htmlContent);
                $htmlContent = trim($htmlContent);
                
                // Save the HTML file
                $stmt = $db->prepare('INSERT OR REPLACE INTO project_files (project_id, filename, content, file_type) VALUES (?, ?, ?, ?)');
                $stmt->bindValue(1, $projectId, SQLITE3_INTEGER);
                $stmt->bindValue(2, 'index.html', SQLITE3_TEXT);
                $stmt->bindValue(3, $htmlContent, SQLITE3_TEXT);
                $stmt->bindValue(4, 'html', SQLITE3_TEXT);
                $stmt->execute();
                
                // Update project status
                $stmt = $db->prepare('UPDATE projects SET status = "built", updated_at = CURRENT_TIMESTAMP WHERE id = ?');
                $stmt->bindValue(1, $projectId, SQLITE3_INTEGER);
                $stmt->execute();
                
                echo json_encode(['success' => true, 'html_content' => $htmlContent]);
            } else {
                echo json_encode(['error' => $response['error']]);
            }
            break;
            
        case 'get_project_files':
            $projectId = $_POST['project_id'];
            
            $stmt = $db->prepare('SELECT filename, content, file_type FROM project_files WHERE project_id = ?');
            $stmt->bindValue(1, $projectId, SQLITE3_INTEGER);
            $result = $stmt->execute();
            
            $files = [];
            while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
                $files[] = $row;
            }
            
            echo json_encode(['success' => true, 'files' => $files]);
            break;
            
        case 'get_projects':
            $result = $db->query('SELECT id, name, description, status, created_at FROM projects ORDER BY updated_at DESC');
            
            $projects = [];
            while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
                $projects[] = $row;
            }
            
            echo json_encode(['success' => true, 'projects' => $projects]);
            break;
    }
    } catch (Exception $e) {
        echo json_encode(['error' => 'Server error: ' . $e->getMessage()]);
    }
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Claude App Builder</title>
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap');

        :root {
            --primary: #000000;
            --primary-dark: #1a1a1a;
            --secondary: #f4f4f5;
            --text: #09090b;
            --text-light: #71717a;
            --border: #e4e4e7;
            --success: #22c55e;
            --error: #ef4444;
            --background: #ffffff;
            --surface: #fafafa;
            --muted: #f4f4f5;
            --accent: #f4f4f5;
            --card: #ffffff;
            --shadow: rgba(0, 0, 0, 0.1);
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Inter', sans-serif;
            background: var(--background);
            color: var(--text);
            height: 100vh;
            overflow: hidden;
            -webkit-font-smoothing: antialiased;
            -moz-osx-font-smoothing: grayscale;
        }

        .app-container {
            display: flex;
            height: 100vh;
            flex-direction: column;
        }

        /* Header */
        .header {
            background: var(--card);
            border-bottom: 1px solid var(--border);
            padding: 1rem 1.5rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
            position: relative;
            z-index: 1000;
            box-shadow: 0 1px 3px var(--shadow);
        }

        .logo {
            font-size: 1.5rem;
            font-weight: 700;
            color: var(--primary);
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .header-controls {
            display: flex;
            gap: 1rem;
            align-items: center;
        }

        .settings-btn {
            background: none;
            border: none;
            font-size: 1.2rem;
            cursor: pointer;
            padding: 0.5rem;
            border-radius: 0.375rem;
            transition: all 0.2s cubic-bezier(0.4, 0, 0.2, 1);
            color: var(--text-light);
        }

        .settings-btn:hover {
            background: var(--muted);
            color: var(--text);
        }

        /* Main Content */
        .main-content {
            display: flex;
            flex: 1;
            overflow: hidden;
        }

        /* Left Panel - Chat */
        .chat-panel {
            flex: 1;
            display: flex;
            flex-direction: column;
            border-right: 1px solid var(--border);
            background: var(--card);
        }

        .chat-header {
            padding: 1rem 1.5rem;
            border-bottom: 1px solid var(--border);
            background: var(--muted);
        }

        .project-info h3 {
            font-size: 1.1rem;
            margin-bottom: 0.25rem;
        }

        .project-info p {
            color: var(--text-light);
            font-size: 0.9rem;
        }

        .chat-messages {
            flex: 1;
            overflow-y: auto;
            padding: 1rem;
            display: flex;
            flex-direction: column;
            gap: 1rem;
        }

        .message {
            display: flex;
            gap: 0.75rem;
            max-width: 80%;
        }

        .message.user {
            align-self: flex-end;
            flex-direction: row-reverse;
        }

        .message.user .message-content {
            background: var(--primary);
            color: white;
        }

        .message-avatar {
            width: 32px;
            height: 32px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 0.8rem;
            font-weight: 600;
            flex-shrink: 0;
            border: 1px solid var(--border);
        }

        .message.user .message-avatar {
            background: var(--primary);
            color: white;
            border-color: var(--primary);
        }

        .message.claude .message-avatar {
            background: var(--muted);
            color: var(--text);
            border-color: var(--border);
        }

        .message-content {
            background: var(--muted);
            padding: 0.75rem 1rem;
            border-radius: 0.75rem;
            font-size: 0.9rem;
            line-height: 1.5;
            white-space: pre-wrap;
            border: 1px solid var(--border);
        }

        .chat-input {
            padding: 1rem;
            border-top: 1px solid var(--border);
            background: var(--card);
        }

        .input-container {
            display: flex;
            gap: 0.75rem;
            align-items: flex-end;
        }

        .message-input {
            flex: 1;
            border: 1px solid var(--border);
            border-radius: 0.75rem;
            padding: 0.75rem 1rem;
            font-family: inherit;
            font-size: 0.9rem;
            resize: none;
            min-height: 20px;
            max-height: 120px;
            background: var(--background);
            transition: border-color 0.2s;
        }

        .message-input:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 1px var(--primary);
        }

        .send-btn, .build-btn {
            background: var(--primary);
            color: white;
            border: none;
            border-radius: 0.75rem;
            padding: 0.75rem 1.5rem;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.2s cubic-bezier(0.4, 0, 0.2, 1);
            white-space: nowrap;
        }

        .send-btn:hover, .build-btn:hover {
            background: var(--primary-dark);
            transform: translateY(-1px);
        }

        .send-btn:disabled, .build-btn:disabled {
            background: var(--text-light);
            cursor: not-allowed;
        }

        .action-buttons {
            display: flex;
            gap: 0.5rem;
            margin-top: 0.5rem;
        }

        .build-btn {
            background: var(--success);
            font-size: 0.9rem;
            padding: 0.5rem 1rem;
        }

        /* Right Panel - File Viewer */
        .file-panel {
            width: 400px;
            display: flex;
            flex-direction: column;
            background: var(--card);
            border-left: 1px solid var(--border);
        }

        .file-header {
            padding: 1rem 1.5rem;
            border-bottom: 1px solid var(--border);
            background: var(--muted);
        }

        .file-header h3 {
            font-size: 1.1rem;
            margin-bottom: 0.5rem;
        }

        .file-list {
            flex: 1;
            overflow-y: auto;
        }

        .file-item {
            padding: 0.75rem 1.5rem;
            border-bottom: 1px solid var(--border);
            cursor: pointer;
            transition: background 0.2s;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .file-item:hover {
            background: var(--background);
        }

        .file-item.active {
            background: var(--primary);
            color: white;
        }

        .file-preview {
            flex: 1;
            padding: 1rem;
            overflow-y: auto;
            background: var(--background);
        }

        .code-preview {
            background: #1e293b;
            color: #e2e8f0;
            padding: 1rem;
            border-radius: 0.5rem;
            font-family: 'Fira Code', monospace;
            font-size: 0.8rem;
            line-height: 1.5;
            overflow-x: auto;
            white-space: pre;
        }

        .export-section {
            padding: 1rem 1.5rem;
            border-top: 1px solid var(--border);
            background: var(--background);
        }

        .export-btn {
            width: 100%;
            background: var(--success);
            color: white;
            border: none;
            border-radius: 0.5rem;
            padding: 0.75rem;
            font-weight: 500;
            cursor: pointer;
            transition: background 0.2s;
        }

        .export-btn:hover {
            background: #059669;
        }

        /* Settings Modal */
        .modal-overlay {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0, 0, 0, 0.5);
            display: flex;
            align-items: center;
            justify-content: center;
            z-index: 10000;
        }

        .modal {
            background: var(--card);
            border-radius: 0.75rem;
            padding: 2rem;
            width: 90%;
            max-width: 500px;
            box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.25);
            border: 1px solid var(--border);
        }

        .modal h2 {
            margin-bottom: 1.5rem;
            font-size: 1.5rem;
        }

        .form-group {
            margin-bottom: 1.5rem;
        }

        .form-group label {
            display: block;
            margin-bottom: 0.5rem;
            font-weight: 500;
        }

        .form-group input, .form-group textarea {
            width: 100%;
            border: 1px solid var(--border);
            border-radius: 0.375rem;
            padding: 0.75rem;
            font-family: inherit;
            background: var(--background);
            transition: border-color 0.2s;
        }

        .form-group input:focus, .form-group textarea:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 1px var(--primary);
        }

        .modal-buttons {
            display: flex;
            gap: 1rem;
            justify-content: flex-end;
            margin-top: 2rem;
        }

        .btn-secondary {
            background: var(--muted);
            color: var(--text);
            border: 1px solid var(--border);
            border-radius: 0.375rem;
            padding: 0.75rem 1.5rem;
            cursor: pointer;
            transition: all 0.2s cubic-bezier(0.4, 0, 0.2, 1);
            font-weight: 500;
        }

        .btn-secondary:hover {
            background: var(--accent);
        }

        .btn-primary {
            background: var(--primary);
            color: white;
            border: none;
            border-radius: 0.375rem;
            padding: 0.75rem 1.5rem;
            cursor: pointer;
            transition: all 0.2s cubic-bezier(0.4, 0, 0.2, 1);
            font-weight: 500;
        }

        .btn-primary:hover {
            background: var(--primary-dark);
            transform: translateY(-1px);
        }

        /* Welcome Screen */
        .welcome-screen {
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            height: 100%;
            text-align: center;
            padding: 3rem;
            max-width: 800px;
            margin: 0 auto;
            width: 100%;
        }

        .welcome-screen h2 {
            font-size: 3rem;
            margin-bottom: 1.5rem;
            color: var(--primary);
            font-weight: 700;
            background: linear-gradient(135deg, var(--primary) 0%, var(--text-light) 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
            line-height: 1.2;
        }

        .welcome-screen p {
            color: var(--text-light);
            margin-bottom: 3rem;
            max-width: 600px;
            line-height: 1.8;
            font-size: 1.1rem;
        }

        .new-project-btn {
            background: var(--primary);
            color: white;
            border: none;
            border-radius: 0.75rem;
            padding: 1.25rem 2.5rem;
            font-size: 1.1rem;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s cubic-bezier(0.4, 0, 0.2, 1);
            box-shadow: 0 4px 12px var(--shadow);
        }

        .new-project-btn:hover {
            background: var(--primary-dark);
            transform: translateY(-2px);
            box-shadow: 0 8px 24px var(--shadow);
        }

        .hidden {
            display: none !important;
        }

        .loading {
            opacity: 0.6;
            pointer-events: none;
        }

        .thinking {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            color: var(--text-light);
            font-style: italic;
            padding: 0.5rem;
        }

        .thinking-dots {
            display: flex;
            gap: 2px;
        }

        .thinking-dot {
            width: 4px;
            height: 4px;
            background: var(--text-light);
            border-radius: 50%;
            animation: thinking 1.4s infinite ease-in-out both;
        }

        .thinking-dot:nth-child(1) { animation-delay: -0.32s; }
        .thinking-dot:nth-child(2) { animation-delay: -0.16s; }
        .thinking-dot:nth-child(3) { animation-delay: 0s; }

        @keyframes thinking {
            0%, 80%, 100% { 
                transform: scale(0);
            } 40% { 
                transform: scale(1);
            }
        }

        .status-indicator {
            padding: 0.25rem 0.75rem;
            border-radius: 9999px;
            font-size: 0.8rem;
            font-weight: 500;
            border: 1px solid;
        }

        .status-development {
            background: #fef3c7;
            color: #92400e;
            border-color: #f59e0b;
        }

        .status-built {
            background: #d1fae5;
            color: #065f46;
            border-color: #10b981;
        }

        .scroll-indicator {
            position: sticky;
            bottom: 0;
            background: linear-gradient(transparent, var(--background));
            padding: 0.5rem 0;
            text-align: center;
            color: var(--text-light);
            font-size: 0.8rem;
        }
    </style>
</head>
<body>
    <div class="app-container">
        <!-- Header -->
        <div class="header">
            <div class="logo">🚀 Claude App Builder</div>
            <div class="header-controls">
                <button class="settings-btn" onclick="openSettings()">⚙️</button>
            </div>
        </div>

        <!-- Main Content -->
        <div class="main-content">
            <!-- Welcome Screen -->
            <div class="welcome-screen" id="welcomeScreen">
                <h2>Claude App Builder</h2>
                <p>
                    Transform your ideas into stunning applications and landing pages with the power of AI. 
                    Share your concept, collaborate with Claude to refine it, and watch as professional-grade 
                    code is generated instantly. From simple landing pages to complex applications - 
                    the future of development is here.
                </p>
                <button class="new-project-btn" onclick="openNewProjectModal()">
                    🚀 Start Building
                </button>
            </div>

            <!-- Chat Panel -->
            <div class="chat-panel hidden" id="chatPanel">
                <div class="chat-header">
                    <div class="project-info" id="projectInfo">
                        <h3 id="projectName">New Project</h3>
                        <p id="projectDescription">Developing your idea...</p>
                    </div>
                </div>

                <div class="chat-messages" id="chatMessages">
                    <div class="message claude">
                        <div class="message-avatar">🤖</div>
                        <div class="message-content">
                            Welcome to Claude App Builder! I'm here to help you transform your ideas into professional applications and landing pages. Tell me about your concept - whether it's a product, service, app, or any innovative idea you have. Let's build something amazing together!
                        </div>
                    </div>
                </div>

                <div class="chat-input">
                    <div class="input-container">
                        <textarea 
                            id="messageInput" 
                            class="message-input" 
                            placeholder="Describe your idea..."
                            rows="1"
                        ></textarea>
                        <button class="send-btn" onclick="sendMessage()" id="sendBtn">Send</button>
                    </div>
                    <div class="action-buttons">
                        <button class="build-btn" onclick="buildProject()" id="buildBtn">
                            🚀 Build Landing Page
                        </button>
                    </div>
                </div>
            </div>

            <!-- File Panel -->
            <div class="file-panel hidden" id="filePanel">
                <div class="file-header">
                    <h3>Project Files</h3>
                    <span class="status-indicator status-development" id="projectStatus">Development</span>
                </div>

                <div class="file-list" id="fileList">
                    <div class="file-item">
                        <span>No files generated yet</span>
                    </div>
                </div>

                <div class="file-preview" id="filePreview">
                    <p style="color: var(--text-light); text-align: center; margin-top: 2rem;">
                        Select a file to preview or build your project to generate files
                    </p>
                </div>

                <div class="export-section">
                    <button class="export-btn" onclick="exportProject()" id="exportBtn" disabled>
                        📦 Export Project
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Settings Modal -->
    <div class="modal-overlay hidden" id="settingsModal">
        <div class="modal">
            <h2>Settings</h2>
            <div class="form-group">
                <label for="apiKeyInput">Claude API Key</label>
                <input 
                    type="password" 
                    id="apiKeyInput" 
                    placeholder="Enter your Claude API key..."
                    autocomplete="off"
                >
                <small style="color: var(--text-light); margin-top: 0.5rem; display: block;">
                    Your API key is stored locally and never sent to our servers except for Claude API calls.
                </small>
            </div>
            <div class="modal-buttons">
                <button class="btn-secondary" onclick="closeSettings()">Cancel</button>
                <button class="btn-primary" onclick="saveSettings()">Save</button>
            </div>
        </div>
    </div>

    <!-- New Project Modal -->
    <div class="modal-overlay hidden" id="newProjectModal">
        <div class="modal">
            <h2>Start New Project</h2>
            <div class="form-group">
                <label for="projectNameInput">Project Name</label>
                <input 
                    type="text" 
                    id="projectNameInput" 
                    placeholder="My Amazing Idea"
                >
            </div>
            <div class="form-group">
                <label for="projectDescInput">Brief Description (Optional)</label>
                <textarea 
                    id="projectDescInput" 
                    rows="3"
                    placeholder="A quick description of your idea..."
                ></textarea>
            </div>
            <div class="modal-buttons">
                <button class="btn-secondary" onclick="closeNewProjectModal()">Cancel</button>
                <button class="btn-primary" onclick="createProject()">Create Project</button>
            </div>
        </div>
    </div>

    <script>
        let currentProject = null;
        let apiKey = localStorage.getItem('claude_api_key') || '';

        // Initialize app
        document.addEventListener('DOMContentLoaded', () => {
            // Auto-resize textarea
            const messageInput = document.getElementById('messageInput');
            messageInput.addEventListener('input', function() {
                this.style.height = 'auto';
                this.style.height = Math.min(this.scrollHeight, 120) + 'px';
            });

            // Send on Enter
            messageInput.addEventListener('keydown', function(e) {
                if (e.key === 'Enter' && !e.shiftKey) {
                    e.preventDefault();
                    sendMessage();
                }
            });

            // Check if API key exists
            if (!apiKey) {
                setTimeout(openSettings, 1000);
            }
        });

        // Settings functions
        function openSettings() {
            document.getElementById('apiKeyInput').value = apiKey;
            document.getElementById('settingsModal').classList.remove('hidden');
        }

        function closeSettings() {
            document.getElementById('settingsModal').classList.add('hidden');
        }

        function saveSettings() {
            const newApiKey = document.getElementById('apiKeyInput').value.trim();
            if (newApiKey) {
                apiKey = newApiKey;
                localStorage.setItem('claude_api_key', apiKey);
                closeSettings();
            } else {
                alert('Please enter a valid API key');
            }
        }

        // Project functions
        function openNewProjectModal() {
            if (!apiKey) {
                alert('Please set your Claude API key first');
                openSettings();
                return;
            }
            document.getElementById('newProjectModal').classList.remove('hidden');
        }

        function closeNewProjectModal() {
            document.getElementById('newProjectModal').classList.add('hidden');
            document.getElementById('projectNameInput').value = '';
            document.getElementById('projectDescInput').value = '';
        }

        async function createProject() {
            const name = document.getElementById('projectNameInput').value.trim();
            const description = document.getElementById('projectDescInput').value.trim();

            if (!name) {
                alert('Please enter a project name');
                return;
            }

            try {
                const formData = new FormData();
                formData.append('action', 'create_project');
                formData.append('name', name);
                formData.append('description', description);

                const response = await fetch('', {
                    method: 'POST',
                    body: formData
                });

                const result = await response.json();
                
                if (result.success) {
                    currentProject = {
                        id: result.project_id,
                        name: name,
                        description: description,
                        status: 'development'
                    };

                    // Update UI
                    document.getElementById('projectName').textContent = name;
                    document.getElementById('projectDescription').textContent = description || 'Developing your idea...';
                    
                    // Show main interface
                    document.getElementById('welcomeScreen').classList.add('hidden');
                    document.getElementById('chatPanel').classList.remove('hidden');
                    document.getElementById('filePanel').classList.remove('hidden');
                    
                    closeNewProjectModal();
                    document.getElementById('messageInput').focus();
                } else {
                    alert('Failed to create project');
                }
            } catch (error) {
                console.error('Error:', error);
                alert('Failed to create project');
            }
        }

        // Chat functions
        async function sendMessage() {
            if (!currentProject || !apiKey) return;

            const messageInput = document.getElementById('messageInput');
            const message = messageInput.value.trim();
            
            if (!message) return;

            // Add user message to chat
            addMessageToChat('user', message);
            messageInput.value = '';
            messageInput.style.height = 'auto';

            // Show thinking indicator
            showThinking();
            
            // Disable send button
            document.getElementById('sendBtn').disabled = true;

            try {
                const formData = new FormData();
                formData.append('action', 'chat_with_claude');
                formData.append('project_id', currentProject.id);
                formData.append('message', message);
                formData.append('api_key', apiKey);
                formData.append('stage', 'ideation');

                const response = await fetch('', {
                    method: 'POST',
                    body: formData
                });

                // Check if response is JSON
                const contentType = response.headers.get('content-type');
                if (!contentType || !contentType.includes('application/json')) {
                    throw new Error('Server returned non-JSON response');
                }

                const result = await response.json();
                
                hideThinking();
                
                if (result.success) {
                    addMessageToChat('claude', result.response);
                } else {
                    let errorMessage = 'Sorry, I encountered an error: ' + (result.error || 'Unknown error');
                    if (result.error && result.error.includes('API key')) {
                        errorMessage += '\n\nPlease check your Claude API key in settings.';
                    }
                    addMessageToChat('claude', errorMessage);
                }
            } catch (error) {
                hideThinking();
                console.error('Chat Error:', error);
                
                let errorMessage = 'Sorry, I encountered an error. ';
                if (error.message.includes('non-JSON')) {
                    errorMessage += 'Please check your API key and try again.';
                } else if (error.name === 'TypeError' && error.message.includes('fetch')) {
                    errorMessage += 'Network connection failed. Please check your internet connection.';
                } else {
                    errorMessage += 'Please try again.';
                }
                
                addMessageToChat('claude', errorMessage);
            }

            // Re-enable send button
            document.getElementById('sendBtn').disabled = false;
            messageInput.focus();
        }

        function addMessageToChat(sender, message) {
            const chatMessages = document.getElementById('chatMessages');
            const messageDiv = document.createElement('div');
            messageDiv.className = `message ${sender}`;
            
            const avatar = sender === 'user' ? '👤' : '🤖';
            
            messageDiv.innerHTML = `
                <div class="message-avatar">${avatar}</div>
                <div class="message-content">${message}</div>
            `;
            
            chatMessages.appendChild(messageDiv);
            chatMessages.scrollTop = chatMessages.scrollHeight;
        }

        function showThinking() {
            const chatMessages = document.getElementById('chatMessages');
            const thinkingDiv = document.createElement('div');
            thinkingDiv.className = 'thinking';
            thinkingDiv.id = 'thinkingIndicator';
            thinkingDiv.innerHTML = `
                Claude is thinking
                <div class="thinking-dots">
                    <div class="thinking-dot"></div>
                    <div class="thinking-dot"></div>
                    <div class="thinking-dot"></div>
                </div>
            `;
            chatMessages.appendChild(thinkingDiv);
            chatMessages.scrollTop = chatMessages.scrollHeight;
        }

        function hideThinking() {
            const thinking = document.getElementById('thinkingIndicator');
            if (thinking) {
                thinking.remove();
            }
        }

        // Build function
        async function buildProject() {
            if (!currentProject || !apiKey) return;

            const buildBtn = document.getElementById('buildBtn');
            buildBtn.disabled = true;
            buildBtn.innerHTML = '🔄 Building...';

            // Show progress message
            addMessageToChat('claude', '🚀 Starting to build your project... This may take up to 2 minutes for complex applications.');

            try {
                const formData = new FormData();
                formData.append('action', 'build_project');
                formData.append('project_id', currentProject.id);
                formData.append('api_key', apiKey);

                const response = await fetch('', {
                    method: 'POST',
                    body: formData
                });

                // Check if response is JSON
                const contentType = response.headers.get('content-type');
                if (!contentType || !contentType.includes('application/json')) {
                    throw new Error('Server returned non-JSON response');
                }

                const result = await response.json();
                
                if (result.success) {
                    currentProject.status = 'built';
                    
                    // Update status
                    const statusIndicator = document.getElementById('projectStatus');
                    statusIndicator.textContent = 'Built';
                    statusIndicator.className = 'status-indicator status-built';
                    
                    // Update file list
                    refreshFileList();
                    
                    // Enable export
                    document.getElementById('exportBtn').disabled = false;
                    
                    addMessageToChat('claude', '🎉 Your landing page has been built successfully! Check the file panel to preview and export your project.');
                } else {
                    addMessageToChat('claude', 'Sorry, I encountered an error while building: ' + (result.error || 'Unknown error'));
                }
            } catch (error) {
                console.error('Build Error:', error);
                let errorMessage = 'Sorry, I encountered an error while building. ';
                
                if (error.message.includes('non-JSON')) {
                    errorMessage += 'The server returned an unexpected response. Please check your API key and try again.';
                } else if (error.name === 'TypeError' && error.message.includes('fetch')) {
                    errorMessage += 'Network connection failed. Please check your internet connection.';
                } else {
                    errorMessage += 'Please try again or check the console for details.';
                }
                
                addMessageToChat('claude', errorMessage);
            }

            buildBtn.disabled = false;
            buildBtn.innerHTML = '🚀 Build Landing Page';
        }

        // File management
        async function refreshFileList() {
            if (!currentProject) return;

            try {
                const formData = new FormData();
                formData.append('action', 'get_project_files');
                formData.append('project_id', currentProject.id);

                const response = await fetch('', {
                    method: 'POST',
                    body: formData
                });

                const result = await response.json();
                
                if (result.success && result.files.length > 0) {
                    const fileList = document.getElementById('fileList');
                    fileList.innerHTML = '';
                    
                    result.files.forEach((file, index) => {
                        const fileItem = document.createElement('div');
                        fileItem.className = 'file-item';
                        if (index === 0) fileItem.classList.add('active');
                        
                        fileItem.innerHTML = `<span>${file.filename}</span>`;
                        fileItem.onclick = () => showFilePreview(file, fileItem);
                        
                        fileList.appendChild(fileItem);
                    });
                    
                    // Show first file by default
                    if (result.files[0]) {
                        showFilePreview(result.files[0]);
                    }
                }
            } catch (error) {
                console.error('Error refreshing file list:', error);
            }
        }

        function showFilePreview(file, activeItem) {
            // Update active file
            if (activeItem) {
                document.querySelectorAll('.file-item').forEach(item => {
                    item.classList.remove('active');
                });
                activeItem.classList.add('active');
            }
            
            const preview = document.getElementById('filePreview');
            preview.innerHTML = `
                <h4 style="margin-bottom: 1rem;">${file.filename}</h4>
                <div class="code-preview">${escapeHtml(file.content)}</div>
            `;
        }

        function escapeHtml(text) {
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }

        // Export function
        async function exportProject() {
            if (!currentProject) return;

            try {
                const formData = new FormData();
                formData.append('action', 'get_project_files');
                formData.append('project_id', currentProject.id);

                const response = await fetch('', {
                    method: 'POST',
                    body: formData
                });

                const result = await response.json();
                
                if (result.success && result.files.length > 0) {
                    // Create and download zip-like structure
                    const projectName = currentProject.name.replace(/[^a-z0-9]/gi, '_').toLowerCase();
                    
                    // For now, we'll download the HTML file directly
                    // In a production app, you'd want to create a proper zip file
                    const htmlFile = result.files.find(f => f.filename.endsWith('.html'));
                    if (htmlFile) {
                        downloadFile(htmlFile.content, `${projectName}_landing_page.html`);
                    }
                } else {
                    alert('No files to export');
                }
            } catch (error) {
                console.error('Error exporting project:', error);
                alert('Failed to export project');
            }
        }

        function downloadFile(content, filename) {
            const blob = new Blob([content], { type: 'text/html' });
            const url = URL.createObjectURL(blob);
            const a = document.createElement('a');
            a.href = url;
            a.download = filename;
            document.body.appendChild(a);
            a.click();
            document.body.removeChild(a);
            URL.revokeObjectURL(url);
        }
    </script>
</body>
</html>