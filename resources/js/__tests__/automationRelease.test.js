import { describe, it, expect } from 'vitest';
import { automationReleaseNodes, legacyAutomation } from '../Utils/automationRelease';
describe('initial automation release', () => {
    it('exposes exactly ten retained nodes', () => { expect(automationReleaseNodes).toHaveLength(10); expect(automationReleaseNodes).not.toContain('send_poll'); });
    it('flags legacy content without changing it', () => { const nodes = [{type:'send_sms'}]; expect(legacyAutomation(nodes, 'message.received')).toBe(true); expect(nodes).toEqual([{type:'send_sms'}]); });
    it('accepts the WhatsApp support catalog', () => { expect(legacyAutomation([{type:'trigger'}, {type:'ask_question'}], 'message.received')).toBe(false); });
});
