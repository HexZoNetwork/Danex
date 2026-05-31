import React, { useEffect, useMemo, useRef, useState } from 'react';
import Spinner from '@/components/elements/Spinner';
import tw from 'twin.macro';
import styled, { css } from 'styled-components/macro';
import { breakpoint } from '@/theme';
import Fade from '@/components/elements/Fade';
import { createPortal } from 'react-dom';

export interface RequiredModalProps {
    visible: boolean;
    onDismissed: () => void;
    appear?: boolean;
    top?: boolean;
}

export interface ModalProps extends RequiredModalProps {
    dismissable?: boolean;
    closeOnEscape?: boolean;
    closeOnBackground?: boolean;
    showSpinnerOverlay?: boolean;
}

export const ModalMask = styled.div`
    ${tw`fixed z-50 overflow-auto flex w-full inset-0`};
    background: rgba(7, 7, 11, 0.78);
    backdrop-filter: blur(14px);
`;

const ModalContainer = styled.div<{ alignTop?: boolean }>`
    max-width: min(95vw, 44rem);
    max-height: calc(100vh - 2rem);
    ${breakpoint('md')`max-width: min(75vw, 48rem)`};
    ${breakpoint('lg')`max-width: min(50vw, 56rem)`};

    ${tw`relative flex flex-col w-full m-auto`};
    ${(props) =>
        props.alignTop &&
        css`
            margin-top: 1rem;
            ${breakpoint('md')`margin-top: 4rem`};
        `};

    margin-bottom: auto;

    & > .close-icon {
        ${tw`absolute right-0 p-2 text-white cursor-pointer transition-all duration-150 ease-linear`};
        top: -2.5rem;
        opacity: 0.72;
        color: #a78bfa;

        &:hover {
            ${tw`transform rotate-90`};
            opacity: 1;
            filter: drop-shadow(0 0 12px rgba(139, 92, 246, 0.45));
        }

        & > svg {
            ${tw`w-6 h-6`};
        }
    }
`;

const Modal: React.FC<ModalProps> = ({
    visible,
    appear,
    dismissable,
    showSpinnerOverlay,
    top = true,
    closeOnBackground = true,
    closeOnEscape = true,
    onDismissed,
    children,
}) => {
    const [render, setRender] = useState(visible);

    const isDismissable = useMemo(() => {
        return (dismissable ?? true) && !(showSpinnerOverlay || false);
    }, [dismissable, showSpinnerOverlay]);

    useEffect(() => {
        if (!isDismissable || !closeOnEscape) return;

        const handler = (e: KeyboardEvent) => {
            if (e.key === 'Escape') setRender(false);
        };

        window.addEventListener('keydown', handler);
        return () => {
            window.removeEventListener('keydown', handler);
        };
    }, [isDismissable, closeOnEscape, render]);

    useEffect(() => setRender(visible), [visible]);

    return (
        <Fade in={render} timeout={150} appear={appear ?? true} unmountOnExit onExited={() => onDismissed()}>
            <ModalMask
                onClick={(e) => e.stopPropagation()}
                onContextMenu={(e) => e.stopPropagation()}
                onMouseDown={(e) => {
                    if (isDismissable && closeOnBackground) {
                        e.stopPropagation();
                        if (e.target === e.currentTarget) {
                            setRender(false);
                        }
                    }
                }}
            >
                <ModalContainer alignTop={top}>
                    {isDismissable && (
                        <div className={'close-icon'} onClick={() => setRender(false)}>
                            <svg
                                xmlns={'http://www.w3.org/2000/svg'}
                                fill={'none'}
                                viewBox={'0 0 24 24'}
                                stroke={'currentColor'}
                            >
                                <path
                                    strokeLinecap={'round'}
                                    strokeLinejoin={'round'}
                                    strokeWidth={'2'}
                                    d={'M6 18L18 6M6 6l12 12'}
                                />
                            </svg>
                        </div>
                    )}
                    {showSpinnerOverlay && (
                        <Fade timeout={150} appear in>
                            <div
                                css={tw`absolute inset-0 rounded-lg flex items-center justify-center`}
                                style={{ background: 'rgba(7, 7, 11, 0.78)', backdropFilter: 'blur(10px)', zIndex: 9999 }}
                            >
                                <Spinner />
                            </div>
                        </Fade>
                    )}
                    <div
                        css={tw`p-3 sm:p-5 md:p-6 rounded-xl overflow-y-auto transition-all duration-150 border`}
                        style={{
                            maxHeight: 'calc(100vh - 3rem)',
                            background: 'linear-gradient(135deg, rgba(17, 17, 24, 0.98), rgba(8, 8, 13, 0.99))',
                            borderColor: 'rgba(139, 92, 246, 0.34)',
                            boxShadow:
                                '0 24px 70px rgba(0, 0, 0, 0.66), 0 0 34px rgba(139, 92, 246, 0.16), inset 0 1px 0 rgba(255, 255, 255, 0.06)',
                        }}
                    >
                        {children}
                    </div>
                </ModalContainer>
            </ModalMask>
        </Fade>
    );
};

const PortaledModal: React.FC<ModalProps> = ({ children, ...props }) => {
    const element = useRef(document.getElementById('modal-portal'));

    return createPortal(<Modal {...props}>{children}</Modal>, element.current!);
};

export default PortaledModal;
