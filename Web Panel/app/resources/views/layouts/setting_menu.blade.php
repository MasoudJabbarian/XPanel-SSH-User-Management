<div class="card-body border-bottom pb-0">
    <ul class="nav nav-tabs analytics-tab" id="myTab" role="tablist">
        <li class="nav-item" role="presentation">
            <a href="general" class="nav-link {{ request()->segment(3) === 'general' ? 'active' : '' }}" type="button" role="tab" aria-selected="false" tabindex="-1"><i class="ti ti-settings"></i>&nbsp;&nbsp;{{__('setting-general-menu')}}</a>
        </li>
        <li class="nav-item" role="presentation">
        </li>
        <li class="nav-item" role="presentation">
            <a href="backup" class="nav-link {{ request()->segment(3) === 'backup' ? 'active' : '' }}" type="button" role="tab" aria-selected="false" tabindex="-1"><i class="ti ti-database"></i>&nbsp;&nbsp;{{__('setting-backup-menu')}}</a>
        </li>
        <li class="nav-item" role="presentation">
            <a href="api" class="nav-link {{ request()->segment(3) === 'api' ? 'active' : '' }}" type="button" role="tab" aria-selected="false" tabindex="-1"><i class="ti ti-puzzle"></i>&nbsp;&nbsp;{{__('setting-api-menu')}}</a>
        </li>
        <li class="nav-item" role="presentation">
            <a href="block" class="nav-link {{ request()->segment(3) === 'block' ? 'active' : '' }}" type="button" role="tab" aria-selected="false" tabindex="-1"><i class="ti ti-hand-stop"></i>&nbsp;&nbsp;{{__('setting-blockip-menu')}}</a>
        </li>
        <li class="nav-item" role="presentation">
        </li>

        <li class="nav-item" role="presentation">
        </li>
        <li class="nav-item" role="presentation">
        </li>
        <li class="nav-item" role="presentation">
        </li>
        <li class="nav-item" role="presentation">
        </li>
        <li class="nav-item" role="presentation">
        </li>
    </ul>
</div>
