#
# Modern Bitcoin Wallet GUI - 2025 Edition
# 
# A secure, local Bitcoin wallet generator with modern dark theme design
# Built with current best practices for 2025
#
# Dependencies (install with pip):
# pip install bit customtkinter pycryptodome qrcode Pillow
#

import tkinter
import customtkinter
import os
import json
import base64
import secrets
import sqlite3
from Crypto.Cipher import AES
from Crypto.Protocol.KDF import PBKDF2
from Crypto.Hash import SHA256
from Crypto.Random import get_random_bytes
from bit import PrivateKey
from PIL import Image, ImageTk
import qrcode
import threading
from datetime import datetime

# === CONSTANTS ===
APP_NAME = "Bitcoin Wallet 2025"
WALLET_DIR = "wallets"
DATABASE_FILE = "wallet_database.db"
DEFAULT_GEOMETRY = "700x850"
PBKDF2_ITERATIONS = 600000  # 2025 NIST recommendation

# === MODERN DARK THEME COLORS ===
COLORS = {
    "bg_primary": "#0a0a0a",        # Deep black background
    "bg_secondary": "#1a1a1a",      # Secondary dark
    "bg_tertiary": "#2a2a2a",       # Tertiary surface
    "accent_primary": "#00d4ff",    # Cyan accent
    "accent_secondary": "#0066cc",   # Blue accent
    "text_primary": "#ffffff",       # Primary text
    "text_secondary": "#cccccc",     # Secondary text
    "text_muted": "#888888",         # Muted text
    "success": "#00ff88",            # Success green
    "warning": "#ffaa00",            # Warning orange
    "error": "#ff4444",              # Error red
    "border": "#333333",             # Border color
}

class ModernBitcoinWallet(customtkinter.CTk):
    """
    Modern Bitcoin Wallet GUI with 2025 design principles and security practices
    """
    
    def __init__(self):
        super().__init__()
        
        # === WINDOW SETUP ===
        self.title(APP_NAME)
        self.geometry(DEFAULT_GEOMETRY)
        self.center_window()
        
        # === MODERN THEME CONFIGURATION ===
        customtkinter.set_appearance_mode("dark")
        customtkinter.set_default_color_theme("blue")
        
        # Configure window colors
        self.configure(fg_color=COLORS["bg_primary"])
        
        # === DIRECTORY SETUP ===
        if not os.path.exists(WALLET_DIR):
            os.makedirs(WALLET_DIR)
        
        # === DATABASE SETUP ===
        self.init_database()
        
        # === UI SETUP ===
        self.setup_ui()
        
        # === WALLET DATA ===
        self.current_wallet = None
        
    def center_window(self):
        """Center the window on screen"""
        self.update_idletasks()
        width = self.winfo_reqwidth()
        height = self.winfo_reqheight()
        x = (self.winfo_screenwidth() // 2) - (width // 2)
        y = (self.winfo_screenheight() // 2) - (height // 2)
        self.geometry(f'+{x}+{y}')
    
    def init_database(self):
        """Initialize SQLite database for storing wallet metadata"""
        try:
            self.db_conn = sqlite3.connect(DATABASE_FILE)
            self.db_conn.row_factory = sqlite3.Row  # Enable column access by name
            
            # Create wallets table
            cursor = self.db_conn.cursor()
            cursor.execute('''
                CREATE TABLE IF NOT EXISTS wallets (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    wallet_name TEXT UNIQUE NOT NULL,
                    address TEXT NOT NULL,
                    wallet_type TEXT DEFAULT 'SegWit',
                    created_date TEXT NOT NULL,
                    last_accessed TEXT,
                    file_path TEXT NOT NULL,
                    is_active INTEGER DEFAULT 1,
                    notes TEXT
                )
            ''')
            
            # Create wallet_stats table for tracking usage
            cursor.execute('''
                CREATE TABLE IF NOT EXISTS wallet_stats (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    wallet_id INTEGER,
                    access_count INTEGER DEFAULT 0,
                    last_unlock_date TEXT,
                    created_date TEXT DEFAULT CURRENT_TIMESTAMP,
                    FOREIGN KEY (wallet_id) REFERENCES wallets (id)
                )
            ''')
            
            self.db_conn.commit()
            print("✅ Database initialized successfully")
            
        except sqlite3.Error as e:
            print(f"❌ Database initialization error: {e}")
            # Fallback to file-only mode
            self.db_conn = None
    
    def add_wallet_to_db(self, wallet_name, address, file_path):
        """Add a new wallet to the database"""
        if not self.db_conn:
            return False
        
        try:
            cursor = self.db_conn.cursor()
            current_time = datetime.now().isoformat()
            
            cursor.execute('''
                INSERT INTO wallets (wallet_name, address, created_date, last_accessed, file_path)
                VALUES (?, ?, ?, ?, ?)
            ''', (wallet_name, address, current_time, current_time, file_path))
            
            wallet_id = cursor.lastrowid
            
            # Initialize stats
            cursor.execute('''
                INSERT INTO wallet_stats (wallet_id, access_count, last_unlock_date)
                VALUES (?, 0, ?)
            ''', (wallet_id, current_time))
            
            self.db_conn.commit()
            return True
            
        except sqlite3.Error as e:
            print(f"Database error adding wallet: {e}")
            return False
    
    def update_wallet_access(self, wallet_name):
        """Update wallet access statistics"""
        if not self.db_conn:
            return
        
        try:
            cursor = self.db_conn.cursor()
            current_time = datetime.now().isoformat()
            
            # Update last accessed time
            cursor.execute('''
                UPDATE wallets 
                SET last_accessed = ? 
                WHERE wallet_name = ?
            ''', (current_time, wallet_name))
            
            # Update stats
            cursor.execute('''
                UPDATE wallet_stats 
                SET access_count = access_count + 1, last_unlock_date = ?
                WHERE wallet_id = (SELECT id FROM wallets WHERE wallet_name = ?)
            ''', (current_time, wallet_name))
            
            self.db_conn.commit()
            
        except sqlite3.Error as e:
            print(f"Database error updating access: {e}")
    
    def get_wallets_from_db(self):
        """Get all wallets from database with metadata"""
        if not self.db_conn:
            return []
        
        try:
            cursor = self.db_conn.cursor()
            cursor.execute('''
                SELECT w.wallet_name, w.address, w.created_date, w.last_accessed, w.file_path,
                       s.access_count, s.last_unlock_date
                FROM wallets w
                LEFT JOIN wallet_stats s ON w.id = s.wallet_id
                WHERE w.is_active = 1
                ORDER BY w.last_accessed DESC
            ''')
            
            return cursor.fetchall()
            
        except sqlite3.Error as e:
            print(f"Database error getting wallets: {e}")
            return []
    
    def remove_wallet_from_db(self, wallet_name):
        """Mark wallet as inactive in database"""
        if not self.db_conn:
            return False
        
        try:
            cursor = self.db_conn.cursor()
            cursor.execute('''
                UPDATE wallets 
                SET is_active = 0 
                WHERE wallet_name = ?
            ''', (wallet_name,))
            
            self.db_conn.commit()
            return True
            
        except sqlite3.Error as e:
            print(f"Database error removing wallet: {e}")
            return False
    
    def close_database(self):
        """Close database connection"""
        if self.db_conn:
            self.db_conn.close()
    
    def setup_ui(self):
        """Setup the modern UI layout"""
        # Configure grid
        self.grid_columnconfigure(0, weight=1)
        self.grid_rowconfigure(1, weight=1)
        
        # === HEADER ===
        self.create_header()
        
        # === MAIN CONTENT ===
        self.create_main_content()
        
        # === FOOTER ===
        self.create_footer()
    
    def create_header(self):
        """Create modern header with gradient effect"""
        header_frame = customtkinter.CTkFrame(
            self,
            height=80,
            fg_color=COLORS["bg_secondary"],
            corner_radius=0
        )
        header_frame.grid(row=0, column=0, sticky="ew", padx=0, pady=0)
        header_frame.grid_columnconfigure(1, weight=1)
        
        # App icon/logo area
        logo_frame = customtkinter.CTkFrame(
            header_frame,
            width=60,
            fg_color="transparent"
        )
        logo_frame.grid(row=0, column=0, padx=20, pady=15, sticky="w")
        
        # Bitcoin symbol
        bitcoin_label = customtkinter.CTkLabel(
            logo_frame,
            text="₿",
            font=customtkinter.CTkFont(size=32, weight="bold"),
            text_color=COLORS["accent_primary"]
        )
        bitcoin_label.pack()
        
        # Title
        title_label = customtkinter.CTkLabel(
            header_frame,
            text=APP_NAME,
            font=customtkinter.CTkFont(size=24, weight="bold"),
            text_color=COLORS["text_primary"]
        )
        title_label.grid(row=0, column=1, padx=20, pady=15)
        
        # Status indicator
        self.status_frame = customtkinter.CTkFrame(
            header_frame,
            fg_color="transparent"
        )
        self.status_frame.grid(row=0, column=2, padx=20, pady=15, sticky="e")
        
        self.status_label = customtkinter.CTkLabel(
            self.status_frame,
            text="🔒 Secure",
            font=customtkinter.CTkFont(size=12),
            text_color=COLORS["success"]
        )
        self.status_label.pack()
    
    def create_main_content(self):
        """Create the main tabbed interface"""
        # Main container
        main_frame = customtkinter.CTkFrame(
            self,
            fg_color=COLORS["bg_primary"],
            corner_radius=0
        )
        main_frame.grid(row=1, column=0, sticky="nsew", padx=0, pady=0)
        main_frame.grid_columnconfigure(0, weight=1)
        main_frame.grid_rowconfigure(0, weight=1)
        
        # Tabview with modern styling
        self.tabview = customtkinter.CTkTabview(
            main_frame,
            width=650,
            height=600,
            fg_color=COLORS["bg_secondary"],
            segmented_button_fg_color=COLORS["bg_tertiary"],
            segmented_button_selected_color=COLORS["accent_primary"],
            segmented_button_selected_hover_color=COLORS["accent_secondary"]
        )
        self.tabview.grid(row=0, column=0, padx=20, pady=20, sticky="nsew")
        
        # Add tabs
        self.tabview.add("🔑 Create Wallet")
        self.tabview.add("📂 Load Wallet") 
        self.tabview.add("ℹ️ Security Guide")
        
        # Setup tab content
        self.setup_create_tab()
        self.setup_load_tab()
        self.setup_guide_tab()
    
    def setup_create_tab(self):
        """Setup the create wallet tab with modern design"""
        tab = self.tabview.tab("🔑 Create Wallet")
        tab.grid_columnconfigure(0, weight=1)
        
        # Instructions
        instructions = customtkinter.CTkLabel(
            tab,
            text="Create a new secure Bitcoin wallet",
            font=customtkinter.CTkFont(size=16, weight="bold"),
            text_color=COLORS["text_primary"]
        )
        instructions.grid(row=0, column=0, pady=(20, 30), sticky="w")
        
        # Wallet name input
        name_label = customtkinter.CTkLabel(
            tab,
            text="Wallet Name",
            font=customtkinter.CTkFont(size=14, weight="bold"),
            text_color=COLORS["text_secondary"]
        )
        name_label.grid(row=1, column=0, pady=(0, 5), sticky="w")
        
        self.name_entry = customtkinter.CTkEntry(
            tab,
            placeholder_text="Enter a unique wallet name...",
            height=40,
            font=customtkinter.CTkFont(size=14),
            fg_color=COLORS["bg_tertiary"],
            border_color=COLORS["border"],
            text_color=COLORS["text_primary"]
        )
        self.name_entry.grid(row=2, column=0, pady=(0, 20), sticky="ew")
        
        # Password inputs
        pass_label = customtkinter.CTkLabel(
            tab,
            text="Master Password (CRITICAL - Cannot be recovered!)",
            font=customtkinter.CTkFont(size=14, weight="bold"),
            text_color=COLORS["warning"]
        )
        pass_label.grid(row=3, column=0, pady=(0, 5), sticky="w")
        
        self.password_entry = customtkinter.CTkEntry(
            tab,
            placeholder_text="Enter a strong password...",
            show="*",
            height=40,
            font=customtkinter.CTkFont(size=14),
            fg_color=COLORS["bg_tertiary"],
            border_color=COLORS["border"],
            text_color=COLORS["text_primary"]
        )
        self.password_entry.grid(row=4, column=0, pady=(0, 10), sticky="ew")
        
        self.confirm_entry = customtkinter.CTkEntry(
            tab,
            placeholder_text="Confirm your password...",
            show="*",
            height=40,
            font=customtkinter.CTkFont(size=14),
            fg_color=COLORS["bg_tertiary"],
            border_color=COLORS["border"],
            text_color=COLORS["text_primary"]
        )
        self.confirm_entry.grid(row=5, column=0, pady=(0, 30), sticky="ew")
        
        # Create button
        self.create_btn = customtkinter.CTkButton(
            tab,
            text="🚀 Create Secure Wallet",
            command=self.create_wallet_threaded,
            height=50,
            font=customtkinter.CTkFont(size=16, weight="bold"),
            fg_color=COLORS["accent_primary"],
            hover_color=COLORS["accent_secondary"],
            text_color=COLORS["bg_primary"]
        )
        self.create_btn.grid(row=6, column=0, pady=(0, 20), sticky="ew")
        
        # Progress bar
        self.progress_bar = customtkinter.CTkProgressBar(
            tab,
            height=8,
            fg_color=COLORS["bg_tertiary"],
            progress_color=COLORS["accent_primary"]
        )
        self.progress_bar.grid(row=7, column=0, pady=(0, 20), sticky="ew")
        self.progress_bar.set(0)
        
        # Results frame
        self.create_results_frame = customtkinter.CTkScrollableFrame(
            tab,
            height=200,
            fg_color=COLORS["bg_tertiary"],
            corner_radius=10
        )
        self.create_results_frame.grid(row=8, column=0, sticky="ew")
        self.create_results_frame.grid_columnconfigure(0, weight=1)
    
    def setup_load_tab(self):
        """Setup the load wallet tab"""
        tab = self.tabview.tab("📂 Load Wallet")
        tab.grid_columnconfigure(0, weight=1)
        
        # Instructions
        instructions = customtkinter.CTkLabel(
            tab,
            text="Load an existing wallet",
            font=customtkinter.CTkFont(size=16, weight="bold"),
            text_color=COLORS["text_primary"]
        )
        instructions.grid(row=0, column=0, pady=(20, 30), sticky="w")
        
        # Wallet selector
        select_label = customtkinter.CTkLabel(
            tab,
            text="Select Wallet",
            font=customtkinter.CTkFont(size=14, weight="bold"),
            text_color=COLORS["text_secondary"]
        )
        select_label.grid(row=1, column=0, pady=(0, 5), sticky="w")
        
        self.wallet_selector = customtkinter.CTkOptionMenu(
            tab,
            values=self.get_wallet_list(),
            height=40,
            font=customtkinter.CTkFont(size=14),
            fg_color=COLORS["bg_tertiary"],
            button_color=COLORS["accent_primary"],
            button_hover_color=COLORS["accent_secondary"]
        )
        self.wallet_selector.grid(row=2, column=0, pady=(0, 10), sticky="ew")
        
        # Refresh button with stats
        button_frame = customtkinter.CTkFrame(tab, fg_color="transparent")
        button_frame.grid(row=3, column=0, pady=(0, 20), sticky="ew")
        button_frame.grid_columnconfigure(1, weight=1)
        
        refresh_btn = customtkinter.CTkButton(
            button_frame,
            text="🔄 Refresh List",
            command=self.refresh_wallet_list,
            height=35,
            width=120,
            font=customtkinter.CTkFont(size=12),
            fg_color=COLORS["bg_tertiary"],
            hover_color=COLORS["border"],
            text_color=COLORS["text_secondary"]
        )
        refresh_btn.grid(row=0, column=0, sticky="w")
        
        # Wallet count label
        self.wallet_count_label = customtkinter.CTkLabel(
            button_frame,
            text="",
            font=customtkinter.CTkFont(size=11),
            text_color=COLORS["text_muted"]
        )
        self.wallet_count_label.grid(row=0, column=1, padx=(10, 0), sticky="w")
        self.update_wallet_count()
        
        # Password input
        pass_label = customtkinter.CTkLabel(
            tab,
            text="Enter Password",
            font=customtkinter.CTkFont(size=14, weight="bold"),
            text_color=COLORS["text_secondary"]
        )
        pass_label.grid(row=4, column=0, pady=(0, 5), sticky="w")
        
        self.load_password_entry = customtkinter.CTkEntry(
            tab,
            placeholder_text="Enter wallet password...",
            show="*",
            height=40,
            font=customtkinter.CTkFont(size=14),
            fg_color=COLORS["bg_tertiary"],
            border_color=COLORS["border"],
            text_color=COLORS["text_primary"]
        )
        self.load_password_entry.grid(row=5, column=0, pady=(0, 30), sticky="ew")
        
        # Load button
        load_btn = customtkinter.CTkButton(
            tab,
            text="🔓 Unlock Wallet",
            command=self.load_wallet,
            height=50,
            font=customtkinter.CTkFont(size=16, weight="bold"),
            fg_color=COLORS["success"],
            hover_color="#00cc77",
            text_color=COLORS["bg_primary"]
        )
        load_btn.grid(row=6, column=0, pady=(0, 20), sticky="ew")
        
        # Results frame (non-scrollable to show all content)
        self.load_results_frame = customtkinter.CTkFrame(
            tab,
            fg_color=COLORS["bg_tertiary"],
            corner_radius=10
        )
        self.load_results_frame.grid(row=7, column=0, sticky="ew", pady=(0, 10))
        self.load_results_frame.grid_columnconfigure(0, weight=1)
    
    def setup_guide_tab(self):
        """Setup the security guide tab"""
        tab = self.tabview.tab("ℹ️ Security Guide")
        
        # Scrollable text
        guide_text = """
🔐 BITCOIN WALLET SECURITY GUIDE 2025

⚠️ CRITICAL WARNINGS:

• Your password is your ONLY way to access funds
• If you lose your password, your Bitcoin is GONE FOREVER
• No company, bank, or government can help recover lost passwords
• This wallet creates REAL Bitcoin addresses on the mainnet

🛡️ SECURITY BEST PRACTICES:

Password Security:
• Use a unique, strong password (minimum 16 characters)
• Include uppercase, lowercase, numbers, and symbols
• Never use personal information or common words
• Consider using a password manager

Backup Strategy:
• Write down your password in multiple secure locations
• Store backup copies in fireproof safes or safety deposit boxes
• Never store passwords digitally (email, cloud, etc.)
• Test your backups periodically

Wallet File Protection:
• Your wallet file is encrypted but still needs protection
• Store multiple copies in different secure locations
• Use encrypted USB drives for backup storage
• Never share wallet files with anyone

🚀 MODERN FEATURES (2025):

• AES-256-GCM encryption with 600,000 PBKDF2 iterations
• Secure random number generation using cryptographic standards
• Native SegWit (Bech32) addresses for lower transaction fees
• Modern dark theme UI optimized for extended use
• High-DPI support for all screen types

💡 USAGE TIPS:

• Start with small amounts to test the wallet
• Always verify receiving addresses before sending funds
• Keep the wallet software updated
• Use a dedicated computer for large amounts
• Consider hardware wallets for long-term storage

📱 QR CODE FEATURES:

• Address QR Code: Share this to receive Bitcoin payments
• Blockchain Explorer QR: Scan to check balance and transactions
• Multiple explorer options: Blockchain.info, Mempool.space, Blockstream
• Real-time balance checking without entering private keys
• Safe to share address QR codes publicly

📈 TRANSACTION FEES:

• SegWit addresses reduce transaction fees by 20-40%
• Check current network fees before sending
• Higher fees = faster confirmation times
• Lower fees = slower but still secure transactions

⚖️ LEGAL NOTICE:

This software is provided "as is" without warranty. Users are
responsible for their own security and fund management. The
developers are not liable for any loss of funds or data.

🔗 QR CODE TYPES:

Address Only (📧):
• Contains just your Bitcoin address
• Safe to share publicly for receiving payments
• Scannable by any Bitcoin wallet app

Blockchain Explorer (💰):
• Links to Blockchain.info to view wallet balance
• Shows transaction history and current holdings
• Does NOT contain private keys - safe to share

Mempool.space (🔗):
• Advanced Bitcoin statistics and mempool data
• Real-time transaction tracking
• Technical analysis tools

Blockstream Explorer (📊):
• Professional-grade blockchain explorer
• Detailed transaction and address analytics
• Lightning Network integration

Remember: Address QR codes are for RECEIVING Bitcoin.
Never share private key QR codes!

• Bitcoin.org - Official Bitcoin information
• BitcoinCore.org - Reference implementation
• BitcoinTalk.org - Community discussions
• GitHub.com/bitcoin/bitcoin - Source code

Remember: You are your own bank. With great power
comes great responsibility! 🚀
        """
        
        textbox = customtkinter.CTkTextbox(
            tab,
            wrap="word",
            font=customtkinter.CTkFont(size=13),
            fg_color=COLORS["bg_tertiary"],
            text_color=COLORS["text_secondary"]
        )
        textbox.grid(row=0, column=0, padx=10, pady=10, sticky="nsew")
        textbox.insert("1.0", guide_text)
        textbox.configure(state="disabled")
        
        tab.grid_columnconfigure(0, weight=1)
        tab.grid_rowconfigure(0, weight=1)
    
    def create_footer(self):
        """Create footer with status and info"""
        footer_frame = customtkinter.CTkFrame(
            self,
            height=40,
            fg_color=COLORS["bg_secondary"],
            corner_radius=0
        )
        footer_frame.grid(row=2, column=0, sticky="ew", padx=0, pady=0)
        footer_frame.grid_columnconfigure(1, weight=1)
        
        # Version info
        version_label = customtkinter.CTkLabel(
            footer_frame,
            text="v2025.1 | Secure Local Wallet",
            font=customtkinter.CTkFont(size=10),
            text_color=COLORS["text_muted"]
        )
        version_label.grid(row=0, column=0, padx=15, pady=10, sticky="w")
        
        # Timestamp
        self.timestamp_label = customtkinter.CTkLabel(
            footer_frame,
            text=f"Session: {datetime.now().strftime('%Y-%m-%d %H:%M')}",
            font=customtkinter.CTkFont(size=10),
            text_color=COLORS["text_muted"]
        )
        self.timestamp_label.grid(row=0, column=2, padx=15, pady=10, sticky="e")
    
    def create_wallet_threaded(self):
        """Create wallet in a separate thread to prevent UI freezing"""
        thread = threading.Thread(target=self.create_wallet, daemon=True)
        thread.start()
    
    def create_wallet(self):
        """Create a new Bitcoin wallet with modern security practices"""
        # Clear previous results
        self.clear_frame(self.create_results_frame)
        
        # Validate inputs
        wallet_name = self.name_entry.get().strip()
        password = self.password_entry.get()
        confirm_password = self.confirm_entry.get()
        
        # Update progress
        self.progress_bar.set(0.1)
        
        if not self.validate_create_inputs(wallet_name, password, confirm_password):
            self.progress_bar.set(0)
            return
        
        try:
            self.progress_bar.set(0.3)
            self.update_status("🔐 Generating secure keys...")
            
            # Generate Bitcoin private key using `bit` library
            private_key = PrivateKey()
            address = private_key.address  # SegWit address
            wif = private_key.to_wif()
            
            self.progress_bar.set(0.5)
            self.update_status("🔒 Encrypting wallet...")
            
            # Encrypt the private key with modern AES-GCM
            encrypted_data = self.encrypt_private_key(wif, password)
            
            self.progress_bar.set(0.7)
            
            # Prepare wallet data
            wallet_data = {
                "wallet_name": wallet_name,
                "address": address,
                "encrypted_wif": encrypted_data["encrypted_wif"],
                "salt": encrypted_data["salt"],
                "nonce": encrypted_data["nonce"],
                "tag": encrypted_data["tag"],
                "created": datetime.now().isoformat(),
                "encryption": "AES-256-GCM",
                "kdf": f"PBKDF2-SHA256-{PBKDF2_ITERATIONS}"
            }
            
            # Save wallet file
            filepath = os.path.join(WALLET_DIR, f"{wallet_name}.json")
            with open(filepath, 'w') as f:
                json.dump(wallet_data, f, indent=2)
            
            # Add to database
            self.add_wallet_to_db(wallet_name, address, filepath)
            
            self.progress_bar.set(1.0)
            self.update_status("✅ Wallet created successfully!")
            
            # Display results
            self.display_wallet_success(address, wif, self.create_results_frame)
            
            # Clear sensitive inputs
            self.password_entry.delete(0, 'end')
            self.confirm_entry.delete(0, 'end')
            
            # Refresh wallet list
            self.refresh_wallet_list()
            
        except Exception as e:
            self.progress_bar.set(0)
            self.show_error(f"Failed to create wallet: {str(e)}", self.create_results_frame)
            self.update_status("❌ Wallet creation failed")
    
    def load_wallet(self):
        """Load an existing wallet"""
        self.clear_frame(self.load_results_frame)
        
        wallet_name = self.wallet_selector.get()
        password = self.load_password_entry.get()
        
        if not wallet_name or "No wallets" in wallet_name or not password:
            self.show_error("Please select a wallet and enter password", self.load_results_frame)
            return
        
        try:
            self.update_status("🔓 Decrypting wallet...")
            
            # Load wallet file
            filepath = os.path.join(WALLET_DIR, f"{wallet_name}.json")
            with open(filepath, 'r') as f:
                wallet_data = json.load(f)
            
            # Decrypt private key
            wif = self.decrypt_private_key(wallet_data, password)
            
            # Verify the private key
            private_key = PrivateKey(wif)
            address = private_key.address
            
            # Verify address matches
            if address != wallet_data["address"]:
                raise ValueError("Address verification failed")
            
            # Update database access statistics
            self.update_wallet_access(wallet_name)
            
            self.update_status("✅ Wallet unlocked!")
            self.current_wallet = {"address": address, "wif": wif, "name": wallet_name}
            
            # Display wallet info
            self.display_wallet_info(address, wif, self.load_results_frame, show_private_key=True)
            
            # Clear password
            self.load_password_entry.delete(0, 'end')
            
        except FileNotFoundError:
            self.show_error("Wallet file not found", self.load_results_frame)
        except ValueError as e:
            if "incorrect password" in str(e).lower():
                self.show_error("Incorrect password", self.load_results_frame)
            else:
                self.show_error(f"Decryption failed: {str(e)}", self.load_results_frame)
        except Exception as e:
            self.show_error(f"Failed to load wallet: {str(e)}", self.load_results_frame)
        finally:
            self.update_status("🔒 Secure")
    
    def encrypt_private_key(self, wif, password):
        """Encrypt private key using AES-256-GCM with PBKDF2"""
        # Generate random salt and nonce
        salt = get_random_bytes(32)
        nonce = get_random_bytes(12)  # 96-bit nonce for GCM
        
        # Derive key using PBKDF2 with high iteration count
        key = PBKDF2(password, salt, 32, count=PBKDF2_ITERATIONS, hmac_hash_module=SHA256)
        
        # Encrypt using AES-GCM
        cipher = AES.new(key, AES.MODE_GCM, nonce=nonce)
        ciphertext, tag = cipher.encrypt_and_digest(wif.encode('utf-8'))
        
        return {
            "encrypted_wif": base64.b64encode(ciphertext).decode('utf-8'),
            "salt": base64.b64encode(salt).decode('utf-8'),
            "nonce": base64.b64encode(nonce).decode('utf-8'),
            "tag": base64.b64encode(tag).decode('utf-8')
        }
    
    def decrypt_private_key(self, wallet_data, password):
        """Decrypt private key using AES-256-GCM"""
        try:
            # Decode stored data
            encrypted_wif = base64.b64decode(wallet_data["encrypted_wif"])
            salt = base64.b64decode(wallet_data["salt"])
            nonce = base64.b64decode(wallet_data["nonce"])
            tag = base64.b64decode(wallet_data["tag"])
            
            # Derive key
            key = PBKDF2(password, salt, 32, count=PBKDF2_ITERATIONS, hmac_hash_module=SHA256)
            
            # Decrypt
            cipher = AES.new(key, AES.MODE_GCM, nonce=nonce)
            wif = cipher.decrypt_and_verify(encrypted_wif, tag)
            
            return wif.decode('utf-8')
            
        except Exception as e:
            raise ValueError("Incorrect password or corrupted wallet file")
    
    def validate_create_inputs(self, wallet_name, password, confirm_password):
        """Validate wallet creation inputs"""
        if not wallet_name:
            self.show_error("Wallet name is required", self.create_results_frame)
            return False
        
        if not password:
            self.show_error("Password is required", self.create_results_frame)
            return False
        
        if len(password) < 12:
            self.show_error("Password must be at least 12 characters", self.create_results_frame)
            return False
        
        if password != confirm_password:
            self.show_error("Passwords do not match", self.create_results_frame)
            return False
        
        # Check if wallet already exists
        filepath = os.path.join(WALLET_DIR, f"{wallet_name}.json")
        if os.path.exists(filepath):
            self.show_error(f"Wallet '{wallet_name}' already exists", self.create_results_frame)
            return False
        
        return True
    
    def copy_to_clipboard(self, text, button=None):
        """Copy text to clipboard and provide visual feedback"""
        try:
            self.clipboard_clear()
            self.clipboard_append(text)
            self.update()  # Ensure clipboard is updated
            
            # Provide visual feedback
            if button:
                original_text = button.cget("text")
                button.configure(text="✓ Copied!", fg_color=COLORS["success"])
                # Reset button after 2 seconds
                self.after(2000, lambda: button.configure(text=original_text, fg_color=COLORS["accent_primary"]))
        except Exception as e:
            print(f"Failed to copy to clipboard: {e}")
    
    def display_wallet_success(self, address, wif, parent_frame):
        """Display successful wallet creation"""
        # Success message
        success_label = customtkinter.CTkLabel(
            parent_frame,
            text="🎉 Wallet Created Successfully!",
            font=customtkinter.CTkFont(size=16, weight="bold"),
            text_color=COLORS["success"]
        )
        success_label.grid(row=0, column=0, pady=(10, 20), sticky="w")
        
        # For wallet creation, show simple address QR code
        self.display_wallet_info_simple(address, parent_frame)
    
    def display_wallet_info_simple(self, address, parent_frame):
        """Display simple wallet info for wallet creation (address + QR only)"""
        # Address section
        addr_label = customtkinter.CTkLabel(
            parent_frame,
            text="Bitcoin Address (SegWit)",
            font=customtkinter.CTkFont(size=14, weight="bold"),
            text_color=COLORS["text_secondary"]
        )
        addr_label.grid(row=1, column=0, pady=(10, 5), sticky="w")
        
        # Address entry
        addr_entry = customtkinter.CTkEntry(
            parent_frame,
            height=40,
            font=customtkinter.CTkFont(size=11),
            fg_color=COLORS["bg_primary"],
            border_color=COLORS["success"],
            text_color=COLORS["text_primary"]
        )
        addr_entry.grid(row=2, column=0, pady=(0, 10), sticky="ew")
        addr_entry.insert(0, address)
        addr_entry.configure(state="readonly")
        
        # Simple QR Code for address
        qr_image = self.generate_qr_code(address, size=150)
        qr_label = customtkinter.CTkLabel(parent_frame, text="", image=qr_image)
        qr_label.grid(row=3, column=0, pady=10)
        
        # Info label
        info_label = customtkinter.CTkLabel(
            parent_frame,
            text="📧 QR code contains your address for receiving payments",
            font=customtkinter.CTkFont(size=11),
            text_color=COLORS["text_muted"]
        )
        info_label.grid(row=4, column=0, pady=(0, 10))
    
    def display_wallet_info(self, address, wif, parent_frame, show_private_key=False):
        """Display wallet information with QR code and copy buttons"""
        # Address section
        addr_label = customtkinter.CTkLabel(
            parent_frame,
            text="Bitcoin Address (SegWit)",
            font=customtkinter.CTkFont(size=14, weight="bold"),
            text_color=COLORS["text_secondary"]
        )
        addr_label.grid(row=1, column=0, pady=(10, 5), sticky="w")
        
        # Address frame with entry and copy button
        addr_frame = customtkinter.CTkFrame(parent_frame, fg_color="transparent")
        addr_frame.grid(row=2, column=0, pady=(0, 10), sticky="ew")
        addr_frame.grid_columnconfigure(0, weight=1)
        
        addr_entry = customtkinter.CTkEntry(
            addr_frame,
            height=40,
            font=customtkinter.CTkFont(size=11),
            fg_color=COLORS["bg_primary"],
            border_color=COLORS["success"],
            text_color=COLORS["text_primary"]
        )
        addr_entry.grid(row=0, column=0, sticky="ew", padx=(0, 10))
        addr_entry.insert(0, address)
        addr_entry.configure(state="readonly")
        
        addr_copy_btn = customtkinter.CTkButton(
            addr_frame,
            text="📋 Copy",
            command=lambda: self.copy_to_clipboard(address, addr_copy_btn),
            width=80,
            height=40,
            font=customtkinter.CTkFont(size=12),
            fg_color=COLORS["accent_primary"],
            hover_color=COLORS["accent_secondary"]
        )
        addr_copy_btn.grid(row=0, column=1)
        
        # QR Code section with options
        qr_label = customtkinter.CTkLabel(
            parent_frame,
            text="QR Code Options",
            font=customtkinter.CTkFont(size=14, weight="bold"),
            text_color=COLORS["text_secondary"]
        )
        qr_label.grid(row=3, column=0, pady=(10, 5), sticky="w")
        
        # QR code type selector
        qr_type_frame = customtkinter.CTkFrame(parent_frame, fg_color="transparent")
        qr_type_frame.grid(row=4, column=0, pady=(0, 10), sticky="ew")
        qr_type_frame.grid_columnconfigure(1, weight=1)
        
        qr_type_label = customtkinter.CTkLabel(
            qr_type_frame,
            text="QR Type:",
            font=customtkinter.CTkFont(size=12),
            text_color=COLORS["text_muted"]
        )
        qr_type_label.grid(row=0, column=0, padx=(0, 10), sticky="w")
        
        qr_options = [
            "💰 Blockchain Explorer (View Balance)",
            "📧 Address Only (Receive Payments)", 
            "🔗 Mempool.space (Advanced Stats)",
            "📊 Blockstream Explorer"
        ]
        
        self.qr_type_selector = customtkinter.CTkOptionMenu(
            qr_type_frame,
            values=qr_options,
            command=lambda choice: self.update_qr_code(address, choice, qr_display_label),
            font=customtkinter.CTkFont(size=11),
            fg_color=COLORS["bg_tertiary"],
            button_color=COLORS["accent_primary"],
            button_hover_color=COLORS["accent_secondary"]
        )
        self.qr_type_selector.grid(row=0, column=1, sticky="ew")
        self.qr_type_selector.set(qr_options[0])  # Default to blockchain explorer
        
        # QR Code display
        qr_data = self.get_qr_data(address, qr_options[0])
        qr_image = self.generate_qr_code(qr_data, size=180)
        qr_display_label = customtkinter.CTkLabel(parent_frame, text="", image=qr_image)
        qr_display_label.grid(row=5, column=0, pady=(5, 10))
        
        # QR Code info
        qr_info_label = customtkinter.CTkLabel(
            parent_frame,
            text=f"🔗 Scan to view balance and transactions",
            font=customtkinter.CTkFont(size=11),
            text_color=COLORS["text_muted"],
            wraplength=400
        )
        qr_info_label.grid(row=6, column=0, pady=(0, 10))
        
        if show_private_key:
            # Private key section (for loaded wallets)
            pk_label = customtkinter.CTkLabel(
                parent_frame,
                text="Private Key (WIF) - KEEP SECRET!",
                font=customtkinter.CTkFont(size=14, weight="bold"),
                text_color=COLORS["error"]
            )
            pk_label.grid(row=7, column=0, pady=(15, 5), sticky="w")
            
            # Private key frame with entry and copy button
            pk_frame = customtkinter.CTkFrame(parent_frame, fg_color="transparent")
            pk_frame.grid(row=8, column=0, pady=(0, 10), sticky="ew")
            pk_frame.grid_columnconfigure(0, weight=1)
            
            pk_entry = customtkinter.CTkEntry(
                pk_frame,
                height=40,
                font=customtkinter.CTkFont(size=11),
                fg_color=COLORS["bg_primary"],
                border_color=COLORS["error"],
                text_color=COLORS["text_primary"]
            )
            pk_entry.grid(row=0, column=0, sticky="ew", padx=(0, 10))
            pk_entry.insert(0, wif)
            pk_entry.configure(state="readonly")
            
            pk_copy_btn = customtkinter.CTkButton(
                pk_frame,
                text="📋 Copy",
                command=lambda: self.copy_to_clipboard(wif, pk_copy_btn),
                width=80,
                height=40,
                font=customtkinter.CTkFont(size=12),
                fg_color=COLORS["error"],
                hover_color="#cc3333"
            )
            pk_copy_btn.grid(row=0, column=1)
    
    def get_qr_data(self, address, qr_type):
        """Get the appropriate data for QR code based on type"""
        if "Blockchain Explorer" in qr_type:
            return f"https://blockchain.info/address/{address}"
        elif "Mempool.space" in qr_type:
            return f"https://mempool.space/address/{address}"
        elif "Blockstream" in qr_type:
            return f"https://blockstream.info/address/{address}"
        else:  # Address only
            return address
    
    def update_qr_code(self, address, qr_type, qr_label):
        """Update QR code when type selection changes"""
        try:
            qr_data = self.get_qr_data(address, qr_type)
            new_qr_image = self.generate_qr_code(qr_data, size=180)
            qr_label.configure(image=new_qr_image)
            
            # Update info text based on QR type
            info_texts = {
                "Blockchain Explorer": "🔗 Scan to view balance and transactions on Blockchain.info",
                "Address Only": "📧 Scan to get address for receiving payments",
                "Mempool.space": "🔗 Scan to view advanced stats on Mempool.space",
                "Blockstream": "🔗 Scan to view wallet details on Blockstream Explorer"
            }
            
            # Find the matching info text
            info_text = "🔗 Scan QR code"
            for key, text in info_texts.items():
                if key in qr_type:
                    info_text = text
                    break
            
            # Update the info label (find it in the parent frame)
            parent = qr_label.master
            for widget in parent.winfo_children():
                if isinstance(widget, customtkinter.CTkLabel) and "Scan to" in widget.cget("text"):
                    widget.configure(text=info_text)
                    break
                    
        except Exception as e:
            print(f"Error updating QR code: {e}")
    
    def generate_qr_code(self, data, size=200):
        """Generate QR code for Bitcoin address"""
        qr = qrcode.QRCode(
            version=1,
            error_correction=qrcode.constants.ERROR_CORRECT_L,
            box_size=6,
            border=4,
        )
        qr.add_data(data)
        qr.make(fit=True)
        
        # Create QR code image with modern colors
        img = qr.make_image(fill_color=COLORS["text_primary"], back_color=COLORS["bg_tertiary"])
        img = img.convert('RGB')
        img = img.resize((size, size), Image.Resampling.LANCZOS)
        
        return ImageTk.PhotoImage(img)
    
    def show_error(self, message, parent_frame):
        """Display error message"""
        error_label = customtkinter.CTkLabel(
            parent_frame,
            text=f"❌ {message}",
            font=customtkinter.CTkFont(size=14),
            text_color=COLORS["error"]
        )
        error_label.grid(row=0, column=0, pady=10, sticky="w")
    
    def clear_frame(self, frame):
        """Clear all widgets from frame"""
        for widget in frame.winfo_children():
            widget.destroy()
    
    def update_status(self, message):
        """Update status label"""
        self.status_label.configure(text=message)
        self.update()
    
    def get_wallet_list(self):
        """Get list of available wallets from database and files"""
        wallet_names = []
        
        # First try to get from database
        if self.db_conn:
            db_wallets = self.get_wallets_from_db()
            wallet_names = [row['wallet_name'] for row in db_wallets]
        
        # Fallback to file system and sync with database
        try:
            file_wallets = [f.replace(".json", "") for f in os.listdir(WALLET_DIR) if f.endswith(".json")]
            
            # Add any wallets found in files but not in database
            for wallet_name in file_wallets:
                if wallet_name not in wallet_names:
                    # Try to read wallet file to get address
                    try:
                        filepath = os.path.join(WALLET_DIR, f"{wallet_name}.json")
                        with open(filepath, 'r') as f:
                            wallet_data = json.load(f)
                        
                        # Add to database
                        if self.db_conn:
                            self.add_wallet_to_db(wallet_name, wallet_data.get("address", ""), filepath)
                        wallet_names.append(wallet_name)
                    except Exception as e:
                        print(f"Error syncing wallet {wallet_name}: {e}")
            
            return wallet_names if wallet_names else ["No wallets found"]
            
        except FileNotFoundError:
            return wallet_names if wallet_names else ["No wallets found"]
    
    def refresh_wallet_list(self):
        """Refresh the wallet dropdown list"""
        wallet_files = self.get_wallet_list()
        self.wallet_selector.configure(values=wallet_files)
        if wallet_files and "No wallets" not in wallet_files:
            self.wallet_selector.set(wallet_files[0])
        self.update_wallet_count()
    
    def update_wallet_count(self):
        """Update the wallet count display"""
        try:
            wallets = self.get_wallet_list()
            count = len([w for w in wallets if "No wallets" not in w])
            if count > 0:
                self.wallet_count_label.configure(text=f"📊 {count} wallet{'s' if count != 1 else ''} available")
            else:
                self.wallet_count_label.configure(text="📊 No wallets found")
        except:
            self.wallet_count_label.configure(text="")
    
    def on_closing(self):
        """Handle app closing - cleanup database connection"""
        try:
            self.close_database()
            self.destroy()
        except Exception as e:
            print(f"Error during app closure: {e}")
            self.destroy()


def main():
    """Main application entry point"""
    try:
        app = ModernBitcoinWallet()
        
        # Handle window closing properly
        app.protocol("WM_DELETE_WINDOW", app.on_closing)
        
        app.mainloop()
        
    except KeyboardInterrupt:
        print("\nApplication closed by user")
    except Exception as e:
        print(f"Application error: {e}")
        import traceback
        traceback.print_exc()


if __name__ == "__main__":
    main()