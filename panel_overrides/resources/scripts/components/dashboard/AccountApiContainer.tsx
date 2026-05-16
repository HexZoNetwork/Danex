import React, { useEffect, useState } from 'react';
import ContentBox from '@/components/elements/ContentBox';
import CreateApiKeyForm from '@/components/dashboard/forms/CreateApiKeyForm';
import getApiKeys, { ApiKey } from '@/api/account/getApiKeys';
import SpinnerOverlay from '@/components/elements/SpinnerOverlay';
import { FontAwesomeIcon } from '@fortawesome/react-fontawesome';
import { faKey, faTrashAlt } from '@fortawesome/free-solid-svg-icons';
import deleteApiKey from '@/api/account/deleteApiKey';
import FlashMessageRender from '@/components/FlashMessageRender';
import { format } from 'date-fns';
import PageContentBlock from '@/components/elements/PageContentBlock';
import tw from 'twin.macro';
import { Dialog } from '@/components/elements/dialog';
import { useFlashKey } from '@/plugins/useFlash';
import Code from '@/components/elements/Code';
import styled from 'styled-components/macro';

const ApiGrid = styled.div`
    ${tw`space-y-2`};
`;

const ApiKeyCard = styled.div`
    ${tw`rounded-lg border p-3 flex items-center gap-3 transition-all duration-200`};
    background: #0b0b10;
    border-color: rgba(139, 92, 246, 0.22);
    box-shadow: 0 14px 34px rgba(0, 0, 0, 0.38);

    &:hover {
        transform: translateY(-1px);
        border-color: rgba(139, 92, 246, 0.58);
        box-shadow: 0 18px 44px rgba(0, 0, 0, 0.48), inset 2px 0 0 rgba(139, 92, 246, 0.72);
    }
`;

const KeyIcon = styled.div`
    ${tw`w-10 h-10 rounded-lg border flex items-center justify-center flex-shrink-0`};
    background: #111117;
    border-color: rgba(139, 92, 246, 0.32);
    color: #a78bfa;
    box-shadow: 0 0 20px rgba(139, 92, 246, 0.14);
`;

const RevokeButton = styled.button`
    ${tw`w-9 h-9 rounded-lg border flex items-center justify-center transition flex-shrink-0`};
    background: #160f12;
    border-color: rgba(239, 68, 68, 0.28);
    color: #fca5a5;

    &:hover {
        border-color: rgba(239, 68, 68, 0.7);
        color: #ffffff;
        box-shadow: 0 0 18px rgba(239, 68, 68, 0.28);
    }
`;

export default () => {
    const [deleteIdentifier, setDeleteIdentifier] = useState('');
    const [keys, setKeys] = useState<ApiKey[]>([]);
    const [loading, setLoading] = useState(true);
    const { clearAndAddHttpError } = useFlashKey('account');

    useEffect(() => {
        getApiKeys()
            .then((keys) => setKeys(keys))
            .then(() => setLoading(false))
            .catch((error) => clearAndAddHttpError(error));
    }, []);

    const doDeletion = (identifier: string) => {
        setLoading(true);

        clearAndAddHttpError();
        deleteApiKey(identifier)
            .then(() => setKeys((s) => [...(s || []).filter((key) => key.identifier !== identifier)]))
            .catch((error) => clearAndAddHttpError(error))
            .then(() => {
                setLoading(false);
                setDeleteIdentifier('');
            });
    };

    return (
        <PageContentBlock title={'Account API'}>
            <FlashMessageRender byKey={'account'} />
            <div css={tw`grid grid-cols-1 xl:grid-cols-[minmax(20rem,30rem)_1fr] gap-6 my-10`}>
                <ContentBox title={'Create API Key'}>
                    <CreateApiKeyForm onKeyCreated={(key) => setKeys((s) => [...s!, key])} />
                </ContentBox>
                <ContentBox title={'API Keys'} css={tw`overflow-hidden`}>
                    <SpinnerOverlay visible={loading} />
                    <Dialog.Confirm
                        title={'Delete API Key'}
                        confirm={'Delete Key'}
                        open={!!deleteIdentifier}
                        onClose={() => setDeleteIdentifier('')}
                        onConfirmed={() => doDeletion(deleteIdentifier)}
                    >
                        All requests using the <Code>{deleteIdentifier}</Code> key will be invalidated.
                    </Dialog.Confirm>
                    {keys.length === 0 ? (
                        <p css={tw`text-center text-sm`}>
                            {loading ? 'Loading...' : 'No API keys exist for this account.'}
                        </p>
                    ) : (
                        <ApiGrid>
                            {keys.map((key) => (
                                <ApiKeyCard key={key.identifier}>
                                    <KeyIcon>
                                        <FontAwesomeIcon icon={faKey} />
                                    </KeyIcon>
                                    <div css={tw`flex-1 min-w-0`}>
                                        <p css={tw`text-sm text-neutral-100 font-semibold break-words`}>{key.description || 'Untitled key'}</p>
                                        <p css={tw`text-[11px] text-neutral-400 uppercase tracking-wider`}>
                                            Last used {key.lastUsedAt ? format(key.lastUsedAt, 'MMM do, yyyy HH:mm') : 'Never'}
                                        </p>
                                        <code css={tw`mt-2 inline-block max-w-full truncate font-mono text-[11px] rounded border px-2 py-1`}
                                            style={{ background: '#111117', borderColor: 'rgba(139, 92, 246, 0.24)', color: '#ddd6fe' }}
                                        >
                                            {key.identifier}
                                        </code>
                                    </div>
                                    <RevokeButton type={'button'} aria-label={'Revoke API key'} onClick={() => setDeleteIdentifier(key.identifier)}>
                                        <FontAwesomeIcon icon={faTrashAlt} />
                                    </RevokeButton>
                                </ApiKeyCard>
                            ))}
                        </ApiGrid>
                    )}
                </ContentBox>
            </div>
        </PageContentBlock>
    );
};
