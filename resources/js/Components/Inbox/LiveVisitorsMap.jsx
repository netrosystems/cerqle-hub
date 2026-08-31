import React, { useEffect, useRef } from 'react';
import { useTranslation } from 'react-i18next';
import { MessageSquare, Globe, Plus, Minus, Maximize2 } from 'lucide-react';
import 'leaflet/dist/leaflet.css';
import L from 'leaflet';

export function getCountryFlagEmoji(countryCode) {
    if (!countryCode || typeof countryCode !== 'string' || countryCode.length !== 2) return '🌐';
    try {
        const codePoints = countryCode
            .toUpperCase()
            .split('')
            .map(char => 127397 + char.charCodeAt(0));
        return String.fromCodePoint(...codePoints);
    } catch {
        return '🌐';
    }
}

export default function LiveVisitorsMap({ visitors = [], selectedVisitorId, onSelectVisitor, onStartChat }) {
    const { t } = useTranslation();
    const mapContainerRef = useRef(null);
    const mapInstanceRef = useRef(null);
    const markersRef = useRef({});

    const isDarkMode = typeof document !== 'undefined' && document.documentElement.classList.contains('dark');

    // Filter valid visitors with coordinates
    const mappedVisitors = visitors.filter(v => {
        const cf = v.contact?.custom_fields || {};
        return typeof cf.webchat_lat === 'number' && typeof cf.webchat_lon === 'number';
    });

    useEffect(() => {
        if (!mapContainerRef.current) return;

        // Clean up previous instance if needed
        if (mapInstanceRef.current) {
            mapInstanceRef.current.remove();
            mapInstanceRef.current = null;
        }

        const map = L.map(mapContainerRef.current, {
            center: [20, 0],
            zoom: 2,
            minZoom: 2,
            maxZoom: 18,
            zoomControl: false,
            attributionControl: false,
        });

        const tileUrl = isDarkMode
            ? 'https://{s}.basemaps.cartocdn.com/dark_all/{z}/{x}/{y}{r}.png'
            : 'https://{s}.basemaps.cartocdn.com/rastertiles/voyager/{z}/{x}/{y}{r}.png';

        L.tileLayer(tileUrl, {
            subdomains: 'abcd',
            maxZoom: 19,
        }).addTo(map);

        L.control.attribution({ position: 'bottomright', prefix: false })
            .addAttribution('&copy; <a href="https://www.openstreetmap.org/copyright" target="_blank">OpenStreetMap</a> &copy; <a href="https://carto.com/" target="_blank">CARTO</a>')
            .addTo(map);

        mapInstanceRef.current = map;

        // Auto resize map when container bounds settle
        setTimeout(() => {
            map.invalidateSize();
        }, 150);

        return () => {
            if (mapInstanceRef.current) {
                mapInstanceRef.current.remove();
                mapInstanceRef.current = null;
            }
        };
    }, [isDarkMode]);

    // Update markers whenever mappedVisitors or selectedVisitorId changes
    useEffect(() => {
        const map = mapInstanceRef.current;
        if (!map) return;

        // Remove old markers
        Object.values(markersRef.current).forEach(marker => marker.remove());
        markersRef.current = {};

        const bounds = [];

        mappedVisitors.forEach(v => {
            const cf = v.contact?.custom_fields || {};
            const lat = Number(cf.webchat_lat);
            const lon = Number(cf.webchat_lon);
            const countryCode = cf.webchat_country_code || 'UN';
            const flag = getCountryFlagEmoji(countryCode);
            const isSelected = v.id === selectedVisitorId;

            const name = v.contact?.first_name || v.contact?.last_name
                ? `${v.contact?.first_name ?? ''} ${v.contact?.last_name ?? ''}`.trim()
                : (v.contact?.phone_e164 ?? `visitor${v.id}`);

            const city = cf.webchat_city || 'City';
            const country = cf.webchat_country || 'Country';
            const locationStr = [city, country].filter(Boolean).join(', ');
            const pageTitle = cf.webchat_page_title || cf.webchat_page_url || 'Active on website';

            const customIcon = L.divIcon({
                className: 'wb-map-marker',
                html: `
                    <div class="relative flex items-center justify-center cursor-pointer">
                        <span class="absolute inline-flex h-9 w-9 animate-ping rounded-full ${isSelected ? 'bg-brand-500 opacity-60' : 'bg-brand-400 opacity-30'}"></span>
                        <div class="relative flex h-8 w-8 items-center justify-center rounded-full bg-white dark:bg-neutral-900 border-2 ${isSelected ? 'border-brand-500 ring-4 ring-brand-500/40 scale-110' : 'border-brand-500 shadow-md'} transition-transform hover:scale-110">
                            <span class="text-sm select-none leading-none">${flag}</span>
                        </div>
                    </div>
                `,
                iconSize: [36, 36],
                iconAnchor: [18, 18],
            });

            const marker = L.marker([lat, lon], { icon: customIcon }).addTo(map);

            const popupContent = document.createElement('div');
            popupContent.className = 'p-3 text-neutral-800 dark:text-neutral-100 font-sans min-w-[220px]';
            popupContent.innerHTML = `
                <div class="flex items-center gap-2 mb-2 pb-2 border-b border-neutral-200 dark:border-neutral-700">
                    <span class="text-xl leading-none">${flag}</span>
                    <div class="min-w-0 flex-1">
                        <p class="font-bold text-xs truncate text-neutral-900 dark:text-white">${name}</p>
                        <p class="text-[11px] text-neutral-500 dark:text-neutral-400 truncate">${locationStr}</p>
                    </div>
                </div>
                <div class="mb-3 space-y-1 text-[11px] text-neutral-600 dark:text-neutral-300">
                    <p class="truncate font-medium flex items-center gap-1">
                        <span class="text-neutral-400">📄</span> ${pageTitle}
                    </p>
                    <p class="text-neutral-400 dark:text-neutral-500 text-[10px] truncate">
                        IP: ${cf.webchat_last_ip || 'Hidden'}
                    </p>
                </div>
                <button type="button" id="wb-popup-chat-btn-${v.id}" class="w-full flex items-center justify-center gap-1.5 rounded-lg bg-brand-600 hover:bg-brand-700 text-white px-3 py-1.5 text-xs font-semibold shadow-sm transition">
                    <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M7.9 20A9 9 0 1 0 4 16.1L2 22Z" />
                    </svg>
                    <span>${t('inbox.start_conversation', 'Start Conversation')}</span>
                </button>
            `;

            marker.bindPopup(popupContent, {
                className: 'wb-custom-map-popup',
                maxWidth: 280,
            });

            marker.on('popupopen', () => {
                onSelectVisitor?.(v.id);
                const btn = document.getElementById(`wb-popup-chat-btn-${v.id}`);
                if (btn) {
                    btn.onclick = (e) => {
                        e.preventDefault();
                        e.stopPropagation();
                        onStartChat?.(v);
                    };
                }
            });

            marker.on('click', () => {
                onSelectVisitor?.(v.id);
            });

            markersRef.current[v.id] = marker;
            bounds.push([lat, lon]);
        });

        // Fit bounds if we have markers and none selected
        if (bounds.length > 0 && !selectedVisitorId) {
            map.fitBounds(bounds, { padding: [60, 60], maxZoom: 6 });
        }
    }, [visitors, selectedVisitorId, isDarkMode, t]);

    // Fly to selected visitor when selection changes
    useEffect(() => {
        if (!selectedVisitorId || !mapInstanceRef.current) return;
        const selected = visitors.find(v => v.id === selectedVisitorId);
        const cf = selected?.contact?.custom_fields || {};
        if (typeof cf.webchat_lat === 'number' && typeof cf.webchat_lon === 'number') {
            mapInstanceRef.current.flyTo([cf.webchat_lat, cf.webchat_lon], 7, { duration: 1.2 });
            const marker = markersRef.current[selectedVisitorId];
            if (marker && !marker.isPopupOpen()) {
                marker.openPopup();
            }
        }
    }, [selectedVisitorId]);

    const handleZoomIn = () => mapInstanceRef.current?.zoomIn();
    const handleZoomOut = () => mapInstanceRef.current?.zoomOut();
    const handleResetView = () => {
        const coords = mappedVisitors.map(v => [
            Number(v.contact?.custom_fields?.webchat_lat),
            Number(v.contact?.custom_fields?.webchat_lon),
        ]);
        if (coords.length > 0) {
            mapInstanceRef.current?.fitBounds(coords, { padding: [60, 60], maxZoom: 6 });
        } else {
            mapInstanceRef.current?.setView([20, 0], 2);
        }
    };

    const onlineCount = visitors.length;

    return (
        <div className="relative flex-1 h-full w-full overflow-hidden bg-neutral-900 select-none">
            {/* Map Canvas */}
            <div ref={mapContainerRef} className="h-full w-full z-0" />

            {/* Top-left Stats Card */}
            <div className="absolute top-4 left-4 z-[400] rounded-2xl bg-white/90 dark:bg-neutral-900/90 backdrop-blur-md border border-neutral-200/80 dark:border-neutral-800/80 shadow-xl px-4 py-3 min-w-[210px] pointer-events-auto">
                <div className="flex items-baseline gap-2">
                    <span className="text-2xl font-black text-neutral-900 dark:text-neutral-100 tabular-nums">
                        {onlineCount}
                    </span>
                    <span className="text-sm font-semibold text-neutral-700 dark:text-neutral-300">
                        {onlineCount === 1 ? t('inbox.online_user', 'online user') : t('inbox.online_users', 'online users')}
                    </span>
                </div>
                <div className="flex items-center gap-1.5 mt-1">
                    <span className="relative flex h-2 w-2">
                        <span className="animate-ping absolute inline-flex h-full w-full rounded-full bg-emerald-400 opacity-75"></span>
                        <span className="relative inline-flex rounded-full h-2 w-2 bg-emerald-500"></span>
                    </span>
                    <span className="text-xs font-medium text-emerald-600 dark:text-emerald-400">
                        {t('inbox.live_view_map', 'Live view from MagicMap')}
                    </span>
                </div>
            </div>

            {/* Bottom-right Zoom & Center Controls */}
            <div className="absolute bottom-6 right-6 z-[400] flex flex-col gap-1.5 pointer-events-auto">
                <button
                    type="button"
                    onClick={handleResetView}
                    title={t('inbox.reset_map_view', 'Fit all visitors')}
                    className="h-9 w-9 rounded-xl bg-white/90 dark:bg-neutral-900/90 backdrop-blur border border-neutral-200 dark:border-neutral-700 shadow-lg flex items-center justify-center text-neutral-700 dark:text-neutral-300 hover:bg-white dark:hover:bg-neutral-800 transition"
                >
                    <Maximize2 className="h-4 w-4" />
                </button>
                <button
                    type="button"
                    onClick={handleZoomIn}
                    title={t('inbox.zoom_in', 'Zoom in')}
                    className="h-9 w-9 rounded-xl bg-white/90 dark:bg-neutral-900/90 backdrop-blur border border-neutral-200 dark:border-neutral-700 shadow-lg flex items-center justify-center text-neutral-700 dark:text-neutral-300 hover:bg-white dark:hover:bg-neutral-800 transition"
                >
                    <Plus className="h-4 w-4" />
                </button>
                <button
                    type="button"
                    onClick={handleZoomOut}
                    title={t('inbox.zoom_out', 'Zoom out')}
                    className="h-9 w-9 rounded-xl bg-white/90 dark:bg-neutral-900/90 backdrop-blur border border-neutral-200 dark:border-neutral-700 shadow-lg flex items-center justify-center text-neutral-700 dark:text-neutral-300 hover:bg-white dark:hover:bg-neutral-800 transition"
                >
                    <Minus className="h-4 w-4" />
                </button>
            </div>
        </div>
    );
}
