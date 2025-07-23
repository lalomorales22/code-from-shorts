<?php
// YouTube Video Organizer - Single File PHP App
// Requirements: PHP with SQLite3 extension, yt-dlp installed on server

error_reporting(E_ALL);
ini_set('display_errors', 1);

class YouTubeOrganizer {
    private $db;
    private $dbPath;
    
    public function __construct() {
        $this->dbPath = __DIR__ . '/youtube_organizer.sqlite';
        $this->initDatabase();
    }
    
    private function initDatabase() {
        try {
            $this->db = new SQLite3($this->dbPath);
            
            // Create tables if they don't exist
            $this->db->exec('
                CREATE TABLE IF NOT EXISTS videos (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    youtube_id TEXT UNIQUE NOT NULL,
                    title TEXT NOT NULL,
                    author TEXT,
                    duration INTEGER,
                    description TEXT,
                    thumbnail_url TEXT,
                    upload_date TEXT,
                    download_status TEXT DEFAULT "pending",
                    file_path TEXT,
                    category TEXT DEFAULT "uncategorized",
                    tags TEXT,
                    notes TEXT,
                    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
                )
            ');
            
            $this->db->exec('
                CREATE TABLE IF NOT EXISTS categories (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    name TEXT UNIQUE NOT NULL,
                    description TEXT,
                    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
                )
            ');
            
            // Insert default categories
            $this->db->exec("
                INSERT OR IGNORE INTO categories (name, description) VALUES 
                ('Programming', 'Programming tutorials and tech content'),
                ('Music', 'Music videos and audio content'),
                ('Educational', 'Educational and learning content'),
                ('Entertainment', 'Entertainment and misc videos')
            ");
            
        } catch (Exception $e) {
            die('Database error: ' . $e->getMessage());
        }
    }
    
    public function getVideoInfo($url) {
        $youtube_id = $this->extractYouTubeId($url);
        if (!$youtube_id) {
            return ['error' => 'Invalid YouTube URL'];
        }
        
        // Use yt-dlp to get video info
        $command = "yt-dlp --dump-json --no-warnings " . escapeshellarg($url) . " 2>/dev/null";
        $output = shell_exec($command);
        
        if (!$output) {
            return ['error' => 'Could not fetch video information'];
        }
        
        $info = json_decode($output, true);
        if (!$info) {
            return ['error' => 'Could not parse video information'];
        }
        
        return [
            'youtube_id' => $youtube_id,
            'title' => $info['title'] ?? 'Unknown Title',
            'author' => $info['uploader'] ?? 'Unknown Author',
            'duration' => $info['duration'] ?? 0,
            'description' => substr($info['description'] ?? '', 0, 500),
            'thumbnail_url' => $info['thumbnail'] ?? '',
            'upload_date' => $info['upload_date'] ?? ''
        ];
    }
    
    public function addVideo($videoData) {
        $stmt = $this->db->prepare('
            INSERT OR REPLACE INTO videos 
            (youtube_id, title, author, duration, description, thumbnail_url, upload_date, category, tags, notes)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ');
        
        $stmt->bindValue(1, $videoData['youtube_id']);
        $stmt->bindValue(2, $videoData['title']);
        $stmt->bindValue(3, $videoData['author']);
        $stmt->bindValue(4, $videoData['duration']);
        $stmt->bindValue(5, $videoData['description']);
        $stmt->bindValue(6, $videoData['thumbnail_url']);
        $stmt->bindValue(7, $videoData['upload_date']);
        $stmt->bindValue(8, $videoData['category'] ?? 'uncategorized');
        $stmt->bindValue(9, $videoData['tags'] ?? '');
        $stmt->bindValue(10, $videoData['notes'] ?? '');
        
        return $stmt->execute();
    }
    
    public function downloadVideo($id, $quality = 'best') {
        $stmt = $this->db->prepare('SELECT * FROM videos WHERE id = ?');
        $stmt->bindValue(1, $id);
        $result = $stmt->execute();
        $video = $result->fetchArray(SQLITE3_ASSOC);
        
        if (!$video) {
            return ['error' => 'Video not found'];
        }
        
        $downloadDir = __DIR__ . '/downloads/' . $video['category'];
        if (!is_dir($downloadDir)) {
            mkdir($downloadDir, 0755, true);
        }
        
        $url = "https://www.youtube.com/watch?v=" . $video['youtube_id'];
        $filename = preg_replace('/[^\w\-_\.]/', '_', $video['title']);
        $command = "yt-dlp -f '$quality' -o " . escapeshellarg($downloadDir . '/' . $filename . '.%(ext)s') . " " . escapeshellarg($url) . " 2>&1";
        
        // Update status to downloading
        $this->updateVideoStatus($id, 'downloading');
        
        // Execute download command
        $output = shell_exec($command);
        
        // Check if download was successful
        if (strpos($output, 'has already been downloaded') !== false || strpos($output, '100%') !== false) {
            $filePath = $downloadDir . '/' . $filename;
            $this->updateVideoStatus($id, 'completed', $filePath);
            return ['success' => 'Download completed', 'output' => $output];
        } else {
            $this->updateVideoStatus($id, 'failed');
            return ['error' => 'Download failed', 'output' => $output];
        }
    }
    
    private function updateVideoStatus($id, $status, $filePath = null) {
        if ($filePath) {
            $stmt = $this->db->prepare('UPDATE videos SET download_status = ?, file_path = ? WHERE id = ?');
            $stmt->bindValue(1, $status);
            $stmt->bindValue(2, $filePath);
            $stmt->bindValue(3, $id);
            $stmt->execute();
        } else {
            $stmt = $this->db->prepare('UPDATE videos SET download_status = ? WHERE id = ?');
            $stmt->bindValue(1, $status);
            $stmt->bindValue(2, $id);
            $stmt->execute();
        }
    }
    
    public function getVideos($category = null) {
        if ($category) {
            $stmt = $this->db->prepare('SELECT * FROM videos WHERE category = ? ORDER BY created_at DESC');
            $stmt->bindValue(1, $category);
            $result = $stmt->execute();
        } else {
            $result = $this->db->query('SELECT * FROM videos ORDER BY created_at DESC');
        }
        
        $videos = [];
        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            $videos[] = $row;
        }
        return $videos;
    }
    
    public function getCategories() {
        $result = $this->db->query('SELECT * FROM categories ORDER BY name');
        $categories = [];
        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            $categories[] = $row;
        }
        return $categories;
    }
    
    public function deleteVideo($id) {
        $stmt = $this->db->prepare('DELETE FROM videos WHERE id = ?');
        $stmt->bindValue(1, $id);
        return $stmt->execute();
    }
    
    private function extractYouTubeId($url) {
        preg_match('/(?:youtube\.com\/(?:[^\/]+\/.+\/|(?:v|e(?:mbed)?)\/|.*[?&]v=)|youtu\.be\/)([^"&?\/\s]{11})/', $url, $matches);
        return isset($matches[1]) ? $matches[1] : false;
    }
}

// Initialize the app
$app = new YouTubeOrganizer();

// Handle AJAX requests
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    
    $action = $_POST['action'] ?? '';
    
    switch ($action) {
        case 'get_video_info':
            $url = $_POST['url'] ?? '';
            echo json_encode($app->getVideoInfo($url));
            exit;
            
        case 'add_video':
            $videoData = [
                'youtube_id' => $_POST['youtube_id'],
                'title' => $_POST['title'],
                'author' => $_POST['author'],
                'duration' => $_POST['duration'],
                'description' => $_POST['description'],
                'thumbnail_url' => $_POST['thumbnail_url'],
                'upload_date' => $_POST['upload_date'],
                'category' => $_POST['category'],
                'tags' => $_POST['tags'],
                'notes' => $_POST['notes']
            ];
            $result = $app->addVideo($videoData);
            echo json_encode(['success' => $result]);
            exit;
            
        case 'download_video':
            $id = $_POST['id'] ?? 0;
            $quality = $_POST['quality'] ?? 'best';
            echo json_encode($app->downloadVideo($id, $quality));
            exit;
            
        case 'delete_video':
            $id = $_POST['id'] ?? 0;
            echo json_encode(['success' => $app->deleteVideo($id)]);
            exit;
            
        case 'get_videos':
            $category = $_POST['category'] ?? null;
            echo json_encode($app->getVideos($category));
            exit;
    }
}

$categories = $app->getCategories();
$videos = $app->getVideos();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>YouTube Video Organizer</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            padding: 20px;
        }
        
        .container {
            max-width: 1200px;
            margin: 0 auto;
            background: rgba(255, 255, 255, 0.95);
            border-radius: 15px;
            padding: 30px;
            box-shadow: 0 20px 40px rgba(0, 0, 0, 0.1);
        }
        
        h1 {
            text-align: center;
            color: #333;
            margin-bottom: 30px;
            font-size: 2.5em;
            font-weight: 300;
        }
        
        .add-video-section {
            background: #f8f9fa;
            padding: 25px;
            border-radius: 10px;
            margin-bottom: 30px;
            border: 1px solid #e9ecef;
        }
        
        .form-group {
            margin-bottom: 20px;
        }
        
        label {
            display: block;
            margin-bottom: 8px;
            font-weight: 600;
            color: #555;
        }
        
        input, select, textarea {
            width: 100%;
            padding: 12px;
            border: 2px solid #e1e5e9;
            border-radius: 8px;
            font-size: 16px;
            transition: border-color 0.3s;
        }
        
        input:focus, select:focus, textarea:focus {
            outline: none;
            border-color: #667eea;
        }
        
        textarea {
            height: 80px;
            resize: vertical;
        }
        
        .btn {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border: none;
            padding: 12px 24px;
            border-radius: 8px;
            cursor: pointer;
            font-size: 16px;
            font-weight: 600;
            transition: transform 0.2s, box-shadow 0.2s;
            margin-right: 10px;
            margin-bottom: 10px;
        }
        
        .btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.2);
        }
        
        .btn-secondary {
            background: #6c757d;
        }
        
        .btn-danger {
            background: #dc3545;
        }
        
        .btn-small {
            padding: 6px 12px;
            font-size: 14px;
        }
        
        .video-preview {
            background: white;
            padding: 20px;
            border-radius: 10px;
            margin-top: 20px;
            border: 2px solid #e1e5e9;
            display: none;
        }
        
        .video-preview.show {
            display: block;
        }
        
        .video-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(350px, 1fr));
            gap: 20px;
            margin-top: 30px;
        }
        
        .video-card {
            background: white;
            border-radius: 10px;
            padding: 20px;
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.1);
            transition: transform 0.2s, box-shadow 0.2s;
        }
        
        .video-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 10px 25px rgba(0, 0, 0, 0.15);
        }
        
        .video-thumbnail {
            width: 100%;
            height: 200px;
            object-fit: cover;
            border-radius: 8px;
            margin-bottom: 15px;
        }
        
        .video-title {
            font-size: 18px;
            font-weight: 600;
            margin-bottom: 10px;
            color: #333;
            line-height: 1.3;
        }
        
        .video-meta {
            color: #666;
            font-size: 14px;
            margin-bottom: 10px;
        }
        
        .video-status {
            padding: 4px 8px;
            border-radius: 4px;
            font-size: 12px;
            font-weight: 600;
            text-transform: uppercase;
        }
        
        .status-pending {
            background: #ffc107;
            color: #212529;
        }
        
        .status-downloading {
            background: #17a2b8;
            color: white;
        }
        
        .status-completed {
            background: #28a745;
            color: white;
        }
        
        .status-failed {
            background: #dc3545;
            color: white;
        }
        
        .filters {
            margin-bottom: 20px;
            padding: 20px;
            background: #f8f9fa;
            border-radius: 10px;
        }
        
        .loading {
            text-align: center;
            padding: 20px;
        }
        
        .spinner {
            border: 3px solid #f3f3f3;
            border-top: 3px solid #667eea;
            border-radius: 50%;
            width: 30px;
            height: 30px;
            animation: spin 1s linear infinite;
            margin: 0 auto;
        }
        
        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }
        
        .alert {
            padding: 12px 20px;
            border-radius: 8px;
            margin-bottom: 20px;
        }
        
        .alert-success {
            background: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
        }
        
        .alert-error {
            background: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
        }
        
        .row {
            display: flex;
            gap: 20px;
            align-items: flex-end;
        }
        
        .col {
            flex: 1;
        }
        
        @media (max-width: 768px) {
            .row {
                flex-direction: column;
            }
            
            .video-grid {
                grid-template-columns: 1fr;
            }
            
            .container {
                padding: 15px;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>🎥 YouTube Video Organizer</h1>
        
        <div id="alerts"></div>
        
        <div class="add-video-section">
            <h2>Add New Video</h2>
            <form id="addVideoForm">
                <div class="row">
                    <div class="col">
                        <div class="form-group">
                            <label for="videoUrl">YouTube URL</label>
                            <input type="url" id="videoUrl" name="url" placeholder="https://www.youtube.com/watch?v=..." required>
                        </div>
                    </div>
                    <div class="col" style="flex: 0 0 auto;">
                        <button type="button" id="fetchInfoBtn" class="btn">Fetch Video Info</button>
                    </div>
                </div>
            </form>
            
            <div id="videoPreview" class="video-preview">
                <div class="row">
                    <div class="col">
                        <div class="form-group">
                            <label for="videoTitle">Title</label>
                            <input type="text" id="videoTitle" name="title">
                        </div>
                        <div class="form-group">
                            <label for="videoAuthor">Author</label>
                            <input type="text" id="videoAuthor" name="author">
                        </div>
                        <div class="form-group">
                            <label for="videoCategory">Category</label>
                            <select id="videoCategory" name="category">
                                <?php foreach ($categories as $category): ?>
                                    <option value="<?= htmlspecialchars($category['name']) ?>">
                                        <?= htmlspecialchars($category['name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    <div class="col">
                        <div class="form-group">
                            <label for="videoTags">Tags (comma separated)</label>
                            <input type="text" id="videoTags" name="tags" placeholder="programming, tutorial, beginner">
                        </div>
                        <div class="form-group">
                            <label for="videoNotes">Notes</label>
                            <textarea id="videoNotes" name="notes" placeholder="Personal notes about this video..."></textarea>
                        </div>
                    </div>
                </div>
                
                <div style="text-align: center; margin-top: 20px;">
                    <button type="button" id="saveVideoBtn" class="btn">Save Video</button>
                    <button type="button" id="cancelBtn" class="btn btn-secondary">Cancel</button>
                </div>
            </div>
        </div>
        
        <div class="filters">
            <h3>Filter Videos</h3>
            <div class="row">
                <div class="col">
                    <select id="categoryFilter">
                        <option value="">All Categories</option>
                        <?php foreach ($categories as $category): ?>
                            <option value="<?= htmlspecialchars($category['name']) ?>">
                                <?= htmlspecialchars($category['name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col" style="flex: 0 0 auto;">
                    <button type="button" id="refreshBtn" class="btn">Refresh</button>
                </div>
            </div>
        </div>
        
        <div id="videosContainer">
            <div class="video-grid" id="videoGrid">
                <?php foreach ($videos as $video): ?>
                    <div class="video-card" data-id="<?= $video['id'] ?>">
                        <?php if ($video['thumbnail_url']): ?>
                            <img src="<?= htmlspecialchars($video['thumbnail_url']) ?>" alt="<?= htmlspecialchars($video['title']) ?>" class="video-thumbnail">
                        <?php endif; ?>
                        <div class="video-title"><?= htmlspecialchars($video['title']) ?></div>
                        <div class="video-meta">
                            By: <?= htmlspecialchars($video['author']) ?><br>
                            Duration: <?= gmdate("H:i:s", $video['duration']) ?><br>
                            Category: <?= htmlspecialchars($video['category']) ?>
                        </div>
                        <div class="video-status status-<?= $video['download_status'] ?>">
                            <?= htmlspecialchars($video['download_status']) ?>
                        </div>
                        <div style="margin-top: 15px;">
                            <button class="btn btn-small download-btn" data-id="<?= $video['id'] ?>">Download</button>
                            <button class="btn btn-small btn-danger delete-btn" data-id="<?= $video['id'] ?>">Delete</button>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>

    <script>
        let currentVideoData = null;
        
        // Utility functions
        function showAlert(message, type = 'success') {
            const alertsContainer = document.getElementById('alerts');
            const alertDiv = document.createElement('div');
            alertDiv.className = `alert alert-${type}`;
            alertDiv.textContent = message;
            alertsContainer.appendChild(alertDiv);
            
            setTimeout(() => {
                alertDiv.remove();
            }, 5000);
        }
        
        function showLoading(element) {
            element.innerHTML = '<div class="loading"><div class="spinner"></div><p>Loading...</p></div>';
        }
        
        // Fetch video information
        document.getElementById('fetchInfoBtn').addEventListener('click', function() {
            const url = document.getElementById('videoUrl').value;
            if (!url) {
                showAlert('Please enter a YouTube URL', 'error');
                return;
            }
            
            const btn = this;
            btn.disabled = true;
            btn.textContent = 'Fetching...';
            
            const formData = new FormData();
            formData.append('action', 'get_video_info');
            formData.append('url', url);
            
            fetch(window.location.href, {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.error) {
                    showAlert(data.error, 'error');
                    return;
                }
                
                currentVideoData = data;
                document.getElementById('videoTitle').value = data.title;
                document.getElementById('videoAuthor').value = data.author;
                document.getElementById('videoPreview').classList.add('show');
                
                showAlert('Video information fetched successfully!');
            })
            .catch(error => {
                showAlert('Error fetching video information: ' + error.message, 'error');
            })
            .finally(() => {
                btn.disabled = false;
                btn.textContent = 'Fetch Video Info';
            });
        });
        
        // Save video
        document.getElementById('saveVideoBtn').addEventListener('click', function() {
            if (!currentVideoData) {
                showAlert('Please fetch video information first', 'error');
                return;
            }
            
            const formData = new FormData();
            formData.append('action', 'add_video');
            formData.append('youtube_id', currentVideoData.youtube_id);
            formData.append('title', document.getElementById('videoTitle').value);
            formData.append('author', document.getElementById('videoAuthor').value);
            formData.append('duration', currentVideoData.duration);
            formData.append('description', currentVideoData.description);
            formData.append('thumbnail_url', currentVideoData.thumbnail_url);
            formData.append('upload_date', currentVideoData.upload_date);
            formData.append('category', document.getElementById('videoCategory').value);
            formData.append('tags', document.getElementById('videoTags').value);
            formData.append('notes', document.getElementById('videoNotes').value);
            
            fetch(window.location.href, {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    showAlert('Video saved successfully!');
                    document.getElementById('addVideoForm').reset();
                    document.getElementById('videoPreview').classList.remove('show');
                    currentVideoData = null;
                    loadVideos();
                } else {
                    showAlert('Error saving video', 'error');
                }
            })
            .catch(error => {
                showAlert('Error: ' + error.message, 'error');
            });
        });
        
        // Cancel button
        document.getElementById('cancelBtn').addEventListener('click', function() {
            document.getElementById('videoPreview').classList.remove('show');
            currentVideoData = null;
        });
        
        // Download video
        function downloadVideo(id) {
            const formData = new FormData();
            formData.append('action', 'download_video');
            formData.append('id', id);
            formData.append('quality', 'best');
            
            const card = document.querySelector(`[data-id="${id}"]`);
            const statusEl = card.querySelector('.video-status');
            statusEl.className = 'video-status status-downloading';
            statusEl.textContent = 'downloading';
            
            fetch(window.location.href, {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    showAlert('Download completed!');
                    statusEl.className = 'video-status status-completed';
                    statusEl.textContent = 'completed';
                } else {
                    showAlert('Download failed: ' + (data.error || 'Unknown error'), 'error');
                    statusEl.className = 'video-status status-failed';
                    statusEl.textContent = 'failed';
                }
            })
            .catch(error => {
                showAlert('Error: ' + error.message, 'error');
                statusEl.className = 'video-status status-failed';
                statusEl.textContent = 'failed';
            });
        }
        
        // Delete video
        function deleteVideo(id) {
            if (!confirm('Are you sure you want to delete this video?')) {
                return;
            }
            
            const formData = new FormData();
            formData.append('action', 'delete_video');
            formData.append('id', id);
            
            fetch(window.location.href, {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    showAlert('Video deleted successfully!');
                    document.querySelector(`[data-id="${id}"]`).remove();
                } else {
                    showAlert('Error deleting video', 'error');
                }
            })
            .catch(error => {
                showAlert('Error: ' + error.message, 'error');
            });
        }
        
        // Load videos
        function loadVideos(category = null) {
            const formData = new FormData();
            formData.append('action', 'get_videos');
            if (category) {
                formData.append('category', category);
            }
            
            const container = document.getElementById('videoGrid');
            showLoading(container);
            
            fetch(window.location.href, {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(videos => {
                container.innerHTML = '';
                
                videos.forEach(video => {
                    const card = document.createElement('div');
                    card.className = 'video-card';
                    card.setAttribute('data-id', video.id);
                    
                    card.innerHTML = `
                        ${video.thumbnail_url ? `<img src="${video.thumbnail_url}" alt="${video.title}" class="video-thumbnail">` : ''}
                        <div class="video-title">${video.title}</div>
                        <div class="video-meta">
                            By: ${video.author}<br>
                            Duration: ${new Date(video.duration * 1000).toISOString().substr(11, 8)}<br>
                            Category: ${video.category}
                        </div>
                        <div class="video-status status-${video.download_status}">
                            ${video.download_status}
                        </div>
                        <div style="margin-top: 15px;">
                            <button class="btn btn-small download-btn" data-id="${video.id}">Download</button>
                            <button class="btn btn-small btn-danger delete-btn" data-id="${video.id}">Delete</button>
                        </div>
                    `;
                    
                    container.appendChild(card);
                });
            })
            .catch(error => {
                container.innerHTML = '<p>Error loading videos: ' + error.message + '</p>';
            });
        }
        
        // Event delegation for dynamic buttons
        document.addEventListener('click', function(e) {
            if (e.target.classList.contains('download-btn')) {
                const id = e.target.getAttribute('data-id');
                downloadVideo(id);
            }
            
            if (e.target.classList.contains('delete-btn')) {
                const id = e.target.getAttribute('data-id');
                deleteVideo(id);
            }
        });
        
        // Category filter
        document.getElementById('categoryFilter').addEventListener('change', function() {
            const category = this.value;
            loadVideos(category || null);
        });
        
        // Refresh button
        document.getElementById('refreshBtn').addEventListener('click', function() {
            const category = document.getElementById('categoryFilter').value;
            loadVideos(category || null);
        });
        
        // Initialize
        document.addEventListener('DOMContentLoaded', function() {
            // Videos are already loaded from PHP, but you can uncomment below to load dynamically
            // loadVideos();
        });
    </script>
</body>
</html>