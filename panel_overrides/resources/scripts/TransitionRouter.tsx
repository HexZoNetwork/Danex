import React from 'react';
import { Route } from 'react-router';
import { SwitchTransition } from 'react-transition-group';
import Fade, { FadeVariant } from '@/components/elements/Fade';
import styled from 'styled-components/macro';
import tw from 'twin.macro';

const StyledSwitchTransition = styled(SwitchTransition)<{ $variant: FadeVariant }>`
    ${tw`relative`};
    perspective: var(--el7-perspective);

    & section {
        ${tw`absolute w-full top-0 left-0`};
        transform-style: preserve-3d;
    }

    ${({ $variant }) =>
        $variant !== 'none' &&
        `
        & section > *:first-child {
            animation: danex-deck-arrive 380ms var(--el7-ease-out) both;
        }
    `}
`;

type Props = {
    variant?: FadeVariant;
    className?: string;
    includeSearch?: boolean;
};

const TransitionRouter: React.FC<Props> = ({ children, variant = 'default', className, includeSearch = true }) => {
    return (
        <Route
            render={({ location }) => (
                <StyledSwitchTransition $variant={variant} className={className}>
                    <Fade timeout={340} key={location.pathname + (includeSearch ? location.search : '')} variant={variant} in appear unmountOnExit>
                        <section>{children}</section>
                    </Fade>
                </StyledSwitchTransition>
            )}
        />
    );
};

export default TransitionRouter;
