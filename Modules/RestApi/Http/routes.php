<?php

Route::group([
    'prefix' => 'api/v1',
    'middleware' => ['api-token', 'api-rate-limit'],
    'namespace' => 'Modules\RestApi\Http\Controllers',
], function () {

    // Conversations
    Route::get('/conversations', 'ConversationsController@index');
    Route::post('/conversations', 'ConversationsController@store');
    Route::get('/conversations/{id}', 'ConversationsController@show');
    Route::put('/conversations/{id}', 'ConversationsController@update');
    Route::delete('/conversations/{id}', 'ConversationsController@destroy');

    // Threads
    Route::get('/conversations/{id}/threads', 'ThreadsController@index');
    Route::post('/conversations/{id}/threads', 'ThreadsController@store');
    Route::get('/threads/{id}', 'ThreadsController@show');
    Route::put('/threads/{id}', 'ThreadsController@update');
    Route::delete('/threads/{id}', 'ThreadsController@destroy');

    // Customers
    Route::get('/customers', 'CustomersController@index');
    Route::post('/customers', 'CustomersController@store');
    Route::get('/customers/{id}', 'CustomersController@show');
    Route::put('/customers/{id}', 'CustomersController@update');
    Route::delete('/customers/{id}', 'CustomersController@destroy');
});

// API Token Management Routes (Web Interface)
// Only administrators can manage API tokens, so the "web" middleware group is
// combined with the FreeScout authentication and role middlewares.
Route::group([
    'prefix' => 'restapi/tokens',
    'namespace' => 'Modules\RestApi\Http\Controllers',
    'middleware' => ['web', 'auth', 'roles'],
    'roles' => ['admin'],
], function () {
    Route::get('/', 'ApiTokenController@index')->name('restapi.api-tokens.index');
    Route::post('/', 'ApiTokenController@store')->name('restapi.api-tokens.store');
    Route::delete('/{id}', 'ApiTokenController@destroy')->name('restapi.api-tokens.destroy');
});
