<?php
session_start();

// Database initialization
function initDatabase() {
    $db = new SQLite3('sprite_generator.db');
    
    // Create images table
    $db->exec('CREATE TABLE IF NOT EXISTS images (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        prompt TEXT NOT NULL,
        image_data TEXT NOT NULL,
        filename VARCHAR(255),
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        model VARCHAR(50) DEFAULT "gpt-image-1",
        size VARCHAR(20) DEFAULT "1024x1024",
        quality VARCHAR(20) DEFAULT "medium"
    )');
    
    // Create sprite sheets table
    $db->exec('CREATE TABLE IF NOT EXISTS sprite_sheets (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        name VARCHAR(255) NOT NULL,
        image_ids TEXT NOT NULL,
        grid_size VARCHAR(10),
        sprite_data TEXT NOT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )');
    
    // Create game characters table
    $db->exec('CREATE TABLE IF NOT EXISTS game_characters (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        name VARCHAR(255) NOT NULL,
        sprite_sheet_id INTEGER,
        pose_mappings TEXT NOT NULL,
        world_background TEXT,
        world_prompt TEXT,
        sprite_data TEXT,
        grid_size VARCHAR(10),
        game_html TEXT,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (sprite_sheet_id) REFERENCES sprite_sheets(id)
    )');
    
    return $db;
}

// Handle AJAX requests
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    
    $action = $_POST['action'] ?? '';
    
    switch ($action) {
        case 'save_api_key':
            $_SESSION['openai_api_key'] = $_POST['api_key'] ?? '';
            echo json_encode(['success' => true]);
            exit;
            
        case 'generate_image':
            if (empty($_SESSION['openai_api_key'])) {
                echo json_encode(['error' => 'API key not set']);
                exit;
            }
            
            $prompt = $_POST['prompt'] ?? '';
            $size = $_POST['size'] ?? '1024x1024';
            $quality = $_POST['quality'] ?? 'medium';
            
            if (empty($prompt)) {
                echo json_encode(['error' => 'Prompt is required']);
                exit;
            }
            
            // Call OpenAI API
            $result = generateImage($prompt, $size, $quality, $_SESSION['openai_api_key']);
            
            if ($result['success']) {
                // Save to database
                $db = initDatabase();
                $stmt = $db->prepare('INSERT INTO images (prompt, image_data, size, quality) VALUES (?, ?, ?, ?)');
                $stmt->bindValue(1, $prompt);
                $stmt->bindValue(2, $result['image_data']);
                $stmt->bindValue(3, $size);
                $stmt->bindValue(4, $quality);
                $stmt->execute();
                
                $result['id'] = $db->lastInsertRowID();
                $db->close();
            }
            
            echo json_encode($result);
            exit;
            
        case 'get_images':
            $db = initDatabase();
            $results = $db->query('SELECT id, prompt, image_data, size, quality, created_at FROM images ORDER BY created_at DESC');
            $images = [];
            while ($row = $results->fetchArray(SQLITE3_ASSOC)) {
                $images[] = $row;
            }
            $db->close();
            echo json_encode($images);
            exit;
            
        case 'delete_image':
            $id = $_POST['id'] ?? 0;
            $db = initDatabase();
            $stmt = $db->prepare('DELETE FROM images WHERE id = ?');
            $stmt->bindValue(1, $id);
            $result = $stmt->execute();
            $db->close();
            echo json_encode(['success' => $result]);
            exit;
            
        case 'create_sprite_sheet':
            if (empty($_SESSION['openai_api_key'])) {
                echo json_encode(['error' => 'API key not set']);
                exit;
            }
            
            $imageId = $_POST['image_id'] ?? 0;
            $gridSize = $_POST['grid_size'] ?? '2x2';
            $name = $_POST['name'] ?? 'Sprite Sheet';
            
            if (empty($imageId)) {
                echo json_encode(['error' => 'No image selected']);
                exit;
            }
            
            $result = generateSpriteSheet($imageId, $gridSize, $name, $_SESSION['openai_api_key']);
            echo json_encode($result);
            exit;
            
        case 'get_sprite_sheets':
            $db = initDatabase();
            $results = $db->query('SELECT id, name, grid_size, sprite_data, created_at FROM sprite_sheets ORDER BY created_at DESC');
            $sprites = [];
            while ($row = $results->fetchArray(SQLITE3_ASSOC)) {
                $sprites[] = $row;
            }
            $db->close();
            echo json_encode($sprites);
            exit;
            
        case 'create_game_character':
            if (empty($_SESSION['openai_api_key'])) {
                echo json_encode(['error' => 'API key not set']);
                exit;
            }
            
            $spriteSheetId = $_POST['sprite_sheet_id'] ?? 0;
            $characterName = $_POST['character_name'] ?? 'My Character';
            $poseMappings = $_POST['pose_mappings'] ?? '{}';
            $worldPrompt = $_POST['world_prompt'] ?? '';
            
            if (empty($spriteSheetId)) {
                echo json_encode(['error' => 'No sprite sheet selected']);
                exit;
            }
            
            $result = createGameCharacter($spriteSheetId, $characterName, $poseMappings, $worldPrompt, $_SESSION['openai_api_key']);
            echo json_encode($result);
            exit;
            
        case 'export_game':
            $characterId = $_POST['character_id'] ?? 0;
            if (empty($characterId)) {
                echo json_encode(['error' => 'No character selected']);
                exit;
            }
            
            $result = exportGame($characterId);
            echo json_encode($result);
            exit;
            
        case 'get_game_worlds':
            $db = initDatabase();
            $results = $db->query('SELECT id, name, world_background, world_prompt, created_at FROM game_characters ORDER BY created_at DESC');
            $worlds = [];
            while ($row = $results->fetchArray(SQLITE3_ASSOC)) {
                $worlds[] = $row;
            }
            $db->close();
            echo json_encode($worlds);
            exit;
            
        case 'get_games':
            $db = initDatabase();
            $results = $db->query('SELECT id, name, world_background, world_prompt, game_html, created_at FROM game_characters WHERE game_html IS NOT NULL AND game_html != "" ORDER BY created_at DESC');
            $games = [];
            while ($row = $results->fetchArray(SQLITE3_ASSOC)) {
                $games[] = $row;
            }
            $db->close();
            echo json_encode($games);
            exit;
            
        case 'play_game':
            $gameId = $_POST['game_id'] ?? 0;
            if (empty($gameId)) {
                echo json_encode(['error' => 'No game selected']);
                exit;
            }
            
            $db = initDatabase();
            $stmt = $db->prepare('SELECT game_html FROM game_characters WHERE id = ?');
            $stmt->bindValue(1, $gameId);
            $result = $stmt->execute();
            $game = $result->fetchArray(SQLITE3_ASSOC);
            $db->close();
            
            if (!$game) {
                echo json_encode(['error' => 'Game not found']);
                exit;
            }
            
            echo json_encode(['success' => true, 'game_html' => $game['game_html']]);
            exit;
            
        case 'delete_sprite_sheet':
            $id = $_POST['id'] ?? 0;
            $db = initDatabase();
            $stmt = $db->prepare('DELETE FROM sprite_sheets WHERE id = ?');
            $stmt->bindValue(1, $id);
            $result = $stmt->execute();
            $db->close();
            echo json_encode(['success' => $result]);
            exit;
            
        case 'delete_game_world':
            $id = $_POST['id'] ?? 0;
            $db = initDatabase();
            $stmt = $db->prepare('DELETE FROM game_characters WHERE id = ?');
            $stmt->bindValue(1, $id);
            $result = $stmt->execute();
            $db->close();
            echo json_encode(['success' => $result]);
            exit;
    }
}

function generateImage($prompt, $size, $quality, $apiKey) {
    // Increase execution time for AI generation
    set_time_limit(180); // 3 minutes
    
    $url = 'https://api.openai.com/v1/images/generations';
    
    $data = [
        'model' => 'gpt-image-1',
        'prompt' => $prompt,
        'n' => 1,
        'size' => $size,
        'quality' => $quality
    ];
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 120); // 2 minutes timeout
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 30); // 30 seconds connection timeout
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Authorization: Bearer ' . $apiKey,
        'Content-Type: application/json'
    ]);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($httpCode !== 200) {
        $error = json_decode($response, true);
        return ['success' => false, 'error' => $error['error']['message'] ?? 'API request failed'];
    }
    
    $result = json_decode($response, true);
    return [
        'success' => true,
        'image_data' => $result['data'][0]['b64_json']
    ];
}

function generateSpriteSheet($imageId, $gridSize, $name, $apiKey) {
    // Increase execution time for AI generation
    set_time_limit(300); // 5 minutes
    
    $db = initDatabase();
    
    // Get the source image
    $stmt = $db->prepare('SELECT image_data, prompt FROM images WHERE id = ?');
    $stmt->bindValue(1, $imageId);
    $result = $stmt->execute();
    $row = $result->fetchArray(SQLITE3_ASSOC);
    
    if (!$row) {
        $db->close();
        return ['success' => false, 'error' => 'Image not found'];
    }
    
    $originalPrompt = $row['prompt'];
    $imageData = $row['image_data'];
    
    // Parse grid size to determine number of sprites needed
    list($cols, $rows) = explode('x', $gridSize);
    $totalSprites = intval($cols) * intval($rows);
    
    // Create AI prompt for sprite sheet generation with specific poses
    $poseDescriptions = [
        'idle/standing facing forward',
        'walking/running to the right', 
        'walking/running to the left',
        'jumping up with arms raised',
        'crouching down',
        'waving hello',
        'attacking/punching forward',
        'looking backward over shoulder',
        'celebrating with arms up'
    ];
    
    $selectedPoses = array_slice($poseDescriptions, 0, $totalSprites);
    $poseList = implode(', ', $selectedPoses);
    
    $spritePrompt = "Create a pixel art sprite sheet showing the same character ({$originalPrompt}) in {$totalSprites} DISTINCTLY DIFFERENT poses. " .
                   "Arrange in a {$gridSize} grid. Each cell must show: {$poseList}. " .
                   "IMPORTANT: Make each pose clearly different - different body positions, arm positions, leg positions. " .
                   "Same character design, same art style, but completely different poses in each grid cell. " .
                   "Use consistent size for each sprite and transparent/white background. Perfect for game character animation.";
    
    // Generate sprite sheet using OpenAI API
    $url = 'https://api.openai.com/v1/images/generations';
    
    $data = [
        'model' => 'gpt-image-1',
        'prompt' => $spritePrompt,
        'n' => 1,
        'size' => '1024x1024',
        'quality' => 'high'
    ];
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 180); // 3 minutes for generation
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 30);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Authorization: Bearer ' . $apiKey,
        'Content-Type: application/json'
    ]);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($httpCode !== 200) {
        $db->close();
        $error = json_decode($response, true);
        return ['success' => false, 'error' => $error['error']['message'] ?? 'Failed to generate sprite sheet'];
    }
    
    $result = json_decode($response, true);
    $spriteData = $result['data'][0]['b64_json'];
    
    // Save to database
    $stmt = $db->prepare('INSERT INTO sprite_sheets (name, image_ids, grid_size, sprite_data) VALUES (?, ?, ?, ?)');
    $stmt->bindValue(1, $name);
    $stmt->bindValue(2, json_encode([$imageId]));
    $stmt->bindValue(3, $gridSize);
    $stmt->bindValue(4, $spriteData);
    $saveResult = $stmt->execute();
    
    if (!$saveResult) {
        $db->close();
        return ['success' => false, 'error' => 'Failed to save sprite sheet to database'];
    }
    
    $spriteId = $db->lastInsertRowID();
    $db->close();
    
    return [
        'success' => true,
        'id' => $spriteId,
        'sprite_data' => $spriteData
    ];
}

function createGameCharacter($spriteSheetId, $characterName, $poseMappings, $worldPrompt, $apiKey) {
    // Increase execution time for world generation
    set_time_limit(300);
    
    $db = initDatabase();
    
    // Get sprite sheet data
    $stmt = $db->prepare('SELECT sprite_data, grid_size FROM sprite_sheets WHERE id = ?');
    $stmt->bindValue(1, $spriteSheetId);
    $result = $stmt->execute();
    $spriteSheet = $result->fetchArray(SQLITE3_ASSOC);
    
    if (!$spriteSheet) {
        $db->close();
        return ['success' => false, 'error' => 'Sprite sheet not found'];
    }
    
    // Generate world background
    $basePrompt = "Create a beautiful 2D game world background with platforms, terrain, and sky. " .
                 "Pixel art style, side-scrolling platformer game environment. " .
                 "Include ground platforms the character can walk on. Vibrant colors, game-ready.";
    
    $finalWorldPrompt = !empty($worldPrompt) ? $worldPrompt . '. ' . $basePrompt : $basePrompt;
    
    $url = 'https://api.openai.com/v1/images/generations';
    
    $data = [
        'model' => 'gpt-image-1',
        'prompt' => $finalWorldPrompt,
        'n' => 1,
        'size' => '1536x1024',
        'quality' => 'high'
    ];
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 180);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 30);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Authorization: Bearer ' . $apiKey,
        'Content-Type: application/json'
    ]);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($httpCode !== 200) {
        $db->close();
        $error = json_decode($response, true);
        return ['success' => false, 'error' => $error['error']['message'] ?? 'Failed to generate world'];
    }
    
    $result = json_decode($response, true);
    $worldBackground = $result['data'][0]['b64_json'];
    
    // Create game HTML
    $gameCharacterData = [
        'name' => $characterName,
        'pose_mappings' => $poseMappings,
        'grid_size' => $spriteSheet['grid_size'],
        'sprite_data' => $spriteSheet['sprite_data'],
        'world_background' => $worldBackground
    ];
    $gameHtml = createGameHTML($gameCharacterData);
    
    // Save to database
    $stmt = $db->prepare('INSERT INTO game_characters (name, sprite_sheet_id, pose_mappings, world_background, world_prompt, sprite_data, grid_size, game_html) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
    $stmt->bindValue(1, $characterName);
    $stmt->bindValue(2, $spriteSheetId);
    $stmt->bindValue(3, $poseMappings);
    $stmt->bindValue(4, $worldBackground);
    $stmt->bindValue(5, $worldPrompt);
    $stmt->bindValue(6, $spriteSheet['sprite_data']);
    $stmt->bindValue(7, $spriteSheet['grid_size']);
    $stmt->bindValue(8, $gameHtml);
    $saveResult = $stmt->execute();
    
    if (!$saveResult) {
        $db->close();
        return ['success' => false, 'error' => 'Failed to save game character'];
    }
    
    $characterId = $db->lastInsertRowID();
    $db->close();
    
    return [
        'success' => true,
        'id' => $characterId,
        'world_background' => $worldBackground,
        'game_html' => $gameHtml
    ];
}

function exportGame($characterId) {
    $db = initDatabase();
    
    // Get character data
    $stmt = $db->prepare('SELECT gc.*, ss.sprite_data, ss.grid_size, ss.name as sprite_name FROM game_characters gc 
                         JOIN sprite_sheets ss ON gc.sprite_sheet_id = ss.id WHERE gc.id = ?');
    $stmt->bindValue(1, $characterId);
    $result = $stmt->execute();
    $character = $result->fetchArray(SQLITE3_ASSOC);
    
    if (!$character) {
        $db->close();
        return ['success' => false, 'error' => 'Character not found'];
    }
    
    $db->close();
    
    // Create temporary directory for game files
    $tempDir = sys_get_temp_dir() . '/game_export_' . uniqid();
    if (!mkdir($tempDir, 0755, true)) {
        return ['success' => false, 'error' => 'Failed to create temporary directory'];
    }
    
    $filesToCleanup = [];
    
    try {
        // Save sprite sheet
        $spriteFile = $tempDir . '/sprite_sheet.png';
        if (file_put_contents($spriteFile, base64_decode($character['sprite_data'])) === false) {
            throw new Exception('Failed to save sprite sheet');
        }
        $filesToCleanup[] = $spriteFile;
        
        // Save world background
        $backgroundFile = $tempDir . '/world_background.png';
        if (file_put_contents($backgroundFile, base64_decode($character['world_background'])) === false) {
            throw new Exception('Failed to save world background');
        }
        $filesToCleanup[] = $backgroundFile;
        
        // Create game HTML file
        $gameHtml = createGameHTML($character);
        $htmlFile = $tempDir . '/game.html';
        if (file_put_contents($htmlFile, $gameHtml) === false) {
            throw new Exception('Failed to save game HTML');
        }
        $filesToCleanup[] = $htmlFile;
        
        // Create zip file
        $zipFile = sys_get_temp_dir() . '/game_' . preg_replace('/[^a-zA-Z0-9_-]/', '_', $character['name']) . '_' . uniqid() . '.zip';
        $zip = new ZipArchive();
        
        $zipResult = $zip->open($zipFile, ZipArchive::CREATE);
        if ($zipResult !== TRUE) {
            throw new Exception('Failed to create zip file: ' . $zipResult);
        }
        
        // Add files to zip
        if (!$zip->addFile($spriteFile, 'sprite_sheet.png')) {
            $zip->close();
            throw new Exception('Failed to add sprite sheet to zip');
        }
        if (!$zip->addFile($backgroundFile, 'world_background.png')) {
            $zip->close();
            throw new Exception('Failed to add background to zip');
        }
        if (!$zip->addFile($htmlFile, 'game.html')) {
            $zip->close();
            throw new Exception('Failed to add HTML file to zip');
        }
        
        $zip->close();
        
        // Verify zip file was created
        if (!file_exists($zipFile)) {
            throw new Exception('Zip file was not created');
        }
        
        // Read zip file data
        $zipData = file_get_contents($zipFile);
        if ($zipData === false) {
            throw new Exception('Failed to read zip file');
        }
        
        // Clean up files
        foreach ($filesToCleanup as $file) {
            if (file_exists($file)) {
                unlink($file);
            }
        }
        if (is_dir($tempDir)) {
            rmdir($tempDir);
        }
        if (file_exists($zipFile)) {
            unlink($zipFile);
        }
        
        return [
            'success' => true,
            'zip_data' => base64_encode($zipData),
            'filename' => $character['name'] . '_game.zip'
        ];
        
    } catch (Exception $e) {
        // Clean up on error
        foreach ($filesToCleanup as $file) {
            if (file_exists($file)) {
                unlink($file);
            }
        }
        if (is_dir($tempDir)) {
            rmdir($tempDir);
        }
        if (isset($zipFile) && file_exists($zipFile)) {
            unlink($zipFile);
        }
        
        return ['success' => false, 'error' => $e->getMessage()];
    }
}

function createGameHTML($character) {
    $poseMappings = $character['pose_mappings'];
    $characterName = htmlspecialchars($character['name']);
    $gridSize = $character['grid_size'];
    
    return '<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>' . $characterName . ' Game</title>
    <style>
        body { margin: 0; padding: 20px; background: #222; color: white; font-family: Arial, sans-serif; text-align: center; }
        #gameCanvas { border: 2px solid #212529; background-size: cover; background-position: center; }
        .controls { margin: 20px 0; }
    </style>
</head>
<body>
    <h1>' . $characterName . ' Game</h1>
    <canvas id="gameCanvas" width="1200" height="500"></canvas>
    <div class="controls">
        <p><strong>Controls:</strong> W (Forward), A (Left), S (Down), D (Right), SPACE (Jump), SHIFT (Crouch)</p>
        <p>Use the arrow keys or WASD to move your character around the world!</p>
    </div>
    
    <script>
        const canvas = document.getElementById("gameCanvas");
        const ctx = canvas.getContext("2d");
        
        // Game state
        let character = { x: 100, y: 300, currentPose: "space", isJumping: false, velocityY: 0 };
        let poseMappings = ' . $poseMappings . ';
        let gridSize = "' . $gridSize . '";
        let spriteImage = new Image();
        let backgroundImage = new Image();
        
        // Load images with base64 data
        spriteImage.src = "data:image/png;base64,' . $character['sprite_data'] . '";
        backgroundImage.src = "data:image/png;base64,' . $character['world_background'] . '";
        
        backgroundImage.onload = function() {
            canvas.style.backgroundImage = "url(data:image/png;base64,' . $character['world_background'] . ')";
            startGame();
        };
        
        // Keyboard handling
        document.addEventListener("keydown", handleKeyDown);
        document.addEventListener("keyup", handleKeyUp);
        
        function handleKeyDown(e) {
            const key = e.key.toLowerCase();
            let mappedKey = key === " " ? "space" : (key === "shift" ? "shift" : key);
            
            if (poseMappings[mappedKey]) {
                character.currentPose = mappedKey;
                
                switch(mappedKey) {
                    case "a":
                        character.x = Math.max(0, character.x - 5);
                        break;
                    case "d":
                        character.x = Math.min(canvas.width - 64, character.x + 5);
                        break;
                    case "w":
                        character.y = Math.max(0, character.y - 5);
                        break;
                    case "s":
                        character.y = Math.min(canvas.height - 64, character.y + 5);
                        break;
                    case "space":
                        if (!character.isJumping) {
                            character.isJumping = true;
                            character.velocityY = -15;
                        }
                        break;
                }
            }
        }
        
        function handleKeyUp(e) {
            const key = e.key.toLowerCase();
            let mappedKey = key === " " ? "space" : (key === "shift" ? "shift" : key);
            
            if (poseMappings[mappedKey] && character.currentPose === mappedKey) {
                character.currentPose = "space";
            }
        }
        
        function startGame() {
            function gameLoop() {
                ctx.clearRect(0, 0, canvas.width, canvas.height);
                
                // Handle jumping physics
                if (character.isJumping) {
                    character.y += character.velocityY;
                    character.velocityY += 0.8;
                    
                    if (character.y >= 300) {
                        character.y = 300;
                        character.isJumping = false;
                        character.velocityY = 0;
                    }
                }
                
                drawCharacter();
                requestAnimationFrame(gameLoop);
            }
            gameLoop();
        }
        
        function drawCharacter() {
            if (!spriteImage.complete || !poseMappings[character.currentPose]) return;
            
            const pose = poseMappings[character.currentPose];
            
            const srcX = pose.x * spriteImage.width;
            const srcY = pose.y * spriteImage.height;
            const srcW = pose.width * spriteImage.width;
            const srcH = pose.height * spriteImage.height;
            
            ctx.drawImage(spriteImage, srcX, srcY, srcW, srcH, character.x, character.y, 64, 64);
        }
    </script>
</body>
</html>';
}

// Initialize database on first run
initDatabase();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>AI Sprite Sheet Generator</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%);
            min-height: 100vh;
            color: #212529;
        }
        
        .container {
            max-width: 1200px;
            margin: 0 auto;
            padding: 20px;
        }
        
        .header {
            background: white;
            border-radius: 15px;
            padding: 20px;
            margin-bottom: 20px;
            box-shadow: 0 8px 32px rgba(0,0,0,0.1);
            display: flex;
            justify-content: between;
            align-items: center;
            gap: 20px;
        }
        
        .header h1 {
            color: #212529;
            font-size: 2.5em;
            font-weight: 700;
        }
        
        .settings-btn {
            background: #212529;
            border: none;
            color: white;
            padding: 12px;
            border-radius: 50%;
            cursor: pointer;
            font-size: 18px;
            transition: all 0.3s ease;
            margin-left: auto;
        }
        
        .settings-btn:hover {
            background: #495057;
            transform: rotate(90deg);
        }
        
        .main-content {
            display: grid;
            grid-template-columns: 1fr 2fr;
            gap: 20px;
            margin-bottom: 20px;
        }
        
        .panel {
            background: white;
            border-radius: 15px;
            padding: 25px;
            box-shadow: 0 8px 32px rgba(0,0,0,0.1);
        }
        
        .panel h2 {
            color: #212529;
            margin-bottom: 20px;
            font-size: 1.5em;
        }
        
        .form-group {
            margin-bottom: 15px;
        }
        
        .form-group label {
            display: block;
            margin-bottom: 5px;
            font-weight: 600;
            color: #555;
        }
        
        .form-group input, .form-group select, .form-group textarea {
            width: 100%;
            padding: 12px;
            border: 2px solid #e2e8f0;
            border-radius: 8px;
            font-size: 16px;
            transition: border-color 0.3s ease;
        }
        
        .form-group input:focus, .form-group select:focus, .form-group textarea:focus {
            outline: none;
            border-color: #212529;
        }
        
        .form-group textarea {
            resize: vertical;
            min-height: 100px;
        }
        
        .btn {
            background: #212529;
            color: white;
            border: none;
            padding: 12px 24px;
            border-radius: 8px;
            cursor: pointer;
            font-size: 16px;
            font-weight: 600;
            transition: all 0.3s ease;
            width: 100%;
        }
        
        .btn:hover {
            background: #495057;
            transform: translateY(-2px);
        }
        
        .btn:disabled {
            background: #cbd5e0;
            cursor: not-allowed;
            transform: none;
        }
        
        .btn-secondary {
            background: #718096;
        }
        
        .btn-secondary:hover {
            background: #4a5568;
        }
        
        .btn-danger {
            background: #e53e3e;
        }
        
        .btn-danger:hover {
            background: #c53030;
        }
        
        .gallery {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
            gap: 15px;
            max-height: 600px;
            overflow-y: auto;
        }
        
        .image-card {
            background: white;
            border-radius: 10px;
            overflow: hidden;
            box-shadow: 0 4px 16px rgba(0,0,0,0.1);
            transition: all 0.3s ease;
            cursor: pointer;
            position: relative;
        }
        
        .image-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 8px 32px rgba(0,0,0,0.2);
        }
        
        .image-card img {
            width: 100%;
            height: 150px;
            object-fit: cover;
        }
        
        .image-card .card-content {
            padding: 12px;
        }
        
        .image-card .prompt {
            font-size: 12px;
            color: #666;
            margin-bottom: 8px;
            display: -webkit-box;
            -webkit-line-clamp: 2;
            -webkit-box-orient: vertical;
            overflow: hidden;
        }
        
        .image-card .meta {
            font-size: 10px;
            color: #999;
        }
        
        .image-card .actions {
            position: absolute;
            top: 5px;
            right: 5px;
            opacity: 0;
            transition: opacity 0.3s ease;
        }
        
        .image-card:hover .actions {
            opacity: 1;
        }
        
        .image-card .checkbox {
            position: absolute;
            top: 5px;
            left: 5px;
            opacity: 0;
            transition: opacity 0.3s ease;
        }
        
        .image-card:hover .checkbox, .image-card.selected .checkbox {
            opacity: 1;
        }
        
        .image-card.selected {
            border: 3px solid #212529;
        }
        
        .sprite-controls {
            display: flex;
            gap: 10px;
            margin-top: 15px;
            align-items: center;
        }
        
        .sprite-controls input, .sprite-controls select {
            flex: 1;
        }
        
        .modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0,0,0,0.5);
            z-index: 1000;
        }
        
        .modal-content {
            background: white;
            margin: 50px auto;
            padding: 30px;
            border-radius: 15px;
            max-width: 500px;
            position: relative;
        }
        
        .close {
            position: absolute;
            top: 15px;
            right: 20px;
            font-size: 24px;
            cursor: pointer;
            color: #999;
        }
        
        .close:hover {
            color: #333;
        }
        
        .tabs {
            display: flex;
            margin-bottom: 20px;
            border-bottom: 2px solid #e2e8f0;
        }
        
        .tab {
            padding: 12px 24px;
            cursor: pointer;
            border-bottom: 2px solid transparent;
            transition: all 0.3s ease;
            color: #666;
        }
        
        .tab:hover {
            color: #333;
        }
        
        .tab.active {
            border-bottom-color: #212529;
            color: #212529;
            font-weight: 600;
        }
        
        .tab-content {
            display: none;
        }
        
        .tab-content.active {
            display: block;
        }
        
        .loading {
            text-align: center;
            padding: 20px;
            color: #666;
        }
        
        .spinner {
            border: 3px solid #f3f3f3;
            border-top: 3px solid #212529;
            border-radius: 50%;
            width: 30px;
            height: 30px;
            animation: spin 1s linear infinite;
            margin: 0 auto 10px;
        }
        
        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }
        
        .error {
            background: #fee;
            border: 1px solid #fcc;
            color: #c33;
            padding: 12px;
            border-radius: 8px;
            margin-bottom: 15px;
        }
        
        .success {
            background: #efe;
            border: 1px solid #cfc;
            color: #3c3;
            padding: 12px;
            border-radius: 8px;
            margin-bottom: 15px;
        }
        
        .mapping-btn {
            position: relative;
            height: 80px;
            text-align: center;
            display: flex;
            flex-direction: column;
            justify-content: center;
            align-items: center;
        }
        
        .mapping-btn.selected {
            background: #212529 !important;
            color: white !important;
        }
        
        .pose-preview {
            width: 30px;
            height: 30px;
            border: 1px solid #ddd;
            border-radius: 4px;
            margin-top: 5px;
            background-size: cover;
            background-position: center;
        }
        
        #game-canvas {
            cursor: crosshair;
            border: 2px solid #212529;
        }
        
        .sprite-selector {
            position: absolute;
            border: 3px solid #ff6b6b;
            background: rgba(255, 107, 107, 0.2);
            pointer-events: none;
            display: none;
        }
        
        .btn.loading {
            position: relative;
            color: transparent !important;
            overflow: hidden;
        }
        
        .btn.loading::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            height: 100%;
            background: linear-gradient(90deg, #495057 0%, #212529 50%, #495057 100%);
            background-size: 200% 100%;
            animation: loading-shimmer 2s infinite;
            border-radius: 8px;
        }
        
        .btn.loading::after {
            content: attr(data-loading-text);
            position: absolute;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            color: white;
            font-weight: 600;
            z-index: 1;
        }
        
        @keyframes loading-shimmer {
            0% {
                background-position: -200% 0;
            }
            100% {
                background-position: 200% 0;
            }
        }
        
        .flip-controls {
            display: flex;
            gap: 10px;
            justify-content: center;
            margin: 15px 0;
        }
        
        .flip-btn {
            background: #6c757d;
            color: white;
            border: none;
            padding: 8px 12px;
            border-radius: 4px;
            cursor: pointer;
            font-size: 12px;
        }
        
        .flip-btn:hover {
            background: #495057;
        }
        
        .flip-btn.active {
            background: #212529;
        }
        
        @media (max-width: 768px) {
            .main-content {
                grid-template-columns: 1fr;
            }
            
            .header {
                flex-direction: column;
                text-align: center;
            }
            
            .header h1 {
                font-size: 2em;
            }
            
            .gallery {
                grid-template-columns: repeat(auto-fill, minmax(150px, 1fr));
            }
            
            .modal-content {
                margin: 10px;
                max-width: 95% !important;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>🎨 AI Sprite Sheet Generator</h1>
            <a href="games.php" class="btn btn-secondary" style="margin-left: auto; text-decoration: none;">🎮 Saved Games</a>
            <button class="settings-btn" onclick="openSettings()">⚙️</button>
        </div>
        
        <div class="main-content">
            <div class="panel">
                <h2>Generate Images</h2>
                <div id="generate-error" class="error" style="display: none;"></div>
                <div id="generate-success" class="success" style="display: none;"></div>
                
                <form id="generate-form">
                    <div class="form-group">
                        <label for="prompt">Prompt</label>
                        <textarea id="prompt" placeholder="Describe the image you want to generate..." required></textarea>
                    </div>
                    
                    <div class="form-group">
                        <label for="size">Size</label>
                        <select id="size">
                            <option value="1024x1024">1024×1024 (Square)</option>
                            <option value="1536x1024">1536×1024 (Landscape)</option>
                            <option value="1024x1536">1024×1536 (Portrait)</option>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label for="quality">Quality</label>
                        <select id="quality">
                            <option value="medium">Medium</option>
                            <option value="high">High</option>
                            <option value="low">Low</option>
                            <option value="auto">Auto</option>
                        </select>
                    </div>
                    
                    <button type="submit" class="btn">Generate Image</button>
                </form>
                
                <div id="sprite-section" style="margin-top: 30px;">
                    <h3>Create Sprite Sheets</h3>
                    <p style="font-size: 14px; color: #666; margin-bottom: 15px;">
                        Click on any generated image to view it and create a sprite sheet from it. The AI will generate multiple poses/animations of your character.
                    </p>
                    <div style="padding: 15px; background: #f8f9fa; border-radius: 8px; border-left: 4px solid #667eea;">
                        <strong>🎮 How it works:</strong><br>
                        <small>1. Generate an image of your character<br>
                               2. Click the image to open it<br>
                               3. Click "Create Sprite Sheet"<br>
                               4. AI generates multiple poses in a grid</small>
                    </div>
                </div>
            </div>
            
            <div class="panel">
                <div class="tabs">
                    <div class="tab active" onclick="switchTab('images', this)">Generated Images</div>
                    <div class="tab" onclick="switchTab('sprites', this)">Sprite Sheets</div>
                    <div class="tab" onclick="switchTab('worlds', this)">Game Worlds</div>
                    <div class="tab" onclick="switchTab('games', this)">Games</div>
                </div>
                
                <div id="images-tab" class="tab-content active">
                    <div id="gallery" class="gallery">
                        <div class="loading">
                            <div class="spinner"></div>
                            Loading images...
                        </div>
                    </div>
                </div>
                
                <div id="sprites-tab" class="tab-content">
                    <div id="sprite-gallery" class="gallery">
                        <div class="loading">
                            <div class="spinner"></div>
                            Loading sprite sheets...
                        </div>
                    </div>
                </div>
                
                <div id="worlds-tab" class="tab-content">
                    <div id="worlds-gallery" class="gallery">
                        <div class="loading">
                            <div class="spinner"></div>
                            Loading game worlds...
                        </div>
                    </div>
                </div>
                
                <div id="games-tab" class="tab-content">
                    <div id="games-gallery" class="gallery">
                        <div class="loading">
                            <div class="spinner"></div>
                            Loading games...
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Image Modal -->
    <div id="image-modal" class="modal">
        <div class="modal-content" style="max-width: 800px;">
            <span class="close" onclick="closeImageModal()">&times;</span>
            <h2 id="modal-image-title">Image Details</h2>
            
            <div style="text-align: center; margin: 20px 0;">
                <img id="modal-image" src="" alt="Full size image" style="max-width: 100%; max-height: 500px; border-radius: 8px;">
            </div>
            
            <div id="modal-image-details" style="margin: 20px 0; padding: 15px; background: #f8f9fa; border-radius: 8px;">
                <p><strong>Prompt:</strong> <span id="modal-prompt"></span></p>
                <p><strong>Size:</strong> <span id="modal-size"></span></p>
                <p><strong>Quality:</strong> <span id="modal-quality"></span></p>
                <p><strong>Created:</strong> <span id="modal-date"></span></p>
            </div>
            
            <div style="display: flex; gap: 10px; justify-content: center;">
                <button class="btn" onclick="downloadImage()" style="flex: 1;">📥 Download Image</button>
                <button class="btn btn-secondary" onclick="openSpriteSheetModal()" style="flex: 1;" id="sprite-sheet-btn">🎮 Create Sprite Sheet</button>
                <button class="btn" onclick="sendToSpriteStudio()" style="flex: 1; display: none;" id="sprite-studio-btn">🎮 Send to Sprite Studio</button>
            </div>
        </div>
    </div>
    
    <!-- Sprite Sheet Creation Modal -->
    <div id="sprite-sheet-modal" class="modal">
        <div class="modal-content">
            <span class="close" onclick="closeSpriteSheetModal()">&times;</span>
            <h2>Create Sprite Sheet</h2>
            
            <div style="text-align: center; margin: 20px 0;">
                <img id="sprite-source-image" src="" alt="Source image" style="max-width: 200px; max-height: 200px; border-radius: 8px;">
            </div>
            
            <div class="form-group">
                <label for="modal-sprite-name">Sprite Sheet Name</label>
                <input type="text" id="modal-sprite-name" placeholder="Enter sprite sheet name">
            </div>
            
            <div class="form-group">
                <label for="modal-grid-size">Grid Size</label>
                <select id="modal-grid-size">
                    <option value="2x2">2×2 Grid (4 sprites)</option>
                    <option value="3x3">3×3 Grid (9 sprites)</option>
                    <option value="4x4">4×4 Grid (16 sprites)</option>
                    <option value="2x3">2×3 Grid (6 sprites)</option>
                    <option value="3x2">3×2 Grid (6 sprites)</option>
                    <option value="4x2">4×2 Grid (8 sprites)</option>
                    <option value="2x4">2×4 Grid (8 sprites)</option>
                </select>
            </div>
            
            <p style="font-size: 14px; color: #666; margin: 15px 0;">This will generate a sprite sheet with multiple poses/animations of your character using AI.</p>
            
            <button class="btn" onclick="createSpriteSheetFromModal()" id="create-sprite-btn">🎮 Generate Sprite Sheet</button>
        </div>
    </div>
    
    <!-- Sprite Studio Modal -->
    <div id="sprite-studio-modal" class="modal">
        <div class="modal-content" style="max-width: 1000px;">
            <span class="close" onclick="closeSpriteStudio()">&times;</span>
            <h2>🎮 Sprite Studio - Map Your Character</h2>
            
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 30px; margin: 20px 0;">
                <!-- Left side - Sprite Sheet -->
                <div>
                    <h3>Your Sprite Sheet</h3>
                    <div style="text-align: center; margin: 20px 0; position: relative;" id="sprite-container">
                        <img id="studio-sprite-sheet" src="" alt="Sprite sheet" style="max-width: 100%; border: 2px solid #212529; border-radius: 8px; user-select: none;">
                        <div id="selection-box" class="sprite-selector"></div>
                    </div>
                    <div class="flip-controls">
                        <button class="flip-btn active" onclick="toggleFlip(false)" id="flip-normal">Normal</button>
                        <button class="flip-btn" onclick="toggleFlip(true)" id="flip-horizontal">Flip Horizontal</button>
                    </div>
                    <p style="font-size: 12px; color: #666;">Select a key, then drag to select an area of the sprite sheet</p>
                </div>
                
                <!-- Right side - Keyboard Mapping -->
                <div>
                    <h3>Keyboard Mapping</h3>
                    <div class="form-group">
                        <label for="character-name">Character Name</label>
                        <input type="text" id="character-name" placeholder="Enter character name">
                    </div>
                    
                    <div class="form-group">
                        <label for="world-prompt">Game World Description</label>
                        <textarea id="world-prompt" placeholder="Describe the game world you want (e.g., 'forest platformer with trees and waterfalls', 'space station with metal platforms')" style="height: 60px;"></textarea>
                    </div>
                    
                    <div style="display: grid; grid-template-columns: repeat(4, 1fr); gap: 8px; margin: 20px 0;">
                        <button class="btn btn-secondary mapping-btn" data-key="w" onclick="selectPoseForKey('w')">
                            W<br><small>Forward</small><br>
                            <div class="pose-preview" id="pose-w"></div>
                        </button>
                        <button class="btn btn-secondary mapping-btn" data-key="a" onclick="selectPoseForKey('a')">
                            A<br><small>Left</small><br>
                            <div class="pose-preview" id="pose-a"></div>
                        </button>
                        <button class="btn btn-secondary mapping-btn" data-key="s" onclick="selectPoseForKey('s')">
                            S<br><small>Down</small><br>
                            <div class="pose-preview" id="pose-s"></div>
                        </button>
                        <button class="btn btn-secondary mapping-btn" data-key="d" onclick="selectPoseForKey('d')">
                            D<br><small>Right</small><br>
                            <div class="pose-preview" id="pose-d"></div>
                        </button>
                        
                        <button class="btn btn-secondary mapping-btn" data-key="space" onclick="selectPoseForKey('space')">
                            SPACE<br><small>Jump</small><br>
                            <div class="pose-preview" id="pose-space"></div>
                        </button>
                        <button class="btn btn-secondary mapping-btn" data-key="shift" onclick="selectPoseForKey('shift')">
                            SHIFT<br><small>Crouch</small><br>
                            <div class="pose-preview" id="pose-shift"></div>
                        </button>
                        <div></div>
                        <div></div>
                    </div>
                    
                    <p style="font-size: 12px; color: #666; margin: 15px 0;">
                        1. Select a keyboard button<br>
                        2. Click and drag on the sprite sheet to select an area<br>
                        3. Click 'Save Pose' to assign it
                    </p>
                    
                    <div style="display: flex; gap: 10px; margin: 15px 0;">
                        <button class="btn btn-secondary" onclick="savePose()" id="save-pose-btn" disabled>💾 Save Pose</button>
                        <button class="btn btn-secondary" onclick="clearSelection()" id="clear-btn">🗑️ Clear Selection</button>
                    </div>
                    
                    <button class="btn" onclick="createGameCharacter()" id="create-game-btn">🌍 Create Game World</button>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Game World Modal -->
    <div id="game-world-modal" class="modal">
        <div class="modal-content" style="max-width: 1200px;">
            <span class="close" onclick="closeGameWorld()">&times;</span>
            <h2>🌍 Game World</h2>
            
            <div id="game-container" style="position: relative; width: 100%; height: 500px; border: 2px solid #212529; border-radius: 8px; overflow: hidden;">
                <canvas id="game-canvas" width="1200" height="500" style="display: block; background-size: cover; background-position: center;"></canvas>
            </div>
            
            <div style="margin: 20px 0; text-align: center;">
                <p><strong>Controls:</strong> W (Forward), A (Left), S (Down), D (Right), SPACE (Jump), SHIFT (Crouch)</p>
                <div style="display: flex; gap: 10px; justify-content: center; margin-top: 15px;">
                    <button class="btn btn-secondary" onclick="downloadGameScreenshot()">📸 Screenshot</button>
                    <button class="btn btn-secondary" onclick="resetCharacterPosition()">🔄 Reset Position</button>
                    <button class="btn" id="download-game-btn">📦 Download Game</button>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Settings Modal -->
    <div id="settings-modal" class="modal">
        <div class="modal-content">
            <span class="close" onclick="closeSettings()">&times;</span>
            <h2>Settings</h2>
            
            <div class="form-group">
                <label for="api-key">OpenAI API Key</label>
                <input type="password" id="api-key" placeholder="sk-..." value="<?php echo htmlspecialchars($_SESSION['openai_api_key'] ?? ''); ?>">
                <small style="color: #666;">Your API key is stored securely in this session only.</small>
            </div>
            
            <button class="btn" onclick="saveApiKey()">Save API Key</button>
        </div>
    </div>
    
    <script>
        let selectedImages = new Set();
        let images = [];
        let sprites = [];
        let worlds = [];
        let games = [];
        
        // Initialize app
        document.addEventListener('DOMContentLoaded', function() {
            loadImages();
            loadSprites();
            loadGameWorlds();
            loadGames();
            
            document.getElementById('generate-form').addEventListener('submit', function(e) {
                e.preventDefault();
                generateImage();
            });
        });
        
        // Settings functions
        function openSettings() {
            document.getElementById('settings-modal').style.display = 'block';
        }
        
        function closeSettings() {
            document.getElementById('settings-modal').style.display = 'none';
        }
        
        function saveApiKey() {
            const apiKey = document.getElementById('api-key').value.trim();
            
            if (!apiKey) {
                alert('Please enter your API key');
                return;
            }
            
            fetch('', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: new URLSearchParams({
                    action: 'save_api_key',
                    api_key: apiKey
                })
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    closeSettings();
                    showMessage('API key saved successfully!', 'success');
                }
            })
            .catch(error => {
                console.error('Error:', error);
                alert('Failed to save API key');
            });
        }
        
        // Image generation
        function generateImage() {
            const submitBtn = document.querySelector('#generate-form button[type="submit"]');
            const originalText = submitBtn.textContent;
            
            submitBtn.disabled = true;
            submitBtn.classList.add('loading');
            submitBtn.setAttribute('data-loading-text', 'Generating Image...');
            
            const formData = new FormData();
            formData.append('action', 'generate_image');
            formData.append('prompt', document.getElementById('prompt').value);
            formData.append('size', document.getElementById('size').value);
            formData.append('quality', document.getElementById('quality').value);
            
            fetch('', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    showMessage('Image generated successfully!', 'success');
                    loadImages(); // Refresh gallery
                    document.getElementById('prompt').value = '';
                } else {
                    showMessage(data.error || 'Failed to generate image', 'error');
                }
            })
            .catch(error => {
                console.error('Error:', error);
                showMessage('Network error occurred', 'error');
            })
            .finally(() => {
                submitBtn.disabled = false;
                submitBtn.classList.remove('loading');
                submitBtn.removeAttribute('data-loading-text');
                submitBtn.textContent = originalText;
            });
        }
        
        // Load images
        function loadImages() {
            fetch('', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: new URLSearchParams({ action: 'get_images' })
            })
            .then(response => response.json())
            .then(data => {
                images = data;
                renderGallery();
            })
            .catch(error => {
                console.error('Error loading images:', error);
                document.getElementById('gallery').innerHTML = '<p>Error loading images</p>';
            });
        }
        
        // Load sprite sheets
        function loadSprites() {
            fetch('', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: new URLSearchParams({ action: 'get_sprite_sheets' })
            })
            .then(response => response.json())
            .then(data => {
                sprites = data;
                renderSpriteGallery();
            })
            .catch(error => {
                console.error('Error loading sprites:', error);
                document.getElementById('sprite-gallery').innerHTML = '<p>Error loading sprite sheets</p>';
            });
        }
        
        // Load game worlds
        function loadGameWorlds() {
            fetch('', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: new URLSearchParams({ action: 'get_game_worlds' })
            })
            .then(response => response.json())
            .then(data => {
                worlds = data;
                renderWorldsGallery();
            })
            .catch(error => {
                console.error('Error loading worlds:', error);
                document.getElementById('worlds-gallery').innerHTML = '<p>Error loading game worlds</p>';
            });
        }
        
        // Load games
        function loadGames() {
            fetch('', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: new URLSearchParams({ action: 'get_games' })
            })
            .then(response => response.json())
            .then(data => {
                games = data;
                renderGameGallery();
            })
            .catch(error => {
                console.error('Error loading games:', error);
                document.getElementById('games-gallery').innerHTML = '<p>Error loading games</p>';
            });
        }
        
        // Render gallery
        function renderGallery() {
            const gallery = document.getElementById('gallery');
            
            if (images.length === 0) {
                gallery.innerHTML = '<p style="text-align: center; color: #666;">No images generated yet. Create your first image!</p>';
                return;
            }
            
            gallery.innerHTML = images.map(image => `
                <div class="image-card ${selectedImages.has(image.id) ? 'selected' : ''}" onclick="openImageModal(${image.id})">
                    <div class="checkbox">
                        <input type="checkbox" ${selectedImages.has(image.id) ? 'checked' : ''} onclick="event.stopPropagation(); toggleImageSelection(${image.id})">
                    </div>
                    <div class="actions">
                        <button onclick="event.stopPropagation(); deleteImage(${image.id})" style="background: #e53e3e; color: white; border: none; border-radius: 4px; padding: 4px 8px; cursor: pointer;">×</button>
                    </div>
                    <img src="data:image/png;base64,${image.image_data}" alt="Generated image">
                    <div class="card-content">
                        <div class="prompt">${image.prompt}</div>
                        <div class="meta">${image.size} • ${image.quality} • ${new Date(image.created_at).toLocaleDateString()}</div>
                    </div>
                </div>
            `).join('');
        }
        
        // Open image modal
        function openImageModal(imageId) {
            const image = images.find(img => img.id === imageId);
            if (!image) return;
            
            currentImageData = image;
            
            document.getElementById('modal-image-title').textContent = 'Image Details';
            document.getElementById('modal-image').src = `data:image/png;base64,${image.image_data}`;
            document.getElementById('modal-prompt').textContent = image.prompt;
            document.getElementById('modal-size').textContent = image.size;
            document.getElementById('modal-quality').textContent = image.quality;
            document.getElementById('modal-date').textContent = new Date(image.created_at).toLocaleDateString();
            
            // Show sprite sheet creation button for regular images
            document.querySelector('#image-modal #sprite-sheet-btn').style.display = 'block';
            // Hide sprite studio button for regular images
            document.querySelector('#image-modal #sprite-studio-btn').style.display = 'none';
            
            document.getElementById('image-modal').style.display = 'block';
        }
        
        // Close image modal
        function closeImageModal() {
            document.getElementById('image-modal').style.display = 'none';
        }
        
        // Download image
        function downloadImage() {
            if (!currentImageData) return;
            
            const link = document.createElement('a');
            link.href = `data:image/png;base64,${currentImageData.image_data}`;
            link.download = `generated-image-${currentImageData.id}.png`;
            document.body.appendChild(link);
            link.click();
            document.body.removeChild(link);
        }
        
        // Open sprite sheet creation modal
        function openSpriteSheetModal() {
            if (!currentImageData) return;
            
            document.getElementById('sprite-source-image').src = `data:image/png;base64,${currentImageData.image_data}`;
            document.getElementById('modal-sprite-name').value = `${currentImageData.prompt.substring(0, 30)}... Sprite Sheet`;
            
            closeImageModal();
            document.getElementById('sprite-sheet-modal').style.display = 'block';
        }
        
        // Close sprite sheet modal
        function closeSpriteSheetModal() {
            document.getElementById('sprite-sheet-modal').style.display = 'none';
        }
        
        // Create sprite sheet from modal
        function createSpriteSheetFromModal() {
            if (!currentImageData) return;
            
            const name = document.getElementById('modal-sprite-name').value || 'Sprite Sheet';
            const gridSize = document.getElementById('modal-grid-size').value;
            const btn = document.getElementById('create-sprite-btn');
            
            btn.disabled = true;
            btn.classList.add('loading');
            btn.setAttribute('data-loading-text', 'Generating Sprite Sheet...');
            
            const formData = new FormData();
            formData.append('action', 'create_sprite_sheet');
            formData.append('image_id', currentImageData.id);
            formData.append('grid_size', gridSize);
            formData.append('name', name);
            
            fetch('', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    showMessage('Sprite sheet generated successfully!', 'success');
                    closeSpriteSheetModal();
                    loadSprites();
                    switchTab('sprites');
                } else {
                    showMessage(data.error || 'Failed to generate sprite sheet', 'error');
                }
            })
            .catch(error => {
                console.error('Error:', error);
                showMessage('Network error occurred', 'error');
            })
            .finally(() => {
                btn.disabled = false;
                btn.classList.remove('loading');
                btn.removeAttribute('data-loading-text');
                btn.textContent = '🎮 Generate Sprite Sheet';
            });
        }
        
        // Render sprite gallery
        function renderSpriteGallery() {
            const gallery = document.getElementById('sprite-gallery');
            
            if (sprites.length === 0) {
                gallery.innerHTML = '<p style="text-align: center; color: #666;">No sprite sheets created yet.</p>';
                return;
            }
            
            gallery.innerHTML = sprites.map(sprite => `
                <div class="image-card" onclick="openSpriteModal(${sprite.id})">
                    <div class="actions">
                        <button onclick="event.stopPropagation(); downloadSprite(${sprite.id})" style="background: #212529; color: white; border: none; border-radius: 4px; padding: 4px 8px; cursor: pointer; margin-right: 4px;">⬇</button>
                        <button onclick="event.stopPropagation(); deleteSpriteSheet(${sprite.id})" style="background: #dc3545; color: white; border: none; border-radius: 4px; padding: 4px 8px; cursor: pointer;">×</button>
                    </div>
                    <img src="data:image/png;base64,${sprite.sprite_data}" alt="Sprite sheet">
                    <div class="card-content">
                        <div class="prompt">${sprite.name}</div>
                        <div class="meta">${sprite.grid_size} • ${new Date(sprite.created_at).toLocaleDateString()}</div>
                    </div>
                </div>
            `).join('');
        }
        
        // Open sprite modal
        function openSpriteModal(spriteId) {
            const sprite = sprites.find(s => s.id === spriteId);
            if (!sprite) return;
            
            document.getElementById('modal-image-title').textContent = 'Sprite Sheet: ' + sprite.name;
            document.getElementById('modal-image').src = `data:image/png;base64,${sprite.sprite_data}`;
            document.getElementById('modal-prompt').textContent = sprite.name;
            document.getElementById('modal-size').textContent = sprite.grid_size + ' Grid';
            document.getElementById('modal-quality').textContent = 'AI Generated';
            document.getElementById('modal-date').textContent = new Date(sprite.created_at).toLocaleDateString();
            
            // Hide sprite sheet creation button for sprite sheets
            document.querySelector('#image-modal #sprite-sheet-btn').style.display = 'none';
            // Show sprite studio button for sprite sheets
            const studioBtn = document.querySelector('#image-modal #sprite-studio-btn');
            studioBtn.style.display = 'block';
            studioBtn.textContent = '🎮 Send to Sprite Studio';
            studioBtn.onclick = sendToSpriteStudio;
            
            // Set current data for download and studio
            currentImageData = { 
                id: sprite.id, 
                image_data: sprite.sprite_data, 
                name: sprite.name,
                grid_size: sprite.grid_size,
                isSprite: true
            };
            
            document.getElementById('image-modal').style.display = 'block';
        }
        
        // Download sprite
        function downloadSprite(spriteId) {
            const sprite = sprites.find(s => s.id === spriteId);
            if (!sprite) return;
            
            const link = document.createElement('a');
            link.href = `data:image/png;base64,${sprite.sprite_data}`;
            link.download = `${sprite.name.replace(/\s+/g, '-').toLowerCase()}-sprite-sheet.png`;
            document.body.appendChild(link);
            link.click();
            document.body.removeChild(link);
        }
        
        // Render worlds gallery (just background images)
        function renderWorldsGallery() {
            const gallery = document.getElementById('worlds-gallery');
            
            if (worlds.length === 0) {
                gallery.innerHTML = '<p style="text-align: center; color: #666;">No game worlds created yet.</p>';
                return;
            }
            
            gallery.innerHTML = worlds.map(world => `
                <div class="image-card" onclick="openWorldModal(${world.id})">
                    <div class="actions">
                        <button onclick="event.stopPropagation(); downloadWorldImage(${world.id})" style="background: #212529; color: white; border: none; border-radius: 4px; padding: 4px 8px; cursor: pointer; margin-right: 4px;">⬇</button>
                        <button onclick="event.stopPropagation(); deleteGameWorld(${world.id})" style="background: #dc3545; color: white; border: none; border-radius: 4px; padding: 4px 8px; cursor: pointer;">×</button>
                    </div>
                    <img src="data:image/png;base64,${world.world_background}" alt="Game world" style="width: 100%; height: 150px; object-fit: cover;">
                    <div class="card-content">
                        <div class="prompt">${world.name} World</div>
                        <div class="meta">Game World ${world.world_prompt ? '• ' + world.world_prompt.substring(0, 30) + '...' : ''} • ${new Date(world.created_at).toLocaleDateString()}</div>
                    </div>
                </div>
            `).join('');
        }
        
        // Render game gallery (playable games)
        function renderGameGallery() {
            const gallery = document.getElementById('games-gallery');
            
            if (games.length === 0) {
                gallery.innerHTML = '<p style="text-align: center; color: #666;">No games created yet.</p>';
                return;
            }
            
            gallery.innerHTML = games.map(game => `
                <div class="image-card" onclick="playGame(${game.id})">
                    <div class="actions">
                        <button onclick="event.stopPropagation(); playGame(${game.id})" style="background: #28a745; color: white; border: none; border-radius: 4px; padding: 4px 8px; cursor: pointer; margin-right: 4px;">▶</button>
                        <button onclick="event.stopPropagation(); exportGame(${game.id})" style="background: #6c757d; color: white; border: none; border-radius: 4px; padding: 4px 8px; cursor: pointer; margin-right: 4px;">📦</button>
                        <button onclick="event.stopPropagation(); deleteGame(${game.id})" style="background: #dc3545; color: white; border: none; border-radius: 4px; padding: 4px 8px; cursor: pointer;">×</button>
                    </div>
                    <img src="data:image/png;base64,${game.world_background}" alt="Game world" style="width: 100%; height: 150px; object-fit: cover;">
                    <div class="card-content">
                        <div class="prompt">${game.name}</div>
                        <div class="meta">Playable Game ${game.world_prompt ? '• ' + game.world_prompt.substring(0, 30) + '...' : ''} • ${new Date(game.created_at).toLocaleDateString()}</div>
                    </div>
                </div>
            `).join('');
        }
        
        // Open game modal (this is now handled by playGame function)
        function openGameModal(gameId, gameHtml) {
            const gameContainer = document.getElementById('game-container');
            gameContainer.innerHTML = gameHtml;
            document.getElementById('game-world-modal').style.display = 'block';

            document.getElementById('download-game-btn').onclick = () => {
                exportGame(gameId);
            };
        }

        function exportGame(characterId) {
            const btn = document.getElementById('download-game-btn');
            btn.disabled = true;
            btn.classList.add('loading');
            btn.setAttribute('data-loading-text', 'Exporting...');

            fetch('', {
                method: 'POST',
                headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                body: new URLSearchParams({
                    action: 'export_game',
                    character_id: characterId
                })
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    const link = document.createElement('a');
                    link.href = `data:application/zip;base64,${data.zip_data}`;
                    link.download = data.filename;
                    document.body.appendChild(link);
                    link.click();
                    document.body.removeChild(link);
                    showMessage('Game exported successfully!', 'success');
                } else {
                    showMessage(data.error || 'Failed to export game', 'error');
                }
            })
            .catch(error => {
                console.error('Error:', error);
                showMessage('Network error occurred', 'error');
            })
            .finally(() => {
                btn.disabled = false;
                btn.classList.remove('loading');
                btn.removeAttribute('data-loading-text');
            });
        }
        
        // Delete functions
        function deleteSpriteSheet(spriteId) {
            if (!confirm('Are you sure you want to delete this sprite sheet?')) return;
            
            fetch('', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: new URLSearchParams({
                    action: 'delete_sprite_sheet',
                    id: spriteId
                })
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    loadSprites();
                    showMessage('Sprite sheet deleted successfully!', 'success');
                } else {
                    showMessage('Failed to delete sprite sheet', 'error');
                }
            })
            .catch(error => {
                console.error('Error:', error);
                showMessage('Network error occurred', 'error');
            });
        }
        
        function deleteGameWorld(gameId) {
            if (!confirm('Are you sure you want to delete this game world?')) return;
            
            fetch('', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: new URLSearchParams({
                    action: 'delete_game_world',
                    id: gameId
                })
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    loadGameWorlds();
                    showMessage('Game world deleted successfully!', 'success');
                } else {
                    showMessage('Failed to delete game world', 'error');
                }
            })
            .catch(error => {
                console.error('Error:', error);
                showMessage('Network error occurred', 'error');
            });
        }
        
        function deleteGame(gameId) {
            if (!confirm('Are you sure you want to delete this game?')) return;
            
            fetch('', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: new URLSearchParams({
                    action: 'delete_game_world',
                    id: gameId
                })
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    loadGames();
                    showMessage('Game deleted successfully!', 'success');
                } else {
                    showMessage('Failed to delete game', 'error');
                }
            })
            .catch(error => {
                console.error('Error:', error);
                showMessage('Network error occurred', 'error');
            });
        }
        
        // Open world modal (just show the image)
        function openWorldModal(worldId) {
            const world = worlds.find(w => w.id === worldId);
            if (!world) return;
            
            document.getElementById('modal-image-title').textContent = 'Game World: ' + world.name;
            document.getElementById('modal-image').src = `data:image/png;base64,${world.world_background}`;
            document.getElementById('modal-prompt').textContent = world.world_prompt || 'Game World Background';
            document.getElementById('modal-size').textContent = 'Game World';
            document.getElementById('modal-quality').textContent = 'AI Generated';
            document.getElementById('modal-date').textContent = new Date(world.created_at).toLocaleDateString();
            
            // Hide sprite sheet and studio buttons for world images
            document.querySelector('#image-modal #sprite-sheet-btn').style.display = 'none';
            document.querySelector('#image-modal #sprite-studio-btn').style.display = 'none';
            
            // Set current data for download
            currentImageData = { 
                id: world.id, 
                image_data: world.world_background, 
                name: world.name,
                isWorld: true
            };
            
            document.getElementById('image-modal').style.display = 'block';
        }
        
        // Download world image
        function downloadWorldImage(worldId) {
            const world = worlds.find(w => w.id === worldId);
            if (!world) return;
            
            const link = document.createElement('a');
            link.href = `data:image/png;base64,${world.world_background}`;
            link.download = `${world.name.replace(/\s+/g, '-').toLowerCase()}-world.png`;
            document.body.appendChild(link);
            link.click();
            document.body.removeChild(link);
        }
        
        // Image selection
        function toggleImageSelection(imageId) {
            if (selectedImages.has(imageId)) {
                selectedImages.delete(imageId);
            } else {
                selectedImages.add(imageId);
            }
            renderGallery();
        }
        
        // Delete image
        function deleteImage(imageId) {
            if (!confirm('Are you sure you want to delete this image?')) return;
            
            fetch('', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: new URLSearchParams({
                    action: 'delete_image',
                    id: imageId
                })
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    selectedImages.delete(imageId);
                    loadImages();
                    showMessage('Image deleted successfully!', 'success');
                }
            })
            .catch(error => {
                console.error('Error:', error);
                alert('Failed to delete image');
            });
        }
        
        
        // Tab switching
        function switchTab(tabName, targetElement = null) {
            // Update tab buttons
            document.querySelectorAll('.tab').forEach(tab => tab.classList.remove('active'));
            
            // If called from an event, use event.target, otherwise find the tab by tabName
            if (targetElement) {
                targetElement.classList.add('active');
            } else if (window.event && window.event.target) {
                window.event.target.classList.add('active');
            } else {
                // Find the correct tab button based on tabName
                const tabButtons = document.querySelectorAll('.tab');
                tabButtons.forEach(tab => {
                    if ((tabName === 'images' && tab.textContent.includes('Generated Images')) ||
                        (tabName === 'sprites' && tab.textContent.includes('Sprite Sheets')) ||
                        (tabName === 'worlds' && tab.textContent.includes('Game Worlds')) ||
                        (tabName === 'games' && tab.textContent.includes('Games'))) {
                        tab.classList.add('active');
                    }
                });
            }
            
            // Update tab content
            document.querySelectorAll('.tab-content').forEach(content => content.classList.remove('active'));
            document.getElementById(tabName + '-tab').classList.add('active');
            
            // Load data if needed
            if (tabName === 'sprites') {
                loadSprites();
            } else if (tabName === 'worlds') {
                loadGameWorlds();
            } else if (tabName === 'games') {
                loadGames();
            }
        }
        
        // Show message
        function showMessage(message, type) {
            const errorDiv = document.getElementById('generate-error');
            const successDiv = document.getElementById('generate-success');
            
            if (type === 'error') {
                errorDiv.textContent = message;
                errorDiv.style.display = 'block';
                successDiv.style.display = 'none';
            } else {
                successDiv.textContent = message;
                successDiv.style.display = 'block';
                errorDiv.style.display = 'none';
            }
            
            // Hide after 5 seconds
            setTimeout(() => {
                errorDiv.style.display = 'none';
                successDiv.style.display = 'none';
            }, 5000);
        }
        
        // Sprite Studio Variables
        let currentSpriteData = null;
        let selectedKey = null;
        let poseMappings = {};
        let gameCharacter = null;
        let isSelecting = false;
        let selectionStart = { x: 0, y: 0 };
        let currentSelection = null;
        let isFlipped = false;
        
        // Send to Sprite Studio
        function sendToSpriteStudio() {
            if (!currentImageData || !currentImageData.isSprite) return;
            
            currentSpriteData = currentImageData;
            document.getElementById('studio-sprite-sheet').src = `data:image/png;base64,${currentImageData.image_data}`;
            document.getElementById('character-name').value = currentImageData.name;
            
            // Reset flip state
            isFlipped = false;
            document.getElementById('flip-normal').classList.add('active');
            document.getElementById('flip-horizontal').classList.remove('active');
            document.getElementById('studio-sprite-sheet').style.transform = 'scaleX(1)';
            
            closeImageModal();
            document.getElementById('sprite-studio-modal').style.display = 'block';
            
            // Make sprite sheet clickable for pose selection
            makespriteSheetClickable();
        }
        
        // Close Sprite Studio
        function closeSpriteStudio() {
            document.getElementById('sprite-studio-modal').style.display = 'none';
        }
        
        // Select pose for key
        function selectPoseForKey(key) {
            selectedKey = key;
            document.querySelectorAll('.mapping-btn').forEach(btn => btn.classList.remove('selected'));
            document.querySelector(`[data-key="${key}"]`).classList.add('selected');
        }
        
        // Make sprite sheet draggable for area selection
        function makespriteSheetClickable() {
            const img = document.getElementById('studio-sprite-sheet');
            const container = document.getElementById('sprite-container');
            const selectionBox = document.getElementById('selection-box');
            
            img.onmousedown = function(e) {
                if (!selectedKey) {
                    alert('Please select a keyboard key first (W, A, S, D, SPACE, or SHIFT)');
                    return;
                }
                
                e.preventDefault();
                isSelecting = true;
                
                const rect = container.getBoundingClientRect();
                selectionStart.x = e.clientX - rect.left;
                selectionStart.y = e.clientY - rect.top;
                
                selectionBox.style.left = selectionStart.x + 'px';
                selectionBox.style.top = selectionStart.y + 'px';
                selectionBox.style.width = '0px';
                selectionBox.style.height = '0px';
                selectionBox.style.display = 'block';
            };
            
            container.onmousemove = function(e) {
                if (!isSelecting) return;
                
                const rect = container.getBoundingClientRect();
                const currentX = e.clientX - rect.left;
                const currentY = e.clientY - rect.top;
                
                const width = Math.abs(currentX - selectionStart.x);
                const height = Math.abs(currentY - selectionStart.y);
                const left = Math.min(currentX, selectionStart.x);
                const top = Math.min(currentY, selectionStart.y);
                
                selectionBox.style.left = left + 'px';
                selectionBox.style.top = top + 'px';
                selectionBox.style.width = width + 'px';
                selectionBox.style.height = height + 'px';
            };
            
            container.onmouseup = function(e) {
                if (!isSelecting) return;
                
                isSelecting = false;
                
                const rect = container.getBoundingClientRect();
                const endX = e.clientX - rect.left;
                const endY = e.clientY - rect.top;
                
                // Calculate selection area relative to image
                const imgRect = img.getBoundingClientRect();
                const containerRect = container.getBoundingClientRect();
                
                const imgLeft = imgRect.left - containerRect.left;
                const imgTop = imgRect.top - containerRect.top;
                
                const selLeft = Math.max(0, Math.min(selectionStart.x, endX) - imgLeft);
                const selTop = Math.max(0, Math.min(selectionStart.y, endY) - imgTop);
                const selWidth = Math.abs(endX - selectionStart.x);
                const selHeight = Math.abs(endY - selectionStart.y);
                
                // Store selection data
                currentSelection = {
                    x: selLeft / img.offsetWidth,
                    y: selTop / img.offsetHeight,
                    width: selWidth / img.offsetWidth,
                    height: selHeight / img.offsetHeight
                };
                
                // Enable save button
                document.getElementById('save-pose-btn').disabled = false;
            };
        }
        
        // Create Game Character
        function createGameCharacter() {
            if (Object.keys(poseMappings).length === 0) {
                alert('Please assign at least one pose to a keyboard key');
                return;
            }
            
            const characterName = document.getElementById('character-name').value || 'My Character';
            const worldPrompt = document.getElementById('world-prompt').value || '';
            const btn = document.getElementById('create-game-btn');
            
            btn.disabled = true;
            btn.classList.add('loading');
            btn.setAttribute('data-loading-text', 'Generating Game World...');
            
            const formData = new FormData();
            formData.append('action', 'create_game_character');
            formData.append('sprite_sheet_id', currentSpriteData.id);
            formData.append('character_name', characterName);
            formData.append('world_prompt', worldPrompt);
            formData.append('pose_mappings', JSON.stringify(poseMappings));
            
            fetch('', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    showMessage('Game world created successfully!', 'success');
                    gameCharacter = {
                        id: data.id,
                        name: characterName,
                        spriteSheet: currentSpriteData,
                        poseMappings: poseMappings,
                        worldBackground: data.world_background
                    };
                    
                    closeSpriteStudio();
                    loadGameWorlds(); // Refresh the worlds gallery
                    loadGames(); // Refresh the games gallery
                    openGameWorld();
                } else {
                    showMessage(data.error || 'Failed to create game character', 'error');
                }
            })
            .catch(error => {
                console.error('Error:', error);
                showMessage('Network error occurred', 'error');
            })
            .finally(() => {
                btn.disabled = false;
                btn.classList.remove('loading');
                btn.removeAttribute('data-loading-text');
                btn.textContent = '🌍 Create Game World';
            });
        }
        
        // Game World Functions
        let gameCanvas, gameCtx;
        let character = { x: 100, y: 300, currentPose: 'space', isJumping: false, velocityY: 0 };
        
        function openGameWorld() {
            if (!gameCharacter) return;
            
            document.getElementById('game-world-modal').style.display = 'block';
            initializeGame();
        }
        
        function closeGameWorld() {
            document.getElementById('game-world-modal').style.display = 'none';
        }
        
        function initializeGame() {
            gameCanvas = document.getElementById('game-canvas');
            gameCtx = gameCanvas.getContext('2d');
            
            // Set background
            gameCanvas.style.backgroundImage = `url(data:image/png;base64,${gameCharacter.worldBackground})`;
            
            // Setup download button
            const downloadBtn = document.getElementById('download-game-btn');
            downloadBtn.onclick = () => downloadGame();
            
            // Load sprite sheet
            const spriteImg = new Image();
            spriteImg.onload = function() {
                gameCharacter.spriteImage = spriteImg;
                startGameLoop();
            };
            spriteImg.src = `data:image/png;base64,${gameCharacter.spriteSheet.image_data}`;
            
            // Add keyboard listeners
            document.addEventListener('keydown', handleKeyDown);
            document.addEventListener('keyup', handleKeyUp);
        }
        
        function handleKeyDown(e) {
            const key = e.key.toLowerCase();
            let mappedKey = key;
            
            if (key === ' ') mappedKey = 'space';
            
            if (gameCharacter.poseMappings[mappedKey]) {
                character.currentPose = mappedKey;
                
                // Handle movement
                switch(mappedKey) {
                    case 'a':
                        character.x = Math.max(0, character.x - 5);
                        break;
                    case 'd':
                        character.x = Math.min(gameCanvas.width - 64, character.x + 5);
                        break;
                    case 'w':
                        character.y = Math.max(0, character.y - 5);
                        break;
                    case 's':
                        character.y = Math.min(gameCanvas.height - 64, character.y + 5);
                        break;
                    case 'space':
                        if (!character.isJumping) {
                            character.isJumping = true;
                            character.velocityY = -15;
                        }
                        break;
                    case 'shift':
                        // Crouch - no movement
                        break;
                }
            }
        }
        
        function handleKeyUp(e) {
            const key = e.key.toLowerCase();
            let mappedKey = key;
            
            if (key === ' ') mappedKey = 'space';
            
            if (gameCharacter.poseMappings[mappedKey] && character.currentPose === mappedKey) {
                character.currentPose = 'space'; // Return to idle
            }
        }
        
        function startGameLoop() {
            function gameLoop() {
                // Clear canvas
                gameCtx.clearRect(0, 0, gameCanvas.width, gameCanvas.height);
                
                // Handle jumping physics
                if (character.isJumping) {
                    character.y += character.velocityY;
                    character.velocityY += 0.8; // Gravity
                    
                    if (character.y >= 300) { // Ground level
                        character.y = 300;
                        character.isJumping = false;
                        character.velocityY = 0;
                    }
                }
                
                // Draw character
                drawCharacter();
                
                requestAnimationFrame(gameLoop);
            }
            gameLoop();
        }
        
        function drawCharacter() {
            if (!gameCharacter.spriteImage || !gameCharacter.poseMappings[character.currentPose]) return;
            
            const pose = gameCharacter.poseMappings[character.currentPose];
            
            // Use area-based selection instead of grid-based
            const srcX = pose.x * gameCharacter.spriteImage.width;
            const srcY = pose.y * gameCharacter.spriteImage.height;
            const srcW = pose.width * gameCharacter.spriteImage.width;
            const srcH = pose.height * gameCharacter.spriteImage.height;
            
            gameCtx.drawImage(
                gameCharacter.spriteImage,
                srcX, srcY, srcW, srcH,
                character.x, character.y, 64, 64
            );
        }
        
        function downloadGameScreenshot() {
            // Create a temporary canvas to combine background and game content
            const tempCanvas = document.createElement('canvas');
            tempCanvas.width = gameCanvas.width;
            tempCanvas.height = gameCanvas.height;
            const tempCtx = tempCanvas.getContext('2d');
            
            // Load and draw background image
            const backgroundImg = new Image();
            backgroundImg.onload = function() {
                // Draw background
                tempCtx.drawImage(backgroundImg, 0, 0, tempCanvas.width, tempCanvas.height);
                
                // Draw the current game canvas content on top
                tempCtx.drawImage(gameCanvas, 0, 0);
                
                // Download the combined image
                const link = document.createElement('a');
                link.download = 'game-screenshot.png';
                link.href = tempCanvas.toDataURL();
                link.click();
            };
            backgroundImg.src = `data:image/png;base64,${gameCharacter.worldBackground}`;
        }
        
        function resetCharacterPosition() {
            character.x = 100;
            character.y = 300;
            character.currentPose = 'space';
            character.isJumping = false;
            character.velocityY = 0;
        }
        
        // Save selected pose
        function savePose() {
            if (!selectedKey || !currentSelection) {
                alert('Please select a key and make a selection on the sprite sheet');
                return;
            }
            
            // Store the mapping with area coordinates
            poseMappings[selectedKey] = {
                x: currentSelection.x,
                y: currentSelection.y,
                width: currentSelection.width,
                height: currentSelection.height
            };
            
            // Update the preview
            updatePosePreview(selectedKey, currentSelection);
            
            const keyName = selectedKey.toUpperCase();
            
            // Clear selection
            clearSelection();
            selectedKey = null;
            document.querySelectorAll('.mapping-btn').forEach(btn => btn.classList.remove('selected'));
            
            showMessage(`Pose saved for ${keyName}!`, 'success');
        }
        
        // Clear selection
        function clearSelection() {
            document.getElementById('selection-box').style.display = 'none';
            document.getElementById('save-pose-btn').disabled = true;
            currentSelection = null;
        }
        
        // Update pose preview
        function updatePosePreview(key, selection) {
            const preview = document.getElementById(`pose-${key}`);
            const canvas = document.createElement('canvas');
            const ctx = canvas.getContext('2d');
            canvas.width = 30;
            canvas.height = 30;
            
            const tempImg = new Image();
            tempImg.onload = function() {
                const srcX = selection.x * tempImg.width;
                const srcY = selection.y * tempImg.height;
                const srcW = selection.width * tempImg.width;
                const srcH = selection.height * tempImg.height;
                
                ctx.drawImage(tempImg, srcX, srcY, srcW, srcH, 0, 0, 30, 30);
                preview.style.backgroundImage = `url(${canvas.toDataURL()})`;
            };
            tempImg.src = `data:image/png;base64,${currentSpriteData.image_data}`;
        }
        
        // Download game
        function downloadGame() {
            if (!gameCharacter) {
                alert('No game character found');
                return;
            }
            
            const btn = event.target;
            btn.disabled = true;
            btn.classList.add('loading');
            btn.setAttribute('data-loading-text', 'Creating Game...');
            
            const formData = new FormData();
            formData.append('action', 'export_game');
            formData.append('character_id', gameCharacter.id);
            
            fetch('', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    // Download the zip file
                    const link = document.createElement('a');
                    link.href = 'data:application/zip;base64,' + data.zip_data;
                    link.download = data.filename;
                    document.body.appendChild(link);
                    link.click();
                    document.body.removeChild(link);
                    
                    showMessage('Game downloaded successfully!', 'success');
                    loadGames(); // Refresh games gallery
                } else {
                    showMessage(data.error || 'Failed to export game', 'error');
                }
            })
            .catch(error => {
                console.error('Error:', error);
                showMessage('Network error occurred', 'error');
            })
            .finally(() => {
                btn.disabled = false;
                btn.classList.remove('loading');
                btn.removeAttribute('data-loading-text');
                btn.textContent = '📦 Download Game';
            });
        }
        
        // Toggle flip
        function toggleFlip(flip) {
            isFlipped = flip;
            const img = document.getElementById('studio-sprite-sheet');
            const normalBtn = document.getElementById('flip-normal');
            const flipBtn = document.getElementById('flip-horizontal');
            
            if (flip) {
                img.style.transform = 'scaleX(-1)';
                normalBtn.classList.remove('active');
                flipBtn.classList.add('active');
            } else {
                img.style.transform = 'scaleX(1)';
                normalBtn.classList.add('active');
                flipBtn.classList.remove('active');
            }
        }
        
        // Play game from gallery
        function playGame(gameId) {
            const formData = new FormData();
            formData.append('action', 'play_game');
            formData.append('game_id', gameId);
            
            fetch('', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    // Open game in modal
                    openGameModal(gameId, data.game_html);
                } else {
                    showMessage(data.error || 'Failed to load game', 'error');
                }
            })
            .catch(error => {
                console.error('Error:', error);
                showMessage('Network error occurred', 'error');
            });
        }
        
        // Close modal when clicking outside
        window.onclick = function(event) {
            const settingsModal = document.getElementById('settings-modal');
            const imageModal = document.getElementById('image-modal');
            const spriteModal = document.getElementById('sprite-sheet-modal');
            const studioModal = document.getElementById('sprite-studio-modal');
            const gameModal = document.getElementById('game-world-modal');
            
            if (event.target === settingsModal) {
                closeSettings();
            } else if (event.target === imageModal) {
                closeImageModal();
            } else if (event.target === spriteModal) {
                closeSpriteSheetModal();
            } else if (event.target === studioModal) {
                closeSpriteStudio();
            } else if (event.target === gameModal) {
                closeGameWorld();
            }
        }
    </script>
</body>
</html>