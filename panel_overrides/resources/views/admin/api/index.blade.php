@extends('layouts.admin')

@section('title')
    Application API
@endsection

@section('content-header')
    <h1>Application API<small>Control access credentials for managing this Panel via the API.</small></h1>
    <ol class="breadcrumb">
        <li><a href="{{ route('admin.index') }}">Admin</a></li>
        <li class="active">Application API</li>
    </ol>
@endsection

@section('content')
    <style>
        .danex-api-card {
            background: #0b0b10;
            border: 1px solid rgba(139, 92, 246, 0.26);
            border-radius: 10px;
            box-shadow: 0 18px 48px rgba(0, 0, 0, 0.46);
            overflow: hidden;
        }
        .danex-api-card .box-header {
            background: #111117;
            border-bottom: 1px solid rgba(139, 92, 246, 0.2);
        }
        .danex-api-table {
            border-collapse: separate;
            border-spacing: 0 8px;
            padding: 8px 10px 14px;
        }
        .danex-api-table thead th {
            color: #8b8ba0;
            border: 0 !important;
            font-size: 11px;
            letter-spacing: .08em;
            text-transform: uppercase;
        }
        .danex-api-table tbody tr {
            background: #111117;
            transition: transform .18s ease, box-shadow .18s ease, background .18s ease;
        }
        .danex-api-table tbody tr:hover {
            transform: translateY(-1px);
            background: #15151d;
            box-shadow: inset 3px 0 0 #8b5cf6, 0 12px 28px rgba(0,0,0,.34);
        }
        .danex-api-table tbody td {
            border-top: 1px solid rgba(139, 92, 246, .16) !important;
            border-bottom: 1px solid rgba(139, 92, 246, .16);
            color: #d4d4df;
            vertical-align: middle !important;
        }
        .danex-api-table tbody td:first-child {
            border-left: 1px solid rgba(139, 92, 246, .16);
            border-radius: 8px 0 0 8px;
        }
        .danex-api-table tbody td:last-child {
            border-right: 1px solid rgba(139, 92, 246, .16);
            border-radius: 0 8px 8px 0;
        }
        .danex-api-table code {
            background: #07070b;
            border: 1px solid rgba(139, 92, 246, .24);
            color: #ddd6fe;
            border-radius: 6px;
            padding: 5px 7px;
            display: inline-block;
            max-width: min(32rem, 72vw);
            white-space: nowrap;
            word-break: normal;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        .danex-api-danger {
            display: inline-flex;
            width: 32px;
            height: 32px;
            align-items: center;
            justify-content: center;
            border-radius: 8px;
            background: #160f12;
            border: 1px solid rgba(239, 68, 68, .28);
            color: #fca5a5;
            transition: box-shadow .18s ease, border-color .18s ease, color .18s ease;
        }
        .danex-api-danger:hover {
            color: #fff;
            border-color: rgba(239, 68, 68, .72);
            box-shadow: 0 0 18px rgba(239, 68, 68, .3);
        }
        @media (max-width: 767px) {
            .danex-api-table {
                min-width: 720px;
            }
            .danex-api-card .box-tools {
                float: none;
                margin-top: 8px;
            }
            .danex-api-card .box-tools .btn {
                width: 100%;
            }
        }
    </style>
    <div class="row">
        <div class="col-xs-12">
            <div class="box box-primary danex-api-card">
                <div class="box-header with-border">
                    <h3 class="box-title">Credentials List</h3>
                    <div class="box-tools">
                        <a href="{{ route('admin.api.new') }}" class="btn btn-sm btn-primary">Create New</a>
                    </div>
                </div>
                <div class="box-body table-responsive no-padding">
                    <table class="table danex-api-table">
                        <thead>
                            <tr>
                                <th>Key</th>
                                <th>Memo</th>
                                <th>Last Used</th>
                                <th>Created</th>
                                <th>Created by</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                        @foreach($keys as $key)
                            <tr>
                                <td><code>
                                    @if (Auth::user()->is($key->user))
                                        @php
                                            $tokenDecryptFailed = false;
                                            $tokenSuffix = rescue(static fn () => decrypt($key->token), null, false);
                                            if (!is_string($tokenSuffix) || $tokenSuffix === '') {
                                                $tokenSuffix = '****';
                                                $tokenDecryptFailed = true;
                                            }
                                        @endphp
                                        {{ $key->identifier . $tokenSuffix }}
                                        @if ($tokenDecryptFailed)
                                            <br><small class="text-warning">Legacy key cannot be displayed. Revoke and create a new key.</small>
                                        @endif
                                    @else
                                        {{ $key->identifier . '****' }}
                                    @endif
                                </code></td>
                                <td>{{ $key->memo }}</td>
                                <td>
                                    @if(!is_null($key->last_used_at))
                                        @datetimeHuman($key->last_used_at)
                                    @else
                                        &mdash;
                                    @endif
                                </td>
                                <td>@datetimeHuman($key->created_at)</td>
                                <td>
                                    <a href="{{ route('admin.users.view', $key->user->id) }}">{{ $key->user->username }}</a>
                                </td>
                                <td>
                                    <a href="#" class="danex-api-danger" data-action="revoke-key" data-attr="{{ $key->identifier }}">
                                        <i class="fa fa-trash-o"></i>
                                    </a>
                                </td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
@endsection

@section('footer-scripts')
    @parent
    <script>
        $(document).ready(function() {
            $('[data-action="revoke-key"]').click(function (event) {
                var self = $(this);
                event.preventDefault();
                swal({
                    type: 'error',
                    title: 'Revoke API Key',
                    text: 'Once this API key is revoked any applications currently using it will stop working.',
                    showCancelButton: true,
                    allowOutsideClick: true,
                    closeOnConfirm: false,
                    confirmButtonText: 'Revoke',
                    confirmButtonColor: '#d9534f',
                    showLoaderOnConfirm: true
                }, function () {
                    $.ajax({
                        method: 'DELETE',
                        url: '/admin/api/revoke/' + self.data('attr'),
                        headers: {
                            'X-CSRF-TOKEN': '{{ csrf_token() }}'
                        }
                    }).done(function () {
                        swal({
                            type: 'success',
                            title: '',
                            text: 'API Key has been revoked.'
                        });
                        self.parent().parent().slideUp();
                    }).fail(function (jqXHR) {
                        console.error(jqXHR);
                        swal({
                            type: 'error',
                            title: 'Whoops!',
                            text: 'An error occurred while attempting to revoke this key.'
                        });
                    });
                });
            });
        });
    </script>
@endsection
