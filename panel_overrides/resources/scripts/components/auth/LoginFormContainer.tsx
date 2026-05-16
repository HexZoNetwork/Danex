import React, { forwardRef } from 'react';
import { Form } from 'formik';
import styled from 'styled-components/macro';
import FlashMessageRender from '@/components/FlashMessageRender';
import tw from 'twin.macro';

type Props = React.DetailedHTMLProps<React.FormHTMLAttributes<HTMLFormElement>, HTMLFormElement> & {
    title?: string;
    compact?: boolean;
    hideLogo?: boolean;
    className?: string;
};

const Container = styled.div<{ compact?: boolean }>`
    ${tw`w-full mx-auto px-4 py-6 md:py-10`};
    max-width: ${({ compact }) => (compact ? '590px' : '460px')};
`;

const AuthShell = styled.div`
    ${tw`relative w-full border overflow-hidden`};
    border-radius: 14px;
    background: #0b0b10;
    border-color: rgba(139, 92, 246, 0.3);
    box-shadow: 0 22px 70px rgba(0, 0, 0, 0.58), 0 0 30px rgba(139, 92, 246, 0.12);

    &::before {
        content: '';
        position: absolute;
        inset: 0;
        pointer-events: none;
        background:
            linear-gradient(90deg, transparent, rgba(139, 92, 246, 0.08), transparent),
            repeating-linear-gradient(90deg, rgba(255,255,255,0.018) 0 1px, transparent 1px 48px);
        opacity: 0.78;
        transform: translateX(-24%);
        animation: auth-panel-scan 7s ease-in-out infinite;
    }
`;

const Header = styled.div`
    ${tw`relative px-5 pt-5 pb-4 border-b text-center`};
    border-color: rgba(139, 92, 246, 0.2);
    background: #09090d;
`;

const LogoPlate = styled.div`
    ${tw`mx-auto flex items-center justify-center border`};
    width: 58px;
    height: 58px;
    border-radius: 14px;
    background: #111117;
    border-color: rgba(139, 92, 246, 0.38);
    box-shadow: inset 0 1px 0 rgba(255, 255, 255, 0.06), 0 0 20px rgba(139, 92, 246, 0.16);

    img {
        width: 34px;
        height: 34px;
        object-fit: contain;
        filter: drop-shadow(0 0 10px rgba(139, 92, 246, 0.38));
    }
`;

const Product = styled.div`
    ${tw`mt-3 text-[11px] uppercase tracking-widest text-neutral-500 font-semibold`};
`;

const FormTitle = styled.h1`
    ${tw`m-0 mt-1 text-neutral-100 font-semibold`};
    font-size: 19px;
    letter-spacing: 0.02em;
`;

const FormPanel = styled.div<{ compact?: boolean }>`
    ${tw`relative`};
    padding: ${({ compact }) => (compact ? '1.15rem' : '1.35rem')};

    @media (min-width: 768px) {
        padding: ${({ compact }) => (compact ? '1.25rem' : '1.5rem')};
    }
`;

const Footer = styled.p`
    ${tw`text-center text-[10px] mt-3 uppercase`};
    color: #68687a;
    letter-spacing: 0.14em;
`;

const Keyframes = styled.div`
    display: none;

    @keyframes auth-panel-scan {
        0%, 28% { transform: translateX(-36%); opacity: 0.5; }
        56% { transform: translateX(18%); opacity: 0.95; }
        100% { transform: translateX(36%); opacity: 0.45; }
    }
`;

export default forwardRef<HTMLFormElement, Props>(({ title, compact, hideLogo, className, ...props }, ref) => (
    <Container className={className} compact={compact}>
        <Keyframes />
        <FlashMessageRender css={tw`mb-2 px-1`} />
        <Form {...props} ref={ref}>
            <AuthShell>
                {!hideLogo && (
                    <Header>
                        <LogoPlate>
                            <img src={'/assets/svgs/pterodactyl.svg'} alt={'Pterodactyl'} />
                        </LogoPlate>
                        <Product>Pterodactyl Panel</Product>
                        {title && <FormTitle>{title}</FormTitle>}
                    </Header>
                )}
                <FormPanel compact={compact}>{props.children}</FormPanel>
            </AuthShell>
        </Form>
        <Footer>DANEX X EL7 / {new Date().getFullYear()}</Footer>
    </Container>
));
