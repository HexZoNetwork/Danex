@extends('layouts.admin')

@section('title')
    Challenge Profiles
@endsection

@section('content-header')
    <h1>Challenge Profiles<small>66 anti-bot challenge variants + full challenge settings.</small></h1>
    <ol class="breadcrumb">
        <li><a href="{{ route('admin.servers') }}">Admin</a></li>
        <li><a href="{{ route('admin.protect') }}">Protect</a></li>
        <li class="active">Challenge</li>
    </ol>
@endsection

@section('content')
@include('admin.protect.partials.styles')
<div class="pp-theme">
@include('admin.protect.partials.tabs', ['active' => 'challenge'])

<div class="row">
    <div class="col-md-8">
        <div class="box box-primary">
            <div class="box-header with-border">
                <h3 class="box-title">Challenge Settings</h3>
            </div>
            <div class="box-body">
                <form method="POST" action="{{ route('admin.protect.challenge.update') }}">
                    @csrf
                    <input type="hidden" name="protect_token" value="{{ $postProtectToken ?? '' }}" />
                    @php($cs = $challengeSettings ?? [])
                    <div class="row">
                        <div class="col-md-6">
                            <div class="pp-toggle-field">
                                <input type="hidden" name="waf_challenge_enabled" value="0">
                                <label class="pp-text-toggle" for="waf_challenge_enabled">
                                    <input id="waf_challenge_enabled" class="pp-text-toggle-input" type="checkbox" role="switch" name="waf_challenge_enabled" value="1" @if((bool)($cs['enabled'] ?? true)) checked @endif>
                                    <span class="pp-text-toggle-frame" aria-hidden="true">
                                        <span class="pp-text-toggle-option pp-text-toggle-on">ON</span>
                                        <span class="pp-text-toggle-option pp-text-toggle-off">OFF</span>
                                        <span class="pp-text-toggle-core"></span>
                                    </span>
                                    <span class="pp-toggle-copy">
                                        <span class="pp-toggle-title">Challenge Enabled</span>
                                        <span class="pp-toggle-state pp-toggle-state-on">ON — bot challenge aktif</span>
                                        <span class="pp-toggle-state pp-toggle-state-off">OFF — challenge tidak aktif</span>
                                    </span>
                                </label>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="pp-toggle-field">
                                <input type="hidden" name="waf_challenge_strict_mode" value="0">
                                <label class="pp-text-toggle" for="waf_challenge_strict_mode">
                                    <input id="waf_challenge_strict_mode" class="pp-text-toggle-input" type="checkbox" role="switch" name="waf_challenge_strict_mode" value="1" @if((bool)($cs['strict_mode'] ?? true)) checked @endif>
                                    <span class="pp-text-toggle-frame" aria-hidden="true">
                                        <span class="pp-text-toggle-option pp-text-toggle-on">ON</span>
                                        <span class="pp-text-toggle-option pp-text-toggle-off">OFF</span>
                                        <span class="pp-text-toggle-core"></span>
                                    </span>
                                    <span class="pp-toggle-copy">
                                        <span class="pp-toggle-title">Strict Mode</span>
                                        <span class="pp-toggle-state pp-toggle-state-on">ON — validasi lebih agresif</span>
                                        <span class="pp-toggle-state pp-toggle-state-off">OFF — mode normal</span>
                                    </span>
                                </label>
                            </div>
                        </div>
                    </div>
                    <div class="form-group">
                        <label for="waf_challenge_type">Active Challenge Type</label>
                        <select id="waf_challenge_type" name="waf_challenge_type" class="form-control">
                            @foreach(($challengeProfiles ?? []) as $profile)
                                <option value="{{ (int) $profile['id'] }}" @if((int) ($challengeType ?? 1) === (int) $profile['id']) selected @endif>
                                    #{{ str_pad((string) ((int) $profile['id']), 2, '0', STR_PAD_LEFT) }} — {{ $profile['name'] }}
                                </option>
                            @endforeach
                        </select>
                    </div>
                    <div class="row">
                        <div class="col-md-6">
                            <div class="form-group">
                                <label for="waf_pow_bits">PoW Bits (8-24)</label>
                                <input id="waf_pow_bits" type="number" min="8" max="24" class="form-control" name="waf_pow_bits" value="{{ (int) ($cs['pow_bits'] ?? 14) }}">
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="form-group">
                                <label for="waf_challenge_ttl_sec">Clearance TTL (seconds)</label>
                                <input id="waf_challenge_ttl_sec" type="number" min="60" max="86400" class="form-control" name="waf_challenge_ttl_sec" value="{{ (int) ($cs['ttl_sec'] ?? 1800) }}">
                            </div>
                        </div>
                    </div>
                    <hr>
                    <div class="pp-toggle-field">
                        <input type="hidden" name="waf_challenge_theme_custom_enabled" value="0">
                        <label class="pp-text-toggle" for="waf_challenge_theme_custom_enabled">
                            <input id="waf_challenge_theme_custom_enabled" class="pp-text-toggle-input" type="checkbox" role="switch" name="waf_challenge_theme_custom_enabled" value="1" @if((bool)($cs['theme_custom_enabled'] ?? false)) checked @endif>
                            <span class="pp-text-toggle-frame" aria-hidden="true">
                                <span class="pp-text-toggle-option pp-text-toggle-on">ON</span>
                                <span class="pp-text-toggle-option pp-text-toggle-off">OFF</span>
                                <span class="pp-text-toggle-core"></span>
                            </span>
                            <span class="pp-toggle-copy">
                                <span class="pp-toggle-title">Custom Challenge Colors</span>
                                <span class="pp-toggle-state pp-toggle-state-on">ON — pakai warna custom di bawah</span>
                                <span class="pp-toggle-state pp-toggle-state-off">OFF — tetap graphite DANEX</span>
                            </span>
                        </label>
                    </div>
                    <div class="pp-field-grid">
                        <div class="form-group">
                            <label for="waf_challenge_theme_gradient_start">Surface Base</label>
                            <input id="waf_challenge_theme_gradient_start" type="color" class="form-control" name="waf_challenge_theme_gradient_start" value="{{ (string) ($cs['theme_gradient_start'] ?? '#07070b') }}">
                        </div>
                        <div class="form-group">
                            <label for="waf_challenge_theme_gradient_end">Surface Raised</label>
                            <input id="waf_challenge_theme_gradient_end" type="color" class="form-control" name="waf_challenge_theme_gradient_end" value="{{ (string) ($cs['theme_gradient_end'] ?? '#111117') }}">
                        </div>
                        <div class="form-group">
                            <label for="waf_challenge_theme_accent">Accent</label>
                            <input id="waf_challenge_theme_accent" type="color" class="form-control" name="waf_challenge_theme_accent" value="{{ (string) ($cs['theme_accent'] ?? '#8b5cf6') }}">
                        </div>
                    </div>
                    <div class="pp-action-row">
                        <button type="submit" class="btn btn-primary">Save Challenge Settings</button>
                        <a href="{{ ($challengePreviewBaseUrl ?? '/__pteroprotect/challenge/page?rd=%2F') . '&type=' . (int) ($challengeType ?? 1) }}" target="_blank" rel="noopener" class="btn btn-default">
                            Preview Current
                        </a>
                    </div>
                </form>
                <p class="text-muted" style="margin-top: 10px;">
                    Setiap type memakai profile challenge unik (66 varian berbeda) + tuning anti-bot.
                </p>
            </div>
        </div>
    </div>

    <div class="col-md-4">
        <div class="box box-default">
            <div class="box-header with-border">
                <h3 class="box-title">Quick Preview</h3>
            </div>
            <div class="box-body">
                <p class="text-muted">Pilih type lalu buka preview di tab baru.</p>
                <div class="form-group">
                    <label for="preview_type">Preview Type</label>
                    <select id="preview_type" class="form-control">
                        @foreach(($challengeProfiles ?? []) as $profile)
                            <option value="{{ (int) $profile['id'] }}" @if((int) ($challengeType ?? 1) === (int) $profile['id']) selected @endif>
                                #{{ str_pad((string) ((int) $profile['id']), 2, '0', STR_PAD_LEFT) }} — {{ $profile['name'] }}
                            </option>
                        @endforeach
                    </select>
                </div>
                <div class="pp-action-row">
                    <button type="button" id="preview_btn" class="btn btn-primary">Open Preview</button>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="row">
    <div class="col-md-12">
        <div class="box box-default">
            <div class="box-header with-border">
                <h3 class="box-title">All 66 Challenge Types</h3>
            </div>
            <div class="box-body">
                <div class="pp-challenge-grid">
                    @foreach(($challengeProfiles ?? []) as $profile)
                        <a class="pp-challenge-card" target="_blank" rel="noopener"
                           href="{{ ($challengePreviewBaseUrl ?? '/__pteroprotect/challenge/page?rd=%2F') . '&type=' . (int) $profile['id'] }}">
                            <span class="pp-card-index">#{{ str_pad((string) ((int) $profile['id']), 2, '0', STR_PAD_LEFT) }}</span>
                            <span>
                                <strong>{{ $profile['name'] }}</strong>
                                <small>Open preview</small>
                            </span>
                        </a>
                    @endforeach
                </div>
            </div>
        </div>
    </div>
</div>
</div>

<script>
    (function () {
        var previewType = document.getElementById('preview_type');
        var previewBtn = document.getElementById('preview_btn');
        if (!previewType || !previewBtn) return;
        previewBtn.addEventListener('click', function () {
            var v = parseInt(previewType.value || '1', 10);
            if (!Number.isFinite(v) || v < 1 || v > 66) v = 1;
            var url = @json($challengePreviewBaseUrl ?? '/__pteroprotect/challenge/page?rd=%2F') + '&type=' + String(v);
            window.open(url, '_blank', 'noopener');
        });
    })();
</script>
@endsection
