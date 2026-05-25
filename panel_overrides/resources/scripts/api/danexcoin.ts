import http from '@/api/http';

export interface DanexCoinSpinLog {
    id: number;
    bet: string;
    reels: [string, string, string];
    multiplier: string;
    payout: string;
    balance_before: string;
    balance_after: string;
    is_jackpot: boolean;
    created_at: string | null;
}

export interface DanexCoinState {
    enabled: boolean;
    balance: string;
    currency: string;
    settings: {
        min_bet: string;
        max_bet: string;
        default_bet: string;
        spin_cooldown_seconds: number;
        base_win_rate: number;
        jackpot_rate: number;
        house_edge_label: string;
    };
    summary: {
        total_spins: number;
        wins: number;
        jackpots: number;
        biggest_payout: string;
        hotness: number;
        streak: number;
    };
    rules: {
        jackpot: string;
        triple: string;
        double?: string;
        auto_adjust?: string;
        miss: string;
    };
    history: DanexCoinSpinLog[];
}

export interface DanexCoinSpinResult {
    id: number;
    bet: string;
    requested_bet?: string;
    reels: [string, string, string];
    multiplier: string;
    payout: string;
    balance_before: string;
    balance_after: string;
    is_jackpot: boolean;
    win_rate?: number;
}

export const getDanexCoinState = async (): Promise<DanexCoinState> => {
    const { data } = await http.get('/api/client/danexcoin');
    return data;
};

export const spinDanexCoin = async (bet: number): Promise<DanexCoinSpinResult> => {
    const { data } = await http.post('/api/client/danexcoin/spin', { bet });
    return data;
};
