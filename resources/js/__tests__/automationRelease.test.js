import { describe, it, expect } from 'vitest';
import { automationReleaseNodes, legacyAutomation } from '../Utils/automationRelease';
describe('initial automation release', () => {
    it('exposes only the four retained SEND actions', () => { expect(automationReleaseNodes).toEqual(['send_whatsapp', 'send_template', 'send_media', 'quick_replies']); });
    it('flags legacy content without changing it', () => { const nodes = [{type:'send_sms'}]; expect(legacyAutomation(nodes, 'message.received')).toBe(true); expect(nodes).toEqual([{type:'send_sms'}]); });
    it('accepts the SEND catalog', () => { expect(legacyAutomation([{type:'trigger'}, ...automationReleaseNodes.map(type => ({type}))], 'message.received')).toBe(false); });
    it.each(['ask_question', 'condition', 'wait', 'webhook', 'run_subflow', 'add_tag', 'remove_tag', 'update_contact', 'assign_agent', 'add_to_campaign', 'cta_button', 'send_location', 'send_poll', 'run_chatbot', 'book_appointment', 'google_meet', 'whatsapp_form', 'whatsapp_catalog', 'woocommerce_product', 'shopify_product', 'google_sheets', 'google_docs', 'google_forms', 'ai_reply'])('excludes %s without rewriting saved data', type => {
        const nodes = [{ type: 'actionNode', data: { nodeType: type, label: 'Stored configuration' } }];
        const before = JSON.stringify(nodes);
        expect(automationReleaseNodes).not.toContain(type);
        expect(legacyAutomation(nodes, 'message.received')).toBe(true);
        expect(JSON.stringify(nodes)).toBe(before);
    });
});
