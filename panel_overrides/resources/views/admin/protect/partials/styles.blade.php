<style>
    .pp-theme .content-header > h1 > small {
        display: block;
        margin-top: 4px;
        color: #9fb0c4;
        font-size: 12px;
    }
    .pp-theme .pp-tabs {
        border-bottom: 1px solid #4a5b6e;
        margin-bottom: 10px;
    }
    .pp-theme .pp-tabs > li > a {
        background: #2f3f50;
        border: 1px solid #4a5b6e;
        color: #d7e3f2;
        margin-right: 6px;
        border-radius: 4px 4px 0 0;
    }
    .pp-theme .pp-tabs > li > a:hover,
    .pp-theme .pp-tabs > li > a:focus {
        background: #36485b;
        color: #ffffff;
        border-color: #4a5b6e;
    }
    .pp-theme .pp-tabs > li.active > a,
    .pp-theme .pp-tabs > li.active > a:hover,
    .pp-theme .pp-tabs > li.active > a:focus {
        background: #3a4e64;
        color: #ffffff;
        border-color: #4a5b6e;
        border-bottom-color: #3a4e64;
    }
    .pp-theme .pp-runtime-status {
        min-height: 80px;
        max-height: 240px;
        overflow: auto;
        white-space: pre-wrap;
        background: #2f3f50;
        border: 1px solid #4a5b6e;
        border-radius: 6px;
        color: #e5edf7;
        padding: 10px;
        margin-bottom: 10px;
    }
    .pp-theme .pp-table {
        background: #2f3f50;
        color: #e5edf7;
    }
    .pp-theme .pp-table > thead > tr > th {
        background: #3a4e64 !important;
        color: #e5edf7 !important;
        border-color: #4a5b6e !important;
    }
    .pp-theme .pp-table > tbody > tr > td,
    .pp-theme .pp-table > tbody > tr > th {
        border-color: #4a5b6e !important;
    }
    .pp-theme .pp-table.table-striped > tbody > tr:nth-of-type(odd) > td,
    .pp-theme .pp-table.table-striped > tbody > tr:nth-of-type(odd) > th {
        background: #334253 !important;
        color: #e5edf7 !important;
    }
    .pp-theme .pp-table.table-striped > tbody > tr:nth-of-type(even) > td,
    .pp-theme .pp-table.table-striped > tbody > tr:nth-of-type(even) > th {
        background: #2f3f50 !important;
        color: #e5edf7 !important;
    }
    .pp-theme .pp-table code {
        background: #415366;
        color: #f4f8fe;
    }
    .pp-theme input[type="checkbox"] {
        -webkit-appearance: none;
        appearance: none;
        width: 18px;
        height: 18px;
        margin: 0 6px 0 0;
        border: 1px solid #6f8fb3;
        border-radius: 4px;
        background: #27384a;
        display: inline-block;
        vertical-align: middle;
        position: relative;
        cursor: pointer;
        transition: all 120ms ease;
    }
    .pp-theme input[type="checkbox"]:hover {
        border-color: #86acda;
    }
    .pp-theme input[type="checkbox"]:focus {
        outline: none;
        box-shadow: 0 0 0 2px rgba(80, 148, 226, 0.28);
    }
    .pp-theme input[type="checkbox"]:checked {
        background: #2e9cff;
        border-color: #67b6ff;
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
