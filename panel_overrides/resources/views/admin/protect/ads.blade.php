@extends('layouts.admin')

@section('title')
    Ads Management
@endsection

@section('content-header')
    <h1>Ads Management<small>Kelola sponsored banner dan popup dengan frekuensi aman.</small></h1>
    <ol class="breadcrumb">
        <li><a href="{{ route('admin.index') }}">Admin</a></li>
        <li><a href="{{ route('admin.protect') }}">Protect</a></li>
        <li class="active">Ads</li>
    </ol>
@endsection

@section('content')
<div class="pp-theme">
    @include('admin.protect.partials.styles')
    @include('admin.protect.partials.tabs', ['active' => 'ads'])

    <div class="row">
        <div class="col-md-5">
            <div class="box box-default">
                <div class="box-header with-border">
                    <h3 class="box-title">Ads Service</h3>
                </div>
                <div class="box-body">
                    <p>Status: <strong>{{ !empty($adsServiceEnabled) ? 'Enabled' : 'Disabled' }}</strong></p>
                    <p class="text-muted" style="margin-top:8px;">Jika service aktif, banner tampil di desktop rail dan popup memakai cooldown per hari agar tidak mengganggu flow panel.</p>
                    <form method="POST" action="{{ route('admin.protect.ads.service') }}" style="display:inline-block;">
                        @csrf
                        <input type="hidden" name="protect_token" value="{{ $postProtectToken ?? '' }}" />
                        <input type="hidden" name="enabled" value="{{ !empty($adsServiceEnabled) ? '0' : '1' }}" />
                        <button type="submit" class="btn btn-{{ !empty($adsServiceEnabled) ? 'warning' : 'success' }}">
                            {{ !empty($adsServiceEnabled) ? 'Disable Service' : 'Enable Service' }}
                        </button>
                    </form>
                </div>
            </div>

            <div class="box box-primary">
                <div class="box-header with-border">
                    <h3 class="box-title">Create Ad</h3>
                </div>
                <div class="box-body">
                    <form method="POST" action="{{ route('admin.protect.ads.store') }}">
                        @csrf
                        <input type="hidden" name="protect_token" value="{{ $postProtectToken ?? '' }}" />
                        <div class="form-group">
                            <label>Media URL (image/gif/video)</label>
                            <input class="form-control" name="media_url" required placeholder="https://..." maxlength="2000" />
                        </div>
                        <div class="form-group">
                            <label>Redirect Link (optional)</label>
                            <input class="form-control" name="link_url" placeholder="https://..." maxlength="2000" />
                        </div>
                        <div class="form-group">
                            <label>Text (optional)</label>
                            <input class="form-control" name="text" placeholder="Kosong boleh" maxlength="255" />
                        </div>
                        <div class="form-group">
                            <label>Sponsor Label</label>
                            <input class="form-control" name="sponsor_label" value="Sponsored" maxlength="64" />
                        </div>
                        <div class="form-group">
                            <label>Placement</label>
                            <select class="form-control" name="placement">
                                <option value="banner">Desktop Banner</option>
                                <option value="popup">Popup</option>
                                <option value="both">Banner + Popup</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>Weight (1-100)</label>
                            <input type="number" min="1" max="100" class="form-control" name="weight" value="1" />
                        </div>
                        <div class="row">
                            <div class="col-xs-4"><label>Daily Cap</label><input type="number" min="1" max="24" class="form-control" name="daily_cap" value="1" /></div>
                            <div class="col-xs-4"><label>Cooldown</label><input type="number" min="5" max="1440" class="form-control" name="cooldown_minutes" value="360" /></div>
                            <div class="col-xs-4"><label>Close Delay</label><input type="number" min="0" max="30" class="form-control" name="close_delay_seconds" value="0" /></div>
                        </div>
                        <div class="checkbox"><label><input type="checkbox" name="enabled" value="1" checked> Enabled</label></div>
                        <button type="submit" class="btn btn-primary">Create Ad</button>
                    </form>
                </div>
            </div>
        </div>

        <div class="col-md-7">
            <div class="box box-default">
                <div class="box-header with-border">
                    <h3 class="box-title">Ads Inventory</h3>
                </div>
                <div class="box-body table-responsive no-padding">
                    <table class="table table-striped pp-table">
                        <thead>
                            <tr>
                                <th style="width:48px;">ID</th>
                                <th>Media</th>
                                <th>Campaign</th>
                                <th style="width:190px;">Delivery</th>
                                <th style="width:170px;">Action</th>
                            </tr>
                        </thead>
                        <tbody>
                        @forelse($adsItems as $item)
                            <tr>
                                <td>{{ (int) ($item['id'] ?? 0) }}</td>
                                <td>
                                    <form method="POST" action="{{ route('admin.protect.ads.update', ['ad' => (int) ($item['id'] ?? 0)]) }}">
                                        @csrf
                                        <input type="hidden" name="protect_token" value="{{ $postProtectToken ?? '' }}" />
                                        <input class="form-control" name="media_url" value="{{ (string) ($item['media_url'] ?? '') }}" maxlength="2000" required />
                                        <input class="form-control" name="link_url" value="{{ (string) ($item['link_url'] ?? '') }}" maxlength="2000" style="margin-top:6px;" placeholder="link optional" />
                                </td>
                                <td>
                                        <input class="form-control" name="text" value="{{ (string) ($item['text'] ?? '') }}" maxlength="255" placeholder="text optional" />
                                        <input class="form-control" name="sponsor_label" value="{{ (string) ($item['sponsor_label'] ?? 'Sponsored') }}" maxlength="64" style="margin-top:6px;" placeholder="label" />
                                </td>
                                <td>
                                        <select class="form-control" name="placement">
                                            @foreach(['banner' => 'Banner', 'popup' => 'Popup', 'both' => 'Both'] as $value => $label)
                                                <option value="{{ $value }}" {{ (string) ($item['placement'] ?? 'banner') === $value ? 'selected' : '' }}>{{ $label }}</option>
                                            @endforeach
                                        </select>
                                        <label style="margin-top:6px;display:block;"><input type="checkbox" name="enabled" value="1" {{ !empty($item['enabled']) ? 'checked' : '' }}> enabled</label>
                                        <input type="number" min="1" max="100" class="form-control" name="weight" value="{{ (int) ($item['weight'] ?? 1) }}" />
                                        <input type="number" min="1" max="24" class="form-control" name="daily_cap" value="{{ (int) ($item['daily_cap'] ?? 1) }}" style="margin-top:6px;" placeholder="daily cap" />
                                        <input type="number" min="5" max="1440" class="form-control" name="cooldown_minutes" value="{{ (int) ($item['cooldown_minutes'] ?? 360) }}" style="margin-top:6px;" placeholder="cooldown minutes" />
                                        <input type="number" min="0" max="30" class="form-control" name="close_delay_seconds" value="{{ (int) ($item['close_delay_seconds'] ?? 0) }}" style="margin-top:6px;" placeholder="close delay" />
                                </td>
                                <td>
                                        <button class="btn btn-xs btn-primary" type="submit">Save changes</button>
                                    </form>
                                    <form method="POST" action="{{ route('admin.protect.ads.delete', ['ad' => (int) ($item['id'] ?? 0)]) }}" style="display:inline-block;margin-top:6px;" onsubmit="return confirm('Delete ads ini?');">
                                        @csrf
                                        <input type="hidden" name="protect_token" value="{{ $postProtectToken ?? '' }}" />
                                        <button class="btn btn-xs btn-danger" type="submit">Delete</button>
                                    </form>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="5" class="text-center text-muted">Belum ada ads.</td>
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
