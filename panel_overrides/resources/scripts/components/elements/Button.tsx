import React from 'react';
import styled, { css } from 'styled-components/macro';
import tw from 'twin.macro';
import Spinner from '@/components/elements/Spinner';

interface Props {
    isLoading?: boolean;
    size?: 'xsmall' | 'small' | 'large' | 'xlarge';
    color?: 'green' | 'red' | 'primary' | 'grey';
    isSecondary?: boolean;
}

const ButtonStyle = styled.button<Omit<Props, 'isLoading'>>`
    ${tw`relative inline-block rounded uppercase tracking-wide text-sm transition-all duration-150 border`};
    background: #111117;
    border-color: rgba(139, 92, 246, 0.28);
    color: #f9fafb;
    box-shadow: inset 0 1px 0 rgba(255, 255, 255, 0.04);

    &:hover:not(:disabled) {
        transform: translateY(-1px);
        border-color: rgba(139, 92, 246, 0.7);
        box-shadow: 0 0 22px rgba(139, 92, 246, 0.18);
    }

    ${(props) =>
        props.color === 'green' &&
        css`
            border-color: rgba(16, 185, 129, 0.44);
            color: #bbf7d0;
        `};

    ${(props) =>
        props.color === 'red' &&
        css`
            border-color: rgba(239, 68, 68, 0.44);
            color: #fecaca;
        `};

    ${(props) =>
        props.color === 'grey' &&
        css`
            border-color: rgba(116, 116, 138, 0.38);
            color: #d4d4df;
        `};

    ${(props) => props.size === 'xsmall' && tw`px-2 py-1 text-xs`};
    ${(props) => (!props.size || props.size === 'small') && tw`px-4 py-2`};
    ${(props) => props.size === 'large' && tw`p-4 text-sm`};
    ${(props) => props.size === 'xlarge' && tw`p-4 w-full`};

    ${(props) =>
        props.isSecondary &&
        css`
            background: #0b0b10;
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
        <span css={isLoading ? tw`text-transparent` : undefined}>{children}</span>
    </ButtonStyle>
);

type LinkProps = Omit<JSX.IntrinsicElements['a'], 'ref' | keyof Props> & Props;

const LinkButton: React.FC<LinkProps> = (props) => <ButtonStyle as={'a'} {...props} />;

export { LinkButton, ButtonStyle };
export default Button;
