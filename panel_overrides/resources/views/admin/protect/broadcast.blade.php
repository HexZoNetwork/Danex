@extends('layouts.admin')

@section('title')
    Protect Broadcast
@endsection

@section('content-header')
    <h1>Protect Broadcast<small>Kirim notifikasi system ke semua user panel.</small></h1>
    <ol class="breadcrumb">
        <li><a href="{{ route('admin.servers') }}">Admin</a></li>
        <li><a href="{{ route('admin.protect') }}">Protect</a></li>
        <li class="active">Broadcast</li>
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
            <li class="active"><a href="{{ route('admin.protect.broadcast') }}">Broadcast</a></li>
            <li><a href="{{ route('admin.protect.notifications') }}">Notifications</a></li>
        </ul>
    </div>
</div>
<div style="height: 10px;"></div>

<div class="row">
    <div class="col-md-12">
        <div class="box box-primary">
            <div class="box-header with-border"><h3 class="box-title">Send System Broadcast</h3></div>
            <div class="box-body">
                <form method="POST" action="{{ route('admin.protect.broadcast.send') }}">
                    @csrf
                    <input type="hidden" name="protect_token" value="{{ $postProtectToken ?? '' }}" />
                    <div class="form-group">
                        <label>Title</label>
                        <input type="text" class="form-control" name="title" maxlength="191" required placeholder="Maintenance / Announcement" />
                    </div>
                    <div class="form-group">
                        <label>Message</label>
                        <textarea class="form-control" name="body" rows="5" maxlength="2000" required placeholder="Write broadcast message here..."></textarea>
                    </div>
                    <button type="submit" class="btn btn-primary">Send Broadcast</button>
                </form>
            </div>
        </div>
    </div>
</div>

<div class="row">
    <div class="col-md-12">
        <div class="box box-default">
            <div class="box-header with-border"><h3 class="box-title">Recent Broadcast Logs</h3></div>
            <div class="box-body">
                <div class="table-responsive">
                    <table class="table table-bordered table-striped">
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Title</th>
                                <th>Message</th>
                                <th>Created</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse(($history ?? []) as $item)
                                <tr>
                                    <td><code>{{ (int) $item->id }}</code></td>
                                    <td>{{ (string) ($item->title ?? '-') }}</td>
                                    <td>{{ (string) ($item->body ?? '-') }}</td>
                                    <td>{{ (string) ($item->created_at ?? '-') }}</td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="4" class="text-center text-muted">No broadcast logs yet.</td>
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
