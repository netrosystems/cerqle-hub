import ClientLayout from '@/Layouts/ClientLayout';
import { Button, Card, Input, Badge, Modal } from '@/Components/ui';
import { Head, router, usePage } from '@inertiajs/react';
import { AlertTriangle, Pencil, Trash2 } from 'lucide-react';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';

function WorkspaceAvatar({ name }) {
    const initials = name
        .split(' ')
        .slice(0, 2)
        .map((w) => w[0]?.toUpperCase() ?? '')
        .join('');

    return (
        <div className="flex h-10 w-10 shrink-0 items-center justify-center rounded-lg bg-brand-100 text-brand-700 font-semibold text-sm dark:bg-brand-900/40 dark:text-brand-300">
            {initials}
        </div>
    );
}

export default function WorkspacesIndex({
    workspaces = [],
    workspaceUsage = { limit: null, count: 0, remaining: null, can_create: true },
}) {
    const { t } = useTranslation();
    const [name, setName] = useState('');
    const [creating, setCreating] = useState(false);
    const [switching, setSwitching] = useState(null);
    const [editTarget, setEditTarget] = useState(null);
    const [editName, setEditName] = useState('');
    const [deleteTarget, setDeleteTarget] = useState(null);
    const [deleteConfirmation, setDeleteConfirmation] = useState('');
    const [saving, setSaving] = useState(false);
    const [deleting, setDeleting] = useState(false);
    const { currentWorkspace, errors = {} } = usePage().props;

    const handleSwitch = (workspaceId) => {
        setSwitching(workspaceId);
        router.post(route('client.workspaces.switch'), { workspace_id: workspaceId }, {
            preserveScroll: true,
            onFinish: () => setSwitching(null),
        });
    };

    const handleCreate = (e) => {
        e.preventDefault();
        if (!name.trim() || !workspaceUsage.can_create) return;
        setCreating(true);
        router.post(route('client.workspaces.store'), { name: name.trim() }, {
            preserveScroll: true,
            onSuccess: () => setName(''),
            onFinish: () => setCreating(false),
        });
    };

    const openEdit = (workspace) => {
        setEditTarget(workspace);
        setEditName(workspace.name);
    };

    const handleUpdate = (e) => {
        e.preventDefault();
        if (!editTarget || !editName.trim()) return;
        setSaving(true);
        router.put(route('client.workspaces.update', editTarget.id), { name: editName.trim() }, {
            preserveScroll: true,
            onSuccess: () => setEditTarget(null),
            onFinish: () => setSaving(false),
        });
    };

    const openDelete = (workspace) => {
        setDeleteTarget(workspace);
        setDeleteConfirmation('');
    };

    const handleDelete = () => {
        if (!deleteTarget || deleteConfirmation !== deleteTarget.name) return;
        setDeleting(true);
        router.delete(route('client.workspaces.destroy', deleteTarget.id), {
            data: { confirmation: deleteConfirmation },
            preserveScroll: true,
            onSuccess: () => {
                setDeleteTarget(null);
                setDeleteConfirmation('');
            },
            onFinish: () => setDeleting(false),
        });
    };

    return (
        <ClientLayout title={t('workspaces.title')}>
            <Head title={t('workspaces.title')} />

            <div className="max-w-2xl space-y-8">
                {/* Header */}
                <div>
                    <h2 className="text-xl font-semibold text-neutral-900 dark:text-neutral-100">{t('workspaces.title')}</h2>
                    <p className="mt-1 text-sm text-neutral-500 dark:text-neutral-400">
                        {t('workspaces.subtitle')}
                    </p>
                </div>

                {/* Workspace list */}
                <div className="space-y-3">
                    <h3 className="text-xs font-semibold uppercase tracking-wider text-neutral-400 dark:text-neutral-500">
                        {t('workspaces.your_workspaces', { count: workspaces.length })}
                    </h3>

                    {workspaces.length === 0 ? (
                        <Card>
                            <Card.Body className="py-10 text-center">
                                <div className="mx-auto mb-3 flex h-12 w-12 items-center justify-center rounded-xl bg-neutral-100 dark:bg-neutral-800">
                                    <svg className="h-6 w-6 text-neutral-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={1.5}>
                                        <path strokeLinecap="round" strokeLinejoin="round" d="M2.25 7.125C2.25 6.504 2.754 6 3.375 6h6c.621 0 1.125.504 1.125 1.125v3.75c0 .621-.504 1.125-1.125 1.125h-6a1.125 1.125 0 01-1.125-1.125v-3.75zM14.25 8.625c0-.621.504-1.125 1.125-1.125h5.25c.621 0 1.125.504 1.125 1.125v8.25c0 .621-.504 1.125-1.125 1.125h-5.25a1.125 1.125 0 01-1.125-1.125v-8.25zM3.75 16.125c0-.621.504-1.125 1.125-1.125h5.25c.621 0 1.125.504 1.125 1.125v2.25c0 .621-.504 1.125-1.125 1.125h-5.25a1.125 1.125 0 01-1.125-1.125v-2.25z" />
                                    </svg>
                                </div>
                                <p className="text-sm font-medium text-neutral-700 dark:text-neutral-300">{t('workspaces.none_yet')}</p>
                                <p className="mt-1 text-sm text-neutral-400">{t('workspaces.create_first')}</p>
                            </Card.Body>
                        </Card>
                    ) : (
                        <ul className="space-y-2">
                            {workspaces.map((w) => {
                                const isCurrent = currentWorkspace?.id === w.id;
                                const isSwitching = switching === w.id;
                                return (
                                    <li key={w.id}>
                                        <div className={[
                                            'flex items-center justify-between rounded-xl border px-4 py-3 transition-colors duration-150',
                                            isCurrent
                                                ? 'border-brand-300 bg-brand-50 dark:border-brand-700 dark:bg-brand-900/20'
                                                : 'border-neutral-200 bg-white hover:border-neutral-300 hover:bg-neutral-50 dark:border-neutral-700 dark:bg-neutral-900 dark:hover:border-neutral-600 dark:hover:bg-neutral-800',
                                        ].join(' ')}>
                                            <div className="flex items-center gap-3">
                                                <WorkspaceAvatar name={w.name} />
                                                <div>
                                                    <p className="text-sm font-medium text-neutral-900 dark:text-neutral-100">{w.name}</p>
                                                    <p className="text-xs text-neutral-400 dark:text-neutral-500">
                                                        {w.is_owner ? t('workspaces.owner') : t('workspaces.member')}
                                                    </p>
                                                </div>
                                            </div>

                                            <div className="flex items-center gap-2">
                                                {isCurrent && (
                                                    <Badge variant="brand" size="sm">{t('common.active')}</Badge>
                                                )}
                                                <Button
                                                    variant={isCurrent ? 'outline' : 'primary'}
                                                    size="sm"
                                                    onClick={() => !isCurrent && handleSwitch(w.id)}
                                                    disabled={isCurrent || isSwitching}
                                                >
                                                    {isSwitching ? t('workspaces.switching') : isCurrent ? t('workspaces.current') : t('workspaces.switch')}
                                                </Button>
                                                {w.can_update && (
                                                    <Button
                                                        variant="outline"
                                                        size="sm"
                                                        onClick={() => openEdit(w)}
                                                        aria-label={t('workspaces.edit_workspace')}
                                                        title={t('workspaces.edit_workspace')}
                                                    >
                                                        <Pencil className="h-3.5 w-3.5" />
                                                    </Button>
                                                )}
                                                {w.can_delete && (
                                                    <Button
                                                        variant="outline"
                                                        size="sm"
                                                        onClick={() => openDelete(w)}
                                                        disabled={workspaces.length <= 1}
                                                        aria-label={t('workspaces.delete_workspace', { defaultValue: 'Permanently delete workspace' })}
                                                        title={workspaces.length <= 1
                                                            ? t('workspaces.cannot_delete_only', { defaultValue: 'Create another workspace before deleting this one' })
                                                            : t('workspaces.delete_workspace', { defaultValue: 'Permanently delete workspace' })}
                                                        className="text-coral-600 hover:border-coral-300 hover:bg-coral-50 dark:text-coral-400 dark:hover:border-coral-800 dark:hover:bg-coral-950/30"
                                                    >
                                                        <Trash2 className="h-3.5 w-3.5" />
                                                    </Button>
                                                )}
                                            </div>
                                        </div>
                                    </li>
                                );
                            })}
                        </ul>
                    )}
                </div>

                {/* Create workspace */}
                {(
                <Card className="border-dashed border-neutral-300 dark:border-neutral-700">
                    <Card.Body className="space-y-4">
                        <div className="flex items-start gap-3">
                            <div className="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-brand-50 dark:bg-brand-900/30">
                                <svg className="h-4.5 w-4.5 text-brand-600 dark:text-brand-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={1.75}>
                                    <path strokeLinecap="round" strokeLinejoin="round" d="M12 4.5v15m7.5-7.5h-15" />
                                </svg>
                            </div>
                            <div>
                                <h3 className="text-sm font-semibold text-neutral-800 dark:text-neutral-200">{t('workspaces.create_new')}</h3>
                                <p className="mt-0.5 text-xs text-neutral-400 dark:text-neutral-500">{t('workspaces.create_new_desc')}</p>
                                <p className={[
                                    'mt-1 text-xs font-medium',
                                    workspaceUsage.can_create
                                        ? 'text-neutral-500 dark:text-neutral-400'
                                        : 'text-coral-600 dark:text-coral-400',
                                ].join(' ')}>
                                    {workspaceUsage.limit === null
                                        ? t('workspaces.unlimited_plan')
                                        : t('workspaces.plan_usage', {
                                            count: workspaceUsage.count,
                                            limit: workspaceUsage.limit,
                                        })}
                                </p>
                            </div>
                        </div>

                        <form onSubmit={handleCreate} className="flex items-end gap-3">
                            <Input
                                label={t('workspaces.name_label')}
                                value={name}
                                onChange={(e) => setName(e.target.value)}
                                placeholder={t('workspaces.name_placeholder')}
                                disabled={!workspaceUsage.can_create}
                                error={errors.name}
                                className="flex-1"
                            />
                            <Button
                                type="submit"
                                variant="primary"
                                disabled={creating || !name.trim() || !workspaceUsage.can_create}
                                className="shrink-0"
                            >
                                {creating ? (
                                    <span className="flex items-center gap-1.5">
                                        <svg className="h-3.5 w-3.5 animate-spin" viewBox="0 0 24 24" fill="none">
                                            <circle className="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" strokeWidth="4" />
                                            <path className="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8H4z" />
                                        </svg>
                                        {t('workspaces.creating')}
                                    </span>
                                ) : (
                                    <span className="flex items-center gap-1.5">
                                        <svg className="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2.5}>
                                            <path strokeLinecap="round" strokeLinejoin="round" d="M12 4.5v15m7.5-7.5h-15" />
                                        </svg>
                                        {t('workspaces.create_workspace')}
                                    </span>
                                )}
                            </Button>
                        </form>
                        {!workspaceUsage.can_create && !errors.name && (
                            <p className="text-sm text-coral-600 dark:text-coral-400">
                                {t('workspaces.limit_reached')}
                            </p>
                        )}
                    </Card.Body>
                </Card>
                )}
            </div>

            <Modal show={!!editTarget} onClose={() => !saving && setEditTarget(null)} maxWidth="sm">
                <form onSubmit={handleUpdate}>
                    <Modal.Header title={t('workspaces.edit_workspace')} onClose={() => setEditTarget(null)} />
                    <Modal.Body>
                        <Input
                            label={t('workspaces.name_label')}
                            value={editName}
                            onChange={(e) => setEditName(e.target.value)}
                            error={errors.name}
                            autoFocus
                            maxLength={255}
                        />
                    </Modal.Body>
                    <Modal.Footer>
                        <Button variant="secondary" onClick={() => setEditTarget(null)} disabled={saving}>
                            {t('common.cancel')}
                        </Button>
                        <Button type="submit" disabled={saving || !editName.trim()}>
                            {saving ? t('workspaces.saving') : t('workspaces.save_changes')}
                        </Button>
                    </Modal.Footer>
                </form>
            </Modal>

            <Modal show={!!deleteTarget} onClose={() => !deleting && setDeleteTarget(null)} maxWidth="md">
                <Modal.Header
                    title={t('workspaces.delete_workspace', { defaultValue: 'Permanently delete workspace' })}
                    onClose={() => setDeleteTarget(null)}
                />
                <Modal.Body className="space-y-4">
                    <div className="rounded-xl border border-coral-200 bg-coral-50 p-4 dark:border-coral-900 dark:bg-coral-950/30">
                        <div className="flex gap-3">
                            <AlertTriangle className="mt-0.5 h-5 w-5 shrink-0 text-coral-600 dark:text-coral-400" />
                            <div>
                                <p className="font-semibold text-coral-800 dark:text-coral-200">
                                    {t('workspaces.delete_warning_title', {
                                        defaultValue: 'This action is permanent and cannot be undone',
                                    })}
                                </p>
                                <p className="mt-1 text-sm leading-5 text-coral-700 dark:text-coral-300">
                                    {t('workspaces.delete_warning_body', {
                                        name: deleteTarget?.name,
                                        defaultValue: 'Deleting “{{name}}” will permanently erase all contacts, conversations, emails, campaigns, integrations, automations, analytics, settings, and other related information. Deleted data cannot be retrieved or restored.',
                                    })}
                                </p>
                            </div>
                        </div>
                    </div>
                    <p className="text-sm text-neutral-600 dark:text-neutral-300">
                        {t('workspaces.type_name_to_confirm', {
                            name: deleteTarget?.name,
                            defaultValue: 'To confirm permanent deletion, type the workspace name “{{name}}” below.',
                        })}
                    </p>
                    <Input
                        value={deleteConfirmation}
                        onChange={(e) => setDeleteConfirmation(e.target.value)}
                        placeholder={deleteTarget?.name}
                        error={errors.confirmation || errors.workspace}
                        autoFocus
                        autoComplete="off"
                    />
                </Modal.Body>
                <Modal.Footer>
                    <Button variant="secondary" onClick={() => setDeleteTarget(null)} disabled={deleting}>
                        {t('common.cancel')}
                    </Button>
                    <Button
                        variant="danger"
                        onClick={handleDelete}
                        disabled={deleting || deleteConfirmation !== deleteTarget?.name}
                    >
                        {deleting
                            ? t('workspaces.deleting', { defaultValue: 'Deleting permanently…' })
                            : t('workspaces.delete_permanently', { defaultValue: 'Permanently delete workspace' })}
                    </Button>
                </Modal.Footer>
            </Modal>
        </ClientLayout>
    );
}
