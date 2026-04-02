import React from 'react';
import tw, { theme } from 'twin.macro';
import styled from 'styled-components/macro';

const Wrapper = styled.div`
    ${tw`w-full bg-neutral-700 border-t border-neutral-600 overflow-x-auto`};
    -webkit-overflow-scrolling: touch;
    scrollbar-width: none;

    &::-webkit-scrollbar {
        display: none;
    }
`;

const Inner = styled.div`
    ${tw`mx-auto w-full max-w-[1200px]`};

    > div {
        ${tw`inline-flex min-w-full items-stretch px-2`};
    }

    a {
        ${tw`text-[13px] sm:text-sm text-neutral-300 no-underline px-3 sm:px-4 py-2.5 sm:py-3 whitespace-nowrap border-b-2 border-transparent transition-colors duration-150`};
    }

    a:hover {
        ${tw`text-neutral-100`};
    }

    a.active {
        ${tw`text-neutral-100`};
        border-bottom-color: ${theme`colors.cyan.500`};
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
