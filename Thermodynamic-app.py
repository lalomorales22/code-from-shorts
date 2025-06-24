"""
Interactive Stochastic Circuit Simulator for Thermodynamic Computing

This application simulates and visualizes stochastic RLC circuits for thermodynamic computing applications,
inspired by Extropic's approach of leveraging noise as a computational resource rather than fighting against it.

It includes functionality for:
1. Real-time interactive circuit simulation with thermal noise
2. Animated visualization of circuit behavior and energy landscapes
3. Monte Carlo analysis with live visualization
4. Parameter tuning through an interactive GUI
5. Energy-based computation demonstrations

Author: Enhanced version of original code with Extropic-inspired additions
"""

import numpy as np
import matplotlib.pyplot as plt
import pandas as pd
import scipy.signal as signal
from scipy.integrate import solve_ivp
from multiprocessing import Pool, cpu_count
import time
from tqdm import tqdm
import seaborn as sns
from dataclasses import dataclass
import json
import os
from datetime import datetime
import argparse
import matplotlib.animation as animation
from matplotlib.widgets import Slider, Button, RadioButtons, CheckButtons
import matplotlib.gridspec as gridspec
from mpl_toolkits.mplot3d import Axes3D
import tkinter as tk
from tkinter import ttk
from matplotlib.backends.backend_tkagg import FigureCanvasTkAgg, NavigationToolbar2Tk
from matplotlib.figure import Figure
import threading
import queue
import colorsys
import random

# Set the style for the plots
plt.style.use('dark_background')
CMAP = plt.cm.viridis

@dataclass
class CircuitComponent:
    """Class for representing a circuit component with stochastic properties."""
    name: str
    nominal_value: float
    noise_amplitude: float
    temperature_coefficient: float
    type: str  # "R", "L", "C", "Source", or "Coupling"
    
    def get_value(self, temperature=300.0, seed=None):
        """
        Calculate the actual value with thermal noise and temperature effects.
        
        Args:
            temperature: Temperature in Kelvin
            seed: Random seed for reproducibility
            
        Returns:
            Actual component value with noise
        """
        if seed is not None:
            np.random.seed(seed)
            
        # Temperature effect
        temp_effect = 1.0 + self.temperature_coefficient * (temperature - 300.0) / 300.0
        
        # Thermal noise (white noise)
        noise = np.random.normal(0, self.noise_amplitude)
        
        return self.nominal_value * temp_effect * (1 + noise)

class StochasticCircuit:
    """Class representing a stochastic RLC circuit for thermodynamic computing."""
    
    def __init__(self, components=None, coupling_matrix=None, temperature=300.0):
        """
        Initialize a stochastic circuit.
        
        Args:
            components: Dictionary of CircuitComponent objects
            coupling_matrix: Matrix representing coupling between components
            temperature: Temperature in Kelvin
        """
        self.components = components or {}
        # Fix for NumPy array boolean ambiguity
        if coupling_matrix is None:
            self.coupling_matrix = np.array([[0.0]])
        else:
            self.coupling_matrix = coupling_matrix
        self.temperature = temperature
        self.simulation_results = None
        self.computation_state = {}
        self.energy_landscape = None
        self.energy_history = []
        self.state_history = []
        
    def add_component(self, component):
        """Add a component to the circuit."""
        self.components[component.name] = component
        
    def set_coupling(self, component1, component2, coupling_strength):
        """Set coupling strength between two components."""
        # Ensure coupling matrix is large enough
        n = len(self.components)
        if self.coupling_matrix.shape[0] < n:
            new_matrix = np.zeros((n, n))
            new_matrix[:self.coupling_matrix.shape[0], :self.coupling_matrix.shape[1]] = self.coupling_matrix
            self.coupling_matrix = new_matrix
        
        # Get indices for components
        comp_names = list(self.components.keys())
        i = comp_names.index(component1)
        j = comp_names.index(component2)
        
        # Set coupling strength
        self.coupling_matrix[i, j] = coupling_strength
        self.coupling_matrix[j, i] = coupling_strength  # Ensure symmetry
    
    def _rlc_dynamics(self, t, y, R, L, C, V_source, omega=0):
        """
        Define the dynamics of a simple RLC circuit.
        
        Args:
            t: Time
            y: State vector [current, voltage]
            R, L, C: Circuit parameters
            V_source: Source voltage
            omega: Frequency of source voltage (if AC)
            
        Returns:
            Derivatives of state variables
        """
        current, voltage = y
        
        # Source voltage (can be DC or AC)
        if omega > 0:
            source = V_source * np.sin(omega * t)
        else:
            source = V_source
        
        # Add thermal noise directly to the dynamics
        current_noise = np.random.normal(0, np.sqrt(2 * 1.38e-23 * self.temperature * R) / L)
        voltage_noise = np.random.normal(0, np.sqrt(2 * 1.38e-23 * self.temperature / C))
        
        # State derivatives
        d_current = (source - voltage - R * current) / L + current_noise
        d_voltage = current / C + voltage_noise
        
        return [d_current, d_voltage]
    
    def _energy_function(self, current, voltage, R, L, C, V_source):
        """
        Calculate the energy of the circuit at a given state.
        
        Args:
            current: Current through the circuit
            voltage: Voltage across the capacitor
            R, L, C: Circuit parameters
            V_source: Source voltage
            
        Returns:
            Energy value
        """
        # Energy stored in inductor
        inductor_energy = 0.5 * L * current**2
        
        # Energy stored in capacitor
        capacitor_energy = 0.5 * C * voltage**2
        
        # Energy dissipated in resistor (over a small time period)
        dissipated_energy = R * current**2 * 0.001
        
        # Energy provided by source
        source_energy = current * V_source * 0.001
        
        # Total energy
        total_energy = inductor_energy + capacitor_energy + dissipated_energy - source_energy
        
        return total_energy
    
    def _update_energy_landscape(self, current_range, voltage_range, R, L, C, V_source):
        """
        Calculate the energy landscape for visualization.
        
        Args:
            current_range: Range of current values
            voltage_range: Range of voltage values
            R, L, C: Circuit parameters
            V_source: Source voltage
            
        Returns:
            3D grid of energy values
        """
        # Create meshgrid
        current_mesh, voltage_mesh = np.meshgrid(current_range, voltage_range)
        energy_mesh = np.zeros_like(current_mesh)
        
        # Calculate energy at each point
        for i in range(len(current_range)):
            for j in range(len(voltage_range)):
                energy_mesh[j, i] = self._energy_function(
                    current_range[i], voltage_range[j], R, L, C, V_source)
        
        self.energy_landscape = (current_mesh, voltage_mesh, energy_mesh)
        return self.energy_landscape
    
    def simulate(self, duration=1.0, dt=0.001, initial_conditions=None, seed=None, capture_energy=False):
        """
        Simulate the circuit over time.
        
        Args:
            duration: Simulation duration in seconds
            dt: Time step in seconds
            initial_conditions: Initial values for state variables
            seed: Random seed for reproducibility
            capture_energy: Flag to capture energy at each step
            
        Returns:
            DataFrame with simulation results
        """
        if seed is not None:
            np.random.seed(seed)
        
        # Get component values with thermal noise
        resistors = [comp for comp in self.components.values() if comp.type == "R"]
        inductors = [comp for comp in self.components.values() if comp.type == "L"]
        capacitors = [comp for comp in self.components.values() if comp.type == "C"]
        sources = [comp for comp in self.components.values() if comp.type == "Source"]
        
        if not (resistors and inductors and capacitors and sources):
            raise ValueError("Circuit must have at least one resistor, inductor, capacitor, and source")
        
        # For simplicity, we'll use the first component of each type
        R = resistors[0].get_value(self.temperature)
        L = inductors[0].get_value(self.temperature)
        C = capacitors[0].get_value(self.temperature)
        V = sources[0].get_value(self.temperature)
        
        # Default initial conditions (zero current, zero voltage)
        if initial_conditions is None:
            initial_conditions = [0.0, 0.0]
        
        # Time points
        t_eval = np.arange(0, duration, dt)
        
        # Solve the ODE
        sol = solve_ivp(
            lambda t, y: self._rlc_dynamics(t, y, R, L, C, V),
            [0, duration],
            initial_conditions,
            t_eval=t_eval,
            method='RK45'
        )
        
        # Create DataFrame with results
        results = pd.DataFrame({
            'time': sol.t,
            'current': sol.y[0],
            'voltage': sol.y[1]
        })
        
        # Calculate and store energy if requested
        if capture_energy:
            energy = np.zeros_like(sol.t)
            for i in range(len(sol.t)):
                energy[i] = self._energy_function(
                    sol.y[0][i], sol.y[1][i], R, L, C, V)
            results['energy'] = energy
            
            # Store a subset of state and energy history for visualization
            subsample = max(1, len(sol.t) // 100)
            self.state_history.append(np.vstack([sol.y[0][::subsample], sol.y[1][::subsample]]).T)
            self.energy_history.append(energy[::subsample])
        
        self.simulation_results = results
        return results
    
    def simulate_real_time(self, duration=1.0, dt=0.001, initial_conditions=None, callback=None):
        """
        Simulate the circuit in real-time with callback for visualization.
        
        Args:
            duration: Simulation duration in seconds
            dt: Time step in seconds
            initial_conditions: Initial values for state variables
            callback: Function to call with current state at each step
            
        Returns:
            DataFrame with simulation results
        """
        # Get component values with thermal noise
        resistors = [comp for comp in self.components.values() if comp.type == "R"]
        inductors = [comp for comp in self.components.values() if comp.type == "L"]
        capacitors = [comp for comp in self.components.values() if comp.type == "C"]
        sources = [comp for comp in self.components.values() if comp.type == "Source"]
        
        if not (resistors and inductors and capacitors and sources):
            raise ValueError("Circuit must have at least one resistor, inductor, capacitor, and source")
        
        # For simplicity, we'll use the first component of each type
        R = resistors[0].get_value(self.temperature)
        L = inductors[0].get_value(self.temperature)
        C = capacitors[0].get_value(self.temperature)
        V = sources[0].get_value(self.temperature)
        
        # Default initial conditions (zero current, zero voltage)
        if initial_conditions is None:
            initial_conditions = [0.0, 0.0]
        
        # Initialize state and results
        current, voltage = initial_conditions
        t = 0.0
        times, currents, voltages, energies = [], [], [], []
        
        # Simulate step by step
        while t < duration:
            # Calculate derivatives
            d_current, d_voltage = self._rlc_dynamics(t, [current, voltage], R, L, C, V)
            
            # Update state using Euler integration
            current += d_current * dt
            voltage += d_voltage * dt
            
            # Calculate energy
            energy = self._energy_function(current, voltage, R, L, C, V)
            
            # Store results
            times.append(t)
            currents.append(current)
            voltages.append(voltage)
            energies.append(energy)
            
            # Call callback if provided
            if callback:
                callback(t, current, voltage, energy)
            
            # Increment time
            t += dt
        
        # Create and store results
        results = pd.DataFrame({
            'time': times,
            'current': currents,
            'voltage': voltages,
            'energy': energies
        })
        self.simulation_results = results
        return results
    
    def perform_thermodynamic_computation(self, problem_type, params=None):
        """
        Perform a computation using the thermodynamic properties of the circuit.
        
        Args:
            problem_type: Type of computation to perform ('optimize', 'sample', 'solve')
            params: Dictionary with problem-specific parameters
            
        Returns:
            Dictionary with computation results
        """
        if problem_type == 'optimize':
            return self._perform_optimization(params)
        elif problem_type == 'sample':
            return self._perform_sampling(params)
        elif problem_type == 'solve':
            return self._perform_equation_solving(params)
        else:
            raise ValueError(f"Unknown problem type: {problem_type}")
    
    def _perform_optimization(self, params):
        """Perform optimization using thermodynamic annealing."""
        # Extract parameters
        objective_function = params.get('objective_function', lambda x: np.sum(x**2))
        dimensions = params.get('dimensions', 2)
        iterations = params.get('iterations', 1000)
        cooling_schedule = params.get('cooling_schedule', lambda t, T0: T0 * (0.99**t))
        initial_temp = params.get('initial_temp', 1000.0)
        
        # Initialize
        current_solution = np.random.normal(0, 1, dimensions)
        current_energy = objective_function(current_solution)
        best_solution = current_solution.copy()
        best_energy = current_energy
        
        # Initialize result tracking
        temps = []
        energies = []
        solutions = []
        
        # Annealing process
        for i in range(iterations):
            # Update temperature
            temp = cooling_schedule(i, initial_temp)
            temps.append(temp)
            
            # Generate new candidate solution
            noise_scale = np.sqrt(temp / initial_temp)
            candidate = current_solution + np.random.normal(0, noise_scale, dimensions)
            candidate_energy = objective_function(candidate)
            
            # Accept or reject
            delta_e = candidate_energy - current_energy
            if delta_e < 0 or np.random.random() < np.exp(-delta_e / temp):
                current_solution = candidate
                current_energy = candidate_energy
                
                # Update best solution
                if current_energy < best_energy:
                    best_solution = current_solution.copy()
                    best_energy = current_energy
            
            # Store results
            energies.append(current_energy)
            solutions.append(current_solution.copy())
        
        # Store computation state
        self.computation_state = {
            'problem': 'optimize',
            'temperatures': temps,
            'energies': energies,
            'solutions': solutions,
            'best_solution': best_solution,
            'best_energy': best_energy
        }
        
        return {
            'best_solution': best_solution,
            'best_energy': best_energy,
            'energy_trajectory': energies,
            'temperature_trajectory': temps,
            'solution_trajectory': solutions
        }
    
    def _perform_sampling(self, params):
        """Sample from a distribution using thermodynamic principles."""
        # Extract parameters
        target_distribution = params.get('distribution', lambda x: np.exp(-np.sum(x**2)))
        dimensions = params.get('dimensions', 2)
        iterations = params.get('iterations', 5000)
        burn_in = params.get('burn_in', 1000)
        step_size = params.get('step_size', 0.1)
        
        # Initialize
        current_state = np.random.normal(0, 1, dimensions)
        current_density = target_distribution(current_state)
        
        # Initialize result tracking
        samples = []
        densities = []
        
        # MCMC sampling
        for i in range(iterations):
            # Generate proposal
            proposal = current_state + np.random.normal(0, step_size, dimensions)
            proposal_density = target_distribution(proposal)
            
            # Accept or reject
            acceptance_ratio = proposal_density / current_density
            if np.random.random() < acceptance_ratio:
                current_state = proposal
                current_density = proposal_density
            
            # Store sample (after burn-in)
            if i >= burn_in:
                samples.append(current_state.copy())
                densities.append(current_density)
        
        # Convert to numpy arrays
        samples = np.array(samples)
        densities = np.array(densities)
        
        # Store computation state
        self.computation_state = {
            'problem': 'sample',
            'samples': samples,
            'densities': densities
        }
        
        return {
            'samples': samples,
            'densities': densities,
            'mean': np.mean(samples, axis=0),
            'std': np.std(samples, axis=0)
        }
    
    def _perform_equation_solving(self, params):
        """Solve equations using thermodynamic fluctuations."""
        # Extract parameters
        equation = params.get('equation', lambda x: np.array([x[0]**2 + x[1]**2 - 1, x[0] - x[1]]))
        dimensions = params.get('dimensions', 2)
        iterations = params.get('iterations', 1000)
        cooling_schedule = params.get('cooling_schedule', lambda t, T0: T0 * (0.99**t))
        initial_temp = params.get('initial_temp', 100.0)
        
        # Initialize
        current_solution = np.random.normal(0, 1, dimensions)
        current_residual = equation(current_solution)
        current_energy = np.sum(current_residual**2)
        best_solution = current_solution.copy()
        best_energy = current_energy
        
        # Initialize result tracking
        temps = []
        energies = []
        solutions = []
        
        # Solution process
        for i in range(iterations):
            # Update temperature
            temp = cooling_schedule(i, initial_temp)
            temps.append(temp)
            
            # Generate new candidate solution
            noise_scale = np.sqrt(temp / initial_temp)
            candidate = current_solution + np.random.normal(0, noise_scale, dimensions)
            candidate_residual = equation(candidate)
            candidate_energy = np.sum(candidate_residual**2)
            
            # Accept or reject
            delta_e = candidate_energy - current_energy
            if delta_e < 0 or np.random.random() < np.exp(-delta_e / temp):
                current_solution = candidate
                current_energy = candidate_energy
                
                # Update best solution
                if current_energy < best_energy:
                    best_solution = current_solution.copy()
                    best_energy = current_energy
            
            # Store results
            energies.append(current_energy)
            solutions.append(current_solution.copy())
        
        # Store computation state
        self.computation_state = {
            'problem': 'solve',
            'temperatures': temps,
            'energies': energies,
            'solutions': solutions,
            'best_solution': best_solution,
            'best_energy': best_energy
        }
        
        return {
            'solution': best_solution,
            'residual_energy': best_energy,
            'energy_trajectory': energies,
            'solution_trajectory': solutions
        }
    
    def calibrate(self, target_current=1.0, target_voltage=5.0, tolerance=0.1, max_iterations=100):
        """
        Calibrate the circuit to achieve target current and voltage.
        
        Args:
            target_current: Target current in amperes
            target_voltage: Target voltage in volts
            tolerance: Acceptable error margin
            max_iterations: Maximum number of calibration iterations
            
        Returns:
            Dictionary with calibration results
        """
        results = {'success': False, 'iterations': 0, 'adjustments': []}
        
        for i in range(max_iterations):
            # Simulate with current parameters
            self.simulate(duration=0.1, dt=0.001)
            
            # Calculate average values from the steady state (last 20% of simulation)
            n = len(self.simulation_results)
            steady_state = self.simulation_results.iloc[int(0.8 * n):]
            avg_current = steady_state['current'].mean()
            avg_voltage = steady_state['voltage'].mean()
            
            # Calculate errors
            current_error = (avg_current - target_current) / target_current
            voltage_error = (avg_voltage - target_voltage) / target_voltage
            
            # Check if within tolerance
            if abs(current_error) < tolerance and abs(voltage_error) < tolerance:
                results['success'] = True
                results['iterations'] = i + 1
                break
                
            # Adjust parameters (simple proportional control)
            resistors = [comp for comp in self.components.values() if comp.type == "R"]
            sources = [comp for comp in self.components.values() if comp.type == "Source"]
            
            # Adjust resistance to control current
            if resistors:
                R_old = resistors[0].nominal_value
                R_new = R_old * (1 + 0.5 * current_error)  # Increase R if current is too high
                resistors[0].nominal_value = R_new
                
                adjustment = {
                    'component': resistors[0].name,
                    'old_value': R_old,
                    'new_value': R_new
                }
                results['adjustments'].append(adjustment)
            
            # Adjust source to control voltage
            if sources:
                V_old = sources[0].nominal_value
                V_new = V_old * (1 + 0.5 * voltage_error)  # Increase V if voltage is too low
                sources[0].nominal_value = V_new
                
                adjustment = {
                    'component': sources[0].name,
                    'old_value': V_old,
                    'new_value': V_new
                }
                results['adjustments'].append(adjustment)
        
        results['final_values'] = {
            'current': avg_current,
            'voltage': avg_voltage
        }
        
        return results
    
    def characterize(self, param_name, param_range, repeats=5, duration=0.1):
        """
        Characterize circuit behavior by varying a parameter.
        
        Args:
            param_name: Name of parameter to vary
            param_range: Range of values to test
            repeats: Number of repetitions for statistical significance
            duration: Simulation duration for each test
            
        Returns:
            DataFrame with characterization results
        """
        results = []
        
        # Check if parameter exists
        if param_name not in self.components:
            raise ValueError(f"Parameter {param_name} not found in circuit components")
        
        # Store original value
        original_value = self.components[param_name].nominal_value
        
        # Run simulations with different parameter values
        for value in tqdm(param_range, desc=f"Characterizing {param_name}"):
            for rep in range(repeats):
                # Set parameter value
                self.components[param_name].nominal_value = value
                
                # Run simulation
                sim_results = self.simulate(duration=duration, dt=0.001, seed=rep)
                
                # Calculate statistics
                avg_current = sim_results['current'].mean()
                std_current = sim_results['current'].std()
                avg_voltage = sim_results['voltage'].mean()
                std_voltage = sim_results['voltage'].std()
                
                # Power metrics
                power = sim_results['current'] * sim_results['voltage']
                avg_power = power.mean()
                std_power = power.std()
                
                # Add to results
                results.append({
                    'param_name': param_name,
                    'param_value': value,
                    'repetition': rep,
                    'avg_current': avg_current,
                    'std_current': std_current,
                    'avg_voltage': avg_voltage,
                    'std_voltage': std_voltage,
                    'avg_power': avg_power,
                    'std_power': std_power
                })
        
        # Restore original value
        self.components[param_name].nominal_value = original_value
        
        return pd.DataFrame(results)
    
    def analyze_noise(self, duration=1.0, dt=0.0001):
        """
        Analyze the circuit's noise characteristics.
        
        Args:
            duration: Simulation duration in seconds
            dt: Time step in seconds
            
        Returns:
            Dictionary with noise analysis results
        """
        # Run a high-resolution simulation
        results = self.simulate(duration=duration, dt=dt)
        
        # Calculate noise metrics
        current_mean = results['current'].mean()
        current_std = results['current'].std()
        voltage_mean = results['voltage'].mean()
        voltage_std = results['voltage'].std()
        
        # Calculate SNR (Signal-to-Noise Ratio)
        current_snr = abs(current_mean) / current_std if current_std > 0 else float('inf')
        voltage_snr = abs(voltage_mean) / voltage_std if voltage_std > 0 else float('inf')
        
        # Perform frequency domain analysis
        fs = 1/dt  # Sampling frequency
        
        # Current spectrum
        current_psd = signal.welch(results['current'], fs, nperseg=1024)
        
        # Voltage spectrum
        voltage_psd = signal.welch(results['voltage'], fs, nperseg=1024)
        
        # Return results
        return {
            'time_domain': {
                'current_mean': current_mean,
                'current_std': current_std,
                'voltage_mean': voltage_mean,
                'voltage_std': voltage_std,
                'current_snr_db': 20 * np.log10(current_snr),
                'voltage_snr_db': 20 * np.log10(voltage_snr)
            },
            'frequency_domain': {
                'frequencies': current_psd[0],
                'current_psd': current_psd[1],
                'voltage_psd': voltage_psd[1]
            }
        }

    def plot_results(self, figsize=(12, 10)):
        """
        Plot simulation results.
        
        Args:
            figsize: Figure size for the plot
            
        Returns:
            Matplotlib figure
        """
        if self.simulation_results is None:
            raise ValueError("No simulation results available. Run simulate() first.")
        
        fig, axes = plt.subplots(4, 1, figsize=figsize, sharex=True)
        
        # Time domain plots
        axes[0].plot(self.simulation_results['time'], self.simulation_results['current'], color='#00FFAA')
        axes[0].set_ylabel('Current (A)')
        axes[0].set_title('Circuit Simulation Results', fontsize=16)
        axes[0].grid(True, alpha=0.3)
        
        axes[1].plot(self.simulation_results['time'], self.simulation_results['voltage'], color='#FF00AA')
        axes[1].set_ylabel('Voltage (V)')
        axes[1].grid(True, alpha=0.3)
        
        # Calculate and plot power
        power = self.simulation_results['current'] * self.simulation_results['voltage']
        axes[2].plot(self.simulation_results['time'], power, color='#FFAA00')
        axes[2].set_ylabel('Power (W)')
        axes[2].grid(True, alpha=0.3)
        
        # Calculate and plot energy
        dt = self.simulation_results['time'].diff().mean()
        energy = np.cumsum(power * dt)
        axes[3].plot(self.simulation_results['time'], energy, color='#00AAFF')
        axes[3].set_xlabel('Time (s)')
        axes[3].set_ylabel('Energy (J)')
        axes[3].grid(True, alpha=0.3)
        
        # Add a subtle background gradient
        for ax in axes:
            ax.set_facecolor('#0F0F1F')
        
        fig.patch.set_facecolor('#0A0A1A')
        plt.tight_layout()
        return fig
    
    def plot_state_space(self, figsize=(10, 8)):
        """
        Plot the circuit state space with energy landscape.
        
        Args:
            figsize: Figure size for the plot
            
        Returns:
            Matplotlib figure
        """
        if self.simulation_results is None:
            raise ValueError("No simulation results available. Run simulate() first.")
        
        # Create figure
        fig = plt.figure(figsize=figsize)
        ax = fig.add_subplot(111, projection='3d')
        
        # Get component values
        resistors = [comp for comp in self.components.values() if comp.type == "R"]
        inductors = [comp for comp in self.components.values() if comp.type == "L"]
        capacitors = [comp for comp in self.components.values() if comp.type == "C"]
        sources = [comp for comp in self.components.values() if comp.type == "Source"]
        
        R = resistors[0].nominal_value
        L = inductors[0].nominal_value
        C = capacitors[0].nominal_value
        V = sources[0].nominal_value
        
        # Define current and voltage ranges
        current_range = np.linspace(
            self.simulation_results['current'].min() * 1.5,
            self.simulation_results['current'].max() * 1.5,
            50
        )
        voltage_range = np.linspace(
            self.simulation_results['voltage'].min() * 1.5,
            self.simulation_results['voltage'].max() * 1.5,
            50
        )
        
        # Calculate energy landscape
        current_mesh, voltage_mesh, energy_mesh = self._update_energy_landscape(
            current_range, voltage_range, R, L, C, V)
        
        # Plot energy landscape
        surf = ax.plot_surface(
            current_mesh, voltage_mesh, energy_mesh, 
            cmap=CMAP, alpha=0.7, edgecolor='none'
        )
        
        # Plot trajectory
        trajectory = ax.plot(
            self.simulation_results['current'],
            self.simulation_results['voltage'],
            np.zeros_like(self.simulation_results['current']),
            color='white', linewidth=2, alpha=0.8
        )
        
        # Calculate energy along trajectory
        trajectory_energy = np.zeros_like(self.simulation_results['current'])
        for i in range(len(self.simulation_results)):
            trajectory_energy[i] = self._energy_function(
                self.simulation_results['current'][i],
                self.simulation_results['voltage'][i],
                R, L, C, V
            )
        
        # Plot trajectory with energy
        energy_trajectory = ax.plot(
            self.simulation_results['current'],
            self.simulation_results['voltage'],
            trajectory_energy,
            color='red', linewidth=2, alpha=0.8
        )
        
        # Add colorbar
        cbar = fig.colorbar(surf, ax=ax, shrink=0.6, aspect=10)
        cbar.set_label('Energy (J)')
        
        # Set labels and title
        ax.set_xlabel('Current (A)')
        ax.set_ylabel('Voltage (V)')
        ax.set_zlabel('Energy (J)')
        ax.set_title('Circuit State Space and Energy Landscape', fontsize=16)
        
        # Set background color
        ax.set_facecolor('#0F0F1F')
        fig.patch.set_facecolor('#0A0A1A')
        
        return fig
    
    def animate_state_space(self, interval=50, frames=100, figsize=(10, 8)):
        """
        Create an animation of the circuit in state space.
        
        Args:
            interval: Time between frames in milliseconds
            frames: Number of frames in animation
            figsize: Figure size
            
        Returns:
            Matplotlib animation
        """
        if self.simulation_results is None:
            raise ValueError("No simulation results available. Run simulate() first.")
        
        # Create figure
        fig = plt.figure(figsize=figsize)
        ax = fig.add_subplot(111, projection='3d')
        
        # Get component values
        resistors = [comp for comp in self.components.values() if comp.type == "R"]
        inductors = [comp for comp in self.components.values() if comp.type == "L"]
        capacitors = [comp for comp in self.components.values() if comp.type == "C"]
        sources = [comp for comp in self.components.values() if comp.type == "Source"]
        
        R = resistors[0].nominal_value
        L = inductors[0].nominal_value
        C = capacitors[0].nominal_value
        V = sources[0].nominal_value
        
        # Define current and voltage ranges
        current_range = np.linspace(
            self.simulation_results['current'].min() * 1.5,
            self.simulation_results['current'].max() * 1.5,
            30
        )
        voltage_range = np.linspace(
            self.simulation_results['voltage'].min() * 1.5,
            self.simulation_results['voltage'].max() * 1.5,
            30
        )
        
        # Calculate energy landscape
        current_mesh, voltage_mesh, energy_mesh = self._update_energy_landscape(
            current_range, voltage_range, R, L, C, V)
        
        # Plot energy landscape
        surf = ax.plot_surface(
            current_mesh, voltage_mesh, energy_mesh, 
            cmap=CMAP, alpha=0.5, edgecolor='none'
        )
        
        # Initialize trajectory line
        line, = ax.plot([], [], [], color='white', linewidth=2, alpha=0.8)
        
        # Initialize energy trajectory line
        energy_line, = ax.plot([], [], [], color='red', linewidth=2, alpha=0.8)
        
        # Initialize point marker
        point = ax.scatter([], [], [], color='cyan', s=100, edgecolor='white')
        
        # Set labels and title
        ax.set_xlabel('Current (A)')
        ax.set_ylabel('Voltage (V)')
        ax.set_zlabel('Energy (J)')
        ax.set_title('Circuit State Space and Energy Landscape Animation', fontsize=16)
        
        # Set background color
        ax.set_facecolor('#0F0F1F')
        fig.patch.set_facecolor('#0A0A1A')
        
        # Set axis limits
        ax.set_xlim([current_range.min(), current_range.max()])
        ax.set_ylim([voltage_range.min(), voltage_range.max()])
        ax.set_zlim([energy_mesh.min(), energy_mesh.max() * 1.2])
        
        # Calculate trajectory with energy
        trajectory_energy = np.zeros_like(self.simulation_results['current'])
        for i in range(len(self.simulation_results)):
            trajectory_energy[i] = self._energy_function(
                self.simulation_results['current'][i],
                self.simulation_results['voltage'][i],
                R, L, C, V
            )
        
        # Animation function
        def animate(i):
            # Calculate frame index for data
            data_idx = min(int(i * len(self.simulation_results) / frames), len(self.simulation_results) - 1)
            
            # Update trajectory line
            line.set_data(
                self.simulation_results['current'][:data_idx],
                self.simulation_results['voltage'][:data_idx]
            )
            line.set_3d_properties(np.zeros(data_idx))
            
            # Update energy trajectory line
            energy_line.set_data(
                self.simulation_results['current'][:data_idx],
                self.simulation_results['voltage'][:data_idx]
            )
            energy_line.set_3d_properties(trajectory_energy[:data_idx])
            
            # Update point
            point._offsets3d = (
                [self.simulation_results['current'][data_idx]],
                [self.simulation_results['voltage'][data_idx]],
                [trajectory_energy[data_idx]]
            )
            
            # Rotate view for dynamic effect
            ax.view_init(elev=30, azim=i / 2)
            
            return line, energy_line, point
        
        # Create animation
        anim = animation.FuncAnimation(
            fig, animate, frames=frames, interval=interval, blit=True
        )
        
        return anim
    
    def plot_thermodynamic_computation(self, figsize=(12, 10)):
        """
        Plot results from thermodynamic computation.
        
        Args:
            figsize: Figure size for the plot
            
        Returns:
            Matplotlib figure
        """
        if not self.computation_state:
            raise ValueError("No computation results available. Run perform_thermodynamic_computation() first.")
        
        problem = self.computation_state.get('problem')
        
        if problem == 'optimize' or problem == 'solve':
            # Setup figure
            fig = plt.figure(figsize=figsize)
            gs = gridspec.GridSpec(2, 2)
            
            # Energy trajectory plot
            ax1 = fig.add_subplot(gs[0, 0])
            ax1.plot(self.computation_state['energies'], color='#00FFAA')
            ax1.set_xlabel('Iteration')
            ax1.set_ylabel('Energy')
            ax1.set_title('Energy Trajectory')
            ax1.grid(True, alpha=0.3)
            ax1.set_facecolor('#0F0F1F')
            
            # Temperature plot
            ax2 = fig.add_subplot(gs[0, 1])
            ax2.plot(self.computation_state['temperatures'], color='#FF00AA')
            ax2.set_xlabel('Iteration')
            ax2.set_ylabel('Temperature')
            ax2.set_title('Temperature Schedule')
            ax2.grid(True, alpha=0.3)
            ax2.set_facecolor('#0F0F1F')
            
            # Solution trajectory
            ax3 = fig.add_subplot(gs[1, :])
            solutions = np.array(self.computation_state['solutions'])
            for i in range(solutions.shape[1]):
                ax3.plot(solutions[:, i], label=f'Dim {i+1}')
            ax3.set_xlabel('Iteration')
            ax3.set_ylabel('Value')
            ax3.set_title('Solution Trajectory')
            ax3.grid(True, alpha=0.3)
            ax3.legend()
            ax3.set_facecolor('#0F0F1F')
            
        elif problem == 'sample':
            # Setup figure
            fig = plt.figure(figsize=figsize)
            samples = self.computation_state['samples']
            
            # For 1D or 2D samples, plot directly
            if samples.shape[1] == 1:
                ax = fig.add_subplot(111)
                ax.hist(samples, bins=50, alpha=0.7, color='#00FFAA')
                ax.set_xlabel('Value')
                ax.set_ylabel('Frequency')
                ax.set_title('Distribution Sampling Results')
                ax.grid(True, alpha=0.3)
                ax.set_facecolor('#0F0F1F')
                
            elif samples.shape[1] == 2:
                ax = fig.add_subplot(111)
                ax.scatter(samples[:, 0], samples[:, 1], alpha=0.5, s=10, c=self.computation_state['densities'], cmap=CMAP)
                ax.set_xlabel('Dimension 1')
                ax.set_ylabel('Dimension 2')
                ax.set_title('Distribution Sampling Results')
                ax.grid(True, alpha=0.3)
                ax.set_facecolor('#0F0F1F')
                
                # Add colorbar
                cbar = fig.colorbar(ax.collections[0], ax=ax)
                cbar.set_label('Probability Density')
                
            else:
                # For higher dimensions, use PCA or plot marginals
                ax1 = fig.add_subplot(221)
                ax1.hist(samples[:, 0], bins=30, alpha=0.7, color='#00FFAA')
                ax1.set_xlabel('Dimension 1')
                ax1.set_ylabel('Frequency')
                ax1.set_title('Marginal Distribution: Dim 1')
                ax1.grid(True, alpha=0.3)
                ax1.set_facecolor('#0F0F1F')
                
                ax2 = fig.add_subplot(222)
                ax2.hist(samples[:, 1], bins=30, alpha=0.7, color='#FF00AA')
                ax2.set_xlabel('Dimension 2')
                ax2.set_ylabel('Frequency')
                ax2.set_title('Marginal Distribution: Dim 2')
                ax2.grid(True, alpha=0.3)
                ax2.set_facecolor('#0F0F1F')
                
                ax3 = fig.add_subplot(223)
                ax3.scatter(samples[:, 0], samples[:, 1], alpha=0.5, s=10, c=self.computation_state['densities'], cmap=CMAP)
                ax3.set_xlabel('Dimension 1')
                ax3.set_ylabel('Dimension 2')
                ax3.set_title('Bivariate Distribution: Dim 1 vs Dim 2')
                ax3.grid(True, alpha=0.3)
                ax3.set_facecolor('#0F0F1F')
                
                ax4 = fig.add_subplot(224)
                if samples.shape[1] > 2:
                    ax4.scatter(samples[:, 0], samples[:, 2], alpha=0.5, s=10, c=self.computation_state['densities'], cmap=CMAP)
                    ax4.set_xlabel('Dimension 1')
                    ax4.set_ylabel('Dimension 3')
                    ax4.set_title('Bivariate Distribution: Dim 1 vs Dim 3')
                else:
                    ax4.text(0.5, 0.5, 'Higher dimensions not available', 
                            ha='center', va='center', transform=ax4.transAxes)
                ax4.grid(True, alpha=0.3)
                ax4.set_facecolor('#0F0F1F')
        
        # Set background color
        fig.patch.set_facecolor('#0A0A1A')
        plt.tight_layout()
        
        return fig
    
    def save_results(self, filename, include_plots=True):
        """
        Save simulation results and optionally plots.
        
        Args:
            filename: Base filename for saving
            include_plots: Whether to save plots
            
        Returns:
            Dictionary with saved file paths
        """
        saved_files = {}
        
        # Create directory if it doesn't exist
        os.makedirs(os.path.dirname(filename) if os.path.dirname(filename) else '.', exist_ok=True)
        
        # Save simulation results
        if self.simulation_results is not None:
            csv_path = f"{filename}_results.csv"
            self.simulation_results.to_csv(csv_path, index=False)
            saved_files['csv'] = csv_path
            
            # Save circuit configuration
            config = {
                'temperature': self.temperature,
                'components': {name: {
                    'type': comp.type,
                    'nominal_value': comp.nominal_value,
                    'noise_amplitude': comp.noise_amplitude,
                    'temperature_coefficient': comp.temperature_coefficient
                } for name, comp in self.components.items()},
                'coupling_matrix': self.coupling_matrix.tolist()
            }
            
            json_path = f"{filename}_config.json"
            with open(json_path, 'w') as f:
                json.dump(config, f, indent=2)
            saved_files['json'] = json_path
            
            # Save plots
            if include_plots:
                # Regular plot
                fig = self.plot_results()
                png_path = f"{filename}_plot.png"
                fig.savefig(png_path, dpi=300)
                plt.close(fig)
                saved_files['png'] = png_path
                
                # State space plot
                try:
                    fig_state = self.plot_state_space()
                    state_png_path = f"{filename}_state_space.png"
                    fig_state.savefig(state_png_path, dpi=300)
                    plt.close(fig_state)
                    saved_files['state_png'] = state_png_path
                except Exception as e:
                    print(f"Error saving state space plot: {e}")
        
        return saved_files


class HPC_Simulator:
    """Class for running stochastic circuit simulations on HPC systems."""
    
    def __init__(self, base_circuit, num_processes=None):
        """
        Initialize the HPC simulator.
        
        Args:
            base_circuit: Base StochasticCircuit to use as a template
            num_processes: Number of parallel processes to use
        """
        self.base_circuit = base_circuit
        self.num_processes = num_processes or cpu_count()
    
    def _run_simulation(self, params):
        """
        Worker function to run a single simulation.
        
        Args:
            params: Dictionary with simulation parameters
            
        Returns:
            Dictionary with simulation results
        """
        # Create a copy of the base circuit
        circuit = StochasticCircuit(
            components={name: CircuitComponent(
                name=comp.name,
                nominal_value=comp.nominal_value,
                noise_amplitude=comp.noise_amplitude,
                temperature_coefficient=comp.temperature_coefficient,
                type=comp.type
            ) for name, comp in self.base_circuit.components.items()},
            coupling_matrix=self.base_circuit.coupling_matrix.copy(),
            temperature=params.get('temperature', self.base_circuit.temperature)
        )
        
        # Apply parameter modifications
        for param_name, param_value in params.get('modifications', {}).items():
            if param_name in circuit.components:
                circuit.components[param_name].nominal_value = param_value
        
        # Run simulation
        try:
            results = circuit.simulate(
                duration=params.get('duration', 1.0),
                dt=params.get('dt', 0.001),
                seed=params.get('seed', None)
            )
            
            # Calculate metrics
            current_mean = float(results['current'].mean())
            current_std = float(results['current'].std())
            voltage_mean = float(results['voltage'].mean())
            voltage_std = float(results['voltage'].std())
            
            # Calculate SNR, handling any potential division by zero
            if current_std > 0:
                snr_current = abs(current_mean) / current_std
            else:
                snr_current = float('inf')
                
            if voltage_std > 0:
                snr_voltage = abs(voltage_mean) / voltage_std
            else:
                snr_voltage = float('inf')
            
            return {
                'params': params,
                'metrics': {
                    'current_mean': current_mean,
                    'current_std': current_std,
                    'voltage_mean': voltage_mean,
                    'voltage_std': voltage_std,
                    'snr_current': snr_current,
                    'snr_voltage': snr_voltage
                }
            }
            
        except Exception as e:
            # Handle any simulation errors
            print(f"Simulation error with parameters {params}: {e}")
            # Return default metrics
            return {
                'params': params,
                'metrics': {
                    'current_mean': 0.0,
                    'current_std': 0.0,
                    'voltage_mean': 0.0,
                    'voltage_std': 0.0,
                    'snr_current': 0.0,
                    'snr_voltage': 0.0
                }
            }
    
    def parameter_sweep(self, parameter_grid, duration=1.0, dt=0.001, repeats=3):
        """
        Perform a parameter sweep using parallel processing.
        
        Args:
            parameter_grid: Dictionary mapping parameter names to lists of values
            duration: Simulation duration in seconds
            dt: Time step in seconds
            repeats: Number of repetitions for each parameter combination
            
        Returns:
            DataFrame with parameter sweep results
        """
        # Generate all parameter combinations
        param_names = list(parameter_grid.keys())
        param_values = list(parameter_grid.values())
        
        # List to store all parameter combinations
        all_params = []
        
        # Helper function to generate all combinations
        def generate_combinations(index, current_mods):
            if index == len(param_names):
                # Base parameters
                params = {
                    'duration': duration,
                    'dt': dt,
                    'modifications': current_mods.copy()
                }
                
                # Add multiple repetitions with different seeds
                for rep in range(repeats):
                    rep_params = params.copy()
                    rep_params['seed'] = rep
                    rep_params['repetition'] = rep
                    all_params.append(rep_params)
                return
            
            for value in param_values[index]:
                current_mods[param_names[index]] = value
                generate_combinations(index + 1, current_mods)
        
        # Generate all combinations
        generate_combinations(0, {})
        
        # Run simulations in parallel
        print(f"Running {len(all_params)} simulations using {self.num_processes} processes...")
        start_time = time.time()
        
        with Pool(self.num_processes) as pool:
            results = list(tqdm(pool.imap(self._run_simulation, all_params), total=len(all_params)))
        
        end_time = time.time()
        print(f"Completed in {end_time - start_time:.2f} seconds")
        
        # Convert results to DataFrame
        rows = []
        for res in results:
            row = {'repetition': res['params']['repetition']}
            
            # Add parameters
            for name, value in res['params'].get('modifications', {}).items():
                row[f"param_{name}"] = value
            
            # Add metrics
            for metric_name, metric_value in res['metrics'].items():
                row[metric_name] = metric_value
                
            rows.append(row)
        
        return pd.DataFrame(rows)
    
    def monte_carlo_analysis(self, num_simulations=1000, duration=1.0, dt=0.001, parameter_variations=None):
        """
        Perform Monte Carlo analysis with random parameter variations.
        
        Args:
            num_simulations: Number of simulations to run
            duration: Simulation duration in seconds
            dt: Time step in seconds
            parameter_variations: Dictionary mapping parameter names to (mean, std) tuples
            
        Returns:
            DataFrame with Monte Carlo results
        """
        # Set default parameter variations if not provided
        if parameter_variations is None:
            parameter_variations = {}
            for name, comp in self.base_circuit.components.items():
                # Default: vary by 10% of nominal value
                parameter_variations[name] = (comp.nominal_value, 0.1 * comp.nominal_value)
        
        # Generate random parameters for each simulation
        all_params = []
        for i in range(num_simulations):
            modifications = {}
            for param_name, (mean, std) in parameter_variations.items():
                modifications[param_name] = np.random.normal(mean, std)
            
            params = {
                'duration': duration,
                'dt': dt,
                'modifications': modifications,
                'seed': i,
                'simulation_id': i
            }
            all_params.append(params)
        
        # Run simulations in parallel
        print(f"Running {num_simulations} Monte Carlo simulations using {self.num_processes} processes...")
        start_time = time.time()
        
        with Pool(self.num_processes) as pool:
            results = list(tqdm(pool.imap(self._run_simulation, all_params), total=num_simulations))
        
        end_time = time.time()
        print(f"Completed in {end_time - start_time:.2f} seconds")
        
        # Convert results to DataFrame
        rows = []
        for res in results:
            row = {'simulation_id': res['params']['simulation_id']}
            
            # Add parameters
            for name, value in res['params'].get('modifications', {}).items():
                row[f"param_{name}"] = value
            
            # Add metrics
            for metric_name, metric_value in res['metrics'].items():
                row[metric_name] = metric_value
                
            rows.append(row)
        
        return pd.DataFrame(rows)
    
    def visualize_parameter_sweep(self, sweep_results, x_param, y_metric, hue_param=None, figsize=(10, 6)):
        """
        Visualize parameter sweep results.
        
        Args:
            sweep_results: DataFrame with parameter sweep results
            x_param: Parameter name for x-axis
            y_metric: Metric name for y-axis
            hue_param: Parameter name for color grouping
            figsize: Figure size
            
        Returns:
            Matplotlib figure
        """
        fig, ax = plt.subplots(figsize=figsize)
        fig.patch.set_facecolor('#0A0A1A')
        ax.set_facecolor('#0F0F1F')
        
        x_col = f"param_{x_param}" if not x_param.startswith("param_") else x_param
        
        if hue_param:
            hue_col = f"param_{hue_param}" if not hue_param.startswith("param_") else hue_param
            
            # Group by x and hue parameters
            grouped = sweep_results.groupby([x_col, hue_col]).agg({
                y_metric: ['mean', 'std']
            }).reset_index()
            
            # Get unique hue values
            hue_values = sorted(sweep_results[hue_col].unique())
            
            # Generate colors
            colors = [colorsys.hsv_to_rgb(i/len(hue_values), 0.8, 0.9) for i in range(len(hue_values))]
            
            # Plot each hue group
            for i, hue_val in enumerate(hue_values):
                group_data = grouped[grouped[hue_col] == hue_val]
                x = group_data[x_col]
                y = group_data[(y_metric, 'mean')]
                yerr = group_data[(y_metric, 'std')]
                
                ax.errorbar(x, y, yerr=yerr, marker='o', color=colors[i], 
                           label=f"{hue_param}={hue_val:.3g}")
        else:
            # Group by x parameter
            grouped = sweep_results.groupby(x_col).agg({
                y_metric: ['mean', 'std']
            }).reset_index()
            
            x = grouped[x_col]
            y = grouped[(y_metric, 'mean')]
            yerr = grouped[(y_metric, 'std')]
            
            ax.errorbar(x, y, yerr=yerr, marker='o', color='#00FFAA')
        
        ax.set_xlabel(x_param)
        ax.set_ylabel(y_metric)
        ax.set_title(f"Effect of {x_param} on {y_metric}")
        ax.grid(True, alpha=0.3)
        
        if hue_param:
            ax.legend()
        
        plt.tight_layout()
        return fig
    
    def visualize_monte_carlo(self, mc_results, metric, figsize=(10, 6)):
        """
        Visualize Monte Carlo simulation results.
        
        Args:
            mc_results: DataFrame with Monte Carlo results
            metric: Metric name to visualize
            figsize: Figure size
            
        Returns:
            Matplotlib figure
        """
        fig, axes = plt.subplots(2, 1, figsize=figsize)
        fig.patch.set_facecolor('#0A0A1A')
        
        for ax in axes:
            ax.set_facecolor('#0F0F1F')
        
        # Histogram
        sns.histplot(mc_results[metric], kde=True, ax=axes[0], color='#00FFAA')
        axes[0].set_title(f"Distribution of {metric}")
        axes[0].grid(True, alpha=0.3)
        
        # Calculate statistics
        mean = mc_results[metric].mean()
        median = mc_results[metric].median()
        std = mc_results[metric].std()
        
        # Add vertical lines for mean and median
        axes[0].axvline(mean, color='#FF00AA', linestyle='--', label=f'Mean: {mean:.4f}')
        axes[0].axvline(median, color='#FFAA00', linestyle='-.', label=f'Median: {median:.4f}')
        axes[0].legend()
        
        # Scatter plot of most influential parameters
        param_cols = [col for col in mc_results.columns if col.startswith('param_')]
        
        # Find parameter with highest correlation to the metric
        correlations = []
        for param in param_cols:
            corr = mc_results[param].corr(mc_results[metric])
            correlations.append((param, corr))
        
        correlations.sort(key=lambda x: abs(x[1]), reverse=True)
        
        if correlations:
            most_influential = correlations[0][0]
            sns.regplot(x=most_influential, y=metric, data=mc_results, ax=axes[1], 
                       scatter_kws={'color': '#00FFAA', 'alpha': 0.5}, 
                       line_kws={'color': '#FF00AA'})
            axes[1].set_title(f"{metric} vs {most_influential} (corr: {correlations[0][1]:.3f})")
            axes[1].grid(True, alpha=0.3)
            
            # Add confidence intervals
            x = mc_results[most_influential]
            y = mc_results[metric]
            
            # Simple linear regression
            coeffs = np.polyfit(x, y, 1)
            p = np.poly1d(coeffs)
            axes[1].plot(x, p(x), 'r--')
        
        plt.tight_layout()
        return fig
    
    def animate_monte_carlo(self, parameter_variations=None, num_simulations=100, duration=0.2, figsize=(12, 8)):
        """
        Create an animated visualization of Monte Carlo simulations.
        
        Args:
            parameter_variations: Dictionary mapping parameter names to (mean, std) tuples
            num_simulations: Number of simulations to run
            duration: Simulation duration in seconds
            figsize: Figure size
            
        Returns:
            Matplotlib figure and animation
        """
        # Set default parameter variations if not provided
        if parameter_variations is None:
            parameter_variations = {}
            for name, comp in self.base_circuit.components.items():
                # Default: vary by 10% of nominal value
                parameter_variations[name] = (comp.nominal_value, 0.1 * comp.nominal_value)
        
        # Setup figure
        fig = plt.figure(figsize=figsize)
        gs = gridspec.GridSpec(2, 2)
        
        # Current plot
        ax1 = fig.add_subplot(gs[0, 0])
        ax1.set_xlabel('Time (s)')
        ax1.set_ylabel('Current (A)')
        ax1.set_title('Current Trajectories')
        ax1.grid(True, alpha=0.3)
        ax1.set_facecolor('#0F0F1F')
        
        # Voltage plot
        ax2 = fig.add_subplot(gs[0, 1])
        ax2.set_xlabel('Time (s)')
        ax2.set_ylabel('Voltage (V)')
        ax2.set_title('Voltage Trajectories')
        ax2.grid(True, alpha=0.3)
        ax2.set_facecolor('#0F0F1F')
        
        # State space plot
        ax3 = fig.add_subplot(gs[1, :])
        ax3.set_xlabel('Current (A)')
        ax3.set_ylabel('Voltage (V)')
        ax3.set_title('State Space Trajectories')
        ax3.grid(True, alpha=0.3)
        ax3.set_facecolor('#0F0F1F')
        
        # Set background color
        fig.patch.set_facecolor('#0A0A1A')
        
        # List to store lines
        current_lines = []
        voltage_lines = []
        state_lines = []
        
        # Function to generate parameter set
        def generate_parameters():
            modifications = {}
            for param_name, (mean, std) in parameter_variations.items():
                modifications[param_name] = np.random.normal(mean, std)
            return modifications
        
        # Function to run a single simulation
        def run_simulation(modifications):
            # Create a copy of the base circuit
            circuit = StochasticCircuit(
                components={name: CircuitComponent(
                    name=comp.name,
                    nominal_value=comp.nominal_value if name not in modifications else modifications[name],
                    noise_amplitude=comp.noise_amplitude,
                    temperature_coefficient=comp.temperature_coefficient,
                    type=comp.type
                ) for name, comp in self.base_circuit.components.items()},
                coupling_matrix=self.base_circuit.coupling_matrix.copy(),
                temperature=self.base_circuit.temperature
            )
            
            # Run simulation
            results = circuit.simulate(duration=duration, dt=0.001)
            return results
        
        # Generate colors
        colors = [colorsys.hsv_to_rgb(i/num_simulations, 0.8, 0.9) for i in range(num_simulations)]
        
        # Run initial simulations
        for i in range(num_simulations):
            # Generate parameters
            modifications = generate_parameters()
            
            # Run simulation
            results = run_simulation(modifications)
            
            # Plot results
            current_line, = ax1.plot(results['time'], results['current'], color=colors[i], alpha=0.5)
            voltage_line, = ax2.plot(results['time'], results['voltage'], color=colors[i], alpha=0.5)
            state_line, = ax3.plot(results['current'], results['voltage'], color=colors[i], alpha=0.5)
            
            # Store lines
            current_lines.append(current_line)
            voltage_lines.append(voltage_line)
            state_lines.append(state_line)
        
        # Set axis limits
        all_times = np.linspace(0, duration, int(duration/0.001))
        
        # Initialize with empty data for animation
        for line in current_lines + voltage_lines + state_lines:
            line.set_data([], [])
        
        # Animation function
        def animate(frame):
            # Calculate frame ratio (0 to 1)
            ratio = (frame + 1) / 100
            
            # Update line data
            for i in range(num_simulations):
                # Generate parameters
                modifications = generate_parameters()
                
                # Run simulation
                results = run_simulation(modifications)
                
                # Calculate data index
                idx = int(ratio * len(results))
                
                # Update lines
                current_lines[i].set_data(results['time'][:idx], results['current'][:idx])
                voltage_lines[i].set_data(results['time'][:idx], results['voltage'][:idx])
                state_lines[i].set_data(results['current'][:idx], results['voltage'][:idx])
            
            # Update axis limits dynamically
            ax1.relim()
            ax1.autoscale_view()
            ax2.relim()
            ax2.autoscale_view()
            ax3.relim()
            ax3.autoscale_view()
            
            return current_lines + voltage_lines + state_lines
        
        # Create animation
        anim = animation.FuncAnimation(fig, animate, frames=100, interval=50, blit=True)
        
        plt.tight_layout()
        return fig, anim


class InteractiveExtropic:
    """Class for interactive visualization and exploration of thermodynamic computing."""
    
    def __init__(self, circuit=None):
        """
        Initialize the interactive visualization.
        
        Args:
            circuit: StochasticCircuit to visualize
        """
        self.circuit = circuit or create_example_circuit()
        self.root = None
        self.animation = None
        self.simulation_thread = None
        self.stop_simulation = False
        self.computation_thread = None
        self.queue = queue.Queue()
    
    def _create_circuit_tab_contents(self, parent):
        """Create the contents for the circuit simulation tab."""
        # Split frame into left control panel and right plot area
        control_frame = ttk.LabelFrame(parent, text="Circuit Controls")
        control_frame.pack(side=tk.LEFT, fill=tk.Y, padx=10, pady=10)
        
        # Temperature slider
        ttk.Label(control_frame, text="Temperature (K):").pack(anchor=tk.W, padx=5, pady=2)
        temp_var = tk.DoubleVar(value=self.circuit.temperature)
        temp_slider = ttk.Scale(control_frame, from_=100, to=500, variable=temp_var, orient=tk.HORIZONTAL, length=200)
        temp_slider.pack(fill=tk.X, padx=5, pady=5)
        ttk.Label(control_frame, textvariable=temp_var).pack(anchor=tk.W, padx=5)
        
        # Component controls
        components_frame = ttk.LabelFrame(control_frame, text="Components")
        components_frame.pack(fill=tk.X, padx=5, pady=10, anchor=tk.N)
        
        # Create sliders for each component
        component_vars = {}
        for name, comp in self.circuit.components.items():
            frame = ttk.Frame(components_frame)
            frame.pack(fill=tk.X, padx=5, pady=5)
            ttk.Label(frame, text=f"{name} ({comp.type}):").pack(side=tk.LEFT)
            
            var = tk.DoubleVar(value=comp.nominal_value)
            component_vars[name] = var
            
            # Different scales for different component types
            if comp.type == "R":
                scale = ttk.Scale(frame, from_=100, to=2000, variable=var, orient=tk.HORIZONTAL)
            elif comp.type == "L":
                scale = ttk.Scale(frame, from_=0.01, to=1.0, variable=var, orient=tk.HORIZONTAL)
            elif comp.type == "C":
                scale = ttk.Scale(frame, from_=1e-7, to=1e-5, variable=var, orient=tk.HORIZONTAL)
            elif comp.type == "Source":
                scale = ttk.Scale(frame, from_=1.0, to=10.0, variable=var, orient=tk.HORIZONTAL)
            else:
                scale = ttk.Scale(frame, from_=0.0, to=1.0, variable=var, orient=tk.HORIZONTAL)
            
            scale.pack(side=tk.LEFT, fill=tk.X, expand=True, padx=5)
            ttk.Label(frame, textvariable=var, width=8).pack(side=tk.LEFT)
        
        # Simulation control buttons
        sim_frame = ttk.Frame(control_frame)
        sim_frame.pack(fill=tk.X, padx=5, pady=10)
        
        ttk.Button(sim_frame, text="Run Simulation", 
                 command=lambda: self._run_simulation(temp_var, component_vars)).pack(side=tk.LEFT, padx=5)
        ttk.Button(sim_frame, text="Real-time Sim", 
                 command=lambda: self._run_realtime_simulation(temp_var, component_vars)).pack(side=tk.LEFT, padx=5)
        ttk.Button(sim_frame, text="Stop", 
                 command=self._stop_simulation).pack(side=tk.LEFT, padx=5)
        
        # Visualization options
        viz_frame = ttk.LabelFrame(control_frame, text="Visualization")
        viz_frame.pack(fill=tk.X, padx=5, pady=10)
        
        viz_var = tk.StringVar(value="time_domain")
        ttk.Radiobutton(viz_frame, text="Time Domain", variable=viz_var, value="time_domain").pack(anchor=tk.W, padx=5, pady=2)
        ttk.Radiobutton(viz_frame, text="State Space", variable=viz_var, value="state_space").pack(anchor=tk.W, padx=5, pady=2)
        ttk.Radiobutton(viz_frame, text="Energy Landscape", variable=viz_var, value="energy").pack(anchor=tk.W, padx=5, pady=2)
        
        ttk.Button(viz_frame, text="Update Plot", 
                 command=lambda: self._update_plot(viz_var.get())).pack(padx=5, pady=5, fill=tk.X)
        
        # Animation controls
        anim_frame = ttk.LabelFrame(control_frame, text="Animation")
        anim_frame.pack(fill=tk.X, padx=5, pady=10)
        
        ttk.Button(anim_frame, text="Animate Circuit", 
                 command=self._animate_circuit).pack(padx=5, pady=5, fill=tk.X)
        
        # Plot area
        plot_frame = ttk.Frame(parent)
        plot_frame.pack(side=tk.RIGHT, fill=tk.BOTH, expand=True, padx=10, pady=10)
        
        # Create initial plot
        fig = Figure(figsize=(8, 6), dpi=100)
        fig.patch.set_facecolor('#0A0A1A')
        self.ax = fig.add_subplot(111)
        self.ax.set_facecolor('#0F0F1F')
        self.ax.set_title("Circuit Simulation", fontsize=16)
        self.ax.grid(True, alpha=0.3)
        
        self.canvas = FigureCanvasTkAgg(fig, master=plot_frame)
        self.canvas.draw()
        self.canvas.get_tk_widget().pack(fill=tk.BOTH, expand=True)
        
        # Add toolbar
        toolbar = NavigationToolbar2Tk(self.canvas, plot_frame)
        toolbar.update()
        self.canvas.get_tk_widget().pack(fill=tk.BOTH, expand=True)
    
    def _create_computation_tab_contents(self, parent):
        """Create the contents for the thermodynamic computation tab."""
        # Control panel
        control_frame = ttk.LabelFrame(parent, text="Computation Controls")
        control_frame.pack(side=tk.LEFT, fill=tk.Y, padx=10, pady=10)
        
        # Problem type selection
        ttk.Label(control_frame, text="Problem Type:").pack(anchor=tk.W, padx=5, pady=2)
        problem_var = tk.StringVar(value="optimize")
        ttk.Radiobutton(control_frame, text="Optimization", variable=problem_var, value="optimize").pack(anchor=tk.W, padx=5, pady=2)
        ttk.Radiobutton(control_frame, text="Sampling", variable=problem_var, value="sample").pack(anchor=tk.W, padx=5, pady=2)
        ttk.Radiobutton(control_frame, text="Equation Solving", variable=problem_var, value="solve").pack(anchor=tk.W, padx=5, pady=2)
        
        # Problem configuration frame
        problem_frame = ttk.LabelFrame(control_frame, text="Problem Configuration")
        problem_frame.pack(fill=tk.X, padx=5, pady=10)
        
        # Dimensions
        ttk.Label(problem_frame, text="Dimensions:").pack(anchor=tk.W, padx=5, pady=2)
        dim_var = tk.IntVar(value=2)
        ttk.Spinbox(problem_frame, from_=1, to=10, textvariable=dim_var).pack(fill=tk.X, padx=5, pady=2)
        
        # Iterations
        ttk.Label(problem_frame, text="Iterations:").pack(anchor=tk.W, padx=5, pady=2)
        iter_var = tk.IntVar(value=1000)
        ttk.Spinbox(problem_frame, from_=100, to=10000, textvariable=iter_var).pack(fill=tk.X, padx=5, pady=2)
        
        # Temperature
        ttk.Label(problem_frame, text="Initial Temperature:").pack(anchor=tk.W, padx=5, pady=2)
        temp_var = tk.DoubleVar(value=1000.0)
        ttk.Scale(problem_frame, from_=100, to=5000, variable=temp_var, orient=tk.HORIZONTAL).pack(fill=tk.X, padx=5, pady=2)
        ttk.Label(problem_frame, textvariable=temp_var).pack(anchor=tk.W, padx=5)
        
        # Computation control buttons
        comp_frame = ttk.Frame(control_frame)
        comp_frame.pack(fill=tk.X, padx=5, pady=10)
        
        ttk.Button(comp_frame, text="Run Computation", 
                 command=lambda: self._run_computation(problem_var.get(), dim_var.get(), iter_var.get(), temp_var.get())).pack(fill=tk.X, padx=5, pady=5)
        ttk.Button(comp_frame, text="Stop", 
                 command=self._stop_computation).pack(fill=tk.X, padx=5, pady=5)
        
        # Visualization options
        viz_frame = ttk.LabelFrame(control_frame, text="Visualization Options")
        viz_frame.pack(fill=tk.X, padx=5, pady=10)
        
        show_trajectory_var = tk.BooleanVar(value=True)
        ttk.Checkbutton(viz_frame, text="Show Trajectory", variable=show_trajectory_var).pack(anchor=tk.W, padx=5, pady=2)
        
        ttk.Button(viz_frame, text="Update Plot", 
                 command=lambda: self._update_computation_plot(show_trajectory_var.get())).pack(padx=5, pady=5, fill=tk.X)
        
        # Plot area
        plot_frame = ttk.Frame(parent)
        plot_frame.pack(side=tk.RIGHT, fill=tk.BOTH, expand=True, padx=10, pady=10)
        
        # Create initial plot
        fig = Figure(figsize=(8, 6), dpi=100)
        fig.patch.set_facecolor('#0A0A1A')
        self.comp_ax = fig.add_subplot(111)
        self.comp_ax.set_facecolor('#0F0F1F')
        self.comp_ax.set_title("Thermodynamic Computation", fontsize=16)
        self.comp_ax.grid(True, alpha=0.3)
        self.comp_ax.text(0.5, 0.5, "Run a computation to visualize results", 
                       ha='center', va='center', fontsize=14, transform=self.comp_ax.transAxes, color='white')
        
        self.comp_canvas = FigureCanvasTkAgg(fig, master=plot_frame)
        self.comp_canvas.draw()
        self.comp_canvas.get_tk_widget().pack(fill=tk.BOTH, expand=True)
        
        # Add toolbar
        toolbar = NavigationToolbar2Tk(self.comp_canvas, plot_frame)
        toolbar.update()
        self.comp_canvas.get_tk_widget().pack(fill=tk.BOTH, expand=True)
    
    def _create_monte_carlo_tab_contents(self, parent):
        """Create the contents for the Monte Carlo analysis tab."""
        # Control panel
        control_frame = ttk.LabelFrame(parent, text="Monte Carlo Controls")
        control_frame.pack(side=tk.LEFT, fill=tk.Y, padx=10, pady=10)
        
        # Number of simulations
        ttk.Label(control_frame, text="Number of Simulations:").pack(anchor=tk.W, padx=5, pady=2)
        num_sim_var = tk.IntVar(value=100)
        ttk.Spinbox(control_frame, from_=10, to=1000, textvariable=num_sim_var).pack(fill=tk.X, padx=5, pady=2)
        
        # Parameter variation frame
        param_frame = ttk.LabelFrame(control_frame, text="Parameter Variations")
        param_frame.pack(fill=tk.X, padx=5, pady=10)
        
        # Create sliders for each component's standard deviation
        param_vars = {}
        for name, comp in self.circuit.components.items():
            frame = ttk.Frame(param_frame)
            frame.pack(fill=tk.X, padx=5, pady=5)
            ttk.Label(frame, text=f"{name} (std %):").pack(side=tk.LEFT)
            
            var = tk.DoubleVar(value=10.0)  # Default 10% variation
            param_vars[name] = var
            
            scale = ttk.Scale(frame, from_=0.0, to=50.0, variable=var, orient=tk.HORIZONTAL)
            scale.pack(side=tk.LEFT, fill=tk.X, expand=True, padx=5)
            ttk.Label(frame, textvariable=var, width=8).pack(side=tk.LEFT)
        
        # Analysis control buttons
        analysis_frame = ttk.Frame(control_frame)
        analysis_frame.pack(fill=tk.X, padx=5, pady=10)
        
        ttk.Button(analysis_frame, text="Run Monte Carlo", 
                 command=lambda: self._run_monte_carlo(num_sim_var.get(), param_vars)).pack(fill=tk.X, padx=5, pady=5)
        ttk.Button(analysis_frame, text="Animate Monte Carlo", 
                 command=lambda: self._animate_monte_carlo(param_vars)).pack(fill=tk.X, padx=5, pady=5)
        
        # Metric selection
        metric_frame = ttk.LabelFrame(control_frame, text="Metric to Analyze")
        metric_frame.pack(fill=tk.X, padx=5, pady=10)
        
        metric_var = tk.StringVar(value="current_mean")
        ttk.Radiobutton(metric_frame, text="Current Mean", variable=metric_var, value="current_mean").pack(anchor=tk.W, padx=5, pady=2)
        ttk.Radiobutton(metric_frame, text="Current Std", variable=metric_var, value="current_std").pack(anchor=tk.W, padx=5, pady=2)
        ttk.Radiobutton(metric_frame, text="Voltage Mean", variable=metric_var, value="voltage_mean").pack(anchor=tk.W, padx=5, pady=2)
        ttk.Radiobutton(metric_frame, text="Voltage Std", variable=metric_var, value="voltage_std").pack(anchor=tk.W, padx=5, pady=2)
        ttk.Radiobutton(metric_frame, text="SNR Current", variable=metric_var, value="snr_current").pack(anchor=tk.W, padx=5, pady=2)
        
        ttk.Button(metric_frame, text="Update Plot", 
                 command=lambda: self._update_monte_carlo_plot(metric_var.get())).pack(padx=5, pady=5, fill=tk.X)
        
        # Plot area
        plot_frame = ttk.Frame(parent)
        plot_frame.pack(side=tk.RIGHT, fill=tk.BOTH, expand=True, padx=10, pady=10)
        
        # Create initial plot
        fig = Figure(figsize=(8, 6), dpi=100)
        fig.patch.set_facecolor('#0A0A1A')
        gs = gridspec.GridSpec(2, 1, figure=fig)
        self.mc_ax1 = fig.add_subplot(gs[0])
        self.mc_ax2 = fig.add_subplot(gs[1])
        
        for ax in [self.mc_ax1, self.mc_ax2]:
            ax.set_facecolor('#0F0F1F')
            ax.grid(True, alpha=0.3)
        
        self.mc_ax1.set_title("Monte Carlo Analysis - Distribution", fontsize=14)
        self.mc_ax2.set_title("Most Influential Parameter", fontsize=14)
        
        self.mc_ax1.text(0.5, 0.5, "Run Monte Carlo analysis to visualize results", 
                      ha='center', va='center', fontsize=12, transform=self.mc_ax1.transAxes, color='white')
        
        self.mc_canvas = FigureCanvasTkAgg(fig, master=plot_frame)
        self.mc_canvas.draw()
        self.mc_canvas.get_tk_widget().pack(fill=tk.BOTH, expand=True)
        
        # Add toolbar
        toolbar = NavigationToolbar2Tk(self.mc_canvas, plot_frame)
        toolbar.update()
        self.mc_canvas.get_tk_widget().pack(fill=tk.BOTH, expand=True)
    
    def _run_simulation(self, temp_var, component_vars):
        """Run a circuit simulation with the current parameters."""
        # Update circuit parameters
        self.circuit.temperature = temp_var.get()
        for name, var in component_vars.items():
            self.circuit.components[name].nominal_value = var.get()
        
        # Run simulation
        self.circuit.simulate(duration=1.0, dt=0.001, capture_energy=True)
        
        # Update plot
        self._update_plot("time_domain")
    
    def _run_realtime_simulation(self, temp_var, component_vars):
        """Run a real-time simulation with live updating plot."""
        if self.simulation_thread and self.simulation_thread.is_alive():
            return  # Already running
        
        # Update circuit parameters
        self.circuit.temperature = temp_var.get()
        for name, var in component_vars.items():
            self.circuit.components[name].nominal_value = var.get()
        
        # Clear previous data
        self.stop_simulation = False
        self.times = []
        self.currents = []
        self.voltages = []
        
        # Set up plot
        self.ax.clear()
        self.ax.set_facecolor('#0F0F1F')
        self.ax.set_title("Real-time Circuit Simulation", fontsize=16)
        self.ax.set_xlabel("Time (s)")
        self.ax.set_ylabel("Value")
        self.ax.grid(True, alpha=0.3)
        
        self.current_line, = self.ax.plot([], [], label="Current", color='#00FFAA')
        self.voltage_line, = self.ax.plot([], [], label="Voltage", color='#FF00AA')
        self.ax.legend()
        
        self.canvas.draw()
        
        # Start simulation thread
        self.simulation_thread = threading.Thread(target=self._realtime_simulation_worker, args=(temp_var, component_vars))
        self.simulation_thread.daemon = True
        self.simulation_thread.start()
    
    def _realtime_simulation_worker(self, temp_var, component_vars):
        """Worker function for real-time simulation."""
        # Update circuit parameters
        self.circuit.temperature = temp_var.get()
        for name, var in component_vars.items():
            self.circuit.components[name].nominal_value = var.get()
        
        # Define callback for real-time updates
        def update_callback(t, current, voltage, energy):
            self.queue.put((t, current, voltage))
            self.root.after(1, self._process_queue)
            
            # Check if we should stop
            if self.stop_simulation:
                return False
            
            return True
        
        # Run simulation
        try:
            self.circuit.simulate_real_time(duration=10.0, dt=0.01, callback=update_callback)
        except Exception as e:
            print(f"Simulation error: {e}")
    
    def _process_queue(self):
        """Process the queue of simulation data."""
        try:
            while True:
                t, current, voltage = self.queue.get_nowait()
                
                # Append to data
                self.times.append(t)
                self.currents.append(current)
                self.voltages.append(voltage)
                
                # Update plot
                self.current_line.set_data(self.times, self.currents)
                self.voltage_line.set_data(self.times, self.voltages)
                
                # Adjust limits if needed
                if len(self.times) > 1:
                    self.ax.set_xlim(0, max(self.times))
                    self.ax.set_ylim(
                        min(min(self.currents), min(self.voltages)) * 1.1,
                        max(max(self.currents), max(self.voltages)) * 1.1
                    )
                
                self.canvas.draw_idle()
                
        except queue.Empty:
            pass
    
    def _stop_simulation(self):
        """Stop the real-time simulation."""
        self.stop_simulation = True
    
    def _update_plot(self, plot_type):
        """Update the plot with the selected visualization."""
        if self.circuit.simulation_results is None:
            return
        
        self.ax.clear()
        self.ax.set_facecolor('#0F0F1F')
        
        if plot_type == "time_domain":
            # Plot time domain results
            self.ax.plot(self.circuit.simulation_results['time'], 
                      self.circuit.simulation_results['current'], 
                      label="Current", color='#00FFAA')
            self.ax.plot(self.circuit.simulation_results['time'], 
                      self.circuit.simulation_results['voltage'], 
                      label="Voltage", color='#FF00AA')
            self.ax.set_xlabel("Time (s)")
            self.ax.set_ylabel("Value")
            self.ax.set_title("Circuit Time Domain", fontsize=16)
            self.ax.legend()
            
        elif plot_type == "state_space":
            # Plot state space
            self.ax.scatter(self.circuit.simulation_results['current'], 
                         self.circuit.simulation_results['voltage'],
                         c=range(len(self.circuit.simulation_results)), 
                         cmap=CMAP, s=10, alpha=0.7)
            self.ax.set_xlabel("Current (A)")
            self.ax.set_ylabel("Voltage (V)")
            self.ax.set_title("Circuit State Space", fontsize=16)
            
        elif plot_type == "energy":
            # Calculate energy landscape
            resistors = [comp for comp in self.circuit.components.values() if comp.type == "R"]
            inductors = [comp for comp in self.circuit.components.values() if comp.type == "L"]
            capacitors = [comp for comp in self.circuit.components.values() if comp.type == "C"]
            sources = [comp for comp in self.circuit.components.values() if comp.type == "Source"]
            
            R = resistors[0].nominal_value
            L = inductors[0].nominal_value
            C = capacitors[0].nominal_value
            V = sources[0].nominal_value
            
            # Define current and voltage ranges
            current_range = np.linspace(
                self.circuit.simulation_results['current'].min() * 1.5,
                self.circuit.simulation_results['current'].max() * 1.5,
                50
            )
            voltage_range = np.linspace(
                self.circuit.simulation_results['voltage'].min() * 1.5,
                self.circuit.simulation_results['voltage'].max() * 1.5,
                50
            )
            
            # Calculate energy landscape
            current_mesh, voltage_mesh, energy_mesh = self.circuit._update_energy_landscape(
                current_range, voltage_range, R, L, C, V)
            
            # Create contour plot
            contour = self.ax.contourf(current_mesh, voltage_mesh, energy_mesh, 50, cmap=CMAP, alpha=0.7)
            self.ax.contour(current_mesh, voltage_mesh, energy_mesh, 20, colors='white', alpha=0.5, linewidths=0.5)
            
            # Plot trajectory
            points = self.ax.scatter(
                self.circuit.simulation_results['current'],
                self.circuit.simulation_results['voltage'],
                c=range(len(self.circuit.simulation_results)),
                cmap='plasma',
                s=5,
                alpha=0.7
            )
            
            # Add colorbar
            plt.colorbar(contour, ax=self.ax, label="Energy (J)")
            
            self.ax.set_xlabel("Current (A)")
            self.ax.set_ylabel("Voltage (V)")
            self.ax.set_title("Energy Landscape", fontsize=16)
        
        self.ax.grid(True, alpha=0.3)
        self.canvas.draw()
    
    def _animate_circuit(self):
        """Create and display an animation of the circuit state space."""
        if self.circuit.simulation_results is None:
            return
        
        # Create animation
        anim = self.circuit.animate_state_space()
        
        # Display animation
        self.animation = anim
        
        # Create a new window for the animation
        top = tk.Toplevel(self.root)
        top.title("Circuit Animation")
        top.geometry("800x600")
        
        # Create a frame for the animation
        frame = ttk.Frame(top)
        frame.pack(fill=tk.BOTH, expand=True, padx=10, pady=10)
        
        # Embed the animation
        canvas = FigureCanvasTkAgg(anim._fig, master=frame)
        canvas.draw()
        canvas.get_tk_widget().pack(fill=tk.BOTH, expand=True)
        
        # Add toolbar
        toolbar = NavigationToolbar2Tk(canvas, frame)
        toolbar.update()
        canvas.get_tk_widget().pack(fill=tk.BOTH, expand=True)
        
        # Save animation button
        ttk.Button(frame, text="Save Animation", 
                 command=lambda: self._save_animation(anim, "circuit_animation.mp4")).pack(pady=10)
    
    def _save_animation(self, anim, filename):
        """Save the animation to a file."""
        try:
            anim.save(filename, writer='ffmpeg', fps=20, dpi=100)
            tk.messagebox.showinfo("Success", f"Animation saved to {filename}")
        except Exception as e:
            tk.messagebox.showerror("Error", f"Failed to save animation: {e}")
            
    def _run_computation(self, problem_type, dimensions, iterations, initial_temp):
        """Run a thermodynamic computation."""
        if self.computation_thread and self.computation_thread.is_alive():
            return  # Already running
        
        # Create parameters
        if problem_type == "optimize":
            params = {
                'dimensions': dimensions,
                'iterations': iterations,
                'initial_temp': initial_temp,
                'objective_function': lambda x: np.sum(x**2)  # Simple quadratic function
            }
        elif problem_type == "sample":
            params = {
                'dimensions': dimensions,
                'iterations': iterations,
                'burn_in': int(iterations * 0.2),
                'distribution': lambda x: np.exp(-np.sum(x**2))  # Gaussian distribution
            }
        elif problem_type == "solve":
            if dimensions == 2:
                # Simple system of equations: x^2 + y^2 = 1, x - y = 0
                params = {
                    'dimensions': dimensions,
                    'iterations': iterations,
                    'initial_temp': initial_temp,
                    'equation': lambda x: np.array([x[0]**2 + x[1]**2 - 1, x[0] - x[1]])
                }
            else:
                # Generic system: sum of squares = 1, all variables equal
                params = {
                    'dimensions': dimensions,
                    'iterations': iterations,
                    'initial_temp': initial_temp,
                    'equation': lambda x: np.array([np.sum(x**2) - 1] + [x[0] - x[i] for i in range(1, dimensions)])
                }
        
        # Start computation thread
        self.computation_thread = threading.Thread(
            target=self._computation_worker, 
            args=(problem_type, params)
        )
        self.computation_thread.daemon = True
        self.computation_thread.start()
        
        # Update status
        self.comp_ax.clear()
        self.comp_ax.set_facecolor('#0F0F1F')
        self.comp_ax.text(0.5, 0.5, "Computation in progress...", 
                       ha='center', va='center', fontsize=14, transform=self.comp_ax.transAxes, color='white')
        self.comp_canvas.draw()
    
    def _computation_worker(self, problem_type, params):
        """Worker function for thermodynamic computation."""
        try:
            # Run computation
            results = self.circuit.perform_thermodynamic_computation(problem_type, params)
            
            # Signal completion
            self.root.after(0, self._update_computation_plot, True)
        except Exception as e:
            print(f"Computation error: {e}")
    
    def _stop_computation(self):
        """Stop the computation."""
        # Currently not implemented since we don't have a way to 
        # gracefully stop the computation once started
        pass
    
    def _update_computation_plot(self, show_trajectory=True):
        """Update the computation plot."""
        if not self.circuit.computation_state:
            return
        
        # Create a new figure for the plot
        fig = self.circuit.plot_thermodynamic_computation()
        
        # Replace the current figure in the canvas
        self.comp_canvas.figure = fig
        self.comp_canvas.draw()
    
    def _run_monte_carlo(self, num_simulations, param_vars):
        """Run Monte Carlo analysis."""
        # Create HPC simulator
        hpc = HPC_Simulator(self.circuit)
        
        # Create parameter variations
        parameter_variations = {}
        for name, var in param_vars.items():
            # Convert percentage to fraction
            std_fraction = var.get() / 100.0
            nominal_value = self.circuit.components[name].nominal_value
            parameter_variations[name] = (nominal_value, nominal_value * std_fraction)
        
        # Run Monte Carlo
        self.mc_results = hpc.monte_carlo_analysis(
            num_simulations=num_simulations,
            duration=0.2,
            parameter_variations=parameter_variations
        )
        
        # Update plot
        self._update_monte_carlo_plot("current_mean")
    
    def _animate_monte_carlo(self, param_vars):
        """Create and display an animation of Monte Carlo simulations."""
        # Create HPC simulator
        hpc = HPC_Simulator(self.circuit)
        
        # Create parameter variations
        parameter_variations = {}
        for name, var in param_vars.items():
            # Convert percentage to fraction
            std_fraction = var.get() / 100.0
            nominal_value = self.circuit.components[name].nominal_value
            parameter_variations[name] = (nominal_value, nominal_value * std_fraction)
        
        # Create animation
        fig, anim = hpc.animate_monte_carlo(
            parameter_variations=parameter_variations,
            num_simulations=30  # Use fewer simulations for animation
        )
        
        # Display animation
        self.animation = anim
        
        # Create a new window for the animation
        top = tk.Toplevel(self.root)
        top.title("Monte Carlo Animation")
        top.geometry("800x600")
        
        # Create a frame for the animation
        frame = ttk.Frame(top)
        frame.pack(fill=tk.BOTH, expand=True, padx=10, pady=10)
        
        # Embed the animation
        canvas = FigureCanvasTkAgg(fig, master=frame)
        canvas.draw()
        canvas.get_tk_widget().pack(fill=tk.BOTH, expand=True)
        
        # Add toolbar
        toolbar = NavigationToolbar2Tk(canvas, frame)
        toolbar.update()
        canvas.get_tk_widget().pack(fill=tk.BOTH, expand=True)
        
        # Save animation button
        ttk.Button(frame, text="Save Animation", 
                 command=lambda: self._save_animation(anim, "monte_carlo_animation.mp4")).pack(pady=10)
    
    def _update_monte_carlo_plot(self, metric):
        """Update the Monte Carlo plot with the selected metric."""
        if not hasattr(self, 'mc_results') or self.mc_results is None:
            return
        
        # Clear axes
        self.mc_ax1.clear()
        self.mc_ax2.clear()
        
        # Set background color
        self.mc_ax1.set_facecolor('#0F0F1F')
        self.mc_ax2.set_facecolor('#0F0F1F')
        
        # Histogram
        sns.histplot(self.mc_results[metric], kde=True, ax=self.mc_ax1, color='#00FFAA')
        self.mc_ax1.set_title(f"Distribution of {metric}")
        self.mc_ax1.grid(True, alpha=0.3)
        
        # Calculate statistics
        mean = self.mc_results[metric].mean()
        median = self.mc_results[metric].median()
        std = self.mc_results[metric].std()
        
        # Add vertical lines for mean and median
        self.mc_ax1.axvline(mean, color='#FF00AA', linestyle='--', label=f'Mean: {mean:.4f}')
        self.mc_ax1.axvline(median, color='#FFAA00', linestyle='-.', label=f'Median: {median:.4f}')
        self.mc_ax1.legend()
        
        # Scatter plot of most influential parameters
        param_cols = [col for col in self.mc_results.columns if col.startswith('param_')]
        
        # Find parameter with highest correlation to the metric
        correlations = []
        for param in param_cols:
            corr = self.mc_results[param].corr(self.mc_results[metric])
            correlations.append((param, corr))
        
        correlations.sort(key=lambda x: abs(x[1]), reverse=True)
        
        if correlations:
            most_influential = correlations[0][0]
            sns.regplot(x=most_influential, y=metric, data=self.mc_results, ax=self.mc_ax2, 
                       scatter_kws={'color': '#00FFAA', 'alpha': 0.5}, 
                       line_kws={'color': '#FF00AA'})
            self.mc_ax2.set_title(f"{metric} vs {most_influential} (corr: {correlations[0][1]:.3f})")
            self.mc_ax2.grid(True, alpha=0.3)
        
        self.mc_canvas.draw()
    
    def run(self):
        """Run the interactive visualization application."""
        self.root = tk.Tk()
        self.root.title("Extropic Thermodynamic Computing Simulator")
        self.root.geometry("1200x800")
        
        # Set dark theme
        style = ttk.Style()
        try:
            style.theme_use('clam')
            style.configure('.', background='#1A1A2A', foreground='white')
            style.configure('TFrame', background='#1A1A2A')
            style.configure('TLabel', background='#1A1A2A', foreground='white')
            style.configure('TButton', background='#2A2A3A', foreground='white')
            style.configure('TCheckbutton', background='#1A1A2A', foreground='white')
            style.configure('TRadiobutton', background='#1A1A2A', foreground='white')
            style.configure('TLabelframe', background='#1A1A2A', foreground='white')
            style.configure('TLabelframe.Label', background='#1A1A2A', foreground='white')
        except tk.TclError:
            pass  # Theme not available
        
        # Create notebook (tabbed interface)
        notebook = ttk.Notebook(self.root)
        notebook.pack(fill=tk.BOTH, expand=True, padx=10, pady=10)
        
        # Create tab frames - each is a direct child of the notebook
        circuit_tab = ttk.Frame(notebook)
        computation_tab = ttk.Frame(notebook)
        monte_carlo_tab = ttk.Frame(notebook)
        
        # Add the frames to the notebook
        notebook.add(circuit_tab, text="Circuit Simulation")
        notebook.add(computation_tab, text="Thermodynamic Computation")
        notebook.add(monte_carlo_tab, text="Monte Carlo Analysis")
        
        # Build the contents of each tab
        self._create_circuit_tab_contents(circuit_tab)
        self._create_computation_tab_contents(computation_tab)
        self._create_monte_carlo_tab_contents(monte_carlo_tab)
        
        # Start the main loop
        self.root.mainloop()


def create_example_circuit():
    """Create an example stochastic circuit for demonstration."""
    # Create components
    resistor = CircuitComponent(
        name="R1",
        nominal_value=1000.0,  # 1k ohm
        noise_amplitude=0.01,  # 1% noise
        temperature_coefficient=0.001,  # 0.1% per degree K
        type="R"
    )
    
    inductor = CircuitComponent(
        name="L1",
        nominal_value=0.1,  # 100 mH
        noise_amplitude=0.005,  # 0.5% noise
        temperature_coefficient=0.0005,  # 0.05% per degree K
        type="L"
    )
    
    capacitor = CircuitComponent(
        name="C1",
        nominal_value=1e-6,  # 1 uF
        noise_amplitude=0.02,  # 2% noise
        temperature_coefficient=0.002,  # 0.2% per degree K
        type="C"
    )
    
    source = CircuitComponent(
        name="V1",
        nominal_value=5.0,  # 5V
        noise_amplitude=0.001,  # 0.1% noise
        temperature_coefficient=0.0001,  # 0.01% per degree K
        type="Source"
    )
    
    # Create circuit
    circuit = StochasticCircuit(temperature=300.0)  # 300K (room temperature)
    
    # Add components
    circuit.add_component(resistor)
    circuit.add_component(inductor)
    circuit.add_component(capacitor)
    circuit.add_component(source)
    
    # Set coupling between components (simplified)
    circuit.set_coupling("R1", "L1", 0.1)
    circuit.set_coupling("L1", "C1", 0.2)
    circuit.set_coupling("C1", "V1", 0.05)
    
    return circuit


def create_extropic_demo_circuit():
    """Create a more complex stochastic circuit for demonstration of Extropic principles."""
    # Create base components
    components = []
    
    # Resistors with different noise levels
    for i in range(3):
        # Higher noise amplitude for showcasing thermodynamic effects
        noise_amp = 0.02 + i * 0.01
        components.append(CircuitComponent(
            name=f"R{i+1}",
            nominal_value=1000.0 * (i + 1),  # 1-3k ohm
            noise_amplitude=noise_amp,
            temperature_coefficient=0.001,
            type="R"
        ))
    
    # Inductors
    for i in range(2):
        components.append(CircuitComponent(
            name=f"L{i+1}",
            nominal_value=0.1 * (i + 1),  # 100-200 mH
            noise_amplitude=0.008,
            temperature_coefficient=0.0005,
            type="L"
        ))
    
    # Capacitors
    for i in range(2):
        components.append(CircuitComponent(
            name=f"C{i+1}",
            nominal_value=1e-6 * (i + 1),  # 1-2 uF
            noise_amplitude=0.025,
            temperature_coefficient=0.002,
            type="C"
        ))
    
    # Sources
    components.append(CircuitComponent(
        name="V1",
        nominal_value=5.0,
        noise_amplitude=0.001,
        temperature_coefficient=0.0001,
        type="Source"
    ))
    
    # Create circuit
    circuit = StochasticCircuit(temperature=350.0)  # Above room temperature for more thermal noise
    
    # Add components
    for comp in components:
        circuit.add_component(comp)
    
    # Set coupling between components (more complex network)
    circuit.set_coupling("R1", "L1", 0.2)
    circuit.set_coupling("L1", "C1", 0.3)
    circuit.set_coupling("C1", "V1", 0.1)
    circuit.set_coupling("R2", "L2", 0.25)
    circuit.set_coupling("L2", "C2", 0.35)
    circuit.set_coupling("C2", "V1", 0.15)
    circuit.set_coupling("R3", "L1", 0.18)
    circuit.set_coupling("R3", "L2", 0.22)
    
    return circuit


def main():
    """Main function to demonstrate the circuit simulator."""
    parser = argparse.ArgumentParser(description="Interactive Stochastic Circuit Simulator for Thermodynamic Computing")
    parser.add_argument("--mode", choices=["gui", "simulate", "optimize", "sample", "monte_carlo"], 
                        default="gui", help="Operation mode")
    parser.add_argument("--duration", type=float, default=1.0, help="Simulation duration in seconds")
    parser.add_argument("--dt", type=float, default=0.001, help="Time step in seconds")
    parser.add_argument("--temperature", type=float, default=300.0, help="Temperature in Kelvin")
    parser.add_argument("--output", default="results", help="Output filename base")
    parser.add_argument("--extropic", action="store_true", help="Use Extropic-inspired complex circuit")
    
    # Handle case when running as script
    # This is required for compatibility with different platforms
    import sys
    if len(sys.argv) == 1 and sys.argv[0].endswith('app.py'):
        sys.argv = ['app.py', '--mode', 'gui']
    
    args = parser.parse_args()
    
    # Create circuit
    if args.extropic:
        print("Creating Extropic-inspired complex stochastic circuit...")
        circuit = create_extropic_demo_circuit()
    else:
        print("Creating stochastic circuit...")
        circuit = create_example_circuit()
    
    # Set temperature
    circuit.temperature = args.temperature
    
    # Run selected mode
    if args.mode == "gui":
        # Launch interactive GUI
        app = InteractiveExtropic(circuit)
        app.run()
        
    elif args.mode == "simulate":
        print(f"Running simulation for {args.duration} seconds...")
        results = circuit.simulate(duration=args.duration, dt=args.dt, capture_energy=True)
        
        # Plot and save results
        print("Plotting results...")
        circuit.plot_results()
        plt.figure()
        circuit.plot_state_space()
        plt.show()
        
        print("Saving results...")
        saved_files = circuit.save_results(args.output)
        print(f"Saved to: {', '.join(saved_files.values())}")
    
    elif args.mode == "optimize":
        print("Running thermodynamic optimization...")
        
        # Define a challenging optimization problem
        def rosenbrock(x):
            """Rosenbrock function (a challenging optimization benchmark)."""
            return sum(100.0 * (x[i+1] - x[i]**2)**2 + (1 - x[i])**2 for i in range(len(x)-1))
        
        # Perform optimization
        params = {
            'objective_function': rosenbrock,
            'dimensions': 5,
            'iterations': 5000,
            'initial_temp': 100.0
        }
        
        results = circuit.perform_thermodynamic_computation('optimize', params)
        
        print(f"Best solution: {results['best_solution']}")
        print(f"Best energy: {results['best_energy']}")
        
        # Plot results
        circuit.plot_thermodynamic_computation()
        plt.show()
    
    elif args.mode == "sample":
        print("Running thermodynamic sampling...")
        
        # Define a multimodal distribution to sample from
        def multimodal_distribution(x):
            """A challenging multimodal distribution."""
            centers = [np.array([-2.0, -2.0]), np.array([2.0, 2.0]), np.array([-2.0, 2.0])]
            weights = [0.3, 0.5, 0.2]
            density = 0
            for c, w in zip(centers, weights):
                density += w * np.exp(-0.5 * np.sum((x - c)**2))
            return density
        
        # Perform sampling
        params = {
            'distribution': multimodal_distribution,
            'dimensions': 2,
            'iterations': 10000,
            'burn_in': 1000
        }
        
        results = circuit.perform_thermodynamic_computation('sample', params)
        
        print(f"Mean of samples: {results['mean']}")
        print(f"Standard deviation of samples: {results['std']}")
        
        # Plot results
        circuit.plot_thermodynamic_computation()
        plt.show()
    
    elif args.mode == "monte_carlo":
        print("Running Monte Carlo analysis...")
        hpc = HPC_Simulator(circuit)
        
        # Run Monte Carlo
        mc_results = hpc.monte_carlo_analysis(num_simulations=200)
        
        print("Monte Carlo results summary:")
        print(mc_results.describe())
        
        # Visualize results
        fig = hpc.visualize_monte_carlo(mc_results, "current_mean")
        plt.figure()
        
        # Parameter sweep for a key component
        print("Running parameter sweep...")
        param_grid = {
            "R1": np.linspace(800, 1200, 5),  # Vary resistance
            "V1": np.linspace(4.5, 5.5, 3)    # Vary voltage
        }
        
        sweep_results = hpc.parameter_sweep(param_grid)
        hpc.visualize_parameter_sweep(sweep_results, "R1", "current_mean", "V1")
        
        plt.show()


if __name__ == "__main__":
    main()