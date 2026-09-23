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
