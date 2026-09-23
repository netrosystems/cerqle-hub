import { readFileSync } from 'node:fs'
import { join } from 'node:path'
import { afterEach, expect, it, vi } from 'vitest'

const widgetSource = readFileSync(
    join(process.cwd(), 'public/widget/cerqle-chat-widget.js'),
    'utf8',
)

const flushPromises = () => new Promise(resolve => setTimeout(resolve, 0))

afterEach(() => {
    document.body.innerHTML = ''
    delete window.__WB_CHAT__
    delete window.__WB_CHAT_LOADED__
    delete window.__WB_CHAT_WIDGET_MOUNTED__
    delete window.Cerqle
    delete window.WisperBot
    window.localStorage.clear()
    vi.unstubAllGlobals()
    vi.useRealTimers()
})

it('never shows the launcher when the initial session says the widget is unavailable', async () => {
    window.__WB_CHAT__ = {
        key: 'disabled-widget-key',
        config: {
            api_base: 'http://127.0.0.1:8000',
            title: 'Support',
            welcome_message: 'Hello',
            prechat_fields: [],
        },
    }
    vi.stubGlobal('fetch', vi.fn().mockResolvedValue({
        ok: false,
        status: 404,
        json: vi.fn(),
    }))

    window.eval(widgetSource)

    const pendingHost = document.getElementById('wb-chat-host')
    expect(pendingHost).not.toBeNull()
    expect(pendingHost.style.display).toBe('none')

    await flushPromises()
    await flushPromises()

    expect(document.getElementById('wb-chat-host')).toBeNull()
})

it('reveals the launcher only after a successful session check', async () => {
    window.__WB_CHAT__ = {
        key: 'enabled-widget-key',
        config: {
            api_base: 'http://127.0.0.1:8000',
            title: 'Support',
            welcome_message: 'Hello',
            prechat_fields: [],
        },
    }
    vi.stubGlobal('fetch', vi.fn().mockResolvedValue({
        ok: true,
        status: 200,
        json: vi.fn().mockResolvedValue({
            visitor_id: 'visitor-1',
            conversation_id: 1,
            token: 'signed-token',
            messages: [],
        }),
    }))

    window.eval(widgetSource)

    const host = document.getElementById('wb-chat-host')
    expect(host.style.display).toBe('none')

    await flushPromises()
    await flushPromises()

    expect(document.getElementById('wb-chat-host').style.display).not.toBe('none')
})

it('removes an already visible launcher after the widget is turned off', async () => {
    vi.useFakeTimers()
    window.__WB_CHAT__ = {
        key: 'widget-key',
        config: {
            api_base: 'http://127.0.0.1:8000',
            title: 'Support',
            welcome_message: 'Hello',
            prechat_fields: [],
        },
    }
    const fetchMock = vi.fn()
        .mockResolvedValueOnce({
            ok: true,
            status: 200,
            json: vi.fn().mockResolvedValue({
                visitor_id: 'visitor-1',
                conversation_id: 1,
                token: 'signed-token',
                messages: [],
            }),
        })
        .mockResolvedValueOnce({ ok: false, status: 404, json: vi.fn() })
        .mockResolvedValueOnce({ ok: false, status: 404, json: vi.fn() })
    vi.stubGlobal('fetch', fetchMock)

    window.eval(widgetSource)
    await vi.advanceTimersByTimeAsync(1)
    expect(document.getElementById('wb-chat-host').style.display).not.toBe('none')

    await vi.advanceTimersByTimeAsync(4999)
    await Promise.resolve()
    await Promise.resolve()

    expect(fetchMock).toHaveBeenCalledTimes(3)
    expect(document.getElementById('wb-chat-host')).toBeNull()
})
