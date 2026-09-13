export function contactProfileUrl(profileUrl, source = window.location.href) {
    const profile = new URL(profileUrl, window.location.origin)
    const origin = new URL(source, window.location.origin)
    profile.searchParams.set('return_to', origin.pathname + origin.search + origin.hash)
    return profile.pathname + profile.search + profile.hash
}

export function contactProfileReturnUrl(search, fallback) {
    const target = new URLSearchParams(search).get('return_to')
    if (!target || !target.startsWith('/') || target.startsWith('//') || /[\\\u0000-\u001f]/.test(target)) return fallback
    try {
        const url = new URL(target, window.location.origin)
        if (url.origin !== window.location.origin || !/^\/app\/inbox(?:\/[^/]+)?\/?$/.test(url.pathname)) return fallback
        return url.pathname + url.search + url.hash
    } catch {
        return fallback
    }
}
