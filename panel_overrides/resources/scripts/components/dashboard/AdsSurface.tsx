import React, { useEffect, useMemo, useRef, useState } from 'react';
import tw from 'twin.macro';
import { AdsItem, getAdsPayload } from '@/api/ads';

const DesktopRail = tw.div`hidden lg:flex fixed left-4 bottom-4 z-30 w-[290px] flex-col gap-3`;
const BannerCard = tw.div`rounded-md border border-neutral-700 bg-neutral-900/95 shadow-xl overflow-hidden`;
const BannerBody = tw.div`p-2`;
const PopupBackdrop = tw.div`fixed inset-0 z-50 bg-black/80 flex items-center justify-center p-4`;
const PopupCard = tw.div`w-full max-w-3xl rounded-md border border-neutral-700 bg-neutral-900 overflow-hidden`;

const renderMedia = (ad: AdsItem, compact = false) => {
    if (ad.mediaKind === 'video') {
        return (
                <video
                    src={ad.mediaUrl}
                    autoPlay
                    loop
                    muted
                    playsInline
                    css={compact ? tw`w-full h-32 object-contain bg-black` : tw`w-full max-h-[72vh] object-contain bg-black`}
                />
            );
        }

    return <img src={ad.mediaUrl} alt={ad.text || 'ads'} css={compact ? tw`w-full h-32 object-contain bg-black` : tw`w-full max-h-[72vh] object-contain bg-black`} />;
};

const clickableWrap = (ad: AdsItem, child: React.ReactNode, key: string) => {
    if (ad.linkUrl) {
        return (
            <a key={key} href={ad.linkUrl} target={'_blank'} rel={'noopener noreferrer'} css={tw`block`}>
                {child}
            </a>
        );
    }

    return <div key={key}>{child}</div>;
};

export default () => {
    const [banners, setBanners] = useState<AdsItem[]>([]);
    const [popup, setPopup] = useState<AdsItem | null>(null);
    const [popupOpen, setPopupOpen] = useState(false);
    const [serviceEnabled, setServiceEnabled] = useState(true);
    const popupCandidateRef = useRef<AdsItem | null>(null);
    const popupOpenRef = useRef(false);
    const serviceEnabledRef = useRef(true);

    useEffect(() => {
        popupOpenRef.current = popupOpen;
    }, [popupOpen]);

    useEffect(() => {
        serviceEnabledRef.current = serviceEnabled;
    }, [serviceEnabled]);

    useEffect(() => {
        let cancelled = false;
        let adsTimer: number | null = null;
        let popupTimer: number | null = null;

        const randomBetween = (min: number, max: number) => Math.floor(Math.random() * (max - min + 1)) + min;

        const load = async () => {
            try {
                const payload = await getAdsPayload();
                if (cancelled) return;
                setServiceEnabled(Boolean(payload.serviceEnabled));
                serviceEnabledRef.current = Boolean(payload.serviceEnabled);
                if (!payload.serviceEnabled) {
                    setBanners([]);
                    setPopup(null);
                    setPopupOpen(false);
                    popupCandidateRef.current = null;
                    return;
                }
                setBanners(payload.banners.slice(0, 1));
                popupCandidateRef.current = payload.popup ?? null;
            } catch {
                // ignore ads failure
            }
        };

        const scheduleAdsRefresh = () => {
            const delay = randomBetween(120_000, 240_000);
            adsTimer = window.setTimeout(async () => {
                await load();
                if (!cancelled) {
                    scheduleAdsRefresh();
                }
            }, delay);
        };

        const schedulePopupSpawn = () => {
            const delay = randomBetween(150_000, 360_000);
            popupTimer = window.setTimeout(() => {
                if (cancelled) {
                    return;
                }

                const popupCandidate = popupCandidateRef.current;
                if (serviceEnabledRef.current && popupCandidate && !popupOpenRef.current) {
                    const now = Date.now();
                    const lastShown = Number(window.localStorage.getItem('ads.popup.last_shown') || '0');
                    const cooldownMs = 180_000;
                    if (now - lastShown >= cooldownMs && Math.random() <= 0.45) {
                        setPopup(popupCandidate);
                        setPopupOpen(true);
                        window.localStorage.setItem('ads.popup.last_shown', String(now));
                    }
                }

                if (!cancelled) {
                    schedulePopupSpawn();
                }
            }, delay);
        };

        void load();
        scheduleAdsRefresh();
        schedulePopupSpawn();

        return () => {
            cancelled = true;
            if (adsTimer !== null) {
                window.clearTimeout(adsTimer);
            }
            if (popupTimer !== null) {
                window.clearTimeout(popupTimer);
            }
        };
    }, []);

    const hasBanners = useMemo(() => banners.length > 0, [banners.length]);
    if (!serviceEnabled || (!hasBanners && !popupOpen)) return null;

    return (
        <>
            {hasBanners && (
                <>
                    <DesktopRail>
                        {banners.map((ad) =>
                            clickableWrap(
                                ad,
                                <BannerCard>
                                    {renderMedia(ad, true)}
                                    {ad.text && (
                                        <BannerBody>
                                            <p css={tw`text-xs text-neutral-200 line-clamp-2`}>{ad.text}</p>
                                        </BannerBody>
                                    )}
                                </BannerCard>,
                                `ads-desktop-banner-${ad.id}`
                            )
                        )}
                    </DesktopRail>
                </>
            )}

            {popupOpen && popup && (
                <PopupBackdrop onClick={() => setPopupOpen(false)}>
                    <PopupCard onClick={(e) => e.stopPropagation()}>
                        <div css={tw`px-3 py-2 border-b border-neutral-700 flex items-center justify-between`}>
                            <p css={tw`text-xs text-neutral-200 uppercase tracking-wide`}>Sponsored</p>
                            <button type={'button'} css={tw`text-xs text-neutral-300 hover:text-neutral-100`} onClick={() => setPopupOpen(false)}>
                                Close
                            </button>
                        </div>
                        {clickableWrap(popup, renderMedia(popup, false), `ads-popup-media-${popup.id}`)}
                        {(popup.text || popup.linkUrl) && (
                            <div css={tw`px-4 py-3 space-y-2`}>
                                {popup.text && <p css={tw`text-sm text-neutral-100`}>{popup.text}</p>}
                                {popup.linkUrl && (
                                    <a href={popup.linkUrl} target={'_blank'} rel={'noopener noreferrer'} css={tw`text-xs text-purple-300 hover:text-white`}>
                                        Open link
                                    </a>
                                )}
                            </div>
                        )}
                    </PopupCard>
                </PopupBackdrop>
            )}
        </>
    );
};
