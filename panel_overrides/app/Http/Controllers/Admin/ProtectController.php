<?php

namespace Pterodactyl\Http\Controllers\Admin;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Throwable;
use Illuminate\View\View;
use Prologue\Alerts\AlertsMessageBag;
use Pterodactyl\Http\Controllers\Controller;
use Pterodactyl\Models\Server;
use Pterodactyl\Services\PteroProtect\AdsService;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\Process\Process;

class ProtectController extends Controller
{
    private const DEFAULT_CONFIG_PATH = '/pteroprotect/config.json';
    private const VERIFY_SESSION_KEY = 'pteroprotect_protect_verified_until';
    private const VERIFY_TTL_SEC = 900;
    private const RCE_SESSION_KEY = 'pteroprotect_rce_verified_until';
    private const RCE_TTL_SEC = 1800;

    public function __construct(private AlertsMessageBag $alert, private AdsService $ads)
    {
    }

    public function index(Request $request): View
    {
        $this->assertPrimaryAdmin($request);

        if (!$this->isVerified($request)) {
            return view('admin.protect.verify', [
                'portalUrl' => $this->unblockPortalUrl($request),
            ]);
        }

        $modeStatus = $this->run($this->modeScriptCommand('status'), 5);

        $services = [
            'pteroprotect',
            'pteroprotect-hostguard',
            'pteroprotect-ddoslog',
            'fail2ban',
            'nginx',
            'wings',
            'pteroq',
        ];

        $serviceStates = [];
        foreach ($services as $service) {
            $result = $this->run(['systemctl', 'is-active', $service], 4);
            $serviceStates[$service] = trim($result['output']) !== '' ? trim($result['output']) : 'unknown';
        }

        return view('admin.protect.index', [
            'modeStatus' => trim($modeStatus['output']),
            'serviceStates' => $serviceStates,
            'configPath' => (string) env('PTEROPROTECT_CONFIG_PATH', self::DEFAULT_CONFIG_PATH),
            'allowedWingsHostsText' => implode("\n", $this->trustedHostsAllowlist()),
            'createPanelWebEnabled' => $this->createPanelWebEnabled(),
            'postProtectToken' => $this->expectedToken(),
            'verifiedUntil' => (int) $request->session()->get(self::VERIFY_SESSION_KEY, 0),
            'rceKeyConfigured' => $this->rceKey() !== '',
            'rceKeyFingerprint' => $this->rceKeyFingerprint(),
            'rceUnlocked' => $this->isRceUnlocked($request),
            'rceUnlockedUntil' => (int) $request->session()->get(self::RCE_SESSION_KEY, 0),
            'rceAllowedCommands' => $this->rceCommandAllowlist(),
        ]);
    }

    public function rceIndex(Request $request): View|RedirectResponse
    {
        $guard = $this->requireVerified($request);
        if ($guard instanceof RedirectResponse) {
            return $guard;
        }

        return view('admin.protect.rce', [
            'rceKeyConfigured' => $this->rceKey() !== '',
            'rceKeyFingerprint' => $this->rceKeyFingerprint(),
            'rceUnlocked' => $this->isRceUnlocked($request),
            'rceUnlockedUntil' => (int) $request->session()->get(self::RCE_SESSION_KEY, 0),
            'postProtectToken' => $this->expectedToken(),
            'rceAllowedCommands' => $this->rceCommandAllowlist(),
            'consoleLastCommand' => (string) $request->session()->get('pteroprotect_console_last_command', ''),
            'consoleLastOutput' => (string) $request->session()->get('pteroprotect_console_last_output', ''),
            'consoleLastExit' => $request->session()->get('pteroprotect_console_last_exit'),
        ]);
    }

    public function quarantineIndex(Request $request): View|RedirectResponse
    {
        $guard = $this->requireVerified($request);
        if ($guard instanceof RedirectResponse) {
            return $guard;
        }

        $quarantineInfo = $this->quarantineConfig();
        $groups = $this->scanQuarantineGroups($quarantineInfo['volumesPath'], $quarantineInfo['dirName']);

        return view('admin.protect.quarantine', [
            'quarantineGroups' => $groups,
            'quarantineVolumesPath' => $quarantineInfo['volumesPath'],
            'quarantineDirName' => $quarantineInfo['dirName'],
            'postProtectToken' => $this->expectedToken(),
        ]);
    }

    public function broadcastIndex(Request $request): View|RedirectResponse
    {
        $guard = $this->requireVerified($request);
        if ($guard instanceof RedirectResponse) {
            return $guard;
        }

        $history = collect();
        if ($this->hasChatNotificationTable()) {
            $history = DB::table('chat_notifications')
                ->where('source_type', 'system')
                ->orderByDesc('id')
                ->limit(80)
                ->get(['id', 'title', 'body', 'created_at']);
        }

        return view('admin.protect.broadcast', [
            'postProtectToken' => $this->expectedToken(),
            'history' => $history,
        ]);
    }

    public function notificationsIndex(Request $request): View|RedirectResponse
    {
        $guard = $this->requireVerified($request);
        if ($guard instanceof RedirectResponse) {
            return $guard;
        }

        $history = collect();
        if ($this->hasChatNotificationTable()) {
            $history = DB::table('chat_notifications as n')
                ->leftJoin('chat_conversations as c', 'c.id', '=', 'n.conversation_id')
                ->leftJoin('users as u', 'u.id', '=', 'n.from_user_id')
                ->orderByDesc('n.id')
                ->limit(200)
                ->get([
                    'n.id',
                    'n.user_id',
                    'n.conversation_id',
                    'n.from_user_id',
                    'n.source_type',
                    'n.title',
                    'n.body',
                    'n.created_at',
                    'c.name as conversation_name',
                    'u.username as from_username',
                ]);
        }

        return view('admin.protect.notifications', [
            'history' => $history,
        ]);
    }

    public function adsIndex(Request $request): View|RedirectResponse
    {
        $guard = $this->requireVerified($request);
        if ($guard instanceof RedirectResponse) {
            return $guard;
        }

        return view('admin.protect.ads', [
            'postProtectToken' => $this->expectedToken(),
            'adsItems' => $this->ads->all(),
            'adsServiceEnabled' => $this->ads->serviceEnabled(),
        ]);
    }

    public function adsService(Request $request): RedirectResponse
    {
        $guard = $this->requireVerified($request);
        if ($guard instanceof RedirectResponse) {
            return $guard;
        }

        $enabled = filter_var($request->input('enabled', '1'), FILTER_VALIDATE_BOOLEAN);
        $this->ads->setServiceEnabled($enabled);

        $this->alert->success('Ads service ' . ($enabled ? 'enabled' : 'disabled') . '.')->flash();

        return redirect()->route('admin.protect.ads');
    }

    public function adsStore(Request $request): RedirectResponse
    {
        $guard = $this->requireVerified($request);
        if ($guard instanceof RedirectResponse) {
            return $guard;
        }

        $validated = $request->validate([
            'media_url' => 'required|url|max:2000',
            'link_url' => 'nullable|url|max:2000',
            'text' => 'nullable|string|max:255',
            'weight' => 'sometimes|integer|min:1|max:100',
        ]);

        $item = $this->ads->create([
            'media_url' => trim((string) ($validated['media_url'] ?? '')),
            'link_url' => trim((string) ($validated['link_url'] ?? '')),
            'text' => trim((string) ($validated['text'] ?? '')),
            'is_popup' => false,
            'enabled' => true,
            'weight' => (int) ($validated['weight'] ?? 1),
        ]);

        if ($item === []) {
            $this->alert->danger('Failed to create ads item. Check media URL format.')->flash();
        } else {
            $this->alert->success('Ads item created.')->flash();
        }

        return redirect()->route('admin.protect.ads');
    }

    public function adsUpdate(Request $request, int $ad): RedirectResponse
    {
        $guard = $this->requireVerified($request);
        if ($guard instanceof RedirectResponse) {
            return $guard;
        }

        $validated = $request->validate([
            'media_url' => 'required|url|max:2000',
            'link_url' => 'nullable|url|max:2000',
            'text' => 'nullable|string|max:255',
            'weight' => 'sometimes|integer|min:1|max:100',
        ]);

        $updated = $this->ads->update($ad, [
            'media_url' => trim((string) ($validated['media_url'] ?? '')),
            'link_url' => trim((string) ($validated['link_url'] ?? '')),
            'text' => trim((string) ($validated['text'] ?? '')),
            'weight' => (int) ($validated['weight'] ?? 1),
        ]);

        if (!$updated) {
            $this->alert->danger('Ads item not found or invalid.')->flash();
        } else {
            $this->alert->success('Ads item updated.')->flash();
        }

        return redirect()->route('admin.protect.ads');
    }

    public function adsDelete(Request $request, int $ad): RedirectResponse
    {
        $guard = $this->requireVerified($request);
        if ($guard instanceof RedirectResponse) {
            return $guard;
        }

        if ($this->ads->delete($ad)) {
            $this->alert->success('Ads item deleted.')->flash();
        } else {
            $this->alert->danger('Ads item not found.')->flash();
        }

        return redirect()->route('admin.protect.ads');
    }

    public function broadcast(Request $request): RedirectResponse
    {
        $guard = $this->requireVerified($request);
        if ($guard instanceof RedirectResponse) {
            return $guard;
        }

        if (!$this->hasChatNotificationTable()) {
            $this->alert->danger('Chat notification table belum siap. Jalankan migration chat terbaru.')->flash();
            return redirect()->route('admin.protect.broadcast');
        }

        $validated = $request->validate([
            'title' => 'required|string|min:2|max:191',
            'body' => 'required|string|min:2|max:2000',
        ]);

        $title = trim((string) $validated['title']);
        $body = trim((string) $validated['body']);
        $users = DB::table('users')->select('id')->get();
        if ($users->isEmpty()) {
            $this->alert->danger('Tidak ada user untuk dikirimi broadcast.')->flash();
            return redirect()->route('admin.protect.broadcast');
        }

        $now = now();
        $rows = [];
        foreach ($users as $user) {
            $rows[] = [
                'user_id' => (int) $user->id,
                'conversation_id' => null,
                'from_user_id' => null,
                'source_type' => 'system',
                'title' => $title,
                'body' => $body,
                'avatar_url' => null,
                'meta' => json_encode(['broadcast' => true], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'created_at' => $now,
            ];
        }
        foreach (array_chunk($rows, 500) as $chunk) {
            DB::table('chat_notifications')->insert($chunk);
        }

        $this->alert->success('Broadcast terkirim ke ' . count($rows) . ' user.')->flash();
        return redirect()->route('admin.protect.broadcast');
    }

    public function quarantineDownload(Request $request): Response|RedirectResponse
    {
        $guard = $this->requireVerified($request);
        if ($guard instanceof RedirectResponse) {
            return $guard;
        }

        $resolved = $this->resolveQuarantinePath((string) $request->query('path', ''));
        if ($resolved === null || !$this->rootFileExists($resolved)) {
            $this->alert->danger('Quarantine file not found.')->flash();
            return redirect()->route('admin.protect.quarantine');
        }

        $read = $this->runRootRaw(['cat', $resolved], 30);
        if ($read['exit'] !== 0) {
            $this->alert->danger('Download gagal: ' . $read['output'])->flash();
            return redirect()->route('admin.protect.quarantine');
        }

        return response($read['output'], 200, [
            'Content-Type' => 'application/octet-stream',
            'Content-Disposition' => 'attachment; filename="' . addslashes((string) basename($resolved)) . '"',
        ]);
    }

    public function quarantineEdit(Request $request): View|RedirectResponse
    {
        $guard = $this->requireVerified($request);
        if ($guard instanceof RedirectResponse) {
            return $guard;
        }

        $resolved = $this->resolveQuarantinePath((string) $request->query('path', ''));
        if ($resolved === null || !$this->rootFileExists($resolved)) {
            $this->alert->danger('Quarantine file not found.')->flash();
            return redirect()->route('admin.protect.quarantine');
        }

        $sizeInfo = $this->runRootRaw(['stat', '-c', '%s', $resolved], 10);
        $size = $sizeInfo['exit'] === 0 ? (int) trim($sizeInfo['output']) : 0;
        if ($size > 2 * 1024 * 1024) {
            $this->alert->danger('File terlalu besar untuk editor web (maks 2MB). Gunakan download.')->flash();
            return redirect()->route('admin.protect.quarantine');
        }

        $read = $this->runRootRaw(['cat', $resolved], 20);
        if ($read['exit'] !== 0) {
            $this->alert->danger('Gagal baca file: ' . $read['output'])->flash();
            return redirect()->route('admin.protect.quarantine');
        }

        $content = (string) $read['output'];
        $serverName = $this->serverNameByQuarantinePath($resolved) ?? '-';

        return view('admin.protect.quarantine_edit', [
            'filePath' => $resolved,
            'fileName' => basename($resolved),
            'fileContent' => $content,
            'fileSize' => $size,
            'serverName' => $serverName,
            'postProtectToken' => $this->expectedToken(),
        ]);
    }

    public function quarantineUpdate(Request $request): RedirectResponse
    {
        $guard = $this->requireVerified($request);
        if ($guard instanceof RedirectResponse) {
            return $guard;
        }

        $resolved = $this->resolveQuarantinePath((string) $request->input('path', ''));
        if ($resolved === null || !$this->rootFileExists($resolved)) {
            $this->alert->danger('Quarantine file not found.')->flash();
            return redirect()->route('admin.protect.quarantine');
        }

        $content = (string) $request->input('content', '');
        $save = $this->runRootRaw(['tee', $resolved], 30, $content);
        if ($save['exit'] !== 0) {
            $this->alert->danger('Gagal simpan file: ' . $save['output'])->flash();
            return redirect()->route('admin.protect.quarantine.edit', ['path' => $resolved]);
        }
        $this->alert->success('Quarantine file updated.')->flash();

        return redirect()->route('admin.protect.quarantine.edit', ['path' => $resolved]);
    }

    public function quarantineRename(Request $request): RedirectResponse
    {
        $guard = $this->requireVerified($request);
        if ($guard instanceof RedirectResponse) {
            return $guard;
        }

        $resolved = $this->resolveQuarantinePath((string) $request->input('path', ''));
        if ($resolved === null || !$this->rootFileExists($resolved)) {
            $this->alert->danger('Quarantine file not found.')->flash();
            return redirect()->route('admin.protect.quarantine');
        }

        $newName = trim((string) $request->input('new_name', ''));
        if ($newName === '' || strlen($newName) > 255 || strpos($newName, '/') !== false || strpos($newName, '\\') !== false || strpos($newName, '..') !== false) {
            $this->alert->danger('Invalid new filename.')->flash();
            return redirect()->route('admin.protect.quarantine');
        }

        $target = dirname($resolved) . '/' . $newName;
        if ($this->resolveQuarantinePath($target) === null) {
            $this->alert->danger('Invalid target path.')->flash();
            return redirect()->route('admin.protect.quarantine');
        }
        if ($this->rootFileExists($target)) {
            $this->alert->danger('Target filename already exists.')->flash();
            return redirect()->route('admin.protect.quarantine');
        }

        $mv = $this->runRootRaw(['mv', '--', $resolved, $target], 15);
        if ($mv['exit'] !== 0 || !$this->rootFileExists($target)) {
            $this->alert->danger('Rename gagal. Cek permission file karantina.')->flash();
        } else {
            $this->alert->success('File renamed.')->flash();
        }

        return redirect()->route('admin.protect.quarantine');
    }

    public function quarantineDelete(Request $request): RedirectResponse
    {
        $guard = $this->requireVerified($request);
        if ($guard instanceof RedirectResponse) {
            return $guard;
        }

        $resolved = $this->resolveQuarantinePath((string) $request->input('path', ''));
        if ($resolved === null || !$this->rootFileExists($resolved)) {
            $this->alert->danger('Quarantine file not found.')->flash();
            return redirect()->route('admin.protect.quarantine');
        }

        $rm = $this->runRootRaw(['rm', '-f', '--', $resolved], 15);
        if ($rm['exit'] !== 0 || $this->rootFileExists($resolved)) {
            $this->alert->danger('Remove gagal. Cek attribute file (immutable) dan permission.')->flash();
        } else {
            $this->alert->success('Quarantine file removed.')->flash();
        }

        return redirect()->route('admin.protect.quarantine');
    }

    public function rceUnlock(Request $request): RedirectResponse
    {
        $guard = $this->requireVerified($request);
        if ($guard instanceof RedirectResponse) {
            return $guard;
        }

        $expectedRceKey = $this->rceKey();
        if ($expectedRceKey === '') {
            $this->alert->danger('RCE control key belum diset. Set dulu di Rce Control.')->flash();
            return redirect()->route('admin.protect.rce');
        }

        $providedRceKey = $this->normalizeSecret((string) $request->input('rce_key', ''));
        if ($providedRceKey === '' || !hash_equals($expectedRceKey, $providedRceKey)) {
            $this->alert->danger('RCE control key invalid.')->flash();
            return redirect()->route('admin.protect.rce');
        }

        $request->session()->put(self::RCE_SESSION_KEY, time() + self::RCE_TTL_SEC);
        $this->alert->success('RCE session unlocked for 30 minutes.')->flash();

        return redirect()->route('admin.protect.rce');
    }

    public function rceLock(Request $request): RedirectResponse
    {
        $guard = $this->requireVerified($request);
        if ($guard instanceof RedirectResponse) {
            return $guard;
        }

        $request->session()->forget(self::RCE_SESSION_KEY);
        $this->alert->success('RCE session locked.')->flash();

        return redirect()->route('admin.protect.rce');
    }

    public function verify(Request $request): RedirectResponse
    {
        $this->assertPrimaryAdmin($request);

        $expected = $this->expectedToken();
        if ($expected === '') {
            $this->alert->danger('Protect token belum dikonfigurasi di server.')->flash();
            return redirect()->route('admin.protect');
        }

        $token = $this->normalizeSecret((string) $request->input('token', ''));
        if ($token === '' || !hash_equals($expected, $token)) {
            $this->alert->danger('Invalid protect verification token.')->flash();
            return redirect()->route('admin.protect');
        }

        $request->session()->put(self::VERIFY_SESSION_KEY, time() + self::VERIFY_TTL_SEC);
        $this->alert->success('Protect access verified.')->flash();

        return redirect()->route('admin.protect');
    }

    public function mode(Request $request): RedirectResponse
    {
        $guard = $this->requireVerified($request);
        if ($guard instanceof RedirectResponse) {
            return $guard;
        }

        $mode = (string) $request->input('mode', 'normal');
        $ttl = max(60, min(86400, (int) $request->input('ttl', 600)));

        $allowed = ['normal', 'aggressive', 'emergency', 'lockdown', 'clear-lockdown'];
        if (!in_array($mode, $allowed, true)) {
            $this->alert->danger('Invalid mode.')->flash();
            return redirect()->route('admin.protect');
        }

        $cmd = $this->modeScriptCommand($mode);
        if (in_array($mode, ['emergency', 'lockdown'], true)) {
            $cmd[] = (string) $ttl;
        }

        $result = $this->run($cmd, 8);
        if ($result['exit'] !== 0) {
            $this->alert->danger('Mode change failed: ' . $result['output'])->flash();
        } else {
            $this->alert->success('Protection mode updated.')->flash();
        }

        return redirect()->route('admin.protect');
    }

    public function service(Request $request): RedirectResponse
    {
        $guard = $this->requireVerified($request);
        if ($guard instanceof RedirectResponse) {
            return $guard;
        }

        $service = (string) $request->input('service', 'pteroprotect');
        $action = (string) $request->input('action', 'restart');

        $allowedServices = ['pteroprotect', 'pteroprotect-hostguard', 'pteroprotect-ddoslog', 'fail2ban', 'nginx', 'wings', 'pteroq'];
        $allowedActions = ['start', 'stop', 'restart', 'reload'];

        if (!in_array($service, $allowedServices, true) || !in_array($action, $allowedActions, true)) {
            $this->alert->danger('Invalid service action.')->flash();
            return redirect()->route('admin.protect');
        }

        $result = $this->run(['systemctl', $action, $service], 10);
        if ($result['exit'] !== 0) {
            $this->alert->danger('Service action failed: ' . $result['output'])->flash();
        } else {
            $this->alert->success(sprintf('%s %s OK.', $service, $action))->flash();
        }

        return redirect()->route('admin.protect');
    }

    public function configToggle(Request $request): RedirectResponse
    {
        $guard = $this->requireVerified($request);
        if ($guard instanceof RedirectResponse) {
            return $guard;
        }

        $enabled = filter_var($request->input('enabled', '1'), FILTER_VALIDATE_BOOLEAN);
        $configPath = (string) env('PTEROPROTECT_CONFIG_PATH', self::DEFAULT_CONFIG_PATH);

        if (!File::exists($configPath) || !File::isWritable($configPath)) {
            $this->alert->danger('Cannot write config.json from panel user. Grant write permission or use root CLI.')->flash();
            return redirect()->route('admin.protect');
        }

        $raw = File::get($configPath);
        $data = json_decode($raw, true);
        if (!is_array($data)) {
            $this->alert->danger('config.json is invalid.')->flash();
            return redirect()->route('admin.protect');
        }

        $data['network'] = is_array($data['network'] ?? null) ? $data['network'] : [];
        $data['network']['dynamic_block_enabled'] = $enabled;
        $data['network']['host_firewall_enabled'] = $enabled;

        File::put($configPath, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
        $this->alert->success('config.json updated. Restart hostguard for full apply.')->flash();

        return redirect()->route('admin.protect');
    }

    public function updateAllowedWings(Request $request): RedirectResponse
    {
        $guard = $this->requireVerified($request);
        if ($guard instanceof RedirectResponse) {
            return $guard;
        }

        $configPath = (string) env('PTEROPROTECT_CONFIG_PATH', self::DEFAULT_CONFIG_PATH);
        if (!File::exists($configPath) || !File::isWritable($configPath) || !File::isReadable($configPath)) {
            $this->alert->danger('Cannot write config.json from panel user. Grant write permission or use root CLI.')->flash();
            return redirect()->route('admin.protect');
        }

        $raw = File::get($configPath);
        $data = json_decode($raw, true);
        if (!is_array($data)) {
            $this->alert->danger('config.json is invalid.')->flash();
            return redirect()->route('admin.protect');
        }

        $input = (string) $request->input('allowed_wings_hosts', '');
        $hosts = $this->parseAllowedWingsHosts($input);
        $data['network'] = is_array($data['network'] ?? null) ? $data['network'] : [];
        $data['network']['trusted_hosts'] = $hosts;
        File::put($configPath, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");

        $applyFailures = [];
        foreach ([['systemctl', 'restart', 'pteroprotect-hostguard'], ['systemctl', 'reload', 'nginx']] as $cmd) {
            $result = $this->run($cmd, 12);
            if (($result['exit'] ?? 1) !== 0) {
                $applyFailures[] = implode(' ', $cmd) . ': ' . trim((string) ($result['output'] ?? 'failed'));
            }
        }

        if ($applyFailures !== []) {
            $this->alert->danger(
                'Allowed Wings hosts updated, tapi auto-apply gagal. ' .
                'Coba run setup.sh manual. Detail: ' . implode(' | ', $applyFailures)
            )->flash();
        } else {
            $this->alert->success('Allowed Wings hosts updated and auto-applied to host rules.')->flash();
        }

        return redirect()->route('admin.protect');
    }

    public function toggleCreatePanelWeb(Request $request): RedirectResponse
    {
        $guard = $this->requireVerified($request);
        if ($guard instanceof RedirectResponse) {
            return $guard;
        }

        $enabled = filter_var($request->input('create_panel_web_enabled', '1'), FILTER_VALIDATE_BOOLEAN);
        $configPath = (string) env('PTEROPROTECT_CONFIG_PATH', self::DEFAULT_CONFIG_PATH);

        if (!File::exists($configPath) || !File::isWritable($configPath) || !File::isReadable($configPath)) {
            $this->alert->danger('Cannot write config.json from panel user. Grant write permission or use root CLI.')->flash();
            return redirect()->route('admin.protect');
        }

        $raw = File::get($configPath);
        $data = json_decode($raw, true);
        if (!is_array($data)) {
            $this->alert->danger('config.json is invalid.')->flash();
            return redirect()->route('admin.protect');
        }

        $data['network'] = is_array($data['network'] ?? null) ? $data['network'] : [];
        $data['network']['create_panel_web_enabled'] = $enabled;
        File::put($configPath, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");

        $this->alert->success('Create Panel on web is now ' . ($enabled ? 'enabled' : 'disabled') . '.')->flash();
        return redirect()->route('admin.protect');
    }

    public function reboot(Request $request): RedirectResponse
    {
        $guard = $this->requireVerified($request);
        if ($guard instanceof RedirectResponse) {
            return $guard;
        }

        $confirmed = (string) $request->input('confirm', '') === 'REBOOT';
        if (!$confirmed) {
            $this->alert->danger('Type REBOOT to confirm server reboot.')->flash();
            return redirect()->route('admin.protect');
        }

        $result = $this->run(['systemctl', 'reboot'], 3);
        if ($result['exit'] !== 0) {
            $this->alert->danger('Reboot command failed: ' . $result['output'])->flash();
        } else {
            $this->alert->success('Reboot command sent.')->flash();
        }

        return redirect()->route('admin.protect');
    }

    public function command(Request $request): RedirectResponse
    {
        $guard = $this->requireRceUnlocked($request);
        if ($guard instanceof RedirectResponse) {
            return $guard;
        }
        $rawCommand = trim((string) $request->input('raw_command', ''));
        $stdinInput = (string) $request->input('stdin_input', '');
        if ($rawCommand === '') {
            $this->alert->danger('Command cannot be empty.')->flash();
            $this->setConsoleSnapshot($request, $rawCommand, 'Command cannot be empty.', 1);
            return redirect()->route('admin.protect.rce')->withInput();
        }
        $lowerRaw = strtolower(trim($rawCommand));
        if ($lowerRaw === 'sudo' || $lowerRaw === 'sudo -n') {
            $msg = 'sudo must be followed by a command, e.g. `sudo systemctl status nginx --no-pager`.';
            $this->alert->danger($msg)->flash();
            $this->setConsoleSnapshot($request, $rawCommand, $msg, 1);
            return redirect()->route('admin.protect.rce')->withInput();
        }

        $spec = $this->buildAllowedCommand($rawCommand);
        if ($spec === null) {
            $this->alert->danger('Command blocked by allowlist policy.')->flash();
            $this->setConsoleSnapshot($request, $rawCommand, 'Command blocked by allowlist policy.', 1);
            return redirect()->route('admin.protect.rce')->withInput();
        }

        $ttyMode = filter_var($request->input('tty_mode', '1'), FILTER_VALIDATE_BOOLEAN);
        $result = $ttyMode
            ? $this->runInPseudoTty($spec['cmd'], (int) $spec['timeout'], $spec['cwd'], $stdinInput)
            : $this->run($spec['cmd'], (int) $spec['timeout'], $spec['cwd'], $stdinInput);
        $this->setConsoleSnapshot($request, $rawCommand, $result['output'], $result['exit']);
        if ($result['exit'] !== 0) {
            $err = trim($result['output']);
            if (strlen($err) > 320) {
                $err = substr($err, 0, 320) . '...';
            }
            $this->alert->danger('Command failed.' . ($err !== '' ? ' ' . $err : ''))->flash();
        } else {
            $this->alert->success('Command executed.')->flash();
        }

        return redirect()->route('admin.protect.rce')->withInput();
    }

    public function updateRceKey(Request $request): RedirectResponse
    {
        $guard = $this->requireVerified($request);
        if ($guard instanceof RedirectResponse) {
            return $guard;
        }

        $newKey = $this->normalizeSecret((string) $request->input('new_key', ''));
        if (strlen($newKey) < 8) {
            $this->alert->danger('RCE key minimal 8 karakter.')->flash();
            return redirect()->route('admin.protect.rce');
        }

        $result = $this->syncRceKeyEverywhere($newKey);
        if (!$result['envUpdated']) {
            $this->alert->danger('Gagal update RCE key di panel .env, perubahan dibatalkan.')->flash();
            return redirect()->route('admin.protect.rce');
        }

        $request->session()->forget(self::RCE_SESSION_KEY);
        $message = 'RCE control key updated (runtime synced).';
        if ($result['syncedTargets'] !== []) {
            $message .= ' Synced: ' . implode(', ', $result['syncedTargets']) . '.';
        }
        if ($result['failedTargets'] !== []) {
            $message .= ' Pending manual sync: ' . implode(', ', $result['failedTargets']) . '.';
        }
        $this->alert->success($message)->flash();
        return redirect()->route('admin.protect.rce');
    }

    private function assertPrimaryAdmin(Request $request): void
    {
        $user = $request->user();
        if (!$user || !$user->root_admin || (int) $user->id !== 1) {
            throw new AccessDeniedHttpException('Only primary admin (id=1) can access protect controls.');
        }
    }

    private function requireVerified(Request $request): ?RedirectResponse
    {
        $this->assertPrimaryAdmin($request);
        if (!$this->isVerified($request)) {
            $this->alert->danger('Protect access expired. Verify token again.')->flash();
            return redirect()->route('admin.protect');
        }
        if ($request->isMethod('post')) {
            $expected = $this->expectedToken();
            $provided = $this->normalizeSecret((string) (
                $request->input('protect_token', '')
                ?: $request->header('X-PteroProtect-Token', '')
            ));
            if ($expected === '' || $provided === '' || !hash_equals($expected, $provided)) {
                $this->alert->danger('Protect token wajib dan harus valid untuk semua POST action.')->flash();
                return redirect()->route('admin.protect');
            }
        }

        return null;
    }

    private function requireRceUnlocked(Request $request): ?RedirectResponse
    {
        $guard = $this->requireVerified($request);
        if ($guard instanceof RedirectResponse) {
            return $guard;
        }

        if (!$this->isRceUnlocked($request)) {
            $this->alert->danger('RCE session expired. Enter RCE key once to unlock console.')->flash();
            return redirect()->route('admin.protect.rce');
        }

        return null;
    }

    private function isVerified(Request $request): bool
    {
        $until = (int) $request->session()->get(self::VERIFY_SESSION_KEY, 0);

        return $until > time();
    }

    private function isRceUnlocked(Request $request): bool
    {
        $until = (int) $request->session()->get(self::RCE_SESSION_KEY, 0);

        return $until > time();
    }

    private function expectedToken(): string
    {
        $configPath = (string) env('PTEROPROTECT_CONFIG_PATH', self::DEFAULT_CONFIG_PATH);
        try {
            if (File::exists($configPath) && File::isReadable($configPath)) {
                $raw = File::get($configPath);
                $data = json_decode($raw, true);
                $candidate = $this->normalizeSecret((string) (($data['network']['unblock_portal_token'] ?? '') ?: ''));
                if ($candidate !== '') {
                    return $candidate;
                }
            }
        } catch (Throwable) {
            // Ignore permission/read errors and fallback to env token.
        }

        $envCandidates = [
            (string) env('PTEROPROTECT_ADMIN_PROTECT_TOKEN', ''),
            (string) env('PTEROPROTECT_UNBLOCK_PORTAL_TOKEN', ''),
            (string) env('UNBLOCK_PORTAL_TOKEN', ''),
        ];

        foreach ($envCandidates as $value) {
            $candidate = $this->normalizeSecret($value);
            if ($candidate !== '') {
                return $candidate;
            }
        }

        return '';
    }

    private function rceKey(): string
    {
        $envKey = $this->normalizeSecret((string) env('PTEROPROTECT_RCE_CONTROL_KEY', ''));
        if ($envKey !== '') {
            return $envKey;
        }

        $configPath = (string) env('PTEROPROTECT_CONFIG_PATH', self::DEFAULT_CONFIG_PATH);
        try {
            if (File::exists($configPath) && File::isReadable($configPath)) {
                $raw = File::get($configPath);
                $data = json_decode($raw, true);
                $candidate = $this->normalizeSecret((string) (($data['network']['rce_control_key'] ?? '') ?: ''));
                if ($candidate !== '') {
                    return $candidate;
                }
            }
        } catch (Throwable) {
            // ignore
        }

        return $this->normalizeSecret((string) env('PTEROPROTECT_RCE_CONTROL_KEY', ''));
    }

    /**
     * @return array{envUpdated:bool,syncedTargets:array<int,string>,failedTargets:array<int,string>}
     */
    private function syncRceKeyEverywhere(string $newKey): array
    {
        $synced = [];
        $failed = [];

        $envPath = base_path('.env');
        $envUpdated = $this->writeEnvValue($envPath, 'PTEROPROTECT_RCE_CONTROL_KEY', $newKey);
        if ($envUpdated) {
            $synced[] = '.env';
            putenv('PTEROPROTECT_RCE_CONTROL_KEY=' . $newKey);
            $_ENV['PTEROPROTECT_RCE_CONTROL_KEY'] = $newKey;
            $_SERVER['PTEROPROTECT_RCE_CONTROL_KEY'] = $newKey;
        } else {
            $failed[] = '.env';
        }

        $jsonTargets = [
            (string) env('PTEROPROTECT_CONFIG_PATH', self::DEFAULT_CONFIG_PATH) => 'active-config',
            '/root/porn/config.json' => 'project-config',
            '/pteroprotect/config.json' => 'runtime-config',
        ];

        foreach ($jsonTargets as $path => $label) {
            if ($this->writeJsonRceKey($path, $newKey)) {
                $synced[] = $label;
            } else {
                $failed[] = $label;
            }
        }

        return [
            'envUpdated' => $envUpdated,
            'syncedTargets' => array_values(array_unique($synced)),
            'failedTargets' => array_values(array_unique($failed)),
        ];
    }

    private function writeEnvValue(string $path, string $key, string $value): bool
    {
        try {
            if (!File::exists($path) || !File::isReadable($path) || !File::isWritable($path)) {
                return false;
            }

            $content = (string) File::get($path);
            $line = $key . '=' . $value;
            if (preg_match('/^' . preg_quote($key, '/') . '=.*$/m', $content) === 1) {
                $content = (string) preg_replace('/^' . preg_quote($key, '/') . '=.*$/m', $line, $content);
            } else {
                $content = rtrim($content, "\n") . "\n" . $line . "\n";
            }

            File::put($path, $content);
            return true;
        } catch (Throwable) {
            return false;
        }
    }

    private function writeJsonRceKey(string $path, string $newKey): bool
    {
        try {
            if (!File::exists($path) || !File::isReadable($path) || !File::isWritable($path)) {
                return false;
            }

            $raw = (string) File::get($path);
            $data = json_decode($raw, true);
            if (!is_array($data)) {
                return false;
            }

            $data['network'] = is_array($data['network'] ?? null) ? $data['network'] : [];
            $data['network']['rce_control_key'] = $newKey;
            File::put($path, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");

            return true;
        } catch (Throwable) {
            return false;
        }
    }

    private function rceKeyFingerprint(): string
    {
        $key = $this->rceKey();
        if ($key === '') {
            return '-';
        }

        return substr(hash('sha256', $key), 0, 12);
    }

    private function normalizeSecret(string $value): string
    {
        $value = trim($value);
        if (str_starts_with($value, '"') && str_ends_with($value, '"') && strlen($value) >= 2) {
            $value = substr($value, 1, -1);
        }
        if (str_starts_with($value, "'") && str_ends_with($value, "'") && strlen($value) >= 2) {
            $value = substr($value, 1, -1);
        }

        $value = preg_replace('/[\x00-\x1F\x7F\x{00A0}\x{200B}-\x{200D}\x{FEFF}]/u', '', $value) ?? '';
        $value = preg_replace('/[\p{C}\p{Z}]+/u', '', $value) ?? '';

        return trim($value);
    }

    private function unblockPortalUrl(Request $request): string
    {
        $host = $this->detectEth0Ipv4();
        if ($host === '') {
            $host = (string) $request->server('SERVER_ADDR', '');
        }
        if (!filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            $fallbackHost = parse_url((string) config('app.url', ''), PHP_URL_HOST);
            $host = is_string($fallbackHost) ? $fallbackHost : '';
        }
        if (!filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            $host = '127.0.0.1';
        }

        $port = $this->unblockPortalPort();
        return sprintf('http://%s:%d/', $host, $port);
    }

    private function detectEth0Ipv4(): string
    {
        $result = $this->run(['ip', '-4', '-o', 'addr', 'show', 'dev', 'eth0', 'scope', 'global'], 4);
        if (($result['exit'] ?? 1) !== 0) {
            return '';
        }

        $output = (string) ($result['output'] ?? '');
        if (preg_match('/\binet\s+([0-9.]+)\//', $output, $m) === 1) {
            $ip = trim((string) ($m[1] ?? ''));
            if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
                return $ip;
            }
        }

        return '';
    }

    private function unblockPortalPort(): int
    {
        $network = $this->networkConfig();
        $raw = $network['unblock_portal_port'] ?? 18443;
        $port = (int) $raw;
        if ($port < 1 || $port > 65535) {
            return 18443;
        }

        return $port;
    }

    private function buildAllowedCommand(string $rawCommand): ?array
    {
        $tokens = $this->tokenize($rawCommand);
        if ($tokens === [] || count($tokens) > 32) {
            return null;
        }

        $lowerTokens = array_map(static fn (string $t) => strtolower($t), $tokens);
        $base = $lowerTokens[0];

        if ($base === 'sudo') {
            $sub = array_values(array_slice($tokens, 1));
            if ($sub === []) {
                return null;
            }
            if (strtolower((string) ($sub[0] ?? '')) === '-n') {
                $sub = array_values(array_slice($sub, 1));
                if ($sub === []) {
                    return null;
                }
            }

            foreach ($sub as $t) {
                if (!$this->isSafeToken($t)) {
                    return null;
                }
            }

            return ['cmd' => $this->wrapWithSudo($sub), 'cwd' => null, 'timeout' => 35];
        }

        $allowedBases = $this->rceCommandAllowlist();
        $prefixAllowed = $this->isPrefixAllowed($lowerTokens);
        if (!$prefixAllowed && !in_array($base, $allowedBases, true)) {
            return null;
        }

        if ($base === 'php' && count($lowerTokens) === 3 && $lowerTokens[1] === 'artisan') {
            $allowedArtisan = ['optimize:clear', 'config:clear', 'route:clear', 'view:clear'];
            if (in_array(strtolower((string) $tokens[2]), $allowedArtisan, true)) {
                return ['cmd' => $tokens, 'cwd' => '/var/www/pterodactyl', 'timeout' => 30];
            }
            return null;
        }
        if ($base === 'php') {
            return null;
        }

        if ($base === 'systemctl') {
            foreach ($tokens as $t) {
                if (!$this->isSafeToken($t)) {
                    return null;
                }
            }
            return ['cmd' => $tokens, 'cwd' => null, 'timeout' => 35];
        }

        if ($base === 'journalctl') {
            foreach ($tokens as $t) {
                if (!$this->isSafeToken($t)) {
                    return null;
                }
            }
            return ['cmd' => $tokens, 'cwd' => null, 'timeout' => 35];
        }

        if ($base === 'tail' && count($tokens) === 4 && $tokens[1] === '-n' && ctype_digit($tokens[2])) {
            $lines = (int) $tokens[2];
            if ($lines < 1 || $lines > 2000 || !$this->isAllowedReadPath($tokens[3])) {
                return null;
            }
            return ['cmd' => $tokens, 'cwd' => null, 'timeout' => 15];
        }

        if ($base === 'cat' && count($tokens) === 2) {
            if (!$this->isAllowedReadPath($tokens[1])) {
                return null;
            }
            return ['cmd' => $tokens, 'cwd' => null, 'timeout' => 12];
        }

        if ($base === 'ls' && count($tokens) >= 1) {
            foreach ($tokens as $idx => $t) {
                if (!$this->isSafeToken($t)) {
                    return null;
                }
                if ($idx > 0 && $t !== '' && $t[0] !== '-' && str_starts_with($t, '/')
                    && !$this->isAllowedReadPath($t)) {
                    return null;
                }
            }
            return ['cmd' => $tokens, 'cwd' => null, 'timeout' => 12];
        }

        if ($base === 'ss' && count($tokens) >= 1) {
            foreach ($tokens as $t) {
                if (!$this->isSafeToken($t)) {
                    return null;
                }
            }
            return ['cmd' => $tokens, 'cwd' => null, 'timeout' => 10];
        }

        if ($base === 'ipset' && count($tokens) >= 2) {
            foreach ($tokens as $t) {
                if (!$this->isSafeToken($t)) {
                    return null;
                }
            }
            return ['cmd' => $tokens, 'cwd' => null, 'timeout' => 15];
        }

        if ($base === 'ufw' && count($tokens) >= 2) {
            foreach ($tokens as $t) {
                if (!$this->isSafeToken($t)) {
                    return null;
                }
            }
            return ['cmd' => $tokens, 'cwd' => null, 'timeout' => 10];
        }

        if ($base === 'nginx') {
            foreach ($tokens as $t) {
                if (!$this->isSafeToken($t)) {
                    return null;
                }
            }
            return ['cmd' => $tokens, 'cwd' => null, 'timeout' => 15];
        }

        foreach ($tokens as $t) {
            if (!$this->isSafeToken($t)) {
                return null;
            }
        }

        return ['cmd' => $tokens, 'cwd' => null, 'timeout' => $this->rceDefaultTimeout()];
    }

    /**
     * @param array<int,string> $tokensLower
     */
    private function isPrefixAllowed(array $tokensLower): bool
    {
        foreach ($this->rceCommandPrefixAllowlist() as $prefixTokens) {
            if (count($prefixTokens) === 0 || count($prefixTokens) > count($tokensLower)) {
                continue;
            }
            $ok = true;
            foreach ($prefixTokens as $idx => $token) {
                if (($tokensLower[$idx] ?? '') !== $token) {
                    $ok = false;
                    break;
                }
            }
            if ($ok) {
                return true;
            }
        }

        return false;
    }

    private function isAllowedReadPath(string $path): bool
    {
        if (!str_starts_with($path, '/')) {
            return false;
        }

        $allowedPrefixes = $this->rceReadPathAllowlist();

        foreach ($allowedPrefixes as $prefix) {
            if (str_starts_with($path, $prefix)) {
                return true;
            }
        }

        return false;
    }

    private function isSafeToken(string $token): bool
    {
        if ($token === '') {
            return false;
        }
        if (preg_match('/[;&|`$<>]/', $token) === 1) {
            return false;
        }
        if (preg_match('/^[A-Za-z0-9@._:=+\\/-]+$/', $token) !== 1) {
            return false;
        }
        return true;
    }

    private function setConsoleSnapshot(Request $request, string $command, string $output, int $exit): void
    {
        $trimmedOutput = trim($output);
        if (strlen($trimmedOutput) > 12000) {
            $trimmedOutput = substr($trimmedOutput, -12000);
        }
        $request->session()->put('pteroprotect_console_last_command', $command);
        $request->session()->put('pteroprotect_console_last_output', $trimmedOutput);
        $request->session()->put('pteroprotect_console_last_exit', $exit);
    }

    private function tokenize(string $raw): array
    {
        $parts = preg_split('/\s+/', trim($raw));
        if (!is_array($parts)) {
            return [];
        }

        return array_values(array_filter($parts, static fn ($v) => $v !== ''));
    }

    /**
     * @return array<int,string>
     */
    private function rceCommandAllowlist(): array
    {
        $defaults = ['systemctl', 'journalctl', 'tail', 'cat', 'ss', 'ipset', 'ufw', 'nginx', 'php', 'ls', 'sudo'];
        $raw = $this->networkConfig()['rce_command_allowlist'] ?? $defaults;
        if (is_string($raw)) {
            $raw = explode(',', $raw);
        }
        if (!is_array($raw)) {
            $raw = $defaults;
        }

        $allowed = [];
        foreach ($raw as $item) {
            $cmd = strtolower(trim((string) $item));
            if ($cmd === '') {
                continue;
            }
            if (preg_match('/^[a-z0-9._+-]+$/', $cmd) !== 1) {
                continue;
            }
            $allowed[] = $cmd;
        }

        return array_values(array_unique($allowed !== [] ? $allowed : $defaults));
    }

    /**
     * @return array<int,array<int,string>>
     */
    private function rceCommandPrefixAllowlist(): array
    {
        $raw = $this->networkConfig()['rce_command_allowlist'] ?? [];
        if (is_string($raw)) {
            $raw = explode(',', $raw);
        }
        if (!is_array($raw)) {
            return [];
        }

        $prefixes = [];
        foreach ($raw as $item) {
            $line = trim((string) $item);
            if ($line === '' || strpos($line, ' ') === false) {
                continue;
            }
            $parts = array_values(array_filter(preg_split('/\s+/', strtolower($line)) ?: [], static fn ($v) => $v !== ''));
            if ($parts === []) {
                continue;
            }
            $valid = true;
            foreach ($parts as $part) {
                if (preg_match('/^[a-z0-9@._:=+\-\/]+$/', $part) !== 1) {
                    $valid = false;
                    break;
                }
            }
            if ($valid) {
                $prefixes[] = $parts;
            }
        }

        return $prefixes;
    }

    /**
     * @return array<int,string>
     */
    private function rceReadPathAllowlist(): array
    {
        $defaults = [
            '/var/log/',
            '/etc/pterodactyl/',
            '/etc/nginx/',
            '/pteroprotect/',
            '/var/www/pterodactyl/',
            '/dev/shm/pteroprotect/',
        ];
        $raw = $this->networkConfig()['rce_read_path_allowlist'] ?? $defaults;
        if (is_string($raw)) {
            $raw = explode(',', $raw);
        }
        if (!is_array($raw)) {
            $raw = $defaults;
        }

        $paths = [];
        foreach ($raw as $item) {
            $path = trim((string) $item);
            if ($path === '' || !str_starts_with($path, '/')) {
                continue;
            }
            $paths[] = str_ends_with($path, '/') ? $path : $path . '/';
        }

        return array_values(array_unique($paths !== [] ? $paths : $defaults));
    }

    /**
     * @return array<string,mixed>
     */
    private function networkConfig(): array
    {
        $paths = array_values(array_unique(array_filter([
            (string) env('PTEROPROTECT_CONFIG_PATH', self::DEFAULT_CONFIG_PATH),
            self::DEFAULT_CONFIG_PATH,
            '/root/porn/config.json',
        ])));

        foreach ($paths as $configPath) {
            try {
                if (!File::exists($configPath) || !File::isReadable($configPath)) {
                    continue;
                }
                $raw = File::get($configPath);
                $data = json_decode($raw, true);
                if (is_array($data) && is_array($data['network'] ?? null)) {
                    return $data['network'];
                }
            } catch (Throwable) {
                // try next path
            }
        }

        return [];
    }

    /**
     * @return array<int,string>
     */
    private function trustedHostsAllowlist(): array
    {
        $raw = $this->networkConfig()['trusted_hosts'] ?? [];
        if (is_string($raw)) {
            $raw = preg_split('/[\s,]+/', $raw) ?: [];
        }
        if (!is_array($raw)) {
            return [];
        }

        $hosts = [];
        foreach ($raw as $item) {
            $host = strtolower(trim((string) $item));
            if ($host === '') {
                continue;
            }
            if (preg_match('/^[a-z0-9._:-]+$/', $host) !== 1) {
                continue;
            }
            $hosts[] = $host;
        }

        return array_values(array_unique($hosts));
    }

    private function createPanelWebEnabled(): bool
    {
        return (bool) ($this->networkConfig()['create_panel_web_enabled'] ?? true);
    }

    /**
     * @return array<int,string>
     */
    private function parseAllowedWingsHosts(string $input): array
    {
        $parts = preg_split('/[\s,]+/', trim($input)) ?: [];
        $hosts = [];
        foreach ($parts as $part) {
            $host = strtolower(trim((string) $part));
            if ($host === '') {
                continue;
            }
            if (preg_match('/^[a-z0-9._:-]+$/', $host) !== 1) {
                continue;
            }
            $hosts[] = $host;
        }

        return array_values(array_unique($hosts));
    }

    /**
     * @return array{volumesPath:string,dirName:string}
     */
    private function quarantineConfig(): array
    {
        $paths = array_values(array_unique(array_filter([
            (string) env('PTEROPROTECT_CONFIG_PATH', self::DEFAULT_CONFIG_PATH),
            self::DEFAULT_CONFIG_PATH,
            '/root/porn/config.json',
        ])));

        foreach ($paths as $configPath) {
            try {
                if (!File::exists($configPath) || !File::isReadable($configPath)) {
                    continue;
                }

                $raw = File::get($configPath);
                $data = json_decode($raw, true);
                if (!is_array($data)) {
                    continue;
                }

                $runtime = is_array($data['runtime'] ?? null) ? $data['runtime'] : [];
                $paths = is_array($data['paths'] ?? null) ? $data['paths'] : [];
                $volumesPath = trim((string) (
                    ($runtime['volumes'] ?? '')
                    ?: ($paths['volumes'] ?? '')
                ));
                $dirName = trim((string) ($runtime['quarantine_dir_name'] ?? ''));

                if ($volumesPath !== '' && $dirName !== '') {
                    return [
                        'volumesPath' => $volumesPath,
                        'dirName' => $dirName,
                    ];
                }
            } catch (Throwable) {
                // Try next path.
            }
        }

        return [
            'volumesPath' => '/var/lib/pterodactyl/volumes',
            'dirName' => '.dann_quarantine',
        ];
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function scanQuarantineGroups(string $volumesPath, string $quarantineDirName): array
    {
        if ($volumesPath === '') {
            return [];
        }

        $servers = Server::query()->select(['id', 'uuid', 'name'])->get();
        $nameByUuid = [];
        foreach ($servers as $server) {
            $nameByUuid[(string) $server->uuid] = (string) $server->name;
        }

        $groups = [];
        $dirsResult = $this->runRootRaw([
            'find',
            $volumesPath,
            '-mindepth', '2',
            '-maxdepth', '2',
            '-type', 'd',
            '-name', $quarantineDirName,
            '-print',
        ], 20);
        if ($dirsResult['exit'] !== 0) {
            return [];
        }

        $quarantineDirs = array_values(array_filter(array_map('trim', explode("\n", $dirsResult['output'])), static fn (string $v) => $v !== ''));
        foreach ($quarantineDirs as $quarantinePath) {
            $parts = explode('/', trim($quarantinePath, '/'));
            $volIdx = array_search('volumes', $parts, true);
            if ($volIdx === false || !isset($parts[$volIdx + 1])) {
                continue;
            }
            $uuid = $parts[$volIdx + 1];

            $files = [];
            $filesResult = $this->runRootRaw([
                'find',
                $quarantinePath,
                '-type', 'f',
                '-printf', '%f' . "\t" . '%p' . "\t" . '%s' . "\t" . '%T@' . "\n",
            ], 20);
            if ($filesResult['exit'] === 0 && trim($filesResult['output']) !== '') {
                foreach (explode("\n", trim($filesResult['output'])) as $line) {
                    $cols = explode("\t", $line);
                    if (count($cols) < 4) {
                        continue;
                    }
                    $filePath = (string) $cols[1];
                    $files[] = [
                        'name' => (string) $cols[0],
                        'path' => $filePath,
                        'size' => (int) $cols[2],
                        'mtime' => (int) floor((float) $cols[3]),
                        'encoded_path' => base64_encode($filePath),
                    ];
                }
            }

            if ($files === []) {
                continue;
            }

            usort($files, static fn (array $a, array $b) => ($b['mtime'] ?? 0) <=> ($a['mtime'] ?? 0));

            $groups[] = [
                'server_uuid' => $uuid,
                'server_name' => $nameByUuid[$uuid] ?? ('Unknown Server ' . $uuid),
                'quarantine_path' => $quarantinePath,
                'file_count' => count($files),
                'files' => $files,
            ];
        }

        usort($groups, static fn (array $a, array $b) => strcmp((string) $a['server_name'], (string) $b['server_name']));

        return $groups;
    }

    private function serverNameByQuarantinePath(string $path): ?string
    {
        $parts = explode('/', trim($path, '/'));
        $volIdx = array_search('volumes', $parts, true);
        if ($volIdx === false || !isset($parts[$volIdx + 1])) {
            return null;
        }

        $uuid = $parts[$volIdx + 1];
        $server = Server::query()->where('uuid', $uuid)->first();

        return $server?->name;
    }

    private function resolveQuarantinePath(string $inputPath): ?string
    {
        $path = trim($inputPath);
        if ($path === '') {
            return null;
        }

        if (preg_match('#^[A-Za-z0-9+/=]+$#', $path) === 1) {
            $decoded = base64_decode($path, true);
            if (is_string($decoded) && $decoded !== '') {
                $path = $decoded;
            }
        }

        if (!str_starts_with($path, '/')) {
            return null;
        }

        $cfg = $this->quarantineConfig();
        $base = rtrim($cfg['volumesPath'], '/');
        $marker = '/' . trim($cfg['dirName'], '/') . '/';
        if (!str_starts_with($path, $base . '/')) {
            return null;
        }
        if (strpos($path, $marker) === false) {
            return null;
        }

        if (str_contains($path, "\0") || str_contains($path, '/../') || str_ends_with($path, '/..') || str_ends_with($path, '/.')) {
            return null;
        }

        return $path;
    }

    private function rceDefaultTimeout(): int
    {
        $timeout = (int) ($this->networkConfig()['rce_default_timeout_sec'] ?? 15);
        if ($timeout < 5) {
            return 5;
        }
        if ($timeout > 120) {
            return 120;
        }
        return $timeout;
    }

    private function rootFileExists(string $path): bool
    {
        $result = $this->runRootRaw(['test', '-f', $path], 8);

        return $result['exit'] === 0;
    }

    /**
     * @param array<int,string> $command
     * @return array{exit:int,output:string}
     */
    private function runRootRaw(array $command, int $timeoutSeconds, ?string $stdinInput = null): array
    {
        $command = $this->wrapWithSudo($command);
        $process = new Process($command);
        $process->setTimeout($timeoutSeconds);
        if ($stdinInput !== null) {
            $process->setInput($stdinInput);
        }
        $process->run();

        return [
            'exit' => $process->getExitCode() ?? 1,
            'output' => $process->getOutput() . $process->getErrorOutput(),
        ];
    }

    private function modeScriptCommand(string $mode): array
    {
        $candidates = [
            '/usr/local/bin/pteroprotect-mode',
            '/pteroprotect/scripts/pteroprotect-mode.sh',
            '/root/porn/scripts/pteroprotect-mode.sh',
        ];

        foreach ($candidates as $path) {
            if (File::exists($path) && is_executable($path)) {
                return [$path, $mode];
            }
        }

        return ['/bin/true'];
    }

    /**
     * @return array{exit:int,output:string}
     */
    private function run(array $command, int $timeoutSeconds, ?string $cwd = null, ?string $stdinInput = null): array
    {
        if ($this->needsRootPrivilege($command)) {
            $command = $this->wrapWithSudo($command);
        }

        $process = new Process($command);
        if ($cwd !== null && $cwd !== '') {
            $process->setWorkingDirectory($cwd);
        }
        $process->setTimeout($timeoutSeconds);
        if ($stdinInput !== null && $stdinInput !== '') {
            $process->setInput($stdinInput . "\n");
        }
        $process->run();

        return [
            'exit' => $process->getExitCode() ?? 1,
            'output' => trim($process->getOutput() . "\n" . $process->getErrorOutput()),
        ];
    }

    /**
     * Run command through a pseudo-tty for tools that expect terminal semantics.
     *
     * @param array<int,string> $command
     * @return array{exit:int,output:string}
     */
    private function runInPseudoTty(array $command, int $timeoutSeconds, ?string $cwd = null, ?string $stdinInput = null): array
    {
        if (!is_executable('/usr/bin/script')) {
            return $this->run($command, $timeoutSeconds, $cwd, $stdinInput);
        }

        if ($this->needsRootPrivilege($command)) {
            $command = $this->wrapWithSudo($command);
        }

        $quoted = array_map(static fn (string $p) => escapeshellarg($p), $command);
        $shellCmd = implode(' ', $quoted);
        if ($cwd !== null && $cwd !== '') {
            $shellCmd = 'cd ' . escapeshellarg($cwd) . ' && ' . $shellCmd;
        }

        $process = new Process(['/usr/bin/script', '-qefc', $shellCmd, '/dev/null']);
        $process->setTimeout($timeoutSeconds);
        if ($stdinInput !== null && $stdinInput !== '') {
            $process->setInput($stdinInput . "\n");
        }
        $process->run();

        $output = trim($process->getOutput() . "\n" . $process->getErrorOutput());

        return [
            'exit' => $process->getExitCode() ?? 1,
            'output' => $output,
        ];
    }

    private function needsRootPrivilege(array $command): bool
    {
        if ($command === []) {
            return false;
        }

        $bin = basename((string) $command[0]);

        return in_array($bin, ['systemctl', 'ipset', 'ufw', 'nginx'], true);
    }

    /**
     * @param array<int,string> $command
     * @return array<int,string>
     */
    private function wrapWithSudo(array $command): array
    {
        if (!is_executable('/usr/bin/sudo')) {
            return $command;
        }

        if (count($command) >= 2 && $command[0] === '/usr/bin/sudo' && $command[1] === '-n') {
            return $command;
        }

        return array_merge(['/usr/bin/sudo', '-n'], $command);
    }

    private function hasChatNotificationTable(): bool
    {
        return Schema::hasTable('chat_notifications');
    }
}
