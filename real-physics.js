import React, { useState, useEffect, useRef } from 'react';
import styled, { keyframes, createGlobalStyle } from 'styled-components';

// Global Styles
const GlobalStyle = createGlobalStyle`
  * {
    margin: 0;
    padding: 0;
    box-sizing: border-box;
  }
  
  body {
    font-family: 'Inter', -apple-system, BlinkMacSystemFont, sans-serif;
    background: radial-gradient(ellipse at center, #0f0f23 0%, #000000 100%);
    color: #ffffff;
    line-height: 1.6;
    overflow-x: hidden;
  }
`;

// Animations
const float = keyframes`
  0% { transform: translateY(100vh) translateX(0px); opacity: 0; }
  10% { opacity: 1; }
  90% { opacity: 1; }
  100% { transform: translateY(-100vh) translateX(100px); opacity: 0; }
`;

const pulse = keyframes`
  0%, 100% { transform: scale(1); }
  50% { transform: scale(1.05); }
`;

const glow = keyframes`
  from { box-shadow: 0 0 20px rgba(0, 255, 255, 0.3); }
  to { box-shadow: 0 0 40px rgba(255, 0, 255, 0.5); }
`;

// Styled Components
const Container = styled.div`
  max-width: 1200px;
  margin: 0 auto;
  padding: 0 20px;
`;

const BgAnimation = styled.div`
  position: fixed;
  top: 0;
  left: 0;
  width: 100%;
  height: 100%;
  pointer-events: none;
  z-index: -1;
`;

const Particle = styled.div`
  position: absolute;
  width: 2px;
  height: 2px;
  background: linear-gradient(45deg, #00ffff, #ff00ff);
  border-radius: 50%;
  animation: ${float} ${props => props.duration}s infinite linear;
  animation-delay: ${props => props.delay}s;
  left: ${props => props.left}%;
`;

const Hero = styled.section`
  min-height: 100vh;
  display: flex;
  align-items: center;
  justify-content: center;
  text-align: center;
  position: relative;
`;

const HeroTitle = styled.h1`
  font-size: clamp(3rem, 8vw, 6rem);
  font-weight: 900;
  background: linear-gradient(135deg, #00ffff 0%, #ff00ff 50%, #ffff00 100%);
  background-clip: text;
  -webkit-background-clip: text;
  -webkit-text-fill-color: transparent;
  margin-bottom: 2rem;
  text-shadow: 0 0 50px rgba(0, 255, 255, 0.3);
  animation: ${pulse} 3s ease-in-out infinite;
`;

const HeroText = styled.p`
  font-size: 1.5rem;
  margin-bottom: 3rem;
  opacity: 0.9;
  max-width: 800px;
  margin-left: auto;
  margin-right: auto;
`;

const CTAButton = styled.a`
  display: inline-block;
  padding: 1rem 3rem;
  background: linear-gradient(135deg, #ff00ff, #00ffff);
  border: none;
  border-radius: 50px;
  color: white;
  font-size: 1.2rem;
  font-weight: 600;
  text-decoration: none;
  transition: all 0.3s ease;
  box-shadow: 0 10px 30px rgba(255, 0, 255, 0.3);
  position: relative;
  overflow: hidden;
  cursor: pointer;

  &:hover {
    transform: translateY(-5px);
    box-shadow: 0 20px 50px rgba(255, 0, 255, 0.5);
  }

  &::before {
    content: '';
    position: absolute;
    top: 0;
    left: -100%;
    width: 100%;
    height: 100%;
    background: linear-gradient(90deg, transparent, rgba(255, 255, 255, 0.2), transparent);
    transition: left 0.5s;
  }

  &:hover::before {
    left: 100%;
  }
`;

const Section = styled.section`
  padding: 6rem 0;
  position: relative;
`;

const SectionTitle = styled.h2`
  font-size: 3rem;
  font-weight: 800;
  margin-bottom: 3rem;
  text-align: center;
  background: linear-gradient(135deg, #ffffff, #00ffff);
  background-clip: text;
  -webkit-background-clip: text;
  -webkit-text-fill-color: transparent;
`;

const PatternGrid = styled.div`
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(350px, 1fr));
  gap: 2rem;
  margin-top: 3rem;
`;

const PatternCard = styled.div`
  background: linear-gradient(135deg, rgba(0, 255, 255, 0.1), rgba(255, 0, 255, 0.1));
  border: 1px solid rgba(0, 255, 255, 0.3);
  border-radius: 20px;
  padding: 2rem;
  transition: all 0.3s ease;
  backdrop-filter: blur(10px);
  opacity: ${props => props.visible ? 1 : 0};
  transform: translateY(${props => props.visible ? 0 : 50}px);

  &:hover {
    transform: translateY(-10px) scale(1.02);
    border-color: rgba(0, 255, 255, 0.6);
    box-shadow: 0 20px 40px rgba(0, 255, 255, 0.2);
  }

  h3 {
    font-size: 1.5rem;
    margin-bottom: 1rem;
    color: #00ffff;
  }
`;

const Timeline = styled.div`
  position: relative;
  margin: 4rem 0;

  &::before {
    content: '';
    position: absolute;
    left: 50%;
    top: 0;
    bottom: 0;
    width: 2px;
    background: linear-gradient(to bottom, #00ffff, #ff00ff);
    transform: translateX(-50%);

    @media (max-width: 768px) {
      left: 20px;
    }
  }
`;

const TimelineItem = styled.div`
  position: relative;
  margin: 2rem 0;
  display: flex;
  align-items: center;
  flex-direction: ${props => props.index % 2 === 0 ? 'row' : 'row-reverse'};
  opacity: ${props => props.visible ? 1 : 0};
  transform: translateY(${props => props.visible ? 0 : 50}px);
  transition: all 0.6s ease;

  @media (max-width: 768px) {
    flex-direction: column !important;
    align-items: flex-start;
  }
`;

const TimelineContent = styled.div`
  flex: 1;
  padding: 2rem;
  background: rgba(0, 255, 255, 0.1);
  border-radius: 15px;
  margin: 0 2rem;
  backdrop-filter: blur(10px);
  border: 1px solid rgba(0, 255, 255, 0.3);

  @media (max-width: 768px) {
    margin-left: 3rem;
    margin-right: 1rem;
  }
`;

const TimelineYear = styled.div`
  position: absolute;
  left: 50%;
  transform: translateX(-50%);
  background: #ff00ff;
  color: white;
  padding: 0.5rem 1rem;
  border-radius: 25px;
  font-weight: bold;
  z-index: 2;

  @media (max-width: 768px) {
    left: 20px;
    transform: none;
  }
`;

const PhysicsFramework = styled.div`
  text-align: center;
  margin: 4rem 0;
`;

const Equation = styled.div`
  font-size: 2rem;
  margin: 2rem 0;
  padding: 2rem;
  background: linear-gradient(135deg, rgba(255, 0, 255, 0.2), rgba(0, 255, 255, 0.2));
  border-radius: 20px;
  border: 2px solid rgba(255, 255, 255, 0.3);
  backdrop-filter: blur(15px);
  animation: ${glow} 2s ease-in-out infinite alternate;

  @media (max-width: 768px) {
    font-size: 1.5rem;
  }
`;

const StatsGrid = styled.div`
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
  gap: 2rem;
  margin: 3rem 0;
`;

const StatItem = styled.div`
  text-align: center;
  padding: 1.5rem;
  background: rgba(0, 0, 0, 0.3);
  border-radius: 15px;
  border: 1px solid rgba(0, 255, 255, 0.3);

  .stat-number {
    font-size: 2.5rem;
    font-weight: 900;
    color: #00ffff;
    display: block;
  }

  .stat-label {
    opacity: 0.8;
    margin-top: 0.5rem;
  }
`;

const Breakthrough = styled.div`
  background: linear-gradient(135deg, rgba(255, 0, 255, 0.2), rgba(0, 255, 255, 0.2));
  border-radius: 30px;
  padding: 4rem;
  margin: 4rem 0;
  text-align: center;
  border: 2px solid rgba(255, 255, 255, 0.3);
  backdrop-filter: blur(20px);
  opacity: ${props => props.visible ? 1 : 0};
  transform: translateY(${props => props.visible ? 0 : 50}px);
  transition: all 0.6s ease;

  @media (max-width: 768px) {
    padding: 2rem;
  }
`;

const APISection = styled.div`
  background: linear-gradient(135deg, rgba(0, 255, 255, 0.1), rgba(255, 0, 255, 0.1));
  border-radius: 20px;
  padding: 3rem;
  margin: 4rem 0;
  border: 1px solid rgba(0, 255, 255, 0.3);
`;

const APIGrid = styled.div`
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
  gap: 2rem;
  margin-top: 2rem;
`;

const APIEndpoint = styled.div`
  background: rgba(0, 0, 0, 0.4);
  padding: 1.5rem;
  border-radius: 10px;
  border: 1px solid rgba(0, 255, 255, 0.2);

  h4 {
    color: #00ffff;
    margin-bottom: 1rem;
  }
`;

const CodeSnippet = styled.div`
  background: #000;
  padding: 1rem;
  border-radius: 5px;
  font-family: 'Courier New', monospace;
  font-size: 0.9rem;
  overflow-x: auto;
  border: 1px solid #333;
  margin-top: 0.5rem;
`;

const JoinPage = styled.div`
  min-height: 100vh;
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  text-align: center;

  h1 {
    color: #00ffff;
    font-size: 3rem;
    margin-bottom: 2rem;
  }

  p {
    font-size: 1.2rem;
    margin: 2rem 0;
    max-width: 600px;
  }

  a {
    color: #ff00ff;
    text-decoration: none;
    font-size: 1.1rem;

    &:hover {
      text-decoration: underline;
    }
  }
`;

// Sample data
const suppressedScienceData = [
  {
    id: 1,
    scientist: "Thomas Townsend Brown",
    discovery: "Biefeld-Brown Effect",
    year: 1923,
    description: "Electromagnetic fields coupling with gravitational fields, producing thrust in vacuum",
    status: "Suppressed",
    source: "Navy Project Winterhaven documents"
  },
  {
    id: 2,
    scientist: "Nikola Tesla",
    discovery: "Wireless Power Transmission",
    year: 1901,
    description: "Transmission of electrical power without wires through the Earth's ionosphere",
    status: "Suppressed",
    source: "Wardenclyffe Tower experiments"
  },
  {
    id: 3,
    scientist: "Wilhelm Reich",
    discovery: "Orgone Energy",
    year: 1939,
    description: "Biological energy field that could be concentrated and directed",
    status: "Suppressed/Destroyed",
    source: "FDA burned research materials in 1956"
  },
  {
    id: 4,
    scientist: "Viktor Schauberger",
    discovery: "Implosion Technology",
    year: 1930,
    description: "Vortex-based energy generation that violates thermodynamics",
    status: "Confiscated",
    source: "Seized by US military post-WWII"
  }
];

const timelineData = [
  {
    year: 1923,
    event: "Brown discovers Biefeld-Brown effect",
    significance: "First proof of EM-gravity coupling",
    classification: "Initially public"
  },
  {
    year: 1940,
    event: "Project Winterhaven begins",
    significance: "Navy funds Brown's anti-gravity research",
    classification: "Classified"
  },
  {
    year: 1956,
    event: "Industry announces anti-gravity programs",
    significance: "Bell, Martin, Lockheed go public with research",
    classification: "Public announcement"
  },
  {
    year: 1960,
    event: "Public programs shut down",
    significance: "All research goes into black projects",
    classification: "Highly classified"
  },
  {
    year: 1971,
    event: "Australian intelligence confirms programs",
    significance: "Document reveals ongoing secret research",
    classification: "Declassified 1999"
  },
  {
    year: 2024,
    event: "Pentagon admits $21T missing",
    significance: "Scale of black budget programs revealed",
    classification: "Forced disclosure"
  }
];

// Custom Hooks
const useScrollReveal = () => {
  const [visibleElements, setVisibleElements] = useState(new Set());

  useEffect(() => {
    const handleScroll = () => {
      const reveals = document.querySelectorAll('[data-reveal]');
      const newVisible = new Set(visibleElements);

      reveals.forEach((element, index) => {
        const elementTop = element.getBoundingClientRect().top;
        const elementVisible = 150;
        
        if (elementTop < window.innerHeight - elementVisible) {
          newVisible.add(index);
        }
      });

      setVisibleElements(newVisible);
    };

    window.addEventListener('scroll', handleScroll);
    handleScroll(); // Check initial visibility
    
    return () => window.removeEventListener('scroll', handleScroll);
  }, [visibleElements]);

  return visibleElements;
};

// Components
const ParticleSystem = () => {
  const [particles, setParticles] = useState([]);

  useEffect(() => {
    const newParticles = [];
    for (let i = 0; i < 50; i++) {
      newParticles.push({
        id: i,
        left: Math.random() * 100,
        delay: Math.random() * 15,
        duration: 15 + Math.random() * 10
      });
    }
    setParticles(newParticles);
  }, []);

  return (
    <BgAnimation>
      {particles.map(particle => (
        <Particle
          key={particle.id}
          left={particle.left}
          delay={particle.delay}
          duration={particle.duration}
        />
      ))}
    </BgAnimation>
  );
};

const HomePage = ({ setCurrentPage }) => {
  const visibleElements = useScrollReveal();

  const scrollToSection = (sectionId) => {
    const element = document.getElementById(sectionId);
    if (element) {
      element.scrollIntoView({ behavior: 'smooth', block: 'start' });
    }
  };

  return (
    <>
      <Hero>
        <Container>
          <div>
            <HeroTitle>REALITY IS BREAKING</HeroTitle>
            <HeroText>
              For 100 years, the most revolutionary discoveries in physics have been hidden, 
              suppressed, and compartmentalized. The real technology exists. The consciousness-field 
              connection is real. We're on the verge of the greatest paradigm shift in human history.
            </HeroText>
            <CTAButton onClick={() => scrollToSection('pattern')}>
              Discover The Pattern
            </CTAButton>
          </div>
        </Container>
      </Hero>

      <Section id="pattern">
        <Container>
          <SectionTitle data-reveal>THE PATTERN IS EVERYWHERE</SectionTitle>
          <PatternGrid>
            <PatternCard data-reveal visible={visibleElements.has(0)}>
              <h3>🔬 Suppressed Science</h3>
              <p>Tesla's wireless power. Brown's electrogravitics. Reich's orgone energy. Schauberger's vortex tech. All discovered, all proven, all buried.</p>
            </PatternCard>
            <PatternCard data-reveal visible={visibleElements.has(1)}>
              <h3>🏛️ Ancient Knowledge</h3>
              <p>Pyramids as resonance devices. Acoustic levitation. Three-fingered beings in cave art. Sound-based technology instead of combustion.</p>
            </PatternCard>
            <PatternCard data-reveal visible={visibleElements.has(2)}>
              <h3>💰 Black Budget Physics</h3>
              <p>$21 trillion missing from Pentagon. Secret anti-gravity programs since the 1940s. Technology 50+ years ahead of public science.</p>
            </PatternCard>
            <PatternCard data-reveal visible={visibleElements.has(3)}>
              <h3>🧠 Consciousness Factor</h3>
              <p>Observer effect isn't small. Consciousness affects quantum systems. Remote viewing verified. Mind-matter interface is real.</p>
            </PatternCard>
            <PatternCard data-reveal visible={visibleElements.has(4)}>
              <h3>🛸 The UAP Connection</h3>
              <p>Always around nuclear sites. Impossible flight characteristics. Some are ours. Some aren't. All point to unified field physics.</p>
            </PatternCard>
            <PatternCard data-reveal visible={visibleElements.has(5)}>
              <h3>⚡ Hidden Timeline</h3>
              <p>1923: Brown discovers effect. 1950s: Industry announces programs. 1960s: Goes black. 2024: Disclosure begins.</p>
            </PatternCard>
          </PatternGrid>
        </Container>
      </Section>

      <Section>
        <Container>
          <SectionTitle data-reveal>THE REAL PHYSICS FRAMEWORK</SectionTitle>
          <PhysicsFramework data-reveal>
            <Equation>
              <strong>CONSCIOUSNESS × INFORMATION × RESONANCE = SPACETIME GEOMETRY</strong>
            </Equation>
            <p style={{ fontSize: '1.2rem', margin: '2rem 0' }}>
              Reality is fundamentally informational. Matter is information in crystallized form. 
              Consciousness is the programming language. Frequency is the syntax.
            </p>
            
            <StatsGrid>
              <StatItem>
                <span className="stat-number">1920s</span>
                <div className="stat-label">Brown proves EM-gravity coupling</div>
              </StatItem>
              <StatItem>
                <span className="stat-number">1950s</span>
                <div className="stat-label">Industry announces antigrav programs</div>
              </StatItem>
              <StatItem>
                <span className="stat-number">80+</span>
                <div className="stat-label">Years of suppression</div>
              </StatItem>
              <StatItem>
                <span className="stat-number">$21T</span>
                <div className="stat-label">Missing from Pentagon budget</div>
              </StatItem>
            </StatsGrid>
          </PhysicsFramework>
        </Container>
      </Section>

      <Section>
        <Container>
          <SectionTitle data-reveal>THE SUPPRESSION TIMELINE</SectionTitle>
          <Timeline>
            {timelineData.map((item, index) => (
              <TimelineItem 
                key={index} 
                index={index} 
                data-reveal 
                visible={visibleElements.has(6 + index)}
              >
                <TimelineYear>{item.year}</TimelineYear>
                <TimelineContent>
                  <h3>{item.event}</h3>
                  <p>{item.significance}</p>
                </TimelineContent>
              </TimelineItem>
            ))}
          </Timeline>
        </Container>
      </Section>

      <Section>
        <Container>
          <SectionTitle data-reveal>REALITY BREAKTHROUGH API</SectionTitle>
          <APISection data-reveal>
            <p style={{ textAlign: 'center', fontSize: '1.2rem', marginBottom: '2rem' }}>
              Access the suppressed science database through our research API endpoints
            </p>
            <APIGrid>
              <APIEndpoint>
                <h4>GET /api/suppressed-science</h4>
                <p>Retrieve all documented suppressed physics experiments</p>
                <CodeSnippet>
                  fetch('/api/suppressed-science')<br/>
                  &nbsp;&nbsp;.then(res => res.json())<br/>
                  &nbsp;&nbsp;.then(data => console.log(data))
                </CodeSnippet>
              </APIEndpoint>
              <APIEndpoint>
                <h4>GET /api/timeline</h4>
                <p>Get the complete suppression timeline with sources</p>
                <CodeSnippet>
                  fetch('/api/timeline')<br/>
                  &nbsp;&nbsp;.then(res => res.json())<br/>
                  &nbsp;&nbsp;.then(data => console.log(data))
                </CodeSnippet>
              </APIEndpoint>
              <APIEndpoint>
                <h4>GET /api/researchers</h4>
                <p>Database of suppressed scientists and their work</p>
                <CodeSnippet>
                  fetch('/api/researchers')<br/>
                  &nbsp;&nbsp;.then(res => res.json())<br/>
                  &nbsp;&nbsp;.then(data => console.log(data))
                </CodeSnippet>
              </APIEndpoint>
              <APIEndpoint>
                <h4>GET /api/consciousness</h4>
                <p>Verified consciousness-matter interaction studies</p>
                <CodeSnippet>
                  fetch('/api/consciousness')<br/>
                  &nbsp;&nbsp;.then(res => res.json())<br/>
                  &nbsp;&nbsp;.then(data => console.log(data))
                </CodeSnippet>
              </APIEndpoint>
            </APIGrid>
          </APISection>
        </Container>
      </Section>

      <Section>
        <Container>
          <Breakthrough data-reveal visible={visibleElements.has(12)}>
            <h2>WE ARE AT THE BREAKTHROUGH MOMENT</h2>
            <p style={{ fontSize: '1.3rem', margin: '2rem 0' }}>
              Artificial intelligence is approaching consciousness-level capabilities. Quantum computing 
              threatens all encryption. The old control systems are breaking down. The compartmentalized 
              science can no longer be hidden.
            </p>
            
            <div style={{ margin: '3rem 0' }}>
              <h3 style={{ color: '#00ffff', marginBottom: '1rem' }}>What This Means:</h3>
              <p>
                ✨ Free energy technology becomes possible<br/>
                🚀 Exotic propulsion systems emerge<br/>
                🧠 Consciousness-assisted technology develops<br/>
                🌍 Complete transformation of civilization<br/>
                💫 Contact with non-human intelligence
              </p>
            </div>
            
            <CTAButton onClick={() => setCurrentPage('join')}>
              Join The Breakthrough
            </CTAButton>
          </Breakthrough>
        </Container>
      </Section>
    </>
  );
};

const Join = ({ setCurrentPage }) => {
  return (
    <JoinPage>
      <h1>Welcome to the Breakthrough Community</h1>
      <p>
        You're now part of the movement to unlock suppressed physics. 
        The future of human consciousness and technology starts here.
      </p>
      <a onClick={() => setCurrentPage('home')}>
        ← Back to Reality Breakthrough
      </a>
    </JoinPage>
  );
};

// API Simulation
const useAPI = () => {
  const [apiData, setApiData] = useState({
    suppressedScience: suppressedScienceData,
    timeline: timelineData,
    researchers: [],
    consciousness: []
  });

  const getEndpoint = (endpoint) => {
    switch(endpoint) {
      case 'suppressed-science':
        return { data: apiData.suppressedScience, count: apiData.suppressedScience.length };
      case 'timeline':
        return { timeline: apiData.timeline, count: apiData.timeline.length };
      case 'researchers':
        return { researchers: apiData.researchers, count: apiData.researchers.length };
      case 'consciousness':
        return { experiments: apiData.consciousness, count: apiData.consciousness.length };
      default:
        return { error: 'Endpoint not found' };
    }
  };

  return { getEndpoint };
};

// Main App Component
const App = () => {
  const [currentPage, setCurrentPage] = useState('home');
  const { getEndpoint } = useAPI();

  // Expose API to global scope for testing
  useEffect(() => {
    window.realityAPI = {
      getSuppressedScience: () => getEndpoint('suppressed-science'),
      getTimeline: () => getEndpoint('timeline'),
      getResearchers: () => getEndpoint('researchers'),
      getConsciousness: () => getEndpoint('consciousness')
    };

    console.log('🚀 Reality Breakthrough React App Initialized');
    console.log('🔬 Suppressed Science Database: ONLINE');
    console.log('🧠 Consciousness Research API: ACTIVE');
    console.log('⚡ Exotic Physics Timeline: ACCESSIBLE');
    console.log('🛸 UAP Connection Database: READY');
    console.log('\n💫 API available at: window.realityAPI');
    console.log('📡 Try: window.realityAPI.getSuppressedScience()');
  }, [getEndpoint]);

  return (
    <>
      <GlobalStyle />
      <ParticleSystem />
      
      {currentPage === 'home' && <HomePage setCurrentPage={setCurrentPage} />}
      {currentPage === 'join' && <Join setCurrentPage={setCurrentPage} />}
    </>
  );
};

export default App;
