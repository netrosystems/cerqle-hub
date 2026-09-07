export function belongsToWorkspace(notification, workspaceId) {
    return Number(workspaceId) > 0 && Number(notification?.workspace_id) === Number(workspaceId);
}
