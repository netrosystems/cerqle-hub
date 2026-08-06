// Decides whether a background contact-list operation should still be
// surfaced on the page. Each status has its own visibility window so the
// panel doesn't accumulate old rows from previous runs:
//   - queued:     5 minutes (after that, the worker never picked it up)
//   - processing: 30 minutes (a long import is still meaningful to show)
//   - completed:  5 minutes (you already saw the result; old imports
//                 shouldn't pile up forever)
//   - failed:     24 hours (an error is worth reading later)
export const OPERATION_VISIBILITY = {
    queued: 5 * 60 * 1000,
    processing: 30 * 60 * 1000,
    completed: 5 * 60 * 1000,
    failed: 24 * 60 * 60 * 1000,
};

export function isLiveOperation(operation, now = Date.now()) {
    if (!operation) return false;
    const window = OPERATION_VISIBILITY[operation.status];
    if (!window) return false;
    if (!operation.created_at) return operation.status !== 'queued' && operation.status !== 'processing';
    const startedAt = new Date(operation.created_at).getTime();
    if (!startedAt) return false;
    return now - startedAt < window;
}
