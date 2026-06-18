import tw from 'twin.macro';
import { createGlobalStyle } from 'styled-components/macro';
// @ts-expect-error untyped font file
import font from '@fontsource-variable/ibm-plex-sans/files/ibm-plex-sans-latin-wght-normal.woff2';

export default createGlobalStyle`
    :root {
        --primary-purple: #8B5CF6;
        --secondary-blue: #8B5CF6;
        --white: #FFFFFF;
        --black: #07070B;
        --gray: #6B7280;
        --accent-teal: #8B5CF6;
        --accent-pink: #8B5CF6;
        --accent-lime: #10B981;
        --el7-accent-light: #A78BFA;
        --el7-bg-1: #07070B;
        --el7-bg-2: #0B0B10;
        --el7-bg-3: #111117;
        --el7-surface: #0B0B10;
        --el7-surface-soft: #0E0E15;
        --el7-surface-strong: #111117;
        --el7-surface-raised: #15151D;
        --el7-border: rgba(139, 92, 246, 0.34);
        --el7-border-soft: rgba(139, 92, 246, 0.18);
        --el7-accent: var(--primary-purple);
        --el7-accent-2: var(--primary-purple);
        --el7-text: var(--white);
        --el7-text-muted: #A3A3B2;
        --el7-text-dim: #74748A;
        --el7-danger: #EF4444;
        --el7-success: #10B981;
        --el7-warning: #F59E0B;
        --el7-shadow: 0 18px 48px rgba(0, 0, 0, 0.52);
        --el7-shadow-deck: 0 22px 54px rgba(0, 0, 0, 0.58), 0 1px 0 rgba(255, 255, 255, 0.045) inset;
        --el7-glow: 0 10px 26px rgba(76, 29, 149, 0.24);
        --el7-telemetry-cyan: #A78BFA;
        --el7-telemetry-amber: #FBBF24;
        --el7-perspective: 1200px;
        --el7-route-z: 22px;
        --el7-route-tilt: 0deg;
        --el7-duration-fast: 140ms;
        --el7-duration: 220ms;
        --el7-duration-slow: 360ms;
        --el7-ease: cubic-bezier(0.4, 0, 0.2, 1);
        --el7-ease-out: cubic-bezier(0.16, 1, 0.3, 1);
    }

    @keyframes danex-fade-up {
        0% { opacity: 0; transform: translateY(14px); filter: blur(4px); }
        100% { opacity: 1; transform: translateY(0); filter: blur(0); }
    }

    @keyframes danex-pulse-border {
        0%, 100% { box-shadow: var(--el7-shadow), 0 0 0 rgba(139, 92, 246, 0); }
        50% { box-shadow: var(--el7-shadow), 0 0 26px rgba(139, 92, 246, 0.18); }
    }

    @keyframes danex-row-scan {
        0% { box-shadow: inset 0 0 0 rgba(139, 92, 246, 0); }
        45% { box-shadow: inset 3px 0 0 rgba(139, 92, 246, 0.68), 0 0 22px rgba(139, 92, 246, 0.12); }
        100% { box-shadow: inset 1px 0 0 rgba(139, 92, 246, 0.2); }
    }

    @keyframes danex-background-drift {
        0%, 100% { transform: translate3d(0, 0, 0); opacity: 0.78; }
        50% { transform: translate3d(0, 18px, 0); opacity: 1; }
    }

    @keyframes danex-spinner-rotate {
        from { transform: rotate(0deg); }
        to { transform: rotate(360deg); }
    }

    @keyframes danex-deck-arrive {
        0% { opacity: 0; transform: translate3d(18px, 18px, -32px) rotateX(1.4deg) rotateY(-1.2deg) scale(0.986); }
        100% { opacity: 1; transform: translate3d(0, 0, 0) rotateX(0) rotateY(0) scale(1); }
    }

    @font-face {
        font-family: 'IBM Plex Sans';
        font-style: normal;
        font-display: swap;
        font-weight: 100 700;
        src: url(${font}) format('woff2-variations');
        unicode-range: U+0000-00FF,U+0131,U+0152-0153,U+02BB-02BC,U+02C6,U+02DA,U+02DC,U+0304,U+0308,U+0329,U+2000-206F,U+20AC,U+2122,U+2191,U+2193,U+2212,U+2215,U+FEFF,U+FFFD;
    }

    body {
        ${tw`font-sans text-neutral-200`};
        letter-spacing: 0;
        background:
            linear-gradient(rgba(139, 92, 246, 0.03) 1px, transparent 1px),
            linear-gradient(90deg, rgba(139, 92, 246, 0.024) 1px, transparent 1px),
            var(--el7-bg-1);
        background-size: 46px 46px, 46px 46px, auto;
        background-attachment: fixed;
        min-height: 100vh;
        color: var(--el7-text);
        -webkit-font-smoothing: antialiased;
        -moz-osx-font-smoothing: grayscale;
    }

    body::before {
        content: '';
        position: fixed;
        inset: 0;
        pointer-events: none;
        z-index: 0;
        background:
            linear-gradient(180deg, rgba(255, 255, 255, 0.026), transparent 20rem),
            linear-gradient(90deg, transparent, rgba(139, 92, 246, 0.032), transparent);
        opacity: 0.56;
    }

    @media (max-width: 640px) {
        body {
            background-size: 34px 34px, 34px 34px, auto;
        }
    }

    h1, h2, h3, h4, h5, h6 {
        ${tw`font-medium tracking-normal font-header`};
    }

    p {
        ${tw`leading-snug font-sans`};
        color: inherit;
    }

    #app, #root {
        min-height: 100vh;
        position: relative;
        z-index: 1;
    }

    a, button {
        transition: color var(--el7-duration) var(--el7-ease), border-color var(--el7-duration) var(--el7-ease), background-color var(--el7-duration) var(--el7-ease), box-shadow var(--el7-duration) var(--el7-ease), transform var(--el7-duration) var(--el7-ease);
    }

    button {
        line-height: 1.15;
    }

    button + button,
    a + button,
    button + a {
        margin-left: 0.35rem;
    }

    @media (max-width: 640px) {
        button + button,
        a + button,
        button + a {
            margin-left: 0;
            margin-top: 0.35rem;
        }
    }

    button:hover {
        transform: translateY(-1px);
    }

    .el7-glass {
        background: var(--el7-surface);
        border: 1px solid var(--el7-border);
        box-shadow: var(--el7-shadow);
    }

    .el7-route-shell {
        position: relative;
        perspective: none;
        transform-style: flat;
        padding: clamp(0.7rem, 1.8vw, 1.35rem);
    }

    .el7-route-shell::before {
        content: '';
        position: absolute;
        inset: 0.35rem 0.2rem auto;
        height: 9rem;
        pointer-events: none;
        border-radius: 1.4rem;
        background: rgba(139, 92, 246, 0.055);
        filter: blur(16px);
        opacity: 0.42;
    }

    .el7-route-panel,
    .el7-ops-card,
    .el7-hero-panel {
        position: relative;
        overflow: hidden;
        background: var(--el7-surface);
        border: 1px solid rgba(139, 92, 246, 0.24);
        border-radius: 1.05rem;
        box-shadow: var(--el7-shadow);
        transform: translateZ(0);
    }

    .el7-route-panel::before,
    .el7-ops-card::before,
    .el7-hero-panel::before {
        content: '';
        position: absolute;
        inset: 0;
        pointer-events: none;
        border-top: 1px solid rgba(255, 255, 255, 0.075);
        background: repeating-linear-gradient(90deg, rgba(255,255,255,0.014) 0 1px, transparent 1px 54px);
        opacity: 0.48;
    }

    .el7-ops-card,
    .el7-lift-3d {
        transition: transform var(--el7-duration) var(--el7-ease-out), border-color var(--el7-duration) var(--el7-ease), box-shadow var(--el7-duration) var(--el7-ease);
    }

    .el7-ops-card:hover,
    .el7-lift-3d:hover {
        transform: translateY(-2px);
        border-color: rgba(139, 92, 246, 0.42);
        box-shadow: var(--el7-shadow), 0 10px 24px rgba(76, 29, 149, 0.16);
    }

    .el7-telemetry-kicker {
        color: var(--el7-telemetry-cyan);
        font-size: 0.68rem;
        font-weight: 800;
        letter-spacing: 0.16em;
        text-transform: uppercase;
    }

    .danex-monitor-surface {
        position: relative;
        overflow: hidden;
        background: var(--el7-surface);
        border: 1px solid var(--el7-border-soft);
        box-shadow: var(--el7-shadow);
        animation: danex-fade-up 360ms var(--el7-ease) both;
    }

    .danex-monitor-surface::before {
        content: '';
        position: absolute;
        inset: 0;
        pointer-events: none;
        border-top: 1px solid rgba(255, 255, 255, 0.06);
    }

    .danex-monitor-surface:hover {
        border-color: var(--el7-border);
        box-shadow: var(--el7-shadow);
    }

    .el7-panel,
    .el7-form-panel,
    .el7-table-shell,
    .el7-response {
        position: relative;
        overflow: hidden;
        background: var(--el7-surface);
        border: 1px solid var(--el7-border-soft);
        border-radius: 0.875rem;
        box-shadow: var(--el7-shadow);
    }

    .el7-panel::before,
    .el7-form-panel::before,
    .el7-table-shell::before,
    .el7-response::before {
        content: '';
        position: absolute;
        inset: 0;
        pointer-events: none;
        border-top: 1px solid rgba(255, 255, 255, 0.06);
        background: none;
        opacity: 0.5;
    }

    .el7-panel > *,
    .el7-form-panel > *,
    .el7-table-shell > *,
    .el7-response > * {
        position: relative;
        z-index: 1;
    }

    .el7-kicker {
        color: var(--el7-accent-light);
        font-size: 0.68rem;
        font-weight: 700;
        letter-spacing: 0.14em;
        text-transform: uppercase;
    }

    .el7-helper {
        color: var(--el7-text-dim);
        font-size: 0.78rem;
        line-height: 1.45;
    }

    .el7-response {
        padding: 0.75rem 0.875rem;
        color: var(--el7-text-muted);
    }

    .el7-response-success {
        border-color: rgba(16, 185, 129, 0.38);
        background: rgba(16, 185, 129, 0.12);
        color: #bbf7d0;
    }

    .el7-response-error {
        border-color: rgba(239, 68, 68, 0.44);
        background: rgba(239, 68, 68, 0.12);
        color: #fecaca;
    }

    .el7-response-warning {
        border-color: rgba(245, 158, 11, 0.44);
        background: rgba(245, 158, 11, 0.12);
        color: #fde68a;
    }

    input:not([type='checkbox']):not([type='radio']),
    textarea,
    select {
        min-height: 2.5rem;
        background-color: var(--el7-surface-strong) !important;
        border: 1px solid rgba(139, 92, 246, 0.24) !important;
        border-radius: 0.625rem !important;
        color: var(--el7-text) !important;
        box-shadow: none;
        transition: border-color var(--el7-duration-fast) var(--el7-ease), box-shadow var(--el7-duration-fast) var(--el7-ease), background-color var(--el7-duration-fast) var(--el7-ease);
    }

    select {
        appearance: auto;
        background-image: none;
        cursor: pointer;
    }

    input:not([type='checkbox']):not([type='radio']):focus,
    textarea:focus,
    select:focus {
        border-color: var(--el7-accent) !important;
        box-shadow: 0 0 0 2px rgba(139, 92, 246, 0.22) !important;
    }

    input::placeholder,
    textarea::placeholder {
        color: var(--el7-text-dim) !important;
    }

    label {
        color: var(--el7-text-muted);
        font-weight: 600;
    }

    fieldset {
        border-color: var(--el7-border-soft);
    }

    input:disabled,
    textarea:disabled,
    select:disabled,
    input[readonly],
    textarea[readonly] {
        opacity: 0.68;
        cursor: not-allowed;
        background-color: #09090d !important;
    }

    .CodeMirror .CodeMirror-selected,
    .CodeMirror-focused .CodeMirror-selected,
    .cm-s-ayu-mirage .CodeMirror-selected,
    .cm-s-ayu-mirage div.CodeMirror-selected {
        background: #8b5cf6 !important;
        opacity: 1 !important;
    }

    .CodeMirror-selectedtext,
    .pp-cm-selected-text {
        background: #8b5cf6 !important;
        color: #ffffff !important;
        text-shadow: 0 1px 1px rgba(0, 0, 0, 0.65);
    }

    .CodeMirror-line::selection,
    .CodeMirror-line > span::selection,
    .CodeMirror-line > span > span::selection {
        background: #8b5cf6 !important;
        color: #ffffff !important;
    }

    [class*='bg-neutral-'],
    [class*='bg-gray-'],
    [class*='bg-slate-'],
    [class*='bg-zinc-'],
    [class*='bg-primary-'],
    [class*='bg-blue-'],
    [class*='bg-cyan-'] {
        background-color: var(--el7-surface) !important;
    }

    [class*='hover:bg-neutral-']:hover,
    [class*='hover:bg-gray-']:hover,
    [class*='hover:bg-primary-']:hover,
    [class*='hover:bg-blue-']:hover,
    [class*='hover:bg-cyan-']:hover {
        background-color: var(--el7-surface-raised) !important;
    }

    [class*='border-neutral-'],
    [class*='border-gray-'],
    [class*='border-slate-'],
    [class*='border-zinc-'],
    [class*='border-primary-'],
    [class*='border-blue-'],
    [class*='border-cyan-'] {
        border-color: var(--el7-border-soft) !important;
    }

    [class*='text-blue-'],
    [class*='text-cyan-'],
    [class*='text-primary-'] {
        color: var(--el7-accent) !important;
    }

    [class*='hover:text-blue-']:hover,
    [class*='hover:text-cyan-']:hover,
    [class*='hover:text-primary-']:hover {
        color: var(--el7-accent-light) !important;
    }

    [class*='ring-blue-'],
    [class*='ring-cyan-'],
    [class*='ring-primary-'],
    [class*='focus:ring-blue-'],
    [class*='focus:ring-cyan-'],
    [class*='focus:ring-primary-'] {
        --tw-ring-color: rgba(139, 92, 246, 0.38) !important;
    }

    [class*='bg-red-'],
    [class*='hover:bg-red-']:hover {
        background-color: rgba(239, 68, 68, 0.14) !important;
        border-color: rgba(239, 68, 68, 0.45) !important;
        color: #fecaca !important;
    }

    [class*='bg-green-'],
    [class*='hover:bg-green-']:hover {
        background-color: rgba(16, 185, 129, 0.14) !important;
        border-color: rgba(16, 185, 129, 0.45) !important;
        color: #bbf7d0 !important;
    }

    [class*='bg-yellow-'],
    [class*='hover:bg-yellow-']:hover,
    [class*='bg-amber-'],
    [class*='hover:bg-amber-']:hover {
        background-color: rgba(245, 158, 11, 0.14) !important;
        border-color: rgba(245, 158, 11, 0.45) !important;
        color: #fde68a !important;
    }

    [class*='bg-red-'] svg,
    [class*='bg-green-'] svg,
    [class*='bg-yellow-'] svg,
    [class*='bg-amber-'] svg {
        color: currentColor !important;
    }

    [class*='shadow'],
    [class*='shadow-'] {
        --tw-shadow-color: rgba(0, 0, 0, 0.55) !important;
    }

    table,
    [role='table'] {
        width: 100%;
        border-collapse: separate;
        border-spacing: 0;
        border-color: var(--el7-border-soft) !important;
        background: var(--el7-surface) !important;
    }

    thead,
    [role='rowgroup']:first-child {
        background: var(--el7-surface-strong) !important;
    }

    th {
        color: var(--el7-text-muted) !important;
        font-size: 0.72rem;
        letter-spacing: 0.08em;
        text-transform: uppercase;
    }

    th,
    td {
        border-color: var(--el7-border-soft) !important;
    }

    tr,
    [role='row'] {
        transition: background-color 220ms var(--el7-ease), box-shadow 220ms var(--el7-ease), transform 220ms var(--el7-ease);
    }

    tr:hover,
    [role='row']:hover {
        background-color: rgba(139, 92, 246, 0.08) !important;
    }

    .group:hover,
    a:hover > [class*='rounded'],
    button:hover > [class*='rounded'] {
        border-color: rgba(139, 92, 246, 0.42);
    }

    pre,
    code,
    kbd {
        background: var(--el7-surface-raised) !important;
        border-color: var(--el7-border-soft) !important;
        color: var(--el7-accent-light) !important;
    }

    .fade-enter,
    .fade-appear {
        opacity: 0;
        transform: translateY(8px);
    }

    .fade-enter-active,
    .fade-appear-active {
        opacity: 1;
        transform: translateY(0);
        transition: opacity 180ms var(--el7-ease), transform 180ms var(--el7-ease);
    }

    .fade-exit {
        opacity: 1;
        transform: translateY(0);
    }

    .fade-exit-active {
        opacity: 0;
        transform: translateY(6px);
        transition: opacity 140ms var(--el7-ease), transform 140ms var(--el7-ease);
    }

    form {
        ${tw`m-0`};
    }

    textarea, select, input, button {
        ${tw`outline-none`};
    }

    textarea:focus-visible,
    select:focus-visible,
    input:focus-visible,
    button:focus-visible,
    a:focus-visible,
    [role='button']:focus-visible {
        outline: 2px solid var(--el7-accent-light) !important;
        outline-offset: 2px !important;
        box-shadow: 0 0 0 4px rgba(139, 92, 246, 0.28) !important;
    }

    select:hover {
        background-color: #12121a !important;
        border-color: rgba(167, 139, 250, 0.56) !important;
    }

    select:focus,
    select:active,
    select:focus-visible {
        background-color: #171720 !important;
        border-color: var(--el7-accent-light) !important;
        box-shadow: 0 0 0 3px rgba(139, 92, 246, 0.24) !important;
    }

    [data-el7-spinner='true'] {
        animation-name: danex-spinner-rotate;
        animation-duration: 560ms;
        animation-timing-function: linear;
        animation-play-state: running !important;
        animation-iteration-count: infinite !important;
        transform-origin: 50% 50%;
    }

    .pagination,
    [class*='pagination'] {
        display: flex;
        align-items: center;
        flex-wrap: wrap;
        gap: 0.35rem;
    }

    .pagination button,
    .pagination a,
    [class*='pagination'] button,
    [class*='pagination'] a {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        vertical-align: middle;
        margin-top: 0 !important;
    }

    @media (prefers-reduced-motion: reduce) {
        *:not([data-el7-keep-motion]):not([data-el7-spinner]),
        *:not([data-el7-keep-motion]):not([data-el7-spinner])::before,
        *:not([data-el7-keep-motion]):not([data-el7-spinner])::after,
        body::before,
        .danex-monitor-surface {
            animation-duration: 0.001ms !important;
            animation-iteration-count: 1 !important;
            transition-duration: 0.001ms !important;
            scroll-behavior: auto !important;
        }

        [data-el7-spinner='true'] {
            animation-duration: 1.15s !important;
        }

        html:focus-within {
            scroll-behavior: auto !important;
        }

        button:hover,
        .el7-ops-card:hover,
        .el7-lift-3d:hover {
            transform: none !important;
        }
    }

    input[type=number]::-webkit-outer-spin-button,
    input[type=number]::-webkit-inner-spin-button {
        -webkit-appearance: none !important;
        margin: 0;
    }

    input[type=number] {
        -moz-appearance: textfield !important;
    }

    /* Scroll Bar Style */
    ::-webkit-scrollbar {
        background: none;
        width: 16px;
        height: 16px;
    }

    ::-webkit-scrollbar-thumb {
        border: solid 0 rgb(0 0 0 / 0%);
        border-right-width: 4px;
        border-left-width: 4px;
        -webkit-border-radius: 9px 4px;
        -webkit-box-shadow: inset 0 0 0 1px rgba(139, 92, 246, 0.36), inset 0 0 0 4px #15151d;
    }

    ::-webkit-scrollbar-track-piece {
        margin: 4px 0;
    }

    ::-webkit-scrollbar-thumb:horizontal {
        border-right-width: 0;
        border-left-width: 0;
        border-top-width: 4px;
        border-bottom-width: 4px;
        -webkit-border-radius: 4px 9px;
    }

    ::-webkit-scrollbar-corner {
        background: transparent;
    }
`;
