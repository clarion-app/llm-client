<?php

namespace ClarionApp\LlmClient;

use Illuminate\Http\Client\Response;
use ClarionApp\HttpQueue\HandleHttpResponse;
use ClarionApp\LlmClient\Models\LanguageModel;
use ClarionApp\LlmClient\Models\Message;
use Illuminate\Support\Facades\Log;

class HandleOpenAIModelsResponse extends HandleHttpResponse
{
    public function handle(Response $response, $server_id, $seconds)
    {
        switch($response->status())
        {
            case 200:
                Log::info(print_r($response->object(), 1));
                $models = $response->object()->data;

                // Add new models
                foreach($models as $model)
                {
                    if(LanguageModel::where("name", $model->id)->where("server_id", $server_id)->first()) continue;
                    LanguageModel::create([
                      "name"=>$model->id,
                      "server_id"=>$server_id
                    ]);
                }

                // Delete removed models
                $langModels = LanguageModel::where("server_id", $server_id)->get();
                foreach($langModels as $langModel)
                {
                    $keep = false;
                    foreach($models as $model)
                    {
                        if($model->id == $langModel->name)
                        {
                            $keep = true;
                            break;
                        }
                    }

                    if($keep) continue;

                    Log::info("Deleting model ".$langModel->name." from server $server_id");
                    $langModel->delete();
                }
                break;
            case 404:
                if(LanguageModel::where("name", "Default")->where("server_id", $server_id)->first()) break;
                LanguageModel::create([
                    "name"=>"Default",
                    "server_id"=>$server_id
                ]);
                break;
            default:
                Log::error("ClarionApp\LlmClient\HandleOpenAIModelsResponse: Unknown response status: ".$response->status());
                Log::error("    Body:".print_r($response->object(), 1));
                break;
        }
    }
}
