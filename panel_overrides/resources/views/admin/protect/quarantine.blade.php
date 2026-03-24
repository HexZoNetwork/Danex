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
<style>
    .content-header > h1 > small {
        display: block;
        margin-top: 4px;
        color: #9fb0c4;
        font-size: 12px;
    }
    .pp-tabs {
        border-bottom: 1px solid #4a5b6e;
    }
    .pp-tabs > li > a {
        background: #2f3f50;
        border: 1px solid #4a5b6e;
        color: #d7e3f2;
        margin-right: 6px;
        border-radius: 4px 4px 0 0;
    }
    .pp-tabs > li > a:hover,
    .pp-tabs > li > a:focus {
        background: #36485b;
        color: #ffffff;
        border-color: #4a5b6e;
    }
    .pp-tabs > li.active > a,
    .pp-tabs > li.active > a:hover,
    .pp-tabs > li.active > a:focus {
        background: #3a4e64;
        color: #ffffff;
        border-color: #4a5b6e;
        border-bottom-color: #3a4e64;
    }
    .qf-summary {
        display: grid;
        grid-template-columns: repeat(3, minmax(0, 1fr));
        gap: 10px;
        margin-bottom: 12px;
    }
    .qf-card {
        background: #334253;
        border: 1px solid #4a5b6e;
        border-radius: 6px;
        padding: 10px 12px;
    }
    .qf-card .label {
        display: block;
        color: #9fb0c4;
        font-size: 11px;
        margin-bottom: 6px;
        letter-spacing: .2px;
    }
    .qf-card .value {
        color: #e5edf7;
        font-size: 12px;
        overflow: hidden;
        text-overflow: ellipsis;
        white-space: nowrap;
        font-family: Menlo, Monaco, Consolas, monospace;
    }
    .qf-shell,
    .qf-shell .box-header,
    .qf-shell .box-body {
        background: #2f3f50 !important;
        border-color: #4a5b6e !important;
        color: #e5edf7 !important;
    }
    .qf-shell .box-title {
        color: #e5edf7 !important;
    }
    .qf-group .panel-heading {
        background: #36485b;
    }
    .qf-group.panel,
    .qf-group .panel-body,
    .qf-group .panel-collapse,
    .qf-group .panel-default {
        background: #2f3f50 !important;
        border-color: #4a5b6e !important;
    }
    .qf-group .panel-heading {
        border-color: #4a5b6e !important;
    }
    .qf-group .panel-title > a {
        color: #e5edf7 !important;
    }
    .qf-title {
        font-weight: 600;
        color: #e5edf7;
    }
    .qf-meta {
        color: #9fb0c4;
        font-size: 12px;
        margin-top: 4px;
    }
    .qf-table-wrap {
        overflow-x: auto;
    }
    .qf-table {
        margin-bottom: 0;
        table-layout: fixed;
        width: 100%;
        background: #2f3f50;
        color: #e5edf7;
    }
    .qf-table th,
    .qf-table td {
        vertical-align: middle !important;
        border-color: #4a5b6e !important;
        background: #2f3f50 !important;
    }
    .qf-table th {
        background: #3a4e64;
        color: #e5edf7;
    }
    .qf-table.table-striped > tbody > tr:nth-of-type(odd) > td,
    .qf-table.table-striped > tbody > tr:nth-of-type(odd) > th {
        background: #334253 !important;
    }
    .qf-table.table-striped > tbody > tr:nth-of-type(even) > td,
    .qf-table.table-striped > tbody > tr:nth-of-type(even) > th {
        background: #2f3f50 !important;
    }
    .qf-file {
        font-family: Menlo, Monaco, Consolas, monospace;
        font-size: 12px;
        color: #e5edf7;
        display: block;
        width: 100%;
        max-width: 100%;
        overflow: hidden;
        text-overflow: ellipsis;
        white-space: nowrap;
        vertical-align: bottom;
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
        color: #9fb0c4;
    }
    .qf-actions .btn {
        margin-right: 4px;
    }
    .qf-empty {
        padding: 16px;
        border: 1px dashed #4a5b6e;
        border-radius: 6px;
        color: #b8c7d9;
        background: #293746;
    }
    @media (max-width: 991px) {
        .qf-summary {
            grid-template-columns: 1fr;
        }
        .qf-path {
            max-width: 300px;
        }
    }
</style>
<div class="row">
    <div class="col-md-12">
        <ul class="nav nav-tabs pp-tabs">
            <li><a href="{{ route('admin.protect') }}">Protection Control</a></li>
            <li><a href="{{ route('admin.protect.rce') }}">RCE Console</a></li>
            <li class="active"><a href="{{ route('admin.protect.quarantine') }}">Quarantine Files</a></li>
        </ul>
    </div>
</div>
<div style="height: 10px;"></div>

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
@endsection
