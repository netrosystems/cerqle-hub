import { describe, it, expect } from 'vitest';
import { automationReleaseNodes, legacyAutomation } from '../Utils/automationRelease';

// The palette is the ten actions WorkflowValidator::TYPES will activate. A PHP
// test (AutomationGenerateAndRetryTest) checks the two lists stay identical.
const RELEASE = [
    'send_whatsapp', 'send_template', 'send_media', 'quick_replies',
    'ask_question', 'condition', 'wait', 'add_tag', 'remove_tag', 'assign_agent',
];

describe('automation release palette', () => {
    it('exposes exactly the ten validated actions', () => { expect(automationReleaseNodes).toEqual(RELEASE); });
    it('flags legacy content without changing it', () => { const nodes = [{type:'send_sms'}]; expect(legacyAutomation(nodes, 'message.received')).toBe(true); expect(nodes).toEqual([{type:'send_sms'}]); });
    it('accepts the release catalog', () => { expect(legacyAutomation([{type:'trigger'}, ...automationReleaseNodes.map(type => ({type}))], 'message.received')).toBe(false); });
    it('still treats any trigger other than Message Received as legacy', () => { expect(legacyAutomation([{type:'send_whatsapp'}], 'contact.created')).toBe(true); });

    // Restored on 2026-09-23 after being hidden on 2026-09-13; the engine was
    // already hardened for them and the validator already accepted them.
    it.each(['ask_question', 'condition', 'wait', 'add_tag', 'remove_tag', 'assign_agent'])('offers %s again', type => {
        expect(automationReleaseNodes).toContain(type);
        expect(legacyAutomation([{ type: 'actionNode', data: { nodeType: type } }], 'message.received')).toBe(false);
    });

    it.each(['webhook', 'run_subflow', 'update_contact', 'add_to_campaign', 'cta_button', 'send_location', 'send_poll', 'run_chatbot', 'book_appointment', 'google_meet', 'whatsapp_form', 'whatsapp_catalog', 'woocommerce_product', 'shopify_product', 'google_sheets', 'google_docs', 'google_forms', 'ai_reply'])('excludes %s without rewriting saved data', type => {
        const nodes = [{ type: 'actionNode', data: { nodeType: type, label: 'Stored configuration' } }];
        const before = JSON.stringify(nodes);
        expect(automationReleaseNodes).not.toContain(type);
        expect(legacyAutomation(nodes, 'message.received')).toBe(true);
        expect(JSON.stringify(nodes)).toBe(before);
    });
});
