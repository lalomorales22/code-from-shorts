<?php
// Calculus 2 for Engineers - Complete Learning Platform with Ollama AI Integration
// Single file PHP application with SQLite database

// Initialize SQLite database
function initDatabase() {
    $db = new PDO('sqlite:calculus2.db');
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    // Create tables if they don't exist
    $db->exec("CREATE TABLE IF NOT EXISTS conversations (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        user_message TEXT NOT NULL,
        ai_response TEXT NOT NULL,
        model_used TEXT NOT NULL,
        topic TEXT,
        timestamp DATETIME DEFAULT CURRENT_TIMESTAMP
    )");
    
    $db->exec("CREATE TABLE IF NOT EXISTS user_progress (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        topic TEXT NOT NULL,
        subtopic TEXT NOT NULL,
        completed BOOLEAN DEFAULT FALSE,
        timestamp DATETIME DEFAULT CURRENT_TIMESTAMP
    )");
    
    return $db;
}

// Handle AJAX requests
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (isset($input['action'])) {
        switch ($input['action']) {
            case 'get_models':
                // Get available Ollama models
                $ch = curl_init();
                curl_setopt($ch, CURLOPT_URL, 'http://localhost:11434/api/tags');
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($ch, CURLOPT_TIMEOUT, 5);
                $response = curl_exec($ch);
                $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                curl_close($ch);
                
                if ($httpCode === 200) {
                    $models = json_decode($response, true);
                    echo json_encode(['success' => true, 'models' => $models['models'] ?? []]);
                } else {
                    echo json_encode(['success' => false, 'error' => 'Ollama not accessible']);
                }
                break;
                
            case 'send_message':
                $db = initDatabase();
                $message = $input['message'];
                $model = $input['model'];
                $topic = $input['topic'] ?? 'General';
                
                // Send to Ollama
                $ch = curl_init();
                curl_setopt($ch, CURLOPT_URL, 'http://localhost:11434/api/chat');
                curl_setopt($ch, CURLOPT_POST, true);
                curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode([
                    'model' => $model,
                    'messages' => [
                        [
                            'role' => 'system',
                            'content' => 'You are an expert Calculus 2 tutor for engineering students. Provide clear, detailed explanations with step-by-step solutions. Use proper mathematical notation and relate concepts to engineering applications when possible.'
                        ],
                        [
                            'role' => 'user', 
                            'content' => $message
                        ]
                    ],
                    'stream' => false
                ]));
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
                curl_setopt($ch, CURLOPT_TIMEOUT, 30);
                
                $response = curl_exec($ch);
                $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                curl_close($ch);
                
                if ($httpCode === 200) {
                    $ollama_response = json_decode($response, true);
                    $ai_message = $ollama_response['message']['content'] ?? 'No response received';
                    
                    // Save to database
                    $stmt = $db->prepare("INSERT INTO conversations (user_message, ai_response, model_used, topic) VALUES (?, ?, ?, ?)");
                    $stmt->execute([$message, $ai_message, $model, $topic]);
                    
                    echo json_encode(['success' => true, 'response' => $ai_message]);
                } else {
                    echo json_encode(['success' => false, 'error' => 'Failed to get response from Ollama']);
                }
                break;
                
            case 'get_history':
                $db = initDatabase();
                $stmt = $db->prepare("SELECT * FROM conversations ORDER BY timestamp DESC LIMIT 50");
                $stmt->execute();
                $history = $stmt->fetchAll(PDO::FETCH_ASSOC);
                echo json_encode(['success' => true, 'history' => $history]);
                break;
        }
        exit;
    }
}

$db = initDatabase();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Calculus 2 for Engineers - Master Course</title>
    <script src="https://polyfill.io/v3/polyfill.min.js?features=es6"></script>
    <script id="MathJax-script" async src="https://cdn.jsdelivr.net/npm/mathjax@3/es5/tex-mml-chtml.js"></script>
    <script>
        window.MathJax = {
            tex: {
                inlineMath: [['$', '$'], ['\\(', '\\)']],
                displayMath: [['$$', '$$'], ['\\[', '\\]']]
            }
        };
    </script>
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
            color: #333;
        }

        .container {
            max-width: 1400px;
            margin: 0 auto;
            padding: 20px;
        }

        .header {
            background: rgba(255, 255, 255, 0.95);
            border-radius: 15px;
            padding: 30px;
            margin-bottom: 30px;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.1);
            text-align: center;
        }

        .header h1 {
            font-size: 2.5em;
            background: linear-gradient(45deg, #667eea, #764ba2);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            margin-bottom: 10px;
        }

        .main-content {
            display: grid;
            grid-template-columns: 300px 1fr 400px;
            gap: 30px;
            align-items: start;
        }

        .sidebar {
            background: rgba(255, 255, 255, 0.95);
            border-radius: 15px;
            padding: 25px;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.1);
        }

        .content-area {
            background: rgba(255, 255, 255, 0.95);
            border-radius: 15px;
            padding: 30px;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.1);
            min-height: 600px;
        }

        .ai-chat {
            background: rgba(255, 255, 255, 0.95);
            border-radius: 15px;
            padding: 25px;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.1);
            height: fit-content;
        }

        .nav-menu {
            list-style: none;
        }

        .nav-menu li {
            margin-bottom: 15px;
        }

        .nav-menu a {
            display: block;
            padding: 12px 15px;
            text-decoration: none;
            color: #555;
            border-radius: 8px;
            transition: all 0.3s ease;
            font-weight: 500;
        }

        .nav-menu a:hover, .nav-menu a.active {
            background: linear-gradient(45deg, #667eea, #764ba2);
            color: white;
            transform: translateX(5px);
        }

        .topic-content {
            display: none;
        }

        .topic-content.active {
            display: block;
        }

        .topic-content h2 {
            color: #2c3e50;
            margin-bottom: 20px;
            font-size: 1.8em;
            border-bottom: 3px solid #667eea;
            padding-bottom: 10px;
        }

        .topic-content h3 {
            color: #34495e;
            margin: 25px 0 15px 0;
            font-size: 1.3em;
        }

        .example-box {
            background: #f8f9fa;
            border-left: 4px solid #667eea;
            padding: 20px;
            margin: 20px 0;
            border-radius: 0 8px 8px 0;
        }

        .formula-box {
            background: linear-gradient(135deg, #e3f2fd, #bbdefb);
            border: 2px solid #2196f3;
            border-radius: 10px;
            padding: 20px;
            margin: 20px 0;
            text-align: center;
        }

        .chat-container {
            height: 400px;
            display: flex;
            flex-direction: column;
        }

        .chat-messages {
            flex: 1;
            overflow-y: auto;
            border: 2px solid #e0e0e0;
            border-radius: 10px;
            padding: 15px;
            margin-bottom: 15px;
            background: #fafafa;
        }

        .message {
            margin-bottom: 15px;
            padding: 10px;
            border-radius: 8px;
        }

        .user-message {
            background: #667eea;
            color: white;
            margin-left: 20px;
        }

        .ai-message {
            background: #e8f5e8;
            border-left: 4px solid #4caf50;
            margin-right: 20px;
        }

        .chat-input {
            display: flex;
            gap: 10px;
        }

        .chat-input textarea {
            flex: 1;
            padding: 12px;
            border: 2px solid #ddd;
            border-radius: 8px;
            resize: vertical;
            min-height: 50px;
            font-family: inherit;
        }

        .btn {
            background: linear-gradient(45deg, #667eea, #764ba2);
            color: white;
            border: none;
            padding: 12px 20px;
            border-radius: 8px;
            cursor: pointer;
            font-weight: 600;
            transition: all 0.3s ease;
        }

        .btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(102, 126, 234, 0.4);
        }

        .model-select {
            width: 100%;
            padding: 10px;
            border: 2px solid #ddd;
            border-radius: 8px;
            margin-bottom: 15px;
            font-size: 14px;
        }

        .progress-indicator {
            background: #e0e0e0;
            border-radius: 10px;
            height: 8px;
            margin: 15px 0;
            overflow: hidden;
        }

        .progress-bar {
            background: linear-gradient(45deg, #667eea, #764ba2);
            height: 100%;
            width: 0%;
            transition: width 0.3s ease;
        }

        .subtopic {
            margin-left: 20px;
            margin-bottom: 15px;
            padding: 15px;
            background: #f9f9f9;
            border-radius: 8px;
            border-left: 3px solid #667eea;
        }

        .formula {
            background: #fff;
            padding: 15px;
            border-radius: 8px;
            margin: 10px 0;
            border: 1px solid #e0e0e0;
            text-align: center;
        }

        @media (max-width: 1200px) {
            .main-content {
                grid-template-columns: 1fr;
                gap: 20px;
            }
        }

        .loading {
            display: none;
            text-align: center;
            padding: 10px;
            color: #666;
        }

        .error {
            background: #ffebee;
            color: #c62828;
            padding: 10px;
            border-radius: 5px;
            margin: 10px 0;
            border-left: 4px solid #c62828;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>Calculus 2 for Engineers</h1>
            <p>Master advanced calculus concepts with AI-powered learning assistance</p>
        </div>

        <div class="main-content">
            <div class="sidebar">
                <h3>Course Topics</h3>
                <ul class="nav-menu">
                    <li><a href="#" onclick="showTopic('integration')" class="active">Advanced Integration</a></li>
                    <li><a href="#" onclick="showTopic('applications')">Applications of Integration</a></li>
                    <li><a href="#" onclick="showTopic('sequences')">Sequences & Series</a></li>
                    <li><a href="#" onclick="showTopic('parametric')">Parametric & Polar</a></li>
                    <li><a href="#" onclick="showTopic('differential')">Differential Equations</a></li>
                </ul>
                
                <div class="progress-indicator">
                    <div class="progress-bar" id="progressBar"></div>
                </div>
                <p><span id="progressText">0%</span> Complete</p>
            </div>

            <div class="content-area">
                <!-- Integration Techniques -->
                <div id="integration" class="topic-content active">
                    <h2>Advanced Integration Techniques</h2>
                    
                    <h3>Integration by Parts</h3>
                    <div class="formula-box">
                        <div class="formula">
                            $$\int u \, dv = uv - \int v \, du$$
                        </div>
                    </div>
                    
                    <div class="subtopic">
                        <h4>LIATE Rule for choosing u:</h4>
                        <ul>
                            <li><strong>L</strong>ogarithmic functions: $\ln x$, $\log x$</li>
                            <li><strong>I</strong>nverse trig functions: $\arcsin x$, $\arctan x$</li>
                            <li><strong>A</strong>lgebraic functions: $x^n$, polynomials</li>
                            <li><strong>T</strong>rigonometric functions: $\sin x$, $\cos x$</li>
                            <li><strong>E</strong>xponential functions: $e^x$, $a^x$</li>
                        </ul>
                    </div>

                    <div class="example-box">
                        <h4>Example: $\int x e^x \, dx$</h4>
                        <p>Using LIATE: $u = x$ (algebraic), $dv = e^x dx$ (exponential)</p>
                        <p>$du = dx$, $v = e^x$</p>
                        <div class="formula">
                            $$\int x e^x \, dx = x e^x - \int e^x \, dx = x e^x - e^x + C = e^x(x-1) + C$$
                        </div>
                    </div>

                    <h3>Trigonometric Integrals</h3>
                    <div class="subtopic">
                        <h4>Powers of Sine and Cosine:</h4>
                        <ul>
                            <li>If power of sine is odd: factor out $\sin x$, use $\sin^2 x = 1 - \cos^2 x$</li>
                            <li>If power of cosine is odd: factor out $\cos x$, use $\cos^2 x = 1 - \sin^2 x$</li>
                            <li>If both powers are even: use half-angle formulas</li>
                        </ul>
                        
                        <div class="formula">
                            $$\sin^2 x = \frac{1 - \cos 2x}{2}, \quad \cos^2 x = \frac{1 + \cos 2x}{2}$$
                        </div>
                    </div>

                    <h3>Trigonometric Substitution</h3>
                    <div class="subtopic">
                        <table style="width: 100%; border-collapse: collapse; margin: 10px 0;">
                            <tr style="background: #667eea; color: white;">
                                <th style="padding: 10px; border: 1px solid #ddd;">Expression</th>
                                <th style="padding: 10px; border: 1px solid #ddd;">Substitution</th>
                                <th style="padding: 10px; border: 1px solid #ddd;">Identity</th>
                            </tr>
                            <tr>
                                <td style="padding: 10px; border: 1px solid #ddd;">$\sqrt{a^2 - x^2}$</td>
                                <td style="padding: 10px; border: 1px solid #ddd;">$x = a\sin\theta$</td>
                                <td style="padding: 10px; border: 1px solid #ddd;">$\sin^2\theta + \cos^2\theta = 1$</td>
                            </tr>
                            <tr>
                                <td style="padding: 10px; border: 1px solid #ddd;">$\sqrt{x^2 + a^2}$</td>
                                <td style="padding: 10px; border: 1px solid #ddd;">$x = a\tan\theta$</td>
                                <td style="padding: 10px; border: 1px solid #ddd;">$1 + \tan^2\theta = \sec^2\theta$</td>
                            </tr>
                            <tr>
                                <td style="padding: 10px; border: 1px solid #ddd;">$\sqrt{x^2 - a^2}$</td>
                                <td style="padding: 10px; border: 1px solid #ddd;">$x = a\sec\theta$</td>
                                <td style="padding: 10px; border: 1px solid #ddd;">$\sec^2\theta - 1 = \tan^2\theta$</td>
                            </tr>
                        </table>
                    </div>

                    <h3>Partial Fractions</h3>
                    <div class="subtopic">
                        <p>For rational functions $\frac{P(x)}{Q(x)}$ where degree of $P < $ degree of $Q$:</p>
                        <ul>
                            <li><strong>Linear factors:</strong> $\frac{A}{x-a}$</li>
                            <li><strong>Repeated linear:</strong> $\frac{A}{x-a} + \frac{B}{(x-a)^2}$</li>
                            <li><strong>Irreducible quadratic:</strong> $\frac{Ax + B}{x^2 + px + q}$</li>
                        </ul>
                    </div>
                </div>

                <!-- Applications of Integration -->
                <div id="applications" class="topic-content">
                    <h2>Applications of Integration</h2>
                    
                    <h3>Area Between Curves</h3>
                    <div class="formula-box">
                        <div class="formula">
                            $$A = \int_a^b |f(x) - g(x)| \, dx$$
                        </div>
                    </div>
                    
                    <h3>Volumes of Revolution</h3>
                    <div class="subtopic">
                        <h4>Disk Method (rotation about x-axis):</h4>
                        <div class="formula">
                            $$V = \pi \int_a^b [f(x)]^2 \, dx$$
                        </div>
                        
                        <h4>Washer Method:</h4>
                        <div class="formula">
                            $$V = \pi \int_a^b ([R(x)]^2 - [r(x)]^2) \, dx$$
                        </div>
                        
                        <h4>Cylindrical Shells:</h4>
                        <div class="formula">
                            $$V = 2\pi \int_a^b x \cdot f(x) \, dx$$
                        </div>
                    </div>

                    <h3>Arc Length</h3>
                    <div class="formula-box">
                        <div class="formula">
                            $$L = \int_a^b \sqrt{1 + \left(\frac{dy}{dx}\right)^2} \, dx$$
                        </div>
                    </div>

                    <h3>Engineering Applications</h3>
                    <div class="subtopic">
                        <h4>Work Done by Variable Force:</h4>
                        <div class="formula">
                            $$W = \int_a^b F(x) \, dx$$
                        </div>
                        
                        <h4>Fluid Pressure:</h4>
                        <div class="formula">
                            $$F = \rho g \int_a^b h(y) \cdot w(y) \, dy$$
                        </div>
                        
                        <h4>Center of Mass:</h4>
                        <div class="formula">
                            $$\bar{x} = \frac{\int_a^b x \cdot f(x) \, dx}{\int_a^b f(x) \, dx}$$
                        </div>
                    </div>
                </div>

                <!-- Sequences and Series -->
                <div id="sequences" class="topic-content">
                    <h2>Sequences and Series</h2>
                    
                    <h3>Sequences</h3>
                    <div class="subtopic">
                        <p>A sequence $\{a_n\}$ converges to limit $L$ if:</p>
                        <div class="formula">
                            $$\lim_{n \to \infty} a_n = L$$
                        </div>
                        
                        <h4>Properties:</h4>
                        <ul>
                            <li><strong>Monotonic:</strong> Either increasing or decreasing</li>
                            <li><strong>Bounded:</strong> $|a_n| \leq M$ for some constant $M$</li>
                            <li><strong>Convergence:</strong> Bounded monotonic sequences converge</li>
                        </ul>
                    </div>

                    <h3>Series Convergence Tests</h3>
                    <div class="subtopic">
                        <h4>Geometric Series:</h4>
                        <div class="formula">
                            $$\sum_{n=0}^{\infty} ar^n = \frac{a}{1-r} \text{ if } |r| < 1$$
                        </div>
                        
                        <h4>p-Series:</h4>
                        <div class="formula">
                            $$\sum_{n=1}^{\infty} \frac{1}{n^p} \text{ converges if } p > 1$$
                        </div>
                        
                        <h4>Ratio Test:</h4>
                        <div class="formula">
                            $$\lim_{n \to \infty} \left|\frac{a_{n+1}}{a_n}\right| = L$$
                        </div>
                        <ul>
                            <li>If $L < 1$: series converges</li>
                            <li>If $L > 1$: series diverges</li>
                            <li>If $L = 1$: test inconclusive</li>
                        </ul>
                        
                        <h4>Root Test:</h4>
                        <div class="formula">
                            $$\lim_{n \to \infty} \sqrt[n]{|a_n|} = L$$
                        </div>
                    </div>

                    <h3>Power Series</h3>
                    <div class="formula-box">
                        <div class="formula">
                            $$\sum_{n=0}^{\infty} c_n (x-a)^n$$
                        </div>
                    </div>
                    
                    <div class="subtopic">
                        <h4>Radius of Convergence:</h4>
                        <div class="formula">
                            $$R = \lim_{n \to \infty} \left|\frac{c_n}{c_{n+1}}\right| \text{ or } R = \frac{1}{\lim_{n \to \infty} \sqrt[n]{|c_n|}}$$
                        </div>
                    </div>

                    <h3>Taylor Series</h3>
                    <div class="formula-box">
                        <div class="formula">
                            $$f(x) = \sum_{n=0}^{\infty} \frac{f^{(n)}(a)}{n!}(x-a)^n$$
                        </div>
                    </div>
                    
                    <div class="subtopic">
                        <h4>Common Taylor Series:</h4>
                        <div class="formula">
                            $$e^x = \sum_{n=0}^{\infty} \frac{x^n}{n!} = 1 + x + \frac{x^2}{2!} + \frac{x^3}{3!} + \cdots$$
                        </div>
                        <div class="formula">
                            $$\sin x = \sum_{n=0}^{\infty} \frac{(-1)^n x^{2n+1}}{(2n+1)!} = x - \frac{x^3}{3!} + \frac{x^5}{5!} - \cdots$$
                        </div>
                        <div class="formula">
                            $$\cos x = \sum_{n=0}^{\infty} \frac{(-1)^n x^{2n}}{(2n)!} = 1 - \frac{x^2}{2!} + \frac{x^4}{4!} - \cdots$$
                        </div>
                    </div>
                </div>

                <!-- Parametric and Polar -->
                <div id="parametric" class="topic-content">
                    <h2>Parametric Equations and Polar Coordinates</h2>
                    
                    <h3>Parametric Equations</h3>
                    <div class="subtopic">
                        <p>Curve defined by: $x = f(t)$, $y = g(t)$</p>
                        
                        <h4>Calculus with Parametric Equations:</h4>
                        <div class="formula">
                            $$\frac{dy}{dx} = \frac{\frac{dy}{dt}}{\frac{dx}{dt}} = \frac{g'(t)}{f'(t)}$$
                        </div>
                        
                        <div class="formula">
                            $$\frac{d^2y}{dx^2} = \frac{\frac{d}{dt}\left(\frac{dy}{dx}\right)}{\frac{dx}{dt}}$$
                        </div>
                        
                        <h4>Arc Length:</h4>
                        <div class="formula">
                            $$L = \int_{\alpha}^{\beta} \sqrt{\left(\frac{dx}{dt}\right)^2 + \left(\frac{dy}{dt}\right)^2} \, dt$$
                        </div>
                    </div>

                    <h3>Polar Coordinates</h3>
                    <div class="subtopic">
                        <p>Point represented as $(r, \theta)$ where:</p>
                        <div class="formula">
                            $$x = r\cos\theta, \quad y = r\sin\theta$$
                        </div>
                        <div class="formula">
                            $$r = \sqrt{x^2 + y^2}, \quad \theta = \arctan\left(\frac{y}{x}\right)$$
                        </div>
                        
                        <h4>Area in Polar Coordinates:</h4>
                        <div class="formula">
                            $$A = \frac{1}{2}\int_{\alpha}^{\beta} r^2 \, d\theta$$
                        </div>
                        
                        <h4>Arc Length in Polar:</h4>
                        <div class="formula">
                            $$L = \int_{\alpha}^{\beta} \sqrt{r^2 + \left(\frac{dr}{d\theta}\right)^2} \, d\theta$$
                        </div>
                    </div>

                    <h3>Conic Sections in Polar Form</h3>
                    <div class="formula-box">
                        <div class="formula">
                            $$r = \frac{ed}{1 + e\cos\theta} \text{ or } r = \frac{ed}{1 + e\sin\theta}$$
                        </div>
                    </div>
                    
                    <div class="subtopic">
                        <ul>
                            <li><strong>Circle:</strong> $e = 0$</li>
                            <li><strong>Ellipse:</strong> $0 < e < 1$</li>
                            <li><strong>Parabola:</strong> $e = 1$</li>
                            <li><strong>Hyperbola:</strong> $e > 1$</li>
                        </ul>
                    </div>
                </div>

                <!-- Differential Equations -->
                <div id="differential" class="topic-content">
                    <h2>Differential Equations</h2>
                    
                    <h3>First-Order Linear Differential Equations</h3>
                    <div class="formula-box">
                        <div class="formula">
                            $$\frac{dy}{dx} + P(x)y = Q(x)$$
                        </div>
                    </div>
                    
                    <div class="subtopic">
                        <h4>Solution Method:</h4>
                        <ol>
                            <li>Find integrating factor: $\mu(x) = e^{\int P(x) \, dx}$</li>
                            <li>Multiply equation by $\mu(x)$</li>
                            <li>Left side becomes $\frac{d}{dx}[\mu(x)y]$</li>
                            <li>Integrate both sides</li>
                        </ol>
                        
                        <div class="formula">
                            $$y = \frac{1}{\mu(x)}\left[\int \mu(x)Q(x) \, dx + C\right]$$
                        </div>
                    </div>

                    <h3>Separable Differential Equations</h3>
                    <div class="formula-box">
                        <div class="formula">
                            $$\frac{dy}{dx} = f(x)g(y)$$
                        </div>
                    </div>
                    
                    <div class="subtopic">
                        <h4>Solution Method:</h4>
                        <ol>
                            <li>Separate variables: $\frac{dy}{g(y)} = f(x) \, dx$</li>
                            <li>Integrate both sides: $\int \frac{dy}{g(y)} = \int f(x) \, dx$</li>
                            <li>Solve for $y$ if possible</li>
                        </ol>
                    </div>

                    <h3>Engineering Applications</h3>
                    <div class="subtopic">
                        <h4>Population Growth:</h4>
                        <div class="formula">
                            $$\frac{dP}{dt} = kP \quad \Rightarrow \quad P(t) = P_0 e^{kt}$$
                        </div>
                        
                        <h4>Newton's Law of Cooling:</h4>
                        <div class="formula">
                            $$\frac{dT}{dt} = -k(T - T_s) \quad \Rightarrow \quad T(t) = T_s + (T_0 - T_s)e^{-kt}$$
                        </div>
                        
                        <h4>RC Circuit:</h4>
                        <div class="formula">
                            $$R\frac{dI}{dt} + \frac{I}{C} = \frac{dV}{dt}$$
                        </div>
                    </div>
                </div>
            </div>

            <div class="ai-chat">
                <h3>AI Calculus Tutor</h3>
                <select id="modelSelect" class="model-select">
                    <option value="">Select Ollama Model...</option>
                </select>
                
                <div class="chat-container">
                    <div id="chatMessages" class="chat-messages">
                        <div class="ai-message">
                            <strong>AI Tutor:</strong> Hello! I'm your Calculus 2 tutor. Ask me about any integration techniques, series convergence, parametric equations, or differential equations. I'm here to help you master these concepts!
                        </div>
                    </div>
                    
                    <div class="chat-input">
                        <textarea id="userInput" placeholder="Ask a calculus question..." rows="2"></textarea>
                        <button class="btn" onclick="sendMessage()">Send</button>
                    </div>
                </div>
                
                <div id="loading" class="loading">
                    <p>AI is thinking...</p>
                </div>
                
                <button class="btn" onclick="loadChatHistory()" style="width: 100%; margin-top: 10px;">
                    View Chat History
                </button>
            </div>
        </div>
    </div>

    <script>
        let currentTopic = 'integration';
        let chatHistory = [];

        // Initialize the application
        document.addEventListener('DOMContentLoaded', function() {
            loadOllamaModels();
            updateProgress();
        });

        // Load available Ollama models
        async function loadOllamaModels() {
            try {
                const response = await fetch('', {
                    method: 'POST',
                    headers: {'Content-Type': 'application/json'},
                    body: JSON.stringify({action: 'get_models'})
                });
                
                const data = await response.json();
                const select = document.getElementById('modelSelect');
                
                if (data.success && data.models.length > 0) {
                    data.models.forEach(model => {
                        const option = document.createElement('option');
                        option.value = model.name;
                        option.textContent = `${model.name} (${(model.size / 1000000000).toFixed(1)}GB)`;
                        select.appendChild(option);
                    });
                } else {
                    const option = document.createElement('option');
                    option.textContent = 'Ollama not available';
                    option.disabled = true;
                    select.appendChild(option);
                }
            } catch (error) {
                console.error('Error loading models:', error);
            }
        }

        // Show topic content
        function showTopic(topic) {
            // Hide all topics
            document.querySelectorAll('.topic-content').forEach(el => {
                el.classList.remove('active');
            });
            
            // Show selected topic
            document.getElementById(topic).classList.add('active');
            
            // Update navigation
            document.querySelectorAll('.nav-menu a').forEach(el => {
                el.classList.remove('active');
            });
            event.target.classList.add('active');
            
            currentTopic = topic;
            updateProgress();
            
            // Re-render math
            if (window.MathJax) {
                MathJax.typesetPromise();
            }
        }

        // Send message to AI
        async function sendMessage() {
            const input = document.getElementById('userInput');
            const messages = document.getElementById('chatMessages');
            const modelSelect = document.getElementById('modelSelect');
            const loading = document.getElementById('loading');
            
            const message = input.value.trim();
            const model = modelSelect.value;
            
            if (!message) return;
            if (!model) {
                alert('Please select an Ollama model first');
                return;
            }
            
            // Add user message to chat
            const userDiv = document.createElement('div');
            userDiv.className = 'message user-message';
            userDiv.innerHTML = `<strong>You:</strong> ${message}`;
            messages.appendChild(userDiv);
            
            // Clear input and show loading
            input.value = '';
            loading.style.display = 'block';
            messages.scrollTop = messages.scrollHeight;
            
            try {
                const response = await fetch('', {
                    method: 'POST',
                    headers: {'Content-Type': 'application/json'},
                    body: JSON.stringify({
                        action: 'send_message',
                        message: message,
                        model: model,
                        topic: currentTopic
                    })
                });
                
                const data = await response.json();
                loading.style.display = 'none';
                
                if (data.success) {
                    const aiDiv = document.createElement('div');
                    aiDiv.className = 'message ai-message';
                    aiDiv.innerHTML = `<strong>AI Tutor:</strong> ${data.response}`;
                    messages.appendChild(aiDiv);
                    
                    // Re-render math in the new message
                    if (window.MathJax) {
                        MathJax.typesetPromise([aiDiv]);
                    }
                } else {
                    const errorDiv = document.createElement('div');
                    errorDiv.className = 'error';
                    errorDiv.textContent = 'Error: ' + (data.error || 'Unknown error');
                    messages.appendChild(errorDiv);
                }
                
                messages.scrollTop = messages.scrollHeight;
                
            } catch (error) {
                loading.style.display = 'none';
                const errorDiv = document.createElement('div');
                errorDiv.className = 'error';
                errorDiv.textContent = 'Network error: ' + error.message;
                messages.appendChild(errorDiv);
                messages.scrollTop = messages.scrollHeight;
            }
        }

        // Load chat history
        async function loadChatHistory() {
            try {
                const response = await fetch('', {
                    method: 'POST',
                    headers: {'Content-Type': 'application/json'},
                    body: JSON.stringify({action: 'get_history'})
                });
                
                const data = await response.json();
                
                if (data.success) {
                    const messages = document.getElementById('chatMessages');
                    messages.innerHTML = '';
                    
                    data.history.reverse().forEach(item => {
                        const userDiv = document.createElement('div');
                        userDiv.className = 'message user-message';
                        userDiv.innerHTML = `<strong>You:</strong> ${item.user_message}`;
                        messages.appendChild(userDiv);
                        
                        const aiDiv = document.createElement('div');
                        aiDiv.className = 'message ai-message';
                        aiDiv.innerHTML = `<strong>AI (${item.model_used}):</strong> ${item.ai_response}`;
                        messages.appendChild(aiDiv);
                    });
                    
                    messages.scrollTop = messages.scrollHeight;
                    
                    // Re-render math
                    if (window.MathJax) {
                        MathJax.typesetPromise();
                    }
                }
            } catch (error) {
                console.error('Error loading history:', error);
            }
        }

        // Update progress indicator
        function updateProgress() {
            // Simple progress calculation based on current topic
            const topics = ['integration', 'applications', 'sequences', 'parametric', 'differential'];
            const currentIndex = topics.indexOf(currentTopic);
            const progress = ((currentIndex + 1) / topics.length) * 100;
            
            document.getElementById('progressBar').style.width = progress + '%';
            document.getElementById('progressText').textContent = Math.round(progress) + '%';
        }

        // Handle Enter key in chat input
        document.getElementById('userInput').addEventListener('keypress', function(e) {
            if (e.key === 'Enter' && !e.shiftKey) {
                e.preventDefault();
                sendMessage();
            }
        });

        // Auto-resize textarea
        document.getElementById('userInput').addEventListener('input', function() {
            this.style.height = 'auto';
            this.style.height = this.scrollHeight + 'px';
        });
    </script>
</body>
</html>
