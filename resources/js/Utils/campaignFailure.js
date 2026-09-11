export function campaignFailureMessage(channel, reason) {
    if (!reason || channel !== 'whatsapp' || !String(reason).includes('131009')) {
        return reason
    }

    const providerText = String(reason)
    const hasRecipientEvidence =
        /(?:recipient|phone(?: number)?|wa_id).*(?:not (?:a )?(?:valid|registered)|invalid|not on whatsapp|not a whatsapp user)|(?:not (?:a )?(?:valid|registered)|invalid|not on whatsapp|not a whatsapp user).*(?:recipient|phone(?: number)?|wa_id)/i.test(
            providerText,
        )

    if (hasRecipientEvidence) {
        return 'This phone number is not registered on WhatsApp or is invalid.'
    }

    return 'This phone number may not be registered on WhatsApp. Meta also uses this error when a message or template value is invalid.'
}
