@extends('layouts.admin')

@section('title')
    DanexCoin Management
@endsection

@section('content-header')
    <h1>DanexCoin Management<small>Tambah atau kurangi coin user tertentu dengan jujur dan transparan.</small></h1>
    <ol class="breadcrumb">
        <li><a href="{{ route('admin.index') }}">Admin</a></li>
        <li class="active">DanexCoin</li>
    </ol>
@endsection

@section('content')
    <div class="row">
        <div class="col-md-6">
            <div class="box box-primary">
                <div class="box-header with-border">
                    <h3 class="box-title">Adjust DanexCoin</h3>
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
                                placeholder="Contoh: 15 / username / email"
                                required
                            />
                            <p class="text-muted" style="margin-top: 6px;">
                                Bisa isi user id, username, atau email.
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

                        <button type="submit" class="btn btn-primary">Simpan Perubahan</button>
                    </form>
                </div>
            </div>
        </div>

        <div class="col-md-6">
            <div class="box box-default">
                <div class="box-header with-border">
                    <h3 class="box-title">DanexCoin Logs</h3>
                </div>
                <div class="box-body">
                    <p class="text-muted" style="margin: 0;">
                        Gunakan tabel log untuk audit spin user dan tracking perubahan saldo.
                    </p>
                </div>
            </div>
        </div>
    </div>

    <div class="row">
        <div class="col-xs-12">
            <div class="box box-default">
                <div class="box-header with-border">
                    <h3 class="box-title">Recent Spin Logs</h3>
                </div>
                <div class="box-body table-responsive no-padding">
                    <table class="table table-hover">
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>User</th>
                                <th>Bet</th>
                                <th>Result</th>
                                <th>Multiplier</th>
                                <th>Payout</th>
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
                                            <span class="label label-success" style="margin-left: 6px;">JACKPOT</span>
                                        @endif
                                    </td>
                                    <td>x{{ number_format((float) $row->multiplier, 2) }}</td>
                                    <td>{{ number_format((float) $row->payout, 2) }}</td>
                                    <td>{{ number_format((float) $row->balance_before, 2) }} -> {{ number_format((float) $row->balance_after, 2) }}</td>
                                    <td>{{ $row->created_at }}</td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="8" class="text-center text-muted">Belum ada spin log.</td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
@endsection
