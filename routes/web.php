<?php

/** @var \Laravel\Lumen\Routing\Router $router */

/*
|--------------------------------------------------------------------------
| Application Routes
|--------------------------------------------------------------------------
|
| Here is where you can register all of the routes for an application.
| It is a breeze. Simply tell Lumen the URIs it should respond to
| and give it the Closure to call when that URI is requested.
|
*/

$router->get('docs', function () use ($router) {
    return view('docs');
});

$router->group(['prefix' => 'api'], function () use ($router) {
    // Authentication Routes
    $router->post('login', 'AuthController@login');

    // Protected Routes
    $router->group(['middleware' => 'auth'], function () use ($router) {
        // Entries Routes
        $router->get('entries', 'EntriesController@index');
//        $router->post('entries', 'EntriesController@store');
//        $router->get('entries/{id}', 'EntriesController@show');
        $router->put('entries/{id}', 'EntriesController@update');
//        $router->delete('entries/{id}', 'EntriesController@delete');

        // Categories Routes
        $router->get('categories', 'CategoriesController@index');
        $router->post('categories', 'CategoriesController@store');
//        $router->put('categories/{id}', 'CategoriesController@update');
//        $router->delete('categories/{id}', 'CategoriesController@delete');

        // Users Routes
        $router->get('users', 'UsersController@index');

        // Documents Routes
        $router->post('entries/{entryId}/documents', 'DocumentController@store');
        $router->get('entries/{entryId}/documents', 'DocumentController@index');

        $router->get('documents/{documentId}', 'DocumentController@show');
        $router->get('documents/{documentId}/thumbnail', 'DocumentController@thumbnail');
//        $router->delete('documents/{documentId}', 'DocumentController@destroy');

        // CSV Export/Import Routes
//        $router->get('export', 'ExportController@exportCSV');
//        $router->post('import', 'ImportController@importCSV');

        // Logout Route
//        $router->post('logout', 'AuthController@logout');  // not possible with api token authentication
    });
});
