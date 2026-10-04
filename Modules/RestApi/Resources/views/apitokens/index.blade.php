@extends('layouts.app')
@section('content')
<div class="container">
    <div class="row">
        <div class="col-md-12">
            <div class="card">
                <div class="card-header">
                    <h3 class="card-title">Administrar Tokens de API</h3>
                    <div class="card-tools">
                        <button type="button" class="btn btn btn-sm" data-toggle="modal"
                            data-target="#createTokenModal">
                            <i class="fas fa-plus"></i> Crear Nuevo Token
                        </button>
                    </div>
                </div>
                <!-- /.card-header -->
                <div class="card-body">
                    @if(session('success'))
                    <div class="alert alert-success alert-dismissible">
                        <button type="button" class="close" data-dismiss="alert" aria-hidden="true">×</button>
                        <h5><i class="icon fas fa-check"></i> Éxito!</h5>
                        {{ session('success') }}
                    </div>
                    @endif
                    @if(session('token'))
                    <div class="alert alert-info alert-dismissible">
                        <button type="button" class="close" data-dismiss="alert" aria-hidden="true">×</button>
                        <h5><i class="icon fas fa-info-circle"></i> ¡Importante!</h5>
                        <p>Tu token ha sido creado. Guárdalo en un lugar seguro, ya que no se mostrará nuevamente.</p>
                        <div class="form-group">
                            <label for="tokenValue">Token:</label>
                            <input type="text" class="form-control" id="tokenValue" value="{{ session('token') }}"
                                readonly>
                            <button type="button" class="btn btn-sm btn-primary mt-2"
                                onclick="this.select(); document.execCommand('copy');">
                                Copiar al portapapeles
                            </button>
                        </div>
                        <small class="form-text text-muted">Token ID: {{ session('tokenId') }}</small>
                    </div>
                    @endif
                    @if(count($tokens) > 0)
                    <table class="table table-hover table-striped">
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Usuario</th>
                                <th>Nombre</th>
                                <th>Buzones</th>
                                <th>Activo</th>
                                <th>Límite de tasa</th>
                                <th>Creado</th>
                                <th>Expira</th>
                                <th>Acciones</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($tokens as $token)
                            <tr>
                                <td>{{ $token->id }}</td>
                                <td>{{ $token->user->getFullName() }}</td>
                                <td>{{ $token->name }}</td>
                                <td>
                                    @if($token->mailbox_ids)
                                    {{ implode(', ', $token->mailbox_ids) }}
                                    @else
                                    <span class="text-muted">Todos</span>
                                    @endif
                                </td>
                                <td>{!! $token->active ? '<span class="badge badge-success">Sí</span>' : '<span
                                        class="badge badge-danger">No</span>' !!}</td>
                                <td>{{ $token->rate_limit }}/h</td>
                                <td>{{ $token->created_at->format('Y-m-d H:i') }}</td>
                                <td>
                                    @if($token->expires_at)
                                    {{ $token->expires_at->format('Y-m-d') }}
                                    @else
                                    <span class="text-muted">Nunca</span>
                                    @endif
                                </td>
                                <td>
                                    @if(!$token->expires_at || $token->expires_at->isFuture() || !$token->expires_at)
                                    <form action="{{ route('restapi.api-tokens.destroy', $token->id) }}" method="POST"
                                        style="display:inline;">
                                        {{ csrf_field() }}
                                        {{ method_field('DELETE') }}
                                        <button type="submit" class="btn btn-danger btn-sm"
                                            onclick="return confirm('¿Está seguro de que desea revocar este token? Esta acción no se puede deshacer.');">
                                            Revocar
                                        </button>
                                    </form>
                                    @else
                                    <span class="badge badge-secondary">Expirado</span>
                                    @endif
                                </td>
                            </tr>
                            @endforeach
                        </tbody>
                    </table>
                    @else
                    <div class="alert alert-info">
                        No se encontraron tokens de API. Cree uno nuevo usando el botón de arriba.
                    </div>
                    @endif
                </div>
                <!-- /.card-body -->
            </div>
            <!-- /.card -->
        </div>
        <!-- /.col -->
    </div>
    <!-- /.row -->
</div>
<!-- /.container -->
<!-- Modal para crear nuevo token -->
<div class="modal fade" id="createTokenModal" tabindex="-1" role="dialog" aria-labelledby="createTokenModalLabel"
    aria-hidden="true">
    <div class="modal-dialog modal-lg" role="document">
        <div class="modal-content">
            <form action="{{ route('restapi.api-tokens.store') }}" method="POST">
                {{ csrf_field() }}
                <div class="modal-header">
                    <h5 class="modal-title" id="createTokenModalLabel">Crear Nuevo Token de API</h5>
                    <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                        <span aria-hidden="true">×</span>
                    </button>
                </div>
                <div class="modal-body">
                    <div class="form-group">
                        <label for="user_id">Usuario *</label>
                        <select class="form-control" id="user_id" name="user_id" required>
                            <option value="">Seleccione un usuario</option>
                            @foreach($users as $user)
                            <option value="{{ $user->id }}">{{ $user->getFullName() }} (ID: {{ $user->id }})</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="name">Nombre del token *</label>
                        <input type="text" class="form-control" id="name" name="name" required
                            placeholder="Ej: Token para integración con CRM">
                    </div>
                    <div class="form-group">
                        <label for="mailbox_ids">IDs de buzones (opcional)</label>
                        <input type="text" class="form-control" id="mailbox_ids" name="mailbox_ids"
                            placeholder="1,2,3 (dejar vacío para todos)">
                        <small class="form-text text-muted">Ingrese los IDs de los buzones separados por comas que este
                            token podrá acceder. Deje vacío para acceso a todos los buzones.</small>
                    </div>
                    <div class="alert alert-info">
                        <i class="fas fa-info-circle"></i>
                        {{ __('rest-api::apitokens.no_media_restriction') }}
                    </div>
                    <div class="form-group">
                        <label for="rate_limit">Límite de tasa *</label>
                        <input type="number" class="form-control" id="rate_limit" name="rate_limit" min="1" value="1000"
                            required>
                        <small class="form-text text-muted">Número máximo de llamadas por hora permitidas para este
                            token.</small>
                    </div>
                    <div class="form-group">
                        <label for="expires_at">Fecha de expiración (opcional)</label>
                        <input type="date" class="form-control" id="expires_at" name="expires_at">
                        <small class="form-text text-muted">Deje vacío para que el token nunca expire.</small>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancelar</button>
                    <button type="submit" class="btn btn-primary">Crear Token</button>
                </div>
            </form>
        </div>
    </div>
</div>
@push('scripts')
<script>
    // Mostrar alerta de copia exitosa
    function showCopySuccess() {
        const btn = event.target;
        const originalText = btn.innerHTML;
        btn.innerHTML = '<i class="fas fa-check"></i> Copiado!';
        setTimeout(() => {
            btn.innerHTML = originalText;
        }, 2000);
    }
</script>
@endpush
@endsection