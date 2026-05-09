<?php

namespace Pterodactyl\Http\Controllers\Admin\Nodes;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Pterodactyl\Http\Controllers\Controller;
use Pterodactyl\Http\Requests\Admin\Nodes\StartAutoConfigureRequest;
use Pterodactyl\Models\Node;
use Pterodactyl\Models\NodeAutoConfigRun;
use Pterodactyl\Services\Nodes\AutoConfigure\NodeAutoConfigureService;

class NodeAutoConfigureController extends Controller
{
    public function __construct(private NodeAutoConfigureService $service)
    {
    }

    public function index(Request $request, Node $node): View
    {
        $latest = NodeAutoConfigRun::query()
            ->where('node_id', (int) $node->id)
            ->orderByDesc('id')
            ->first();

        return view('admin.nodes.auto-configure', [
            'node' => $node,
            'latestRun' => $latest,
            'defaults' => [
                'target_port' => 22,
                'target_username' => 'root',
                'wings_port' => 8080,
                'fallback_port_range' => (string) config('pteroprotect_autoconfigure.allowed_wings_port_range', '8081-8099'),
                'host_key_policy' => (string) config('pteroprotect_autoconfigure.host_key_policy', 'strict_tofu'),
            ],
        ]);
    }

    public function start(StartAutoConfigureRequest $request, Node $node): RedirectResponse
    {
        $run = $this->service->start($node, $request->user(), $request->validated());

        return redirect()->route('admin.nodes.view.auto-configure', ['node' => $node->id])
            ->with('status', 'Auto configure started. Run #' . $run->id);
    }

    public function status(Request $request, Node $node, NodeAutoConfigRun $run): JsonResponse
    {
        abort_if((int) $run->node_id !== (int) $node->id, 404);

        return new JsonResponse([
            'id' => (int) $run->id,
            'status' => (string) $run->status,
            'last_error_code' => $run->last_error_code,
            'last_error_message' => $run->last_error_message,
            'started_at' => optional($run->started_at)->toIso8601String(),
            'finished_at' => optional($run->finished_at)->toIso8601String(),
        ]);
    }

    public function logs(Request $request, Node $node, NodeAutoConfigRun $run): JsonResponse
    {
        abort_if((int) $run->node_id !== (int) $node->id, 404);

        $after = max(0, (int) $request->query('after_id', 0));
        $logs = $run->logs()
            ->where('id', '>', $after)
            ->orderBy('id')
            ->limit(300)
            ->get(['id', 'level', 'step', 'event', 'message', 'context', 'created_at']);

        return new JsonResponse([
            'items' => $logs,
            'next_after_id' => (int) ($logs->last()->id ?? $after),
        ]);
    }

    public function cancel(Request $request, Node $node, NodeAutoConfigRun $run): JsonResponse
    {
        abort_if((int) $run->node_id !== (int) $node->id, 404);
        if (in_array($run->status, [NodeAutoConfigRun::STATUS_PENDING, NodeAutoConfigRun::STATUS_RUNNING], true)) {
            $run->status = NodeAutoConfigRun::STATUS_CANCELED;
            $run->finished_at = now();
            $run->save();
        }

        return new JsonResponse(['ok' => true, 'status' => $run->status]);
    }
}
