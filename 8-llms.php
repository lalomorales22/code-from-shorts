<?php
// Set a longer execution time limit to handle multiple API calls in a round.
set_time_limit(300); // 5 minutes
ini_set('max_execution_time', 300);

// --- Load Environment Variables ---
// IMPORTANT: Make sure you have a .env file in the same directory with your API keys.
// Example: OPENAI_API_KEY="sk-..."
if (file_exists('.env')) {
    $env = parse_ini_file('.env');
    foreach ($env as $key => $value) {
        $_ENV[$key] = $value;
    }
}

// --- DATABASE INITIALIZATION ---
function initializeDatabase() {
    $db = new SQLite3('ai_chat_room.db');
    
    // Create conversations table
    $db->exec('CREATE TABLE IF NOT EXISTS conversations (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        name TEXT NOT NULL,
        last_summary TEXT,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )');

    // Create messages table
    $db->exec('CREATE TABLE IF NOT EXISTS messages (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        conversation_id INTEGER NOT NULL,
        speaker TEXT NOT NULL,
        content TEXT NOT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (conversation_id) REFERENCES conversations(id)
    )');

    // Create artifacts table for code snippets
    $db->exec('CREATE TABLE IF NOT EXISTS artifacts (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        conversation_id INTEGER NOT NULL,
        agent_name TEXT NOT NULL,
        filename TEXT NOT NULL,
        language TEXT NOT NULL,
        content TEXT NOT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (conversation_id) REFERENCES conversations(id)
    )');
    
    return $db;
}

$db = initializeDatabase();

// --- AGENT & API CONFIGURATION ---
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

$agents = [
    'Claude' => ['persona' => 'You are Claude, the Senior Engineer...', 'role' => 'Senior Engineer', 'api' => 'claude', 'model' => 'claude-3-haiku-20240307'],
    'Grok' => ['persona' => 'You are Grok, the Systems Architect...', 'role' => 'Systems Architect', 'api' => 'grok', 'model' => 'grok-4-0709'],
    'ChatGPT' => ['persona' => 'You are ChatGPT, the Project Manager...', 'role' => 'Project Manager', 'api' => 'openai', 'model' => 'gpt-4o'],
    'Gemini' => ['persona' => 'You are Gemini, the Frontend & UX Engineer...', 'role' => 'Frontend Engineer', 'api' => 'gemini', 'model' => 'gemini-1.5-flash'],
    'Kimi' => ['persona' => 'You are Kimi, a creative strategist...', 'role' => 'Creative Strategist', 'api' => 'groq', 'model' => 'moonshotai/kimi-k2-instruct'],
    'Qwen' => ['persona' => 'You are Qwen, a technical writer...', 'role' => 'Technical Writer', 'api' => 'groq', 'model' => 'qwen/qwen3-32b'],
    'DeepSeek' => ['persona' => 'You are DeepSeek, a data scientist...', 'role' => 'Data Scientist', 'api' => 'groq', 'model' => 'deepseek-r1-distill-llama-70b'],
    'Llama' => ['persona' => 'You are Llama, an ethical AI specialist...', 'role' => 'Ethical AI Specialist', 'api' => 'groq', 'model' => 'meta-llama/llama-4-maverick-17b-128e-instruct'],
    'Summarizer' => ['persona' => 'You are a helpful summarizer. Your role is to read the latest conversation round and provide a concise summary of the key points and decisions made. Be brief and to the point.', 'role' => 'Summarizer', 'api' => 'openai', 'model' => 'gpt-3.5-turbo'],
];

// --- REAL API & HELPER FUNCTIONS ---

function makeApiRequest($url, $headers, $postData) {
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($postData),
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_TIMEOUT => 45,
        CURLOPT_CONNECTTIMEOUT => 15,
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
    global $agents, $apis;
    $agent = $agents[$agentName];
    $apiConfig = $apis[$agent['api']];

    if (empty($apiConfig['key'])) return "Error: API key for {$agent['api']} is missing.";

    $data = [
        'model' => $agent['model'],
        'messages' => [
            ['role' => 'system', 'content' => $agent['persona']],
            ['role' => 'user', 'content' => $conversationHistory]
        ],
        'max_tokens' => 1024,
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
    error_log("Invalid OpenAI-compatible response for {$agentName}: " . json_encode($result));
    return 'Error: Invalid response structure from ' . $agentName . '.';
}

function callGrokAPI($agentName, $conversationHistory) {
    // Grok uses an OpenAI-compatible endpoint.
    return callOpenAICompatibleAPI($agentName, $conversationHistory);
}


function callClaudeAPI($agentName, $conversationHistory) {
    global $agents, $apis;
    $agent = $agents[$agentName];
    $apiConfig = $apis[$agent['api']];
    if (empty($apiConfig['key'])) return "Error: API key for Claude is missing.";

    $data = [
        'model' => $agent['model'],
        'system' => $agent['persona'],
        'messages' => [['role' => 'user', 'content' => $conversationHistory]],
        'max_tokens' => 1024,
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
    error_log("Invalid Claude response: " . json_encode($result));
    return 'Error: Invalid response structure from Claude.';
}

function callGeminiAPI($agentName, $conversationHistory) {
    global $agents, $apis;
    $agent = $agents[$agentName];
    $apiConfig = $apis[$agent['api']];
    if (empty($apiConfig['key'])) return "Error: API key for Gemini is missing.";

    $url = $apiConfig['url'] . '?key=' . $apiConfig['key'];
    $fullContext = $agent['persona'] . "\n\nConversation so far:\n" . $conversationHistory;
    $data = [
        'contents' => [['role' => 'user', 'parts' => [['text' => $fullContext]]]],
    ];
    $headers = ['Content-Type: application/json'];

    $result = makeApiRequest($url, $headers, $data);
    if (is_string($result)) return $result;

    if (isset($result['error']['message'])) {
        error_log("Gemini API Error: " . $result['error']['message']);
        return "Error from Gemini: " . $result['error']['message'];
    }

    if (isset($result['promptFeedback']['blockReason'])) {
        $reason = $result['promptFeedback']['blockReason'];
        error_log("Gemini content blocked: " . $reason);
        return "Error from Gemini: Request blocked due to {$reason}.";
    }

    if (isset($result['candidates'][0]['content']['parts'][0]['text'])) {
        return $result['candidates'][0]['content']['parts'][0]['text'];
    }
    error_log("Invalid Gemini response: " . json_encode($result));
    return 'Error: Invalid response structure from Gemini.';
}

function callAgentAPI($agentName, $conversationHistory) {
    global $agents;
    $apiType = $agents[$agentName]['api'];

    switch ($apiType) {
        case 'openai':
        case 'groq':
            return callOpenAICompatibleAPI($agentName, $conversationHistory);
        case 'grok':
             return callGrokAPI($agentName, $conversationHistory);
        case 'claude':
            return callClaudeAPI($agentName, $conversationHistory);
        case 'gemini':
            return callGeminiAPI($agentName, $conversationHistory);
        default:
            return "Error: Unknown API type '{$apiType}' for agent '{$agentName}'.";
    }
}

function extractAndSaveArtifacts($db, $responseText, $agentName, $conversationId) {
    $cleanText = $responseText;
    if (preg_match('/```CODE_OUTPUT:([^:]+):([^\n]+)\n(.*?)```/s', $responseText, $matches)) {
        $filename = trim($matches[1]);
        $language = trim($matches[2]);
        $codeContent = trim($matches[3]);

        $stmt = $db->prepare('INSERT INTO artifacts (conversation_id, agent_name, filename, language, content) VALUES (:conv_id, :agent, :filename, :lang, :content)');
        $stmt->bindValue(':conv_id', $conversationId, SQLITE3_INTEGER);
        $stmt->bindValue(':agent', $agentName, SQLITE3_TEXT);
        $stmt->bindValue(':filename', $filename, SQLITE3_TEXT);
        $stmt->bindValue(':lang', $language, SQLITE3_TEXT);
        $stmt->bindValue(':content', $codeContent, SQLITE3_TEXT);
        $stmt->execute();

        $cleanText = preg_replace('/```CODE_OUTPUT:.*?```/s', "[Code for {$filename} was added to the Artifacts panel.]", $responseText);
    }
    return $cleanText;
}

// --- AJAX REQUEST HANDLING ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['action'])) {
    header('Content-Type: application/json');
    $action = $_POST['action'];
    $response = ['success' => false];

    switch ($action) {
        case 'get_conversations':
            $result = $db->query('SELECT id, name, updated_at FROM conversations ORDER BY updated_at DESC');
            $convs = [];
            while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
                $convs[] = $row;
            }
            $response = ['success' => true, 'conversations' => $convs];
            break;

        case 'create_conversation':
            $name = $_POST['name'] ?? 'New Project';
            $stmt = $db->prepare('INSERT INTO conversations (name) VALUES (:name)');
            $stmt->bindValue(':name', $name, SQLITE3_TEXT);
            $stmt->execute();
            $response = ['success' => true, 'id' => $db->lastInsertRowID()];
            break;

        case 'load_conversation':
            $convId = $_POST['id'];
            $msgStmt = $db->prepare('SELECT speaker, content FROM messages WHERE conversation_id = :id ORDER BY created_at ASC');
            $msgStmt->bindValue(':id', $convId, SQLITE3_INTEGER);
            $msgResult = $msgStmt->execute();
            $messages = [];
            while ($row = $msgResult->fetchArray(SQLITE3_ASSOC)) $messages[] = $row;
            
            $artStmt = $db->prepare('SELECT id, agent_name, filename, language, content FROM artifacts WHERE conversation_id = :id ORDER BY created_at ASC');
            $artStmt->bindValue(':id', $convId, SQLITE3_INTEGER);
            $artResult = $artStmt->execute();
            $artifacts = [];
            while ($row = $artResult->fetchArray(SQLITE3_ASSOC)) $artifacts[] = $row;

            $response = ['success' => true, 'messages' => $messages, 'artifacts' => $artifacts];
            break;

        case 'send_message':
            $convId = $_POST['conversation_id'];
            $content = $_POST['content'];

            $stmt = $db->prepare('INSERT INTO messages (conversation_id, speaker, content) VALUES (:conv_id, "User", :content)');
            $stmt->bindValue(':conv_id', $convId, SQLITE3_INTEGER);
            $stmt->bindValue(':content', $content, SQLITE3_TEXT);
            $stmt->execute();
            
            $agentTurnOrder = array_keys($agents); // Use all agents including summarizer
            $newMessages = [];

            $historyResult = $db->query("SELECT speaker, content FROM messages WHERE conversation_id = {$convId} ORDER BY created_at DESC LIMIT 15");
            $historyArray = [];
            while($row = $historyResult->fetchArray(SQLITE3_ASSOC)) $historyArray[] = $row;
            $conversationHistory = implode("\n", array_map(fn($r) => "{$r['speaker']}: {$r['content']}", array_reverse($historyArray)));

            foreach ($agentTurnOrder as $agentName) {
                if ($agentName === 'Summarizer') continue; // Skip summarizer in main loop
                $rawResponse = callAgentAPI($agentName, $conversationHistory);
                $cleanResponse = extractAndSaveArtifacts($db, $rawResponse, $agentName, $convId);

                $msgStmt = $db->prepare('INSERT INTO messages (conversation_id, speaker, content) VALUES (:conv_id, :speaker, :content)');
                $msgStmt->bindValue(':conv_id', $convId, SQLITE3_INTEGER);
                $msgStmt->bindValue(':speaker', $agentName, SQLITE3_TEXT);
                $msgStmt->bindValue(':content', $cleanResponse, SQLITE3_TEXT);
                $msgStmt->execute();
                $newMessages[] = ['speaker' => $agentName, 'content' => $cleanResponse];
                $conversationHistory .= "\n{$agentName}: {$cleanResponse}"; // Update history for next agent
            }

            // Summarize at the end
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

            $artResult = $db->query("SELECT id, agent_name, filename, language, content FROM artifacts WHERE conversation_id = {$convId} ORDER BY created_at ASC");
            $newArtifacts = [];
            while ($row = $artResult->fetchArray(SQLITE3_ASSOC)) $newArtifacts[] = $row;

            $response = ['success' => true, 'new_messages' => $newMessages, 'all_artifacts' => $newArtifacts];
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
    <title>AI Dev Team Terminal</title>
    <style>
        :root {
            --bg-color: #0d0d0d;
            --text-color: #00ff41;
            --border-color: #00ff41;
            --panel-bg: rgba(20, 20, 20, 0.8);
            --header-bg: #1a1a1a;
            --hover-bg: rgba(0, 255, 65, 0.2);
        }
        body {
            background-color: var(--bg-color);
            color: var(--text-color);
            font-family: 'Courier New', Courier, monospace;
            margin: 0;
            overflow: hidden;
            display: flex;
            height: 100vh;
        }
        .panel {
            border: 1px solid var(--border-color);
            margin: 5px;
            display: flex;
            flex-direction: column;
            background: var(--panel-bg);
            box-shadow: 0 0 10px var(--border-color);
        }
        .panel-header {
            background: var(--header-bg);
            padding: 10px;
            font-weight: bold;
            border-bottom: 1px solid var(--border-color);
            text-shadow: 0 0 5px var(--text-color);
        }
        .panel-content {
            flex-grow: 1;
            overflow-y: auto;
            padding: 10px;
        }
        #left-panel { flex: 0 0 250px; }
        #new-project-btn {
            background: var(--border-color);
            color: var(--bg-color);
            border: none;
            padding: 10px;
            width: 100%;
            cursor: pointer;
            font-family: inherit;
            font-weight: bold;
            margin-bottom: 10px;
        }
        .project-item {
            padding: 8px;
            cursor: pointer;
            border-bottom: 1px dashed rgba(0, 255, 65, 0.3);
        }
        .project-item:hover, .project-item.active {
            background: var(--hover-bg);
        }
        #center-panel { flex-grow: 1; }
        #chat-window { display: flex; flex-direction: column; height: 100%; }
        #messages { flex-grow: 1; overflow-y: auto; padding: 10px; }
        .message { margin-bottom: 15px; }
        .message .speaker { font-weight: bold; }
        .message .content { white-space: pre-wrap; word-wrap: break-word; }
        .message .speaker.User { color: #ff4757; }
        .message .speaker.Claude { color: #ffa502; }
        .message .speaker.Grok { color: #2e86de; }
        .message .speaker.ChatGPT { color: #1dd1a1; }
        .message .speaker.Gemini { color: #5f27cd; }
        .message .speaker.GLM { color: #ff6b6b; }
        .message .speaker.Kimi, .message .speaker.Qwen, .message .speaker.DeepSeek, .message .speaker.Llama { color: #feca57; }
        .message .speaker.Summarizer { color: #7f8fa6; }
        #input-area { border-top: 1px solid var(--border-color); padding: 10px; display: flex; }
        #message-input {
            flex-grow: 1;
            background: #1a1a1a;
            border: 1px solid var(--border-color);
            color: var(--text-color);
            padding: 8px;
            font-family: inherit;
        }
        #send-btn {
            background: var(--border-color);
            color: var(--bg-color);
            border: none;
            padding: 0 15px;
            cursor: pointer;
            font-weight: bold;
        }
        #right-panel { flex: 0 0 350px; }
        .artifact-item { background: #1a1a1a; border: 1px solid #333; padding: 10px; margin-bottom: 10px; }
        .artifact-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 5px; }
        .artifact-filename { font-weight: bold; }
        .artifact-actions button {
            background: none;
            border: 1px solid var(--text-color);
            color: var(--text-color);
            cursor: pointer;
            font-family: inherit;
            margin-left: 5px;
        }
        .artifact-code {
            background: #0d0d0d;
            padding: 8px;
            max-height: 150px;
            overflow-y: auto;
            white-space: pre;
            font-size: 0.9em;
        }
        #thinking-indicator {
            text-align: center;
            padding: 10px;
            display: none;
            animation: blink 1.5s linear infinite;
        }
        @keyframes blink { 50% { opacity: 0; } }
    </style>
</head>
<body>

    <div id="left-panel" class="panel">
        <div class="panel-header">PROJECTS</div>
        <div class="panel-content">
            <button id="new-project-btn">NEW PROJECT</button>
            <div id="project-list"></div>
        </div>
    </div>

    <div id="center-panel" class="panel">
        <div id="chat-window">
            <div class="panel-header" id="chat-header">AI DEV TEAM</div>
            <div id="messages" class="panel-content"><p>Select a project or create a new one to begin.</p></div>
            <div id="thinking-indicator">AI team is thinking...</div>
            <div id="input-area">
                <input type="text" id="message-input" placeholder="Your command..." disabled>
                <button id="send-btn" disabled>SEND</button>
            </div>
        </div>
    </div>

    <div id="right-panel" class="panel">
        <div class="panel-header">ARTIFACTS</div>
        <div id="artifact-list" class="panel-content"></div>
    </div>

<script>
document.addEventListener('DOMContentLoaded', () => {
    const newProjectBtn = document.getElementById('new-project-btn');
    const projectList = document.getElementById('project-list');
    const chatHeader = document.getElementById('chat-header');
    const messagesDiv = document.getElementById('messages');
    const artifactList = document.getElementById('artifact-list');
    const messageInput = document.getElementById('message-input');
    const sendBtn = document.getElementById('send-btn');
    const thinkingIndicator = document.getElementById('thinking-indicator');

    let currentConversationId = null;

    async function apiCall(action, body = {}) {
        const formData = new FormData();
        formData.append('action', action);
        for (const key in body) {
            formData.append(key, body[key]);
        }
        const response = await fetch('', { method: 'POST', body: formData });
        return response.json();
    }

    function renderProjects(projects) {
        projectList.innerHTML = '';
        projects.forEach(p => {
            const div = document.createElement('div');
            div.className = 'project-item';
            div.textContent = p.name;
            div.dataset.id = p.id;
            if (p.id === currentConversationId) {
                div.classList.add('active');
            }
            div.addEventListener('click', () => selectConversation(p.id, p.name));
            projectList.appendChild(div);
        });
    }

    function renderMessages(msgs) {
        messagesDiv.innerHTML = '';
        if (msgs.length === 0) {
            messagesDiv.innerHTML = '<p>New project started. Send a message to the AI team.</p>';
        } else {
            msgs.forEach(msg => appendMessage(msg));
        }
    }

    function appendMessage(msg) {
        const div = document.createElement('div');
        div.className = 'message';
        div.innerHTML = `<div class="speaker ${msg.speaker.replace(/\s+/g, '')}">${escapeHtml(msg.speaker)}</div><div class="content">${escapeHtml(msg.content)}</div>`;
        messagesDiv.appendChild(div);
        messagesDiv.scrollTop = messagesDiv.scrollHeight;
    }

    function renderArtifacts(artifacts) {
        artifactList.innerHTML = '';
        artifacts.forEach(art => {
            const div = document.createElement('div');
            div.className = 'artifact-item';
            div.innerHTML = `
                <div class="artifact-header">
                    <span class="artifact-filename">${escapeHtml(art.filename)}</span>
                    <div class="artifact-actions">
                        <button class="copy-btn" data-id="${art.id}">Copy</button>
                    </div>
                </div>
                <pre class="artifact-code" id="code-${art.id}"><code>${escapeHtml(art.content)}</code></pre>
            `;
            artifactList.appendChild(div);
        });
        document.querySelectorAll('.copy-btn').forEach(btn => {
            btn.addEventListener('click', () => {
                const code = document.getElementById(`code-${btn.dataset.id}`).textContent;
                navigator.clipboard.writeText(code).then(() => alert('Code copied!'));
            });
        });
    }
    
    function escapeHtml(unsafe) {
        return unsafe
             .replace(/&/g, "&amp;")
             .replace(/</g, "&lt;")
             .replace(/>/g, "&gt;")
             .replace(/"/g, "&quot;")
             .replace(/'/g, "&#039;");
    }

    async function loadConversations() {
        const data = await apiCall('get_conversations');
        if (data.success) renderProjects(data.conversations);
    }

    async function selectConversation(id, name) {
        currentConversationId = id;
        chatHeader.textContent = `AI DEV TEAM - ${name}`;
        messageInput.disabled = false;
        sendBtn.disabled = false;
        document.querySelectorAll('.project-item').forEach(el => el.classList.remove('active'));
        document.querySelector(`.project-item[data-id='${id}']`).classList.add('active');
        
        const data = await apiCall('load_conversation', { id });
        if (data.success) {
            renderMessages(data.messages);
            renderArtifacts(data.artifacts);
        }
    }

    newProjectBtn.addEventListener('click', async () => {
        const name = prompt('Enter project name:', 'New AI Project');
        if (name) {
            const data = await apiCall('create_conversation', { name });
            if (data.success) {
                await loadConversations();
                selectConversation(data.id, name);
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

        const data = await apiCall('send_message', { conversation_id: currentConversationId, content });
        
        thinkingIndicator.style.display = 'none';
        messageInput.disabled = false;
        sendBtn.disabled = false;
        messageInput.focus();

        if (data.success) {
            data.new_messages.forEach(msg => appendMessage(msg));
            renderArtifacts(data.all_artifacts);
        }
    }

    sendBtn.addEventListener('click', handleSendMessage);
    messageInput.addEventListener('keypress', (e) => {
        if (e.key === 'Enter') handleSendMessage();
    });

    loadConversations();
});
</script>

</body>
</html>
