<?php

namespace Pterodactyl\Http\Controllers\Admin;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Pterodactyl\Http\Controllers\Controller;
use Pterodactyl\Models\Node;

class NodeAutoDeployController extends Controller
{
    public function __invoke(Request $request, Node $node): JsonResponse
    {
        // Keep this endpoint explicit and harmless until a full token-rotation flow is required.
        return new JsonResponse([
            'ok' => true,
            'node_id' => (int) $node->id,
            'message' => 'Node auto-deploy token endpoint is available.',
        ]);
    }
}
