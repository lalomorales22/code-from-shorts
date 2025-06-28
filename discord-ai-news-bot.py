import discord
from discord.ext import tasks
import feedparser

# Specify intents for the bot
intents = discord.Intents.default()
intents.messages = True  # Enable if your bot needs to read messages
intents.guilds = True    # Enable if your bot needs to interact with guilds

# Discord bot token - Replace 'your_actual_bot_token_here' with your bot's token
BOT_TOKEN = 'enter-your-bot-token-here'

# Discord channel ID where messages will be sent
CHANNEL_ID = enter-your-channel-id-here  # Replace with your channel ID

# List of RSS feed URLs to monitor

RSS_FEED_URLS = [
    'https://tldr.tech/api/rss/tech',
    'http://feeds.feedburner.com/TechCrunch/',
    'https://techcrunch.com/feed/',
    'https://www.artificialintelligence-news.com/feed/',
    'https://news.mit.edu/topic/mitartificial-intelligence2-rss.xml',
    'https://news.mit.edu/rss/topic/robotics',
    'https://news.mit.edu/rss/topic/algorithms',
    'https://news.mit.edu/rss/topic/computing',
    'https://news.mit.edu/rss/topic/human-computer-interaction',
    'https://news.mit.edu/rss/topic/computer-science',
    'https://news.mit.edu/rss/topic/history-science',
    'https://news.mit.edu/rss/topic/quantum-computing',
    'https://www.aitrends.com/feed/',
    'https://blogs.nvidia.com/feed/',
    'https://theaisummer.com/feed.xml',
    'https://www.kdnuggets.com/feed',
    'https://www.marktechpost.com/feed/',
    'https://becominghuman.ai/feed',
    'https://machinelearningmastery.com/feed/',
    'https://medium.com/feed/ai-roadmap-institute',
    'https://techxplore.com/rss-feed/machine-learning-ai-news/',
    'https://blog.paperspace.com/rss/',
    'https://www.aiweirdness.com/rss/',
    'https://techmeme.com/feed.xml',  # Techmeme: Essential tech news
    'https://feeds.arstechnica.com/arstechnica/index',  # Ars Technica: News and reviews
    'https://engadget.com/rss.xml',  # Engadget: Consumer tech news and reviews
    'https://theverge.com/rss/index.xml',  # The Verge: Technology, science, art, and culture
    'https://androidauthority.com/feed',  # Android Authority: Android news, reviews, and tips
    'https://pcworld.com/index.rss',  # PCWorld: News, tips and reviews on PCs, Windows, and more
    'https://feeds.feedburner.com/thenextweb',  # The Next Web: Internet technology, business and culture
    'http://feeds.feedburner.com/ServeTheHome',
    'https://adamtheautomator.com/feed/',
    'https://4sysops.com/feed/',
    'https://singularityhub.com/tag/artificial-intelligence/',
    'https://techxplore.com/machine-learning-ai-news/',
    'https://slashdot.org/search/ai',
    'https://dev.to/feed/',
    'https://www.404media.co/rss',
    'https://magazine.sebastianraschka.com/feed',
    'https://aiacceleratorinstitute.com/rss/',
    'https://ai-techpark.com/category/ai/feed/',
    'https://knowtechie.com/category/ai/feed/',
    'https://aimodels.substack.com/feed',
    'https://www.artificialintelligence-news.com/feed/rss/',
    'https://venturebeat.com/category/ai/feed/',
    'https://ainowinstitute.org/category/news/feed',
    'https://siliconangle.com/category/ai/feed',
    'https://aisnakeoil.substack.com/feed',
    'https://www.anaconda.com/blog/feed',
    'https://analyticsindiamag.com/feed/',
    'https://feeds.arstechnica.com/arstechnica/index',
    'https://theconversation.com/europe/topics/artificial-intelligence-ai-90/articles.atom',
    'https://www.theguardian.com/technology/artificialintelligenceai/rss',
    'https://spacenews.com/tag/artificial-intelligence/feed/',
    'https://futurism.com/categories/ai-artificial-intelligence/feed',
    'https://www.wired.com/feed/tag/ai/latest/rss',
    'https://www.sciencedaily.com/rss/computers_math/artificial_intelligence.xml',
    'https://www.techrepublic.com/rssfeeds/topic/artificial-intelligence/',
    'https://medium.com/feed/artificialis',
    'https://siliconangle.com/category/big-data/feed',
    'https://machinelearningmastery.com/blog/feed',
    'https://davidstutz.de/category/blog/feed',
    'https://www.together.xyz/blog?format=rss',
    'https://neptune.ai/blog/feed',
    'https://blog.eleuther.ai/index.xml',
    'https://pyimagesearch.com/blog/feed',
    'https://feeds.bloomberg.com/technology/news.rss',
    'https://feeds.businessinsider.com/custom/all',
    'https://www.wired.com/feed/category/business/latest/rss',
    'https://every.to/chain-of-thought/feed.xml',
    'https://huyenchip.com/feed',
    'https://txt.cohere.ai/rss/',
    'https://news.crunchbase.com/feed',
    'https://arxiv.org/rss/cs.CL',
    'https://arxiv.org/rss/cs.CV',
    'https://arxiv.org/rss/cs.LG',
    'https://dagshub.com/blog/rss/',
    'https://www.darkreading.com/rss_simple.asp',
    'https://www.databricks.com/feed',
    'https://datafloq.com/feed/?post_type=post',
    'https://datamachina.substack.com/feed',
    'https://www.datanami.com/feed/',
    'https://debuggercafe.com/feed/',
    'https://deephaven.io/blog/rss.xml',
    'https://deepmind.com/blog/feed/basic/',
    'https://tech.eu/category/deep-tech/feed',
    'https://departmentofproduct.substack.com/feed',
    'https://dev.to/feed',
    'https://www.eetimes.com/feed',
    'https://www.engadget.com/rss.xml',
    'https://eugeneyan.com/rss/',
    'https://explosion.ai/feed',
    'https://www.freethink.com/feed/all',
    'https://www.generational.pub/feed',
    'https://www.forrester.com/blogs/category/artificial-intelligence-ai/feed',
    'https://www.ghacks.net/feed/',
    'https://gizmodo.com/rss',
    'https://globalnews.ca/tag/artificial-intelligence/feed',
    'http://googleaiblog.blogspot.com/atom.xml',
    'https://gradientflow.com/feed/',
    'https://hackernoon.com/tagged/ai/feed',
    'https://feeds.feedburner.com/HealthTechMagazine',
    'https://huggingface.co/blog/feed.xml',
    'https://spectrum.ieee.org/feeds/topic/artificial-intelligence.rss',
    'https://insidebigdata.com/feed',
    'https://www.interconnects.ai/feed',
    'https://www.ibtimes.com/rss',
    'https://www.jmlr.org/jmlr.xml',
    'https://www.kdnuggets.com/feed',
    'https://blog.langchain.dev/rss/',
    'https://lastweekin.ai/feed',
    'https://www.latent.space/feed',
    'https://www.zdnet.com/topic/artificial-intelligence/rss.xml',
    'https://lightning.ai/pages/feed/',
    'https://www.bmc.com/blogs/categories/machine-learning-big-data/feed',
    'https://blog.ml.cmu.edu/feed',
    'https://www.marktechpost.com/feed',
    'https://www.microsoft.com/en-us/research/feed/',
    'https://news.mit.edu/topic/mitmachine-learning-rss.xml',
    'https://www.technologyreview.com/feed/',
    'https://www.sciencedaily.com/rss/computers_math/neural_interfaces.xml',
    'https://www.newscientist.com/subject/technology/feed/',
    'https://phys.org/rss-feed/technology-news/machine-learning-ai/',
    'https://techxplore.com/rss-feed/machine-learning-ai-news/',
    'https://www.assemblyai.com/blog/rss/',
    'https://nicholas.carlini.com/writing/feed.xml',
    'https://developer.nvidia.com/blog/feed',
    'https://rss.nytimes.com/services/xml/rss/nyt/Technology.xml',
    'https://www.oneusefulthing.org/feed',
    'https://blog.paperspace.com/rss/',
    'https://petapixel.com/feed',
    'https://erichartford.com/rss.xml',
    'https://minimaxir.com/post/index.xml',
    'https://www.producthunt.com/feed',
    'https://feeds.feedburner.com/PythonInsider',
    'https://api.quantamagazine.org/feed',
    'https://medium.com/feed/radix-ai-blog',
    'https://feeds.feedburner.com/RBloggers',
    'https://replicate.com/blog/rss',
    'https://notes.replicatecodex.com/rss/',
    'https://restofworld.org/feed/latest',
    'https://www.sciencedaily.com/rss/computers_math/robotics.xml',
    'https://tech.eu/category/robotics/feed',
    'http://rss.sciam.com/ScientificAmerican-Global',
    'https://www.semianalysis.com/feed',
    'https://www.siliconrepublic.com/feed',
    'https://simonwillison.net/atom/everything/',
    'https://stackoverflow.blog/feed/',
    'https://crfm.stanford.edu/feed',
    'https://arxiv.org/rss/stat.ML',
    'https://medium.com/feed/@netflixtechblog',
    'https://medium.com/feed/@odsc',
    'https://syncedreview.com/feed',
    'https://synthedia.substack.com/feed',
    'https://techcrunch.com/feed/',
    'https://www.techmeme.com/feed.xml',
    'https://techmonitor.ai/feed',
    'https://www.reutersagency.com/feed/?best-topics=tech',
    'https://www.techspot.com/backend.xml',
    'https://bdtechtalks.com/feed/',
    'https://thealgorithmicbridge.substack.com/feed',
    'https://bair.berkeley.edu/blog/feed.xml',
    'https://the-decoder.com/feed/',
    'https://thegradient.pub/rss/',
    'https://www.theintrinsicperspective.com/feed',
    'https://thenewstack.io/feed',
    'https://thenextweb.com/neural/feed',
    'https://www.theregister.com/software/ai_ml/headlines.atom',
    'https://rss.beehiiv.com/feeds/2R3C6Bt5wj.xml',
    'https://thesequence.substack.com/feed',
    'https://anchor.fm/s/fb1e8218/podcast/rss'
    # Add more RSS feed URLs as needed
]

# Time interval in seconds for checking the feeds
CHECK_INTERVAL = 300

# Keep track of the latest post published for each feed
latest_posts = {feed_url: '' for feed_url in RSS_FEED_URLS}

# Initialize the client with the specified intents
client = discord.Client(intents=intents)

@tasks.loop(seconds=CHECK_INTERVAL)
async def check_feeds():
    for feed_url in RSS_FEED_URLS:
        feed = feedparser.parse(feed_url)
        if feed.entries:
            newest_post = feed.entries[0]
            # Check if 'id' attribute exists, fallback to 'link' if not
            post_id = newest_post.get('id', newest_post.link)  # Modified line
            if latest_posts[feed_url] == '' or post_id != latest_posts[feed_url]:
                # Handle first run or new post
                channel = client.get_channel(CHANNEL_ID)
                if channel:  # Check if the channel was found
                    await channel.send(f"**{newest_post.title}**\n{newest_post.link}")
                    latest_posts[feed_url] = post_id  # Modified line
                else:
                    print(f"Could not find channel with ID {CHANNEL_ID}")
            else:
                print("No new posts to publish for feed:", feed_url)
        else:
            print("No entries found in feed:", feed_url)


@client.event
async def on_ready():
    print(f'Logged in as {client.user}')
    check_feeds.start()  # Start the task to check the feeds

client.run(BOT_TOKEN)