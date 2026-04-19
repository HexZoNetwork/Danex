import http from '@/api/http';

export interface CreatePanelEgg {
    id: number;
    name: string;
    nest_id: number;
    description: string;
}

export interface CreatePanelOptions {
    created: boolean;
    eggs: CreatePanelEgg[];
    ram_options: number[];
    fixed: {
        cpu: number;
        threads: string;
    };
}

export const getCreatePanelOptions = async (): Promise<CreatePanelOptions> => {
    const { data } = await http.get('/api/client/create-panel/options');
    return data;
};

export interface CreatePanelPayload {
    name: string;
    egg_id: number;
    ram: number;
}

export interface CreatePanelResponse {
    data: {
        server_id: number;
        server_uuid: string;
        server_identifier: string;
    };
}

export const createMadeinwebPanel = async (payload: CreatePanelPayload): Promise<CreatePanelResponse> => {
    const { data } = await http.post('/api/client/create-panel/create', payload);
    return data;
};
