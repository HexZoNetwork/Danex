import React, { useEffect, useMemo, useState } from 'react';
import tw from 'twin.macro';
import { AdsItem, getAdsPayload } from '@/api/ads';

const Wrap = tw.div`mx-auto w-full max-w-[1200px] px-4 mt-3`;
const BannerGrid = tw.div`grid gap-3 md:grid-cols-2`;
const BannerCard = tw.div`rounded-md border border-neutral-700 bg-neutral-900 overflow-hidden`;
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
                css={compact ? tw`w-full h-40 object-contain bg-black` : tw`w-full max-h-[72vh] object-contain bg-black`}
            />
        );
    }

    return <img src={ad.mediaUrl} alt={ad.text || 'ads'} css={compact ? tw`w-full h-40 object-contain bg-black` : tw`w-full max-h-[72vh] object-contain bg-black`} />;
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

    useEffect(() => {
        let cancelled = false;

        const load = async () => {
            try {
                const payload = await getAdsPayload();
                if (cancelled) return;
                setServiceEnabled(Boolean(payload.serviceEnabled));
                if (!payload.serviceEnabled) {
                    setBanners([]);
                    setPopup(null);
                    setPopupOpen(false);
                    return;
                }
                setBanners(payload.banners.slice(0, 2));

                const popupCandidate = payload.popup;
                if (!popupCandidate) return;

                const now = Date.now();
                const lastShown = Number(window.localStorage.getItem('ads.popup.last_shown') || '0');
                const cooldownMs = 7 * 60 * 1000;
                if (now - lastShown < cooldownMs) return;
                if (Math.random() > 0.35) return;

                setPopup(popupCandidate);
                setPopupOpen(true);
                window.localStorage.setItem('ads.popup.last_shown', String(now));
            } catch {
                // ignore ads failure
            }
        };

        load();
        const timer = window.setInterval(load, 90_000);

        return () => {
            cancelled = true;
            window.clearInterval(timer);
        };
    }, []);

    const hasBanners = useMemo(() => banners.length > 0, [banners.length]);
    if (!serviceEnabled || (!hasBanners && !popupOpen)) return null;

    return (
        <>
            {hasBanners && (
                <Wrap>
                    <BannerGrid>
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
                                `ads-banner-${ad.id}`
                            )
                        )}
                    </BannerGrid>
                </Wrap>
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
                                    <a href={popup.linkUrl} target={'_blank'} rel={'noopener noreferrer'} css={tw`text-xs text-cyan-400`}>
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
