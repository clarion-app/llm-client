<?php
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Broadcast;
use ClarionApp\LlmClient\Controllers\ConversationController;
use ClarionApp\LlmClient\Controllers\ServerController;
use ClarionApp\LlmClient\Controllers\MessageController;
use ClarionApp\LlmClient\Controllers\LanguageModelController;
use ClarionApp\LlmClient\Controllers\AgentController;
use ClarionApp\LlmClient\Models\Conversation;
use ClarionApp\LlmClient\Controllers\DeclarativeMemoryController;
use ClarionApp\LlmClient\Controllers\FetchPageController;
use ClarionApp\LlmClient\Controllers\UserSettingController;
use ClarionApp\LlmClient\Controllers\McpServerController;
use ClarionApp\LlmClient\Controllers\EpisodicMemoryController;
use ClarionApp\LlmClient\Controllers\FeedbackController;

Route::group(['middleware'=>'auth:api', 'prefix'=>$this->routePrefix ], function () {
    Route::resource('conversation', ConversationController::class);
    Route::post('conversation/{id}/generate-title', [ConversationController::class, "generateTitle"]);
    Route::post('conversation/{id}/end', [ConversationController::class, "end"]);
    Route::post('conversation/{id}/confirm-api-call', [ConversationController::class, "confirmApiCall"]);
    Route::get('user/{id}/conversation', [ConversationController::class, "userConversations"]);
    Route::post('agent', AgentController::class);
    Route::resource('server', ServerController::class);
    Route::resource('message', MessageController::class);
    Route::get('conversation/{conversation_id}/message', [MessageController::class, "index"]);
    Route::get('server/{server_id}/model', [LanguageModelController::class, "index"]);
    Route::post('models/{server_id}/refresh', [LanguageModelController::class, "refresh"]);
    Route::get('model', [LanguageModelController::class, "index"]);

    Route::post('page/text', [FetchPageController::class, "getTextFromUrl"]);

    Route::get('user-setting', [UserSettingController::class, "show"]);
    Route::put('user-setting', [UserSettingController::class, "update"]);

    Route::post('mcp', [McpServerController::class, "handle"]);

    // Episodic Memory endpoints (US3 - Search Past Conversation Events)
    Route::get('episodic-memories', [EpisodicMemoryController::class, "index"]);
    Route::post('episodic-memories/search', [EpisodicMemoryController::class, "search"]);
    Route::patch('episodic-memories/{id}/protect', [EpisodicMemoryController::class, "protect"]);
    Route::delete('episodic-memories/{id}', [EpisodicMemoryController::class, "destroy"]);

    // Declarative Memory endpoints (user-driven CRUD, behind auth:api)
    Route::get('declarative-memories', [DeclarativeMemoryController::class, "index"]);
    Route::post('declarative-memories', [DeclarativeMemoryController::class, "store"]);
    Route::put('declarative-memories/{id}', [DeclarativeMemoryController::class, "update"]);
    Route::delete('declarative-memories/{id}', [DeclarativeMemoryController::class, "destroy"]);

    // Feedback endpoints (learned preferences from user feedback)
    Route::post('feedback', [FeedbackController::class, "store"]);
    Route::get('feedback/preferences/proposed', [FeedbackController::class, "proposed"]);
    Route::post('feedback/preferences/{pattern_key}/confirm', [FeedbackController::class, "confirm"]);
    Route::post('feedback/preferences/{pattern_key}/decline', [FeedbackController::class, "decline"]);
    Route::get('feedback/preferences/learned', [FeedbackController::class, "learned"]);
    Route::patch('feedback/preferences/{id}', [FeedbackController::class, "update"]);
    Route::delete('feedback/preferences/{id}', [FeedbackController::class, "destroy"]);
    Route::get('feedback/audit/{preference_id}', [FeedbackController::class, "audit"]);
});

Broadcast::channel('Conversation.{id}', function ($user, $id) {
    $conversation = Conversation::find($id);
    if(!$conversation) return false;

    if($conversation->user_id === $user->id) return true;

    return false;
});

