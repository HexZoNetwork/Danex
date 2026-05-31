import React from 'react';
import tw from 'twin.macro';
import styled from 'styled-components/macro';

const Wrapper = styled.div`
    ${tw`w-full overflow-x-auto px-2 sm:px-4 py-2`};
    background: rgba(7, 7, 11, 0.86);
    border-top: 1px solid rgba(139, 92, 246, 0.16);
    border-bottom: 1px solid rgba(139, 92, 246, 0.22);
    box-shadow: 0 12px 28px rgba(0, 0, 0, 0.36);
    backdrop-filter: blur(12px);
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
        ${tw`text-[12px] sm:text-sm text-neutral-300 no-underline px-3 sm:px-4 py-2 whitespace-nowrap border transition-colors duration-150 uppercase rounded-md`};
        position: relative;
        letter-spacing: 0.03em;
        background: #0b0b10;
        border-color: rgba(139, 92, 246, 0.18);
        box-shadow: inset 0 1px 0 rgba(255, 255, 255, 0.035);
    }

    a:hover {
        color: #f5edff;
        background: #111117;
        border-color: rgba(139, 92, 246, 0.42);
        box-shadow: 0 0 18px rgba(139, 92, 246, 0.12);
    }

    a.active {
        color: #ffffff;
        background: rgba(139, 92, 246, 0.14);
        border-color: rgba(139, 92, 246, 0.62);
        text-shadow: 0 0 18px rgba(139, 92, 246, 0.45);
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
