<?php
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use ClarionApp\LlmClient\Controllers\ConversationController;

Route::group(['middleware'=>'api', 'prefix'=>'api' ], function () {
    Route::resource('conversation', ConversationController::class);
});
