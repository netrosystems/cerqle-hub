import { Link, usePage } from '@inertiajs/react';
import { useLayoutEffect, useRef, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { ChevronDown, Plus, X } from 'lucide-react';

const SIDEBAR_SCROLL_STORAGE_PREFIX = 'cerqle.sidebar.scroll.';
const SIDEBAR_GROUPS_STORAGE_PREFIX = 'cerqle.sidebar.groups.';

function readScrollPosition(storageKey) {
    if (typeof window === 'undefined') return 0;

    try {
        const value = Number.parseInt(window.sessionStorage.getItem(storageKey) ?? '0', 10);
        return Number.isFinite(value) && value >= 0 ? value : 0;
    } catch {
        return 0;
    }
}

function writeScrollPosition(storageKey, position) {
    if (typeof window === 'undefined') return;

    try {
        window.sessionStorage.setItem(storageKey, String(Math.max(0, Math.round(position))));
    } catch {
        // Storage may be unavailable in privacy-restricted browser contexts.
    }
}

function readOpenGroups(storageKey) {
    if (typeof window === 'undefined') return {};

    try {
        const parsed = JSON.parse(window.sessionStorage.getItem(storageKey) ?? '{}');
        return parsed && typeof parsed === 'object' ? parsed : {};
    } catch {
        return {};
    }
}

function writeOpenGroup(storageKey, label, isOpen) {
    if (typeof window === 'undefined') return;

    try {
        const state = readOpenGroups(storageKey);
        state[label] = isOpen;
        window.sessionStorage.setItem(storageKey, JSON.stringify(state));
    } catch {
        // Storage may be unavailable in privacy-restricted browser contexts.
    }
}

function isNavItemActive(item) {
    return typeof item.active === 'function'
        ? item.active()
        : item.active ?? (item.route && route().current(item.route));
}

function NavLinks({ items, onClose }) {
    return items.map((item, i) => {
        const isActive = isNavItemActive(item);
        return (
            <Link
                key={item.key ?? item.route ?? item.href ?? i}
                href={item.href ?? (item.route ? route(item.route) : '#')}
                onClick={onClose}
                className={[
                    'group flex items-center gap-2.5 rounded-lg px-3 py-2 text-sm font-medium transition-all duration-150',
                    isActive
                        ? 'bg-brand-600 text-white shadow-sm'
                        : 'text-white/80 hover:bg-white/10 hover:text-white',
                ].join(' ')}
            >
                {item.icon && (
                    <span className={[
                        'shrink-0 transition-colors duration-150',
                        isActive
                            ? 'text-white'
                            : 'text-white/65 group-hover:text-white',
                    ].join(' ')}>
                        {item.icon}
                    </span>
                )}
                <span className="truncate">{item.label}</span>
                {isActive && <span className="ml-auto h-1.5 w-1.5 shrink-0 rounded-full bg-white/70" />}
            </Link>
        );
    });
}

function NavGroup({ label, items, onClose, defaultOpen = true, storageKey }) {
    const hasActiveItem = items.some((item) => (
        isNavItemActive(item)
    ));
    // Inertia recreates this sidebar on every visit, so which groups are open
    // is remembered next to the scroll position rather than held in state alone.
    const [open, setOpen] = useState(() => {
        const remembered = readOpenGroups(storageKey)[label];
        return typeof remembered === 'boolean' ? remembered : (defaultOpen || hasActiveItem);
    });

    // A link in the page body can navigate into a collapsed group. Reveal the
    // current item instead of leaving the menu pointing at nothing. Adjusted
    // during render, not in an effect, so it never paints collapsed first.
    const [sawActiveItem, setSawActiveItem] = useState(hasActiveItem);
    if (hasActiveItem !== sawActiveItem) {
        setSawActiveItem(hasActiveItem);
        if (hasActiveItem) setOpen(true);
    }

    const groupId = `nav-group-${label.replace(/\s+/g, '-').toLowerCase()}`;

    const toggle = () => {
        const next = !open;
        setOpen(next);
        writeOpenGroup(storageKey, label, next);
    };

    return (
        <div className="mb-0.5">
            <button
                type="button"
                onClick={toggle}
                aria-expanded={open}
                aria-controls={groupId}
                className="flex w-full items-center justify-between px-3 py-1.5 mt-3 text-[10px] font-bold uppercase tracking-widest text-white/70 hover:text-white transition-colors duration-150 select-none"
            >
                <span>{label}</span>
                <ChevronDown
                    className={[
                        'h-3 w-3 transition-transform duration-200',
                        open ? 'rotate-0' : '-rotate-90',
                    ].join(' ')}
                />
            </button>

            {open && (
                <div id={groupId} className="mt-0.5 space-y-0.5">
                    <NavLinks items={items} onClose={onClose} />
                </div>
            )}
        </div>
    );
}

export default function Sidebar({
    navItems = [],
    navGroups = [],
    open = false,
    onClose,
    footer,
    title: _title,
    logo,
    showCreateButton = true,
    scrollKey = 'default',
}) {
    const { t } = useTranslation();
    const appName = import.meta.env.VITE_APP_NAME || 'Cerqle';
    const { branding } = usePage().props;
    const logoUrl = branding?.logo_url;
    const desktopNavRef = useRef(null);
    const mobileNavRef = useRef(null);
    const scrollStorageKey = `${SIDEBAR_SCROLL_STORAGE_PREFIX}${scrollKey}`;
    const groupsStorageKey = `${SIDEBAR_GROUPS_STORAGE_PREFIX}${scrollKey}`;

    // Inertia swaps page components, which recreates the layout and this sidebar.
    // Restore the menu's own scroll position before paint so lower navigation
    // items stay where the user left them instead of jumping back to the top.
    useLayoutEffect(() => {
        const position = readScrollPosition(scrollStorageKey);
        if (desktopNavRef.current) desktopNavRef.current.scrollTop = position;
        if (mobileNavRef.current) mobileNavRef.current.scrollTop = position;
    }, [open, scrollStorageKey]);

    const rememberScrollPosition = (event) => {
        writeScrollPosition(scrollStorageKey, event.currentTarget.scrollTop);
    };

    const renderContent = (surface) => (
        <aside className="flex h-full w-64 flex-col bg-secondary-900 dark:bg-neutral-900">
            {/* Brand header */}
            <div className="flex h-14 shrink-0 items-center gap-2.5 px-4 border-b border-white/8">
                {logoUrl ? (
                    <img src={logoUrl} alt={appName} className="h-7 max-w-[140px] object-contain" />
                ) : logo ? (
                    logo
                ) : (
                    <img src="/cerqle-logo-white.svg" alt={appName} className="h-10 w-auto max-w-[200px] object-contain" />
                )}
            </div>

            {showCreateButton && (
                <div className="shrink-0 p-3 pb-2 border-b border-white/8">
                    <button
                        type="button"
                        className="flex w-full items-center justify-center gap-2 rounded-lg px-3 py-2 text-sm font-semibold text-white bg-brand-600 hover:bg-brand-700 transition duration-150"
                    >
                        <Plus className="h-4 w-4" />
                        {t('common.create')}
                    </button>
                </div>
            )}

            <nav
                ref={surface === 'desktop' ? desktopNavRef : mobileNavRef}
                onScroll={rememberScrollPosition}
                data-testid={`sidebar-scroll-${surface}`}
                className="flex-1 overflow-y-auto px-2 py-2 scrollbar-thin scrollbar-track-transparent scrollbar-thumb-white/10"
            >
                {navGroups.length > 0 &&
                    navGroups.map((group, gi) => (
                        <NavGroup
                            // Index-prefixed: group labels are not guaranteed unique
                            // (e.g. two "Account" groups), and a duplicate React key
                            // makes React omit/duplicate siblings, corrupting the
                            // sidebar across SPA navigations.
                            key={`${gi}-${group.key ?? group.label ?? ''}`}
                            label={group.label}
                            items={group.items ?? []}
                            onClose={onClose}
                            defaultOpen={group.defaultOpen ?? true}
                            storageKey={groupsStorageKey}
                        />
                    ))}

                {navGroups.length === 0 &&
                    navItems.map((item, i) => {
                        if (item.type === 'divider') {
                            return <hr key={`div-${i}`} className="my-2 border-white/10" />;
                        }
                        const isActive =
                            typeof item.active === 'function'
                                ? item.active()
                                : item.active ?? (item.route && route().current(item.route));
                        return (
                            <Link
                                key={item.key ?? item.route ?? item.href ?? i}
                                href={item.href ?? (item.route ? route(item.route) : '#')}
                                onClick={onClose}
                                className={[
                                    'group flex items-center gap-2.5 rounded-lg px-3 py-2 text-sm font-medium transition-all duration-150',
                                    isActive
                                        ? 'bg-brand-600 text-white'
                                        : 'text-white/80 hover:bg-white/10 hover:text-white',
                                ].join(' ')}
                            >
                                {item.icon && (
                                    <span className={isActive ? 'text-white' : 'text-white/65 group-hover:text-white'}>
                                        {item.icon}
                                    </span>
                                )}
                                <span className="truncate">{item.label}</span>
                            </Link>
                        );
                    })}
            </nav>

            {footer && (
                <div className="shrink-0 border-t border-white/8 p-3">
                    <div className="text-white/55">
                        {footer}
                    </div>
                </div>
            )}
        </aside>
    );

    return (
        <>
            {/* Desktop: always visible */}
            <div className="hidden lg:fixed lg:inset-y-0 lg:z-20 lg:flex lg:w-64 lg:flex-col lg:left-0 rtl:lg:left-auto rtl:lg:right-0">
                {renderContent('desktop')}
            </div>

            {/* Mobile: overlay + drawer */}
            {open && (
                <div className="fixed inset-0 z-40 lg:hidden">
                    <div className="fixed inset-0 bg-black/60 backdrop-blur-sm" onClick={onClose} aria-hidden="true" />
                    <div className="fixed inset-y-0 left-0 w-64 shadow-2xl rtl:left-auto rtl:right-0">
                        <button
                            type="button"
                            onClick={onClose}
                            className="absolute top-3 right-3 z-10 flex h-7 w-7 items-center justify-center rounded-full bg-white/10 text-white/70 hover:bg-white/20 transition"
                            aria-label={t('ui.close_menu')}
                        >
                            <X className="h-4 w-4" />
                        </button>
                        {renderContent('mobile')}
                    </div>
                </div>
            )}
        </>
    );
}
