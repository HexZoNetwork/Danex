@extends('layouts.admin')

@section('title')
    Protect Notifications
@endsection

@section('content-header')
    <h1>Protect Notifications<small>Log realtime notifikasi system, DM, group, dan call.</small></h1>
    <ol class="breadcrumb">
        <li><a href="{{ route('admin.servers') }}">Admin</a></li>
        <li><a href="{{ route('admin.protect') }}">Protect</a></li>
        <li class="active">Notifications</li>
    </ol>
@endsection

@section('content')
@include('admin.protect.partials.styles')
<div class="pp-theme">
@include('admin.protect.partials.tabs', ['active' => 'notifications'])

<div class="row">
    <div class="col-md-12">
        <div class="box box-default">
            <div class="box-header with-border"><h3 class="box-title">Recent Notification Logs</h3></div>
            <div class="box-body">
                <div class="table-responsive">
                    <table class="table table-bordered table-striped pp-table">
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>User</th>
                                <th>Source</th>
                                <th>Conversation</th>
                                <th>From</th>
                                <th>Title</th>
                                <th>Message</th>
                                <th>Created</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse(($history ?? []) as $item)
                                <tr>
                                    <td><code>{{ (int) $item->id }}</code></td>
                                    <td>{{ (int) ($item->user_id ?? 0) }}</td>
                                    <td><span class="label label-info">{{ (string) ($item->source_type ?? '-') }}</span></td>
                                    <td>{{ (string) ($item->conversation_name ?? ($item->conversation_id ? ('#' . (int) $item->conversation_id) : '-')) }}</td>
                                    <td>{{ (string) ($item->from_username ?? ($item->from_user_id ? ('user#' . (int) $item->from_user_id) : '-')) }}</td>
                                    <td>{{ (string) ($item->title ?? '-') }}</td>
                                    <td style="max-width: 380px; word-break: break-word;">{{ (string) ($item->body ?? '-') }}</td>
                                    <td>{{ (string) ($item->created_at ?? '-') }}</td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="8" class="text-center text-muted">No notification logs yet.</td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>
</div>
@endsection
