import http, { FractalResponseData } from '@/api/http';

export interface AccountProfile {
    username: string;
    email: string;
    firstName: string;
    lastName: string;
    avatarUrl: string;
    birthday: string;
    language?: string;
}

export interface UpdateAccountProfilePayload {
    username: string;
    email: string;
    name_first: string;
    name_last: string;
    avatar_url: string | null;
    birthday: string | null;
}

const normalize = (attributes: Record<string, unknown>): AccountProfile => ({
    username: String(attributes.username || ''),
    email: String(attributes.email || ''),
    firstName: String(attributes.first_name || ''),
    lastName: String(attributes.last_name || ''),
    avatarUrl: String(attributes.avatar_url || ''),
    birthday: String(attributes.birthday || ''),
    language: attributes.language ? String(attributes.language) : undefined,
});

const pickAttributes = (data: any): Record<string, unknown> => {
    const attributes = (data as FractalResponseData)?.attributes;
    if (attributes && typeof attributes === 'object') {
        return attributes;
    }

    if (data && typeof data === 'object' && data.data?.attributes) {
        return data.data.attributes;
    }

    return {};
};

export const getAccountProfile = async (): Promise<AccountProfile> => {
    const { data } = await http.get('/api/client/account');

    return normalize(pickAttributes(data));
};

export const updateAccountProfile = async (payload: UpdateAccountProfilePayload): Promise<AccountProfile> => {
    const { data } = await http.put('/api/client/account/profile', payload);

    return normalize(pickAttributes(data));
};
