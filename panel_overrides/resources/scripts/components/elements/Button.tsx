import React from 'react';
import styled, { css } from 'styled-components/macro';
import tw from 'twin.macro';
import Spinner from '@/components/elements/Spinner';

interface Props {
    isLoading?: boolean;
    size?: 'xsmall' | 'small' | 'large' | 'xlarge';
    color?: 'green' | 'red' | 'yellow' | 'primary' | 'grey';
    isSecondary?: boolean;
}

const ButtonStyle = styled.button<Omit<Props, 'isLoading'>>`
    ${tw`relative inline-flex items-center justify-center rounded uppercase tracking-wide text-sm transition-all duration-150 border`};
    gap: 0.45rem;
    min-height: 38px;
    line-height: 1.15;
    text-align: center;
    vertical-align: middle;
    overflow: hidden;
    background: #8b5cf6;
    border-color: rgba(255, 255, 255, 0.18);
    color: #f9fafb;
    box-shadow: 0 0 24px rgba(139, 92, 246, 0.22), inset 0 1px 0 rgba(255, 255, 255, 0.08);

    &::after {
        content: '';
        position: absolute;
        inset: -1px;
        pointer-events: none;
        background: linear-gradient(105deg, transparent 20%, rgba(255, 255, 255, 0.16), transparent 78%);
        opacity: 0;
        transform: translateX(-130%);
        transition: transform 420ms cubic-bezier(0.4, 0, 0.2, 1), opacity 180ms ease;
    }

    &:hover:not(:disabled) {
        transform: translateY(-1px);
        border-color: rgba(139, 92, 246, 0.7);
        box-shadow: 0 0 22px rgba(139, 92, 246, 0.18);
    }

    &:hover:not(:disabled)::after,
    &:focus:not(:disabled)::after {
        opacity: 1;
        transform: translateX(130%);
    }

    &:active:not(:disabled) {
        transform: translateY(0) scale(0.985);
    }

    ${(props) =>
        props.color === 'green' &&
        css`
            background: rgba(16, 185, 129, 0.14);
            border-color: rgba(16, 185, 129, 0.44);
            color: #bbf7d0;
        `};

    ${(props) =>
        props.color === 'red' &&
        css`
            background: rgba(239, 68, 68, 0.14);
            border-color: rgba(239, 68, 68, 0.44);
            color: #fecaca;
        `};

    ${(props) =>
        props.color === 'yellow' &&
        css`
            background: rgba(245, 158, 11, 0.14);
            border-color: rgba(245, 158, 11, 0.48);
            color: #fde68a;
        `};

    ${(props) =>
        props.color === 'grey' &&
        css`
            background: #111117;
            border-color: rgba(116, 116, 138, 0.38);
            color: #d4d4df;
        `};

    ${(props) => props.size === 'xsmall' && tw`px-2 py-1 text-xs`};
    ${(props) =>
        props.size === 'xsmall' &&
        css`
            min-height: 28px;
        `};
    ${(props) => (!props.size || props.size === 'small') && tw`px-4 py-2`};
    ${(props) => props.size === 'large' && tw`px-5 py-3 text-sm`};
    ${(props) =>
        props.size === 'large' &&
        css`
            min-height: 46px;
        `};
    ${(props) => props.size === 'xlarge' && tw`px-5 py-4 w-full`};
    ${(props) =>
        props.size === 'xlarge' &&
        css`
            min-height: 50px;
        `};

    ${(props) =>
        props.isSecondary &&
        css`
            background: #0b0b10;
            border-color: rgba(139, 92, 246, 0.28);
            color: #d4d4df;
            box-shadow: inset 0 1px 0 rgba(255, 255, 255, 0.04);
        `};

    &:disabled {
        opacity: 0.55;
        cursor: default;
        transform: none;
        box-shadow: none;
    }
`;

type ComponentProps = Omit<JSX.IntrinsicElements['button'], 'ref' | keyof Props> & Props;

const Button: React.FC<ComponentProps> = ({ children, isLoading, ...props }) => (
    <ButtonStyle {...props}>
        {isLoading && (
            <div css={tw`flex absolute justify-center items-center w-full h-full left-0 top-0`}>
                <Spinner size={'small'} />
            </div>
        )}
        <span css={isLoading ? tw`relative z-10 inline-flex items-center justify-center gap-2 text-transparent` : tw`relative z-10 inline-flex items-center justify-center gap-2`}>{children}</span>
    </ButtonStyle>
);

type LinkProps = Omit<JSX.IntrinsicElements['a'], 'ref' | keyof Props> & Props;

const LinkButton: React.FC<LinkProps> = (props) => <ButtonStyle as={'a'} {...props} />;

export { LinkButton, ButtonStyle };
export default Button;
