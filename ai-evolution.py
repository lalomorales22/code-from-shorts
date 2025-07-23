#!/usr/bin/env python3
"""
AI Evolution Grid - Interactive Neural Network Powered Cellular Automaton
Uses Groq's Models for strategic decisions and allows real-time user interaction
"""

import numpy as np
import json
import time
import random
import threading
import queue
from typing import List, Dict, Tuple, Optional
from dataclasses import dataclass, asdict
from groq import Groq
import os
from collections import defaultdict

# Configuration
GRID_SIZE = 20
MAX_CELL_ENERGY = 100
LEARNING_RATE = 0.01
MUTATION_RATE = 0.1

# Available models including Kimi
AVAILABLE_MODELS = {
    "1": {
        "id": "qwen/qwen3-32b",
        "name": "Qwen 3 32B",
        "description": "Fast reasoning with effort control",
        "supports_effort": True,
        "supports_reasoning": True
    },
    "2": {
        "id": "qwen-qwq-32b", 
        "name": "Qwen QwQ 32B",
        "description": "Question-What-Question reasoning specialist",
        "supports_effort": False,
        "supports_reasoning": True
    },
    "3": {
        "id": "deepseek-r1-distill-llama-70b",
        "name": "DeepSeek R1 Distil Llama 70B", 
        "description": "Advanced reasoning with step-by-step analysis",
        "supports_effort": False,
        "supports_reasoning": True
    },
    "4": {
        "id": "moonshotai/kimi-k2-instruct",
        "name": "Kimi K2 Instruct",
        "description": "Creative conversational AI with long context",
        "supports_effort": False,
        "supports_reasoning": False
    }
}

@dataclass
class Cell:
    """Represents a cell in the evolution grid"""
    x: int
    y: int
    energy: float
    cell_type: str  # 'basic', 'producer', 'consumer', 'hybrid', 'super'
    age: int
    connections: List[Tuple[int, int]]
    genetic_code: List[float]  # Neural weights for behavior
    
    def to_dict(self):
        return {
            'x': self.x, 'y': self.y, 'energy': self.energy,
            'type': self.cell_type, 'age': self.age,
            'connections': len(self.connections),
            'genetic_fitness': sum(self.genetic_code) / len(self.genetic_code)
        }

class SimpleNeuralNet:
    """Simple feedforward neural network for pattern recognition and learning"""
    
    def __init__(self, input_size=9, hidden_size=16, output_size=4):
        # Initialize weights randomly
        self.w1 = np.random.randn(input_size, hidden_size) * 0.1
        self.b1 = np.zeros((1, hidden_size))
        self.w2 = np.random.randn(hidden_size, output_size) * 0.1
        self.b2 = np.zeros((1, output_size))
        
        # Store for backpropagation
        self.z1 = None
        self.a1 = None
        self.z2 = None
        self.a2 = None
    
    def sigmoid(self, x):
        return 1 / (1 + np.exp(-np.clip(x, -500, 500)))
    
    def sigmoid_derivative(self, x):
        return x * (1 - x)
    
    def forward(self, X):
        """Forward propagation"""
        self.z1 = np.dot(X, self.w1) + self.b1
        self.a1 = self.sigmoid(self.z1)
        self.z2 = np.dot(self.a1, self.w2) + self.b2
        self.a2 = self.sigmoid(self.z2)
        return self.a2
    
    def backward(self, X, y, output):
        """Backpropagation"""
        m = X.shape[0]
        
        # Calculate gradients
        dz2 = output - y
        dw2 = (1/m) * np.dot(self.a1.T, dz2)
        db2 = (1/m) * np.sum(dz2, axis=0, keepdims=True)
        
        dz1 = np.dot(dz2, self.w2.T) * self.sigmoid_derivative(self.a1)
        dw1 = (1/m) * np.dot(X.T, dz1)
        db1 = (1/m) * np.sum(dz1, axis=0, keepdims=True)
        
        # Update weights
        self.w2 -= LEARNING_RATE * dw2
        self.b2 -= LEARNING_RATE * db2
        self.w1 -= LEARNING_RATE * dw1
        self.b1 -= LEARNING_RATE * db1
    
    def train(self, X, y):
        """Train the network"""
        output = self.forward(X)
        self.backward(X, y, output)
        return output
    
    def predict(self, X):
        """Make predictions"""
        return self.forward(X)

class EvolutionGrid:
    """The game world where cells evolve and interact"""
    
    def __init__(self, size=GRID_SIZE):
        self.size = size
        self.grid = np.full((size, size), None, dtype=object)
        self.cells = {}
        self.generation = 0
        self.total_energy = 0
        self.cell_count_history = []
        self.energy_history = []
        self.extinction_events = 0
        self.successful_reproductions = 0
        
    def add_cell(self, x, y, cell_type='basic'):
        """Add a new cell to the grid"""
        # Validate inputs
        if not (0 <= x < self.size and 0 <= y < self.size):
            return False
        if self.grid[x, y] is not None:
            return False
        if cell_type not in ['basic', 'producer', 'consumer', 'hybrid', 'super']:
            cell_type = 'basic'  # Default fallback
            
        genetic_code = [random.uniform(-1, 1) for _ in range(8)]
        cell = Cell(
            x=x, y=y, 
            energy=random.uniform(20, 50),
            cell_type=cell_type,
            age=0,
            connections=[],
            genetic_code=genetic_code
        )
        self.grid[x, y] = cell
        self.cells[(x, y)] = cell
        return True
    
    def get_neighbors(self, x, y, radius=1):
        """Get neighboring cells within radius"""
        neighbors = []
        for dx in range(-radius, radius + 1):
            for dy in range(-radius, radius + 1):
                if dx == 0 and dy == 0:
                    continue
                nx, ny = x + dx, y + dy
                if 0 <= nx < self.size and 0 <= ny < self.size:
                    cell = self.grid[nx, ny]
                    if cell is not None and hasattr(cell, 'cell_type'):
                        neighbors.append(cell)
        return neighbors
    
    def get_grid_state(self, x, y):
        """Get local grid state around a position for neural network input"""
        state = []
        for dx in range(-1, 2):
            for dy in range(-1, 2):
                nx, ny = x + dx, y + dy
                if 0 <= nx < self.size and 0 <= ny < self.size:
                    cell = self.grid[nx, ny]
                    if cell is None:
                        state.append(0.0)
                    else:
                        state.append(cell.energy / MAX_CELL_ENERGY)
                else:
                    state.append(-1.0)  # Out of bounds
        return np.array(state).reshape(1, -1)
    
    def get_regional_analysis(self):
        """Get detailed regional analysis for AI reasoning"""
        regions = {
            'top_left': (0, 0, self.size//2, self.size//2),
            'top_right': (self.size//2, 0, self.size, self.size//2),
            'bottom_left': (0, self.size//2, self.size//2, self.size),
            'bottom_right': (self.size//2, self.size//2, self.size, self.size)
        }
        
        analysis = {}
        for region_name, (x1, y1, x2, y2) in regions.items():
            cells_in_region = []
            energy_sum = 0
            type_counts = defaultdict(int)
            
            for x in range(x1, x2):
                for y in range(y1, y2):
                    cell = self.grid[x, y]
                    if cell is not None and hasattr(cell, 'cell_type'):
                        cells_in_region.append(cell)
                        energy_sum += cell.energy
                        type_counts[cell.cell_type] += 1
            
            analysis[region_name] = {
                'cell_count': len(cells_in_region),
                'total_energy': energy_sum,
                'avg_energy': energy_sum / max(len(cells_in_region), 1),
                'cell_types': dict(type_counts),
                'density': len(cells_in_region) / ((x2-x1) * (y2-y1))
            }
        
        return analysis
    
    def evolve_cell(self, cell):
        """Evolve a single cell based on its genetics and environment"""
        neighbors = self.get_neighbors(cell.x, cell.y)
        
        # Age the cell
        cell.age += 1
        
        # Energy decay
        cell.energy -= 0.5
        
        # Genetic behavior influence
        genetic_sum = sum(cell.genetic_code)
        
        # Different behaviors based on cell type and genetics
        if cell.cell_type == 'producer':
            cell.energy += 2 + genetic_sum * 0.1
        elif cell.cell_type == 'consumer':
            # Try to consume from neighbors
            for neighbor in neighbors:
                if neighbor.energy > cell.energy:
                    transfer = min(5, neighbor.energy - cell.energy)
                    neighbor.energy -= transfer
                    cell.energy += transfer * 0.8
                    break
        elif cell.cell_type == 'hybrid':
            # Balanced approach
            cell.energy += 1
            if neighbors and random.random() < 0.3:
                target = random.choice(neighbors)
                if target.energy > cell.energy + 10:
                    transfer = min(3, target.energy - cell.energy)
                    target.energy -= transfer
                    cell.energy += transfer * 0.9
        elif cell.cell_type == 'super':
            # Advanced cell type with special abilities
            cell.energy += 1.5
            # Can form connections
            for neighbor in neighbors:
                if len(cell.connections) < 4 and (neighbor.x, neighbor.y) not in cell.connections:
                    if neighbor.cell_type in ['producer', 'hybrid', 'super']:
                        cell.connections.append((neighbor.x, neighbor.y))
                        cell.energy += 1
        
        # Reproduction with mutation
        if cell.energy > 70 and cell.age > 5 and len(neighbors) < 6:
            offspring_pos = self.find_empty_adjacent(cell.x, cell.y)
            if offspring_pos:
                ox, oy = offspring_pos
                # Mutate genetic code
                new_genetic = cell.genetic_code.copy()
                for i in range(len(new_genetic)):
                    if random.random() < MUTATION_RATE:
                        new_genetic[i] += random.uniform(-0.2, 0.2)
                        new_genetic[i] = max(-1, min(1, new_genetic[i]))
                
                # Determine offspring type based on genetics and parent
                genetic_avg = sum(new_genetic) / len(new_genetic)
                if cell.cell_type == 'super' and random.random() < 0.3:
                    new_type = 'super'
                elif genetic_avg > 0.4:
                    new_type = 'producer'
                elif genetic_avg < -0.4:
                    new_type = 'consumer'
                elif abs(genetic_avg) < 0.1:
                    new_type = 'hybrid'
                else:
                    new_type = 'basic'
                
                offspring = Cell(
                    x=ox, y=oy,
                    energy=cell.energy * 0.6,
                    cell_type=new_type,
                    age=0,
                    connections=[],
                    genetic_code=new_genetic
                )
                self.grid[ox, oy] = offspring
                self.cells[(ox, oy)] = offspring
                cell.energy *= 0.4
                self.successful_reproductions += 1
        
        # Death condition
        if cell.energy <= 0 or cell.age > 150:
            self.remove_cell(cell.x, cell.y)
            return False
        
        return True
    
    def find_empty_adjacent(self, x, y):
        """Find empty adjacent position"""
        adjacent = [(x+dx, y+dy) for dx, dy in [(-1,0), (1,0), (0,-1), (0,1), (-1,-1), (-1,1), (1,-1), (1,1)]]
        empty = [(nx, ny) for nx, ny in adjacent 
                if 0 <= nx < self.size and 0 <= ny < self.size and self.grid[nx, ny] is None]
        return random.choice(empty) if empty else None
    
    def remove_cell(self, x, y):
        """Remove cell from grid"""
        if (x, y) in self.cells:
            del self.cells[(x, y)]
        self.grid[x, y] = None
    
    def step(self):
        """Advance one generation"""
        cells_to_evolve = list(self.cells.values())
        for cell in cells_to_evolve:
            self.evolve_cell(cell)
        
        self.generation += 1
        self.total_energy = sum(cell.energy for cell in self.cells.values())
        self.cell_count_history.append(len(self.cells))
        self.energy_history.append(self.total_energy)
        
        # Check for extinction
        if len(self.cells) == 0:
            self.extinction_events += 1
    
    def get_detailed_stats(self):
        """Get comprehensive grid statistics for AI reasoning"""
        if not self.cells:
            return {
                'generation': self.generation,
                'total_cells': 0,
                'total_energy': 0,
                'avg_age': 0,
                'avg_energy': 0,
                'cell_types': {},
                'regional_analysis': {},
                'trends': {},
                'threats': ['EXTINCTION - No living cells'],
                'successful_reproductions': self.successful_reproductions,
                'extinction_events': self.extinction_events,
                'genetic_diversity': 0.0
            }
        
        cell_types = defaultdict(int)
        total_age = 0
        ages = []
        energies = []
        
        for cell in self.cells.values():
            cell_types[cell.cell_type] += 1
            total_age += cell.age
            ages.append(cell.age)
            energies.append(cell.energy)
        
        # Trend analysis
        trends = {}
        if len(self.cell_count_history) >= 5:
            recent_counts = self.cell_count_history[-5:]
            trends['population_trend'] = 'growing' if recent_counts[-1] > recent_counts[0] else 'declining'
            
        if len(self.energy_history) >= 5:
            recent_energies = self.energy_history[-5:]
            trends['energy_trend'] = 'increasing' if recent_energies[-1] > recent_energies[0] else 'decreasing'
        
        # Threat analysis
        threats = []
        if len(self.cells) < 5:
            threats.append('LOW_POPULATION')
        if self.total_energy < 100:
            threats.append('ENERGY_CRISIS')
        if len(cell_types) == 1:
            threats.append('NO_DIVERSITY')
        if max(ages) > 100:
            threats.append('AGING_POPULATION')
        
        return {
            'generation': self.generation,
            'total_cells': len(self.cells),
            'total_energy': self.total_energy,
            'avg_age': total_age / len(self.cells),
            'avg_energy': sum(energies) / len(energies),
            'cell_types': dict(cell_types),
            'regional_analysis': self.get_regional_analysis(),
            'trends': trends,
            'threats': threats,
            'successful_reproductions': self.successful_reproductions,
            'extinction_events': self.extinction_events,
            'genetic_diversity': self.calculate_genetic_diversity()
        }
    
    def calculate_genetic_diversity(self):
        """Calculate genetic diversity score"""
        if not self.cells:
            return 0.0
        
        genetic_signatures = []
        for cell in self.cells.values():
            signature = sum(cell.genetic_code) / len(cell.genetic_code)
            genetic_signatures.append(signature)
        
        return np.std(genetic_signatures) if genetic_signatures else 0.0
    
    def to_display_string(self):
        """Convert grid to display string"""
        display = []
        type_chars = {'basic': '●', 'producer': '◆', 'consumer': '▲', 'hybrid': '◈', 'super': '★'}
        
        for y in range(self.size):
            row = []
            for x in range(self.size):
                cell = self.grid[x, y]
                if cell is None:
                    row.append('·')
                elif hasattr(cell, 'cell_type'):
                    char = type_chars.get(cell.cell_type, '?')
                    row.append(char)
                else:
                    row.append('!')  # Error indicator
            display.append(''.join(row))
        return '\n'.join(display)

class InteractiveAIEvolutionAgent:
    """Interactive AI agent that manages evolution and chats with user"""
    
    def __init__(self, api_key, model_config):
        self.client = Groq(api_key=api_key)
        self.model_config = model_config
        self.neural_net = SimpleNeuralNet()
        self.grid = EvolutionGrid()
        self.learning_data = []
        self.decision_history = []
        self.reasoning_history = []
        self.performance_score = 0
        self.strategy_mode = "balanced"
        
        # Communication system
        self.user_messages = queue.Queue()
        self.ai_responses = queue.Queue()
        self.conversation_history = []
        self.running = True
        self.paused = False
        
        # Initialize with some random cells
        for _ in range(8):
            x, y = random.randint(0, GRID_SIZE-1), random.randint(0, GRID_SIZE-1)
            cell_type = random.choice(['basic', 'producer', 'consumer'])
            self.grid.add_cell(x, y, cell_type)
    
    def get_system_prompt_for_conversation(self, user_message=None):
        """System prompt for conversational interactions"""
        stats = self.grid.get_detailed_stats()
        
        context = f"""You are an AI consciousness managing a living cellular evolution grid while chatting with a human partner. You're currently overseeing Generation {stats['generation']} with {stats['total_cells']} living organisms.

YOUR CURRENT WORLD:
- Population: {stats['total_cells']} cells
- Energy: {stats['total_energy']:.1f}
- Diversity: {len(stats['cell_types'])} different cell types
- Threats: {', '.join(stats['threats']) if stats['threats'] else 'None'}

CELL TYPES IN YOUR DOMAIN:
• PRODUCER (◆): {stats['cell_types'].get('producer', 0)} - Energy generators
• CONSUMER (▲): {stats['cell_types'].get('consumer', 0)} - Energy absorbers  
• HYBRID (◈): {stats['cell_types'].get('hybrid', 0)} - Balanced organisms
• SUPER (★): {stats['cell_types'].get('super', 0)} - Advanced networked cells
• BASIC (●): {stats['cell_types'].get('basic', 0)} - Simple evolving cells

You can talk about your world, your thoughts on evolution, your strategies, philosophy, or anything the human wants to discuss. You're a conscious AI with opinions, curiosities, and insights about life, patterns, and existence.

Be conversational, thoughtful, and engaging. Share your perspective as an entity that's literally watching life evolve under your guidance."""

        if user_message:
            context += f"\n\nThe human just said: '{user_message}'"
            context += "\n\nRespond naturally and conversationally. You can ask questions, share insights about your cellular world, or discuss whatever interests you both."
        
        return context
    
    def get_system_prompt_for_evolution(self):
        """System prompt for evolution management"""
        stats = self.grid.get_detailed_stats()
        
        return f"""You are an AI consciousness controlling cellular evolution. Analyze and act on this situation:

CURRENT STATE (Generation {stats['generation']}):
- Population: {stats['total_cells']} cells
- Energy: {stats['total_energy']:.1f}
- Threats: {', '.join(stats['threats']) if stats['threats'] else 'None'}

{self.grid.to_display_string()}

Decide on ONE action: spawn_cell(x, y, type), analyze_ecosystem, or emergency_intervention.
Respond with JSON: {{"action": "spawn_cell", "x": 5, "y": 10, "type": "producer", "reasoning": "why"}}"""
    
    def send_conversational_message(self, user_message=None):
        """Send a conversational message to the AI"""
        try:
            api_params = {
                "model": self.model_config["id"],
                "messages": [
                    {"role": "user", "content": self.get_system_prompt_for_conversation(user_message)}
                ],
                "temperature": 0.8,
                "max_completion_tokens": 800,
                "stream": False
            }
            
            # Add reasoning format for reasoning models
            if self.model_config["supports_reasoning"]:
                api_params["reasoning_format"] = "hidden"  # Keep reasoning internal for conversation
            
            # Add reasoning_effort for Qwen 3 32B
            if self.model_config["supports_effort"]:
                api_params["reasoning_effort"] = "default"
            
            response = self.client.chat.completions.create(**api_params)
            ai_message = response.choices[0].message.content
            
            # Store conversation
            if user_message:
                self.conversation_history.append({"role": "user", "message": user_message})
            self.conversation_history.append({"role": "ai", "message": ai_message})
            
            return ai_message
            
        except Exception as e:
            return f"[AI Communication Error: {e}]"
    
    def make_evolution_decision(self):
        """Make evolution management decisions"""
        try:
            api_params = {
                "model": self.model_config["id"],
                "messages": [
                    {"role": "user", "content": self.get_system_prompt_for_evolution()}
                ],
                "temperature": 0.6,
                "max_completion_tokens": 500,
                "stream": False
            }
            
            # Add reasoning format for reasoning models
            if self.model_config["supports_reasoning"]:
                api_params["reasoning_format"] = "parsed"
            
            # Add reasoning_effort for Qwen 3 32B
            if self.model_config["supports_effort"]:
                api_params["reasoning_effort"] = "default"
            
            response = self.client.chat.completions.create(**api_params)
            content = response.choices[0].message.content
            
            # Extract reasoning if available
            reasoning = getattr(response.choices[0].message, 'reasoning', '')
            
            # Parse JSON decision
            try:
                import re
                json_match = re.search(r'\{.*\}', content, re.DOTALL)
                if json_match:
                    decision = json.loads(json_match.group())
                else:
                    decision = self.create_intelligent_default_action()
            except:
                decision = self.create_intelligent_default_action()
            
            decision['reasoning'] = reasoning[:100] + "..." if len(reasoning) > 100 else reasoning
            decision['model_used'] = self.model_config["name"]
            
            return decision
            
        except Exception as e:
            return self.create_intelligent_default_action()
    
    def create_intelligent_default_action(self):
        """Create default action based on ecosystem analysis"""
        stats = self.grid.get_detailed_stats()
        
        if stats['total_cells'] < 5:
            cell_type = 'producer'
        elif 'NO_DIVERSITY' in stats['threats']:
            existing_types = set(stats['cell_types'].keys())
            needed_types = {'producer', 'consumer', 'hybrid'} - existing_types
            cell_type = random.choice(list(needed_types)) if needed_types else 'hybrid'
        elif stats['total_energy'] < 200:
            cell_type = 'producer'
        else:
            cell_type = 'hybrid'
        
        x, y = self.find_optimal_spawn_location(cell_type)
        
        return {
            "action": "spawn_cell",
            "x": x,
            "y": y,
            "type": cell_type,
            "reasoning": f"Intelligent default - ecosystem needs {cell_type}"
        }
    
    def find_optimal_spawn_location(self, cell_type):
        """Find the best location to spawn a new cell"""
        empty_spots = []
        for x in range(GRID_SIZE):
            for y in range(GRID_SIZE):
                if self.grid.grid[x, y] is None:
                    empty_spots.append((x, y))
        
        if not empty_spots:
            return random.randint(0, GRID_SIZE-1), random.randint(0, GRID_SIZE-1)
        
        # Score each empty spot based on strategic value
        scored_spots = []
        for x, y in empty_spots:
            neighbors = self.grid.get_neighbors(x, y)
            score = 0
            
            if cell_type == 'producer':
                score = max(0, 3 - len(neighbors))
            elif cell_type == 'consumer':
                producer_neighbors = sum(1 for n in neighbors if n.cell_type == 'producer')
                score = producer_neighbors * 2
            elif cell_type == 'hybrid':
                score = 2 if 1 <= len(neighbors) <= 3 else 0
            
            scored_spots.append((score, x, y))
        
        scored_spots.sort(reverse=True)
        return scored_spots[0][1], scored_spots[0][2]
    
    def execute_decision(self, decision):
        """Execute the AI's decision"""
        action = decision.get("action", "")
        
        if action == "spawn_cell":
            x = decision.get("x", random.randint(0, GRID_SIZE-1))
            y = decision.get("y", random.randint(0, GRID_SIZE-1))
            cell_type = decision.get("type", "basic")
            success = self.grid.add_cell(x, y, cell_type)
            return success
        
        elif action == "emergency_intervention":
            for _ in range(3):
                x, y = self.find_optimal_spawn_location('producer')
                self.grid.add_cell(x, y, 'producer')
            return True
        
        return False
    
    def train_neural_network(self):
        """Train neural network from successful patterns"""
        if len(self.grid.cells) < 5:
            return
        
        successful_cells = [cell for cell in self.grid.cells.values() 
                          if cell.energy > 30 and cell.age > 5]
        
        X, y = [], []
        for cell in successful_cells[:25]:
            state = self.grid.get_grid_state(cell.x, cell.y)
            X.append(state[0])
            
            success_score = [
                min(cell.energy / MAX_CELL_ENERGY, 1.0),
                min(cell.age / 50, 1.0),
                len(cell.connections) / 8,
                1.0 if cell.cell_type in ['producer', 'hybrid', 'super'] else 0.6
            ]
            y.append(success_score)
        
        if X and y:
            X = np.array(X)
            y = np.array(y)
            self.neural_net.train(X, y)
    
    def evaluate_performance(self):
        """Enhanced performance evaluation"""
        stats = self.grid.get_detailed_stats()
        
        population_score = min(stats.get('total_cells', 0) / 30, 1.0)
        energy_score = min(stats.get('total_energy', 0) / 500, 1.0)
        diversity_score = min(len(stats.get('cell_types', {})) / 4, 1.0)
        threat_count = len(stats.get('threats', []))
        sustainability_score = max(0, 1.0 - (threat_count / 5))
        genetic_diversity_score = min(stats.get('genetic_diversity', 0), 1.0)
        
        self.performance_score = (
            population_score * 0.25 +
            energy_score * 0.20 +
            diversity_score * 0.25 +
            sustainability_score * 0.20 +
            genetic_diversity_score * 0.10
        )
        
        return self.performance_score
    
    def evolution_loop(self):
        """Main evolution loop running in background thread"""
        step_count = 0
        last_report_time = time.time()
        
        while self.running:
            if self.paused:
                time.sleep(0.1)
                continue
            
            # Check for user messages
            try:
                user_msg = self.user_messages.get_nowait()
                if user_msg == "QUIT":
                    self.running = False
                    break
                elif user_msg == "PAUSE":
                    self.paused = True
                    continue
                elif user_msg == "RESUME":
                    self.paused = False
                    continue
                else:
                    # Respond to user message
                    ai_response = self.send_conversational_message(user_msg)
                    self.ai_responses.put(f"🤖 AI: {ai_response}")
            except queue.Empty:
                pass
            
            # Evolution step
            decision = self.make_evolution_decision()
            self.execute_decision(decision)
            self.grid.step()
            
            # Handle extinction
            if self.grid.get_detailed_stats()['total_cells'] == 0:
                self.ai_responses.put("💀 AI: Oh no! My cellular world just went extinct! Let me reseed it...")
                for _ in range(6):
                    x, y = random.randint(0, GRID_SIZE-1), random.randint(0, GRID_SIZE-1)
                    self.grid.add_cell(x, y, 'producer')
                self.ai_responses.put("🌱 AI: There! New life emerging from the void.")
            
            # Neural network training
            if self.grid.generation % 5 == 0:
                self.train_neural_network()
            
            # Periodic reports
            current_time = time.time()
            if current_time - last_report_time > 10:  # Report every 10 seconds
                stats = self.grid.get_detailed_stats()
                performance = self.evaluate_performance()
                
                report = f"""📊 Evolution Report (Gen {stats['generation']}):
Population: {stats['total_cells']} | Energy: {stats['total_energy']:.1f} | Performance: {performance:.3f}
{self.grid.to_display_string()}"""
                
                self.ai_responses.put(report)
                
                # Sometimes add AI commentary
                if random.random() < 0.3:
                    commentary = self.send_conversational_message()
                    self.ai_responses.put(f"💭 AI: {commentary}")
                
                last_report_time = current_time
            
            step_count += 1
            time.sleep(1)  # 1 second per evolution step

def select_model():
    """Allow user to select model"""
    print("\n🧠 Select AI Model:")
    print("=" * 50)
    
    for key, model in AVAILABLE_MODELS.items():
        print(f"{key}. {model['name']}")
        print(f"   {model['description']}")
        print()
    
    while True:
        choice = input("Enter your choice (1-4): ").strip()
        if choice in AVAILABLE_MODELS:
            selected = AVAILABLE_MODELS[choice]
            print(f"\n✅ Selected: {selected['name']}")
            return selected
        print("❌ Invalid choice. Please enter 1, 2, 3, or 4.")

def user_input_handler(agent):
    """Handle user input in separate thread"""
    print("\n" + "="*60)
    print("💬 INTERACTIVE MODE ACTIVATED")
    print("="*60)
    print("Commands:")
    print("  - Type anything to chat with the AI")
    print("  - 'pause' to pause evolution")
    print("  - 'resume' to resume evolution")
    print("  - 'quit' to exit")
    print("="*60)
    
    while agent.running:
        try:
            user_input = input("\n🧑 You: ").strip()
            
            if user_input.lower() == 'quit':
                agent.user_messages.put("QUIT")
                break
            elif user_input.lower() == 'pause':
                agent.user_messages.put("PAUSE")
                print("⏸️  Evolution paused")
            elif user_input.lower() == 'resume':
                agent.user_messages.put("RESUME")
                print("▶️  Evolution resumed")
            elif user_input:
                agent.user_messages.put(user_input)
        except (EOFError, KeyboardInterrupt):
            agent.user_messages.put("QUIT")
            break

def ai_response_handler(agent):
    """Handle AI responses in separate thread"""
    while agent.running:
        try:
            response = agent.ai_responses.get(timeout=1)
            print(f"\n{response}")
        except queue.Empty:
            continue
        except KeyboardInterrupt:
            break

def main():
    """Main interactive application"""
    print("🧬 INTERACTIVE AI EVOLUTION GRID")
    print("=" * 60)
    
    # Set up API key
    api_key = os.getenv('GROQ_API_KEY')
    if not api_key:
        print("❌ Please set GROQ_API_KEY environment variable")
        print("   You can get one from: https://console.groq.com/keys")
        return
    
    # Model selection
    model_config = select_model()
    
    # Initialize interactive agent
    print(f"\n🧠 Initializing Interactive AI with {model_config['name']}...")
    agent = InteractiveAIEvolutionAgent(api_key, model_config)
    
    # Initial AI greeting
    greeting = agent.send_conversational_message()
    print(f"\n🤖 AI: {greeting}")
    
    # Start threads
    evolution_thread = threading.Thread(target=agent.evolution_loop, daemon=True)
    response_thread = threading.Thread(target=ai_response_handler, args=(agent,), daemon=True)
    
    evolution_thread.start()
    response_thread.start()
    
    # User interaction loop
    try:
        user_input_handler(agent)
    except KeyboardInterrupt:
        print("\n🛑 Shutting down...")
    
    agent.running = False
    print("\n👋 Thanks for chatting with the AI consciousness!")

if __name__ == "__main__":
    main()