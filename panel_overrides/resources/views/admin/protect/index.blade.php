@extends('layouts.admin')

@section('title')
    Danex Protect Overview
@endsection

@section('content-header')
    <h1>Danex Protect Overview<small>Status first, then controlled mitigation actions.</small></h1>
    <ol class="breadcrumb">
        <li><a href="{{ route('admin.servers') }}">Admin</a></li>
        <li class="active">Protect</li>
    </ol>
@endsection

@section('content')
@include('admin.protect.partials.styles')
<div class="pp-theme">
@include('admin.protect.partials.tabs', ['active' => 'control'])
	<div class="row">
	    <div class="col-md-12">
	        <div class="box box-default">
	            <div class="box-header with-border">
	                <h3 class="box-title">Runtime Status</h3>
            </div>
            <div class="box-body">
                <pre class="pp-runtime-status">{{ trim((string) $modeStatus) !== '' ? $modeStatus : 'Status belum tersedia.' }}</pre>
                <p><strong>Config path:</strong> <code>{{ $configPath }}</code></p>
            </div>
	        </div>
	    </div>
	</div>

	@php
	    $selfHeal = is_array($selfHealSnapshot ?? null) ? $selfHealSnapshot : [];
	    $snapshotTs = (int) ($selfHeal['ts'] ?? 0);
	    $snapshotAge = $snapshotTs > 0 ? max(0, time() - $snapshotTs) : null;
	@endphp
	<div class="row">
	    <div class="col-md-12">
	        <div class="box box-info">
	            <div class="box-header with-border"><h3 class="box-title">Web Check Snapshot</h3></div>
	            <div class="box-body">
	                <div class="row">
	                    <div class="col-sm-3">
	                        <p class="text-muted">External</p>
	                        <h4>{{ ($selfHeal['external_ok'] ?? false) ? 'OK' : 'Not OK' }}</h4>
	                    </div>
	                    <div class="col-sm-3">
	                        <p class="text-muted">Origin</p>
	                        <h4>{{ ($selfHeal['challenge_ok'] ?? false) ? 'OK' : 'Not OK' }}</h4>
	                    </div>
	                    <div class="col-sm-3">
	                        <p class="text-muted">Latency</p>
	                        <h4>{{ number_format((float) ($selfHeal['external_latency_ms'] ?? 0), 1) }} ms</h4>
	                    </div>
	                    <div class="col-sm-3">
	                        <p class="text-muted">Age</p>
	                        <h4>{{ $snapshotAge === null ? '-' : $snapshotAge . 's' }}</h4>
	                    </div>
	                </div>
	                <table class="table table-bordered pp-table" style="margin-top: 10px;">
	                    <tbody>
	                    <tr>
	                        <th style="width: 180px;">Source</th>
	                        <td><code>{{ $selfHeal['source'] ?? 'not available' }}</code></td>
	                    </tr>
	                    <tr>
	                        <th>External API</th>
	                        <td><code>{{ $selfHeal['check_api_url'] ?? '-' }}</code></td>
	                    </tr>
	                    <tr>
	                        <th>Origin Probe</th>
	                        <td><code>{{ $selfHeal['origin_probe_url'] ?? '-' }}</code></td>
	                    </tr>
	                    <tr>
	                        <th>External Limit</th>
	                        <td>{{ (int) ($selfHeal['external_check_min_interval_sec'] ?? 60) }}s min interval, {{ (int) ($selfHeal['external_check_cache_ttl_sec'] ?? 120) }}s cache TTL</td>
	                    </tr>
	                    <tr>
	                        <th>Snapshot File</th>
	                        <td><code>{{ $selfHeal['path'] ?? '-' }}</code></td>
	                    </tr>
	                    </tbody>
	                </table>
	            </div>
	        </div>
	    </div>
	</div>

	<div class="row">
	    <div class="col-md-6">
        <div class="box box-primary">
            <div class="box-header with-border"><h3 class="box-title">Protection Mode</h3></div>
            <div class="box-body">
                <p class="text-muted">Emergency and lockdown modes can block legitimate users. Apply them only during active incidents.</p>
                <form method="POST" action="{{ route('admin.protect.mode') }}" data-confirm-action="Apply protection mode change. High-impact modes can block traffic. Type APPLY to continue." data-confirm-token="APPLY">
                    @csrf
                    <input type="hidden" name="protect_token" value="{{ $postProtectToken ?? '' }}" />
                    <div class="form-group">
                        <label for="protect-mode">Mode</label>
                        <select id="protect-mode" name="mode" class="form-control">
                            <option value="normal">normal</option>
                            <option value="aggressive">aggressive</option>
                            <option value="emergency">emergency</option>
                            <option value="lockdown">lockdown</option>
                            <option value="clear-lockdown">clear-lockdown</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="protect-mode-ttl">TTL (seconds)</label>
                        <input id="protect-mode-ttl" type="number" min="60" max="86400" name="ttl" value="600" class="form-control" />
                    </div>
                    <button type="submit" class="btn btn-primary">Apply Mode</button>
                </form>
            </div>
        </div>
    </div>

    <div class="col-md-6">
        <div class="box box-default">
            <div class="box-header with-border"><h3 class="box-title">Protection State</h3></div>
            <div class="box-body">
                <p class="text-muted">Disabling protection reduces WAF/runtime coverage. Prefer mode changes unless you are troubleshooting.</p>
                <form method="POST" action="{{ route('admin.protect.config') }}">
                    @csrf
                    <input type="hidden" name="protect_token" value="{{ $postProtectToken ?? '' }}" />
                    <div class="form-group">
                        <label for="protect-enabled">Protection State</label>
                        <select id="protect-enabled" name="enabled" class="form-control">
                            <option value="1">Enable</option>
                            <option value="0">Disable</option>
                        </select>
                    </div>
                    <button type="submit" class="btn btn-primary">Update config.json</button>
                </form>
                <p class="text-muted" style="margin-top: 10px;">Jika panel user tidak punya izin tulis ke config, pakai root CLI.</p>
            </div>
        </div>
    </div>
</div>

<div class="row">
    <div class="col-md-6">
        <div class="box box-warning">
            <div class="box-header with-border"><h3 class="box-title">Create Panel Access</h3></div>
            <div class="box-body">
                <form method="POST" action="{{ route('admin.protect.create_panel_web') }}">
                    @csrf
                    <input type="hidden" name="protect_token" value="{{ $postProtectToken ?? '' }}" />
                    <div class="form-group">
                        <label for="create-panel-web-enabled">Create Panel on Web</label>
                        <select id="create-panel-web-enabled" name="create_panel_web_enabled" class="form-control">
                            <option value="1" @if(($createPanelWebEnabled ?? true) === true) selected @endif>ON</option>
                            <option value="0" @if(($createPanelWebEnabled ?? true) === false) selected @endif>OFF</option>
                        </select>
                    </div>
                    <button type="submit" class="btn btn-warning">Apply Create Panel Toggle</button>
                </form>
                <hr>
                <form method="POST" action="{{ route('admin.protect.create_panel_auto_suspend') }}">
                    @csrf
                    <input type="hidden" name="protect_token" value="{{ $postProtectToken ?? '' }}" />
                    <div class="form-group">
                        <label for="create-panel-auto-suspend-enabled">Create Panel Auto Suspend</label>
                        <select id="create-panel-auto-suspend-enabled" name="create_panel_auto_suspend_enabled" class="form-control">
                            <option value="1" @if(($createPanelAutoSuspendEnabled ?? false) === true) selected @endif>ON</option>
                            <option value="0" @if(($createPanelAutoSuspendEnabled ?? false) === false) selected @endif>OFF</option>
                        </select>
                    </div>
                    <button type="submit" class="btn btn-warning">Apply Auto Suspend Toggle</button>
                </form>
            </div>
        </div>
    </div>
    <div class="col-md-6">
        <div class="box box-danger">
            <div class="box-header with-border"><h3 class="box-title">Offline Server Cleanup</h3></div>
            <div class="box-body">
                <p class="text-danger">Deletes all servers whose Wings power state is offline, not only Create Panel or madeinweb servers.</p>
                <p class="text-muted">After offline servers are gone, every non-admin user with no remaining servers is deleted. Admin users are never deleted.</p>
                <p class="text-muted">Cleanup now runs as a server-side queue job, so the browser does not need to wait for every Wings/API call.</p>
                @if(isset($latestCleanupRun) && $latestCleanupRun)
                    <div id="cleanup-run-status" class="alert alert-info" data-status-url="{{ route('admin.protect.create_panel_cleanup.status', $latestCleanupRun->id) }}">
                        <strong>Latest cleanup job #{{ $latestCleanupRun->id }}:</strong>
                        <span data-cleanup-field="status">{{ $latestCleanupRun->status }}</span>
                        <div class="small" style="margin-top:6px;">
                            Checked <span data-cleanup-field="checked_servers">{{ $latestCleanupRun->checked_servers }}</span>,
                            deleted servers <span data-cleanup-field="deleted_servers">{{ $latestCleanupRun->deleted_servers }}</span>,
                            deleted users <span data-cleanup-field="deleted_users">{{ $latestCleanupRun->deleted_users }}</span>,
                            failures <span data-cleanup-field="failed_count">{{ $latestCleanupRun->failed_count }}</span>.
                        </div>
                        <div class="small text-muted" data-cleanup-field="message">{{ implode(' | ', (array) ($latestCleanupRun->messages ?? [])) }}</div>
                    </div>
                @endif
                <form method="POST" action="{{ route('admin.protect.create_panel_cleanup') }}" data-confirm-action="Queue offline cleanup job. This can delete offline servers and empty non-admin owners. Type CLEANUP to continue." data-confirm-token="CLEANUP">
                    @csrf
                    <input type="hidden" name="protect_token" value="{{ $postProtectToken ?? '' }}" />
                    <button type="submit" class="btn btn-danger">Queue Offline Cleanup Job</button>
                </form>
            </div>
        </div>
    </div>
</div>

<div class="row">
    <div class="col-md-12">
        <div class="box box-default">
            <div class="box-header with-border"><h3 class="box-title">RCE Key Management</h3></div>
            <div class="box-body">
                <p>Status key: <strong>{{ $rceKeyConfigured ? 'configured' : 'not configured' }}</strong></p>
                <p class="text-muted">Key fingerprint (sha256-12): <code>{{ $rceKeyFingerprint ?? '-' }}</code></p>
                <p class="text-muted">Raw shell tidak diaktifkan. Command endpoint tetap butuh RCE key + id=1 + protect verify.</p>
                <form method="POST" action="{{ route('admin.protect.rce_key') }}" class="form-inline">
                    @csrf
                    <input type="hidden" name="protect_token" value="{{ $postProtectToken ?? '' }}" />
                    <div class="form-group">
                        <label for="protect-rce-key">New RCE Key</label>
                        <input id="protect-rce-key" type="password" name="new_key" class="form-control" placeholder="min 32 chars" required />
                    </div>
                    <button type="submit" class="btn btn-primary" style="margin-left:10px;">Update Key</button>
                </form>
            </div>
        </div>
    </div>
</div>

<div class="row">
    <div class="col-md-12">
        <div class="box box-default">
            <div class="box-header with-border"><h3 class="box-title">Break-glass Access</h3></div>
            <div class="box-body">
                <p class="text-muted">RCE dipisah ke tab sendiri. Unlock satu kali pakai RCE key, lalu jalankan command tanpa isi key berulang.</p>
                <p class="text-muted">RCE status: <strong>{{ $rceUnlocked ? 'unlocked' : 'locked' }}</strong></p>
                <p class="text-muted">Allowlist command aktif: <code>{{ implode(', ', $rceAllowedCommands ?? []) }}</code></p>
                <a href="{{ route('admin.protect.rce') }}" class="btn btn-danger">Open Break-glass Diagnostics</a>
            </div>
        </div>
    </div>
</div>

<div class="row">
    <div class="col-md-12">
        <div class="box box-primary">
            <div class="box-header with-border"><h3 class="box-title">Allowed Wings Hosts</h3></div>
            <div class="box-body">
                <form method="POST" action="{{ route('admin.protect.allowed_wings') }}">
                    @csrf
                    <input type="hidden" name="protect_token" value="{{ $postProtectToken ?? '' }}" />
                    <div class="form-group">
                        <label for="allowed-wings-hosts">Host/FQDN/IP (satu per baris atau pisah koma)</label>
                        <textarea id="allowed-wings-hosts" class="form-control" name="allowed_wings_hosts" rows="6" placeholder="node-1.example.com&#10;node-2.example.com">{{ old('allowed_wings_hosts', $allowedWingsHostsText ?? '') }}</textarea>
                    </div>
                    <button type="submit" class="btn btn-primary">Save Allowed Wings Hosts</button>
                </form>
                <p class="text-muted" style="margin-top: 10px;">Disimpan ke <code>network.trusted_hosts</code> di config.json dan langsung auto-apply ke host rules. Jika auto-apply gagal, baru jalankan <code>setup.sh</code>.</p>
            </div>
        </div>
    </div>
</div>

<div class="row">
    <div class="col-md-8">
        <div class="box box-default">
            <div class="box-header with-border"><h3 class="box-title">Service Control</h3></div>
            <div class="box-body">
                <table class="table table-bordered pp-table">
                    <thead>
                    <tr>
                        <th>Service</th>
                        <th>Status</th>
                    </tr>
                    </thead>
                    <tbody>
                    @foreach($serviceStates as $name => $state)
                        <tr>
                            <td><code>{{ $name }}</code></td>
                            <td>{{ $state }}</td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>

                <form method="POST" action="{{ route('admin.protect.service') }}" class="form-inline" style="margin-top:10px;" data-confirm-action="Run service control action. Restart/stop can interrupt protection services. Type SERVICE to continue." data-confirm-token="SERVICE">
                    @csrf
                    <input type="hidden" name="protect_token" value="{{ $postProtectToken ?? '' }}" />
                    <div class="form-group">
                        <label for="protect-service">Service</label>
                        <select id="protect-service" class="form-control" name="service">
                            @foreach($serviceStates as $name => $state)
                                <option value="{{ $name }}">{{ $name }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="form-group" style="margin-left:10px;">
                        <label for="protect-service-action">Action</label>
                        <select id="protect-service-action" class="form-control" name="action">
                            <option value="restart">restart</option>
                            <option value="reload">reload</option>
                            <option value="start">start</option>
                            <option value="stop">stop</option>
                        </select>
                    </div>
                    <button type="submit" class="btn btn-warning" style="margin-left:10px;">Run Service Action</button>
                </form>
            </div>
        </div>
    </div>

    <div class="col-md-4">
        <div class="box box-default">
            <div class="box-header with-border"><h3 class="box-title">Danger Zone</h3></div>
            <div class="box-body">
                <p class="text-danger">Reboot interrupts panel, Wings, and protection services.</p>
                <form method="POST" action="{{ route('admin.protect.reboot') }}">
                    @csrf
                    <input type="hidden" name="protect_token" value="{{ $postProtectToken ?? '' }}" />
                    <div class="form-group">
                        <label for="protect-reboot-confirm">Type REBOOT to confirm</label>
                        <input id="protect-reboot-confirm" type="text" class="form-control" name="confirm" placeholder="REBOOT" />
                    </div>
                    <button type="submit" class="btn btn-danger">Reboot Host</button>
                </form>
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
    var answer = window.prompt(message);
    if (answer !== token) {
        event.preventDefault();
    }
});

(function pollCleanupRun() {
    var box = document.getElementById('cleanup-run-status');
    if (!box) return;
    var url = box.getAttribute('data-status-url');
    if (!url || !window.fetch) return;
    function setField(name, value) {
        var el = box.querySelector('[data-cleanup-field="' + name + '"]');
        if (el) el.textContent = value == null ? '' : String(value);
    }
    function tick() {
        fetch(url, { credentials: 'same-origin', headers: { 'Accept': 'application/json' } })
            .then(function (response) { return response.ok ? response.json() : null; })
            .then(function (data) {
                if (!data) return;
                setField('status', data.status);
                setField('checked_servers', data.checked_servers);
                setField('deleted_servers', data.deleted_servers);
                setField('deleted_users', data.deleted_users);
                setField('failed_count', data.failed_count);
                setField('message', (data.messages || []).join(' | ') || data.last_error_message || '');
                if (data.status === 'pending' || data.status === 'running') {
                    window.setTimeout(tick, 2500);
                }
            })
            .catch(function () { window.setTimeout(tick, 5000); });
    }
    tick();
})();
</script>
@endsection
