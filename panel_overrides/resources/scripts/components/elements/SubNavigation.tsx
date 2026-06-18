import React from 'react';
import tw from 'twin.macro';
import styled from 'styled-components/macro';

const Wrapper = styled.div`
    ${tw`w-full overflow-x-auto px-2 sm:px-4 py-2`};
    position: relative;
    background: #09090d;
    border-top: 1px solid rgba(139, 92, 246, 0.14);
    border-bottom: 1px solid rgba(139, 92, 246, 0.24);
    box-shadow: 0 12px 28px rgba(0, 0, 0, 0.32);
    backdrop-filter: blur(14px);
    -webkit-overflow-scrolling: touch;
    scrollbar-width: none;

    &::-webkit-scrollbar {
        display: none;
    }
`;

const Inner = styled.div`
    ${tw`mx-auto w-full max-w-[1200px]`};

    > div {
        ${tw`inline-flex min-w-full items-center gap-2`};
    }

    a {
        ${tw`text-[12px] sm:text-sm text-neutral-300 no-underline px-3 sm:px-4 py-2 whitespace-nowrap border uppercase rounded-md`};
        position: relative;
        letter-spacing: 0.05em;
        background: #111117;
        border-color: rgba(139, 92, 246, 0.18);
        box-shadow: none;
        transform: translateZ(0);
        transition: transform 180ms var(--el7-ease-out), color 180ms var(--el7-ease), border-color 180ms var(--el7-ease), box-shadow 180ms var(--el7-ease), background 180ms var(--el7-ease);
    }

    a:hover {
        color: #f8fbff;
        background: #111117;
        border-color: rgba(139, 92, 246, 0.42);
        box-shadow: 0 8px 20px rgba(76, 29, 149, 0.16);
        transform: translateY(-1px);
    }

    a.active {
        color: #ffffff;
        background: rgba(139, 92, 246, 0.24);
        border-color: rgba(139, 92, 246, 0.54);
        box-shadow: inset 0 -2px 0 rgba(139, 92, 246, 0.72);
        text-shadow: none;
    }

    @media (max-width: 640px) {
        > div {
            ${tw`gap-1.5`};
        }

        a {
            ${tw`px-3 py-2 text-xs`};
            min-height: 2.5rem;
            display: inline-flex;
            align-items: center;
            border-radius: 7px;
            letter-spacing: 0.025em;
        }
    }
`;

type Props = {
    children: React.ReactNode;
};

export default ({ children }: Props) => (
    <Wrapper>
        <Inner>{children}</Inner>
    </Wrapper>
);
