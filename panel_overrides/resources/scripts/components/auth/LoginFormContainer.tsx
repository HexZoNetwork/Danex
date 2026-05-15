import React, { forwardRef } from 'react';
import { Form } from 'formik';
import styled from 'styled-components/macro';
import { breakpoint } from '@/theme';
import FlashMessageRender from '@/components/FlashMessageRender';
import tw from 'twin.macro';

type Props = React.DetailedHTMLProps<React.FormHTMLAttributes<HTMLFormElement>, HTMLFormElement> & {
    title?: string;
    compact?: boolean;
    hideLogo?: boolean;
    className?: string;
};

const Container = styled.div`
    ${tw`w-full mx-auto px-2`};

    ${breakpoint('sm')`
        ${tw`w-4/5 px-0`}
    `};

    ${breakpoint('md')`
        ${tw`p-10`}
    `};

    ${breakpoint('lg')`
        ${tw`w-3/5`}
    `};

    ${breakpoint('xl')`
        ${tw`w-full`}
        max-width: 700px;
    `};
`;

const AuthShell = styled.div<{ compact?: boolean }>`
    ${tw`w-full mx-1 border overflow-hidden`};
    border-radius: 18px;
    background:
        linear-gradient(180deg, rgba(255, 255, 255, 0.035), transparent 34%),
        #0b0b10;
    border-color: rgba(139, 92, 246, 0.34);
    box-shadow: 0 28px 80px rgba(0, 0, 0, 0.62), 0 0 44px rgba(139, 92, 246, 0.16);
    position: relative;

    &::before {
        content: '';
        position: absolute;
        inset: 0;
        pointer-events: none;
        background:
            radial-gradient(circle at 18% 0%, rgba(139, 92, 246, 0.14), transparent 18rem),
            repeating-linear-gradient(90deg, rgba(255,255,255,0.024) 0 1px, transparent 1px 58px),
            repeating-linear-gradient(0deg, rgba(255,255,255,0.018) 0 1px, transparent 1px 58px);
        opacity: 0.86;
    }

    @media (min-width: 768px) {
        display: ${({ compact }) => (compact ? 'block' : 'grid')};
        grid-template-columns: minmax(220px, 0.86fr) minmax(320px, 1.14fr);
    }
`;

const BrandPanel = styled.div`
    ${tw`relative p-6 md:p-8 flex flex-col justify-between select-none`};
    min-height: 260px;
    background: #09090d;
    border-bottom: 1px solid rgba(139, 92, 246, 0.22);

    @media (min-width: 768px) {
        border-bottom: 0;
        border-right: 1px solid rgba(139, 92, 246, 0.22);
    }
`;

const MonitorMark = styled.div`
    ${tw`relative border flex items-center justify-center`};
    width: 104px;
    height: 74px;
    border-radius: 16px;
    background: #0f0f16;
    border-color: rgba(139, 92, 246, 0.44);
    box-shadow: inset 0 0 24px rgba(139, 92, 246, 0.1), 0 0 26px rgba(139, 92, 246, 0.12);
    overflow: hidden;

    &::before {
        content: '';
        position: absolute;
        left: 15px;
        right: 15px;
        top: 20px;
        height: 3px;
        background: #8b5cf6;
        box-shadow: 0 13px 0 #06b6d4, 0 26px 0 rgba(139, 92, 246, 0.62);
        animation: auth-heartbeat 1.6s ease-in-out infinite;
    }

    &::after {
        content: '';
        position: absolute;
        inset: 0;
        background: linear-gradient(180deg, transparent, rgba(255,255,255,0.08), transparent);
        transform: translateY(-100%);
        animation: auth-scan 3.8s ease-in-out infinite;
    }
`;

const StatusRail = styled.div`
    ${tw`mt-7 grid gap-3`};

    span {
        ${tw`flex items-center justify-between text-xs uppercase tracking-widest`};
        color: #a6a6b8;
        border: 1px solid rgba(139, 92, 246, 0.18);
        border-radius: 10px;
        background: #0b0b10;
        padding: 10px 12px;
    }

    b {
        color: #d9f99d;
        font-weight: 800;
    }
`;

const FormPanel = styled.div`
    ${tw`relative p-6 md:p-8`};
`;

const BrandTitle = styled.div`
    ${tw`mt-6`};

    h1 {
        ${tw`m-0 text-neutral-100 font-bold uppercase`};
        font-size: clamp(24px, 4vw, 34px);
        letter-spacing: 0.08em;
        line-height: 1;
    }

    p {
        ${tw`mt-3 mb-0 text-sm`};
        color: #a6a6b8;
        letter-spacing: 0.08em;
        text-transform: uppercase;
    }
`;

const FormTitle = styled.h2`
    ${tw`mt-0 mb-5 text-neutral-100 font-semibold uppercase`};
    font-size: 14px;
    letter-spacing: 0.14em;

    &::after {
        content: '';
        display: block;
        width: 72px;
        height: 2px;
        margin-top: 12px;
        background: #8b5cf6;
        box-shadow: 0 0 18px rgba(139, 92, 246, 0.55);
    }
`;

const Footer = styled.p`
    ${tw`text-center text-xs mt-4 uppercase`};
    color: #6f6f82;
    letter-spacing: 0.12em;
`;

const Keyframes = styled.div`
    display: none;

    @keyframes auth-heartbeat {
        0%, 100% { opacity: 0.62; transform: translateX(-5px); }
        45% { opacity: 1; transform: translateX(6px); }
    }

    @keyframes auth-scan {
        0%, 32% { transform: translateY(-100%); }
        58%, 100% { transform: translateY(100%); }
    }
`;

export default forwardRef<HTMLFormElement, Props>(({ title, compact, hideLogo, className, ...props }, ref) => (
    <Container className={className}>
        <Keyframes />
        <FlashMessageRender css={tw`mb-2 px-1`} />
        <Form {...props} ref={ref}>
            <AuthShell compact={compact}>
                {!hideLogo && (
                    <BrandPanel>
                        <div>
                            <MonitorMark aria-hidden={'true'} />
                            <BrandTitle>
                                <h1>DANEX</h1>
                                <p>X EL7 Control</p>
                            </BrandTitle>
                        </div>
                        <StatusRail>
                            <span>
                                Core <b>Ready</b>
                            </span>
                            <span>
                                WAF <b>Bound</b>
                            </span>
                            <span>
                                Nodes <b>Live</b>
                            </span>
                        </StatusRail>
                    </BrandPanel>
                )}
                <FormPanel>
                    {title && <FormTitle>{title}</FormTitle>}
                    {props.children}
                </FormPanel>
            </AuthShell>
        </Form>
        <Footer>DANEX X EL7 Secure Panel / {new Date().getFullYear()}</Footer>
    </Container>
));
