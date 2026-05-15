<div class="sidebar-title">
    {{ __('System') }}
</div>
<ul class="sidebar-menu">
    <li @if (Route::is('system'))class="active" @endif><a href="{{ route('system') }}"><i
                class="glyphicon glyphicon-list-alt"></i> {{ __('Status') }}</a></li>
    <li @if (Route::is('system.tools'))class="active" @endif><a href="{{ route('system.tools') }}"><i
                class="glyphicon glyphicon-wrench"></i> {{ __('Tools') }}</a></li>
    <li @if (substr(Route::currentRouteName(), 0, strlen('restapi.api-tokens'))==='restapi.api-tokens' )class="active"
        @endif><a href="{{ route('restapi.api-tokens.index') }}"><i class="glyphicon glyphicon-tag"></i>
            {{ __('API Tokens') }}</a></li>
</ul>