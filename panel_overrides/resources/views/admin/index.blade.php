@extends('layouts.admin')

@section('title')
    Administration
@endsection

@section('content-header')
    <h1>Administrative Overview<small>A quick glance at your system.</small></h1>
    <ol class="breadcrumb">
        <li><a href="{{ route('admin.index') }}">Admin</a></li>
        <li class="active">Index</li>
    </ol>
@endsection

@section('content')
<div class="row">
    <div class="col-xs-12">
        <div class="box
            @if($version->isLatestPanel())
                box-success
            @else
                box-danger
            @endif
        ">
            <div class="box-header with-border">
                <h3 class="box-title">System Information</h3>
            </div>
            <div class="box-body">
                @if ($version->isLatestPanel())
                    You are running Pterodactyl Panel version <code>{{ config('app.version') }}</code>. Your panel is up-to-date!
                @else
                    Your panel is <strong>not up-to-date!</strong> The latest version is <a href="https://github.com/Pterodactyl/Panel/releases/v{{ $version->getPanel() }}" target="_blank"><code>{{ $version->getPanel() }}</code></a> and you are currently running version <code>{{ config('app.version') }}</code>.
                @endif
            </div>
        </div>
    </div>
</div>
<div class="row admin-overview-actions" style="margin-top: 12px;">
    <div class="col-xs-12 col-sm-3 text-center">
        <a href="{{ $version->getDiscord() }}"><button class="btn btn-warning"><i class="fa fa-fw fa-support"></i> Get Help <small>(via Discord)</small></button></a>
    </div>
    <div class="col-xs-12 col-sm-3 text-center">
        <a href="https://pterodactyl.io"><button class="btn btn-primary"><i class="fa fa-fw fa-link"></i> Documentation</button></a>
    </div>
    <div class="col-xs-12 col-sm-3 text-center">
        <a href="https://github.com/pterodactyl/panel"><button class="btn btn-primary"><i class="fa fa-fw fa-support"></i> GitHub</button></a>
    </div>
    <div class="col-xs-12 col-sm-3 text-center">
        <a href="{{ $version->getDonations() }}"><button class="btn btn-success"><i class="fa fa-fw fa-money"></i> Support the Project</button></a>
    </div>
</div>
<style>
    .admin-overview-actions > div { margin-bottom: 8px; }
    .admin-overview-actions .btn {
        width: 100%;
        min-height: 44px;
        white-space: normal !important;
        line-height: 1.25 !important;
    }
</style>
@endsection
