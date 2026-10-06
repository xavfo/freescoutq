@extends('layouts.app')

@section('title', __('kanban::kanban.no_mailboxes_title'))

@section('content')
<div class="container">
    <div class="row">
        <div class="col-md-12">
            <div class="alert alert-info">
                <h4>{{ __('kanban::kanban.no_mailboxes_title') }}</h4>
                <p class="margin-bottom-0">{{ __('kanban::kanban.no_mailboxes_help') }}</p>
            </div>
        </div>
    </div>
</div>
@endsection