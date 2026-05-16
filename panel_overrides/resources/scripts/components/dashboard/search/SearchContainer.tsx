import React, { useState } from 'react';
import { FontAwesomeIcon } from '@fortawesome/react-fontawesome';
import { faSearch } from '@fortawesome/free-solid-svg-icons';
import useEventListener from '@/plugins/useEventListener';
import SearchModal from '@/components/dashboard/search/SearchModal';
import Tooltip from '@/components/elements/tooltip/Tooltip';
import styled from 'styled-components/macro';
import tw from 'twin.macro';

const SearchShell = styled.button`
    ${tw`hidden md:flex items-center h-9 rounded-full border px-3 text-left transition-all duration-200`};
    width: clamp(14rem, 24vw, 24rem);
    background: #09090d;
    border-color: rgba(139, 92, 246, 0.22);
    color: #8b8ba0;
    box-shadow: inset 0 1px 0 rgba(255, 255, 255, 0.04);

    &:hover,
    &:focus {
        border-color: rgba(139, 92, 246, 0.62);
        background: #111117;
        color: #e5e7eb;
        box-shadow: 0 0 0 2px rgba(139, 92, 246, 0.12), 0 0 22px rgba(139, 92, 246, 0.16);
    }
`;

const IconButton = styled.button`
    ${tw`md:hidden flex items-center justify-center leading-none rounded border transition`};
    width: 2.35rem;
    height: 2.35rem;
    background: #09090d;
    border-color: rgba(139, 92, 246, 0.22);
    color: #d4d4df;

    &:hover {
        background: #111117;
        border-color: rgba(139, 92, 246, 0.58);
        box-shadow: 0 0 18px rgba(139, 92, 246, 0.2);
    }
`;

export default () => {
    const [visible, setVisible] = useState(false);

    useEventListener('keydown', (e: KeyboardEvent) => {
        if (['input', 'textarea'].indexOf(((e.target as HTMLElement).tagName || 'input').toLowerCase()) < 0) {
            if (!visible && e.metaKey && e.key.toLowerCase() === '/') {
                setVisible(true);
            }
        }
    });

    return (
        <>
            {visible && <SearchModal appear visible={visible} onDismissed={() => setVisible(false)} />}
            <SearchShell type={'button'} onClick={() => setVisible(true)} aria-label={'Search servers'}>
                <FontAwesomeIcon icon={faSearch} css={tw`mr-3 text-purple-300`} />
                <span css={tw`flex-1 text-sm truncate`}>Search servers</span>
                <span css={tw`text-[10px] border rounded px-1.5 py-0.5`} style={{ borderColor: 'rgba(139, 92, 246, 0.24)', color: '#74748a' }}>
                    Cmd /
                </span>
            </SearchShell>
            <Tooltip placement={'bottom'} content={'Search'}>
                <IconButton type={'button'} onClick={() => setVisible(true)}>
                    <FontAwesomeIcon icon={faSearch} />
                </IconButton>
            </Tooltip>
        </>
    );
};
