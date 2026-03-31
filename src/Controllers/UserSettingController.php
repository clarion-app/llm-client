<?php

namespace ClarionApp\LlmClient\Controllers;

use App\Http\Controllers\Controller;
use ClarionApp\LlmClient\Models\UserSetting;
use Illuminate\Http\Request;
use Auth;

class UserSettingController extends Controller
{
    public function show()
    {
        $setting = UserSetting::where('user_id', Auth::id())->first();

        if (!$setting) {
            return response()->json([
                'server_id' => null,
                'model' => null,
            ], 200);
        }

        return response()->json([
            'server_id' => $setting->server_id,
            'model' => $setting->model,
        ], 200);
    }

    public function update(Request $request)
    {
        $validatedData = $request->validate([
            'server_id' => 'nullable|uuid|exists:llm_servers,id',
            'model' => 'nullable|string|max:255',
        ]);

        $setting = UserSetting::updateOrCreate(
            ['user_id' => Auth::id()],
            $validatedData
        );

        return response()->json([
            'server_id' => $setting->server_id,
            'model' => $setting->model,
        ], 200);
    }
}
