@extends('layouts.app')

@section('title', __('RestApi'))

@section('content')
<div class="container">
    <div class="row">
        <div class="col-md-12">
            <div class="card">
                <div class="card-header">
                    <h3 class="card-title">Módulo RestApi</h3>
                </div>
                <div class="card-body">
                    <p>El módulo RestApi proporciona una interfaz segura para gestionar tokens de acceso a la API de
                        FreeScout.</p>
                    <p>Utilice el menú lateral <strong>System → API Tokens</strong> para acceder a la consola de gestión
                        completa donde podrá:</p>
                    <ul>
                        <li>Ver todos los tokens de API existentes</li>
                        <li>Crear nuevos tokens con permisos específicos</li>
                        <li>Establecer límites de tasa y fechas de expiración</li>
                        <li>Revocar tokens que ya no se necesitan</li>
                    </ul>
                    <p>Para comenzar, haga clic en <strong>System → API Tokens</strong> en el menú de navegación.</p>
                </div>
                <div class="card-footer text-muted">
                    RestApi v{{ \Modules\RestApi\Providers\RestApiServiceProvider::moduleVersion() }}
                </div>
            </div>
        </div>
    </div>
</div>
@endsection