<style>
    .pp-theme .content-header > h1 > small {
        display: block;
        margin-top: 4px;
        color: #a3a3b2;
        font-size: 12px;
    }
    .pp-theme .content {
        position: relative;
    }
    .pp-theme .content::before {
        content: "";
        position: fixed;
        inset: 0;
        z-index: -1;
        pointer-events: none;
        background:
            radial-gradient(circle at 14% 10%, rgba(139, 92, 246, 0.12), transparent 28rem),
            radial-gradient(circle at 86% 12%, rgba(6, 182, 212, 0.08), transparent 24rem),
            repeating-linear-gradient(90deg, rgba(255,255,255,0.022) 0 1px, transparent 1px 72px),
            repeating-linear-gradient(0deg, rgba(255,255,255,0.016) 0 1px, transparent 1px 72px);
    }
    .pp-theme .pp-tabs {
        display: flex;
        gap: 8px;
        overflow-x: auto;
        overflow-y: hidden;
        border-bottom: 0;
        margin-bottom: 14px;
        padding: 8px;
        background: #0b0b10;
        border: 1px solid rgba(139, 92, 246, 0.22);
        border-radius: 12px;
        box-shadow: inset 0 1px 0 rgba(255, 255, 255, 0.04), 0 12px 34px rgba(0, 0, 0, 0.28);
        scrollbar-width: thin;
    }
    .pp-theme .pp-tabs > li {
        float: none;
        flex: 0 0 auto;
    }
    .pp-theme .pp-tabs > li > a {
        background: #0b0b10;
        border: 1px solid rgba(139, 92, 246, 0.2);
        color: #d4d4df;
        margin-right: 0;
        border-radius: 9px;
        letter-spacing: 0.04em;
        text-transform: uppercase;
        font-size: 12px;
        transition: transform 160ms ease, border-color 160ms ease, background 160ms ease, box-shadow 160ms ease;
    }
    .pp-theme .pp-tabs > li > a:hover,
    .pp-theme .pp-tabs > li > a:focus {
        background: rgba(139, 92, 246, 0.12);
        color: #ffffff;
        border-color: rgba(139, 92, 246, 0.46);
        transform: translateY(-1px);
    }
    @media (prefers-reduced-motion: reduce) {
        .pp-theme *::before,
        .pp-theme *::after {
            animation: none !important;
        }
        .pp-theme .pp-tabs > li > a:hover,
        .pp-theme .pp-tabs > li > a:focus {
            transform: none !important;
        }
    }
    .pp-theme .pp-tabs > li.active > a,
    .pp-theme .pp-tabs > li.active > a:hover,
    .pp-theme .pp-tabs > li.active > a:focus {
        background: #8b5cf6;
        color: #ffffff;
        border-color: rgba(255, 255, 255, 0.18);
        box-shadow: 0 0 24px rgba(139, 92, 246, 0.28);
    }
    .pp-theme .box,
    .pp-theme .box.box-primary,
    .pp-theme .box.box-solid,
    .pp-theme .panel,
    .pp-theme .well {
        background: #0b0b10 !important;
        border: 1px solid rgba(139, 92, 246, 0.22) !important;
        border-radius: 12px !important;
        box-shadow: 0 14px 38px rgba(0, 0, 0, 0.34), inset 0 1px 0 rgba(255, 255, 255, 0.035) !important;
        color: #d9d9e7;
        overflow: hidden;
    }
    .pp-theme .box-header,
    .pp-theme .box.box-solid > .box-header,
    .pp-theme .panel-heading {
        background: #111117 !important;
        color: #f7f7fb !important;
        border-bottom: 1px solid rgba(139, 92, 246, 0.2) !important;
    }
    .pp-theme .box-title,
    .pp-theme .panel-title,
    .pp-theme h3,
    .pp-theme h4 {
        color: #f7f7fb !important;
        letter-spacing: 0.03em;
    }
    .pp-theme .box-footer,
    .pp-theme .panel-footer,
    .pp-theme .modal-footer {
        background: #0b0b10 !important;
        border-top: 1px solid rgba(139, 92, 246, 0.18) !important;
    }
    .pp-theme .form-control,
    .pp-theme input[type="text"],
    .pp-theme input[type="number"],
    .pp-theme input[type="password"],
    .pp-theme input[type="email"],
    .pp-theme input[type="url"],
    .pp-theme input[type="search"],
    .pp-theme select,
    .pp-theme textarea {
        background: #09090d !important;
        border: 1px solid rgba(139, 92, 246, 0.28) !important;
        color: #f4f4fb !important;
        border-radius: 9px !important;
        box-shadow: inset 0 1px 0 rgba(255, 255, 255, 0.035) !important;
        transition: border-color 160ms ease, box-shadow 160ms ease, background 160ms ease;
    }
    .pp-theme .form-control:focus,
    .pp-theme input:focus,
    .pp-theme select:focus,
    .pp-theme textarea:focus {
        border-color: #8b5cf6 !important;
        box-shadow: 0 0 0 2px rgba(139, 92, 246, 0.22), inset 0 1px 0 rgba(255, 255, 255, 0.04) !important;
        background: #0f0f16 !important;
        outline: none !important;
    }
    .pp-theme .control-label,
    .pp-theme label,
    .pp-theme .help-block,
    .pp-theme .text-muted {
        color: #a6a6b8 !important;
    }
    .pp-theme .pp-runtime-status {
        min-height: 80px;
        max-height: 240px;
        overflow: auto;
        white-space: pre-wrap;
        background: #0b0b10;
        border: 1px solid rgba(139, 92, 246, 0.22);
        border-radius: 6px;
        color: #d4d4df;
        padding: 10px;
        margin-bottom: 10px;
        box-shadow: inset 0 1px 0 rgba(255, 255, 255, 0.04);
    }
    .pp-theme .pp-table {
        background: #0b0b10;
        color: #d4d4df;
    }
    .pp-theme .table:not(.pp-table) {
        background: #0b0b10 !important;
        color: #d4d4df !important;
        border-color: rgba(139, 92, 246, 0.16) !important;
    }
    .pp-theme .table:not(.pp-table) > thead > tr > th,
    .pp-theme .table:not(.pp-table) > tbody > tr > th {
        background: #111117 !important;
        color: #a6a6b8 !important;
        border-color: rgba(139, 92, 246, 0.22) !important;
        text-transform: uppercase;
        font-size: 11px;
        letter-spacing: 0.08em;
    }
    .pp-theme .table:not(.pp-table) > tbody > tr > td {
        background: #0b0b10 !important;
        color: #d4d4df !important;
        border-color: rgba(139, 92, 246, 0.14) !important;
        transition: background 160ms ease, box-shadow 160ms ease;
    }
    .pp-theme .table-striped:not(.pp-table) > tbody > tr:nth-of-type(even) > td,
    .pp-theme .table-striped:not(.pp-table) > tbody > tr:nth-of-type(even) > th {
        background: #0f0f16 !important;
    }
    .pp-theme .table-hover:not(.pp-table) > tbody > tr:hover > td,
    .pp-theme .table:not(.pp-table) > tbody > tr:hover > td {
        background: rgba(139, 92, 246, 0.11) !important;
        box-shadow: inset 3px 0 0 #8b5cf6;
    }
    .pp-theme .pp-table > thead > tr > th {
        background: #111117 !important;
        color: #a3a3b2 !important;
        border-color: rgba(139, 92, 246, 0.22) !important;
        text-transform: uppercase;
        font-size: 11px;
        letter-spacing: 0.08em;
    }
    .pp-theme .pp-table > tbody > tr > td,
    .pp-theme .pp-table > tbody > tr > th {
        border-color: rgba(139, 92, 246, 0.16) !important;
    }
    .pp-theme .pp-table.table-striped > tbody > tr:nth-of-type(odd) > td,
    .pp-theme .pp-table.table-striped > tbody > tr:nth-of-type(odd) > th {
        background: #0b0b10 !important;
        color: #d4d4df !important;
    }
    .pp-theme .pp-table.table-striped > tbody > tr:nth-of-type(even) > td,
    .pp-theme .pp-table.table-striped > tbody > tr:nth-of-type(even) > th {
        background: #0f0f16 !important;
        color: #d4d4df !important;
    }
    .pp-theme .pp-table > tbody > tr:hover > td,
    .pp-theme .pp-table > tbody > tr:hover > th {
        background: rgba(139, 92, 246, 0.1) !important;
    }
    .pp-theme .pp-table code {
        background: #15151d;
        color: #a78bfa;
        border: 1px solid rgba(139, 92, 246, 0.18);
    }
    .pp-theme code,
    .pp-theme pre {
        background: #09090d !important;
        color: #a78bfa !important;
        border: 1px solid rgba(139, 92, 246, 0.2) !important;
        border-radius: 8px !important;
    }
    .pp-theme .btn {
        border-radius: 9px !important;
        border: 1px solid rgba(139, 92, 246, 0.28) !important;
        background: #111117 !important;
        color: #f7f7fb !important;
        font-weight: 700;
        letter-spacing: 0.02em;
        transition: transform 160ms ease, box-shadow 160ms ease, border-color 160ms ease, background 160ms ease;
        box-shadow: inset 0 1px 0 rgba(255, 255, 255, 0.04);
    }
    .pp-theme .btn:hover,
    .pp-theme .btn:focus {
        transform: translateY(-1px);
        border-color: #8b5cf6 !important;
        box-shadow: 0 0 22px rgba(139, 92, 246, 0.2), inset 0 1px 0 rgba(255, 255, 255, 0.06);
        outline: none !important;
    }
    .pp-theme .btn-primary,
    .pp-theme .btn-info {
        background: #8b5cf6 !important;
        border-color: rgba(255, 255, 255, 0.18) !important;
        box-shadow: 0 0 24px rgba(139, 92, 246, 0.24);
    }
    .pp-theme .btn-success {
        background: #0f3d30 !important;
        border-color: rgba(16, 185, 129, 0.55) !important;
        color: #d1fae5 !important;
        box-shadow: 0 0 20px rgba(16, 185, 129, 0.18);
    }
    .pp-theme .btn-warning {
        background: #3f2e0b !important;
        border-color: rgba(245, 158, 11, 0.58) !important;
        color: #fef3c7 !important;
        box-shadow: 0 0 20px rgba(245, 158, 11, 0.16);
    }
    .pp-theme .btn-danger {
        background: #3f1218 !important;
        border-color: rgba(239, 68, 68, 0.56) !important;
        color: #fee2e2 !important;
        box-shadow: 0 0 20px rgba(239, 68, 68, 0.17);
    }
    .pp-theme .label,
    .pp-theme .badge {
        border-radius: 999px;
        border: 1px solid rgba(139, 92, 246, 0.22);
        background: #15151d;
        color: #d8d8e8;
        font-weight: 700;
    }
    .pp-theme .label-success,
    .pp-theme .badge-success {
        background: rgba(16, 185, 129, 0.16) !important;
        border-color: rgba(16, 185, 129, 0.42);
        color: #bbf7d0 !important;
    }
    .pp-theme .label-warning,
    .pp-theme .badge-warning {
        background: rgba(245, 158, 11, 0.16) !important;
        border-color: rgba(245, 158, 11, 0.42);
        color: #fde68a !important;
    }
    .pp-theme .label-danger,
    .pp-theme .badge-danger {
        background: rgba(239, 68, 68, 0.16) !important;
        border-color: rgba(239, 68, 68, 0.42);
        color: #fecaca !important;
    }
    .pp-theme .alert,
    .pp-theme .callout {
        background: #0b0b10 !important;
        border: 1px solid rgba(139, 92, 246, 0.22) !important;
        border-left-width: 3px !important;
        color: #d8d8e8 !important;
        border-radius: 10px !important;
        box-shadow: 0 10px 30px rgba(0,0,0,0.22);
    }
    .pp-theme .alert-danger,
    .pp-theme .callout-danger {
        border-left-color: #ef4444 !important;
    }
    .pp-theme .alert-warning,
    .pp-theme .callout-warning {
        border-left-color: #f59e0b !important;
    }
    .pp-theme .alert-success,
    .pp-theme .callout-success {
        border-left-color: #10b981 !important;
    }
    .pp-theme .progress {
        background: #09090d !important;
        border: 1px solid rgba(139, 92, 246, 0.16);
        border-radius: 999px;
        overflow: hidden;
    }
    .pp-theme .progress-bar {
        background: #8b5cf6 !important;
        box-shadow: 0 0 18px rgba(139, 92, 246, 0.36);
    }
    .pp-theme .pagination > li > a,
    .pp-theme .pagination > li > span {
        background: #0b0b10 !important;
        color: #d8d8e8 !important;
        border-color: rgba(139, 92, 246, 0.24) !important;
    }
    .pp-theme .pagination > .active > a,
    .pp-theme .pagination > .active > span {
        background: #8b5cf6 !important;
        color: #fff !important;
        border-color: rgba(255,255,255,0.18) !important;
    }
    .pp-theme .select2-container--default .select2-selection--single,
    .pp-theme .select2-container--default .select2-selection--multiple {
        background: #09090d !important;
        border-color: rgba(139, 92, 246, 0.28) !important;
        color: #f4f4fb !important;
    }
    .pp-theme .dataTables_wrapper,
    .pp-theme .dataTables_info,
    .pp-theme .dataTables_length,
    .pp-theme .dataTables_filter {
        color: #a6a6b8 !important;
    }
    .pp-theme .pp-tabs {
        position: sticky;
        top: 0;
        z-index: 8;
        border-radius: 10px;
    }
    .pp-theme .pp-tabs > li > a::before {
        content: "";
        display: inline-block;
        width: 7px;
        height: 7px;
        margin-right: 8px;
        border-radius: 999px;
        background: #2b2b34;
        border: 1px solid rgba(139, 92, 246, 0.35);
        vertical-align: 1px;
        transition: background 160ms ease, box-shadow 160ms ease;
    }
    .pp-theme .pp-tabs > li.active > a::before {
        background: #10b981;
        box-shadow: 0 0 14px rgba(16, 185, 129, 0.45);
    }
    .pp-theme .box {
        position: relative;
        transform: translateZ(0);
        animation: pp-card-in 260ms cubic-bezier(0.4, 0, 0.2, 1) both;
    }
    .pp-theme .box::before,
    .pp-theme .panel::before,
    .pp-theme .well::before {
        content: "";
        position: absolute;
        left: 0;
        top: 0;
        bottom: 0;
        width: 3px;
        background: linear-gradient(180deg, #8b5cf6, rgba(139, 92, 246, 0));
        opacity: 0.72;
        pointer-events: none;
    }
    .pp-theme .box:hover,
    .pp-theme .panel:hover,
    .pp-theme .well:hover {
        border-color: rgba(139, 92, 246, 0.42) !important;
        box-shadow: 0 18px 48px rgba(0, 0, 0, 0.42), 0 0 28px rgba(139, 92, 246, 0.12), inset 0 1px 0 rgba(255, 255, 255, 0.045) !important;
    }
    .pp-theme .box.box-danger::before,
    .pp-theme .panel-danger::before {
        background: linear-gradient(180deg, #ef4444, rgba(239, 68, 68, 0));
    }
    .pp-theme .box.box-warning::before,
    .pp-theme .panel-warning::before {
        background: linear-gradient(180deg, #f59e0b, rgba(245, 158, 11, 0));
    }
    .pp-theme .box.box-success::before,
    .pp-theme .panel-success::before {
        background: linear-gradient(180deg, #10b981, rgba(16, 185, 129, 0));
    }
    .pp-theme .form-inline {
        display: flex;
        align-items: flex-end;
        flex-wrap: wrap;
        gap: 10px;
    }
    .pp-theme .form-inline .form-group,
    .pp-theme .form-inline .btn {
        margin-left: 0 !important;
        margin-right: 0 !important;
    }
    .pp-theme .btn {
        min-height: 38px;
        display: inline-flex !important;
        align-items: center;
        justify-content: center;
        gap: 7px;
        padding: 9px 14px !important;
        line-height: 1.15 !important;
        white-space: normal;
        text-align: center;
        position: relative;
        overflow: hidden;
    }
    .pp-theme .btn::after {
        content: "";
        position: absolute;
        inset: 0;
        background: linear-gradient(120deg, transparent, rgba(255, 255, 255, 0.12), transparent);
        transform: translateX(-120%);
        transition: transform 420ms cubic-bezier(0.4, 0, 0.2, 1);
        pointer-events: none;
    }
    .pp-theme .btn:hover::after,
    .pp-theme .btn:focus::after {
        transform: translateX(120%);
    }
    .pp-theme .label-info,
    .pp-theme .badge-info {
        background: rgba(6, 182, 212, 0.15) !important;
        border-color: rgba(6, 182, 212, 0.42);
        color: #a5f3fc !important;
    }
    .pp-theme .text-danger { color: #fca5a5 !important; }
    .pp-theme .text-warning { color: #fde68a !important; }
    .pp-theme .text-success { color: #bbf7d0 !important; }
    .pp-theme .qf-summary,
    .pp-theme .pp-rum-grid {
        display: grid;
        grid-template-columns: repeat(3, minmax(0, 1fr));
        gap: 10px;
    }
    .pp-theme .qf-card,
    .pp-theme .pp-rum-card,
    .pp-theme .pp-console,
    .pp-theme .pp-pty-wrap,
    .pp-theme .pp-dark-shell,
    .pp-theme .qf-shell {
        background: #09090d !important;
        border: 1px solid rgba(139, 92, 246, 0.24) !important;
        border-radius: 12px !important;
        color: #d8d8e8 !important;
        box-shadow: inset 0 1px 0 rgba(255, 255, 255, 0.035), 0 12px 34px rgba(0, 0, 0, 0.24) !important;
    }
    .pp-theme .qf-card {
        padding: 12px;
    }
    .pp-theme .qf-card .label,
    .pp-theme .pp-rum-label,
    .pp-theme .qf-meta {
        color: #a6a6b8 !important;
    }
    .pp-theme .qf-card .value,
    .pp-theme .qf-title,
    .pp-theme .pp-rum-value {
        color: #f7f7fb !important;
    }
    .pp-theme .pp-rum-value {
        font-size: 30px;
        font-weight: 800;
        text-shadow: 0 0 18px rgba(139, 92, 246, 0.2);
    }
    .pp-theme .pp-pill.good { background: rgba(16, 185, 129, 0.18) !important; color: #bbf7d0 !important; border: 1px solid rgba(16, 185, 129, 0.42); }
    .pp-theme .pp-pill.ni { background: rgba(245, 158, 11, 0.18) !important; color: #fde68a !important; border: 1px solid rgba(245, 158, 11, 0.42); }
    .pp-theme .pp-pill.poor { background: rgba(239, 68, 68, 0.18) !important; color: #fecaca !important; border: 1px solid rgba(239, 68, 68, 0.42); }
    .pp-theme .pp-console-output,
    .pp-theme #pp-pty-terminal,
    .pp-theme textarea[style],
    .pp-theme pre {
        background: #07070b !important;
        border-color: rgba(139, 92, 246, 0.24) !important;
        color: #e8e8f4 !important;
    }
    .pp-theme .qf-group.panel,
    .pp-theme .qf-group .panel-heading,
    .pp-theme .qf-group .panel-body,
    .pp-theme .qf-group .panel-collapse {
        background: #0b0b10 !important;
        border-color: rgba(139, 92, 246, 0.22) !important;
    }
    .pp-theme .qf-group .panel-title > a {
        color: #f7f7fb !important;
    }
    @keyframes pp-card-in {
        from { opacity: 0; transform: translateY(8px); }
        to { opacity: 1; transform: translateY(0); }
    }
    .pp-theme input[type="checkbox"] {
        -webkit-appearance: none;
        appearance: none;
        width: 18px;
        height: 18px;
        margin: 0 6px 0 0;
        border: 1px solid rgba(139, 92, 246, 0.34);
        border-radius: 4px;
        background: #111117;
        display: inline-block;
        vertical-align: middle;
        position: relative;
        cursor: pointer;
        transition: all 120ms ease;
    }
    .pp-theme input[type="checkbox"]:hover {
        border-color: #8b5cf6;
    }
    .pp-theme input[type="checkbox"]:focus {
        outline: none;
        box-shadow: 0 0 0 2px rgba(139, 92, 246, 0.28);
    }
    .pp-theme input[type="checkbox"]:checked {
        background: #8b5cf6;
        border-color: #a78bfa;
    }
    .pp-theme input[type="checkbox"]:checked::after {
        content: "";
        position: absolute;
        left: 5px;
        top: 1px;
        width: 5px;
        height: 10px;
        border: solid #ffffff;
        border-width: 0 2px 2px 0;
        transform: rotate(45deg);
    }
    .pp-theme input[type="checkbox"]:disabled {
        opacity: 0.6;
        cursor: not-allowed;
    }
    .pp-theme .checkbox > label {
        display: inline-flex;
        align-items: center;
        gap: 6px;
    }
    .pp-theme .pp-toggle-field {
        margin-bottom: 16px;
    }
    .pp-theme .pp-text-toggle {
        display: flex;
        align-items: center;
        gap: 12px;
        width: 100%;
        min-height: 72px;
        padding: 12px;
        background: linear-gradient(135deg, rgba(139, 92, 246, 0.12), rgba(6, 182, 212, 0.06) 48%, rgba(9, 9, 13, 0.94));
        border: 1px solid rgba(139, 92, 246, 0.28);
        border-radius: 14px;
        cursor: pointer;
        user-select: none;
        box-shadow: inset 0 1px 0 rgba(255, 255, 255, 0.045), 0 12px 30px rgba(0, 0, 0, 0.22);
        transition: border-color 160ms ease, box-shadow 160ms ease, transform 160ms ease, background 160ms ease;
    }
    .pp-theme .pp-text-toggle:hover,
    .pp-theme .pp-text-toggle:focus-within {
        border-color: rgba(139, 92, 246, 0.62);
        box-shadow: 0 0 0 2px rgba(139, 92, 246, 0.18), 0 18px 40px rgba(0, 0, 0, 0.3), 0 0 22px rgba(139, 92, 246, 0.16);
        transform: translateY(-1px);
    }
    .pp-theme .pp-text-toggle-input {
        position: absolute !important;
        width: 1px !important;
        height: 1px !important;
        margin: -1px !important;
        padding: 0 !important;
        border: 0 !important;
        clip: rect(0 0 0 0) !important;
        clip-path: inset(50%) !important;
        overflow: hidden !important;
        white-space: nowrap !important;
    }
    .pp-theme .pp-text-toggle-frame {
        position: relative;
        flex: 0 0 118px;
        height: 42px;
        display: grid;
        grid-template-columns: 1fr 1fr;
        align-items: center;
        padding: 3px;
        background: #07070b;
        border: 1px solid rgba(239, 68, 68, 0.38);
        border-radius: 999px;
        box-shadow: inset 0 1px 10px rgba(0, 0, 0, 0.55), 0 0 18px rgba(239, 68, 68, 0.12);
        overflow: hidden;
    }
    .pp-theme .pp-text-toggle-core {
        position: absolute;
        top: 3px;
        left: 3px;
        width: calc(50% - 3px);
        height: calc(100% - 6px);
        background: linear-gradient(135deg, #ef4444, #7f1d1d);
        border-radius: 999px;
        box-shadow: 0 0 18px rgba(239, 68, 68, 0.35);
        transform: translateX(100%);
        transition: transform 180ms ease, background 180ms ease, box-shadow 180ms ease;
    }
    .pp-theme .pp-text-toggle-option {
        position: relative;
        z-index: 1;
        text-align: center;
        color: #d8d8e8;
        font-size: 11px;
        font-weight: 900;
        letter-spacing: 0.12em;
        text-shadow: 0 1px 10px rgba(0, 0, 0, 0.75);
    }
    .pp-theme .pp-text-toggle-input:checked + .pp-text-toggle-frame {
        border-color: rgba(16, 185, 129, 0.58);
        box-shadow: inset 0 1px 10px rgba(0, 0, 0, 0.5), 0 0 20px rgba(16, 185, 129, 0.18);
    }
    .pp-theme .pp-text-toggle-input:checked + .pp-text-toggle-frame .pp-text-toggle-core {
        background: linear-gradient(135deg, #10b981, #06b6d4);
        box-shadow: 0 0 22px rgba(16, 185, 129, 0.38), 0 0 18px rgba(6, 182, 212, 0.2);
        transform: translateX(0);
    }
    .pp-theme .pp-toggle-copy {
        display: flex;
        min-width: 0;
        flex-direction: column;
        gap: 4px;
    }
    .pp-theme .pp-toggle-title {
        color: #f7f7fb;
        font-weight: 800;
        letter-spacing: 0.04em;
        text-transform: uppercase;
    }
    .pp-theme .pp-toggle-state {
        font-size: 12px;
        font-weight: 700;
        letter-spacing: 0.03em;
    }
    .pp-theme .pp-toggle-state-on {
        display: none;
        color: #bbf7d0;
    }
    .pp-theme .pp-toggle-state-off {
        display: inline;
        color: #fecaca;
    }
    .pp-theme .pp-text-toggle-input:checked ~ .pp-toggle-copy .pp-toggle-state-on {
        display: inline;
    }
    .pp-theme .pp-text-toggle-input:checked ~ .pp-toggle-copy .pp-toggle-state-off {
        display: none;
    }
    .pp-theme .pp-text-toggle-input:focus-visible + .pp-text-toggle-frame {
        outline: 2px solid #a78bfa;
        outline-offset: 3px;
    }
    @media (prefers-reduced-motion: reduce) {
        .pp-theme .pp-text-toggle,
        .pp-theme .pp-text-toggle-core {
            transition: none !important;
            transform: none !important;
        }
        .pp-theme .pp-text-toggle-input:checked + .pp-text-toggle-frame .pp-text-toggle-core {
            left: 3px;
            transform: none !important;
        }
        .pp-theme .pp-text-toggle-input:not(:checked) + .pp-text-toggle-frame .pp-text-toggle-core {
            left: calc(50% + 0px);
            transform: none !important;
        }
    }
    @media (max-width: 767px) {
        .pp-theme .content {
            padding: 10px !important;
        }
        .pp-theme .box-body,
        .pp-theme .box-header {
            padding: 12px !important;
        }
        .pp-theme .table-responsive {
            border: 1px solid rgba(139, 92, 246, 0.18);
            border-radius: 10px;
        }
        .pp-theme .btn {
            margin-bottom: 6px;
            width: 100%;
        }
        .pp-theme .form-inline {
            display: block;
        }
        .pp-theme .form-inline .form-group {
            margin-bottom: 8px;
        }
        .pp-theme .pp-text-toggle {
            align-items: flex-start;
            flex-direction: column;
        }
        .pp-theme .pp-text-toggle-frame {
            flex-basis: auto;
            width: 118px;
        }
        .pp-theme .qf-summary,
        .pp-theme .pp-rum-grid {
            grid-template-columns: 1fr;
        }
    }
</style>
