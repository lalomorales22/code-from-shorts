from flask import Flask, render_template_string, request, jsonify, redirect, url_for
from flask_sqlalchemy import SQLAlchemy
from datetime import datetime
import json
import requests  # ADD THIS LINE
import os

app = Flask(__name__)
app.config['SQLALCHEMY_DATABASE_URI'] = 'sqlite:///business_system.db'
app.config['SQLALCHEMY_TRACK_MODIFICATIONS'] = False
app.config['SECRET_KEY'] = 'your-secret-key-here'

# ADD THESE CONFIGURATION LINES HERE:
CLAUDE_API_KEY = 'replace-with-api-key'  # Replace with your real API key
CLAUDE_API_URL = 'https://api.anthropic.com/v1/messages'
CLAUDE_MODEL = 'claude-sonnet-4-20250514'

db = SQLAlchemy(app)

# Database Models
class Product(db.Model):
    id = db.Column(db.Integer, primary_key=True)
    name = db.Column(db.String(100), nullable=False)
    sku = db.Column(db.String(50), unique=True, nullable=False)
    price = db.Column(db.Float, nullable=False)
    cost = db.Column(db.Float, default=0)
    quantity = db.Column(db.Integer, default=0)
    category = db.Column(db.String(50))
    description = db.Column(db.Text)
    created_at = db.Column(db.DateTime, default=datetime.utcnow)
    
class Customer(db.Model):
    id = db.Column(db.Integer, primary_key=True)
    name = db.Column(db.String(100), nullable=False)
    email = db.Column(db.String(100), unique=True, nullable=False)
    phone = db.Column(db.String(20))
    address = db.Column(db.Text)
    notes = db.Column(db.Text)
    created_at = db.Column(db.DateTime, default=datetime.utcnow)
    
class Order(db.Model):
    id = db.Column(db.Integer, primary_key=True)
    customer_id = db.Column(db.Integer, db.ForeignKey('customer.id'))
    total = db.Column(db.Float, nullable=False)
    status = db.Column(db.String(20), default='pending')
    items = db.Column(db.Text)  # JSON string of order items
    created_at = db.Column(db.DateTime, default=datetime.utcnow)
    customer = db.relationship('Customer', backref='orders')
    
class Transaction(db.Model):
    id = db.Column(db.Integer, primary_key=True)
    order_id = db.Column(db.Integer, db.ForeignKey('order.id'))
    amount = db.Column(db.Float, nullable=False)
    payment_method = db.Column(db.String(50))
    status = db.Column(db.String(20), default='completed')
    created_at = db.Column(db.DateTime, default=datetime.utcnow)
    order = db.relationship('Order', backref='transactions')
    
class SocialMedia(db.Model):
    id = db.Column(db.Integer, primary_key=True)
    platform = db.Column(db.String(50), nullable=False)
    url = db.Column(db.String(200), nullable=False)
    username = db.Column(db.String(100))
    notes = db.Column(db.Text)
    created_at = db.Column(db.DateTime, default=datetime.utcnow)

# HTML Template
HTML_TEMPLATE = '''<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Business Management System</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background-color: #f8f9fa;
            color: #000;
            height: 100vh;
            display: flex;
            padding: 20px;
            gap: 20px;
        }
        
        .sidebar {
            width: 250px;
            background: white;
            border: 1px solid #e5e7eb;
            border-radius: 8px;
            padding: 20px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
        }
        
        .main-content {
            flex: 1;
            background: white;
            border: 1px solid #e5e7eb;
            border-radius: 8px;
            padding: 30px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
            overflow-y: auto;
        }
        
        .nav-item {
            display: block;
            padding: 12px 16px;
            margin-bottom: 8px;
            border: 1px solid #e5e7eb;
            border-radius: 6px;
            text-decoration: none;
            color: #000;
            transition: all 0.2s;
            cursor: pointer;
            background: white;
            font-size: 14px;
            font-weight: 500;
        }
        
        .nav-item:hover {
            background: #f3f4f6;
            border-color: #000;
        }
        
        .nav-item.active {
            background: #000;
            color: white;
            border-color: #000;
        }
        
        .header {
            margin-bottom: 30px;
        }
        
        h1 {
            font-size: 24px;
            font-weight: 700;
            margin-bottom: 8px;
        }
        
        h2 {
            font-size: 20px;
            font-weight: 600;
            margin-bottom: 20px;
        }
        
        .subtitle {
            color: #6b7280;
            font-size: 14px;
        }
        
        .table-container {
            overflow-x: auto;
            margin-bottom: 20px;
        }
        
        table {
            width: 100%;
            border-collapse: collapse;
            font-size: 14px;
        }
        
        th, td {
            text-align: left;
            padding: 12px;
            border-bottom: 1px solid #e5e7eb;
        }
        
        th {
            font-weight: 600;
            background: #f9fafb;
        }
        
        tr:hover {
            background: #f9fafb;
        }
        
        .btn {
            padding: 8px 16px;
            border: 1px solid #e5e7eb;
            border-radius: 6px;
            background: white;
            color: #000;
            cursor: pointer;
            font-size: 14px;
            font-weight: 500;
            transition: all 0.2s;
            text-decoration: none;
            display: inline-block;
            margin-right: 8px;
        }
        
        .btn:hover {
            background: #f3f4f6;
            border-color: #000;
        }
        
        .btn-primary {
            background: #000;
            color: white;
            border-color: #000;
        }
        
        .btn-primary:hover {
            background: #333;
        }
        
        .btn-danger {
            color: #dc2626;
            border-color: #dc2626;
        }
        
        .btn-danger:hover {
            background: #fee2e2;
        }
        
        .form-group {
            margin-bottom: 20px;
        }
        
        label {
            display: block;
            font-size: 14px;
            font-weight: 500;
            margin-bottom: 8px;
        }
        
        input, textarea, select {
            width: 100%;
            padding: 10px;
            border: 1px solid #e5e7eb;
            border-radius: 6px;
            font-size: 14px;
            transition: border-color 0.2s;
        }
        
        input:focus, textarea:focus, select:focus {
            outline: none;
            border-color: #000;
        }
        
        .chat-container {
            height: 400px;
            border: 1px solid #e5e7eb;
            border-radius: 8px;
            display: flex;
            flex-direction: column;
        }
        
        .chat-messages {
            flex: 1;
            padding: 20px;
            overflow-y: auto;
            background: #f9fafb;
        }
        
        .chat-input {
            border-top: 1px solid #e5e7eb;
            padding: 20px;
            display: flex;
            gap: 10px;
        }
        
        .chat-input input {
            flex: 1;
        }
        
        .message {
            margin-bottom: 16px;
            padding: 12px;
            border-radius: 8px;
            font-size: 14px;
        }
        
        .message.user {
            background: #e5e7eb;
            margin-left: 20%;
        }
        
        .message.ai {
            background: #f3f4f6;
            margin-right: 20%;
        }
        
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }
        
        .stat-card {
            padding: 20px;
            border: 1px solid #e5e7eb;
            border-radius: 8px;
            text-align: center;
        }
        
        .stat-value {
            font-size: 32px;
            font-weight: 700;
            margin-bottom: 8px;
        }
        
        .stat-label {
            color: #6b7280;
            font-size: 14px;
        }
        
        .hidden {
            display: none !important;
        }
        
        .modal {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0,0,0,0.5);
            display: flex;
            align-items: center;
            justify-content: center;
            z-index: 1000;
        }
        
        .modal-content {
            background: white;
            padding: 30px;
            border-radius: 8px;
            width: 90%;
            max-width: 500px;
            max-height: 90vh;
            overflow-y: auto;
            position: relative;
        }
        
        .close-modal {
            position: absolute;
            top: 10px;
            right: 15px;
            font-size: 28px;
            cursor: pointer;
            line-height: 1;
            color: #666;
            transition: color 0.2s;
        }
        
        .close-modal:hover {
            color: #000;
        }
        
        .logo {
            font-size: 20px;
            font-weight: 700;
            margin-bottom: 30px;
            padding-bottom: 20px;
            border-bottom: 1px solid #e5e7eb;
        }
        
        .status-danger {
            color: #dc2626;
        }
        
        .status-warning {
            color: #f59e0b;
        }
        
        .status-success {
            color: #10b981;
        }
        
        .product-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(250px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }
        
        .product-card {
            border: 1px solid #e5e7eb;
            border-radius: 8px;
            padding: 20px;
            transition: all 0.2s;
            cursor: pointer;
        }
        
        .product-card:hover {
            border-color: #000;
            box-shadow: 0 4px 6px rgba(0,0,0,0.1);
        }
        
        .product-card h3 {
            font-size: 18px;
            margin-bottom: 8px;
        }
        
        .product-price {
            font-size: 24px;
            font-weight: 700;
            margin-bottom: 8px;
        }
        
        .product-sku {
            color: #6b7280;
            font-size: 12px;
            margin-bottom: 8px;
        }
        
        .product-stock {
            font-size: 14px;
            margin-bottom: 8px;
        }
        
        .product-category {
            display: inline-block;
            padding: 4px 8px;
            background: #f3f4f6;
            border-radius: 4px;
            font-size: 12px;
            color: #4b5563;
        }
        
        .search-bar {
            display: flex;
            gap: 10px;
            margin-bottom: 20px;
        }
        
        .search-bar input {
            flex: 1;
        }
        
        .category-filters {
            display: flex;
            gap: 10px;
            margin-bottom: 20px;
            flex-wrap: wrap;
        }
        
        .category-filter {
            padding: 8px 16px;
            border: 1px solid #e5e7eb;
            border-radius: 6px;
            background: white;
            cursor: pointer;
            font-size: 14px;
            transition: all 0.2s;
        }
        
        .category-filter:hover {
            background: #f3f4f6;
        }
        
        .category-filter.active {
            background: #000;
            color: white;
            border-color: #000;
        }
        
        .recent-list {
            list-style: none;
            padding: 0;
        }
        
        .recent-item {
            padding: 12px 0;
            border-bottom: 1px solid #e5e7eb;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .recent-item:last-child {
            border-bottom: none;
        }
        
        .activity-icon {
            display: inline-block;
            width: 8px;
            height: 8px;
            background: #10b981;
            border-radius: 50%;
            margin-right: 8px;
        }
    </style>
</head>
<body>
    <div class="sidebar">
        <div class="logo">Business Manager</div>
        <a class="nav-item active" onclick="showPage('dashboard')">Dashboard</a>
        <a class="nav-item" onclick="showPage('products')">Products</a>
        <a class="nav-item" onclick="showPage('customers')">Customers</a>
        <a class="nav-item" onclick="showPage('orders')">Orders</a>
        <a class="nav-item" onclick="showPage('transactions')">Transactions</a>
        <a class="nav-item" onclick="showPage('inventory')">Inventory</a>
        <a class="nav-item" onclick="showPage('social')">Social Media</a>
        <a class="nav-item" onclick="showPage('ai')">AI Assistant</a>
        <a class="nav-item" onclick="showPage('database')">Database View</a>
    </div>
    
    <div class="main-content">
        <!-- Dashboard Page -->
        <div id="dashboard-page" class="page">
            <div class="header">
                <h1>Dashboard</h1>
                <p class="subtitle">Welcome to your business management system</p>
            </div>
            
            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-value" id="total-products">0</div>
                    <div class="stat-label">Total Products</div>
                </div>
                <div class="stat-card">
                    <div class="stat-value" id="total-customers">0</div>
                    <div class="stat-label">Total Customers</div>
                </div>
                <div class="stat-card">
                    <div class="stat-value" id="total-orders">0</div>
                    <div class="stat-label">Total Orders</div>
                </div>
                <div class="stat-card">
                    <div class="stat-value" id="total-revenue">$0</div>
                    <div class="stat-label">Total Revenue</div>
                </div>
            </div>
            
            <div style="display: grid; grid-template-columns: 2fr 1fr; gap: 30px; margin-top: 30px;">
                <div>
                    <h2>Featured Products</h2>
                    <div class="search-bar">
                        <input type="text" id="product-search" placeholder="Search products..." onkeyup="filterProducts()">
                        <select id="category-filter" onchange="filterProducts()">
                            <option value="">All Categories</option>
                        </select>
                    </div>
                    <div id="featured-products" class="product-grid"></div>
                </div>
                
                <div>
                    <h2>Recent Activity</h2>
                    <ul id="recent-activity" class="recent-list"></ul>
                    
                    <h2 style="margin-top: 30px;">Low Stock Alert</h2>
                    <ul id="low-stock" class="recent-list"></ul>
                </div>
            </div>
        </div>
        
        <!-- Products Page -->
        <div id="products-page" class="page hidden">
            <div class="header">
                <h1>Products</h1>
                <p class="subtitle">Manage your product catalog</p>
            </div>
            
            <div style="display: flex; gap: 10px; margin-bottom: 20px;">
                <button class="btn btn-primary" onclick="showAddProductModal()">Add Product</button>
                <input type="text" id="products-search" placeholder="Search products..." onkeyup="loadProducts()" style="flex: 1;">
                <select id="products-category-filter" onchange="loadProducts()">
                    <option value="">All Categories</option>
                </select>
            </div>
            
            <div class="table-container">
                <table id="products-table">
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Name</th>
                            <th>SKU</th>
                            <th>Price</th>
                            <th>Cost</th>
                            <th>Quantity</th>
                            <th>Category</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody></tbody>
                </table>
            </div>
        </div>
        
        <!-- Customers Page -->
        <div id="customers-page" class="page hidden">
            <div class="header">
                <h1>Customers</h1>
                <p class="subtitle">Manage your customer relationships</p>
            </div>
            
            <button class="btn btn-primary" onclick="showAddCustomerModal()">Add Customer</button>
            
            <div class="table-container">
                <table id="customers-table">
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Name</th>
                            <th>Email</th>
                            <th>Phone</th>
                            <th>Created</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody></tbody>
                </table>
            </div>
        </div>
        
        <!-- Orders Page -->
        <div id="orders-page" class="page hidden">
            <div class="header">
                <h1>Orders</h1>
                <p class="subtitle">Track and manage orders</p>
            </div>
            
            <button class="btn btn-primary" onclick="showAddOrderModal()">Create Order</button>
            
            <div class="table-container">
                <table id="orders-table">
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Customer</th>
                            <th>Total</th>
                            <th>Status</th>
                            <th>Created</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody></tbody>
                </table>
            </div>
        </div>
        
        <!-- Transactions Page -->
        <div id="transactions-page" class="page hidden">
            <div class="header">
                <h1>Transactions</h1>
                <p class="subtitle">View payment transactions</p>
            </div>
            
            <div class="table-container">
                <table id="transactions-table">
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Order ID</th>
                            <th>Amount</th>
                            <th>Method</th>
                            <th>Status</th>
                            <th>Date</th>
                        </tr>
                    </thead>
                    <tbody></tbody>
                </table>
            </div>
        </div>
        
        <!-- Inventory Page -->
        <div id="inventory-page" class="page hidden">
            <div class="header">
                <h1>Inventory</h1>
                <p class="subtitle">Monitor stock levels</p>
            </div>
            
            <div class="table-container">
                <table id="inventory-table">
                    <thead>
                        <tr>
                            <th>Product</th>
                            <th>SKU</th>
                            <th>Current Stock</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody></tbody>
                </table>
            </div>
        </div>
        
        <!-- Social Media Page -->
        <div id="social-page" class="page hidden">
            <div class="header">
                <h1>Social Media</h1>
                <p class="subtitle">Manage your social media presence</p>
            </div>
            
            <button class="btn btn-primary" onclick="showAddSocialModal()">Add Social Link</button>
            
            <div class="table-container">
                <table id="social-table">
                    <thead>
                        <tr>
                            <th>Platform</th>
                            <th>Username</th>
                            <th>URL</th>
                            <th>Notes</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody></tbody>
                </table>
            </div>
        </div>
        
        <!-- AI Assistant Page -->
        <div id="ai-page" class="page hidden">
            <div class="header">
                <h1>AI Assistant</h1>
                <p class="subtitle">Chat with your business AI assistant</p>
            </div>
            
            <div class="chat-container">
                <div class="chat-messages" id="chat-messages">
                    <div class="message ai">
                        Hello! I'm your business AI assistant. I can help you manage products, customers, orders, and more. What can I help you with today?
                    </div>
                </div>
                <div class="chat-input">
                    <input type="text" id="chat-input" placeholder="Ask me anything about your business..." onkeypress="if(event.key==='Enter') sendMessage()">
                    <button class="btn btn-primary" onclick="sendMessage()">Send</button>
                </div>
            </div>
        </div>
        
        <!-- Database View Page -->
        <div id="database-page" class="page hidden">
            <div class="header">
                <h1>Database View</h1>
                <p class="subtitle">Direct database management</p>
            </div>
            
            <div class="form-group">
                <label>Select Table</label>
                <select id="table-select" onchange="loadTableData()">
                    <option value="product">Products</option>
                    <option value="customer">Customers</option>
                    <option value="order">Orders</option>
                    <option value="transaction">Transactions</option>
                    <option value="social_media">Social Media</option>
                </select>
            </div>
            
            <div id="db-table-container"></div>
        </div>
    </div>
    
    <!-- Modals -->
    <div id="modal" class="modal hidden" onclick="if(event.target === this) closeModal()">
        <div class="modal-content">
            <span class="close-modal" onclick="closeModal()">&times;</span>
            <div id="modal-body"></div>
        </div>
    </div>
    
    <script>
        // API Configuration
        
        // Page Navigation
        function showPage(page) {
            document.querySelectorAll('.page').forEach(p => p.classList.add('hidden'));
            document.getElementById(page + '-page').classList.remove('hidden');
            
            document.querySelectorAll('.nav-item').forEach(item => item.classList.remove('active'));
            event.target.classList.add('active');
            
            // Load data for the page
            switch(page) {
                case 'dashboard':
                    loadDashboard();
                    break;
                case 'products':
                    loadProducts();
                    break;
                case 'customers':
                    loadCustomers();
                    break;
                case 'orders':
                    loadOrders();
                    break;
                case 'transactions':
                    loadTransactions();
                    break;
                case 'inventory':
                    loadInventory();
                    break;
                case 'social':
                    loadSocialMedia();
                    break;
                case 'database':
                    loadTableData();
                    break;
            }
        }
        
        // Modal Functions
        function showModal(content) {
            document.getElementById('modal-body').innerHTML = content;
            document.getElementById('modal').classList.remove('hidden');
        }
        
        function closeModal() {
            document.getElementById('modal').classList.add('hidden');
        }
        
        // Product Functions
        function showAddProductModal() {
            const content = `
                <h2>Add Product</h2>
                <form onsubmit="addProduct(event)">
                    <div class="form-group">
                        <label>Product Name</label>
                        <input type="text" name="name" required>
                    </div>
                    <div class="form-group">
                        <label>SKU</label>
                        <input type="text" name="sku" required>
                    </div>
                    <div class="form-group">
                        <label>Price</label>
                        <input type="number" name="price" step="0.01" required>
                    </div>
                    <div class="form-group">
                        <label>Cost</label>
                        <input type="number" name="cost" step="0.01">
                    </div>
                    <div class="form-group">
                        <label>Quantity</label>
                        <input type="number" name="quantity" value="0">
                    </div>
                    <div class="form-group">
                        <label>Category</label>
                        <input type="text" name="category">
                    </div>
                    <div class="form-group">
                        <label>Description</label>
                        <textarea name="description" rows="3"></textarea>
                    </div>
                    <button type="submit" class="btn btn-primary">Add Product</button>
                    <button type="button" class="btn" onclick="closeModal()">Cancel</button>
                </form>
            `;
            showModal(content);
        }
        
        async function addProduct(event) {
            event.preventDefault();
            const formData = new FormData(event.target);
            const data = Object.fromEntries(formData);
            
            const response = await fetch('/api/products', {
                method: 'POST',
                headers: {'Content-Type': 'application/json'},
                body: JSON.stringify(data)
            });
            
            if (response.ok) {
                closeModal();
                loadProducts();
            }
        }
        
        async function loadProducts() {
            const response = await fetch('/api/products');
            const products = await response.json();
            
            // Get search and filter values
            const searchTerm = (document.getElementById('products-search')?.value || '').toLowerCase();
            const categoryFilter = document.getElementById('products-category-filter')?.value || '';
            
            // Update category filter options
            const categories = [...new Set(products.map(p => p.category).filter(c => c))];
            const categorySelect = document.getElementById('products-category-filter');
            if (categorySelect && categorySelect.options.length <= 1) {
                categorySelect.innerHTML = '<option value="">All Categories</option>' + 
                    categories.map(c => `<option value="${c}">${c}</option>`).join('');
            }
            
            // Filter products
            let filtered = products;
            if (searchTerm) {
                filtered = filtered.filter(p => 
                    p.name.toLowerCase().includes(searchTerm) || 
                    p.sku.toLowerCase().includes(searchTerm) ||
                    (p.description && p.description.toLowerCase().includes(searchTerm))
                );
            }
            if (categoryFilter) {
                filtered = filtered.filter(p => p.category === categoryFilter);
            }
            
            const tbody = document.querySelector('#products-table tbody');
            tbody.innerHTML = filtered.map(p => `
                <tr>
                    <td>${p.id}</td>
                    <td>${p.name}</td>
                    <td>${p.sku}</td>
                    <td>$${p.price.toFixed(2)}</td>
                    <td>$${p.cost.toFixed(2)}</td>
                    <td>${p.quantity}</td>
                    <td>${p.category || '-'}</td>
                    <td>
                        <button class="btn" onclick="editProduct(${p.id})">Edit</button>
                        <button class="btn btn-danger" onclick="deleteProduct(${p.id})">Delete</button>
                    </td>
                </tr>
            `).join('');
        }
        
        async function deleteProduct(id) {
            if (confirm('Are you sure you want to delete this product?')) {
                await fetch(`/api/products/${id}`, {method: 'DELETE'});
                loadProducts();
            }
        }
        
        async function editProduct(id) {
            const response = await fetch(`/api/products/${id}`);
            const product = await response.json();
            
            const content = `
                <h2>Edit Product</h2>
                <form onsubmit="updateProduct(event, ${id})">
                    <div class="form-group">
                        <label>Product Name</label>
                        <input type="text" name="name" value="${product.name}" required>
                    </div>
                    <div class="form-group">
                        <label>SKU</label>
                        <input type="text" name="sku" value="${product.sku}" required>
                    </div>
                    <div class="form-group">
                        <label>Price</label>
                        <input type="number" name="price" step="0.01" value="${product.price}" required>
                    </div>
                    <div class="form-group">
                        <label>Cost</label>
                        <input type="number" name="cost" step="0.01" value="${product.cost}">
                    </div>
                    <div class="form-group">
                        <label>Quantity</label>
                        <input type="number" name="quantity" value="${product.quantity}">
                    </div>
                    <div class="form-group">
                        <label>Category</label>
                        <input type="text" name="category" value="${product.category || ''}">
                    </div>
                    <div class="form-group">
                        <label>Description</label>
                        <textarea name="description" rows="3">${product.description || ''}</textarea>
                    </div>
                    <button type="submit" class="btn btn-primary">Update Product</button>
                    <button type="button" class="btn" onclick="closeModal()">Cancel</button>
                </form>
            `;
            showModal(content);
        }
        
        async function updateProduct(event, id) {
            event.preventDefault();
            const formData = new FormData(event.target);
            const data = Object.fromEntries(formData);
            
            await fetch(`/api/products/${id}`, {
                method: 'PUT',
                headers: {'Content-Type': 'application/json'},
                body: JSON.stringify(data)
            });
            
            closeModal();
            loadProducts();
        }
        
        // Customer Functions
        function showAddCustomerModal() {
            const content = `
                <h2>Add Customer</h2>
                <form onsubmit="addCustomer(event)">
                    <div class="form-group">
                        <label>Name</label>
                        <input type="text" name="name" required>
                    </div>
                    <div class="form-group">
                        <label>Email</label>
                        <input type="email" name="email" required>
                    </div>
                    <div class="form-group">
                        <label>Phone</label>
                        <input type="tel" name="phone">
                    </div>
                    <div class="form-group">
                        <label>Address</label>
                        <textarea name="address" rows="3"></textarea>
                    </div>
                    <div class="form-group">
                        <label>Notes</label>
                        <textarea name="notes" rows="3"></textarea>
                    </div>
                    <button type="submit" class="btn btn-primary">Add Customer</button>
                    <button type="button" class="btn" onclick="closeModal()">Cancel</button>
                </form>
            `;
            showModal(content);
        }
        
        async function addCustomer(event) {
            event.preventDefault();
            const formData = new FormData(event.target);
            const data = Object.fromEntries(formData);
            
            const response = await fetch('/api/customers', {
                method: 'POST',
                headers: {'Content-Type': 'application/json'},
                body: JSON.stringify(data)
            });
            
            if (response.ok) {
                closeModal();
                loadCustomers();
            }
        }
        
        async function loadCustomers() {
            const response = await fetch('/api/customers');
            const customers = await response.json();
            
            const tbody = document.querySelector('#customers-table tbody');
            tbody.innerHTML = customers.map(c => `
                <tr>
                    <td>${c.id}</td>
                    <td>${c.name}</td>
                    <td>${c.email}</td>
                    <td>${c.phone || '-'}</td>
                    <td>${new Date(c.created_at).toLocaleDateString()}</td>
                    <td>
                        <button class="btn" onclick="editCustomer(${c.id})">Edit</button>
                        <button class="btn btn-danger" onclick="deleteCustomer(${c.id})">Delete</button>
                    </td>
                </tr>
            `).join('');
        }
        
        async function deleteCustomer(id) {
            if (confirm('Are you sure you want to delete this customer?')) {
                await fetch(`/api/customers/${id}`, {method: 'DELETE'});
                loadCustomers();
            }
        }
        
        async function editCustomer(id) {
            const response = await fetch(`/api/customers/${id}`);
            const customer = await response.json();
            
            const content = `
                <h2>Edit Customer</h2>
                <form onsubmit="updateCustomer(event, ${id})">
                    <div class="form-group">
                        <label>Name</label>
                        <input type="text" name="name" value="${customer.name}" required>
                    </div>
                    <div class="form-group">
                        <label>Email</label>
                        <input type="email" name="email" value="${customer.email}" required>
                    </div>
                    <div class="form-group">
                        <label>Phone</label>
                        <input type="tel" name="phone" value="${customer.phone || ''}">
                    </div>
                    <div class="form-group">
                        <label>Address</label>
                        <textarea name="address" rows="3">${customer.address || ''}</textarea>
                    </div>
                    <div class="form-group">
                        <label>Notes</label>
                        <textarea name="notes" rows="3">${customer.notes || ''}</textarea>
                    </div>
                    <button type="submit" class="btn btn-primary">Update Customer</button>
                    <button type="button" class="btn" onclick="closeModal()">Cancel</button>
                </form>
            `;
            showModal(content);
        }
        
        async function updateCustomer(event, id) {
            event.preventDefault();
            const formData = new FormData(event.target);
            const data = Object.fromEntries(formData);
            
            await fetch(`/api/customers/${id}`, {
                method: 'PUT',
                headers: {'Content-Type': 'application/json'},
                body: JSON.stringify(data)
            });
            
            closeModal();
            loadCustomers();
        }
        
        // Order Functions
        async function showAddOrderModal() {
            const customersResponse = await fetch('/api/customers');
            const customers = await customersResponse.json();
            
            const productsResponse = await fetch('/api/products');
            const products = await productsResponse.json();
            
            window.availableProducts = products; // Store for later use
            
            const content = `
                <h2>Create Order</h2>
                <form onsubmit="createOrder(event)">
                    <div class="form-group">
                        <label>Customer</label>
                        <select name="customer_id" required>
                            <option value="">Select Customer</option>
                            ${customers.map(c => `<option value="${c.id}">${c.name}</option>`).join('')}
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Products</label>
                        <div id="order-items">
                            <div class="order-item" style="display: flex; gap: 10px; margin-bottom: 10px;">
                                <select name="product_id[]" style="flex: 1;" onchange="updateOrderTotal()" required>
                                    <option value="">Select Product</option>
                                    ${products.map(p => `<option value="${p.id}" data-price="${p.price}">${p.name} - $${p.price}</option>`).join('')}
                                </select>
                                <input type="number" name="quantity[]" placeholder="Qty" value="1" min="1" style="width: 80px;" onchange="updateOrderTotal()" required>
                            </div>
                        </div>
                        <button type="button" class="btn" onclick="addOrderItem()">Add Item</button>
                    </div>
                    <div class="form-group">
                        <label>Total: $<span id="order-total">0.00</span></label>
                    </div>
                    <button type="submit" class="btn btn-primary">Create Order</button>
                    <button type="button" class="btn" onclick="closeModal()">Cancel</button>
                </form>
            `;
            showModal(content);
        }
        
        function addOrderItem() {
            const products = window.availableProducts || [];
            const orderItems = document.getElementById('order-items');
            const newItem = document.createElement('div');
            newItem.className = 'order-item';
            newItem.style.cssText = 'display: flex; gap: 10px; margin-bottom: 10px;';
            newItem.innerHTML = `
                <select name="product_id[]" style="flex: 1;" onchange="updateOrderTotal()" required>
                    <option value="">Select Product</option>
                    ${products.map(p => `<option value="${p.id}" data-price="${p.price}">${p.name} - $${p.price}</option>`).join('')}
                </select>
                <input type="number" name="quantity[]" placeholder="Qty" value="1" min="1" style="width: 80px;" onchange="updateOrderTotal()" required>
                <button type="button" class="btn btn-danger" onclick="this.parentElement.remove(); updateOrderTotal()">Remove</button>
            `;
            orderItems.appendChild(newItem);
        }
        
        function updateOrderTotal() {
            const productSelects = document.querySelectorAll('select[name="product_id[]"]');
            const quantities = document.querySelectorAll('input[name="quantity[]"]');
            let total = 0;
            
            productSelects.forEach((select, index) => {
                if (select.value) {
                    const price = parseFloat(select.options[select.selectedIndex].getAttribute('data-price'));
                    const quantity = parseInt(quantities[index].value) || 0;
                    total += price * quantity;
                }
            });
            
            document.getElementById('order-total').textContent = total.toFixed(2);
        }
        
        async function viewOrder(id) {
            const response = await fetch(`/api/orders/${id}`);
            const order = await response.json();
            
            let itemsHtml = '';
            if (order.items) {
                const items = JSON.parse(order.items);
                itemsHtml = `
                    <table style="width: 100%; margin-top: 10px;">
                        <thead>
                            <tr>
                                <th>Product</th>
                                <th>Quantity</th>
                                <th>Price</th>
                                <th>Subtotal</th>
                            </tr>
                        </thead>
                        <tbody>
                            ${items.map(item => `
                                <tr>
                                    <td>Product #${item.product_id}</td>
                                    <td>${item.quantity}</td>
                                    <td>$${item.price.toFixed(2)}</td>
                                    <td>$${(item.price * item.quantity).toFixed(2)}</td>
                                </tr>
                            `).join('')}
                        </tbody>
                    </table>
                `;
            }
            
            const content = `
                <h2>Order Details #${order.id}</h2>
                <div class="form-group">
                    <label>Customer</label>
                    <p>${order.customer_name}</p>
                </div>
                <div class="form-group">
                    <label>Status</label>
                    <p>${order.status}</p>
                </div>
                <div class="form-group">
                    <label>Created</label>
                    <p>${new Date(order.created_at).toLocaleString()}</p>
                </div>
                <div class="form-group">
                    <label>Order Items</label>
                    ${itemsHtml}
                </div>
                <div class="form-group">
                    <label>Total</label>
                    <h3>$${order.total.toFixed(2)}</h3>
                </div>
                <button type="button" class="btn" onclick="closeModal()">Close</button>
            `;
            showModal(content);
        }
        
        async function createOrder(event) {
            event.preventDefault();
            const formData = new FormData(event.target);
            
            const orderData = {
                customer_id: formData.get('customer_id'),
                items: [],
                total: 0
            };
            
            const productIds = formData.getAll('product_id[]');
            const quantities = formData.getAll('quantity[]');
            
            for (let i = 0; i < productIds.length; i++) {
                if (productIds[i]) {
                    const productResponse = await fetch(`/api/products/${productIds[i]}`);
                    const product = await productResponse.json();
                    
                    orderData.items.push({
                        product_id: productIds[i],
                        quantity: parseInt(quantities[i]),
                        price: product.price
                    });
                    
                    orderData.total += product.price * parseInt(quantities[i]);
                }
            }
            
            const response = await fetch('/api/orders', {
                method: 'POST',
                headers: {'Content-Type': 'application/json'},
                body: JSON.stringify(orderData)
            });
            
            if (response.ok) {
                closeModal();
                loadOrders();
            }
        }
        
        async function loadOrders() {
            const response = await fetch('/api/orders');
            const orders = await response.json();
            
            const tbody = document.querySelector('#orders-table tbody');
            tbody.innerHTML = orders.map(o => `
                <tr>
                    <td>${o.id}</td>
                    <td>${o.customer_name}</td>
                    <td>$${o.total.toFixed(2)}</td>
                    <td>${o.status}</td>
                    <td>${new Date(o.created_at).toLocaleDateString()}</td>
                    <td>
                        <button class="btn" onclick="viewOrder(${o.id})">View</button>
                        <button class="btn btn-danger" onclick="deleteOrder(${o.id})">Delete</button>
                    </td>
                </tr>
            `).join('');
        }
        
        async function deleteOrder(id) {
            if (confirm('Are you sure you want to delete this order?')) {
                await fetch(`/api/orders/${id}`, {method: 'DELETE'});
                loadOrders();
            }
        }
        
        // Transaction Functions
        async function loadTransactions() {
            const response = await fetch('/api/transactions');
            const transactions = await response.json();
            
            const tbody = document.querySelector('#transactions-table tbody');
            tbody.innerHTML = transactions.map(t => `
                <tr>
                    <td>${t.id}</td>
                    <td>${t.order_id}</td>
                    <td>$${t.amount.toFixed(2)}</td>
                    <td>${t.payment_method || '-'}</td>
                    <td>${t.status}</td>
                    <td>${new Date(t.created_at).toLocaleDateString()}</td>
                </tr>
            `).join('');
        }
        
        // Inventory Functions
        async function loadInventory() {
            const response = await fetch('/api/products');
            const products = await response.json();
            
            const tbody = document.querySelector('#inventory-table tbody');
            tbody.innerHTML = products.map(p => {
                const status = p.quantity === 0 ? 'Out of Stock' : p.quantity < 10 ? 'Low Stock' : 'In Stock';
                const statusClass = p.quantity === 0 ? 'danger' : p.quantity < 10 ? 'warning' : 'success';
                
                return `
                    <tr>
                        <td>${p.name}</td>
                        <td>${p.sku}</td>
                        <td>${p.quantity}</td>
                        <td><span class="status-${statusClass}">${status}</span></td>
                        <td>
                            <button class="btn" onclick="adjustInventory(${p.id}, ${p.quantity})">Adjust</button>
                        </td>
                    </tr>
                `;
            }).join('');
        }
        
        function adjustInventory(productId, currentQty) {
            const content = `
                <h2>Adjust Inventory</h2>
                <form onsubmit="updateInventory(event, ${productId})">
                    <div class="form-group">
                        <label>Current Quantity: ${currentQty}</label>
                    </div>
                    <div class="form-group">
                        <label>New Quantity</label>
                        <input type="number" name="quantity" value="${currentQty}" min="0" required>
                    </div>
                    <button type="submit" class="btn btn-primary">Update</button>
                    <button type="button" class="btn" onclick="closeModal()">Cancel</button>
                </form>
            `;
            showModal(content);
        }
        
        async function updateInventory(event, productId) {
            event.preventDefault();
            const formData = new FormData(event.target);
            const quantity = formData.get('quantity');
            
            await fetch(`/api/products/${productId}/inventory`, {
                method: 'PATCH',
                headers: {'Content-Type': 'application/json'},
                body: JSON.stringify({quantity: parseInt(quantity)})
            });
            
            closeModal();
            loadInventory();
        }
        
        // Social Media Functions
        function showAddSocialModal() {
            const content = `
                <h2>Add Social Media Link</h2>
                <form onsubmit="addSocialMedia(event)">
                    <div class="form-group">
                        <label>Platform</label>
                        <select name="platform" required>
                            <option value="">Select Platform</option>
                            <option value="Facebook">Facebook</option>
                            <option value="Instagram">Instagram</option>
                            <option value="Twitter">Twitter</option>
                            <option value="LinkedIn">LinkedIn</option>
                            <option value="TikTok">TikTok</option>
                            <option value="YouTube">YouTube</option>
                            <option value="Other">Other</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Username</label>
                        <input type="text" name="username">
                    </div>
                    <div class="form-group">
                        <label>URL</label>
                        <input type="url" name="url" required>
                    </div>
                    <div class="form-group">
                        <label>Notes</label>
                        <textarea name="notes" rows="3"></textarea>
                    </div>
                    <button type="submit" class="btn btn-primary">Add Link</button>
                    <button type="button" class="btn" onclick="closeModal()">Cancel</button>
                </form>
            `;
            showModal(content);
        }
        
        async function addSocialMedia(event) {
            event.preventDefault();
            const formData = new FormData(event.target);
            const data = Object.fromEntries(formData);
            
            const response = await fetch('/api/social', {
                method: 'POST',
                headers: {'Content-Type': 'application/json'},
                body: JSON.stringify(data)
            });
            
            if (response.ok) {
                closeModal();
                loadSocialMedia();
            }
        }
        
        async function loadSocialMedia() {
            const response = await fetch('/api/social');
            const links = await response.json();
            
            const tbody = document.querySelector('#social-table tbody');
            tbody.innerHTML = links.map(link => `
                <tr>
                    <td>${link.platform}</td>
                    <td>${link.username || '-'}</td>
                    <td><a href="${link.url}" target="_blank">${link.url}</a></td>
                    <td>${link.notes || '-'}</td>
                    <td>
                        <button class="btn btn-danger" onclick="deleteSocialMedia(${link.id})">Delete</button>
                    </td>
                </tr>
            `).join('');
        }
        
        async function deleteSocialMedia(id) {
            if (confirm('Are you sure you want to delete this link?')) {
                await fetch(`/api/social/${id}`, {method: 'DELETE'});
                loadSocialMedia();
            }
        }
        
        // AI Chat Functions
        async function sendMessage() {
            const input = document.getElementById('chat-input');
            const message = input.value.trim();
            if (!message) return;
            
            const messagesDiv = document.getElementById('chat-messages');
            messagesDiv.innerHTML += `<div class="message user">${message}</div>`;
            input.value = '';
            
            // Show typing indicator
            messagesDiv.innerHTML += `<div class="message ai" id="typing">Thinking...</div>`;
            messagesDiv.scrollTop = messagesDiv.scrollHeight;
            
            try {
                // Get business context
                const context = await getBusinessContext();
                
                // Call your Flask endpoint instead of Claude API directly
                const response = await fetch('/api/chat', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json'
                    },
                    body: JSON.stringify({
                        message: message,
                        context: context
                    })
                });
                
                // Remove typing indicator
                document.getElementById('typing').remove();
                
                if (response.ok) {
                    const data = await response.json();
                    messagesDiv.innerHTML += `<div class="message ai">${data.response}</div>`;
                } else {
                    const errorData = await response.json();
                    messagesDiv.innerHTML += `<div class="message ai">Error: ${errorData.error}</div>`;
                }
                
                messagesDiv.scrollTop = messagesDiv.scrollHeight;
                
            } catch (error) {
                // Remove typing indicator if it exists
                const typingElement = document.getElementById('typing');
                if (typingElement) typingElement.remove();
                
                messagesDiv.innerHTML += `<div class="message ai">I'm having trouble connecting to the AI service. Please try again.</div>`;
                messagesDiv.scrollTop = messagesDiv.scrollHeight;
            }
        }
        
        async function getBusinessContext() {
            const [products, customers, orders] = await Promise.all([
                fetch('/api/products').then(r => r.json()),
                fetch('/api/customers').then(r => r.json()),
                fetch('/api/orders').then(r => r.json())
            ]);
            
            return {
                totalProducts: products.length,
                totalCustomers: customers.length,
                totalOrders: orders.length,
                products: products.slice(0, 5),
                recentOrders: orders.slice(0, 5)
            };
        }
        
        // Database View Functions
        async function loadTableData() {
            const table = document.getElementById('table-select').value;
            const response = await fetch(`/api/database/${table}`);
            const data = await response.json();
            
            const container = document.getElementById('db-table-container');
            if (data.length === 0) {
                container.innerHTML = '<p>No data in this table</p>';
                return;
            }
            
            const columns = Object.keys(data[0]);
            container.innerHTML = `
                <table>
                    <thead>
                        <tr>
                            ${columns.map(col => `<th>${col}</th>`).join('')}
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        ${data.map(row => `
                            <tr>
                                ${columns.map(col => `<td>${row[col] || '-'}</td>`).join('')}
                                <td>
                                    <button class="btn" onclick="editDbRow('${table}', ${row.id})">Edit</button>
                                    <button class="btn btn-danger" onclick="deleteDbRow('${table}', ${row.id})">Delete</button>
                                </td>
                            </tr>
                        `).join('')}
                    </tbody>
                </table>
            `;
        }
        
        async function deleteDbRow(table, id) {
            if (confirm('Are you sure you want to delete this record?')) {
                await fetch(`/api/database/${table}/${id}`, {method: 'DELETE'});
                loadTableData();
            }
        }
        
        // Dashboard Functions
        async function loadDashboard() {
            const [products, customers, orders, transactions] = await Promise.all([
                fetch('/api/products').then(r => r.json()),
                fetch('/api/customers').then(r => r.json()),
                fetch('/api/orders').then(r => r.json()),
                fetch('/api/transactions').then(r => r.json())
            ]);
            
            // Update stats
            document.getElementById('total-products').textContent = products.length;
            document.getElementById('total-customers').textContent = customers.length;
            document.getElementById('total-orders').textContent = orders.length;
            
            const revenue = transactions.reduce((sum, t) => sum + t.amount, 0);
            document.getElementById('total-revenue').textContent = '$' + revenue.toFixed(2);
            
            // Store products globally for filtering
            window.dashboardProducts = products;
            
            // Get unique categories
            const categories = [...new Set(products.map(p => p.category).filter(c => c))];
            const categoryFilter = document.getElementById('category-filter');
            categoryFilter.innerHTML = '<option value="">All Categories</option>' + 
                categories.map(c => `<option value="${c}">${c}</option>`).join('');
            
            // Display featured products
            displayProductCards(products.slice(0, 8));
            
            // Recent activity
            const recentOrders = orders.slice(-5).reverse();
            const recentCustomers = customers.slice(-3).reverse();
            
            const activityHtml = [
                ...recentOrders.map(o => ({
                    type: 'order',
                    text: `New order #${o.id} - $${o.total.toFixed(2)}`,
                    date: o.created_at
                })),
                ...recentCustomers.map(c => ({
                    type: 'customer',
                    text: `New customer: ${c.name}`,
                    date: c.created_at
                }))
            ]
            .sort((a, b) => new Date(b.date) - new Date(a.date))
            .slice(0, 8)
            .map(activity => `
                <li class="recent-item">
                    <div>
                        <span class="activity-icon"></span>
                        ${activity.text}
                    </div>
                    <span class="subtitle">${new Date(activity.date).toLocaleDateString()}</span>
                </li>
            `).join('');
            
            document.getElementById('recent-activity').innerHTML = activityHtml || '<li class="recent-item">No recent activity</li>';
            
            // Low stock alert
            const lowStockProducts = products.filter(p => p.quantity < 10);
            const lowStockHtml = lowStockProducts.map(p => `
                <li class="recent-item">
                    <div>
                        <strong>${p.name}</strong>
                        <br>
                        <span class="subtitle">SKU: ${p.sku}</span>
                    </div>
                    <span class="${p.quantity === 0 ? 'status-danger' : 'status-warning'}">
                        ${p.quantity} left
                    </span>
                </li>
            `).join('');
            
            document.getElementById('low-stock').innerHTML = lowStockHtml || '<li class="recent-item">All products well stocked</li>';
        }
        
        function displayProductCards(products) {
            const container = document.getElementById('featured-products');
            container.innerHTML = products.map(p => {
                const stockClass = p.quantity === 0 ? 'status-danger' : p.quantity < 10 ? 'status-warning' : 'status-success';
                return `
                    <div class="product-card" onclick="editProduct(${p.id})">
                        <h3>${p.name}</h3>
                        <div class="product-price">$${p.price.toFixed(2)}</div>
                        <div class="product-sku">SKU: ${p.sku}</div>
                        <div class="product-stock ${stockClass}">Stock: ${p.quantity}</div>
                        ${p.category ? `<span class="product-category">${p.category}</span>` : ''}
                    </div>
                `;
            }).join('');
        }
        
        function filterProducts() {
            const searchTerm = document.getElementById('product-search').value.toLowerCase();
            const categoryFilter = document.getElementById('category-filter').value;
            
            let filtered = window.dashboardProducts || [];
            
            if (searchTerm) {
                filtered = filtered.filter(p => 
                    p.name.toLowerCase().includes(searchTerm) || 
                    p.sku.toLowerCase().includes(searchTerm)
                );
            }
            
            if (categoryFilter) {
                filtered = filtered.filter(p => p.category === categoryFilter);
            }
            
            displayProductCards(filtered.slice(0, 8));
        }
        
        // Initialize
        document.addEventListener('DOMContentLoaded', () => {
            loadDashboard();
        });
    </script>
</body>
</html>
'''

# API Routes
@app.route('/')
def index():
    return render_template_string(HTML_TEMPLATE)

# Product APIs
@app.route('/api/products', methods=['GET', 'POST'])
def handle_products():
    if request.method == 'GET':
        products = Product.query.all()
        return jsonify([{
            'id': p.id,
            'name': p.name,
            'sku': p.sku,
            'price': p.price,
            'cost': p.cost,
            'quantity': p.quantity,
            'category': p.category,
            'description': p.description,
            'created_at': p.created_at.isoformat()
        } for p in products])
    
    elif request.method == 'POST':
        data = request.json
        product = Product(
            name=data['name'],
            sku=data['sku'],
            price=float(data['price']),
            cost=float(data.get('cost', 0)),
            quantity=int(data.get('quantity', 0)),
            category=data.get('category'),
            description=data.get('description')
        )
        db.session.add(product)
        db.session.commit()
        return jsonify({'id': product.id}), 201

@app.route('/api/products/<int:id>', methods=['GET', 'DELETE', 'PUT'])
def handle_product(id):
    product = Product.query.get_or_404(id)
    
    if request.method == 'GET':
        return jsonify({
            'id': product.id,
            'name': product.name,
            'sku': product.sku,
            'price': product.price,
            'cost': product.cost,
            'quantity': product.quantity,
            'category': product.category,
            'description': product.description
        })
    
    elif request.method == 'DELETE':
        db.session.delete(product)
        db.session.commit()
        return '', 204
    
    elif request.method == 'PUT':
        data = request.json
        product.name = data.get('name', product.name)
        product.sku = data.get('sku', product.sku)
        product.price = float(data.get('price', product.price))
        product.cost = float(data.get('cost', product.cost))
        product.quantity = int(data.get('quantity', product.quantity))
        product.category = data.get('category', product.category)
        product.description = data.get('description', product.description)
        db.session.commit()
        return '', 204

@app.route('/api/products/<int:id>/inventory', methods=['PATCH'])
def update_inventory(id):
    product = Product.query.get_or_404(id)
    data = request.json
    product.quantity = int(data['quantity'])
    db.session.commit()
    return '', 204

# Customer APIs
@app.route('/api/customers', methods=['GET', 'POST'])
def handle_customers():
    if request.method == 'GET':
        customers = Customer.query.all()
        return jsonify([{
            'id': c.id,
            'name': c.name,
            'email': c.email,
            'phone': c.phone,
            'address': c.address,
            'notes': c.notes,
            'created_at': c.created_at.isoformat()
        } for c in customers])
    
    elif request.method == 'POST':
        data = request.json
        customer = Customer(
            name=data['name'],
            email=data['email'],
            phone=data.get('phone'),
            address=data.get('address'),
            notes=data.get('notes')
        )
        db.session.add(customer)
        db.session.commit()
        return jsonify({'id': customer.id}), 201

@app.route('/api/customers/<int:id>', methods=['GET', 'DELETE', 'PUT'])
def handle_customer(id):
    customer = Customer.query.get_or_404(id)
    
    if request.method == 'GET':
        return jsonify({
            'id': customer.id,
            'name': customer.name,
            'email': customer.email,
            'phone': customer.phone,
            'address': customer.address,
            'notes': customer.notes,
            'created_at': customer.created_at.isoformat()
        })
    
    elif request.method == 'DELETE':
        db.session.delete(customer)
        db.session.commit()
        return '', 204
    
    elif request.method == 'PUT':
        data = request.json
        customer.name = data.get('name', customer.name)
        customer.email = data.get('email', customer.email)
        customer.phone = data.get('phone', customer.phone)
        customer.address = data.get('address', customer.address)
        customer.notes = data.get('notes', customer.notes)
        db.session.commit()
        return '', 204

# Order APIs
@app.route('/api/orders', methods=['GET', 'POST'])
def handle_orders():
    if request.method == 'GET':
        orders = Order.query.join(Customer).all()
        return jsonify([{
            'id': o.id,
            'customer_id': o.customer_id,
            'customer_name': o.customer.name if o.customer else 'Unknown',
            'total': o.total,
            'status': o.status,
            'items': json.loads(o.items) if o.items else [],
            'created_at': o.created_at.isoformat()
        } for o in orders])
    
    elif request.method == 'POST':
        data = request.json
        order = Order(
            customer_id=data['customer_id'],
            total=data['total'],
            items=json.dumps(data['items'])
        )
        db.session.add(order)
        db.session.commit()
        
        # Create transaction
        transaction = Transaction(
            order_id=order.id,
            amount=data['total'],
            payment_method='pending'
        )
        db.session.add(transaction)
        db.session.commit()
        
        return jsonify({'id': order.id}), 201

@app.route('/api/orders/<int:id>', methods=['GET', 'DELETE'])
def handle_order(id):
    order = Order.query.get_or_404(id)
    
    if request.method == 'GET':
        return jsonify({
            'id': order.id,
            'customer_id': order.customer_id,
            'customer_name': order.customer.name if order.customer else 'Unknown',
            'total': order.total,
            'status': order.status,
            'items': order.items,
            'created_at': order.created_at.isoformat()
        })
    
    elif request.method == 'DELETE':
        db.session.delete(order)
        db.session.commit()
        return '', 204

# Transaction APIs
@app.route('/api/transactions')
def get_transactions():
    transactions = Transaction.query.all()
    return jsonify([{
        'id': t.id,
        'order_id': t.order_id,
        'amount': t.amount,
        'payment_method': t.payment_method,
        'status': t.status,
        'created_at': t.created_at.isoformat()
    } for t in transactions])

# Social Media APIs
@app.route('/api/social', methods=['GET', 'POST'])
def handle_social():
    if request.method == 'GET':
        links = SocialMedia.query.all()
        return jsonify([{
            'id': s.id,
            'platform': s.platform,
            'url': s.url,
            'username': s.username,
            'notes': s.notes,
            'created_at': s.created_at.isoformat()
        } for s in links])
    
    elif request.method == 'POST':
        data = request.json
        social = SocialMedia(
            platform=data['platform'],
            url=data['url'],
            username=data.get('username'),
            notes=data.get('notes')
        )
        db.session.add(social)
        db.session.commit()
        return jsonify({'id': social.id}), 201

@app.route('/api/social/<int:id>', methods=['DELETE'])
def delete_social(id):
    social = SocialMedia.query.get_or_404(id)
    db.session.delete(social)
    db.session.commit()
    return '', 204

# Database View APIs
@app.route('/api/database/<table>')
def get_table_data(table):
    model_map = {
        'product': Product,
        'customer': Customer,
        'order': Order,
        'transaction': Transaction,
        'social_media': SocialMedia
    }
    
    model = model_map.get(table)
    if not model:
        return jsonify({'error': 'Invalid table'}), 400
    
    items = model.query.all()
    return jsonify([{col.name: getattr(item, col.name) for col in model.__table__.columns} for item in items])

@app.route('/api/database/<table>/<int:id>', methods=['DELETE'])
def delete_db_row(table, id):
    model_map = {
        'product': Product,
        'customer': Customer,
        'order': Order,
        'transaction': Transaction,
        'social_media': SocialMedia
    }
    
    model = model_map.get(table)
    if not model:
        return jsonify({'error': 'Invalid table'}), 400
    
    item = model.query.get_or_404(id)
    db.session.delete(item)
    db.session.commit()
    return '', 204

@app.route('/api/chat', methods=['POST'])
def chat_with_claude():
    try:
        data = request.json
        message = data.get('message', '')
        context = data.get('context', {})
        
        # Prepare the request to Claude API
        headers = {
            'Content-Type': 'application/json',
            'x-api-key': CLAUDE_API_KEY,
            'anthropic-version': '2023-06-01'
        }
        
        payload = {
            'model': CLAUDE_MODEL,
            'max_tokens': 1000,
            'messages': [
                {
                    'role': 'user',
                    'content': f'You are a helpful business assistant. Here\'s the current business data: {json.dumps(context)}. User question: {message}'
                }
            ]
        }
        
        # Make request to Claude API
        response = requests.post(CLAUDE_API_URL, headers=headers, json=payload)
        
        if response.status_code == 200:
            claude_data = response.json()
            ai_response = claude_data['content'][0]['text']
            return jsonify({'response': ai_response})
        else:
            error_text = response.text if response.text else 'Unknown error'
            return jsonify({'error': f'Claude API error: {response.status_code} - {error_text}'}), 500
            
    except Exception as e:
        return jsonify({'error': f'Server error: {str(e)}'}), 500

# Initialize database
with app.app_context():
    db.create_all()

if __name__ == '__main__':
    app.run(debug=True, port=5000)