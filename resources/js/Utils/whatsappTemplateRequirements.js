export function whatsappTemplateRequirements(template) {
    const components = Array.isArray(template?.components) ? template.components : []
    const header = components.find((component) => String(component?.type ?? '').toUpperCase() === 'HEADER')
    const headerFormat = String(header?.format ?? '').toUpperCase()
    const mediaHeader = ['IMAGE', 'VIDEO', 'DOCUMENT'].includes(headerFormat) ? headerFormat : null
    const hasVoiceCall = components.some(
        (component) =>
            String(component?.type ?? '').toUpperCase() === 'BUTTONS' &&
            (component.buttons ?? []).some((button) => String(button?.type ?? '').toUpperCase() === 'VOICE_CALL'),
    )

    return {
        mediaHeader,
        hasMediaHeader: mediaHeader !== null,
        hasVoiceCall,
    }
}

export function whatsappTemplateOptionLabel(template) {
    const requirements = whatsappTemplateRequirements(template)
    if (requirements.hasVoiceCall) return 'Unavailable for campaigns: voice-call button'
    if (requirements.mediaHeader) return `${requirements.mediaHeader.toLowerCase()} required`
    return 'No media needed'
}
