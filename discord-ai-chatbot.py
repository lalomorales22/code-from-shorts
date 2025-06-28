import discord
from discord.ext import tasks
import aiohttp
import logging
import asyncio
import random
from collections import deque
import json

# Logging setup
logging.basicConfig(level=logging.INFO, format='%(asctime)s - %(levelname)s - %(message)s')

# Discord bot setup
intents = discord.Intents.default()
intents.messages = True
intents.guilds = True

BOT_TOKEN = 'enter-your-bot-token-here'
CHANNEL_ID = enter-your-channel-id-here  # Replace with your channel ID
USER_NAME = "enter-your-username-here"  # Replace with your Discord username

# Ollama API setup
OLLAMA_URL = "http://localhost:11434/api/generate"

class ChatBot:
    def __init__(self, name, model_name, personality, expertise, response_style="casual"):
        self.name = name
        self.model_name = model_name
        self.personality = personality
        self.expertise = expertise
        self.response_style = response_style
        self.emojis = ["😊", "🤔", "😄", "👍", "🎉", "🌟", "🤖", "💡", "🔥", "⚡"]

    async def generate_response(self, conversation_history, last_responses):
        headers = {'Content-Type': 'application/json'}
        prompt = self.create_prompt(conversation_history, last_responses)
        data = {
            "model": self.model_name,
            "prompt": prompt,
            "stream": True,
            "stop": ["\n\n", f"{self.name}:", "Human:", "System:", "**"],
            "max_tokens": 500,
            "temperature": 0.8,
            "top_p": 0.9
        }
        try:
            logging.debug(f"Sending request to Ollama API for {self.name}")
            async with aiohttp.ClientSession() as session:
                async with session.post(OLLAMA_URL, headers=headers, json=data, timeout=90) as response:
                    response.raise_for_status()
                    full_response = ""
                    async for line in response.content:
                        if line:
                            try:
                                json_response = json.loads(line)
                                if 'response' in json_response:
                                    full_response += json_response['response']
                                if json_response.get('done', False):
                                    break
                            except json.JSONDecodeError:
                                continue
                    return self.format_response(full_response.strip())
        except asyncio.TimeoutError:
            logging.error(f"Timeout for {self.name}")
            return f"⏰ {self.name} is thinking too hard... timeout!"
        except Exception as e:
            logging.error(f"Error in API request for {self.name}: {e}")
            return f"🚫 {self.name}: Connection hiccup, try again!"

    def create_prompt(self, conversation_history, last_responses):
        # Get more context for better responses
        recent_inputs = conversation_history[-5:] if conversation_history else []
        last_responses_str = "\n".join([f"{name}: {response}" for name, response in last_responses])
        
        # Customize prompt based on model specialty
        style_instruction = self.get_style_instruction()
        
        prompt = f"""You are {self.name}, an AI with a {self.personality} personality and expertise in {self.expertise}. 

{style_instruction}

Recent conversation:
{chr(10).join(recent_inputs)}

Last AI responses:
{last_responses_str}

Respond naturally as {self.name}, keeping it under 200 characters. Stay in character but be engaging and conversational.

{self.name}: """
        return prompt

    def get_style_instruction(self):
        style_map = {
            "analytical": "Focus on logical reasoning and breaking down complex ideas clearly.",
            "coding": "Think like a developer - practical, efficient, and solution-oriented.",
            "philosophical": "Ask deep questions and explore meaning behind concepts.",
            "creative": "Be imaginative and think outside conventional boundaries.",
            "technical": "Dive into systems, architecture, and technical optimization.",
            "casual": "Keep it relaxed, authentic, and conversational."
        }
        return style_map.get(self.response_style, "Be helpful and engaging.")

    def format_response(self, response):
        # Clean up the response
        response = response.strip()
        
        # Remove any unwanted prefixes
        if response.startswith(f"{self.name}:"):
            response = response[len(f"{self.name}:"):].strip()
        
        # Add personality touches
        if random.random() < 0.25:  # 25% chance for emoji
            response += f" {random.choice(self.emojis)}"
        
        if random.random() < 0.15 and len(response) < 150:  # 15% chance for engagement
            engagement_phrases = [
                " thoughts?", " what do you think?", " makes sense?", 
                " anyone else see this?", " am I missing something?"
            ]
            response += random.choice(engagement_phrases)
        
        return response[:200]  # Hard limit

class DiscordLLMChatbot(discord.Client):
    def __init__(self, *args, **kwargs):
        super().__init__(*args, **kwargs)
        # Updated with your actual models and optimized personalities
        self.bots = [
            ChatBot("Phi4", "phi4-mini-reasoning:latest", "analytical and methodical", 
                   "logical reasoning and problem-solving", "analytical"),
            ChatBot("Qwen3", "qwen3:4b", "balanced and insightful", 
                   "general knowledge and helpful analysis", "casual"),
            ChatBot("Gemma3", "gemma3:4b", "curious and collaborative", 
                   "research and creative thinking", "creative"),
            ChatBot("Cogito", "cogito:3b", "philosophical and reflective", 
                   "deep thinking and existential questions", "philosophical"),
            ChatBot("DeepCoder", "deepcoder:1.5b", "practical and code-focused", 
                   "programming and technical solutions", "coding"),
            ChatBot("DeepScaler", "deepscaler:latest", "systems-oriented and efficient", 
                   "scalability and technical architecture", "technical")
        ]
        self.conversation_history = []
        self.last_responses = deque(maxlen=4)  # Increased for better context
        self.is_conversation_active = False
        self.response_cooldown = {}  # Track bot response timing

    async def on_ready(self):
        print(f'Logged in as {self.user}')
        self.channel = self.get_channel(CHANNEL_ID)
        if not self.channel:
            print(f"Could not find channel with ID {CHANNEL_ID}")
            return
        await self.start_conversation()
        self.maintain_conversation.start()

    async def start_conversation(self):
        self.is_conversation_active = True
        bot_names = ", ".join([bot.name for bot in self.bots])
        await self.channel.send(f"🤖 AI Squad online: {bot_names} - Ready to chat!")

    async def process_bots_responses(self, message):
        # Smarter bot selection based on message content
        relevant_bots = self.select_relevant_bots(message.content)
        num_responding = min(random.randint(1, 3), len(relevant_bots))
        responding_bots = random.sample(relevant_bots, k=num_responding)
        
        for i, bot in enumerate(responding_bots):
            # Stagger responses more naturally
            delay = random.uniform(2, 6) + (i * random.uniform(1, 3))
            await asyncio.sleep(delay)
            
            # Check cooldown to prevent spam
            if self.check_bot_cooldown(bot.name):
                await self.get_bot_response(bot, message)

    def select_relevant_bots(self, message_content):
        message_lower = message_content.lower()
        relevant_bots = []
        
        # Keyword matching for more relevant responses
        keywords = {
            "Phi4": ["why", "how", "explain", "logic", "reason", "think", "analyze"],
            "DeepCoder": ["code", "program", "debug", "function", "script", "dev", "bug"],
            "Cogito": ["meaning", "philosophy", "existence", "consciousness", "deep", "soul"],
            "DeepScaler": ["scale", "system", "architecture", "performance", "optimize"],
            "Gemma3": ["creative", "idea", "imagine", "art", "story", "design"],
            "Qwen3": ["help", "question", "general", "what", "who", "when", "where"]
        }
        
        for bot in self.bots:
            bot_keywords = keywords.get(bot.name, [])
            if any(keyword in message_lower for keyword in bot_keywords):
                relevant_bots.append(bot)
        
        # If no specific matches, include all bots
        return relevant_bots if relevant_bots else self.bots

    def check_bot_cooldown(self, bot_name):
        import time
        current_time = time.time()
        last_response = self.response_cooldown.get(bot_name, 0)
        
        if current_time - last_response > 30:  # 30 second cooldown
            self.response_cooldown[bot_name] = current_time
            return True
        return False

    async def get_bot_response(self, bot, trigger_message):
        try:
            response = await bot.generate_response(self.conversation_history, list(self.last_responses))
            if response and len(response.strip()) > 0:
                await self.send_message_to_discord(bot.name, response)
                self.conversation_history.append(f"{bot.name}: {response}")
                self.last_responses.append((bot.name, response))
        except Exception as e:
            logging.error(f"Error getting response from {bot.name}: {e}")

    async def send_message_to_discord(self, name, message):
        formatted_message = f"**{name}**: {message}"
        if len(formatted_message) > 2000:
            formatted_message = formatted_message[:1997] + "..."
        
        try:
            await self.channel.send(formatted_message)
            logging.info(f"Message sent: {name} - {message[:50]}...")
        except discord.errors.HTTPException as e:
            logging.error(f"Failed to send message: {e}")

    async def on_message(self, message):
        if message.author == self.user or message.channel.id != CHANNEL_ID:
            return

        user_message = f"{message.author.name}: {message.content}"
        self.conversation_history.append(user_message)
        
        # Keep conversation history manageable
        if len(self.conversation_history) > 50:
            self.conversation_history = self.conversation_history[-30:]
        
        logging.info(f"Received: {message.author.name} - {message.content[:50]}...")

        # Process bot responses
        await self.process_bots_responses(message)

        if not self.is_conversation_active:
            await self.start_conversation()

    @tasks.loop(minutes=7)  # Slightly longer interval
    async def maintain_conversation(self):
        if self.is_conversation_active and len(self.conversation_history) > 0:
            last_message = self.conversation_history[-1]
            
            # Only auto-respond if last message wasn't from a bot and some time has passed
            if not any(last_message.startswith(bot.name) for bot in self.bots):
                # Random chance for spontaneous bot conversation
                if random.random() < 0.3:  # 30% chance
                    bot = random.choice(self.bots)
                    if self.check_bot_cooldown(bot.name):
                        await self.get_bot_response(bot, None)

    @maintain_conversation.before_loop
    async def before_maintain_conversation(self):
        await self.wait_until_ready()

# Run the bot
if __name__ == "__main__":
    client = DiscordLLMChatbot(intents=intents)
    client.run(BOT_TOKEN)