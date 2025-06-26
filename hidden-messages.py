# Enhanced Steganography GUI Application
# Description: A professional Python application with GUI for steganography operations
#              Supports embedding and extracting secret messages in images with preview functionality
#
# Author: Enhanced by Claude (Based on original by Gemini & Lalo)
# Version: 3.0 (Professional Enhanced)
# Date: June 26, 2025
#
# Instructions:
# 1. Make sure you have Python installed.
# 2. Install the required libraries by running:
#    pip install stegano Pillow tkinterdnd2
# 3. Save this script as steganography_pro.py
# 4. Run: python steganography_pro.py

import tkinter as tk
from tkinter import filedialog, messagebox, font, ttk
from PIL import Image, ImageTk
import os
import threading
import time
from io import BytesIO

try:
    from stegano import lsb
except ImportError:
    messagebox.showerror(
        "Dependency Error",
        "The 'stegano' library is not installed.\nPlease run 'pip install stegano' in your terminal."
    )
    exit()

try:
    from tkinterdnd2 import TkinterDnD, DND_FILES
    DND_AVAILABLE = True
except ImportError:
    DND_AVAILABLE = False
    print("Drag-and-drop not available. Install tkinterdnd2 for this feature.")


class SteganographyApp:
    """
    Professional steganography application with enhanced features.
    """

    def __init__(self, master):
        """Initialize the main application."""
        self.master = master
        self.image_path_encode = None
        self.image_path_decode = None
        self.recent_files = []
        
        # Theme and styling
        self._setup_theme()
        self._setup_window()
        self._setup_fonts()
        self._create_main_layout()
        self._create_pages()
        
        self.show_frame("EncodePage")

    def _setup_theme(self):
        """Set up the application theme."""
        self.BG_COLOR = "#0d1117"
        self.FRAME_COLOR = "#161b22" 
        self.INPUT_COLOR = "#21262d"
        self.TEXT_COLOR = "#f0f6fc"
        self.ACCENT_COLOR = "#238636"
        self.ACCENT_HOVER = "#2ea043"
        self.SECONDARY_COLOR = "#1f6feb"
        self.SECONDARY_HOVER = "#388bfd"
        self.SUCCESS_COLOR = "#238636"
        self.ERROR_COLOR = "#da3633"
        self.WARNING_COLOR = "#fb8500"
        self.BORDER_COLOR = "#30363d"

    def _setup_window(self):
        """Configure the main window."""
        self.master.title("CipherFrame Pro | Advanced Steganography Tool v3.0")
        self.master.geometry("1200x800")
        self.master.configure(bg=self.BG_COLOR)
        self.master.resizable(True, True)
        self.master.minsize(1000, 700)
        
        # Center window on screen
        self.master.update_idletasks()
        x = (self.master.winfo_screenwidth() // 2) - (1200 // 2)
        y = (self.master.winfo_screenheight() // 2) - (800 // 2)
        self.master.geometry(f"1200x800+{x}+{y}")

    def _setup_fonts(self):
        """Set up font definitions."""
        self.default_font = font.Font(family="Segoe UI", size=10)
        self.title_font = font.Font(family="Segoe UI", size=16, weight="bold")
        self.subtitle_font = font.Font(family="Segoe UI", size=12, weight="bold")
        self.button_font = font.Font(family="Segoe UI", size=10, weight="bold")
        self.nav_font = font.Font(family="Segoe UI", size=12, weight="bold")
        self.small_font = font.Font(family="Segoe UI", size=8)

    def _create_main_layout(self):
        """Create the main application layout."""
        self.master.grid_rowconfigure(0, weight=1)
        self.master.grid_columnconfigure(0, weight=1)

        self.container = tk.Frame(self.master, bg=self.BG_COLOR)
        self.container.grid(row=0, column=0, sticky="nsew")
        self.container.grid_rowconfigure(1, weight=1)
        self.container.grid_columnconfigure(0, weight=1)

        self._create_navigation()
        self._create_status_bar()

    def _create_navigation(self):
        """Create the navigation bar."""
        nav_frame = tk.Frame(self.container, bg=self.FRAME_COLOR, height=60)
        nav_frame.grid(row=0, column=0, sticky="ew")
        nav_frame.grid_propagate(False)

        # Left side - navigation buttons
        left_frame = tk.Frame(nav_frame, bg=self.FRAME_COLOR)
        left_frame.pack(side=tk.LEFT, fill=tk.Y, padx=10)

        self.nav_buttons = {}
        for page_name in ("Encode", "Decode"):
            button = tk.Button(
                left_frame,
                text=page_name,
                font=self.nav_font,
                fg="black",
                bg=self.FRAME_COLOR,
                activeforeground="black",
                activebackground=self.ACCENT_COLOR,
                relief=tk.FLAT,
                padx=20,
                pady=8,
                command=lambda p=page_name: self.show_frame(p + "Page")
            )
            button.pack(side=tk.LEFT, padx=5, pady=10)
            self._add_hover_effect(button, self.ACCENT_HOVER, self.FRAME_COLOR)
            self.nav_buttons[page_name + "Page"] = button

        # Right side - info
        right_frame = tk.Frame(nav_frame, bg=self.FRAME_COLOR)
        right_frame.pack(side=tk.RIGHT, fill=tk.Y, padx=10)

        info_label = tk.Label(
            right_frame,
            text="Professional Steganography Tool",
            font=self.small_font,
            bg=self.FRAME_COLOR,
            fg=self.TEXT_COLOR
        )
        info_label.pack(side=tk.RIGHT, pady=20)

    def _create_status_bar(self):
        """Create the status bar."""
        status_frame = tk.Frame(self.master, bg=self.FRAME_COLOR, height=30)
        status_frame.grid(row=1, column=0, sticky="ew")
        status_frame.grid_propagate(False)

        self.status_bar = tk.Label(
            status_frame,
            text="Ready",
            font=self.small_font,
            bg=self.FRAME_COLOR,
            fg=self.TEXT_COLOR,
            anchor="w",
            padx=10
        )
        self.status_bar.pack(side=tk.LEFT, fill=tk.BOTH, expand=True, pady=5)

        self.progress_var = tk.DoubleVar()
        self.progress_bar = ttk.Progressbar(
            status_frame,
            variable=self.progress_var,
            maximum=100,
            length=200
        )
        self.progress_bar.pack(side=tk.RIGHT, padx=10, pady=5)
        self.progress_bar.pack_forget()  # Hide initially

    def _create_pages(self):
        """Create the application pages."""
        self.frames = {}
        for F in (EncodePage, DecodePage):
            page_name = F.__name__
            frame = F(parent=self.container, controller=self)
            self.frames[page_name] = frame
            frame.grid(row=1, column=0, sticky="nsew")

    def show_frame(self, page_name):
        """Show the specified frame."""
        self.current_frame_name = page_name
        frame = self.frames[page_name]
        frame.tkraise()
        
        # Update navigation buttons
        for name, button in self.nav_buttons.items():
            if name == page_name:
                button.config(bg=self.ACCENT_COLOR, fg="black")
            else:
                button.config(bg=self.FRAME_COLOR, fg="black")
        
        self._update_status("Ready")

    def _update_status(self, message, msg_type="info"):
        """Update the status bar."""
        self.status_bar.config(text=f"Status: {message}")
        if msg_type == "success":
            self.status_bar.config(fg=self.SUCCESS_COLOR)
        elif msg_type == "error":
            self.status_bar.config(fg=self.ERROR_COLOR)
        elif msg_type == "warning":
            self.status_bar.config(fg=self.WARNING_COLOR)
        else:
            self.status_bar.config(fg=self.TEXT_COLOR)

    def _add_hover_effect(self, widget, hover_color, default_color):
        """Add hover effect to widget."""
        def on_enter(e):
            widget.config(bg=hover_color, fg="black")
        
        def on_leave(e):
            # Check if this is the active nav button
            if hasattr(self, 'current_frame_name'):
                for name, button in self.nav_buttons.items():
                    if button == widget and name == self.current_frame_name:
                        widget.config(fg="black")  # Keep black text for active
                        return  # Keep active color
            widget.config(bg=default_color, fg="black")
        
        widget.bind("<Enter>", on_enter)
        widget.bind("<Leave>", on_leave)

    def show_progress(self):
        """Show progress bar."""
        self.progress_bar.pack(side=tk.RIGHT, padx=10, pady=5)
        
    def hide_progress(self):
        """Hide progress bar."""
        self.progress_bar.pack_forget()
        self.progress_var.set(0)

    def update_progress(self, value):
        """Update progress bar value."""
        self.progress_var.set(value)
        self.master.update_idletasks()


class ImagePreviewFrame:
    """Frame for displaying image previews."""
    
    def __init__(self, parent, controller, title="Image Preview"):
        self.parent = parent
        self.controller = controller
        self.title = title
        self.current_image = None
        self._create_widgets()

    def _create_widgets(self):
        """Create preview widgets."""
        self.frame = tk.Frame(self.parent, bg=self.controller.FRAME_COLOR, relief=tk.SOLID, bd=1)
        
        # Title
        title_label = tk.Label(
            self.frame,
            text=self.title,
            font=self.controller.subtitle_font,
            bg=self.controller.FRAME_COLOR,
            fg=self.controller.TEXT_COLOR
        )
        title_label.pack(pady=(10, 5))
        
        # Image display area
        self.image_frame = tk.Frame(self.frame, bg=self.controller.INPUT_COLOR, width=300, height=200)
        self.image_frame.pack(padx=10, pady=5, fill=tk.BOTH, expand=True)
        self.image_frame.pack_propagate(False)
        
        self.image_label = tk.Label(
            self.image_frame,
            text="No image selected",
            font=self.controller.default_font,
            bg=self.controller.INPUT_COLOR,
            fg=self.controller.TEXT_COLOR
        )
        self.image_label.pack(expand=True)
        
        # Image info
        self.info_label = tk.Label(
            self.frame,
            text="",
            font=self.controller.small_font,
            bg=self.controller.FRAME_COLOR,
            fg=self.controller.TEXT_COLOR
        )
        self.info_label.pack(pady=(0, 10))

    def display_image(self, image_path):
        """Display an image in the preview."""
        try:
            if not image_path or not os.path.exists(image_path):
                self.clear_image()
                return

            # Load and resize image
            img = Image.open(image_path)
            self.current_image = img.copy()
            
            # Calculate size to fit in preview area while maintaining aspect ratio
            preview_size = (280, 180)
            img.thumbnail(preview_size, Image.Resampling.LANCZOS)
            
            # Convert to PhotoImage
            photo = ImageTk.PhotoImage(img)
            
            # Update label
            self.image_label.configure(image=photo, text="")
            self.image_label.image = photo  # Keep a reference
            
            # Update info
            original_size = self.current_image.size
            file_size = os.path.getsize(image_path)
            size_kb = file_size / 1024
            
            info_text = f"{original_size[0]}x{original_size[1]} • {size_kb:.1f} KB"
            self.info_label.config(text=info_text)
            
        except Exception as e:
            self.clear_image()
            self.info_label.config(text=f"Error: {str(e)}")

    def clear_image(self):
        """Clear the image preview."""
        self.image_label.configure(image="", text="No image selected")
        self.image_label.image = None
        self.info_label.config(text="")
        self.current_image = None

    def pack(self, **kwargs):
        """Pack the frame."""
        self.frame.pack(**kwargs)

    def grid(self, **kwargs):
        """Grid the frame."""
        self.frame.grid(**kwargs)


class EncodePage(tk.Frame):
    """Enhanced encode page with image preview."""
    
    def __init__(self, parent, controller):
        super().__init__(parent, bg=controller.BG_COLOR)
        self.controller = controller
        self._create_widgets()
        self._setup_drag_drop()

    def _create_widgets(self):
        """Create the encode page widgets."""
        # Main container with padding
        main_container = tk.Frame(self, bg=self.controller.BG_COLOR)
        main_container.pack(fill=tk.BOTH, expand=True, padx=20, pady=20)
        
        # Title
        title_label = tk.Label(
            main_container,
            text="Embed Secret Message",
            font=self.controller.title_font,
            bg=self.controller.BG_COLOR,
            fg=self.controller.TEXT_COLOR
        )
        title_label.pack(pady=(0, 20))

        # Main content area
        content_frame = tk.Frame(main_container, bg=self.controller.BG_COLOR)
        content_frame.pack(fill=tk.BOTH, expand=True)
        
        # Configure grid weights
        content_frame.grid_columnconfigure(0, weight=2)
        content_frame.grid_columnconfigure(1, weight=1)
        content_frame.grid_rowconfigure(0, weight=1)

        # Left panel - Controls
        self._create_controls_panel(content_frame)
        
        # Right panel - Image preview
        self._create_preview_panel(content_frame)

    def _create_controls_panel(self, parent):
        """Create the controls panel."""
        controls_frame = tk.Frame(parent, bg=self.controller.BG_COLOR)
        controls_frame.grid(row=0, column=0, sticky="nsew", padx=(0, 10))

        # Image selection
        self._create_image_selection(controls_frame)
        
        # Message input
        self._create_message_input(controls_frame)
        
        # Password input
        self._create_password_input(controls_frame)
        
        # Capacity estimation
        self._create_capacity_info(controls_frame)
        
        # Action buttons
        self._create_action_buttons(controls_frame)

    def _create_image_selection(self, parent):
        """Create image selection widgets."""
        image_section = tk.LabelFrame(
            parent,
            text="Source Image",
            font=self.controller.subtitle_font,
            bg=self.controller.BG_COLOR,
            fg=self.controller.TEXT_COLOR,
            relief=tk.FLAT,
            bd=0
        )
        image_section.pack(fill=tk.X, pady=(0, 15))

        # File path entry and browse button
        path_frame = tk.Frame(image_section, bg=self.controller.BG_COLOR)
        path_frame.pack(fill=tk.X, pady=5)

        self.image_path_entry = tk.Entry(
            path_frame,
            font=self.controller.default_font,
            bg=self.controller.INPUT_COLOR,
            fg=self.controller.TEXT_COLOR,
            relief=tk.FLAT,
            insertbackground=self.controller.TEXT_COLOR,
            state='readonly'
        )
        self.image_path_entry.pack(side=tk.LEFT, fill=tk.X, expand=True, ipady=5)
        self.image_path_entry.insert(0, "Select or drag an image file...")

        browse_button = tk.Button(
            path_frame,
            text="Browse",
            command=self.select_image,
            font=self.controller.button_font,
            bg=self.controller.SECONDARY_COLOR,
            fg="black",
            relief=tk.FLAT,
            activebackground=self.controller.SECONDARY_HOVER,
            activeforeground="black",
            padx=20
        )
        browse_button.pack(side=tk.RIGHT, padx=(10, 0), ipady=5)
        self.controller._add_hover_effect(browse_button, self.controller.SECONDARY_HOVER, self.controller.SECONDARY_COLOR)

        # Supported formats info
        formats_label = tk.Label(
            image_section,
            text="Supported: PNG, BMP, TIFF",
            font=self.controller.small_font,
            bg=self.controller.BG_COLOR,
            fg=self.controller.TEXT_COLOR
        )
        formats_label.pack(anchor="w", pady=(2, 0))

    def _create_message_input(self, parent):
        """Create message input widgets."""
        message_section = tk.LabelFrame(
            parent,
            text="Secret Message",
            font=self.controller.subtitle_font,
            bg=self.controller.BG_COLOR,
            fg=self.controller.TEXT_COLOR,
            relief=tk.FLAT,
            bd=0
        )
        message_section.pack(fill=tk.BOTH, expand=True, pady=(0, 15))

        # Text widget with scrollbar
        text_frame = tk.Frame(message_section, bg=self.controller.BG_COLOR)
        text_frame.pack(fill=tk.BOTH, expand=True, pady=5)

        self.message_text = tk.Text(
            text_frame,
            font=self.controller.default_font,
            bg=self.controller.INPUT_COLOR,
            fg=self.controller.TEXT_COLOR,
            relief=tk.FLAT,
            insertbackground=self.controller.TEXT_COLOR,
            wrap=tk.WORD,
            padx=10,
            pady=10
        )
        
        scrollbar = tk.Scrollbar(text_frame, orient=tk.VERTICAL, command=self.message_text.yview)
        self.message_text.configure(yscrollcommand=scrollbar.set)
        
        self.message_text.pack(side=tk.LEFT, fill=tk.BOTH, expand=True)
        scrollbar.pack(side=tk.RIGHT, fill=tk.Y)

        # Character count
        self.char_count_label = tk.Label(
            message_section,
            text="Characters: 0",
            font=self.controller.small_font,
            bg=self.controller.BG_COLOR,
            fg=self.controller.TEXT_COLOR
        )
        self.char_count_label.pack(anchor="w", pady=(2, 0))
        
        # Bind text change event
        self.message_text.bind('<KeyRelease>', self._update_char_count)
        self.message_text.bind('<ButtonRelease>', self._update_char_count)

    def _create_password_input(self, parent):
        """Create password input widgets."""
        password_section = tk.LabelFrame(
            parent,
            text="Encryption Password",
            font=self.controller.subtitle_font,
            bg=self.controller.BG_COLOR,
            fg=self.controller.TEXT_COLOR,
            relief=tk.FLAT,
            bd=0
        )
        password_section.pack(fill=tk.X, pady=(0, 15))

        self.password_entry = tk.Entry(
            password_section,
            show="*",
            font=self.controller.default_font,
            bg=self.controller.INPUT_COLOR,
            fg=self.controller.TEXT_COLOR,
            relief=tk.FLAT,
            insertbackground=self.controller.TEXT_COLOR
        )
        self.password_entry.pack(fill=tk.X, pady=5, ipady=5)

    def _create_capacity_info(self, parent):
        """Create capacity information widget."""
        self.capacity_label = tk.Label(
            parent,
            text="Select an image to see capacity",
            font=self.controller.small_font,
            bg=self.controller.BG_COLOR,
            fg=self.controller.TEXT_COLOR
        )
        self.capacity_label.pack(anchor="w", pady=(0, 15))

    def _create_action_buttons(self, parent):
        """Create action buttons."""
        button_frame = tk.Frame(parent, bg=self.controller.BG_COLOR)
        button_frame.pack(fill=tk.X, pady=10)

        # Clear button
        clear_button = tk.Button(
            button_frame,
            text="Clear All",
            command=self.clear_fields,
            font=self.controller.button_font,
            bg=self.controller.FRAME_COLOR,
            fg="black",
            relief=tk.FLAT,
            activebackground=self.controller.BORDER_COLOR,
            activeforeground="black",
            padx=20,
            pady=8
        )
        clear_button.pack(side=tk.LEFT)
        self.controller._add_hover_effect(clear_button, self.controller.BORDER_COLOR, self.controller.FRAME_COLOR)

        # Encode button
        encode_button = tk.Button(
            button_frame,
            text="Encode & Save",
            command=self.encode_and_save,
            font=self.controller.button_font,
            bg=self.controller.ACCENT_COLOR,
            fg="black",
            relief=tk.FLAT,
            activebackground=self.controller.ACCENT_HOVER,
            activeforeground="black",
            padx=30,
            pady=8
        )
        encode_button.pack(side=tk.RIGHT)
        self.controller._add_hover_effect(encode_button, self.controller.ACCENT_HOVER, self.controller.ACCENT_COLOR)

    def _create_preview_panel(self, parent):
        """Create the image preview panel."""
        preview_container = tk.Frame(parent, bg=self.controller.BG_COLOR)
        preview_container.grid(row=0, column=1, sticky="nsew")

        self.image_preview = ImagePreviewFrame(
            preview_container,
            self.controller,
            "Source Image Preview"
        )
        self.image_preview.pack(fill=tk.BOTH, expand=True)

    def _setup_drag_drop(self):
        """Set up drag and drop functionality."""
        if DND_AVAILABLE:
            self.drop_target_register(DND_FILES)
            self.dnd_bind('<<Drop>>', self._on_drop)

    def _on_drop(self, event):
        """Handle file drop."""
        if DND_AVAILABLE and event.data:
            files = self.tk.splitlist(event.data)
            if files:
                file_path = files[0]
                if self._is_supported_image(file_path):
                    self._set_image_path(file_path)

    def _is_supported_image(self, file_path):
        """Check if file is a supported image format."""
        supported_extensions = {'.png', '.bmp', '.tiff', '.tif'}
        _, ext = os.path.splitext(file_path.lower())
        return ext in supported_extensions

    def _update_char_count(self, event=None):
        """Update character count display."""
        content = self.message_text.get(1.0, tk.END)
        char_count = len(content) - 1  # Subtract 1 for the trailing newline
        self.char_count_label.config(text=f"Characters: {char_count}")
        self._update_capacity_info()

    def _update_capacity_info(self):
        """Update capacity information."""
        if not self.controller.image_path_encode:
            self.capacity_label.config(text="Select an image to see capacity")
            return

        try:
            img = Image.open(self.controller.image_path_encode)
            total_pixels = img.width * img.height
            max_chars = (total_pixels * 3) // 8  # Rough estimation for LSB
            
            message_length = len(self.message_text.get(1.0, tk.END)) - 1
            password_length = len(self.password_entry.get())
            total_length = message_length + password_length + 20  # Extra for metadata
            
            if total_length > max_chars:
                color = self.controller.ERROR_COLOR
                status = "Message too large!"
            elif total_length > max_chars * 0.8:
                color = self.controller.WARNING_COLOR
                status = "Near capacity"
            else:
                color = self.controller.SUCCESS_COLOR
                status = "Capacity OK"
            
            capacity_text = f"Capacity: {total_length}/{max_chars} chars • {status}"
            self.capacity_label.config(text=capacity_text, fg=color)
            
        except Exception:
            self.capacity_label.config(text="Error reading image capacity")

    def select_image(self):
        """Open file dialog to select image."""
        file_types = [
            ("Image files", "*.png *.bmp *.tiff *.tif"),
            ("PNG files", "*.png"),
            ("BMP files", "*.bmp"),
            ("TIFF files", "*.tiff *.tif"),
            ("All files", "*.*")
        ]
        
        path = filedialog.askopenfilename(
            title="Select Source Image",
            filetypes=file_types
        )
        
        if path and os.path.exists(path):
            self._set_image_path(path)

    def _set_image_path(self, path):
        """Set the selected image path."""
        self.controller.image_path_encode = path
        self.image_path_entry.config(state='normal')
        self.image_path_entry.delete(0, tk.END)
        self.image_path_entry.insert(0, path)
        self.image_path_entry.config(state='readonly')
        
        # Update preview
        self.image_preview.display_image(path)
        self._update_capacity_info()
        self.controller._update_status("Image selected", "success")

    def clear_fields(self):
        """Clear all input fields."""
        self.controller.image_path_encode = None
        self.image_path_entry.config(state='normal')
        self.image_path_entry.delete(0, tk.END)
        self.image_path_entry.insert(0, "Select or drag an image file...")
        self.image_path_entry.config(state='readonly')
        
        self.message_text.delete(1.0, tk.END)
        self.password_entry.delete(0, tk.END)
        self.image_preview.clear_image()
        self._update_char_count()
        self.controller._update_status("Fields cleared")

    def encode_and_save(self):
        """Encode message and save result."""
        # Validation
        if not self.controller.image_path_encode:
            messagebox.showwarning("Input Required", "Please select a source image.")
            return

        secret_message = self.message_text.get(1.0, tk.END).strip()
        if not secret_message:
            messagebox.showwarning("Input Required", "Please enter a secret message.")
            return

        password = self.password_entry.get().strip()
        if not password:
            messagebox.showwarning("Input Required", "Please enter a password.")
            return

        # Save dialog
        save_path = filedialog.asksaveasfilename(
            title="Save Encoded Image",
            defaultextension=".png",
            filetypes=[("PNG files", "*.png"), ("All files", "*.*")]
        )
        
        if not save_path:
            return

        # Encode in separate thread
        def encode_worker():
            try:
                self.controller._update_status("Encoding message...")
                self.controller.show_progress()
                
                # Simulate progress
                for i in range(0, 50, 10):
                    self.controller.update_progress(i)
                    time.sleep(0.1)
                
                message_with_pass = f"KEY:{password}:::{secret_message}"
                secret_image = lsb.hide(self.controller.image_path_encode, message_with_pass)
                
                self.controller.update_progress(80)
                secret_image.save(save_path)
                self.controller.update_progress(100)
                
                # Success callback
                def success_callback():
                    self.controller.hide_progress()
                    self.controller._update_status("Message encoded successfully!", "success")
                    messagebox.showinfo("Success", f"Message embedded successfully!\nSaved to: {save_path}")
                    
                self.controller.master.after(0, success_callback)
                
            except Exception as e:
                def error_callback():
                    self.controller.hide_progress()
                    self.controller._update_status("Encoding failed", "error")
                    messagebox.showerror("Error", f"Encoding failed: {str(e)}")
                    
                self.controller.master.after(0, error_callback)

        threading.Thread(target=encode_worker, daemon=True).start()


class DecodePage(tk.Frame):
    """Enhanced decode page with image preview."""
    
    def __init__(self, parent, controller):
        super().__init__(parent, bg=controller.BG_COLOR)
        self.controller = controller
        self._create_widgets()
        self._setup_drag_drop()

    def _create_widgets(self):
        """Create the decode page widgets."""
        # Main container
        main_container = tk.Frame(self, bg=self.controller.BG_COLOR)
        main_container.pack(fill=tk.BOTH, expand=True, padx=20, pady=20)
        
        # Title
        title_label = tk.Label(
            main_container,
            text="Extract Secret Message",
            font=self.controller.title_font,
            bg=self.controller.BG_COLOR,
            fg=self.controller.TEXT_COLOR
        )
        title_label.pack(pady=(0, 20))

        # Main content area
        content_frame = tk.Frame(main_container, bg=self.controller.BG_COLOR)
        content_frame.pack(fill=tk.BOTH, expand=True)
        
        # Configure grid
        content_frame.grid_columnconfigure(0, weight=2)
        content_frame.grid_columnconfigure(1, weight=1)
        content_frame.grid_rowconfigure(0, weight=1)

        # Left panel - Controls
        self._create_controls_panel(content_frame)
        
        # Right panel - Image preview
        self._create_preview_panel(content_frame)

    def _create_controls_panel(self, parent):
        """Create the controls panel."""
        controls_frame = tk.Frame(parent, bg=self.controller.BG_COLOR)
        controls_frame.grid(row=0, column=0, sticky="nsew", padx=(0, 10))

        # Image selection
        self._create_image_selection(controls_frame)
        
        # Password input
        self._create_password_input(controls_frame)
        
        # Action buttons
        self._create_action_buttons(controls_frame)
        
        # Result display
        self._create_result_display(controls_frame)

    def _create_image_selection(self, parent):
        """Create image selection widgets."""
        image_section = tk.LabelFrame(
            parent,
            text="Encoded Image",
            font=self.controller.subtitle_font,
            bg=self.controller.BG_COLOR,
            fg=self.controller.TEXT_COLOR,
            relief=tk.FLAT,
            bd=0
        )
        image_section.pack(fill=tk.X, pady=(0, 15))

        # File path entry and browse button
        path_frame = tk.Frame(image_section, bg=self.controller.BG_COLOR)
        path_frame.pack(fill=tk.X, pady=5)

        self.image_path_entry = tk.Entry(
            path_frame,
            font=self.controller.default_font,
            bg=self.controller.INPUT_COLOR,
            fg=self.controller.TEXT_COLOR,
            relief=tk.FLAT,
            insertbackground=self.controller.TEXT_COLOR,
            state='readonly'
        )
        self.image_path_entry.pack(side=tk.LEFT, fill=tk.X, expand=True, ipady=5)
        self.image_path_entry.insert(0, "Select or drag an encoded image...")

        browse_button = tk.Button(
            path_frame,
            text="Browse",
            command=self.select_image,
            font=self.controller.button_font,
            bg=self.controller.SECONDARY_COLOR,
            fg="black",
            relief=tk.FLAT,
            activebackground=self.controller.SECONDARY_HOVER,
            activeforeground="black",
            padx=20
        )
        browse_button.pack(side=tk.RIGHT, padx=(10, 0), ipady=5)
        self.controller._add_hover_effect(browse_button, self.controller.SECONDARY_HOVER, self.controller.SECONDARY_COLOR)

    def _create_password_input(self, parent):
        """Create password input widgets."""
        password_section = tk.LabelFrame(
            parent,
            text="Decryption Password",
            font=self.controller.subtitle_font,
            bg=self.controller.BG_COLOR,
            fg=self.controller.TEXT_COLOR,
            relief=tk.FLAT,
            bd=0
        )
        password_section.pack(fill=tk.X, pady=(0, 15))

        self.password_entry = tk.Entry(
            password_section,
            show="*",
            font=self.controller.default_font,
            bg=self.controller.INPUT_COLOR,
            fg=self.controller.TEXT_COLOR,
            relief=tk.FLAT,
            insertbackground=self.controller.TEXT_COLOR
        )
        self.password_entry.pack(fill=tk.X, pady=5, ipady=5)
        
        # Bind Enter key to decode
        self.password_entry.bind('<Return>', lambda e: self.decode_message())

    def _create_action_buttons(self, parent):
        """Create action buttons."""
        button_frame = tk.Frame(parent, bg=self.controller.BG_COLOR)
        button_frame.pack(fill=tk.X, pady=15)

        # Clear button
        clear_button = tk.Button(
            button_frame,
            text="Clear All",
            command=self.clear_fields,
            font=self.controller.button_font,
            bg=self.controller.FRAME_COLOR,
            fg="black",
            relief=tk.FLAT,
            activebackground=self.controller.BORDER_COLOR,
            activeforeground="black",
            padx=20,
            pady=8
        )
        clear_button.pack(side=tk.LEFT)
        self.controller._add_hover_effect(clear_button, self.controller.BORDER_COLOR, self.controller.FRAME_COLOR)

        # Decode button
        decode_button = tk.Button(
            button_frame,
            text="Decode Message",
            command=self.decode_message,
            font=self.controller.button_font,
            bg=self.controller.ACCENT_COLOR,
            fg="black",
            relief=tk.FLAT,
            activebackground=self.controller.ACCENT_HOVER,
            activeforeground="black",
            padx=30,
            pady=8
        )
        decode_button.pack(side=tk.RIGHT)
        self.controller._add_hover_effect(decode_button, self.controller.ACCENT_HOVER, self.controller.ACCENT_COLOR)

    def _create_result_display(self, parent):
        """Create result display widgets."""
        result_section = tk.LabelFrame(
            parent,
            text="Decoded Message",
            font=self.controller.subtitle_font,
            bg=self.controller.BG_COLOR,
            fg=self.controller.TEXT_COLOR,
            relief=tk.FLAT,
            bd=0
        )
        result_section.pack(fill=tk.BOTH, expand=True)

        # Text widget with scrollbar
        text_frame = tk.Frame(result_section, bg=self.controller.BG_COLOR)
        text_frame.pack(fill=tk.BOTH, expand=True, pady=5)

        self.result_text = tk.Text(
            text_frame,
            font=self.controller.default_font,
            bg=self.controller.INPUT_COLOR,
            fg=self.controller.TEXT_COLOR,
            relief=tk.FLAT,
            wrap=tk.WORD,
            padx=10,
            pady=10,
            state='disabled'
        )
        
        scrollbar = tk.Scrollbar(text_frame, orient=tk.VERTICAL, command=self.result_text.yview)
        self.result_text.configure(yscrollcommand=scrollbar.set)
        
        self.result_text.pack(side=tk.LEFT, fill=tk.BOTH, expand=True)
        scrollbar.pack(side=tk.RIGHT, fill=tk.Y)

        # Action buttons for result
        result_buttons = tk.Frame(result_section, bg=self.controller.BG_COLOR)
        result_buttons.pack(fill=tk.X, pady=(5, 0))

        copy_button = tk.Button(
            result_buttons,
            text="Copy to Clipboard",
            command=self.copy_to_clipboard,
            font=self.controller.button_font,
            bg=self.controller.SECONDARY_COLOR,
            fg="black",
            relief=tk.FLAT,
            activebackground=self.controller.SECONDARY_HOVER,
            activeforeground="black",
            state='disabled'
        )
        copy_button.pack(side=tk.LEFT, padx=(0, 10))
        self.controller._add_hover_effect(copy_button, self.controller.SECONDARY_HOVER, self.controller.SECONDARY_COLOR)
        self.copy_button = copy_button

        save_button = tk.Button(
            result_buttons,
            text="Save to File",
            command=self.save_to_file,
            font=self.controller.button_font,
            bg=self.controller.SECONDARY_COLOR,
            fg="black",
            relief=tk.FLAT,
            activebackground=self.controller.SECONDARY_HOVER,
            activeforeground="black",
            state='disabled'
        )
        save_button.pack(side=tk.LEFT)
        self.controller._add_hover_effect(save_button, self.controller.SECONDARY_HOVER, self.controller.SECONDARY_COLOR)
        self.save_button = save_button

    def _create_preview_panel(self, parent):
        """Create the image preview panel."""
        preview_container = tk.Frame(parent, bg=self.controller.BG_COLOR)
        preview_container.grid(row=0, column=1, sticky="nsew")

        self.image_preview = ImagePreviewFrame(
            preview_container,
            self.controller,
            "Encoded Image Preview"
        )
        self.image_preview.pack(fill=tk.BOTH, expand=True)

    def _setup_drag_drop(self):
        """Set up drag and drop functionality."""
        if DND_AVAILABLE:
            self.drop_target_register(DND_FILES)
            self.dnd_bind('<<Drop>>', self._on_drop)

    def _on_drop(self, event):
        """Handle file drop."""
        if DND_AVAILABLE and event.data:
            files = self.tk.splitlist(event.data)
            if files:
                file_path = files[0]
                if self._is_supported_image(file_path):
                    self._set_image_path(file_path)

    def _is_supported_image(self, file_path):
        """Check if file is a supported image format."""
        supported_extensions = {'.png', '.bmp', '.tiff', '.tif'}
        _, ext = os.path.splitext(file_path.lower())
        return ext in supported_extensions

    def select_image(self):
        """Open file dialog to select image."""
        file_types = [
            ("Image files", "*.png *.bmp *.tiff *.tif"),
            ("PNG files", "*.png"),
            ("BMP files", "*.bmp"),
            ("TIFF files", "*.tiff *.tif"),
            ("All files", "*.*")
        ]
        
        path = filedialog.askopenfilename(
            title="Select Encoded Image",
            filetypes=file_types
        )
        
        if path and os.path.exists(path):
            self._set_image_path(path)

    def _set_image_path(self, path):
        """Set the selected image path."""
        self.controller.image_path_decode = path
        self.image_path_entry.config(state='normal')
        self.image_path_entry.delete(0, tk.END)
        self.image_path_entry.insert(0, path)
        self.image_path_entry.config(state='readonly')
        
        # Update preview
        self.image_preview.display_image(path)
        self.controller._update_status("Encoded image selected", "success")

    def clear_fields(self):
        """Clear all input fields."""
        self.controller.image_path_decode = None
        self.image_path_entry.config(state='normal')
        self.image_path_entry.delete(0, tk.END)
        self.image_path_entry.insert(0, "Select or drag an encoded image...")
        self.image_path_entry.config(state='readonly')
        
        self.password_entry.delete(0, tk.END)
        self.image_preview.clear_image()
        
        # Clear result
        self.result_text.config(state='normal')
        self.result_text.delete(1.0, tk.END)
        self.result_text.config(state='disabled')
        
        # Disable result buttons
        self.copy_button.config(state='disabled')
        self.save_button.config(state='disabled')
        
        self.controller._update_status("Fields cleared")

    def decode_message(self):
        """Decode the hidden message."""
        # Validation
        if not self.controller.image_path_decode:
            messagebox.showwarning("Input Required", "Please select an encoded image.")
            return

        password = self.password_entry.get().strip()
        if not password:
            messagebox.showwarning("Input Required", "Please enter the password.")
            return

        # Decode in separate thread
        def decode_worker():
            try:
                self.controller._update_status("Decoding message...")
                self.controller.show_progress()
                
                for i in range(0, 80, 20):
                    self.controller.update_progress(i)
                    time.sleep(0.1)
                
                hidden_data = lsb.reveal(self.controller.image_path_decode)
                
                if not hidden_data:
                    raise ValueError("No hidden data found in this image.")
                
                key_prefix = f"KEY:{password}:::"
                if hidden_data.startswith(key_prefix):
                    secret_message = hidden_data.replace(key_prefix, "", 1)
                    
                    # Success callback
                    def success_callback():
                        self.controller.hide_progress()
                        self.result_text.config(state='normal', fg=self.controller.SUCCESS_COLOR)
                        self.result_text.delete(1.0, tk.END)
                        self.result_text.insert(1.0, secret_message)
                        self.result_text.config(state='disabled')
                        
                        # Enable result buttons
                        self.copy_button.config(state='normal')
                        self.save_button.config(state='normal')
                        
                        self.controller._update_status("Message decoded successfully!", "success")
                        
                    self.controller.master.after(0, success_callback)
                else:
                    raise ValueError("Incorrect password or corrupted data.")
                    
            except Exception as e:
                def error_callback():
                    self.controller.hide_progress()
                    self.result_text.config(state='normal', fg=self.controller.ERROR_COLOR)
                    self.result_text.delete(1.0, tk.END)
                    self.result_text.insert(1.0, f"DECODING FAILED:\n{str(e)}")
                    self.result_text.config(state='disabled')
                    
                    # Disable result buttons
                    self.copy_button.config(state='disabled')
                    self.save_button.config(state='disabled')
                    
                    self.controller._update_status("Decoding failed", "error")
                    
                self.controller.master.after(0, error_callback)

        threading.Thread(target=decode_worker, daemon=True).start()

    def copy_to_clipboard(self):
        """Copy decoded message to clipboard."""
        content = self.result_text.get(1.0, tk.END).strip()
        if content and not content.startswith("DECODING FAILED"):
            self.controller.master.clipboard_clear()
            self.controller.master.clipboard_append(content)
            self.controller._update_status("Message copied to clipboard", "success")
        else:
            messagebox.showwarning("Nothing to Copy", "No valid message to copy.")

    def save_to_file(self):
        """Save decoded message to file."""
        content = self.result_text.get(1.0, tk.END).strip()
        if content and not content.startswith("DECODING FAILED"):
            file_path = filedialog.asksaveasfilename(
                title="Save Decoded Message",
                defaultextension=".txt",
                filetypes=[("Text files", "*.txt"), ("All files", "*.*")]
            )
            
            if file_path:
                try:
                    with open(file_path, 'w', encoding='utf-8') as f:
                        f.write(content)
                    self.controller._update_status("Message saved to file", "success")
                    messagebox.showinfo("Success", f"Message saved to:\n{file_path}")
                except Exception as e:
                    messagebox.showerror("Error", f"Failed to save file:\n{str(e)}")
        else:
            messagebox.showwarning("Nothing to Save", "No valid message to save.")


if __name__ == "__main__":
    # Use TkinterDnD if available for drag-and-drop support
    if DND_AVAILABLE:
        root = TkinterDnD.Tk()
    else:
        root = tk.Tk()
    
    app = SteganographyApp(root)
    root.mainloop()