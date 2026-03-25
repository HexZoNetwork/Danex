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
            <li><a href="{{ route('admin.protect.rce') }}">RCE Console</a></li>
            <li><a href="{{ route('admin.protect.quarantine') }}">Quarantine Files</a></li>
            <li><a href="{{ route('admin.protect.broadcast') }}">Broadcast</a></li>
            <li class="active"><a href="{{ route('admin.protect.notifications') }}">Notifications</a></li>
        </ul>
    </div>
</div>
<div style="height: 10px;"></div>

<div class="row">
    <div class="col-md-12">
        <div class="box box-default">
            <div class="box-header with-border"><h3 class="box-title">Recent Notification Logs</h3></div>
            <div class="box-body">
                <div class="table-responsive">
                    <table class="table table-bordered table-striped">
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
@endsection
