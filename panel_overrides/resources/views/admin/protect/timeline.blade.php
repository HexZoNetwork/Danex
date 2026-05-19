@extends('layouts.admin')

@section('title')
    Security Events
@endsection

@section('content-header')
    <h1>Security Events<small>Riwayat pelanggaran, quarantine, dan aktivitas keamanan panel.</small></h1>
    <ol class="breadcrumb">
        <li><a href="{{ route('admin.servers') }}">Admin</a></li>
        <li><a href="{{ route('admin.protect') }}">Protect</a></li>
        <li class="active">Timeline</li>
    </ol>
@endsection

@section('content')
@include('admin.protect.partials.styles')
<div class="pp-theme">
@include('admin.protect.partials.tabs', ['active' => 'timeline'])

<div class="row">
    <div class="col-md-12">
        <div class="box box-default">
            <div class="box-header with-border"><h3 class="box-title">Filter</h3></div>
            <div class="box-body">
                <form method="GET" action="{{ route('admin.protect.timeline') }}" class="form-inline">
                    <div class="form-group">
                        <label>User ID</label>
                        <input type="number" min="0" class="form-control" name="user_id" value="{{ (int) ($filters['user_id'] ?? 0) }}" placeholder="0 = all" />
                    </div>
                    <div class="form-group" style="margin-left:10px;">
                        <label>Server ID</label>
                        <input type="number" min="0" class="form-control" name="server_id" value="{{ (int) ($filters['server_id'] ?? 0) }}" placeholder="0 = all" />
                    </div>
                    <div class="form-group" style="margin-left:10px;">
                        <label>Type/Event</label>
                        <input type="text" class="form-control" name="violation_type" value="{{ (string) ($filters['violation_type'] ?? '') }}" placeholder="xss, spam, file.*" />
                    </div>
                    <div class="form-group" style="margin-left:10px;">
                        <label>Action</label>
                        <input type="text" class="form-control" name="action_taken" value="{{ (string) ($filters['action_taken'] ?? '') }}" placeholder="suspend/delete/kill" />
                    </div>
                    <button type="submit" class="btn btn-primary" style="margin-left:10px;">Apply</button>
                    <a href="{{ route('admin.protect.timeline') }}" class="btn btn-default" style="margin-left:6px;">Reset</a>
                </form>
            </div>
        </div>
    </div>
</div>

<div class="row">
    <div class="col-md-12">
        <div class="box box-danger">
            <div class="box-header with-border"><h3 class="box-title">Security Event Timeline</h3></div>
            <div class="box-body">
                @if(!$hasViolationsTable)
                    <p class="text-warning">Table <code>user_violations</code> belum ada. Jalankan migration terbaru dulu.</p>
                @else
                    <div class="table-responsive">
                        <table class="table table-bordered table-striped pp-table">
                            <thead>
                                <tr>
                                    <th>ID</th>
                                    <th>Time</th>
                                    <th>User</th>
                                    <th>Server</th>
                                    <th>Type</th>
                                    <th>Action</th>
                                    <th>Severity</th>
                                    <th>File</th>
                                    <th>Details</th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse(($violations ?? []) as $v)
                                    <tr>
                                        <td><code>{{ (int) $v->id }}</code></td>
                                        <td>{{ (string) ($v->created_at ?? '-') }}</td>
                                        <td>{{ (string) ($v->username ?? '-') }} <span class="text-muted">#{{ (int) ($v->user_id ?? 0) }}</span></td>
                                        <td>{{ (string) ($v->server_name ?? '-') }} <span class="text-muted">#{{ (int) ($v->server_id ?? 0) }}</span></td>
                                        <td><span class="label label-danger">{{ (string) ($v->violation_type ?? '-') }}</span></td>
                                        <td><span class="label label-warning">{{ (string) ($v->action_taken ?? '-') }}</span></td>
                                        @php $sev = (int) ($v->severity ?? 0); @endphp
                                        <td><strong>{{ $sev }}</strong> <span class="label label-{{ $sev >= 8 ? 'danger' : ($sev >= 5 ? 'warning' : 'default') }}">{{ $sev >= 8 ? 'critical' : ($sev >= 5 ? 'high' : 'normal') }}</span></td>
                                        <td style="max-width:220px;word-break:break-word;">{{ (string) ($v->file_name ?? '-') }}</td>
                                        <td style="max-width:420px;word-break:break-word;">{{ (string) ($v->details ?? '-') }}</td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="9" class="text-center text-muted">No violation data found.</td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                @endif
            </div>
        </div>
    </div>
</div>

<div class="row">
    <div class="col-md-12">
        <div class="box box-warning">
            <div class="box-header with-border"><h3 class="box-title">Quarantined / Illegal Files</h3></div>
            <div class="box-body">
                @if(!$hasIllegalFilesTable)
                    <p class="text-warning">Table <code>illegal_files</code> belum ada. Jalankan migration terbaru dulu.</p>
                @else
                    <div class="table-responsive">
                        <table class="table table-bordered table-striped pp-table">
                            <thead>
                                <tr>
                                    <th>Last Seen</th>
                                    <th>Seen Count</th>
                                    <th>User</th>
                                    <th>Server UUID</th>
                                    <th>File</th>
                                    <th>Reason</th>
                                    <th>Hash</th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse(($illegalFiles ?? []) as $row)
                                    <tr>
                                        <td>{{ (string) ($row->last_seen ?? '-') }}</td>
                                        <td><strong>{{ (int) ($row->seen_count ?? 0) }}</strong></td>
                                        <td>#{{ (int) ($row->user_id ?? 0) }}</td>
                                        <td><code>{{ (string) ($row->server_uuid ?? '-') }}</code></td>
                                        <td style="max-width:320px;word-break:break-word;">{{ (string) ($row->file_path ?? ($row->file_name ?? '-')) }}</td>
                                        <td style="max-width:260px;word-break:break-word;">{{ (string) ($row->detection_reason ?? '-') }}</td>
                                        <td><code>{{ (string) ($row->file_hash ?? '-') }}</code></td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="7" class="text-center text-muted">No illegal file data found.</td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                @endif
            </div>
        </div>
    </div>
</div>

<div class="row">
    <div class="col-md-12">
        <div class="box box-default">
            <div class="box-header with-border"><h3 class="box-title">Panel Activity Logs (Security Context)</h3></div>
            <div class="box-body">
                @if(!$hasActivityLogs)
                    <p class="text-warning">Table <code>activity_logs</code> tidak tersedia.</p>
                @else
                    <div class="table-responsive">
                        <table class="table table-bordered table-striped pp-table">
                            <thead>
                                <tr>
                                    <th>ID</th>
                                    <th>Time</th>
                                    <th>Event</th>
                                    <th>Actor</th>
                                    <th>Server</th>
                                    <th>IP</th>
                                    <th>Reason</th>
                                    <th>Description</th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse(($activityLogs ?? []) as $log)
                                    <tr>
                                        <td><code>{{ (int) $log->id }}</code></td>
                                        <td>{{ (string) ($log->timestamp ?? '-') }}</td>
                                        <td><code>{{ (string) ($log->event ?? '-') }}</code></td>
                                        <td>{{ (string) ($log->actor_username ?? ($log->actor_id ? ('#' . (int) $log->actor_id) : '-')) }}</td>
                                        <td>{{ isset($log->server_id) ? ('#' . (int) $log->server_id) : '-' }}</td>
                                        <td>{{ (string) ($log->ip ?? '-') }}</td>
                                        <td style="max-width:360px;word-break:break-word;">
                                            {{ (string) ($log->reason ?? '-') }}
                                            @if(!empty($log->reason_detail) && (string) $log->reason_detail !== (string) $log->reason)
                                                <div class="text-muted" style="margin-top:4px; font-size:12px;">detail: {{ (string) $log->reason_detail }}</div>
                                            @endif
                                        </td>
                                        <td style="max-width:420px;word-break:break-word;">{{ (string) ($log->description ?? '-') }}</td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="8" class="text-center text-muted">No activity log data found.</td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                @endif
            </div>
        </div>
    </div>
</div>
</div>
@endsection
