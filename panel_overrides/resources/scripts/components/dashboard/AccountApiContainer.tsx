import React, { useEffect, useRef, useState } from 'react';
import ContentBox from '@/components/elements/ContentBox';
import CreateApiKeyForm from '@/components/dashboard/forms/CreateApiKeyForm';
import getApiKeys, { ApiKey } from '@/api/account/getApiKeys';
import SpinnerOverlay from '@/components/elements/SpinnerOverlay';
import { FontAwesomeIcon } from '@fortawesome/react-fontawesome';
import { faEye, faKey, faTrashAlt } from '@fortawesome/free-solid-svg-icons';
import deleteApiKey from '@/api/account/deleteApiKey';
import revealApiKey from '@/api/account/revealApiKey';
import FlashMessageRender from '@/components/FlashMessageRender';
import { format } from 'date-fns';
import PageContentBlock from '@/components/elements/PageContentBlock';
import tw from 'twin.macro';
import { Dialog } from '@/components/elements/dialog';
import { useFlashKey } from '@/plugins/useFlash';
import Code from '@/components/elements/Code';
import styled from 'styled-components/macro';
import { httpErrorToHuman } from '@/api/http';

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

const RevealButton = styled.button`
    ${tw`w-9 h-9 rounded-lg border flex items-center justify-center transition flex-shrink-0`};
    background: #10111b;
    border-color: rgba(139, 92, 246, 0.28);
    color: #c4b5fd;

    &:hover:not(:disabled) {
        border-color: rgba(139, 92, 246, 0.7);
        color: #ffffff;
        box-shadow: 0 0 18px rgba(139, 92, 246, 0.28);
    }

    &:disabled {
        opacity: 0.55;
        cursor: wait;
    }
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

const RevealBox = styled.div`
    ${tw`fixed inset-0 z-50 flex items-center justify-center p-4`};
    background: rgba(7, 7, 11, 0.82);
`;

const RevealCard = styled.div`
    ${tw`w-full max-w-2xl rounded-xl border p-5`};
    background: #0b0b10;
    border-color: rgba(139, 92, 246, 0.42);
    box-shadow: 0 24px 70px rgba(0, 0, 0, 0.68), 0 0 34px rgba(139, 92, 246, 0.16);
`;

const RevealInput = styled.input`
    ${tw`w-full rounded-lg border px-3 py-2 text-sm outline-none`};
    background: #07070b;
    border-color: rgba(139, 92, 246, 0.28);
    color: #f5f3ff;

    &:focus {
        border-color: rgba(139, 92, 246, 0.72);
        box-shadow: 0 0 0 1px rgba(139, 92, 246, 0.34);
    }
`;

const RevealAction = styled.button`
    ${tw`rounded-lg border px-4 py-2 text-sm uppercase tracking-wide transition`};
    background: #8b5cf6;
    border-color: rgba(255, 255, 255, 0.18);
    color: #ffffff;

    &:hover:not(:disabled) {
        transform: translateY(-1px);
        box-shadow: 0 0 18px rgba(139, 92, 246, 0.28);
    }

    &:disabled {
        opacity: 0.55;
        cursor: wait;
    }
`;

const RevealSecondary = styled(RevealAction)`
    background: #111117;
    border-color: rgba(139, 92, 246, 0.28);
    color: #d4d4df;
`;

export default () => {
    const [deleteIdentifier, setDeleteIdentifier] = useState('');
    const [revealedApiKey, setRevealedApiKey] = useState('');
    const [revealIdentifier, setRevealIdentifier] = useState('');
    const [revealPassword, setRevealPassword] = useState('');
    const [revealError, setRevealError] = useState('');
    const [revealCopied, setRevealCopied] = useState(false);
    const [revealingIdentifier, setRevealingIdentifier] = useState('');
    const [keys, setKeys] = useState<ApiKey[]>([]);
    const [loading, setLoading] = useState(true);
    const revealRequestRef = useRef(0);
    const { clearAndAddHttpError } = useFlashKey('account');

    useEffect(() => {
        getApiKeys()
            .then((keys) => setKeys(keys))
            .catch((error) => clearAndAddHttpError(error))
            .then(() => setLoading(false));
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

    const openReveal = (identifier: string) => {
        revealRequestRef.current += 1;
        setRevealIdentifier(identifier);
        setRevealPassword('');
        setRevealedApiKey('');
        setRevealError('');
        setRevealCopied(false);
        setRevealingIdentifier('');
    };

    const closeReveal = () => {
        revealRequestRef.current += 1;
        setRevealIdentifier('');
        setRevealPassword('');
        setRevealedApiKey('');
        setRevealError('');
        setRevealCopied(false);
        setRevealingIdentifier('');
    };

    const copyWithFallback = () => {
        const element = document.createElement('textarea');
        element.value = revealedApiKey;
        element.setAttribute('readonly', 'true');
        element.style.position = 'fixed';
        element.style.opacity = '0';
        document.body.appendChild(element);
        element.select();

        try {
            return document.execCommand('copy');
        } finally {
            document.body.removeChild(element);
        }
    };

    const copyRevealedApiKey = () => {
        if (!revealedApiKey) return;

        const copied = navigator.clipboard?.writeText
            ? navigator.clipboard.writeText(revealedApiKey).then(() => true).catch(copyWithFallback)
            : Promise.resolve(copyWithFallback());

        copied.then((didCopy) => {
            if (didCopy) {
                setRevealCopied(true);
            } else {
                setRevealError('The token could not be copied automatically. Select and copy it manually.');
            }
        });
    };

    const doReveal = (e?: React.FormEvent) => {
        e?.preventDefault();
        if (!revealIdentifier || revealingIdentifier) return;
        if (!revealPassword) {
            setRevealError('Enter your account password to reveal this token.');
            return;
        }

        const requestId = revealRequestRef.current + 1;
        revealRequestRef.current = requestId;
        const identifier = revealIdentifier;

        setRevealedApiKey('');
        setRevealError('');
        setRevealCopied(false);
        setRevealingIdentifier(identifier);
        clearAndAddHttpError();

        revealApiKey(identifier, revealPassword)
            .then((apiKey) => {
                if (revealRequestRef.current === requestId && revealIdentifier === identifier) {
                    setRevealedApiKey(apiKey);
                }
            })
            .catch((error) => {
                if (revealRequestRef.current === requestId && revealIdentifier === identifier) {
                    setRevealError(httpErrorToHuman(error));
                }
            })
            .then(() => {
                if (revealRequestRef.current === requestId && revealIdentifier === identifier) {
                    setRevealingIdentifier('');
                }
            });
    };

    return (
        <PageContentBlock title={'Account API'}>
            {(!!revealIdentifier || !!revealingIdentifier || !!revealedApiKey || !!revealError) && (
                <RevealBox onClick={closeReveal}>
                    <RevealCard onClick={(e) => e.stopPropagation()}>
                        <h3 css={tw`text-2xl text-neutral-50 mb-4`}>Full PTLC Token</h3>
                        {!revealedApiKey && (
                            <form onSubmit={doReveal}>
                                <p css={tw`text-sm text-neutral-300 mb-4`}>
                                    Confirm your account password before revealing this API key. Treat the token like a password.
                                </p>
                                <RevealInput
                                    type={'password'}
                                    value={revealPassword}
                                    autoComplete={'current-password'}
                                    autoFocus
                                    disabled={!!revealingIdentifier}
                                    placeholder={'Account password'}
                                    onChange={(e) => setRevealPassword(e.currentTarget.value)}
                                />
                                {!!revealError && <p css={tw`text-sm text-red-300 mt-3`}>{revealError}</p>}
                                <div css={tw`flex flex-wrap justify-end gap-3 mt-6`}>
                                    <RevealAction type={'submit'} disabled={!!revealingIdentifier}>
                                        {revealingIdentifier ? 'Loading...' : 'Reveal Token'}
                                    </RevealAction>
                                    <RevealSecondary type={'button'} onClick={closeReveal}>Close</RevealSecondary>
                                </div>
                            </form>
                        )}
                        {!!revealedApiKey && (
                            <>
                                <p css={tw`text-sm text-neutral-300 mb-4`}>
                                    Treat this token like a password. Anyone with the full PTLC token can use this API key.
                                </p>
                                {!!revealError && <p css={tw`text-sm text-red-300 mb-3`}>{revealError}</p>}
                                <pre css={tw`text-sm rounded-lg p-3 font-mono max-w-full overflow-x-auto whitespace-pre-wrap break-all select-all`}
                                    style={{ background: '#07070b', border: '1px solid rgba(139, 92, 246, 0.26)', color: '#ddd6fe' }}
                                >
                                    <code>{revealedApiKey}</code>
                                </pre>
                                <div css={tw`flex flex-wrap justify-end gap-3 mt-6`}>
                                    <RevealAction type={'button'} onClick={copyRevealedApiKey}>
                                        {revealCopied ? 'Copied' : 'Copy Full Key'}
                                    </RevealAction>
                                    <RevealSecondary type={'button'} onClick={closeReveal}>Close</RevealSecondary>
                                </div>
                            </>
                        )}
                    </RevealCard>
                </RevealBox>
            )}
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
                                    <div css={tw`flex items-center gap-2 flex-shrink-0`}>
                                        <RevealButton
                                            type={'button'}
                                            aria-label={'Reveal full API key'}
                                            disabled={revealingIdentifier === key.identifier}
                                            onClick={() => openReveal(key.identifier)}
                                        >
                                            <FontAwesomeIcon icon={faEye} />
                                        </RevealButton>
                                        <RevokeButton type={'button'} aria-label={'Revoke API key'} onClick={() => setDeleteIdentifier(key.identifier)}>
                                            <FontAwesomeIcon icon={faTrashAlt} />
                                        </RevokeButton>
                                    </div>
                                </ApiKeyCard>
                            ))}
                        </ApiGrid>
                    )}
                </ContentBox>
            </div>
        </PageContentBlock>
    );
};
