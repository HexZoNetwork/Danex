import React, { memo, useEffect, useRef, useState } from 'react';
import { createPortal } from 'react-dom';
import { FontAwesomeIcon } from '@fortawesome/react-fontawesome';
import {
    faBoxOpen,
    faCopy,
    faEllipsisH,
    faFileArchive,
    faFileDownload,
    faLevelUpAlt,
    faPencilAlt,
    faTrashAlt,
} from '@fortawesome/free-solid-svg-icons';
import { FileObject } from '@/api/server/files/loadDirectory';
import { ServerContext } from '@/state/server';
import http from '@/api/http';
import useFileManagerSwr from '@/plugins/useFileManagerSwr';
import useFlash from '@/plugins/useFlash';
import { join } from 'pathe';
import tw from 'twin.macro';
import Can from '@/components/elements/Can';
import isEqual from 'react-fast-compare';

const menuStyle: React.CSSProperties = {
    position: 'fixed',
    zIndex: 9999,
    width: '12rem',
    background: '#0b0b10',
    border: '1px solid rgba(139, 92, 246, 0.28)',
    borderRadius: '0.5rem',
    padding: '0.5rem',
    boxShadow: '0 18px 48px rgba(0, 0, 0, 0.55), 0 0 22px rgba(139, 92, 246, 0.12)',
};

const rowStyle = (danger = false): React.CSSProperties => ({
    display: 'flex',
    alignItems: 'center',
    width: '100%',
    gap: '0.5rem',
    padding: '0.5rem',
    borderRadius: '0.375rem',
    color: danger ? '#fecaca' : '#d4d4df',
    background: 'transparent',
    border: 0,
    cursor: 'pointer',
    textAlign: 'left',
});

type MenuPoint = { x: number; y: number };
type EditMode = 'rename' | 'move';

const BusySpinner = () => (
    <svg width={'40'} height={'40'} viewBox={'0 0 40 40'} css={tw`mx-auto mb-4`} aria-hidden>
        <circle cx={'20'} cy={'20'} r={'16'} fill={'none'} stroke={'rgba(139, 92, 246, 0.22)'} strokeWidth={'4'} />
        <path d={'M20 4a16 16 0 0 1 16 16'} fill={'none'} stroke={'#a78bfa'} strokeLinecap={'round'} strokeWidth={'4'}>
            <animateTransform attributeName={'transform'} dur={'0.85s'} from={'0 20 20'} repeatCount={'indefinite'} to={'360 20 20'} type={'rotate'} />
        </path>
    </svg>
);

const clampMenuToViewport = (point: MenuPoint, menu?: HTMLDivElement | null): MenuPoint => {
    const width = menu?.offsetWidth || 192;
    const height = menu?.offsetHeight || 320;

    return {
        x: Math.min(Math.max(8, point.x), Math.max(8, window.innerWidth - width - 8)),
        y: Math.min(Math.max(8, point.y), Math.max(8, window.innerHeight - height - 8)),
    };
};

const FileDropdownMenu = ({ file }: { file: FileObject }) => {
    const buttonRef = useRef<HTMLButtonElement | null>(null);
    const menuRef = useRef<HTMLDivElement | null>(null);
    const inputRef = useRef<HTMLInputElement | null>(null);
    const [openAt, setOpenAt] = useState<MenuPoint | null>(null);
    const [editMode, setEditMode] = useState<EditMode | null>(null);
    const [editValue, setEditValue] = useState(file.name);
    const [busyLabel, setBusyLabel] = useState<string | null>(null);

    const uuid = ServerContext.useStoreState((state) => state.server.data!.uuid);
    const directory = ServerContext.useStoreState((state) => state.files.directory);
    const { mutate } = useFileManagerSwr();
    const { clearAndAddHttpError, clearFlashes } = useFlash();

    const close = () => setOpenAt(null);

    const openFromButton = (event: React.MouseEvent) => {
        event.preventDefault();
        event.stopPropagation();
        const rect = buttonRef.current?.getBoundingClientRect();
        const x = rect ? rect.left : event.clientX;
        const y = rect ? rect.bottom + 6 : event.clientY;
        setOpenAt(clampMenuToViewport({ x, y }, menuRef.current));
    };

    useEffect(() => {
        const onContext = (event: Event) => {
            const custom = event as CustomEvent<MenuPoint>;
            if (!custom.detail) return;
            setOpenAt(clampMenuToViewport(custom.detail, menuRef.current));
        };
        window.addEventListener(`pterodactyl:files:ctx:${file.key}`, onContext as EventListener);
        return () => window.removeEventListener(`pterodactyl:files:ctx:${file.key}`, onContext as EventListener);
    }, [file.key]);

    useEffect(() => {
        if (!openAt) return;
        const onPointerDown = (event: MouseEvent) => {
            if (menuRef.current?.contains(event.target as Node) || buttonRef.current?.contains(event.target as Node)) return;
            close();
        };
        const onKeyDown = (event: KeyboardEvent) => {
            if (event.key === 'Escape') close();
        };
        window.addEventListener('mousedown', onPointerDown);
        window.addEventListener('keydown', onKeyDown);
        return () => {
            window.removeEventListener('mousedown', onPointerDown);
            window.removeEventListener('keydown', onKeyDown);
        };
    }, [openAt]);

    useEffect(() => {
        if (editMode) {
            window.setTimeout(() => inputRef.current?.focus(), 0);
        }
    }, [editMode]);

    const apiPath = `/api/client/servers/${uuid}/files`;
    const filePath = join(directory, file.name);

    const run = (label: string, callback: () => Promise<unknown>) => {
        setBusyLabel(label);
        clearFlashes('files');
        close();
        callback()
            .then(() => mutate())
            .catch((error) => clearAndAddHttpError({ key: 'files', error }))
            .finally(() => setBusyLabel(null));
    };

    const download = () => {
        setBusyLabel('Preparing download...');
        clearFlashes('files');
        close();
        http.get(`${apiPath}/download`, { params: { file: filePath } })
            .then(({ data }) => {
                const url = data?.attributes?.url;
                if (typeof url === 'string' && url.length > 0) {
                    window.location.href = url;
                    return;
                }
                throw new Error('Download URL is unavailable.');
            })
            .catch((error) => clearAndAddHttpError({ key: 'files', error }))
            .finally(() => window.setTimeout(() => setBusyLabel(null), 350));
    };

    const openEditModal = (mode: EditMode) => {
        close();
        setEditValue(file.name);
        setEditMode(mode);
    };

    const submitEdit = (event: React.FormEvent) => {
        event.preventDefault();
        const next = editValue.trim();
        if (!editMode || !next || next === file.name) {
            setEditMode(null);
            return;
        }

        const mode = editMode;
        setEditMode(null);
        run(mode === 'move' ? 'Moving file...' : 'Renaming file...', () =>
            http.put(`${apiPath}/rename`, { root: directory, files: [{ from: file.name, to: next }] })
        );
    };

    const copy = () => run('Copying file...', () => http.post(`${apiPath}/copy`, { location: filePath }));
    const archive = () => run('Creating archive...', () => http.post(`${apiPath}/compress`, { root: directory, files: [file.name] }));
    const unarchive = () => run('Extracting archive...', () => http.post(`${apiPath}/decompress`, { root: directory, file: file.name }));
    const remove = () => {
        if (!window.confirm(`Delete ${file.name}? This cannot be undone.`)) return;
        run('Deleting file...', () => http.post(`${apiPath}/delete`, { root: directory, files: [file.name] }));
    };

    const Row = ({ icon, label, onClick, danger = false }: { icon: any; label: string; onClick: () => void; danger?: boolean }) => (
        <button
            type={'button'}
            disabled={Boolean(busyLabel)}
            style={{ ...rowStyle(danger), opacity: busyLabel ? 0.55 : 1 }}
            onClick={(event) => {
                event.preventDefault();
                event.stopPropagation();
                if (!busyLabel) onClick();
            }}
            onMouseEnter={(event) => {
                event.currentTarget.style.background = danger ? 'rgba(239, 68, 68, 0.14)' : 'rgba(139, 92, 246, 0.12)';
                event.currentTarget.style.color = '#ffffff';
            }}
            onMouseLeave={(event) => {
                event.currentTarget.style.background = 'transparent';
                event.currentTarget.style.color = danger ? '#fecaca' : '#d4d4df';
            }}
        >
            <FontAwesomeIcon icon={icon} fixedWidth />
            <span>{label}</span>
        </button>
    );

    const editTitle = editMode === 'move' ? 'Move file or folder' : 'Rename file or folder';
    const editLabel = editMode === 'move' ? 'New path' : 'New name';

    return (
        <>
            <button
                ref={buttonRef}
                type={'button'}
                aria-label={`Open actions for ${file.name}`}
                css={tw`px-4 py-2 text-neutral-400 hover:text-white focus:outline-none`}
                onClick={openFromButton}
                onMouseDown={(event) => event.stopPropagation()}
            >
                <FontAwesomeIcon icon={faEllipsisH} />
            </button>
            {openAt &&
                createPortal(
                    <div
                        ref={menuRef}
                        style={{ ...menuStyle, left: openAt.x, top: openAt.y }}
                        onContextMenu={(event) => event.preventDefault()}
                        onMouseDown={(event) => event.stopPropagation()}
                        onClick={(event) => event.stopPropagation()}
                    >
                        {busyLabel && <div css={tw`px-2 pb-1 text-[11px] text-neutral-500`}>{busyLabel}</div>}
                        <Can action={'file.update'}>
                            <Row icon={faPencilAlt} label={'Rename'} onClick={() => openEditModal('rename')} />
                            <Row icon={faLevelUpAlt} label={'Move'} onClick={() => openEditModal('move')} />
                        </Can>
                        {file.isFile && (
                            <Can action={'file.create'}>
                                <Row icon={faCopy} label={'Copy'} onClick={copy} />
                            </Can>
                        )}
                        {file.isArchiveType() ? (
                            <Can action={'file.archive'}>
                                <Row icon={faBoxOpen} label={'Unarchive'} onClick={unarchive} />
                            </Can>
                        ) : (
                            <Can action={'file.archive'}>
                                <Row icon={faFileArchive} label={'Archive'} onClick={archive} />
                            </Can>
                        )}
                        {file.isFile && (
                            <Can action={'file.read-content'}>
                                <Row icon={faFileDownload} label={'Download'} onClick={download} />
                            </Can>
                        )}
                        <Can action={'file.delete'}>
                            <Row icon={faTrashAlt} label={'Delete'} onClick={remove} danger />
                        </Can>
                    </div>,
                    document.body
                )}
            {editMode &&
                createPortal(
                    <div
                        css={tw`fixed inset-0 z-50 flex items-center justify-center bg-black bg-opacity-70 p-4`}
                        onMouseDown={() => setEditMode(null)}
                    >
                        <form
                            css={tw`w-full max-w-lg rounded-lg border p-4 shadow-2xl`}
                            style={{ background: '#0b0b10', borderColor: 'rgba(139, 92, 246, 0.32)' }}
                            onMouseDown={(event) => event.stopPropagation()}
                            onSubmit={submitEdit}
                        >
                            <h3 css={tw`mb-3 text-lg font-semibold text-neutral-100`}>{editTitle}</h3>
                            <label css={tw`mb-1 block text-xs uppercase tracking-wide text-neutral-400`} htmlFor={`file-action-${file.key}`}>
                                {editLabel}
                            </label>
                            <input
                                ref={inputRef}
                                id={`file-action-${file.key}`}
                                value={editValue}
                                onChange={(event) => setEditValue(event.currentTarget.value)}
                                css={tw`w-full rounded border px-3 py-2 text-sm text-neutral-100 focus:outline-none`}
                                style={{ background: '#111117', borderColor: 'rgba(139, 92, 246, 0.28)' }}
                            />
                            {editMode === 'move' && (
                                <p css={tw`mt-2 text-xs text-neutral-500`}>
                                    Enter a path relative to the current directory, for example <span css={tw`text-neutral-300`}>folder/{file.name}</span>.
                                </p>
                            )}
                            <div css={tw`mt-4 flex justify-end gap-2`}>
                                <button type={'button'} css={tw`rounded px-3 py-2 text-sm text-neutral-300 hover:text-white`} onClick={() => setEditMode(null)}>
                                    Cancel
                                </button>
                                <button type={'submit'} css={tw`rounded px-3 py-2 text-sm font-semibold text-white`} style={{ background: '#8b5cf6' }}>
                                    {editMode === 'move' ? 'Move' : 'Rename'}
                                </button>
                            </div>
                        </form>
                    </div>,
                    document.body
                )}
            {busyLabel &&
                createPortal(
                    <div css={tw`fixed inset-0 z-50 flex items-center justify-center p-4`} style={{ background: 'rgba(0, 0, 0, 0.58)', backdropFilter: 'blur(8px)' }}>
                        <div css={tw`w-full max-w-xs rounded-xl border p-5 text-center shadow-2xl`} style={{ background: '#0b0b10', borderColor: 'rgba(139, 92, 246, 0.34)' }}>
                            <BusySpinner />
                            <p css={tw`text-sm font-semibold text-neutral-100`}>{busyLabel}</p>
                            <p css={tw`mt-1 text-xs text-neutral-500 truncate`}>{file.name}</p>
                        </div>
                    </div>,
                    document.body
                )}
        </>
    );
};

export default memo(FileDropdownMenu, isEqual);
