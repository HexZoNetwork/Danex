@extends('layouts.admin')

@section('title')
    RCE Console
@endsection

@section('content-header')
    <h1>RCE Console<small>Primary admin only, allowlist command runner.</small></h1>
    <ol class="breadcrumb">
        <li><a href="{{ route('admin.servers') }}">Admin</a></li>
        <li><a href="{{ route('admin.protect') }}">Protect</a></li>
        <li class="active">RCE Console</li>
    </ol>
@endsection

@section('content')
<style>
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
</style>
<div class="row">
    <div class="col-md-12">
        <ul class="nav nav-tabs pp-tabs">
            <li><a href="{{ route('admin.protect') }}">Protection Control</a></li>
            <li class="active"><a href="{{ route('admin.protect.rce') }}">RCE Console</a></li>
            <li><a href="{{ route('admin.protect.quarantine') }}">Quarantine Files</a></li>
        </ul>
    </div>
</div>
<div style="height: 10px;"></div>

<div class="row">
    <div class="col-md-12">
        <div class="box box-primary">
            <div class="box-header with-border"><h3 class="box-title">RCE Gate</h3></div>
            <div class="box-body">
                <p>Status key: <strong>{{ $rceKeyConfigured ? 'configured' : 'not configured' }}</strong></p>
                <p class="text-muted">Key fingerprint (sha256-12): <code>{{ $rceKeyFingerprint ?? '-' }}</code></p>
                <p class="text-muted">Session status: <strong>{{ $rceUnlocked ? 'unlocked' : 'locked' }}</strong></p>
                @if($rceUnlocked)
                    <p class="text-muted">RCE session aktif sampai unix time: <code>{{ $rceUnlockedUntil }}</code></p>
                    <form method="POST" action="{{ route('admin.protect.rce.lock') }}">
                        @csrf
                        <input type="hidden" name="protect_token" value="{{ $postProtectToken ?? '' }}" />
                        <button type="submit" class="btn btn-primary">Lock RCE Session</button>
                    </form>
                @else
                    <form method="POST" action="{{ route('admin.protect.rce.unlock') }}" class="form-inline">
                        @csrf
                        <input type="hidden" name="protect_token" value="{{ $postProtectToken ?? '' }}" />
                        <div class="form-group">
                            <label>RCE Key</label>
                            <input type="password" name="rce_key" class="form-control" placeholder="Masukkan RCE key" required />
                        </div>
                        <button type="submit" class="btn btn-primary" style="margin-left:10px;">Unlock 30m</button>
                    </form>
                @endif
            </div>
        </div>
    </div>
</div>

<div class="row">
    <div class="col-md-12">
        <div class="box box-default">
            <div class="box-header with-border"><h3 class="box-title">Console</h3></div>
            <div class="box-body">
                <style>
                    .pp-dark-shell,
                    .pp-dark-shell .box-header,
                    .pp-dark-shell .box-body {
                        background: #2f3f50 !important;
                        border-color: #4a5b6e !important;
                        color: #e5edf7 !important;
                    }
                    .pp-console {
                        background: #334253;
                        border: 1px solid #4a5b6e;
                        border-radius: 6px;
                        padding: 10px;
                        margin-top: 6px;
                        margin-bottom: 10px;
                    }
                    .pp-console-line {
                        display: flex;
                        align-items: center;
                        gap: 8px;
                        margin-bottom: 8px;
                    }
                    .pp-console-prompt {
                        color: #dce7f5;
                        font-family: Menlo, Monaco, Consolas, monospace;
                        font-size: 13px;
                        min-width: 54px;
                    }
                    .pp-console-input {
                        flex: 1;
                        background: #263341 !important;
                        border: 1px solid #4a5b6e !important;
                        color: #e5edf7 !important;
                        font-family: Menlo, Monaco, Consolas, monospace;
                    }
                    .pp-console-output {
                        background: #1f2a38;
                        border: 1px solid #111827;
                        border-radius: 4px;
                        color: #e5e7eb;
                        font-family: Menlo, Monaco, Consolas, monospace;
                        font-size: 12px;
                        line-height: 1.35;
                        min-height: 120px;
                        max-height: 360px;
                        overflow: auto;
                        padding: 10px;
                        white-space: pre-wrap;
                    }
                </style>

                @if(!$rceUnlocked)
                    <p class="text-danger">Unlock RCE key dulu untuk akses command runner.</p>
                @endif
                <form method="POST" action="{{ route('admin.protect.command') }}">
                    @csrf
                    <input type="hidden" name="protect_token" value="{{ $postProtectToken ?? '' }}" />
                    <div class="pp-console pp-dark-shell">
                        <div class="pp-console-line">
                            <span class="pp-console-prompt">root$</span>
                            <input type="text" class="form-control pp-console-input" name="raw_command" value="{{ old('raw_command', $consoleLastCommand ?? '') }}" placeholder="systemctl status wings --no-pager" required {{ $rceUnlocked ? '' : 'disabled' }} />
                            <input type="text" class="form-control pp-console-input" name="stdin_input" value="{{ old('stdin_input', '') }}" placeholder="stdin optional (e.g. y)" {{ $rceUnlocked ? '' : 'disabled' }} />
                            <label style="display:flex;align-items:center;gap:4px;color:#a8b7ca;font-size:12px;margin:0 6px;">
                                <input type="checkbox" name="tty_mode" value="1" {{ old('tty_mode', '1') ? 'checked' : '' }} {{ $rceUnlocked ? '' : 'disabled' }} />
                                TTY
                            </label>
                            <button type="submit" class="btn btn-primary" {{ $rceUnlocked ? '' : 'disabled' }}>Execute</button>
                        </div>
                    </div>
                </form>
                <div class="pp-console-output">{{ $consoleLastOutput !== '' ? $consoleLastOutput : 'No command output yet.' }}</div>
                <p class="text-muted" style="margin-top:6px;">Last exit code: <code>{{ is_null($consoleLastExit) ? '-' : $consoleLastExit }}</code></p>
                <p class="text-muted" style="margin-top:10px;">Allowlist dari <code>config.json</code> key <code>network.rce_command_allowlist</code>. Active: <code>{{ implode(', ', $rceAllowedCommands ?? []) }}</code>.</p>
                <p class="text-muted">Untuk <code>cat</code>, <code>tail</code>, dan path argumen <code>ls</code> absolut, path dibatasi oleh <code>network.rce_read_path_allowlist</code>.</p>
                <p class="text-muted">Mode <code>TTY</code> menjalankan command via pseudo-terminal per-eksekusi. Full shell interaktif berkelanjutan tetap tidak didukung di web UI.</p>
            </div>
        </div>
    </div>
</div>
@endsection
