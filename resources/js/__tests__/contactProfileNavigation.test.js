import { expect, it } from 'vitest'
import { contactProfileUrl, contactProfileReturnUrl } from '@/Utils/contactProfileNavigation'

it('returns to the originating chat with filters after refresh or new-tab entry', () => {
    const source = '/app/inbox/chat-uuid?folder=mine&channel=messenger#latest'
    const profile = contactProfileUrl('/app/contacts/contact-uuid', source)
    expect(contactProfileReturnUrl(new URL(profile, window.location.origin).search, '/app/contacts')).toBe(source)
})

it('preserves inbox list origin and falls back for direct entry or unsafe targets', () => {
    expect(contactProfileReturnUrl('?return_to=%2Fapp%2Finbox%3Ffolder%3Dmine', '/app/contacts')).toBe('/app/inbox?folder=mine')
    for (const target of ['', 'https://evil.test', '//evil.test', '/app/team', '/app/inbox/../../admin', '/\\evil.test', 'javascript:alert(1)']) {
        expect(contactProfileReturnUrl(`?return_to=${encodeURIComponent(target)}`, '/app/contacts')).toBe('/app/contacts')
    }
    expect(contactProfileReturnUrl('', '/app/contacts')).toBe('/app/contacts')
})
