<?php
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use ClarionApp\LlmClient\Controllers\ConversationController;
use ClarionApp\LlmClient\Controllers\ServerGroupController;

Route::group(['middleware'=>'api', 'prefix'=>'api' ], function () {
    Route::resource('conversation', ConversationController::class);
    Route::resource('server-groups', ServerGroupController::class);
});
