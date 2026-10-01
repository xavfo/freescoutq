<?php

namespace Modules\RestApi\Http\Controllers;

use Illuminate\Routing\Controller;
use Illuminate\Http\Request;
use Modules\RestApi\Entities\ApiKey;
use App\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class ApiTokenController extends Controller
{
    /**
     * Show the API token management interface
     */
    public function index()
    {
        $tokens = ApiKey::with('user')->orderBy('created_at', 'desc')->get();
        $users = User::orderBy('first_name')->get();
        
        return view('restapi::apitokens.index', compact('tokens', 'users'));
    }

    /**
     * Store a newly created API token
     */
    public function store(Request $request)
    {
        $request->validate([
            'user_id' => 'required|exists:users,id',
            'name' => 'required|string|max:255',
            'mailbox_ids' => 'nullable|string',
            'rate_limit' => 'required|integer|min:1',
            'expires_at' => 'nullable|date',
        ]);

        // Generate token
        $token = ApiKey::generateToken();
        $tokenHash = hash('sha256', $token);

        // Parse mailbox IDs
        $mailboxIdsList = null;
        if ($request->mailbox_ids) {
            $mailboxIdsList = array_map('trim', explode(',', $request->mailbox_ids));
        }

        // Create API key
        $apiKey = ApiKey::create([
            'user_id' => $request->user_id,
            'name' => $request->name,
            'token' => $token,
            'token_hash' => $tokenHash,
            'mailbox_ids' => $mailboxIdsList ? json_encode($mailboxIdsList) : null,
            'rate_limit' => $request->rate_limit,
            'active' => true,
            'expires_at' => $request->expires_at ? \Carbon\Carbon::parse($request->expires_at) : null,
        ]);

        // Redirect back with success message and token (only shown once)
        return redirect()->route('restapi.api-tokens.index')
            ->with('success', 'Token creado exitosamente')
            ->with('token', $token)
            ->with('tokenId', $apiKey->id);
    }

    /**
     * Revoke (delete) an API token
     */
    public function destroy($id)
    {
        $apiKey = ApiKey::findOrFail($id);
        $apiKey->delete();

        return redirect()->route('restapi.api-tokens.index')
            ->with('success', 'Token revocado exitosamente');
    }
}