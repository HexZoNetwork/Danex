import React, { useCallback, useEffect, useRef, useState } from 'react';
import type { ITerminalOptions, Terminal } from 'xterm';
import { ScrollDownHelperAddon } from '@/plugins/XtermScrollDownHelperAddon';
import Spinner from '@/components/elements/Spinner';
import { ServerContext } from '@/state/server';
import { usePermissions } from '@/plugins/usePermissions';
import { theme as th } from 'twin.macro';
import useEventListener from '@/plugins/useEventListener';
import { debounce } from 'debounce';
import { usePersistedState } from '@/plugins/usePersistedState';
import { SocketEvent, SocketRequest } from '@/components/server/events';
import classNames from 'classnames';
import { ChevronDoubleRightIcon } from '@heroicons/react/solid';

import styles from './style.module.css';

const theme = {
    background: 'transparent',
    cursor: 'transparent',
    black: th`colors.black`.toString(),
    red: '#E54B4B',
    green: '#9ECE58',
    yellow: '#FAED70',
    blue: '#396FE2',
    magenta: '#BB80B3',
    cyan: '#2DDAFD',
    white: '#d0d0d0',
    brightBlack: 'rgba(255, 255, 255, 0.2)',
    brightRed: '#FF5370',
    brightGreen: '#C3E88D',
    brightYellow: '#FFCB6B',
    brightBlue: '#82AAFF',
    brightMagenta: '#C792EA',
    brightCyan: '#89DDFF',
    brightWhite: '#ffffff',
    selection: '#FAF089',
};

type TerminalRuntime = {
    terminal: Terminal;
    fitAddon: { fit: () => void };
    searchBar: { show: () => void; hidden: () => void; addNewStyle: (style: string) => void };
};

const terminalProps: ITerminalOptions = {
    disableStdin: true,
    cursorStyle: 'underline',
    allowTransparency: true,
    fontSize: 12,
    fontFamily: th('fontFamily.mono'),
    rows: 30,
    theme: theme,
};

const ANSI_ESCAPE = String.fromCharCode(27);
const ANSI_RESET = `${ANSI_ESCAPE}[0m`;
const TERMINAL_PRELUDE = `${ANSI_ESCAPE}[1m${ANSI_ESCAPE}[33mcontainer@pterodactyl~ ${ANSI_RESET}`;
const TERMINAL_ERROR = `${ANSI_ESCAPE}[1m${ANSI_ESCAPE}[41m`;

const writeFormattedLine = (terminal: Terminal, line: string, prelude = false, error = false) => {
    terminal.writeln(
        (prelude ? TERMINAL_PRELUDE : '') +
            (error ? TERMINAL_ERROR : '') +
            line.replace(/(?:\r\n|\r|\n)$/im, '') +
            ANSI_RESET
    );
};

export default () => {
    const ref = useRef<HTMLDivElement>(null);
    const mountedRef = useRef(true);
    const connectedRef = useRef(false);
    const runtimeRef = useRef<TerminalRuntime>();
    const pendingOutputRef = useRef<Array<{ line: string; prelude?: boolean; error?: boolean }>>([]);
    const [runtime, setRuntime] = useState<TerminalRuntime>();
    const [terminalError, setTerminalError] = useState('');
    const { connected, instance } = ServerContext.useStoreState((state) => state.socket);
    const [canSendCommands] = usePermissions(['control.console']);
    const serverId = ServerContext.useStoreState((state) => state.server.data!.id);
    const isTransferring = ServerContext.useStoreState((state) => state.server.data!.isTransferring);
    const [history, setHistory] = usePersistedState<string[]>(`${serverId}:command_history`, []);
    const [historyIndex, setHistoryIndex] = useState(-1);
    // SearchBarAddon has hardcoded z-index: 999 :(
    const zIndex = `
    .xterm-search-bar__addon {
        z-index: 10;
    }`;

    useEffect(() => {
        connectedRef.current = connected;
    }, [connected]);

    useEffect(
        () => () => {
            mountedRef.current = false;
            runtimeRef.current?.terminal.dispose();
        },
        []
    );

    const writeConsoleLine = useCallback((line: string, prelude = false, error = false) => {
        const terminal = runtimeRef.current?.terminal;
        if (!terminal) {
            pendingOutputRef.current.push({ line, prelude, error });
            return;
        }

        writeFormattedLine(terminal, line, prelude, error);
    }, []);

    const flushPendingOutput = (terminal: Terminal) => {
        const pending = pendingOutputRef.current.splice(0);
        pending.forEach(({ line, prelude, error }) => writeFormattedLine(terminal, line, prelude, error));
    };

    const handleConsoleOutput = (line: string, prelude = false) => writeConsoleLine(line, prelude);

    const handleTransferStatus = (status: string) => {
        switch (status) {
            // Sent by either the source or target node if a failure occurs.
            case 'failure':
                writeConsoleLine('Transfer has failed.', true);
                return;
        }
    };

    const handleDaemonErrorOutput = (line: string) => writeConsoleLine(line, true, true);

    const handlePowerChangeEvent = (state: string) => writeConsoleLine('Server marked as ' + state + '...', true);

    const handleCommandKeyDown = (e: React.KeyboardEvent<HTMLInputElement>) => {
        if (e.key === 'ArrowUp') {
            const newIndex = Math.min(historyIndex + 1, history!.length - 1);

            setHistoryIndex(newIndex);
            e.currentTarget.value = history![newIndex] || '';

            // By default up arrow will also bring the cursor to the start of the line,
            // so we'll preventDefault to keep it at the end.
            e.preventDefault();
        }

        if (e.key === 'ArrowDown') {
            const newIndex = Math.max(historyIndex - 1, -1);

            setHistoryIndex(newIndex);
            e.currentTarget.value = history![newIndex] || '';
        }

        const command = e.currentTarget.value;
        if (e.key === 'Enter' && command.length > 0) {
            setHistory((prevHistory) => [command, ...prevHistory!].slice(0, 32));
            setHistoryIndex(-1);

            instance && instance.send('send command', command);
            e.currentTarget.value = '';
        }
    };

    const bootTerminal = useCallback(async () => {
        if (!connectedRef.current || !ref.current || runtimeRef.current?.terminal.element) return;
        setTerminalError('');

        try {
            const [{ Terminal }, { FitAddon }, { SearchAddon }, { SearchBarAddon }, { WebLinksAddon }, { Unicode11Addon }] = await Promise.all([
                import(/* webpackChunkName: "terminal-tools" */ 'xterm'),
                import(/* webpackChunkName: "terminal-tools" */ 'xterm-addon-fit'),
                import(/* webpackChunkName: "terminal-tools" */ 'xterm-addon-search'),
                import(/* webpackChunkName: "terminal-tools" */ 'xterm-addon-search-bar'),
                import(/* webpackChunkName: "terminal-tools" */ 'xterm-addon-web-links'),
                import(/* webpackChunkName: "terminal-tools" */ 'xterm-addon-unicode11'),
                import(/* webpackChunkName: "terminal-tools" */ 'xterm/css/xterm.css'),
            ]);

            if (!mountedRef.current || !connectedRef.current || !ref.current || runtimeRef.current?.terminal.element) return;

            const terminal = new Terminal({ ...terminalProps });
            const fitAddon = new FitAddon();
            const searchAddon = new SearchAddon();
            const searchBar = new SearchBarAddon({ searchAddon });
            terminal.loadAddon(fitAddon);
            terminal.loadAddon(searchAddon);
            terminal.loadAddon(searchBar);
            terminal.loadAddon(new WebLinksAddon());
            terminal.loadAddon(new Unicode11Addon());
            terminal.loadAddon(new ScrollDownHelperAddon());
            terminal.open(ref.current);
            terminal.unicode.activeVersion = '11';
            fitAddon.fit();
            searchBar.addNewStyle(zIndex);
            terminal.attachCustomKeyEventHandler((e: KeyboardEvent) => {
                if ((e.ctrlKey || e.metaKey) && e.key === 'c') {
                    document.execCommand('copy');
                    return false;
                } else if ((e.ctrlKey || e.metaKey) && e.key === 'f') {
                    e.preventDefault();
                    searchBar.show();
                    return false;
                } else if (e.key === 'Escape') {
                    searchBar.hidden();
                }
                return true;
            });

            const nextRuntime = { terminal, fitAddon, searchBar };
            runtimeRef.current = nextRuntime;
            setRuntime(nextRuntime);
            flushPendingOutput(terminal);
        } catch {
            if (mountedRef.current) {
                setTerminalError('Console failed to load. Check the network connection and try again.');
            }
        }
    }, []);

    useEffect(() => {
        void bootTerminal();
    }, [bootTerminal, connected]);

    useEventListener(
        'resize',
        debounce(() => {
            if (runtimeRef.current?.terminal.element) {
                runtimeRef.current.fitAddon.fit();
            }
        }, 100)
    );

    useEffect(() => {
        const listeners: Record<string, (s: string) => void> = {
            [SocketEvent.STATUS]: handlePowerChangeEvent,
            [SocketEvent.CONSOLE_OUTPUT]: handleConsoleOutput,
            [SocketEvent.INSTALL_OUTPUT]: handleConsoleOutput,
            [SocketEvent.TRANSFER_LOGS]: handleConsoleOutput,
            [SocketEvent.TRANSFER_STATUS]: handleTransferStatus,
            [SocketEvent.DAEMON_MESSAGE]: (line) => handleConsoleOutput(line, true),
            [SocketEvent.DAEMON_ERROR]: handleDaemonErrorOutput,
        };

        if (connected && instance) {
            if (!isTransferring) {
                runtimeRef.current?.terminal.clear();
                pendingOutputRef.current = [];
            }

            Object.keys(listeners).forEach((key: string) => {
                instance.addListener(key, listeners[key]);
            });
            instance.send(SocketRequest.SEND_LOGS);
        }

        return () => {
            if (instance) {
                Object.keys(listeners).forEach((key: string) => {
                    instance.removeListener(key, listeners[key]);
                });
            }
        };
    }, [connected, instance]);

    return (
        <div className={classNames(styles.terminal, 'relative')}>
            {(!connected || (!runtime && !terminalError)) && (
                <div className={'absolute inset-0 z-10 flex items-center justify-center'}>
                    <Spinner size={'large'} />
                </div>
            )}
            {terminalError && (
                <div className={'absolute inset-0 z-20 flex items-center justify-center bg-black bg-opacity-70 p-4'}>
                    <div className={'max-w-md rounded bg-neutral-900 p-4 text-center text-sm text-neutral-200 shadow-lg'}>
                        <p className={'mb-3'}>{terminalError}</p>
                        <button
                            type={'button'}
                            className={'rounded bg-primary-500 px-3 py-1 text-xs font-semibold text-white'}
                            onClick={() => void bootTerminal()}
                        >
                            Retry
                        </button>
                    </div>
                </div>
            )}
            <div
                className={classNames(styles.container, styles.overflows_container, { 'rounded-b': !canSendCommands })}
            >
                <div className={'h-full'}>
                    <div id={styles.terminal} ref={ref} />
                </div>
            </div>
            {canSendCommands && (
                <div className={classNames('relative', styles.overflows_container)}>
                    <input
                        className={classNames('peer', styles.command_input)}
                        type={'text'}
                        placeholder={'Type a command...'}
                        aria-label={'Console command input.'}
                        disabled={!instance || !connected}
                        onKeyDown={handleCommandKeyDown}
                        autoCorrect={'off'}
                        autoCapitalize={'none'}
                    />
                    <div
                        className={classNames(
                            'text-gray-100 peer-focus:text-gray-50 peer-focus:animate-pulse',
                            styles.command_icon
                        )}
                    >
                        <ChevronDoubleRightIcon className={'w-4 h-4'} />
                    </div>
                </div>
            )}
        </div>
    );
};
