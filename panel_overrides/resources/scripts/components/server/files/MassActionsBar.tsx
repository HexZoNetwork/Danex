import React, { useEffect, useState } from 'react';
import tw from 'twin.macro';
import { Button } from '@/components/elements/button/index';
import Fade from '@/components/elements/Fade';
import useFileManagerSwr from '@/plugins/useFileManagerSwr';
import useFlash from '@/plugins/useFlash';
import compressFiles from '@/api/server/files/compressFiles';
import { ServerContext } from '@/state/server';
import deleteFiles from '@/api/server/files/deleteFiles';
import Portal from '@/components/elements/Portal';
import { Dialog } from '@/components/elements/dialog';
import Can from '@/components/elements/Can';
import http from '@/api/http';
import { join } from 'pathe';

const BusySpinner = () => (
    <svg width={'40'} height={'40'} viewBox={'0 0 40 40'} css={tw`mx-auto mb-4`} aria-hidden>
        <circle cx={'20'} cy={'20'} r={'16'} fill={'none'} stroke={'rgba(139, 92, 246, 0.22)'} strokeWidth={'4'} />
        <path d={'M20 4a16 16 0 0 1 16 16'} fill={'none'} stroke={'#a78bfa'} strokeLinecap={'round'} strokeWidth={'4'}>
            <animateTransform attributeName={'transform'} dur={'0.85s'} from={'0 20 20'} repeatCount={'indefinite'} to={'360 20 20'} type={'rotate'} />
        </path>
    </svg>
);

const ActionOverlay = ({ visible, message }: { visible: boolean; message: string }) => {
    if (!visible) return null;

    return (
        <Portal>
            <div
                css={tw`fixed inset-0 z-50 flex items-center justify-center p-4`}
                style={{ background: 'rgba(0, 0, 0, 0.58)', backdropFilter: 'blur(8px)' }}
            >
                <div
                    css={tw`w-full max-w-xs rounded-xl border p-5 text-center shadow-2xl`}
                    style={{ background: '#0b0b10', borderColor: 'rgba(139, 92, 246, 0.34)' }}
                >
                    <BusySpinner />
                    <p css={tw`text-sm font-semibold text-neutral-100`}>{message || 'Working...'}</p>
                </div>
            </div>
        </Portal>
    );
};

const MassActionsBar = () => {
    const uuid = ServerContext.useStoreState((state) => state.server.data!.uuid);
    const directory = ServerContext.useStoreState((state) => state.files.directory);
    const selectedFiles = ServerContext.useStoreState((state) => state.files.selectedFiles);
    const setSelectedFiles = ServerContext.useStoreActions((actions) => actions.files.setSelectedFiles);

    const { mutate } = useFileManagerSwr();
    const { clearFlashes, clearAndAddHttpError } = useFlash();
    const [loading, setLoading] = useState(false);
    const [loadingMessage, setLoadingMessage] = useState('');
    const [showConfirm, setShowConfirm] = useState(false);
    const [showMove, setShowMove] = useState(false);
    const [moveDestination, setMoveDestination] = useState('');
    const [moveError, setMoveError] = useState('');

    useEffect(() => {
        if (!loading) setLoadingMessage('');
    }, [loading]);

    useEffect(() => {
        if (selectedFiles.length === 0) {
            setShowMove(false);
        }
    }, [selectedFiles.length]);

    const onClickCompress = () => {
        setLoading(true);
        clearFlashes('files');
        setLoadingMessage('Archiving selected files...');

        compressFiles(uuid, directory, selectedFiles)
            .then(() => mutate())
            .then(() => setSelectedFiles([]))
            .catch((error) => clearAndAddHttpError({ key: 'files', error }))
            .then(() => setLoading(false));
    };

    const onClickConfirmDeletion = () => {
        setLoading(true);
        setShowConfirm(false);
        clearFlashes('files');
        setLoadingMessage('Deleting selected files...');

        deleteFiles(uuid, directory, selectedFiles)
            .then(() => {
                mutate((files) => files.filter((f) => selectedFiles.indexOf(f.name) < 0), false);
                setSelectedFiles([]);
            })
            .catch((error) => {
                mutate();
                clearAndAddHttpError({ key: 'files', error });
            })
            .then(() => setLoading(false));
    };

    const openMove = () => {
        setMoveDestination('');
        setMoveError('');
        setShowMove(true);
    };

    const closeMove = () => {
        if (loading) return;
        setShowMove(false);
        setMoveDestination('');
        setMoveError('');
    };

    const onClickMove = (event: React.FormEvent) => {
        event.preventDefault();

        const target = moveDestination.trim().replace(/^\/+|\/+$/g, '');
        if (!target) {
            setMoveError('Enter the destination folder.');
            return;
        }

        setLoading(true);
        setShowMove(false);
        setMoveError('');
        clearFlashes('files');
        setLoadingMessage('Moving selected files...');

        http.put(`/api/client/servers/${uuid}/files/rename`, {
            root: directory,
            files: selectedFiles.map((file) => ({ from: file, to: join(target, file) })),
        })
            .then(() => {
                mutate((files) => files.filter((f) => selectedFiles.indexOf(f.name) < 0), false);
                setSelectedFiles([]);
            })
            .catch((error) => {
                mutate();
                clearAndAddHttpError({ key: 'files', error });
            })
            .then(() => {
                setMoveDestination('');
                setLoading(false);
            });
    };

    return (
        <>
            <ActionOverlay visible={loading} message={loadingMessage} />
            <Dialog.Confirm
                title={'Delete Files'}
                open={showConfirm}
                confirm={'Delete'}
                onClose={() => setShowConfirm(false)}
                onConfirmed={onClickConfirmDeletion}
            >
                <p className={'mb-2'}>
                    Are you sure you want to delete&nbsp;
                    <span className={'font-semibold text-gray-50'}>{selectedFiles.length} files</span>? This is a permanent
                    action and the files cannot be recovered.
                </p>
                {selectedFiles.slice(0, 15).map((file) => (
                    <li key={file}>{file}</li>
                ))}
                {selectedFiles.length > 15 && <li>and {selectedFiles.length - 15} others</li>}
            </Dialog.Confirm>
            {showMove && (
                <Portal>
                    <div
                        css={tw`fixed inset-0 z-50 flex items-center justify-center bg-black bg-opacity-70 p-4`}
                        onMouseDown={closeMove}
                    >
                        <form
                            css={tw`w-full max-w-lg rounded-xl border p-5 shadow-2xl`}
                            style={{ background: '#0b0b10', borderColor: 'rgba(139, 92, 246, 0.34)' }}
                            onMouseDown={(event) => event.stopPropagation()}
                            onSubmit={onClickMove}
                        >
                            <h3 css={tw`mb-3 text-lg font-semibold text-neutral-100`}>Move selected files</h3>
                            <p css={tw`mb-4 text-sm text-neutral-400`}>
                                Move {selectedFiles.length} selected {selectedFiles.length === 1 ? 'item' : 'items'} into a folder relative to the current directory.
                            </p>
                            <label css={tw`mb-1 block text-xs uppercase tracking-wide text-neutral-400`} htmlFor={'mass-move-destination'}>
                                Destination folder
                            </label>
                            <input
                                id={'mass-move-destination'}
                                value={moveDestination}
                                autoFocus
                                placeholder={'folder/subfolder'}
                                css={tw`w-full rounded border px-3 py-2 text-sm text-neutral-100 focus:outline-none`}
                                style={{ background: '#111117', borderColor: 'rgba(139, 92, 246, 0.28)' }}
                                onChange={(event) => setMoveDestination(event.currentTarget.value)}
                            />
                            <p css={tw`mt-2 text-xs text-neutral-500`}>
                                Example: entering <span css={tw`text-neutral-300`}>backup</span> moves each selected item into <span css={tw`text-neutral-300`}>backup/</span>.
                            </p>
                            {!!moveError && <p css={tw`mt-3 text-sm text-red-300`}>{moveError}</p>}
                            <div css={tw`mt-5 flex justify-end gap-2`}>
                                <button type={'button'} css={tw`rounded px-3 py-2 text-sm text-neutral-300 hover:text-white`} onClick={closeMove}>
                                    Cancel
                                </button>
                                <button type={'submit'} css={tw`rounded px-3 py-2 text-sm font-semibold text-white`} style={{ background: '#8b5cf6' }}>
                                    Move
                                </button>
                            </div>
                        </form>
                    </div>
                </Portal>
            )}
            <Portal>
                <div className={'pointer-events-none fixed bottom-0 mb-6 flex justify-center w-full z-50'}>
                    <Fade timeout={75} in={selectedFiles.length > 0} unmountOnExit>
                        <div
                            css={tw`pointer-events-auto flex items-center space-x-3 rounded-xl border p-3 shadow-2xl`}
                            style={{ background: 'rgba(11, 11, 16, 0.92)', borderColor: 'rgba(139, 92, 246, 0.28)', backdropFilter: 'blur(10px)' }}
                        >
                            <Can action={'file.update'}>
                                <Button disabled={loading} onClick={openMove}>Move</Button>
                            </Can>
                            <Can action={'file.archive'}>
                                <Button disabled={loading} onClick={onClickCompress}>Archive</Button>
                            </Can>
                            <Can action={'file.delete'}>
                                <Button.Danger disabled={loading} variant={Button.Variants.Secondary} onClick={() => setShowConfirm(true)}>
                                    Delete
                                </Button.Danger>
                            </Can>
                        </div>
                    </Fade>
                </div>
            </Portal>
        </>
    );
};

export default MassActionsBar;
