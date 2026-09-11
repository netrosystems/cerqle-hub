import { describe, expect, it } from 'vitest'
import {
    whatsappTemplateOptionLabel,
    whatsappTemplateRequirements,
} from '@/Utils/whatsappTemplateRequirements'

describe('whatsappTemplateRequirements', () => {
    it('labels header-free templates as requiring no media', () => {
        const template = { components: [{ type: 'BODY', text: 'Hello' }] }
        expect(whatsappTemplateRequirements(template)).toEqual({
            mediaHeader: null,
            hasMediaHeader: false,
            hasVoiceCall: false,
        })
        expect(whatsappTemplateOptionLabel(template)).toBe('No media needed')
    })

    it('identifies template-defined image headers', () => {
        const template = { components: [{ type: 'HEADER', format: 'IMAGE' }] }
        expect(whatsappTemplateRequirements(template).mediaHeader).toBe('IMAGE')
        expect(whatsappTemplateOptionLabel(template)).toBe('image required')
    })

    it('marks voice-call templates unavailable for campaigns', () => {
        const template = {
            components: [{ type: 'BUTTONS', buttons: [{ type: 'VOICE_CALL', text: 'Call us' }] }],
        }
        expect(whatsappTemplateRequirements(template).hasVoiceCall).toBe(true)
        expect(whatsappTemplateOptionLabel(template)).toBe('Unavailable for campaigns: voice-call button')
    })
})
