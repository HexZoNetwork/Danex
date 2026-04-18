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

export default forwardRef<HTMLFormElement, Props>(({ title, compact, hideLogo, className, ...props }, ref) => (
    <Container className={className}>
        {title && <h2 css={tw`text-3xl text-center text-neutral-100 font-medium py-4`}>{title}</h2>}
        <FlashMessageRender css={tw`mb-2 px-1`} />
        <Form {...props} ref={ref}>
            <div css={[tw`w-full bg-white shadow-lg rounded-lg p-6 mx-1`, !compact && tw`md:flex md:pl-0`]}>
                {!hideLogo && (
                    <div css={tw`flex-none select-none mb-6 md:mb-0 self-center`}>
                        <img
                            src={'/assets/svgs/pterodactyl.svg'}
                            width={256}
                            height={256}
                            loading={'eager'}
                            decoding={'async'}
                            css={tw`block w-48 md:w-64 h-auto mx-auto`}
                        />
                    </div>
                )}
                <div css={tw`flex-1`}>{props.children}</div>
            </div>
        </Form>
        <p css={tw`text-center text-neutral-500 text-xs mt-4`}>
            &copy; 2015 - {new Date().getFullYear()}&nbsp;
            <a
                rel={'noopener nofollow noreferrer'}
                href={'https://pterodactyl.io'}
                target={'_blank'}
                css={tw`no-underline text-neutral-500 hover:text-neutral-300`}
            >
                Pterodactyl Software
            </a>
        </p>
    </Container>
));
