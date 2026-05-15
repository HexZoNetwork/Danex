<!DOCTYPE html>
<html>
    <head>
        <meta charset="utf-8">
        <meta http-equiv="X-UA-Compatible" content="IE=edge">
        <title>{{ config('app.name', 'Pterodactyl') }} - @yield('title')</title>
        <meta content="width=device-width, initial-scale=1, maximum-scale=1, user-scalable=no" name="viewport">
        <meta name="_token" content="{{ csrf_token() }}">

        <link rel="apple-touch-icon" sizes="180x180" href="/favicons/apple-touch-icon.png">
        <link rel="icon" type="image/png" href="/favicons/favicon-32x32.png" sizes="32x32">
        <link rel="icon" type="image/png" href="/favicons/favicon-16x16.png" sizes="16x16">
        <link rel="manifest" href="/favicons/manifest.json">
        <link rel="mask-icon" href="/favicons/safari-pinned-tab.svg" color="#8b5cf6">
        <link rel="shortcut icon" href="/favicons/favicon.ico">
        <meta name="msapplication-config" content="/favicons/browserconfig.xml">
        <meta name="theme-color" content="#07070b">

        @include('layouts.scripts')

        @section('scripts')
            {!! Theme::css('vendor/select2/select2.min.css?t={cache-version}') !!}
            {!! Theme::css('vendor/bootstrap/bootstrap.min.css?t={cache-version}') !!}
            {!! Theme::css('vendor/adminlte/admin.min.css?t={cache-version}') !!}
            {!! Theme::css('vendor/adminlte/colors/skin-blue.min.css?t={cache-version}') !!}
            {!! Theme::css('vendor/sweetalert/sweetalert.min.css?t={cache-version}') !!}
            {!! Theme::css('vendor/animate/animate.min.css?t={cache-version}') !!}
            {!! Theme::css('css/pterodactyl.css?t={cache-version}') !!}
            <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/4.7.0/css/font-awesome.min.css">
            <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/ionicons/2.0.1/css/ionicons.min.css">

            <!--[if lt IE 9]>
            <script src="https://oss.maxcdn.com/html5shiv/3.7.3/html5shiv.min.js"></script>
            <script src="https://oss.maxcdn.com/respond/1.4.2/respond.min.js"></script>
            <![endif]-->
        @show
        <style>
            @keyframes el7-fade-up {
                0% { opacity: 0; transform: translateY(12px); filter: blur(4px); }
                100% { opacity: 1; transform: translateY(0); }
            }
            @keyframes el7-glow {
                0%, 100% { box-shadow: 0 0 0 rgba(139, 92, 246, 0); }
                50% { box-shadow: 0 0 24px rgba(139, 92, 246, 0.22); }
            }
            :root {
                --el7-bg-a: #07070b;
                --el7-surface: #0b0b10;
                --el7-surface-strong: #111117;
                --el7-surface-raised: #15151d;
                --el7-border: rgba(139, 92, 246, 0.32);
                --el7-border-soft: rgba(139, 92, 246, 0.18);
                --el7-text: #ffffff;
                --el7-muted: #a3a3b2;
                --el7-dim: #74748a;
                --el7-accent: #8b5cf6;
                --el7-accent-light: #a78bfa;
                --el7-danger: #ef4444;
                --el7-success: #10b981;
                --el7-warning: #f59e0b;
            }
            body.hold-transition.skin-blue.fixed.sidebar-mini {
                background: var(--el7-bg-a);
                color: var(--el7-text);
                letter-spacing: 0;
            }
            .main-header .logo, .main-header .navbar {
                background: var(--el7-surface) !important;
                border-color: var(--el7-border) !important;
                box-shadow: 0 14px 38px rgba(0, 0, 0, 0.48);
            }
            .main-header .logo {
                border-right: 1px solid var(--el7-border-soft);
                font-weight: 700;
                letter-spacing: 0.02em;
                text-shadow: 0 0 18px rgba(139, 92, 246, 0.36);
            }
            .main-sidebar, .content-wrapper, .main-footer {
                background: var(--el7-bg-a) !important;
                border-color: var(--el7-border-soft) !important;
            }
            .main-sidebar {
                background: var(--el7-surface) !important;
                border-right: 1px solid var(--el7-border-soft);
            }
            .sidebar-menu > li > a, .sidebar-menu .header, .navbar-nav > li > a { color: var(--el7-text) !important; }
            .sidebar-menu .header {
                color: var(--el7-dim) !important;
                background: var(--el7-surface-strong) !important;
                letter-spacing: 0.08em;
            }
            .sidebar-menu > li.active > a, .sidebar-menu > li > a:hover {
                background: rgba(139, 92, 246, 0.14) !important;
                border-left: 3px solid var(--el7-accent);
                box-shadow: inset 0 0 0 1px rgba(139, 92, 246, 0.1), 0 0 20px rgba(139, 92, 246, 0.12);
            }
            .content-wrapper {
                border-left: 1px solid var(--el7-border-soft);
                min-height: 100vh;
            }
            .content-header h1 {
                font-weight: 700;
                letter-spacing: 0;
            }
            .content-header > .breadcrumb,
            .breadcrumb {
                background: transparent !important;
            }
            .breadcrumb > li,
            .breadcrumb > li > a {
                color: var(--el7-muted) !important;
            }
            .box, .alert {
                border-radius: 8px;
                border: 1px solid var(--el7-border-soft);
                background: var(--el7-surface) !important;
                animation: el7-fade-up 280ms ease;
            }
            .box {
                box-shadow: 0 18px 48px rgba(0, 0, 0, 0.5), inset 0 1px 0 rgba(255, 255, 255, 0.04);
                overflow: hidden;
            }
            .box:hover {
                border-color: var(--el7-border);
                box-shadow: 0 24px 64px rgba(0, 0, 0, 0.58), 0 0 28px rgba(139, 92, 246, 0.14);
            }
            .box .box-header.with-border {
                border-bottom: 1px solid var(--el7-border-soft) !important;
                background: var(--el7-surface-strong) !important;
            }
            .box .box-title, .content-header h1, .content-header h1 small { color: var(--el7-text) !important; }
            .content-header h1 small { color: var(--el7-muted) !important; }
            .table {
                color: #d4d4df !important;
                background: var(--el7-surface) !important;
            }
            .table > thead > tr > th,
            .table > tbody > tr > th,
            .table > tbody > tr > td {
                border-color: var(--el7-border-soft) !important;
                vertical-align: middle !important;
            }
            .table > thead > tr > th,
            .table > tbody > tr:first-child > th {
                background: var(--el7-surface-strong) !important;
                color: var(--el7-muted) !important;
                text-transform: uppercase;
                font-size: 11px;
                letter-spacing: 0.08em;
            }
            .table-striped > tbody > tr:nth-of-type(odd),
            .table-striped > tbody > tr:nth-of-type(even),
            .table-hover > tbody > tr {
                background: var(--el7-surface) !important;
            }
            .table-hover > tbody > tr:hover,
            .table > tbody > tr:hover > td {
                background: rgba(139, 92, 246, 0.1) !important;
            }
            code {
                background: var(--el7-surface-raised) !important;
                color: var(--el7-accent-light) !important;
                border: 1px solid var(--el7-border-soft);
                border-radius: 4px;
            }
            .form-control, .select2-selection, .input-group .input-group-addon, .input-group .input-group-btn .btn {
                background: var(--el7-surface-strong) !important;
                border: 1px solid var(--el7-border-soft) !important;
                color: var(--el7-text) !important;
                border-radius: 8px !important;
            }
            .form-control:focus { border-color: var(--el7-accent) !important; box-shadow: 0 0 0 2px rgba(139, 92, 246, 0.22) !important; }
            .btn {
                border-radius: 8px !important;
                border: 1px solid var(--el7-border-soft) !important;
                transition: all 180ms ease;
                background: var(--el7-surface-strong) !important;
                color: var(--el7-text) !important;
            }
            .btn-primary { border-color: rgba(139, 92, 246, 0.58) !important; color: #ffffff !important; }
            .btn-default { color: #d4d4df !important; }
            .btn-success { border-color: rgba(16, 185, 129, 0.52) !important; color: #bbf7d0 !important; }
            .btn-warning { border-color: rgba(245, 158, 11, 0.52) !important; color: #fde68a !important; }
            .btn-danger { border-color: rgba(239, 68, 68, 0.52) !important; color: #fecaca !important; }
            .btn:hover {
                transform: translateY(-1px);
                border-color: var(--el7-border) !important;
                box-shadow: 0 0 20px rgba(139, 92, 246, 0.16);
            }
            .pagination > li > a, .pagination > li > span {
                background: var(--el7-surface-strong) !important;
                border-color: var(--el7-border-soft) !important;
                color: var(--el7-muted) !important;
            }
            .pagination > .active > span,
            .pagination > .active > a {
                border-color: var(--el7-accent) !important;
                color: #ffffff !important;
            }
            .label {
                border-radius: 999px;
                border: 1px solid transparent;
                background: var(--el7-surface-raised) !important;
            }
            .label-success { color: #bbf7d0 !important; border-color: rgba(16, 185, 129, 0.45); }
            .label-warning { color: #fde68a !important; border-color: rgba(245, 158, 11, 0.45); }
            .label.bg-maroon, .label-danger { color: #fecaca !important; border-color: rgba(239, 68, 68, 0.45); }
            .select2-container--default .select2-results__option,
            .select2-dropdown {
                background: var(--el7-surface-strong) !important;
                color: var(--el7-text) !important;
                border-color: var(--el7-border-soft) !important;
            }
            .main-footer a { color: var(--el7-muted); }
            .skin-blue .main-header .navbar .nav > li > a:hover,
            .skin-blue .main-header .navbar .nav > li > a:active,
            .skin-blue .main-header .navbar .nav > li > a:focus,
            .skin-blue .main-header .navbar .sidebar-toggle:hover {
                background: rgba(139, 92, 246, 0.12) !important;
                color: #ffffff !important;
                box-shadow: inset 0 -2px 0 var(--el7-accent);
            }
            .skin-blue .main-header li.user-header,
            .navbar-nav > .user-menu > .dropdown-menu > li.user-header,
            .navbar-nav > .user-menu > .dropdown-menu > .user-footer,
            .dropdown-menu,
            .dropdown-menu > li > a {
                background: var(--el7-surface) !important;
                color: var(--el7-text) !important;
                border-color: var(--el7-border-soft) !important;
            }
            .dropdown-menu {
                border: 1px solid var(--el7-border-soft) !important;
                box-shadow: 0 18px 48px rgba(0, 0, 0, 0.58), 0 0 22px rgba(139, 92, 246, 0.12) !important;
            }
            .dropdown-menu > li > a:hover {
                background: rgba(139, 92, 246, 0.12) !important;
                color: #ffffff !important;
            }
            .box.box-primary,
            .box.box-info,
            .box.box-success,
            .box.box-warning,
            .box.box-danger,
            .box.box-solid,
            .box-primary,
            .box-success,
            .box-warning,
            .box-danger,
            .box-info {
                border-top-color: var(--el7-border) !important;
            }
            .box.box-solid > .box-header,
            .box-primary > .box-header,
            .box-success > .box-header,
            .box-warning > .box-header,
            .box-danger > .box-header,
            .box-info > .box-header {
                background: var(--el7-surface-strong) !important;
                color: var(--el7-text) !important;
                border-bottom: 1px solid var(--el7-border-soft) !important;
            }
            .box-footer,
            .box-body,
            .nav-tabs-custom,
            .nav-tabs-custom > .tab-content,
            .tab-content,
            .panel,
            .panel-default,
            .panel-body,
            .panel-heading,
            .well,
            .list-group-item {
                background: var(--el7-surface) !important;
                color: var(--el7-text) !important;
                border-color: var(--el7-border-soft) !important;
            }
            .nav-tabs-custom {
                border: 1px solid var(--el7-border-soft);
                border-radius: 8px;
                overflow: hidden;
                box-shadow: 0 18px 48px rgba(0, 0, 0, 0.5);
            }
            .nav-tabs,
            .nav-tabs-custom > .nav-tabs {
                border-bottom-color: var(--el7-border-soft) !important;
                background: var(--el7-surface-strong) !important;
            }
            .nav-tabs > li > a,
            .nav-tabs-custom > .nav-tabs > li > a {
                color: var(--el7-muted) !important;
                border: 0 !important;
                border-radius: 0 !important;
            }
            .nav-tabs > li.active > a,
            .nav-tabs > li.active > a:hover,
            .nav-tabs-custom > .nav-tabs > li.active > a,
            .nav-tabs-custom > .nav-tabs > li.active > a:hover {
                background: var(--el7-surface) !important;
                color: #ffffff !important;
                box-shadow: inset 0 2px 0 var(--el7-accent);
            }
            .info-box,
            .small-box,
            .callout,
            .progress,
            .progress-bar {
                background: var(--el7-surface) !important;
                border: 1px solid var(--el7-border-soft) !important;
                color: var(--el7-text) !important;
                box-shadow: 0 16px 38px rgba(0, 0, 0, 0.45) !important;
            }
            .info-box-icon,
            .small-box .icon,
            .progress-bar {
                background: var(--el7-surface-strong) !important;
                color: var(--el7-accent-light) !important;
                border-right: 1px solid var(--el7-border-soft);
            }
            .progress {
                height: 10px;
                border-radius: 999px;
                overflow: hidden;
            }
            .progress-bar {
                border: 0 !important;
                box-shadow: 0 0 18px rgba(139, 92, 246, 0.2) !important;
            }
            .table-responsive,
            .dataTables_wrapper,
            .dataTables_scroll,
            .dataTables_scrollBody {
                background: var(--el7-surface) !important;
                border-color: var(--el7-border-soft) !important;
            }
            .table > tbody > tr,
            .table > tfoot > tr {
                background: var(--el7-surface) !important;
                transition: background 180ms ease, box-shadow 180ms ease;
            }
            .table > tbody > tr > td,
            .table > tfoot > tr > td {
                font-variant-numeric: tabular-nums;
            }
            .table-hover > tbody > tr:hover > td,
            .table-hover > tbody > tr:hover > th,
            .table > tbody > tr:hover > td,
            .table > tbody > tr:hover > th {
                background: rgba(139, 92, 246, 0.1) !important;
                box-shadow: inset 2px 0 0 rgba(139, 92, 246, 0.74);
            }
            .table a,
            .box a,
            .content a {
                color: var(--el7-accent-light);
            }
            .table a:hover,
            .box a:hover,
            .content a:hover {
                color: #ffffff;
                text-shadow: 0 0 12px rgba(139, 92, 246, 0.45);
            }
            .bg-blue,
            .bg-light-blue,
            .bg-aqua,
            .bg-teal,
            .bg-purple,
            .bg-navy,
            .bg-gray,
            .bg-black {
                background: var(--el7-surface-strong) !important;
                color: var(--el7-text) !important;
                border-color: var(--el7-border-soft) !important;
            }
            .bg-green,
            .label-success,
            .badge.bg-green {
                background: rgba(16, 185, 129, 0.12) !important;
                color: #bbf7d0 !important;
                border-color: rgba(16, 185, 129, 0.45) !important;
            }
            .bg-yellow,
            .label-warning,
            .badge.bg-yellow {
                background: rgba(245, 158, 11, 0.12) !important;
                color: #fde68a !important;
                border-color: rgba(245, 158, 11, 0.45) !important;
            }
            .bg-red,
            .bg-maroon,
            .label-danger,
            .badge.bg-red,
            .badge.bg-maroon {
                background: rgba(239, 68, 68, 0.12) !important;
                color: #fecaca !important;
                border-color: rgba(239, 68, 68, 0.45) !important;
            }
            .badge,
            .label {
                display: inline-flex;
                align-items: center;
                min-height: 20px;
                border: 1px solid var(--el7-border-soft);
                font-variant-numeric: tabular-nums;
            }
            .text-blue,
            .text-aqua,
            .text-light-blue,
            .text-purple {
                color: var(--el7-accent-light) !important;
            }
            .text-muted,
            .help-block,
            .description-block .description-text,
            .description-block .description-header,
            .box .box-header .box-title small {
                color: var(--el7-muted) !important;
            }
            .modal-content,
            .modal-header,
            .modal-body,
            .modal-footer,
            .sweet-alert,
            .swal-modal {
                background: var(--el7-surface) !important;
                color: var(--el7-text) !important;
                border-color: var(--el7-border-soft) !important;
            }
            .modal-content,
            .sweet-alert,
            .swal-modal {
                border: 1px solid var(--el7-border-soft) !important;
                box-shadow: 0 24px 70px rgba(0, 0, 0, 0.64), 0 0 30px rgba(139, 92, 246, 0.12) !important;
            }
            .close,
            .modal-header .close {
                color: var(--el7-accent-light) !important;
                opacity: 0.85;
                text-shadow: none;
            }
            .select2-container--default .select2-selection--single,
            .select2-container--default .select2-selection--multiple,
            .select2-container--default .select2-selection--single .select2-selection__rendered,
            .select2-container--default .select2-selection--multiple .select2-selection__choice,
            .select2-container--default .select2-search--dropdown .select2-search__field {
                background: var(--el7-surface-strong) !important;
                color: var(--el7-text) !important;
                border-color: var(--el7-border-soft) !important;
            }
            .select2-container--default .select2-results__option--highlighted[aria-selected],
            .select2-container--default .select2-results__option[aria-selected=true] {
                background: rgba(139, 92, 246, 0.16) !important;
                color: #ffffff !important;
            }
            .form-control[disabled],
            .form-control[readonly],
            fieldset[disabled] .form-control {
                background: #09090d !important;
                color: var(--el7-dim) !important;
                opacity: 1;
            }
            .input-group-addon,
            .input-group-btn .btn {
                font-variant-numeric: tabular-nums;
            }
            .btn-primary,
            .btn-info,
            .btn-link {
                border-color: rgba(139, 92, 246, 0.58) !important;
                color: #ffffff !important;
            }
            .btn.active,
            .btn:active,
            .btn:focus {
                border-color: var(--el7-accent) !important;
                box-shadow: 0 0 0 2px rgba(139, 92, 246, 0.26), 0 0 20px rgba(139, 92, 246, 0.12) !important;
            }
            .main-footer {
                color: var(--el7-dim);
                border-top: 1px solid var(--el7-border-soft);
            }
            .content {
                animation: el7-fade-up 280ms ease both;
            }
        </style>
    </head>
    <body class="hold-transition skin-blue fixed sidebar-mini">
        <div class="wrapper">
            <header class="main-header">
                <a href="{{ route('index') }}" class="logo">
                    <span>{{ config('app.name', 'Pterodactyl') }}</span>
                </a>
                <nav class="navbar navbar-static-top">
                    <a href="#" class="sidebar-toggle" data-toggle="push-menu" role="button">
                        <span class="sr-only">Toggle navigation</span>
                        <span class="icon-bar"></span>
                        <span class="icon-bar"></span>
                        <span class="icon-bar"></span>
                    </a>
                    <div class="navbar-custom-menu">
                        <ul class="nav navbar-nav">
                            <li class="user-menu">
                                <a href="{{ route('account') }}">
                                    <img src="{{ !empty(Auth::user()->avatar_url) ? Auth::user()->avatar_url : ('https://www.gravatar.com/avatar/' . md5(strtolower(Auth::user()->email)) . '?s=160') }}" class="user-image" alt="User Image">
                                    <span class="hidden-xs">{{ Auth::user()->name_first }} {{ Auth::user()->name_last }}</span>
                                </a>
                            </li>
                            <li>
                                <a href="{{ route('index') }}" data-toggle="tooltip" data-placement="bottom" title="Exit Admin Control"><i class="fa fa-server"></i></a>
                            </li>
                            <li>
                                <a href="{{ route('auth.logout') }}" id="logoutButton" data-toggle="tooltip" data-placement="bottom" title="Logout"><i class="fa fa-sign-out"></i></a>
                            </li>
                        </ul>
                    </div>
                </nav>
            </header>
            <aside class="main-sidebar">
                <section class="sidebar">
                    <ul class="sidebar-menu">
                        <li class="header">BASIC ADMINISTRATION</li>
                        <li class="{{ Route::currentRouteName() !== 'admin.index' ?: 'active' }}">
                            <a href="{{ route('admin.index') }}">
                                <i class="fa fa-home"></i> <span>Overview</span>
                            </a>
                        </li>
                        @if((int) Auth::id() === 1)
                            <li class="{{ ! starts_with(Route::currentRouteName(), 'admin.settings') ?: 'active' }}">
                                <a href="{{ route('admin.settings')}}">
                                    <i class="fa fa-wrench"></i> <span>Settings</span>
                                </a>
                            </li>
                        @endif
                        <li class="{{ ! starts_with(Route::currentRouteName(), 'admin.api') ?: 'active' }}">
                            <a href="{{ route('admin.api.index')}}">
                                <i class="fa fa-gamepad"></i> <span>Application API</span>
                            </a>
                        </li>
                        @if((int) Auth::id() === 1)
                            <li class="{{ ! starts_with(Route::currentRouteName(), 'admin.protect') ?: 'active' }}">
                                <a href="{{ route('admin.protect') }}">
                                    <i class="fa fa-shield"></i> <span>Protect</span>
                                </a>
                            </li>
                        @endif
                        <li class="header">MANAGEMENT</li>
                        @if((int) Auth::id() === 1)
                            <li class="{{ ! starts_with(Route::currentRouteName(), 'admin.databases') ?: 'active' }}">
                                <a href="{{ route('admin.databases') }}">
                                    <i class="fa fa-database"></i> <span>Databases</span>
                                </a>
                            </li>
                            <li class="{{ ! starts_with(Route::currentRouteName(), 'admin.locations') ?: 'active' }}">
                                <a href="{{ route('admin.locations') }}">
                                    <i class="fa fa-globe"></i> <span>Locations</span>
                                </a>
                            </li>
                            <li class="{{ ! starts_with(Route::currentRouteName(), 'admin.nodes') ?: 'active' }}">
                                <a href="{{ route('admin.nodes') }}">
                                    <i class="fa fa-sitemap"></i> <span>Nodes</span>
                                </a>
                            </li>
                        @endif
                        <li class="{{ ! starts_with(Route::currentRouteName(), 'admin.servers') ?: 'active' }}">
                            <a href="{{ route('admin.servers') }}">
                                <i class="fa fa-server"></i> <span>Servers</span>
                            </a>
                        </li>
                        <li class="{{ ! starts_with(Route::currentRouteName(), 'admin.users') ?: 'active' }}">
                            <a href="{{ route('admin.users') }}">
                                <i class="fa fa-users"></i> <span>Users</span>
                            </a>
                        </li>
                        <li class="{{ ! starts_with(Route::currentRouteName(), 'admin.management.danexcoin') ?: 'active' }}">
                            <a href="{{ route('admin.management.danexcoin.index') }}">
                                <i class="fa fa-money"></i> <span>DanexCoin</span>
                            </a>
                        </li>
                        @if((int) Auth::id() === 1)
                            <li class="header">SERVICE MANAGEMENT</li>
                            <li class="{{ ! starts_with(Route::currentRouteName(), 'admin.mounts') ?: 'active' }}">
                                <a href="{{ route('admin.mounts') }}">
                                    <i class="fa fa-magic"></i> <span>Mounts</span>
                                </a>
                            </li>
                            <li class="{{ ! starts_with(Route::currentRouteName(), 'admin.nests') ?: 'active' }}">
                                <a href="{{ route('admin.nests') }}">
                                    <i class="fa fa-th-large"></i> <span>Nests</span>
                                </a>
                            </li>
                        @endif
                    </ul>
                </section>
            </aside>
            <div class="content-wrapper">
                <section class="content-header">
                    @yield('content-header')
                </section>
                <section class="content">
                    <div class="row">
                        <div class="col-xs-12">
                            @if (count($errors) > 0)
                                <div class="alert alert-danger">
                                    There was an error validating the data provided.<br><br>
                                    <ul>
                                        @foreach ($errors->all() as $error)
                                            <li>{{ $error }}</li>
                                        @endforeach
                                    </ul>
                                </div>
                            @endif
                            @foreach (Alert::getMessages() as $type => $messages)
                                @foreach ($messages as $message)
                                    <div class="alert alert-{{ $type }} alert-dismissable" role="alert">
                                        {{ $message }}
                                    </div>
                                @endforeach
                            @endforeach
                        </div>
                    </div>
                    @yield('content')
                </section>
            </div>
            <footer class="main-footer">
                <div class="pull-right small text-gray" style="margin-right:10px;margin-top:-7px;">
                    <strong><i class="fa fa-fw {{ $appIsGit ? 'fa-git-square' : 'fa-code-fork' }}"></i></strong> {{ $appVersion }}<br />
                    <strong><i class="fa fa-fw fa-clock-o"></i></strong> {{ round(microtime(true) - LARAVEL_START, 3) }}s
                </div>
                Copyright &copy; 2015 - {{ date('Y') }} <a href="https://pterodactyl.io/">Pterodactyl Software</a>.
            </footer>
        </div>
        @section('footer-scripts')
            <script src="/js/keyboard.polyfill.js" type="application/javascript"></script>
            <script>keyboardeventKeyPolyfill.polyfill();</script>

            {!! Theme::js('vendor/jquery/jquery.min.js?t={cache-version}') !!}
            {!! Theme::js('vendor/sweetalert/sweetalert.min.js?t={cache-version}') !!}
            {!! Theme::js('vendor/bootstrap/bootstrap.min.js?t={cache-version}') !!}
            {!! Theme::js('vendor/slimscroll/jquery.slimscroll.min.js?t={cache-version}') !!}
            {!! Theme::js('vendor/adminlte/app.min.js?t={cache-version}') !!}
            {!! Theme::js('vendor/bootstrap-notify/bootstrap-notify.min.js?t={cache-version}') !!}
            {!! Theme::js('vendor/select2/select2.full.min.js?t={cache-version}') !!}
            {!! Theme::js('js/admin/functions.js?t={cache-version}') !!}
            <script src="/js/autocomplete.js" type="application/javascript"></script>

            @if(Auth::user()->root_admin)
                <script>
                    $('#logoutButton').on('click', function (event) {
                        event.preventDefault();

                        var that = this;
                        swal({
                            title: 'Do you want to log out?',
                            type: 'warning',
                            showCancelButton: true,
                            confirmButtonColor: '#d9534f',
                            cancelButtonColor: '#d33',
                            confirmButtonText: 'Log out'
                        }, function () {
                             $.ajax({
                                type: 'POST',
                                url: '{{ route('auth.logout') }}',
                                data: {
                                    _token: '{{ csrf_token() }}'
                                },complete: function () {
                                    window.location.href = '{{route('auth.login')}}';
                                }
                        });
                    });
                });
                </script>
            @endif

            <script>
                $(function () {
                    $('[data-toggle="tooltip"]').tooltip();
                })
            </script>
        @show
    </body>
</html>
