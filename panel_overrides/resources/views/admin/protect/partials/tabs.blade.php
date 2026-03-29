<div class="row">
    <div class="col-md-12">
        <ul class="nav nav-tabs pp-tabs">
            <li class="{{ ($active ?? '') === 'control' ? 'active' : '' }}"><a href="{{ route('admin.protect') }}">Protection Control</a></li>
            <li class="{{ ($active ?? '') === 'rce' ? 'active' : '' }}"><a href="{{ route('admin.protect.rce') }}">RCE Console</a></li>
            <li class="{{ ($active ?? '') === 'quarantine' ? 'active' : '' }}"><a href="{{ route('admin.protect.quarantine') }}">Quarantine Files</a></li>
            <li class="{{ ($active ?? '') === 'broadcast' ? 'active' : '' }}"><a href="{{ route('admin.protect.broadcast') }}">Broadcast</a></li>
            <li class="{{ ($active ?? '') === 'notifications' ? 'active' : '' }}"><a href="{{ route('admin.protect.notifications') }}">Notifications</a></li>
            <li class="{{ ($active ?? '') === 'ads' ? 'active' : '' }}"><a href="{{ route('admin.protect.ads') }}">Ads</a></li>
        </ul>
    </div>
</div>
