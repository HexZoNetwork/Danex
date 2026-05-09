@extends('layouts.admin')

@section('title')
    Node Auto Configure
@endsection

@section('content-header')
    <h1>Node: {{ $node->name }}<small>Auto Configure Wings</small></h1>
    <ol class="breadcrumb breadcrumb-sm">
        <li><a href="{{ route('admin.index') }}">Admin</a></li>
        <li><a href="{{ route('admin.nodes') }}">Nodes</a></li>
        <li><a href="{{ route('admin.nodes.view', $node->id) }}">{{ $node->name }}</a></li>
        <li class="active">Auto Configure</li>
    </ol>
@endsection

@section('content')
<div class="row">
    <div class="col-xs-12 col-md-6">
        <div class="box box-primary">
            <div class="box-header with-border"><h3 class="box-title">Start Auto Configure</h3></div>
            <form method="post" action="{{ route('admin.nodes.view.auto-configure.start', $node->id) }}">
                @csrf
                <div class="box-body">
                    <div class="form-group"><label>Target IP/FQDN</label><input class="form-control" name="target_host" required></div>
                    <div class="form-group"><label>SSH Port</label><input class="form-control" name="target_port" value="{{ $defaults['target_port'] }}"></div>
                    <div class="form-group"><label>SSH Username</label><input class="form-control" name="target_username" value="{{ $defaults['target_username'] }}"></div>
                    <div class="form-group"><label>Bootstrap Password (not stored plaintext)</label><input class="form-control" type="password" name="bootstrap_password" required></div>
                    <div class="form-group"><label>Preferred Wings Port</label><input class="form-control" name="wings_port" value="{{ $defaults['wings_port'] }}"></div>
                    <div class="form-group"><label>Fallback Port Range</label><input class="form-control" name="fallback_port_range" value="{{ $defaults['fallback_port_range'] }}"></div>
                    <div class="form-group"><label>Host Key Policy</label>
                        <select class="form-control" name="host_key_policy">
                            <option value="strict_tofu" {{ $defaults['host_key_policy'] === 'strict_tofu' ? 'selected' : '' }}>strict_tofu</option>
                            <option value="strict_pinned" {{ $defaults['host_key_policy'] === 'strict_pinned' ? 'selected' : '' }}>strict_pinned</option>
                        </select>
                    </div>
                    <div class="form-group"><label>Mode</label>
                        <select class="form-control" name="reconfigure_mode">
                            <option value="reconfigure" selected>Reconfigure Existing Node</option>
                            <option value="install">Fresh Install</option>
                        </select>
                    </div>
                    <div class="form-group"><label>Firewall Mode</label>
                        <select class="form-control" name="firewall_mode">
                            <option value="auto" selected>auto</option>
                            <option value="minimal">minimal</option>
                        </select>
                    </div>
                </div>
                <div class="box-footer">
                    <button class="btn btn-primary" type="submit">Start</button>
                </div>
            </form>
        </div>
    </div>

    <div class="col-xs-12 col-md-6">
        <div class="box box-default">
            <div class="box-header with-border"><h3 class="box-title">Realtime Run Logs</h3></div>
            <div class="box-body">
                <p><strong>Latest Run:</strong> {{ $latestRun?->id ?? 'none' }}</p>
                <p><strong>Status:</strong> <span id="run-status">{{ $latestRun?->status ?? 'n/a' }}</span></p>
                <pre id="run-logs" style="max-height:380px;overflow:auto;background:#101822;color:#d0f0ff;padding:12px;">Waiting for run...</pre>
                @if($latestRun)
                    <button id="cancel-btn" data-run-id="{{ $latestRun->id }}" class="btn btn-danger btn-sm" type="button">Cancel Run</button>
                @endif
            </div>
        </div>
    </div>
</div>
@endsection

@section('footer-scripts')
@parent
<script>
(function () {
    const runId = {{ (int) ($latestRun?->id ?? 0) }};
    if (!runId) return;

    const nodeId = {{ (int) $node->id }};
    let afterId = 0;
    const logsEl = document.getElementById('run-logs');
    const statusEl = document.getElementById('run-status');

    async function pull() {
        try {
            const statusResp = await fetch(`/admin/nodes/view/${nodeId}/auto-configure/status/${runId}`);
            if (statusResp.ok) {
                const status = await statusResp.json();
                statusEl.textContent = status.status || 'unknown';
            }

            const logsResp = await fetch(`/admin/nodes/view/${nodeId}/auto-configure/logs/${runId}?after_id=${afterId}`);
            if (logsResp.ok) {
                const data = await logsResp.json();
                (data.items || []).forEach((item) => {
                    logsEl.textContent += `\n[${item.level}] [${item.step}] ${item.message}`;
                });
                afterId = data.next_after_id || afterId;
                logsEl.scrollTop = logsEl.scrollHeight;
            }
        } catch (_e) {}
    }

    setInterval(pull, 2000);
    pull();

    const cancelBtn = document.getElementById('cancel-btn');
    if (cancelBtn) {
        cancelBtn.addEventListener('click', async () => {
            await fetch(`/admin/nodes/view/${nodeId}/auto-configure/cancel/${runId}`, {
                method: 'POST',
                headers: {'X-CSRF-TOKEN': '{{ csrf_token() }}'},
            });
        });
    }
})();
</script>
@endsection
