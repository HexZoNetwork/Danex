import http from '@/api/http';

export type AdsMediaKind = 'image' | 'video';

export interface AdsItem {
    id: number;
    mediaUrl: string;
    linkUrl: string;
    text: string;
    isPopup: boolean;
    mediaKind: AdsMediaKind;
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
    isPopup: Boolean(item?.is_popup),
    mediaKind: String(item?.media_kind || 'image') === 'video' ? 'video' : 'image',
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
