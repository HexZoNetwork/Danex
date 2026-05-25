import React, { useEffect, useMemo, useRef, useState } from 'react';
import tw from 'twin.macro';
import { AdsItem, getAdsPayload } from '@/api/ads';

const DesktopRail = tw.div`hidden lg:flex fixed left-4 bottom-4 z-30 w-[290px] flex-col gap-3`;
const BannerCard = tw.div`rounded-md border border-neutral-700 bg-neutral-900/95 shadow-xl overflow-hidden`;
const BannerBody = tw.div`p-2`;
const PopupBackdrop = tw.div`fixed inset-0 z-50 bg-black/80 flex items-center justify-center p-4`;
const PopupCard = tw.div`w-full max-w-3xl rounded-md border border-neutral-700 bg-neutral-900 overflow-hidden`;

const renderMedia = (ad: AdsItem, compact = false, autoplay = true) => {
    if (ad.mediaKind === 'video') {
        return (
                <video
                    src={ad.mediaUrl}
                    autoPlay={autoplay}
                    loop
                    muted
                    playsInline
                    css={compact ? tw`w-full h-32 object-contain bg-black` : tw`w-full max-h-[72vh] object-contain bg-black`}
                />
            );
        }

    return <img src={ad.mediaUrl} alt={ad.text || 'Sponsored media'} css={compact ? tw`w-full h-32 object-contain bg-black` : tw`w-full max-h-[72vh] object-contain bg-black`} />;
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
    const popupCardRef = useRef<HTMLDivElement | null>(null);
    const closeButtonRef = useRef<HTMLButtonElement | null>(null);
    const returnFocusRef = useRef<HTMLElement | null>(null);
    const [prefersReducedMotion, setPrefersReducedMotion] = useState(false);
    const [closeLocked, setCloseLocked] = useState(false);
    const [closeCountdown, setCloseCountdown] = useState(0);

    useEffect(() => {
        popupOpenRef.current = popupOpen;
    }, [popupOpen]);

    useEffect(() => {
        serviceEnabledRef.current = serviceEnabled;
    }, [serviceEnabled]);

    useEffect(() => {
        const media = window.matchMedia?.('(prefers-reduced-motion: reduce)');
        if (!media) return;
        const update = () => setPrefersReducedMotion(media.matches);
        update();
        media.addEventListener?.('change', update);
        return () => media.removeEventListener?.('change', update);
    }, []);

    useEffect(() => {
        if (!popupOpen) return;
        returnFocusRef.current = document.activeElement instanceof HTMLElement ? document.activeElement : null;
        closeButtonRef.current?.focus();

        const onKeyDown = (event: KeyboardEvent) => {
            if (event.key === 'Escape' && !closeLocked) {
                setPopupOpen(false);
                return;
            }
            if (event.key !== 'Tab' || !popupCardRef.current) return;
            const focusable = Array.from(popupCardRef.current.querySelectorAll<HTMLElement>('a[href],button:not([disabled])'));
            if (focusable.length === 0) return;
            const first = focusable[0];
            const last = focusable[focusable.length - 1];
            if (event.shiftKey && document.activeElement === first) {
                event.preventDefault();
                last.focus();
            } else if (!event.shiftKey && document.activeElement === last) {
                event.preventDefault();
                first.focus();
            }
        };

        document.addEventListener('keydown', onKeyDown);
        return () => {
            document.removeEventListener('keydown', onKeyDown);
            returnFocusRef.current?.focus();
        };
    }, [popupOpen, closeLocked]);

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
                    const shownKey = `ads.popup.${popupCandidate.id}.shown`;
                    const lastShown = Number(window.localStorage.getItem(`${shownKey}.last`) || '0');
                    const countDate = window.localStorage.getItem(`${shownKey}.date`) || '';
                    const today = new Date().toISOString().slice(0, 10);
                    const shownToday = countDate === today ? Number(window.localStorage.getItem(`${shownKey}.count`) || '0') : 0;
                    const cooldownMs = popupCandidate.cooldownMinutes * 60_000;
                    if (now - lastShown >= cooldownMs && shownToday < popupCandidate.dailyCap && Math.random() <= 0.65) {
                        setPopup(popupCandidate);
                        setPopupOpen(true);
                        setCloseLocked(popupCandidate.closeDelaySeconds > 0);
                        setCloseCountdown(popupCandidate.closeDelaySeconds);
                        window.localStorage.setItem(`${shownKey}.last`, String(now));
                        window.localStorage.setItem(`${shownKey}.date`, today);
                        window.localStorage.setItem(`${shownKey}.count`, String(shownToday + 1));
                        if (popupCandidate.closeDelaySeconds > 0) {
                            const countdown = window.setInterval(() => {
                                setCloseCountdown((current) => Math.max(0, current - 1));
                            }, 1000);
                            window.setTimeout(() => {
                                window.clearInterval(countdown);
                                setCloseLocked(false);
                                setCloseCountdown(0);
                            }, popupCandidate.closeDelaySeconds * 1000);
                        }
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
                                    {renderMedia(ad, true, !prefersReducedMotion)}
                                    <BannerBody>
                                        <p css={tw`text-[10px] text-yellow-300 uppercase tracking-wide mb-1`}>{ad.sponsorLabel}</p>
                                        {ad.text && <p css={tw`text-xs text-neutral-200 line-clamp-2`}>{ad.text}</p>}
                                        {ad.linkUrl && <p css={tw`mt-1 text-[10px] text-purple-300 uppercase tracking-wide`}>Open offer</p>}
                                    </BannerBody>
                                </BannerCard>,
                                `ads-desktop-banner-${ad.id}`
                            )
                        )}
                    </DesktopRail>
                </>
            )}

            {popupOpen && popup && (
                <PopupBackdrop onClick={() => !closeLocked && setPopupOpen(false)}>
                    <PopupCard ref={popupCardRef} role={'dialog'} aria-modal={'true'} aria-label={'Sponsored content'} tabIndex={-1} onClick={(e) => e.stopPropagation()}>
                        <div css={tw`px-3 py-2 border-b border-neutral-700 flex items-center justify-between`}>
                            <p css={tw`text-xs text-neutral-200 uppercase tracking-wide`}>{popup.sponsorLabel}</p>
                            <button ref={closeButtonRef} type={'button'} css={tw`text-xs text-neutral-300 hover:text-neutral-100 disabled:opacity-50`} disabled={closeLocked} onClick={() => setPopupOpen(false)}>
                                {closeLocked ? `Close in ${closeCountdown}s` : 'Close'}
                            </button>
                        </div>
                        {clickableWrap(popup, renderMedia(popup, false, !prefersReducedMotion), `ads-popup-media-${popup.id}`)}
                        {(popup.text || popup.linkUrl) && (
                            <div css={tw`px-4 py-3 space-y-2`}>
                                {popup.text && <p css={tw`text-sm text-neutral-100`}>{popup.text}</p>}
                                {popup.linkUrl && (
                                    <a href={popup.linkUrl} target={'_blank'} rel={'noopener noreferrer'} css={tw`inline-flex rounded border border-purple-500/40 px-3 py-1.5 text-xs text-purple-200 hover:text-white hover:border-purple-300`}>
                                        Open offer
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
