<?php

namespace App\Http\Controllers;

use App\Models\Message;
use App\Models\UserSession;
use Illuminate\Support\Str;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Http;

class ChatbotController extends Controller
{
    public function index(Request $request)
    {
        return view('index');
    }

    public function chat(Request $request, string $session_uuid)
    {   
        $user_id = $request->session()->get('user_id');
        $session_uuid = $request->session()->get('session_uuid');

        Log::info('Session uuid from chat: ' . $session_uuid);
        
        if (!$session_uuid) {
            return redirect()->route('google.logout')->with('error', 'Session uuid is required.');
        }
        
        $user_session = UserSession::where('session_uuid', $session_uuid)->where('user_id', $user_id)->first();
        
        Log::info('Session uuid from chat: ' . $user_session->session_uuid);
        
        if (!$user_session) {
            return redirect()->route('index')->with('error', 'Invalid session from chat.');
        }

        $messages = $user_session->messages()->where('id', '>=', 2)->get();

        return view('chat', ['messages' => $messages,]);
    }

    public function new_chat(Request $request)
    {
        $user_id = $request->session()->get('user_id');

        $user_session = UserSession::create([
            'session_uuid' => Str::uuid()->toString(),
            'user_id' => $user_id,
            'last_activity' => time()
        ]);

        if (!$user_session) {
            return redirect()->route('index')->with('error', 'Invalid session from new_chat.');
        }

        $request->session()->put('session_uuid', $user_session->session_uuid);

        return redirect()->route('chat', ['session_uuid' => $user_session->session_uuid]);
    }

    public function store(Request $request)
    {
        $message = $request->input('message', '');

        $access_token = $request->session()->get('access_token');
        $session_uuid = $request->session()->get('session_uuid');

        try {
            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $access_token,
                'Content-Type' => 'application/json'
            ])->post('http://localhost:8001/chat/' . $session_uuid, [
                'message' => $message
            ]);
        } catch (\Exception $e) {
            return response()->json(['error' => 'Bad request'], 400);
        }

        if ($response->status() == 401) {
            return redirect()->route('google.logout')->with('error', 'Session expired, please log in again.');
        }

        $user_session = UserSession::where('session_uuid', $session_uuid)->first();

        Message::create([
            'user_session_id' => $user_session->id,
            'message_history' => [
                ['role' => 'user', 'content' => $message],
                ['role' => 'assistant', 'content' => $response->json('generation')]
            ]
        ]);

        return response()->json([
            'generation' => $response->json('generation'),
            'mood' => $response->json('mood')
        ]);
    }
}
