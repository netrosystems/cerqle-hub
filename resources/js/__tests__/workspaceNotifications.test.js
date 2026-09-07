import { describe, expect, it } from 'vitest';
import { belongsToWorkspace } from '@/lib/workspaceNotifications';

describe('workspace notification filtering', () => {
    it('accepts the active workspace including serialized string IDs', () => {
        expect(belongsToWorkspace({ workspace_id: 4 }, '4')).toBe(true);
    });
    it('ignores other workspace broadcasts', () => {
        expect(belongsToWorkspace({ workspace_id: 5 }, 4)).toBe(false);
    });
    it('fails closed for missing workspace on either side', () => {
        expect(belongsToWorkspace({}, 4)).toBe(false);
        expect(belongsToWorkspace({ workspace_id: 4 }, null)).toBe(false);
        expect(belongsToWorkspace({}, undefined)).toBe(false);
    });
});
