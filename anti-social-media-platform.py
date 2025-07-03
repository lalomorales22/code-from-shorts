import os
from flask import Flask, request, render_template_string, redirect, url_for, g, jsonify
from dotenv import load_dotenv
import sqlite3
from flask_socketio import SocketIO, emit
from werkzeug.security import generate_password_hash, check_password_hash
from flask_login import LoginManager, UserMixin, login_user, login_required, logout_user, current_user
from datetime import datetime
import requests
import base64
import logging

logging.basicConfig(level=logging.DEBUG)
print(f"LUMAAI_API_KEY: {os.getenv('LUMAAI_API_KEY')}")

# Load environment variables
load_dotenv()

app = Flask(__name__)
app.config['SECRET_KEY'] = 'your_secret_key_here'
login_manager = LoginManager(app)
login_manager.login_view = 'login'
socketio = SocketIO(app)

# Database setup
def get_db():
    db = getattr(g, '_database', None)
    if db is None:
        db = g._database = sqlite3.connect('message_board.db')
    return db

def generate_image_with_stability(prompt):
    api_key = os.getenv("STABILITY_API_KEY")
    if not api_key:
        return None, "Stability API key not set"

    url = "https://api.stability.ai/v1/generation/stable-diffusion-xl-1024-v1-0/text-to-image"
    headers = {
        "Accept": "application/json",
        "Authorization": f"Bearer {api_key}"
    }
    payload = {
        "text_prompts": [{"text": prompt}],
        "cfg_scale": 7,
        "height": 1024,
        "width": 1024,
        "samples": 1,
        "steps": 30,
    }

    try:
        response = requests.post(url, headers=headers, json=payload)
        response.raise_for_status()
        data = response.json()
        image_data = data["artifacts"][0]["base64"]
        return image_data, None
    except requests.exceptions.RequestException as e:
        return None, str(e)

def generate_video_with_luma(prompt, aspect_ratio="16:9"):
    api_key = os.getenv("LUMAAI_API_KEY")
    if not api_key:
        logging.error("Luma AI API key not set")
        return None, "Luma AI API key not set"

    url = "https://api.lumalabs.ai/dream-machine/v1/generations"
    headers = {
        "accept": "application/json",
        "content-type": "application/json",
        "authorization": f"Bearer {api_key}"
    }
    payload = {
        "prompt": prompt,
        "aspect_ratio": aspect_ratio,
        "loop": True
    }

    try:
        response = requests.post(url, json=payload, headers=headers)
        response.raise_for_status()
        data = response.json()
        generation_id = data.get('id')
        logging.info(f"Video generation request successful. Generation ID: {generation_id}")
        return generation_id, None
    except requests.exceptions.RequestException as e:
        logging.error(f"Error in generate_video_with_luma: {str(e)}")
        return None, str(e)

def get_video_status(generation_id):
    api_key = os.getenv("LUMAAI_API_KEY")
    if not api_key:
        logging.error("Luma AI API key not set")
        return None, "Luma AI API key not set"

    url = f"https://api.lumalabs.ai/dream-machine/v1/generations/{generation_id}"
    headers = {
        "accept": "application/json",
        "authorization": f"Bearer {api_key}"
    }

    try:
        logging.debug(f"Sending request to Luma AI API: {url}")
        response = requests.get(url, headers=headers)
        response.raise_for_status()
        data = response.json()
        logging.debug(f"Received response from Luma AI API: {data}")
        return data, None
    except requests.exceptions.RequestException as e:
        logging.error(f"Error in get_video_status: {str(e)}")
        return None, str(e)

@app.teardown_appcontext
def close_connection(exception):
    db = getattr(g, '_database', None)
    if db is not None:
        db.close()

def init_db():
    with app.app_context():
        db = get_db()
        cursor = db.cursor()
        
        cursor.execute('''
            CREATE TABLE IF NOT EXISTS users
            (id INTEGER PRIMARY KEY AUTOINCREMENT,
             username TEXT UNIQUE NOT NULL,
             password TEXT NOT NULL,
             avatar TEXT)
        ''')
        
        cursor.execute('''
            CREATE TABLE IF NOT EXISTS messages
            (id INTEGER PRIMARY KEY AUTOINCREMENT,
             user_id INTEGER,
             content TEXT NOT NULL,
             timestamp DATETIME DEFAULT CURRENT_TIMESTAMP,
             image_data TEXT,
             video_id TEXT,
             video_url TEXT,
             FOREIGN KEY (user_id) REFERENCES users (id))
        ''')
        
        cursor.execute('''
            CREATE TABLE IF NOT EXISTS comments
            (id INTEGER PRIMARY KEY AUTOINCREMENT,
             user_id INTEGER,
             message_id INTEGER,
             content TEXT NOT NULL,
             timestamp DATETIME DEFAULT CURRENT_TIMESTAMP,
             FOREIGN KEY (user_id) REFERENCES users (id),
             FOREIGN KEY (message_id) REFERENCES messages (id))
        ''')
        
        cursor.execute('''
            CREATE TABLE IF NOT EXISTS tags
            (id INTEGER PRIMARY KEY AUTOINCREMENT,
             name TEXT UNIQUE NOT NULL)
        ''')
        
        cursor.execute('''
            CREATE TABLE IF NOT EXISTS message_tags
            (message_id INTEGER,
             tag_id INTEGER,
             FOREIGN KEY (message_id) REFERENCES messages (id),
             FOREIGN KEY (tag_id) REFERENCES tags (id),
             PRIMARY KEY (message_id, tag_id))
        ''')
        
        cursor.execute('''
            CREATE TABLE IF NOT EXISTS reactions
            (id INTEGER PRIMARY KEY AUTOINCREMENT,
             message_id INTEGER,
             user_id INTEGER,
             reaction TEXT,
             FOREIGN KEY (message_id) REFERENCES messages (id),
             FOREIGN KEY (user_id) REFERENCES users (id),
             UNIQUE(message_id, user_id, reaction))
        ''')
        
        db.commit()

init_db()

class User(UserMixin):
    def __init__(self, id, username, avatar):
        self.id = id
        self.username = username
        self.avatar = avatar

@login_manager.user_loader
def load_user(user_id):
    db = get_db()
    cursor = db.cursor()
    cursor.execute("SELECT * FROM users WHERE id = ?", (user_id,))
    user = cursor.fetchone()
    if user:
        return User(user[0], user[1], user[3])
    return None

@app.route('/')
def index():
    db = get_db()
    cursor = db.cursor()
    cursor.execute('''
        SELECT messages.id, messages.content, messages.image_data, messages.video_url, messages.timestamp, users.username, users.avatar
        FROM messages
        JOIN users ON messages.user_id = users.id
        ORDER BY messages.timestamp DESC
    ''')
    messages = cursor.fetchall()
    
    for i, message in enumerate(messages):
        cursor.execute('''
            SELECT comments.content, comments.timestamp, users.username, users.avatar
            FROM comments
            JOIN users ON comments.user_id = users.id
            WHERE comments.message_id = ?
            ORDER BY comments.timestamp ASC
        ''', (message[0],))
        comments = cursor.fetchall()
        
        cursor.execute('''
            SELECT tags.name
            FROM tags
            JOIN message_tags ON tags.id = message_tags.tag_id
            WHERE message_tags.message_id = ?
        ''', (message[0],))
        tags = [tag[0] for tag in cursor.fetchall()]
        
        cursor.execute('''
            SELECT reaction, COUNT(*) as count
            FROM reactions
            WHERE message_id = ?
            GROUP BY reaction
        ''', (message[0],))
        reactions = dict(cursor.fetchall())
        
        messages[i] = message + (comments, tags, reactions)
    
    cursor.execute('''
        SELECT tags.name, COUNT(*) as tag_count
        FROM tags
        JOIN message_tags ON tags.id = message_tags.tag_id
        GROUP BY tags.id
        ORDER BY tag_count DESC
        LIMIT 10
    ''')
    popular_tags = cursor.fetchall()
    
    return render_template_string(BASE_HTML, messages=messages, popular_tags=popular_tags)

@app.route('/post_message', methods=['POST'])
@login_required
def post_message():
    content = request.form.get('content')
    tags = request.form.get('tags', '').split(',')
    image_data = request.form.get('image_data')
    video_id = request.form.get('video_id')
    
    logging.info(f"Posting message. Content: {content[:50]}..., Video ID: {video_id}")
    
    if content or image_data or video_id:
        db = get_db()
        cursor = db.cursor()
        
        # If there's a video_id, get the video_url
        video_url = None
        if video_id:
            data, error = get_video_status(video_id)
            if not error and data.get('state') == 'completed':
                video_url = data.get('assets', {}).get('video')
        
        cursor.execute("INSERT INTO messages (user_id, content, image_data, video_id, video_url) VALUES (?, ?, ?, ?, ?)",
                       (current_user.id, content, image_data, video_id, video_url))
        message_id = cursor.lastrowid
        
        logging.info(f"Message inserted. ID: {message_id}")
        
        for tag in tags:
            tag = tag.strip().lower()
            if tag:
                cursor.execute("INSERT OR IGNORE INTO tags (name) VALUES (?)", (tag,))
                cursor.execute("SELECT id FROM tags WHERE name = ?", (tag,))
                tag_id = cursor.fetchone()[0]
                cursor.execute("INSERT INTO message_tags (message_id, tag_id) VALUES (?, ?)",
                               (message_id, tag_id))
        
        db.commit()
        
        cursor.execute('''
            SELECT messages.id, messages.content, messages.image_data, messages.video_url, messages.timestamp, users.username, users.avatar
            FROM messages
            JOIN users ON messages.user_id = users.id
            WHERE messages.id = ?
        ''', (message_id,))
        new_message = cursor.fetchone()
        
        logging.info(f"Emitting new message. ID: {new_message[0]}, Video URL: {new_message[3]}")
        
        socketio.emit('new_message', {
            'id': new_message[0],
            'content': new_message[1],
            'image_data': new_message[2],
            'video_url': new_message[3],
            'timestamp': new_message[4],
            'username': new_message[5],
            'avatar': new_message[6],
            'tags': tags,
            'reactions': {}
        })
    return redirect(url_for('index'))

@app.route('/generate_image', methods=['POST'])
@login_required
def generate_image():
    prompt = request.form.get('prompt')
    image_data, error = generate_image_with_stability(prompt)
    
    if error:
        return jsonify({"error": error}), 500
    
    return jsonify({"image_data": image_data})

@app.route('/generate_video', methods=['POST'])
@login_required
def generate_video():
    prompt = request.form.get('prompt')
    aspect_ratio = request.form.get('aspect_ratio', '16:9')
    generation_id, error = generate_video_with_luma(prompt, aspect_ratio)
    
    if error:
        logging.error(f"Error generating video: {error}")
        return jsonify({"error": error}), 500
    
    logging.info(f"Video generation started. Generation ID: {generation_id}")
    return jsonify({"generation_id": generation_id})

@app.route('/check_video_status/<generation_id>')
@login_required
def check_video_status(generation_id):
    data, error = get_video_status(generation_id)
    
    if error:
        logging.error(f"Error fetching video status: {error}")
        return jsonify({"error": f"Error fetching video status: {error}"}), 500
    
    if data is None:
        logging.error("No data received from Luma AI API")
        return jsonify({"error": "No data received from Luma AI API"}), 500
    
    status = data.get('state')
    assets = data.get('assets')
    video_url = assets.get('video') if assets else None
    
    logging.info(f"Video status for generation {generation_id}: {status}")
    logging.info(f"Video URL: {video_url}")
    
    if status == 'completed' and video_url:
        db = get_db()
        cursor = db.cursor()
        cursor.execute("UPDATE messages SET video_url = ? WHERE video_id = ?", (video_url, generation_id))
        db.commit()
        logging.info(f"Updated video URL in database for generation {generation_id}")
    
    return jsonify({
        "status": status,
        "video_url": video_url,
        "full_data": data
    })

@app.route('/tag/<tag_name>')
def view_tag(tag_name):
    db = get_db()
    cursor = db.cursor()
    cursor.execute('''
        SELECT messages.id, messages.content, messages.image_data, messages.video_url, messages.timestamp, users.username, users.avatar
        FROM messages
        JOIN users ON messages.user_id = users.id
        JOIN message_tags ON messages.id = message_tags.message_id
        JOIN tags ON message_tags.tag_id = tags.id
        WHERE tags.name = ?
        ORDER BY messages.timestamp DESC
    ''', (tag_name,))
    messages = cursor.fetchall()
    
    for i, message in enumerate(messages):
        cursor.execute('''
            SELECT comments.content, comments.timestamp, users.username, users.avatar
            FROM comments
            JOIN users ON comments.user_id = users.id
            WHERE comments.message_id = ?
            ORDER BY comments.timestamp ASC
        ''', (message[0],))
        comments = cursor.fetchall()
        
        cursor.execute('''
            SELECT tags.name
            FROM tags
            JOIN message_tags ON tags.id = message_tags.tag_id
            WHERE message_tags.message_id = ?
        ''', (message[0],))
        tags = [tag[0] for tag in cursor.fetchall()]
        
        cursor.execute('''
            SELECT reaction, COUNT(*) as count
            FROM reactions
            WHERE message_id = ?
            GROUP BY reaction
        ''', (message[0],))
        reactions = dict(cursor.fetchall())
        
        messages[i] = message + (comments, tags, reactions)
    
    return render_template_string(BASE_HTML, messages=messages, current_tag=tag_name)

@app.route('/post_comment/<int:message_id>', methods=['POST'])
@login_required
def post_comment(message_id):
    content = request.form.get('content')
    if content:
        db = get_db()
        cursor = db.cursor()
        cursor.execute("INSERT INTO comments (user_id, message_id, content) VALUES (?, ?, ?)",
                       (current_user.id, message_id, content))
        comment_id = cursor.lastrowid
        db.commit()
        
        cursor.execute('''
            SELECT comments.content, comments.timestamp, users.username, users.avatar
            FROM comments
            JOIN users ON comments.user_id = users.id
            WHERE comments.id = ?
        ''', (comment_id,))
        new_comment = cursor.fetchone()
        
        socketio.emit('new_comment', {
            'message_id': message_id,
            'content': new_comment[0],
            'timestamp': new_comment[1],
            'username': new_comment[2],
            'avatar': new_comment[3]
        })
    return redirect(url_for('index'))

@app.route('/login', methods=['GET', 'POST'])
def login():
    if request.method == 'POST':
        username = request.form.get('username')
        password = request.form.get('password')
        db = get_db()
        cursor = db.cursor()
        cursor.execute("SELECT * FROM users WHERE username = ?", (username,))
        user = cursor.fetchone()
        if user and check_password_hash(user[2], password):
            user_obj = User(user[0], user[1], user[3])
            login_user(user_obj)
            return redirect(url_for('index'))
        return "Invalid username or password"
    return render_template_string(LOGIN_HTML)

@app.route('/register', methods=['GET', 'POST'])
def register():
    if request.method == 'POST':
        username = request.form.get('username')
        password = request.form.get('password')
        avatar = request.form.get('avatar')
        db = get_db()
        cursor = db.cursor()
        cursor.execute("SELECT * FROM users WHERE username = ?", (username,))
        if cursor.fetchone():
            return "Username already exists"
        cursor.execute("INSERT INTO users (username, password, avatar) VALUES (?, ?, ?)",
                       (username, generate_password_hash(password), avatar))
        db.commit()
        return redirect(url_for('login'))
    return render_template_string(REGISTER_HTML)

@app.route('/logout')
@login_required
def logout():
    logout_user()
    return redirect(url_for('index'))

@app.route('/profile/<username>')
def profile(username):
    db = get_db()
    cursor = db.cursor()
    cursor.execute("SELECT id, username, avatar FROM users WHERE username = ?", (username,))
    user = cursor.fetchone()
    if user is None:
        return "User not found", 404
    
    cursor.execute('''
        SELECT messages.id, messages.content, messages.image_data, messages.video_url, messages.timestamp
        FROM messages
        WHERE messages.user_id = ?
        ORDER BY messages.timestamp DESC
    ''', (user[0],))
    messages = cursor.fetchall()
    
    return render_template_string(PROFILE_HTML, user=user, messages=messages)

@app.route('/add_reaction/<int:message_id>/<reaction>')
@login_required
def add_reaction(message_id, reaction):
    db = get_db()
    cursor = db.cursor()
    try:
        cursor.execute('''
            INSERT INTO reactions (message_id, user_id, reaction)
            VALUES (?, ?, ?)
            ON CONFLICT(message_id, user_id, reaction) DO UPDATE SET reaction = excluded.reaction
        ''', (message_id, current_user.id, reaction))
        db.commit()
        
        cursor.execute('''
            SELECT reaction, COUNT(*) as count
            FROM reactions
            WHERE message_id = ?
            GROUP BY reaction
        ''', (message_id,))
        reactions = dict(cursor.fetchall())
        
        socketio.emit('reaction_update', {
            'message_id': message_id,
            'reactions': reactions
        })
        
        return 'OK', 200
    except Exception as e:
        print(f"Error adding reaction: {e}")
        return 'Error', 500

@app.route('/update_video_url', methods=['POST'])
@login_required
def update_video_url():
    data = request.json
    message_id = data.get('message_id')
    video_url = data.get('video_url')
    
    if message_id and video_url:
        db = get_db()
        cursor = db.cursor()
        cursor.execute("UPDATE messages SET video_url = ? WHERE id = ?", (video_url, message_id))
        db.commit()
        return jsonify({"success": True}), 200
    else:
        return jsonify({"error": "Invalid data"}), 400

BASE_HTML = '''
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Rad Message Board</title>
    <style>
        :root {
            --bg-color: #000;
            --text-color: #fff;
            --border-color: #fff;
            --input-bg-color: #000;
            --input-text-color: #fff;
            --button-bg-color: #fff;
            --button-text-color: #000;
            --tag-bg-color: #fff;
            --tag-text-color: #000;
        }
        body {
            font-family: 'Courier New', monospace;
            background-color: var(--bg-color);
            color: var(--text-color);
            margin: 0;
            padding: 20px;
            transition: background-color 0.3s, color 0.3s;
        }
        .container {
            max-width: 800px;
            margin: 0 auto;
        }
        h1, h2 {
            border-bottom: 4px solid var(--border-color);
            padding-bottom: 10px;
        }
        .message, .comment {
            border: 4px solid var(--border-color);
            padding: 10px;
            margin-bottom: 20px;
        }
        .message-content, .comment-content {
            margin-bottom: 10px;
            word-wrap: break-word;
        }
        .message-meta, .comment-meta {
            font-size: 0.8em;
            color: #ccc;
            margin-bottom: 10px;
        }
        form {
            margin-bottom: 20px;
        }
        input[type="text"], textarea {
            width: calc(100% - 24px);
            padding: 10px;
            margin-bottom: 10px;
            background-color: var(--input-bg-color);
            color: var(--input-text-color);
            border: 2px solid var(--border-color);
        }
        input[type="submit"], button {
            background-color: var(--button-bg-color);
            color: var(--button-text-color);
            border: none;
            padding: 10px 20px;
            cursor: pointer;
        }
        .nav {
            margin-bottom: 20px;
        }
        .nav a {
            color: var(--text-color);
            margin-right: 10px;
        }
        .comments-section {
            margin-top: 10px;
            padding-top: 10px;
            border-top: 2px solid var(--border-color);
        }
        .avatar {
            font-size: 1.5em;
            margin-right: 5px;
        }
        .tag {
            display: inline-block;
            background-color: var(--tag-bg-color);
            color: var(--tag-text-color);
            padding: 2px 5px;
            margin-right: 5px;
            font-size: 0.8em;
        }
        .tag-cloud {
            margin-bottom: 20px;
        }
        #generated-image, #generated-video {
            max-width: 100%;
            height: auto;
            margin-top: 10px;
        }
        .video-container {
            margin-top: 10px;
        }
    </style>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/socket.io/4.0.1/socket.io.js"></script>
</head>
<body>
    <div class="container">
        <nav class="nav">
            <a href="{{ url_for('index') }}">Home</a>
            {% if current_user.is_authenticated %}
                <a href="{{ url_for('logout') }}">Logout</a>
                <a href="{{ url_for('profile', username=current_user.username) }}">Profile</a>
            {% else %}
                <a href="{{ url_for('login') }}">Login</a>
                <a href="{{ url_for('register') }}">Register</a>
            {% endif %}
        </nav>
        <h1>Anti-Social Media Board</h1>
        {% if popular_tags %}
            <div class="tag-cloud">
                <h2>Popular Tags</h2>
                {% for tag, count in popular_tags %}
                    <a href="{{ url_for('view_tag', tag_name=tag) }}" class="tag">{{ tag }} ({{ count }})</a>
                {% endfor %}
            </div>
        {% endif %}
        {% if current_user.is_authenticated %}
            <form id="post-form" action="{{ url_for('post_message') }}" method="post">
            <textarea name="content" placeholder="What's on your mind?" required></textarea>
            <input type="text" name="tags" placeholder="Tags (comma-separated)">
            
            <input type="text" id="image-prompt" placeholder="Image generation prompt">
            <button type="button" onclick="generateImage()">Generate Image</button>
            <img id="generated-image" src="" alt="Generated Image" style="display:none;">
            <input type="hidden" id="image-data" name="image_data">
            
            <input type="text" id="video-prompt" placeholder="Video generation prompt">
            <select id="video-aspect-ratio">
                <option value="16:9">16:9</option>
                <option value="4:3">4:3</option>
                <option value="1:1">1:1</option>
                <option value="9:16">9:16</option>
            </select>
            <button type="button" onclick="generateVideo()">Generate Video</button>
            <div id="video-status"></div>
            <video id="generated-video" src="" controls style="display:none;"></video>
            <input type="hidden" id="video-id" name="video_id">
            
            <input type="submit" value="Post Message">
        </form>
        {% endif %}
        <div id="messages-container">
            {% for message in messages %}
                <div class="message" data-message-id="{{ message[0] }}">
                    <div class="message-content">{{ message[1] }}</div>
                    {% if message[2] %}
                        <img src="data:image/png;base64,{{ message[2] }}" alt="Generated Image" style="max-width: 100%; height: auto;">
                    {% endif %}
                    {% if message[3] %}
                        <div class="video-container">
                            <video src="{{ message[3] }}" controls style="max-width: 100%; height: auto;"></video>
                        </div>
                    {% endif %}
                    <div class="message-meta">
                        <span class="avatar">{{ message[6] }}</span>
                        Posted by <a href="{{ url_for('profile', username=message[5]) }}">{{ message[5] }}</a> on {{ message[4] }}
                    </div>
                    {% if message[8] %}
                        <div class="message-tags">
                            {% for tag in message[8] %}
                                <a href="{{ url_for('view_tag', tag_name=tag) }}" class="tag">{{ tag }}</a>
                            {% endfor %}
                        </div>
                    {% endif %}
                    <div class="reactions">
                        {% for reaction, count in message[9].items() %}
                            <button onclick="addReaction({{ message[0] }}, '{{ reaction }}')">{{ reaction }} {{ count }}</button>
                        {% endfor %}
                    </div>
                    {% if message[7] %}
                        <div class="comments-section">
                            <h3>Comments:</h3>
                            {% for comment in message[7] %}
                                <div class="comment">
                                    <div class="comment-content">{{ comment[0] }}</div>
                                    <div class="comment-meta">
                                        <span class="avatar">{{ comment[3] }}</span>
                                        Posted by <a href="{{ url_for('profile', username=comment[2]) }}">{{ comment[2] }}</a> on {{ comment[1] }}
                                    </div>
                                </div>
                            {% endfor %}
                        </div>
                    {% endif %}
                    {% if current_user.is_authenticated %}
                        <form class="comment-form" action="{{ url_for('post_comment', message_id=message[0]) }}" method="post">
                            <input type="text" name="content" placeholder="Add a comment" required>
                            <input type="submit" value="Post Comment">
                        </form>
                    {% endif %}
                </div>
            {% endfor %}
        </div>
    </div>

    <script>
        const socket = io();

        function generateImage() {
            const prompt = document.getElementById('image-prompt').value;
            fetch('/generate_image', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: 'prompt=' + encodeURIComponent(prompt)
            })
            .then(response => response.json())
            .then(data => {
                if (data.error) {
                    alert('Error: ' + data.error);
                } else {
                    const img = document.getElementById('generated-image');
                    img.src = 'data:image/png;base64,' + data.image_data;
                    img.style.display = 'block';
                    document.getElementById('image-data').value = data.image_data;
                }
            })
            .catch(error => console.error('Error:', error));
        }
        
        function generateVideo() {
            const prompt = document.getElementById('video-prompt').value;
            const aspectRatio = document.getElementById('video-aspect-ratio').value;
            fetch('/generate_video', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: 'prompt=' + encodeURIComponent(prompt) + '&aspect_ratio=' + encodeURIComponent(aspectRatio)
            })
            .then(response => response.json())
            .then(data => {
                if (data.error) {
                    alert('Error: ' + data.error);
                } else {
                    document.getElementById('video-id').value = data.generation_id;
                    checkVideoStatus(data.generation_id);
                }
            })
            .catch(error => console.error('Error:', error));
        }
        
        function checkVideoStatus(generationId) {
    fetch('/check_video_status/' + generationId)
    .then(response => response.json())
    .then(data => {
        if (data.error) {
            console.error('Error:', data.error);
            document.getElementById('video-status').textContent = 'Error: ' + data.error;
        } else {
            console.log('Video status:', data.status);
            document.getElementById('video-status').textContent = 'Video status: ' + data.status;
            
            if (data.status === 'completed' && data.video_url) {
                const video = document.getElementById('generated-video');
                video.src = data.video_url;
                video.style.display = 'block';
                document.getElementById('video-status').textContent = 'Video generation completed!';
            } else if (data.status === 'dreaming' || data.status === 'processing') {
                setTimeout(() => checkVideoStatus(generationId), 5000);
            } else {
                console.warn('Unexpected video status:', data.status);
            }
        }
    })
    .catch(error => {
        console.error('Error:', error);
        document.getElementById('video-status').textContent = 'An error occurred while checking the video status';
    });
}
        
        function addReaction(messageId, reaction) {
            fetch(`/add_reaction/${messageId}/${reaction}`, {method: 'GET'})
                .then(response => {
                    if (!response.ok) {
                        throw new Error('Network response was not ok');
                    }
                })
                .catch(error => console.error('Error:', error));
        }

        function createMessageElement(message) {
    console.log("Creating message element:", message);
    const messageElement = document.createElement('div');
    messageElement.className = 'message';
    messageElement.dataset.messageId = message.id;
    messageElement.innerHTML = `
        <div class="message-content">${message.content}</div>
        ${message.image_data ? `<img src="data:image/png;base64,${message.image_data}" alt="Generated Image" style="max-width: 100%; height: auto;">` : ''}
        ${message.video_url ? `<div class="video-container"><video src="${message.video_url}" controls style="max-width: 100%; height: auto;"></video></div>` : ''}
        <div class="message-meta">
            <span class="avatar">${message.avatar}</span>
            Posted by ${message.username} on ${message.timestamp}
        </div>
        <div class="message-tags">
            ${message.tags.map(tag => `<span class="tag">${tag}</span>`).join('')}
        </div>
        <div class="reactions"></div>
        <div class="comments-section"></div>
        <form class="comment-form" action="/post_comment/${message.id}" method="post">
            <input type="text" name="content" placeholder="Add a comment" required>
            <input type="submit" value="Post Comment">
        </form>
    `;
    console.log("Message element created:", messageElement.outerHTML);
    
    if (message.video_id && !message.video_url) {
        checkVideoStatus(message.video_id, messageElement);
    }
    
    return messageElement;
}

socket.on('new_message', function(message) {
    console.log("Received new message:", message);
    const messagesContainer = document.getElementById('messages-container');
    const newMessageElement = createMessageElement(message);
    messagesContainer.insertBefore(newMessageElement, messagesContainer.firstChild);
});
        
        socket.on('new_comment', function(comment) {
            const messageElement = document.querySelector(`[data-message-id="${comment.message_id}"]`);
            if (messageElement) {
                const commentsSection = messageElement.querySelector('.comments-section');
                const newCommentElement = document.createElement('div');
                newCommentElement.className = 'comment';
                newCommentElement.innerHTML = `
                    <div class="comment-content">${comment.content}</div>
                    <div class="comment-meta">
                        <span class="avatar">${comment.avatar}</span>
                        Posted by ${comment.username} on ${comment.timestamp}
                    </div>
                `;
                commentsSection.appendChild(newCommentElement);
            }
        });

        socket.on('reaction_update', function(data) {
            const messageElement = document.querySelector(`[data-message-id="${data.message_id}"]`);
            if (messageElement) {
                const reactionsElement = messageElement.querySelector('.reactions');
                if (reactionsElement) {
                    reactionsElement.innerHTML = '';
                    for (const [reaction, count] of Object.entries(data.reactions)) {
                        const button = document.createElement('button');
                        button.textContent = `${reaction} ${count}`;
                        button.onclick = () => addReaction(data.message_id, reaction);
                        reactionsElement.appendChild(button);
                    }
                }
            }
        });
    </script>
</body>
</html>
'''

LOGIN_HTML = '''
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - Rad Message Board</title>
    <style>
        body {
            font-family: 'Courier New', monospace;
            background-color: #000;
            color: #fff;
            margin: 0;
            padding: 20px;
        }
        .container {
            max-width: 400px;
            margin: 0 auto;
        }
        h1 {
            border-bottom: 4px solid #fff;
            padding-bottom: 10px;
        }
        form {
            border: 4px solid #fff;
            padding: 20px;
        }
        input[type="text"], input[type="password"] {
            width: 100%;
            padding: 10px;
            margin-bottom: 10px;
            background-color: #000;
            color: #fff;
            border: 2px solid #fff;
        }
        input[type="submit"] {
            background-color: #fff;
            color: #000;
            border: none;
            padding: 10px 20px;
            cursor: pointer;
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>Login</h1>
        <form action="{{ url_for('login') }}" method="post">
            <input type="text" name="username" placeholder="Username" required>
            <input type="password" name="password" placeholder="Password" required>
            <input type="submit" value="Login">
        </form>
    </div>
</body>
</html>
'''

REGISTER_HTML = '''
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Register - Rad Message Board</title>
    <style>
        body {
            font-family: 'Courier New', monospace;
            background-color: #000;
            color: #fff;
            margin: 0;
            padding: 20px;
        }
        .container {
            max-width: 400px;
            margin: 0 auto;
        }
        h1 {
            border-bottom: 4px solid #fff;
            padding-bottom: 10px;
        }
        form {
            border: 4px solid #fff;
            padding: 20px;
        }
        input[type="text"], input[type="password"], select {
            width: 100%;
            padding: 10px;
            margin-bottom: 10px;
            background-color: #000;
            color: #fff;
            border: 2px solid #fff;
        }
        input[type="submit"] {
            background-color: #fff;
            color: #000;
            border: none;
            padding: 10px 20px;
            cursor: pointer;
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>Register</h1>
        <form action="{{ url_for('register') }}" method="post">
            <input type="text" name="username" placeholder="Username" required>
            <input type="password" name="password" placeholder="Password" required>
            <select name="avatar" required>
                <option value="">Select Avatar</option>
                <option value="😊">😊</option>
                <option value="🤠">🤠</option>
                <option value="🤖">🤖</option>
                <option value="👽">👽</option>
                <option value="🦄">🦄</option>
            </select>
            <input type="submit" value="Register">
        </form>
    </div>
</body>
</html>
'''

PROFILE_HTML = '''
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ user[1] }}'s Profile - Rad Message Board</title>
    <style>
        body {
            font-family: 'Courier New', monospace;
            background-color: #000;
            color: #fff;
            margin: 0;
            padding: 20px;
        }
        .container {
            max-width: 800px;
            margin: 0 auto;
        }
        h1, h2 {
            border-bottom: 4px solid #fff;
            padding-bottom: 10px;
        }
        .message {
            border: 4px solid #fff;
            padding: 10px;
            margin-bottom: 20px;
        }
        .message-content {
            margin-bottom: 10px;
            word-wrap: break-word;
        }
        .message-meta {
            font-size: 0.8em;
            color: #ccc;
        }
        .avatar {
            font-size: 2em;
            margin-right: 10px;
        }
        .nav {
            margin-bottom: 20px;
        }
        .nav a {
            color: #fff;
            margin-right: 10px;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="nav">
            <a href="{{ url_for('index') }}">Home</a>
            <a href="{{ url_for('logout') }}">Logout</a>
        </div>
        <h1>{{ user[1] }}'s Profile</h1>
        <p><span class="avatar">{{ user[2] }}</span> {{ user[1] }}</p>
        <h2>Messages</h2>
        {% for message in messages %}
            <div class="message">
                <div class="message-content">{{ message[1] }}</div>
                {% if message[2] %}
                    <img src="data:image/png;base64,{{ message[2] }}" alt="Generated Image" style="max-width: 100%; height: auto;">
                {% endif %}
                {% if message[3] %}
                    <div class="video-container">
                        <video src="{{ message[3] }}" controls style="max-width: 100%; height: auto;"></video>
                    </div>
                {% endif %}
                <div class="message-meta">Posted on {{ message[4] }}</div>
            </div>
        {% endfor %}
    </div>
</body>
</html>
'''

@socketio.on('connect')
def handle_connect():
    print('Client connected')

@socketio.on('disconnect')
def handle_disconnect():
    print('Client disconnected')

if __name__ == '__main__':
    socketio.run(app, debug=True)