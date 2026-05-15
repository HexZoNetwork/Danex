import React from 'react';
import tw from 'twin.macro';
import styled from 'styled-components/macro';
import CSSTransition, { CSSTransitionProps } from 'react-transition-group/CSSTransition';

interface Props extends Omit<CSSTransitionProps, 'timeout' | 'classNames'> {
    timeout: number;
}

const Container = styled.div<{ timeout: number }>`
    .fade-enter,
    .fade-exit,
    .fade-appear {
        will-change: opacity, transform, filter;
    }

    .fade-enter,
    .fade-appear {
        opacity: 0;
        transform: translateY(10px) scale(0.992);
        filter: blur(4px);

        &.fade-enter-active,
        &.fade-appear-active {
            opacity: 1;
            transform: translateY(0) scale(1);
            filter: blur(0);
            transition: opacity ${(props) => props.timeout}ms ease, transform ${(props) => props.timeout}ms ease, filter ${(props) => props.timeout}ms ease;
            transition-duration: ${(props) => props.timeout}ms;
        }
    }

    .fade-exit {
        ${tw`opacity-100`};
        transform: translateY(0) scale(1);
        filter: blur(0);

        &.fade-exit-active {
            opacity: 0;
            transform: translateY(8px) scale(0.994);
            filter: blur(2px);
            transition: opacity ${(props) => props.timeout}ms ease, transform ${(props) => props.timeout}ms ease, filter ${(props) => props.timeout}ms ease;
        }
    }
`;

const Fade: React.FC<Props> = ({ timeout, children, ...props }) => (
    <Container timeout={timeout}>
        <CSSTransition timeout={timeout} classNames={'fade'} {...props}>
            {children}
        </CSSTransition>
    </Container>
);
Fade.displayName = 'Fade';

export default Fade;
