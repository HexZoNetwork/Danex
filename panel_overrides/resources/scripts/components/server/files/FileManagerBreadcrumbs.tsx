import React, { useEffect, useRef, useState } from 'react';
import { ServerContext } from '@/state/server';
import { NavLink, useLocation } from 'react-router-dom';
import { encodePathSegments, hashToPath } from '@/helpers';
import tw from 'twin.macro';

interface Props {
    renderLeft?: JSX.Element;
    withinFileEditor?: boolean;
    isNewFile?: boolean;
}

export default ({ renderLeft, withinFileEditor, isNewFile }: Props) => {
    const [file, setFile] = useState<string | null>(null);
    const scrollerRef = useRef<HTMLDivElement | null>(null);
    const id = ServerContext.useStoreState((state) => state.server.data!.id);
    const directory = ServerContext.useStoreState((state) => state.files.directory);
    const { hash } = useLocation();

    useEffect(() => {
        const path = hashToPath(hash);

        if (withinFileEditor && !isNewFile) {
            const name = path.split('/').pop() || null;
            setFile(name);
        } else {
            setFile(null);
        }
    }, [withinFileEditor, isNewFile, hash]);

    useEffect(() => {
        const scroller = scrollerRef.current;
        if (!scroller) return;
        scroller.scrollLeft = scroller.scrollWidth;
    }, [directory, file]);

    const breadcrumbs = (): { name: string; path?: string }[] =>
        directory
            .split('/')
            .filter((directory) => !!directory)
            .map((directory, index, dirs) => {
                if (!withinFileEditor && index === dirs.length - 1) {
                    return { name: directory };
                }

                return { name: directory, path: `/${dirs.slice(0, index + 1).join('/')}` };
            });

    return (
        <div css={tw`flex min-w-0 flex-1 items-center rounded-md border px-2 py-2 text-xs sm:text-sm`} style={{ background: '#07070b', borderColor: 'rgba(139, 92, 246, 0.18)' }}>
            <div css={tw`mr-2 flex flex-shrink-0 items-center`}>{renderLeft || <div css={tw`w-6`} />}</div>
            <div css={tw`relative min-w-0 flex-1`}>
                <div css={tw`pointer-events-none absolute left-0 top-0 bottom-0 z-10 w-5 bg-gradient-to-r from-[#07070b] to-transparent`} />
                <div css={tw`pointer-events-none absolute right-0 top-0 bottom-0 z-10 w-7 bg-gradient-to-l from-[#07070b] to-transparent`} />
                <div ref={scrollerRef} css={tw`flex min-w-0 flex-1 items-center overflow-x-auto whitespace-nowrap px-1 text-neutral-500`} style={{ scrollbarWidth: 'none' }}>
                    /<span css={tw`px-1 text-neutral-300`}>home</span>/
                    <NavLink to={`/server/${id}/files`} css={tw`px-1 text-neutral-200 no-underline hover:text-neutral-100`}>
                        container
                    </NavLink>
                    /
                    {breadcrumbs().map((crumb, index) =>
                        crumb.path ? (
                            <React.Fragment key={index}>
                                <NavLink
                                    to={`/server/${id}/files#${encodePathSegments(crumb.path)}`}
                                    css={tw`px-1 text-neutral-200 no-underline hover:text-neutral-100`}
                                >
                                    {crumb.name}
                                </NavLink>
                                /
                            </React.Fragment>
                        ) : (
                            <span key={index} css={tw`px-1 text-neutral-300`}>
                                {crumb.name}
                            </span>
                        )
                    )}
                    {file && <span css={tw`px-1 text-neutral-300`}>{file}</span>}
                </div>
            </div>
        </div>
    );
};
