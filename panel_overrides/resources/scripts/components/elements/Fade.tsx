import React from 'react';
import tw from 'twin.macro';
import styled from 'styled-components/macro';
import CSSTransition, { CSSTransitionProps } from 'react-transition-group/CSSTransition';

export type FadeVariant = 'default' | 'dashboard' | 'server' | 'auth' | 'none';

interface Props extends Omit<CSSTransitionProps, 'timeout' | 'classNames'> {
    timeout: number;
    variant?: FadeVariant;
}

const variantDepth = (variant: FadeVariant) => {
    switch (variant) {
        case 'server':
            return {
                enter: 'translate3d(20px, 18px, -34px) rotateX(1.6deg) rotateY(-1.2deg) scale(0.985)',
                exit: 'translate3d(-12px, -8px, -24px) rotateX(-0.9deg) rotateY(0.8deg) scale(0.99)',
            };
        case 'dashboard':
            return {
                enter: 'translate3d(14px, 16px, -24px) rotateX(1deg) rotateY(-0.8deg) scale(0.988)',
                exit: 'translate3d(-8px, -6px, -18px) rotateX(-0.6deg) rotateY(0.5deg) scale(0.992)',
            };
        case 'auth':
            return {
                enter: 'translate3d(0, 14px, -18px) rotateX(0.75deg) scale(0.99)',
                exit: 'translate3d(0, -6px, -12px) rotateX(-0.45deg) scale(0.994)',
            };
        case 'none':
            return { enter: 'none', exit: 'none' };
        default:
            return {
                enter: 'translate3d(0, 10px, -16px) scale(0.992)',
                exit: 'translate3d(0, 7px, -12px) scale(0.994)',
            };
    }
};

const Container = styled.div<{ timeout: number; variant: FadeVariant }>`
    perspective: var(--el7-perspective);
    transform-style: preserve-3d;

    .fade-enter,
    .fade-exit,
    .fade-appear {
        will-change: opacity, transform;
        transform-style: preserve-3d;
        backface-visibility: hidden;
    }

    .fade-enter,
    .fade-appear {
        opacity: 0;
        transform: ${({ variant }) => variantDepth(variant).enter};

        &.fade-enter-active,
        &.fade-appear-active {
            opacity: 1;
            transform: translate3d(0, 0, 0) rotateX(0) rotateY(0) scale(1);
            transition: opacity ${(props) => props.timeout}ms var(--el7-ease-out), transform ${(props) => props.timeout}ms var(--el7-ease-out);
        }
    }

    .fade-exit {
        ${tw`opacity-100`};
        transform: translate3d(0, 0, 0) rotateX(0) rotateY(0) scale(1);

        &.fade-exit-active {
            opacity: 0;
            transform: ${({ variant }) => variantDepth(variant).exit};
            transition: opacity ${(props) => Math.max(120, props.timeout - 90)}ms var(--el7-ease), transform ${(props) => Math.max(120, props.timeout - 90)}ms var(--el7-ease);
        }
    }

    @media (prefers-reduced-motion: reduce) {
        .fade-enter,
        .fade-appear,
        .fade-exit,
        .fade-enter-active,
        .fade-appear-active,
        .fade-exit-active {
            transform: none !important;
            transition-property: opacity !important;
            transition-duration: 80ms !important;
        }
    }
`;

const Fade: React.FC<Props> = ({ timeout, variant = 'default', children, ...props }) => (
    <Container timeout={timeout} variant={variant}>
        <CSSTransition timeout={timeout} classNames={'fade'} {...props}>
            {children}
        </CSSTransition>
    </Container>
);
Fade.displayName = 'Fade';

export default Fade;
