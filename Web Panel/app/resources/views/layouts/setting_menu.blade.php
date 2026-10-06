<div class="card-body border-bottom pb-0">
<ul class="nav nav-tabs analytics-tab">
    <li class="nav-item"><a href="{{ url('/settings/general') }}" class="nav-link {{ request()->segment(2)==='general'?'active':'' }}">General</a></li>
    <li class="nav-item"><a href="{{ url('/settings/backup') }}" class="nav-link {{ request()->segment(2)==='backup'?'active':'' }}">Backups</a></li>
    <li class="nav-item"><a href="{{ url('/settings/api') }}" class="nav-link {{ request()->segment(2)==='api'?'active':'' }}">API</a></li>
</ul>
</div>