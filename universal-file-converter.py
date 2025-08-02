import streamlit as st
import mimetypes
from PIL import Image, ImageFilter, ImageEnhance
import pandas as pd
import docx2txt
from PyPDF2 import PdfReader
import csv
import json
import yaml
import xmltodict
import openpyxl
from io import BytesIO, StringIO
import base64
import time
import subprocess
import os
import zipfile
import tarfile
import gzip
import bz2
import lzma
from pathlib import Path
import tempfile
import shutil
from typing import Dict, List, Optional, Tuple
import logging
from datetime import datetime
import hashlib
try:
    import magic
except ImportError:
    magic = None
try:
    import ffmpeg
except ImportError:
    ffmpeg = None
try:
    from reportlab.pdfgen import canvas
    from reportlab.lib.pagesizes import letter, A4
    from reportlab.lib.styles import getSampleStyleSheet
    from reportlab.platypus import SimpleDocTemplate, Paragraph, Spacer
except ImportError:
    canvas = None
try:
    import markdown
except ImportError:
    markdown = None
try:
    import pypandoc
except ImportError:
    pypandoc = None

# Set page config
st.set_page_config(
    page_title="File Convert Anything",
    page_icon="🔄",
    layout="wide",
    initial_sidebar_state="expanded"
)

# Modern Shadcn-inspired theme
st.markdown("""
<style>
    @import url('https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap');
    
    * {
        font-family: 'Inter', sans-serif;
    }
    
    .stApp {
        background-color: #000000;
        color: #FFFFFF;
    }
    
    .main {
        padding: 2rem;
    }
    
    /* Header styling */
    .main-header {
        text-align: center;
        padding: 2rem 0;
        border-bottom: 1px solid #27272a;
        margin-bottom: 2rem;
    }
    
    .main-title {
        font-size: 3rem;
        font-weight: 700;
        background: linear-gradient(135deg, #ffffff 0%, #a1a1aa 100%);
        -webkit-background-clip: text;
        -webkit-text-fill-color: transparent;
        margin-bottom: 0.5rem;
    }
    
    .main-subtitle {
        font-size: 1.2rem;
        color: #a1a1aa;
        font-weight: 400;
    }
    
    /* Card styling */
    .upload-card, .conversion-card, .preview-card {
        background: #09090b;
        border: 1px solid #27272a;
        border-radius: 12px;
        padding: 1.5rem;
        margin: 1rem 0;
        box-shadow: 0 4px 6px -1px rgb(0 0 0 / 0.1);
    }
    
    .card-title {
        font-size: 1.25rem;
        font-weight: 600;
        margin-bottom: 1rem;
        color: #ffffff;
    }
    
    /* Input styling */
    .stSelectbox > div > div {
        background-color: #18181b;
        border: 1px solid #27272a;
        border-radius: 8px;
        color: #ffffff;
    }
    
    .stTextInput > div > div > input {
        background-color: #18181b;
        border: 1px solid #27272a;
        border-radius: 8px;
        color: #ffffff;
    }
    
    .stNumberInput > div > div > input {
        background-color: #18181b;
        border: 1px solid #27272a;
        border-radius: 8px;
        color: #ffffff;
    }
    
    /* Button styling */
    .stButton > button {
        background-color: #ffffff;
        color: #000000;
        border: none;
        border-radius: 8px;
        font-weight: 500;
        padding: 0.5rem 1rem;
        transition: all 0.2s;
        width: 100%;
    }
    
    .stButton > button:hover {
        background-color: #f4f4f5;
        transform: translateY(-1px);
        box-shadow: 0 4px 8px -2px rgb(0 0 0 / 0.2);
    }
    
    /* Progress bar */
    .stProgress > div > div > div {
        background-color: #ffffff;
        border-radius: 4px;
    }
    
    /* File uploader */
    .stFileUploader > div > div {
        background-color: #18181b;
        border: 2px dashed #27272a;
        border-radius: 12px;
        padding: 2rem;
        text-align: center;
        transition: all 0.2s;
    }
    
    .stFileUploader > div > div:hover {
        border-color: #ffffff;
        background-color: #27272a;
    }
    
    /* Metrics */
    .metric-container {
        background: #18181b;
        border: 1px solid #27272a;
        border-radius: 8px;
        padding: 1rem;
        text-align: center;
    }
    
    /* Sidebar */
    .css-1d391kg {
        background-color: #09090b;
        border-right: 1px solid #27272a;
    }
    
    /* Hide default streamlit styling */
    #MainMenu {visibility: hidden;}
    footer {visibility: hidden;}
    header {visibility: hidden;}
    
    /* Custom scrollbar */
    ::-webkit-scrollbar {
        width: 8px;
    }
    
    ::-webkit-scrollbar-track {
        background: #18181b;
    }
    
    ::-webkit-scrollbar-thumb {
        background: #27272a;
        border-radius: 4px;
    }
    
    ::-webkit-scrollbar-thumb:hover {
        background: #3f3f46;
    }
</style>
""", unsafe_allow_html=True)

def get_file_info(file) -> Dict[str, str]:
    """Extract comprehensive file information."""
    file_size = file.size
    
    # Convert bytes to human readable format
    def format_bytes(bytes_val):
        for unit in ['B', 'KB', 'MB', 'GB', 'TB']:
            if bytes_val < 1024.0:
                return f"{bytes_val:.1f} {unit}"
            bytes_val /= 1024.0
        return f"{bytes_val:.1f} PB"
    
    # Generate file hash for integrity checking
    file_content = file.getvalue()
    file_hash = hashlib.md5(file_content).hexdigest()[:8]
    
    # Detect MIME type more accurately
    mime_type = file.type
    if not mime_type:
        if magic:
            try:
                mime_type = magic.from_buffer(file_content, mime=True)
            except:
                mime_type = mimetypes.guess_type(file.name)[0] or "application/octet-stream"
        else:
            mime_type = mimetypes.guess_type(file.name)[0] or "application/octet-stream"
    
    return {
        "Name": file.name,
        "Size": format_bytes(file_size),
        "Size_Bytes": file_size,
        "Type": mime_type,
        "Hash": file_hash,
        "Extension": Path(file.name).suffix.lower()
    }

def get_supported_conversions(file_type: str, file_extension: str = "") -> List[str]:
    """Get comprehensive list of supported conversions for a file type."""
    
    # Image formats
    image_formats = ["PNG", "JPEG", "JPG", "GIF", "BMP", "TIFF", "WEBP", "ICO"]
    
    # Document formats
    document_formats = ["PDF", "DOCX", "DOC", "TXT", "HTML", "MD"]
    
    # Data formats
    data_formats = ["CSV", "JSON", "JSONL", "XML", "YAML", "XLSX", "XLS", "TSV"]
    
    # Video formats
    video_formats = ["MP4", "AVI", "MOV", "WMV", "WEBM", "MKV"]
    
    # Audio formats
    audio_formats = ["MP3", "WAV", "FLAC", "AAC", "OGG", "M4A"]
    
    conversions = {
        # Text files
        "text/plain": document_formats + data_formats,
        "text/markdown": document_formats + ["HTML"],
        "text/html": document_formats + ["MD"],
        
        # Images
        "image/jpeg": image_formats,
        "image/png": image_formats,
        "image/gif": image_formats,
        "image/bmp": image_formats,
        "image/tiff": image_formats,
        "image/webp": image_formats,
        
        # Documents
        "application/pdf": ["TXT", "HTML", "MD"],
        "application/vnd.openxmlformats-officedocument.wordprocessingml.document": document_formats,
        
        # Spreadsheets
        "text/csv": data_formats + ["HTML"],
        "application/vnd.openxmlformats-officedocument.spreadsheetml.sheet": data_formats + ["HTML"],
        
        # Data formats
        "application/json": data_formats + ["HTML"],
        "application/x-ndjson": data_formats + ["HTML"],
        "text/xml": data_formats + ["HTML"],
        "application/xml": data_formats + ["HTML"],
        "text/yaml": data_formats + ["HTML"],
        "application/x-yaml": data_formats + ["HTML"],
        
        # Video files
        "video/mp4": video_formats + audio_formats,
        "video/x-msvideo": video_formats + audio_formats,
        "video/quicktime": video_formats + audio_formats,
        "video/webm": video_formats + audio_formats,
        
        # Audio files
        "audio/mpeg": audio_formats,
        "audio/wav": audio_formats,
        "audio/flac": audio_formats,
        "audio/aac": audio_formats,
        "audio/ogg": audio_formats,
    }
    
    # Get conversions based on MIME type
    supported = conversions.get(file_type, [])
    
    # Also check by file extension if MIME type doesn't match
    if not supported and file_extension:
        ext_conversions = {
            ".txt": document_formats + data_formats,
            ".md": document_formats + ["HTML"],
            ".py": ["HTML", "PDF", "TXT"],
            ".js": ["HTML", "PDF", "TXT"],
        }
        supported = ext_conversions.get(file_extension.lower(), [])
    
    return supported

def convert_file(file, file_type: str, target_format: str, **kwargs) -> bytes:
    """Comprehensive file conversion function supporting numerous formats."""
    content = file.getvalue()
    target_format = target_format.upper()
    
    try:
        # Text file conversions
        if file_type == "text/plain" or file_type.startswith("text/"):
            return _convert_text_file(content, target_format, **kwargs)
        
        # Image conversions
        elif file_type.startswith("image/"):
            return _convert_image_file(content, target_format, **kwargs)
        
        # PDF conversions
        elif file_type == "application/pdf":
            return _convert_pdf_file(content, target_format, **kwargs)
        
        # Document conversions
        elif "word" in file_type or "document" in file_type:
            return _convert_document_file(content, target_format, file_type, **kwargs)
        
        # Data file conversions
        elif file_type in ["text/csv", "application/json", "application/x-ndjson", 
                          "text/xml", "application/xml", "text/yaml", "application/x-yaml"] or \
             "spreadsheet" in file_type or "excel" in file_type:
            return _convert_data_file(content, target_format, file_type, **kwargs)
        
        # Video conversions
        elif file_type.startswith("video/"):
            return _convert_video_file(file, content, target_format, **kwargs)
        
        # Audio conversions
        elif file_type.startswith("audio/"):
            return _convert_audio_file(file, content, target_format, **kwargs)
        
        else:
            raise ValueError(f"Conversion from {file_type} to {target_format} is not supported.")
    
    except Exception as e:
        logging.error(f"Conversion error: {str(e)}")
        raise ValueError(f"Failed to convert file: {str(e)}")

def _convert_text_file(content: bytes, target_format: str, **kwargs) -> bytes:
    """Convert text files to various formats."""
    text = content.decode('utf-8', errors='ignore')
    
    if target_format == "PDF":
        return _text_to_pdf(text, **kwargs)
    elif target_format == "HTML":
        return _text_to_html(text, **kwargs)
    elif target_format == "MD":
        return text.encode('utf-8')
    elif target_format in ["JSON", "JSONL"]:
        lines = text.splitlines()
        if target_format == "JSON":
            data = [{"line": i+1, "text": line.strip()} for i, line in enumerate(lines) if line.strip()]
            return json.dumps(data, indent=2).encode('utf-8')
        else:
            jsonl_lines = [json.dumps({"text": line.strip()}) for line in lines if line.strip()]
            return '\n'.join(jsonl_lines).encode('utf-8')
    elif target_format == "CSV":
        lines = text.splitlines()
        df = pd.DataFrame([{"line_number": i+1, "content": line} for i, line in enumerate(lines)])
        return df.to_csv(index=False).encode('utf-8')
    else:
        return text.encode('utf-8')

def _convert_image_file(content: bytes, target_format: str, **kwargs) -> bytes:
    """Convert image files with advanced options."""
    img = Image.open(BytesIO(content))
    
    # Apply image enhancements if specified
    if kwargs.get('enhance_contrast'):
        enhancer = ImageEnhance.Contrast(img)
        img = enhancer.enhance(kwargs.get('contrast_factor', 1.2))
    
    if kwargs.get('enhance_brightness'):
        enhancer = ImageEnhance.Brightness(img)
        img = enhancer.enhance(kwargs.get('brightness_factor', 1.1))
    
    if kwargs.get('apply_blur'):
        img = img.filter(ImageFilter.GaussianBlur(radius=kwargs.get('blur_radius', 1)))
    
    # Handle transparency for formats that don't support it
    if target_format == "JPEG" and img.mode in ("RGBA", "LA", "P"):
        background = Image.new("RGB", img.size, (255, 255, 255))
        if img.mode == "P":
            img = img.convert("RGBA")
        background.paste(img, mask=img.split()[-1] if img.mode == "RGBA" else None)
        img = background
    
    # Resize if specified
    if kwargs.get('resize_width') and kwargs.get('resize_height'):
        img = img.resize((kwargs['resize_width'], kwargs['resize_height']), Image.Resampling.LANCZOS)
    
    img_buffer = BytesIO()
    save_kwargs = {}
    
    if target_format in ["JPEG", "JPG"]:
        save_kwargs['quality'] = kwargs.get('quality', 85)
        save_kwargs['optimize'] = True
        target_format = "JPEG"
    elif target_format == "PNG":
        save_kwargs['optimize'] = True
    elif target_format == "WEBP":
        save_kwargs['quality'] = kwargs.get('quality', 85)
        save_kwargs['method'] = 6
    
    img.save(img_buffer, format=target_format, **save_kwargs)
    return img_buffer.getvalue()

def _convert_pdf_file(content: bytes, target_format: str, **kwargs) -> bytes:
    """Convert PDF files to other formats."""
    if target_format == "TXT":
        pdf = PdfReader(BytesIO(content))
        text = ""
        for page in pdf.pages:
            text += page.extract_text() + "\n"
        return text.encode('utf-8')
    
    elif target_format == "HTML":
        pdf = PdfReader(BytesIO(content))
        html_content = "<html><body>"
        for i, page in enumerate(pdf.pages):
            text = page.extract_text()
            html_content += f"<h2>Page {i+1}</h2><pre>{text}</pre>"
        html_content += "</body></html>"
        return html_content.encode('utf-8')
    
    else:
        raise ValueError(f"PDF to {target_format} conversion not supported")

def _convert_document_file(content: bytes, target_format: str, file_type: str, **kwargs) -> bytes:
    """Convert document files (DOCX, DOC, etc.)."""
    if "wordprocessingml" in file_type:
        text = docx2txt.process(BytesIO(content))
    else:
        text = content.decode('utf-8', errors='ignore')
    
    if target_format == "TXT":
        return text.encode('utf-8')
    elif target_format == "PDF":
        return _text_to_pdf(text, **kwargs)
    elif target_format == "HTML":
        return _text_to_html(text, **kwargs)
    elif target_format == "MD":
        return text.encode('utf-8')
    else:
        raise ValueError(f"Document to {target_format} conversion not supported")

def _convert_data_file(content: bytes, target_format: str, file_type: str, **kwargs) -> bytes:
    """Convert data files (CSV, JSON, XML, YAML, Excel)."""
    try:
        if file_type == "text/csv":
            df = pd.read_csv(BytesIO(content))
        elif file_type == "application/json":
            df = pd.read_json(BytesIO(content))
        elif file_type == "application/x-ndjson":
            df = pd.read_json(BytesIO(content), lines=True)
        elif file_type in ["text/xml", "application/xml"]:
            df = pd.read_xml(BytesIO(content))
        elif file_type in ["text/yaml", "application/x-yaml"]:
            data = yaml.safe_load(BytesIO(content))
            if isinstance(data, list):
                df = pd.DataFrame(data)
            elif isinstance(data, dict):
                df = pd.DataFrame([data])
            else:
                df = pd.DataFrame({"value": [data]})
        elif "spreadsheet" in file_type or "excel" in file_type:
            df = pd.read_excel(BytesIO(content))
        else:
            raise ValueError(f"Unsupported data file type: {file_type}")
        
        # Convert to target format
        if target_format == "CSV":
            return df.to_csv(index=False).encode('utf-8')
        elif target_format == "TSV":
            return df.to_csv(index=False, sep='\t').encode('utf-8')
        elif target_format == "JSON":
            return df.to_json(orient="records", indent=2).encode('utf-8')
        elif target_format == "JSONL":
            return df.to_json(orient="records", lines=True).encode('utf-8')
        elif target_format in ["XML"]:
            return df.to_xml(index=False).encode('utf-8')
        elif target_format == "YAML":
            data = json.loads(df.to_json(orient="records"))
            return yaml.dump(data, default_flow_style=False).encode('utf-8')
        elif target_format == "XLSX":
            excel_buffer = BytesIO()
            with pd.ExcelWriter(excel_buffer, engine='openpyxl') as writer:
                df.to_excel(writer, index=False)
            return excel_buffer.getvalue()
        elif target_format == "HTML":
            return df.to_html(index=False, classes="table table-striped").encode('utf-8')
        else:
            raise ValueError(f"Conversion to {target_format} not supported")
    
    except Exception as e:
        raise ValueError(f"Data conversion failed: {str(e)}")

def _convert_video_file(file, content: bytes, target_format: str, **kwargs) -> bytes:
    """Convert video files using subprocess fallback."""
    with tempfile.TemporaryDirectory() as temp_dir:
        input_path = os.path.join(temp_dir, f"input{Path(file.name).suffix}")
        output_path = os.path.join(temp_dir, f"output.{target_format.lower()}")
        
        with open(input_path, "wb") as f:
            f.write(content)
        
        # Build ffmpeg command
        cmd = ["ffmpeg", "-i", input_path]
        
        if target_format in ["MP4", "AVI", "MOV", "WMV", "MKV", "WEBM"]:
            crf = kwargs.get('video_quality', 23)
            cmd.extend(["-c:v", "libx264", "-crf", str(crf), "-c:a", "aac"])
        elif target_format in ["MP3", "WAV", "FLAC", "AAC", "OGG"]:
            if target_format == "MP3":
                cmd.extend(["-c:a", "libmp3lame", "-b:a", "192k"])
            elif target_format == "WAV":
                cmd.extend(["-c:a", "pcm_s16le"])
        
        cmd.append(output_path)
        
        try:
            result = subprocess.run(cmd, capture_output=True, text=True)
            if result.returncode != 0:
                raise ValueError(f"FFmpeg error: {result.stderr}")
            
            with open(output_path, "rb") as f:
                return f.read()
        except FileNotFoundError:
            raise ValueError("FFmpeg not found. Please install FFmpeg for video/audio conversion.")

def _convert_audio_file(file, content: bytes, target_format: str, **kwargs) -> bytes:
    """Convert audio files."""
    return _convert_video_file(file, content, target_format, **kwargs)

def _text_to_pdf(text: str, **kwargs) -> bytes:
    """Convert text to PDF with formatting."""
    if not canvas:
        raise ValueError("ReportLab not installed for PDF generation")
    
    buffer = BytesIO()
    doc = SimpleDocTemplate(buffer, pagesize=letter)
    styles = getSampleStyleSheet()
    story = []
    
    # Split text into paragraphs
    paragraphs = text.split('\n\n')
    for para in paragraphs:
        if para.strip():
            p = Paragraph(para.replace('\n', '<br/>'), styles['Normal'])
            story.append(p)
            story.append(Spacer(1, 12))
    
    doc.build(story)
    return buffer.getvalue()

def _text_to_html(text: str, **kwargs) -> bytes:
    """Convert text to HTML."""
    html = f"<html><head><title>Document</title><style>body{{font-family:Arial,sans-serif;max-width:800px;margin:0 auto;padding:2rem;}}</style></head><body><pre>{text}</pre></body></html>"
    return html.encode('utf-8')

def get_file_preview(file, file_type: str) -> Optional[str]:
    """Generate preview for different file types."""
    content = file.getvalue()
    
    try:
        if file_type.startswith('image/'):
            return content
        
        elif file_type.startswith('text/') or file_type in ['application/json', 'application/xml', 'text/yaml']:
            text = content.decode('utf-8', errors='ignore')
            if len(text) > 2000:
                return text[:2000] + '\n\n... (truncated)'
            return text
        
        elif file_type == 'application/pdf':
            try:
                pdf = PdfReader(BytesIO(content))
                text = ""
                for i, page in enumerate(pdf.pages[:3]):
                    text += f"=== Page {i+1} ===\n"
                    text += page.extract_text()[:500] + "\n\n"
                return text + "... (showing first 3 pages)"
            except:
                return "PDF preview unavailable"
        
        elif 'word' in file_type or 'document' in file_type:
            try:
                text = docx2txt.process(BytesIO(content))
                if len(text) > 1500:
                    return text[:1500] + "\n\n... (truncated)"
                return text
            except:
                return "Document preview unavailable"
        
        elif file_type.startswith('video/') or file_type.startswith('audio/'):
            size_mb = len(content) / (1024 * 1024)
            return f"Media file preview:\nSize: {size_mb:.1f} MB\nType: {file_type}"
        
        else:
            try:
                text = content.decode('utf-8', errors='ignore')[:1000]
                if text.strip():
                    return text + "\n\n... (showing as text)"
            except:
                pass
            
            return f"Binary file ({len(content)} bytes)\nType: {file_type}\nPreview not available"
    
    except Exception as e:
        return f"Preview error: {str(e)}"

# Configure logging
logging.basicConfig(level=logging.INFO)

def create_download_link(file_data: bytes, filename: str, file_format: str) -> str:
    """Create a download link for converted files."""
    b64 = base64.b64encode(file_data).decode()
    
    mime_types = {
        'PDF': 'application/pdf',
        'DOCX': 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'XLSX': 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'MP4': 'video/mp4',
        'MP3': 'audio/mpeg',
        'PNG': 'image/png',
        'JPEG': 'image/jpeg',
        'JSON': 'application/json',
        'XML': 'application/xml',
        'CSV': 'text/csv',
        'TXT': 'text/plain',
        'HTML': 'text/html'
    }
    
    mime_type = mime_types.get(file_format.upper(), 'application/octet-stream')
    
    href = f'<a href="data:{mime_type};base64,{b64}" download="{filename}" style="display: inline-block; padding: 0.5rem 1rem; background-color: #ffffff; color: #000000; text-decoration: none; border-radius: 8px; font-weight: 500; margin: 0.5rem 0;">📥 Download {file_format.upper()} File</a>'
    return href

def render_file_stats(file_info: Dict[str, str]):
    """Render file statistics in a card layout."""
    col1, col2, col3, col4 = st.columns(4)
    
    with col1:
        st.markdown('<div class="metric-container">', unsafe_allow_html=True)
        st.metric("📄 Name", file_info["Name"][:20] + "..." if len(file_info["Name"]) > 20 else file_info["Name"])
        st.markdown('</div>', unsafe_allow_html=True)
    
    with col2:
        st.markdown('<div class="metric-container">', unsafe_allow_html=True)
        st.metric("📊 Size", file_info["Size"])
        st.markdown('</div>', unsafe_allow_html=True)
    
    with col3:
        st.markdown('<div class="metric-container">', unsafe_allow_html=True)
        st.metric("🏷️ Type", file_info["Type"].split('/')[-1].upper())
        st.markdown('</div>', unsafe_allow_html=True)
    
    with col4:
        st.markdown('<div class="metric-container">', unsafe_allow_html=True)
        st.metric("🔢 Hash", file_info["Hash"])
        st.markdown('</div>', unsafe_allow_html=True)

# Main app header
st.markdown('<div class="main-header">', unsafe_allow_html=True)
st.markdown('<h1 class="main-title">File Convert Anything</h1>', unsafe_allow_html=True)
st.markdown('<p class="main-subtitle">Transform any file into any format with precision and style</p>', unsafe_allow_html=True)
st.markdown('</div>', unsafe_allow_html=True)

# Sidebar with information
with st.sidebar:
    st.markdown("## 🚀 Features")
    st.markdown("""
    - **Universal Conversion**: Images, videos, documents, data files, and more
    - **Advanced Options**: Quality settings, compression, formatting
    - **Secure**: Files processed locally, not stored
    - **Fast**: Optimized conversion algorithms
    """)
    
    st.markdown("## 📋 Supported Formats")
    format_categories = {
        "Images": "PNG, JPEG, GIF, BMP, TIFF, WEBP, ICO",
        "Documents": "PDF, DOCX, DOC, TXT, HTML, MD",
        "Data": "CSV, JSON, XML, YAML, XLSX, XLS",
        "Media": "MP4, AVI, MOV, MP3, WAV, FLAC",
        "Code": "PY, JS, HTML, CSS, SQL, C, CPP"
    }
    
    for category, formats in format_categories.items():
        with st.expander(f"📁 {category}"):
            st.write(formats)

# File uploader section
st.markdown('<div class="upload-card">', unsafe_allow_html=True)
st.markdown('<h2 class="card-title">📤 Upload Your File</h2>', unsafe_allow_html=True)

uploaded_file = st.file_uploader(
    "Choose any file to convert", 
    type=None,
    help="Drag and drop a file here or click to browse. All file types supported!"
)
st.markdown('</div>', unsafe_allow_html=True)

if uploaded_file is not None:
    file_info = get_file_info(uploaded_file)
    
    # Display file statistics
    st.markdown('<div class="conversion-card">', unsafe_allow_html=True)
    st.markdown('<h2 class="card-title">📊 File Information</h2>', unsafe_allow_html=True)
    render_file_stats(file_info)
    st.markdown('</div>', unsafe_allow_html=True)
    
    # File preview section
    st.markdown('<div class="preview-card">', unsafe_allow_html=True)
    st.markdown('<h2 class="card-title">👁️ File Preview</h2>', unsafe_allow_html=True)
    
    preview = get_file_preview(uploaded_file, file_info["Type"])
    if preview:
        if file_info["Type"].startswith('image/'):
            st.image(preview, caption="Image Preview", use_column_width=True)
        else:
            st.text_area("Content Preview", preview, height=250, help="Showing file content preview")
    else:
        st.info("Preview not available for this file type.")
    
    st.markdown('</div>', unsafe_allow_html=True)
    
    # Conversion options
    supported_conversions = get_supported_conversions(file_info["Type"], file_info["Extension"])
    
    if supported_conversions:
        st.markdown('<div class="conversion-card">', unsafe_allow_html=True)
        st.markdown('<h2 class="card-title">🔄 Conversion Options</h2>', unsafe_allow_html=True)
        
        col1, col2 = st.columns([2, 1])
        
        with col1:
            target_format = st.selectbox(
                "Select target format:", 
                supported_conversions,
                help="Choose the format you want to convert your file to"
            )
        
        with col2:
            st.markdown("<br>", unsafe_allow_html=True)
            show_advanced = st.checkbox("⚙️ Advanced Options", help="Show additional conversion settings")
        
        # Advanced options
        conversion_options = {}
        
        if show_advanced:
            st.markdown("### ⚙️ Advanced Settings")
            
            # Image options
            if file_info["Type"].startswith('image/') and target_format.upper() in ['JPEG', 'PNG', 'WEBP']:
                col1, col2 = st.columns(2)
                with col1:
                    conversion_options['quality'] = st.slider("Quality", 1, 100, 85, help="Higher values = better quality")
                    conversion_options['resize_width'] = st.number_input("Resize Width (px)", min_value=0, value=0, help="0 = no resize")
                with col2:
                    conversion_options['enhance_contrast'] = st.checkbox("Enhance Contrast")
                    conversion_options['resize_height'] = st.number_input("Resize Height (px)", min_value=0, value=0, help="0 = no resize")
                
                if conversion_options['enhance_contrast']:
                    conversion_options['contrast_factor'] = st.slider("Contrast Factor", 0.5, 2.0, 1.2, 0.1)
            
            # Video/Audio options
            elif file_info["Type"].startswith('video/') or file_info["Type"].startswith('audio/'):
                conversion_options['video_quality'] = st.slider(
                    "Quality (CRF)", 0, 51, 23, 
                    help="Lower values = better quality (18-28 recommended)"
                )
        
        # Convert button
        convert_button = st.button(
            f"🚀 Convert to {target_format.upper()}", 
            type="primary",
            use_container_width=True
        )
        
        if convert_button:
            try:
                with st.spinner(f'Converting to {target_format.upper()}...'):
                    progress_bar = st.progress(0)
                    
                    for i in range(0, 50, 10):
                        time.sleep(0.1)
                        progress_bar.progress(i)
                    
                    converted_file = convert_file(
                        uploaded_file, 
                        file_info["Type"], 
                        target_format,
                        **conversion_options
                    )
                    
                    for i in range(50, 101, 10):
                        time.sleep(0.05)
                        progress_bar.progress(i)
                
                st.success(f"✅ Successfully converted to {target_format.upper()}!")
                
                original_name = Path(file_info["Name"]).stem
                new_filename = f"{original_name}_converted.{target_format.lower()}"
                
                download_link = create_download_link(converted_file, new_filename, target_format)
                st.markdown(download_link, unsafe_allow_html=True)
                
                # Show conversion stats
                col1, col2, col3 = st.columns(3)
                with col1:
                    st.metric("Original Size", file_info["Size"])
                with col2:
                    new_size = len(converted_file)
                    st.metric("Converted Size", f"{new_size / 1024:.1f} KB" if new_size < 1024*1024 else f"{new_size / (1024*1024):.1f} MB")
                with col3:
                    compression_ratio = (1 - new_size / file_info["Size_Bytes"]) * 100
                    st.metric("Size Change", f"{compression_ratio:+.1f}%")
                
            except ValueError as e:
                st.error(f"❌ Conversion Error: {str(e)}")
            except Exception as e:
                st.error(f"❌ Unexpected error: {str(e)}")
                logging.error(f"Conversion error: {str(e)}", exc_info=True)
        
        st.markdown('</div>', unsafe_allow_html=True)
    else:
        st.markdown('<div class="conversion-card">', unsafe_allow_html=True)
        st.warning("⚠️ No supported conversions available for this file type.")
        st.info("💡 Try uploading a different file format or check if the file is corrupted.")
        st.markdown('</div>', unsafe_allow_html=True)

else:
    # Landing page when no file is uploaded
    st.markdown('<div class="upload-card">', unsafe_allow_html=True)
    st.markdown("""<div style="text-align: center; padding: 2rem;">
        <h3>🎯 Ready to convert your files?</h3>
        <p style="color: #a1a1aa; margin-bottom: 2rem;">Upload any file above to get started with instant conversion</p>
        
        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 1rem; margin-top: 2rem;">
            <div style="background: #18181b; padding: 1rem; border-radius: 8px; border: 1px solid #27272a;">
                <h4>📷 Images</h4>
                <p style="font-size: 0.9rem; color: #a1a1aa;">Convert between PNG, JPEG, GIF, WEBP, and more</p>
            </div>
            <div style="background: #18181b; padding: 1rem; border-radius: 8px; border: 1px solid #27272a;">
                <h4>🎥 Videos</h4>
                <p style="font-size: 0.9rem; color: #a1a1aa;">Transform MP4, AVI, MOV, and extract audio</p>
            </div>
            <div style="background: #18181b; padding: 1rem; border-radius: 8px; border: 1px solid #27272a;">
                <h4>📄 Documents</h4>
                <p style="font-size: 0.9rem; color: #a1a1aa;">Convert PDF, DOCX, TXT, and markup files</p>
            </div>
            <div style="background: #18181b; padding: 1rem; border-radius: 8px; border: 1px solid #27272a;">
                <h4>📊 Data</h4>
                <p style="font-size: 0.9rem; color: #a1a1aa;">Process CSV, JSON, XML, Excel spreadsheets</p>
            </div>
        </div>
    </div>""", unsafe_allow_html=True)
    st.markdown('</div>', unsafe_allow_html=True)

# Footer
st.markdown('<div style="margin-top: 4rem; padding: 2rem 0; border-top: 1px solid #27272a; text-align: center;">', unsafe_allow_html=True)
st.markdown("""
<div style="color: #a1a1aa;">
    <p style="margin: 0;">⚡ Powered by Python • Built with Streamlit</p>
    <p style="margin: 0.5rem 0 0 0; font-size: 0.9rem;">🔒 Secure • 🚀 Fast • 🌐 Universal File Conversion</p>
</div>
""", unsafe_allow_html=True)
st.markdown('</div>', unsafe_allow_html=True)