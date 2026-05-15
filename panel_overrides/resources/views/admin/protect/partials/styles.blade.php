<style>
    .pp-theme .content-header > h1 > small {
        display: block;
        margin-top: 4px;
        color: #a3a3b2;
        font-size: 12px;
    }
    .pp-theme .pp-tabs {
        border-bottom: 1px solid rgba(139, 92, 246, 0.24);
        margin-bottom: 10px;
    }
    .pp-theme .pp-tabs > li > a {
        background: #0b0b10;
        border: 1px solid rgba(139, 92, 246, 0.2);
        color: #d4d4df;
        margin-right: 6px;
        border-radius: 8px 8px 0 0;
    }
    .pp-theme .pp-tabs > li > a:hover,
    .pp-theme .pp-tabs > li > a:focus {
        background: rgba(139, 92, 246, 0.12);
        color: #ffffff;
        border-color: rgba(139, 92, 246, 0.46);
    }
    .pp-theme .pp-tabs > li.active > a,
    .pp-theme .pp-tabs > li.active > a:hover,
    .pp-theme .pp-tabs > li.active > a:focus {
        background: #111117;
        color: #ffffff;
        border-color: rgba(139, 92, 246, 0.46);
        border-bottom-color: #111117;
        box-shadow: 0 0 20px rgba(139, 92, 246, 0.14);
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
</style>
