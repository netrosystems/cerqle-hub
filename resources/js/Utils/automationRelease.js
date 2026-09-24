// The creation palette: exactly the node types WorkflowValidator::TYPES will
// activate. The engine is hardened and tested for these ten; anything else a
// stored workflow contains is shown as legacy rather than silently removed.
// Keep this list and the validator's in step — the builder test enforces it.
export const automationReleaseNodes = [
    'send_whatsapp', 'send_template', 'send_media', 'quick_replies',
    'ask_question',
    'condition', 'wait',
    'add_tag', 'remove_tag', 'assign_agent',
];

export function legacyAutomation(nodes = [], trigger = '') {
    return (trigger && trigger !== 'message.received') || nodes.some(n => !['trigger', 'triggerNode', ...automationReleaseNodes].includes(n.data?.nodeType ?? n.type));
}

// Prompts offered by "Generate with AI", on the Automations page and in the
// builder. Every one must be buildable from the release actions and the
// Message Received trigger — Wisperbot's examples ("when a contact is
// added", "abandoned carts") produced drafts Cerqle would refuse to activate.
export const automationAiExamples = [
    ['automation.ai_example_pricing', 'When someone asks about price, reply with our pricing and ask if they would like a demo'],
    ['automation.ai_example_triage', 'Ask new customers what they need help with. If they say "order", ask for their order number; otherwise assign an agent'],
    ['automation.ai_example_after_hours', 'Thank people for their message, tag them as "follow-up", and wait 1 hour before asking if they still need help'],
];

// Body {{1}}-style variables are filled in the builder; anything else (media
// headers, variable headers or buttons, call/copy-code buttons, named
// parameters) cannot be, so activation would refuse the template.
export function templateIsSupported(components) {
    return (Array.isArray(components) ? components : []).every(c => {
        const type = (c.type || '').toUpperCase();
        const json = JSON.stringify(c);
        if (type === 'BODY') return !/\{\{\s*[^\d\s}][^}]*\}\}/.test(c.text || '');
        return !json.includes('{{')
            && !['IMAGE', 'VIDEO', 'DOCUMENT'].includes((c.format || '').toUpperCase())
            && !json.includes('VOICE_CALL') && !json.includes('COPY_CODE');
    });
}
