#!/usr/bin/env python3
"""
Claude AI Chatbot with Vision & RAG
A complete AI chatbot with image analysis and document search in one file
Requires: pip install flask anthropic pillow python-docx PyPDF2 sentence-transformers numpy
Set ANTHROPIC_API_KEY environment variable or edit API_KEY below
"""

import os
import sqlite3
import base64
import json
import hashlib
from datetime import datetime
from typing import List, Dict, Any
from flask import Flask, request, jsonify, render_template_string
import anthropic
from PIL import Image
import io
import PyPDF2
import docx
from sentence_transformers import SentenceTransformer
import numpy as np

# Configuration
API_KEY = os.environ.get('ANTHROPIC_API_KEY', 'your-api-key-here')  # Replace with your API key
DB_PATH = 'chatbot.db'
UPLOAD_FOLDER = 'uploads'

# Initialize Flask app
app = Flask(__name__)
app.secret_key = 'your-secret-key-here'

# Initialize Claude client
client = anthropic.Anthropic(api_key=API_KEY)

# Initialize embedding model for RAG
try:
    embedding_model = SentenceTransformer('all-MiniLM-L6-v2')
except:
    embedding_model = None
    print("Warning: Could not load embedding model. RAG features disabled.")

# Create uploads directory
os.makedirs(UPLOAD_FOLDER, exist_ok=True)

def init_database():
    """Initialize SQLite database with required tables"""
    conn = sqlite3.connect(DB_PATH)
    cursor = conn.cursor()
    
    # Chat messages table
    cursor.execute('''
        CREATE TABLE IF NOT EXISTS messages (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            session_id TEXT NOT NULL,
            role TEXT NOT NULL,
            content TEXT NOT NULL,
            image_data TEXT,
            timestamp DATETIME DEFAULT CURRENT_TIMESTAMP
        )
    ''')
    
    # Documents table for RAG
    cursor.execute('''
        CREATE TABLE IF NOT EXISTS documents (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            filename TEXT NOT NULL,
            content TEXT NOT NULL,
            embedding BLOB,
            file_hash TEXT UNIQUE,
            upload_time DATETIME DEFAULT CURRENT_TIMESTAMP
        )
    ''')
    
    conn.commit()
    conn.close()

def extract_text_from_file(file_path: str, filename: str) -> str:
    """Extract text from various file formats"""
    try:
        file_ext = filename.lower().split('.')[-1]
        
        if file_ext == 'pdf':
            with open(file_path, 'rb') as file:
                reader = PyPDF2.PdfReader(file)
                text = ""
                for page in reader.pages:
                    text += page.extract_text() + "\n"
                return text
        
        elif file_ext == 'docx':
            doc = docx.Document(file_path)
            text = ""
            for paragraph in doc.paragraphs:
                text += paragraph.text + "\n"
            return text
        
        elif file_ext in ['txt', 'md', 'py', 'js', 'html', 'css']:
            with open(file_path, 'r', encoding='utf-8') as file:
                return file.read()
        
        else:
            return f"Unsupported file type: {file_ext}"
    
    except Exception as e:
        return f"Error extracting text: {str(e)}"

def get_file_hash(file_path: str) -> str:
    """Generate hash for file deduplication"""
    with open(file_path, 'rb') as f:
        return hashlib.md5(f.read()).hexdigest()

def store_document(filename: str, content: str, file_hash: str):
    """Store document in database with embedding"""
    conn = sqlite3.connect(DB_PATH)
    cursor = conn.cursor()
    
    try:
        # Check if document already exists
        cursor.execute('SELECT id FROM documents WHERE file_hash = ?', (file_hash,))
        if cursor.fetchone():
            return False, "Document already exists"
        
        # Generate embedding if model is available
        embedding_data = None
        if embedding_model and content.strip():
            embedding = embedding_model.encode([content])[0]
            embedding_data = embedding.tobytes()
        
        cursor.execute('''
            INSERT INTO documents (filename, content, embedding, file_hash)
            VALUES (?, ?, ?, ?)
        ''', (filename, content, embedding_data, file_hash))
        
        conn.commit()
        return True, "Document stored successfully"
    
    except Exception as e:
        return False, f"Error storing document: {str(e)}"
    finally:
        conn.close()

def search_documents(query: str, limit: int = 3) -> List[Dict]:
    """Search documents using semantic similarity"""
    if not embedding_model:
        return []
    
    conn = sqlite3.connect(DB_PATH)
    cursor = conn.cursor()
    
    try:
        # Get all documents with embeddings
        cursor.execute('SELECT id, filename, content, embedding FROM documents WHERE embedding IS NOT NULL')
        documents = cursor.fetchall()
        
        if not documents:
            return []
        
        # Generate query embedding
        query_embedding = embedding_model.encode([query])[0]
        
        # Calculate similarities
        similarities = []
        for doc in documents:
            doc_embedding = np.frombuffer(doc[3], dtype=np.float32)
            similarity = np.dot(query_embedding, doc_embedding) / (
                np.linalg.norm(query_embedding) * np.linalg.norm(doc_embedding)
            )
            similarities.append((similarity, doc))
        
        # Sort by similarity and return top results
        similarities.sort(reverse=True)
        
        results = []
        for similarity, doc in similarities[:limit]:
            results.append({
                'filename': doc[1],
                'content': doc[2][:500] + "..." if len(doc[2]) > 500 else doc[2],
                'similarity': float(similarity)
            })
        
        return results
    
    except Exception as e:
        print(f"Search error: {e}")
        return []
    finally:
        conn.close()

def save_message(session_id: str, role: str, content: str, image_data: str = None):
    """Save message to database"""
    conn = sqlite3.connect(DB_PATH)
    cursor = conn.cursor()
    cursor.execute('''
        INSERT INTO messages (session_id, role, content, image_data)
        VALUES (?, ?, ?, ?)
    ''', (session_id, role, content, image_data))
    conn.commit()
    conn.close()

def get_chat_history(session_id: str, limit: int = 10) -> List[Dict]:
    """Get recent chat history"""
    conn = sqlite3.connect(DB_PATH)
    cursor = conn.cursor()
    cursor.execute('''
        SELECT role, content, image_data, timestamp FROM messages 
        WHERE session_id = ? 
        ORDER BY timestamp DESC LIMIT ?
    ''', (session_id, limit))
    
    messages = []
    for row in reversed(cursor.fetchall()):
        msg = {
            'role': row[0],
            'content': row[1],
            'timestamp': row[3]
        }
        if row[2]:  # image_data
            msg['image_data'] = row[2]
        messages.append(msg)
    
    conn.close()
    return messages

@app.route('/')
def index():
    """Main chat interface"""
    return render_template_string(HTML_TEMPLATE)

@app.route('/chat', methods=['POST'])
def chat():
    """Handle chat messages with Claude API"""
    try:
        data = request.json
        message = data.get('message', '')
        session_id = data.get('session_id', 'default')
        image_data = data.get('image_data', None)
        
        if not message.strip() and not image_data:
            return jsonify({'error': 'Empty message'}), 400
        
        # Search relevant documents for RAG
        relevant_docs = search_documents(message) if message.strip() else []
        
        # Build context from relevant documents
        context = ""
        if relevant_docs:
            context = "\n\nRelevant documents:\n"
            for doc in relevant_docs:
                context += f"\n--- {doc['filename']} (similarity: {doc['similarity']:.2f}) ---\n"
                context += doc['content'] + "\n"
        
        # Get chat history
        history = get_chat_history(session_id, 5)
        
        # Build messages for Claude API
        messages = []
        for hist_msg in history:
            if hist_msg['role'] in ['user', 'assistant']:
                messages.append({
                    'role': hist_msg['role'],
                    'content': hist_msg['content']
                })
        
        # Add current message
        current_content = message
        if context:
            current_content += context
        
        user_message = {'role': 'user', 'content': current_content}
        
        # Handle image if provided
        if image_data:
            # Convert base64 to proper format for Claude
            if ',' in image_data:
                image_data = image_data.split(',')[1]
            
            user_message['content'] = [
                {'type': 'text', 'text': current_content},
                {
                    'type': 'image',
                    'source': {
                        'type': 'base64',
                        'media_type': 'image/jpeg',
                        'data': image_data
                    }
                }
            ]
        
        messages.append(user_message)
        
        # Call Claude API
        response = client.messages.create(
            model="claude-3-5-sonnet-20241022",
            max_tokens=1000,
            messages=messages
        )
        
        assistant_response = response.content[0].text
        
        # Save messages to database
        save_message(session_id, 'user', message, image_data)
        save_message(session_id, 'assistant', assistant_response)
        
        return jsonify({
            'response': assistant_response,
            'relevant_docs': [{'filename': doc['filename'], 'similarity': doc['similarity']} for doc in relevant_docs]
        })
    
    except Exception as e:
        return jsonify({'error': f'Chat error: {str(e)}'}), 500

@app.route('/upload', methods=['POST'])
def upload_file():
    """Handle file uploads for RAG"""
    try:
        if 'file' not in request.files:
            return jsonify({'error': 'No file provided'}), 400
        
        file = request.files['file']
        if file.filename == '':
            return jsonify({'error': 'No file selected'}), 400
        
        # Save uploaded file
        filepath = os.path.join(UPLOAD_FOLDER, file.filename)
        file.save(filepath)
        
        # Extract text content
        content = extract_text_from_file(filepath, file.filename)
        
        # Generate file hash
        file_hash = get_file_hash(filepath)
        
        # Store in database
        success, message = store_document(file.filename, content, file_hash)
        
        # Clean up uploaded file
        os.remove(filepath)
        
        if success:
            return jsonify({'message': message, 'content_preview': content[:200] + '...' if len(content) > 200 else content})
        else:
            return jsonify({'error': message}), 400
    
    except Exception as e:
        return jsonify({'error': f'Upload error: {str(e)}'}), 500

@app.route('/documents')
def list_documents():
    """List all uploaded documents"""
    conn = sqlite3.connect(DB_PATH)
    cursor = conn.cursor()
    cursor.execute('SELECT filename, upload_time FROM documents ORDER BY upload_time DESC')
    docs = [{'filename': row[0], 'upload_time': row[1]} for row in cursor.fetchall()]
    conn.close()
    return jsonify({'documents': docs})

# HTML Template with embedded CSS and JavaScript
HTML_TEMPLATE = '''
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Claude AI Chatbot with Vision & RAG</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            height: 100vh;
            display: flex;
            justify-content: center;
            align-items: center;
        }
        
        .container {
            width: 90%;
            max-width: 800px;
            height: 90vh;
            background: rgba(255, 255, 255, 0.95);
            border-radius: 20px;
            box-shadow: 0 20px 40px rgba(0, 0, 0, 0.1);
            display: flex;
            flex-direction: column;
            overflow: hidden;
        }
        
        .header {
            background: linear-gradient(45deg, #ff6b6b, #ee5a24);
            color: white;
            padding: 20px;
            text-align: center;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
        }
        
        .header h1 {
            font-size: 24px;
            margin-bottom: 5px;
        }
        
        .header p {
            opacity: 0.9;
            font-size: 14px;
        }
        
        .chat-area {
            flex: 1;
            display: flex;
            flex-direction: column;
            overflow: hidden;
        }
        
        .messages {
            flex: 1;
            overflow-y: auto;
            padding: 20px;
            background: #f8f9fa;
        }
        
        .message {
            margin-bottom: 15px;
            display: flex;
            align-items: flex-start;
        }
        
        .message.user {
            justify-content: flex-end;
        }
        
        .message-content {
            max-width: 70%;
            padding: 12px 16px;
            border-radius: 18px;
            word-wrap: break-word;
        }
        
        .message.user .message-content {
            background: linear-gradient(45deg, #667eea, #764ba2);
            color: white;
        }
        
        .message.assistant .message-content {
            background: white;
            color: #333;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
        }
        
        .message-image {
            max-width: 200px;
            border-radius: 10px;
            margin-top: 8px;
        }
        
        .relevant-docs {
            background: #e3f2fd;
            border-left: 4px solid #2196f3;
            padding: 8px 12px;
            margin-top: 8px;
            border-radius: 0 8px 8px 0;
            font-size: 12px;
        }
        
        .input-area {
            padding: 20px;
            background: white;
            border-top: 1px solid #eee;
        }
        
        .upload-area {
            margin-bottom: 15px;
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
        }
        
        .file-input-wrapper {
            position: relative;
            overflow: hidden;
            display: inline-block;
        }
        
        .file-input {
            position: absolute;
            left: -9999px;
        }
        
        .file-input-label {
            background: #4CAF50;
            color: white;
            padding: 8px 16px;
            border-radius: 20px;
            cursor: pointer;
            font-size: 14px;
            transition: background 0.3s;
        }
        
        .file-input-label:hover {
            background: #45a049;
        }
        
        .image-input-label {
            background: #ff9800;
        }
        
        .image-input-label:hover {
            background: #f57c00;
        }
        
        .message-form {
            display: flex;
            gap: 10px;
        }
        
        .message-input {
            flex: 1;
            padding: 12px 16px;
            border: 2px solid #ddd;
            border-radius: 25px;
            outline: none;
            font-size: 16px;
            transition: border-color 0.3s;
        }
        
        .message-input:focus {
            border-color: #667eea;
        }
        
        .send-btn {
            background: linear-gradient(45deg, #667eea, #764ba2);
            color: white;
            border: none;
            padding: 12px 24px;
            border-radius: 25px;
            cursor: pointer;
            font-size: 16px;
            transition: transform 0.2s;
        }
        
        .send-btn:hover {
            transform: translateY(-2px);
        }
        
        .send-btn:disabled {
            opacity: 0.6;
            cursor: not-allowed;
            transform: none;
        }
        
        .loading {
            display: none;
            text-align: center;
            padding: 10px;
            color: #666;
        }
        
        .status {
            padding: 10px;
            margin: 10px 0;
            border-radius: 8px;
            font-size: 14px;
        }
        
        .status.success {
            background: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
        }
        
        .status.error {
            background: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
        }
        
        .image-preview {
            max-width: 100px;
            max-height: 100px;
            border-radius: 8px;
            margin: 5px 0;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>🤖 Claude AI Chatbot</h1>
            <p>With Vision Analysis & Document Search (RAG)</p>
        </div>
        
        <div class="chat-area">
            <div class="messages" id="messages">
                <div class="message assistant">
                    <div class="message-content">
                        👋 Hello! I'm Claude, your AI assistant with vision and document search capabilities. 
                        You can:
                        <br>• Chat with me about anything
                        <br>• Upload images for analysis
                        <br>• Upload documents (PDF, DOCX, TXT) for intelligent search
                        <br>• Ask questions about your uploaded documents
                    </div>
                </div>
            </div>
            
            <div class="loading" id="loading">
                🤔 Claude is thinking...
            </div>
        </div>
        
        <div class="input-area">
            <div class="upload-area">
                <div class="file-input-wrapper">
                    <input type="file" id="fileInput" class="file-input" accept=".pdf,.docx,.txt,.md,.py,.js,.html,.css">
                    <label for="fileInput" class="file-input-label">📄 Upload Document</label>
                </div>
                
                <div class="file-input-wrapper">
                    <input type="file" id="imageInput" class="file-input" accept="image/*">
                    <label for="imageInput" class="file-input-label image-input-label">🖼️ Upload Image</label>
                </div>
            </div>
            
            <div id="status"></div>
            <div id="imagePreview"></div>
            
            <form class="message-form" id="messageForm">
                <input type="text" id="messageInput" class="message-input" 
                       placeholder="Ask me anything, or upload files for analysis..." required>
                <button type="submit" class="send-btn" id="sendBtn">Send</button>
            </form>
        </div>
    </div>

    <script>
        let currentImage = null;
        const sessionId = 'session_' + Date.now();
        
        // DOM elements
        const messagesDiv = document.getElementById('messages');
        const messageForm = document.getElementById('messageForm');
        const messageInput = document.getElementById('messageInput');
        const sendBtn = document.getElementById('sendBtn');
        const loading = document.getElementById('loading');
        const status = document.getElementById('status');
        const fileInput = document.getElementById('fileInput');
        const imageInput = document.getElementById('imageInput');
        const imagePreview = document.getElementById('imagePreview');
        
        // Show status message
        function showStatus(message, isError = false) {
            status.innerHTML = `<div class="status ${isError ? 'error' : 'success'}">${message}</div>`;
            setTimeout(() => status.innerHTML = '', 3000);
        }
        
        // Add message to chat
        function addMessage(role, content, imageData = null, relevantDocs = null) {
            const messageDiv = document.createElement('div');
            messageDiv.className = `message ${role}`;
            
            let messageContent = `<div class="message-content">${content.replace(/\\n/g, '<br>')}</div>`;
            
            if (imageData) {
                messageContent += `<img src="${imageData}" class="message-image" alt="Uploaded image">`;
            }
            
            if (relevantDocs && relevantDocs.length > 0) {
                const docsList = relevantDocs.map(doc => 
                    `${doc.filename} (${(doc.similarity * 100).toFixed(1)}% match)`
                ).join(', ');
                messageContent += `<div class="relevant-docs">📚 Referenced documents: ${docsList}</div>`;
            }
            
            messageDiv.innerHTML = messageContent;
            messagesDiv.appendChild(messageDiv);
            messagesDiv.scrollTop = messagesDiv.scrollHeight;
        }
        
        // Handle file upload
        fileInput.addEventListener('change', async (e) => {
            const file = e.target.files[0];
            if (!file) return;
            
            const formData = new FormData();
            formData.append('file', file);
            
            try {
                showStatus('Uploading document...');
                const response = await fetch('/upload', {
                    method: 'POST',
                    body: formData
                });
                
                const result = await response.json();
                
                if (response.ok) {
                    showStatus(`Document "${file.name}" uploaded successfully!`);
                } else {
                    showStatus(result.error, true);
                }
            } catch (error) {
                showStatus('Upload failed: ' + error.message, true);
            }
            
            fileInput.value = '';
        });
        
        // Handle image upload
        imageInput.addEventListener('change', (e) => {
            const file = e.target.files[0];
            if (!file) return;
            
            const reader = new FileReader();
            reader.onload = (e) => {
                currentImage = e.target.result;
                imagePreview.innerHTML = `
                    <img src="${currentImage}" class="image-preview" alt="Preview">
                    <button onclick="clearImage()" style="margin-left: 10px; padding: 5px 10px; border: none; background: #ff4757; color: white; border-radius: 5px; cursor: pointer;">Remove</button>
                `;
                showStatus('Image ready to send!');
            };
            reader.readAsDataURL(file);
        });
        
        // Clear image preview
        function clearImage() {
            currentImage = null;
            imagePreview.innerHTML = '';
            imageInput.value = '';
        }
        
        // Handle message submission
        messageForm.addEventListener('submit', async (e) => {
            e.preventDefault();
            
            const message = messageInput.value.trim();
            if (!message && !currentImage) return;
            
            // Disable form
            sendBtn.disabled = true;
            loading.style.display = 'block';
            
            // Add user message
            addMessage('user', message, currentImage);
            
            try {
                const response = await fetch('/chat', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json'
                    },
                    body: JSON.stringify({
                        message: message,
                        session_id: sessionId,
                        image_data: currentImage
                    })
                });
                
                const result = await response.json();
                
                if (response.ok) {
                    addMessage('assistant', result.response, null, result.relevant_docs);
                } else {
                    addMessage('assistant', '❌ Error: ' + result.error);
                }
            } catch (error) {
                addMessage('assistant', '❌ Connection error: ' + error.message);
            }
            
            // Reset form
            messageInput.value = '';
            clearImage();
            sendBtn.disabled = false;
            loading.style.display = 'none';
        });
        
        // Auto-resize textarea
        messageInput.addEventListener('input', function() {
            this.style.height = 'auto';
            this.style.height = this.scrollHeight + 'px';
        });
    </script>
</body>
</html>
'''

if __name__ == '__main__':
    # Initialize database on startup
    init_database()
    print("🚀 Claude AI Chatbot starting...")
    print("📄 Features: Chat, Vision Analysis, Document RAG")
    print("🔗 Open: http://localhost:5000")
    
    if API_KEY == 'your-api-key-here':
        print("⚠️  WARNING: Please set your ANTHROPIC_API_KEY environment variable!")
    
    app.run(debug=True, host='0.0.0.0', port=5000)
