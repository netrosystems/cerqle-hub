// Decides whether a background contact-list operation should still be
// surfaced on the page. Each status has its own visibility window so the
// panel doesn't accumulate old rows from previous runs:
//   - queued:     always (a stopped worker must remain visible, not disappear)
//   - processing: always (large imports may legitimately run for a long time)
//   - completed:  5 minutes (you already saw the result; old imports
//                 shouldn't pile up forever)
//   - failed:     24 hours (long enough for an administrator to diagnose it)
// Completed CSV validations are also always visible until the user confirms
// them. They are an actionable review step, not a transient success message.
export const OPERATION_VISIBILITY = {
    queued: Number.POSITIVE_INFINITY,
    processing: Number.POSITIVE_INFINITY,
    completed: 5 * 60 * 1000,
    failed: 24 * 60 * 60 * 1000,
};

export function isLiveOperation(operation, now = Date.now()) {
    if (!operation) return false;
    if (operation.type === 'csv_validation' && operation.status === 'completed') return true;
    const window = OPERATION_VISIBILITY[operation.status];
    if (!window) return false;
    const timestamp = operation.status === 'queued'
        ? operation.created_at
        : operation.status === 'processing'
            ? (operation.started_at || operation.created_at)
            : (operation.finished_at || operation.updated_at || operation.created_at);
    if (!timestamp) return false;
    const startedAt = new Date(timestamp).getTime();
    if (!startedAt) return false;
    return now - startedAt < window;
}
