import { useCallback, useEffect, useRef, useState } from 'react'
import axios from 'axios'

export const AVAILABILITY_URL = '/app/settings/notification-availability'
const CHANGED = 'cerqle:notification-availability-changed'
const STORAGE_KEY = 'cerqle:notification-availability'
export const WORK_NOTIFICATION_TYPES = new Set([
    'new_message',
    'mention',
    'conversation_assigned',
    'campaign_completed',
    'automation_failed',
    'handover',
    'pending_customer_reply',
    'workspace_export_ready',
])

export function allowsNotificationAlert(notification, availability, workspaceId) {
    if (notification.silent === true) return false
    if (!WORK_NOTIFICATION_TYPES.has(notification.type)) return true
    if (!availability || Number(availability.workspace_id) !== Number(workspaceId)) return false
    if (availability.next_boundary && Date.parse(availability.next_boundary) <= Date.now()) return false
    return availability.active === true
}

export function availabilityRequestOptions() {
    const token = document.querySelector('meta[name="csrf-token"]')?.content
    return {
        withCredentials: true,
        headers: {
            Accept: 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
            ...(token ? { 'X-CSRF-TOKEN': token } : {}),
        },
    }
}

export function notifyAvailabilityChanged(workspaceId) {
    window.dispatchEvent(new CustomEvent(CHANGED, { detail: workspaceId }))
    try {
        localStorage.setItem(STORAGE_KEY, JSON.stringify({ workspaceId, nonce: `${Date.now()}-${Math.random()}` }))
    } catch {
        /* Storage may be disabled; focus and periodic refresh still apply. */
    }
}

export default function useNotificationAvailability(workspaceId, url = AVAILABILITY_URL, memberId = null) {
    const [state, setState] = useState({ availability: null, loading: true, error: null })
    const current = useRef(null)
    const refreshRef = useRef(async () => {})
    const refresh = useCallback(() => refreshRef.current(), [])
    const shouldAlert = useCallback(
        (notification) => allowsNotificationAlert(notification, current.current, workspaceId),
        [workspaceId],
    )

    useEffect(() => {
        let disposed = false
        let sequence = 0
        let timer
        current.current = null
        setState({ availability: null, loading: true, error: null })
        const fetchAvailability = async () => {
            if (!workspaceId) {
                setState({ availability: null, loading: false, error: null })
                return
            }
            const request = ++sequence
            try {
                const { data } = await axios.get(url, availabilityRequestOptions())
                if (disposed || request !== sequence) return
                if (Number(data.workspace_id) !== Number(workspaceId)) throw new Error('Workspace changed.')
                if (memberId != null && Number(data.member_id) !== Number(memberId)) throw new Error('Member changed.')
                current.current = data
                setState({ availability: data, loading: false, error: null })
                clearTimeout(timer)
                const boundary = Date.parse(data.next_boundary)
                if (Number.isFinite(boundary)) {
                    timer = setTimeout(
                        fetchAvailability,
                        Math.min(2147483647, Math.max(1000, boundary - Date.now() + 100)),
                    )
                } else {
                    timer = setTimeout(fetchAvailability, 60000)
                }
            } catch (error) {
                if (disposed || request !== sequence) return
                current.current = null
                setState((previous) => ({ ...previous, loading: false, error }))
                clearTimeout(timer)
                timer = setTimeout(fetchAvailability, 60000)
            }
        }
        refreshRef.current = fetchAvailability
        const focus = () => {
            if (document.visibilityState !== 'hidden') fetchAvailability()
        }
        const changed = (event) => {
            if (Number(event.detail) === Number(workspaceId)) fetchAvailability()
        }
        const storage = (event) => {
            if (event.key !== STORAGE_KEY || !event.newValue) return
            try {
                if (Number(JSON.parse(event.newValue).workspaceId) === Number(workspaceId)) fetchAvailability()
            } catch {
                /* Ignore unrelated or malformed storage values. */
            }
        }
        fetchAvailability()
        window.addEventListener('focus', focus)
        document.addEventListener('visibilitychange', focus)
        window.addEventListener(CHANGED, changed)
        window.addEventListener('storage', storage)
        return () => {
            disposed = true
            clearTimeout(timer)
            window.removeEventListener('focus', focus)
            document.removeEventListener('visibilitychange', focus)
            window.removeEventListener(CHANGED, changed)
            window.removeEventListener('storage', storage)
        }
    }, [workspaceId, url, memberId])

    return { ...state, refresh, shouldAlert }
}
