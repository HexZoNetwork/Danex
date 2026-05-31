import React, { Suspense } from 'react';
import styled, { css, keyframes } from 'styled-components/macro';
import tw from 'twin.macro';
import ErrorBoundary from '@/components/elements/ErrorBoundary';

export type SpinnerSize = 'small' | 'base' | 'large';

interface Props {
    size?: SpinnerSize;
    centered?: boolean;
    isBlue?: boolean;
}

interface Spinner extends React.FC<Props> {
    Size: Record<'SMALL' | 'BASE' | 'LARGE', SpinnerSize>;
    Suspense: React.FC<Props>;
}

const spin = keyframes`
    from { transform: rotate(0deg); }
    to { transform: rotate(360deg); }
`;

const SpinnerComponent = styled.div.attrs({ 'data-el7-spinner': 'true' })<Props>`
    ${tw`relative w-8 h-8 flex-shrink-0`};
    aspect-ratio: 1 / 1;
    box-sizing: border-box;
    border-width: 3px;
    border-style: solid;
    border-radius: 9999px;
    animation-name: ${spin} !important;
    animation-duration: 560ms !important;
    animation-timing-function: linear !important;
    animation-iteration-count: infinite !important;
    animation-play-state: running !important;
    transform-origin: 50% 50%;
    will-change: transform;

    ${(props) =>
        props.size === 'small'
            ? tw`w-4 h-4 border-2`
            : props.size === 'large'
            ? css`
                  ${tw`w-14 h-14 sm:w-16 sm:h-16`};
                  border-width: 5px;

                  @media (min-width: 640px) {
                      border-width: 6px;
                  }
              `
            : null};

    border-color: ${(props) => (!props.isBlue ? 'rgba(167, 139, 250, 0.18)' : 'hsla(212, 92%, 43%, 0.2)')};
    border-right-color: ${(props) => (!props.isBlue ? 'rgba(167, 139, 250, 0.54)' : 'hsla(212, 92%, 43%, 0.58)')};
    border-top-color: ${(props) => (!props.isBlue ? 'rgb(255, 255, 255)' : 'hsl(212, 92%, 43%)')};
    filter: drop-shadow(0 0 12px ${(props) => (!props.isBlue ? 'rgba(139, 92, 246, 0.36)' : 'hsla(212, 92%, 43%, 0.36)')});

    &::after {
        content: '';
        position: absolute;
        inset: 22%;
        border-radius: inherit;
        border: 1px solid ${(props) => (!props.isBlue ? 'rgba(167, 139, 250, 0.28)' : 'hsla(212, 92%, 43%, 0.28)')};
    }

    @media (prefers-reduced-motion: reduce) {
        animation-duration: 1.15s !important;
    }
`;

const Spinner: Spinner = ({ centered, ...props }) =>
    centered ? (
        <div css={[tw`flex justify-center items-center`, props.size === 'large' ? tw`my-10 sm:m-20` : tw`m-6`]}>
            <SpinnerComponent {...props} />
        </div>
    ) : (
        <SpinnerComponent {...props} />
    );
Spinner.displayName = 'Spinner';

Spinner.Size = {
    SMALL: 'small',
    BASE: 'base',
    LARGE: 'large',
};

Spinner.Suspense = ({ children, centered = true, size = Spinner.Size.LARGE, ...props }) => (
    <Suspense fallback={<Spinner centered={centered} size={size} {...props} />}>
        <ErrorBoundary>{children}</ErrorBoundary>
    </Suspense>
);
Spinner.Suspense.displayName = 'Spinner.Suspense';

export default Spinner;
