from flask import Flask, render_template, render_template_string, request, send_file, jsonify
from PIL import Image
import os
import io
import uuid
import base64
import shutil
import tempfile

app = Flask(__name__)

# Create a temporary directory for storing uploaded images
TEMP_DIR = tempfile.mkdtemp()

@app.route('/')
def index():
    return render_template_string(HTML_TEMPLATE)

@app.route('/upload', methods=['POST'])
def upload():
    try:
        # Create a unique session ID for this set of uploads
        session_id = str(uuid.uuid4())
        session_dir = os.path.join(TEMP_DIR, session_id)
        os.makedirs(session_dir, exist_ok=True)
        
        # Get uploaded files
        files = request.files.getlist('images')
        
        # Save each file
        file_paths = []
        for i, file in enumerate(files):
            if file.filename:
                # Ensure it's a PNG file
                if not file.filename.lower().endswith('.png'):
                    return jsonify({'error': 'Only PNG files are allowed'}), 400
                
                try:
                    # Verify it's a valid image
                    img = Image.open(file)
                    img.verify()  # Verify it's a valid image
                    file.seek(0)  # Reset file pointer after verification
                    
                    # Save file with a numeric prefix for ordering
                    file_path = os.path.join(session_dir, f"{i:03d}_{file.filename}")
                    file.save(file_path)
                    file_paths.append(file_path)
                except Exception:
                    return jsonify({'error': f'Invalid image file: {file.filename}'}), 400
        
        if not file_paths:
            return jsonify({'error': 'No valid files uploaded'}), 400
            
        return jsonify({
            'session_id': session_id,
            'file_count': len(file_paths)
        })
    except Exception as e:
        return jsonify({'error': str(e)}), 500

@app.route('/create-gif', methods=['POST'])
def create_gif():
    try:
        data = request.json
        session_id = data.get('session_id')
        delay = int(data.get('delay', 100))  # Default 100ms
        loop = data.get('loop', True)
        width = data.get('width')
        height = data.get('height')
        
        # Get the session directory
        session_dir = os.path.join(TEMP_DIR, session_id)
        if not os.path.exists(session_dir):
            return jsonify({'error': 'Session not found'}), 404
        
        # Get all PNG files in the directory
        files = [f for f in os.listdir(session_dir) if f.lower().endswith('.png')]
        
        if not files:
            return jsonify({'error': 'No images found in session'}), 400
        
        # Sort files by name (which includes the numeric prefix)
        files.sort()
        
        # Open all images
        images = []
        for file in files:
            img_path = os.path.join(session_dir, file)
            try:
                img = Image.open(img_path)
                
                # Resize if dimensions are provided
                if width and height:
                    img = img.resize((int(width), int(height)))
                    
                # Convert to RGB if image has an alpha channel
                if img.mode == 'RGBA':
                    background = Image.new('RGB', img.size, (255, 255, 255))
                    background.paste(img, mask=img.split()[3])  # Use alpha channel as mask
                    img = background
                elif img.mode != 'RGB':
                    img = img.convert('RGB')
                    
                images.append(img)
            except Exception as e:
                return jsonify({'error': f'Error processing image {file}: {str(e)}'}), 500
        
        if not images:
            return jsonify({'error': 'No valid images to process'}), 400
        
        # Create an in-memory GIF
        output = io.BytesIO()
        loop_value = 0 if loop else 1  # 0 = infinite loop, 1 = play once
        
        images[0].save(
            output, 
            format='GIF',
            save_all=True,
            append_images=images[1:],
            loop=loop_value,
            duration=delay,
            optimize=True
        )
        output.seek(0)
        
        # Convert to base64 for preview
        gif_base64 = base64.b64encode(output.getvalue()).decode('utf-8')
        
        # Save the GIF in the session directory for download
        gif_path = os.path.join(session_dir, 'animated.gif')
        with open(gif_path, 'wb') as f:
            f.write(output.getvalue())
        
        return jsonify({
            'success': True,
            'gif_data': f'data:image/gif;base64,{gif_base64}',
            'gif_filename': 'animated.gif',
            'session_id': session_id
        })
    except Exception as e:
        return jsonify({'error': str(e)}), 500

@app.route('/download/<session_id>')
def download(session_id):
    try:
        gif_path = os.path.join(TEMP_DIR, session_id, 'animated.gif')
        if not os.path.exists(gif_path):
            return "GIF not found", 404
        
        return send_file(gif_path, as_attachment=True, download_name='animated.gif')
    except Exception as e:
        return str(e), 500

@app.route('/reorder', methods=['POST'])
def reorder():
    try:
        data = request.json
        session_id = data.get('session_id')
        new_order = data.get('order', [])
        
        session_dir = os.path.join(TEMP_DIR, session_id)
        if not os.path.exists(session_dir):
            return jsonify({'error': 'Session not found'}), 404
        
        # Get all PNG files in the directory
        files = [f for f in os.listdir(session_dir) if f.lower().endswith('.png')]
        files.sort()
        
        if len(files) != len(new_order):
            return jsonify({'error': 'Order list length doesn\'t match number of files'}), 400
        
        # Rename files with temporary names first (to avoid conflicts)
        for i, idx in enumerate(new_order):
            if idx < 0 or idx >= len(files):
                return jsonify({'error': f'Invalid index in order list: {idx}'}), 400
                
            old_name = files[idx]
            temp_name = f"temp_{i:03d}_{old_name}"
            os.rename(
                os.path.join(session_dir, old_name),
                os.path.join(session_dir, temp_name)
            )
        
        # Rename files to their final names
        temp_files = [f for f in os.listdir(session_dir) if f.startswith('temp_') and f.lower().endswith('.png')]
        temp_files.sort()
        
        for i, temp_name in enumerate(temp_files):
            # Extract the original filename part (after the temp prefix and original prefix)
            original_name = '_'.join(temp_name.split('_')[2:])
            new_name = f"{i:03d}_{original_name}"
            
            os.rename(
                os.path.join(session_dir, temp_name),
                os.path.join(session_dir, new_name)
            )
            
        return jsonify({'success': True})
    except Exception as e:
        return jsonify({'error': str(e)}), 500

@app.route('/remove/<session_id>/<filename>')
def remove_frame(session_id, filename):
    try:
        file_path = os.path.join(TEMP_DIR, session_id, filename)
        if not os.path.exists(file_path):
            return jsonify({'error': 'File not found'}), 404
            
        os.remove(file_path)
        
        # Reindex remaining files
        session_dir = os.path.join(TEMP_DIR, session_id)
        files = [f for f in os.listdir(session_dir) if f.lower().endswith('.png')]
        files.sort()
        
        for i, old_name in enumerate(files):
            # Extract the original filename part (after the original prefix)
            original_name = '_'.join(old_name.split('_')[1:])
            new_name = f"{i:03d}_{original_name}"
            
            if old_name != new_name:
                os.rename(
                    os.path.join(session_dir, old_name),
                    os.path.join(session_dir, new_name)
                )
                
        return jsonify({
            'success': True,
            'remaining_count': len(files)
        })
    except Exception as e:
        return jsonify({'error': str(e)}), 500

# HTML template with CSS and JavaScript
HTML_TEMPLATE = '''
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Animated GIF Creator</title>
    <style>
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            margin: 0;
            padding: 20px;
            background-color: #f5f5f5;
            color: #333;
        }
        
        .container {
            max-width: 800px;
            margin: 0 auto;
            background-color: white;
            padding: 30px;
            border-radius: 10px;
            box-shadow: 0 2px 15px rgba(0, 0, 0, 0.1);
        }
        
        h1 {
            text-align: center;
            color: #2c3e50;
            margin-top: 0;
            margin-bottom: 30px;
        }
        
        .upload-area {
            border: 2px dashed #3498db;
            border-radius: 8px;
            padding: 30px;
            text-align: center;
            margin-bottom: 30px;
            background-color: #f8fafc;
            cursor: pointer;
            transition: all 0.3s ease;
        }
        
        .upload-area:hover, .upload-area.dragover {
            background-color: #e8f4fe;
            border-color: #2980b9;
        }
        
        .controls {
            margin-bottom: 30px;
            padding: 20px;
            background-color: #f8fafc;
            border-radius: 8px;
            border: 1px solid #e1e1e1;
        }
        
        .control-group {
            margin-bottom: 15px;
        }
        
        label {
            display: block;
            margin-bottom: 8px;
            font-weight: 600;
            color: #2c3e50;
        }
        
        input[type="range"] {
            width: 100%;
        }
        
        .value-display {
            display: inline-block;
            margin-left: 10px;
            font-weight: bold;
            color: #3498db;
        }
        
        .btn {
            display: block;
            width: 100%;
            padding: 12px;
            border: none;
            border-radius: 5px;
            font-size: 1rem;
            font-weight: 600;
            cursor: pointer;
            transition: background-color 0.3s;
            margin-bottom: 15px;
            color: white;
        }
        
        .create-btn {
            background-color: #3498db;
        }
        
        .create-btn:hover {
            background-color: #2980b9;
        }
        
        .download-btn {
            background-color: #27ae60;
        }
        
        .download-btn:hover {
            background-color: #219653;
        }
        
        .create-btn:disabled {
            background-color: #95a5a6;
            cursor: not-allowed;
        }
        
        .frames-container {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
            margin: 20px 0;
            min-height: 50px;
        }
        
        .frame-item {
            position: relative;
            border: 1px solid #ddd;
            border-radius: 6px;
            overflow: hidden;
            width: 100px;
            height: 100px;
            background-color: #f8f9fa;
            transition: transform 0.2s;
            cursor: grab;
        }
        
        .frame-item:hover {
            transform: scale(1.05);
            box-shadow: 0 3px 10px rgba(0, 0, 0, 0.1);
        }
        
        .frame-item img {
            width: 100%;
            height: 100%;
            object-fit: contain;
        }
        
        .delete-frame {
            position: absolute;
            top: 5px;
            right: 5px;
            background-color: rgba(231, 76, 60, 0.8);
            color: white;
            border: none;
            border-radius: 50%;
            width: 24px;
            height: 24px;
            font-size: 14px;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            opacity: 0;
            transition: opacity 0.2s;
        }
        
        .frame-item:hover .delete-frame {
            opacity: 1;
        }
        
        .preview-area {
            margin-top: 30px;
            text-align: center;
            padding: 20px;
            border-radius: 8px;
            background-color: #f8fafc;
            border: 1px solid #e1e1e1;
        }
        
        .result-gif {
            max-width: 100%;
            max-height: 300px;
            border: 1px solid #ddd;
            border-radius: 8px;
            margin: 0 auto;
        }
        
        .message {
            text-align: center;
            margin: 15px 0;
            padding: 12px;
            border-radius: 5px;
        }
        
        .error-message {
            background-color: #fdeded;
            color: #b71c1c;
            border: 1px solid #f5c6cb;
        }
        
        .success-message {
            background-color: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
        }
        
        .loader {
            border: 4px solid #f3f3f3;
            border-top: 4px solid #3498db;
            border-radius: 50%;
            width: 40px;
            height: 40px;
            animation: spin 1s linear infinite;
            margin: 20px auto;
        }
        
        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }
        
        .hidden {
            display: none;
        }
        
        .dragging {
            opacity: 0.5;
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>Animated GIF Creator</h1>
        
        <div id="upload-area" class="upload-area">
            <p>Drag & drop PNG images here or click to select files</p>
            <input type="file" id="file-input" multiple accept=".png" style="display: none;">
        </div>
        
        <h2>Frames</h2>
        <div class="frames-container" id="frames-preview"></div>
        
        <div class="controls">
            <h2>GIF Settings</h2>
            
            <div class="control-group">
                <label for="delay-slider">Frame Delay (ms): <span id="delay-value" class="value-display">100</span></label>
                <input type="range" id="delay-slider" min="10" max="1000" value="100" step="10">
            </div>
            
            <div class="control-group">
                <label>
                    <input type="checkbox" id="resize-checkbox">
                    Resize images
                </label>
                
                <div id="resize-controls" class="hidden" style="margin-top: 10px; padding-left: 10px;">
                    <div class="control-group">
                        <label for="width-input">Width (px): <span id="width-value" class="value-display">200</span></label>
                        <input type="range" id="width-input" min="50" max="800" value="200" step="10">
                    </div>
                    
                    <div class="control-group">
                        <label for="height-input">Height (px): <span id="height-value" class="value-display">200</span></label>
                        <input type="range" id="height-input" min="50" max="800" value="200" step="10">
                    </div>
                </div>
            </div>
            
            <div class="control-group">
                <label>
                    <input type="checkbox" id="loop-checkbox" checked>
                    Loop GIF
                </label>
            </div>
        </div>
        
        <button id="create-btn" class="btn create-btn" disabled>Create Animated GIF</button>
        <button id="download-btn" class="btn download-btn hidden">Download GIF</button>
        
        <div id="loader" class="loader hidden"></div>
        <div id="error-message" class="message error-message hidden"></div>
        <div id="success-message" class="message success-message hidden"></div>
        
        <div class="preview-area">
            <h2>Preview</h2>
            <img id="gif-preview" class="result-gif hidden" alt="GIF Preview">
        </div>
    </div>
    
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            // DOM elements
            const uploadArea = document.getElementById('upload-area');
            const fileInput = document.getElementById('file-input');
            const framesPreview = document.getElementById('frames-preview');
            const delaySlider = document.getElementById('delay-slider');
            const delayValue = document.getElementById('delay-value');
            const resizeCheckbox = document.getElementById('resize-checkbox');
            const resizeControls = document.getElementById('resize-controls');
            const widthInput = document.getElementById('width-input');
            const widthValue = document.getElementById('width-value');
            const heightInput = document.getElementById('height-input');
            const heightValue = document.getElementById('height-value');
            const loopCheckbox = document.getElementById('loop-checkbox');
            const createBtn = document.getElementById('create-btn');
            const downloadBtn = document.getElementById('download-btn');
            const loader = document.getElementById('loader');
            const errorMessage = document.getElementById('error-message');
            const successMessage = document.getElementById('success-message');
            const gifPreview = document.getElementById('gif-preview');
            
            // Variables for application state
            let files = [];
            let sessionId = null;
            let draggingIndex = null;
            
            // Event Listeners
            uploadArea.addEventListener('click', () => fileInput.click());
            
            uploadArea.addEventListener('dragover', (e) => {
                e.preventDefault();
                uploadArea.classList.add('dragover');
            });
            
            uploadArea.addEventListener('dragleave', () => {
                uploadArea.classList.remove('dragover');
            });
            
            uploadArea.addEventListener('drop', (e) => {
                e.preventDefault();
                uploadArea.classList.remove('dragover');
                
                const droppedFiles = Array.from(e.dataTransfer.files).filter(file => 
                    file.type === 'image/png'
                );
                
                if (droppedFiles.length > 0) {
                    handleFiles(droppedFiles);
                } else {
                    showError('Please drop only PNG files');
                }
            });
            
            fileInput.addEventListener('change', (e) => {
                const selectedFiles = Array.from(e.target.files).filter(file => 
                    file.type === 'image/png'
                );
                
                if (selectedFiles.length > 0) {
                    handleFiles(selectedFiles);
                } else {
                    showError('Please select only PNG files');
                }
            });
            
            delaySlider.addEventListener('input', () => {
                delayValue.textContent = delaySlider.value;
            });
            
            resizeCheckbox.addEventListener('change', () => {
                resizeControls.classList.toggle('hidden', !resizeCheckbox.checked);
            });
            
            widthInput.addEventListener('input', () => {
                widthValue.textContent = widthInput.value;
            });
            
            heightInput.addEventListener('input', () => {
                heightValue.textContent = heightInput.value;
            });
            
            createBtn.addEventListener('click', createGif);
            
            downloadBtn.addEventListener('click', () => {
                if (sessionId) {
                    window.location.href = `/download/${sessionId}`;
                }
            });
            
            // Functions
            function handleFiles(newFiles) {
                files = [...files, ...newFiles];
                updateFramesPreview();
                uploadFiles();
            }
            
            function updateFramesPreview() {
                framesPreview.innerHTML = '';
                
                files.forEach((file, index) => {
                    const reader = new FileReader();
                    reader.onload = (e) => {
                        const frameItem = document.createElement('div');
                        frameItem.className = 'frame-item';
                        frameItem.setAttribute('data-index', index);
                        frameItem.draggable = true;
                        
                        const img = document.createElement('img');
                        img.src = e.target.result;
                        img.alt = `Frame ${index + 1}`;
                        
                        const deleteBtn = document.createElement('button');
                        deleteBtn.className = 'delete-frame';
                        deleteBtn.innerHTML = '×';
                        deleteBtn.title = 'Delete frame';
                        deleteBtn.addEventListener('click', (e) => {
                            e.stopPropagation();
                            files = files.filter((_, i) => i !== index);
                            updateFramesPreview();
                            if (sessionId) {
                                // Update server if we have a session
                                fetch(`/remove/${sessionId}/${file.name}`).catch(console.error);
                            }
                        });
                        
                        frameItem.appendChild(img);
                        frameItem.appendChild(deleteBtn);
                        framesPreview.appendChild(frameItem);
                        
                        // Add drag and drop event listeners for reordering
                        frameItem.addEventListener('dragstart', (e) => {
                            draggingIndex = index;
                            setTimeout(() => frameItem.classList.add('dragging'), 0);
                        });
                        
                        frameItem.addEventListener('dragend', () => {
                            frameItem.classList.remove('dragging');
                            draggingIndex = null;
                        });
                        
                        frameItem.addEventListener('dragover', (e) => {
                            e.preventDefault();
                            if (draggingIndex !== null && draggingIndex !== index) {
                                frameItem.classList.add('dragover');
                            }
                        });
                        
                        frameItem.addEventListener('dragleave', () => {
                            frameItem.classList.remove('dragover');
                        });
                        
                        frameItem.addEventListener('drop', (e) => {
                            e.preventDefault();
                            frameItem.classList.remove('dragover');
                            
                            if (draggingIndex !== null && draggingIndex !== index) {
                                // Update the files array
                                const temp = files[draggingIndex];
                                files.splice(draggingIndex, 1);
                                files.splice(index, 0, temp);
                                
                                updateFramesPreview();
                                
                                // If we already have a session, update the server-side order
                                if (sessionId) {
                                    const newOrder = files.map((_, i) => i);
                                    fetch('/reorder', {
                                        method: 'POST',
                                        headers: {
                                            'Content-Type': 'application/json'
                                        },
                                        body: JSON.stringify({
                                            session_id: sessionId,
                                            order: newOrder
                                        })
                                    }).catch(console.error);
                                }
                            }
                        });
                    };
                    reader.readAsDataURL(file);
                });
                
                createBtn.disabled = files.length === 0;
            }
            
            function uploadFiles() {
                if (files.length === 0) {
                    sessionId = null;
                    return;
                }
                
                showLoader();
                hideError();
                hideSuccess();
                downloadBtn.classList.add('hidden');
                gifPreview.classList.add('hidden');
                
                const formData = new FormData();
                files.forEach(file => {
                    formData.append('images', file);
                });
                
                fetch('/upload', {
                    method: 'POST',
                    body: formData
                })
                .then(response => response.json())
                .then(data => {
                    hideLoader();
                    
                    if (data.error) {
                        showError(data.error);
                        return;
                    }
                    
                    sessionId = data.session_id;
                    createBtn.disabled = false;
                    showSuccess(`${data.file_count} images uploaded successfully`);
                })
                .catch(error => {
                    hideLoader();
                    showError('Error uploading files: ' + error.message);
                });
            }
            
            function createGif() {
                if (!sessionId) {
                    showError('Please upload images first');
                    return;
                }
                
                showLoader();
                hideError();
                hideSuccess();
                downloadBtn.classList.add('hidden');
                gifPreview.classList.add('hidden');
                
                const options = {
                    session_id: sessionId,
                    delay: parseInt(delaySlider.value),
                    loop: loopCheckbox.checked
                };
                
                if (resizeCheckbox.checked) {
                    options.width = parseInt(widthInput.value);
                    options.height = parseInt(heightInput.value);
                }
                
                fetch('/create-gif', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json'
                    },
                    body: JSON.stringify(options)
                })
                .then(response => response.json())
                .then(data => {
                    hideLoader();
                    
                    if (data.error) {
                        showError(data.error);
                        return;
                    }
                    
                    showSuccess('GIF created successfully!');
                    downloadBtn.classList.remove('hidden');
                    
                    // Show the GIF preview
                    gifPreview.src = data.gif_data;
                    gifPreview.classList.remove('hidden');
                })
                .catch(error => {
                    hideLoader();
                    showError('Error creating GIF: ' + error.message);
                });
            }
            
            function showLoader() {
                loader.classList.remove('hidden');
            }
            
            function hideLoader() {
                loader.classList.add('hidden');
            }
            
            function showError(message) {
                errorMessage.textContent = message;
                errorMessage.classList.remove('hidden');
                successMessage.classList.add('hidden');
            }
            
            function hideError() {
                errorMessage.classList.add('hidden');
            }
            
            function showSuccess(message) {
                successMessage.textContent = message;
                successMessage.classList.remove('hidden');
                errorMessage.classList.add('hidden');
            }
            
            function hideSuccess() {
                successMessage.classList.add('hidden');
            }
        });
    </script>
</body>
</html>
'''

if __name__ == '__main__':
    try:
        # Create temp directory if it doesn't exist
        os.makedirs(TEMP_DIR, exist_ok=True)
        
        # Run the Flask app
        app.run(debug=True)
    finally:
        # Cleanup when the application exits
        if os.path.exists(TEMP_DIR):
            shutil.rmtree(TEMP_DIR)