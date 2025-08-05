<?php
// Enhanced Groq Chatbot with Pro Mode Integration
session_start();

// Database setup with migration support
function initDatabase() {
    $db = new SQLite3('chatbot_enhanced.db');
    
    // Create chats table
    $db->exec('CREATE TABLE IF NOT EXISTS chats (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        title TEXT NOT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )');
    
    // Create messages table (original schema)
    $db->exec('CREATE TABLE IF NOT EXISTS messages (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        chat_id INTEGER NOT NULL,
        role TEXT NOT NULL,
        content TEXT NOT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (chat_id) REFERENCES chats (id) ON DELETE CASCADE
    )');
    
    // Migration: Add pro_mode and candidates columns if they don't exist
    $result = $db->query("PRAGMA table_info(messages)");
    $columns = [];
    while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
        $columns[] = $row['name'];
    }
    
    if (!in_array('pro_mode', $columns)) {
        $db->exec('ALTER TABLE messages ADD COLUMN pro_mode BOOLEAN DEFAULT 0');
    }
    
    if (!in_array('candidates', $columns)) {
        $db->exec('ALTER TABLE messages ADD COLUMN candidates TEXT DEFAULT NULL');
    }
    
    // Create saved_code table
    $db->exec('CREATE TABLE IF NOT EXISTS saved_code (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        chat_id INTEGER,
        filename TEXT NOT NULL,
        content TEXT NOT NULL,
        language TEXT DEFAULT "javascript",
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (chat_id) REFERENCES chats (id) ON DELETE CASCADE
    )');
    
    // Create api_logs table
    $db->exec('CREATE TABLE IF NOT EXISTS api_logs (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        chat_id INTEGER,
        type TEXT NOT NULL,
        payload TEXT NOT NULL,
        timestamp DATETIME DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (chat_id) REFERENCES chats (id) ON DELETE CASCADE
    )');
    
    return $db;
}

// Pro Mode: Generate multiple candidates in parallel
function generateCandidates($messages, $n_runs, $hf_token) {
    $candidates = [];
    $multi_handle = curl_multi_init();
    $curl_handles = [];
    
    // Prepare all requests
    for ($i = 0; $i < $n_runs; $i++) {
        $ch = curl_init();
        $data = [
            'model' => 'openai/gpt-oss-120b:groq',
            'messages' => $messages,
            'temperature' => 0.9, // High temp for diversity
            'max_tokens' => 2000,
            'stream' => false
        ];
        
        curl_setopt_array($ch, [
            CURLOPT_URL => 'https://router.huggingface.co/v1/chat/completions',
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($data),
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $hf_token
            ],
            CURLOPT_TIMEOUT => 120,
            CURLOPT_SSL_VERIFYPEER => true
        ]);
        
        curl_multi_add_handle($multi_handle, $ch);
        $curl_handles[$i] = $ch;
    }
    
    // Execute all requests in parallel
    $running = null;
    do {
        curl_multi_exec($multi_handle, $running);
        curl_multi_select($multi_handle);
    } while ($running > 0);
    
    // Collect results
    for ($i = 0; $i < $n_runs; $i++) {
        $response = curl_multi_getcontent($curl_handles[$i]);
        $http_code = curl_getinfo($curl_handles[$i], CURLINFO_HTTP_CODE);
        
        if ($response && $http_code === 200) {
            $result = json_decode($response, true);
            if (isset($result['choices'][0]['message']['content'])) {
                $candidates[] = $result['choices'][0]['message']['content'];
            } else {
                $candidates[] = "Error: Invalid response format";
            }
        } else {
            $candidates[] = "Error: Request failed (HTTP $http_code)";
        }
        
        curl_multi_remove_handle($multi_handle, $curl_handles[$i]);
        curl_close($curl_handles[$i]);
    }
    
    curl_multi_close($multi_handle);
    return $candidates;
}

// Pro Mode: Synthesize candidates into final answer
function synthesizeCandidates($candidates, $hf_token) {
    $numbered_candidates = "";
    foreach ($candidates as $i => $candidate) {
        $numbered_candidates .= "\n<candidate>" . ($i + 1) . "\n" . $candidate . "\n</candidate>\n";
    }
    
    $synthesis_messages = [
        [
            'role' => 'system',
            'content' => 'You are an expert editor. Synthesize ONE best answer from the candidate answers provided, merging strengths, correcting errors, and removing repetition. Do not mention the candidates or the synthesis process. Be decisive and clear.'
        ],
        [
            'role' => 'user',
            'content' => "You are given " . count($candidates) . " candidate answers delimited by tags.\n\n" . $numbered_candidates . "\n\nReturn the single best final answer."
        ]
    ];
    
    $data = [
        'model' => 'openai/gpt-oss-120b:groq',
        'messages' => $synthesis_messages,
        'temperature' => 0.2, // Low temp for consistency
        'max_tokens' => 3000,
        'stream' => false
    ];
    
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => 'https://router.huggingface.co/v1/chat/completions',
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($data),
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $hf_token
        ],
        CURLOPT_TIMEOUT => 120,
        CURLOPT_SSL_VERIFYPEER => true
    ]);
    
    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($response && $http_code === 200) {
        $result = json_decode($response, true);
        if (isset($result['choices'][0]['message']['content'])) {
            return $result['choices'][0]['message']['content'];
        }
    }
    
    return "Error: Synthesis failed";
}

// Initialize database
$db = initDatabase();
$current_chat_id = $_SESSION['current_chat_id'] ?? null;

// API endpoint handlers
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    
    // Save code to IDE
    if (isset($_POST['save_code'])) {
        $filename = htmlspecialchars($_POST['filename']);
        $content = $_POST['content'];
        $language = htmlspecialchars($_POST['language'] ?? 'javascript');
        $chat_id = $current_chat_id;
        
        $stmt = $db->prepare('INSERT OR REPLACE INTO saved_code (chat_id, filename, content, language, updated_at) 
                             VALUES (?, ?, ?, ?, CURRENT_TIMESTAMP)');
        $stmt->bindValue(1, $chat_id);
        $stmt->bindValue(2, $filename);
        $stmt->bindValue(3, $content);
        $stmt->bindValue(4, $language);
        $stmt->execute();
        
        echo json_encode(['success' => true, 'id' => $db->lastInsertRowID()]);
        exit;
    }
    
    // Get saved files
    if (isset($_POST['get_files'])) {
        $stmt = $db->prepare('SELECT * FROM saved_code WHERE chat_id = ? ORDER BY updated_at DESC');
        $stmt->bindValue(1, $current_chat_id);
        $result = $stmt->execute();
        
        $files = [];
        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            $files[] = $row;
        }
        
        echo json_encode(['files' => $files]);
        exit;
    }
    
    // Get API logs
    if (isset($_POST['get_logs'])) {
        $stmt = $db->prepare('SELECT * FROM api_logs WHERE chat_id = ? ORDER BY timestamp DESC LIMIT 50');
        $stmt->bindValue(1, $current_chat_id);
        $result = $stmt->execute();
        
        $logs = [];
        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            $logs[] = $row;
        }
        
        echo json_encode(['logs' => $logs]);
        exit;
    }
    
    // Handle new chat creation
    if (isset($_POST['new_chat'])) {
        $title = "New Chat " . date('M j, g:i A');
        $stmt = $db->prepare('INSERT INTO chats (title) VALUES (?)');
        $stmt->bindValue(1, $title);
        $stmt->execute();
        $current_chat_id = $db->lastInsertRowID();
        $_SESSION['current_chat_id'] = $current_chat_id;
        
        echo json_encode(['chat_id' => $current_chat_id, 'title' => $title]);
        exit;
    }
    
    // Handle chat deletion
    if (isset($_POST['delete_chat'])) {
        $chat_id = (int)$_POST['delete_chat'];
        $stmt = $db->prepare('DELETE FROM chats WHERE id = ?');
        $stmt->bindValue(1, $chat_id);
        $stmt->execute();
        
        if ($current_chat_id == $chat_id) {
            unset($_SESSION['current_chat_id']);
        }
        
        echo json_encode(['success' => true]);
        exit;
    }
    
    // Handle chat selection
    if (isset($_POST['select_chat'])) {
        $chat_id = (int)$_POST['select_chat'];
        $_SESSION['current_chat_id'] = $chat_id;
        $current_chat_id = $chat_id;
        
        echo json_encode(['success' => true]);
        exit;
    }
    
    // Handle message sending (including Pro Mode)
    if (isset($_POST['message'])) {
        $user_message = trim($_POST['message']);
        $pro_mode = isset($_POST['pro_mode']) && $_POST['pro_mode'] === 'true';
        $candidate_count = (int)($_POST['candidate_count'] ?? 5);
        
        if (!empty($user_message)) {
            // Create new chat if none exists
            if (!$current_chat_id) {
                $title = substr($user_message, 0, 50) . (strlen($user_message) > 50 ? '...' : '');
                $stmt = $db->prepare('INSERT INTO chats (title) VALUES (?)');
                $stmt->bindValue(1, $title);
                $stmt->execute();
                $current_chat_id = $db->lastInsertRowID();
                $_SESSION['current_chat_id'] = $current_chat_id;
            }
            
            // Save user message
            $stmt = $db->prepare('INSERT INTO messages (chat_id, role, content) VALUES (?, "user", ?)');
            $stmt->bindValue(1, $current_chat_id);
            $stmt->bindValue(2, $user_message);
            $stmt->execute();
            
            // Get chat history for API
            $stmt = $db->prepare('SELECT role, content FROM messages WHERE chat_id = ? ORDER BY created_at ASC');
            $stmt->bindValue(1, $current_chat_id);
            $result = $stmt->execute();
            
            $messages = [];
            while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
                $messages[] = ['role' => $row['role'], 'content' => $row['content']];
            }
            
            $hf_token = $_ENV['HF_TOKEN'] ?? getenv('HF_TOKEN');
            
            if (empty($hf_token)) {
                $bot_response = "❌ Error: HF_TOKEN environment variable not set. Please configure your HuggingFace token.";
                $candidates = [];
            } else {
                if ($pro_mode) {
                    // Pro Mode: Generate candidates and synthesize
                    $start_time = microtime(true);
                    
                    // Log pro mode start
                    $stmt = $db->prepare('INSERT INTO api_logs (chat_id, type, payload) VALUES (?, "pro_mode_start", ?)');
                    $stmt->bindValue(1, $current_chat_id);
                    $stmt->bindValue(2, json_encode(['candidate_count' => $candidate_count, 'message' => $user_message]));
                    $stmt->execute();
                    
                    // Generate candidates
                    $candidates = generateCandidates($messages, $candidate_count, $hf_token);
                    
                    // Synthesize final answer
                    $bot_response = synthesizeCandidates($candidates, $hf_token);
                    
                    $end_time = microtime(true);
                    $processing_time = round($end_time - $start_time, 2);
                    
                    // Log pro mode completion
                    $stmt = $db->prepare('INSERT INTO api_logs (chat_id, type, payload) VALUES (?, "pro_mode_complete", ?)');
                    $stmt->bindValue(1, $current_chat_id);
                    $stmt->bindValue(2, json_encode(['processing_time' => $processing_time, 'candidates_generated' => count($candidates)]));
                    $stmt->execute();
                    
                } else {
                    // Standard Mode: Single API call
                    $candidates = [];
                    
                    $data = [
                        'model' => 'openai/gpt-oss-120b:groq',
                        'messages' => $messages,
                        'temperature' => 0.7,
                        'stream' => false
                    ];
                    
                    $ch = curl_init();
                    curl_setopt_array($ch, [
                        CURLOPT_URL => 'https://router.huggingface.co/v1/chat/completions',
                        CURLOPT_RETURNTRANSFER => true,
                        CURLOPT_POST => true,
                        CURLOPT_POSTFIELDS => json_encode($data),
                        CURLOPT_HTTPHEADER => [
                            'Content-Type: application/json',
                            'Authorization: Bearer ' . $hf_token
                        ],
                        CURLOPT_TIMEOUT => 120,
                        CURLOPT_SSL_VERIFYPEER => true
                    ]);
                    
                    $response = curl_exec($ch);
                    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                    $curl_error = curl_error($ch);
                    curl_close($ch);
                    
                    if ($curl_error) {
                        $bot_response = "❌ Connection error: " . $curl_error;
                    } elseif ($response && $http_code === 200) {
                        $result = json_decode($response, true);
                        if (isset($result['choices'][0]['message']['content'])) {
                            $bot_response = $result['choices'][0]['message']['content'];
                        } else {
                            $bot_response = "❌ Error: Invalid API response format.";
                        }
                    } else {
                        $error_detail = $response ? json_decode($response, true) : [];
                        $error_message = isset($error_detail['error']['message']) ? $error_detail['error']['message'] : 'Unknown error';
                        $bot_response = "❌ API request failed (HTTP $http_code): $error_message";
                    }
                }
                
                // Save bot response with candidates if pro mode
                $stmt = $db->prepare('INSERT INTO messages (chat_id, role, content, pro_mode, candidates) VALUES (?, "assistant", ?, ?, ?)');
                $stmt->bindValue(1, $current_chat_id);
                $stmt->bindValue(2, $bot_response);
                $stmt->bindValue(3, $pro_mode ? 1 : 0);
                $stmt->bindValue(4, $pro_mode ? json_encode($candidates) : null);
                $stmt->execute();
            }
            
            echo json_encode([
                'response' => $bot_response, 
                'chat_id' => $current_chat_id,
                'pro_mode' => $pro_mode,
                'candidates' => $pro_mode ? $candidates : []
            ]);
            exit;
        }
    }
}

// Get all chats for sidebar
$chats_result = $db->query('SELECT * FROM chats ORDER BY updated_at DESC');
$chats = [];
while ($row = $chats_result->fetchArray(SQLITE3_ASSOC)) {
    $chats[] = $row;
}

// Get messages for current chat
$current_messages = [];
if ($current_chat_id) {
    $stmt = $db->prepare('SELECT * FROM messages WHERE chat_id = ? ORDER BY created_at ASC');
    $stmt->bindValue(1, $current_chat_id);
    $result = $stmt->execute();
    
    while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
        $current_messages[] = $row;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Groq Chatbot - Pro Mode Enhanced</title>
    <link rel="icon" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 100 100'><text y='.9em' font-size='90'>🤖</text></svg>">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/highlight.js/11.9.0/styles/vs.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.2/codemirror.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.2/theme/eclipse.min.css">
    <script src="https://cdnjs.cloudflare.com/ajax/libs/highlight.js/11.9.0/highlight.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.2/codemirror.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.2/mode/javascript/javascript.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.2/mode/xml/xml.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.2/mode/css/css.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.2/mode/htmlmixed/htmlmixed.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.2/mode/php/php.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.2/mode/python/python.min.js"></script>
    
    <style>
        /* === WINDOWS 95 THEME VARIABLES === */
        :root {
            /* Windows 95 Desktop Colors */
            --win95-desktop: #008080;
            --win95-face: #c0c0c0;
            --win95-light: #ffffff;
            --win95-dark: #808080;
            --win95-darkest: #404040;
            --win95-text: #000000;
            --win95-text-disabled: #808080;
            --win95-highlight: #0000ff;
            --win95-window: #ffffff;
            
            /* Windows 95 Title Bar */
            --win95-titlebar-active: linear-gradient(90deg, #0f5395 0%, #4584d1 100%);
            --win95-titlebar-inactive: linear-gradient(90deg, #808080 0%, #c0c0c0 100%);
            
            /* Special Colors */
            --win95-blue: #0000ff;
            --win95-red: #ff0000;
            --win95-green: #008000;
            --win95-yellow: #ffff00;
            --win95-purple: #800080;
            
            /* Pro Mode Colors */
            --pro-mode: #ff6b35;
            --pro-mode-light: #ff8c5a;
            
            /* Terminal Colors */
            --terminal-bg: #000000;
            --terminal-green: #00ff00;
            --terminal-amber: #ffbf00;
        }

        /* === GLOBAL RESET & FONT === */
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'MS Sans Serif', 'Microsoft Sans Serif', sans-serif;
            font-size: 11px;
            background: var(--win95-desktop);
            min-height: 100vh;
            padding: 8px;
            overflow: hidden;
        }

        /* === WINDOWS 95 BUTTON MIXIN === */
        .win95-button {
            background: var(--win95-face);
            border: 2px solid;
            border-color: var(--win95-light) var(--win95-dark) var(--win95-dark) var(--win95-light);
            color: var(--win95-text);
            font-family: inherit;
            font-size: 11px;
            cursor: pointer;
            padding: 4px 8px;
            outline: none;
        }

        .win95-button:hover {
            background: #d4d0c8;
        }

        .win95-button:active,
        .win95-button.pressed {
            border-color: var(--win95-dark) var(--win95-light) var(--win95-light) var(--win95-dark);
            background: #b8b4ac;
        }

        .win95-button:disabled {
            color: var(--win95-text-disabled);
            cursor: default;
        }

        .win95-button:disabled:hover {
            background: var(--win95-face);
        }

        /* === WINDOWS 95 INPUT MIXIN === */
        .win95-input {
            background: var(--win95-window);
            border: 2px solid;
            border-color: var(--win95-dark) var(--win95-light) var(--win95-light) var(--win95-dark);
            color: var(--win95-text);
            font-family: inherit;
            font-size: 11px;
            padding: 2px 4px;
            outline: none;
        }

        .win95-input:focus {
            background: var(--win95-window);
            border-color: var(--win95-darkest) var(--win95-light) var(--win95-light) var(--win95-darkest);
        }

        /* === WINDOWS 95 PANEL MIXIN === */
        .win95-panel {
            background: var(--win95-face);
            border: 2px solid;
            border-color: var(--win95-light) var(--win95-dark) var(--win95-dark) var(--win95-light);
        }

        .win95-panel.sunken {
            border-color: var(--win95-dark) var(--win95-light) var(--win95-light) var(--win95-dark);
        }

        /* === WIDGET CONTAINER === */
        .widget-container {
            max-width: 1400px;
            height: calc(100vh - 16px);
            margin: 0 auto;
            background: var(--win95-face);
            border: 2px solid;
            border-color: var(--win95-light) var(--win95-dark) var(--win95-dark) var(--win95-light);
            box-shadow: 2px 2px 4px rgba(0, 0, 0, 0.25);
            display: flex;
            flex-direction: column;
        }

        /* === WIDGET TITLE BAR === */
        .widget-title-bar {
            height: 18px;
            background: var(--win95-titlebar-active);
            display: flex;
            align-items: center;
            padding: 2px 4px;
            color: white;
            font-size: 11px;
            font-weight: bold;
            flex-shrink: 0;
        }

        .widget-title-bar .title {
            flex: 1;
            display: flex;
            align-items: center;
            gap: 4px;
        }

        .widget-controls {
            display: flex;
            gap: 2px;
        }

        .widget-btn {
            width: 16px;
            height: 14px;
            background: var(--win95-face);
            border: 1px solid;
            border-color: var(--win95-light) var(--win95-dark) var(--win95-dark) var(--win95-light);
            font-size: 8px;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: bold;
        }

        .widget-btn:active {
            border-color: var(--win95-dark) var(--win95-light) var(--win95-light) var(--win95-dark);
        }

        /* === MAIN APP LAYOUT === */
        .app-container {
            flex: 1;
            background: var(--win95-face);
            display: flex;
            flex-direction: column;
            overflow: hidden;
        }

        .app-body {
            flex: 1;
            display: flex;
            overflow: hidden;
        }

        /* === LEFT SIDEBAR === */
        .left-sidebar {
            width: 280px;
            background: var(--win95-face);
            border-right: 2px solid;
            border-color: var(--win95-dark) var(--win95-light) var(--win95-light) var(--win95-dark);
            display: flex;
            flex-direction: column;
            flex-shrink: 0;
        }

        .sidebar-header {
            padding: 8px;
            background: var(--win95-face);
            border-bottom: 1px solid var(--win95-dark);
            flex-shrink: 0;
        }

        .new-chat-btn {
            @extend .win95-button;
            width: 100%;
            padding: 6px 12px;
            font-weight: bold;
            background: var(--win95-face);
            border: 2px solid;
            border-color: var(--win95-light) var(--win95-dark) var(--win95-dark) var(--win95-light);
            color: var(--win95-text);
            font-family: inherit;
            font-size: 11px;
            cursor: pointer;
            outline: none;
        }

        .new-chat-btn:hover {
            background: #d4d0c8;
        }

        .new-chat-btn:active {
            border-color: var(--win95-dark) var(--win95-light) var(--win95-light) var(--win95-dark);
            background: #b8b4ac;
        }

        .chat-list {
            flex: 1;
            overflow-y: auto;
            padding: 4px;
            background: var(--win95-window);
            border: 2px solid;
            border-color: var(--win95-dark) var(--win95-light) var(--win95-light) var(--win95-dark);
            margin: 4px;
        }

        .chat-item {
            padding: 4px 8px;
            margin-bottom: 1px;
            cursor: pointer;
            display: flex;
            justify-content: space-between;
            align-items: center;
            color: var(--win95-text);
            font-size: 11px;
            background: var(--win95-window);
        }

        .chat-item:hover {
            background: #e0e0e0;
        }

        .chat-item.active {
            background: var(--win95-highlight);
            color: white;
        }

        .chat-item.pro-mode {
            border-left: 3px solid var(--pro-mode);
        }

        .chat-delete-btn {
            background: var(--win95-face);
            border: 1px solid;
            border-color: var(--win95-light) var(--win95-dark) var(--win95-dark) var(--win95-light);
            color: var(--win95-text);
            cursor: pointer;
            padding: 1px 4px;
            font-size: 9px;
            font-weight: bold;
        }

        .chat-delete-btn:hover {
            background: #d4d0c8;
        }

        .chat-delete-btn:active {
            border-color: var(--win95-dark) var(--win95-light) var(--win95-light) var(--win95-dark);
        }

        /* === MAIN CONTENT === */
        .main-content {
            flex: 1;
            display: flex;
            flex-direction: column;
            min-width: 0;
            background: var(--win95-face);
        }

        .chat-header {
            padding: 8px 12px;
            background: var(--win95-face);
            border-bottom: 2px solid;
            border-color: var(--win95-dark) var(--win95-light) var(--win95-light) var(--win95-dark);
            display: flex;
            justify-content: space-between;
            align-items: center;
            color: var(--win95-text);
            flex-shrink: 0;
        }

        .chat-header h2 {
            font-size: 12px;
            font-weight: bold;
        }

        .toggle-btn {
            background: var(--win95-face);
            border: 2px solid;
            border-color: var(--win95-light) var(--win95-dark) var(--win95-dark) var(--win95-light);
            color: var(--win95-text);
            padding: 4px 8px;
            cursor: pointer;
            font-size: 11px;
            font-family: inherit;
        }

        .toggle-btn:hover {
            background: #d4d0c8;
        }

        .toggle-btn:active {
            border-color: var(--win95-dark) var(--win95-light) var(--win95-light) var(--win95-dark);
        }

        /* === CHAT MESSAGES === */
        .chat-messages {
            flex: 1;
            overflow-y: auto;
            overflow-x: hidden;
            padding: 8px;
            background: var(--win95-window);
            border: 2px solid;
            border-color: var(--win95-dark) var(--win95-light) var(--win95-light) var(--win95-dark);
            margin: 4px;
            position: relative;
            min-height: 0;
        }

        /* Windows 95 Scrollbars */
        .chat-messages::-webkit-scrollbar {
            width: 16px;
        }

        .chat-messages::-webkit-scrollbar-track {
            background: var(--win95-face);
            border: 2px solid;
            border-color: var(--win95-dark) var(--win95-light) var(--win95-light) var(--win95-dark);
        }

        .chat-messages::-webkit-scrollbar-thumb {
            background: var(--win95-face);
            border: 2px solid;
            border-color: var(--win95-light) var(--win95-dark) var(--win95-dark) var(--win95-light);
        }

        .chat-messages::-webkit-scrollbar-thumb:hover {
            background: #d4d0c8;
        }

        .chat-messages::-webkit-scrollbar-thumb:active {
            border-color: var(--win95-dark) var(--win95-light) var(--win95-light) var(--win95-dark);
        }

        .chat-messages::-webkit-scrollbar-button {
            background: var(--win95-face);
            border: 2px solid;
            border-color: var(--win95-light) var(--win95-dark) var(--win95-dark) var(--win95-light);
            height: 16px;
        }

        .chat-messages::-webkit-scrollbar-button:hover {
            background: #d4d0c8;
        }

        .chat-messages::-webkit-scrollbar-button:active {
            border-color: var(--win95-dark) var(--win95-light) var(--win95-light) var(--win95-dark);
        }

        .message {
            margin-bottom: 12px;
            animation: fadeIn 0.3s ease-in;
        }

        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(4px); }
            to { opacity: 1; transform: translateY(0); }
        }

        .message-role {
            font-size: 10px;
            color: var(--win95-text-disabled);
            margin-bottom: 4px;
            text-transform: uppercase;
            letter-spacing: 1px;
            display: flex;
            align-items: center;
            gap: 4px;
            font-weight: bold;
        }

        .message.user .message-role {
            color: var(--win95-blue);
            text-align: right;
            justify-content: flex-end;
        }

        .pro-mode-badge {
            background: var(--pro-mode);
            color: white;
            padding: 1px 4px;
            font-size: 8px;
            font-weight: bold;
            border: 1px solid var(--win95-dark);
        }

        .message-content {
            padding: 8px 12px;
            background: var(--win95-window);
            border: 2px solid;
            border-color: var(--win95-dark) var(--win95-light) var(--win95-light) var(--win95-dark);
            color: var(--win95-text);
            line-height: 1.4;
            word-wrap: break-word;
            max-width: 100%;
            font-size: 11px;
        }

        .message.user .message-content {
            background: #e6f3ff;
            margin-left: auto;
            max-width: 70%;
        }

        .message.assistant .message-content {
            max-width: 90%;
        }

        .message.pro-mode .message-content {
            border-left: 4px solid var(--pro-mode);
        }

        /* === CANDIDATES SECTION === */
        .candidates-section {
            margin-top: 8px;
            padding-top: 8px;
            border-top: 1px solid var(--win95-dark);
        }

        .candidates-toggle {
            background: var(--pro-mode);
            color: white;
            border: 2px solid;
            border-color: var(--win95-light) var(--win95-dark) var(--win95-dark) var(--win95-light);
            padding: 2px 6px;
            cursor: pointer;
            font-size: 10px;
            margin-bottom: 4px;
            font-family: inherit;
        }

        .candidates-toggle:hover {
            background: var(--pro-mode-light);
        }

        .candidates-toggle:active {
            border-color: var(--win95-dark) var(--win95-light) var(--win95-light) var(--win95-dark);
        }

        .candidates-list {
            display: none;
            max-height: 200px;
            overflow-y: auto;
        }

        .candidates-list.show {
            display: block;
        }

        .candidate-item {
            background: #fff8dc;
            border: 1px solid var(--pro-mode);
            padding: 6px;
            margin-bottom: 4px;
            font-size: 10px;
            line-height: 1.3;
        }

        .candidate-header {
            color: var(--pro-mode);
            font-weight: bold;
            margin-bottom: 2px;
            font-size: 9px;
            text-transform: uppercase;
        }

        /* === CODE BLOCKS === */
        .code-block {
            margin: 8px 0;
            background: var(--win95-window);
            border: 2px solid;
            border-color: var(--win95-dark) var(--win95-light) var(--win95-light) var(--win95-dark);
        }

        .code-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 4px 8px;
            background: var(--win95-face);
            border-bottom: 1px solid var(--win95-dark);
        }

        .code-lang {
            color: var(--win95-blue);
            font-size: 10px;
            font-weight: bold;
            text-transform: uppercase;
        }

        .code-actions {
            display: flex;
            gap: 4px;
        }

        .code-btn {
            background: var(--win95-face);
            border: 2px solid;
            border-color: var(--win95-light) var(--win95-dark) var(--win95-dark) var(--win95-light);
            color: var(--win95-text);
            padding: 2px 6px;
            cursor: pointer;
            font-size: 10px;
            font-family: inherit;
        }

        .code-btn:hover {
            background: #d4d0c8;
        }

        .code-btn:active {
            border-color: var(--win95-dark) var(--win95-light) var(--win95-light) var(--win95-dark);
        }

        .code-content {
            padding: 8px;
            overflow-x: auto;
            background: var(--win95-window);
        }

        .code-content pre {
            margin: 0;
            font-size: 11px;
            line-height: 1.3;
            font-family: 'Courier New', monospace;
            color: var(--win95-text);
        }

        /* === MARKDOWN STYLING === */
        .markdown-table {
            margin: 8px 0;
            background: var(--win95-window);
            border: 2px solid;
            border-color: var(--win95-dark) var(--win95-light) var(--win95-light) var(--win95-dark);
        }

        .markdown-table table {
            width: 100%;
            border-collapse: collapse;
            font-size: 11px;
        }

        .markdown-table th {
            background: var(--win95-face);
            color: var(--win95-text);
            padding: 4px 8px;
            text-align: left;
            font-weight: bold;
            border-bottom: 1px solid var(--win95-dark);
        }

        .markdown-table td {
            padding: 4px 8px;
            border-bottom: 1px solid #e0e0e0;
            vertical-align: top;
            line-height: 1.3;
        }

        .markdown-table tr:last-child td {
            border-bottom: none;
        }

        .markdown-table tr:nth-child(even) {
            background: #f8f8f8;
        }

        .message-content h1, .message-content h2, .message-content h3,
        .message-content h4, .message-content h5, .message-content h6 {
            color: var(--win95-blue);
            margin: 8px 0 4px 0;
            font-weight: bold;
        }

        .message-content h1 { font-size: 16px; }
        .message-content h2 { font-size: 14px; }
        .message-content h3 { font-size: 13px; }
        .message-content h4 { font-size: 12px; }
        .message-content h5 { font-size: 11px; }
        .message-content h6 { font-size: 10px; }

        .message-content ul, .message-content ol {
            margin: 8px 0;
            padding-left: 16px;
        }

        .message-content li {
            margin: 2px 0;
            line-height: 1.3;
        }

        .message-content strong {
            color: var(--win95-text);
            font-weight: bold;
        }

        .message-content em {
            color: var(--win95-blue);
            font-style: italic;
        }

        .inline-code {
            background: #f0f0f0;
            border: 1px solid var(--win95-dark);
            padding: 1px 3px;
            font-family: 'Courier New', monospace;
            font-size: 10px;
        }

        /* === INPUT AREA === */
        .input-area {
            padding: 8px;
            background: var(--win95-face);
            border-top: 2px solid;
            border-color: var(--win95-dark) var(--win95-light) var(--win95-light) var(--win95-dark);
            flex-shrink: 0;
        }

        .pro-mode-controls {
            display: flex;
            gap: 12px;
            align-items: center;
            margin-bottom: 8px;
            padding: 6px 8px;
            background: var(--win95-face);
            border: 2px solid;
            border-color: var(--win95-dark) var(--win95-light) var(--win95-light) var(--win95-dark);
        }

        .pro-mode-toggle {
            display: flex;
            align-items: center;
            gap: 6px;
            color: var(--win95-text);
            font-size: 11px;
            font-weight: bold;
        }

        .pro-mode-switch {
            position: relative;
            width: 32px;
            height: 16px;
            background: var(--win95-face);
            border: 2px solid;
            border-color: var(--win95-dark) var(--win95-light) var(--win95-light) var(--win95-dark);
            cursor: pointer;
        }

        .pro-mode-switch.active {
            background: var(--pro-mode);
            border-color: var(--win95-light) var(--win95-dark) var(--win95-dark) var(--win95-light);
        }

        .pro-mode-switch::after {
            content: '';
            position: absolute;
            top: 1px;
            left: 1px;
            width: 12px;
            height: 10px;
            background: var(--win95-light);
            border: 1px solid var(--win95-dark);
            transition: transform 0.2s;
        }

        .pro-mode-switch.active::after {
            transform: translateX(14px);
            background: white;
        }

        .candidate-count-selector {
            display: flex;
            align-items: center;
            gap: 4px;
            color: var(--win95-text);
            font-size: 11px;
            font-weight: bold;
        }

        .candidate-count-input {
            background: var(--win95-window);
            border: 2px solid;
            border-color: var(--win95-dark) var(--win95-light) var(--win95-light) var(--win95-dark);
            color: var(--win95-text);
            padding: 2px 4px;
            width: 40px;
            font-size: 11px;
            font-family: inherit;
        }

        .candidate-count-input:focus {
            outline: none;
            border-color: var(--win95-darkest) var(--win95-light) var(--win95-light) var(--win95-darkest);
        }

        .input-wrapper {
            display: flex;
            gap: 8px;
            align-items: flex-end;
        }

        .message-input {
            flex: 1;
            background: var(--win95-window);
            border: 2px solid;
            border-color: var(--win95-dark) var(--win95-light) var(--win95-light) var(--win95-dark);
            color: var(--win95-text);
            padding: 4px 6px;
            resize: vertical;
            font-family: inherit;
            font-size: 11px;
            min-height: 32px;
            max-height: 80px;
        }

        .message-input:focus {
            outline: none;
            border-color: var(--win95-darkest) var(--win95-light) var(--win95-light) var(--win95-darkest);
        }

        .send-btn {
            background: var(--win95-face);
            border: 2px solid;
            border-color: var(--win95-light) var(--win95-dark) var(--win95-dark) var(--win95-light);
            color: var(--win95-text);
            padding: 6px 16px;
            cursor: pointer;
            font-weight: bold;
            font-size: 11px;
            font-family: inherit;
            min-width: 60px;
        }

        .send-btn:hover:not(:disabled) {
            background: #d4d0c8;
        }

        .send-btn:active:not(:disabled) {
            border-color: var(--win95-dark) var(--win95-light) var(--win95-light) var(--win95-dark);
            background: #b8b4ac;
        }

        .send-btn:disabled {
            color: var(--win95-text-disabled);
            cursor: default;
        }

        .send-btn.pro-mode {
            background: var(--pro-mode);
            color: white;
        }

        .send-btn.pro-mode:hover:not(:disabled) {
            background: var(--pro-mode-light);
        }

        .send-btn.pro-mode:active:not(:disabled) {
            background: #e55a2b;
        }

        /* === RIGHT SIDEBAR (IDE) === */
        .right-sidebar {
            width: 400px;
            background: var(--win95-face);
            border-left: 2px solid;
            border-color: var(--win95-dark) var(--win95-light) var(--win95-light) var(--win95-dark);
            display: none;
            flex-direction: column;
            flex-shrink: 0;
        }

        .right-sidebar.open {
            display: flex;
        }

        .ide-header {
            padding: 8px 12px;
            border-bottom: 2px solid;
            border-color: var(--win95-dark) var(--win95-light) var(--win95-light) var(--win95-dark);
            display: flex;
            justify-content: space-between;
            align-items: center;
            color: var(--win95-text);
            flex-shrink: 0;
            background: var(--win95-face);
        }

        .ide-header h3 {
            font-size: 12px;
            font-weight: bold;
        }

        .ide-tabs {
            flex: 1;
            display: flex;
            flex-direction: column;
            min-height: 0;
        }

        .tab-list {
            display: flex;
            background: var(--win95-face);
            border-bottom: 2px solid;
            border-color: var(--win95-dark) var(--win95-light) var(--win95-light) var(--win95-dark);
            overflow-x: auto;
            flex-shrink: 0;
        }

        .tab {
            padding: 4px 12px;
            background: var(--win95-face);
            border: none;
            border-right: 1px solid var(--win95-dark);
            color: var(--win95-text);
            cursor: pointer;
            font-size: 11px;
            font-family: inherit;
            white-space: nowrap;
        }

        .tab.active {
            background: var(--win95-window);
            border-top: 2px solid var(--win95-highlight);
        }

        .tab:hover:not(.active) {
            background: #d4d0c8;
        }

        .ide-content {
            flex: 1;
            overflow: hidden;
            background: var(--win95-window);
        }

        .CodeMirror {
            height: 100%;
            font-size: 11px;
            font-family: 'Courier New', monospace;
        }

        /* === TERMINAL === */
        .terminal {
            height: 120px;
            background: var(--terminal-bg);
            color: var(--terminal-green);
            font-family: 'Courier New', monospace;
            display: flex;
            flex-direction: column;
            border-top: 2px solid;
            border-color: var(--win95-dark) var(--win95-light) var(--win95-light) var(--win95-dark);
            flex-shrink: 0;
            transition: height 0.3s ease;
        }

        .terminal.collapsed {
            height: 20px;
        }

        .terminal-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 2px 8px;
            background: var(--win95-face);
            border-bottom: 1px solid var(--win95-dark);
            flex-shrink: 0;
            height: 20px;
        }

        .terminal-title {
            color: var(--win95-text);
            font-size: 11px;
            display: flex;
            align-items: center;
            gap: 4px;
            font-weight: bold;
        }

        .terminal-content {
            flex: 1;
            overflow-y: auto;
            padding: 4px 8px;
            font-size: 10px;
            line-height: 1.2;
            min-height: 0;
        }

        .terminal.collapsed .terminal-content {
            display: none;
        }

        .log-entry {
            margin-bottom: 2px;
            font-family: 'Courier New', monospace;
        }

        .log-entry.request { color: #00aaff; }
        .log-entry.response { color: var(--terminal-green); }
        .log-entry.error { color: var(--win95-red); }
        .log-entry.pro_mode_start { color: var(--terminal-amber); }
        .log-entry.pro_mode_complete { color: var(--terminal-green); }

        .log-timestamp {
            color: var(--win95-text-disabled);
            margin-right: 4px;
        }

        /* === TYPING INDICATOR === */
        .typing-indicator {
            display: flex;
            gap: 4px;
            padding: 8px 12px;
            align-items: center;
        }

        .typing-dot {
            width: 4px;
            height: 4px;
            background: var(--win95-text-disabled);
            border-radius: 50%;
            animation: typing 1.4s infinite;
        }

        .typing-dot:nth-child(2) { animation-delay: 0.2s; }
        .typing-dot:nth-child(3) { animation-delay: 0.4s; }

        @keyframes typing {
            0%, 60%, 100% { transform: translateY(0); opacity: 0.5; }
            30% { transform: translateY(-4px); opacity: 1; }
        }

        /* === SCROLL TO BOTTOM BUTTON === */
        .scroll-to-bottom {
            position: absolute;
            bottom: 12px;
            right: 12px;
            background: var(--win95-face);
            color: var(--win95-text);
            border: 2px solid;
            border-color: var(--win95-light) var(--win95-dark) var(--win95-dark) var(--win95-light);
            width: 24px;
            height: 24px;
            display: none;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            font-size: 12px;
            font-weight: bold;
            font-family: inherit;
        }

        .scroll-to-bottom:hover {
            background: #d4d0c8;
        }

        .scroll-to-bottom:active {
            border-color: var(--win95-dark) var(--win95-light) var(--win95-light) var(--win95-dark);
        }

        .scroll-to-bottom.visible {
            display: flex;
        }

        /* === PRO MODE PROGRESS === */
        .pro-mode-progress {
            position: fixed;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            background: var(--win95-face);
            border: 2px solid;
            border-color: var(--win95-light) var(--win95-dark) var(--win95-dark) var(--win95-light);
            padding: 16px;
            z-index: 1000;
            display: none;
            text-align: center;
            color: var(--win95-text);
            box-shadow: 2px 2px 4px rgba(0, 0, 0, 0.25);
        }

        .pro-mode-progress.show {
            display: block;
        }

        .progress-title {
            color: var(--pro-mode);
            font-weight: bold;
            margin-bottom: 8px;
            font-size: 12px;
        }

        .progress-bar {
            width: 200px;
            height: 16px;
            background: var(--win95-window);
            border: 2px solid;
            border-color: var(--win95-dark) var(--win95-light) var(--win95-light) var(--win95-dark);
            overflow: hidden;
            margin: 8px 0;
        }

        .progress-fill {
            height: 100%;
            background: var(--pro-mode);
            transition: width 0.3s ease;
            width: 0%;
        }

        .progress-text {
            font-size: 10px;
            color: var(--win95-text);
        }

        /* === GLOBAL SCROLLBARS === */
        * {
            scrollbar-width: auto;
            scrollbar-color: var(--win95-face) var(--win95-window);
        }

        *::-webkit-scrollbar {
            width: 16px;
            height: 16px;
        }

        *::-webkit-scrollbar-track {
            background: var(--win95-face);
        }

        *::-webkit-scrollbar-thumb {
            background: var(--win95-face);
            border: 2px solid;
            border-color: var(--win95-light) var(--win95-dark) var(--win95-dark) var(--win95-light);
        }

        *::-webkit-scrollbar-thumb:hover {
            background: #d4d0c8;
        }

        *::-webkit-scrollbar-thumb:active {
            border-color: var(--win95-dark) var(--win95-light) var(--win95-light) var(--win95-dark);
        }

        *::-webkit-scrollbar-corner {
            background: var(--win95-face);
        }

        /* === RESPONSIVE DESIGN === */
        @media (max-width: 1200px) {
            .left-sidebar {
                width: 240px;
            }
            
            .right-sidebar {
                width: 350px;
            }
        }

        @media (max-width: 768px) {
            body {
                padding: 4px;
            }
            
            .widget-container {
                height: calc(100vh - 8px);
            }
            
            .left-sidebar {
                width: 200px;
            }
            
            .right-sidebar {
                width: 280px;
            }
            
            .terminal {
                height: 80px;
            }
        }
    </style>
</head>
<body>
    <div class="widget-container">
        <!-- Title Bar -->
        <div class="widget-title-bar">
            <div class="title">
                <span>🤖</span>
                <span>Groq Chatbot - Pro Mode Enhanced</span>
            </div>
            <div class="widget-controls">
                <button class="widget-btn">_</button>
                <button class="widget-btn">□</button>
                <button class="widget-btn">×</button>
            </div>
        </div>

        <!-- Main App Container -->
        <div class="app-container" id="appContainer">
            <!-- App Body (Main Content Area) -->
            <div class="app-body">
                <!-- Left Sidebar -->
                <div class="left-sidebar">
                    <div class="sidebar-header">
                        <button class="new-chat-btn" onclick="createNewChat()">New Chat</button>
                    </div>
                    <div class="chat-list" id="chatList">
                        <?php foreach ($chats as $chat): ?>
                            <?php
                            // Check if chat has pro mode messages
                            $has_pro_mode = false;
                            try {
                                $stmt = $db->prepare('SELECT COUNT(*) as count FROM messages WHERE chat_id = ? AND pro_mode = 1');
                                if ($stmt) {
                                    $stmt->bindValue(1, $chat['id']);
                                    $result = $stmt->execute();
                                    if ($result) {
                                        $row = $result->fetchArray(SQLITE3_ASSOC);
                                        $has_pro_mode = $row['count'] > 0;
                                    }
                                }
                            } catch (Exception $e) {
                                $has_pro_mode = false;
                            }
                            ?>
                            <div class="chat-item <?php echo $chat['id'] == $current_chat_id ? 'active' : ''; ?> <?php echo $has_pro_mode ? 'pro-mode' : ''; ?>" 
                                 data-chat-id="<?php echo $chat['id']; ?>" 
                                 onclick="selectChat(<?php echo $chat['id']; ?>)">
                                <span><?php echo htmlspecialchars($chat['title']); ?></span>
                                <button class="chat-delete-btn" onclick="event.stopPropagation(); deleteChat(<?php echo $chat['id']; ?>)">×</button>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>

                <!-- Main Content -->
                <div class="main-content">
                    <div class="chat-header">
                        <h2>Groq Chat Interface</h2>
                        <button class="toggle-btn" onclick="toggleRightSidebar()">Code Editor</button>
                    </div>
                    
                    <div class="chat-messages" id="chatMessages">
                        <?php foreach ($current_messages as $message): ?>
                            <div class="message <?php echo $message['role']; ?> <?php echo (isset($message['pro_mode']) && $message['pro_mode']) ? 'pro-mode' : ''; ?>">
                                <div class="message-role">
                                    <?php echo $message['role']; ?>
                                    <?php if (isset($message['pro_mode']) && $message['pro_mode']): ?>
                                        <span class="pro-mode-badge">PRO</span>
                                    <?php endif; ?>
                                </div>
                                <div class="message-content" data-raw="<?php echo htmlspecialchars($message['content']); ?>" data-candidates="<?php echo htmlspecialchars($message['candidates'] ?? ''); ?>">
                                    <!-- Content will be formatted by JavaScript -->
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    
                    <button class="scroll-to-bottom" id="scrollToBottomBtn" onclick="scrollToBottom()">↓</button>
                    
                    <div class="input-area">
                        <div class="pro-mode-controls">
                            <div class="pro-mode-toggle">
                                <span>Pro Mode:</span>
                                <div class="pro-mode-switch" id="proModeSwitch" onclick="toggleProMode()"></div>
                            </div>
                            <div class="candidate-count-selector" id="candidateCountSelector" style="display: none;">
                                <span>Candidates:</span>
                                <input type="number" class="candidate-count-input" id="candidateCount" value="5" min="3" max="10">
                            </div>
                        </div>
                        
                        <form id="chatForm" class="input-wrapper">
                            <textarea 
                                class="message-input" 
                                id="messageInput" 
                                placeholder="Type your message..."
                                rows="2"></textarea>
                            <button type="submit" class="send-btn" id="sendBtn">Send</button>
                        </form>
                    </div>
                </div>

                <!-- Right Sidebar (IDE) -->
                <div class="right-sidebar" id="rightSidebar">
                    <div class="ide-header">
                        <h3>Code Editor</h3>
                        <button class="toggle-btn" onclick="toggleRightSidebar()">×</button>
                    </div>
                    <div class="ide-tabs">
                        <div class="tab-list" id="tabList">
                            <!-- Tabs will be added dynamically -->
                        </div>
                        <div class="ide-content" id="ideContent">
                            <textarea id="codeEditor"></textarea>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Terminal -->
            <div class="terminal" id="terminal">
                <div class="terminal-header">
                    <div class="terminal-title">
                        <span>Command Prompt</span>
                    </div>
                    <button class="toggle-btn" onclick="toggleTerminal()" id="terminalToggleBtn">_</button>
                </div>
                <div class="terminal-content" id="terminalContent">
                    <!-- Logs will be added here -->
                </div>
            </div>
        </div>

        <!-- Pro Mode Progress Modal -->
        <div class="pro-mode-progress" id="proModeProgress">
            <div class="progress-title">Pro Mode Processing</div>
            <div class="progress-bar">
                <div class="progress-fill" id="progressFill"></div>
            </div>
            <div class="progress-text" id="progressText">Generating candidates...</div>
        </div>
    </div>

    <script>
        // === GLOBAL VARIABLES ===
        let currentChatId = <?php echo $current_chat_id ? $current_chat_id : 'null'; ?>;
        let codeEditor = null;
        let openFiles = new Map();
        let activeFile = null;
        let proModeEnabled = false;

        // === INITIALIZATION ===
        document.addEventListener('DOMContentLoaded', function() {
            initializeCodeEditor();
            setupEventListeners();
            formatExistingMessages();
            loadTerminalLogs();
            
            setTimeout(() => {
                scrollToBottom();
                updateScrollButton();
            }, 100);
        });

        // === CODE EDITOR SETUP ===
        function initializeCodeEditor() {
            try {
                const textarea = document.getElementById('codeEditor');
                if (textarea && typeof CodeMirror !== 'undefined') {
                    codeEditor = CodeMirror.fromTextArea(textarea, {
                        theme: 'eclipse',
                        lineNumbers: true,
                        mode: 'javascript',
                        autoCloseBrackets: true,
                        matchBrackets: true,
                        indentUnit: 2,
                        tabSize: 2,
                        lineWrapping: true
                    });
                }
            } catch (error) {
                console.warn('CodeMirror initialization error:', error);
            }
        }

        // === EVENT LISTENERS ===
        function setupEventListeners() {
            const form = document.getElementById('chatForm');
            const input = document.getElementById('messageInput');
            const chatMessages = document.getElementById('chatMessages');

            form.addEventListener('submit', handleSubmit);
            
            input.addEventListener('keydown', function(e) {
                if (e.key === 'Enter' && !e.shiftKey) {
                    e.preventDefault();
                    handleSubmit(e);
                }
            });

            input.addEventListener('input', function() {
                this.style.height = 'auto';
                this.style.height = Math.min(this.scrollHeight, 80) + 'px';
            });

            chatMessages.addEventListener('scroll', updateScrollButton);
        }

        // === PRO MODE TOGGLE ===
        function toggleProMode() {
            proModeEnabled = !proModeEnabled;
            const switchEl = document.getElementById('proModeSwitch');
            const countSelector = document.getElementById('candidateCountSelector');
            const sendBtn = document.getElementById('sendBtn');
            
            switchEl.classList.toggle('active', proModeEnabled);
            countSelector.style.display = proModeEnabled ? 'flex' : 'none';
            sendBtn.classList.toggle('pro-mode', proModeEnabled);
            sendBtn.textContent = proModeEnabled ? 'Send Pro' : 'Send';
        }

        // === SIDEBAR TOGGLES ===
        function toggleRightSidebar() {
            const rightSidebar = document.getElementById('rightSidebar');
            rightSidebar.classList.toggle('open');
        }

        function toggleTerminal() {
            const terminal = document.getElementById('terminal');
            const toggleBtn = document.getElementById('terminalToggleBtn');
            
            terminal.classList.toggle('collapsed');
            
            if (terminal.classList.contains('collapsed')) {
                toggleBtn.textContent = '□';
            } else {
                toggleBtn.textContent = '_';
            }
        }

        // === SCROLL MANAGEMENT ===
        function updateScrollButton() {
            const chatMessages = document.getElementById('chatMessages');
            const scrollBtn = document.getElementById('scrollToBottomBtn');
            
            if (!chatMessages || !scrollBtn) return;
            
            const isAtBottom = chatMessages.scrollHeight - chatMessages.scrollTop - chatMessages.clientHeight < 50;
            scrollBtn.classList.toggle('visible', !isAtBottom);
        }

        function scrollToBottom() {
            const container = document.getElementById('chatMessages');
            if (container) {
                container.scrollTop = container.scrollHeight;
                updateScrollButton();
            }
        }

        // === MESSAGE FORMATTING ===
        function formatExistingMessages() {
            document.querySelectorAll('.message-content[data-raw]').forEach(element => {
                const rawContent = element.getAttribute('data-raw');
                const candidatesData = element.getAttribute('data-candidates');
                
                element.innerHTML = formatMessage(rawContent);
                
                // Add candidates section if available
                if (candidatesData && candidatesData !== '') {
                    try {
                        const candidates = JSON.parse(candidatesData);
                        if (candidates && candidates.length > 0) {
                            addCandidatesSection(element, candidates);
                        }
                    } catch (e) {
                        console.warn('Error parsing candidates:', e);
                    }
                }
                
                // Highlight code blocks
                element.querySelectorAll('pre code').forEach(block => {
                    if (typeof hljs !== 'undefined') {
                        hljs.highlightElement(block);
                    }
                });
            });
        }

        function addCandidatesSection(messageElement, candidates) {
            const candidatesSection = document.createElement('div');
            candidatesSection.className = 'candidates-section';
            
            const toggleBtn = document.createElement('button');
            toggleBtn.className = 'candidates-toggle';
            toggleBtn.textContent = `View ${candidates.length} Candidates`;
            
            const candidatesList = document.createElement('div');
            candidatesList.className = 'candidates-list';
            
            candidates.forEach((candidate, index) => {
                const candidateItem = document.createElement('div');
                candidateItem.className = 'candidate-item';
                
                const header = document.createElement('div');
                header.className = 'candidate-header';
                header.textContent = `Candidate ${index + 1}`;
                
                const content = document.createElement('div');
                content.innerHTML = formatMessage(candidate);
                
                candidateItem.appendChild(header);
                candidateItem.appendChild(content);
                candidatesList.appendChild(candidateItem);
            });
            
            toggleBtn.addEventListener('click', () => {
                candidatesList.classList.toggle('show');
                toggleBtn.textContent = candidatesList.classList.contains('show') ? 
                    'Hide Candidates' : `View ${candidates.length} Candidates`;
            });
            
            candidatesSection.appendChild(toggleBtn);
            candidatesSection.appendChild(candidatesList);
            messageElement.appendChild(candidatesSection);
        }

        function formatMessage(content) {
            const escapeHtml = (text) => {
                const div = document.createElement('div');
                div.textContent = text;
                return div.innerHTML;
            };

            // Store code blocks and tables temporarily
            const codeBlocks = [];
            const tables = [];
            let codeIndex = 0;
            let tableIndex = 0;

            // Extract and replace code blocks with placeholders
            content = content.replace(/```(\w*)\n?([\s\S]*?)```/g, function(match, lang, code) {
                const placeholder = `__CODE_BLOCK_${codeIndex}__`;
                codeBlocks[codeIndex] = { lang: lang || 'plaintext', code: code.trim() };
                codeIndex++;
                return placeholder;
            });

            // Extract and replace markdown tables
            const tableRegex = /^\|(.+)\|\s*\n\|[\s\-\|:]+\|\s*\n((?:\|.+\|\s*\n?)*)/gm;
            content = content.replace(tableRegex, function(match, headers, rows) {
                const placeholder = `__TABLE_${tableIndex}__`;
                tables[tableIndex] = { headers: headers.trim(), rows: rows.trim() };
                tableIndex++;
                return placeholder;
            });

            // Escape HTML in remaining content
            content = escapeHtml(content);

            // Format markdown headers
            content = content.replace(/^### (.*$)/gim, '<h3>$1</h3>');
            content = content.replace(/^## (.*$)/gim, '<h2>$1</h2>');
            content = content.replace(/^# (.*$)/gim, '<h1>$1</h1>');
            content = content.replace(/^#### (.*$)/gim, '<h4>$1</h4>');
            content = content.replace(/^##### (.*$)/gim, '<h5>$1</h5>');
            content = content.replace(/^###### (.*$)/gim, '<h6>$1</h6>');

            // Format bold and italic
            content = content.replace(/\*\*(.*?)\*\*/g, '<strong>$1</strong>');
            content = content.replace(/\*(.*?)\*/g, '<em>$1</em>');

            // Format inline code
            content = content.replace(/`([^`]+)`/g, '<span class="inline-code">$1</span>');

            // Format lists
            content = content.replace(/^\* (.+$)/gim, '<li>$1</li>');
            content = content.replace(/^- (.+$)/gim, '<li>$1</li>');
            content = content.replace(/^\d+\. (.+$)/gim, '<li>$1</li>');
            
            // Wrap consecutive list items in ul tags
            content = content.replace(/(<li>.*<\/li>)/gs, function(match) {
                return '<ul>' + match + '</ul>';
            });

            // Replace line breaks
            content = content.replace(/\n/g, '<br>');

            // Restore tables
            tables.forEach((table, index) => {
                const headerCells = table.headers.split('|').map(h => h.trim()).filter(h => h);
                const rowData = table.rows.split('\n').filter(r => r.trim()).map(row => 
                    row.split('|').map(cell => cell.trim()).filter(cell => cell)
                );
                
                let tableHtml = '<div class="markdown-table"><table>';
                
                if (headerCells.length > 0) {
                    tableHtml += '<thead><tr>';
                    headerCells.forEach(header => {
                        tableHtml += `<th>${header}</th>`;
                    });
                    tableHtml += '</tr></thead>';
                }
                
                if (rowData.length > 0) {
                    tableHtml += '<tbody>';
                    rowData.forEach(row => {
                        tableHtml += '<tr>';
                        row.forEach(cell => {
                            tableHtml += `<td>${cell}</td>`;
                        });
                        tableHtml += '</tr>';
                    });
                    tableHtml += '</tbody>';
                }
                
                tableHtml += '</table></div>';
                content = content.replace(`__TABLE_${index}__`, tableHtml);
            });

            // Restore code blocks
            codeBlocks.forEach((block, index) => {
                const blockId = 'code-' + Date.now() + '-' + index;
                const codeHtml = `
                    <div class="code-block">
                        <div class="code-header">
                            <span class="code-lang">${block.lang}</span>
                            <div class="code-actions">
                                <button class="code-btn" onclick="copyCode('${blockId}')">Copy</button>
                                <button class="code-btn" onclick="sendToIDE('${blockId}', '${block.lang}')">IDE</button>
                            </div>
                        </div>
                        <div class="code-content">
                            <pre><code id="${blockId}" class="language-${block.lang}">${escapeHtml(block.code)}</code></pre>
                        </div>
                    </div>
                `;
                content = content.replace(`__CODE_BLOCK_${index}__`, codeHtml);
            });

            return content;
        }

        // === PRO MODE PROGRESS ===
        function showProModeProgress() {
            const progress = document.getElementById('proModeProgress');
            const fill = document.getElementById('progressFill');
            const text = document.getElementById('progressText');
            
            progress.classList.add('show');
            
            let step = 0;
            const steps = ['Generating candidates...', 'Analyzing responses...', 'Synthesizing final answer...'];
            
            const updateProgress = () => {
                if (step < steps.length) {
                    fill.style.width = ((step + 1) / steps.length * 100) + '%';
                    text.textContent = steps[step];
                    step++;
                    setTimeout(updateProgress, 1000);
                }
            };
            
            updateProgress();
        }

        function hideProModeProgress() {
            document.getElementById('proModeProgress').classList.remove('show');
        }

        // === FORM SUBMISSION ===
        async function handleSubmit(e) {
            e.preventDefault();
            
            const input = document.getElementById('messageInput');
            const sendBtn = document.getElementById('sendBtn');
            const message = input.value.trim();
            
            if (!message || sendBtn.disabled) return;

            try {
                sendBtn.disabled = true;
                sendBtn.textContent = proModeEnabled ? 'Processing...' : 'Sending...';
                input.disabled = true;

                addMessageToUI(message, 'user');
                input.value = '';
                input.style.height = 'auto';

                if (proModeEnabled) {
                    showProModeProgress();
                } else {
                    showTypingIndicator();
                }

                const formData = new FormData();
                formData.append('message', message);
                formData.append('pro_mode', proModeEnabled);
                if (proModeEnabled) {
                    const candidateCount = document.getElementById('candidateCount').value;
                    formData.append('candidate_count', candidateCount);
                }

                const response = await fetch('', {
                    method: 'POST',
                    body: formData
                });

                const data = await response.json();

                if (proModeEnabled) {
                    hideProModeProgress();
                } else {
                    removeTypingIndicator();
                }

                addMessageToUI(data.response, 'assistant', data.pro_mode, data.candidates);
                loadTerminalLogs();

            } catch (error) {
                console.error('Submit error:', error);
                if (proModeEnabled) {
                    hideProModeProgress();
                } else {
                    removeTypingIndicator();
                }
                addMessageToUI('Error: Failed to get response. Please try again.', 'assistant');
            } finally {
                sendBtn.disabled = false;
                sendBtn.textContent = proModeEnabled ? 'Send Pro' : 'Send';
                input.disabled = false;
                input.focus();
            }
        }

        // === MESSAGE UI ===
        function addMessageToUI(content, role, isProMode = false, candidates = []) {
            const container = document.getElementById('chatMessages');
            
            const messageDiv = document.createElement('div');
            messageDiv.className = `message ${role}`;
            if (isProMode) messageDiv.classList.add('pro-mode');
            
            const roleDiv = document.createElement('div');
            roleDiv.className = 'message-role';
            roleDiv.textContent = role;
            
            if (isProMode && role === 'assistant') {
                const badge = document.createElement('span');
                badge.className = 'pro-mode-badge';
                badge.textContent = 'PRO';
                roleDiv.appendChild(badge);
            }
            
            const contentDiv = document.createElement('div');
            contentDiv.className = 'message-content';
            contentDiv.innerHTML = formatMessage(content);
            
            if (candidates && candidates.length > 0) {
                addCandidatesSection(contentDiv, candidates);
            }
            
            messageDiv.appendChild(roleDiv);
            messageDiv.appendChild(contentDiv);
            container.appendChild(messageDiv);
            
            messageDiv.querySelectorAll('pre code').forEach(block => {
                if (typeof hljs !== 'undefined') {
                    hljs.highlightElement(block);
                }
            });
            
            setTimeout(scrollToBottom, 50);
        }

        // === TYPING INDICATOR ===
        function showTypingIndicator() {
            const container = document.getElementById('chatMessages');
            const indicator = document.createElement('div');
            indicator.className = 'typing-indicator';
            indicator.id = 'typingIndicator';
            indicator.innerHTML = '<div class="typing-dot"></div><div class="typing-dot"></div><div class="typing-dot"></div>';
            container.appendChild(indicator);
            scrollToBottom();
        }

        function removeTypingIndicator() {
            const indicator = document.getElementById('typingIndicator');
            if (indicator) indicator.remove();
        }

        // === CODE HANDLING ===
        function copyCode(codeId) {
            const codeElement = document.getElementById(codeId);
            const text = codeElement.textContent;
            
            navigator.clipboard.writeText(text).then(() => {
                const btn = codeElement.closest('.code-block').querySelector('.code-btn');
                const originalText = btn.textContent;
                btn.textContent = 'Copied!';
                setTimeout(() => {
                    btn.textContent = originalText;
                }, 2000);
            }).catch(err => {
                console.error('Failed to copy:', err);
            });
        }

        function sendToIDE(codeId, language) {
            const codeElement = document.getElementById(codeId);
            const code = codeElement.textContent;
            
            const rightSidebar = document.getElementById('rightSidebar');
            if (!rightSidebar.classList.contains('open')) {
                toggleRightSidebar();
            }
            
            const filename = `code_${Date.now()}.${getFileExtension(language)}`;
            createFileTab(filename, code, language);
            saveCodeToDatabase(filename, code, language);
        }

        function getFileExtension(language) {
            const extensions = {
                'javascript': 'js', 'python': 'py', 'php': 'php',
                'html': 'html', 'css': 'css', 'sql': 'sql',
                'json': 'json', 'xml': 'xml'
            };
            return extensions[language.toLowerCase()] || 'txt';
        }

        function createFileTab(filename, content, language) {
            const tabList = document.getElementById('tabList');
            
            if (openFiles.has(filename)) {
                switchToTab(filename);
                return;
            }
            
            const tab = document.createElement('button');
            tab.className = 'tab';
            tab.textContent = filename;
            tab.onclick = () => switchToTab(filename);
            tabList.appendChild(tab);
            
            openFiles.set(filename, { content, language });
            switchToTab(filename);
        }

        function switchToTab(filename) {
            document.querySelectorAll('.tab').forEach(tab => {
                tab.classList.toggle('active', tab.textContent === filename);
            });
            
            const file = openFiles.get(filename);
            if (file && codeEditor) {
                codeEditor.setValue(file.content);
                codeEditor.setOption('mode', getCodeMirrorMode(file.language));
                activeFile = filename;
            }
        }

        function getCodeMirrorMode(language) {
            const modes = {
                'javascript': 'javascript', 'python': 'python', 'php': 'php',
                'html': 'htmlmixed', 'css': 'css', 'sql': 'sql',
                'json': 'javascript', 'xml': 'xml'
            };
            return modes[language.toLowerCase()] || 'text/plain';
        }

        async function saveCodeToDatabase(filename, content, language) {
            const formData = new FormData();
            formData.append('save_code', '1');
            formData.append('filename', filename);
            formData.append('content', content);
            formData.append('language', language);
            
            try {
                await fetch('', { method: 'POST', body: formData });
            } catch (error) {
                console.error('Error saving code:', error);
            }
        }

        // === TERMINAL LOGS ===
        async function loadTerminalLogs() {
            if (!currentChatId) return;
            
            const formData = new FormData();
            formData.append('get_logs', '1');
            
            try {
                const response = await fetch('', { method: 'POST', body: formData });
                const data = await response.json();
                const terminal = document.getElementById('terminalContent');
                
                terminal.innerHTML = '';
                data.logs.forEach(log => {
                    const entry = document.createElement('div');
                    entry.className = `log-entry ${log.type}`;
                    
                    const timestamp = new Date(log.timestamp).toLocaleTimeString();
                    let message = '';
                    
                    try {
                        const payload = JSON.parse(log.payload);
                        if (log.type === 'request') {
                            message = `USER: ${payload.message || ''}`;
                        } else if (log.type === 'pro_mode_start') {
                            message = `PRO MODE: Starting with ${payload.candidate_count} candidates`;
                        } else if (log.type === 'pro_mode_complete') {
                            message = `PRO MODE: Complete (${payload.processing_time}s, ${payload.candidates_generated} candidates)`;
                        } else {
                            message = `API: ${JSON.stringify(payload).substring(0, 100)}...`;
                        }
                    } catch (e) {
                        message = log.payload.substring(0, 100) + '...';
                    }
                    
                    entry.innerHTML = `<span class="log-timestamp">[${timestamp}]</span>${message}`;
                    terminal.appendChild(entry);
                });
                
                terminal.scrollTop = terminal.scrollHeight;
            } catch (error) {
                console.error('Error loading logs:', error);
            }
        }

        // === CHAT MANAGEMENT ===
        async function createNewChat() {
            const formData = new FormData();
            formData.append('new_chat', '1');
            
            try {
                const response = await fetch('', { method: 'POST', body: formData });
                const data = await response.json();
                location.reload();
            } catch (error) {
                console.error('Error creating chat:', error);
            }
        }

        async function selectChat(chatId) {
            const formData = new FormData();
            formData.append('select_chat', chatId);
            
            try {
                await fetch('', { method: 'POST', body: formData });
                location.reload();
            } catch (error) {
                console.error('Error selecting chat:', error);
            }
        }

        async function deleteChat(chatId) {
            if (!confirm('Delete this chat?')) return;
            
            const formData = new FormData();
            formData.append('delete_chat', chatId);
            
            try {
                await fetch('', { method: 'POST', body: formData });
                location.reload();
            } catch (error) {
                console.error('Error deleting chat:', error);
            }
        }
    </script>
</body>
</html>