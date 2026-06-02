import React, { useContext, useState } from 'react';
import tw from 'twin.macro';
import Button from '@/components/elements/Button';
import asModal from '@/hoc/asModal';
import ModalContext from '@/context/ModalContext';

interface Props {
    apiKey: string;
}

const fallbackCopy = (value: string) => {
    const element = document.createElement('textarea');

    element.value = value;
    element.setAttribute('readonly', 'true');
    element.style.position = 'fixed';
    element.style.opacity = '0';
    element.style.pointerEvents = 'none';
    document.body.appendChild(element);
    element.select();

    const copied = document.execCommand('copy');
    document.body.removeChild(element);

    return copied;
};

const ApiKeyModal = ({ apiKey }: Props) => {
    const { dismiss } = useContext(ModalContext);
    const [copied, setCopied] = useState(false);

    const copyApiKey = () => {
        if (navigator.clipboard?.writeText) {
            navigator.clipboard
                .writeText(apiKey)
                .then(() => setCopied(true))
                .catch(() => setCopied(fallbackCopy(apiKey)));

            return;
        }

        setCopied(fallbackCopy(apiKey));
    };

    return (
        <>
            <h3 css={tw`mb-6 text-2xl`}>Your API Key</h3>
            <p css={tw`text-sm mb-6`}>
                The full API key you have requested is shown below. Please store this in a safe location, it will not be
                shown again.
            </p>
            <pre css={tw`text-sm bg-neutral-900 rounded py-2 px-4 font-mono max-w-full overflow-x-auto whitespace-pre-wrap break-all select-all`}>
                <code css={tw`font-mono`}>{apiKey}</code>
            </pre>
            <p css={tw`text-xs text-neutral-400 mt-3`}>
                Treat this token like a password. Anyone with the full PTLC token can use the account API permissions
                attached to this key.
            </p>
            <div css={tw`flex flex-wrap justify-end gap-3 mt-6`}>
                <Button type={'button'} color={'green'} onClick={copyApiKey}>
                    {copied ? 'Copied' : 'Copy Full Key'}
                </Button>
                <Button type={'button'} isSecondary onClick={() => dismiss()}>
                    Close
                </Button>
            </div>
        </>
    );
};

ApiKeyModal.displayName = 'ApiKeyModal';

export default asModal<Props>({
    closeOnEscape: false,
    closeOnBackground: false,
})(ApiKeyModal);
