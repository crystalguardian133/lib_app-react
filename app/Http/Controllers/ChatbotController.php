<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

class ChatbotController extends Controller
{
    public function send(Request $request)
    {
        $question = $request->input('message');

        if (!$question) {
            return response()->json(['error' => 'No message provided'], 400);
        }

        $apiKey = env('GEMINI_API_KEY');

        $response = Http::withHeaders([
            'Content-Type' => 'application/json',
            'x-goog-api-key' => $apiKey,
        ])->post('https://generativelanguage.googleapis.com/v1beta/models/gemini-2.5-flash:generateContent', [
            'contents' => [[ 'parts' => [[ 'text' => $question ]]]]
            
        ]);

        if ($response->successful()) {
            $reply = $response->json()['candidates'][0]['content']['parts'][0]['text'] ?? 'No reply';
            return response()->json(['reply' => $reply]);
        }

        return response()->json(['error' => 'Failed to connect to Gemini.'], 500);
    }
}

