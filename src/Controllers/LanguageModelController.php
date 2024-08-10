<?php

namespace ClarionApp\LlmClient\Controllers;

use App\Http\Controllers\Controller;
use ClarionApp\LlmClient\Models\LanguageModel;
use Illuminate\Http\Request;
use Auth;
use Illuminate\Support\Facades\Log;
use ClarionApp\LlmClient\OpenAIModelsRequest;

class LanguageModelController extends Controller
{
    public function refresh($server_id)
    {
        $modelRequest = new OpenAIModelsRequest();
        $modelRequest->getLanguageModels($server_id);
        return response()->json(['message'=>'Refreshing models'], 200);
    }

    /**
     * Display a listing of the resource.
     */
    public function index($server_id)
    {
        $language_models = LanguageModel::where('server_id', $server_id)->orderBy('name')->get();
        return response()->json($language_models, 200);
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create()
    {
        //
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
    }

    /**
     * Display the specified resource.
     */
    public function show($id)
    {
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(LanguageModel $language_model)
    {
        //
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, LanguageModel $language_model)
    {
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(LanguageModel $language_model)
    {
    }
}
