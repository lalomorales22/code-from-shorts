<?php
// GrokChatBot - Personalized AI Assistant w Image Gen and RAG

// Start session for authentication
session_start();

// Password configuration
$correct_password = 'password';

// Handle login
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'login') {
    $entered_password = $_POST['password'] ?? '';
    
    if ($entered_password === $correct_password) {
        $_SESSION['authenticated'] = true;
        header('Content-Type: application/json');
        echo json_encode(['success' => true]);
        exit;
    } else {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'error' => 'Incorrect password']);
        exit;
    }
}

// Handle logout
if (isset($_GET['logout'])) {
    session_destroy();
    header('Location: ' . $_SERVER['PHP_SELF']);
    exit;
}

// Check if user is authenticated for all other operations
$is_authenticated = isset($_SESSION['authenticated']) && $_SESSION['authenticated'] === true;

// Initialize SQLite Database
function initializeDatabase() {
    $db = new SQLite3('GrokChatBot.db');
    
    // Create chats table
    $db->exec('
        CREATE TABLE IF NOT EXISTS chats (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            title TEXT NOT NULL DEFAULT "New Chat",
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )
    ');
    
    // Create messages table
    $db->exec('
        CREATE TABLE IF NOT EXISTS messages (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            chat_id INTEGER,
            role TEXT NOT NULL,
            content TEXT NOT NULL,
            image_path TEXT,
            generated_image_path TEXT,
            message_type TEXT DEFAULT "text",
            timestamp DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (chat_id) REFERENCES chats (id) ON DELETE CASCADE
        )
    ');
    
    return $db;
}

// Only initialize database if authenticated
if ($is_authenticated) {
    // Load environment variables from .htaccess or .env
    if (file_exists('.env')) {
        $env = parse_ini_file('.env');
        foreach ($env as $key => $value) {
            $_ENV[$key] = $value;
        }
    }

    // Also check for Apache environment variables set via .htaccess
    if (isset($_SERVER['GROK_API_KEY'])) {
        $_ENV['GROK_API_KEY'] = $_SERVER['GROK_API_KEY'];
    }

    // Initialize database
    $db = initializeDatabase();

    // Grok API Configuration
    $grok_api_key = $_ENV['GROK_API_KEY'] ?? '';
    $grok_chat_url = 'https://api.x.ai/v1/chat/completions';
    $grok_image_url = 'https://api.x.ai/v1/images/generations';

    // AI Persona - Customizable for any user
    $ai_persona = "You are GrokChatBot, a personalized AI assistant created specifically for [ENTER-USER-NAME-HERE]! 🎉 

You're here to be [ENTER-USER-NAME-HERE]'s ultimate companion for navigating [ENTER-LIFE-STAGE-HERE] life. You should be:

HELPFUL WITH:
- Homework help (all subjects - math, science, English, history, etc.)
- Coding and software development projects
- Creative writing and ideas
- Life advice and decision-making
- Finding new hobbies and interests
- Social situations and friendship advice
- Future planning (college, career interests)
- Fun project ideas and challenges
- Analyzing and describing images that [ENTER-USER-NAME-HERE] shares with you (JPEG and PNG formats)
- Generating creative images from text descriptions

FAMILY BACKGROUND:
[ENTER-USER-NAME-HERE] lives in [ENTER-CITY-HERE] with [ENTER-FAMILY-MEMBERS-HERE]. [ENTER-ADDITIONAL-FAMILY-INFO-HERE]
- [ENTER-FAMILY-MEMBER-1-INFO-HERE]
- [ENTER-FAMILY-MEMBER-2-INFO-HERE]
- [ENTER-FAMILY-MEMBER-3-INFO-HERE]
- [ENTER-EXTENDED-FAMILY-INFO-HERE]
[ENTER-SPECIAL-PLACES-OR-CONNECTIONS-HERE]

PERSONALITY:
- Friendly, supportive, and encouraging
- Use casual language appropriate for [ENTER-AGE-GROUP-HERE] (but stay positive)
- Celebrate their achievements and help them through challenges
- Be genuinely interested in their interests and growth
- Offer practical, actionable advice
- Sometimes suggest fun challenges or projects to try
- When [ENTER-USER-NAME-HERE] shares images, describe what you see in detail and engage with the content meaningfully
- When [ENTER-USER-NAME-HERE] asks for image generation, create detailed and creative prompts
- You can reference their family members naturally in conversations when relevant

COMMUNICATION STYLE:
- Keep responses engaging and not too formal
- Use examples they can relate to
- Break down complex topics into manageable pieces
- Ask follow-up questions to understand what they need
- Be patient and never make them feel dumb for asking anything
- If you see an image, always acknowledge it and provide detailed observations about what you see
- Be excited and curious about the images [ENTER-USER-NAME-HERE] shares or wants to create
- Feel free to reference [ENTER-SPECIAL-INTERESTS-OR-TOPICS-HERE] when appropriate

TECHNICAL CAPABILITIES:
- You can see and analyze JPEG and PNG images that [ENTER-USER-NAME-HERE] uploads
- You can generate images from text descriptions using Grok-2-Image
- You're powered by Grok-2-Vision for images and Grok-3-Mini for text conversations
- You can help with visual homework, analyze photos, describe scenes, read text in images, and more

Remember: You're a helpful AI assistant designed to provide useful information, assistance, and engage in meaningful conversations. Always be encouraging and supportive. When users share images, be genuinely interested and provide thoughtful, detailed responses about what you observe.";

    // Database helper functions
    function createNewChat($db, $title = "New Chat") {
        $stmt = $db->prepare('INSERT INTO chats (title) VALUES (?)');
        $stmt->bindValue(1, $title, SQLITE3_TEXT);
        $stmt->execute();
        return $db->lastInsertRowID();
    }

    function saveMessage($db, $chatId, $role, $content, $imagePath = null, $generatedImagePath = null, $messageType = 'text') {
        $stmt = $db->prepare('INSERT INTO messages (chat_id, role, content, image_path, generated_image_path, message_type) VALUES (?, ?, ?, ?, ?, ?)');
        $stmt->bindValue(1, $chatId, SQLITE3_INTEGER);
        $stmt->bindValue(2, $role, SQLITE3_TEXT);
        $stmt->bindValue(3, $content, SQLITE3_TEXT);
        $stmt->bindValue(4, $imagePath, SQLITE3_TEXT);
        $stmt->bindValue(5, $generatedImagePath, SQLITE3_TEXT);
        $stmt->bindValue(6, $messageType, SQLITE3_TEXT);
        $stmt->execute();
        
        // Update chat timestamp
        $updateStmt = $db->prepare('UPDATE chats SET updated_at = CURRENT_TIMESTAMP WHERE id = ?');
        $updateStmt->bindValue(1, $chatId, SQLITE3_INTEGER);
        $updateStmt->execute();
    }

    function getChatHistory($db, $chatId) {
        $stmt = $db->prepare('SELECT role, content, image_path, generated_image_path, message_type, timestamp FROM messages WHERE chat_id = ? ORDER BY timestamp ASC');
        $stmt->bindValue(1, $chatId, SQLITE3_INTEGER);
        $result = $stmt->execute();
        
        $messages = [];
        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            $messages[] = $row;
        }
        return $messages;
    }

    function getAllChats($db) {
        $result = $db->query('SELECT id, title, updated_at FROM chats ORDER BY updated_at DESC');
        $chats = [];
        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            $chats[] = $row;
        }
        return $chats;
    }

    function updateChatTitle($db, $chatId, $title) {
        $stmt = $db->prepare('UPDATE chats SET title = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?');
        $stmt->bindValue(1, $title, SQLITE3_TEXT);
        $stmt->bindValue(2, $chatId, SQLITE3_INTEGER);
        return $stmt->execute();
    }

    function deleteChat($db, $chatId) {
        $stmt = $db->prepare('DELETE FROM chats WHERE id = ?');
        $stmt->bindValue(1, $chatId, SQLITE3_INTEGER);
        return $stmt->execute();
    }

    // Handle file uploads with image resizing - Updated for xAI requirements
    function handleImageUpload() {
        if (!isset($_FILES['image'])) {
            error_log("DEBUG: No image file in upload");
            return null;
        }
        
        if ($_FILES['image']['error'] !== UPLOAD_ERR_OK) {
            error_log("DEBUG: Upload error: " . $_FILES['image']['error']);
            return null;
        }
        
        // xAI only supports JPG/JPEG and PNG
        $allowedTypes = ['image/jpeg', 'image/png'];
        $fileType = $_FILES['image']['type'];
        
        if (!in_array($fileType, $allowedTypes)) {
            error_log("DEBUG: Invalid file type for xAI: " . $fileType);
            return null;
        }
        
        // Check file size (xAI limit is 10MiB, but we'll limit to 5MB for upload)
        if ($_FILES['image']['size'] > 5 * 1024 * 1024) {
            error_log("DEBUG: File too large: " . $_FILES['image']['size']);
            return null;
        }
        
        // Ensure uploads directory exists with proper permissions
        $uploadDir = __DIR__ . '/uploads/';
        if (!is_dir($uploadDir)) {
            if (!mkdir($uploadDir, 0755, true)) {
                error_log("ERROR: Could not create uploads directory");
                return null;
            }
        }
        
        // Generate unique filename
        $extension = pathinfo($_FILES['image']['name'], PATHINFO_EXTENSION);
        $filename = uniqid('img_', true) . '.jpg'; // Always save as JPG for consistency
        $uploadPath = $uploadDir . $filename;
        $relativePath = 'uploads/' . $filename;
        
        // Resize and compress image
        $resizedPath = resizeImage($_FILES['image']['tmp_name'], $uploadPath, $fileType);
        
        if ($resizedPath && file_exists($uploadPath) && is_readable($uploadPath)) {
            error_log("DEBUG: Image processed and uploaded successfully: " . $relativePath);
            return $relativePath;
        }
        
        error_log("ERROR: Failed to process uploaded file");
        return null;
    }

    // Save generated image from base64 data
    function saveGeneratedImage($base64Data) {
        // Ensure uploads directory exists with proper permissions
        $uploadDir = __DIR__ . '/uploads/';
        if (!is_dir($uploadDir)) {
            if (!mkdir($uploadDir, 0755, true)) {
                error_log("ERROR: Could not create uploads directory");
                return null;
            }
        }
        
        // Generate unique filename for generated image
        $filename = uniqid('gen_img_', true) . '.jpg';
        $uploadPath = $uploadDir . $filename;
        $relativePath = 'uploads/' . $filename;
        
        // Remove data:image/jpeg;base64, prefix if present
        if (strpos($base64Data, 'data:image') === 0) {
            $base64Data = substr($base64Data, strpos($base64Data, ',') + 1);
        }
        
        // Decode and save
        $imageData = base64_decode($base64Data);
        if ($imageData !== false && file_put_contents($uploadPath, $imageData)) {
            error_log("DEBUG: Generated image saved successfully: " . $relativePath);
            return $relativePath;
        }
        
        error_log("ERROR: Failed to save generated image");
        return null;
    }

    // Resize image to reduce API payload size - Updated for xAI supported formats
    function resizeImage($sourcePath, $targetPath, $sourceType) {
        // Check if GD extension is available
        if (!extension_loaded('gd')) {
            error_log("ERROR: GD extension not available, skipping image resize");
            // Fallback: just copy the original file
            return copy($sourcePath, $targetPath);
        }
        
        try {
            // Create image resource based on type (xAI only supports JPEG and PNG)
            switch ($sourceType) {
                case 'image/jpeg':
                    $sourceImage = imagecreatefromjpeg($sourcePath);
                    break;
                case 'image/png':
                    $sourceImage = imagecreatefrompng($sourcePath);
                    break;
                default:
                    error_log("ERROR: Unsupported image type for xAI: " . $sourceType);
                    return copy($sourcePath, $targetPath);
            }
            
            if (!$sourceImage) {
                error_log("ERROR: Could not create image resource from: " . $sourcePath);
                return copy($sourcePath, $targetPath);
            }
            
            // Get original dimensions
            $originalWidth = imagesx($sourceImage);
            $originalHeight = imagesy($sourceImage);
            
            // Calculate new dimensions (max 1024px on either side for better performance)
            $maxDimension = 1024;
            if ($originalWidth > $maxDimension || $originalHeight > $maxDimension) {
                if ($originalWidth > $originalHeight) {
                    $newWidth = $maxDimension;
                    $newHeight = intval(($originalHeight * $maxDimension) / $originalWidth);
                } else {
                    $newHeight = $maxDimension;
                    $newWidth = intval(($originalWidth * $maxDimension) / $originalHeight);
                }
            } else {
                $newWidth = $originalWidth;
                $newHeight = $originalHeight;
            }
            
            // Create new image
            $newImage = imagecreatetruecolor($newWidth, $newHeight);
            
            // Handle transparency for PNG
            if ($sourceType === 'image/png') {
                imagealphablending($newImage, false);
                imagesavealpha($newImage, true);
                $transparent = imagecolorallocatealpha($newImage, 255, 255, 255, 127);
                imagefill($newImage, 0, 0, $transparent);
            }
            
            // Resize image
            imagecopyresampled($newImage, $sourceImage, 0, 0, 0, 0, $newWidth, $newHeight, $originalWidth, $originalHeight);
            
            // Save as JPEG with quality 90 for better image quality
            $result = imagejpeg($newImage, $targetPath, 90);
            
            // Clean up
            imagedestroy($sourceImage);
            imagedestroy($newImage);
            
            if ($result) {
                error_log("DEBUG: Image resized from {$originalWidth}x{$originalHeight} to {$newWidth}x{$newHeight}");
                return true;
            } else {
                error_log("ERROR: Failed to save resized image to: " . $targetPath);
                return false;
            }
            
        } catch (Exception $e) {
            error_log("ERROR: Exception in resizeImage: " . $e->getMessage());
            // Fallback: copy original file
            return copy($sourcePath, $targetPath);
        }
    }

    // Grok Chat API call with improved model selection to prevent 400 errors
    function callGrokAPI($messages, $imagePath = null) {
        global $grok_api_key, $grok_chat_url;
        
        if (empty($grok_api_key)) {
            error_log("ERROR: Grok API key not configured");
            return 'Error: Grok API key not configured. Please set GROK_API_KEY in your .env file.';
        }
        
        // Prepare messages array and check if conversation has ANY images
        $apiMessages = [];
        $conversationHasImages = false;
        
        foreach ($messages as $msg) {
            $messageContent = [];
            
            // Add text content
            if (!empty($msg['content'])) {
                $messageContent[] = [
                    'type' => 'text',
                    'text' => $msg['content']
                ];
            }
            
            // Add image if present
            if (!empty($msg['image_path'])) {
                $fullImagePath = __DIR__ . '/' . $msg['image_path'];
                error_log("DEBUG: Processing image at: " . $fullImagePath);
                
                if (file_exists($fullImagePath) && is_readable($fullImagePath)) {
                    try {
                        $imageData = file_get_contents($fullImagePath);
                        if ($imageData !== false) {
                            // Get file size for logging
                            $fileSize = strlen($imageData);
                            error_log("DEBUG: Image file size: " . $fileSize . " bytes");
                            
                            // Check if image is too large (xAI limit is 10MiB)
                            if ($fileSize > 10 * 1024 * 1024) {
                                error_log("ERROR: Image too large for API: " . $fileSize);
                                continue;
                            }
                            
                            $base64Image = base64_encode($imageData);
                            
                            // Use JPEG mime type since we're saving all as JPEG
                            $imageMime = 'image/jpeg';
                            
                            // Add image with proper xAI format including detail field
                            $messageContent[] = [
                                'type' => 'image_url',
                                'image_url' => [
                                    'url' => "data:$imageMime;base64,$base64Image",
                                    'detail' => 'high'  // Required by xAI API
                                ]
                            ];
                            
                            $conversationHasImages = true;
                            error_log("DEBUG: Image successfully added to API request - Base64 size: " . strlen($base64Image));
                        } else {
                            error_log("ERROR: Could not read image file: " . $fullImagePath);
                        }
                    } catch (Exception $e) {
                        error_log("ERROR: Exception reading image: " . $e->getMessage());
                    }
                } else {
                    error_log("ERROR: Image file not found or not readable: " . $fullImagePath);
                }
            }
            
            // Only add message if it has content
            if (!empty($messageContent)) {
                $apiMessages[] = [
                    'role' => $msg['role'],
                    'content' => $messageContent
                ];
            }
        }
        
        // CRITICAL FIX: Use vision model if conversation has ANY images, even for text-only follow-ups
        $model = $conversationHasImages ? 'grok-2-vision' : 'grok-3-mini-latest';
        
        $data = [
            'model' => $model,
            'messages' => $apiMessages,
            'max_tokens' => 1500,
            'temperature' => 0.7
        ];

        error_log("DEBUG: Using model: $model, Sending " . count($apiMessages) . " messages, Conversation has images: " . ($conversationHasImages ? 'yes' : 'no'));
        
        // Log the payload size
        $payloadSize = strlen(json_encode($data));
        error_log("DEBUG: Total payload size: " . $payloadSize . " bytes");

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $grok_chat_url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($data),
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $grok_api_key
            ],
            CURLOPT_TIMEOUT => 60, // Increase timeout for image processing
            CURLOPT_CONNECTTIMEOUT => 15
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($curlError) {
            error_log("CURL Error: " . $curlError);
            return 'Error: Network connection failed';
        }

        error_log("DEBUG: API Response - HTTP Code: $httpCode");

        if ($httpCode === 200) {
            $result = json_decode($response, true);
            if (isset($result['choices'][0]['message']['content'])) {
                return $result['choices'][0]['message']['content'];
            } else {
                error_log("ERROR: Unexpected API response format: " . substr($response, 0, 1000));
                return 'Error: Unexpected response format from AI';
            }
        }
        
        // Enhanced error logging for debugging
        error_log("Grok API Error - HTTP Code: $httpCode");
        error_log("Full API Response: " . $response);
        
        // Try to parse error message
        $errorResult = json_decode($response, true);
        if (isset($errorResult['error']['message'])) {
            $errorMsg = $errorResult['error']['message'];
            error_log("API Error Message: " . $errorMsg);
            
            // Handle specific error cases
            if (strpos($errorMsg, 'model') !== false && $conversationHasImages) {
                return 'Error: The vision model is not available. Please try again later or start a new chat for text only.';
            }
            
            return 'Error: ' . $errorMsg;
        }
        
        // Handle common HTTP error codes
        switch ($httpCode) {
            case 400:
                return 'Error: Invalid request format. This might be due to mixing image and text models. Try starting a new chat.';
            case 401:
                return 'Error: Invalid API key. Please check your Grok API configuration.';
            case 404:
                return 'Error: Model not found. Please check if you have access to the vision models.';
            case 429:
                return 'Error: Rate limit exceeded. Please wait a moment and try again.';
            case 500:
                return 'Error: Server error. Please try again later.';
            default:
                return 'Error: Failed to get response from GrokChatBot (HTTP ' . $httpCode . ')';
        }
    }

    // NEW: Grok Image Generation API call
    function callGrokImageAPI($prompt, $numImages = 1) {
        global $grok_api_key, $grok_image_url;
        
        if (empty($grok_api_key)) {
            error_log("ERROR: Grok API key not configured");
            return ['error' => 'Error: Grok API key not configured. Please set GROK_API_KEY in your .env file.'];
        }
        
        $data = [
            'model' => 'grok-2-image',
            'prompt' => $prompt,
            'response_format' => 'b64_json',
            'n' => min($numImages, 4) // Limit to 4 images max for performance
        ];

        error_log("DEBUG: Generating $numImages image(s) with prompt: " . substr($prompt, 0, 100));

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $grok_image_url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($data),
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $grok_api_key
            ],
            CURLOPT_TIMEOUT => 120, // Longer timeout for image generation
            CURLOPT_CONNECTTIMEOUT => 15
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($curlError) {
            error_log("CURL Error (Image): " . $curlError);
            return ['error' => 'Error: Network connection failed while generating image'];
        }

        error_log("DEBUG: Image API Response - HTTP Code: $httpCode");

        if ($httpCode === 200) {
            $result = json_decode($response, true);
            if (isset($result['data']) && is_array($result['data'])) {
                $images = [];
                foreach ($result['data'] as $imageData) {
                    if (isset($imageData['b64_json'])) {
                        // Save the generated image
                        $savedPath = saveGeneratedImage($imageData['b64_json']);
                        if ($savedPath) {
                            $images[] = [
                                'path' => $savedPath,
                                'revised_prompt' => $imageData['revised_prompt'] ?? $prompt
                            ];
                        }
                    }
                }
                return ['success' => true, 'images' => $images];
            } else {
                error_log("ERROR: Unexpected image API response format: " . substr($response, 0, 1000));
                return ['error' => 'Error: Unexpected response format from image generation'];
            }
        }
        
        // Enhanced error logging for debugging
        error_log("Grok Image API Error - HTTP Code: $httpCode");
        error_log("Full Image API Response: " . $response);
        
        // Try to parse error message
        $errorResult = json_decode($response, true);
        if (isset($errorResult['error']['message'])) {
            $errorMsg = $errorResult['error']['message'];
            error_log("Image API Error Message: " . $errorMsg);
            return ['error' => 'Error: ' . $errorMsg];
        }
        
        // Handle common HTTP error codes
        switch ($httpCode) {
            case 400:
                return ['error' => 'Error: Invalid image generation request. Please check your prompt.'];
            case 401:
                return ['error' => 'Error: Invalid API key for image generation.'];
            case 429:
                return ['error' => 'Error: Rate limit exceeded for image generation. Please wait a moment and try again.'];
            case 500:
                return ['error' => 'Error: Server error during image generation. Please try again later.'];
            default:
                return ['error' => 'Error: Failed to generate image (HTTP ' . $httpCode . ')'];
        }
    }
}

// Handle AJAX requests (only if authenticated)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $is_authenticated) {
    header('Content-Type: application/json');
    
    $action = $_POST['action'] ?? '';
    
    switch ($action) {
        case 'new_chat':
            $chatId = createNewChat($db);
            echo json_encode(['success' => true, 'chat_id' => $chatId]);
            exit;
            
        case 'get_chats':
            $chats = getAllChats($db);
            echo json_encode(['success' => true, 'chats' => $chats]);
            exit;
            
        case 'get_chat_history':
            $chatId = intval($_POST['chat_id']);
            $history = getChatHistory($db, $chatId);
            echo json_encode(['success' => true, 'history' => $history]);
            exit;
            
        case 'send_message':
            $chatId = intval($_POST['chat_id']);
            $message = $_POST['message'] ?? '';
            
            if (empty($message)) {
                echo json_encode(['success' => false, 'error' => 'Message cannot be empty']);
                exit;
            }
            
            error_log("DEBUG: Processing message for chat $chatId: " . substr($message, 0, 100));
            
            // Handle image upload
            $imagePath = handleImageUpload();
            if ($imagePath) {
                error_log("DEBUG: Image uploaded successfully: $imagePath");
            }
            
            // Save user message
            saveMessage($db, $chatId, 'user', $message, $imagePath);
            
            // Get chat history for context
            $history = getChatHistory($db, $chatId);
            error_log("DEBUG: Retrieved " . count($history) . " messages from history");
            
            // Prepare messages for API (include system message)
            $apiMessages = [
                ['role' => 'system', 'content' => $ai_persona, 'image_path' => null]
            ];
            
            // Add chat history
            foreach ($history as $msg) {
                $apiMessages[] = [
                    'role' => $msg['role'],
                    'content' => $msg['content'],
                    'image_path' => $msg['image_path']
                ];
            }
            
            // Get AI response
            $aiResponse = callGrokAPI($apiMessages);
            
            // Save AI response
            saveMessage($db, $chatId, 'assistant', $aiResponse);
            
            echo json_encode(['success' => true, 'response' => $aiResponse]);
            exit;

        case 'generate_image':
            $chatId = intval($_POST['chat_id']);
            $prompt = $_POST['prompt'] ?? '';
            $numImages = intval($_POST['num_images'] ?? 1);
            
            if (empty($prompt)) {
                echo json_encode(['success' => false, 'error' => 'Image prompt cannot be empty']);
                exit;
            }
            
            error_log("DEBUG: Generating image for chat $chatId with prompt: " . substr($prompt, 0, 100));
            
            // Save user message
            saveMessage($db, $chatId, 'user', $prompt, null, null, 'image_request');
            
            // Generate image(s)
            $imageResult = callGrokImageAPI($prompt, $numImages);
            
            if (isset($imageResult['error'])) {
                // Save error message
                saveMessage($db, $chatId, 'assistant', $imageResult['error'], null, null, 'error');
                echo json_encode(['success' => false, 'error' => $imageResult['error']]);
                exit;
            }
            
            if (isset($imageResult['success']) && !empty($imageResult['images'])) {
                $responseText = "I've generated " . count($imageResult['images']) . " image(s) for you! 🎨";
                if (count($imageResult['images']) === 1 && isset($imageResult['images'][0]['revised_prompt'])) {
                    $responseText .= "\n\nRevised prompt: " . $imageResult['images'][0]['revised_prompt'];
                }
                
                // Save the response with generated images
                $imagePaths = array_map(function($img) { return $img['path']; }, $imageResult['images']);
                saveMessage($db, $chatId, 'assistant', $responseText, null, implode(',', $imagePaths), 'image_generation');
                
                echo json_encode([
                    'success' => true, 
                    'response' => $responseText,
                    'images' => $imagePaths,
                    'revised_prompt' => $imageResult['images'][0]['revised_prompt'] ?? $prompt
                ]);
                exit;
            }
            
            echo json_encode(['success' => false, 'error' => 'Failed to generate image']);
            exit;
            
        case 'rename_chat':
            $chatId = intval($_POST['chat_id']);
            $title = $_POST['title'] ?? '';
            $success = updateChatTitle($db, $chatId, $title);
            echo json_encode(['success' => $success]);
            exit;
            
        case 'delete_chat':
            $chatId = intval($_POST['chat_id']);
            $success = deleteChat($db, $chatId);
            echo json_encode(['success' => $success]);
            exit;
    }
    
    echo json_encode(['success' => false, 'error' => 'Unknown action']);
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>GrokChatBot - Your Personal Assistant</title>
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap');

        :root {
            --bg-primary: #0a0a0a;
            --bg-secondary: #111111;
            --bg-tertiary: #1a1a1a;
            --text-primary: #ffffff;
            --text-secondary: #b3b3b3;
            --text-muted: #666666;
            --accent-primary: #3b82f6;
            --accent-secondary: #1d4ed8;
            --accent-purple: #8b5cf6;
            --accent-purple-dark: #7c3aed;
            --border-color: #262626;
            --success-color: #10b981;
            --danger-color: #ef4444;
            --warning-color: #f59e0b;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Inter', sans-serif;
            background-color: var(--bg-primary);
            color: var(--text-primary);
            overflow: hidden;
            height: 100vh;
        }

        /* Login Page */
        .login-page {
            display: <?php echo $is_authenticated ? 'none' : 'flex'; ?>;
            flex-direction: column;
            justify-content: center;
            align-items: center;
            height: 100vh;
            text-align: center;
            background: linear-gradient(135deg, #0a0a0a 0%, #1a1a1a 100%);
        }

        .login-container {
            background: var(--bg-secondary);
            border: 1px solid var(--border-color);
            border-radius: 16px;
            padding: 3rem;
            width: 100%;
            max-width: 400px;
            box-shadow: 0 20px 40px rgba(0, 0, 0, 0.3);
        }

        .login-title {
            font-size: 2.5rem;
            font-weight: 300;
            margin-bottom: 1rem;
            background: linear-gradient(135deg, #ffffff 0%, #b3b3b3 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }

        .login-subtitle {
            font-size: 1rem;
            color: var(--text-secondary);
            margin-bottom: 2rem;
        }

        .login-form {
            display: flex;
            flex-direction: column;
            gap: 1.5rem;
        }

        .password-input {
            background: var(--bg-tertiary);
            border: 1px solid var(--border-color);
            color: var(--text-primary);
            padding: 1rem 1.25rem;
            border-radius: 8px;
            font-size: 1rem;
            font-family: inherit;
            outline: none;
            transition: border-color 0.2s ease;
        }

        .password-input:focus {
            border-color: var(--accent-primary);
        }

        .password-input::placeholder {
            color: var(--text-muted);
        }

        .login-button {
            background: linear-gradient(135deg, var(--accent-primary) 0%, var(--accent-secondary) 100%);
            border: none;
            padding: 1rem 2rem;
            font-size: 1rem;
            font-weight: 600;
            color: white;
            border-radius: 8px;
            cursor: pointer;
            transition: all 0.3s ease;
            box-shadow: 0 4px 20px rgba(59, 130, 246, 0.3);
        }

        .login-button:hover:not(:disabled) {
            transform: translateY(-1px);
            box-shadow: 0 6px 25px rgba(59, 130, 246, 0.4);
        }

        .login-button:disabled {
            background: var(--text-muted);
            cursor: not-allowed;
            transform: none;
            box-shadow: none;
        }

        .login-error {
            color: var(--danger-color);
            font-size: 0.9rem;
            margin-top: 0.5rem;
            opacity: 0;
            transition: opacity 0.3s ease;
        }

        .login-error.show {
            opacity: 1;
        }

        /* Landing Page */
        .landing-page {
            display: <?php echo $is_authenticated ? 'flex' : 'none'; ?>;
            flex-direction: column;
            justify-content: center;
            align-items: center;
            height: 100vh;
            text-align: center;
            background: linear-gradient(135deg, #0a0a0a 0%, #1a1a1a 100%);
        }

        .landing-title {
            font-size: 4rem;
            font-weight: 300;
            margin-bottom: 2rem;
            background: linear-gradient(135deg, #ffffff 0%, #b3b3b3 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }

        .landing-subtitle {
            font-size: 1.2rem;
            color: var(--text-secondary);
            margin-bottom: 3rem;
            max-width: 600px;
        }

        .start-button {
            background: linear-gradient(135deg, var(--accent-primary) 0%, var(--accent-secondary) 100%);
            border: none;
            padding: 1rem 2.5rem;
            font-size: 1.1rem;
            font-weight: 600;
            color: white;
            border-radius: 50px;
            cursor: pointer;
            transition: all 0.3s ease;
            box-shadow: 0 4px 20px rgba(59, 130, 246, 0.3);
        }

        .start-button:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 30px rgba(59, 130, 246, 0.4);
        }

        .logout-link {
            position: absolute;
            top: 2rem;
            right: 2rem;
            color: var(--text-secondary);
            text-decoration: none;
            font-size: 0.9rem;
            transition: color 0.2s ease;
        }

        .logout-link:hover {
            color: var(--text-primary);
        }

        /* Chat Interface */
        .chat-interface {
            display: none;
            height: 100vh;
            flex-direction: row;
        }

        /* Sidebar */
        .sidebar {
            width: 300px;
            background-color: var(--bg-secondary);
            border-right: 1px solid var(--border-color);
            display: flex;
            flex-direction: column;
            height: 100vh;
        }

        .sidebar-header {
            padding: 1.5rem;
            border-bottom: 1px solid var(--border-color);
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 1rem;
        }

        .sidebar-left {
            display: flex;
            align-items: center;
            gap: 1rem;
        }

        .logo {
            font-size: 1.5rem;
            font-weight: 700;
            background: linear-gradient(135deg, var(--accent-primary) 0%, var(--accent-secondary) 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }

        .new-chat-btn {
            background: var(--accent-primary);
            border: none;
            color: white;
            padding: 0.5rem 1rem;
            border-radius: 8px;
            cursor: pointer;
            font-size: 0.9rem;
            transition: all 0.2s ease;
        }

        .new-chat-btn:hover {
            background: var(--accent-secondary);
        }

        .chat-list {
            flex: 1;
            overflow-y: auto;
            padding: 1rem;
        }

        .chat-item {
            padding: 0.75rem;
            margin-bottom: 0.5rem;
            border-radius: 8px;
            cursor: pointer;
            transition: all 0.2s ease;
            border: 1px solid transparent;
            display: flex;
            justify-content: space-between;
            align-items: center;
            group: chat-item;
        }

        .chat-item:hover {
            background-color: var(--bg-tertiary);
            border-color: var(--border-color);
        }

        .chat-item.active {
            background-color: var(--accent-primary);
            color: white;
        }

        .chat-title {
            flex: 1;
            font-size: 0.9rem;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .chat-actions {
            display: none;
            gap: 0.25rem;
        }

        .chat-item:hover .chat-actions {
            display: flex;
        }

        .chat-action-btn {
            background: none;
            border: none;
            color: var(--text-muted);
            cursor: pointer;
            padding: 0.25rem;
            border-radius: 4px;
            font-size: 0.8rem;
        }

        .chat-action-btn:hover {
            background-color: rgba(255, 255, 255, 0.1);
            color: var(--text-primary);
        }

        /* Main Chat Area */
        .chat-area {
            flex: 1;
            display: flex;
            flex-direction: column;
            height: 100vh;
        }

        .chat-header {
            padding: 1.5rem;
            border-bottom: 1px solid var(--border-color);
            background-color: var(--bg-secondary);
        }

        .chat-title-main {
            font-size: 1.2rem;
            font-weight: 600;
        }

        .chat-messages {
            flex: 1;
            overflow-y: auto;
            padding: 1.5rem;
            display: flex;
            flex-direction: column;
            gap: 1.5rem;
        }

        .message {
            display: flex;
            gap: 1rem;
            max-width: 80%;
            animation: fadeIn 0.3s ease-out;
        }

        .message.user {
            align-self: flex-end;
            flex-direction: row-reverse;
        }

        .message.assistant {
            align-self: flex-start;
        }

        .message-avatar {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 600;
            font-size: 0.9rem;
            flex-shrink: 0;
        }

        .message.user .message-avatar {
            background: linear-gradient(135deg, var(--accent-primary) 0%, var(--accent-secondary) 100%);
            color: white;
        }

        .message.assistant .message-avatar {
            background: var(--bg-tertiary);
            color: var(--text-primary);
        }

        .message-content {
            background-color: var(--bg-secondary);
            padding: 1rem 1.25rem;
            border-radius: 16px;
            border: 1px solid var(--border-color);
            line-height: 1.6;
            position: relative;
        }

        .message.user .message-content {
            background: linear-gradient(135deg, var(--accent-primary) 0%, var(--accent-secondary) 100%);
            color: white;
            border: none;
        }

        .message-text {
            word-wrap: break-word;
            white-space: pre-wrap;
        }

        /* Cursor effect for streaming text */
        .streaming-cursor::after {
            content: '|';
            animation: blink 1s infinite;
            color: var(--accent-primary);
        }

        @keyframes blink {
            0%, 50% { opacity: 1; }
            51%, 100% { opacity: 0; }
        }

        .message-image {
            max-width: 300px;
            max-height: 300px;
            border-radius: 8px;
            margin-top: 0.5rem;
            object-fit: cover;
            cursor: pointer;
            transition: transform 0.2s ease, opacity 0.3s ease;
            opacity: 0;
        }

        .message-image.loaded {
            opacity: 1;
        }

        .message-image:hover {
            transform: scale(1.02);
        }

        /* Generated Images Grid */
        .generated-images {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 0.75rem;
            margin-top: 0.75rem;
        }

        .generated-image {
            max-width: 100%;
            border-radius: 8px;
            object-fit: cover;
            cursor: pointer;
            transition: transform 0.2s ease, opacity 0.3s ease;
            opacity: 0;
            aspect-ratio: 1;
        }

        .generated-image.loaded {
            opacity: 1;
        }

        .generated-image:hover {
            transform: scale(1.02);
        }

        /* Input Area */
        .input-area {
            padding: 1.5rem;
            border-top: 1px solid var(--border-color);
            background-color: var(--bg-secondary);
        }

        .input-container {
            display: flex;
            gap: 1rem;
            align-items: flex-end;
            background-color: var(--bg-tertiary);
            border: 1px solid var(--border-color);
            border-radius: 12px;
            padding: 1rem;
        }

        .input-wrapper {
            flex: 1;
            display: flex;
            flex-direction: column;
            gap: 0.5rem;
        }

        /* Mode Toggle */
        .mode-toggle {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            margin-bottom: 0.5rem;
            font-size: 0.9rem;
        }

        .mode-switch {
            position: relative;
            display: inline-block;
            width: 60px;
            height: 28px;
        }

        .mode-switch input {
            opacity: 0;
            width: 0;
            height: 0;
        }

        .mode-slider {
            position: absolute;
            cursor: pointer;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: linear-gradient(135deg, var(--accent-primary) 0%, var(--accent-secondary) 100%);
            transition: 0.3s;
            border-radius: 34px;
        }

        .mode-slider:before {
            position: absolute;
            content: "";
            height: 20px;
            width: 20px;
            left: 4px;
            bottom: 4px;
            background-color: white;
            transition: 0.3s;
            border-radius: 50%;
        }

        input:checked + .mode-slider {
            background: linear-gradient(135deg, var(--accent-purple) 0%, var(--accent-purple-dark) 100%);
        }

        input:checked + .mode-slider:before {
            transform: translateX(32px);
        }

        .mode-label {
            color: var(--text-secondary);
            transition: color 0.3s ease;
        }

        .mode-label.active {
            color: var(--text-primary);
            font-weight: 500;
        }

        .message-input {
            background: none;
            border: none;
            color: var(--text-primary);
            font-size: 1rem;
            resize: none;
            min-height: 24px;
            max-height: 120px;
            outline: none;
            font-family: inherit;
        }

        .message-input::placeholder {
            color: var(--text-muted);
        }

        .file-upload-area {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            flex-wrap: wrap;
        }

        .file-input {
            display: none;
        }

        .file-upload-btn {
            background: none;
            border: 1px solid var(--border-color);
            color: var(--text-secondary);
            padding: 0.5rem;
            border-radius: 8px;
            cursor: pointer;
            font-size: 0.9rem;
            transition: all 0.2s ease;
        }

        .file-upload-btn:hover {
            border-color: var(--accent-primary);
            color: var(--accent-primary);
        }

        .file-preview {
            font-size: 0.8rem;
            color: var(--success-color);
            display: flex;
            align-items: center;
            gap: 0.25rem;
        }

        .file-preview.has-file {
            background: rgba(16, 185, 129, 0.1);
            padding: 0.25rem 0.5rem;
            border-radius: 6px;
            border: 1px solid rgba(16, 185, 129, 0.3);
        }

        /* Image Generation Controls */
        .image-controls {
            display: none;
            align-items: center;
            gap: 0.5rem;
            flex-wrap: wrap;
        }

        .image-controls.active {
            display: flex;
        }

        .num-images-control {
            display: flex;
            align-items: center;
            gap: 0.25rem;
            font-size: 0.8rem;
            color: var(--text-secondary);
        }

        .num-images-select {
            background: var(--bg-secondary);
            border: 1px solid var(--border-color);
            color: var(--text-primary);
            padding: 0.25rem 0.5rem;
            border-radius: 4px;
            font-size: 0.8rem;
        }

        .send-button {
            background: var(--accent-primary);
            border: none;
            color: white;
            padding: 0.75rem 1.25rem;
            border-radius: 8px;
            cursor: pointer;
            font-weight: 600;
            transition: all 0.2s ease;
        }

        .send-button:hover:not(:disabled) {
            background: var(--accent-secondary);
        }

        .send-button.image-mode {
            background: linear-gradient(135deg, var(--accent-purple) 0%, var(--accent-purple-dark) 100%);
        }

        .send-button.image-mode:hover:not(:disabled) {
            background: linear-gradient(135deg, var(--accent-purple-dark) 0%, #6d28d9 100%);
        }

        .send-button:disabled {
            background: var(--text-muted);
            cursor: not-allowed;
        }

        /* Welcome Message */
        .welcome-message {
            text-align: center;
            color: var(--text-secondary);
            margin: 2rem;
            padding: 2rem;
            border: 1px dashed var(--border-color);
            border-radius: 12px;
        }

        /* Animations */
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(10px); }
            to { opacity: 1; transform: translateY(0); }
        }

        .thinking {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            color: var(--text-muted);
            font-style: italic;
        }

        .thinking-dots {
            display: flex;
            gap: 2px;
        }

        .thinking-dot {
            width: 4px;
            height: 4px;
            background-color: var(--text-muted);
            border-radius: 50%;
            animation: bounce 1.4s infinite ease-in-out both;
        }

        .thinking-dot:nth-child(1) { animation-delay: -0.32s; }
        .thinking-dot:nth-child(2) { animation-delay: -0.16s; }

        @keyframes bounce {
            0%, 80%, 100% { transform: scale(0); }
            40% { transform: scale(1.0); }
        }

        /* Scrollbar */
        ::-webkit-scrollbar {
            width: 8px;
        }

        ::-webkit-scrollbar-track {
            background: var(--bg-primary);
        }

        ::-webkit-scrollbar-thumb {
            background: var(--border-color);
            border-radius: 4px;
        }

        ::-webkit-scrollbar-thumb:hover {
            background: var(--text-muted);
        }

        /* Mobile Toggle Button - Outside Sidebar */
        .mobile-toggle-outside {
            display: none;
            background: none;
            border: none;
            color: var(--text-primary);
            font-size: 1.5rem;
            padding: 0.5rem;
            cursor: pointer;
            position: fixed;
            top: 1rem;
            left: 1rem;
            z-index: 1001;
            background-color: var(--bg-secondary);
            border-radius: 8px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.3);
        }

        /* Mobile Toggle Button - Inside Sidebar */
        .mobile-toggle-inside {
            display: none;
            background: none;
            border: none;
            color: var(--text-primary);
            font-size: 1.2rem;
            padding: 0.5rem;
            cursor: pointer;
            border-radius: 6px;
            transition: background-color 0.2s ease;
        }

        .mobile-toggle-inside:hover {
            background-color: var(--bg-tertiary);
        }

        .mobile-overlay {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background-color: rgba(0, 0, 0, 0.5);
            z-index: 999;
        }

        /* Mobile Responsive */
        @media (max-width: 768px) {
            .mobile-toggle-outside {
                display: block;
            }

            .mobile-toggle-inside {
                display: block;
            }

            .sidebar {
                position: fixed;
                top: 0;
                left: -100%;
                width: 320px;
                height: 100vh;
                z-index: 1000;
                transition: left 0.3s ease;
            }

            .sidebar.open {
                left: 0;
            }

            .sidebar-header {
                padding: 1.2rem;
            }

            .logo {
                font-size: 1.4rem;
                flex-shrink: 0;
            }

            .new-chat-btn {
                padding: 0.4rem 0.8rem;
                font-size: 0.85rem;
                flex-shrink: 0;
            }

            .chat-area {
                width: 100%;
                margin-left: 0;
            }

            .mobile-overlay.show {
                display: block;
            }
            
            .landing-title {
                font-size: 2.5rem;
            }
            
            .landing-subtitle {
                font-size: 1rem;
                padding: 0 1rem;
            }
            
            .message {
                max-width: 95%;
            }

            .input-container {
                flex-direction: column;
                gap: 0.75rem;
            }

            .file-upload-area {
                order: -1;
            }

            .message-input {
                min-height: 40px;
            }

            .chat-messages {
                padding: 1rem;
                margin-left: 0;
            }

            .input-area {
                padding: 1rem;
                margin-left: 0;
            }

            .chat-header {
                padding: 1rem 1rem 1rem 4rem;
            }

            .generated-images {
                grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            }

            .login-container {
                margin: 1rem;
                padding: 2rem;
            }

            .login-title {
                font-size: 2rem;
            }
        }

        @media (max-width: 480px) {
            .landing-title {
                font-size: 2rem;
            }

            .start-button {
                padding: 0.75rem 2rem;
                font-size: 1rem;
            }

            .sidebar {
                width: 300px;
            }

            .message-content {
                padding: 0.75rem 1rem;
            }

            .input-container {
                padding: 0.75rem;
            }

            .sidebar-header {
                padding: 1rem;
            }

            .logo {
                font-size: 1.3rem;
            }

            .new-chat-btn {
                padding: 0.35rem 0.7rem;
                font-size: 0.8rem;
            }

            .generated-images {
                grid-template-columns: 1fr;
            }

            .login-container {
                padding: 1.5rem;
            }

            .login-title {
                font-size: 1.8rem;
            }
        }
    </style>
</head>
<body>
    <!-- Login Page -->
    <div class="login-page" id="loginPage">
        <div class="login-container">
            <h1 class="login-title">GrokChatBot</h1>
            <p class="login-subtitle">Enter your password to access your personal AI assistant</p>
            <form class="login-form" id="loginForm">
                <input 
                    type="password" 
                    class="password-input" 
                    id="passwordInput" 
                    placeholder="Enter password" 
                    autocomplete="current-password"
                    required
                >
                <button type="submit" class="login-button" id="loginButton">
                    Access GrokChatBot
                </button>
                <div class="login-error" id="loginError"></div>
            </form>
        </div>
    </div>

    <!-- Landing Page -->
    <div class="landing-page" id="landingPage">
        <a href="?logout=1" class="logout-link">Logout</a>
        <h1 class="landing-title">Hello!</h1>
        <p class="landing-subtitle">
            Welcome to your personal AI assistant! I can help you with questions, tasks, 
            coding projects, and much more. Chat with me or generate amazing images! 🎨
        </p>
        <button class="start-button" onclick="showChatInterface()">talk to GrokChatBot</button>
    </div>

    <!-- Chat Interface -->
    <div class="chat-interface" id="chatInterface">
        <!-- Mobile Toggle Button -->
        <button class="mobile-toggle-outside" id="mobileToggleOutside" onclick="toggleSidebar()">☰</button>
        
        <!-- Mobile Overlay -->
        <div class="mobile-overlay" id="mobileOverlay" onclick="closeSidebar()"></div>
        
        <!-- Sidebar -->
        <div class="sidebar" id="sidebar">
            <div class="sidebar-header">
                <div class="sidebar-left">
                    <button class="mobile-toggle-inside" id="mobileToggleInside" onclick="closeSidebar()">✕</button>
                    <div class="logo">GrokChatBot</div>
                </div>
                <button class="new-chat-btn" onclick="createNewChat()">+ New Chat</button>
            </div>
            <div class="chat-list" id="chatList">
                <!-- Chat items will be populated here -->
            </div>
        </div>

        <!-- Main Chat Area -->
        <div class="chat-area">
            <div class="chat-header">
                <div class="chat-title-main" id="currentChatTitle">Select a chat to start</div>
            </div>
            
            <div class="chat-messages" id="chatMessages">
                <div class="welcome-message">
                    <h3>Welcome to GrokChatBot! 🤖</h3>
                    <p>I'm here to help you with anything you need - homework, coding, life advice, or just having a great conversation. You can share JPEG and PNG images with me, or I can generate amazing images from your descriptions! What would you like to do today?</p>
                </div>
            </div>
            
            <div class="input-area">
                <div class="input-container">
                    <div class="input-wrapper">
                        <!-- Mode Toggle -->
                        <div class="mode-toggle">
                            <span class="mode-label active" id="chatModeLabel">💬 Chat</span>
                            <label class="mode-switch">
                                <input type="checkbox" id="modeToggle">
                                <span class="mode-slider"></span>
                            </label>
                            <span class="mode-label" id="imageModeLabel">🎨 Generate</span>
                        </div>

                        <textarea 
                            class="message-input" 
                            id="messageInput" 
                            placeholder="Ask me anything! I can see images and generate them too... 📸🎨" 
                            rows="1"
                        ></textarea>

                        <!-- Chat Mode Controls -->
                        <div class="file-upload-area" id="chatControls">
                            <input type="file" class="file-input" id="fileInput" accept="image/jpeg,image/png">
                            <button class="file-upload-btn" onclick="document.getElementById('fileInput').click()">
                                📎 Upload Image
                            </button>
                            <div class="file-preview" id="filePreview"></div>
                        </div>

                        <!-- Image Generation Controls -->
                        <div class="image-controls" id="imageControls">
                            <div class="num-images-control">
                                <span>Images:</span>
                                <select class="num-images-select" id="numImages">
                                    <option value="1">1</option>
                                    <option value="2">2</option>
                                    <option value="3">3</option>
                                    <option value="4">4</option>
                                </select>
                            </div>
                        </div>
                    </div>
                    <button class="send-button" id="sendButton">Send</button>
                </div>
            </div>
        </div>
    </div>

    <script>
        let currentChatId = null;
        let isLoading = false;
        let isMobile = window.innerWidth <= 768;
        let isImageMode = false;
        let isAuthenticated = <?php echo $is_authenticated ? 'true' : 'false'; ?>;

        // Login functionality
        if (!isAuthenticated) {
            document.getElementById('loginForm').addEventListener('submit', async function(e) {
                e.preventDefault();
                
                const passwordInput = document.getElementById('passwordInput');
                const loginButton = document.getElementById('loginButton');
                const loginError = document.getElementById('loginError');
                
                const password = passwordInput.value;
                
                if (!password) {
                    showLoginError('Please enter a password');
                    return;
                }
                
                loginButton.disabled = true;
                loginButton.textContent = 'Checking...';
                
                try {
                    const formData = new FormData();
                    formData.append('action', 'login');
                    formData.append('password', password);
                    
                    const response = await fetch('', {
                        method: 'POST',
                        body: formData
                    });
                    
                    const result = await response.json();
                    
                    if (result.success) {
                        // Login successful - reload page to show authenticated content
                        window.location.reload();
                    } else {
                        showLoginError(result.error || 'Invalid password');
                        passwordInput.value = '';
                        passwordInput.focus();
                    }
                } catch (error) {
                    showLoginError('Connection error. Please try again.');
                    console.error('Login error:', error);
                }
                
                loginButton.disabled = false;
                loginButton.textContent = 'Access GrokChatBot';
            });

            // Focus password input on load
            document.addEventListener('DOMContentLoaded', function() {
                const passwordInput = document.getElementById('passwordInput');
                if (passwordInput) {
                    passwordInput.focus();
                }
            });
        }

        function showLoginError(message) {
            const loginError = document.getElementById('loginError');
            loginError.textContent = message;
            loginError.classList.add('show');
            
            setTimeout(() => {
                loginError.classList.remove('show');
            }, 5000);
        }

        // Mobile sidebar functions
        function toggleSidebar() {
            const sidebar = document.getElementById('sidebar');
            const overlay = document.getElementById('mobileOverlay');
            
            sidebar.classList.toggle('open');
            overlay.classList.toggle('show');
        }

        function closeSidebar() {
            const sidebar = document.getElementById('sidebar');
            const overlay = document.getElementById('mobileOverlay');
            
            sidebar.classList.remove('open');
            overlay.classList.remove('show');
        }

        // Handle window resize
        window.addEventListener('resize', function() {
            const wasMobile = isMobile;
            isMobile = window.innerWidth <= 768;
            
            if (wasMobile && !isMobile) {
                closeSidebar();
            }
        });

        // Mode toggle functionality - Initialize after DOM is loaded
        function initializeModeToggle() {
            const modeToggle = document.getElementById('modeToggle');
            const chatModeLabel = document.getElementById('chatModeLabel');
            const imageModeLabel = document.getElementById('imageModeLabel');
            const chatControls = document.getElementById('chatControls');
            const imageControls = document.getElementById('imageControls');
            const messageInput = document.getElementById('messageInput');
            const sendButton = document.getElementById('sendButton');

            if (!modeToggle) return; // Not ready yet

            modeToggle.addEventListener('change', function() {
                isImageMode = this.checked;
                
                if (isImageMode) {
                    chatModeLabel.classList.remove('active');
                    imageModeLabel.classList.add('active');
                    chatControls.style.display = 'none';
                    imageControls.classList.add('active');
                    messageInput.placeholder = "Describe the image you want me to create... 🎨";
                    sendButton.classList.add('image-mode');
                    sendButton.textContent = 'Generate';
                } else {
                    chatModeLabel.classList.add('active');
                    imageModeLabel.classList.remove('active');
                    chatControls.style.display = 'flex';
                    imageControls.classList.remove('active');
                    messageInput.placeholder = "Ask me anything! I can see images and generate them too... 📸🎨";
                    sendButton.classList.remove('image-mode');
                    sendButton.textContent = 'Send';
                }
                
                updateSendButton();
            });
        }

        // Only initialize if authenticated
        if (isAuthenticated) {
            // Auto-resize textarea
            const messageInput = document.getElementById('messageInput');
            if (messageInput) {
                messageInput.addEventListener('input', function() {
                    this.style.height = 'auto';
                    this.style.height = Math.min(this.scrollHeight, 120) + 'px';
                    updateSendButton();
                });

                // Handle Enter key
                messageInput.addEventListener('keypress', function(e) {
                    if (e.key === 'Enter' && !e.shiftKey) {
                        e.preventDefault();
                        if (isImageMode) {
                            generateImage();
                        } else {
                            sendMessage();
                        }
                    }
                });
            }

            // File input handler with xAI format validation
            const fileInput = document.getElementById('fileInput');
            if (fileInput) {
                fileInput.addEventListener('change', function(e) {
                    const file = e.target.files[0];
                    const preview = document.getElementById('filePreview');
                    
                    if (file) {
                        // xAI only supports JPG/JPEG and PNG
                        const allowedTypes = ['image/jpeg', 'image/png'];
                        if (!allowedTypes.includes(file.type)) {
                            alert('xAI only supports JPEG and PNG images. Please select a different file.');
                            this.value = '';
                            preview.textContent = '';
                            preview.className = 'file-preview';
                            return;
                        }
                        
                        // Check file size (5MB limit to match server)
                        if (file.size > 5 * 1024 * 1024) {
                            alert('Image must be smaller than 5MB. Large images will be automatically resized.');
                            this.value = '';
                            preview.textContent = '';
                            preview.className = 'file-preview';
                            return;
                        }
                        
                        preview.textContent = `📸 ${file.name} (${(file.size / 1024 / 1024).toFixed(1)}MB)`;
                        preview.className = 'file-preview has-file';
                    } else {
                        preview.textContent = '';
                        preview.className = 'file-preview';
                    }
                    
                    updateSendButton();
                });
            }

            // Send button click handler
            const sendButton = document.getElementById('sendButton');
            if (sendButton) {
                sendButton.addEventListener('click', function() {
                    if (isImageMode) {
                        generateImage();
                    } else {
                        sendMessage();
                    }
                });
            }
        }

        function showChatInterface() {
            document.getElementById('landingPage').style.display = 'none';
            document.getElementById('chatInterface').style.display = 'flex';
            
            loadChats().then(() => {
                if (!currentChatId) {
                    createNewChat();
                }
            });
            
            setTimeout(() => {
                document.getElementById('messageInput').focus();
            }, 100);
        }

        async function createNewChat() {
            if (isMobile) {
                closeSidebar();
            }
            
            try {
                const formData = new FormData();
                formData.append('action', 'new_chat');
                
                const response = await fetch('', {
                    method: 'POST',
                    body: formData
                });
                
                const result = await response.json();
                if (result.success) {
                    await loadChats();
                    selectChat(result.chat_id);
                }
            } catch (error) {
                console.error('Error creating new chat:', error);
            }
        }

        async function loadChats() {
            try {
                const formData = new FormData();
                formData.append('action', 'get_chats');
                
                const response = await fetch('', {
                    method: 'POST',
                    body: formData
                });
                
                const result = await response.json();
                if (result.success) {
                    renderChatList(result.chats);
                    return result.chats;
                }
                return [];
            } catch (error) {
                console.error('Error loading chats:', error);
                return [];
            }
        }

        function renderChatList(chats) {
            const chatList = document.getElementById('chatList');
            chatList.innerHTML = '';
            
            chats.forEach(chat => {
                const chatItem = document.createElement('div');
                chatItem.className = 'chat-item';
                if (chat.id === currentChatId) {
                    chatItem.classList.add('active');
                }
                
                chatItem.innerHTML = `
                    <div class="chat-title" onclick="selectChat(${chat.id}, '${chat.title.replace(/'/g, '\\\'')}')">${chat.title}</div>
                    <div class="chat-actions">
                        <button class="chat-action-btn" onclick="renameChat(${chat.id})" title="Rename">✏️</button>
                        <button class="chat-action-btn" onclick="deleteChat(${chat.id})" title="Delete">🗑️</button>
                    </div>
                `;
                
                chatList.appendChild(chatItem);
            });
        }

        async function selectChat(chatId, chatTitle = 'Chat') {
            currentChatId = chatId;
            
            if (isMobile) {
                closeSidebar();
            }
            
            document.querySelectorAll('.chat-item').forEach(item => {
                item.classList.remove('active');
            });
            
            const chatItems = document.querySelectorAll('.chat-item');
            chatItems.forEach(item => {
                const titleElement = item.querySelector('.chat-title');
                if (titleElement && titleElement.onclick.toString().includes(chatId)) {
                    item.classList.add('active');
                }
            });
            
            await loadChatHistory(chatId);
            document.getElementById('currentChatTitle').textContent = chatTitle;
        }

        async function loadChatHistory(chatId) {
            try {
                const formData = new FormData();
                formData.append('action', 'get_chat_history');
                formData.append('chat_id', chatId);
                
                const response = await fetch('', {
                    method: 'POST',
                    body: formData
                });
                
                const result = await response.json();
                if (result.success) {
                    renderChatHistory(result.history);
                }
            } catch (error) {
                console.error('Error loading chat history:', error);
            }
        }

        function renderChatHistory(history) {
            const chatMessages = document.getElementById('chatMessages');
            chatMessages.innerHTML = '';
            
            if (history.length === 0) {
                chatMessages.innerHTML = `
                    <div class="welcome-message">
                        <h3>New Chat Started! 🚀</h3>
                        <p>What would you like to talk about? You can chat with me, share images, or generate amazing artwork!</p>
                    </div>
                `;
                return;
            }
            
            history.forEach(message => {
                const messageDiv = document.createElement('div');
                messageDiv.className = `message ${message.role}`;
                
                const avatar = message.role === 'user' ? 'U' : '🤖';
                
                let imageHtml = '';
                if (message.image_path) {
                    imageHtml = `<img src="${message.image_path}" alt="Uploaded image" class="message-image" onclick="openImageModal('${message.image_path}')" loading="lazy" onload="this.classList.add('loaded')">`;
                }
                
                // Handle generated images
                let generatedImagesHtml = '';
                if (message.generated_image_path) {
                    const imagePaths = message.generated_image_path.split(',');
                    if (imagePaths.length > 1) {
                        generatedImagesHtml = '<div class="generated-images">';
                        imagePaths.forEach(path => {
                            generatedImagesHtml += `<img src="${path.trim()}" alt="Generated image" class="generated-image" onclick="openImageModal('${path.trim()}')" loading="lazy" onload="this.classList.add('loaded')">`;
                        });
                        generatedImagesHtml += '</div>';
                    } else {
                        generatedImagesHtml = `<img src="${imagePaths[0]}" alt="Generated image" class="message-image" onclick="openImageModal('${imagePaths[0]}')" loading="lazy" onload="this.classList.add('loaded')">`;
                    }
                }
                
                messageDiv.innerHTML = `
                    <div class="message-avatar">${avatar}</div>
                    <div class="message-content">
                        <div class="message-text">${message.content}</div>
                        ${imageHtml}
                        ${generatedImagesHtml}
                    </div>
                `;
                
                chatMessages.appendChild(messageDiv);
            });
            
            chatMessages.scrollTop = chatMessages.scrollHeight;
        }

        // FIXED: Modified addMessageToChat to return text element when streaming
        function addMessageToChat(role, content, imagePath = null, isStreaming = false, generatedImages = null) {
            const chatMessages = document.getElementById('chatMessages');
            const messageDiv = document.createElement('div');
            messageDiv.className = `message ${role}`;                const avatar = role === 'user' ? 'U' : '🤖';
            
            let imageHtml = '';
            if (imagePath) {
                // Handle both server paths (uploaded images) and blob URLs (immediate preview)
                const imageUrl = imagePath.startsWith('blob:') ? imagePath : imagePath;
                imageHtml = `<img src="${imageUrl}" alt="Uploaded image" class="message-image" onclick="openImageModal('${imageUrl}')" loading="lazy" onload="this.classList.add('loaded')">`;
            }
            
            // Handle generated images
            let generatedImagesHtml = '';
            if (generatedImages && generatedImages.length > 0) {
                if (generatedImages.length > 1) {
                    generatedImagesHtml = '<div class="generated-images">';
                    generatedImages.forEach(imagePath => {
                        generatedImagesHtml += `<img src="${imagePath}" alt="Generated image" class="generated-image" onclick="openImageModal('${imagePath}')" loading="lazy" onload="this.classList.add('loaded')">`;
                    });
                    generatedImagesHtml += '</div>';
                } else {
                    generatedImagesHtml = `<img src="${generatedImages[0]}" alt="Generated image" class="message-image" onclick="openImageModal('${generatedImages[0]}')" loading="lazy" onload="this.classList.add('loaded')">`;
                }
            }
            
            // Create the text element separately so we can return it
            const textDiv = document.createElement('div');
            textDiv.className = 'message-text';
            textDiv.textContent = content;
            
            messageDiv.innerHTML = `
                <div class="message-avatar">${avatar}</div>
                <div class="message-content">
                    ${imageHtml}
                    ${generatedImagesHtml}
                </div>
            `;
            
            // Insert the text div into the message content
            const messageContent = messageDiv.querySelector('.message-content');
            messageContent.insertBefore(textDiv, messageContent.firstChild);
            
            chatMessages.appendChild(messageDiv);
            chatMessages.scrollTop = chatMessages.scrollHeight;
            
            // Return the text element if streaming, otherwise return the message div
            return isStreaming ? textDiv : messageDiv;
        }

        function openImageModal(imagePath) {
            window.open(imagePath, '_blank');
        }

        function typeWriterEffect(element, text, speed = 30) {
            return new Promise((resolve) => {
                let i = 0;
                element.innerHTML = '';
                element.classList.add('streaming-cursor');
                
                function typeChar() {
                    if (i < text.length) {
                        if (text.charAt(i) === '\n') {
                            element.innerHTML += '<br>';
                        } else {
                            element.innerHTML += text.charAt(i);
                        }
                        i++;
                        setTimeout(typeChar, speed);
                        
                        const chatMessages = document.getElementById('chatMessages');
                        chatMessages.scrollTop = chatMessages.scrollHeight;
                    } else {
                        element.classList.remove('streaming-cursor');
                        resolve();
                    }
                }
                
                typeChar();
            });
        }

        function showThinking(isProcessingImage = false, isGeneratingImage = false) {
            const chatMessages = document.getElementById('chatMessages');
            const thinkingDiv = document.createElement('div');
            thinkingDiv.className = 'message assistant';
            thinkingDiv.id = 'thinking-message';
            
            let thinkingText = 'GrokChatBot is thinking';
            if (isProcessingImage) {
                thinkingText = 'GrokChatBot is looking at your image';
            } else if (isGeneratingImage) {
                thinkingText = 'GrokChatBot is creating your image';
            }
            
            thinkingDiv.innerHTML = `
                <div class="message-avatar">🤖</div>
                <div class="message-content">
                    <div class="thinking">
                        ${thinkingText}
                        <div class="thinking-dots">
                            <div class="thinking-dot"></div>
                            <div class="thinking-dot"></div>
                            <div class="thinking-dot"></div>
                        </div>
                    </div>
                </div>
            `;
            
            chatMessages.appendChild(thinkingDiv);
            chatMessages.scrollTop = chatMessages.scrollHeight;
        }

        function removeThinking() {
            const thinkingMessage = document.getElementById('thinking-message');
            if (thinkingMessage) {
                thinkingMessage.remove();
            }
        }

        async function sendMessage() {
            if (!currentChatId) {
                alert('Please select or create a chat first!');
                return;
            }
            
            if (isLoading) {
                return;
            }
            
            const messageInput = document.getElementById('messageInput');
            const fileInput = document.getElementById('fileInput');
            const sendButton = document.getElementById('sendButton');
            const filePreview = document.getElementById('filePreview');
            
            const message = messageInput.value.trim();
            const hasImage = fileInput.files[0];
            
            if (!message && !hasImage) {
                messageInput.focus();
                return;
            }
            
            // Prevent multiple sends
            isLoading = true;
            sendButton.disabled = true;
            sendButton.textContent = hasImage ? 'Processing...' : 'Sending...';
            
            // Clear input immediately
            const userMessage = message || "What do you see in this image?";
            messageInput.value = '';
            messageInput.style.height = 'auto';
            
            // Create image preview URL for immediate display
            let imagePreviewUrl = null;
            if (hasImage) {
                imagePreviewUrl = URL.createObjectURL(hasImage);
            }
            
            // Add user message to chat immediately WITH image if present
            addMessageToChat('user', userMessage, imagePreviewUrl);
            
            // Show appropriate thinking message
            showThinking(hasImage);
            
            try {
                const formData = new FormData();
                formData.append('action', 'send_message');
                formData.append('chat_id', currentChatId);
                formData.append('message', userMessage);
                
                if (hasImage) {
                    formData.append('image', fileInput.files[0]);
                }
                
                const response = await fetch('', {
                    method: 'POST',
                    body: formData
                });
                
                const result = await response.json();
                
                removeThinking();
                
                if (result.success) {
                    // FIXED: Now correctly gets the text element for streaming
                    const streamingTextElement = addMessageToChat('assistant', '', null, true);
                    
                    await typeWriterEffect(streamingTextElement, result.response, 25);
                    
                    // Clean up the preview URL to free memory
                    if (imagePreviewUrl) {
                        URL.revokeObjectURL(imagePreviewUrl);
                    }
                    
                    // Clear file input and preview
                    fileInput.value = '';
                    filePreview.textContent = '';
                    filePreview.className = 'file-preview';
                    
                    loadChats();
                } else {
                    let errorMessage = 'Sorry, I encountered an error. Please try again! 😅';
                    
                    if (result.error) {
                        if (result.error.includes('too large')) {
                            errorMessage = 'The image you uploaded is too large. Please try a smaller image or I can help you resize it! 📸';
                        } else if (result.error.includes('vision model')) {
                            errorMessage = 'The image processing feature is temporarily unavailable. You can still chat with me using text! 💬';
                        } else if (result.error.includes('format')) {
                            errorMessage = 'There was an issue with the image format. Please try uploading a JPEG or PNG image! 🖼️';
                        } else {
                            errorMessage += '\n\nError details: ' + result.error;
                        }
                    }
                    
                    addMessageToChat('assistant', errorMessage);
                    console.error('API Error:', result.error);
                }
            } catch (error) {
                removeThinking();
                addMessageToChat('assistant', 'Sorry, I encountered a network error. Please check your connection and try again! 😅');
                console.error('Error sending message:', error);
            }
            
            // Re-enable sending
            isLoading = false;
            sendButton.disabled = false;
            sendButton.textContent = 'Send';
            
            messageInput.focus();
        }

        // NEW: Image generation function
        async function generateImage() {
            if (!currentChatId) {
                alert('Please select or create a chat first!');
                return;
            }
            
            if (isLoading) {
                return;
            }
            
            const messageInput = document.getElementById('messageInput');
            const numImagesSelect = document.getElementById('numImages');
            const sendButton = document.getElementById('sendButton');
            
            const prompt = messageInput.value.trim();
            const numImages = parseInt(numImagesSelect.value);
            
            if (!prompt) {
                messageInput.focus();
                return;
            }
            
            // Prevent multiple generates
            isLoading = true;
            sendButton.disabled = true;
            sendButton.textContent = 'Generating...';
            
            // Clear input immediately
            messageInput.value = '';
            messageInput.style.height = 'auto';
            
            // Add user message to chat immediately
            addMessageToChat('user', prompt);
            
            // Show thinking message for image generation
            showThinking(false, true);
            
            try {
                const formData = new FormData();
                formData.append('action', 'generate_image');
                formData.append('chat_id', currentChatId);
                formData.append('prompt', prompt);
                formData.append('num_images', numImages);
                
                const response = await fetch('', {
                    method: 'POST',
                    body: formData
                });
                
                const result = await response.json();
                
                removeThinking();
                
                if (result.success) {
                    // Add AI response with generated images
                    addMessageToChat('assistant', result.response, null, false, result.images);
                    
                    loadChats();
                } else {
                    let errorMessage = 'Sorry, I encountered an error while generating your image. Please try again! 😅';
                    
                    if (result.error) {
                        errorMessage = result.error;
                    }
                    
                    addMessageToChat('assistant', errorMessage);
                    console.error('Image Generation Error:', result.error);
                }
            } catch (error) {
                removeThinking();
                addMessageToChat('assistant', 'Sorry, I encountered a network error while generating your image. Please check your connection and try again! 😅');
                console.error('Error generating image:', error);
            }
            
            // Re-enable generating
            isLoading = false;
            sendButton.disabled = false;
            sendButton.textContent = 'Generate';
            
            messageInput.focus();
        }

        async function renameChat(chatId) {
            const newTitle = prompt('Enter new chat title:');
            if (newTitle && newTitle.trim()) {
                try {
                    const formData = new FormData();
                    formData.append('action', 'rename_chat');
                    formData.append('chat_id', chatId);
                    formData.append('title', newTitle.trim());
                    
                    const response = await fetch('', {
                        method: 'POST',
                        body: formData
                    });
                    
                    const result = await response.json();
                    if (result.success) {
                        loadChats();
                        if (chatId === currentChatId) {
                            document.getElementById('currentChatTitle').textContent = newTitle.trim();
                        }
                    }
                } catch (error) {
                    console.error('Error renaming chat:', error);
                }
            }
        }

        async function deleteChat(chatId) {
            if (confirm('Are you sure you want to delete this chat?')) {
                try {
                    const formData = new FormData();
                    formData.append('action', 'delete_chat');
                    formData.append('chat_id', chatId);
                    
                    const response = await fetch('', {
                        method: 'POST',
                        body: formData
                    });
                    
                    const result = await response.json();
                    if (result.success) {
                        if (chatId === currentChatId) {
                            currentChatId = null;
                            document.getElementById('chatMessages').innerHTML = `
                                <div class="welcome-message">
                                    <h3>Welcome to GrokChatBot! 🤖</h3>
                                    <p>I'm here to help you with anything you need. What would you like to talk about today?</p>
                                </div>
                            `;
                            document.getElementById('currentChatTitle').textContent = 'Select a chat to start';
                        }
                        loadChats();
                    }
                } catch (error) {
                    console.error('Error deleting chat:', error);
                }
            }
        }

        function updateSendButton() {
            const messageInputElement = document.getElementById('messageInput');
            const sendButtonElement = document.getElementById('sendButton');
            const fileInputElement = document.getElementById('fileInput');
            
            if (!messageInputElement || !sendButtonElement) {
                return; // Elements not ready yet
            }
            
            const hasMessage = messageInputElement.value.trim().length > 0;
            const hasImage = !isImageMode && fileInputElement && fileInputElement.files[0];
            const canSend = (hasMessage || hasImage) && currentChatId && !isLoading;
            sendButtonElement.disabled = !canSend;
            
            if (!currentChatId) {
                sendButtonElement.textContent = 'Select Chat';
            } else if (isLoading) {
                sendButtonElement.textContent = isImageMode ? 'Generating...' : 'Sending...';
            } else {
                sendButtonElement.textContent = isImageMode ? 'Generate' : 'Send';
            }
        }

        // Initialize
        document.addEventListener('DOMContentLoaded', function() {
            console.log('GrokChatBot loaded with image support and generation! 🎉');
            
            // Initialize mode toggle functionality
            initializeModeToggle();
            
            // Get references to elements after DOM is loaded
            const messageInputElement = document.getElementById('messageInput');
            const sendButtonElement = document.getElementById('sendButton');
            
            if (messageInputElement) {
                messageInputElement.addEventListener('input', updateSendButton);
                
                // Auto-resize textarea
                messageInputElement.addEventListener('input', function() {
                    this.style.height = 'auto';
                    this.style.height = Math.min(this.scrollHeight, 120) + 'px';
                    updateSendButton();
                });

                // Handle Enter key
                messageInputElement.addEventListener('keypress', function(e) {
                    if (e.key === 'Enter' && !e.shiftKey) {
                        e.preventDefault();
                        if (isImageMode) {
                            generateImage();
                        } else {
                            sendMessage();
                        }
                    }
                });
            }
            
            const fileInputElement = document.getElementById('fileInput');
            if (fileInputElement) {
                fileInputElement.addEventListener('change', updateSendButton);
                
                // File input handler with xAI format validation
                fileInputElement.addEventListener('change', function(e) {
                    const file = e.target.files[0];
                    const preview = document.getElementById('filePreview');
                    
                    if (file) {
                        // xAI only supports JPG/JPEG and PNG
                        const allowedTypes = ['image/jpeg', 'image/png'];
                        if (!allowedTypes.includes(file.type)) {
                            alert('xAI only supports JPEG and PNG images. Please select a different file.');
                            this.value = '';
                            preview.textContent = '';
                            preview.className = 'file-preview';
                            return;
                        }
                        
                        // Check file size (5MB limit to match server)
                        if (file.size > 5 * 1024 * 1024) {
                            alert('Image must be smaller than 5MB. Large images will be automatically resized.');
                            this.value = '';
                            preview.textContent = '';
                            preview.className = 'file-preview';
                            return;
                        }
                        
                        preview.textContent = `📸 ${file.name} (${(file.size / 1024 / 1024).toFixed(1)}MB)`;
                        preview.className = 'file-preview has-file';
                    } else {
                        preview.textContent = '';
                        preview.className = 'file-preview';
                    }
                    
                    updateSendButton();
                });
            }
            
            const numImagesElement = document.getElementById('numImages');
            if (numImagesElement) {
                numImagesElement.addEventListener('change', updateSendButton);
            }
            
            // Send button click handler
            if (sendButtonElement) {
                sendButtonElement.addEventListener('click', function() {
                    if (isImageMode) {
                        generateImage();
                    } else {
                        sendMessage();
                    }
                });
            }
            
            updateSendButton();
            
            // Focus on message input only if we're on the chat interface
            if (messageInputElement && document.getElementById('chatInterface').style.display !== 'none') {
                messageInputElement.focus();
            }
        });

        // Make sure showChatInterface is globally available
        window.showChatInterface = showChatInterface;
    </script>
</body>
</html>