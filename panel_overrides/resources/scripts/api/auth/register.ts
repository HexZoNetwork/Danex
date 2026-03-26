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
}

export const startRegister = async (payload: RegisterStartPayload): Promise<RegisterStartResponse> => {
    await http.get('/sanctum/csrf-cookie');
    const { data } = await http.post('/auth/register/start', payload);

    return {
        requestToken: String(data?.data?.request_token || ''),
        expiresIn: Number(data?.data?.expires_in || 0),
    };
};

export const verifyRegisterOtp = async (requestToken: string, otp: string): Promise<void> => {
    await http.get('/sanctum/csrf-cookie');
    await http.post('/auth/register/verify', {
        request_token: requestToken,
        otp,
    });
};
