<?php

namespace Pterodactyl\Http\Controllers\Admin;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Pterodactyl\Http\Controllers\Controller;
use Pterodactyl\Models\Node;

class NodeAutoDeployController extends Controller
{
    public function __invoke(Request $request, Node $node): JsonResponse
    {
        $tokenId = (string) $node->daemon_token_id;
        $token = (string) Crypt::decryptString((string) $node->daemon_token);

        return new JsonResponse([
            'token' => $tokenId . '.' . $token,
            'node' => (int) $node->id,
        ]);
    }
}
