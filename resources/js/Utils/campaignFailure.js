export function campaignFailureMessage(channel, reason) {
    if (!reason || channel !== 'whatsapp') {
        return reason
    }

    const providerText = String(reason)
    if (providerText.includes('138000')) {
        return 'This template includes a WhatsApp voice-call button, but Calling is not enabled for the selected sending number. Choose a template without a voice-call button.'
    }

    if (!providerText.includes('131009')) return reason

    const hasRecipientEvidence =
        /(?:recipient|phone(?: number)?|wa_id).*(?:not (?:a )?(?:valid|registered)|invalid|not on whatsapp|not a whatsapp user)|(?:not (?:a )?(?:valid|registered)|invalid|not on whatsapp|not a whatsapp user).*(?:recipient|phone(?: number)?|wa_id)/i.test(
            providerText,
        )

    if (hasRecipientEvidence) {
        return 'This phone number is not registered on WhatsApp or is invalid.'
    }

    return 'This phone number may not be registered on WhatsApp. Meta also uses this error when a message or template value is invalid.'
}
