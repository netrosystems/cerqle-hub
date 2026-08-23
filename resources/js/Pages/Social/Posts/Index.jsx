import { useState } from 'react';
import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import { useTranslation } from 'react-i18next';
import ClientLayout from '@/Layouts/ClientLayout';
import EmptyState from '@/Components/EmptyState';
import { SocialBrandIcon } from '@/Components/BrandIcons';
import {
    Plus, Trash2, ExternalLink, Share2, Clock, CheckCircle2, XCircle,
    Pencil, Send, Sparkles, Eye, Image, X, Calendar, Zap, Ban,
} from 'lucide-react';
import { browserTz, formatInTz } from '@/Utils/datetime';
import AiPlannerModal from './AiPlannerModal';

const STATUS_META = {
    draft:      { labelKey: 'social.status_draft',      cls: 'bg-neutral-100 text-neutral-600 dark:bg-neutral-700 dark:text-neutral-300', icon: <Pencil className="h-3 w-3" /> },
    scheduled:  { labelKey: 'social.status_scheduled',  cls: 'bg-blue-100 text-blue-700 dark:bg-blue-900/40 dark:text-blue-300',         icon: <Clock className="h-3 w-3" /> },
    publishing: { labelKey: 'social.status_publishing', cls: 'bg-yellow-100 text-yellow-700 dark:bg-yellow-900/40 dark:text-yellow-300', icon: <Send className="h-3 w-3" /> },
    published:  { labelKey: 'social.status_published',  cls: 'bg-green-100 text-green-700 dark:bg-green-900/40 dark:text-green-300',     icon: <CheckCircle2 className="h-3 w-3" /> },
    failed:     { labelKey: 'social.status_failed',     cls: 'bg-red-100 text-red-700 dark:bg-red-900/40 dark:text-red-300',             icon: <XCircle className="h-3 w-3" /> },
};

const NETWORKS = ['facebook', 'instagram', 'linkedin', 'youtube', 'tiktok'];
const NETWORK_LABELS = { facebook: 'Facebook', instagram: 'Instagram', linkedin: 'LinkedIn', youtube: 'YouTube', tiktok: 'TikTok' };

function StatusBadge({ status }) {
    const { t } = useTranslation();
    const meta = STATUS_META[status] ?? { labelKey: null, cls: 'bg-neutral-100 text-neutral-500', icon: null };
    return (
        <span className={`inline-flex items-center gap-1 rounded-full px-2.5 py-0.5 text-xs font-medium ${meta.cls}`}>
            {meta.icon} {meta.labelKey ? t(meta.labelKey) : status}
        </span>
    );
}

function AccountPill({ acct }) {
    return (
        <div className="flex items-center gap-1.5">
            <div className="relative shrink-0">
                {acct.picture_url
                    ? <img src={acct.picture_url} alt={acct.name} className="h-5 w-5 rounded-full object-cover" />
                    : <span className="flex h-5 w-5 items-center justify-center rounded-full bg-neutral-200 dark:bg-neutral-700 text-[9px] font-bold text-neutral-500">
                        {acct.name?.[0]?.toUpperCase() ?? '?'}
                      </span>
                }
                <span className="absolute -bottom-0.5 -right-0.5 flex h-3 w-3 items-center justify-center rounded-full bg-white dark:bg-neutral-900 ring-1 ring-white dark:ring-neutral-900">
                    <SocialBrandIcon network={acct.network} className="h-2.5 w-2.5" />
                </span>
            </div>
            <span className="text-xs text-neutral-600 dark:text-neutral-400 truncate max-w-[100px]">{acct.name}</span>
        </div>
    );
}

function FacebookEditModal({ post, account, result, onClose }) {
    const { t } = useTranslation();
    const { data, setData, put, processing, errors } = useForm({
        body: result?.edited_body ?? post?.body ?? '',
    });

    if (!post || !account) return null;

    const submit = (event) => {
        event.preventDefault();
        put(route('client.social.posts.facebook.update', [post.id, account.id]), {
            preserveScroll: true,
            onSuccess: onClose,
        });
    };

    return (
        <div className="fixed inset-0 z-[60] flex items-center justify-center p-4">
            <div className="absolute inset-0 bg-black/50 backdrop-blur-sm" onClick={onClose} />
            <form onSubmit={submit} className="relative w-full max-w-lg rounded-2xl bg-white p-5 shadow-2xl dark:bg-neutral-900">
                <div className="mb-4 flex items-center justify-between gap-3">
                    <div>
                        <h3 className="font-semibold text-neutral-900 dark:text-neutral-100">{t('social.edit_facebook_post')}</h3>
                        <p className="mt-0.5 text-xs text-neutral-500">{account.name}</p>
                    </div>
                    <button type="button" onClick={onClose} className="text-neutral-400 hover:text-neutral-700">
                        <X className="h-5 w-5" />
                    </button>
                </div>
                <textarea
                    value={data.body}
                    onChange={(event) => setData('body', event.target.value)}
                    rows={8}
                    maxLength={63206}
                    autoFocus
                    className="w-full resize-none rounded-xl border border-neutral-300 bg-white px-3 py-2 text-sm text-neutral-900 focus:outline-none focus:ring-2 focus:ring-brand-500 dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-100"
                />
                {errors.body && <p className="mt-1 text-xs text-red-500">{errors.body}</p>}
                {errors.facebook && <p className="mt-1 text-xs text-red-500">{errors.facebook}</p>}
                <div className="mt-4 flex justify-end gap-2">
                    <button type="button" onClick={onClose} className="rounded-lg border border-neutral-300 px-4 py-2 text-sm dark:border-neutral-700">
                        {t('common.cancel')}
                    </button>
                    <button type="submit" disabled={processing || !data.body.trim()} className="rounded-lg bg-brand-600 px-4 py-2 text-sm font-medium text-white disabled:opacity-60">
                        {processing ? t('social.saving') : t('social.update_on_facebook')}
                    </button>
                </div>
            </form>
        </div>
    );
}

/* ── Detail Modal ─────────────────────────────────────────────── */
function PostDetailModal({ post, accountMap, userTz, onClose, onDelete, onEditFacebook, onDeleteFacebook, onDeleteInstagram }) {
    const { t } = useTranslation();
    if (!post) return null;
    const targets = post.target_accounts ?? [];
    const dateField = post.published_at ?? post.scheduled_at;
    const tz = post.timezone || userTz;
    const mediaUrls = (post.media_urls ?? []).filter(Boolean);
    const publishedTargets = targets.filter((id) => post.publish_results?.[id]?.status === 'published');
    const hasPublishedTarget = publishedTargets.length > 0;
    const canEdit = ['draft', 'scheduled', 'failed'].includes(post.status) && !hasPublishedTarget;
    const canDelete = ['draft', 'scheduled', 'failed'].includes(post.status) && !hasPublishedTarget;

    return (
        <div className="fixed inset-0 z-50 flex items-center justify-center p-4">
            <div className="absolute inset-0 bg-black/40 backdrop-blur-sm" onClick={onClose} />
            <div className="relative w-full max-w-lg rounded-2xl bg-white dark:bg-neutral-900 shadow-2xl overflow-hidden flex flex-col max-h-[85vh]">
                <div className="flex items-center justify-between px-5 py-4 border-b border-neutral-100 dark:border-neutral-800">
                    <div className="flex items-center gap-2 flex-wrap">
                        <StatusBadge status={post.status} />
                        {dateField && (
                            <span className="flex items-center gap-1 text-xs text-neutral-400">
                                <Calendar className="h-3 w-3" /> {formatInTz(dateField, tz)}
                            </span>
                        )}
                    </div>
                    <button onClick={onClose} className="text-neutral-400 hover:text-neutral-600 dark:hover:text-neutral-200 transition">
                        <X className="h-5 w-5" />
                    </button>
                </div>

                <div className="overflow-y-auto flex-1 px-5 py-4 space-y-4">
                    {post.title && <h3 className="text-base font-semibold text-neutral-900 dark:text-neutral-100">{post.title}</h3>}
                    <p className="text-sm text-neutral-700 dark:text-neutral-300 whitespace-pre-wrap leading-relaxed">{post.body}</p>

                    {mediaUrls.length > 0 && (
                        <div className={`grid gap-2 ${mediaUrls.length === 1 ? 'grid-cols-1' : 'grid-cols-2'}`}>
                            {mediaUrls.map((url, i) => (
                                <img key={i} src={url} alt="" className="w-full rounded-lg object-cover max-h-48" />
                            ))}
                        </div>
                    )}

                    {targets.length > 0 && (
                        <div>
                            <p className="text-xs font-semibold text-neutral-400 uppercase tracking-wider mb-2">{t('social.posted_to')}</p>
                            <div className="flex flex-wrap gap-2">
                                {targets.map((id) => {
                                    const acct = accountMap[id];
                                    if (!acct) return null;
                                    return (
                                        <div key={id} className="flex items-center gap-1.5 rounded-full border border-neutral-200 dark:border-neutral-700 px-2.5 py-1">
                                            <AccountPill acct={acct} />
                                        </div>
                                    );
                                })}
                            </div>
                        </div>
                    )}

                    {post.publish_results && Object.keys(post.publish_results).length > 0 && (
                        <div>
                            <p className="text-xs font-semibold text-neutral-400 uppercase tracking-wider mb-2">{t('social.publish_results')}</p>
                            <div className="space-y-1.5">
                                {Object.entries(post.publish_results).map(([accountId, result]) => {
                                    const acct = accountMap[accountId];
                                    return (
                                        <div key={accountId} className="rounded-lg bg-neutral-50 px-3 py-2 text-xs dark:bg-neutral-800">
                                            <div className="flex items-center justify-between">
                                                <span className="text-neutral-600 dark:text-neutral-400">{acct?.name ?? t('social.account_number', { id: accountId })}</span>
                                                <div className="flex items-center gap-2">
                                                    <span className={result.status === 'published' ? 'text-green-600 font-medium' : 'text-red-500 font-medium'}>
                                                        {result.status === 'published' ? t('social.result_published') : t('social.result_failed')}
                                                    </span>
                                                    {result.status === 'published' && acct?.network === 'facebook' && (
                                                        <>
                                                            <button type="button" onClick={() => onEditFacebook(post, acct, result)}
                                                                className="rounded-md border border-neutral-200 p-1 text-neutral-500 hover:border-brand-300 hover:text-brand-600 dark:border-neutral-700"
                                                                title={t('social.edit_facebook_post')}>
                                                                <Pencil className="h-3.5 w-3.5" />
                                                            </button>
                                                            <button type="button" onClick={() => onDeleteFacebook(post, acct)}
                                                                className="rounded-md border border-neutral-200 p-1 text-neutral-500 hover:border-red-300 hover:text-red-500 dark:border-neutral-700"
                                                                title={t('social.delete_facebook_post')}>
                                                                <Trash2 className="h-3.5 w-3.5" />
                                                            </button>
                                                        </>
                                                    )}
                                                    {result.status === 'published' && acct?.network === 'instagram' && (
                                                        <button type="button" onClick={() => onDeleteInstagram(post, acct)}
                                                            className="rounded-md border border-neutral-200 p-1 text-neutral-500 hover:border-red-300 hover:text-red-500 dark:border-neutral-700"
                                                            title={t('social.delete_instagram_post')}>
                                                            <Trash2 className="h-3.5 w-3.5" />
                                                        </button>
                                                    )}
                                                </div>
                                            </div>
                                            {(result.warnings ?? []).map((warning, index) => (
                                                <p key={index} className="mt-1 text-amber-600 dark:text-amber-400">{warning}</p>
                                            ))}
                                        </div>
                                    );
                                })}
                            </div>
                        </div>
                    )}
                </div>

                <div className="flex items-center justify-between px-5 py-3 border-t border-neutral-100 dark:border-neutral-800 bg-neutral-50 dark:bg-neutral-800/50 gap-2 flex-wrap">
                    <div className="flex items-center gap-2">
                        {post.post_url && (
                            <a href={post.post_url} target="_blank" rel="noopener noreferrer"
                                className="inline-flex items-center gap-1.5 text-sm font-medium text-brand-600 hover:text-brand-700 transition">
                                <ExternalLink className="h-4 w-4" /> {t('social.view_on_platform')}
                            </a>
                        )}
                    </div>
                    <div className="flex items-center gap-2">
                        {canDelete && (
                            <button type="button" onClick={() => onDelete(post.id)}
                                className="inline-flex items-center gap-1.5 rounded-lg border border-red-200 px-3 py-1.5 text-sm font-medium text-red-600 hover:bg-red-50 dark:border-red-900/60 dark:hover:bg-red-900/20 transition">
                                <Trash2 className="h-3.5 w-3.5" /> {post.status === 'scheduled' ? t('social.delete_schedule') : t('common.delete')}
                            </button>
                        )}
                        {canEdit && (
                            <Link href={route('client.social.posts.edit', post.id)}
                                className="inline-flex items-center gap-1.5 rounded-lg border border-neutral-300 dark:border-neutral-600 px-3 py-1.5 text-sm font-medium text-neutral-700 dark:text-neutral-300 hover:bg-neutral-100 dark:hover:bg-neutral-700 transition">
                                <Pencil className="h-3.5 w-3.5" /> {t('common.edit')}
                            </Link>
                        )}
                        <button onClick={onClose}
                            className="rounded-lg bg-neutral-200 dark:bg-neutral-700 px-4 py-1.5 text-sm font-medium text-neutral-700 dark:text-neutral-200 hover:bg-neutral-300 dark:hover:bg-neutral-600 transition">
                            {t('common.close')}
                        </button>
                    </div>
                </div>
            </div>
        </div>
    );
}

/* ── Post Card ────────────────────────────────────────────────── */
function PostCard({ post, accountMap, userTz, onView, onDelete, onEditFacebook, onDeleteFacebook, onDeleteInstagram }) {
    const { t } = useTranslation();
    const [actioning, setActioning] = useState(null);
    const targets = post.target_accounts ?? [];
    const dateField = post.published_at ?? post.scheduled_at;
    const tz = post.timezone || userTz;
    const publishedTargets = targets.filter((id) => post.publish_results?.[id]?.status === 'published');
    const hasPublishedTarget = publishedTargets.length > 0;
    const facebookTarget = publishedTargets.map((id) => accountMap[id]).find((account) => account?.network === 'facebook');
    const instagramTarget = publishedTargets.map((id) => accountMap[id]).find((account) => account?.network === 'instagram');
    const canDelete = ['draft', 'scheduled', 'failed'].includes(post.status) && !hasPublishedTarget;
    const canEdit   = ['draft', 'scheduled', 'failed'].includes(post.status) && !hasPublishedTarget;
    const canPublishNow = ['draft', 'scheduled', 'failed'].includes(post.status);
    const canCancel = post.status === 'scheduled';
    const mediaUrls = (post.media_urls ?? []).filter(Boolean);

    const action = (type, confirmMsg, fn) => {
        if (!confirm(confirmMsg)) return;
        setActioning(type);
        router.post(fn(), {}, {
            preserveScroll: true,
            onFinish: () => setActioning(null),
        });
    };

    return (
        <div className="rounded-xl border border-neutral-200 dark:border-neutral-700 bg-white dark:bg-neutral-900 flex flex-col overflow-hidden hover:shadow-md transition-shadow">
            {/* Media thumbnail / accent bar */}
            {mediaUrls.length > 0 ? (
                <div className="relative h-40 bg-neutral-100 dark:bg-neutral-800 shrink-0">
                    <img src={mediaUrls[0]} alt="" className="w-full h-full object-cover" />
                    {mediaUrls.length > 1 && (
                        <span className="absolute top-2 right-2 flex items-center gap-1 rounded-full bg-black/60 px-2 py-0.5 text-[10px] text-white">
                            <Image className="h-3 w-3" /> {mediaUrls.length}
                        </span>
                    )}
                </div>
            ) : (
                <div className="h-1.5 bg-gradient-to-r from-brand-500 to-brand-400 shrink-0" />
            )}

            <div className="flex flex-col flex-1 p-4 gap-3">
                {/* Status + date */}
                <div className="flex items-center justify-between gap-2 flex-wrap">
                    <StatusBadge status={post.status} />
                    {dateField && (
                        <span className="flex items-center gap-1 text-[11px] text-neutral-400">
                            <Clock className="h-3 w-3 shrink-0" /> {formatInTz(dateField, tz)}
                        </span>
                    )}
                </div>

                {/* Content */}
                <div className="flex-1 min-h-0">
                    {post.title && <p className="text-sm font-semibold text-neutral-900 dark:text-neutral-100 mb-1 truncate">{post.title}</p>}
                    <p className="text-xs text-neutral-500 dark:text-neutral-400 line-clamp-3 leading-relaxed">{post.body}</p>
                </div>

                {/* Accounts */}
                {targets.length > 0 && (
                    <div className="flex flex-wrap gap-1.5">
                        {targets.slice(0, 3).map((id) => {
                            const acct = accountMap[id];
                            return acct ? <AccountPill key={id} acct={acct} /> : null;
                        })}
                        {targets.length > 3 && <span className="text-[11px] text-neutral-400 self-center">{t('social.more_count', { count: targets.length - 3 })}</span>}
                    </div>
                )}

                {/* Primary actions */}
                <div className="flex items-center gap-2 pt-2 border-t border-neutral-100 dark:border-neutral-800">
                    <button onClick={() => onView(post)}
                        className="flex-1 inline-flex items-center justify-center gap-1.5 rounded-lg border border-neutral-200 dark:border-neutral-700 px-3 py-1.5 text-xs font-medium text-neutral-600 dark:text-neutral-300 hover:border-brand-400 hover:text-brand-600 transition">
                        <Eye className="h-3.5 w-3.5" /> {t('social.details')}
                    </button>
                    {canEdit && (
                        <Link href={route('client.social.posts.edit', post.id)}
                            className="flex-1 inline-flex items-center justify-center gap-1.5 rounded-lg border border-neutral-200 dark:border-neutral-700 px-3 py-1.5 text-xs font-medium text-neutral-600 dark:text-neutral-300 hover:border-brand-400 hover:text-brand-600 transition">
                            <Pencil className="h-3.5 w-3.5" /> {t('common.edit')}
                        </Link>
                    )}
                    {facebookTarget && (
                        <button type="button" onClick={() => onEditFacebook(post, facebookTarget, post.publish_results?.[facebookTarget.id])}
                            className="flex-1 inline-flex items-center justify-center gap-1.5 rounded-lg border border-neutral-200 dark:border-neutral-700 px-3 py-1.5 text-xs font-medium text-neutral-600 dark:text-neutral-300 hover:border-brand-400 hover:text-brand-600 transition">
                            <Pencil className="h-3.5 w-3.5" /> Facebook
                        </button>
                    )}
                </div>

                {/* Secondary actions */}
                <div className="flex items-center gap-1.5 flex-wrap">
                    {canPublishNow && (
                        <button
                            onClick={() => action('publish', t('social.confirm_publish_now'), () => route('client.social.posts.publish-now', post.id))}
                            disabled={actioning === 'publish'}
                            className="inline-flex items-center gap-1 rounded-lg bg-brand-600 px-2.5 py-1 text-[11px] font-medium text-white hover:bg-brand-700 disabled:opacity-60 transition"
                        >
                            <Zap className="h-3 w-3" /> {actioning === 'publish' ? t('social.publishing') : t('social.publish_now')}
                        </button>
                    )}
                    {canCancel && (
                        <button
                            onClick={() => action('cancel', t('social.confirm_cancel_schedule'), () => route('client.social.posts.cancel', post.id))}
                            disabled={actioning === 'cancel'}
                            className="inline-flex items-center gap-1 rounded-lg border border-amber-300 px-2.5 py-1 text-[11px] font-medium text-amber-600 hover:bg-amber-50 dark:hover:bg-amber-900/20 disabled:opacity-60 transition"
                        >
                            <Ban className="h-3 w-3" /> {actioning === 'cancel' ? t('social.cancelling') : t('social.cancel_schedule')}
                        </button>
                    )}
                    {post.post_url && (
                        <a href={post.post_url} target="_blank" rel="noopener noreferrer"
                            className="inline-flex items-center gap-1 rounded-lg border border-neutral-200 dark:border-neutral-700 px-2.5 py-1 text-[11px] font-medium text-neutral-500 hover:text-brand-600 transition">
                            <ExternalLink className="h-3 w-3" /> {t('social.view')}
                        </a>
                    )}
                    {facebookTarget && (
                        <button type="button" onClick={() => onDeleteFacebook(post, facebookTarget)}
                            className="ml-auto inline-flex items-center gap-1 rounded-lg border border-neutral-200 px-2 py-1 text-[11px] text-neutral-400 hover:border-red-300 hover:text-red-500 dark:border-neutral-700"
                            title={t('social.delete_facebook_post')}>
                            <Trash2 className="h-3 w-3" /> Facebook
                        </button>
                    )}
                    {instagramTarget && (
                        <button type="button" onClick={() => onDeleteInstagram(post, instagramTarget)}
                            className="ml-auto inline-flex items-center gap-1 rounded-lg border border-neutral-200 px-2 py-1 text-[11px] text-neutral-400 hover:border-red-300 hover:text-red-500 dark:border-neutral-700"
                            title={t('social.delete_instagram_post')}>
                            <Trash2 className="h-3 w-3" /> Instagram
                        </button>
                    )}
                    {canDelete && (
                        <button onClick={() => onDelete(post.id)}
                            className="ml-auto inline-flex items-center gap-1 rounded-lg border border-neutral-200 dark:border-neutral-700 px-2 py-1 text-[11px] text-neutral-400 hover:text-red-500 hover:border-red-300 transition">
                            <Trash2 className="h-3 w-3" /> {post.status === 'scheduled' ? t('social.delete_schedule') : t('common.delete')}
                        </button>
                    )}
                </div>
            </div>
        </div>
    );
}

/* ── Main Page ────────────────────────────────────────────────── */
export default function PostsIndex({ posts, accounts, filters }) {
    const { t } = useTranslation();
    const { props } = usePage();
    const flash = props.flash ?? {};
    const userTz = props.timezone || browserTz() || 'Asia/Dhaka';

    const [plannerOpen, setPlannerOpen] = useState(false);
    const [detailPost, setDetailPost] = useState(null);
    const [facebookEdit, setFacebookEdit] = useState(null);
    const accountMap = accounts.reduce((acc, a) => { acc[a.id] = a; return acc; }, {});

    const handleFilter = (key, val) =>
        router.get(route('client.social.posts.index'), { ...filters, [key]: val || undefined }, { preserveState: true, replace: true });

    const handleDelete = (id) => {
        if (confirm(t('social.confirm_delete_post'))) {
            router.delete(route('client.social.posts.destroy', id), {
                preserveScroll: true,
                onSuccess: () => setDetailPost(null),
            });
        }
    };

    const handleFacebookDelete = (post, account) => {
        if (!confirm(t('social.confirm_delete_facebook_post'))) return;

        router.delete(route('client.social.posts.facebook.destroy', [post.id, account.id]), {
            preserveScroll: true,
            onSuccess: () => setDetailPost(null),
        });
    };

    const handleInstagramDelete = (post, account) => {
        if (!confirm(t('social.confirm_delete_instagram_post'))) return;

        router.delete(route('client.social.posts.instagram.destroy', [post.id, account.id]), {
            preserveScroll: true,
            onSuccess: () => setDetailPost(null),
        });
    };

    return (
        <ClientLayout title={t('social.posts_title')}>
            <Head title={t('social.posts_head')} />
            <div className="space-y-5">
                {/* Header */}
                <div className="flex flex-wrap items-center justify-between gap-3">
                    <div>
                        <h2 className="text-xl font-semibold text-neutral-900 dark:text-neutral-100">{t('social.posts_title')}</h2>
                        <p className="text-sm text-neutral-500 dark:text-neutral-400 mt-0.5">{t('social.posts_subtitle')}</p>
                    </div>
                    {(
                        <div className="flex items-center gap-2">
                            <button onClick={() => setPlannerOpen(true)}
                                className="ai-glow inline-flex items-center gap-1.5 rounded-lg border border-brand-600 px-3 py-2 text-sm font-medium text-brand-600 hover:bg-brand-50 dark:hover:bg-brand-900/20 transition">
                                <Sparkles className="h-4 w-4" /> {t('social.ai_plan')}
                            </button>
                            <Link href={route('client.social.composer')}
                                className="inline-flex items-center gap-1.5 rounded-lg bg-brand-600 px-3 py-2 text-sm font-medium text-white hover:bg-brand-700 transition">
                                <Plus className="h-4 w-4" /> {t('social.new_post')}
                            </Link>
                        </div>
                    )}
                </div>

                {flash.success && (
                    <div className="rounded-lg bg-green-50 dark:bg-green-900/30 text-green-800 dark:text-green-200 px-4 py-2 text-sm">{flash.success}</div>
                )}
                {props.errors?.facebook && (
                    <div className="rounded-lg bg-red-50 px-4 py-2 text-sm text-red-700 dark:bg-red-900/30 dark:text-red-200">{props.errors.facebook}</div>
                )}
                {props.errors?.instagram && (
                    <div className="rounded-lg bg-red-50 px-4 py-2 text-sm text-red-700 dark:bg-red-900/30 dark:text-red-200">{props.errors.instagram}</div>
                )}

                {/* Filters */}
                <div className="flex flex-wrap items-center gap-3 rounded-xl border border-neutral-200 dark:border-neutral-700 bg-white dark:bg-neutral-900 px-4 py-3">
                    <span className="text-xs font-semibold text-neutral-400 uppercase tracking-wider shrink-0">{t('social.filter_by')}</span>
                    <div className="flex flex-wrap gap-2 flex-1">
                        <div className="relative">
                            <select
                                value={filters.status ?? ''}
                                onChange={(e) => handleFilter('status', e.target.value)}
                                className="appearance-none rounded-lg border border-neutral-200 dark:border-neutral-700 bg-neutral-50 dark:bg-neutral-800 pl-3 pr-8 py-1.5 text-sm font-medium text-neutral-700 dark:text-neutral-300 focus:outline-none focus:ring-2 focus:ring-brand-500 cursor-pointer"
                            >
                                <option value="">{t('social.status_all')}</option>
                                {Object.entries(STATUS_META).map(([k, v]) => <option key={k} value={k}>{t(v.labelKey)}</option>)}
                            </select>
                            <span className="pointer-events-none absolute right-2.5 top-1/2 -translate-y-1/2 text-neutral-400">
                                <svg className="h-3.5 w-3.5" viewBox="0 0 20 20" fill="currentColor"><path fillRule="evenodd" d="M5.23 7.21a.75.75 0 011.06.02L10 11.168l3.71-3.938a.75.75 0 111.08 1.04l-4.25 4.5a.75.75 0 01-1.08 0l-4.25-4.5a.75.75 0 01.02-1.06z" clipRule="evenodd" /></svg>
                            </span>
                        </div>
                        <div className="relative">
                            <select
                                value={filters.network ?? ''}
                                onChange={(e) => handleFilter('network', e.target.value)}
                                className="appearance-none rounded-lg border border-neutral-200 dark:border-neutral-700 bg-neutral-50 dark:bg-neutral-800 pl-3 pr-8 py-1.5 text-sm font-medium text-neutral-700 dark:text-neutral-300 focus:outline-none focus:ring-2 focus:ring-brand-500 cursor-pointer"
                            >
                                <option value="">{t('social.all_platforms')}</option>
                                {NETWORKS.map((n) => <option key={n} value={n}>{NETWORK_LABELS[n]}</option>)}
                            </select>
                            <span className="pointer-events-none absolute right-2.5 top-1/2 -translate-y-1/2 text-neutral-400">
                                <svg className="h-3.5 w-3.5" viewBox="0 0 20 20" fill="currentColor"><path fillRule="evenodd" d="M5.23 7.21a.75.75 0 011.06.02L10 11.168l3.71-3.938a.75.75 0 111.08 1.04l-4.25 4.5a.75.75 0 01-1.08 0l-4.25-4.5a.75.75 0 01.02-1.06z" clipRule="evenodd" /></svg>
                            </span>
                        </div>
                        {(filters.status || filters.network) && (
                            <button
                                onClick={() => router.get(route('client.social.posts.index'), {}, { replace: true })}
                                className="inline-flex items-center gap-1 rounded-lg px-2.5 py-1.5 text-xs font-medium text-neutral-400 hover:text-red-500 hover:bg-red-50 dark:hover:bg-red-900/20 transition"
                            >
                                <X className="h-3.5 w-3.5" /> {t('social.clear')}
                            </button>
                        )}
                    </div>
                    <span className="text-xs text-neutral-400 shrink-0">{t('social.post_count', { count: posts.total })}</span>
                </div>

                {/* Cards */}
                {posts.data.length === 0 ? (
                    <EmptyState
                        icon={<Share2 className="h-8 w-8" />}
                        title={t('social.no_posts_title')}
                        description={t('social.no_posts_description')}
                        action={{ label: t('social.new_post'), href: route('client.social.composer') }}
                    />
                ) : (
                    <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4">
                        {posts.data.map((post) => (
                            <PostCard
                                key={post.id}
                                post={post}
                                accountMap={accountMap}
                                userTz={userTz}
                                onView={setDetailPost}
                                onDelete={handleDelete}
                                onEditFacebook={(post, account, result) => setFacebookEdit({ post, account, result })}
                                onDeleteFacebook={handleFacebookDelete}
                                onDeleteInstagram={handleInstagramDelete}
                            />
                        ))}
                    </div>
                )}

                {/* Pagination */}
                {posts.last_page > 1 && (
                    <div className="flex gap-1 flex-wrap">
                        {posts.links.map((link, i) => (
                            link.url ? (
                                <Link key={i} href={link.url}
                                    className={`px-3 py-1.5 rounded text-sm border ${link.active ? 'bg-brand-600 text-white border-brand-600' : 'border-neutral-300 dark:border-neutral-600 text-neutral-700 dark:text-neutral-300 hover:bg-neutral-50 dark:hover:bg-neutral-800'}`}
                                    dangerouslySetInnerHTML={{ __html: link.label }} />
                            ) : (
                                <span key={i} className="px-3 py-1.5 rounded text-sm border border-neutral-200 dark:border-neutral-700 text-neutral-300 dark:text-neutral-600 opacity-40 cursor-not-allowed"
                                    dangerouslySetInnerHTML={{ __html: link.label }} />
                            )
                        ))}
                    </div>
                )}
            </div>

            <PostDetailModal
                post={detailPost}
                accountMap={accountMap}
                userTz={userTz}
                onClose={() => setDetailPost(null)}
                onDelete={handleDelete}
                onEditFacebook={(post, account, result) => setFacebookEdit({ post, account, result })}
                onDeleteFacebook={handleFacebookDelete}
                onDeleteInstagram={handleInstagramDelete}
            />

            <FacebookEditModal
                post={facebookEdit?.post}
                account={facebookEdit?.account}
                result={facebookEdit?.result}
                onClose={() => { setFacebookEdit(null); setDetailPost(null); }}
            />

            <AiPlannerModal show={plannerOpen} onClose={() => setPlannerOpen(false)} accounts={accounts}
                onSuccess={() => { setPlannerOpen(false); router.reload({ only: ['posts'] }); }} />
        </ClientLayout>
    );
}
