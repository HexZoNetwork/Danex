import React, { memo } from 'react';
import { FontAwesomeIcon } from '@fortawesome/react-fontawesome';
import { IconProp } from '@fortawesome/fontawesome-svg-core';
import tw from 'twin.macro';
import isEqual from 'react-fast-compare';

interface Props {
    icon?: IconProp;
    title: string | React.ReactNode;
    className?: string;
    children: React.ReactNode;
}

const TitledGreyBox = ({ icon, title, children, className }: Props) => (
    <div
        css={tw`rounded-lg shadow-md border overflow-hidden`}
        className={className}
        style={{ background: '#0b0b10', borderColor: 'rgba(139, 92, 246, 0.24)' }}
    >
        <div css={tw`rounded-t p-3 border-b`} style={{ background: '#111117', borderColor: 'rgba(139, 92, 246, 0.18)' }}>
            {typeof title === 'string' ? (
                <p css={tw`text-sm uppercase tracking-wider text-neutral-200`}>
                    {icon && <FontAwesomeIcon icon={icon} css={tw`mr-2 text-purple-300`} />}
                    {title}
                </p>
            ) : (
                title
            )}
        </div>
        <div css={tw`p-3`}>{children}</div>
    </div>
);

export default memo(TitledGreyBox, isEqual);
