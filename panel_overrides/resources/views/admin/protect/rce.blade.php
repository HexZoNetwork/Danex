@extends('layouts.admin')

@section('title')
    Break-glass Diagnostics
@endsection

@section('content-header')
    <h1>Break-glass Diagnostics<small>Primary admin only, audited emergency access.</small></h1>
    <ol class="breadcrumb">
        <li><a href="{{ route('admin.servers') }}">Admin</a></li>
        <li><a href="{{ route('admin.protect') }}">Protect</a></li>
        <li class="active">Break-glass</li>
    </ol>
@endsection

@section('content')
@include('admin.protect.partials.styles')
<div class="pp-theme">
@include('admin.protect.partials.tabs', ['active' => 'rce'])

<div class="row">
    <div class="col-md-12">
        <div class="box box-danger">
            <div class="box-header with-border"><h3 class="box-title">Break-glass Gate</h3></div>
            <div class="box-body">
                <p class="text-danger">Use only during active incidents. Unlocking enables full root terminal access and all actions are audited.</p>
                <p>Status key: <strong>{{ $rceKeyConfigured ? 'configured' : 'not configured' }}</strong></p>
                <p class="text-muted">Key fingerprint (sha256-12): <code>{{ $rceKeyFingerprint ?? '-' }}</code></p>
                <p class="text-muted">Session status: <strong>{{ $rceUnlocked ? 'unlocked' : 'locked' }}</strong></p>
                @if($rceUnlocked)
                    <p class="text-muted">RCE session aktif sampai unix time: <code>{{ $rceUnlockedUntil }}</code></p>
                    <form method="POST" action="{{ route('admin.protect.rce.lock') }}">
                        @csrf
                        <input type="hidden" name="protect_token" value="{{ $postProtectToken ?? '' }}" />
                        <button type="submit" class="btn btn-warning">Lock Break-glass Session</button>
                    </form>
                @else
                    <form method="POST" action="{{ route('admin.protect.rce.unlock') }}" class="form-inline">
                        @csrf
                        <input type="hidden" name="protect_token" value="{{ $postProtectToken ?? '' }}" />
                        <div class="form-group">
                            <label for="rce-unlock-key">RCE Key</label>
                            <input id="rce-unlock-key" type="password" name="rce_key" class="form-control" placeholder="Masukkan RCE key" required />
                        </div>
                         <button type="submit" class="btn btn-danger" style="margin-left:10px;">Unlock Break-glass 30m</button>
                    </form>
                @endif
            </div>
        </div>
    </div>
</div>

<div class="row">
    <div class="col-md-12">
        <div class="box box-default">
            <div class="box-header with-border"><h3 class="box-title">Full Root Terminal</h3></div>
            <div class="box-body">
                <link rel="stylesheet" href="/vendor/pteroprotect/xterm.css">
                <style>
                    #pp-pty-terminal {
                        height: 560px;
                        background: #07070b;
                        border: 1px solid rgba(239, 68, 68, 0.34);
                        border-radius: 6px;
                        padding: 6px;
                    }
                    .pp-pty-toolbar {
                        display: flex;
                        align-items: center;
                        gap: 8px;
                        margin-bottom: 10px;
                    }
                    .pp-pty-status {
                        color: #a6a6b8;
                        font-family: Menlo, Monaco, Consolas, monospace;
                        font-size: 12px;
                    }
                    .pp-dark-shell,
                    .pp-dark-shell .box-header,
                    .pp-dark-shell .box-body {
                        background: #0b0b10 !important;
                        border-color: rgba(139, 92, 246, 0.24) !important;
                        color: #f7f7fb !important;
                    }
                    .pp-console {
                        background: #09090d;
                        border: 1px solid rgba(139, 92, 246, 0.24);
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
                        color: #f7f7fb;
                        font-family: Menlo, Monaco, Consolas, monospace;
                        font-size: 13px;
                        min-width: 54px;
                    }
                    .pp-console-input {
                        flex: 1;
                        background: #07070b !important;
                        border: 1px solid rgba(139, 92, 246, 0.24) !important;
                        color: #f7f7fb !important;
                        font-family: Menlo, Monaco, Consolas, monospace;
                    }
                     .pp-console-output {
                        background: #0b0b10;
                        border: 1px solid #07070b;
                        border-radius: 4px;
                        color: #f7f7fb;
                        font-family: Menlo, Monaco, Consolas, monospace;
                        font-size: 12px;
                        line-height: 1.35;
                        min-height: 120px;
                        max-height: 360px;
                        overflow: auto;
                        padding: 10px;
                         white-space: pre-wrap;
                     }
                     @media (max-width: 767px) {
                         .pp-console-line {
                             align-items: stretch;
                             flex-direction: column;
                         }
                         .pp-console-prompt {
                             min-width: 0;
                         }
                         .pp-console-input,
                         .pp-console-line .btn {
                             width: 100%;
                         }
                     }
                </style>

                @if(!$rceUnlocked)
                    <p class="text-danger">Unlock break-glass key dulu untuk akses full root terminal.</p>
                @endif
                <div class="pp-pty-toolbar">
                    <button type="button" id="pp-pty-connect" class="btn btn-danger btn-lg" {{ $rceUnlocked ? '' : 'disabled' }}>Start Full Root Terminal</button>
                    <button type="button" id="pp-pty-disconnect" class="btn btn-default" disabled>Disconnect</button>
                    <span id="pp-pty-status" class="pp-pty-status">{{ $rceUnlocked ? 'ready' : 'locked' }}</span>
                </div>
                <div id="pp-pty-terminal"></div>
                <p class="text-danger" style="margin-top:10px;">Full root terminal bypasses command templates. Use it only when SSH or normal admin flows are unavailable.</p>
                <p class="text-muted">One-time ticket, local root helper, idle timeout, and audit metadata are enforced server-side.</p>

                <hr>
                <h4>Fallback: Allowlisted Command Template</h4>
                <p class="text-muted">Optional diagnostics fallback kalau terminal penuh tidak dibutuhkan.</p>
                <form method="POST" action="{{ route('admin.protect.command') }}" data-confirm-action="Execute allowlisted root command. Verify template and target values. Type EXECUTE to continue." data-confirm-token="EXECUTE">
                    @csrf
                    <input type="hidden" name="protect_token" value="{{ $postProtectToken ?? '' }}" />
                    <div class="pp-console pp-dark-shell">
                        <div class="pp-console-line">
                            <span class="pp-console-prompt">template</span>
                            <label class="sr-only" for="rce-template">Command template</label>
                            <select id="rce-template" class="form-control pp-console-input" name="template_id" required {{ $rceUnlocked ? '' : 'disabled' }}>
                                <option value="">Select command template</option>
                                @foreach(($rceCommandTemplates ?? []) as $templateId => $templateMeta)
                                    <option value="{{ $templateId }}" {{ old('template_id') === $templateId ? 'selected' : '' }}>
                                        {{ $templateMeta['label'] ?? $templateId }}
                                    </option>
                                @endforeach
                            </select>
                            <label class="sr-only" for="rce-service">Service</label>
                            <input id="rce-service" type="text" class="form-control pp-console-input" name="service" value="{{ old('service', 'nginx') }}" placeholder="service (for systemctl status)" {{ $rceUnlocked ? '' : 'disabled' }} />
                            <label class="sr-only" for="rce-unit">Unit</label>
                            <input id="rce-unit" type="text" class="form-control pp-console-input" name="unit" value="{{ old('unit', 'nginx') }}" placeholder="unit (for journal tail)" {{ $rceUnlocked ? '' : 'disabled' }} />
                            <label class="sr-only" for="rce-path">Log path</label>
                            <input id="rce-path" type="text" class="form-control pp-console-input" name="path" value="{{ old('path', '/var/log/nginx/error.log') }}" placeholder="log path (for tail_logs)" {{ $rceUnlocked ? '' : 'disabled' }} />
                            <label class="sr-only" for="rce-lines">Lines</label>
                            <input id="rce-lines" type="number" min="1" max="2000" class="form-control pp-console-input" name="lines" value="{{ old('lines', 200) }}" placeholder="lines" {{ $rceUnlocked ? '' : 'disabled' }} />
                            <label style="display:flex;align-items:center;gap:4px;color:#a6a6b8;font-size:12px;margin:0 6px;">
                                <input type="checkbox" name="tty_mode" value="1" {{ old('tty_mode', '1') ? 'checked' : '' }} {{ $rceUnlocked ? '' : 'disabled' }} />
                                TTY
                            </label>
                            <button type="submit" class="btn btn-default" {{ $rceUnlocked ? '' : 'disabled' }}>Run Command Template</button>
                        </div>
                    </div>
                </form>
                <div class="pp-console-output">{{ $consoleLastOutput !== '' ? $consoleLastOutput : 'No command output yet.' }}</div>
                <p class="text-muted" style="margin-top:6px;">Last exit code: <code>{{ is_null($consoleLastExit) ? '-' : $consoleLastExit }}</code></p>
                <p class="text-muted" style="margin-top:10px;">Template mode aktif. Source template: <code>network.admin_exec_templates</code>.</p>
                <p class="text-muted">Path untuk template <code>tail_logs</code> dibatasi oleh <code>network.rce_read_path_allowlist</code> dan validasi <code>realpath</code>.</p>
                <p class="text-muted">Mode <code>TTY</code> menjalankan command via pseudo-terminal per-eksekusi. Full root terminal tersedia di atas.</p>
                <script src="/vendor/pteroprotect/xterm.js"></script>
                <script>
                    (function () {
                        var unlocked = {{ $rceUnlocked ? 'true' : 'false' }};
                        var token = @json($postProtectToken ?? '');
                        var csrf = @json(csrf_token());
                        var termEl = document.getElementById('pp-pty-terminal');
                        var statusEl = document.getElementById('pp-pty-status');
                        var connectBtn = document.getElementById('pp-pty-connect');
                        var disconnectBtn = document.getElementById('pp-pty-disconnect');
                        var term = null;
                        var ws = null;

                        function setStatus(text) {
                            if (statusEl) statusEl.textContent = text;
                        }

                        function csrfToken() {
                            var meta = document.querySelector('meta[name="csrf-token"]');
                            return (meta && meta.getAttribute('content')) || csrf || '';
                        }

                        function initTerm() {
                            if (term) return term;
                            if (!window.Terminal) {
                                setStatus('xterm asset missing');
                                return null;
                            }
                            term = new window.Terminal({
                                cursorBlink: true,
                                fontFamily: 'Menlo, Monaco, Consolas, monospace',
                                fontSize: 13,
                                theme: { background: '#07070b', foreground: '#f7f7fb' },
                                cols: 120,
                                rows: 32
                            });
                            term.open(termEl);
                            return term;
                        }

                        async function createSession() {
                            var resp = await fetch('{{ route('admin.protect.terminal.sessions') }}', {
                                method: 'POST',
                                credentials: 'same-origin',
                                headers: {
                                    'Accept': 'application/json',
                                    'Content-Type': 'application/json',
                                    'X-CSRF-TOKEN': csrfToken(),
                                    'X-PteroProtect-Token': token
                                },
                                body: JSON.stringify({ protect_token: token, _token: csrfToken() })
                            });
                            if (!resp.ok) throw new Error('ticket failed: HTTP ' + resp.status);
                            var data = await resp.json();
                            if (!data.ok || !data.ws_url) throw new Error(data.error || 'ticket rejected');
                            return data.ws_url;
                        }

                        function resize() {
                            if (!ws || ws.readyState !== WebSocket.OPEN || !term) return;
                            ws.send(JSON.stringify({ type: 'resize', cols: term.cols || 120, rows: term.rows || 32 }));
                        }

                        async function connect() {
                            if (!unlocked || ws) return;
                            var t = initTerm();
                            if (!t) return;
                            connectBtn.disabled = true;
                            setStatus('creating ticket');
                            try {
                                var wsUrl = await createSession();
                                var proto = window.location.protocol === 'https:' ? 'wss://' : 'ws://';
                                ws = new WebSocket(proto + window.location.host + wsUrl);
                                ws.binaryType = 'arraybuffer';
                                ws.onopen = function () {
                                    disconnectBtn.disabled = false;
                                    setStatus('connected');
                                    t.focus();
                                    resize();
                                };
                                ws.onmessage = function (event) {
                                    if (event.data instanceof ArrayBuffer) {
                                        t.write(new Uint8Array(event.data));
                                    } else {
                                        t.write(String(event.data));
                                    }
                                };
                                ws.onclose = function () {
                                    ws = null;
                                    connectBtn.disabled = false;
                                    disconnectBtn.disabled = true;
                                    setStatus('disconnected');
                                };
                                ws.onerror = function () { setStatus('websocket error'); };
                                t.onData(function (data) {
                                    if (ws && ws.readyState === WebSocket.OPEN) ws.send(data);
                                });
                            } catch (err) {
                                connectBtn.disabled = false;
                                setStatus(String(err && err.message ? err.message : err));
                            }
                        }

                        connectBtn && connectBtn.addEventListener('click', function () {
                            if (window.prompt('Start full interactive root terminal. This is break-glass access and is audited. Type ROOT to continue.') !== 'ROOT') return;
                            connect();
                        });
                        disconnectBtn && disconnectBtn.addEventListener('click', function () {
                            if (ws) ws.close();
                        });
                    })();
                </script>
            </div>
        </div>
    </div>
</div>
</div>
<script>
document.addEventListener('submit', function (event) {
    var form = event.target;
    if (!form || !form.getAttribute) return;
    var message = form.getAttribute('data-confirm-action');
    var token = form.getAttribute('data-confirm-token');
    if (!message || !token) return;
    if (window.prompt(message) !== token) {
        event.preventDefault();
    }
});
</script>
@endsection
