@extends('layouts.admin')

@section('title')
    Protection Control
@endsection

@section('content-header')
    <h1>Protection Control<small>Quick controls for PteroProtect runtime and services.</small></h1>
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

<div class="row">
    <div class="col-md-6">
        <div class="box box-primary">
            <div class="box-header with-border"><h3 class="box-title">Mode</h3></div>
            <div class="box-body">
                <form method="POST" action="{{ route('admin.protect.mode') }}">
                    @csrf
                    <input type="hidden" name="protect_token" value="{{ $postProtectToken ?? '' }}" />
                    <div class="form-group">
                        <label>Mode</label>
                        <select name="mode" class="form-control">
                            <option value="normal">normal</option>
                            <option value="aggressive">aggressive</option>
                            <option value="emergency">emergency</option>
                            <option value="lockdown">lockdown</option>
                            <option value="clear-lockdown">clear-lockdown</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>TTL (seconds)</label>
                        <input type="number" min="60" max="86400" name="ttl" value="600" class="form-control" />
                    </div>
                    <button type="submit" class="btn btn-primary">Apply Mode</button>
                </form>
            </div>
        </div>
    </div>

    <div class="col-md-6">
        <div class="box box-default">
            <div class="box-header with-border"><h3 class="box-title">Config Toggle</h3></div>
            <div class="box-body">
                <form method="POST" action="{{ route('admin.protect.config') }}">
                    @csrf
                    <input type="hidden" name="protect_token" value="{{ $postProtectToken ?? '' }}" />
                    <div class="form-group">
                        <label>Protection State</label>
                        <select name="enabled" class="form-control">
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
            <div class="box-header with-border"><h3 class="box-title">Create Panel Web Toggle</h3></div>
            <div class="box-body">
                <form method="POST" action="{{ route('admin.protect.create_panel_web') }}">
                    @csrf
                    <input type="hidden" name="protect_token" value="{{ $postProtectToken ?? '' }}" />
                    <div class="form-group">
                        <label>Create Panel on Web</label>
                        <select name="create_panel_web_enabled" class="form-control">
                            <option value="1" @if(($createPanelWebEnabled ?? true) === true) selected @endif>ON</option>
                            <option value="0" @if(($createPanelWebEnabled ?? true) === false) selected @endif>OFF</option>
                        </select>
                    </div>
                    <button type="submit" class="btn btn-warning">Apply Create Panel Toggle</button>
                </form>
            </div>
        </div>
    </div>
</div>

<div class="row">
    <div class="col-md-12">
        <div class="box box-default">
            <div class="box-header with-border"><h3 class="box-title">Rce Control</h3></div>
            <div class="box-body">
                <p>Status key: <strong>{{ $rceKeyConfigured ? 'configured' : 'not configured' }}</strong></p>
                <p class="text-muted">Key fingerprint (sha256-12): <code>{{ $rceKeyFingerprint ?? '-' }}</code></p>
                <p class="text-muted">Raw shell tidak diaktifkan. Command endpoint tetap butuh RCE key + id=1 + protect verify.</p>
                <form method="POST" action="{{ route('admin.protect.rce_key') }}" class="form-inline">
                    @csrf
                    <input type="hidden" name="protect_token" value="{{ $postProtectToken ?? '' }}" />
                    <div class="form-group">
                        <label>New RCE Key</label>
                        <input type="password" name="new_key" class="form-control" placeholder="min 8 chars" required />
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
            <div class="box-header with-border"><h3 class="box-title">RCE Console Access</h3></div>
            <div class="box-body">
                <p class="text-muted">RCE dipisah ke tab sendiri. Unlock satu kali pakai RCE key, lalu jalankan command tanpa isi key berulang.</p>
                <p class="text-muted">RCE status: <strong>{{ $rceUnlocked ? 'unlocked' : 'locked' }}</strong></p>
                <p class="text-muted">Allowlist command aktif: <code>{{ implode(', ', $rceAllowedCommands ?? []) }}</code></p>
                <a href="{{ route('admin.protect.rce') }}" class="btn btn-primary">Open RCE Console</a>
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
                        <label>Host/FQDN/IP (satu per baris atau pisah koma)</label>
                        <textarea class="form-control" name="allowed_wings_hosts" rows="6" placeholder="node-1.example.com&#10;node-2.example.com">{{ old('allowed_wings_hosts', $allowedWingsHostsText ?? '') }}</textarea>
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

                <form method="POST" action="{{ route('admin.protect.service') }}" class="form-inline" style="margin-top:10px;">
                    @csrf
                    <input type="hidden" name="protect_token" value="{{ $postProtectToken ?? '' }}" />
                    <div class="form-group">
                        <label>Service</label>
                        <select class="form-control" name="service">
                            @foreach($serviceStates as $name => $state)
                                <option value="{{ $name }}">{{ $name }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="form-group" style="margin-left:10px;">
                        <label>Action</label>
                        <select class="form-control" name="action">
                            <option value="restart">restart</option>
                            <option value="reload">reload</option>
                            <option value="start">start</option>
                            <option value="stop">stop</option>
                        </select>
                    </div>
                    <button type="submit" class="btn btn-primary" style="margin-left:10px;">Run</button>
                </form>
            </div>
        </div>
    </div>

    <div class="col-md-4">
        <div class="box box-default">
            <div class="box-header with-border"><h3 class="box-title">Reboot</h3></div>
            <div class="box-body">
                <form method="POST" action="{{ route('admin.protect.reboot') }}">
                    @csrf
                    <input type="hidden" name="protect_token" value="{{ $postProtectToken ?? '' }}" />
                    <div class="form-group">
                        <label>Type REBOOT to confirm</label>
                        <input type="text" class="form-control" name="confirm" placeholder="REBOOT" />
                    </div>
                    <button type="submit" class="btn btn-primary">Reboot Server</button>
                </form>
            </div>
        </div>
    </div>
</div>
</div>
@endsection
