export const automationReleaseNodes = ['send_whatsapp', 'send_template', 'send_media', 'quick_replies', 'ask_question', 'condition', 'wait', 'add_tag', 'remove_tag', 'assign_agent'];
export function legacyAutomation(nodes = [], trigger = '') {
    return (trigger && trigger !== 'message.received') || nodes.some(n => !['trigger', 'triggerNode', ...automationReleaseNodes].includes(n.data?.nodeType ?? n.type));
}
