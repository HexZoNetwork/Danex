import React, { useEffect, useRef, useState } from 'react';
import tw from 'twin.macro';
import PageContentBlock from '@/components/elements/PageContentBlock';
import { Button } from '@/components/elements/button/index';
import { DanexCoinSpinLog, getDanexCoinState, spinDanexCoin } from '@/api/danexcoin';
import sevenImage from '@/assets/danexcoin/seven.svg';
import barImage from '@/assets/danexcoin/bar.svg';
import cherryImage from '@/assets/danexcoin/cherry.svg';
import diamondImage from '@/assets/danexcoin/diamond.svg';
import bellImage from '@/assets/danexcoin/bell.svg';
import starImage from '@/assets/danexcoin/star.svg';

const Panel = tw.div`rounded-xl border border-neutral-700 bg-neutral-800 p-4 shadow-lg`;
const ReelWrap = tw.div`rounded-xl border border-cyan-800/60 bg-gradient-to-b from-neutral-900 to-neutral-800 p-3`;
const Reel = tw.div`rounded-lg border border-cyan-700/40 bg-neutral-900 py-4 text-center shadow-inner min-h-[116px] flex items-center justify-center`;
const ReelImage = tw.img`w-16 h-16 sm:w-20 sm:h-20 object-contain`;
const ReelFallback = tw.div`text-lg text-cyan-300 font-bold`;

const toNumber = (value: string): number => Number.parseFloat(value || '0') || 0;
const SYMBOLS = ['7', 'BAR', 'CHERRY', 'DIAMOND', 'BELL', 'STAR'] as const;
type SymbolKey = (typeof SYMBOLS)[number];
const SPIN_DURATION_MS = 5500;

const symbolImageMap: Record<SymbolKey, string> = {
    '7': sevenImage,
    BAR: barImage,
    CHERRY: cherryImage,
    DIAMOND: diamondImage,
    BELL: bellImage,
    STAR: starImage,
};

const randomSymbol = (): SymbolKey => SYMBOLS[Math.floor(Math.random() * SYMBOLS.length)];

export default () => {
    const spinLockRef = useRef(false);
    const [loading, setLoading] = useState(true);
    const [spinning, setSpinning] = useState(false);
    const [bet, setBet] = useState('10');
    const [balance, setBalance] = useState('0.00');
    const [history, setHistory] = useState<DanexCoinSpinLog[]>([]);
    const [loadError, setLoadError] = useState('');
    const [reels, setReels] = useState<[SymbolKey | '?', SymbolKey | '?', SymbolKey | '?']>(['?', '?', '?']);
    const [lastMessage, setLastMessage] = useState('Masukkan bet lalu spin.');

    const load = async () => {
        const state = await getDanexCoinState();
        setBalance(String(state?.balance || '0.00'));
        setHistory(
            Array.isArray(state?.history)
                ? state.history.filter((row) => Array.isArray((row as any)?.reels) && (row as any).reels.length === 3)
                : []
        );
    };

    const reelsForRow = (row: DanexCoinSpinLog): [string, string, string] => {
        if (Array.isArray((row as any)?.reels) && (row as any).reels.length === 3) {
            return [String(row.reels[0] || '?'), String(row.reels[1] || '?'), String(row.reels[2] || '?')];
        }

        return ['?', '?', '?'];
    };

    const renderSymbol = (value: string | '?') => {
        const image = symbolImageMap[value as SymbolKey];
        if (image) {
            return <ReelImage src={image} alt={value} />;
        }

        return <ReelFallback>{value}</ReelFallback>;
    };

    useEffect(() => {
        let cancelled = false;
        (async () => {
            try {
                await load();
                if (!cancelled) {
                    setLoadError('');
                }
            } catch (error: any) {
                if (!cancelled) {
                    setLoadError(String(error?.response?.data?.error || 'Gagal memuat DanexCoin.'));
                }
            } finally {
                if (!cancelled) setLoading(false);
            }
        })();

        return () => {
            cancelled = true;
        };
    }, []);

    const onSpin = async () => {
        if (spinLockRef.current) return;

        const betValue = Number.parseFloat(bet);
        if (!Number.isFinite(betValue) || betValue <= 0) {
            setLastMessage('Bet harus angka dan lebih dari 0.');
            return;
        }

        spinLockRef.current = true;
        setSpinning(true);
        setLastMessage('Mesin lagi muter...');
        let spinFrame: number | null = null;
        try {
            spinFrame = window.setInterval(() => {
                setReels([randomSymbol(), randomSymbol(), randomSymbol()]);
            }, 110);

            const [result] = await Promise.all([
                spinDanexCoin(betValue),
                new Promise((resolve) => window.setTimeout(resolve, SPIN_DURATION_MS)),
            ]);

            setReels(Array.isArray(result?.reels) && result.reels.length === 3 ? result.reels : ['?', '?', '?']);
            setBalance(result.balance_after);
            setLoadError('');

            const multiplier = toNumber(result.multiplier);
            if (multiplier >= 2) {
                setLastMessage(`JACKPOT! 777 kena x${result.multiplier}.`);
            } else if (multiplier >= 1.5) {
                setLastMessage(`Menang! 3 simbol sama kena x${result.multiplier}.`);
            } else if (multiplier >= 0.25) {
                setLastMessage(`Lumayan! 2 simbol sama kena x${result.multiplier}.`);
            } else {
                setLastMessage('Zonk. Tidak ada payout di spin ini.');
            }

            setHistory((current) => [
                {
                    id: result.id,
                    bet: result.bet,
                    reels: result.reels,
                    multiplier: result.multiplier,
                    payout: result.payout,
                    balance_before: result.balance_before,
                    balance_after: result.balance_after,
                    is_jackpot: result.is_jackpot,
                    created_at: new Date().toISOString(),
                },
                ...current,
            ].slice(0, 20));
        } catch (error: any) {
            const status = Number(error?.response?.status || 0);
            const payload = error?.response?.data;
            const detail =
                payload?.error ||
                payload?.message ||
                payload?.errors?.[0]?.detail ||
                payload?.errors?.[0]?.title ||
                null;
            const message =
                status === 429 ? 'Terlalu cepat spin. Tunggu sebentar lalu coba lagi.' : detail || 'Spin gagal. Coba lagi.';
            setLastMessage(String(message));
            try {
                await load();
            } catch {
                // keep current UI state if refresh fails
            }
        } finally {
            if (spinFrame !== null) {
                window.clearInterval(spinFrame);
            }
            setSpinning(false);
            spinLockRef.current = false;
        }
    };

    return (
        <PageContentBlock title={'DanexCoin Slots'} showFlashKey={'dashboard'}>
            <div css={tw`mx-auto w-full max-w-5xl space-y-4`}>
                <Panel css={tw`bg-gradient-to-r from-neutral-900 via-neutral-800 to-cyan-900/40`}>
                    <div css={tw`flex flex-wrap items-center justify-between gap-3`}>
                        <div>
                            <p css={tw`text-xs uppercase tracking-wider text-neutral-400`}>Wallet</p>
                            <p css={tw`text-lg font-semibold text-neutral-100`}>DanexCoin</p>
                        </div>
                        <div css={tw`text-right`}>
                            <p css={tw`text-xs uppercase tracking-wider text-neutral-400`}>Balance</p>
                            <p css={tw`text-2xl font-bold text-cyan-300`}>{balance}</p>
                        </div>
                    </div>
                    {loadError && <p css={tw`mt-3 text-xs text-red-300`}>{loadError}</p>}
                </Panel>

                <Panel>
                    <div css={tw`grid grid-cols-3 gap-2 sm:gap-3`}>
                        <ReelWrap>
                            <Reel>{renderSymbol(reels[0])}</Reel>
                        </ReelWrap>
                        <ReelWrap>
                            <Reel>{renderSymbol(reels[1])}</Reel>
                        </ReelWrap>
                        <ReelWrap>
                            <Reel>{renderSymbol(reels[2])}</Reel>
                        </ReelWrap>
                    </div>

                    <div css={tw`mt-4 grid grid-cols-1 sm:grid-cols-[auto_1fr_auto] items-center gap-2`}>
                        <label htmlFor={'bet'} css={tw`text-sm text-neutral-300`}>
                            Bet
                        </label>
                        <input
                            id={'bet'}
                            type={'number'}
                            min={'1'}
                            step={'0.01'}
                            value={bet}
                            onChange={(e) => setBet(e.currentTarget.value)}
                            css={tw`w-full bg-neutral-900 border border-neutral-600 rounded-lg px-3 py-2 text-neutral-100`}
                            disabled={spinning || loading}
                        />
                        <Button
                            type={'button'}
                            onClick={onSpin}
                            disabled={spinning || loading}
                            css={tw`w-full sm:w-auto bg-cyan-600 hover:bg-cyan-500 border-cyan-500 text-white font-semibold`}
                        >
                            {spinning ? 'Spinning...' : 'Spin'}
                        </Button>
                    </div>

                    <div css={tw`mt-3 rounded-lg border border-neutral-700 bg-neutral-900/70 px-3 py-2`}>
                        <p css={tw`text-sm text-neutral-200`}>{lastMessage}</p>
                    </div>
                </Panel>

                <Panel>
                    <h2 css={tw`text-sm font-semibold text-neutral-100 mb-2`}>Riwayat Spin</h2>
                    {history.length === 0 ? (
                        <p css={tw`text-sm text-neutral-400`}>Belum ada spin.</p>
                    ) : (
                        <div css={tw`space-y-2`}>
                            {history.map((row) => (
                                <div
                                    key={row.id}
                                    css={tw`rounded-lg border border-neutral-700 bg-neutral-900/60 px-3 py-2 flex items-center justify-between gap-3 flex-wrap`}
                                >
                                    <div css={tw`flex items-center gap-2 text-neutral-100`}>
                                        <code>{reelsForRow(row)[0]}</code>
                                        <code>{reelsForRow(row)[1]}</code>
                                        <code>{reelsForRow(row)[2]}</code>
                                        {row.is_jackpot && (
                                            <span css={tw`text-[10px] px-2 py-0.5 rounded-full bg-green-600/30 text-green-300 border border-green-500/40`}>
                                                JACKPOT
                                            </span>
                                        )}
                                    </div>
                                    <div css={tw`text-xs text-neutral-300`}>
                                        Bet {row.bet} | x{row.multiplier} | Payout {row.payout} | Bal {row.balance_after}
                                    </div>
                                </div>
                            ))}
                        </div>
                    )}
                </Panel>
            </div>
        </PageContentBlock>
    );
};
