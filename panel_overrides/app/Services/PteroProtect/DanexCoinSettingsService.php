<?php

namespace Pterodactyl\Services\PteroProtect;

class DanexCoinSettingsService
{
    private string $storePath;

    public function __construct(?string $storePath = null)
    {
        $this->storePath = $storePath ?: (string) env('DANEXCOIN_SETTINGS_FILE', storage_path('pteroprotect/danexcoin.json'));
    }

    public function get(): array
    {
        $data = $this->read();

        return [
            'enabled' => (bool) ($data['enabled'] ?? true),
            'min_bet' => $this->money($data['min_bet'] ?? 1),
            'max_bet' => $this->money($data['max_bet'] ?? 100000000),
            'default_bet' => $this->money($data['default_bet'] ?? 10),
            'spin_cooldown_seconds' => max(1, min(30, (int) ($data['spin_cooldown_seconds'] ?? 4))),
            'base_win_rate' => $this->rate($data['base_win_rate'] ?? 0.16),
            'jackpot_rate' => $this->rate($data['jackpot_rate'] ?? 0.08),
            'triple_multiplier' => $this->money($data['triple_multiplier'] ?? 1.5),
            'double_multiplier' => $this->money($data['double_multiplier'] ?? 0.35),
            'jackpot_multiplier' => $this->money($data['jackpot_multiplier'] ?? 3),
            'hot_window_minutes' => max(5, min(120, (int) ($data['hot_window_minutes'] ?? 15))),
            'house_edge_label' => trim((string) ($data['house_edge_label'] ?? 'volatile')),
        ];
    }

    public function update(array $input): array
    {
        $settings = $this->get();
        foreach ($settings as $key => $value) {
            if (!array_key_exists($key, $input)) {
                continue;
            }

            if ($key === 'enabled') {
                $settings[$key] = filter_var($input[$key], FILTER_VALIDATE_BOOLEAN);
            } elseif (str_ends_with($key, '_rate')) {
                $settings[$key] = $this->rate($input[$key]);
            } elseif ($key === 'spin_cooldown_seconds' || $key === 'hot_window_minutes') {
                $settings[$key] = (int) $input[$key];
            } else {
                $settings[$key] = is_numeric($input[$key]) ? $this->money($input[$key]) : trim((string) $input[$key]);
            }
        }

        $settings['min_bet'] = max(0.01, $this->money($settings['min_bet']));
        $settings['max_bet'] = max($settings['min_bet'], $this->money($settings['max_bet']));
        $settings['default_bet'] = max($settings['min_bet'], min($settings['max_bet'], $this->money($settings['default_bet'])));
        $settings['spin_cooldown_seconds'] = max(1, min(30, (int) $settings['spin_cooldown_seconds']));
        $settings['hot_window_minutes'] = max(5, min(120, (int) $settings['hot_window_minutes']));
        $settings['house_edge_label'] = mb_substr(trim((string) $settings['house_edge_label']), 0, 32);

        $this->write($settings);

        return $settings;
    }

    private function money(mixed $value): float
    {
        return round((float) $value, 2);
    }

    private function rate(mixed $value): float
    {
        return max(0.0, min(0.95, round((float) $value, 4)));
    }

    private function read(): array
    {
        $this->ensureStoreExists();
        $raw = @file_get_contents($this->storePath);
        $data = json_decode((string) $raw, true);

        return is_array($data) ? $data : [];
    }

    private function write(array $data): void
    {
        $this->ensureStoreExists();
        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        @file_put_contents($this->storePath, ($json ?: '{}') . "\n", LOCK_EX);
    }

    private function ensureStoreExists(): void
    {
        $dir = dirname($this->storePath);
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        if (!is_file($this->storePath)) {
            @file_put_contents($this->storePath, "{\n}\n", LOCK_EX);
        }
    }
}
