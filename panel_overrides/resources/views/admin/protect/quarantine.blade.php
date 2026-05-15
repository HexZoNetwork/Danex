@extends('layouts.admin')

@section('title')
    Quarantine Files
@endsection

@section('content-header')
    <h1>Quarantine Files<small>Daftar file karantina per server.</small></h1>
    <ol class="breadcrumb">
        <li><a href="{{ route('admin.servers') }}">Admin</a></li>
        <li><a href="{{ route('admin.protect') }}">Protect</a></li>
        <li class="active">Quarantine Files</li>
    </ol>
@endsection

@section('content')
@include('admin.protect.partials.styles')
<div class="pp-theme">
<style>
    .qf-summary {
        display: grid;
        grid-template-columns: repeat(3, minmax(0, 1fr));
        gap: 10px;
        margin-bottom: 14px;
    }
    .qf-card {
        position: relative;
        overflow: hidden;
        background: #09090d;
        border: 1px solid rgba(139, 92, 246, 0.28);
        border-radius: 12px;
        padding: 13px 14px;
        box-shadow: 0 14px 34px rgba(0, 0, 0, 0.26), inset 0 1px 0 rgba(255, 255, 255, 0.035);
        transition: transform 180ms ease, border-color 180ms ease, box-shadow 180ms ease;
    }
    .qf-card::before {
        content: "";
        position: absolute;
        left: 0;
        top: 10px;
        bottom: 10px;
        width: 3px;
        background: #8b5cf6;
        box-shadow: 0 0 18px rgba(139, 92, 246, 0.48);
    }
    .qf-card:hover {
        transform: translateY(-2px);
        border-color: rgba(139, 92, 246, 0.58);
        box-shadow: 0 18px 44px rgba(0, 0, 0, 0.34), 0 0 22px rgba(139, 92, 246, 0.13);
    }
    .qf-card .label {
        display: block;
        color: #a6a6b8;
        font-size: 11px;
        margin-bottom: 6px;
        letter-spacing: .2px;
    }
    .qf-card .value {
        color: #f7f7fb;
        font-size: 13px;
        overflow: hidden;
        text-overflow: ellipsis;
        white-space: nowrap;
        font-family: Menlo, Monaco, Consolas, monospace;
    }
    .qf-shell,
    .qf-shell .box-header,
    .qf-shell .box-body {
        background: #0b0b10 !important;
        border-color: rgba(139, 92, 246, 0.24) !important;
        color: #f7f7fb !important;
    }
    .qf-shell .box-title {
        color: #f7f7fb !important;
    }
    .qf-group .panel-heading {
        position: relative;
        background: #111117;
        padding: 13px 14px;
    }
    .qf-group .panel-heading::before {
        content: "";
        position: absolute;
        left: 0;
        top: 0;
        bottom: 0;
        width: 3px;
        background: linear-gradient(180deg, #8b5cf6, rgba(139, 92, 246, 0.18));
        box-shadow: 0 0 18px rgba(139, 92, 246, 0.4);
    }
    .qf-group.panel,
    .qf-group .panel-body,
    .qf-group .panel-collapse,
    .qf-group .panel-default {
        background: #0b0b10 !important;
        border-color: rgba(139, 92, 246, 0.24) !important;
    }
    .qf-group .panel-heading {
        border-color: rgba(139, 92, 246, 0.24) !important;
    }
    .qf-group .panel-title > a {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 10px;
        color: #f7f7fb !important;
        text-decoration: none !important;
    }
    .qf-title {
        font-weight: 600;
        color: #f7f7fb;
    }
    .qf-meta {
        color: #a6a6b8;
        font-size: 12px;
        margin-top: 4px;
    }
    .qf-table-wrap {
        overflow-x: auto;
        border: 1px solid rgba(139, 92, 246, 0.18);
        border-radius: 12px;
        background: #07070b;
    }
    .qf-table {
        margin-bottom: 0;
        table-layout: fixed;
        width: 100%;
        background: #0b0b10;
        color: #f7f7fb;
    }
    .qf-table th,
    .qf-table td {
        vertical-align: middle !important;
        border-color: rgba(139, 92, 246, 0.24) !important;
        background: #0b0b10 !important;
    }
    .qf-table th {
        background: #111117 !important;
        color: #a6a6b8 !important;
        text-transform: uppercase;
        letter-spacing: 0.08em;
        font-size: 11px;
    }
    .qf-table.table-striped > tbody > tr:nth-of-type(odd) > td,
    .qf-table.table-striped > tbody > tr:nth-of-type(odd) > th {
        background: #09090d !important;
    }
    .qf-table.table-striped > tbody > tr:nth-of-type(even) > td,
    .qf-table.table-striped > tbody > tr:nth-of-type(even) > th {
        background: #0b0b10 !important;
    }
    .qf-file {
        font-family: Menlo, Monaco, Consolas, monospace;
        font-size: 12px;
        color: #f7f7fb;
        display: block;
        width: 100%;
        max-width: 100%;
        overflow: hidden;
        text-overflow: ellipsis;
        white-space: nowrap;
        vertical-align: bottom;
    }
    .qf-table tbody tr {
        transition: transform 160ms ease, box-shadow 160ms ease;
    }
    .qf-table tbody tr:hover td {
        background: rgba(139, 92, 246, 0.12) !important;
        box-shadow: inset 3px 0 0 #8b5cf6;
    }
    .qf-path {
        max-width: 100%;
        display: block;
        width: 100%;
        overflow: hidden;
        text-overflow: ellipsis;
        white-space: nowrap;
        vertical-align: bottom;
        font-family: Menlo, Monaco, Consolas, monospace;
        font-size: 11px;
        color: #a6a6b8;
    }
    .qf-actions .btn {
        margin: 2px 4px 2px 0;
        min-width: 64px;
    }
    .qf-empty {
        padding: 18px;
        border: 1px dashed rgba(139, 92, 246, 0.3);
        border-radius: 12px;
        color: #b8c7d9;
        background: #09090d;
    }
    .qf-shell {
        position: relative;
    }
    .qf-shell::before {
        content: "";
        position: absolute;
        inset: 0;
        pointer-events: none;
        background:
            linear-gradient(90deg, transparent, rgba(139, 92, 246, 0.08), transparent),
            repeating-linear-gradient(90deg, rgba(255,255,255,0.018) 0 1px, transparent 1px 48px);
        opacity: 0.48;
    }
    .qf-shell > .box-header,
    .qf-shell > .box-body {
        position: relative;
        z-index: 1;
    }
    @media (max-width: 991px) {
        .qf-summary {
            grid-template-columns: 1fr;
        }
        .qf-path {
            max-width: 300px;
        }
        .qf-actions {
            min-width: 260px;
        }
    }
</style>
@include('admin.protect.partials.tabs', ['active' => 'quarantine'])

<div class="row">
    <div class="col-md-12">
        <div class="qf-summary">
            <div class="qf-card">
                <span class="label">Volumes Path</span>
                <div class="value">Hidden for cleaner UI</div>
            </div>
            <div class="qf-card">
                <span class="label">Quarantine Dir</span>
                <div class="value">{{ $quarantineDirName }}</div>
            </div>
            <div class="qf-card">
                <span class="label">Total Server Groups</span>
                <div class="value">{{ count($quarantineGroups) }}</div>
            </div>
        </div>
    </div>
</div>

<div class="row">
    <div class="col-md-12">
        <div class="box box-primary qf-shell">
            <div class="box-header with-border">
                <h3 class="box-title">Server Quarantine List</h3>
            </div>
            <div class="box-body">
                @if(count($quarantineGroups) === 0)
                    <div class="qf-empty">Belum ada file karantina terdeteksi.</div>
                @else
                    <div class="panel-group" id="quarantine-accordion">
                        @foreach($quarantineGroups as $idx => $group)
                            <div class="panel panel-default qf-group">
                                <div class="panel-heading">
                                    <h4 class="panel-title">
                                        <a data-toggle="collapse" data-parent="#quarantine-accordion" href="#qgroup-{{ $idx }}" aria-expanded="{{ $idx === 0 ? 'true' : 'false' }}">
                                            <span class="qf-title">{{ $group['server_name'] }}</span>
                                            <span class="label label-default" style="margin-left:6px;">{{ $group['file_count'] }} file</span>
                                        </a>
                                    </h4>
                                    <div class="qf-meta">
                                        UUID: <code>{{ $group['server_uuid'] }}</code>
                                    </div>
                                </div>
                                <div id="qgroup-{{ $idx }}" class="panel-collapse collapse{{ $idx === 0 ? ' in' : '' }}">
                                    <div class="panel-body" style="padding-top: 0;">
                                        <div class="qf-table-wrap" style="margin-top: 10px;">
                                            <table class="table table-bordered table-striped table-condensed qf-table">
                                                <thead>
                                                <tr>
                                                    <th style="width: 22%;">File</th>
                                                    <th style="width: 38%;">Location</th>
                                                    <th style="width: 10%;">Size</th>
                                                    <th style="width: 14%;">Modified (UTC)</th>
                                                    <th style="width: 16%;">Actions</th>
                                                </tr>
                                                </thead>
                                                <tbody>
                                                @foreach($group['files'] as $file)
                                                    <tr>
                                                        <td><span class="qf-file">{{ $file['name'] }}</span></td>
                                                        <td>
                                                            @php
                                                                $safePath = $group['server_uuid'] . '/' . $quarantineDirName . '/' . $file['name'];
                                                            @endphp
                                                            <span class="qf-path" title="{{ $safePath }}">{{ $safePath }}</span>
                                                        </td>
                                                        <td>{{ number_format((int) $file['size']) }} B</td>
                                                        <td>{{ (int) $file['mtime'] > 0 ? gmdate('Y-m-d H:i:s', (int) $file['mtime']) : '-' }}</td>
                                                        <td class="qf-actions">
                                                            <a class="btn btn-xs btn-primary" href="{{ route('admin.protect.quarantine.download', ['path' => $file['encoded_path']]) }}">Download</a>
                                                            <a class="btn btn-xs btn-default" href="{{ route('admin.protect.quarantine.edit', ['path' => $file['encoded_path']]) }}">Edit</a>
                                                            <button type="button" class="btn btn-xs btn-warning js-rename-btn" data-path="{{ $file['encoded_path'] }}" data-name="{{ $file['name'] }}">Rename</button>
                                                            <form method="POST" action="{{ route('admin.protect.quarantine.delete') }}" style="display:inline-block;" onsubmit="return confirm('Remove file ini?');">
                                                                @csrf
                                                                <input type="hidden" name="protect_token" value="{{ $postProtectToken ?? '' }}" />
                                                                <input type="hidden" name="path" value="{{ $file['encoded_path'] }}" />
                                                                <button type="submit" class="btn btn-xs btn-danger">Remove</button>
                                                            </form>
                                                        </td>
                                                    </tr>
                                                @endforeach
                                                </tbody>
                                            </table>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        @endforeach
                    </div>
                @endif
            </div>
        </div>
    </div>
</div>
<form method="POST" action="{{ route('admin.protect.quarantine.rename') }}" id="qf-rename-form" style="display:none;">
    @csrf
    <input type="hidden" name="protect_token" value="{{ $postProtectToken ?? '' }}" />
    <input type="hidden" name="path" id="qf-rename-path" />
    <input type="hidden" name="new_name" id="qf-rename-name" />
</form>
<script>
document.addEventListener('DOMContentLoaded', function () {
    const renameForm = document.getElementById('qf-rename-form');
    const renamePath = document.getElementById('qf-rename-path');
    const renameName = document.getElementById('qf-rename-name');
    if (!renameForm || !renamePath || !renameName) return;
    document.querySelectorAll('.js-rename-btn').forEach(function (btn) {
        btn.addEventListener('click', function () {
            const currentName = this.getAttribute('data-name') || '';
            const nextName = window.prompt('Rename file:', currentName);
            if (!nextName) return;
            renamePath.value = this.getAttribute('data-path') || '';
            renameName.value = nextName.trim();
            if (renameName.value === '') return;
            renameForm.submit();
        });
    });
});
</script>
</div>
@endsection
