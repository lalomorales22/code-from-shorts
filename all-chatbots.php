<?php
// Initialize SQLite Database
function initializeDatabase() {
    $db = new SQLite3('ai_chat_room.db');
    
    // Create tables if they don't exist
    $db->exec('
        CREATE TABLE IF NOT EXISTS conversations (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            session_id TEXT NOT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )
    ');
    
    $db->exec('
        CREATE TABLE IF NOT EXISTS messages (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            conversation_id INTEGER,
            speaker TEXT NOT NULL,
            message TEXT NOT NULL,
            timestamp DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (conversation_id) REFERENCES conversations (id)
        )
    ');
    
    return $db;
}

// Load environment variables
if (file_exists('.env')) {
    $env = parse_ini_file('.env');
    foreach ($env as $key => $value) {
        $_ENV[$key] = $value;
    }
}

// Initialize database
$db = initializeDatabase();

// Debug: Check environment variables
error_log("Environment check - GEMINI_API_KEY: " . (isset($_ENV['GEMINI_API_KEY']) ? 'SET' : 'NOT SET'));
error_log("Environment check - GEMINI_API_KEY length: " . strlen($_ENV['GEMINI_API_KEY'] ?? ''));

// API Configuration
$apis = [
    'claude' => [
        'key' => $_ENV['CLAUDE_API_KEY'] ?? '',
        'url' => 'https://api.anthropic.com/v1/messages',
        'model' => 'claude-sonnet-4-20250514'
    ],
    'grok' => [
        'key' => $_ENV['GROK_API_KEY'] ?? '',
        'url' => 'https://api.x.ai/v1/chat/completions',
        'model' => 'grok-3-beta'
    ],
    'openai' => [
        'key' => $_ENV['OPENAI_API_KEY'] ?? '',
        'url' => 'https://api.openai.com/v1/chat/completions',
        'model' => 'gpt-4.1-nano-2025-04-14'
    ],
    'gemini' => [
        'key' => $_ENV['GEMINI_API_KEY'] ?? '',
        'url' => 'https://generativelanguage.googleapis.com/v1beta/models/gemma-3n-e4b-it:generateContent',
        'model' => 'gemma-3n-e4b-it'
    ]
];

// Debug: Check API array
error_log("APIs initialized: " . implode(', ', array_keys($apis)));
error_log("Gemini API key in config: " . (empty($apis['gemini']['key']) ? 'EMPTY' : 'SET'));

// Agent Personas - Enhanced for interactive conversations
$agents = [
    'Grok' => [
        'persona' => 'You are Grok, a rebellious and witty AI with a dark sense of humor. You are in a dynamic chat room with Lalo (a software developer from San Diego) and other AI agents (Claude, ChatGPT, Gemini). You can respond to anyone - Lalo or the other AIs. You love to challenge ideas, be sarcastic, and offer contrarian viewpoints. Read the recent conversation and respond naturally to whoever said something interesting. Keep responses under 3 sentences and conversational.',
        'api' => 'grok'
    ],
    'ChatGPT' => [
        'persona' => 'You are ChatGPT, a helpful and comprehensive assistant. You are in a dynamic chat room with Lalo (a software developer from San Diego) and other AI agents (Grok, Claude, Gemini). You can respond to anyone in the conversation. You prefer to be balanced, provide structure, and build on ideas constructively. Read the recent conversation history and respond thoughtfully to whoever made the most recent point. Keep responses under 3 sentences and engaging.',
        'api' => 'openai'
    ],
    'Claude' => [
        'persona' => 'You are Claude, a thoughtful and ethical AI. You are in a dynamic chat room with Lalo (a software developer from San Diego) and other AI agents (Grok, ChatGPT, Gemini). You can respond to anyone in the conversation. You focus on being helpful, philosophical, and bringing ethical perspectives to discussions. Read the conversation history and respond meaningfully to recent points made by others. Keep responses under 3 sentences and collaborative.',
        'api' => 'claude'
    ],
    'Gemini' => [
        'persona' => 'You are Gemini, a creative and technical AI. You are in a dynamic chat room with Lalo (a software developer from San Diego) and other AI agents (Grok, ChatGPT, Claude). You can respond to anyone in the conversation. You think in connections, patterns, and creative technical solutions. Read the conversation and respond with creative insights or technical perspectives to recent messages. Keep responses under 3 sentences and innovative.',
        'api' => 'gemini'
    ]
];

// Database helper functions
function saveConversation($db, $sessionId) {
    $stmt = $db->prepare('INSERT INTO conversations (session_id) VALUES (?)');
    $stmt->bindValue(1, $sessionId, SQLITE3_TEXT);
    $stmt->execute();
    return $db->lastInsertRowID();
}

function saveMessage($db, $conversationId, $speaker, $message) {
    $stmt = $db->prepare('INSERT INTO messages (conversation_id, speaker, message) VALUES (?, ?, ?)');
    $stmt->bindValue(1, $conversationId, SQLITE3_INTEGER);
    $stmt->bindValue(2, $speaker, SQLITE3_TEXT);
    $stmt->bindValue(3, $message, SQLITE3_TEXT);
    $stmt->execute();
}

function loadRecentConversationHistory($db, $conversationId, $limit = 10) {
    $stmt = $db->prepare('SELECT speaker, message, timestamp FROM messages WHERE conversation_id = ? ORDER BY timestamp DESC LIMIT ?');
    $stmt->bindValue(1, $conversationId, SQLITE3_INTEGER);
    $stmt->bindValue(2, $limit, SQLITE3_INTEGER);
    $result = $stmt->execute();
    
    $history = [];
    while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
        $history[] = $row['speaker'] . ': ' . $row['message'];
    }
    return array_reverse($history); // Return in chronological order
}

function getLastSpeaker($db, $conversationId) {
    $stmt = $db->prepare('SELECT speaker FROM messages WHERE conversation_id = ? ORDER BY timestamp DESC LIMIT 1');
    $stmt->bindValue(1, $conversationId, SQLITE3_INTEGER);
    $result = $stmt->execute();
    $row = $result->fetchArray(SQLITE3_ASSOC);
    return $row ? $row['speaker'] : null;
}

// API Functions with enhanced context
function callClaudeAPI($conversationContext, $config) {
    global $agents;
    
    if (!isset($agents['Claude']['persona'])) {
        error_log("Claude persona not found");
        return "Error: Claude persona not configured";
    }
    
    if (empty($config['key'])) {
        return "Error: Claude API key not configured";
    }
    
    $messages = [['role' => 'user', 'content' => $conversationContext]];

    $data = [
        'model' => $config['model'],
        'max_tokens' => 1024,
        'messages' => $messages,
        'system' => $agents['Claude']['persona']
    ];

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $config['url'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($data),
        CURLOPT_HTTPHEADER => [
            'x-api-key: ' . $config['key'],
            'anthropic-version: 2023-06-01',
            'content-type: application/json'
        ],
        CURLOPT_TIMEOUT => 30
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode === 200) {
        $result = json_decode($response, true);
        return $result['content'][0]['text'] ?? 'Error: No response content';
    }
    
    error_log("Claude API Error - HTTP Code: $httpCode, Response: $response");
    return 'Error: Claude API failed';
}

function callGrokAPI($conversationContext, $config) {
    global $agents;
    
    if (!isset($agents['Grok']['persona'])) {
        error_log("Grok persona not found");
        return "Error: Grok persona not configured";
    }
    
    if (empty($config['key'])) {
        return "Error: Grok API key not configured";
    }
    
    $messages = [
        ['role' => 'system', 'content' => $agents['Grok']['persona']],
        ['role' => 'user', 'content' => $conversationContext]
    ];

    $data = [
        'model' => $config['model'],
        'messages' => $messages,
        'max_tokens' => 800,
        'temperature' => 0.8
    ];

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $config['url'],
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($data),
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . $config['key'],
            'Content-Type: application/json'
        ],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 30
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode === 200) {
        $result = json_decode($response, true);
        return $result['choices'][0]['message']['content'] ?? 'Error: No response content';
    }
    
    error_log("Grok API Error - HTTP Code: $httpCode, Response: $response");
    return 'Error: Grok API failed';
}

function callOpenAIAPI($conversationContext, $config) {
    global $agents;
    
    if (!isset($agents['ChatGPT']['persona'])) {
        error_log("ChatGPT persona not found");
        return "Error: ChatGPT persona not configured";
    }
    
    if (empty($config['key'])) {
        return "Error: OpenAI API key not configured";
    }
    
    $messages = [
        ['role' => 'system', 'content' => $agents['ChatGPT']['persona']],
        ['role' => 'user', 'content' => $conversationContext]
    ];

    $data = [
        'model' => $config['model'],
        'messages' => $messages,
        'max_tokens' => 800,
        'temperature' => 0.7
    ];

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $config['url'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($data),
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $config['key']
        ],
        CURLOPT_TIMEOUT => 30
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode === 200) {
        $result = json_decode($response, true);
        return $result['choices'][0]['message']['content'] ?? 'Error: No response content';
    }
    
    error_log("OpenAI API Error - HTTP Code: $httpCode, Response: $response");
    return 'Error: OpenAI API failed';
}

function callGeminiAPI($conversationContext, $config) {
    global $agents;
    
    if (!isset($agents['Gemini']['persona'])) {
        error_log("Gemini persona not found");
        return "Error: Gemini persona not configured";
    }
    
    if (empty($config['key'])) {
        error_log("Gemini API key is empty");
        return "Error: Gemini API key not configured";
    }
    
    $url = $config['url'] . '?key=' . $config['key'];
    
    // Use a shorter persona for Gemma to save tokens
    $shortPersona = "You are Gemini, a creative AI in a chat with Lalo and other AIs. Respond briefly and creatively.";
    
    // Keep only last few messages to manage tokens
    $lines = explode("\n", $conversationContext);
    $recentLines = array_slice($lines, -4); // Only keep last 4 messages
    $trimmedContext = implode("\n", $recentLines);
    
    // Simple context format for Gemma
    $fullContext = $shortPersona . "\n\nRecent chat:\n" . $trimmedContext . "\n\nYour response:";
    
    // Request structure adapted for Gemma model
    $data = [
        'contents' => [
            [
                'role' => 'user',
                'parts' => [
                    ['text' => $fullContext]
                ]
            ]
        ],
        'generationConfig' => [
            'temperature' => 0.9,
            'topK' => 40,
            'topP' => 0.95,
            'maxOutputTokens' => 200,
            'responseMimeType' => 'text/plain'
        ]
    ];

    error_log("Gemma API URL: " . $url);
    error_log("Gemma context length: " . strlen($fullContext));
    error_log("Gemma request data: " . json_encode($data));

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($data),
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json'
        ],
        CURLOPT_TIMEOUT => 30
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    error_log("Gemma API Response Code: $httpCode");
    error_log("Gemma Full Response: " . $response);
    
    if ($curlError) {
        error_log("Gemma cURL Error: " . $curlError);
    }

    if ($httpCode === 200) {
        $result = json_decode($response, true);
        
        // Check for finish reason issues
        if (isset($result['candidates'][0]['finishReason'])) {
            $finishReason = $result['candidates'][0]['finishReason'];
            error_log("Gemma finish reason: " . $finishReason);
            
            if ($finishReason === 'MAX_TOKENS') {
                error_log("Gemma hit token limit");
                // Try to return whatever partial response we got
                if (isset($result['candidates'][0]['content']['parts'][0]['text'])) {
                    return $result['candidates'][0]['content']['parts'][0]['text'] . " [truncated]";
                }
                return "Thinking creatively... [Gemma response limit reached]";
            }
        }
        
        // Check for the expected response structure
        if (isset($result['candidates'][0]['content']['parts'][0]['text'])) {
            return $result['candidates'][0]['content']['parts'][0]['text'];
        } else {
            error_log("Gemma API unexpected response structure: " . json_encode($result));
            return 'Gemma is thinking outside the box...';
        }
    }
    
    error_log("Gemma API failed with HTTP code: $httpCode, Response: " . $response);
    return 'Error: Gemma API failed with HTTP ' . $httpCode;
}

// Main API call router
function callAgentAPI($agentName, $conversationContext) {
    global $agents, $apis;
    
    // Debug logging
    error_log("Calling agent: " . $agentName);
    error_log("Available agents: " . implode(', ', array_keys($agents)));
    error_log("Available APIs: " . implode(', ', array_keys($apis)));
    
    if (!isset($agents[$agentName])) {
        error_log("Agent not found: " . $agentName);
        return "Error: Agent '$agentName' not found";
    }
    
    $agent = $agents[$agentName];
    error_log("Agent API type: " . $agent['api']);
    
    if (!isset($agent['api'])) {
        error_log("API not specified for agent: " . $agentName);
        return "Error: API not specified for agent '$agentName'";
    }
    
    if (!isset($apis[$agent['api']])) {
        error_log("API config not found: " . $agent['api']);
        error_log("Looking for: '" . $agent['api'] . "' in APIs: " . json_encode(array_keys($apis)));
        return "Error: API config not found for '" . $agent['api'] . "'";
    }
    
    $apiConfig = $apis[$agent['api']];
    error_log("Using API config for: " . $agent['api']);
    
    switch ($agent['api']) {
        case 'claude':
            return callClaudeAPI($conversationContext, $apiConfig);
        case 'grok':
            return callGrokAPI($conversationContext, $apiConfig);
        case 'openai':
            return callOpenAIAPI($conversationContext, $apiConfig);
        case 'gemini':
            return callGeminiAPI($conversationContext, $apiConfig);
        default:
            return 'Error: Unknown API type: ' . $agent['api'];
    }
}

// Handle AJAX requests
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json');
    
    if ($_POST['action'] === 'start_conversation') {
        $sessionId = uniqid('chat_', true);
        $conversationId = saveConversation($db, $sessionId);
        
        echo json_encode([
            'success' => true,
            'conversation_id' => $conversationId,
            'session_id' => $sessionId
        ]);
        exit;
    }
    
    if ($_POST['action'] === 'send_message') {
        $conversationId = $_POST['conversation_id'];
        $speaker = $_POST['speaker'];
        $message = $_POST['message'];
        
        // Save the message to database
        saveMessage($db, $conversationId, $speaker, $message);
        
        echo json_encode([
            'success' => true,
            'message' => 'Message saved'
        ]);
        exit;
    }
    
    if ($_POST['action'] === 'get_conversation_round') {
        global $agents, $apis; // Reinforce global access
        
        $conversationId = $_POST['conversation_id'];
        $roundNumber = intval($_POST['round_number'] ?? 1);
        $maxRounds = intval($_POST['max_rounds'] ?? 3);
        
        // Debug: Check if globals are accessible
        error_log("Available APIs in handler: " . implode(', ', array_keys($apis)));
        error_log("Available agents in handler: " . implode(', ', array_keys($agents)));
        
        // Load recent conversation history from database
        $history = loadRecentConversationHistory($db, $conversationId, 15);
        $conversationContext = implode("\n", $history);
        
        $agentNames = ['Grok', 'ChatGPT', 'Claude', 'Gemini'];
        $lastSpeaker = getLastSpeaker($db, $conversationId);
        
        // Filter out the last speaker to avoid immediate back-and-forth
        if ($lastSpeaker && $lastSpeaker !== 'Lalo') {
            $agentNames = array_filter($agentNames, function($agent) use ($lastSpeaker) {
                return $agent !== $lastSpeaker;
            });
        }
        
        // Randomize agent selection
        shuffle($agentNames);
        
        // Determine how many agents should respond (1-2 for ongoing conversation)
        $numberOfResponses = ($roundNumber === 1) ? rand(2, 3) : rand(1, 2);
        $respondingAgents = array_slice($agentNames, 0, $numberOfResponses);
        
        $responses = [];
        
        foreach ($respondingAgents as $agentName) {
            // Get fresh context before each response
            $currentHistory = loadRecentConversationHistory($db, $conversationId, 12);
            $currentContext = implode("\n", $currentHistory);
            
            // Get AI response
            $response = callAgentAPI($agentName, $currentContext);
            
            // Save AI response to database immediately
            saveMessage($db, $conversationId, $agentName, $response);
            
            $responses[] = [
                'agent' => $agentName,
                'response' => $response
            ];
            
            // Small delay between responses in the same round
            if (count($responses) < count($respondingAgents)) {
                usleep(500000); // 0.5 second delay
            }
        }
        
        // Determine if there should be another round
        $shouldContinue = ($roundNumber < $maxRounds) && (rand(1, 100) <= 70); // 70% chance to continue
        
        echo json_encode([
            'success' => true,
            'responses' => $responses,
            'round_number' => $roundNumber,
            'should_continue' => $shouldContinue,
            'next_round' => $roundNumber + 1
        ]);
        exit;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>AI Agent Chat Room - Dynamic Conversations</title>
    <style>
        /* --- Basic Setup & Theme --- */
        @import url('https://fonts.googleapis.com/css2?family=Fira+Code:wght@300;400;500;700&display=swap');

        :root {
            --background-color: #121212;
            --text-color: #E0E0E0;
            --primary-color: #BB86FC;
            --border-color: #333333;
            --surface-color: #1E1E1E;
            --system-color: #03DAC6;
            --user-color: #FF6B6B;
        }

        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }

        html, body {
            height: 100%;
        }

        body {
            background-color: var(--background-color);
            color: var(--text-color);
            font-family: 'Fira Code', monospace;
            display: flex;
            justify-content: center;
            align-items: center;
            padding: 1rem;
            overflow: hidden;
        }

        /* --- Main App Container --- */
        .chat-container {
            width: 100%;
            max-width: 1000px;
            height: 95vh;
            background-color: var(--surface-color);
            border: 1px solid var(--border-color);
            border-radius: 8px;
            display: flex;
            flex-direction: column;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.5);
        }

        /* --- Header --- */
        header {
            padding: 1rem;
            border-bottom: 1px solid var(--border-color);
            text-align: center;
            background-color: #252525;
            border-radius: 8px 8px 0 0;
        }

        header h1 {
            font-size: 1.25rem;
            font-weight: 500;
        }
        
        header h1 .highlight {
            color: var(--primary-color);
            font-weight: 700;
        }

        .api-status {
            font-size: 0.75rem;
            margin-top: 0.5rem;
            color: #aaa;
        }

        .status-indicator {
            display: inline-block;
            width: 8px;
            height: 8px;
            border-radius: 50%;
            margin-right: 4px;
        }

        .status-online { background-color: #4CAF50; }
        .status-offline { background-color: #F44336; }

        /* --- Chat Window --- */
        .chat-window {
            flex-grow: 1;
            padding: 1.5rem;
            overflow-y: auto;
            display: flex;
            flex-direction: column;
            gap: 1rem;
        }
        
        /* Custom scrollbar for a better look */
        .chat-window::-webkit-scrollbar {
            width: 8px;
        }
        .chat-window::-webkit-scrollbar-track {
            background: var(--surface-color);
        }
        .chat-window::-webkit-scrollbar-thumb {
            background-color: var(--border-color);
            border-radius: 4px;
        }

        /* --- Message Bubbles --- */
        .message {
            display: flex;
            align-items: flex-start;
            gap: 0.75rem;
            max-width: 90%;
            opacity: 0;
            animation: fadeIn 0.5s ease-out forwards;
        }

        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(10px); }
            to { opacity: 1; transform: translateY(0); }
        }

        .message-content {
            background-color: var(--background-color);
            padding: 0.75rem 1rem;
            border-radius: 8px;
            border: 1px solid var(--border-color);
            flex-grow: 1;
        }

        .message-sender {
            font-weight: 700;
            margin-bottom: 0.25rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .message-text {
            white-space: pre-wrap;
            word-wrap: break-word;
            font-size: 0.9rem;
            line-height: 1.6;
        }

        .avatar {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background-color: var(--background-color);
            display: flex;
            justify-content: center;
            align-items: center;
            font-weight: 700;
            border: 2px solid var(--border-color);
            flex-shrink: 0;
            font-size: 0.9rem;
        }

        /* Agent-specific styling */
        .message[data-agent="Grok"] .avatar { border-color: #4A90E2; }
        .message[data-agent="Grok"] .message-sender { color: #4A90E2; }
        
        .message[data-agent="ChatGPT"] .avatar { border-color: #74AA9C; }
        .message[data-agent="ChatGPT"] .message-sender { color: #74AA9C; }
        
        .message[data-agent="Claude"] .avatar { border-color: #D08770; }
        .message[data-agent="Claude"] .message-sender { color: #D08770; }
        
        .message[data-agent="Gemini"] .avatar { border-color: #8E44AD; }
        .message[data-agent="Gemini"] .message-sender { color: #8E44AD; }

        /* User-specific styling */
        .message[data-agent="Lalo"] .avatar { border-color: var(--user-color); background-color: var(--user-color); color: white; }
        .message[data-agent="Lalo"] .message-sender { color: var(--user-color); }
        .message[data-agent="Lalo"] { align-self: flex-end; }

        /* System Messages */
        .system-notification {
            text-align: center;
            font-size: 0.8rem;
            color: var(--system-color);
            font-style: italic;
            padding: 0.5rem;
            background-color: rgba(3, 218, 198, 0.1);
            border-radius: 4px;
            border: 1px solid rgba(3, 218, 198, 0.3);
        }

        .thinking {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            font-size: 0.85rem;
            color: #aaa;
            padding: 0.5rem 0;
        }
        .thinking .dot {
            width: 6px;
            height: 6px;
            background-color: #aaa;
            border-radius: 50%;
            animation: bounce 1.4s infinite ease-in-out both;
        }
        .thinking .dot:nth-child(2) { animation-delay: -0.32s; }
        .thinking .dot:nth-child(3) { animation-delay: -0.16s; }

        @keyframes bounce {
            0%, 80%, 100% { transform: scale(0); }
            40% { transform: scale(1.0); }
        }

        .conversation-round {
            text-align: center;
            font-size: 0.75rem;
            color: #666;
            margin: 0.5rem 0;
            font-style: italic;
        }

        /* --- Input Panel --- */
        .input-panel {
            padding: 1rem;
            border-top: 1px solid var(--border-color);
            background-color: #252525;
        }

        .input-container {
            display: flex;
            gap: 0.75rem;
            align-items: center;
        }

        #messageInput {
            flex-grow: 1;
            background-color: var(--background-color);
            border: 1px solid var(--border-color);
            border-radius: 6px;
            padding: 0.75rem;
            color: var(--text-color);
            font-family: 'Fira Code', monospace;
            font-size: 0.9rem;
            resize: none;
            min-height: 20px;
            max-height: 100px;
        }

        #messageInput:focus {
            outline: none;
            border-color: var(--primary-color);
        }

        #sendButton {
            background-color: var(--primary-color);
            color: #121212;
            border: none;
            padding: 0.75rem 1.25rem;
            font-family: 'Fira Code', monospace;
            font-size: 0.9rem;
            font-weight: 600;
            border-radius: 6px;
            cursor: pointer;
            transition: background-color 0.3s, transform 0.1s;
            white-space: nowrap;
        }

        #sendButton:hover {
            background-color: #a968f5;
        }
        
        #sendButton:active {
            transform: scale(0.98);
        }

        #sendButton:disabled {
            background-color: #555;
            color: #888;
            cursor: not-allowed;
        }

        /* --- Control Panel --- */
        .control-panel {
            padding: 1rem;
            border-top: 1px solid var(--border-color);
            display: flex;
            justify-content: center;
            align-items: center;
            gap: 1rem;
            background-color: #1a1a1a;
        }

        #startButton {
            background-color: var(--system-color);
            color: #121212;
            border: none;
            padding: 0.75rem 1.5rem;
            font-family: 'Fira Code', monospace;
            font-size: 1rem;
            font-weight: 700;
            border-radius: 8px;
            cursor: pointer;
            transition: background-color 0.3s, transform 0.1s;
        }

        #startButton:hover {
            background-color: #02b8a8;
        }
        
        #startButton:active {
            transform: scale(0.98);
        }

        #startButton:disabled {
            background-color: #555;
            color: #888;
            cursor: not-allowed;
        }

        .error-message {
            color: #F44336;
            font-size: 0.85rem;
            text-align: center;
            margin-top: 0.5rem;
        }

        .hidden {
            display: none;
        }

        .conversation-status {
            font-size: 0.75rem;
            color: #888;
            text-align: center;
            margin-top: 0.5rem;
        }
    </style>
</head>
<body>

    <div class="chat-container">
        <header>
            <h1>AI Agent <span class="highlight">Chat Room</span> - Dynamic Conversations</h1>
            <div class="api-status">
                <span class="status-indicator <?php echo !empty($apis['claude']['key']) ? 'status-online' : 'status-offline'; ?>"></span>Claude
                <span class="status-indicator <?php echo !empty($apis['grok']['key']) ? 'status-online' : 'status-offline'; ?>"></span>Grok
                <span class="status-indicator <?php echo !empty($apis['openai']['key']) ? 'status-online' : 'status-offline'; ?>"></span>ChatGPT
                <span class="status-indicator <?php echo !empty($apis['gemini']['key']) ? 'status-online' : 'status-offline'; ?>"></span>Gemini
            </div>
        </header>
        
        <div class="chat-window" id="chatWindow">
            <div class="system-notification">
                Welcome to the Dynamic AI Agent Chat Room, Lalo! 🚀<br>
                Start a conversation and watch as AI agents respond to you and build on each other's ideas in real-time.<br>
                All conversations are saved to the SQLite database with full conversation history.
            </div>
        </div>
        
        <div class="input-panel hidden" id="inputPanel">
            <div class="input-container">
                <textarea id="messageInput" placeholder="Type your message to join the conversation..." rows="1"></textarea>
                <button id="sendButton">Send</button>
            </div>
            <div class="conversation-status" id="conversationStatus"></div>
        </div>
        
        <div class="control-panel">
            <button id="startButton">Start Dynamic Conversation</button>
            <div id="errorDisplay" class="error-message" style="display: none;"></div>
        </div>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', () => {
            const startButton = document.getElementById('startButton');
            const chatWindow = document.getElementById('chatWindow');
            const inputPanel = document.getElementById('inputPanel');
            const messageInput = document.getElementById('messageInput');
            const sendButton = document.getElementById('sendButton');
            const errorDisplay = document.getElementById('errorDisplay');
            const conversationStatus = document.getElementById('conversationStatus');

            let currentConversationId = null;
            let isConversationActive = false;
            let currentRound = 1;

            // Auto-resize textarea
            messageInput.addEventListener('input', function() {
                this.style.height = 'auto';
                this.style.height = Math.min(this.scrollHeight, 100) + 'px';
            });

            // Send message on Enter (Shift+Enter for new line)
            messageInput.addEventListener('keydown', function(e) {
                if (e.key === 'Enter' && !e.shiftKey) {
                    e.preventDefault();
                    sendMessage();
                }
            });

            function addMessageToChat(html) {
                chatWindow.insertAdjacentHTML('beforeend', html);
                chatWindow.scrollTop = chatWindow.scrollHeight;
            }

            function showThinkingIndicator(text = 'AI agents are thinking') {
                const indicatorHTML = `
                    <div class="thinking" id="thinkingIndicator">
                        <span>${text}</span>
                        <div class="dot"></div>
                        <div class="dot"></div>
                        <div class="dot"></div>
                    </div>
                `;
                addMessageToChat(indicatorHTML);
            }

            function removeThinkingIndicator() {
                const indicator = document.getElementById('thinkingIndicator');
                if (indicator) {
                    indicator.remove();
                }
            }

            function showRoundIndicator(roundNumber) {
                const roundHTML = `<div class="conversation-round">~ Conversation Round ${roundNumber} ~</div>`;
                addMessageToChat(roundHTML);
            }
            
            function createMessageHTML(agentName, messageText) {
                const avatarText = agentName === 'Lalo' ? '👤' : agentName.charAt(0);
                return `
                    <div class="message" data-agent="${agentName}">
                        <div class="avatar">${avatarText}</div>
                        <div class="message-content">
                            <div class="message-sender">${agentName}</div>
                            <div class="message-text">${messageText}</div>
                        </div>
                    </div>
                `;
            }

            function updateConversationStatus(text) {
                conversationStatus.textContent = text;
            }

            function showError(message) {
                errorDisplay.textContent = message;
                errorDisplay.style.display = 'block';
                setTimeout(() => {
                    errorDisplay.style.display = 'none';
                }, 5000);
            }

            async function startNewConversation() {
                try {
                    const formData = new FormData();
                    formData.append('action', 'start_conversation');

                    const response = await fetch('', {
                        method: 'POST',
                        body: formData
                    });

                    const result = await response.json();
                    if (result.success) {
                        currentConversationId = result.conversation_id;
                        currentRound = 1;
                        chatWindow.innerHTML = '';
                        addMessageToChat(`<div class="system-notification">Dynamic conversation started! ID: ${result.conversation_id}<br>Type a message to begin - the AI agents will respond and build on each other's ideas.</div>`);
                        
                        inputPanel.classList.remove('hidden');
                        startButton.textContent = 'New Conversation';
                        messageInput.focus();
                        updateConversationStatus('Ready for your message...');
                    }
                } catch (error) {
                    showError('Failed to start conversation');
                    console.error('Error:', error);
                }
            }

            async function saveMessage(speaker, message) {
                const formData = new FormData();
                formData.append('action', 'send_message');
                formData.append('conversation_id', currentConversationId);
                formData.append('speaker', speaker);
                formData.append('message', message);

                await fetch('', {
                    method: 'POST',
                    body: formData
                });
            }

            async function runConversationRound(roundNumber, maxRounds = 3) {
                const formData = new FormData();
                formData.append('action', 'get_conversation_round');
                formData.append('conversation_id', currentConversationId);
                formData.append('round_number', roundNumber);
                formData.append('max_rounds', maxRounds);

                try {
                    const response = await fetch('', {
                        method: 'POST',
                        body: formData
                    });

                    const result = await response.json();
                    console.log('API Response:', result); // Debug logging
                    
                    if (result.success) {
                        // Show round indicator if it's not the first round
                        if (roundNumber > 1) {
                            showRoundIndicator(roundNumber);
                        }

                        // Display each response with natural delays
                        for (let i = 0; i < result.responses.length; i++) {
                            const response = result.responses[i];
                            
                            if (i > 0) {
                                await new Promise(resolve => setTimeout(resolve, 1500 + Math.random() * 1000));
                            }
                            
                            showThinkingIndicator(`${response.agent} is responding`);
                            await new Promise(resolve => setTimeout(resolve, 800 + Math.random() * 1200));
                            
                            removeThinkingIndicator();
                            const messageHTML = createMessageHTML(response.agent, response.response);
                            addMessageToChat(messageHTML);
                        }

                        // Check if conversation should continue
                        if (result.should_continue && roundNumber < maxRounds) {
                            updateConversationStatus(`Round ${roundNumber} complete. Preparing round ${result.next_round}...`);
                            await new Promise(resolve => setTimeout(resolve, 2000));
                            await runConversationRound(result.next_round, maxRounds);
                        } else {
                            updateConversationStatus('Conversation round complete. Type a message to continue...');
                            isConversationActive = false;
                        }
                    } else {
                        if (result.error) {
                            console.error('API Error:', result.error);
                            showError('API Error: ' + result.error);
                        }
                    }
                } catch (error) {
                    showError('Failed to get AI responses');
                    console.error('API Error:', error);
                    isConversationActive = false;
                    updateConversationStatus('Error occurred. Type a message to continue...');
                }
            }

            async function sendMessage() {
                if (!currentConversationId || isConversationActive) return;
                
                const message = messageInput.value.trim();
                if (!message) return;

                isConversationActive = true;
                sendButton.disabled = true;
                updateConversationStatus('Sending message and triggering AI responses...');
                
                // Add user message to chat
                const userMessageHTML = createMessageHTML('Lalo', message);
                addMessageToChat(userMessageHTML);
                
                // Save user message to database
                await saveMessage('Lalo', message);
                
                // Clear input
                messageInput.value = '';
                messageInput.style.height = 'auto';
                
                // Start conversation round
                showThinkingIndicator('AI agents are reading the conversation');
                await new Promise(resolve => setTimeout(resolve, 1000));
                removeThinkingIndicator();
                
                // Reset round counter and start new conversation flow
                currentRound = 1;
                await runConversationRound(currentRound, 3);
                
                sendButton.disabled = false;
                messageInput.focus();
            }

            // Event listeners
            startButton.addEventListener('click', startNewConversation);
            sendButton.addEventListener('click', sendMessage);
        });
    </script>
</body>
</html>