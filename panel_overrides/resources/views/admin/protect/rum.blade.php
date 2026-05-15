@extends('layouts.admin')

@section('title')
    RUM Insight
@endsection

@section('content-header')
    <h1>RUM Insight<small>Fast overview for panel performance and client-side failures.</small></h1>
    <ol class="breadcrumb">
        <li><a href="{{ route('admin.servers') }}">Admin</a></li>
        <li><a href="{{ route('admin.protect') }}">Protect</a></li>
        <li class="active">RUM Insight</li>
    </ol>
@endsection

@section('content')
@include('admin.protect.partials.styles')
<style>
    .pp-theme .pp-rum-card {
        background: linear-gradient(135deg, #0b0b10 0%, #111117 100%);
        border: 1px solid rgba(139, 92, 246, 0.24);
        border-radius: 10px;
        color: #f7f7fb;
        padding: 14px;
        min-height: 112px;
        margin-bottom: 14px;
    }
    .pp-theme .pp-rum-label {
        color: #a6a6b8;
        font-size: 12px;
        text-transform: uppercase;
        letter-spacing: .6px;
    }
    .pp-theme .pp-rum-value {
        font-size: 30px;
        font-weight: 700;
        line-height: 1.2;
        margin-top: 6px;
    }
    .pp-theme .pp-pill {
        display: inline-block;
        font-size: 11px;
        border-radius: 999px;
        padding: 2px 8px;
        margin-right: 5px;
        margin-bottom: 5px;
        color: #fff;
    }
    .pp-theme .pp-pill.good { background: rgba(16, 185, 129, 0.2); }
    .pp-theme .pp-pill.ni { background: rgba(245, 158, 11, 0.2); }
    .pp-theme .pp-pill.poor { background: rgba(239, 68, 68, 0.2); }
</style>
<div class="pp-theme">
@include('admin.protect.partials.tabs', ['active' => 'rum'])

@if(!$hasRumTable)
    <div class="alert alert-warning">
        Tabel <code>panel_rum_events</code> belum ada. Jalankan migration RUM dulu.
    </div>
@endif

<div class="row">
    <div class="col-md-3">
        <div class="pp-rum-card">
            <div class="pp-rum-label">Event 1 Jam</div>
            <div class="pp-rum-value">{{ (int) ($summary['hour_total'] ?? 0) }}</div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="pp-rum-card">
            <div class="pp-rum-label">Event 24 Jam</div>
            <div class="pp-rum-value">{{ (int) ($summary['day_total'] ?? 0) }}</div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="pp-rum-card">
            <div class="pp-rum-label">API 5xx (1 Jam)</div>
            <div class="pp-rum-value">{{ (int) ($summary['hour_5xx'] ?? 0) }}</div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="pp-rum-card">
            <div class="pp-rum-label">JS Error (1 Jam)</div>
            <div class="pp-rum-value">{{ (int) ($summary['hour_js_errors'] ?? 0) }}</div>
        </div>
    </div>
</div>

<div class="row">
    <div class="col-md-7">
        <div class="box box-default">
            <div class="box-header with-border"><h3 class="box-title">Metric Breakdown (24h)</h3></div>
            <div class="box-body">
                <table class="table table-bordered pp-table">
                    <thead>
                    <tr>
                        <th>Metric</th>
                        <th>Total</th>
                        <th>Avg</th>
                        <th>Ratings</th>
                    </tr>
                    </thead>
                    <tbody>
                    @forelse($metrics as $row)
                        <tr>
                            <td><code>{{ $row->metric }}</code></td>
                            <td>{{ (int) $row->total }}</td>
                            <td>
                                @if($row->avg_value !== null)
                                    {{ number_format((float) $row->avg_value, 1) }}
                                @else
                                    -
                                @endif
                            </td>
                            <td>
                                <span class="pp-pill good">good {{ (int) ($row->good_count ?? 0) }}</span>
                                <span class="pp-pill ni">need {{ (int) ($row->ni_count ?? 0) }}</span>
                                <span class="pp-pill poor">poor {{ (int) ($row->poor_count ?? 0) }}</span>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="4">Belum ada data.</td></tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    <div class="col-md-5">
        <div class="box box-default">
            <div class="box-header with-border"><h3 class="box-title">Slow API (1h)</h3></div>
            <div class="box-body">
                <table class="table table-bordered pp-table table-striped">
                    <thead>
                    <tr>
                        <th>Path</th>
                        <th>Avg ms</th>
                        <th>5xx</th>
                    </tr>
                    </thead>
                    <tbody>
                    @forelse($topApis as $api)
                        <tr>
                            <td><code>{{ $api->api_path }}</code></td>
                            <td>{{ number_format((float) $api->avg_ms, 1) }}</td>
                            <td>{{ (int) $api->err_5xx }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="3">Belum ada API latency tinggi.</td></tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
        </div>
        <div class="box box-default">
            <div class="box-header with-border"><h3 class="box-title">Error Routes (24h)</h3></div>
            <div class="box-body">
                <table class="table table-bordered pp-table table-striped">
                    <thead>
                    <tr>
                        <th>Route</th>
                        <th>Errors</th>
                    </tr>
                    </thead>
                    <tbody>
                    @forelse($errorRoutes as $row)
                        <tr>
                            <td><code>{{ $row->route }}</code></td>
                            <td>{{ (int) $row->total }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="2">Belum ada route error.</td></tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>
</div>

@if($hasRumTable)
<script>
    (function () {
        var endpoint = "{{ route('admin.protect.rum.ping') }}";
        var csrf = "{{ csrf_token() }}";
        var protectToken = "{{ $postProtectToken ?? '' }}";
        if (!endpoint || !csrf || !protectToken) {
            return;
        }

        var sendPing = function () {
            var body = new URLSearchParams();
            body.append('protect_token', protectToken);

            fetch(endpoint, {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    'X-CSRF-TOKEN': csrf,
                    'X-Requested-With': 'XMLHttpRequest',
                    'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'
                },
                body: body.toString()
            }).catch(function () {});
        };

        sendPing();
        window.setInterval(sendPing, 8000);
        window.setInterval(function () {
            if (document.visibilityState === 'visible') {
                window.location.reload();
            }
        }, 15000);
    })();
</script>
@endif
@endsection
