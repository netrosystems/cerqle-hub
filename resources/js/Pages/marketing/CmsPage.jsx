import { useMemo } from 'react';
import LandingLayout from '@/Layouts/LandingLayout';
import SeoHead from '@/Components/SeoHead';
import { Reveal } from '@/Components/Reveal';

/** Pill badge mirroring the Cerqle variant in resources/js/Pages/marketing/About.jsx. */
function Badge({ text }) {
    if (!text) return null;
    return (
        <span className="inline-flex items-center gap-2 rounded-full bg-brand-500/15 text-brand-600 dark:text-brand-300 text-xs font-semibold px-3.5 py-1.5 border border-brand-500/25">
            <span className="relative flex h-2 w-2">
                <span className="absolute inline-flex h-full w-full rounded-full bg-brand-400 opacity-70 animate-pulse-ring" />
                <span className="relative inline-flex h-2 w-2 rounded-full bg-brand-500" />
            </span>
            {text}
        </span>
    );
}

/** Slugify a heading string for use as an anchor id. */
function slugify(text, fallback) {
    const base = text
        .toLowerCase()
        .replace(/[^a-z0-9]+/g, '-')
        .replace(/^-|-$/g, '');
    return base || fallback;
}

export default function CmsPage({ page }) {
    // Extract h2 headings from the seeded/admin HTML to power both the
    // left-rail ToC and the on-page anchor ids. Cheap and works for the
    // current content shape (h2 sections).
    const headings = useMemo(() => {
        if (!page.content) return [];
        return [...page.content.matchAll(/<h2[^>]*>(.*?)<\/h2>/gi)].map((m, i) => {
            const text = m[1].replace(/<[^>]+>/g, '').trim();
            return { id: slugify(text, `section-${i}`), text };
        });
    }, [page.content]);

    // Inject `id="..."` onto every h2 in the rendered HTML so the ToC anchors
    // resolve. Dedupe ids by suffixing -2, -3 on collisions.
    const annotatedContent = useMemo(() => {
        const seen = new Map();
        return (page.content ?? '').replace(
            /<h2(?![^>]*\sid=)([^>]*)>(.*?)<\/h2>/gi,
            (_, attrs, inner) => {
                const text = inner.replace(/<[^>]+>/g, '');
                const base = slugify(text, 'section');
                const n = (seen.get(base) ?? 0) + 1;
                seen.set(base, n);
                const id = n === 1 ? base : `${base}-${n}`;
                return `<h2 id="${id}"${attrs}>${inner}</h2>`;
            },
        );
    }, [page.content]);

    return (
        <LandingLayout>
            <SeoHead
                title={page.meta_title ?? page.title}
                description={page.meta_description}
            />

            {/* Dark hero — matches resources/js/Pages/marketing/About.jsx */}
            <section
                className="relative overflow-hidden"
                style={{
                    background:
                        'radial-gradient(ellipse 70% 65% at 50% 0%, rgba(143,95,167,0.18) 0%, transparent 60%), #281a31',
                }}
            >
                <div className="pointer-events-none absolute -left-24 top-6 h-72 w-72 rounded-full bg-brand-500/20 blur-3xl animate-float-slow" />
                <div className="pointer-events-none absolute -right-16 top-24 h-80 w-80 rounded-full bg-brand-600/15 blur-3xl animate-float" />
                <div className="max-w-4xl mx-auto px-4 sm:px-6 lg:px-8 pt-24 pb-16 text-center relative">
                    <Reveal className="flex justify-center mb-6" y={12}>
                        <Badge text="Legal" />
                    </Reveal>
                    <Reveal
                        as="h1"
                        delay={80}
                        className="text-4xl sm:text-5xl font-bold tracking-tight text-white max-w-3xl mx-auto leading-tight"
                    >
                        {page.title}
                    </Reveal>
                    {page.last_updated && (
                        <Reveal
                            as="p"
                            delay={170}
                            className="mt-6 text-sm text-neutral-400"
                        >
                            Last updated {page.last_updated}
                        </Reveal>
                    )}
                </div>
            </section>

            {/* Cream article body */}
            <section className="bg-[#fbf9fd]">
                <div className="max-w-6xl mx-auto px-4 sm:px-6 lg:px-8 py-16 lg:py-24">
                    <div className="lg:grid lg:grid-cols-[16rem_minmax(0,1fr)] lg:gap-12">
                        {headings.length > 1 && (
                            <aside className="hidden lg:block">
                                <div className="sticky top-28">
                                    <p className="text-xs font-semibold uppercase tracking-wider text-neutral-500 mb-3">
                                        On this page
                                    </p>
                                    <nav>
                                        <ul className="space-y-2 text-sm">
                                            {headings.map((h) => (
                                                <li key={h.id}>
                                                    <a
                                                        href={`#${h.id}`}
                                                        className="text-neutral-600 hover:text-brand-600 transition"
                                                    >
                                                        {h.text}
                                                    </a>
                                                </li>
                                            ))}
                                        </ul>
                                    </nav>
                                </div>
                            </aside>
                        )}

                        <article className="cms-prose max-w-none">
                            <div dangerouslySetInnerHTML={{ __html: annotatedContent }} />
                        </article>
                    </div>
                </div>
            </section>
        </LandingLayout>
    );
}
