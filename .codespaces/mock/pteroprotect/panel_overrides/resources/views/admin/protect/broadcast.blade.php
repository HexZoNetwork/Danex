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
@include('admin.protect.partials.styles')
<div class="pp-theme">
@include('admin.protect.partials.tabs', ['active' => 'broadcast'])

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
                    <table class="table table-bordered table-striped pp-table">
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
</div>
@endsection
