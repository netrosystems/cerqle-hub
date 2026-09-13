// Creation palette only. Stored workflows retain their original nodes and handlers.
export const automationReleaseNodes = ['send_whatsapp', 'send_template', 'send_media', 'quick_replies'];
export function legacyAutomation(nodes = [], trigger = '') {
    return (trigger && trigger !== 'message.received') || nodes.some(n => !['trigger', 'triggerNode', ...automationReleaseNodes].includes(n.data?.nodeType ?? n.type));
}
