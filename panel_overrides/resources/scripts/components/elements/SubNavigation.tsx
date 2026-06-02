import React from 'react';
import tw from 'twin.macro';
import styled from 'styled-components/macro';

const Wrapper = styled.div`
    ${tw`w-full overflow-x-auto px-2 sm:px-4 py-2`};
    position: relative;
    background: linear-gradient(180deg, rgba(9, 9, 13, 0.94), rgba(7, 7, 11, 0.86));
    border-top: 1px solid rgba(34, 211, 238, 0.1);
    border-bottom: 1px solid rgba(139, 92, 246, 0.24);
    box-shadow: 0 12px 28px rgba(0, 0, 0, 0.36), inset 0 1px 0 rgba(255,255,255,0.035);
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
        background: linear-gradient(145deg, rgba(17, 17, 24, 0.94), rgba(8, 8, 13, 0.98));
        border-color: rgba(139, 92, 246, 0.18);
        box-shadow: inset 0 1px 0 rgba(255, 255, 255, 0.035);
        transform: translateZ(0);
        transition: transform 180ms var(--el7-ease-out), color 180ms var(--el7-ease), border-color 180ms var(--el7-ease), box-shadow 180ms var(--el7-ease), background 180ms var(--el7-ease);
    }

    a:hover {
        color: #f8fbff;
        background: #111117;
        border-color: rgba(34, 211, 238, 0.34);
        box-shadow: 0 0 18px rgba(34, 211, 238, 0.11);
        transform: translate3d(0, -1px, 12px);
    }

    a.active {
        color: #ffffff;
        background: linear-gradient(145deg, rgba(139, 92, 246, 0.22), rgba(34, 211, 238, 0.08));
        border-color: rgba(34, 211, 238, 0.46);
        box-shadow: inset 0 -2px 0 rgba(34, 211, 238, 0.72), 0 0 22px rgba(139, 92, 246, 0.18);
        text-shadow: 0 0 18px rgba(34, 211, 238, 0.34);
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
