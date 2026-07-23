import { Link } from '@inertiajs/react';
import {
    ArrowRight,
    Bot,
    BriefcaseBusiness,
    Building2,
    CheckCircle2,
    Code2,
    Factory,
    FileText,
    Globe2,
    Headphones,
    HeartPulse,
    Landmark,
    Layers3,
    Mail,
    MapPin,
    Megaphone,
    MessageCircle,
    MessagesSquare,
    Network,
    Phone,
    Plane,
    RefreshCcw,
    Rocket,
    ShieldCheck,
    ShoppingBag,
    Sparkles,
    Store,
    Truck,
    Users,
    WalletCards,
} from 'lucide-react';
import LandingLayout from '@/Layouts/LandingLayout';
import SeoHead from '@/Components/SeoHead';
import { Reveal } from '@/Components/Reveal';

const productLinks = [
    ['Products', '/products'],
    ['Chatbot building', '/chatbot-building'],
    ['Customer engagement', '/customer-engagement'],
    ['Contact center', '/contact-center'],
    ['Authentication & verification API', '/authentication-verification-api'],
    ['Number lookup', '/number-lookup'],
];

const channelLinks = [
    ['Channels', '/channels'],
    ['WhatsApp Business', '/whatsapp-business'],
    ['SMS', '/sms'],
    ['Voice', '/voice'],
    ['Email', '/email'],
    ['Instagram', '/instagram'],
    ['Messenger', '/messenger'],
    ['Facebook', '/facebook'],
];

const solutionLinks = [
    ['Solutions', '/solutions'],
    ['Marketing', '/marketing'],
    ['Sales', '/sales'],
    ['Customer service & support', '/customer-serviceand-support'],
    ['Enterprise', '/enterprise'],
    ['Retail & e-commerce', '/retail-e-commerce'],
    ['Finance & banking', '/finance-banking'],
    ['Healthcare', '/healthcare'],
    ['Travel & hospitality', '/travel-hospitality'],
    ['Insurance', '/insurance'],
    ['Logistics & transportation', '/logistics-transportation'],
    ['Wholesale', '/wholesale'],
];

const partnerLinks = [
    ['Partners', '/partners'],
    ['Digital marketing agencies', '/digital-marketing-agencies'],
    ['B2B startups', '/b2b-startups'],
    ['Telco partnerships', '/telco-partnerships'],
    ['System integrators', '/system-integrators'],
    ['Distributors & resellers', '/distributors-resellers'],
];

const resourceLinks = [
    ['Resources', '/resources'],
    ['API reference', '/api-reference'],
    ['Guides', '/guides'],
    ['Product updates', '/product-updates'],
    ['Customer stories', '/customers-stories'],
    ['FAQ & help center', '/faq'],
    ['Ask an industry expert', '/ask-an-industry-expert'],
    ['CX assessment', '/cx-assessment'],
];

const companyLinks = [
    ['About us', '/about-us'],
    ['Company values', '/company-values'],
    ['Meet the team', '/meet-the-team'],
    ['Careers', '/careers'],
    ['Job openings', '/job-openings'],
    ['Offices', '/offices'],
    ['Policies', '/policies'],
    ['Privacy', '/privacy'],
];

const categoryCards = [
    { title: 'Products', href: '/products', description: 'Core engagement tools for inbox, automation, APIs, verification and customer operations.', icon: Layers3, links: productLinks.slice(1, 5) },
    { title: 'Channels', href: '/channels', description: 'Meet customers wherever they already talk: WhatsApp, SMS, voice, email and social messaging.', icon: MessagesSquare, links: channelLinks.slice(1, 5) },
    { title: 'Solutions', href: '/solutions', description: 'Playbooks for teams, industries and business models that need measurable customer engagement.', icon: Rocket, links: solutionLinks.slice(1, 5) },
    { title: 'Partners', href: '/partners', description: 'Programs for agencies, telcos, system integrators, resellers and growth partners.', icon: Network, links: partnerLinks.slice(1, 5) },
    { title: 'Resources', href: '/resources', description: 'Guides, API references, product updates and expert help for building with Cerqle.', icon: FileText, links: resourceLinks.slice(1, 5) },
    { title: 'Company', href: '/about-us', description: 'Learn about Cerqle, our values, team, offices and career opportunities.', icon: Building2, links: companyLinks.slice(1, 5) },
];

const iconBySlug = {
    products: Layers3,
    'chatbot-building': Bot,
    'customer-engagement': Sparkles,
    'contact-center': Headphones,
    'authentication-verification-api': ShieldCheck,
    'number-lookup': Phone,
    channels: MessagesSquare,
    'whatsapp-business': MessageCircle,
    sms: MessageCircle,
    voice: Phone,
    email: Mail,
    instagram: Megaphone,
    facebook: Megaphone,
    messenger: MessageCircle,
    solutions: Rocket,
    marketing: Megaphone,
    sales: WalletCards,
    'customer-serviceand-support': Headphones,
    enterprise: Building2,
    wholesale: Store,
    'finance-banking': Landmark,
    'retail-e-commerce': ShoppingBag,
    healthcare: HeartPulse,
    'travel-hospitality': Plane,
    insurance: ShieldCheck,
    'logistics-transportation': Truck,
    partners: Network,
    'digital-marketing-agencies': Megaphone,
    'b2b-startups': Rocket,
    'telco-partnerships': Globe2,
    'system-integrators': Code2,
    'distributors-resellers': BriefcaseBusiness,
    resources: FileText,
    'api-reference': Code2,
    guides: FileText,
    'product-updates': RefreshCcw,
    'product-updates-2': RefreshCcw,
    'customers-stories': Users,
    'ask-an-industry-expert': Users,
    'cx-assessment': CheckCircle2,
    'cx-assessment-2': CheckCircle2,
    'cx-assessment-3': CheckCircle2,
    'about-us': Building2,
    'company-values': Sparkles,
    'meet-the-team': Users,
    careers: BriefcaseBusiness,
    'job-openings': BriefcaseBusiness,
    'open-jobs': BriefcaseBusiness,
    offices: MapPin,
    policies: ShieldCheck,
    privacy: ShieldCheck,
    industry: Factory,
    department: Users,
    'business-type': Store,
    services: Headphones,
};

const pageDefinitions = {
    products: {
        badge: 'Product suite',
        title: 'One customer engagement platform for every conversation.',
        description: 'Bring inbox, automation, campaigns, contact-center workflows and programmable APIs into one secure Cerqle workspace.',
        links: productLinks,
        features: ['Unified customer timelines', 'AI chatbot and workflow builder', 'Secure APIs for verification and lookup', 'Operational analytics across teams'],
    },
    channels: {
        badge: 'Channels',
        title: 'Reach customers across WhatsApp, SMS, voice, email and social messaging.',
        description: 'Design one journey and deliver it through the channels your customers already use every day.',
        links: channelLinks,
        features: ['One inbox for every channel', 'Reusable templates and automations', 'Conversation history in one customer view', 'Routing, assignment and response tracking'],
    },
    solutions: {
        badge: 'Solutions',
        title: 'Customer engagement playbooks for every team and industry.',
        description: 'Use Cerqle to automate support, accelerate sales, run campaigns and keep conversations moving from first touch to loyal customer.',
        links: solutionLinks,
        features: ['Marketing journeys', 'Sales follow-up automation', 'Support operations', 'Industry-specific communication workflows'],
    },
    partners: {
        badge: 'Partner ecosystem',
        title: 'Grow with Cerqle as an agency, telco, integrator or reseller.',
        description: 'Package omnichannel messaging, automation and customer engagement services around a platform built for scale.',
        links: partnerLinks,
        features: ['Partner-ready implementation paths', 'Multi-client engagement use cases', 'API-first extensibility', 'Programs for agencies, telcos and resellers'],
    },
    resources: {
        badge: 'Resources',
        title: 'Everything teams need to build smarter customer conversations.',
        description: 'Explore guides, API references, product updates, customer stories and expert resources for better engagement.',
        links: resourceLinks,
        features: ['Implementation guides', 'API and webhook references', 'Product announcements', 'CX assessment resources'],
    },
    'about-us': {
        badge: 'Company',
        title: 'Cerqle helps businesses communicate smarter across every channel.',
        description: 'We unify customer conversations, automate repetitive work and help teams turn engagement into growth.',
        links: companyLinks,
        features: ['Built for omnichannel communication', 'Focused on customer engagement', 'Designed for growing teams', 'A platform for measurable business conversations'],
    },
    'company-values': {
        badge: 'Values',
        title: 'Built around clarity, speed and customer trust.',
        description: 'Cerqle’s product principles are simple: keep teams close to customers, make automation useful, and keep every interaction accountable.',
        links: companyLinks,
        features: ['Customer-first communication', 'Useful automation over noise', 'Secure operational foundations', 'Continuous product improvement'],
    },
    'meet-the-team': {
        badge: 'Team',
        title: 'Meet the people building the future of customer engagement.',
        description: 'Cerqle is shaped by product, engineering and customer-focused operators who believe communication should feel effortless.',
        links: companyLinks,
        features: ['Product-minded builders', 'Customer engagement specialists', 'Engineering depth', 'Global business perspective'],
    },
    careers: {
        badge: 'Careers',
        title: 'Build customer engagement infrastructure with us.',
        description: 'Join a team working on communication tools for businesses that need speed, intelligence and reliability.',
        links: companyLinks,
        features: ['Product and engineering roles', 'Quality and operations focus', 'Collaborative team culture', 'Work on real customer problems'],
    },
    'job-openings': {
        badge: 'Open roles',
        title: 'Explore current opportunities at Cerqle.',
        description: 'Find roles where you can help improve omnichannel customer engagement, automation and platform reliability.',
        links: companyLinks,
        features: ['QA and testing opportunities', 'Engineering roles', 'Product operations', 'Customer implementation support'],
    },
    'open-jobs': {
        badge: 'Open roles',
        title: 'Explore current opportunities at Cerqle.',
        description: 'Find roles where you can help improve omnichannel customer engagement, automation and platform reliability.',
        links: companyLinks,
        features: ['QA and testing opportunities', 'Engineering roles', 'Product operations', 'Customer implementation support'],
    },
    offices: {
        badge: 'Offices',
        title: 'Cerqle works with teams and partners across markets.',
        description: 'Connect with Cerqle to discuss regional customer engagement needs, partnerships and implementation support.',
        links: companyLinks,
        features: ['Regional partnerships', 'Implementation support', 'Customer success collaboration', 'Market-specific engagement workflows'],
    },
    policies: {
        badge: 'Policies',
        title: 'Clear policies for secure, responsible customer engagement.',
        description: 'Review the standards and policy foundations behind Cerqle’s communication and customer engagement platform.',
        links: companyLinks,
        features: ['Data-aware communication', 'Responsible customer outreach', 'Secure access practices', 'Clear operational standards'],
    },
    privacy: {
        badge: 'Privacy',
        title: 'Privacy matters in every customer conversation.',
        description: 'Cerqle is designed for teams that need to manage customer communication with care, accountability and trust.',
        links: companyLinks,
        features: ['Customer data awareness', 'Controlled access', 'Secure communication workflows', 'Transparent handling practices'],
    },
    'api-reference': {
        badge: 'Developers',
        title: 'API reference for programmable customer engagement.',
        description: 'Use Cerqle APIs and webhooks to connect messaging, verification, automation and customer workflows into your stack.',
        links: resourceLinks,
        features: ['REST API patterns', 'Webhook-ready workflows', 'Channel orchestration', 'Verification and lookup use cases'],
    },
    'cx-assessment': {
        badge: 'Assessment',
        title: 'Assess your customer experience and find the next bottleneck.',
        description: 'Map the gaps in your customer communication, automation and support operations before you scale.',
        links: resourceLinks,
        features: ['Channel coverage review', 'Automation opportunity mapping', 'Response-time analysis', 'Growth-readiness checklist'],
    },
    'cx-assessment-2': {
        badge: 'Assessment',
        title: 'Assess your customer experience and find the next bottleneck.',
        description: 'Map the gaps in your customer communication, automation and support operations before you scale.',
        links: resourceLinks,
        features: ['Channel coverage review', 'Automation opportunity mapping', 'Response-time analysis', 'Growth-readiness checklist'],
    },
    'cx-assessment-3': {
        badge: 'Assessment',
        title: 'Assess your customer experience and find the next bottleneck.',
        description: 'Map the gaps in your customer communication, automation and support operations before you scale.',
        links: resourceLinks,
        features: ['Channel coverage review', 'Automation opportunity mapping', 'Response-time analysis', 'Growth-readiness checklist'],
    },
    survey: {
        badge: 'Survey',
        title: 'Capture customer feedback inside the conversation flow.',
        description: 'Use surveys and structured follow-up to understand customer sentiment, service quality and journey gaps.',
        links: resourceLinks,
        features: ['Post-conversation feedback', 'CSAT and service signals', 'Structured responses', 'Actionable experience insight'],
    },
    'live-chat-support-requests': {
        badge: 'Support',
        title: 'Handle live chat and support requests from one workspace.',
        description: 'Route customer questions, automate first replies and give agents the context they need to resolve issues quickly.',
        links: productLinks,
        features: ['Live request routing', 'Agent assignment', 'AI-assisted replies', 'Customer history at a glance'],
    },
};

const generatedPages = {
    'chatbot-building': ['Product', 'Build AI chatbots that resolve routine questions and hand off complex conversations gracefully.', productLinks, ['Visual chatbot flows', 'Human handoff', 'Reusable answers', 'Channel-ready automation']],
    'customer-engagement': ['Product', 'Turn every customer touchpoint into a connected journey across marketing, sales and support.', productLinks, ['Unified profiles', 'Lifecycle messaging', 'Campaign follow-ups', 'Cross-channel analytics']],
    'contact-center': ['Product', 'Give support teams a shared command center for high-volume customer conversations.', productLinks, ['Team inboxes', 'SLA-friendly routing', 'Agent collaboration', 'Conversation reporting']],
    'authentication-verification-api': ['Product', 'Add verification and authentication workflows to your customer journeys.', productLinks, ['OTP-ready flows', 'API-first delivery', 'Security-oriented messaging', 'Reliable verification touchpoints']],
    'number-lookup': ['Product', 'Validate and enrich phone-number workflows before outreach or verification.', productLinks, ['Number intelligence', 'Cleaner customer data', 'Reduced failed sends', 'Better routing decisions']],
    email: ['Channel', 'Run customer email alongside chat, SMS and social conversations.', channelLinks, ['Shared customer context', 'Email-to-inbox routing', 'Automation triggers', 'Campaign continuity']],
    sms: ['Channel', 'Deliver fast, direct SMS messages for alerts, campaigns and verification.', channelLinks, ['High-intent outreach', 'Transactional updates', 'Automation-ready templates', 'Unified reporting']],
    voice: ['Channel', 'Use voice as part of a connected customer engagement journey.', channelLinks, ['Call-aware workflows', 'Escalation paths', 'Conversation context', 'Support continuity']],
    'whatsapp-business': ['Channel', 'Create WhatsApp Business conversations that feel personal and operationally scalable.', channelLinks, ['Template messaging', 'AI-assisted replies', 'Team routing', 'Customer timeline history']],
    instagram: ['Channel', 'Manage Instagram conversations without losing customer context.', channelLinks, ['DM handling', 'Lead capture', 'Social support', 'Unified inbox history']],
    facebook: ['Channel', 'Bring Facebook conversations into the same customer engagement workspace.', channelLinks, ['Social messaging', 'Campaign responses', 'Agent routing', 'Cross-channel context']],
    messenger: ['Channel', 'Support customers through Messenger while keeping every conversation connected.', channelLinks, ['Messenger inbox', 'Automation rules', 'Support handoff', 'Customer profile context']],
    marketing: ['Solution', 'Launch campaigns and nurture leads across the channels customers actually use.', solutionLinks, ['Segmented outreach', 'Campaign automation', 'Lead capture', 'Performance visibility']],
    sales: ['Solution', 'Help sales teams respond faster, follow up smarter and convert more conversations.', solutionLinks, ['Lead qualification', 'Automated follow-up', 'Conversation reminders', 'Pipeline-friendly context']],
    'customer-serviceand-support': ['Solution', 'Resolve customer issues faster with AI, routing and shared conversation history.', solutionLinks, ['Support automation', 'Agent handoff', 'Request routing', 'Service analytics']],
    enterprise: ['Solution', 'Scale customer engagement across teams, regions and complex workflows.', solutionLinks, ['Role-aware operations', 'Governed automation', 'Multi-team workflows', 'Reliable reporting']],
    wholesale: ['Industry', 'Coordinate buyer communication, order updates and repeat engagement for wholesale teams.', solutionLinks, ['Order conversations', 'Bulk updates', 'Partner communication', 'Repeat purchase flows']],
    'finance-banking': ['Industry', 'Support secure, timely communication for finance and banking customer journeys.', solutionLinks, ['Verification flows', 'Service notifications', 'Support routing', 'Trust-focused messaging']],
    'retail-e-commerce': ['Industry', 'Automate retail conversations from product questions to post-purchase support.', solutionLinks, ['Cart recovery', 'Order updates', 'Product Q&A', 'Loyalty engagement']],
    healthcare: ['Industry', 'Keep patient and customer communication organized, timely and easy to follow.', solutionLinks, ['Appointment reminders', 'Support routing', 'Care instructions', 'Feedback collection']],
    'travel-hospitality': ['Industry', 'Manage booking, itinerary and guest-service conversations from one place.', solutionLinks, ['Booking updates', 'Guest support', 'Travel alerts', 'Service recovery']],
    insurance: ['Industry', 'Guide policyholders through questions, claims updates and service requests.', solutionLinks, ['Claims communication', 'Document reminders', 'Support routing', 'Renewal outreach']],
    'logistics-transportation': ['Industry', 'Keep logistics customers informed with proactive, channel-ready updates.', solutionLinks, ['Delivery alerts', 'Exception handling', 'Customer support', 'Partner updates']],
    'digital-marketing-agencies': ['Partner', 'Offer omnichannel engagement and automation services to your clients.', partnerLinks, ['Client-ready campaigns', 'Managed messaging', 'Automation packages', 'Performance reporting']],
    'b2b-startups': ['Partner', 'Help startups launch customer engagement without building messaging infrastructure from scratch.', partnerLinks, ['Fast implementation', 'Lead workflows', 'Scalable channels', 'API flexibility']],
    'telco-partnerships': ['Partner', 'Partner with Cerqle to package messaging and engagement services for business customers.', partnerLinks, ['Channel expertise', 'Business messaging', 'Implementation support', 'Growth programs']],
    'system-integrators': ['Partner', 'Integrate Cerqle into customer stacks with APIs, webhooks and flexible workflows.', partnerLinks, ['API-first platform', 'Workflow integration', 'Custom deployment paths', 'Reusable implementation patterns']],
    'distributors-resellers': ['Partner', 'Bring Cerqle’s customer engagement platform to new markets and business segments.', partnerLinks, ['Reseller-ready positioning', 'Market enablement', 'Partner support', 'Scalable offerings']],
    guides: ['Resource', 'Practical guides for building, launching and scaling customer engagement workflows.', resourceLinks, ['Implementation walkthroughs', 'Channel best practices', 'Automation ideas', 'Operational checklists']],
    'product-updates': ['Resource', 'Track the latest improvements across Cerqle products, channels and workflows.', resourceLinks, ['Release notes', 'Feature highlights', 'Platform improvements', 'Product direction']],
    'product-updates-2': ['Resource', 'Track the latest improvements across Cerqle products, channels and workflows.', resourceLinks, ['Release notes', 'Feature highlights', 'Platform improvements', 'Product direction']],
    'customers-stories': ['Resource', 'See how businesses use Cerqle to simplify communication and grow customer relationships.', resourceLinks, ['Team stories', 'Use-case examples', 'Operational wins', 'Growth lessons']],
    'ask-an-industry-expert': ['Resource', 'Talk through customer engagement challenges with people who understand the channel landscape.', resourceLinks, ['Expert guidance', 'Industry context', 'Workflow recommendations', 'Implementation advice']],
    industry: ['Solutions', 'Explore Cerqle use cases across high-touch, high-volume industries.', solutionLinks, ['Finance', 'Retail', 'Healthcare', 'Logistics']],
    department: ['Solutions', 'Give marketing, sales and support teams one shared engagement layer.', solutionLinks, ['Marketing', 'Sales', 'Support', 'Operations']],
    'business-type': ['Solutions', 'Adapt Cerqle to startups, enterprise teams, wholesale businesses and partner-led models.', solutionLinks, ['Startups', 'Enterprise', 'Wholesale', 'Partner teams']],
    services: ['Services', 'Get support planning, launching and optimizing customer engagement workflows.', productLinks, ['Implementation planning', 'Workflow design', 'Channel setup', 'Optimization support']],
    'features-and-functionality': ['Features', 'Explore the capabilities that power Cerqle’s omnichannel customer engagement platform.', productLinks, ['Unified inbox', 'AI automation', 'Broadcast campaigns', 'Analytics']],
    'conversational-use-cases': ['Use cases', 'Design conversational experiences for acquisition, support, retention and customer success.', solutionLinks, ['Lead capture', 'Customer support', 'Feedback loops', 'Retention campaigns']],
    'conversational-experiences': ['Use cases', 'Create conversational journeys that feel natural, useful and measurable.', solutionLinks, ['Guided journeys', 'AI replies', 'Human handoff', 'Outcome tracking']],
};

function titleFromSlug(slug) {
    return slug
        .replace(/-/g, ' ')
        .replace(/\band\b/g, '&')
        .replace(/\bcx\b/gi, 'CX')
        .replace(/\bapi\b/gi, 'API')
        .replace(/\bsms\b/gi, 'SMS')
        .replace(/\bb2b\b/gi, 'B2B')
        .replace(/\bwhatsapp\b/gi, 'WhatsApp')
        .replace(/\be commerce\b/gi, 'e-commerce')
        .replace(/\b\w/g, (letter) => letter.toUpperCase());
}

function pageForSlug(slug) {
    if (pageDefinitions[slug]) return pageDefinitions[slug];
    if (generatedPages[slug]) {
        const [badge, description, links, features] = generatedPages[slug];
        const title = `${titleFromSlug(slug)} for smarter customer engagement.`;
        return { badge, title, description, links, features };
    }
    return {
        badge: 'Cerqle',
        title: `${titleFromSlug(slug)} with Cerqle.`,
        description: 'A focused Cerqle page for teams building connected customer engagement across messaging, automation and support.',
        links: productLinks,
        features: ['Omnichannel messaging', 'AI automation', 'Shared customer context', 'Operational visibility'],
    };
}

function Badge({ children }) {
    return (
        <span className="inline-flex items-center gap-2 rounded-full border border-brand-200 bg-white/80 px-4 py-2 text-xs font-semibold uppercase tracking-[0.18em] text-brand-700 shadow-sm backdrop-blur">
            <span className="h-2 w-2 rounded-full bg-brand-500 shadow-[0_0_0_6px_rgba(143,95,167,0.12)]" />
            {children}
        </span>
    );
}

function LinkCard({ title, href, description, icon: Icon = Sparkles, links = [] }) {
    return (
        <Reveal className="group rounded-[2rem] border border-brand-100 bg-white p-6 shadow-[0_24px_80px_-48px_rgba(62,42,73,0.45)] transition-all duration-300 hover:-translate-y-1 hover:border-brand-300 hover:shadow-[0_28px_90px_-44px_rgba(143,95,167,0.6)]">
            <div className="flex h-12 w-12 items-center justify-center rounded-2xl bg-brand-50 text-brand-600 ring-1 ring-brand-100">
                <Icon className="h-6 w-6" />
            </div>
            <h3 className="mt-5 text-xl font-bold text-brand-950">{title}</h3>
            <p className="mt-3 text-sm leading-6 text-secondary-600">{description}</p>
            {links.length > 0 && (
                <div className="mt-5 space-y-2">
                    {links.map(([label, url]) => (
                        <Link key={url} href={url} className="flex items-center justify-between rounded-2xl border border-transparent px-3 py-2 text-sm font-semibold text-secondary-700 transition hover:border-brand-100 hover:bg-brand-50/70 hover:text-brand-700">
                            <span>{label}</span>
                            <ArrowRight className="h-4 w-4 opacity-50 transition group-hover:translate-x-0.5" />
                        </Link>
                    ))}
                </div>
            )}
        </Reveal>
    );
}

export default function CerqlePage({ slug, canRegister }) {
    const appName = import.meta.env.VITE_APP_NAME || 'Cerqle';
    const page = pageForSlug(slug);
    const Icon = iconBySlug[slug] || Sparkles;
    const isHub = ['products', 'channels', 'solutions', 'partners', 'resources'].includes(slug);

    return (
        <LandingLayout>
            <SeoHead title={`${page.title} — ${appName}`} description={page.description} />

            <section className="relative isolate overflow-hidden bg-[linear-gradient(180deg,#ffffff_0%,#fbf9fd_55%,#f5eff8_100%)]">
                <div className="absolute inset-x-0 top-0 -z-10 h-[34rem] bg-[radial-gradient(circle_at_50%_0%,rgba(143,95,167,0.2),transparent_58%)]" />
                <div className="mx-auto grid max-w-6xl items-center gap-12 px-4 pb-20 pt-24 sm:px-6 lg:grid-cols-[1.05fr_0.95fr] lg:px-8 lg:pt-28">
                    <div>
                        <Reveal y={12}><Badge>{page.badge}</Badge></Reveal>
                        <Reveal as="h1" delay={80} className="mt-7 max-w-4xl text-4xl font-bold leading-[1.05] tracking-tight text-brand-950 sm:text-6xl">
                            {page.title}
                        </Reveal>
                        <Reveal as="p" delay={160} className="mt-6 max-w-2xl text-base leading-8 text-secondary-600 sm:text-lg">
                            {page.description}
                        </Reveal>
                        <Reveal delay={240} className="mt-8 flex flex-wrap gap-3">
                            {canRegister && (
                                <Link href={route('register')} className="inline-flex items-center gap-2 rounded-full bg-brand-900 px-6 py-3 text-sm font-bold text-white shadow-[0_16px_44px_-20px_rgba(62,42,73,0.9)] transition hover:-translate-y-0.5 hover:bg-brand-800">
                                    Get started
                                    <ArrowRight className="h-4 w-4" />
                                </Link>
                            )}
                            <Link href="/contact" className="inline-flex items-center gap-2 rounded-full border border-brand-200 bg-white px-6 py-3 text-sm font-bold text-brand-900 transition hover:-translate-y-0.5 hover:border-brand-300 hover:bg-brand-50">
                                Talk to sales
                            </Link>
                        </Reveal>
                    </div>

                    <Reveal delay={140} className="relative">
                        <div className="absolute -inset-8 rounded-[3rem] bg-brand-500/10 blur-3xl" />
                        <div className="relative overflow-hidden rounded-[2rem] border border-brand-100 bg-white p-5 shadow-[0_30px_100px_-45px_rgba(62,42,73,0.55)]">
                            <div className="rounded-[1.5rem] bg-brand-950 p-5 text-white">
                                <div className="flex items-center justify-between">
                                    <div className="flex items-center gap-3">
                                        <div className="flex h-11 w-11 items-center justify-center rounded-2xl bg-white/10 ring-1 ring-white/15">
                                            <Icon className="h-6 w-6 text-brand-200" />
                                        </div>
                                        <div>
                                            <p className="text-sm font-semibold text-white">{page.badge}</p>
                                            <p className="text-xs text-white/50">Cerqle workspace</p>
                                        </div>
                                    </div>
                                    <span className="rounded-full bg-emerald-400/15 px-3 py-1 text-xs font-semibold text-emerald-200">Live</span>
                                </div>
                                <div className="mt-6 space-y-3">
                                    {page.features.map((feature, index) => (
                                        <div key={feature} className="flex items-center gap-3 rounded-2xl bg-white/[0.06] p-3 ring-1 ring-white/10">
                                            <span className="flex h-8 w-8 items-center justify-center rounded-xl bg-brand-500/25 text-sm font-bold text-brand-100">{index + 1}</span>
                                            <span className="text-sm text-white/80">{feature}</span>
                                        </div>
                                    ))}
                                </div>
                            </div>
                        </div>
                    </Reveal>
                </div>
            </section>

            <section className="bg-white py-20">
                <div className="mx-auto max-w-6xl px-4 sm:px-6 lg:px-8">
                    <Reveal className="mx-auto max-w-2xl text-center">
                        <p className="text-sm font-semibold uppercase tracking-[0.2em] text-brand-500">Explore Cerqle</p>
                        <h2 className="mt-3 text-3xl font-bold tracking-tight text-brand-950 sm:text-4xl">
                            {isHub ? 'Choose the area you want to build next.' : 'Keep exploring related Cerqle pages.'}
                        </h2>
                    </Reveal>
                    <div className="mt-12 grid gap-5 md:grid-cols-2 lg:grid-cols-3">
                        {(isHub ? categoryCards : categoryCards.filter((card) => page.links?.some(([, url]) => card.links.some(([, childUrl]) => childUrl === url)) || card.links.some(([, childUrl]) => page.links?.some(([, url]) => url === childUrl))).concat(categoryCards.slice(0, 3)).slice(0, 6)).map((card) => (
                            <LinkCard key={card.href} {...card} />
                        ))}
                    </div>
                </div>
            </section>

            <section className="border-y border-brand-100 bg-brand-950 py-20 text-white">
                <div className="mx-auto flex max-w-6xl flex-col items-start justify-between gap-8 px-4 sm:px-6 lg:flex-row lg:items-center lg:px-8">
                    <div>
                        <p className="text-sm font-semibold uppercase tracking-[0.2em] text-brand-200">Ready when you are</p>
                        <h2 className="mt-3 max-w-2xl text-3xl font-bold tracking-tight sm:text-4xl">Bring every customer conversation into one Cerqle workspace.</h2>
                    </div>
                    <Link href="/contact" className="inline-flex shrink-0 items-center gap-2 rounded-full bg-white px-6 py-3 text-sm font-bold text-brand-950 transition hover:-translate-y-0.5 hover:bg-brand-50">
                        Contact us
                        <ArrowRight className="h-4 w-4" />
                    </Link>
                </div>
            </section>
        </LandingLayout>
    );
}
