import tw from 'twin.macro';
import { createGlobalStyle } from 'styled-components/macro';
// @ts-expect-error untyped font file
import font from '@fontsource-variable/ibm-plex-sans/files/ibm-plex-sans-latin-wght-normal.woff2';

export default createGlobalStyle`
    :root {
        --primary-purple: #8B5CF6;
        --secondary-blue: #3B82F6;
        --white: #FFFFFF;
        --black: #07070B;
        --gray: #6B7280;
        --accent-teal: #06B6D4;
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
        --el7-accent-2: var(--secondary-blue);
        --el7-text: var(--white);
        --el7-text-muted: #A3A3B2;
        --el7-text-dim: #74748A;
        --el7-danger: #EF4444;
        --el7-success: #10B981;
        --el7-warning: #F59E0B;
        --el7-shadow: 0 18px 48px rgba(0, 0, 0, 0.52);
        --el7-glow: 0 0 28px rgba(139, 92, 246, 0.24);
        --el7-ease: cubic-bezier(0.4, 0, 0.2, 1);
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

    @keyframes danex-scanline {
        0% { transform: translateY(-18%); opacity: 0; }
        20%, 58% { opacity: 0.5; }
        100% { transform: translateY(118%); opacity: 0; }
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
            radial-gradient(circle at 15% -10%, rgba(139, 92, 246, 0.12), transparent 34rem),
            radial-gradient(circle at 88% 0%, rgba(6, 182, 212, 0.06), transparent 28rem),
            linear-gradient(rgba(139, 92, 246, 0.03) 1px, transparent 1px),
            linear-gradient(90deg, rgba(139, 92, 246, 0.024) 1px, transparent 1px),
            var(--el7-bg-1);
        background-size: auto, auto, 46px 46px, 46px 46px, auto;
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
            linear-gradient(180deg, rgba(255, 255, 255, 0.035), transparent 22rem),
            linear-gradient(90deg, transparent, rgba(139, 92, 246, 0.04), transparent),
            radial-gradient(circle at 32% 70%, rgba(16, 185, 129, 0.035), transparent 28rem),
            radial-gradient(circle at 72% 64%, rgba(245, 158, 11, 0.026), transparent 26rem);
        opacity: 0.9;
        animation: danex-background-drift 16s ease-in-out infinite;
    }

    body::after {
        content: '';
        position: fixed;
        left: 0;
        right: 0;
        top: 0;
        height: 34vh;
        pointer-events: none;
        z-index: 0;
        background: linear-gradient(180deg, transparent, rgba(139, 92, 246, 0.055), transparent);
        mix-blend-mode: screen;
        animation: danex-scanline 9s linear infinite;
    }

    @media (max-width: 640px) {
        body {
            background-size: auto, auto, 34px 34px, 34px 34px, auto;
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
        transition: color 220ms var(--el7-ease), border-color 220ms var(--el7-ease), background-color 220ms var(--el7-ease), box-shadow 220ms var(--el7-ease), transform 220ms var(--el7-ease);
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
        box-shadow: var(--el7-shadow), var(--el7-glow);
    }

    input:not([type='checkbox']):not([type='radio']),
    textarea,
    select {
        background-color: var(--el7-surface-strong) !important;
        border-color: rgba(139, 92, 246, 0.24) !important;
        color: var(--el7-text) !important;
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

    @media (prefers-reduced-motion: reduce) {
        body::before,
        body::after,
        .danex-monitor-surface {
            animation: none !important;
        }

        html:focus-within {
            scroll-behavior: auto !important;
        }

        button:hover {
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
