import React, { useEffect, useState } from 'react';
import { Server } from '@/api/server/getServer';
import getServers from '@/api/getServers';
import ServerRow from '@/components/dashboard/ServerRow';
import Spinner from '@/components/elements/Spinner';
import PageContentBlock from '@/components/elements/PageContentBlock';
import useFlash from '@/plugins/useFlash';
import { useStoreState } from 'easy-peasy';
import { usePersistedState } from '@/plugins/usePersistedState';
import tw from 'twin.macro';
import useSWR from 'swr';
import { PaginatedResult } from '@/api/http';
import Pagination from '@/components/elements/Pagination';
import { useLocation } from 'react-router-dom';
import { FontAwesomeIcon } from '@fortawesome/react-fontawesome';
import { faBorderAll, faList } from '@fortawesome/free-solid-svg-icons';

const SERVERS_PER_PAGE = 6;

export default () => {
    const { search } = useLocation();
    const defaultPage = Number(new URLSearchParams(search).get('page') || '1');

    const [page, setPage] = useState(!isNaN(defaultPage) && defaultPage > 0 ? defaultPage : 1);
    const { clearFlashes, clearAndAddHttpError } = useFlash();
    const uuid = useStoreState((state) => state.user.data!.uuid);
    const rootAdmin = useStoreState((state) => state.user.data!.rootAdmin);
    const [showOnlyAdmin, setShowOnlyAdmin] = usePersistedState(`${uuid}:show_all_servers`, false);
    const [denseMode, setDenseMode] = usePersistedState(`${uuid}:server_grid_dense`, false);

    const { data: servers, error } = useSWR<PaginatedResult<Server>>(
        ['/api/client/servers', showOnlyAdmin && rootAdmin, page, SERVERS_PER_PAGE],
        () => getServers({ page, perPage: SERVERS_PER_PAGE, type: showOnlyAdmin && rootAdmin ? 'admin' : undefined })
    );

    useEffect(() => {
        setPage(1);
    }, [showOnlyAdmin]);

    useEffect(() => {
        if (!servers) return;
        if (servers.pagination.currentPage > 1 && !servers.items.length) {
            setPage(1);
        }
    }, [servers?.pagination.currentPage]);

    useEffect(() => {
        // Don't use react-router to handle changing this part of the URL, otherwise it
        // triggers a needless re-render. We just want to track this in the URL incase the
        // user refreshes the page.
        window.history.replaceState(null, document.title, `/${page <= 1 ? '' : `?page=${page}`}`);
    }, [page]);

    useEffect(() => {
        if (error) clearAndAddHttpError({ key: 'dashboard', error });
        if (!error) clearFlashes('dashboard');
    }, [error]);

    return (
        <PageContentBlock title={'Dashboard'} showFlashKey={'dashboard'}>
            <div css={tw`mb-4 flex flex-col gap-2 sm:flex-row sm:items-end sm:justify-between`}>
                <div>
                    <p css={tw`text-[11px] uppercase tracking-widest text-neutral-500 font-semibold`}>DANEX X EL7 SERVER MONITOR</p>
                    <h1 css={tw`mt-1 text-2xl sm:text-3xl text-neutral-50 font-semibold`}>Servers</h1>
                </div>
            </div>
            <div css={tw`mb-3 flex flex-wrap items-center justify-between gap-2 rounded-lg border px-3 py-2`} style={{ background: '#0b0b10', borderColor: 'rgba(139, 92, 246, 0.24)' }}>
                <div>
                    {rootAdmin && (
                        <button
                            type={'button'}
                            css={tw`h-9 rounded-md border px-3 text-xs uppercase tracking-wider transition`}
                            style={{ background: showOnlyAdmin ? '#8b5cf6' : '#07070b', color: showOnlyAdmin ? '#ffffff' : '#d6d3e8', borderColor: showOnlyAdmin ? 'rgba(167, 139, 250, 0.72)' : 'rgba(139, 92, 246, 0.24)', boxShadow: showOnlyAdmin ? '0 0 18px rgba(139, 92, 246, 0.35)' : 'none' }}
                            onClick={() => setShowOnlyAdmin((s) => !s)}
                        >
                            {showOnlyAdmin ? "Showing others' servers" : 'Showing your servers'}
                        </button>
                    )}
                </div>
                <div css={tw`flex flex-wrap items-center gap-2`}>
                    <div css={tw`flex items-center gap-1 rounded-md border p-1`} style={{ background: '#07070b', borderColor: 'rgba(139, 92, 246, 0.22)' }}>
                        <button
                            type={'button'}
                            aria-label={'Comfort box view'}
                            css={tw`w-9 h-8 rounded flex items-center justify-center transition`}
                            style={!denseMode ? { background: '#8b5cf6', color: '#ffffff', boxShadow: '0 0 18px rgba(139, 92, 246, 0.42)' } : { color: '#a6a6b8' }}
                            onClick={() => setDenseMode(false)}
                        >
                            <FontAwesomeIcon icon={faBorderAll} />
                        </button>
                        <button
                            type={'button'}
                            aria-label={'Dense row view'}
                            css={tw`w-9 h-8 rounded flex items-center justify-center transition`}
                            style={denseMode ? { background: '#8b5cf6', color: '#ffffff', boxShadow: '0 0 18px rgba(139, 92, 246, 0.42)' } : { color: '#a6a6b8' }}
                            onClick={() => setDenseMode(true)}
                        >
                            <FontAwesomeIcon icon={faList} />
                        </button>
                    </div>
                </div>
            </div>
            {!servers ? (
                <Spinner centered size={'large'} />
            ) : (
                <Pagination data={servers} onPageSelect={setPage}>
                    {({ items }) =>
                        items.length > 0 ? (
                            <div css={denseMode ? tw`space-y-2` : tw`grid grid-cols-1 lg:grid-cols-2 2xl:grid-cols-3 gap-3`}>
                                {items.map((server, index) => (
                                    <ServerRow
                                        key={server.uuid}
                                        server={server}
                                        compact={denseMode}
                                        eager={index < 3}
                                    />
                                ))}
                            </div>
                        ) : (
                            <p css={tw`text-center text-sm text-neutral-400`}>
                                {showOnlyAdmin
                                    ? 'There are no other servers to display.'
                                    : 'There are no servers associated with your account.'}
                            </p>
                        )
                    }
                </Pagination>
            )}
        </PageContentBlock>
    );
};
