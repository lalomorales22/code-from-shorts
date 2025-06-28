<?php
// API Handler for AJAX requests
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'generate_image') {
    header('Content-Type: application/json');
    
    // Allow cross-origin requests if needed
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Methods: POST, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type, Authorization');
    
    // Get POST data
    $data = json_decode(file_get_contents('php://input'), true);
    
    // Validate required fields
    if (!isset($data['prompt']) || empty($data['prompt'])) {
        http_response_code(400);
        echo json_encode(['error' => 'Prompt is required']);
        exit;
    }
    
    // Get API key from request or use default
    $apiKey = isset($data['apiKey']) && !empty($data['apiKey']) 
        ? $data['apiKey'] 
        : 'ENTER-API-KEY-HERE';
    
    // Set image type
    $outputFormat = isset($data['outputFormat']) ? $data['outputFormat'] : 'jpeg';
    
    // Call Stability AI API to generate the image
    $url = "https://api.stability.ai/v2beta/stable-image/generate/sd3";
    
    // Create multipart form data
    $postFields = [
        'prompt' => $data['prompt'],
        'output_format' => $outputFormat
    ];
    
    // Configure and execute cURL request
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        "Authorization: Bearer {$apiKey}",
        "Accept: image/*"
    ]);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $postFields);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    
    $response = curl_exec($ch);
    $statusCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $contentType = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
    $curlError = curl_error($ch);
    curl_close($ch);
    
    // Check for errors
    if ($response === false || $statusCode !== 200) {
        http_response_code(500);
        echo json_encode([
            'error' => 'Failed to generate image',
            'statusCode' => $statusCode,
            'curlError' => $curlError
        ]);
        exit;
    }
    
    // Determine if we need to save the image
    $saveImage = isset($data['saveImage']) && $data['saveImage'];
    $imagePath = null;
    
    if ($saveImage) {
        // Create uploads directory if it doesn't exist
        $uploadDir = 'uploads/';
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0755, true);
        }
        
        // Generate a unique filename
        $imageType = $outputFormat === 'jpeg' ? 'jpg' : $outputFormat;
        $filename = uniqid('img_') . '.' . $imageType;
        $filePath = $uploadDir . $filename;
        
        // Save the image
        if (file_put_contents($filePath, $response)) {
            $imagePath = $filePath;
        }
    }
    
    // Return a data URI if not saving the file
    if (!$saveImage) {
        $mimeType = $outputFormat === 'jpeg' ? 'image/jpeg' : 'image/png';
        $base64Image = base64_encode($response);
        echo json_encode([
            'success' => true,
            'imageData' => "data:{$mimeType};base64,{$base64Image}"
        ]);
    } else {
        // Return the path to the saved image
        echo json_encode([
            'success' => true,
            'imagePath' => $imagePath
        ]);
    }
    exit;
}

// Handle preflight OPTIONS request
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Methods: POST, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type, Authorization');
    exit(0);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>pnguin stereogram v5.0 | P3N6U1N</title>
  <style>
    /* Reset defaults */
    body, h2, h3, h4, label, button, input, textarea {
      margin: 0;
      padding: 0;
      font-family: 'Source Code Pro', 'Courier New', monospace;
      font-weight: 300;
    }
    :root {
      --bg-color: #000;
      --text-color: #ccc;
      --accent-color: #555;
      --border-color: #777;
      --hover-color: #333;
      --light-accent: #aaa;
      --matrix-color: #ddd;
      --success-color: #48a346;
      --error-color: #a34846;
      --sidebar-width: 340px;
      --scrollbar-width: 6px;
    }
    body {
      background-color: var(--bg-color);
      color: var(--text-color);
      overflow: hidden;
      height: 100vh;
      width: 100vw;
    }
    .container {
      display: flex;
      height: 100vh;
      width: 100vw;
      position: relative;
    }
    
    /* Improved scrollbar styling */
    ::-webkit-scrollbar {
      width: var(--scrollbar-width);
      height: var(--scrollbar-width);
    }
    ::-webkit-scrollbar-track {
      background: var(--bg-color);
    }
    ::-webkit-scrollbar-thumb {
      background: var(--accent-color);
      border-radius: 3px;
    }
    ::-webkit-scrollbar-thumb:hover {
      background: var(--light-accent);
    }
    
    /* Sidebar controls with improved layout */
    .controls {
      width: var(--sidebar-width);
      background-color: var(--bg-color);
      border-right: 2px solid var(--border-color);
      height: 100%;
      position: relative;
      box-shadow: 0 0 10px var(--accent-color);
    }
    
    .controls-inner {
      height: 100%;
      overflow-y: auto;
      overflow-x: hidden;
      padding: 20px;
      box-sizing: border-box;
    }
    
    .controls h2, .controls h3, .controls h4 {
      margin-bottom: 15px;
      text-align: center;
      text-transform: uppercase;
      letter-spacing: 2px;
      color: var(--light-accent);
    }
    
    .controls h2 {
      font-size: 22px;
      margin-bottom: 20px;
    }
    
    .controls h3 {
      font-size: 18px;
      margin-top: 25px;
      background-color: var(--accent-color);
      padding: 8px 5px;
      border-radius: 3px;
    }
    
    .controls h4 {
      font-size: 16px;
      margin-top: 15px;
      color: var(--text-color);
    }
    
    /* Collapsible sections */
    .collapsible {
      margin-bottom: 15px;
    }
    
    .collapsible-header {
      background-color: var(--accent-color);
      padding: 8px 10px;
      cursor: pointer;
      border-radius: 3px;
      display: flex;
      justify-content: space-between;
      align-items: center;
      user-select: none;
    }
    
    .collapsible-header:hover {
      background-color: var(--hover-color);
    }
    
    .collapsible-header h3 {
      margin: 0;
      background-color: transparent;
      padding: 0;
    }
    
    .collapsible-header .toggle-icon {
      transition: transform 0.3s;
    }
    
    .collapsible-header.active .toggle-icon {
      transform: rotate(180deg);
    }
    
    .collapsible-content {
      max-height: 0;
      overflow: hidden;
      transition: max-height 0.3s ease-out;
      background-color: rgba(40, 40, 40, 0.5);
      border-radius: 0 0 3px 3px;
    }
    
    .collapsible-inner {
      padding: 10px;
    }
    
    .control-group {
      margin-bottom: 15px;
    }
    
    .control-group label {
      display: block;
      margin-bottom: 5px;
      font-weight: bold;
      text-transform: uppercase;
      color: var(--text-color);
    }
    
    .control-group label .tooltip-icon {
      display: inline-block;
      margin-left: 5px;
      width: 16px;
      height: 16px;
      background-color: var(--accent-color);
      color: var(--text-color);
      border-radius: 50%;
      text-align: center;
      line-height: 16px;
      font-size: 12px;
      cursor: help;
      position: relative;
    }
    
    .tooltip-text {
      visibility: hidden;
      width: 200px;
      background-color: var(--accent-color);
      color: var(--text-color);
      text-align: center;
      border-radius: 5px;
      padding: 5px;
      position: absolute;
      z-index: 1;
      bottom: 125%;
      left: 50%;
      transform: translateX(-50%);
      opacity: 0;
      transition: opacity 0.3s;
      font-weight: normal;
      text-transform: none;
      font-size: 12px;
      pointer-events: none;
    }
    
    .tooltip-icon:hover .tooltip-text {
      visibility: visible;
      opacity: 1;
    }
    
    input[type="file"], input[type="range"], textarea, select {
      width: 100%;
      background-color: var(--bg-color);
      color: var(--text-color);
      border: 1px solid var(--border-color);
      padding: 5px;
      box-sizing: border-box;
    }
    
    input[type="text"] {
      width: 100%;
      background-color: var(--bg-color);
      color: var(--text-color);
      border: 1px solid var(--border-color);
      padding: 5px;
      box-sizing: border-box;
    }
    
    input[type="range"] {
      -webkit-appearance: none;
      background: var(--accent-color);
      height: 5px;
    }
    
    input[type="range"]::-webkit-slider-thumb {
      -webkit-appearance: none;
      width: 15px;
      height: 15px;
      background: var(--light-accent);
      cursor: pointer;
      border-radius: 50%;
    }
    
    .slider-value {
      float: right;
      font-weight: normal;
      color: var(--text-color);
    }
    
    /* Preview area with improved positioning */
    .preview-container {
      flex: 1;
      display: flex;
      flex-direction: column;
      height: 100%;
      overflow: hidden;
      position: relative;
    }
    
    .preview-tabs {
      display: flex;
      background-color: var(--accent-color);
      border-bottom: 1px solid var(--border-color);
    }
    
    .preview-tab {
      padding: 10px 15px;
      cursor: pointer;
      font-weight: bold;
      text-transform: uppercase;
      transition: all 0.2s;
    }
    
    .preview-tab:hover {
      background-color: var(--hover-color);
    }
    
    .preview-tab.active {
      background-color: var(--hover-color);
      border-bottom: 2px solid var(--light-accent);
    }
    
    .preview-content {
      flex: 1;
      display: none;
      align-items: center;
      justify-content: center;
      overflow: auto;
      padding: 20px;
    }
    
    .preview-content.active {
      display: flex;
    }
    
    .image-preview {
      position: relative;
      display: flex;
      align-items: center;
      justify-content: center;
      max-width: 100%;
      max-height: 100%;
    }
    
    .image-preview img {
      max-width: 100%;
      max-height: 80vh;
      border: 2px solid var(--border-color);
      box-shadow: 0 0 15px var(--accent-color);
    }
    
    canvas {
      max-width: 100%;
      max-height: 85vh;
      border: 2px solid var(--border-color);
      box-shadow: 0 0 15px var(--accent-color);
    }
    
    button {
      width: 100%;
      padding: 10px;
      margin-top: 10px;
      background-color: var(--accent-color);
      color: var(--text-color);
      border: 1px solid var(--border-color);
      cursor: pointer;
      text-transform: uppercase;
      transition: all 0.3s;
    }
    
    button:hover {
      background-color: var(--hover-color);
      color: var(--light-accent);
      box-shadow: 0 0 10px var(--light-accent);
    }
    
    .ai-option {
      padding: 8px;
      margin-top: 10px;
      border: 1px solid var(--border-color);
      border-radius: 3px;
      cursor: pointer;
      transition: all 0.3s;
    }
    
    .ai-option:hover {
      background-color: var(--hover-color);
    }
    
    .ai-option input {
      margin-right: 8px;
    }
    
    .preview-thumbnail {
      width: 100%;
      height: 150px;
      background-color: var(--bg-color);
      border: 1px solid var(--border-color);
      display: flex;
      align-items: center;
      justify-content: center;
      margin-top: 5px;
      position: relative;
      overflow: hidden;
    }
    
    .preview-thumbnail img {
      max-width: 100%;
      max-height: 100%;
      object-fit: contain;
    }
    
    .preview-placeholder {
      color: var(--accent-color);
      text-align: center;
      font-style: italic;
    }
    
    #loading {
      display: none;
      position: absolute;
      top: 50%;
      left: 50%;
      transform: translate(-50%, -50%);
      background-color: rgba(0, 0, 0, 0.8);
      padding: 20px;
      border-radius: 10px;
      z-index: 100;
      text-align: center;
    }
    
    #loading .spinner {
      border: 5px solid var(--accent-color);
      border-top: 5px solid var(--light-accent);
      border-radius: 50%;
      width: 50px;
      height: 50px;
      animation: spin 1s linear infinite;
      margin: 0 auto 15px auto;
    }
    
    @keyframes spin {
      0% { transform: rotate(0deg); }
      100% { transform: rotate(360deg); }
    }
    
    #matrixBg {
      position: fixed;
      top: 0;
      left: 0;
      width: 100%;
      height: 100%;
      z-index: -1;
    }
    
    .modal {
      display: none;
      position: fixed;
      top: 0;
      left: 0;
      width: 100%;
      height: 100%;
      background: rgba(0, 0, 0, 0.7);
      z-index: 1000;
    }
    
    .modal > div {
      background: var(--bg-color);
      padding: 20px;
      margin: 15% auto;
      width: 80%;
      max-width: 600px;
      border: 2px solid var(--border-color);
      box-shadow: 0 0 20px var(--accent-color);
      color: var(--text-color);
    }
    
    #notification {
      display: none;
      position: fixed;
      top: 20px;
      right: 20px;
      padding: 15px;
      background-color: var(--success-color);
      color: white;
      border-radius: 5px;
      box-shadow: 0 0 10px rgba(0,0,0,0.5);
      z-index: 9999;
    }
    
    #notification.error {
      background-color: var(--error-color);
    }
    
    /* Help button */
    .help-button {
      position: absolute;
      top: 10px;
      right: 10px;
      width: 30px;
      height: 30px;
      background-color: var(--accent-color);
      color: var(--text-color);
      border-radius: 50%;
      display: flex;
      align-items: center;
      justify-content: center;
      cursor: pointer;
      font-weight: bold;
      z-index: 10;
    }
    
    .help-button:hover {
      background-color: var(--hover-color);
      color: var(--light-accent);
    }
    
    /* Input tab styling */
    .input-tab-content {
      display: none;
    }
    
    .input-tab-content.active {
      display: block;
    }
    
    /* Responsive tweaks */
    @media (max-width: 768px) {
      .container {
        flex-direction: column;
      }
      
      .controls {
        width: 100%;
        height: auto;
        max-height: 50vh;
        border-right: none;
        border-bottom: 2px solid var(--border-color);
      }
      
      .preview-container {
        height: 50vh;
      }
    }
  </style>
</head>
<body>
  <!-- Matrix Rain Background -->
  <canvas id="matrixBg"></canvas>
  
  <div id="notification"></div>
  
  <div class="container">
    <!-- Sidebar Controls -->
    <div class="controls">
      <div class="controls-inner">
        <h2>pnguin stereogram v5.0</h2>
        
        <div class="collapsible">
          <div class="collapsible-header active">
            <h3>Input Images</h3>
            <span class="toggle-icon">▼</span>
          </div>
          <div class="collapsible-content" style="max-height: 1000px;">
            <div class="collapsible-inner">
              <div class="preview-tabs" style="margin-bottom: 15px;">
                <div class="preview-tab active" data-input-tab="manual">Manual</div>
                <div class="preview-tab" data-input-tab="ai">AI Gen</div>
              </div>
              
              <!-- Manual Tab -->
              <div id="manualInputTab" class="input-tab-content active">
                <div class="control-group">
                  <label for="depthMap">
                    Depth Map [SYS_INPUT]
                    <span class="tooltip-icon">?
                      <span class="tooltip-text">Upload a grayscale image where white areas appear closer and black areas appear further away</span>
                    </span>
                  </label>
                  <input type="file" id="depthMap" accept="image/*">
                  <div class="preview-thumbnail" id="depthMapContainer">
                    <div class="preview-placeholder">No depth map selected</div>
                  </div>
                </div>
                
                <div class="control-group">
                  <label for="pattern">
                    Pattern [CRYPT_SRC]
                    <span class="tooltip-icon">?
                      <span class="tooltip-text">Upload a repeating pattern image that will be used to create the stereogram</span>
                    </span>
                  </label>
                  <input type="file" id="pattern" accept="image/*">
                  <div class="preview-thumbnail" id="patternContainer">
                    <div class="preview-placeholder">No pattern selected</div>
                  </div>
                </div>
              </div>
              
              <!-- AI Tab -->
              <div id="aiInputTab" class="input-tab-content" style="display: none;">
                <div class="control-group">
                  <label for="aiPrompt">
                    AI Prompt
                    <span class="tooltip-icon">?
                      <span class="tooltip-text">Describe what you want the AI to generate</span>
                    </span>
                  </label>
                  <textarea id="aiPrompt" rows="3" placeholder="Describe what you want to generate..."></textarea>
                </div>
                
                <div class="control-group">
                  <label>Generate As:</label>
                  <div class="ai-option">
                    <input type="radio" id="genDepthMap" name="genType" value="depthMap" checked>
                    <label for="genDepthMap">Depth Map</label>
                  </div>
                  <div class="ai-option">
                    <input type="radio" id="genPattern" name="genType" value="pattern">
                    <label for="genPattern">Pattern</label>
                  </div>
                </div>
                
                <div class="control-group">
                  <button id="generateAIBtn">Generate with AI</button>
                </div>
              </div>
            </div>
          </div>
        </div>
        
        <div class="collapsible">
          <div class="collapsible-header">
            <h3>Stereogram Settings</h3>
            <span class="toggle-icon">▼</span>
          </div>
          <div class="collapsible-content">
            <div class="collapsible-inner">
              <div class="control-group">
                <label for="shiftStrength">
                  Shift Strength <span id="shiftStrengthValue" class="slider-value">10</span>
                  <span class="tooltip-icon">?
                    <span class="tooltip-text">Controls the depth effect intensity - higher values create more pronounced 3D effect</span>
                  </span>
                </label>
                <input type="range" id="shiftStrength" min="0" max="50" value="10">
              </div>
              
              <div class="control-group">
                <label for="patternScale">
                  Pattern Scale <span id="patternScaleValue" class="slider-value">1</span>
                  <span class="tooltip-icon">?
                    <span class="tooltip-text">Adjusts the size of the repeating pattern</span>
                  </span>
                </label>
                <input type="range" id="patternScale" min="0.5" max="2" step="0.1" value="1">
              </div>
              
              <div class="control-group">
                <label for="depthContrast">
                  Depth Contrast <span id="depthContrastValue" class="slider-value">1</span>
                  <span class="tooltip-icon">?
                    <span class="tooltip-text">Enhances or reduces the contrast in the depth map</span>
                  </span>
                </label>
                <input type="range" id="depthContrast" min="0.1" max="2" step="0.1" value="1">
              </div>
            </div>
          </div>
        </div>
        
        <div class="collapsible">
          <div class="collapsible-header">
            <h3>Steganography</h3>
            <span class="toggle-icon">▼</span>
          </div>
          <div class="collapsible-content">
            <div class="collapsible-inner">
              <div class="control-group">
                <label for="hiddenMessage">
                  Hidden Payload [SHADOW_DATA]
                  <span class="tooltip-icon">?
                    <span class="tooltip-text">Secret message to embed within the stereogram image</span>
                  </span>
                </label>
                <textarea id="hiddenMessage" rows="3" placeholder="Enter cryptic payload..."></textarea>
                <label><input type="checkbox" id="enableStego" checked> Enable Steganography</label>
              </div>
            </div>
          </div>
        </div>
        
        <div class="collapsible">
          <div class="collapsible-header">
            <h3>Output Settings</h3>
            <span class="toggle-icon">▼</span>
          </div>
          <div class="collapsible-content">
            <div class="collapsible-inner">
              <div class="control-group">
                <label for="formatSelect">Output Format</label>
                <select id="formatSelect">
                  <option value="png">PNG</option>
                  <option value="jpeg">JPEG</option>
                </select>
                <div id="jpegQualityGroup" style="display:none;">
                  <label for="jpegQuality">JPEG Quality <span id="jpegQualityValue">0.8</span></label>
                  <input type="range" id="jpegQuality" min="0" max="1" step="0.1" value="0.8">
                </div>
              </div>
            </div>
          </div>
        </div>
        
        <div class="collapsible">
          <div class="collapsible-header">
            <h3>API Settings</h3>
            <span class="toggle-icon">▼</span>
          </div>
          <div class="collapsible-content">
            <div class="collapsible-inner">
              <div class="control-group">
                <label for="apiKey">Stability AI API Key</label>
                <input type="text" id="apiKey" placeholder="Enter your API key...">
                <small>Default key: ENTER-API-KEY-HERE</small>
              </div>
            </div>
          </div>
        </div>
        
        <div class="control-group" style="margin-top: 20px;">
          <button id="generateBtn">Execute Stereogram</button>
          <button id="downloadBtn">Extract Output</button>
          <button id="decodeHiddenBtn">Decrypt Payload</button>
        </div>
      </div>
    </div>
    
    <!-- Main Preview Area -->
    <div class="preview-container">
      <div class="preview-tabs">
        <div class="preview-tab active" data-preview="result">Final Result</div>
        <div class="preview-tab" data-preview="depth">Depth Map</div>
        <div class="preview-tab" data-preview="pattern">Pattern</div>
      </div>
      
      <div id="resultPreview" class="preview-content active">
        <div class="image-preview">
          <canvas id="stereogramCanvas"></canvas>
        </div>
      </div>
      
      <div id="depthPreview" class="preview-content">
        <div class="image-preview">
          <img id="depthPreviewImg" style="display: none;">
          <div id="depthPlaceholder" class="preview-placeholder">No depth map available</div>
        </div>
      </div>
      
      <div id="patternPreview" class="preview-content">
        <div class="image-preview">
          <img id="patternPreviewImg" style="display: none;">
          <div id="patternPlaceholder" class="preview-placeholder">No pattern available</div>
        </div>
      </div>
      
      <div id="loading">
        <div class="spinner"></div>
        <div id="loadingText">[SYS] Processing...</div>
      </div>
      
      <div class="help-button" id="helpButton">?</div>
    </div>
  </div>

  <!-- Decode Modal -->
  <div id="decodeModal" class="modal">
    <div>
      <h2>Decrypt Hidden Payload</h2>
      <input type="file" id="decodeImage" accept="image/*">
      <button id="decodeBtn">Run Decryptor</button>
      <textarea id="decodedMessage" rows="5" readonly placeholder="Decrypted data will appear here..."></textarea>
      <button id="closeDecodeModal">Exit</button>
    </div>
  </div>
  
  <!-- Help Modal -->
  <div id="helpModal" class="modal">
    <div>
      <h2>Stereogram Guide</h2>
      <p>Stereograms are images that create the illusion of 3D when viewed correctly. To see the 3D effect:</p>
      <ol>
        <li>Position your face close to the screen</li>
        <li>Relax your eyes as if looking through the image at a distant point</li>
        <li>Slowly move back from the screen while maintaining the relaxed gaze</li>
        <li>The hidden 3D image should gradually appear</li>
      </ol>
      <h3>Controls Explained</h3>
      <p><strong>Depth Map:</strong> Controls which parts of the image appear to be closer or further away. White areas appear closest, black areas furthest.</p>
      <p><strong>Pattern:</strong> The repeating texture used to create the stereogram effect.</p>
      <p><strong>Shift Strength:</strong> Controls how pronounced the 3D effect is. Higher values create more depth but may be harder to view.</p>
      <p><strong>AI Generation:</strong> Creates depth maps or patterns using Stability AI.</p>
      <p><strong>Steganography:</strong> Hides secret messages within the image that can be extracted later.</p>
      <button id="closeHelpModal">Close Guide</button>
    </div>
  </div>

<script>
/**
 * API Handler for Stability AI requests
 * Enhanced version with improved error handling and progress tracking
 */

class StabilityAPIHandler {
    constructor(defaultApiKey = 'ENTER-API-KEY-HERE') {
      this.defaultApiKey = defaultApiKey;
      this.apiEndpoint = window.location.href; // Use current page for API calls
      this.isGenerating = false;
    }
  
    /**
     * Generate an image using Stability AI API with improved error handling
     * @param {string} prompt - The text prompt to generate an image from
     * @param {string} apiKey - Optional API key (uses default if not provided)
     * @param {string} outputFormat - Image format ('jpeg' or 'png')
     * @param {function} onProgress - Optional callback for progress updates
     * @returns {Promise<Object>} - Promise resolving to { success: true, imageData: "data:..." }
     */
    async generateImage(prompt, apiKey = null, outputFormat = 'jpeg', onProgress = null) {
      // Validate inputs
      if (!prompt || prompt.trim() === '') {
        throw new Error('Prompt is required');
      }
      
      if (this.isGenerating) {
        throw new Error('Another generation is already in progress');
      }
      
      this.isGenerating = true;
      const key = apiKey || this.defaultApiKey;
      
      // Notify about progress
      if (onProgress) onProgress({ 
        stage: 'start', 
        message: 'Starting API request...',
        progress: 0.1
      });
      
      try {
        if (onProgress) onProgress({ 
          stage: 'sending', 
          message: 'Sending request to Stability AI...',
          progress: 0.2
        });
        
        // Call the API through our PHP endpoint
        const response = await fetch(this.apiEndpoint, {
          method: 'POST',
          headers: {
            'Content-Type': 'application/json',
          },
          body: JSON.stringify({
            action: 'generate_image',
            prompt: prompt,
            apiKey: key,
            outputFormat: outputFormat
          })
        });
        
        if (!response.ok) {
          let errorMsg = `API error (${response.status})`;
          
          try {
            const errorBody = await response.text();
            errorMsg = `${errorMsg}: ${errorBody}`;
          } catch (e) {
            // If we can't parse the error body, just use the status code
          }
          
          throw new Error(errorMsg);
        }
        
        if (onProgress) onProgress({ 
          stage: 'processing', 
          message: 'Processing image data...',
          progress: 0.7
        });
        
        const result = await response.json();
        
        if (!result.success) {
          throw new Error(result.error || 'Unknown error occurred');
        }
        
        if (onProgress) onProgress({ 
          stage: 'complete', 
          message: 'Image generation complete',
          progress: 1.0
        });
        
        this.isGenerating = false;
        return result;
        
      } catch (error) {
        this.isGenerating = false;
        console.error('Error generating image:', error);
        return {
          success: false,
          error: error.message || 'Unknown error occurred'
        };
      }
    }
  
    /**
     * Enhanced version for specialized depth map generation
     * @param {string} prompt - Base prompt to describe the scene/object
     * @param {string} apiKey - Optional API key
     * @param {boolean} isDepthMap - Whether to optimize prompt for depth map generation
     * @param {function} onProgress - Optional progress callback
     * @returns {Promise<Object>} - Promise resolving to image data
     */
    async generateSpecializedImage(prompt, apiKey = null, isDepthMap = false, onProgress = null) {
      let finalPrompt = prompt;
      
      // Enhance the prompt for depth map generation with improved instructions
      if (isDepthMap) {
        finalPrompt = `Depth map for ${prompt}. Clear grayscale image where white areas represent objects closest to the viewer and black areas represent the background or distant objects. Please create strong contrast between foreground and background elements with smooth gradients for mid-range depths. The depth map should clearly define object boundaries and spatial relationships.`;
      } else {
        // For pattern generation, optimize for repeating seamless textures
        finalPrompt = `${prompt}. Create a seamless repeating pattern with medium contrast and subtle details. Ensure the pattern has no obvious seams when tiled and has good variance in texture while maintaining consistency.`;
      }
      
      return this.generateImage(finalPrompt, apiKey, 'jpeg', onProgress);
    }
    
    /**
     * Generate random patterns specifically optimized for stereograms
     * @param {string} style - Style description (e.g., "geometric", "natural", "abstract")
     * @param {string} apiKey - Optional API key
     * @param {function} onProgress - Optional progress callback
     * @returns {Promise<Object>} - Promise resolving to image data
     */
    async generatePattern(style, apiKey = null, onProgress = null) {
      const patternPrompt = `Seamless repeating pattern for stereogram with ${style} style. Create a texture with medium contrast, no distinct focal points, and a balanced distribution of elements. The pattern should be subtle enough not to distract from the 3D effect, yet detailed enough to make the stereogram work effectively. Ensure the pattern can be tiled without visible seams.`;
      return this.generateImage(patternPrompt, apiKey, 'jpeg', onProgress);
    }
    
    /**
     * Cancels any current generation if possible
     * @returns {boolean} - True if a generation was canceled, false otherwise
     */
    cancelGeneration() {
      if (!this.isGenerating) {
        return false;
      }
      
      this.isGenerating = false;
      return true;
    }
}

/**
 * Stereogram.js - Core functionality for the PNGUIN Stereogram Generator
 * Version 5.0
 */

// Initialize the API handler
const apiHandler = new StabilityAPIHandler();

// Core Variables
let depthImg = null;
let patternImg = null;
const canvas = document.getElementById('stereogramCanvas');
const ctx = canvas.getContext('2d');
let defaultApiKey = "ENTER-API-KEY-HERE";
let isGenerating = false;

// DOM Elements - Input Controls
const depthInput = document.getElementById('depthMap');
const patternInput = document.getElementById('pattern');
const shiftStrengthSlider = document.getElementById('shiftStrength');
const patternScaleSlider = document.getElementById('patternScale');
const depthContrastSlider = document.getElementById('depthContrast');
const shiftStrengthValue = document.getElementById('shiftStrengthValue');
const patternScaleValue = document.getElementById('patternScaleValue');
const depthContrastValue = document.getElementById('depthContrastValue');
const hiddenMessageInput = document.getElementById('hiddenMessage');
const enableStegoCheckbox = document.getElementById('enableStego');
const formatSelect = document.getElementById('formatSelect');
const jpegQualityGroup = document.getElementById('jpegQualityGroup');
const jpegQualitySlider = document.getElementById('jpegQuality');
const jpegQualityValue = document.getElementById('jpegQualityValue');
const apiKeyInput = document.getElementById('apiKey');
const aiPromptInput = document.getElementById('aiPrompt');
const generateAIBtn = document.getElementById('generateAIBtn');

// DOM Elements - Preview
const depthMapContainer = document.getElementById('depthMapContainer');
const patternContainer = document.getElementById('patternContainer');
const depthPreviewImg = document.getElementById('depthPreviewImg');
const patternPreviewImg = document.getElementById('patternPreviewImg');
const depthPlaceholder = document.getElementById('depthPlaceholder');
const patternPlaceholder = document.getElementById('patternPlaceholder');

// DOM Elements - Loading and notifications
const loadingElement = document.getElementById('loading');
const loadingText = document.getElementById('loadingText');

// Matrix Rain Effect Initialization
initializeMatrixEffect();

// Tab Navigation for Input Method (Manual/AI)
initializeInputTabs();

// Preview Tabs (Result/Depth/Pattern)
initializePreviewTabs();

// Collapsible Sections
initializeCollapsibles();

// Initialize Help Button
initializeHelp();

// Event Listeners for File Inputs
initializeFileInputs();

// Event Listeners for Sliders
initializeSliders();

// Event Listeners for Buttons
initializeButtons();

// Event Listener for Format Select
formatSelect.addEventListener('change', () => {
  jpegQualityGroup.style.display = formatSelect.value === 'jpeg' ? 'block' : 'none';
});

// Initialize with default API key
window.addEventListener('DOMContentLoaded', () => {
  apiKeyInput.value = defaultApiKey;
});

/**
 * Matrix Rain Effect (Grey Themed)
 */
function initializeMatrixEffect() {
  const matrixCanvas = document.getElementById('matrixBg');
  const matrixCtx = matrixCanvas.getContext('2d');
  
  // Set canvas dimensions and adjust on window resize
  function resizeMatrixCanvas() {
    matrixCanvas.width = window.innerWidth;
    matrixCanvas.height = window.innerHeight;
  }
  
  resizeMatrixCanvas();
  window.addEventListener('resize', resizeMatrixCanvas);
  
  const columns = Math.floor(matrixCanvas.width / 20);
  const drops = Array(columns).fill(0);
  
  function drawMatrix() {
    matrixCtx.fillStyle = 'rgba(0, 0, 0, 0.05)';
    matrixCtx.fillRect(0, 0, matrixCanvas.width, matrixCanvas.height);
    matrixCtx.fillStyle = '#aaa';  // Light grey matrix rain
    matrixCtx.font = '15px monospace';
    
    drops.forEach((y, index) => {
      const text = String.fromCharCode(Math.floor(Math.random() * 128));
      const x = index * 20;
      matrixCtx.fillText(text, x, y);
      drops[index] = y > 100 + Math.random() * 10000 ? 0 : y + 20;
    });
  }
  
  setInterval(drawMatrix, 50);
}

/**
 * Initialize Input Method Tabs (Manual/AI)
 */
function initializeInputTabs() {
  const inputTabs = document.querySelectorAll('[data-input-tab]');
  const inputContents = document.querySelectorAll('.input-tab-content');
  
  inputTabs.forEach(tab => {
    tab.addEventListener('click', () => {
      const tabName = tab.getAttribute('data-input-tab');
      
      // Remove active class from all tabs and contents
      inputTabs.forEach(t => t.classList.remove('active'));
      inputContents.forEach(c => c.style.display = 'none');
      
      // Add active class to clicked tab and show its content
      tab.classList.add('active');
      document.getElementById(`${tabName}InputTab`).style.display = 'block';
    });
  });
}

/**
 * Initialize Preview Tabs (Result/Depth/Pattern)
 */
function initializePreviewTabs() {
  const previewTabs = document.querySelectorAll('[data-preview]');
  const previewContents = document.querySelectorAll('.preview-content');
  
  previewTabs.forEach(tab => {
    tab.addEventListener('click', () => {
      const previewName = tab.getAttribute('data-preview');
      
      // Remove active class from all tabs and contents
      previewTabs.forEach(t => t.classList.remove('active'));
      previewContents.forEach(c => c.classList.remove('active'));
      
      // Add active class to clicked tab and its content
      tab.classList.add('active');
      document.getElementById(`${previewName}Preview`).classList.add('active');
    });
  });
}

/**
 * Initialize Collapsible Sections
 */
function initializeCollapsibles() {
  const collapsibles = document.querySelectorAll('.collapsible-header');
  
  collapsibles.forEach(header => {
    header.addEventListener('click', () => {
      header.classList.toggle('active');
      const content = header.nextElementSibling;
      
      if (header.classList.contains('active')) {
        content.style.maxHeight = '1000px'; // Set a large value to accommodate any content
      } else {
        content.style.maxHeight = '0';
      }
    });
  });
}

/**
 * Initialize Help Button and Modal
 */
function initializeHelp() {
  const helpButton = document.getElementById('helpButton');
  const helpModal = document.getElementById('helpModal');
  const closeHelpModal = document.getElementById('closeHelpModal');
  
  helpButton.addEventListener('click', () => {
    helpModal.style.display = 'block';
  });
  
  closeHelpModal.addEventListener('click', () => {
    helpModal.style.display = 'none';
  });
  
  // Close modal when clicking outside
  window.addEventListener('click', (event) => {
    if (event.target === helpModal) {
      helpModal.style.display = 'none';
    }
  });
}

/**
 * Initialize File Input Handlers
 */
function initializeFileInputs() {
  depthInput.addEventListener('change', function() {
    const file = this.files[0];
    if (!file) return;
    
    depthImg = new Image();
    depthImg.onload = () => {
      // Update preview
      updateImagePreview(depthMapContainer, depthImg.src);
      updateImagePreview(depthPreviewImg, depthImg.src, depthPlaceholder);
      
      // Generate stereogram if both images are loaded
      if (patternImg) {
        canvas.width = depthImg.width;
        canvas.height = depthImg.height;
        generateStereogram();
      }
    };
    depthImg.src = URL.createObjectURL(file);
  });
  
  patternInput.addEventListener('change', function() {
    const file = this.files[0];
    if (!file) return;
    
    patternImg = new Image();
    patternImg.onload = () => {
      // Update preview
      updateImagePreview(patternContainer, patternImg.src);
      updateImagePreview(patternPreviewImg, patternImg.src, patternPlaceholder);
      
      // Generate stereogram if both images are loaded
      if (depthImg) {
        canvas.width = depthImg.width;
        canvas.height = depthImg.height;
        generateStereogram();
      }
    };
    patternImg.src = URL.createObjectURL(file);
  });
}

/**
 * Update Image Preview
 * @param {HTMLElement} container - Container element for the image/thumbnail
 * @param {string} src - Image source URL
 * @param {HTMLElement} placeholder - Optional placeholder element to hide
 */
function updateImagePreview(container, src, placeholder = null) {
  if (container instanceof HTMLImageElement) {
    // If container is an image element
    container.src = src;
    container.style.display = 'block';
    if (placeholder) placeholder.style.display = 'none';
  } else {
    // If container is a div containing a placeholder
    container.innerHTML = '';
    const img = document.createElement('img');
    img.src = src;
    container.appendChild(img);
  }
}

/**
 * Initialize Slider Controls
 */
function initializeSliders() {
  shiftStrengthSlider.addEventListener('input', () => {
    shiftStrengthValue.textContent = shiftStrengthSlider.value;
    debouncedGenerate();
  });
  
  patternScaleSlider.addEventListener('input', () => {
    patternScaleValue.textContent = patternScaleSlider.value;
    debouncedGenerate();
  });
  
  depthContrastSlider.addEventListener('input', () => {
    depthContrastValue.textContent = depthContrastSlider.value;
    debouncedGenerate();
  });
  
  jpegQualitySlider.addEventListener('input', () => {
    jpegQualityValue.textContent = jpegQualitySlider.value;
  });
}

/**
 * Initialize Button Event Handlers
 */
function initializeButtons() {
  // Generate Stereogram
  document.getElementById('generateBtn').addEventListener('click', generateStereogram);
  
  // Download Button
  document.getElementById('downloadBtn').addEventListener('click', downloadStereogram);
  
  // Decrypt Modal
  document.getElementById('decodeHiddenBtn').addEventListener('click', () => {
    document.getElementById('decodeModal').style.display = 'block';
  });
  
  document.getElementById('decodeBtn').addEventListener('click', decodeHiddenMessage);
  
  document.getElementById('closeDecodeModal').addEventListener('click', () => {
    document.getElementById('decodeModal').style.display = 'none';
  });
  
  // AI Generation
  generateAIBtn.addEventListener('click', generateWithAI);
}

/**
 * Download generated stereogram
 */
function downloadStereogram() {
  if (!canvas.width || !canvas.height) {
    showNotification("No stereogram has been generated yet", true);
    return;
  }
  
  const format = formatSelect.value;
  const mimeType = format === 'jpeg' ? 'image/jpeg' : 'image/png';
  const quality = format === 'jpeg' ? parseFloat(jpegQualitySlider.value) : 1;
  const link = document.createElement('a');
  link.download = `stereogram.${format}`;
  link.href = canvas.toDataURL(mimeType, quality);
  link.click();
  showNotification("Stereogram downloaded successfully");
}

/**
 * Generate with AI using Stability API
 */
async function generateWithAI() {
  const prompt = aiPromptInput.value.trim();
  const genType = document.querySelector('input[name="genType"]:checked').value;
  const apiKey = apiKeyInput.value.trim() || defaultApiKey;
  
  if (!prompt) {
    showNotification("Please enter a prompt for AI generation", true);
    return;
  }
  
  if (isGenerating) {
    showNotification("AI generation already in progress", true);
    return;
  }
  
  isGenerating = true;
  showLoading("Generating with AI...");
  
  try {
    const result = await apiHandler.generateSpecializedImage(
      prompt, 
      apiKey, 
      genType === 'depthMap',
      updateLoadingProgress
    );
    
    if (!result.success) {
      throw new Error(result.error || "Failed to generate image");
    }
    
    // Load the generated image
    const img = new Image();
    img.onload = () => {
      if (genType === 'depthMap') {
        depthImg = img;
        updateImagePreview(depthMapContainer, result.imageData);
        updateImagePreview(depthPreviewImg, result.imageData, depthPlaceholder);
      } else {
        patternImg = img;
        updateImagePreview(patternContainer, result.imageData);
        updateImagePreview(patternPreviewImg, result.imageData, patternPlaceholder);
      }
      
      // Generate stereogram if both images are available
      if (depthImg && patternImg) {
        canvas.width = depthImg.width;
        canvas.height = depthImg.height;
        generateStereogram();
      }
      
      showNotification(`AI ${genType === 'depthMap' ? 'depth map' : 'pattern'} generated successfully`);
      hideLoading();
    };
    img.src = result.imageData;
    
  } catch (error) {
    console.error("AI generation error:", error);
    showNotification("Failed to generate image with AI: " + error.message, true);
    hideLoading();
  } finally {
    isGenerating = false;
  }
}

/**
 * Update loading progress display
 * @param {Object} progressInfo - Progress information object
 */
function updateLoadingProgress(progressInfo) {
  const { stage, message, progress } = progressInfo;
  loadingText.textContent = message || `Processing (${Math.round(progress * 100)}%)...`;
}

/**
 * Show loading indicator
 * @param {string} message - Optional loading message
 */
function showLoading(message = "Processing...") {
  loadingText.textContent = message;
  loadingElement.style.display = 'block';
}

/**
 * Hide loading indicator
 */
function hideLoading() {
  loadingElement.style.display = 'none';
}

/**
 * Notification System
 * @param {string} message - Message to display
 * @param {boolean} isError - Whether this is an error notification
 */
function showNotification(message, isError = false) {
  const notification = document.getElementById('notification');
  notification.textContent = message;
  notification.className = isError ? 'error' : '';
  notification.style.display = 'block';
  
  setTimeout(() => {
    notification.style.display = 'none';
  }, 5000);
}

/**
 * Generate Stereogram
 * Creates a stereogram based on the depth map and pattern
 */
function generateStereogram() {
  if (!depthImg || !patternImg) {
    showNotification("[ERR] SYS requires depth map and pattern image.", true);
    return;
  }
  
  showLoading("Generating stereogram...");
  
  // Use setTimeout to allow the loading indicator to appear
  setTimeout(() => {
    try {
      canvas.width = depthImg.width;
      canvas.height = depthImg.height;
      
      const shiftStrength = parseInt(shiftStrengthSlider.value);
      const patternScale = parseFloat(patternScaleSlider.value);
      const contrast = parseFloat(depthContrastSlider.value);
      
      // Create the pattern canvas with scaling
      const patternWidth = patternImg.width * patternScale;
      const patternHeight = patternImg.height * patternScale;
      
      // Create off-screen canvas for pattern tiling
      const offCanvas = document.createElement('canvas');
      offCanvas.width = canvas.width;
      offCanvas.height = canvas.height;
      const offCtx = offCanvas.getContext('2d');
      
      // Tile the pattern
      for (let y = 0; y < canvas.height; y += patternHeight) {
        for (let x = 0; x < canvas.width; x += patternWidth) {
          offCtx.drawImage(patternImg, x, y, patternWidth, patternHeight);
        }
      }
      let patternData = offCtx.getImageData(0, 0, canvas.width, canvas.height);
      
      // Process depth map with contrast adjustment
      const depthCanvas = document.createElement('canvas');
      depthCanvas.width = depthImg.width;
      depthCanvas.height = depthImg.height;
      const depthCtx = depthCanvas.getContext('2d');
      depthCtx.drawImage(depthImg, 0, 0);
      let depthData = depthCtx.getImageData(0, 0, canvas.width, canvas.height);
      depthData = adjustContrast(depthData, contrast);
      
      // Generate the stereogram
      for (let y = 0; y < canvas.height; y++) {
        for (let x = 0; x < canvas.width; x++) {
          const index = (y * canvas.width + x) * 4;
          const depthValue = depthData.data[index] / 255;
          const shift = Math.floor(depthValue * shiftStrength);
          
          // Calculate source position with wraparound
          let srcX = (x + shift) % canvas.width;
          if (srcX < 0) srcX += canvas.width;
          
          const srcIndex = (y * canvas.width + srcX) * 4;
          
          // Copy pixel data
          patternData.data[index] = patternData.data[srcIndex];
          patternData.data[index + 1] = patternData.data[srcIndex + 1];
          patternData.data[index + 2] = patternData.data[srcIndex + 2];
          patternData.data[index + 3] = 255; // Force opacity
        }
      }
      
      // Apply steganography if enabled
      if (enableStegoCheckbox.checked && hiddenMessageInput.value) {
        embedMessage(patternData, hiddenMessageInput.value);
      }
      
      // Draw the final stereogram
      ctx.putImageData(patternData, 0, 0);
      
      // Switch to result tab
      document.querySelector('[data-preview="result"]').click();
      
      hideLoading();
      showNotification("Stereogram generated successfully");
    } catch (error) {
      console.error("Error generating stereogram:", error);
      hideLoading();
      showNotification("Error generating stereogram: " + error.message, true);
    }
  }, 100);
}

/**
 * Adjust contrast of image data
 * @param {ImageData} imageData - Image data to adjust
 * @param {number} contrast - Contrast adjustment factor
 * @returns {ImageData} - Adjusted image data
 */
function adjustContrast(imageData, contrast) {
  const data = imageData.data;
  const factor = (259 * (contrast + 255)) / (255 * (259 - contrast));
  
  for (let i = 0; i < data.length; i += 4) {
    data[i] = Math.min(Math.max(factor * (data[i] - 128) + 128, 0), 255);
    data[i + 1] = data[i + 2] = data[i]; // Make grayscale
  }
  
  return imageData;
}

/**
 * Steganography Functions
 */

/**
 * Embed a message in image data using LSB steganography
 * @param {ImageData} imageData - Image data to embed message in
 * @param {string} message - Message to embed
 */
function embedMessage(imageData, message) {
  const encoder = new TextEncoder();
  const messageBytes = encoder.encode(message);
  const length = messageBytes.length;
  const lengthBytes = new Uint32Array([length]);
  const dataToEmbed = new Uint8Array(4 + length);
  
  // Store length followed by message bytes
  dataToEmbed.set(new Uint8Array(lengthBytes.buffer), 0);
  dataToEmbed.set(messageBytes, 4);
  
  const data = imageData.data;
  let byteIndex = 0;
  let bitInByte = 0;
  
  // Embed data bits in the least significant bit of each color channel
  for (let y = 0; y < imageData.height; y++) {
    for (let x = 0; x < imageData.width; x++) {
      const index = (y * imageData.width + x) * 4;
      
      for (let c = 0; c < 3; c++) { // Process RGB channels
        if (byteIndex < dataToEmbed.length) {
          const bit = (dataToEmbed[byteIndex] >> (7 - bitInByte)) & 1;
          data[index + c] = (data[index + c] & 0xFE) | bit;
          
          bitInByte++;
          if (bitInByte === 8) {
            bitInByte = 0;
            byteIndex++;
          }
        } else return; // Done embedding
      }
      
      if (byteIndex >= dataToEmbed.length) return; // Done embedding
    }
  }
  
  if (byteIndex < dataToEmbed.length) {
    showNotification('[ERR] Payload too large for image.', true);
  }
}

/**
 * Generator function to iterate through bits in image data
 * @param {ImageData} imageData - Image data to extract bits from
 * @yields {number} - Next bit from the image
 */
function* bitIterator(imageData) {
  const data = imageData.data;
  for (let y = 0; y < imageData.height; y++) {
    for (let x = 0; x < imageData.width; x++) {
      const index = (y * imageData.width + x) * 4;
      for (let c = 0; c < 3; c++) {
        yield data[index + c] & 1;
      }
    }
  }
}

/**
 * Extract a hidden message from image data
 * @param {ImageData} imageData - Image data containing hidden message
 * @returns {string} - Extracted message or error
 */
function decodeMessage(imageData) {
  const bitGen = bitIterator(imageData);
  let lengthBinary = '';
  
  // Extract length (32 bits)
  for (let i = 0; i < 32; i++) {
    const bit = bitGen.next().value;
    if (bit === undefined) return '[ERR] Image too small';
    lengthBinary += bit;
  }
  
  const length = parseInt(lengthBinary, 2);
  if (length <= 0 || length > 10000) {
    return '[ERR] Invalid payload length detected';
  }
  
  let messageBinary = '';
  
  // Extract message bits
  for (let i = 0; i < length * 8; i++) {
    const bit = bitGen.next().value;
    if (bit === undefined) return '[ERR] Payload incomplete';
    messageBinary += bit;
  }
  
  // Convert binary to bytes
  const messageBytes = [];
  for (let i = 0; i < messageBinary.length; i += 8) {
    messageBytes.push(parseInt(messageBinary.substring(i, i + 8), 2));
  }
  
  // Convert bytes to text
  try {
    return new TextDecoder().decode(new Uint8Array(messageBytes));
  } catch (e) {
    return '[ERR] Failed to decode message';
  }
}

/**
 * Decode a hidden message from an image file
 */
function decodeHiddenMessage() {
  const fileInput = document.getElementById('decodeImage');
  if (!fileInput.files[0]) {
    showNotification('[ERR] Upload an image to decrypt.', true);
    return;
  }
  
  const img = new Image();
  img.onload = () => {
    const tempCanvas = document.createElement('canvas');
    tempCanvas.width = img.width;
    tempCanvas.height = img.height;
    const tempCtx = tempCanvas.getContext('2d');
    tempCtx.drawImage(img, 0, 0);
    
    try {
      const imageData = tempCtx.getImageData(0, 0, img.width, img.height);
      const decodedMessage = decodeMessage(imageData);
      document.getElementById('decodedMessage').value = decodedMessage;
      
      if (decodedMessage.startsWith('[ERR]')) {
        showNotification('Error decoding message: ' + decodedMessage, true);
      } else {
        showNotification('Message decoded successfully');
      }
    } catch (error) {
      console.error("Error decoding message:", error);
      showNotification('Error decoding message: ' + error.message, true);
      document.getElementById('decodedMessage').value = '[ERR] Failed to process image';
    }
  };
  
  img.onerror = () => {
    showNotification('[ERR] Failed to load image', true);
  };
  
  img.src = URL.createObjectURL(fileInput.files[0]);
}

/**
 * Debounce function to limit how often a function can be called
 * @param {Function} func - Function to debounce
 * @param {number} wait - Time to wait in milliseconds
 * @returns {Function} - Debounced function
 */
function debounce(func, wait) {
  let timeout;
  return function(...args) {
    clearTimeout(timeout);
    timeout = setTimeout(() => func.apply(this, args), wait);
  };
}

// Create debounced version of generateStereogram
const debouncedGenerate = debounce(generateStereogram, 300);

// Close all modals when clicking outside them
window.addEventListener('click', (event) => {
  const modals = document.querySelectorAll('.modal');
  modals.forEach(modal => {
    if (event.target === modal) {
      modal.style.display = 'none';
    }
  });
});

// Initialize canvas size observer to handle window resizing
const resizeObserver = new ResizeObserver(entries => {
  for (let entry of entries) {
    if (entry.target === document.querySelector('.preview-container')) {
      // Update canvas size if a stereogram exists
      if (depthImg && patternImg) {
        generateStereogram();
      }
    }
  }
});

// Observe the preview container for size changes
resizeObserver.observe(document.querySelector('.preview-container'));
</script>

</body>
</html>
