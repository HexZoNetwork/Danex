import http from '@/api/http';

export type AdsMediaKind = 'image' | 'video';

export interface AdsItem {
    id: number;
    mediaUrl: string;
    linkUrl: string;
    text: string;
    sponsorLabel: string;
    placement: 'banner' | 'popup' | 'both';
    isPopup: boolean;
    mediaKind: AdsMediaKind;
    dailyCap: number;
    cooldownMinutes: number;
    closeDelaySeconds: number;
}

export interface AdsPayload {
    serviceEnabled: boolean;
    banners: AdsItem[];
    popup: AdsItem | null;
}

const mapItem = (item: any): AdsItem => ({
    id: Number(item?.id || 0),
    mediaUrl: String(item?.media_url || ''),
    linkUrl: String(item?.link_url || ''),
    text: String(item?.text || ''),
    sponsorLabel: String(item?.sponsor_label || 'Sponsored'),
    placement: ['banner', 'popup', 'both'].includes(String(item?.placement)) ? item.placement : 'banner',
    isPopup: Boolean(item?.is_popup),
    mediaKind: String(item?.media_kind || 'image') === 'video' ? 'video' : 'image',
    dailyCap: Math.max(1, Number(item?.daily_cap || 1)),
    cooldownMinutes: Math.max(5, Number(item?.cooldown_minutes || 360)),
    closeDelaySeconds: Math.max(0, Number(item?.close_delay_seconds || 0)),
});

export const getAdsPayload = async (): Promise<AdsPayload> => {
    const { data } = await http.get('/api/client/ads');
    const raw = data?.data || {};

    return {
        serviceEnabled: Boolean(raw?.service_enabled ?? true),
        banners: Array.isArray(raw?.banners) ? raw.banners.map(mapItem).filter((item: AdsItem) => item.mediaUrl !== '') : [],
        popup: raw?.popup ? mapItem(raw.popup) : null,
    };
};
