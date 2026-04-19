import React from 'react';
import tw from 'twin.macro';
import PageContentBlock from '@/components/elements/PageContentBlock';
import PublicChatPanel from '@/components/dashboard/chat/PublicChatPanel';

export default () => {
    return (
        <PageContentBlock showFlashKey={'dashboard'}>
            <div css={tw`mx-auto w-full max-w-7xl`}>
                <PublicChatPanel />
            </div>
        </PageContentBlock>
    );
};
