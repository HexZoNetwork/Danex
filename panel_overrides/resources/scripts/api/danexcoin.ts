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
    balance: string;
    currency: string;
    rules: {
        jackpot: string;
        triple: string;
        miss: string;
    };
    history: DanexCoinSpinLog[];
}

export interface DanexCoinSpinResult {
    id: number;
    bet: string;
    reels: [string, string, string];
    multiplier: string;
    payout: string;
    balance_before: string;
    balance_after: string;
    is_jackpot: boolean;
}

export const getDanexCoinState = async (): Promise<DanexCoinState> => {
    const { data } = await http.get('/api/client/danexcoin');
    return data;
};

export const spinDanexCoin = async (bet: number): Promise<DanexCoinSpinResult> => {
    const { data } = await http.post('/api/client/danexcoin/spin', { bet });
    return data;
};
