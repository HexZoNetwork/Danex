import React from 'react';
import { createPortal } from 'react-dom';
import Spinner, { SpinnerSize } from '@/components/elements/Spinner';
import tw from 'twin.macro';

interface Props {
    visible: boolean;
    fixed?: boolean;
    size?: SpinnerSize;
    backgroundOpacity?: number;
}

const OverlayBody: React.FC<Props> = ({ size, fixed, backgroundOpacity, children }) => (
    <div
        css={[
            tw`top-0 left-0 flex items-center justify-center w-full h-full rounded flex-col`,
            fixed ? tw`fixed` : tw`absolute`,
        ]}
        style={{
            background: `rgba(0, 0, 0, ${backgroundOpacity || 0.62})`,
            backdropFilter: 'blur(8px)',
            zIndex: fixed ? 2147483000 : 9999,
        }}
    >
        <div
            css={tw`rounded-xl border px-6 py-5 text-center shadow-2xl`}
            style={{ background: '#0b0b10', borderColor: 'rgba(139, 92, 246, 0.34)' }}
        >
            <Spinner size={size} />
            {children && (typeof children === 'string' ? <p css={tw`mt-4 text-neutral-300`}>{children}</p> : children)}
        </div>
    </div>
);

const SpinnerOverlay: React.FC<Props> = (props) => {
    if (!props.visible) return null;
    if (props.fixed && typeof document !== 'undefined') {
        return createPortal(<OverlayBody {...props} />, document.body);
    }

    return <OverlayBody {...props} />;
};

export default SpinnerOverlay;
