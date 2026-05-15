@extends('layouts.admin')

@section('title')
    Protect Verification
@endsection

@section('content-header')
    <h1>Protect Verification<small>2-step verification required.</small></h1>
    <ol class="breadcrumb">
        <li><a href="{{ route('admin.servers') }}">Admin</a></li>
        <li><a href="{{ route('admin.protect') }}">Protect</a></li>
        <li class="active">Verify</li>
    </ol>
@endsection

@section('content')
@include('admin.protect.partials.styles')
<div class="pp-theme">
<div class="row">
    <div class="col-md-8 col-md-offset-2">
        <div class="box box-default">
            <div class="box-header with-border">
                <h3 class="box-title">Verification Needed</h3>
            </div>
            <div class="box-body">
                <p>Step 1: akun yang login wajib <code>id=1</code>.</p>
                <p>Step 2: masukkan token protect rahasia milik server ini.</p>
                <p><small>Portal unblock: <a href="{{ $portalUrl }}" target="_blank">{{ $portalUrl }}</a></small></p>

                <form method="POST" action="{{ route('admin.protect.verify') }}">
                    @csrf
                    <div class="form-group">
                        <label>Protect Token</label>
                        <input type="password" name="token" class="form-control" placeholder="Masukkan token" required />
                    </div>
                    <button type="submit" class="btn btn-primary">Verify Access</button>
                </form>
            </div>
        </div>
    </div>
</div>
</div>
@endsection
