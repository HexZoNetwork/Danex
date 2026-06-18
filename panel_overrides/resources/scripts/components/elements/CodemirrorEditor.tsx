import React, { useCallback, useEffect, useRef, useState } from 'react';
import CodeMirror from 'codemirror';
import styled from 'styled-components/macro';
import tw from 'twin.macro';
import modes from '@/modes';

require('codemirror/lib/codemirror.css');
require('codemirror/theme/ayu-mirage.css');
require('codemirror/addon/edit/closebrackets');
require('codemirror/addon/edit/closetag');
require('codemirror/addon/edit/matchbrackets');
require('codemirror/addon/edit/matchtags');
require('codemirror/addon/edit/trailingspace');
require('codemirror/addon/fold/foldcode');
require('codemirror/addon/fold/foldgutter.css');
require('codemirror/addon/fold/foldgutter');
require('codemirror/addon/fold/brace-fold');
require('codemirror/addon/fold/comment-fold');
require('codemirror/addon/fold/indent-fold');
require('codemirror/addon/fold/markdown-fold');
require('codemirror/addon/fold/xml-fold');
require('codemirror/addon/hint/css-hint');
require('codemirror/addon/hint/html-hint');
require('codemirror/addon/hint/javascript-hint');
require('codemirror/addon/hint/show-hint.css');
require('codemirror/addon/hint/show-hint');
require('codemirror/addon/hint/sql-hint');
require('codemirror/addon/hint/xml-hint');
require('codemirror/addon/mode/simple');
require('codemirror/addon/dialog/dialog.css');
require('codemirror/addon/dialog/dialog');
require('codemirror/addon/scroll/annotatescrollbar');
require('codemirror/addon/scroll/scrollpastend');
require('codemirror/addon/scroll/simplescrollbars.css');
require('codemirror/addon/scroll/simplescrollbars');
require('codemirror/addon/search/jump-to-line');
require('codemirror/addon/search/match-highlighter');
require('codemirror/addon/search/matchesonscrollbar.css');
require('codemirror/addon/search/matchesonscrollbar');
require('codemirror/addon/search/search');
require('codemirror/addon/search/searchcursor');
require('codemirror/addon/selection/mark-selection');

const modeLoaders: Record<string, () => Promise<unknown>> = {
    brainfuck: () => import('codemirror/mode/brainfuck/brainfuck'),
    clike: () => import('codemirror/mode/clike/clike'),
    css: () => import('codemirror/mode/css/css'),
    dart: () => import('codemirror/mode/dart/dart'),
    diff: () => import('codemirror/mode/diff/diff'),
    dockerfile: () => import('codemirror/mode/dockerfile/dockerfile'),
    erlang: () => import('codemirror/mode/erlang/erlang'),
    gfm: () => import('codemirror/mode/gfm/gfm'),
    go: () => import('codemirror/mode/go/go'),
    handlebars: () => import('codemirror/mode/handlebars/handlebars'),
    htmlembedded: () => import('codemirror/mode/htmlembedded/htmlembedded'),
    htmlmixed: () => import('codemirror/mode/htmlmixed/htmlmixed'),
    http: () => import('codemirror/mode/http/http'),
    javascript: () => import('codemirror/mode/javascript/javascript'),
    jsx: () => import('codemirror/mode/jsx/jsx'),
    julia: () => import('codemirror/mode/julia/julia'),
    lua: () => import('codemirror/mode/lua/lua'),
    markdown: () => import('codemirror/mode/markdown/markdown'),
    nginx: () => import('codemirror/mode/nginx/nginx'),
    perl: () => import('codemirror/mode/perl/perl'),
    php: () => import('codemirror/mode/php/php'),
    properties: () => import('codemirror/mode/properties/properties'),
    protobuf: () => import('codemirror/mode/protobuf/protobuf'),
    pug: () => import('codemirror/mode/pug/pug'),
    python: () => import('codemirror/mode/python/python'),
    rpm: () => import('codemirror/mode/rpm/rpm'),
    ruby: () => import('codemirror/mode/ruby/ruby'),
    rust: () => import('codemirror/mode/rust/rust'),
    sass: () => import('codemirror/mode/sass/sass'),
    shell: () => import('codemirror/mode/shell/shell'),
    smarty: () => import('codemirror/mode/smarty/smarty'),
    sql: () => import('codemirror/mode/sql/sql'),
    swift: () => import('codemirror/mode/swift/swift'),
    toml: () => import('codemirror/mode/toml/toml'),
    twig: () => import('codemirror/mode/twig/twig'),
    vue: () => import('codemirror/mode/vue/vue'),
    xml: () => import('codemirror/mode/xml/xml'),
    yaml: () => import('codemirror/mode/yaml/yaml'),
};

const modeAliases: Record<string, string> = {
    'text/css': 'css',
    'text/x-scss': 'sass',
    'text/x-sass': 'sass',
    'text/x-diff': 'diff',
    'text/x-dockerfile': 'dockerfile',
    'text/x-go': 'go',
    'text/x-csrc': 'clike',
    'text/x-chdr': 'clike',
    'text/x-c++src': 'clike',
    'text/x-c++hdr': 'clike',
    'text/x-java': 'clike',
    'text/x-csharp': 'clike',
    'text/x-objectivec': 'clike',
    'text/x-scala': 'clike',
    'text/x-kotlin': 'clike',
    'text/html': 'htmlmixed',
    'text/x-httpd-php': 'php',
    'application/json': 'javascript',
    'application/javascript': 'javascript',
    'text/javascript': 'javascript',
    'text/jsx': 'jsx',
    'text/x-lua': 'lua',
    'text/markdown': 'gfm',
    'text/x-nginx-conf': 'nginx',
    'text/x-perl': 'perl',
    'text/x-python': 'python',
    'text/x-ruby': 'ruby',
    'text/x-rustsrc': 'rust',
    'text/x-sh': 'shell',
    'text/x-sql': 'sql',
    'text/x-swift': 'swift',
    'text/x-toml': 'toml',
    'text/x-vue': 'vue',
    'application/xml': 'xml',
    'text/xml': 'xml',
    'text/x-yaml': 'yaml',
};

const loadEditorMode = (mime: string) => {
    const modeName = modeAliases[mime] || mime;
    return (modeLoaders[modeName] || (() => Promise.resolve()))().catch(() => undefined);
};

const EditorContainer = styled.div`
    min-height: 16rem;
    height: calc(100vh - 20rem);
    ${tw`relative`};

    > div {
        ${tw`rounded h-full`};
    }

    .CodeMirror {
        height: 100%;
        min-height: inherit;
        font-size: 13px;
        line-height: 1.45rem;
    }

    .CodeMirror-scroll {
        min-height: inherit;
    }

    .CodeMirror div.CodeMirror-selected,
    .CodeMirror-focused div.CodeMirror-selected,
    .cm-s-ayu-mirage div.CodeMirror-selected,
    .CodeMirror .CodeMirror-selected,
    .cm-s-ayu-mirage .CodeMirror-selected {
        background: #8b5cf6 !important;
        opacity: 1 !important;
    }

    .CodeMirror-line::selection,
    .CodeMirror-line > span::selection,
    .CodeMirror-line > span > span::selection,
    .cm-s-ayu-mirage .CodeMirror-line::selection,
    .cm-s-ayu-mirage .CodeMirror-line > span::selection,
    .cm-s-ayu-mirage .CodeMirror-line > span > span::selection {
        background: #8b5cf6 !important;
        color: #ffffff !important;
    }

    .CodeMirror-selectedtext,
    .pp-cm-selected-text {
        background: #8b5cf6 !important;
        color: #ffffff !important;
        text-shadow: 0 1px 1px rgba(0, 0, 0, 0.65);
    }

    .CodeMirror-cursor {
        border-left-color: #facc15 !important;
    }

    .CodeMirror-activeline-background {
        background: rgba(139, 92, 246, 0.12) !important;
    }

    .CodeMirror-linenumber {
        padding: 1px 12px 0 12px !important;
    }

    .CodeMirror-foldmarker {
        color: #cbccc6;
        text-shadow: none;
        margin-left: 0.25rem;
        margin-right: 0.25rem;
    }
`;

export interface Props {
    style?: React.CSSProperties;
    initialContent?: string;
    mode: string;
    filename?: string;
    jumpToLine?: number;
    onModeChanged: (mode: string) => void;
    fetchContent: (callback: () => Promise<string>) => void;
    onContentSaved: () => void;
    onContentChanged?: (content: string) => void;
}

const findModeByFilename = (filename: string) => {
    for (let i = 0; i < modes.length; i++) {
        const info = modes[i];

        if (info.file && info.file.test(filename)) {
            return info;
        }
    }

    const dot = filename.lastIndexOf('.');
    const ext = dot > -1 && filename.substring(dot + 1, filename.length);

    if (ext) {
        for (let i = 0; i < modes.length; i++) {
            const info = modes[i];
            if (info.ext) {
                for (let j = 0; j < info.ext.length; j++) {
                    if (info.ext[j] === ext) {
                        return info;
                    }
                }
            }
        }
    }

    return undefined;
};

export default ({ style, initialContent, filename, mode, jumpToLine, fetchContent, onContentSaved, onContentChanged, onModeChanged }: Props) => {
    const [editor, setEditor] = useState<CodeMirror.Editor>();
    const onContentSavedRef = useRef(onContentSaved);
    const onContentChangedRef = useRef(onContentChanged);

    useEffect(() => {
        onContentSavedRef.current = onContentSaved;
        onContentChangedRef.current = onContentChanged;
    }, [onContentSaved, onContentChanged]);

    const ref = useCallback((node) => {
        if (!node) return;

        const e = CodeMirror.fromTextArea(node, {
            mode: 'text/plain',
            theme: 'ayu-mirage',
            indentUnit: 4,
            smartIndent: true,
            tabSize: 4,
            indentWithTabs: false,
            lineWrapping: true,
            lineNumbers: true,
            foldGutter: true,
            fixedGutter: true,
            scrollbarStyle: 'overlay',
            coverGutterNextToScrollbar: false,
            readOnly: false,
            showCursorWhenSelecting: true,
            // @ts-expect-error provided by codemirror/addon/selection/mark-selection.
            styleSelectedText: 'pp-cm-selected-text',
            autofocus: false,
            spellcheck: true,
            autocorrect: false,
            autocapitalize: false,
            lint: false,
            autoCloseBrackets: true,
            matchBrackets: true,
            gutters: ['CodeMirror-linenumbers', 'CodeMirror-foldgutter'],
        });

        setEditor(e);
    }, []);

    useEffect(() => {
        if (filename === undefined) {
            return;
        }

        onModeChanged(findModeByFilename(filename)?.mime || 'text/plain');
    }, [filename]);

    useEffect(() => {
        if (!editor) return;
        let cancelled = false;
        loadEditorMode(mode).finally(() => {
            if (!cancelled) editor.setOption('mode', mode);
        });
        return () => {
            cancelled = true;
        };
    }, [editor, mode]);

    useEffect(() => {
        if (editor) {
            const nextContent = initialContent || '';
            if (editor.getValue() === nextContent) return;

            editor.setValue(nextContent);
            // Reset the history so that "Ctrl+Z" doesn't delete the intial content
            // we just set above.
            editor.setHistory({ done: [], undone: [] });
        }
    }, [editor, initialContent]);

    useEffect(() => {
        if (!editor) {
            fetchContent(() => Promise.reject(new Error('no editor session has been configured')));
            return;
        }

        const keyMap = {
            'Ctrl-S': () => onContentSavedRef.current(),
            'Cmd-S': () => onContentSavedRef.current(),
        };
        const changeHandler = () => onContentChangedRef.current?.(editor.getValue());

        editor.addKeyMap(keyMap);
        editor.on('change', changeHandler);
        fetchContent(() => Promise.resolve(editor.getValue()));

        return () => {
            editor.off('change', changeHandler);
            editor.removeKeyMap(keyMap);
        };
    }, [editor, fetchContent]);

    useEffect(() => {
        if (!editor) return;

        const refresh = () => window.requestAnimationFrame(() => editor.refresh());
        refresh();
        window.addEventListener('resize', refresh);
        return () => window.removeEventListener('resize', refresh);
    }, [editor]);

    useEffect(() => {
        if (!editor || !jumpToLine || jumpToLine < 1) return;
        const line = Math.max(0, jumpToLine - 1);
        editor.focus();
        editor.setCursor({ line, ch: 0 });
        editor.scrollIntoView({ line, ch: 0 }, 80);
    }, [editor, jumpToLine]);

    return (
        <EditorContainer style={style}>
            <textarea ref={ref} />
        </EditorContainer>
    );
};
