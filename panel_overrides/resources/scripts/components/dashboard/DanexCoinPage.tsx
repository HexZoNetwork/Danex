import React, { useEffect, useRef, useState } from 'react';
import tw from 'twin.macro';
import styled from 'styled-components/macro';
import PageContentBlock from '@/components/elements/PageContentBlock';
import { Button } from '@/components/elements/button/index';
import { DanexCoinSpinLog, getDanexCoinState, spinDanexCoin } from '@/api/danexcoin';
import sevenImage from '@/assets/danexcoin/seven.svg';
import barImage from '@/assets/danexcoin/bar.svg';
import cherryImage from '@/assets/danexcoin/cherry.svg';
import diamondImage from '@/assets/danexcoin/diamond.svg';
import bellImage from '@/assets/danexcoin/bell.svg';
import starImage from '@/assets/danexcoin/star.svg';

const Panel = styled.div`
    ${tw`rounded-xl border p-3 sm:p-4 shadow-lg`};
    background: #0b0b10;
    border-color: rgba(139, 92, 246, 0.24);
    box-shadow: 0 18px 48px rgba(0, 0, 0, 0.5);
    transition: transform 220ms cubic-bezier(0.4, 0, 0.2, 1), border-color 220ms cubic-bezier(0.4, 0, 0.2, 1), box-shadow 220ms cubic-bezier(0.4, 0, 0.2, 1);

    &:hover {
        transform: translateY(-2px);
        border-color: rgba(139, 92, 246, 0.42);
        box-shadow: 0 22px 54px rgba(0, 0, 0, 0.55), 0 0 24px rgba(139, 92, 246, 0.12);
    }
`;
const CasinoPanel = styled(Panel)`
    background:
        radial-gradient(circle at 20% 0%, rgba(234, 179, 8, 0.12), transparent 22rem),
        linear-gradient(145deg, rgba(127, 29, 29, 0.2), rgba(11, 11, 16, 0.98) 42%),
        #0b0b10;
    border-color: rgba(234, 179, 8, 0.28);
`;
const ReelWrap = styled.div`
    ${tw`rounded-xl border p-1.5 sm:p-3`};
    background: #111117;
    border-color: rgba(139, 92, 246, 0.3);
`;
const Reel = styled.div`
    ${tw`rounded-lg border py-3 sm:py-4 text-center shadow-inner min-h-[88px] sm:min-h-[116px] flex items-center justify-center`};
    background: #07070b;
    border-color: rgba(139, 92, 246, 0.24);
    box-shadow: inset 0 0 28px rgba(0, 0, 0, 0.5);
`;
const ReelImage = styled.img<{ $spinning?: boolean }>`
    ${tw`w-12 h-12 sm:w-20 sm:h-20 object-contain`};
    animation: ${({ $spinning }) => ($spinning ? 'danex-slot-pulse 520ms ease-in-out infinite' : 'none')};
`;
const ReelFallback = styled.div<{ $spinning?: boolean }>`
    ${tw`text-lg text-purple-300 font-bold`};
    animation: ${({ $spinning }) => ($spinning ? 'danex-slot-pulse 520ms ease-in-out infinite' : 'none')};
`;

const HistoryRow = styled.div`
    ${tw`rounded-lg border px-3 py-2 grid gap-2 sm:flex sm:items-center sm:justify-between sm:gap-3 sm:flex-wrap`};
    background: #111117;
    border-color: rgba(139, 92, 246, 0.18);
    transition: border-color 180ms cubic-bezier(0.4, 0, 0.2, 1), box-shadow 180ms cubic-bezier(0.4, 0, 0.2, 1);

    &:hover {
        border-color: rgba(139, 92, 246, 0.48);
        box-shadow: inset 2px 0 0 rgba(139, 92, 246, 0.7);
    }
`;

const MessageBox = styled.div`
    ${tw`mt-3 rounded-lg border px-3 py-2`};
    background: #111117;
    border-color: rgba(139, 92, 246, 0.2);
`;
const StatTile = styled.div`
    ${tw`rounded-lg border px-3 py-2`};
    background: rgba(17, 17, 23, 0.78);
    border-color: rgba(234, 179, 8, 0.18);
`;
const QuickBet = styled.button<{ $active?: boolean }>`
    ${tw`rounded-lg border px-3 py-2 text-xs font-semibold transition-colors`};
    min-width: 4.5rem;
    background: ${({ $active }) => ($active ? 'rgba(234, 179, 8, 0.18)' : '#111117')};
    border-color: ${({ $active }) => ($active ? 'rgba(234, 179, 8, 0.58)' : 'rgba(139, 92, 246, 0.24)')};
    color: ${({ $active }) => ($active ? '#fde68a' : '#d8d8e8')};
`;
const JackpotStrip = styled.div`
    ${tw`mt-3 rounded-lg border px-3 py-2 flex flex-wrap items-center justify-between gap-2`};
    background: linear-gradient(90deg, rgba(127, 29, 29, 0.24), rgba(234, 179, 8, 0.08), rgba(17, 17, 23, 0.88));
    border-color: rgba(234, 179, 8, 0.24);
`;

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
    const [enabled, setEnabled] = useState(true);
    const [settings, setSettings] = useState({
        min_bet: '1.00',
        max_bet: '100000000.00',
        default_bet: '10.00',
        spin_cooldown_seconds: 4,
        house_edge_label: 'volatile',
    });
    const [summary, setSummary] = useState({
        total_spins: 0,
        wins: 0,
        jackpots: 0,
        biggest_payout: '0.00',
        hotness: 0,
        streak: 0,
    });
    const [history, setHistory] = useState<DanexCoinSpinLog[]>([]);
    const [loadError, setLoadError] = useState('');
    const [reels, setReels] = useState<[SymbolKey | '?', SymbolKey | '?', SymbolKey | '?']>(['?', '?', '?']);
    const [lastMessage, setLastMessage] = useState('Set amount, then run the reward cycle.');

    const load = async () => {
        const state = await getDanexCoinState();
        setBalance(String(state?.balance || '0.00'));
        setEnabled(Boolean(state?.enabled ?? true));
        if (state?.settings) {
            setSettings(state.settings);
            setBet((current) => current || String(state.settings.default_bet || '10.00'));
        }
        if (state?.summary) {
            setSummary(state.summary);
        }
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
            return <ReelImage src={image} alt={value} $spinning={spinning} />;
        }

        return <ReelFallback $spinning={spinning}>{value}</ReelFallback>;
    };
    const setBalanceBet = (fraction: number) => {
        const maxBet = toNumber(settings.max_bet);
        const next = Math.max(toNumber(settings.min_bet), Math.min(maxBet, toNumber(balance) * fraction));
        setBet(next.toFixed(2));
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
            setLastMessage('Amount must be a number greater than 0.');
            return;
        }
        if (!enabled) {
            setLastMessage('DanexCoin table is closed by admin.');
            return;
        }

        spinLockRef.current = true;
        setSpinning(true);
        setLastMessage('Reward cycle running...');
        let spinFrame: number | null = null;
        try {
            spinFrame = window.setInterval(() => {
                setReels([randomSymbol(), randomSymbol(), randomSymbol()]);
            }, 110);

            const [result] = await Promise.all([
                spinDanexCoin(betValue),
                new Promise((resolve) => window.setTimeout(resolve, SPIN_DURATION_MS)),
            ]);

            setReels(Array.isArray(result?.reels) && result.reels.length === 3 ? result.reels as typeof reels : ['?', '?', '?']);
            setBalance(result.balance_after);
            setLoadError('');

            const multiplier = toNumber(result.multiplier);
            if (multiplier >= 2) {
                setLastMessage(`JACKPOT line hit x${result.multiplier}. Cashout ${result.payout}.`);
            } else if (multiplier >= 1.5) {
                setLastMessage(`Triple line hit x${result.multiplier}. Cashout ${result.payout}.`);
            } else if (multiplier >= 0.25) {
                setLastMessage(`Pair saved the round x${result.multiplier}. Cashout ${result.payout}.`);
            } else {
                setLastMessage('Zonk. House takes this round.');
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
            setSummary((current) => ({
                ...current,
                total_spins: current.total_spins + 1,
                wins: current.wins + (multiplier > 0 ? 1 : 0),
                jackpots: current.jackpots + (result.is_jackpot ? 1 : 0),
                biggest_payout: String(Math.max(toNumber(current.biggest_payout), toNumber(result.payout)).toFixed(2)),
                hotness: Math.min(100, Math.round(((current.wins + (multiplier > 0 ? 1 : 0)) / Math.max(1, current.total_spins + 1)) * 100)),
                streak: multiplier > 0 ? current.streak + 1 : 0,
            }));
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
                status === 429 ? 'Too many reward runs. Wait a moment and try again.' : detail || 'Reward run failed. Try again.';
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
        <PageContentBlock title={'DanexCoin Arcade'} showFlashKey={'dashboard'}>
            <div css={tw`mx-auto w-full max-w-5xl space-y-4`}>
                <CasinoPanel>
                    <div css={tw`flex flex-wrap items-center justify-between gap-3`}>
                        <div>
                            <p css={tw`text-xs uppercase tracking-wider text-yellow-300`}>DanexCoin Casino</p>
                            <p css={tw`text-lg font-semibold text-neutral-100`}>High volatility slot table</p>
                            <p css={tw`text-xs text-neutral-400 mt-1`}>Mode {settings.house_edge_label} | fast spin table | private odds</p>
                        </div>
                        <div css={tw`text-right`}>
                            <p css={tw`text-xs uppercase tracking-wider text-neutral-400`}>Balance</p>
                            <p css={tw`text-2xl font-bold text-yellow-300`}>{balance}</p>
                        </div>
                    </div>
                    <div css={tw`mt-4 grid grid-cols-2 md:grid-cols-4 gap-2`}>
                        <StatTile>
                            <p css={tw`text-[10px] uppercase tracking-wider text-neutral-500`}>Hotness</p>
                            <p css={tw`text-lg font-bold text-red-300`}>{summary.hotness}%</p>
                        </StatTile>
                        <StatTile>
                            <p css={tw`text-[10px] uppercase tracking-wider text-neutral-500`}>Win Streak</p>
                            <p css={tw`text-lg font-bold text-green-300`}>{summary.streak}</p>
                        </StatTile>
                        <StatTile>
                            <p css={tw`text-[10px] uppercase tracking-wider text-neutral-500`}>Jackpots</p>
                            <p css={tw`text-lg font-bold text-yellow-300`}>{summary.jackpots}</p>
                        </StatTile>
                        <StatTile>
                            <p css={tw`text-[10px] uppercase tracking-wider text-neutral-500`}>Best Cashout</p>
                            <p css={tw`text-lg font-bold text-purple-300`}>{summary.biggest_payout}</p>
                        </StatTile>
                    </div>
                    <JackpotStrip>
                        <div>
                            <p css={tw`text-[10px] uppercase tracking-wider text-yellow-300`}>Table Signal</p>
                            <p css={tw`text-sm text-neutral-100`}>{summary.hotness >= 50 ? 'Hot table, cashouts are moving.' : 'Cold table, play tight.'}</p>
                        </div>
                        <div css={tw`text-right`}>
                            <p css={tw`text-[10px] uppercase tracking-wider text-neutral-500`}>Session Spins</p>
                            <p css={tw`text-sm font-semibold text-neutral-100`}>{summary.total_spins}</p>
                        </div>
                    </JackpotStrip>
                    {loadError && <p css={tw`mt-3 text-xs text-red-300`}>{loadError}</p>}
                    {!enabled && <p css={tw`mt-3 text-xs text-red-300`}>Table closed by admin.</p>}
                </CasinoPanel>

                <Panel>
                    <style>{`
                        @keyframes danex-slot-pulse {
                            0%, 100% { transform: translateY(0) scale(1); filter: drop-shadow(0 0 0 rgba(139, 92, 246, 0)); }
                            50% { transform: translateY(-3px) scale(1.04); filter: drop-shadow(0 0 16px rgba(139, 92, 246, 0.35)); }
                        }
                    `}</style>
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

                    <div css={tw`mt-4 flex gap-2 overflow-x-auto pb-1 sm:grid sm:grid-cols-6 sm:overflow-visible sm:pb-0`}>
                        {[settings.default_bet, '25', '100', '1000'].map((value) => (
                            <QuickBet key={value} type={'button'} $active={bet === value} onClick={() => setBet(value)} disabled={spinning || loading}>
                                {value}
                            </QuickBet>
                        ))}
                        <QuickBet type={'button'} onClick={() => setBalanceBet(0.5)} disabled={spinning || loading}>Half</QuickBet>
                        <QuickBet type={'button'} onClick={() => setBalanceBet(1)} disabled={spinning || loading}>Max</QuickBet>
                    </div>

                    <div css={tw`mt-3 grid grid-cols-1 sm:grid-cols-[auto_1fr_auto] items-center gap-2`}>
                        <label htmlFor={'bet'} css={tw`text-sm text-neutral-300`}>
                            Bet
                        </label>
                        <input
                            id={'bet'}
                            type={'number'}
                            min={'1'}
                            max={settings.max_bet}
                            step={'0.01'}
                            value={bet}
                            onChange={(e) => setBet(e.currentTarget.value)}
                            css={tw`w-full bg-neutral-900 border border-neutral-600 rounded-lg px-3 py-2 text-neutral-100`}
                            disabled={spinning || loading || !enabled}
                        />
                        <Button
                            type={'button'}
                            onClick={onSpin}
                            disabled={spinning || loading || !enabled}
                            css={tw`w-full sm:w-auto font-semibold`}
                        >
                            {spinning ? 'Spinning...' : 'Spin'}
                        </Button>
                    </div>

                    <MessageBox>
                        <p css={tw`text-sm text-neutral-200`}>{lastMessage}</p>
                    </MessageBox>
                </Panel>

                <Panel>
                    <h2 css={tw`text-sm font-semibold text-neutral-100 mb-2`}>Table History</h2>
                    {history.length === 0 ? (
                        <p css={tw`text-sm text-neutral-400`}>No reward run yet.</p>
                    ) : (
                        <div css={tw`space-y-2`}>
                            {history.map((row) => (
                                <HistoryRow key={row.id}>
                                    <div css={tw`flex items-center gap-2 text-neutral-100`}>
                                        <code>{reelsForRow(row)[0]}</code>
                                        <code>{reelsForRow(row)[1]}</code>
                                        <code>{reelsForRow(row)[2]}</code>
                                        {row.is_jackpot && (
                                            <span css={tw`text-[10px] px-2 py-0.5 rounded-full bg-green-600/30 text-green-300 border border-green-500/40`}>
                                                PRIME
                                            </span>
                                        )}
                                    </div>
                                    <div css={tw`grid grid-cols-2 gap-x-3 gap-y-1 text-xs text-neutral-300 tabular-nums sm:block`}>
                                        <span>Bet {row.bet}</span>
                                        <span>x{row.multiplier}</span>
                                        <span>Cashout {row.payout}</span>
                                        <span>Bal {row.balance_after}</span>
                                    </div>
                                </HistoryRow>
                            ))}
                        </div>
                    )}
                </Panel>
            </div>
        </PageContentBlock>
    );
};
