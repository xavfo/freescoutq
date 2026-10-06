/*
 * Tablero Kanban — comportamiento.
 *
 * Reglas:
 *   · El marcado de las tarjetas lo genera SIEMPRE el servidor (Blade). Aquí no
 *     se construye HTML de tarjetas: se inyecta el que devuelve /kanban/board.
 *   · Al soltar una tarjeta se aplica el cambio de forma optimista y, si el
 *     servidor dice que no, se revierte a su sitio exacto.
 *   · Las acciones rápidas reutilizan el endpoint del core (conversations.ajax),
 *     así que respetan permisos, historial y notificaciones sin duplicar lógica.
 */
( function () {
    'use strict';

    var root = document.getElementById( 'kanban' );

    if ( !root ) {
        return;
    }

    var boardUrl = root.getAttribute( 'data-board-url' );
    var moveUrl = root.getAttribute( 'data-move-url' );
    var conversationsUrl = root.getAttribute( 'data-conversations-url' );
    var moveLabel = root.getAttribute( 'data-label-move' );
    var errorLabel = root.getAttribute( 'data-label-error' );

    var dragCard = null;

    /* ----------------------------- utilidades ----------------------------- */

    function columns () {
        return Array.prototype.slice.call( root.querySelectorAll( '.kb-col' ) );
    }

    function bodyOf ( col ) {
        return col.querySelector( '.kb-col-body' );
    }

    function filters () {
        var data = {};
        var form = document.getElementById( 'kb-filters' );

        if ( !form ) {
            return data;
        }

        Array.prototype.forEach.call( form.elements, function ( el ) {
            if ( !el.name ) {
                return;
            }
            if ( el.type === 'checkbox' ) {
                if ( el.checked ) {
                    data[el.name] = el.value;
                }
            } else if ( el.value !== '' ) {
                data[el.name] = el.value;
            }
        } );

        return data;
    }

    function refreshCounts () {
        columns().forEach( function ( col ) {
            var body = bodyOf( col );
            var cards = body.querySelectorAll( '.kb-card' );
            var wip = parseInt( col.getAttribute( 'data-wip' ) || '0', 10 );
            var badge = col.querySelector( '.kb-count' );

            if ( badge ) {
                badge.textContent = wip ? cards.length + '/' + wip : String( cards.length );
                badge.className = 'kb-count' + ( wip && cards.length > wip ? ' kb-over' : '' );
            }

            applyEmpty( col );
        } );
    }

    /* Placeholder de columna vacía (el partial de tarjetas no lo genera). */
    function applyEmpty ( col ) {
        var body = bodyOf( col );
        var hasCards = body.querySelectorAll( '.kb-card' ).length > 0;
        var empty = body.querySelector( '.kb-empty' );

        if ( !hasCards && !empty ) {
            var div = document.createElement( 'div' );
            div.className = 'kb-empty';
            div.textContent = col.getAttribute( 'data-empty' ) || '';
            body.appendChild( div );
        } else if ( hasCards && empty ) {
            empty.remove();
        }
    }

    function closeMenus () {
        Array.prototype.forEach.call( root.querySelectorAll( '.kb-menu' ), function ( menu ) {
            menu.remove();
        } );
    }

    /* ------------------------- carga del tablero --------------------------- */

    function paintColumn ( data ) {
        var col = root.querySelector( '.kb-col[data-stage="' + data.id + '"]' );

        if ( !col ) {
            return;
        }

        var body = bodyOf( col );
        body.innerHTML = data.html || '';
        col.setAttribute( 'data-offset', data.offset );

        var badge = col.querySelector( '.kb-count' );
        if ( badge ) {
            var wip = parseInt( col.getAttribute( 'data-wip' ) || '0', 10 );
            badge.textContent = wip ? data.count + '/' + wip : String( data.count );
            badge.className = 'kb-count' + ( wip && data.count > wip ? ' kb-over' : '' );
        }

        var foot = col.querySelector( '.kb-col-foot' );
        if ( foot ) {
            foot.style.display = data.has_more ? '' : 'none';
        }

        applyEmpty( col );
    }

    function loadBoard ( extra ) {
        var data = filters();

        if ( extra ) {
            Object.keys( extra ).forEach( function ( key ) {
                data[key] = extra[key];
            } );
        }

        fsAjax( data, boardUrl, function ( response ) {
            if ( response.status !== 'success' ) {
                showFloatingAlert( 'error', response.msg || errorLabel );
                return;
            }

            ( response.columns || [] ).forEach( paintColumn );
            ajaxFinish();
        }, true, function ( xhr ) {
            ajaxFinish();
            showFloatingAlert( 'error', errorLabel + ' (' + xhr.status + ')' );
        } );
    }

    /* "Cargar más": añade la siguiente página de ESA columna. */
    function loadMore ( col ) {
        var body = bodyOf( col );
        var stage = col.getAttribute( 'data-stage' );
        var offset = parseInt( col.getAttribute( 'data-offset' ) || '0', 10 );
        var data = filters();

        data.stage = stage;
        data.offset = offset;

        fsAjax( data, boardUrl, function ( response ) {
            ajaxFinish();

            if ( response.status !== 'success' ) {
                showFloatingAlert( 'error', response.msg || errorLabel );
                return;
            }

            var column = ( response.columns || [] )[0];

            if ( !column ) {
                return;
            }

            var empty = body.querySelector( '.kb-empty' );
            if ( empty ) {
                empty.remove();
            }

            body.insertAdjacentHTML( 'beforeend', column.html || '' );
            col.setAttribute( 'data-offset', column.offset );

            var badge = col.querySelector( '.kb-count' );
            if ( badge ) {
                badge.textContent = String( column.count );
            }

            var foot = col.querySelector( '.kb-col-foot' );
            if ( foot && !column.has_more ) {
                foot.style.display = 'none';
            }
        }, true, function ( xhr ) {
            ajaxFinish();
            showFloatingAlert( 'error', errorLabel + ' (' + xhr.status + ')' );
        } );
    }

    /* ---------------------------- drag & drop ------------------------------ */

    root.addEventListener( 'dragstart', function ( event ) {
        var card = event.target.closest( '.kb-card' );

        if ( !card ) {
            return;
        }

        dragCard = card;
        card.classList.add( 'kb-dragging' );

        event.dataTransfer.effectAllowed = 'move';

        try {
            event.dataTransfer.setData( 'text/plain', card.getAttribute( 'data-id' ) );
        } catch ( e ) {
            // Safari/IE antiguos: no pasa nada, usamos la referencia en memoria.
        }
    } );

    root.addEventListener( 'dragend', function () {
        if ( dragCard ) {
            dragCard.classList.remove( 'kb-dragging' );
        }
        dragCard = null;
        columns().forEach( function ( col ) {
            col.classList.remove( 'kb-drop' );
        } );
    } );

    root.addEventListener( 'dragover', function ( event ) {
        var body = event.target.closest( '.kb-col-body' );

        if ( !body || !dragCard ) {
            return;
        }

        event.preventDefault();
        event.dataTransfer.dropEffect = 'move';

        var col = body.closest( '.kb-col' );

        columns().forEach( function ( other ) {
            if ( other !== col ) {
                other.classList.remove( 'kb-drop' );
            }
        } );

        col.classList.add( 'kb-drop' );
    } );

    /**
     * Mueve una tarjeta a otra columna.
     *
     * Lo usan el arrastre y el menú ("Mover a"), así que hay un solo camino:
     * cambio optimista en pantalla y reversión exacta si el servidor lo rechaza.
     */
    function moveCard ( card, col ) {
        var body = bodyOf( col );
        var origin = card.parentNode;
        var before = card.nextSibling;

        if ( origin === body ) {
            return;
        }

        var stage = col.getAttribute( 'data-stage' );
        var stageName = col.getAttribute( 'data-name' );

        // 1) Optimista: la tarjeta se mueve ya.
        body.appendChild( card );
        refreshCounts();

        fsAjax(
            {
                conversation_id: card.getAttribute( 'data-id' ),
                stage: stage,
                mailbox_id: filters().mailbox_id || ''
            },
            moveUrl,
            function ( response ) {
                ajaxFinish();

                if ( response.status !== 'success' ) {
                    // 2) Reversión: vuelve exactamente a su sitio.
                    origin.insertBefore( card, before );
                    refreshCounts();
                    showFloatingAlert( 'error', response.msg || errorLabel );
                    return;
                }

                if ( response.status_changed ) {
                    loadBoard();
                }

                showFloatingAlert( 'success', response.msg || moveLabel.replace( ':stage', stageName ) );
            },
            true,
            function ( xhr ) {
                ajaxFinish();
                origin.insertBefore( card, before );
                refreshCounts();
                showFloatingAlert( 'error', errorLabel + ' (' + xhr.status + ')' );
            }
        );
    }

    root.addEventListener( 'drop', function ( event ) {
        var body = event.target.closest( '.kb-col-body' );

        if ( !body || !dragCard ) {
            return;
        }

        event.preventDefault();

        var col = body.closest( '.kb-col' );
        col.classList.remove( 'kb-drop' );

        moveCard( dragCard, col );
    } );

    /* ------------------------ acciones rápidas ----------------------------- */

    function coreAction ( data, done ) {
        fsAjax( data, conversationsUrl, function ( response ) {
            ajaxFinish();

            if ( response.status === 'success' ) {
                showFloatingAlert( 'success', response.msg || '' );

                if ( typeof done === 'function' ) {
                    done( response );
                }
            } else {
                showFloatingAlert( 'error', response.msg || errorLabel );
            }
        }, true, function ( xhr ) {
            ajaxFinish();
            showFloatingAlert( 'error', errorLabel + ' (' + xhr.status + ')' );
        } );
    }

    /**
     * Botones "Mover a …" del menú de la tarjeta.
     *
     * Es la alternativa al arrastre: permite mover con teclado y deja claro a
     * qué fase va cada opción (el arrastre no dice el destino hasta que sueltas).
     */
    function moveMenuItems ( card ) {
        var current = card.closest( '.kb-col' ).getAttribute( 'data-stage' );
        var html = '';

        columns().forEach( function ( col ) {
            var stage = col.getAttribute( 'data-stage' );

            if ( stage === current ) {
                return;
            }

            html += '<button data-action="move" data-stage="' + stage + '">'
                + root.getAttribute( 'data-label-move-to' ) + ' “' + col.getAttribute( 'data-name' ) + '”</button>';
        } );

        return html;
    }

    function openMenu ( card ) {
        closeMenus();

        var id = card.getAttribute( 'data-id' );

        var menu = document.createElement( 'div' );
        menu.className = 'kb-menu';
        menu.innerHTML =
            '<button data-action="assign">' + root.getAttribute( 'data-label-assign-me' ) + '</button>' +
            '<button data-action="unassign">' + root.getAttribute( 'data-label-unassign' ) + '</button>' +
            '<div class="kb-sep"></div>' +
            '<button data-action="active">' + root.getAttribute( 'data-label-active' ) + '</button>' +
            '<button data-action="pending">' + root.getAttribute( 'data-label-pending' ) + '</button>' +
            '<button data-action="closed">' + root.getAttribute( 'data-label-closed' ) + '</button>' +
            '<div class="kb-sep"></div>' +
            moveMenuItems( card ) +
            '<div class="kb-sep"></div>' +
            '<button data-action="open">' + root.getAttribute( 'data-label-open' ) + '</button>';

        card.appendChild( menu );

        menu.addEventListener( 'click', function ( event ) {
            var button = event.target.closest( 'button' );

            if ( !button ) {
                return;
            }

            var action = button.getAttribute( 'data-action' );
            closeMenus();

            if ( action === 'open' ) {
                window.open( card.querySelector( '.kb-subject' ).getAttribute( 'href' ), '_blank' );
                return;
            }

            if ( action === 'move' ) {
                var target = root.querySelector( '.kb-col[data-stage="' + button.getAttribute( 'data-stage' ) + '"]' );

                if ( target ) {
                    moveCard( card, target );
                }

                return;
            }

            if ( action === 'assign' ) {
                coreAction( {
                    action: 'conversation_change_user',
                    conversation_id: id,
                    user_id: root.getAttribute( 'data-user-id' ),
                    x_embed: 1
                }, loadBoard );
                return;
            }

            if ( action === 'unassign' ) {
                coreAction( {
                    action: 'conversation_change_user',
                    conversation_id: id,
                    user_id: -1,
                    x_embed: 1
                }, loadBoard );
                return;
            }

            var statuses = { active: 1, pending: 2, closed: 3 };

            coreAction( {
                action: 'conversation_change_status',
                conversation_id: id,
                status: statuses[action],
                x_embed: 1
            }, loadBoard );
        } );
    }

    root.addEventListener( 'click', function ( event ) {
        var trigger = event.target.closest( '.kb-card-menu' );

        if ( trigger ) {
            event.preventDefault();
            event.stopPropagation();
            openMenu( trigger.closest( '.kb-card' ) );
            return;
        }

        var more = event.target.closest( '.kb-more' );

        if ( more ) {
            event.preventDefault();
            loadMore( more.closest( '.kb-col' ) );
            return;
        }

        if ( !event.target.closest( '.kb-menu' ) ) {
            closeMenus();
        }
    } );

    document.addEventListener( 'keydown', function ( event ) {
        if ( event.key === 'Escape' ) {
            closeMenus();
        }
    } );

    /* ------------------------------ filtros -------------------------------- */

    var form = document.getElementById( 'kb-filters' );

    if ( form ) {
        form.addEventListener( 'submit', function ( event ) {
            event.preventDefault();
            loadMoreReset();
        } );

        Array.prototype.forEach.call( form.querySelectorAll( '.kb-auto' ), function ( el ) {
            el.addEventListener( 'change', function () {
                loadMoreReset();
            } );
        } );

        var search = form.querySelector( '.kb-search' );

        if ( search ) {
            var searchTimer = null;

            search.addEventListener( 'keyup', function () {
                clearTimeout( searchTimer );
                searchTimer = setTimeout( loadMoreReset, 400 );
            } );
        }
    }

    var reload = document.getElementById( 'kb-reload' );

    if ( reload ) {
        reload.addEventListener( 'click', function ( event ) {
            event.preventDefault();
            loadMoreReset();
        } );
    }

    /* Al cambiar filtros se vuelve a la primera página de cada columna. */
    function loadMoreReset () {
        columns().forEach( function ( col ) {
            col.setAttribute( 'data-offset', '0' );
        } );
        loadBoard();
    }

    refreshCounts();

    /* --------------------------- refresco automático ----------------------- */

    // Desactivado por defecto (refresh_seconds = 0). Si se configura, se evita
    // refrescar mientras el usuario arrastra, tiene un menú abierto o la
    // pestaña está en segundo plano: si no, el tablero se le movería solo.
    var refreshSeconds = parseInt( root.getAttribute( 'data-refresh' ) || '0', 10 );

    if ( refreshSeconds > 0 ) {
        setInterval( function () {
            if ( document.hidden || dragCard ) {
                return;
            }

            if ( root.querySelector( '.kb-menu' ) ) {
                return;
            }

            loadBoard();
        }, refreshSeconds * 1000 );
    }
} )();
