@extends('layouts.admin')

@section('title')
    DanexCoin Rewards
@endsection

@section('content-header')
    <h1>DanexCoin Rewards<small>Adjust wallet balance and audit reward cycle activity.</small></h1>
    <ol class="breadcrumb">
        <li><a href="{{ route('admin.index') }}">Admin</a></li>
        <li class="active">DanexCoin</li>
    </ol>
@endsection

@section('content')
    <div class="row">
        <div class="col-md-4">
            <div class="box box-primary">
                <div class="box-header with-border">
                    <h3 class="box-title">Adjust Wallet</h3>
                </div>
                <div class="box-body">
                    <form method="POST" action="{{ route('admin.management.danexcoin.adjust') }}">
                        @csrf
                        <div class="form-group">
                            <label>User Identifier</label>
                            <input
                                type="text"
                                class="form-control"
                                name="identifier"
                                placeholder="15 / username / email"
                                required
                            />
                            <p class="text-muted" style="margin-top: 6px;">
                                Use user id, username, or email.
                            </p>
                        </div>

                        <div class="form-group">
                            <label>Mode</label>
                            <select name="mode" class="form-control">
                                <option value="add">Tambah Coin</option>
                                <option value="remove">Kurangi Coin</option>
                            </select>
                        </div>

                        <div class="form-group">
                            <label>Amount</label>
                            <input type="number" min="0.01" max="100000000" step="0.01" class="form-control" name="amount" required />
                        </div>

                        <button type="submit" class="btn btn-primary">Apply Change</button>
                    </form>
                </div>
            </div>
        </div>

        <div class="col-md-4">
            <div class="box box-default">
                <div class="box-header with-border">
                    <h3 class="box-title">Casino Pulse 24h</h3>
                </div>
                <div class="box-body">
                    <div class="row">
                        <div class="col-xs-6"><strong>{{ number_format((int) ($stats['spins'] ?? 0)) }}</strong><br><small class="text-muted">spins</small></div>
                        <div class="col-xs-6"><strong>{{ number_format((int) ($stats['unique_users'] ?? 0)) }}</strong><br><small class="text-muted">players</small></div>
                        <div class="col-xs-6" style="margin-top:12px;"><strong>{{ number_format((float) ($stats['wagered'] ?? 0), 2) }}</strong><br><small class="text-muted">wagered</small></div>
                        <div class="col-xs-6" style="margin-top:12px;"><strong>{{ number_format((float) ($stats['paid'] ?? 0), 2) }}</strong><br><small class="text-muted">paid</small></div>
                    </div>
                    <hr>
                    <p class="text-muted" style="margin: 0;">Jackpot hits: <strong>{{ number_format((int) ($stats['jackpots'] ?? 0)) }}</strong></p>
                </div>
            </div>
        </div>

        <div class="col-md-4">
            <div class="box box-danger">
                <div class="box-header with-border">
                    <h3 class="box-title">DanexCoin Settings</h3>
                </div>
                <div class="box-body">
                    <form method="POST" action="{{ route('admin.management.danexcoin.settings') }}">
                        @csrf
                        <div class="checkbox">
                            <label><input type="checkbox" name="enabled" value="1" {{ !empty($settings['enabled']) ? 'checked' : '' }}> Table enabled</label>
                        </div>
                        <div class="row">
                            <div class="col-xs-4"><label>Min Bet</label><input class="form-control" type="number" step="0.01" name="min_bet" value="{{ $settings['min_bet'] ?? 1 }}"></div>
                            <div class="col-xs-4"><label>Max Bet</label><input class="form-control" type="number" step="0.01" name="max_bet" value="{{ $settings['max_bet'] ?? 100000000 }}"></div>
                            <div class="col-xs-4"><label>Default</label><input class="form-control" type="number" step="0.01" name="default_bet" value="{{ $settings['default_bet'] ?? 10 }}"></div>
                        </div>
                        <div class="row" style="margin-top:10px;">
                            <div class="col-xs-4"><label>Win Rate</label><input class="form-control" type="number" min="0" max="0.95" step="0.0001" name="base_win_rate" value="{{ $settings['base_win_rate'] ?? 0.16 }}"></div>
                            <div class="col-xs-4"><label>Jackpot Rate</label><input class="form-control" type="number" min="0" max="0.95" step="0.0001" name="jackpot_rate" value="{{ $settings['jackpot_rate'] ?? 0.08 }}"></div>
                            <div class="col-xs-4"><label>Cooldown</label><input class="form-control" type="number" min="1" max="30" name="spin_cooldown_seconds" value="{{ $settings['spin_cooldown_seconds'] ?? 4 }}"></div>
                        </div>
                        <div class="row" style="margin-top:10px;">
                            <div class="col-xs-4"><label>Double x</label><input class="form-control" type="number" step="0.01" name="double_multiplier" value="{{ $settings['double_multiplier'] ?? 0.35 }}"></div>
                            <div class="col-xs-4"><label>Triple x</label><input class="form-control" type="number" step="0.01" name="triple_multiplier" value="{{ $settings['triple_multiplier'] ?? 1.5 }}"></div>
                            <div class="col-xs-4"><label>Jackpot x</label><input class="form-control" type="number" step="0.01" name="jackpot_multiplier" value="{{ $settings['jackpot_multiplier'] ?? 3 }}"></div>
                        </div>
                        <div class="row" style="margin-top:10px;">
                            <div class="col-xs-6"><label>Hot Window</label><input class="form-control" type="number" min="5" max="120" name="hot_window_minutes" value="{{ $settings['hot_window_minutes'] ?? 15 }}"></div>
                            <div class="col-xs-6"><label>Vibe Label</label><input class="form-control" name="house_edge_label" maxlength="32" value="{{ $settings['house_edge_label'] ?? 'volatile' }}"></div>
                        </div>
                        <button type="submit" class="btn btn-danger" style="margin-top:12px;">Save Casino Settings</button>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <div class="row">
        <div class="col-xs-12">
            <div class="box box-default">
                <div class="box-header with-border">
                    <h3 class="box-title">Recent Reward Runs</h3>
                </div>
                <div class="box-body table-responsive no-padding">
                    <table class="table table-hover">
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>User</th>
                                <th>Amount</th>
                                <th>Result</th>
                                <th>Multiplier</th>
                                <th>Reward</th>
                                <th>Balance</th>
                                <th>Time</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse($recent as $row)
                                <tr>
                                    <td>#{{ $row->id }}</td>
                                    <td>
                                        <strong>{{ $row->username }}</strong>
                                        <br>
                                        <small class="text-muted">#{{ $row->user_id }}</small>
                                    </td>
                                    <td>{{ number_format((float) $row->bet, 2) }}</td>
                                    <td>
                                        <code>{{ $row->reel_1 }}</code>
                                        <code>{{ $row->reel_2 }}</code>
                                        <code>{{ $row->reel_3 }}</code>
                                        @if((bool) $row->is_jackpot)
                                            <span class="label label-success" style="margin-left: 6px;">PRIME</span>
                                        @endif
                                    </td>
                                    <td>x{{ number_format((float) $row->multiplier, 2) }}</td>
                                    <td>{{ number_format((float) $row->payout, 2) }}</td>
                                    <td>{{ number_format((float) $row->balance_before, 2) }} -> {{ number_format((float) $row->balance_after, 2) }}</td>
                                    <td>{{ $row->created_at }}</td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="8" class="text-center text-muted">No reward run log yet.</td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
@endsection
