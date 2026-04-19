@extends('layouts.admin')

@section('title')
    Edit Quarantine File
@endsection

@section('content-header')
    <h1>Edit Quarantine File<small>Editor file karantina.</small></h1>
    <ol class="breadcrumb">
        <li><a href="{{ route('admin.servers') }}">Admin</a></li>
        <li><a href="{{ route('admin.protect') }}">Protect</a></li>
        <li><a href="{{ route('admin.protect.quarantine') }}">Quarantine Files</a></li>
        <li class="active">Edit</li>
    </ol>
@endsection

@section('content')
@include('admin.protect.partials.styles')
<div class="pp-theme">
@include('admin.protect.partials.tabs', ['active' => 'quarantine'])

<div class="row">
    <div class="col-md-12">
        <div class="box box-default">
            <div class="box-header with-border">
                <h3 class="box-title">{{ $fileName }}</h3>
            </div>
            <div class="box-body">
                <p><strong>Server:</strong> <code>{{ $serverName }}</code></p>
                <p><strong>Path:</strong> <code>hidden (internal quarantine path)</code></p>
                <p><strong>Size:</strong> <code>{{ number_format((int) $fileSize) }} B</code></p>

                <form method="POST" action="{{ route('admin.protect.quarantine.update') }}">
                    @csrf
                    <input type="hidden" name="protect_token" value="{{ $postProtectToken ?? '' }}" />
                    <input type="hidden" name="path" value="{{ base64_encode($filePath) }}" />
                    <div class="form-group">
                        <label>Editor</label>
                        <textarea id="qeditor" name="content" class="form-control" rows="26" style="font-family: Menlo, Monaco, Consolas, monospace; background: #263341; color: #e5edf7; border: 1px solid #4a5b6e;">{{ $fileContent }}</textarea>
                    </div>
                    <button type="submit" class="btn btn-success">Save File</button>
                    <a href="{{ route('admin.protect.quarantine') }}" class="btn btn-default">Back</a>
                </form>
            </div>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const el = document.getElementById('qeditor');
    if (!el) return;
    el.addEventListener('keydown', function (e) {
        if (e.key === 'Tab') {
            e.preventDefault();
            const start = this.selectionStart;
            const end = this.selectionEnd;
            this.value = this.value.substring(0, start) + "    " + this.value.substring(end);
            this.selectionStart = this.selectionEnd = start + 4;
        }
    });
});
</script>
</div>
@endsection
