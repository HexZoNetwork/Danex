import React, { useCallback, useEffect, useRef, useState } from 'react';
import * as eslint from 'eslint-linter-browserify';
import getFileContents from '@/api/server/files/getFileContents';
import http, { httpErrorToHuman } from '@/api/http';
import SpinnerOverlay from '@/components/elements/SpinnerOverlay';
import saveFileContents from '@/api/server/files/saveFileContents';
import FileManagerBreadcrumbs from '@/components/server/files/FileManagerBreadcrumbs';
import { useHistory, useLocation, useParams } from 'react-router';
import FileNameModal from '@/components/server/files/FileNameModal';
import Can from '@/components/elements/Can';
import FlashMessageRender from '@/components/FlashMessageRender';
import PageContentBlock from '@/components/elements/PageContentBlock';
import { ServerError } from '@/components/elements/ScreenBlock';
import tw from 'twin.macro';
import styled from 'styled-components/macro';
import Button from '@/components/elements/Button';
import Select from '@/components/elements/Select';
import modes from '@/modes';
import useFlash from '@/plugins/useFlash';
import { ServerContext } from '@/state/server';
import ErrorBoundary from '@/components/elements/ErrorBoundary';
import { encodePathSegments, hashToPath } from '@/helpers';
import { basename, dirname, join } from 'pathe';
import CodemirrorEditor from '@/components/elements/CodemirrorEditor';
import { FontAwesomeIcon } from '@fortawesome/react-fontawesome';
import {
    faChevronDown,
    faChevronRight,
    faEllipsisH,
    faFileAlt,
    faExclamationTriangle,
    faFolder,
    faFolderOpen,
    faTerminal,
    faTimes,
} from '@fortawesome/free-solid-svg-icons';
import Console from '@/components/server/console/Console';
import PowerButtons from '@/components/server/console/PowerButtons';

type QuickFile = {
    name: string;
    isFile: boolean;
};

type FileCacheEntry = {
    content?: string;
    promise?: Promise<string>;
    size: number;
};

type BubblePanel = 'files' | 'console';
type BottomDockPanel = 'problems' | BubblePanel;
type ResizeTarget = 'files' | 'console' | null;
type BubblePosition = { x: number; y: number };
type BubbleDrag = { panel: BubblePanel; offsetX: number; offsetY: number; startX: number; startY: number; moved: boolean };
type DirectoryFiles = Record<string, QuickFile[]>;
type LoadingMap = Record<string, boolean>;
type OpenMap = Record<string, boolean>;
type ProblemSeverity = 'error' | 'warning' | 'info';
type Problem = { file: string; line: number; severity: ProblemSeverity; message: string };
const clamp = (value: number, min: number, max: number) => Math.min(Math.max(value, min), max);
const isMarkdownMode = (file: string, mode: string) => /(?:^|\.)(md|markdown|mdown|mkdn)$/i.test(file) || mode === 'text/markdown';

const MAX_FILE_CACHE_ENTRIES = 24;
const MAX_FILE_CACHE_BYTES = 4 * 1024 * 1024;
const fileContentCache = new Map<string, FileCacheEntry>();
const fileCacheKey = (uuid: string, path: string) => `${uuid}:${path}`;

const pruneFileContentCache = () => {
    let totalBytes = 0;
    fileContentCache.forEach((entry) => {
        totalBytes += entry.size;
    });

    while (fileContentCache.size > MAX_FILE_CACHE_ENTRIES || totalBytes > MAX_FILE_CACHE_BYTES) {
        const oldestKey = fileContentCache.keys().next().value;
        if (!oldestKey) return;
        totalBytes -= fileContentCache.get(oldestKey)?.size || 0;
        fileContentCache.delete(oldestKey);
    }
};

const rememberFileContent = (uuid: string, path: string, content: string) => {
    const key = fileCacheKey(uuid, path);
    fileContentCache.delete(key);
    fileContentCache.set(key, { content, size: content.length });
    pruneFileContentCache();
};

const preloadFileContent = (uuid: string, path: string) => {
    const key = fileCacheKey(uuid, path);
    const cached = fileContentCache.get(key);

    if (cached?.content !== undefined) {
        fileContentCache.delete(key);
        fileContentCache.set(key, cached);
        return Promise.resolve(cached.content);
    }

    if (cached?.promise) {
        return cached.promise;
    }

    const promise = getFileContents(uuid, path)
        .then((content) => {
            rememberFileContent(uuid, path, content);
            return content;
        })
        .catch((error) => {
            fileContentCache.delete(key);
            throw error;
        });

    fileContentCache.set(key, { promise, size: 0 });
    return promise;
};

const getCachedFileContent = (uuid: string, path: string) => fileContentCache.get(fileCacheKey(uuid, path))?.content;
const setCachedFileContent = (uuid: string, path: string, content: string) => rememberFileContent(uuid, path, content);
const deleteCachedFileContent = (uuid: string, path: string) => fileContentCache.delete(fileCacheKey(uuid, path));

const lineFromJsonError = (content: string, message: string) => {
    const position = message.match(/position (\d+)/i)?.[1];
    if (!position) return 1;

    return content.slice(0, Number(position)).split('\n').length;
};

const isJavaScriptMode = (file: string, mode: string) =>
    /\.(?:js|cjs|mjs)$/i.test(file) || mode === 'application/javascript' || mode === 'text/javascript';

const eslintLinter = new eslint.Linter();
const eslintGlobals = [
    'Array', 'ArrayBuffer', 'Atomics', 'BigInt', 'BigInt64Array', 'BigUint64Array', 'Boolean', 'Buffer',
    'DataView', 'Date', 'Error', 'EvalError', 'Float32Array', 'Float64Array', 'Function', 'Infinity', 'Int16Array',
    'Int32Array', 'Int8Array', 'Intl', 'JSON', 'Map', 'Math', 'NaN', 'Number', 'Object', 'Promise', 'Proxy',
    'RangeError', 'Reflect', 'RegExp', 'Set', 'String', 'Symbol', 'SyntaxError', 'TypeError', 'URIError',
    'Uint16Array', 'Uint32Array', 'Uint8Array', 'Uint8ClampedArray', 'WeakMap', 'WeakSet', 'WebAssembly',
    'clearInterval', 'clearTimeout', 'console', 'document', 'exports', 'fetch', 'global', 'globalThis', 'localStorage',
    'module', 'navigator', 'process', 'require', 'sessionStorage', 'setInterval', 'setTimeout', 'URL', 'URLSearchParams',
    'window', 'parseFloat', 'parseInt', 'decodeURI', 'decodeURIComponent', 'encodeURI', 'encodeURIComponent',
    'isFinite', 'isNaN',
].reduce<Record<string, 'readonly'>>((globals, name) => ({ ...globals, [name]: 'readonly' }), {});

const javascriptSourceType = (file: string) => /\.cjs$/i.test(file) ? 'commonjs' : 'module';

const eslintConfigForFile = (file: string) => ({
    languageOptions: {
        ecmaVersion: 'latest' as const,
        sourceType: javascriptSourceType(file) as 'module' | 'commonjs',
        globals: eslintGlobals,
        parserOptions: {
            ecmaFeatures: {
                jsx: true,
            },
        },
    },
    rules: {
        'constructor-super': 'error',
        'for-direction': 'error',
        'getter-return': 'error',
        'no-async-promise-executor': 'error',
        'no-class-assign': 'error',
        'no-compare-neg-zero': 'error',
        'no-cond-assign': 'error',
        'no-const-assign': 'error',
        'no-constant-binary-expression': 'error',
        'no-constant-condition': 'error',
        'no-control-regex': 'error',
        'no-debugger': 'warn',
        'no-dupe-args': 'error',
        'no-dupe-class-members': 'error',
        'no-dupe-else-if': 'error',
        'no-dupe-keys': 'error',
        'no-duplicate-case': 'error',
        'no-empty-character-class': 'error',
        'no-empty-pattern': 'error',
        'no-ex-assign': 'error',
        'no-extra-boolean-cast': 'error',
        'no-fallthrough': 'error',
        'no-func-assign': 'error',
        'no-import-assign': 'error',
        'no-inner-declarations': 'error',
        'no-invalid-regexp': 'error',
        'no-irregular-whitespace': 'error',
        'no-loss-of-precision': 'error',
        'no-misleading-character-class': 'error',
        'no-new-native-nonconstructor': 'error',
        'no-obj-calls': 'error',
        'no-prototype-builtins': 'error',
        'no-redeclare': 'error',
        'no-regex-spaces': 'error',
        'no-self-assign': 'error',
        'no-setter-return': 'error',
        'no-shadow-restricted-names': 'error',
        'no-sparse-arrays': 'error',
        'no-this-before-super': 'error',
        'no-undef': 'error',
        'no-unexpected-multiline': 'error',
        'no-unreachable': 'error',
        'no-unsafe-finally': 'error',
        'no-unsafe-negation': 'error',
        'no-unsafe-optional-chaining': 'error',
        'no-unused-labels': 'error',
        'no-useless-backreference': 'error',
        'require-yield': 'error',
        'use-isnan': 'error',
        'valid-typeof': 'error',
    },
});

const parseJavaScriptProblems = (file: string, content: string): Problem[] => {
    try {
        return eslintLinter.verify(content, eslintConfigForFile(file), { filename: file })
            .filter((message) => message.severity > 0)
            .map((message) => ({
                file,
                line: message.line || 1,
                severity: message.severity === 2 ? 'error' : 'warning',
                message: message.ruleId ? `${message.ruleId}: ${message.message}` : message.message,
            }));
    } catch (error) {
        return [{
            file,
            line: 1,
            severity: 'error',
            message: error instanceof Error ? error.message : 'ESLint failed to parse this JavaScript file',
        }];
    }
};

const parseSyntaxProblems = (file: string, content: string, mode: string): Problem[] => {
    if (!file || isMarkdownMode(file, mode)) return [];

    const trimmed = content.trim();
    if (!trimmed) return [];

    if (mode === 'application/json' || /\.(json|mcmeta|lock)$/i.test(file)) {
        try {
            JSON.parse(trimmed);
            return [];
        } catch (error) {
            return [{
                file,
                line: lineFromJsonError(content, error instanceof Error ? error.message : ''),
                severity: 'error',
                message: error instanceof Error ? error.message : 'Invalid JSON syntax',
            }];
        }
    }

    if (isJavaScriptMode(file, mode)) {
        return parseJavaScriptProblems(file, content);
    }

    return [];
};

const nudgeLayoutEngines = () => {
    if (typeof window === 'undefined') return;
    window.requestAnimationFrame(() => window.dispatchEvent(new Event('resize')));
};

const DockTabButton = styled.button<{ active?: boolean }>`
    position: relative;
    display: inline-flex;
    height: 3rem;
    width: 3rem;
    align-items: center;
    justify-content: center;
    border: 1px solid ${({ active }) => active ? 'rgba(167, 139, 250, 0.68)' : 'rgba(139, 92, 246, 0.34)'};
    border-radius: 9999px;
    padding: 0;
    background: ${({ active }) => active
        ? 'radial-gradient(circle at 35% 25%, rgba(196, 181, 253, 0.42), rgba(139, 92, 246, 0.24) 42%, rgba(9, 9, 14, 0.98) 74%)'
        : 'radial-gradient(circle at 35% 25%, rgba(167, 139, 250, 0.28), rgba(24, 24, 32, 0.96) 48%, rgba(7, 7, 11, 0.98) 78%)'};
    color: ${({ active }) => active ? '#f5f3ff' : '#ddd6fe'};
    box-shadow: ${({ active }) => active ? '0 0 26px rgba(139, 92, 246, 0.32)' : '0 12px 28px rgba(0, 0, 0, 0.45)'};
    cursor: grab;
    transition: transform 140ms ease, border-color 140ms ease, background 140ms ease, box-shadow 140ms ease;

    &:hover {
        transform: translateX(-5px) scale(1.04);
        border-color: rgba(167, 139, 250, 0.72);
        box-shadow: 0 0 28px rgba(139, 92, 246, 0.34), 0 12px 28px rgba(0, 0, 0, 0.45);
    }

    &:active { cursor: grabbing; }
    &:focus { outline: none; }

    .dock-tab-icon {
        display: inline-flex;
        height: 1.65rem;
        width: 1.65rem;
        align-items: center;
        justify-content: center;
        border-radius: 9999px;
        background: rgba(139, 92, 246, 0.18);
        color: #ede9fe;
    }
`;

const DockTabGlyph = ({ icon }: { icon: any }) => (
    <span className={'dock-tab-icon'}><FontAwesomeIcon icon={icon} fixedWidth /></span>
);

const NavPill = ({ active, children, onClick }: { active: boolean; children: React.ReactNode; onClick: () => void }) => (
    <button
        type={'button'}
        css={tw`relative rounded-md px-3 py-1 text-2xs font-semibold uppercase tracking-widest transition-colors duration-150 focus:outline-none`}
        style={{
            background: active ? 'rgba(139, 92, 246, 0.22)' : 'rgba(11, 11, 16, 0.82)',
            border: `1px solid ${active ? 'rgba(167, 139, 250, 0.58)' : 'rgba(139, 92, 246, 0.18)'}`,
            color: active ? '#f4f4f5' : '#a3a3b2',
            boxShadow: active ? 'inset 0 -2px 0 rgba(167, 139, 250, 0.72), 0 0 18px rgba(139, 92, 246, 0.16)' : 'none',
        }}
        onClick={onClick}
    >
        {children}
    </button>
);

const Panel = ({
    title,
    children,
    actions,
    compact = false,
}: {
    title: string;
    children: React.ReactNode;
    actions?: React.ReactNode;
    compact?: boolean;
}) => (
    <div
        css={tw`flex min-h-0 flex-col overflow-hidden border transition-all duration-150 ease-out`}
        style={{
            height: compact ? '100%' : 'calc(100vh - 15.5rem)',
            maxHeight: compact ? undefined : '42rem',
            background: 'linear-gradient(145deg, rgba(17, 17, 24, 0.98), rgba(7, 7, 11, 0.98))',
            borderColor: 'rgba(139, 92, 246, 0.24)',
            boxShadow: 'inset 0 1px 0 rgba(255,255,255,.045)',
        }}
    >
        <div css={tw`flex items-center justify-between border-b px-3 py-2`} style={{ borderColor: 'rgba(139, 92, 246, 0.18)', background: 'rgba(11, 11, 16, 0.92)' }}>
            <p css={tw`text-2xs font-semibold uppercase tracking-widest`} style={{ color: '#c4b5fd' }}>{title}</p>
            {actions || <span css={tw`h-1.5 w-1.5 rounded-full`} style={{ background: '#8b5cf6', boxShadow: '0 0 14px rgba(139,92,246,.8)' }} />}
        </div>
        {children}
    </div>
);

const sortQuickFiles = (files: QuickFile[]) =>
    [...files]
        .sort((a, b) => a.name.localeCompare(b.name))
        .sort((a, b) => (a.isFile === b.isFile ? 0 : a.isFile ? 1 : -1));

const QuickExplorer = ({
    uuid,
    serverId,
    currentPath,
    onOpenSplit,
    actions,
}: {
    uuid: string;
    serverId: string;
    currentPath: string;
    onOpenSplit: (path: string) => void;
    actions?: React.ReactNode;
}) => {
    const history = useHistory();
    const [rootDirectory, setRootDirectory] = useState(dirname(currentPath || '/'));
    const [directoryFiles, setDirectoryFiles] = useState<DirectoryFiles>({});
    const [loading, setLoading] = useState<LoadingMap>({});
    const [open, setOpen] = useState<OpenMap>({});
    const [error, setError] = useState('');
    const [reloadKey, setReloadKey] = useState(0);

    const loadDirectory = (path: string) => {
        setLoading((state) => ({ ...state, [path]: true }));
        setError('');

        return http.get(`/api/client/servers/${uuid}/files/list`, { params: { directory: path } })
            .then(({ data }) => {
                const rows = (data?.data || []) as Array<{ attributes?: any }>;
                const files = rows.map((row) => {
                    const attributes = row.attributes || row;
                    return {
                        name: String(attributes.name || ''),
                        isFile: String(attributes.mode || '').startsWith('-') || attributes.is_file === true || attributes.isFile === true,
                    };
                }).filter((file) => file.name.length > 0);

                setDirectoryFiles((state) => ({ ...state, [path]: files }));
            })
            .catch((error) => setError(httpErrorToHuman(error)))
            .then(() => setLoading((state) => ({ ...state, [path]: false })));
    };

    useEffect(() => {
        const nextRoot = dirname(currentPath || '/');
        setRootDirectory(nextRoot);
        setOpen((state) => ({ ...state, [nextRoot]: true }));
        loadDirectory(nextRoot);
    }, [currentPath, reloadKey]);

    useEffect(() => {
        setOpen((state) => ({ ...state, [rootDirectory]: true }));
        if (!directoryFiles[rootDirectory] && !loading[rootDirectory]) {
            loadDirectory(rootDirectory);
        }
    }, [rootDirectory]);

    const currentName = currentPath.split('/').filter(Boolean).pop();
    const fullPath = (directory: string, name: string) => join(directory, name);
    const openFile = (path: string) => history.push(`/server/${serverId}/files/edit#/${encodePathSegments(path)}`);

    const toggleDirectory = (path: string) => {
        const next = !open[path];
        setOpen((state) => ({ ...state, [path]: next }));
        if (next && !directoryFiles[path]) {
            loadDirectory(path);
        }
    };

    const renamePath = (path: string, isFile: boolean) => {
        const name = basename(path);
        const next = window.prompt(`Rename ${isFile ? 'file' : 'folder'}`, name)?.trim();
        if (!next || next === name || next.includes('/')) return;

        const root = dirname(path);
        http.put(`/api/client/servers/${uuid}/files/rename`, { root, files: [{ from: name, to: next }] })
            .then(() => {
                if (isFile) {
                    deleteCachedFileContent(uuid, path);
                    if (path === currentPath) {
                        openFile(join(root, next));
                    }
                }
                setReloadKey((key) => key + 1);
                loadDirectory(root);
            })
            .catch((error) => setError(httpErrorToHuman(error)));
    };

    const moveFileToFolder = (fromPath: string, folderPath: string) => {
        if (!fromPath || fromPath === folderPath || dirname(fromPath) === folderPath) return;

        const root = dirname(fromPath);
        const name = basename(fromPath);
        const target = join(folderPath, name);

        http.put(`/api/client/servers/${uuid}/files/rename`, { root, files: [{ from: name, to: target }] })
            .then(() => {
                deleteCachedFileContent(uuid, fromPath);
                loadDirectory(root);
                loadDirectory(folderPath);
                if (fromPath === currentPath) {
                    openFile(target);
                }
            })
            .catch((error) => setError(httpErrorToHuman(error)));
    };

    const renderDirectory = (directory: string, depth = 0): React.ReactNode => {
        const rows = sortQuickFiles(directoryFiles[directory] || []);
        const indent = depth * 12;

        return (
            <div key={directory}>
                {loading[directory] && <p css={tw`px-3 py-2 text-xs text-neutral-500`}>Loading folder...</p>}
                {rows.slice(0, 160).map((file, index) => {
                    const path = fullPath(directory, file.name);
                    const isCurrent = file.isFile && file.name === currentName && dirname(currentPath) === directory;
                    const isOpen = !!open[path];

                    return (
                        <div key={`${path}_${index}`}>
                            <div
                                className={'group'}
                                draggable={file.isFile}
                                onDragStart={(event) => {
                                    if (!file.isFile) return;
                                    event.dataTransfer.setData('text/plain', path);
                                    event.dataTransfer.effectAllowed = 'copyMove';
                                }}
                                onDragOver={(event) => {
                                    if (!file.isFile) {
                                        event.preventDefault();
                                        event.dataTransfer.dropEffect = 'move';
                                    }
                                }}
                                onDrop={(event) => {
                                    if (file.isFile) return;
                                    event.preventDefault();
                                    moveFileToFolder(event.dataTransfer.getData('text/plain'), path);
                                }}
                                css={tw`flex items-center gap-1 rounded-full px-2 py-1.5 text-xs transition-all duration-200 hover:translate-x-1 hover:bg-neutral-900 hover:text-neutral-100`}
                                style={{
                                    paddingLeft: `${10 + indent}px`,
                                    color: isCurrent ? '#ddd6fe' : '#a3a3af',
                                    background: isCurrent ? 'rgba(139, 92, 246, 0.16)' : 'rgba(255, 255, 255, 0.015)',
                                }}
                            >
                                <button
                                    type={'button'}
                                    css={tw`flex min-w-0 flex-1 items-center gap-2 text-left focus:outline-none`}
                                    onDoubleClick={() => file.isFile && onOpenSplit(path)}
                                    onClick={() => file.isFile ? openFile(path) : toggleDirectory(path)}
                                >
                                    <span css={tw`w-4 flex-shrink-0 text-neutral-600`}>
                                        {file.isFile ? '├─' : <FontAwesomeIcon icon={isOpen ? faChevronDown : faChevronRight} fixedWidth />}
                                    </span>
                                    <FontAwesomeIcon icon={file.isFile ? faFileAlt : isOpen ? faFolderOpen : faFolder} fixedWidth />
                                    <span css={tw`truncate`}>{file.name}</span>
                                </button>
                                <button
                                    type={'button'}
                                    css={tw`flex-shrink-0 rounded-full px-2 py-1 text-neutral-500 opacity-0 transition-all duration-150 hover:bg-black hover:bg-opacity-30 hover:text-neutral-100 group-hover:opacity-100 focus:opacity-100 focus:outline-none`}
                                    onClick={(event) => {
                                        event.preventDefault();
                                        event.stopPropagation();
                                        renamePath(path, file.isFile);
                                    }}
                                    title={'Rename'}
                                >
                                    <FontAwesomeIcon icon={faEllipsisH} />
                                </button>
                            </div>
                            {!file.isFile && isOpen && renderDirectory(path, depth + 1)}
                        </div>
                    );
                })}
                {!loading[directory] && rows.length === 0 && <p css={tw`px-3 py-2 text-xs text-neutral-500`}>Folder kosong.</p>}
            </div>
        );
    };

    return (
        <Panel title={'Explorer'} actions={actions} compact>
            <button
                type={'button'}
                css={tw`mx-3 mt-3 rounded-full border px-3 py-2 text-left text-2xs text-neutral-400 transition hover:text-neutral-100`}
                style={{ background: '#07070b', borderColor: 'rgba(139, 92, 246, 0.18)' }}
                onClick={() => toggleDirectory(rootDirectory)}
            >
                /home/container{rootDirectory === '/' ? '' : rootDirectory}
            </button>
            <div css={tw`min-h-0 flex-1 overflow-y-auto p-3`}>
                {!!error && <p css={tw`mb-2 rounded-2xl px-3 py-2 text-xs text-red-300`} style={{ background: 'rgba(239, 68, 68, 0.1)' }}>{error}</p>}
                {rootDirectory !== '/' && (
                    <button
                        type={'button'}
                        css={tw`mb-1 flex w-full items-center gap-2 rounded-full px-3 py-2 text-left text-xs text-neutral-400 transition-all duration-200 hover:bg-neutral-900 hover:text-neutral-100`}
                        onClick={() => setRootDirectory(dirname(rootDirectory))}
                    >
                        <span css={tw`text-neutral-600`}>└</span>
                        <FontAwesomeIcon icon={faFolderOpen} fixedWidth />
                        <span>..</span>
                    </button>
                )}
                {renderDirectory(rootDirectory)}
            </div>
        </Panel>
    );
};

const QuickConsole = ({ actions }: { actions?: React.ReactNode }) => (
    <Panel title={'Console'} actions={actions} compact>
        <div css={tw`min-h-0 flex-1 overflow-hidden p-3 pb-2`}>
            <div css={tw`h-full overflow-hidden rounded-lg`}>
                <Console />
            </div>
        </div>
        <PowerButtons className={'grid grid-cols-3 gap-2 border-t p-3'} />
    </Panel>
);

const ProblemList = ({ problems, onProblemClick }: { problems: Problem[]; onProblemClick: (problem: Problem) => void }) => (
    <div css={tw`min-h-0 flex-1 overflow-y-auto px-2 py-2`}>
        {problems.length === 0 ? (
            <div css={tw`flex h-full flex-col items-center justify-center text-center text-sm text-neutral-500`}>
                <FontAwesomeIcon icon={faExclamationTriangle} css={tw`mb-3 text-2xl`} style={{ color: '#34d399' }} />
                <p css={tw`font-semibold text-neutral-300`}>No Problems</p>
                <p css={tw`mt-1 max-w-md text-xs`}>Syntax issues from the currently open code file will appear here.</p>
            </div>
        ) : (
            <div css={tw`space-y-1`}>
                {problems.map((problem, index) => (
                    <button
                        key={`${problem.file}:${problem.line}:${index}`}
                        type={'button'}
                        css={tw`flex w-full items-start gap-3 rounded-2xl px-3 py-2 text-left text-xs transition-all duration-150 hover:bg-neutral-900 hover:text-neutral-100 focus:outline-none`}
                        style={{ color: problem.severity === 'error' ? '#fecaca' : problem.severity === 'warning' ? '#fde68a' : '#bfdbfe' }}
                        onClick={() => onProblemClick(problem)}
                    >
                        <span css={tw`mt-0.5 h-2 w-2 flex-shrink-0 rounded-full`} style={{ background: problem.severity === 'error' ? '#ef4444' : problem.severity === 'warning' ? '#f59e0b' : '#60a5fa' }} />
                        <span css={tw`min-w-0 flex-1`}>
                            <span css={tw`block truncate font-semibold text-neutral-200`}>{problem.file}</span>
                            <span css={tw`block text-neutral-400`}>Line {problem.line} · {problem.message}</span>
                        </span>
                    </button>
                ))}
            </div>
        )}
    </div>
);

export default () => {
    const [error, setError] = useState('');
    const { action } = useParams<{ action: 'new' | string }>();
    const [loading, setLoading] = useState(action === 'edit');
    const [content, setContent] = useState('');
    const [modalVisible, setModalVisible] = useState(false);
    const [mode, setMode] = useState('text/plain');
    const [splitPath, setSplitPath] = useState('');
    const [splitContent, setSplitContent] = useState('');
    const [splitLoading, setSplitLoading] = useState(false);
    const [splitMode, setSplitMode] = useState('text/plain');
    const [minimizedPanes, setMinimizedPanes] = useState<Record<BubblePanel, boolean>>({ files: false, console: false });
    const [dockedPanes, setDockedPanes] = useState<Record<BubblePanel, boolean>>({ files: false, console: false });
    const [activeDockPanel, setActiveDockPanel] = useState<BottomDockPanel>('problems');
    const [filePaneWidth, setFilePaneWidth] = useState(280);
    const [consolePaneWidth, setConsolePaneWidth] = useState(360);
    const [resizeTarget, setResizeTarget] = useState<ResizeTarget>(null);
    const [mainJumpLine, setMainJumpLine] = useState<number>();
    const [splitJumpLine, setSplitJumpLine] = useState<number>();
    const [isDesktop, setIsDesktop] = useState(() => typeof window !== 'undefined' && window.innerWidth >= 1024);
    const [bubblePositions, setBubblePositions] = useState<Record<BubblePanel, BubblePosition>>(() => {
        const width = typeof window === 'undefined' ? 1280 : window.innerWidth;
        const height = typeof window === 'undefined' ? 720 : window.innerHeight;

        return {
            files: { x: width - 76, y: Math.round(height / 2) - 44 },
            console: { x: width - 76, y: Math.round(height / 2) + 12 },
        };
    });
    const [bubbleDrag, setBubbleDrag] = useState<BubbleDrag | null>(null);

    const history = useHistory();
    const { hash } = useLocation();

    const id = ServerContext.useStoreState((state) => state.server.data!.id);
    const uuid = ServerContext.useStoreState((state) => state.server.data!.uuid);
    const setDirectory = ServerContext.useStoreActions((actions) => actions.files.setDirectory);
    const { addError, clearFlashes } = useFlash();
    const fetchFileContent = useRef<null | (() => Promise<string>)>(null);
    const fetchSplitContent = useRef<null | (() => Promise<string>)>(null);
    const bubbleLayerRef = useRef<HTMLDivElement>(null);
    const bottomDockRef = useRef<HTMLDivElement>(null);
    const suppressBubbleClick = useRef(false);
    const currentPath = hashToPath(hash);
    const problems = parseSyntaxProblems(currentPath || 'New file', content, mode);
    const bindFileContent = useCallback((value: () => Promise<string>) => {
        fetchFileContent.current = value;
    }, []);
    const bindSplitContent = useCallback((value: () => Promise<string>) => {
        fetchSplitContent.current = value;
    }, []);

    useEffect(() => {
        const onResize = () => {
            const desktop = window.innerWidth >= 1024;
            setIsDesktop(desktop);
        };

        onResize();
        window.addEventListener('resize', onResize);
        return () => window.removeEventListener('resize', onResize);
    }, []);

    useEffect(() => {
        if (!resizeTarget) return;

        const onMouseMove = (event: MouseEvent) => {
            if (resizeTarget === 'files') {
                setFilePaneWidth(clamp(event.clientX - 34, 220, 440));
                nudgeLayoutEngines();
                return;
            }

            setConsolePaneWidth(clamp(window.innerWidth - event.clientX - 66, 260, 520));
            nudgeLayoutEngines();
        };

        const onMouseUp = () => {
            setResizeTarget(null);
            nudgeLayoutEngines();
        };

        window.addEventListener('mousemove', onMouseMove);
        window.addEventListener('mouseup', onMouseUp);
        return () => {
            window.removeEventListener('mousemove', onMouseMove);
            window.removeEventListener('mouseup', onMouseUp);
        };
    }, [resizeTarget]);

    useEffect(() => {
        setError('');
        const path = hashToPath(hash);

        if (action === 'new') {
            setDirectory(path || '/');
            setContent('');
            setMode('text/plain');
            setLoading(false);
            return;
        }

        setDirectory(dirname(path));
        const cached = getCachedFileContent(uuid, path);

        if (cached !== undefined) {
            setContent(cached);
            setLoading(false);
            return;
        }

        setLoading(true);
        preloadFileContent(uuid, path)
            .then(setContent)
            .catch((error) => {
                console.error(error);
                setError(httpErrorToHuman(error));
            })
            .then(() => setLoading(false));
    }, [action, uuid, hash]);

    const openSplitEditor = (path: string) => {
        if (!path || path === currentPath) return;

        setSplitPath(path);
        const cached = getCachedFileContent(uuid, path);
        if (cached !== undefined) {
            setSplitContent(cached);
            setSplitLoading(false);
            return;
        }

        setSplitLoading(true);
        preloadFileContent(uuid, path)
            .then(setSplitContent)
            .catch((error) => addError({ message: httpErrorToHuman(error), key: 'files:view' }))
            .then(() => setSplitLoading(false));
    };

    const save = (name?: string) => {
        const fetchContent = fetchFileContent.current;
        if (!fetchContent) {
            return;
        }

        setLoading(true);
        clearFlashes('files:view');
        fetchContent()
            .then((content) => {
                const path = name || hashToPath(hash);
                return saveFileContents(uuid, path, content).then(() => ({ content, path }));
            })
            .then(({ content, path }) => {
                setCachedFileContent(uuid, path, content);
                setContent(content);
                if (name) {
                    history.push(`/server/${id}/files/edit#/${encodePathSegments(name)}`);
                    return;
                }

                return Promise.resolve();
            })
            .catch((error) => {
                console.error(error);
                addError({ message: httpErrorToHuman(error), key: 'files:view' });
            })
            .then(() => setLoading(false));
    };

    const saveSplit = () => {
        const fetchContent = fetchSplitContent.current;
        if (!fetchContent || !splitPath) return;

        setSplitLoading(true);
        clearFlashes('files:view');
        fetchContent()
            .then((content) => saveFileContents(uuid, splitPath, content).then(() => ({ content, path: splitPath })))
            .then(({ content, path }) => {
                setCachedFileContent(uuid, path, content);
                setSplitContent(content);
            })
            .catch((error) => addError({ message: httpErrorToHuman(error), key: 'files:view' }))
            .then(() => setSplitLoading(false));
    };

    const jumpToProblem = (problem: Problem) => {
        if (problem.file === splitPath) {
            setSplitJumpLine(undefined);
            window.setTimeout(() => setSplitJumpLine(problem.line), 0);
            return;
        }

        setMainJumpLine(undefined);
        window.setTimeout(() => setMainJumpLine(problem.line), 0);
    };

    const minimizePane = (target: BubblePanel) => {
        setMinimizedPanes((state) => ({ ...state, [target]: true }));
        setDockedPanes((state) => ({ ...state, [target]: false }));
        setActiveDockPanel((panel) => (panel === target ? 'problems' : panel));
        nudgeLayoutEngines();
    };

    const dockPane = (target: BubblePanel) => {
        setDockedPanes((state) => ({ ...state, [target]: true }));
        setActiveDockPanel(target);
        nudgeLayoutEngines();
    };

    const restorePane = (target: BubblePanel) => {
        setMinimizedPanes((state) => ({ ...state, [target]: false }));
        setDockedPanes((state) => ({ ...state, [target]: false }));
        setActiveDockPanel((panel) => (panel === target ? 'problems' : panel));
        nudgeLayoutEngines();
    };

    useEffect(() => {
        if (!bubbleDrag) return;

        const onMouseMove = (event: MouseEvent) => {
            const layerRect = bubbleLayerRef.current?.getBoundingClientRect();
            const layerLeft = layerRect?.left || 0;
            const layerTop = layerRect?.top || 0;
            const layerWidth = layerRect?.width || window.innerWidth;
            const layerHeight = layerRect?.height || window.innerHeight;
            const nextX = clamp(event.clientX - layerLeft - bubbleDrag.offsetX, 12, layerWidth - 60);
            const nextY = clamp(event.clientY - layerTop - bubbleDrag.offsetY, 12, layerHeight - 60);
            const moved = bubbleDrag.moved || Math.abs(event.clientX - bubbleDrag.startX) > 4 || Math.abs(event.clientY - bubbleDrag.startY) > 4;

            setBubbleDrag((drag) => drag ? { ...drag, moved } : drag);
            setBubblePositions((positions) => ({ ...positions, [bubbleDrag.panel]: { x: nextX, y: nextY } }));
        };

        const onMouseUp = (event: MouseEvent) => {
            const dockRect = bottomDockRef.current?.getBoundingClientRect();
            const shouldDock = !!dockRect && event.clientX >= dockRect.left && event.clientX <= dockRect.right && event.clientY >= dockRect.top && event.clientY <= dockRect.bottom;

            if (bubbleDrag.moved || Math.abs(event.clientX - bubbleDrag.startX) > 4 || Math.abs(event.clientY - bubbleDrag.startY) > 4) {
                suppressBubbleClick.current = true;
                window.setTimeout(() => {
                    suppressBubbleClick.current = false;
                }, 0);
            }

            if (shouldDock) {
                dockPane(bubbleDrag.panel);
            }

            setBubbleDrag(null);
        };

        window.addEventListener('mousemove', onMouseMove);
        window.addEventListener('mouseup', onMouseUp);
        return () => {
            window.removeEventListener('mousemove', onMouseMove);
            window.removeEventListener('mouseup', onMouseUp);
        };
    }, [bubbleDrag]);

    const startBubbleDrag = (panel: BubblePanel, event: React.MouseEvent<HTMLButtonElement>) => {
        const rect = event.currentTarget.getBoundingClientRect();

        event.preventDefault();
        setBubbleDrag({
            panel,
            offsetX: event.clientX - rect.left,
            offsetY: event.clientY - rect.top,
            startX: event.clientX,
            startY: event.clientY,
            moved: false,
        });
    };

    const panelActions = (target: BubblePanel) => (
        <div css={tw`flex items-center gap-2`}>
            {isDesktop && (
                <button
                    type={'button'}
                    css={tw`relative rounded-full px-2 py-1 text-2xs font-bold uppercase tracking-widest transition hover:bg-primary-800 hover:bg-opacity-20 focus:outline-none`}
                    style={{ color: '#c4b5fd' }}
                    onClick={() => minimizePane(target)}
                >
                    Dock
                </button>
            )}
            <span css={tw`h-2 w-2 rounded-full`} style={{ background: 'rgba(167, 139, 250, 0.82)', boxShadow: '0 0 16px rgba(167, 139, 250, 0.82)' }} />
        </div>
    );

    if (error) {
        return <ServerError message={error} onBack={() => history.goBack()} />;
    }

    return (
        <PageContentBlock>
            <FlashMessageRender byKey={'files:view'} css={tw`mb-4`} />
            <ErrorBoundary>
                <div css={tw`mb-4 lg:hidden`}>
                    <FileManagerBreadcrumbs withinFileEditor isNewFile={action !== 'edit'} />
                </div>
            </ErrorBoundary>
            {hash.replace(/^#/, '').endsWith('.pteroignore') && (
                <div css={tw`mb-4 p-4 border-l-4 bg-neutral-900 rounded border-cyan-400`}>
                    <p css={tw`text-neutral-300 text-sm`}>
                        You&apos;re editing a <code css={tw`font-mono bg-black rounded py-px px-1`}>.pteroignore</code>{' '}
                        file. Any files or directories listed in here will be excluded from backups. Wildcards are
                        supported by using an asterisk (<code css={tw`font-mono bg-black rounded py-px px-1`}>*</code>).
                        You can negate a prior rule by prepending an exclamation point (
                        <code css={tw`font-mono bg-black rounded py-px px-1`}>!</code>).
                    </p>
                </div>
            )}
            <FileNameModal
                visible={modalVisible}
                onDismissed={() => setModalVisible(false)}
                onFileNamed={(name) => {
                    setModalVisible(false);
                    save(name);
                }}
            />
            {!isDesktop ? (
                <>
                    <div css={tw`relative`}>
                        <SpinnerOverlay visible={loading} fixed size={'large'} />
                        <CodemirrorEditor
                            mode={mode}
                            filename={hash.replace(/^#/, '')}
                            onModeChanged={setMode}
                            initialContent={content}
                            fetchContent={bindFileContent}
                            onContentSaved={() => {
                                if (action !== 'edit') {
                                    setModalVisible(true);
                                } else {
                                    save();
                                }
                            }}
                        />
                    </div>
                    <div css={tw`flex flex-col gap-3 mt-4 md:flex-row md:justify-end`}>
                        <div css={tw`flex-1 rounded bg-neutral-900 overflow-hidden md:flex-none`}>
                            <Select value={mode} onChange={(e) => setMode(e.currentTarget.value)}>
                                {modes.map((mode) => (
                                    <option key={`${mode.name}_${mode.mime}`} value={mode.mime}>
                                        {mode.name}
                                    </option>
                                ))}
                            </Select>
                        </div>
                        {action === 'edit' ? (
                            <Can action={'file.update'}>
                                <Button css={tw`flex-1 sm:flex-none`} onClick={() => save()}>
                                    Save Content
                                </Button>
                            </Can>
                        ) : (
                            <Can action={'file.create'}>
                                <Button css={tw`flex-1 sm:flex-none`} onClick={() => setModalVisible(true)}>
                                    Create File
                                </Button>
                            </Can>
                        )}
                    </div>
                </>
            ) : (
                <div css={tw`relative min-h-0`}>
                    {isDesktop && (
                        <div ref={bubbleLayerRef} css={tw`pointer-events-none fixed inset-0 z-20 hidden lg:block`}>
                            {(['files', 'console'] as BubblePanel[])
                                .filter((panel) => minimizedPanes[panel] && !dockedPanes[panel])
                                .map((panel) => (
                                    <DockTabButton
                                        key={panel}
                                        type={'button'}
                                        active={bubbleDrag?.panel === panel}
                                        title={`${panel === 'files' ? 'Files' : 'Console'} — click to restore, drag onto Problems to dock`}
                                        style={{
                                            position: 'absolute',
                                            left: `${bubblePositions[panel].x}px`,
                                            top: `${bubblePositions[panel].y}px`,
                                            pointerEvents: 'auto',
                                            touchAction: 'none',
                                        }}
                                        onMouseDown={(event) => startBubbleDrag(panel, event)}
                                        onClick={() => {
                                            if (suppressBubbleClick.current) return;
                                            restorePane(panel);
                                        }}
                                    >
                                        <DockTabGlyph icon={panel === 'files' ? faFolderOpen : faTerminal} />
                                    </DockTabButton>
                                ))}
                        </div>
                    )}

                    <div
                        css={tw`min-w-0 overflow-hidden rounded-xl border`}
                        style={{ background: 'var(--el7-surface)', borderColor: 'var(--el7-border-soft)' }}
                    >
                        <div css={tw`flex flex-wrap items-center gap-2 border-b px-3 py-2`} style={{ borderColor: 'var(--el7-border-soft)', background: 'var(--el7-surface-strong)' }}>
                            <NavPill active={!minimizedPanes.files} onClick={() => restorePane('files')}>
                                Files{dockedPanes.files ? ' · docked' : minimizedPanes.files ? ' · minimized' : ''}
                            </NavPill>
                            <NavPill active onClick={() => undefined}>Text</NavPill>
                            <NavPill active={!minimizedPanes.console} onClick={() => restorePane('console')}>
                                Console{dockedPanes.console ? ' · docked' : minimizedPanes.console ? ' · minimized' : ''}
                            </NavPill>
                            <span css={tw`ml-auto hidden text-2xs uppercase tracking-widest text-neutral-500 md:block`}>
                                Top panes resize horizontally · docked panes expand below
                            </span>
                        </div>

                        <div
                            css={tw`min-h-0 p-3`}
                            style={{
                                display: 'grid',
                                gridTemplateColumns: [
                                    !minimizedPanes.files ? `${filePaneWidth}px` : null,
                                    !minimizedPanes.files ? '10px' : null,
                                    'minmax(420px, 1fr)',
                                    !minimizedPanes.console ? '10px' : null,
                                    !minimizedPanes.console ? `${consolePaneWidth}px` : null,
                                ]
                                    .filter(Boolean)
                                    .join(' '),
                                gap: 0,
                                alignItems: 'stretch',
                            }}
                        >
                            {!minimizedPanes.files && (
                                <div css={tw`min-h-0 min-w-0`}>
                                    <QuickExplorer
                                        uuid={uuid}
                                        serverId={id}
                                        currentPath={currentPath}
                                        onOpenSplit={openSplitEditor}
                                        actions={panelActions('files')}
                                    />
                                </div>
                            )}
                            {!minimizedPanes.files && (
                                <button
                                    type={'button'}
                                    aria-label={'Resize file pane'}
                                    css={tw`mx-1 h-full rounded-full focus:outline-none`}
                                    style={{ cursor: 'col-resize', background: resizeTarget === 'files' ? 'rgba(167, 139, 250, 0.42)' : 'rgba(139, 92, 246, 0.12)' }}
                                    onMouseDown={(event) => {
                                        event.preventDefault();
                                        setResizeTarget('files');
                                    }}
                                />
                            )}

                            <Panel
                                title={'Text'}
                                actions={(
                                    <div css={tw`flex items-center gap-2`}>
                                        <div css={tw`w-40 overflow-hidden rounded-md`}>
                                            <Select value={mode} onChange={(e) => setMode(e.currentTarget.value)}>
                                                {modes.map((mode) => (
                                                    <option key={`${mode.name}_${mode.mime}`} value={mode.mime}>
                                                        {mode.name}
                                                    </option>
                                                ))}
                                            </Select>
                                        </div>
                                        {splitPath && (
                                            <Button css={tw`rounded-full px-3 py-1 text-xs`} onClick={saveSplit}>
                                                Save Split
                                            </Button>
                                        )}
                                        {action === 'edit' ? (
                                            <Can action={'file.update'}>
                                                <Button css={tw`rounded-full px-3 py-1 text-xs`} onClick={() => save()}>
                                                    Save Content
                                                </Button>
                                            </Can>
                                        ) : (
                                            <Can action={'file.create'}>
                                                <Button css={tw`rounded-full px-3 py-1 text-xs`} onClick={() => setModalVisible(true)}>
                                                    Create File
                                                </Button>
                                            </Can>
                                        )}
                                    </div>
                                )}
                                compact
                            >
                                <div css={tw`relative min-h-0 flex-1 overflow-hidden`}>
                                    <SpinnerOverlay visible={loading} fixed size={'large'} />
                                    <CodemirrorEditor
                                        style={{ height: 'calc(100vh - 31rem)', minHeight: '24rem', maxHeight: '44rem' }}
                                        mode={mode}
                                        filename={hash.replace(/^#/, '')}
                                        jumpToLine={mainJumpLine}
                                        onModeChanged={setMode}
                                        initialContent={content}
                                        onContentChanged={setContent}
                                        fetchContent={bindFileContent}
                                        onContentSaved={() => {
                                            if (action !== 'edit') {
                                                setModalVisible(true);
                                            } else {
                                                save();
                                            }
                                        }}
                                    />
                                </div>
                                {splitPath && (
                                    <div css={tw`border-t p-3`} style={{ borderColor: 'var(--el7-border-soft)' }}>
                                        <div css={tw`mb-2 flex items-center justify-between rounded-full border px-3 py-2 text-xs text-neutral-300`} style={{ background: '#07070b', borderColor: 'rgba(139, 92, 246, 0.2)' }}>
                                            <span css={tw`truncate`}>{splitPath}</span>
                                            <button type={'button'} css={tw`ml-2 text-neutral-500 hover:text-neutral-100 focus:outline-none`} onClick={() => setSplitPath('')}>
                                                <FontAwesomeIcon icon={faTimes} />
                                            </button>
                                        </div>
                                        <div css={tw`relative overflow-hidden rounded-lg`}>
                                            <SpinnerOverlay visible={splitLoading} fixed size={'large'} />
                                            <CodemirrorEditor
                                                style={{ height: '18rem', minHeight: '16rem' }}
                                                mode={splitMode}
                                                filename={splitPath}
                                                jumpToLine={splitJumpLine}
                                                onModeChanged={setSplitMode}
                                                initialContent={splitContent}
                                                onContentChanged={setSplitContent}
                                                fetchContent={bindSplitContent}
                                                onContentSaved={saveSplit}
                                            />
                                        </div>
                                    </div>
                                )}
                            </Panel>

                            {!minimizedPanes.console && (
                                <button
                                    type={'button'}
                                    aria-label={'Resize console pane'}
                                    css={tw`mx-1 h-full rounded-full focus:outline-none`}
                                    style={{ cursor: 'col-resize', background: resizeTarget === 'console' ? 'rgba(167, 139, 250, 0.42)' : 'rgba(139, 92, 246, 0.12)' }}
                                    onMouseDown={(event) => {
                                        event.preventDefault();
                                        setResizeTarget('console');
                                    }}
                                />
                            )}
                            {!minimizedPanes.console && (
                                <div css={tw`min-h-0 min-w-0`}>
                                    <QuickConsole actions={panelActions('console')} />
                                </div>
                            )}
                        </div>

                        <div
                            ref={bottomDockRef}
                            css={tw`mx-3 mb-3 flex min-h-0 flex-col overflow-hidden rounded-xl border transition-all duration-200`}
                            style={{
                                height: activeDockPanel === 'console' && dockedPanes.console ? 'min(36rem, calc(100vh - 18rem))' : activeDockPanel === 'files' && dockedPanes.files ? '28rem' : '16rem',
                                borderColor: bubbleDrag ? 'rgba(167, 139, 250, 0.52)' : 'var(--el7-border-soft)',
                                background: '#07070b',
                                boxShadow: bubbleDrag ? '0 0 24px rgba(139, 92, 246, 0.18)' : undefined,
                            }}
                        >
                            <div css={tw`flex items-center gap-2 border-b px-3 py-2`} style={{ borderColor: 'var(--el7-border-soft)', background: 'var(--el7-surface-strong)' }}>
                                <NavPill active={activeDockPanel === 'problems'} onClick={() => setActiveDockPanel('problems')}>
                                    Problems <span css={tw`ml-1 text-neutral-500`}>{problems.length}</span>
                                </NavPill>
                                {dockedPanes.files && (
                                    <NavPill active={activeDockPanel === 'files'} onClick={() => setActiveDockPanel('files')}>
                                        Files dock
                                    </NavPill>
                                )}
                                {dockedPanes.console && (
                                    <NavPill active={activeDockPanel === 'console'} onClick={() => setActiveDockPanel('console')}>
                                        Console dock
                                    </NavPill>
                                )}
                                <span css={tw`ml-auto text-2xs uppercase tracking-widest text-neutral-600`}>
                                    Problems scans open code only
                                </span>
                            </div>
                            <div css={tw`min-h-0 flex-1 overflow-hidden`}>
                                {activeDockPanel === 'files' && dockedPanes.files ? (
                                    <QuickExplorer
                                        uuid={uuid}
                                        serverId={id}
                                        currentPath={currentPath}
                                        onOpenSplit={openSplitEditor}
                                        actions={panelActions('files')}
                                    />
                                ) : activeDockPanel === 'console' && dockedPanes.console ? (
                                    <QuickConsole actions={panelActions('console')} />
                                ) : (
                                    <ProblemList problems={problems} onProblemClick={jumpToProblem} />
                                )}
                            </div>
                        </div>
                    </div>
                </div>
            )}
        </PageContentBlock>
    );
};
