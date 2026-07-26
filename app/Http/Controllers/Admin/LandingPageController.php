<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\SystemSetting;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class LandingPageController extends Controller
{
    /**
     * Single source of truth for every editable marketing-site setting.
     * Public pages, the admin "Site Content" manager, and the LandingPageSeeder
     * all derive their keys/defaults from here so the three never drift.
     */
    public static function defaults(): array
    {
        return [
            // Master switch — when '0', public marketing pages redirect to login
            'landing.page_enabled' => '1',

            // ── Navbar ──────────────────────────────────────────────
            'landing.signin_label'         => 'Log in',
            'landing.signin_link_type'     => 'dynamic',
            'landing.signin_link_url'      => '',
            'landing.getstarted_label'     => 'Get Cerqle free',
            'landing.getstarted_link_type' => 'dynamic',
            'landing.getstarted_link_url'  => '',

            // ── SEO ─────────────────────────────────────────────────
            'landing.seo_title'       => 'Cerqle — Omnichannel Customer Support, Automated with AI',
            'landing.seo_description' => 'Cerqle unifies WhatsApp, Messenger, Instagram, email and live chat into one AI-powered support desk — answering instantly, routing smartly and resolving more conversations with less effort. Try it free, no card needed.',
            'landing.seo_keywords'    => 'WhatsApp Business API, shared team inbox, AI chatbot, conversational marketing, Messenger inbox, Instagram DM automation, bulk WhatsApp broadcast, customer messaging platform, chat CRM',
            'landing.seo_og_image'    => '',

            // ── Hero ────────────────────────────────────────────────
            'landing.hero_enabled'       => '1',
            'landing.hero_badge'         => 'AI-powered omnichannel support',
            'landing.hero_title'         => 'Omnichannel customer support, automated with AI',
            'landing.hero_subtitle'      => 'Cerqle brings every customer conversation — WhatsApp, Messenger, Instagram, email and live chat — into one AI-powered support desk that answers instantly, routes smartly, and never sleeps.',
            'landing.hero_cta_primary'   => 'Get started',
            'landing.hero_cta_secondary' => 'See how it works',
            'landing.hero_trust_1'       => 'Free 14-day trial',
            'landing.hero_trust_2'       => 'No card, no setup fees',
            'landing.hero_trust_3'       => 'Powered by official Meta APIs',

            // ── Metrics (numbers band) ──────────────────────────────
            'landing.metrics_enabled' => '1',
            'landing.metric_1_value'  => '68M+',
            'landing.metric_1_label'  => 'Messages handled monthly',
            'landing.metric_2_value'  => '14,500+',
            'landing.metric_2_label'  => 'Growing teams',
            'landing.metric_3_value'  => '99.98%',
            'landing.metric_3_label'  => 'Platform uptime',
            'landing.metric_4_value'  => '6x',
            'landing.metric_4_label'  => 'More replies than email',

            // ── Trusted By (brand logos) ────────────────────────────
            'landing.stats_enabled'  => '1',
            'landing.stats_heading'  => 'The messaging engine behind 14,000+ fast-growing brands',
            'landing.stats_1_label'  => 'Nimbus',
            'landing.stats_2_label'  => 'Vanta Labs',
            'landing.stats_3_label'  => 'Orbit',
            'landing.stats_4_label'  => 'Pinewood',
            'landing.stats_5_label'  => 'Kestrel',
            'landing.stats_6_label'  => 'Beacon',

            // ── Channels showcase ───────────────────────────────────
            'landing.channels_enabled'  => '1',
            'landing.channels_badge'    => 'One inbox, every channel',
            'landing.channels_title'    => 'Support customers on every channel they use',
            'landing.channels_subtitle' => 'Connect every channel once and resolve everything from one screen — no tab-juggling, no missed messages.',
            'landing.channel_1_key'   => 'whatsapp',
            'landing.channel_1_title' => 'WhatsApp Business',
            'landing.channel_1_desc'  => 'The official Cloud API — templates, broadcasts and round-the-clock auto-replies that still feel human.',
            'landing.channel_2_key'   => 'messenger',
            'landing.channel_2_title' => 'Facebook Messenger',
            'landing.channel_2_desc'  => 'Handle Page DMs, comment replies and story mentions without ever leaving your inbox.',
            'landing.channel_3_key'   => 'instagram',
            'landing.channel_3_title' => 'Instagram DMs',
            'landing.channel_3_desc'  => 'Answer direct messages, comments and mentions the moment they land — in real time.',
            'landing.channel_4_key'   => 'sms',
            'landing.channel_4_title' => 'SMS',
            'landing.channel_4_desc'  => 'Fire off high-deliverability texts for alerts, reminders and time-sensitive offers.',
            'landing.channel_5_key'   => 'email',
            'landing.channel_5_title' => 'Email',
            'landing.channel_5_desc'  => 'Send transactional and marketing email from the very same customer timeline.',

            // ── Problem / Solution ──────────────────────────────────
            'landing.problems_enabled' => '1',
            'landing.problems_title'   => 'Recognise the chaos?',
            'landing.problem_1'        => 'Messages buried across WhatsApp, Instagram, Messenger and a dozen inboxes',
            'landing.problem_2'        => 'Hot leads going cold while replies sit unread for hours',
            'landing.problem_3'        => 'No simple way to broadcast an offer or automate the follow-up',
            'landing.problem_4'        => 'Flying blind — no clue what your team or your campaigns are really doing',
            'landing.solution_title'   => 'Cerqle untangles all of it',
            'landing.solution_desc'    => 'A single workspace to capture, automate and close every conversation.',
            'landing.solution_1'       => 'One shared inbox for every channel, synced across the whole team',
            'landing.solution_2'       => 'AI agents and flows that answer the second a message lands',
            'landing.solution_3'       => 'Targeted broadcasts with smart scheduling and audience segments',
            'landing.solution_4'       => 'Live dashboards for delivery, response time and revenue',

            // ── Features ────────────────────────────────────────────
            'landing.features_enabled'   => '1',
            'landing.features_badge'     => 'Everything inside',
            'landing.features_title'     => 'Everything your support team needs, in one place',
            'landing.features_subtitle'  => 'A shared inbox, AI agents and automations that resolve more conversations with a fraction of the effort.',
            'landing.feature_1_icon'     => 'message-square',
            'landing.feature_1_title'    => 'Shared Team Inbox',
            'landing.feature_1_desc'     => 'Every WhatsApp, Messenger and Instagram thread in one place, with assignments, private notes and saved replies.',
            'landing.feature_2_icon'     => 'cpu',
            'landing.feature_2_title'    => 'AI Agents',
            'landing.feature_2_desc'     => 'Train an assistant on your own docs, FAQs and catalog, then let it answer instantly in any language.',
            'landing.feature_3_icon'     => 'zap',
            'landing.feature_3_title'    => 'Visual Automations',
            'landing.feature_3_desc'     => 'Drag-and-drop flows that tag, route and reply for you — zero code, zero developers.',
            'landing.feature_4_icon'     => 'share-2',
            'landing.feature_4_title'    => 'Mass Broadcasts',
            'landing.feature_4_desc'     => 'Blast personalised WhatsApp and SMS campaigns to thousands, with rate control built in.',
            'landing.feature_5_icon'     => 'users',
            'landing.feature_5_title'    => 'Built-in CRM',
            'landing.feature_5_desc'     => 'Every contact, full chat history, custom fields, tags and segments — all on one profile.',
            'landing.feature_6_icon'     => 'trending-up',
            'landing.feature_6_title'    => 'Lead Capture',
            'landing.feature_6_desc'     => 'Grab, qualify and score leads automatically so a hot one never slips away.',
            'landing.feature_7_icon'     => 'layout',
            'landing.feature_7_title'    => 'Commerce Sync',
            'landing.feature_7_desc'     => 'Link your store to sync orders, nudge abandoned carts and confirm deliveries over chat.',
            'landing.feature_8_icon'     => 'globe',
            'landing.feature_8_title'    => 'Social Publishing',
            'landing.feature_8_desc'     => 'Plan and post across your social accounts from one shared content calendar.',
            'landing.feature_9_icon'     => 'bar-chart-2',
            'landing.feature_9_title'    => 'Live Analytics',
            'landing.feature_9_desc'     => 'Delivery, open and reply rates, agent scorecards and campaign ROI — updated in real time.',

            // ── How it works ────────────────────────────────────────
            'landing.howitworks_enabled'  => '1',
            'landing.howitworks_badge'    => 'Up and running',
            'landing.howitworks_title'    => 'From first message to resolved in minutes',
            'landing.howitworks_subtitle' => 'No code and no drawn-out onboarding — connect a channel and you are live.',
            'landing.step_1_title'        => 'Plug in your channels',
            'landing.step_1_desc'         => 'Connect WhatsApp, Messenger and Instagram through official Meta APIs in a couple of clicks.',
            'landing.step_2_title'        => 'Bring in your contacts',
            'landing.step_2_desc'         => 'Drop in a CSV or sync your CRM — we dedupe and segment everything for you.',
            'landing.step_3_title'        => 'Automate and scale',
            'landing.step_3_desc'         => 'Launch broadcasts, switch on AI agents, and watch conversations become customers.',

            // ── Integrations strip ──────────────────────────────────
            'landing.integrations_strip_enabled'  => '1',
            'landing.integrations_strip_title'    => 'Plays nicely with your whole stack',
            'landing.integrations_strip_subtitle' => 'Wire Cerqle into 100+ apps through native integrations, webhooks and our REST API.',

            // ── Why us ──────────────────────────────────────────────
            'landing.why_enabled'   => '1',
            'landing.why_badge'     => 'Why Cerqle',
            'landing.why_title'     => 'Built for support teams that care about results',
            'landing.why_subtitle'  => 'Serious power for big support teams, refreshingly simple for everyone else.',
            'landing.why_1_icon'    => 'shield-check',
            'landing.why_1_title'   => 'Official & Safe',
            'landing.why_1_desc'    => 'Runs entirely on approved Meta Business APIs — no bans, no shady workarounds.',
            'landing.why_2_icon'    => 'zap',
            'landing.why_2_title'   => 'Blazing Fast',
            'landing.why_2_desc'    => 'Messages out the door in seconds on infrastructure built to scale.',
            'landing.why_3_icon'    => 'trending-up',
            'landing.why_3_title'   => 'Better Engagement',
            'landing.why_3_desc'    => 'Chat earns up to 6x the open rate of email — reach people where they actually reply.',
            'landing.why_4_icon'    => 'globe',
            'landing.why_4_title'   => 'Speaks Every Language',
            'landing.why_4_desc'    => 'Support customers worldwide with built-in localisation and AI translation.',
            'landing.why_5_icon'    => 'users',
            'landing.why_5_title'   => 'Truly Collaborative',
            'landing.why_5_desc'    => 'Assign chats, drop internal notes, and never fire off a double reply.',
            'landing.why_6_icon'    => 'server',
            'landing.why_6_title'   => 'Always On',
            'landing.why_6_desc'    => 'Secure, dependable and ready to grow with you — 99.98% uptime.',

            // ── Security & Compliance ───────────────────────────────
            'landing.security_enabled'  => '1',
            'landing.security_badge'    => 'Security & trust',
            'landing.security_title'    => 'Enterprise-grade protection, on by default',
            'landing.security_subtitle' => 'Your data — and your customers\' trust — guarded at every single layer.',
            'landing.security_1_icon'  => 'shield-check',
            'landing.security_1_title' => 'Encrypted End to End',
            'landing.security_1_desc'  => 'Data locked down in transit and at rest with industry-standard encryption.',
            'landing.security_2_icon'  => 'check-circle',
            'landing.security_2_title' => 'GDPR Ready',
            'landing.security_2_desc'  => 'Consent tracking, data controls and the right to be forgotten, all built in.',
            'landing.security_3_icon'  => 'users',
            'landing.security_3_title' => 'Role-Based Access',
            'landing.security_3_desc'  => 'Fine-grained permissions, 2FA and full audit trails keep your workspace tight.',
            'landing.security_4_icon'  => 'server',
            'landing.security_4_title' => 'Rock-Solid Infra',
            'landing.security_4_desc'  => 'Redundant, monitored systems with automated backups and 99.98% uptime.',

            // ── Testimonials ────────────────────────────────────────
            'landing.testimonials_enabled'   => '1',
            'landing.testimonials_badge'     => 'Real teams',
            'landing.testimonials_title'     => 'Teams that switched are not looking back',
            'landing.testimonials_subtitle'  => 'Here is what Cerqle customers have to say.',
            'landing.testimonial_1_name'     => 'Elena Marsh',
            'landing.testimonial_1_role'     => 'Growth Lead, Nimbus',
            'landing.testimonial_1_text'     => 'We cut first-reply time from hours to under a minute. The AI agent clears the repetitive questions so my team can actually sell.',
            'landing.testimonial_1_avatar'   => '',
            'landing.testimonial_2_name'     => 'Rohan Patel',
            'landing.testimonial_2_role'     => 'Founder, Vanta Labs',
            'landing.testimonial_2_text'     => 'We were live in ten minutes. By that afternoon we had sent our first broadcast to 9,000 contacts.',
            'landing.testimonial_2_avatar'   => '',
            'landing.testimonial_3_name'     => 'Chloe Adebayo',
            'landing.testimonial_3_role'     => 'Head of CX, Orbit',
            'landing.testimonial_3_text'     => 'One inbox for WhatsApp and Instagram completely changed how we run support. Absolute game-changer.',
            'landing.testimonial_3_avatar'   => '',
            'landing.testimonial_4_name'     => 'Marcus Feld',
            'landing.testimonial_4_role'     => 'Ops Lead, Beacon',
            'landing.testimonial_4_text'     => 'Our automations save the team 30-plus hours a week. It is like hiring three people who never clock off.',
            'landing.testimonial_4_avatar'   => '',
            'landing.testimonial_5_name'     => 'Priya Nair',
            'landing.testimonial_5_role'     => 'Owner, Pinewood',
            'landing.testimonial_5_text'     => 'WhatsApp cart reminders won back a quarter of our abandoned checkouts. It paid for itself almost instantly.',
            'landing.testimonial_5_avatar'   => '',
            'landing.testimonial_6_name'     => 'Daniel Cho',
            'landing.testimonial_6_role'     => 'Support Lead, Kestrel',
            'landing.testimonial_6_text'     => 'Finally, every conversation in one spot. Our average response time dropped from hours to minutes.',
            'landing.testimonial_6_avatar'   => '',

            // ── FAQ ─────────────────────────────────────────────────
            'landing.faq_enabled'   => '1',
            'landing.faq_badge'     => 'Questions',
            'landing.faq_title'     => 'Answers before you even ask',
            'landing.faq_subtitle'  => 'The things people usually want to know about Cerqle.',
            'landing.faq_1_q'       => 'Which channels can I connect?',
            'landing.faq_1_a'       => 'WhatsApp Business, Facebook Messenger and Instagram DMs all land in one inbox — plus SMS and email broadcasting, run from a single dashboard.',
            'landing.faq_2_q'       => 'Do I need a WhatsApp Business API account?',
            'landing.faq_2_a'       => 'Yes — Cerqle runs on the official Meta WhatsApp Cloud API, and our guided setup gets you connected in minutes.',
            'landing.faq_3_q'       => 'Can I try it before I pay?',
            'landing.faq_3_a'       => 'Of course. Every plan starts with a 14-day free trial and never asks for a card upfront.',
            'landing.faq_4_q'       => 'Will the AI actually understand my business?',
            'landing.faq_4_a'       => 'Yes — train it on your own docs, FAQs and product catalog so every answer stays accurate and on-brand.',
            'landing.faq_5_q'       => 'Is my data safe?',
            'landing.faq_5_a'       => 'Always. We use end-to-end encryption, role-based access and full GDPR compliance — and we never sell or share your data.',

            // ── CTA ─────────────────────────────────────────────────
            'landing.cta_enabled'   => '1',
            'landing.cta_title'     => 'Give every customer an instant answer',
            'landing.cta_subtitle'  => 'Join 14,000+ teams delivering faster support with Cerqle. Start free — no card, no catch.',
            'landing.cta_primary'   => 'Get started',
            'landing.cta_secondary' => 'Book a demo',

            // ── About page ──────────────────────────────────────────
            'landing.about_badge'       => 'Our story',
            'landing.about_title'       => 'We are making business conversations feel effortless',
            'landing.about_subtitle'    => 'Cerqle helps thousands of teams turn everyday messages into relationships that actually last.',
            'landing.about_story_title' => 'How we got here',
            'landing.about_story_body'  => "Cerqle began with one nagging problem: customer conversations were splintered across too many apps, and brilliant leads kept falling through the cracks.\n\nSo we built a single home for every WhatsApp, Messenger and Instagram chat — wired up with AI and automation that do the heavy lifting. Today, teams in more than 60 countries lean on Cerqle to reply faster, sell more, and build relationships that stick.",
            'landing.about_value_1_icon'  => 'zap',
            'landing.about_value_1_title' => 'Ship Boldly',
            'landing.about_value_1_desc'  => 'We move fast and fight to make complicated things feel simple.',
            'landing.about_value_2_icon'  => 'users',
            'landing.about_value_2_title' => 'Customers First',
            'landing.about_value_2_desc'  => 'Every call we make starts with the people who use Cerqle every day.',
            'landing.about_value_3_icon'  => 'shield-check',
            'landing.about_value_3_title' => 'Earn Trust',
            'landing.about_value_3_desc'  => 'We guard customer data like it is our own — because it matters that much.',
            'landing.about_value_4_icon'  => 'globe',
            'landing.about_value_4_title' => 'For Everyone',
            'landing.about_value_4_desc'  => 'Accessible, multilingual and built for teams of every size.',
            'landing.about_stat_1_value' => '14,000+',
            'landing.about_stat_1_label' => 'Teams served',
            'landing.about_stat_2_value' => '60+',
            'landing.about_stat_2_label' => 'Countries',
            'landing.about_stat_3_value' => '68M+',
            'landing.about_stat_3_label' => 'Messages delivered',
            'landing.about_stat_4_value' => '99.98%',
            'landing.about_stat_4_label' => 'Uptime',
            'landing.about_cta_title'    => 'Want to grow with us?',
            'landing.about_cta_subtitle' => 'Start free today, or just say hello — we would genuinely love to hear from you.',

            // ── Contact page ───────────────────────────────────────
            'landing.contact_email' => 'hello@cerqle.ai',

            // ── Integrations page ───────────────────────────────────
            'landing.integrations_page_badge'    => 'Integrations',
            'landing.integrations_page_title'    => 'Connect Cerqle to everything you run',
            'landing.integrations_page_subtitle' => 'Native integrations, webhooks and a full REST API — drop Cerqle straight into the tools your team already lives in.',
            'landing.intcat_1_title' => 'Messaging Channels',
            'landing.intcat_1_items' => "WhatsApp Business\nFacebook Messenger\nInstagram Direct\nSMS\nEmail",
            'landing.intcat_2_title' => 'AI Providers',
            'landing.intcat_2_items' => "OpenAI\nAnthropic Claude\nGoogle Gemini\nQdrant",
            'landing.intcat_3_title' => 'E-commerce',
            'landing.intcat_3_items' => "Shopify\nWooCommerce\nMagento\nBigCommerce",
            'landing.intcat_4_title' => 'Payments & Billing',
            'landing.intcat_4_items' => "Stripe\nPayPal\nPaddle",
            'landing.intcat_5_title' => 'CRM & Automation',
            'landing.intcat_5_items' => "Zapier\nHubSpot\nGoogle Sheets\nWebhooks",
            'landing.intcat_6_title' => 'Developer Tools',
            'landing.intcat_6_items' => "REST API\nWebhooks\nOAuth 2.0\nFirebase",
            'landing.intcat_7_title' => 'Social Media',
            'landing.intcat_7_items' => "Facebook\nInstagram\nLinkedIn\nX (Twitter)\nYouTube\nTikTok",
        ];
    }

    public function index(): Response
    {
        $settings = [];
        foreach (self::defaults() as $key => $default) {
            $settings[$key] = SystemSetting::get($key, $default);
        }

        return Inertia::render('Admin/LandingPage/Index', [
            'settings' => $settings,
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'settings'   => ['required', 'array'],
            'settings.*' => ['nullable', 'string', 'max:5000'],
        ]);

        foreach ($data['settings'] as $key => $value) {
            if (str_starts_with($key, 'landing.')) {
                SystemSetting::set($key, $value ?? '', false, 'landing');
            }
        }

        return back()->with('success', 'Site content saved.');
    }

    /**
     * Resolve every public marketing setting (DB value, falling back to defaults).
     * Keys are derived from defaults() so they can never drift.
     */
    public static function getPublicSettings(): array
    {
        $defaults = self::defaults();
        $result   = [];
        foreach ($defaults as $key => $default) {
            // The master toggle is read separately by LandingController; skip it here.
            if ($key === 'landing.page_enabled') {
                continue;
            }
            $result[$key] = SystemSetting::get($key, $default);
        }

        return $result;
    }
}
