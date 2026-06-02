import http, { FractalResponseData } from '@/api/http';

const revealApiKey = async (identifier: string, password: string): Promise<string> => {
    const { data } = await http.post(`/api/client/account/api-keys/${identifier}/reveal`, { password });
    const attributes = (data as FractalResponseData)?.attributes || data?.data?.attributes || data || {};
    const token = String(attributes.token || attributes.api_key || attributes.apiKey || '');

    if (!token) {
        throw new Error('Full PTLC token could not be loaded.');
    }

    return token;
};

export default revealApiKey;
