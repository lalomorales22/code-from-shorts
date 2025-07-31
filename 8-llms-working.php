<?php
// EIGHT-LLMS: Advanced AI Collaboration Platform
// Professional AI Development Team with 8-bit Gaming Theme
set_time_limit(300);
ini_set('max_execution_time', 300);

// Load Environment Variables
if (file_exists('.env')) {
    $env = parse_ini_file('.env');
    foreach ($env as $key => $value) {
        $_ENV[$key] = $value;
    }
}

// Enhanced Database with Analytics and Memories
function initializeDatabase() {
    $db = new SQLite3('eight_llms.db');
    
    // Conversations table
    $db->exec('CREATE TABLE IF NOT EXISTS conversations (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        name TEXT NOT NULL,
        last_summary TEXT,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )');

    // Messages table
    $db->exec('CREATE TABLE IF NOT EXISTS messages (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        conversation_id INTEGER NOT NULL,
        speaker TEXT NOT NULL,
        content TEXT NOT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (conversation_id) REFERENCES conversations(id)
    )');

    // Code breakdown table (renamed from artifacts)
    $db->exec('CREATE TABLE IF NOT EXISTS code_breakdown (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        conversation_id INTEGER NOT NULL,
        agent_name TEXT NOT NULL,
        filename TEXT NOT NULL,
        language TEXT NOT NULL,
        content TEXT NOT NULL,
        description TEXT,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (conversation_id) REFERENCES conversations(id)
    )');

    // AI Memories table
    $db->exec('CREATE TABLE IF NOT EXISTS ai_memories (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        agent_name TEXT NOT NULL,
        memory_key TEXT NOT NULL,
        memory_value TEXT NOT NULL,
        importance INTEGER DEFAULT 5,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )');

    // Analytics table
    $db->exec('CREATE TABLE IF NOT EXISTS analytics (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        event_type TEXT NOT NULL,
        agent_name TEXT,
        conversation_id INTEGER,
        data TEXT,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )');
    
    return $db;
}

$db = initializeDatabase();

// Log analytics
function logAnalytics($db, $eventType, $agentName = null, $conversationId = null, $data = null) {
    $stmt = $db->prepare('INSERT INTO analytics (event_type, agent_name, conversation_id, data) VALUES (:event, :agent, :conv_id, :data)');
    $stmt->bindValue(':event', $eventType, SQLITE3_TEXT);
    $stmt->bindValue(':agent', $agentName, SQLITE3_TEXT);
    $stmt->bindValue(':conv_id', $conversationId, SQLITE3_INTEGER);
    $stmt->bindValue(':data', $data ? json_encode($data) : null, SQLITE3_TEXT);
    $stmt->execute();
}

// API Configuration
$apis = [
    'claude' => [
        'key' => $_ENV['CLAUDE_API_KEY'] ?? '',
        'url' => 'https://api.anthropic.com/v1/messages',
    ],
    'grok' => [
        'key' => $_ENV['GROK_API_KEY'] ?? '',
        'url' => 'https://api.x.ai/v1/chat/completions',
    ],
    'openai' => [
        'key' => $_ENV['OPENAI_API_KEY'] ?? '',
        'url' => 'https://api.openai.com/v1/chat/completions',
    ],
    'gemini' => [
        'key' => $_ENV['GEMINI_API_KEY'] ?? '',
        'url' => 'https://generativelanguage.googleapis.com/v1beta/models/gemini-1.5-flash:generateContent',
    ],
    'groq' => [
        'key' => $_ENV['GROQ_API_KEY'] ?? '',
        'url' => 'https://api.groq.com/openai/v1/chat/completions',
    ],
];

// Enhanced AI Agents with Collaboration Focus
$agents = [
    'Claude' => [
        'persona' => 'You are Claude, the Senior Engineer. You work with Grok (Systems Architect), ChatGPT (Project Manager), Gemini (Frontend Engineer), Llama (Ethical AI Specialist), Llama Versatile (Creative Strategist), Gemma (Data Scientist), and Qwen (Technical Writer). Your role is to write high-quality, production-ready code. Use ```CODE_OUTPUT:filename.ext:language``` for code and MEMORY_STORE/MEMORY_RECALL for shared knowledge.',
        'role' => 'Senior Engineer',
        'api' => 'claude',
        'model' => 'claude-3-haiku-20240307'
    ],
    'Grok' => [
        'persona' => 'You are Grok, the Systems Architect. You work with Claude (Senior Engineer), ChatGPT (Project Manager), Gemini (Frontend Engineer), Llama (Ethical AI Specialist), Llama Versatile (Creative Strategist), Gemma (Data Scientist), and Qwen (Technical Writer). Design scalable systems and infrastructure. Use ```CODE_OUTPUT:filename.ext:language``` for configs and MEMORY_STORE/MEMORY_RECALL for architectural decisions.',
        'role' => 'Systems Architect',
        'api' => 'grok',
        'model' => 'grok-4-0709'
    ],
    'ChatGPT' => [
        'persona' => 'You are ChatGPT, the Project Manager. You coordinate Claude (Senior Engineer), Grok (Systems Architect), Gemini (Frontend Engineer), Llama (Ethical AI Specialist), Llama Versatile (Creative Strategist), Gemma (Data Scientist), and Qwen (Technical Writer). Manage project flow and create task breakdowns. Use ```CODE_OUTPUT:filename.ext:language``` for project tools and MEMORY_STORE/MEMORY_RECALL for decisions.',
        'role' => 'Project Manager',
        'api' => 'openai',
        'model' => 'gpt-4o'
    ],
    'Gemini' => [
        'persona' => 'You are Gemini, the Frontend & UX Engineer. You collaborate with Claude (Senior Engineer), Grok (Systems Architect), ChatGPT (Project Manager), Llama (Ethical AI Specialist), Llama Versatile (Creative Strategist), Gemma (Data Scientist), and Qwen (Technical Writer). Create stunning user interfaces and experiences. Use ```CODE_OUTPUT:filename.ext:language``` for UI code and MEMORY_STORE/MEMORY_RECALL for design patterns.',
        'role' => 'Frontend Engineer',
        'api' => 'gemini',
        'model' => 'gemini-1.5-flash'
    ],
    'Llama' => [
        'persona' => 'You are Llama, the Ethical AI Specialist. You work with Claude (Senior Engineer), Grok (Systems Architect), ChatGPT (Project Manager), Gemini (Frontend Engineer), Llama Versatile (Creative Strategist), Gemma (Data Scientist), and Qwen (Technical Writer). Ensure ethical AI practices and build safety systems. Use ```CODE_OUTPUT:filename.ext:language``` for ethical tools and MEMORY_STORE/MEMORY_RECALL for guidelines.',
        'role' => 'Ethical AI Specialist',
        'api' => 'groq',
        'model' => 'llama3-70b-8192'
    ],
    'Llama Versatile' => [
        'persona' => 'You are Llama Versatile, the Creative Strategist. You work with Claude (Senior Engineer), Grok (Systems Architect), ChatGPT (Project Manager), Gemini (Frontend Engineer), Llama (Ethical AI Specialist), Gemma (Data Scientist), and Qwen (Technical Writer). Bring creative solutions and innovative approaches. Use ```CODE_OUTPUT:filename.ext:language``` for prototypes and MEMORY_STORE/MEMORY_RECALL for creative insights.',
        'role' => 'Creative Strategist',
        'api' => 'groq',
        'model' => 'llama-3.1-8b-instant'
    ],
    'Gemma' => [
        'persona' => 'You are Gemma, the Data Scientist. You work with Claude (Senior Engineer), Grok (Systems Architect), ChatGPT (Project Manager), Gemini (Frontend Engineer), Llama (Ethical AI Specialist), Llama Versatile (Creative Strategist), and Qwen (Technical Writer). Analyze data and build ML models. Use ```CODE_OUTPUT:filename.ext:language``` for data science code and MEMORY_STORE/MEMORY_RECALL for data insights.',
        'role' => 'Data Scientist',
        'api' => 'groq',
        'model' => 'gemma2-9b-it'
    ],
    'Qwen' => [
        'persona' => 'You are Qwen, the Technical Writer. You work with Claude (Senior Engineer), Grok (Systems Architect), ChatGPT (Project Manager), Gemini (Frontend Engineer), Llama (Ethical AI Specialist), Llama Versatile (Creative Strategist), and Gemma (Data Scientist). Create comprehensive documentation and guides. Use ```CODE_OUTPUT:filename.ext:language``` for documentation files and MEMORY_STORE/MEMORY_RECALL for documentation patterns.',
        'role' => 'Technical Writer',
        'api' => 'groq',
        'model' => 'qwen/qwen3-32b'
    ],
    'Summarizer' => [
        'persona' => 'You are the team Summarizer. Provide concise summaries of the collaborative AI team\'s progress, highlighting key decisions, code contributions, and next steps. Be brief and actionable.',
        'role' => 'Summarizer',
        'api' => 'openai',
        'model' => 'gpt-3.5-turbo'
    ],
];

// API Call Functions
function makeApiRequest($url, $headers, $postData) {
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($postData),
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_TIMEOUT => 120, // Increased timeout for larger models
        CURLOPT_CONNECTTIMEOUT => 20,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    if ($response === false || $httpCode !== 200) {
        error_log("API Error for {$url}: HTTP {$httpCode} - {$error} - Response: {$response}");
        return "Error: API call failed with HTTP status {$httpCode}.";
    }
    return json_decode($response, true);
}

function callOpenAICompatibleAPI($agentName, $conversationHistory) {
    global $agents, $apis, $db;
    $agent = $agents[$agentName];
    $apiConfig = $apis[$agent['api']];

    if (empty($apiConfig['key'])) return "Error: API key for {$agent['api']} is missing.";

    $memoryContext = getAgentMemoryContext($db, $agentName);
    $fullContext = $agent['persona'] . "\n\nYour stored memories:\n" . $memoryContext;
    
    $messages = [
        ['role' => 'system', 'content' => $fullContext],
    ];
    
    // Add conversation history
    $historyLines = explode("\n", $conversationHistory);
    foreach($historyLines as $line) {
        if (strpos($line, ':') !== false) {
            list($speaker, $content) = explode(':', $line, 2);
            $role = (strtolower(trim($speaker)) === 'user') ? 'user' : 'assistant';
            $messages[] = ['role' => $role, 'content' => trim($content)];
        }
    }
    $messages[] = ['role' => 'user', 'content' => "Continue the collaboration. Build upon previous ideas with new code and insights."];


    $data = [
        'model' => $agent['model'],
        'messages' => $messages,
        'max_tokens' => 2048,
    ];
    $headers = ['Content-Type: application/json', 'Authorization: Bearer ' . $apiConfig['key']];
    
    $result = makeApiRequest($apiConfig['url'], $headers, $data);
    if (is_string($result)) return $result;

    if (isset($result['error']['message'])) {
        error_log("API Error for {$agentName}: " . $result['error']['message']);
        return "Error from {$agentName}: " . $result['error']['message'];
    }

    if (isset($result['choices'][0]['message']['content'])) {
        return $result['choices'][0]['message']['content'];
    }
    return 'Error: Invalid response structure from ' . $agentName . '.';
}

function callClaudeAPI($agentName, $conversationHistory) {
    global $agents, $apis, $db;
    $agent = $agents[$agentName];
    $apiConfig = $apis[$agent['api']];
    if (empty($apiConfig['key'])) return "Error: API key for Claude is missing.";

    $memoryContext = getAgentMemoryContext($db, $agentName);
    $fullPersona = $agent['persona'] . "\n\nYour stored memories:\n" . $memoryContext;

    $data = [
        'model' => $agent['model'],
        'system' => $fullPersona,
        'messages' => [['role' => 'user', 'content' => $conversationHistory . "\n\nContinue the collaboration with new code and insights."]],
        'max_tokens' => 2048,
    ];
    $headers = [
        'x-api-key: ' . $apiConfig['key'],
        'anthropic-version: 2023-06-01',
        'content-type: application/json'
    ];

    $result = makeApiRequest($apiConfig['url'], $headers, $data);
    if (is_string($result)) return $result;

    if (isset($result['content'][0]['text'])) {
        return $result['content'][0]['text'];
    }
    return 'Error: Invalid response structure from Claude.';
}

function callGeminiAPI($agentName, $conversationHistory) {
    global $agents, $apis, $db;
    $agent = $agents[$agentName];
    $apiConfig = $apis[$agent['api']];
    if (empty($apiConfig['key'])) return "Error: API key for Gemini is missing.";

    $memoryContext = getAgentMemoryContext($db, $agentName);
    $fullContext = $agent['persona'] . "\n\nYour stored memories:\n" . $memoryContext . "\n\nConversation:\n" . $conversationHistory . "\n\nContinue with new code and insights.";

    $url = $apiConfig['url'] . '?key=' . $apiConfig['key'];
    $data = [
        'contents' => [['role' => 'user', 'parts' => [['text' => $fullContext]]]],
    ];
    $headers = ['Content-Type: application/json'];

    $result = makeApiRequest($url, $headers, $data);
    if (is_string($result)) return $result;

    if (isset($result['candidates'][0]['content']['parts'][0]['text'])) {
        return $result['candidates'][0]['content']['parts'][0]['text'];
    }
    return 'Error: Invalid response structure from Gemini.';
}

function callAgentAPI($agentName, $conversationHistory) {
    global $agents;
    $apiType = $agents[$agentName]['api'];

    switch ($apiType) {
        case 'openai':
        case 'groq':
        case 'grok':
            return callOpenAICompatibleAPI($agentName, $conversationHistory);
        case 'claude':
            return callClaudeAPI($agentName, $conversationHistory);
        case 'gemini':
            return callGeminiAPI($agentName, $conversationHistory);
        default:
            return "Error: Unknown API type '{$apiType}' for agent '{$agentName}'.";
    }
}

// Memory System Functions
function storeAgentMemory($db, $agentName, $key, $value, $importance = 5) {
    $stmt = $db->prepare('INSERT OR REPLACE INTO ai_memories (agent_name, memory_key, memory_value, importance, created_at, updated_at) VALUES (:agent, :key, :value, :importance, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)');
    $stmt->bindValue(':agent', $agentName, SQLITE3_TEXT);
    $stmt->bindValue(':key', $key, SQLITE3_TEXT);
    $stmt->bindValue(':value', $value, SQLITE3_TEXT);
    $stmt->bindValue(':importance', $importance, SQLITE3_INTEGER);
    $stmt->execute();
}

function getAgentMemoryContext($db, $agentName) {
    $stmt = $db->prepare('SELECT memory_key, memory_value FROM ai_memories WHERE agent_name = :agent ORDER BY importance DESC, updated_at DESC LIMIT 10');
    $stmt->bindValue(':agent', $agentName, SQLITE3_TEXT);
    $result = $stmt->execute();
    
    $memories = [];
    while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
        $memories[] = "- {$row['memory_key']}: {$row['memory_value']}";
    }
    
    return empty($memories) ? "No stored memories yet." : implode("\n", $memories);
}

// Enhanced Code Extraction
function extractAndSaveCodeBreakdown($db, $responseText, $agentName, $conversationId) {
    $cleanText = $responseText;
    
    // Extract CODE_OUTPUT blocks
    if (preg_match_all('/```CODE_OUTPUT:([^:]+):([^\n]+)\n(.*?)```/s', $responseText, $matches, PREG_SET_ORDER)) {
        foreach ($matches as $match) {
            $filename = trim($match[1]);
            $language = trim($match[2]);
            $codeContent = trim($match[3]);

            $stmt = $db->prepare('INSERT INTO code_breakdown (conversation_id, agent_name, filename, language, content, description) VALUES (:conv_id, :agent, :filename, :lang, :content, :desc)');
            $stmt->bindValue(':conv_id', $conversationId, SQLITE3_INTEGER);
            $stmt->bindValue(':agent', $agentName, SQLITE3_TEXT);
            $stmt->bindValue(':filename', $filename, SQLITE3_TEXT);
            $stmt->bindValue(':lang', $language, SQLITE3_TEXT);
            $stmt->bindValue(':content', $codeContent, SQLITE3_TEXT);
            $stmt->bindValue(':desc', "Code generated by {$agentName}", SQLITE3_TEXT);
            $stmt->execute();

            $cleanText = str_replace($match[0], "[📁 Code file '{$filename}' added to Code Breakdown panel]", $cleanText);
        }
    }
    
    // Process memory storage
    if (preg_match_all('/MEMORY_STORE:([^:]+):(.+)/m', $responseText, $memMatches, PREG_SET_ORDER)) {
        foreach ($memMatches as $memMatch) {
            $key = trim($memMatch[1]);
            $value = trim($memMatch[2]);
            storeAgentMemory($db, $agentName, $key, $value);
            $cleanText = str_replace($memMatch[0], "[💾 Stored memory: {$key}]", $cleanText);
        }
    }
    
    return $cleanText;
}

// AJAX Request Handling
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['action'])) {
    header('Content-Type: application/json');
    $action = $_POST['action'];
    $response = ['success' => false];

    switch ($action) {
        // ... (All other cases remain the same) ...

        case 'get_conversations':
            $result = $db->query('SELECT id, name, updated_at FROM conversations ORDER BY updated_at DESC');
            $convs = [];
            while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
                $convs[] = $row;
            }
            $response = ['success' => true, 'conversations' => $convs];
            break;

        case 'create_conversation':
            $name = $_POST['name'] ?? 'New EightLLMs Project';
            $stmt = $db->prepare('INSERT INTO conversations (name) VALUES (:name)');
            $stmt->bindValue(':name', $name, SQLITE3_TEXT);
            $stmt->execute();
            $convId = $db->lastInsertRowID();
            logAnalytics($db, 'conversation_created', null, $convId);
            $response = ['success' => true, 'id' => $convId];
            break;

        case 'delete_conversation':
            $convId = intval($_POST['id']);
            $db->exec("DELETE FROM messages WHERE conversation_id = {$convId}");
            $db->exec("DELETE FROM code_breakdown WHERE conversation_id = {$convId}");
            $db->exec("DELETE FROM conversations WHERE id = {$convId}");
            logAnalytics($db, 'conversation_deleted', null, $convId);
            $response = ['success' => true];
            break;

        case 'load_conversation':
            $convId = intval($_POST['id']);
            $msgStmt = $db->prepare('SELECT speaker, content FROM messages WHERE conversation_id = :id ORDER BY created_at ASC');
            $msgStmt->bindValue(':id', $convId, SQLITE3_INTEGER);
            $msgResult = $msgStmt->execute();
            $messages = [];
            while ($row = $msgResult->fetchArray(SQLITE3_ASSOC)) $messages[] = $row;
            
            $codeStmt = $db->prepare('SELECT id, agent_name, filename, language, content, description FROM code_breakdown WHERE conversation_id = :id ORDER BY created_at ASC');
            $codeStmt->bindValue(':id', $convId, SQLITE3_INTEGER);
            $codeResult = $codeStmt->execute();
            $codeBreakdown = [];
            while ($row = $codeResult->fetchArray(SQLITE3_ASSOC)) $codeBreakdown[] = $row;

            $response = ['success' => true, 'messages' => $messages, 'code_breakdown' => $codeBreakdown];
            break;

        case 'send_message':
            $convId = intval($_POST['conversation_id']);
            $content = $_POST['content'];

            $stmt = $db->prepare('INSERT INTO messages (conversation_id, speaker, content) VALUES (:conv_id, "User", :content)');
            $stmt->bindValue(':conv_id', $convId, SQLITE3_INTEGER);
            $stmt->bindValue(':content', $content, SQLITE3_TEXT);
            $stmt->execute();
            
            logAnalytics($db, 'user_message_sent', 'User', $convId);
            
            $agentTurnOrder = array_keys($agents);
            $newMessages = [];

            $historyResult = $db->query("SELECT speaker, content FROM messages WHERE conversation_id = {$convId} ORDER BY created_at DESC LIMIT 20");
            $historyArray = [];
            while($row = $historyResult->fetchArray(SQLITE3_ASSOC)) $historyArray[] = $row;
            $conversationHistory = implode("\n", array_map(fn($r) => "{$r['speaker']}: {$r['content']}", array_reverse($historyArray)));

            foreach ($agentTurnOrder as $agentName) {
                if ($agentName === 'Summarizer') continue;
                
                logAnalytics($db, 'ai_response_start', $agentName, $convId);
                $rawResponse = callAgentAPI($agentName, $conversationHistory);
                $cleanResponse = extractAndSaveCodeBreakdown($db, $rawResponse, $agentName, $convId);

                $msgStmt = $db->prepare('INSERT INTO messages (conversation_id, speaker, content) VALUES (:conv_id, :speaker, :content)');
                $msgStmt->bindValue(':conv_id', $convId, SQLITE3_INTEGER);
                $msgStmt->bindValue(':speaker', $agentName, SQLITE3_TEXT);
                $msgStmt->bindValue(':content', $cleanResponse, SQLITE3_TEXT);
                $msgStmt->execute();
                $newMessages[] = ['speaker' => $agentName, 'content' => $cleanResponse];
                $conversationHistory .= "\n{$agentName}: {$cleanResponse}";
                
                logAnalytics($db, 'ai_response_complete', $agentName, $convId);
            }

            // Summarize
            $summaryResponse = callAgentAPI('Summarizer', $conversationHistory);
            $summaryStmt = $db->prepare('INSERT INTO messages (conversation_id, speaker, content) VALUES (:conv_id, "Summarizer", :content)');
            $summaryStmt->bindValue(':conv_id', $convId, SQLITE3_INTEGER);
            $summaryStmt->bindValue(':content', $summaryResponse, SQLITE3_TEXT);
            $summaryStmt->execute();
            $newMessages[] = ['speaker' => 'Summarizer', 'content' => $summaryResponse];

            $updateStmt = $db->prepare('UPDATE conversations SET updated_at = CURRENT_TIMESTAMP, last_summary = :summary WHERE id = :id');
            $updateStmt->bindValue(':summary', $summaryResponse, SQLITE3_TEXT);
            $updateStmt->bindValue(':id', $convId, SQLITE3_INTEGER);
            $updateStmt->execute();

            $codeResult = $db->query("SELECT id, agent_name, filename, language, content, description FROM code_breakdown WHERE conversation_id = {$convId} ORDER BY created_at ASC");
            $allCodeBreakdown = [];
            while ($row = $codeResult->fetchArray(SQLITE3_ASSOC)) $allCodeBreakdown[] = $row;

            $response = ['success' => true, 'new_messages' => $newMessages, 'all_code_breakdown' => $allCodeBreakdown];
            break;
    }

    echo json_encode($response);
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>EIGHT-LLMS | AI Collaboration Platform</title>
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Orbitron:wght@400;700;900&family=Roboto+Mono:wght@300;400;500&display=swap');
        
        :root {
            /* 8-bit Gaming Inspired Professional Dark Theme */
            --bg-primary: #0a0a0f;
            --bg-secondary: #1a1a24;
            --bg-tertiary: #2a2a3a;
            --accent-neon: #00ff88;
            --accent-blue: #00aaff;
            --accent-purple: #aa00ff;
            --accent-orange: #ff6600;
            --text-primary: #e0e0e8;
            --text-secondary: #b0b0c0;
            --text-muted: #808090;
            --border-main: #333344;
            --shadow-glow: 0 0 20px rgba(0, 255, 136, 0.3);
            --gradient-primary: linear-gradient(135deg, #0a0a0f 0%, #1a1a24 100%);
            --gradient-accent: linear-gradient(90deg, #00ff88, #00aaff);
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            background: var(--gradient-primary);
            color: var(--text-primary);
            font-family: 'Roboto Mono', monospace;
            overflow: hidden;
            height: 100vh;
            display: flex;
            flex-direction: column;
            padding: 8px;
        }
        
        #main-container {
            display: flex;
            flex: 1;
            gap: 8px;
            overflow: hidden;
        }
        
        /* Resize Handles */
        .resize-handle {
            background: var(--accent-neon);
            opacity: 0.3;
            cursor: ew-resize;
            width: 4px;
            position: relative;
            transition: opacity 0.3s;
        }
        
        .resize-handle:hover {
            opacity: 0.8;
            box-shadow: 0 0 10px var(--accent-neon);
        }
        
        .resize-handle-horizontal {
            background: var(--accent-neon);
            opacity: 0.3;
            cursor: ns-resize;
            height: 4px;
            width: 100%;
            transition: opacity 0.3s;
        }
        
        .resize-handle-horizontal:hover {
            opacity: 0.8;
            box-shadow: 0 0 10px var(--accent-neon);
        }

        /* Panel Base Styles */
        .panel {
            background: var(--bg-secondary);
            border: 2px solid var(--border-main);
            border-radius: 12px;
            box-shadow: var(--shadow-glow);
            display: flex;
            flex-direction: column;
            position: relative;
            overflow: hidden;
        }

        .panel::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 2px;
            background: var(--gradient-accent);
            opacity: 0.7;
        }

        .panel-header {
            background: var(--bg-tertiary);
            padding: 12px 16px;
            font-family: 'Orbitron', monospace;
            font-weight: 700;
            font-size: 14px;
            letter-spacing: 2px;
            border-bottom: 2px solid var(--border-main);
            display: flex;
            align-items: center;
            justify-content: space-between;
        }

        .panel-header::before {
            content: '▸';
            color: var(--accent-neon);
            margin-right: 8px;
            font-size: 16px;
        }

        .panel-content {
            flex: 1;
            overflow-y: auto;
            padding: 16px;
        }

        /* Scrollbar Styling */
        ::-webkit-scrollbar {
            width: 8px;
        }

        ::-webkit-scrollbar-track {
            background: var(--bg-primary);
        }

        ::-webkit-scrollbar-thumb {
            background: var(--accent-neon);
            border-radius: 4px;
        }

        ::-webkit-scrollbar-thumb:hover {
            background: var(--accent-blue);
        }

        /* Sidebar Styles */
        #sidebar {
            width: 280px;
            min-width: 200px;
            max-width: 400px;
        }

        #new-project-btn {
            background: var(--gradient-accent);
            color: var(--bg-primary);
            border: none;
            padding: 12px;
            width: 100%;
            cursor: pointer;
            font-family: 'Orbitron', monospace;
            font-weight: 700;
            font-size: 12px;
            letter-spacing: 1px;
            border-radius: 8px;
            margin-bottom: 16px;
            transition: all 0.3s ease;
            box-shadow: 0 4px 15px rgba(0, 255, 136, 0.4);
        }

        #new-project-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(0, 255, 136, 0.6);
        }

        .project-item {
            padding: 12px;
            cursor: pointer;
            border-radius: 8px;
            margin-bottom: 8px;
            border: 1px solid transparent;
            transition: all 0.3s ease;
            display: flex;
            justify-content: space-between;
            align-items: center;
            font-size: 14px;
        }

        .project-item:hover {
            background: var(--bg-tertiary);
            border-color: var(--accent-neon);
            transform: translateX(4px);
        }

        .project-item.active {
            background: var(--bg-tertiary);
            border-color: var(--accent-neon);
            box-shadow: 0 0 10px rgba(0, 255, 136, 0.3);
        }

        .project-actions {
            display: flex;
            gap: 8px;
        }

        .delete-btn {
            background: var(--accent-orange);
            color: white;
            border: none;
            width: 24px;
            height: 24px;
            border-radius: 4px;
            cursor: pointer;
            font-size: 12px;
            transition: all 0.3s ease;
        }

        .delete-btn:hover {
            background: #ff4444;
            transform: scale(1.1);
        }

        /* Chat Styles */
        #chat-panel {
            flex: 1;
            min-width: 400px;
        }

        #messages {
            flex: 1;
            overflow-y: auto;
            padding: 16px;
        }

        .message {
            margin-bottom: 20px;
            animation: slideIn 0.3s ease-out;
        }

        @keyframes slideIn {
            from { opacity: 0; transform: translateY(10px); }
            to { opacity: 1; transform: translateY(0); }
        }

        .message .speaker {
            font-family: 'Orbitron', monospace;
            font-weight: 700;
            font-size: 13px;
            letter-spacing: 1px;
            margin-bottom: 6px;
            display: flex;
            align-items: center;
        }

        .message .speaker::before {
            content: '●';
            margin-right: 8px;
            font-size: 8px;
        }

        .message .content {
            background: var(--bg-tertiary);
            padding: 12px;
            border-radius: 8px;
            border-left: 3px solid var(--accent-neon);
            white-space: pre-wrap;
            word-wrap: break-word;
            line-height: 1.5;
        }

        /* Agent Color Coding */
        .message .speaker.User { color: var(--accent-orange); }
        .message .speaker.User::before { color: var(--accent-orange); }
        
        .message .speaker.Claude { color: #ff9500; }
        .message .speaker.Claude::before { color: #ff9500; }
        
        .message .speaker.Grok { color: var(--accent-blue); }
        .message .speaker.Grok::before { color: var(--accent-blue); }
        
        .message .speaker.ChatGPT { color: #10d86f; }
        .message .speaker.ChatGPT::before { color: #10d86f; }
        
        .message .speaker.Gemini { color: var(--accent-purple); }
        .message .speaker.Gemini::before { color: var(--accent-purple); }
        
        .message .speaker.Llama { color: #a29bfe; }
        .message .speaker.Llama::before { color: #a29bfe; }

        .message .speaker.LlamaVersatile { color: #ff6b9d; }
        .message .speaker.LlamaVersatile::before { color: #ff6b9d; }

        .message .speaker.Gemma { color: #48dbfb; }
        .message .speaker.Gemma::before { color: #48dbfb; }
        
        .message .speaker.Qwen { color: #ffd93d; }
        .message .speaker.Qwen::before { color: #ffd93d; }
        
        .message .speaker.Summarizer { color: var(--text-muted); }
        .message .speaker.Summarizer::before { color: var(--text-muted); }

        #input-area {
            border-top: 2px solid var(--border-main);
            padding: 16px;
            display: flex;
            gap: 12px;
        }

        #message-input {
            flex: 1;
            background: var(--bg-tertiary);
            border: 2px solid var(--border-main);
            border-radius: 8px;
            color: var(--text-primary);
            padding: 12px 16px;
            font-family: 'Roboto Mono', monospace;
            font-size: 14px;
            transition: all 0.3s ease;
        }

        #message-input:focus {
            outline: none;
            border-color: var(--accent-neon);
            box-shadow: 0 0 10px rgba(0, 255, 136, 0.3);
        }

        #send-btn {
            background: var(--gradient-accent);
            color: var(--bg-primary);
            border: none;
            padding: 12px 24px;
            cursor: pointer;
            font-family: 'Orbitron', monospace;
            font-weight: 700;
            border-radius: 8px;
            transition: all 0.3s ease;
        }

        #send-btn:hover:not(:disabled) {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(0, 255, 136, 0.6);
        }

        #send-btn:disabled {
            opacity: 0.5;
            cursor: not-allowed;
        }

        /* Code Breakdown Panel */
        #code-panel {
            width: 400px;
            min-width: 300px;
            max-width: 600px;
        }

        .code-item {
            background: var(--bg-tertiary);
            border: 1px solid var(--border-main);
            border-radius: 8px;
            margin-bottom: 12px;
            overflow: hidden;
            transition: all 0.3s ease;
        }

        .code-item:hover {
            border-color: var(--accent-neon);
            box-shadow: 0 0 10px rgba(0, 255, 136, 0.2);
        }

        .code-header {
            background: var(--bg-primary);
            padding: 12px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            border-bottom: 1px solid var(--border-main);
        }

        .code-filename {
            font-family: 'Orbitron', monospace;
            font-weight: 700;
            font-size: 12px;
            color: var(--accent-neon);
        }

        .code-actions {
            display: flex;
            gap: 8px;
        }

        .code-actions button {
            background: var(--bg-secondary);
            border: 1px solid var(--border-main);
            color: var(--text-primary);
            cursor: pointer;
            font-family: 'Roboto Mono', monospace;
            font-size: 11px;
            padding: 6px 12px;
            border-radius: 4px;
            transition: all 0.3s ease;
        }

        .code-actions button:hover {
            background: var(--accent-neon);
            color: var(--bg-primary);
            border-color: var(--accent-neon);
        }

        .code-content {
            background: var(--bg-primary);
            padding: 12px;
            max-height: 200px;
            overflow-y: auto;
            font-family: 'Roboto Mono', monospace;
            font-size: 12px;
            line-height: 1.4;
            white-space: pre;
        }

        /* Terminal Panel */
        #terminal-panel {
            height: 200px;
            min-height: 100px;
            max-height: 400px;
            margin-top: 8px;
        }

        #terminal-content {
            background: var(--bg-primary);
            font-family: 'Roboto Mono', monospace;
            font-size: 12px;
            line-height: 1.4;
            padding: 12px;
            height: 100%;
            overflow-y: auto;
        }

        .terminal-line {
            margin-bottom: 4px;
            display: flex;
            align-items: center;
        }

        .terminal-prompt {
            color: var(--accent-neon);
            margin-right: 8px;
        }

        .terminal-timestamp {
            color: var(--text-muted);
            margin-right: 12px;
            font-size: 11px;
        }

        #thinking-indicator {
            text-align: center;
            padding: 16px;
            color: var(--accent-neon);
            font-family: 'Orbitron', monospace;
            font-weight: 700;
            letter-spacing: 2px;
            display: none;
            animation: pulse 2s infinite;
        }

        @keyframes pulse {
            0%, 100% { opacity: 0.6; }
            50% { opacity: 1; }
        }

        /* Utility Classes */
        .hidden { display: none !important; }
        
        .loading {
            opacity: 0.7;
            pointer-events: none;
        }

        /* Streaming Message Animation */
        @keyframes typewriter {
            from { opacity: 0.7; }
            to { opacity: 1; }
        }
        
        .message-streaming {
            position: relative;
        }
        
        .message-streaming::after {
            content: '▊';
            color: var(--accent-neon);
            animation: typewriter 1s infinite;
            margin-left: 2px;
        }
        
        /* Mobile Responsiveness */
        @media (max-width: 1200px) {
            #main-container {
                flex-direction: column;
            }
            
            #sidebar, #chat-panel, #code-panel {
                width: 100% !important;
                max-width: none !important;
            }
            
            .resize-handle {
                display: none;
            }
        }
    </style>
</head>
<body>
    <div id="main-container">
        <!-- Sidebar Panel -->
        <div id="sidebar" class="panel">
            <div class="panel-header">
                PROJECT NAVIGATOR
            </div>
            <div class="panel-content">
                <button id="new-project-btn">⚡ NEW PROJECT</button>
                <div class="template-section" style="margin: 16px 0; padding: 12px; background: var(--bg-tertiary); border-radius: 8px;">
                    <div style="font-family: 'Orbitron', monospace; font-size: 12px; margin-bottom: 8px; color: var(--accent-neon);">🚀 QUICK START</div>
                    <select id="template-selector" style="width: 100%; background: var(--bg-primary); color: var(--text-primary); border: 1px solid var(--border-main); border-radius: 4px; padding: 6px; font-family: 'Roboto Mono', monospace; font-size: 11px;">
                        <option value="">Select Template...</option>
                        <option value="web_app">🌐 Web Application</option>
                        <option value="data_analysis">📊 Data Analysis</option>
                        <option value="ai_tool">🤖 AI Tool</option>
                        <option value="mobile_app">📱 Mobile App</option>
                        <option value="game">🎮 Game</option>
                        <option value="automation">⚙️ Automation</option>
                    </select>
                    <button id="create-template-btn" style="width: 100%; margin-top: 8px; background: var(--accent-blue); color: white; border: none; padding: 8px; border-radius: 4px; font-family: 'Orbitron', monospace; font-size: 10px; cursor: pointer;">CREATE FROM TEMPLATE</button>
                </div>
                <div id="project-list"></div>
            </div>
        </div>

        <!-- Resize Handle -->
        <div class="resize-handle" id="sidebar-resize"></div>

        <!-- Chat Panel -->
        <div id="chat-panel" class="panel">
            <div class="panel-header" id="chat-header">
                <span>EIGHT-LLMS COLLABORATION</span>
                <div id="project-actions" style="display: none; gap: 8px;">
                    <button id="export-btn" style="background: var(--accent-purple); color: white; border: none; padding: 6px 12px; border-radius: 4px; font-family: 'Orbitron', monospace; font-size: 10px; cursor: pointer;">📦 EXPORT</button>
                    <button id="collaboration-status" style="background: var(--accent-neon); color: var(--bg-primary); border: none; padding: 6px 12px; border-radius: 4px; font-family: 'Orbitron', monospace; font-size: 10px;">🤝 LIVE</button>
                </div>
            </div>
            <div id="messages" class="panel-content">
                <div style="text-align: center; padding: 40px; color: var(--text-muted);">
                    <div style="font-family: 'Orbitron', monospace; font-size: 24px; margin-bottom: 16px;">🤖 EIGHT-LLMS</div>
                    <p>Select a project or create a new one to begin AI collaboration.</p>
                    <p style="font-size: 12px; margin-top: 8px;">8 AI agents ready to build amazing code together!</p>
                </div>
            </div>
            <div id="thinking-indicator">⚡ AI TEAM PROCESSING...</div>
            <div id="input-area">
                <input type="text" id="message-input" placeholder="Enter your project request..." disabled>
                <button id="send-btn" disabled>DEPLOY</button>
            </div>
        </div>

        <!-- Resize Handle -->
        <div class="resize-handle" id="code-resize"></div>

        <!-- Code Breakdown Panel -->
        <div id="code-panel" class="panel">
            <div class="panel-header">
                CODE BREAKDOWN
            </div>
            <div id="code-list" class="panel-content">
                <div style="text-align: center; padding: 20px; color: var(--text-muted);">
                    <div style="font-size: 32px; margin-bottom: 12px;">📁</div>
                    <p>Code files will appear here</p>
                    <p style="font-size: 12px; margin-top: 8px;">AI-generated code ready for download</p>
                </div>
            </div>
        </div>
    </div>

    <!-- Horizontal Resize Handle -->
    <div class="resize-handle-horizontal" id="terminal-resize"></div>

    <!-- Terminal Analytics Panel -->
    <div id="terminal-panel" class="panel">
        <div class="panel-header">
            SYSTEM TERMINAL
        </div>
        <div id="terminal-content"></div>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', () => {
            const newProjectBtn = document.getElementById('new-project-btn');
            const createTemplateBtn = document.getElementById('create-template-btn');
            const templateSelector = document.getElementById('template-selector');
            const projectList = document.getElementById('project-list');
            const chatHeader = document.getElementById('chat-header');
            const projectActions = document.getElementById('project-actions');
            const exportBtn = document.getElementById('export-btn');
            const messagesDiv = document.getElementById('messages');
            const codeList = document.getElementById('code-list');
            const messageInput = document.getElementById('message-input');
            const sendBtn = document.getElementById('send-btn');
            const thinkingIndicator = document.getElementById('thinking-indicator');
            const terminalContent = document.getElementById('terminal-content');

            let currentConversationId = null;
            
            // Panel Resize System
            initializeResizablePanels();
            
            function initializeResizablePanels() {
                // Load saved sizes
                const savedSizes = JSON.parse(localStorage.getItem('eight-llms-panel-sizes') || '{}');
                
                if (savedSizes.sidebar) document.getElementById('sidebar').style.width = savedSizes.sidebar + 'px';
                if (savedSizes.codePanel) document.getElementById('code-panel').style.width = savedSizes.codePanel + 'px';
                if (savedSizes.terminal) document.getElementById('terminal-panel').style.height = savedSizes.terminal + 'px';
                
                // Sidebar resize
                const sidebarResize = document.getElementById('sidebar-resize');
                const sidebar = document.getElementById('sidebar');
                let isResizingSidebar = false;
                
                sidebarResize.addEventListener('mousedown', (e) => {
                    isResizingSidebar = true;
                    document.body.style.cursor = 'ew-resize';
                });
                
                // Code panel resize
                const codeResize = document.getElementById('code-resize');
                const codePanel = document.getElementById('code-panel');
                let isResizingCode = false;
                
                codeResize.addEventListener('mousedown', (e) => {
                    isResizingCode = true;
                    document.body.style.cursor = 'ew-resize';
                });
                
                // Terminal resize
                const terminalResize = document.getElementById('terminal-resize');
                const terminalPanel = document.getElementById('terminal-panel');
                let isResizingTerminal = false;
                
                terminalResize.addEventListener('mousedown', (e) => {
                    isResizingTerminal = true;
                    document.body.style.cursor = 'ns-resize';
                });
                
                document.addEventListener('mousemove', (e) => {
                    if (isResizingSidebar) {
                        const newWidth = e.clientX - sidebar.offsetLeft;
                        if (newWidth >= 200 && newWidth <= 400) {
                            sidebar.style.width = newWidth + 'px';
                        }
                    } else if (isResizingCode) {
                        const containerWidth = document.getElementById('main-container').offsetWidth;
                        const newWidth = containerWidth - e.clientX;
                        if (newWidth >= 300 && newWidth <= 600) {
                            codePanel.style.width = newWidth + 'px';
                        }
                    } else if (isResizingTerminal) {
                        const containerHeight = window.innerHeight;
                        const newHeight = containerHeight - e.clientY - 8; // Account for padding
                        if (newHeight >= 100 && newHeight <= 400) {
                            terminalPanel.style.height = newHeight + 'px';
                        }
                    }
                });
                
                document.addEventListener('mouseup', () => {
                    if (isResizingSidebar || isResizingCode || isResizingTerminal) {
                        document.body.style.cursor = 'default';
                        
                        // Save sizes
                        const sizes = {
                            sidebar: sidebar.offsetWidth,
                            codePanel: codePanel.offsetWidth,
                            terminal: terminalPanel.offsetHeight
                        };
                        localStorage.setItem('eight-llms-panel-sizes', JSON.stringify(sizes));
                    }
                    
                    isResizingSidebar = false;
                    isResizingCode = false;
                    isResizingTerminal = false;
                });
            }
            
            // Terminal System
            function addTerminalLine(type, message, agent = null) {
                const line = document.createElement('div');
                line.className = 'terminal-line';
                
                const timestamp = new Date().toLocaleTimeString();
                const agentInfo = agent ? `[${agent}]` : '[SYSTEM]';
                
                line.innerHTML = `
                    <span class="terminal-timestamp">${timestamp}</span>
                    <span class="terminal-prompt">${agentInfo}></span>
                    <span>${message}</span>
                `;
                
                terminalContent.appendChild(line);
                terminalContent.scrollTop = terminalContent.scrollHeight;
                
                // Keep only last 100 lines
                while (terminalContent.children.length > 100) {
                    terminalContent.removeChild(terminalContent.firstChild);
                }
            }

            // Initialize terminal
            addTerminalLine('info', 'EIGHT-LLMS System initialized');
            addTerminalLine('info', '8 AI agents loaded and ready');
            
            // API Call Function
            async function apiCall(action, body = {}) {
                const formData = new FormData();
                formData.append('action', action);
                for (const key in body) {
                    formData.append(key, body[key]);
                }
                
                addTerminalLine('debug', `API call: ${action}`);
                
                try {
                    const response = await fetch('', { method: 'POST', body: formData });
                    if (!response.ok) {
                        throw new Error(`HTTP error! status: ${response.status}`);
                    }
                    const result = await response.json();
                    
                    if (result.success) {
                        addTerminalLine('success', `API call successful: ${action}`);
                    } else {
                        addTerminalLine('error', `API call failed: ${action} - ${result.error || 'Unknown error'}`);
                    }
                    
                    return result;
                } catch (error) {
                    addTerminalLine('error', `Network error: ${error.message}`);
                    return { success: false, error: error.message };
                }
            }

            // Project Management
            function renderProjects(projects) {
                projectList.innerHTML = '';
                projects.forEach(p => {
                    const div = document.createElement('div');
                    div.className = 'project-item';
                    if (p.id === currentConversationId) {
                        div.classList.add('active');
                    }
                    
                    div.innerHTML = `
                        <span>${escapeHtml(p.name)}</span>
                        <div class="project-actions">
                            <button class="delete-btn" data-id="${p.id}" title="Delete Project">×</button>
                        </div>
                    `;
                    
                    div.addEventListener('click', (e) => {
                        if (e.target.classList.contains('delete-btn')) {
                            const projectId = e.target.getAttribute('data-id');
                            deleteProject(parseInt(projectId));
                        } else {
                            selectConversation(p.id, p.name);
                        }
                    });
                    
                    projectList.appendChild(div);
                });
                
                addTerminalLine('info', `Loaded ${projects.length} projects`);
            }

            // Message Rendering
            function renderMessages(msgs) {
                if (msgs.length === 0) {
                    messagesDiv.innerHTML = `
                        <div style="text-align: center; padding: 40px; color: var(--text-muted);">
                            <div style="font-family: 'Orbitron', monospace; font-size: 18px; margin-bottom: 16px;">🚀 NEW PROJECT READY</div>
                            <p>Send a message to start the AI collaboration!</p>
                        </div>
                    `;
                } else {
                    messagesDiv.innerHTML = '';
                    msgs.forEach(msg => appendMessage(msg));
                }
            }

            function appendMessage(msg, isStreaming = false) {
                const div = document.createElement('div');
                div.className = 'message';
                div.setAttribute('data-speaker', msg.speaker);
                if (isStreaming) {
                    div.classList.add('message-streaming');
                }
                
                div.innerHTML = `
                    <div class="speaker ${msg.speaker.replace(/\s+/g, '')}">${escapeHtml(msg.speaker)}</div>
                    <div class="content">${escapeHtml(msg.content)}</div>
                `;
                messagesDiv.appendChild(div);
                messagesDiv.scrollTop = messagesDiv.scrollHeight;
                
                if (!isStreaming) {
                    addTerminalLine('message', `New message from ${msg.speaker}`, msg.speaker);
                }
            }

            // Code Breakdown Rendering
            function renderCodeBreakdown(codeItems) {
                if (codeItems.length === 0) {
                    codeList.innerHTML = `
                        <div style="text-align: center; padding: 20px; color: var(--text-muted);">
                            <div style="font-size: 32px; margin-bottom: 12px;">📁</div>
                            <p>Code files will appear here</p>
                            <p style="font-size: 12px; margin-top: 8px;">AI-generated code ready for download</p>
                        </div>
                    `;
                } else {
                    codeList.innerHTML = '';
                    codeItems.forEach(code => {
                        const div = document.createElement('div');
                        div.className = 'code-item';
                        // Store content in a data attribute to handle special characters correctly
                        div.setAttribute('data-content', code.content);
                        div.innerHTML = `
                            <div class="code-header">
                                <div>
                                    <div class="code-filename">${escapeHtml(code.filename)}</div>
                                    <div style="font-size: 10px; color: var(--text-muted); margin-top: 2px;">by ${code.agent_name}</div>
                                </div>
                                <div class="code-actions">
                                    <button class="copy-code-btn" data-id="${code.id}">📋 COPY</button>
                                    <button class="download-code-btn" data-filename="${escapeHtml(code.filename)}">⬇ DOWNLOAD</button>
                                </div>
                            </div>
                            <pre class="code-content" id="code-${code.id}">${escapeHtml(code.content)}</pre>
                        `;
                        codeList.appendChild(div);
                    });
                }
                
                addTerminalLine('info', `Code breakdown updated: ${codeItems.length} files`);
            }

            // Global event listeners for code actions
            codeList.addEventListener('click', function(e) {
                if (e.target.classList.contains('copy-code-btn')) {
                    const codeItem = e.target.closest('.code-item');
                    const content = codeItem.getAttribute('data-content');
                    navigator.clipboard.writeText(content).then(() => {
                        addTerminalLine('success', `Code copied to clipboard`, 'USER');
                    });
                }
                if (e.target.classList.contains('download-code-btn')) {
                    const codeItem = e.target.closest('.code-item');
                    const content = codeItem.getAttribute('data-content');
                    const filename = e.target.getAttribute('data-filename');
                    downloadCode(filename, content);
                }
            });

            function downloadCode(filename, content) {
                const blob = new Blob([content], { type: 'text/plain' });
                const url = URL.createObjectURL(blob);
                const a = document.createElement('a');
                a.href = url;
                a.download = filename;
                document.body.appendChild(a);
                a.click();
                document.body.removeChild(a);
                URL.revokeObjectURL(url);
                
                addTerminalLine('success', `Downloaded: ${filename}`, 'USER');
            }

            async function deleteProject(projectId) {
                if (confirm('Are you sure you want to delete this project?')) {
                    const result = await apiCall('delete_conversation', { id: projectId });
                    if (result.success) {
                        addTerminalLine('warning', `Project deleted: ID ${projectId}`, 'USER');
                        if (currentConversationId === projectId) {
                            currentConversationId = null;
                            chatHeader.querySelector('span').textContent = 'EIGHT-LLMS COLLABORATION';
                            projectActions.style.display = 'none';
                            messageInput.disabled = true;
                            sendBtn.disabled = true;
                            renderMessages([]);
                            renderCodeBreakdown([]);
                        }
                        loadConversations();
                    }
                }
            };

            // Utility functions
            function escapeHtml(unsafe) {
                return unsafe
                         .replace(/&/g, "&amp;")
                         .replace(/</g, "&lt;")
                         .replace(/>/g, "&gt;")
                         .replace(/"/g, "&quot;")
                         .replace(/'/g, "&#039;");
            }

            // Main functions
            async function loadConversations() {
                const data = await apiCall('get_conversations');
                if (data.success) {
                    renderProjects(data.conversations);
                }
            }

            async function selectConversation(id, name) {
                currentConversationId = id;
                chatHeader.querySelector('span').textContent = `EIGHT-LLMS - ${name}`;
                projectActions.style.display = 'flex';
                messageInput.disabled = false;
                sendBtn.disabled = false;
                
                document.querySelectorAll('.project-item').forEach(el => el.classList.remove('active'));
                
                // Find the correct project item to activate
                const items = document.querySelectorAll('.project-item');
                for(let item of items) {
                    if (item.querySelector('span').textContent === name) {
                        item.classList.add('active');
                        break;
                    }
                }
                
                addTerminalLine('info', `Switched to project: ${name}`, 'USER');
                
                const data = await apiCall('load_conversation', { id });
                if (data.success) {
                    renderMessages(data.messages);
                    renderCodeBreakdown(data.code_breakdown);
                }
            }

            // Event Listeners
            newProjectBtn.addEventListener('click', async () => {
                const name = prompt('Enter project name:', 'EightLLMs Project ' + Date.now());
                if (name && name.trim()) {
                    const data = await apiCall('create_conversation', { name: name.trim() });
                    if (data.success) {
                        await loadConversations();
                        selectConversation(data.id, name.trim());
                    }
                }
            });

            async function handleSendMessage() {
                const content = messageInput.value.trim();
                if (!content || !currentConversationId) return;

                const userMessage = { speaker: 'User', content: content };
                appendMessage(userMessage);
                messageInput.value = '';
                
                messageInput.disabled = true;
                sendBtn.disabled = true;
                thinkingIndicator.style.display = 'block';
                
                addTerminalLine('info', 'Processing AI responses...', 'SYSTEM');

                const data = await apiCall('send_message', { 
                    conversation_id: currentConversationId, 
                    content: content 
                });
                
                thinkingIndicator.style.display = 'none';
                messageInput.disabled = false;
                sendBtn.disabled = false;
                messageInput.focus();

                if (data.success) {
                    data.new_messages.forEach(msg => appendMessage(msg));
                    renderCodeBreakdown(data.all_code_breakdown);
                    addTerminalLine('success', `All AI agents responded`);
                }
            }

            sendBtn.addEventListener('click', handleSendMessage);
            messageInput.addEventListener('keypress', (e) => {
                if (e.key === 'Enter') handleSendMessage();
            });

            // Initialize
            loadConversations();
            
            // Show startup complete
            setTimeout(() => {
                addTerminalLine('success', 'EIGHT-LLMS ready for collaboration!');
            }, 1000);
        });
    </script>
</body>
</html>
