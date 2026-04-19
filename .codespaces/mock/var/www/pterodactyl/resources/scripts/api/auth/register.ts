import http from '@/api/http';

export interface RegisterStartPayload {
    email: string;
    username: string;
    name_first: string;
    password: string;
    telegram_id: string;
}

export interface RegisterStartResponse {
    requestToken: string;
    expiresIn: number;
    botUsername: string;
    botStartUrl: string;
}

export interface RegisterMetaResponse {
    telegramReady: boolean;
    botUsername: string;
    botStartUrl: string;
    requiredChannels: string[];
}

export const startRegister = async (payload: RegisterStartPayload): Promise<RegisterStartResponse> => {
    await http.get('/sanctum/csrf-cookie');
    const { data } = await http.post('/auth/register/start', payload);

    return {
        requestToken: String(data?.data?.request_token || ''),
        expiresIn: Number(data?.data?.expires_in || 0),
        botUsername: String(data?.data?.bot_username || ''),
        botStartUrl: String(data?.data?.bot_start_url || ''),
    };
};

export const getRegisterMeta = async (): Promise<RegisterMetaResponse> => {
    const { data } = await http.get('/auth/register/meta');

    return {
        telegramReady: !!data?.data?.telegram_ready,
        botUsername: String(data?.data?.bot_username || ''),
        botStartUrl: String(data?.data?.bot_start_url || ''),
        requiredChannels: Array.isArray(data?.data?.required_channels)
            ? data.data.required_channels.map((v: unknown) => String(v || '')).filter((v: string) => v !== '')
            : [],
    };
};

export const verifyRegisterOtp = async (requestToken: string, otp: string): Promise<void> => {
    await http.get('/sanctum/csrf-cookie');
    await http.post('/auth/register/verify', {
        request_token: requestToken,
        otp,
    });
};
