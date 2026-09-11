import { describe, expect, it } from 'vitest'
import { campaignFailureMessage } from '@/Utils/campaignFailure'

describe('campaignFailureMessage', () => {
    it('explains legacy Meta 131009 failures without making an unsupported diagnosis', () => {
        expect(
            campaignFailureMessage(
                'whatsapp',
                'WhatsApp send failed (HTTP 400): (#131009) Parameter value is not valid code 131009',
            ),
        ).toBe(
            'This phone number may not be registered on WhatsApp. Meta also uses this error when a message or template value is invalid.',
        )
    })

    it('uses definitive wording when Meta identifies the recipient phone number', () => {
        expect(
            campaignFailureMessage(
                'whatsapp',
                'Recipient phone number is not a valid WhatsApp user. (Meta error 131009)',
            ),
        ).toBe('This phone number is not registered on WhatsApp or is invalid.')
    })

    it('does not rewrite unrelated provider failures', () => {
        const reason = 'WhatsApp send failed (HTTP 500): Temporary provider error'
        expect(campaignFailureMessage('whatsapp', reason)).toBe(reason)
        expect(campaignFailureMessage('sms', reason)).toBe(reason)
    })

    it('explains Meta 138000 as a voice-call template problem', () => {
        expect(
            campaignFailureMessage(
                'whatsapp',
                'WhatsApp send failed (HTTP 400): Calling API not enabled. (Meta error 138000)',
            ),
        ).toBe(
            'This template includes a WhatsApp voice-call button, but Calling is not enabled for the selected sending number. Choose a template without a voice-call button.',
        )
    })
})
