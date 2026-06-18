import React from 'react';
import tw from 'twin.macro';
import styled from 'styled-components/macro';
import PageContentBlock from '@/components/elements/PageContentBlock';

const games = [
    {
        emoji: '🃏',
        name: 'Danex UNO',
        source: '/minigames/uno/',
        description: 'Multiplayer card game. Create a room, share the code with friends, and play from the browser.',
        status: 'Playable now',
        mode: 'Hosted PHP mini-app',
    },
];

const Panel = styled.div`
    ${tw`rounded-xl border p-4 sm:p-5 shadow-lg`};
    background: #0b0b10;
    border-color: rgba(139, 92, 246, 0.24);
    box-shadow: 0 18px 48px rgba(0, 0, 0, 0.5);
`;

const GameCard = styled.a`
    ${tw`relative block overflow-hidden rounded-xl border p-4 text-neutral-200 no-underline shadow-lg transition-all duration-200 focus:outline-none`};
    background: #111117;
    border-color: rgba(139, 92, 246, 0.26);

    &:hover,
    &:focus-visible {
        transform: translateY(-2px);
        border-color: rgba(139, 92, 246, 0.5);
        box-shadow: 0 18px 44px rgba(0, 0, 0, 0.5);
        color: #fff;
    }
`;

const ColorStrip = styled.div`
    ${tw`absolute inset-x-0 top-0 grid grid-cols-4 h-1.5`};

    span:nth-child(1) { background: #ef4444; }
    span:nth-child(2) { background: #facc15; }
    span:nth-child(3) { background: #22c55e; }
    span:nth-child(4) { background: #8b5cf6; }
`;

const GameIcon = styled.div`
    ${tw`flex h-20 w-20 items-center justify-center rounded-2xl border text-4xl shadow-inner`};
    background: #111117;
    border-color: rgba(234, 179, 8, 0.32);
`;

const CardPill = styled.span<{ $color: 'red' | 'yellow' | 'green' | 'blue' }>`
    ${tw`rounded-md border px-2 py-1 text-[10px] font-bold uppercase tracking-wider`};
    background: ${({ $color }) => ({
        red: 'rgba(239, 68, 68, 0.14)',
        yellow: 'rgba(250, 204, 21, 0.14)',
        green: 'rgba(34, 197, 94, 0.14)',
        blue: 'rgba(139, 92, 246, 0.14)',
    }[$color])};
    border-color: ${({ $color }) => ({
        red: 'rgba(239, 68, 68, 0.38)',
        yellow: 'rgba(250, 204, 21, 0.38)',
        green: 'rgba(34, 197, 94, 0.38)',
        blue: 'rgba(139, 92, 246, 0.38)',
    }[$color])};
    color: ${({ $color }) => ({
        red: '#fca5a5',
        yellow: '#fde68a',
        green: '#86efac',
        blue: '#c4b5fd',
    }[$color])};
`;

export default () => (
    <PageContentBlock title={'Mini Games'} showFlashKey={'dashboard'}>
        <div css={tw`mx-auto w-full max-w-5xl space-y-4`}>
            <Panel>
                <p className={'el7-kicker'}>Game Hub</p>
                <h2 css={tw`mt-1 text-xl font-semibold text-neutral-100`}>Mini Games</h2>
                <p className={'el7-helper'} css={tw`mt-2`}>
                    Small games live here. UNO is the first card, ready as a source link now and prepared for a future hosted launch.
                </p>
            </Panel>

            <div css={tw`grid grid-cols-1 gap-4 md:grid-cols-2 xl:grid-cols-3`}>
                {games.map((game) => (
                    <GameCard key={game.name} href={game.source} target={'_blank'} rel={'noreferrer noopener'}>
                        <ColorStrip aria-hidden={'true'}>
                            <span />
                            <span />
                            <span />
                            <span />
                        </ColorStrip>
                        <div css={tw`flex flex-col gap-4 pt-2`}>
                            <div css={tw`flex items-start gap-4`}>
                                <GameIcon aria-hidden={'true'}>{game.emoji}</GameIcon>
                                <div css={tw`min-w-0 flex-1`}>
                                    <p css={tw`text-xs uppercase tracking-wider text-yellow-300`}>First Game</p>
                                    <h3 css={tw`mt-1 text-lg font-bold text-neutral-100`}>{game.emoji} {game.name}</h3>
                                    <p css={tw`mt-1 text-sm text-neutral-400`}>{game.description}</p>
                                </div>
                            </div>

                            <div css={tw`flex flex-wrap gap-2`} aria-label={'UNO-style cards'}>
                                <CardPill $color={'red'}>Skip</CardPill>
                                <CardPill $color={'yellow'}>Reverse</CardPill>
                                <CardPill $color={'green'}>Draw 2</CardPill>
                                <CardPill $color={'blue'}>Wild</CardPill>
                            </div>

                            <div css={tw`rounded-lg border px-3 py-2 text-xs text-neutral-300`} style={{ background: '#111117', borderColor: 'rgba(139, 92, 246, 0.18)' }}>
                                <p><strong css={tw`text-neutral-100`}>Status:</strong> {game.status}</p>
                                <p css={tw`mt-1`}><strong css={tw`text-neutral-100`}>Mode:</strong> {game.mode}</p>
                            </div>

                            <span css={tw`inline-flex w-full items-center justify-center rounded-lg border px-3 py-2 text-sm font-semibold text-yellow-200 transition-colors`} style={{ background: 'rgba(234, 179, 8, 0.12)', borderColor: 'rgba(234, 179, 8, 0.32)' }}>
                                Play UNO →
                            </span>
                        </div>
                    </GameCard>
                ))}
            </div>
        </div>
    </PageContentBlock>
);
