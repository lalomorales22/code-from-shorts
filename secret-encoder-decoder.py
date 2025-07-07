#!/usr/bin/env python3
"""
Message Encoder Decoder
A tool to encode and decode secret messages using emojis, letters, or numbers.
"""

import tkinter as tk
from tkinter import ttk, messagebox
import pyperclip
import string

class MessageEncoderDecoder:
    def __init__(self, root):
        self.root = root
        self.root.title("🌟 Message Encoder Decoder 🌟")
        self.root.geometry("600x1000")
        self.root.configure(bg="#000000")
        
        # Selected carrier
        self.selected_carrier = tk.StringVar(value="✨")
        self.carrier_type = tk.StringVar(value="emoji")
        
        # Store button references for highlighting
        self.carrier_buttons = []
        self.current_selected_btn = None
        
        # Emoji sets
        self.emojis = [
            "😀", "😃", "😄", "😁", "😆", "😅", "😂", "🤣", "😊", "😇", "🙂", "🙃", "😉", "😌",
            "😍", "🥰", "😘", "😗", "😙", "😚", "😋", "😛", "😝", "😜", "🤪", "🤨", "🧐", "🤓",
            "😎", "🤩", "🥳", "😏", "😒", "😞", "😔", "😟", "😕", "🙁", "☹️", "😣", "😖", "😫",
            "😩", "🥺", "😢", "😭", "😤", "😠", "😡", "🤬", "🤯", "😳", "🥵", "🥶", "😱", "😨",
            "😰", "😥", "😓", "🤗", "🤔", "🤭", "🤫", "🤥", "😶", "😐", "😑", "😬", "🙄", "😯",
            "😦", "😧", "😮", "😲", "🥱", "😴", "🤤", "😪", "😵", "🤐", "🥴", "🤢", "🤮", "🤧",
            "😷", "🤒", "🤕", "🤑", "🤠", "😈", "👿", "👹", "👺", "🤡", "💩", "👻", "💀", "☠️",
            "👽", "👾", "🤖", "🎃", "😺", "😸", "😹", "😻", "😼", "😽", "🙀", "😿", "😾", "🐶"
        ]
        
        self.letters = list(string.ascii_lowercase)
        self.numbers = [str(i) for i in range(10)]
        
        self.setup_ui()
    
    def setup_ui(self):
        # Main container
        main_frame = tk.Frame(self.root, bg="#000000")
        main_frame.pack(fill=tk.BOTH, expand=True, padx=15, pady=15)
        
        # Title
        title_label = tk.Label(
            main_frame, 
            text="🌟 Message Encoder Decoder 🌟",
            font=("Arial", 18, "bold"),
            fg="#ffffff",
            bg="#000000"
        )
        title_label.pack(pady=8)
        
        # Description
        desc_label = tk.Label(
            main_frame,
            text="Encode secret messages into emojis, letters, or numbers",
            font=("Arial", 9),
            fg="#666666",
            bg="#000000"
        )
        desc_label.pack(pady=3)
        
        # Notebook for tabs
        style = ttk.Style()
        style.theme_use('clam')
        style.configure('TNotebook', background='#000000', borderwidth=0)
        style.configure('TNotebook.Tab', background='#111111', foreground='#cccccc', padding=[12, 8])
        style.map('TNotebook.Tab', background=[('selected', '#000000')], foreground=[('selected', '#ffffff')])
        
        self.notebook = ttk.Notebook(main_frame)
        self.notebook.pack(fill=tk.BOTH, expand=True, pady=8)
        
        # Create tabs
        self.encode_frame = tk.Frame(self.notebook, bg="#000000")
        self.decode_frame = tk.Frame(self.notebook, bg="#000000")
        
        self.notebook.add(self.encode_frame, text="Encode 🔒")
        self.notebook.add(self.decode_frame, text="Decode 🔓")
        
        self.setup_encode_tab()
        self.setup_decode_tab()
    
    def setup_encode_tab(self):
        # Message input
        tk.Label(
            self.encode_frame,
            text="Enter your secret message",
            font=("Arial", 11, "bold"),
            fg="#ffffff",
            bg="#000000"
        ).pack(anchor=tk.W, padx=15, pady=(15, 5))
        
        self.message_text = tk.Text(
            self.encode_frame,
            height=4,
            font=("Arial", 10),
            bg="#0a0a0a",
            fg="#ffffff",
            insertbackground="#ffffff",
            bd=1,
            relief=tk.SOLID,
            highlightthickness=0
        )
        self.message_text.pack(fill=tk.X, padx=15, pady=5)
        
        # Carrier selection
        carrier_frame = tk.Frame(self.encode_frame, bg="#000000")
        carrier_frame.pack(fill=tk.BOTH, expand=True, padx=15, pady=8)
        
        # Emoji carrier
        self.setup_emoji_carrier(carrier_frame)
        
        # Letter carrier
        self.setup_letter_carrier(carrier_frame)
        
        # Number carrier
        self.setup_number_carrier(carrier_frame)
        
        # Current selection display
        selection_frame = tk.Frame(self.encode_frame, bg="#000000")
        selection_frame.pack(fill=tk.X, padx=15, pady=8)
        
        tk.Label(
            selection_frame,
            text="Selected:",
            font=("Arial", 10),
            fg="#cccccc",
            bg="#000000"
        ).pack(side=tk.LEFT)
        
        self.selection_display = tk.Label(
            selection_frame,
            text="✨",
            font=("Arial", 16, "bold"),
            fg="#ffffff",
            bg="#000000"
        )
        self.selection_display.pack(side=tk.LEFT, padx=10)
        
        # Encoded output
        self.encoded_label = tk.Label(
            self.encode_frame,
            text="Encoded message with ✨",
            font=("Arial", 11, "bold"),
            fg="#ffffff",
            bg="#000000"
        )
        self.encoded_label.pack(anchor=tk.W, padx=15, pady=(8, 5))
        
        output_frame = tk.Frame(self.encode_frame, bg="#000000")
        output_frame.pack(fill=tk.X, padx=15, pady=5)
        
        self.encoded_text = tk.Text(
            output_frame,
            height=4,
            font=("Arial", 10),
            bg="#0a0a0a",
            fg="#ffffff",
            state=tk.DISABLED,
            bd=1,
            relief=tk.SOLID,
            highlightthickness=0
        )
        self.encoded_text.pack(side=tk.LEFT, fill=tk.BOTH, expand=True)
        
        copy_btn = tk.Button(
            output_frame,
            text="Copy",
            command=self.copy_encoded,
            bg="#222222",
            fg="#ffffff",
            font=("Arial", 9, "bold"),
            cursor="hand2",
            bd=0,
            relief=tk.FLAT,
            activebackground="#333333"
        )
        copy_btn.pack(side=tk.RIGHT, padx=(8, 0), fill=tk.Y)
        
        # Encode button
        encode_btn = tk.Button(
            self.encode_frame,
            text="Encode Message",
            command=self.encode_message,
            bg="#ffffff",
            fg="#000000",
            font=("Arial", 11, "bold"),
            cursor="hand2",
            pady=8,
            bd=0,
            relief=tk.FLAT,
            activebackground="#cccccc"
        )
        encode_btn.pack(pady=15)
    
    def setup_decode_tab(self):
        # Encoded message input
        tk.Label(
            self.decode_frame,
            text="Enter encoded message to decode",
            font=("Arial", 11, "bold"),
            fg="#ffffff",
            bg="#000000"
        ).pack(anchor=tk.W, padx=15, pady=(15, 5))
        
        self.decode_input = tk.Text(
            self.decode_frame,
            height=6,
            font=("Arial", 10),
            bg="#0a0a0a",
            fg="#ffffff",
            insertbackground="#ffffff",
            bd=1,
            relief=tk.SOLID,
            highlightthickness=0
        )
        self.decode_input.pack(fill=tk.X, padx=15, pady=5)
        
        # Decode button
        decode_btn = tk.Button(
            self.decode_frame,
            text="Decode Message",
            command=self.decode_message,
            bg="#ffffff",
            fg="#000000",
            font=("Arial", 11, "bold"),
            cursor="hand2",
            pady=8,
            bd=0,
            relief=tk.FLAT,
            activebackground="#cccccc"
        )
        decode_btn.pack(pady=15)
        
        # Decoded output
        tk.Label(
            self.decode_frame,
            text="Decoded message:",
            font=("Arial", 11, "bold"),
            fg="#ffffff",
            bg="#000000"
        ).pack(anchor=tk.W, padx=15, pady=(8, 5))
        
        output_frame = tk.Frame(self.decode_frame, bg="#000000")
        output_frame.pack(fill=tk.X, padx=15, pady=5)
        
        self.decoded_text = tk.Text(
            output_frame,
            height=4,
            font=("Arial", 10),
            bg="#0a0a0a",
            fg="#ffffff",
            state=tk.DISABLED,
            bd=1,
            relief=tk.SOLID,
            highlightthickness=0
        )
        self.decoded_text.pack(side=tk.LEFT, fill=tk.BOTH, expand=True)
        
        copy_decoded_btn = tk.Button(
            output_frame,
            text="Copy",
            command=self.copy_decoded,
            bg="#222222",
            fg="#ffffff",
            font=("Arial", 9, "bold"),
            cursor="hand2",
            bd=0,
            relief=tk.FLAT,
            activebackground="#333333"
        )
        copy_decoded_btn.pack(side=tk.RIGHT, padx=(8, 0), fill=tk.Y)
    
    def setup_emoji_carrier(self, parent):
        emoji_frame = tk.LabelFrame(
            parent,
            text="Pick an emoji carrier",
            font=("Arial", 9, "bold"),
            fg="#cccccc",
            bg="#000000",
            bd=1,
            relief=tk.SOLID
        )
        emoji_frame.pack(fill=tk.BOTH, expand=True, pady=3)
        
        # Create grid frame for emojis
        emoji_grid = tk.Frame(emoji_frame, bg="#000000")
        emoji_grid.pack(padx=5, pady=5)
        
        # Create grid of emoji buttons with 8 columns
        for i, emoji in enumerate(self.emojis):
            row = i // 8
            col = i % 8
            
            btn = tk.Button(
                emoji_grid,
                text=emoji,
                font=("Arial", 11),
                width=3,
                height=1,
                command=lambda e=emoji, b=None: self.select_carrier(e, "emoji", b),
                bg="#111111",
                fg="#ffffff",
                cursor="hand2",
                relief=tk.FLAT,
                bd=0,
                activebackground="#222222"
            )
            btn.grid(row=row, column=col, padx=1, pady=1)
            
            # Update the button's command to pass itself
            btn.config(command=lambda e=emoji, b=btn: self.select_carrier(e, "emoji", b))
            self.carrier_buttons.append(btn)
            
            # Set initial selection for the first emoji
            if emoji == "✨":
                self.current_selected_btn = btn
                btn.config(bg="#ffffff", fg="#000000")
    
    def setup_letter_carrier(self, parent):
        letter_frame = tk.LabelFrame(
            parent,
            text="Or pick a letter carrier",
            font=("Arial", 9, "bold"),
            fg="#cccccc",
            bg="#000000",
            bd=1,
            relief=tk.SOLID
        )
        letter_frame.pack(fill=tk.X, pady=3)
        
        # Create grid frame for letters
        letter_grid = tk.Frame(letter_frame, bg="#000000")
        letter_grid.pack(padx=5, pady=5)
        
        # Create grid of letter buttons with 8 columns
        for i, letter in enumerate(self.letters):
            row = i // 8
            col = i % 8
            
            btn = tk.Button(
                letter_grid,
                text=letter,
                font=("Arial", 9, "bold"),
                width=3,
                height=1,
                command=lambda l=letter, b=None: self.select_carrier(l, "letter", b),
                bg="#111111",
                fg="#ffffff",
                cursor="hand2",
                relief=tk.FLAT,
                bd=0,
                activebackground="#222222"
            )
            btn.grid(row=row, column=col, padx=1, pady=1)
            
            # Update the button's command to pass itself
            btn.config(command=lambda l=letter, b=btn: self.select_carrier(l, "letter", b))
            self.carrier_buttons.append(btn)
    
    def setup_number_carrier(self, parent):
        number_frame = tk.LabelFrame(
            parent,
            text="Or pick a number carrier",
            font=("Arial", 9, "bold"),
            fg="#cccccc",
            bg="#000000",
            bd=1,
            relief=tk.SOLID
        )
        number_frame.pack(fill=tk.X, pady=3)
        
        # Create frame for number buttons
        number_grid = tk.Frame(number_frame, bg="#000000")
        number_grid.pack(padx=5, pady=5)
        
        for i, number in enumerate(self.numbers):
            row = i // 5  # 5 numbers per row
            col = i % 5
            
            btn = tk.Button(
                number_grid,
                text=number,
                font=("Arial", 9, "bold"),
                width=3,
                height=1,
                command=lambda n=number, b=None: self.select_carrier(n, "number", b),
                bg="#111111",
                fg="#ffffff",
                cursor="hand2",
                relief=tk.FLAT,
                bd=0,
                activebackground="#222222"
            )
            btn.grid(row=row, column=col, padx=1, pady=1)
            
            # Update the button's command to pass itself
            btn.config(command=lambda n=number, b=btn: self.select_carrier(n, "number", b))
            self.carrier_buttons.append(btn)
    
    def select_carrier(self, carrier, carrier_type, button):
        # Reset previous selection
        if self.current_selected_btn:
            if self.carrier_type.get() == "emoji":
                self.current_selected_btn.config(bg="#111111", fg="#ffffff")
            else:
                self.current_selected_btn.config(bg="#111111", fg="#ffffff")
        
        # Set new selection
        self.selected_carrier.set(carrier)
        self.carrier_type.set(carrier_type)
        self.current_selected_btn = button
        
        # Highlight selected button
        if button:
            button.config(bg="#ffffff", fg="#000000")
        
        # Update displays
        self.selection_display.config(text=carrier)
        self.encoded_label.config(text=f"Encoded message with {carrier}")
    
    def encode_message(self):
        message = self.message_text.get("1.0", tk.END).strip()
        if not message:
            messagebox.showwarning("Warning", "Please enter a message to encode!")
            return
        
        carrier = self.selected_carrier.get()
        encoded = self.encode_text(message, carrier)
        
        # Display encoded message
        self.encoded_text.config(state=tk.NORMAL)
        self.encoded_text.delete("1.0", tk.END)
        self.encoded_text.insert("1.0", encoded)
        self.encoded_text.config(state=tk.DISABLED)
    
    def decode_message(self):
        encoded_message = self.decode_input.get("1.0", tk.END).strip()
        if not encoded_message:
            messagebox.showwarning("Warning", "Please enter an encoded message to decode!")
            return
        
        try:
            decoded = self.decode_text(encoded_message)
            
            # Display decoded message
            self.decoded_text.config(state=tk.NORMAL)
            self.decoded_text.delete("1.0", tk.END)
            self.decoded_text.insert("1.0", decoded)
            self.decoded_text.config(state=tk.DISABLED)
        except Exception as e:
            messagebox.showerror("Error", "Failed to decode message. Please check the format.")
    
    def encode_text(self, text, carrier):
        """
        Encode text using the selected carrier.
        Each character is converted to its ASCII value, then represented as repetitions of the carrier.
        """
        encoded_parts = []
        
        for char in text:
            ascii_val = ord(char)
            # Represent ASCII value as repetitions of carrier
            encoded_char = carrier * ascii_val
            encoded_parts.append(encoded_char)
        
        # Join with spaces and add metadata
        encoded = " | ".join(encoded_parts)
        return f"[{carrier}]" + encoded
    
    def decode_text(self, encoded_text):
        """
        Decode text by counting carrier repetitions and converting back to ASCII.
        """
        # Extract carrier from metadata
        if not encoded_text.startswith("[") or "]" not in encoded_text:
            raise ValueError("Invalid format")
        
        carrier_end = encoded_text.index("]")
        carrier = encoded_text[1:carrier_end]
        content = encoded_text[carrier_end + 1:]
        
        # Split by delimiter
        parts = content.split(" | ")
        decoded_chars = []
        
        for part in parts:
            if not part:
                continue
            # Count occurrences of carrier
            count = part.count(carrier)
            if count == 0:
                continue
            # Convert back to character
            decoded_chars.append(chr(count))
        
        return "".join(decoded_chars)
    
    def copy_encoded(self):
        encoded_message = self.encoded_text.get("1.0", tk.END).strip()
        if encoded_message:
            pyperclip.copy(encoded_message)
    
    def copy_decoded(self):
        decoded_message = self.decoded_text.get("1.0", tk.END).strip()
        if decoded_message:
            pyperclip.copy(decoded_message)

def main():
    try:
        import pyperclip
    except ImportError:
        print("Installing required package: pyperclip")
        import subprocess
        import sys
        subprocess.check_call([sys.executable, "-m", "pip", "install", "pyperclip"])
        import pyperclip
    
    root = tk.Tk()
    app = MessageEncoderDecoder(root)
    root.mainloop()

if __name__ == "__main__":
    main()