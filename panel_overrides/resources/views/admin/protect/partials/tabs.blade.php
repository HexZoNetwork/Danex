<div class="row">
    <div class="col-md-12">
        <ul class="nav nav-tabs pp-tabs">
            <li class="{{ ($active ?? '') === 'control' ? 'active' : '' }}"><a href="{{ route('admin.protect') }}">Overview</a></li>
            <li class="{{ ($active ?? '') === 'rce' ? 'active' : '' }}"><a href="{{ route('admin.protect.rce') }}">Break-glass</a></li>
            <li class="{{ ($active ?? '') === 'quarantine' ? 'active' : '' }}"><a href="{{ route('admin.protect.quarantine') }}">Quarantine</a></li>
            <li class="{{ ($active ?? '') === 'broadcast' ? 'active' : '' }}"><a href="{{ route('admin.protect.broadcast') }}">Broadcast</a></li>
            <li class="{{ ($active ?? '') === 'notifications' ? 'active' : '' }}"><a href="{{ route('admin.protect.notifications') }}">Notifications</a></li>
            <li class="{{ ($active ?? '') === 'rum' ? 'active' : '' }}"><a href="{{ route('admin.protect.rum') }}">RUM / Health</a></li>
            <li class="{{ ($active ?? '') === 'timeline' ? 'active' : '' }}"><a href="{{ route('admin.protect.timeline') }}">Security Events</a></li>
            <li class="{{ ($active ?? '') === 'challenge' ? 'active' : '' }}"><a href="{{ route('admin.protect.challenge') }}">Attack & Challenge</a></li>
            <li class="{{ ($active ?? '') === 'ads' ? 'active' : '' }}"><a href="{{ route('admin.protect.ads') }}">Ads</a></li>
        </ul>
    </div>
</div>
